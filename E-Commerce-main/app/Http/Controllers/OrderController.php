<?php

namespace App\Http\Controllers;


use Illuminate\Http\Request;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Jobs\ProcessDailySales;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OrderController extends Controller
{
   
    private const MAX_CONCURRENT_CHECKOUTS = 3;

    
    private const CHECKOUT_SLOTS_KEY = 'checkout_api_concurrent_slots';
   
    private const CHECKOUT_SLOT_EXPIRATION = 60;
    public function checkout()
    {
        $slotId = $this->tryAcquireCheckoutSlot();

        if ($slotId === null) {
            
            Log::warning('OrderController: Checkout rejected - max concurrent requests reached', [
                'user_id' => auth()->id(),
                'max_concurrent' => self::MAX_CONCURRENT_CHECKOUTS,
                'current_slots' => $this->getCurrentCheckoutSlotCount(),
            ]);

            return response()->json([
                'message' => 'Too many checkout requests. Server is busy, please try again.',
                'error_code' => 'CHECKOUT_RESOURCE_LIMIT_EXCEEDED',
                'max_concurrent_checkouts' => self::MAX_CONCURRENT_CHECKOUTS,
                'retry_after_seconds' => 5,
            ], 429);
        }

        Log::info('OrderController: Checkout slot acquired', [
            'user_id' => auth()->id(),
            'slot_id' => $slotId,
            'current_slots' => $this->getCurrentCheckoutSlotCount(),
        ]);

        $startedAt = microtime(true);

        $this->recordCheckoutTraffic();

        try {
            
            if ($this->shouldUsePressureMode()) {
                return $this->checkoutBlackFriday();
            }

            return $this->checkoutNormal();

        } finally {
            $this->finishCheckoutTraffic($startedAt);
            $this->releaseCheckoutSlot($slotId);

            Log::info('OrderController: Checkout slot released', [
                'user_id' => auth()->id(),
                'slot_id' => $slotId,
                'remaining_slots' => $this->getCurrentCheckoutSlotCount(),
            ]);
        }
    }

    private function shouldUsePressureMode(): bool
    {
        if (config('app.black_friday_mode')) {
            return true;
        }

        if (Cache::get('pressure_mode_active', false)) {
            return true;
        }

        $requestsPerMinuteThreshold = (int) config('app.checkout_requests_per_minute_threshold', 100);
        $activeCheckoutsThreshold = (int) config('app.active_checkouts_threshold', 20);

        $minuteKey = 'checkout_requests_' . now()->format('YmdHi');

        $requestsThisMinute = (int) Cache::get($minuteKey, 0);
        $activeCheckouts = (int) Cache::get('active_checkouts_count', 0);

        if ($requestsThisMinute >= $requestsPerMinuteThreshold) {
            $this->activatePressureMode('High checkout requests per minute');
            return true;
        }

        if ($activeCheckouts >= $activeCheckoutsThreshold) {
            $this->activatePressureMode('High active checkout count');
            return true;
        }

        return false;
    }

    private function isSystemUnderPressure(): bool
    {
        return $this->shouldUsePressureMode();
    }

    private function recordCheckoutTraffic(): void
    {
        $minuteKey = 'checkout_requests_' . now()->format('YmdHi');

        Cache::add($minuteKey, 0, now()->addMinutes(2));
        Cache::increment($minuteKey);

        Cache::add('active_checkouts_count', 0, now()->addMinutes(10));
        Cache::increment('active_checkouts_count');
    }

    private function finishCheckoutTraffic(float $startedAt): void
    {
        $activeCheckouts = (int) Cache::get('active_checkouts_count', 0);

        if ($activeCheckouts > 0) {
            Cache::decrement('active_checkouts_count');
        }

        $durationMs = (microtime(true) - $startedAt) * 1000;

        $slowCheckoutThreshold = (int) config('app.slow_checkout_ms_threshold', 1500);

        if ($durationMs >= $slowCheckoutThreshold) {
            $this->activatePressureMode('Slow checkout detected: ' . round($durationMs) . 'ms');
        }
    }

    private function activatePressureMode(string $reason): void
    {
        $duration = (int) config('app.pressure_mode_duration_seconds', 300);

        Cache::put('pressure_mode_active', true, now()->addSeconds($duration));

        Log::warning('Pressure mode activated', [
            'reason' => $reason,
            'duration_seconds' => $duration,
        ]);
    }

    private function checkoutNormal()
    {
        $user = auth()->user();

        $cartItems = $user->cartItems()->with('product')->get();

        if ($cartItems->isEmpty()) {
            return response()->json([
                'mode' => 'normal',
                'message' => 'Cart is empty'
            ], 400);
        }

        $total = 0;

        foreach ($cartItems as $item) {
            if (!$item->product) {
                return response()->json([
                    'mode' => 'normal',
                    'message' => 'Product not found',
                    'product_id' => $item->product_id
                ], 404);
            }

            if ($item->quantity > $item->product->quantity) {
                return response()->json([
                    'mode' => 'normal',
                    'message' => 'Not enough quantity available',
                    'product_id' => $item->product_id,
                    'available_quantity' => $item->product->quantity,
                    'requested_quantity' => $item->quantity
                ], 400);
            }

            $total += $item->quantity * $item->product->price;
        }

        $wallet = $user->wallet;

        if (!$wallet || $wallet->balance < $total) {
            return response()->json([
                'mode' => 'normal',
                'message' => 'Insufficient balance'
            ], 400);
        }

        $wallet->balance -= $total;
        $wallet->save();

        $order = Order::create([
            'user_id' => $user->id,
            'total_price' => $total,
            'invoice_number' => $this->generateInvoiceNumber()
        ]);

        foreach ($cartItems as $item) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
                'price' => $item->product->price
            ]);

            $product = $item->product;
            $product->quantity -= $item->quantity;
            $product->save();
        }

        $user->cartItems()->delete();

        $order->load('orderItems');
        if ($this->isSystemUnderPressure()) {
                ProcessOrderBackgroundTasks::dispatch($order)
                    ->onQueue('low-priority')
                    ->delay(now()->addMinutes(2)); 
            } else {
                ProcessOrderBackgroundTasks::dispatch($order)
                    ->onQueue('order-processing');
            }
        return response()->json([
            'mode' => 'normal',
            'message' => 'Order completed successfully',
            'order_id' => $order->id,
            'invoice_url' => url('/api/invoice/' . $order->id),
            'OrderItems' => $order->orderItems
        ]);
    }

    private function checkoutBlackFriday()
    {
        $user = auth()->user();

        try {
            $order = DB::transaction(function () use ($user) {

                $cartItems = CartItem::where('user_id', $user->id)
                    ->lockForUpdate()
                    ->get();

                if ($cartItems->isEmpty()) {
                    throw new \Exception('Cart is empty');
                }

                $productIds = $cartItems->pluck('product_id')->unique()->values();

                $products = Product::whereIn('id', $productIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $wallet = $user->wallet()
                    ->lockForUpdate()
                    ->first();

                if (!$wallet) {
                    throw new \Exception('Wallet not found');
                }

                $total = 0;

                foreach ($cartItems as $item) {
                    if (!isset($products[$item->product_id])) {
                        throw new \Exception('Product not found: ' . $item->product_id);
                    }

                    $product = $products[$item->product_id];

                    if ($product->quantity < $item->quantity) {
                        throw new \Exception(
                            "Not enough quantity for product [{$product->name}]. " .
                            "Available: {$product->quantity}, Requested: {$item->quantity}"
                        );
                    }

                    $total += $item->quantity * $product->price;
                }

                if ($wallet->balance < $total) {
                    throw new \Exception('Insufficient balance');
                }

                $order = Order::create([
                    'user_id' => $user->id,
                    'total_price' => $total,
                    'invoice_number' => $this->generateInvoiceNumber()
                ]);

                foreach ($cartItems as $item) {
                    $product = $products[$item->product_id];

                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $item->product_id,
                        'quantity' => $item->quantity,
                        'price' => $product->price
                    ]);

                    $product->decrement('quantity', $item->quantity);
                }

                $wallet->decrement('balance', $total);

                CartItem::where('user_id', $user->id)->delete();

                return $order->load('orderItems');

            }, 5);

            return response()->json([
                'mode' => 'black_friday',
                'message' => 'Order completed successfully',
                'order_id' => $order->id,
                'invoice_url' => url('/api/invoice/' . $order->id),
                'OrderItems' => $order->orderItems
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'mode' => 'black_friday',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    private function generateInvoiceNumber(): string
    {
        return 'INV-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(6));
    }
public function processDailySales(Request $request)
{
    /*
     * لا يوجد Body.
     * النظام يأخذ تاريخ اليوم تلقائياً.
     */
    $date = now()->toDateString();

    /*
     * يمنع تشغيل نفس تقرير اليوم مرتين بنفس الوقت.
     */
    $lock = Cache::lock('process_daily_sales_' . $date, 60);

    if (!$lock->get()) {
        return response()->json([
            'message' => 'Daily sales processing is already running',
            'date' => $date
        ], 429);
    }

    try {
        /*
         * حذف تقرير اليوم القديم قبل إعادة بنائه.
         */
        DB::table('daily_sales_summaries')
            ->where('summary_date', $date)
            ->delete();

        /*
         * Query لمبيعات اليوم.
         */
        $baseQuery = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereDate('orders.created_at', $date);

        /*
         * هون النظام يأخذ عدد السجلات من الداتا بيز تلقائياً.
         */
        $totalItems = (clone $baseQuery)->count('order_items.id');

        if ($totalItems === 0) {
            return response()->json([
                'message' => 'No sales found for today',
                'date' => $date
            ]);
        }

        /*
         * أول وآخر ID من الداتا بيز.
         */
        $minId = (clone $baseQuery)->min('order_items.id');
        $maxId = (clone $baseQuery)->max('order_items.id');

        /*
         * القرار:
         * الوضع العادي → Job واحد
         * وقت الضغط → النظام يقسم تلقائياً حسب عدد السجلات في الداتا بيز
         */
        $pressureMode = $this->isSystemUnderPressure();

        if (!$pressureMode) {
            $chunkSize = 500;

            ProcessDailySales::dispatch($date, $minId, $maxId, $chunkSize)
                ->onQueue('daily-sales');

            return response()->json([
                'message' => 'Normal mode: daily sales sent as one background job',
                'queue' => 'daily-sales',
                'date' => $date,
                'pressure_mode' => false,
                'total_items' => $totalItems,
                'chunk_size' => $chunkSize,
                'jobs_count' => 1
            ]);
        }

        /*
         * وقت الضغط:
         * النظام يحدد عدد الـJobs بناءً على عدد السجلات.
         *
         * مثال:
         * 96 record  → 3 jobs تقريباً
         * 300 record → 5 jobs تقريباً
         * 1000+      → jobs أكثر
         */
        if ($totalItems <= 100) {
            $targetJobs = 3;
        } elseif ($totalItems <= 500) {
            $targetJobs = 5;
        } elseif ($totalItems <= 1000) {
            $targetJobs = 10;
        } else {
            $targetJobs = (int) ceil($totalItems / 200);
        }

        /*
         * لا نعمل jobs أكثر من عدد السجلات.
         * ولا نتركها تزيد كثيراً بشكل مبالغ فيه.
         */
        $targetJobs = min($targetJobs, $totalItems, 50);

        /*
         * كم سجل تقريباً داخل كل Job.
         */
        $itemsPerJob = (int) ceil($totalItems / $targetJobs);

        /*
         * نأخذ IDs الحقيقية من الداتا بيز ونقسمها تلقائياً.
         */
        $ids = (clone $baseQuery)
            ->orderBy('order_items.id')
            ->pluck('order_items.id');

        $chunkSize = 100;
        $jobsCount = 0;

        foreach ($ids->chunk($itemsPerJob) as $idChunk) {
            $startId = $idChunk->first();
            $endId = $idChunk->last();

            ProcessDailySales::dispatch($date, $startId, $endId, $chunkSize)
                ->onQueue('daily-sales');

            $jobsCount++;
        }

        return response()->json([
            'message' => 'Pressure mode: daily sales split automatically based on database records',
            'queue' => 'daily-sales',
            'date' => $date,
            'pressure_mode' => true,
            'total_items' => $totalItems,
            'items_per_job' => $itemsPerJob,
            'chunk_size' => $chunkSize,
            'jobs_count' => $jobsCount
        ]);

    } finally {
        optional($lock)->release();
    }
}

    public function deleteOrder($id)
    {
        $user = auth()->user();

        $order = Order::with('orderItems')->find($id);

        if (!$order) {
            return response()->json([
                'message' => 'Order not found'
            ], 404);
        }

        if ($order->user_id != $user->id) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        DB::transaction(function () use ($user, $order) {

            $productIds = $order->orderItems->pluck('product_id')->unique()->values();

            $products = Product::whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($order->orderItems as $item) {
                if (isset($products[$item->product_id])) {
                    $products[$item->product_id]->increment('quantity', $item->quantity);
                }
            }

            $wallet = $user->wallet()
                ->lockForUpdate()
                ->first();

            if ($wallet) {
                $wallet->increment('balance', $order->total_price);
            }

            $order->orderItems()->delete();

            $order->delete();
        });

        return response()->json([
            'message' => 'Order deleted successfully'
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Resource Management Helper Methods - Checkout
    |--------------------------------------------------------------------------
    |
    | هذه الدوال المساعدة تدير الـ slots للتحكم بالتوازي في Checkout
    |
    */

    /**
     * محاولة الحصول على checkout slot
     * 
     * @return string|null معرف الـ slot إذا نجح، أو null إذا كل الـ slots مشغولة
     */
    private function tryAcquireCheckoutSlot(): ?string
    {
        $slots = $this->getActiveCheckoutSlots();

        /*
         * التحقق: هل وصلنا للحد الأقصى؟
         */
        if (count($slots) >= self::MAX_CONCURRENT_CHECKOUTS) {
            return null;
        }

        /*
         * إنشاء slot جديد
         */
        $slotId = uniqid('checkout_slot_', true);
        $expiration = time() + self::CHECKOUT_SLOT_EXPIRATION;

        $slots[$slotId] = $expiration;

        Cache::put(self::CHECKOUT_SLOTS_KEY, $slots, self::CHECKOUT_SLOT_EXPIRATION * 2);

        return $slotId;
    }

    /**
     * تحرير checkout slot
     * 
     * @param string $slotId معرف الـ slot
     */
    private function releaseCheckoutSlot(string $slotId): void
    {
        $slots = Cache::get(self::CHECKOUT_SLOTS_KEY, []);
        unset($slots[$slotId]);

        Cache::put(self::CHECKOUT_SLOTS_KEY, $slots, self::CHECKOUT_SLOT_EXPIRATION * 2);
    }

    /**
     * الحصول على الـ checkout slots النشطة
     * 
     * @return array
     */
    private function getActiveCheckoutSlots(): array
    {
        $slots = Cache::get(self::CHECKOUT_SLOTS_KEY, []);
        $now = time();

        /*
         * تنظيف الـ slots المنتهية الصلاحية
         */
        $activeSlots = array_filter($slots, function ($expiration) use ($now) {
            return $expiration > $now;
        });

        /*
         * تحديث الـ Cache إذا تم حذف slots منتهية
         */
        if (count($activeSlots) !== count($slots)) {
            Cache::put(self::CHECKOUT_SLOTS_KEY, $activeSlots, self::CHECKOUT_SLOT_EXPIRATION * 2);
        }

        return $activeSlots;
    }

    /**
     * الحصول على عدد الـ checkout slots المشغولة حالياً
     * 
     * @return int
     */
    private function getCurrentCheckoutSlotCount(): int
    {
        return count($this->getActiveCheckoutSlots());
    }
}