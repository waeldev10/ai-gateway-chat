<?php

namespace App\Enums;

enum AiSource: string
{
    case Web = 'web';
    case Api = 'api';
    case Telegram = 'telegram';

    public static function fromRaw(?string $value): self
    {
        return self::tryFrom(strtolower(trim((string) $value))) ?? self::Web;
    }
}
