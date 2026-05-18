<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Jobs\Middleware\ConcurrencyLimiter;

class ProcessDailySales implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200;

    /**
     * Resource Management: تحديد الـ Middleware للتحكم بالتوازي
     * 
     * هذا يضمن عدم تشغيل أكثر من الحد المسموح من الـ Jobs في نفس الوقت
     * مما يمنع استهلاك الموارد بشكل مفرط
     * 
     * @return array
     */
    public function middleware(): array
    {
        return [
            new ConcurrencyLimiter(
                key: 'daily_sales_processing',
                maxConcurrent: (int) config('app.max_daily_sales_jobs', 3)
            ),
        ];
    }

    public function __construct(
        public string $date,
        public int $startId,
        public int $endId,
        public int $chunkSize = 500
    ) {}

    public function handle(): void
    {
        $workerInfo = [
            'host' => gethostname(),
            'process_id' => getmypid(),
        ];

        Log::info('Start Daily Sales Job', [
            'date' => $this->date,
            'start_id' => $this->startId,
            'end_id' => $this->endId,
            'chunk_size' => $this->chunkSize,
            'host' => $workerInfo['host'],
            'process_id' => $workerInfo['process_id'],
        ]);

        DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->whereDate('orders.created_at', $this->date)
            ->whereBetween('order_items.id', [$this->startId, $this->endId])
            ->select(
                'order_items.id as item_id',
                'order_items.product_id as product_id',
                'order_items.quantity',
                'order_items.price',
                'products.cost_price'
            )
            ->chunkById($this->chunkSize, function ($items) use ($workerInfo) {

                $summary = [];

                Log::info('Processing daily sales chunk', [
                    'records_count' => $items->count(),
                    'start_id' => $this->startId,
                    'end_id' => $this->endId,
                    'chunk_size' => $this->chunkSize,
                    'host' => $workerInfo['host'],
                    'process_id' => $workerInfo['process_id'],
                ]);

                foreach ($items as $item) {

                    if (!$item->product_id) {
                        Log::warning('Skipped order item because product_id is missing', [
                            'item_id' => $item->item_id ?? null,
                        ]);

                        continue;
                    }

                    $productId = (int) $item->product_id;

                    if (!isset($summary[$productId])) {
                        $summary[$productId] = [
                            'summary_date' => $this->date,
                            'product_id' => $productId,
                            'total_quantity' => 0,
                            'total_sales' => 0,
                            'total_cost' => 0,
                            'total_profit' => 0,
                        ];
                    }

                    $quantity = (int) $item->quantity;
                    $sellingPrice = (float) $item->price;
                    $costPrice = (float) $item->cost_price;

                    $sales = $quantity * $sellingPrice;
                    $cost = $quantity * $costPrice;
                    $profit = $sales - $cost;

                    $summary[$productId]['total_quantity'] += $quantity;
                    $summary[$productId]['total_sales'] += $sales;
                    $summary[$productId]['total_cost'] += $cost;
                    $summary[$productId]['total_profit'] += $profit;
                }

                $this->saveSummary($summary);

            }, 'order_items.id', 'item_id');

        Log::info('End Daily Sales Job', [
            'date' => $this->date,
            'start_id' => $this->startId,
            'end_id' => $this->endId,
            'host' => $workerInfo['host'],
            'process_id' => $workerInfo['process_id'],
        ]);
    }

    private function saveSummary(array $summary): void
    {
        if (empty($summary)) {
            return;
        }

        DB::transaction(function () use ($summary) {

            foreach ($summary as $data) {

                /*
                 * مهم:
                 * يجب وجود unique index على:
                 * summary_date + product_id
                 */
                DB::table('daily_sales_summaries')->insertOrIgnore([
                    'summary_date' => $data['summary_date'],
                    'product_id' => $data['product_id'],
                    'total_quantity' => 0,
                    'total_sales' => 0,
                    'total_cost' => 0,
                    'total_profit' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('daily_sales_summaries')
                    ->where('summary_date', $data['summary_date'])
                    ->where('product_id', $data['product_id'])
                    ->update([
                        'total_quantity' => DB::raw('total_quantity + ' . (int) $data['total_quantity']),
                        'total_sales' => DB::raw('total_sales + ' . (float) $data['total_sales']),
                        'total_cost' => DB::raw('total_cost + ' . (float) $data['total_cost']),
                        'total_profit' => DB::raw('total_profit + ' . (float) $data['total_profit']),
                        'updated_at' => now(),
                    ]);
            }
        });
    }
}