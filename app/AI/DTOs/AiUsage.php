<?php

namespace App\AI\DTOs;

final readonly class AiUsage
{
    public function __construct(
        public ?int $promptTokens = null,
        public ?int $completionTokens = null,
        public ?int $totalTokens = null,
    ) {}

    public static function fromTokens(
        ?int $promptTokens,
        ?int $completionTokens,
        ?int $totalTokens = null,
    ): ?self {
        if ($promptTokens === null && $completionTokens === null && $totalTokens === null) {
            return null;
        }

        if ($totalTokens === null && $promptTokens !== null && $completionTokens !== null) {
            $totalTokens = $promptTokens + $completionTokens;
        }

        return new self($promptTokens, $completionTokens, $totalTokens);
    }
}
