<?php

namespace App\AI\DTOs;

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
