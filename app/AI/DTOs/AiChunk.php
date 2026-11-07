<?php

namespace App\AI\DTOs;

final readonly class AiChunk
{
    public function __construct(
        public string $content,
        public ?string $finishReason = null,
        public ?AiUsage $usage = null,
    ) {}
}
