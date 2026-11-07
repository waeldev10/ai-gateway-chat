<?php

namespace App\AI\DTOs;

final readonly class AiRequest
{
    public function __construct(public array $messages) {}

    public static function fromMessages(array $messages): self
    {
        return new self($messages);
    }
}
