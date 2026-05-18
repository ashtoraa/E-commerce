<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Resource Management & Capacity Control Middleware
 * 
 * هذا الـ Middleware يتحكم بعدد العمليات المتوازية التي تنفذ في نفس الوقت.
 * 
 * الهدف:
 * - منع استهلاك الموارد بشكل مفرط (انهيار النظام)
 * - عدم تقليل العمليات بشكل يبطئ الاستجابة
 * 
 * آلية العمل:
 * 1. قبل تنفيذ أي Job، يتحقق من عدد العمليات الجارية حالياً
 * 2. إذا وصلنا للحد الأقصى، الـ Job ينتظر ويعاد للـ Queue
 * 3. إذا كان هناك slot متاح، يتم تنفيذ الـ Job
 * 4. بعد انتهاء الـ Job، يتم تحرير الـ slot
 * 
 * لماذا اخترنا هذا الحل:
 * - يعمل على مستوى الـ Job (أدق تحكم)
 * - لا يرفض الطلبات، بل يؤجلها (أفضل تجربة مستخدم)
 * - قابل للتعديل عبر الـ .env (مرونة)
 * - يعمل مع أي عدد من الـ Workers (scalable)
 */
class ConcurrencyLimiter
{
    /**
     * اسم المفتاح في الـ Cache لتتبع العمليات الجارية
     */
    private string $key;

    /**
     * الحد الأقصى للعمليات المتوازية
     */
    private int $maxConcurrent;

    /**
     * كم ثانية ينتظر الـ Job قبل إعادة المحاولة
     */
    private int $releaseAfterSeconds;

    /**
     * مدة صلاحية القفل بالثواني (لمنع الـ deadlock)
     */
    private int $lockExpirationSeconds;

    public function __construct(
        string $key = 'job_concurrency',
        ?int $maxConcurrent = null,
        ?int $releaseAfterSeconds = null,
        ?int $lockExpirationSeconds = null
    ) {
        $this->key = $key;
        
        /*
         * القيم الافتراضية من الـ config
         * يمكن تعديلها من الـ .env
         */
        $this->maxConcurrent = $maxConcurrent 
            ?? (int) config('app.max_concurrent_jobs', 5);
        
        $this->releaseAfterSeconds = $releaseAfterSeconds 
            ?? (int) config('app.job_release_delay_seconds', 10);
        
        $this->lockExpirationSeconds = $lockExpirationSeconds 
            ?? (int) config('app.job_lock_expiration_seconds', 1800);
    }

    /**
     * معالجة الـ Job
     * 
     * @param mixed $job الـ Job المراد تنفيذه
     * @param Closure $next الدالة التالية في السلسلة
     */
    public function handle($job, Closure $next): void
    {
        $currentCount = $this->getCurrentCount();
        
        /*
         * تسجيل محاولة التنفيذ للمراقبة
         */
        Log::info('ConcurrencyLimiter: Job attempting to acquire slot', [
            'job_class' => get_class($job),
            'key' => $this->key,
            'current_count' => $currentCount,
            'max_concurrent' => $this->maxConcurrent,
        ]);

        /*
         * التحقق: هل وصلنا للحد الأقصى؟
         */
        if ($currentCount >= $this->maxConcurrent) {
            /*
             * الحد الأقصى مُستنفد
             * نعيد الـ Job للـ Queue لينتظر
             */
            Log::warning('ConcurrencyLimiter: Max concurrent jobs reached, releasing job back to queue', [
                'job_class' => get_class($job),
                'key' => $this->key,
                'current_count' => $currentCount,
                'max_concurrent' => $this->maxConcurrent,
                'release_after_seconds' => $this->releaseAfterSeconds,
            ]);

            $job->release($this->releaseAfterSeconds);
            return;
        }

        /*
         * هناك slot متاح
         * نحجز الـ slot ونبدأ التنفيذ
         */
        $slotId = $this->acquireSlot();

        Log::info('ConcurrencyLimiter: Slot acquired, executing job', [
            'job_class' => get_class($job),
            'key' => $this->key,
            'slot_id' => $slotId,
            'new_count' => $this->getCurrentCount(),
        ]);

        try {
            /*
             * تنفيذ الـ Job الفعلي
             */
            $next($job);
            
        } finally {
            /*
             * مهم جداً: تحرير الـ slot بعد الانتهاء
             * حتى لو حصل خطأ
             */
            $this->releaseSlot($slotId);

            Log::info('ConcurrencyLimiter: Slot released', [
                'job_class' => get_class($job),
                'key' => $this->key,
                'slot_id' => $slotId,
                'remaining_count' => $this->getCurrentCount(),
            ]);
        }
    }

    /**
     * الحصول على عدد العمليات الجارية حالياً
     */
    private function getCurrentCount(): int
    {
        $slots = Cache::get($this->getSlotsCacheKey(), []);
        
        /*
         * تنظيف الـ slots المنتهية الصلاحية
         */
        $now = time();
        $activeSlots = array_filter($slots, function ($expiration) use ($now) {
            return $expiration > $now;
        });

        /*
         * تحديث الـ Cache بالـ slots النشطة فقط
         */
        if (count($activeSlots) !== count($slots)) {
            Cache::put($this->getSlotsCacheKey(), $activeSlots, $this->lockExpirationSeconds);
        }

        return count($activeSlots);
    }

    /**
     * حجز slot جديد
     * 
     * @return string معرف الـ slot
     */
    private function acquireSlot(): string
    {
        $slotId = uniqid('slot_', true);
        $expiration = time() + $this->lockExpirationSeconds;

        $slots = Cache::get($this->getSlotsCacheKey(), []);
        $slots[$slotId] = $expiration;

        Cache::put($this->getSlotsCacheKey(), $slots, $this->lockExpirationSeconds);

        return $slotId;
    }

    /**
     * تحرير slot
     * 
     * @param string $slotId معرف الـ slot
     */
    private function releaseSlot(string $slotId): void
    {
        $slots = Cache::get($this->getSlotsCacheKey(), []);
        unset($slots[$slotId]);

        Cache::put($this->getSlotsCacheKey(), $slots, $this->lockExpirationSeconds);
    }

    /**
     * مفتاح الـ Cache للـ slots
     */
    private function getSlotsCacheKey(): string
    {
        return 'concurrency_limiter_slots_' . $this->key;
    }
}
