<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CartItem;
use Illuminate\Validation\ValidationException;
use App\Models\User;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CartController extends Controller
{
    /**
     * ==========================================================================
     * Resource Management & Capacity Control - HTTP Level
     * ==========================================================================
     * 
     * هذه الآلية تتحكم بعدد الطلبات المتزامنة على مستوى HTTP مباشرة.
     * 
     * الهدف:
     * - منع استهلاك موارد السيرفر بشكل مفرط
     * - رفض الطلبات الزائدة فوراً بـ HTTP 429
     * - مناسب لاختبار JMeter لإثبات Resource Management
     * 
     * آلية العمل:
     * 1. عند وصول طلب، نحاول الحصول على slot من الـ slots المتاحة
     * 2. إذا كل الـ slots مشغولة، نرفض الطلب فوراً بـ 429
     * 3. إذا حصلنا على slot، ننفذ الطلب ثم نحرر الـ slot
     * 
     * الفرق عن Job Middleware:
     * - Job Middleware: يؤجل العمليات (للـ Queue)
     * - HTTP Concurrency: يرفض فوراً (للـ API المباشر)
     */

    /**
     * الحد الأقصى للطلبات المتزامنة
     */
    private const MAX_CONCURRENT_REQUESTS = 3;

    /**
     * مفتاح الـ Cache لتتبع الـ slots
     */
    private const SLOTS_CACHE_KEY = 'cart_api_concurrent_slots';

    /**
     * مدة صلاحية الـ slot بالثواني (لمنع deadlock)
     */
    private const SLOT_EXPIRATION_SECONDS = 30;
/**
     * إضافة منتج للسلة مع Resource Management
     * 
     * هذا الـ endpoint محمي بـ Concurrency Control:
     * - الحد الأقصى: 3 طلبات متزامنة
     * - الطلبات الزائدة: ترفض فوراً بـ HTTP 429
     */
    public function add(Request $request)
    {
        /*
         * =================================================================
         * Resource Management: محاولة الحصول على slot
         * =================================================================
         */
        $slotId = $this->tryAcquireSlot();

        if ($slotId === null) {
            /*
             * كل الـ slots مشغولة
             * نرفض الطلب فوراً بـ HTTP 429
             */
            Log::warning('CartController: Request rejected - max concurrent requests reached', [
                'user_id' => auth()->id(),
                'max_concurrent' => self::MAX_CONCURRENT_REQUESTS,
                'current_slots' => $this->getCurrentSlotCount(),
            ]);

            return response()->json([
                'message' => 'Too many requests. Server is busy, please try again.',
                'error_code' => 'RESOURCE_LIMIT_EXCEEDED',
                'max_concurrent_requests' => self::MAX_CONCURRENT_REQUESTS,
                'retry_after_seconds' => 5,
            ], 429);
        }

        Log::info('CartController: Slot acquired for add request', [
            'user_id' => auth()->id(),
            'slot_id' => $slotId,
            'current_slots' => $this->getCurrentSlotCount(),
        ]);

        try {
            /*
             * =================================================================
             * المنطق الأصلي لإضافة المنتج للسلة
             * =================================================================
             */
            $request->validate([
                'product_id' => 'required|exists:products,id',
                'quantity' => 'required|integer|min:1'
            ]);

            $product = Product::findOrFail($request->product_id);

            // تحقق من الكمية المتاحة
            if ($request->quantity > $product->quantity) {
                return response()->json([
                    'message' => 'Not enough quantity available',
                    'available_quantity' => $product->quantity
                ], 400);
            }

            $item = CartItem::where('user_id', auth()->id())
                ->where('product_id', $request->product_id)
                ->first();

            if ($item) {

                $newQuantity = $item->quantity + $request->quantity;

                if ($newQuantity > $product->quantity) {
                    return response()->json([
                        'message' => 'Not enough quantity available',
                        'available_quantity' => $product->quantity,
                        "your_requested_quantity" => $newQuantity,
                        "your_current_quantity_in_cart" => $item->quantity
                    ], 400);
                }

                $item->quantity = $newQuantity;
                $item->save();

            } else {
                CartItem::create([
                    'user_id' => auth()->id(),
                    'product_id' => $request->product_id,
                    'quantity' => $request->quantity
                ]);
            }

            return response()->json([
                'message' => 'Added to cart'
            ]);

        } finally {
            /*
             * =================================================================
             * Resource Management: تحرير الـ slot بعد الانتهاء
             * =================================================================
             */
            $this->releaseSlot($slotId);

            Log::info('CartController: Slot released after add request', [
                'user_id' => auth()->id(),
                'slot_id' => $slotId,
                'remaining_slots' => $this->getCurrentSlotCount(),
            ]);
        }
    }
///////////////////
public function show()
{
    return CartItem::with('product')
        ->where('user_id', auth()->id())
        ->get();
}

/////////////////////////////////////
public function remove(Request $request)
{
    $request->validate([
        'product_id' => 'required|exists:products,id',
        'quantity' => 'required|integer|min:1'
    ]);

    $cartItem = CartItem::where('user_id', auth()->id())
        ->where('product_id', $request->product_id)
        ->first();

    if (!$cartItem) {
        return response()->json([
            'message' => 'Item not found in cart'
        ], 404);
    }

    // إذا الكمية أكبر من المطلوب للحذف
    if ($cartItem->quantity > $request->quantity) {
        $cartItem->quantity -= $request->quantity;
        $cartItem->save();

        return response()->json([
            'message' => 'Quantity reduced',
            'quantity' => $cartItem->quantity
        ]);
    }

    // إذا بدنا نحذف كل الكمية
    $cartItem->delete();

    return response()->json([
        'message' => 'Item removed completely'
    ]);
}

    /*
    |--------------------------------------------------------------------------
    | Resource Management Helper Methods
    |--------------------------------------------------------------------------
    |
    | هذه الدوال المساعدة تدير الـ slots للتحكم بالتوازي
    |
    */

    /**
     * محاولة الحصول على slot
     * 
     * @return string|null معرف الـ slot إذا نجح، أو null إذا كل الـ slots مشغولة
     */
    private function tryAcquireSlot(): ?string
    {
        $slots = $this->getActiveSlots();

        /*
         * التحقق: هل وصلنا للحد الأقصى؟
         */
        if (count($slots) >= self::MAX_CONCURRENT_REQUESTS) {
            return null;
        }

        /*
         * إنشاء slot جديد
         */
        $slotId = uniqid('http_slot_', true);
        $expiration = time() + self::SLOT_EXPIRATION_SECONDS;

        $slots[$slotId] = $expiration;

        Cache::put(self::SLOTS_CACHE_KEY, $slots, self::SLOT_EXPIRATION_SECONDS * 2);

        return $slotId;
    }

    /**
     * تحرير slot
     * 
     * @param string $slotId معرف الـ slot
     */
    private function releaseSlot(string $slotId): void
    {
        $slots = Cache::get(self::SLOTS_CACHE_KEY, []);
        unset($slots[$slotId]);

        Cache::put(self::SLOTS_CACHE_KEY, $slots, self::SLOT_EXPIRATION_SECONDS * 2);
    }

    /**
     * الحصول على الـ slots النشطة (غير المنتهية الصلاحية)
     * 
     * @return array
     */
    private function getActiveSlots(): array
    {
        $slots = Cache::get(self::SLOTS_CACHE_KEY, []);
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
            Cache::put(self::SLOTS_CACHE_KEY, $activeSlots, self::SLOT_EXPIRATION_SECONDS * 2);
        }

        return $activeSlots;
    }

    /**
     * الحصول على عدد الـ slots المشغولة حالياً
     * 
     * @return int
     */
    private function getCurrentSlotCount(): int
    {
        return count($this->getActiveSlots());
    }
}
