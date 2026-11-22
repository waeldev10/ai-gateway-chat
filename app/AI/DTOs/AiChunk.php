<?php

namespace App\AI\DTOs;

/** يمثل جزء من الرد المتدفق
 * وقد يحمل بيانات الإنهاء واستهلاك الرموز في آخر الأجزاء */
final readonly class AiChunk
{
    public function __construct(
        public string $content,
        public ?string $finishReason = null,
        public ?AiUsage $usage = null,
    ) {}
}
