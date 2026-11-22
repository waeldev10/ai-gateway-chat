<?php

namespace App\AI\DTOs;

/** يحمل رسائل المحادثة بصيغة موحدة قبل إرسالها إلى مشغل المزود. */
final readonly class AiRequest
{
    public function __construct(public array $messages) {}

    public static function fromMessages(array $messages): self
    {
        return new self($messages);
    }
}
