<?php

namespace App\AI\DTOs;

/** يمثل الرد الكامل بعد توحيده مع الاتصال والنموذج وسبب الإنهاء واستهلاك الرموز */
final readonly class AiResponse
{
    public function __construct(
        public string $content,
        public string $connection,
        public string $model,
        public ?string $finishReason = null,
        public ?AiUsage $usage = null,
    ) {}
}
