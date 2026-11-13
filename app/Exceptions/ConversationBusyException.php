<?php

namespace App\Exceptions;

use RuntimeException;

final class ConversationBusyException extends RuntimeException
{
    public readonly string $problemCode;

    private function __construct(string $message, string $problemCode)
    {
        parent::__construct($message);

        $this->problemCode = $problemCode;
    }

    public static function operationInProgress(): self
    {
        return new self(
            'هناك عملية أخرى قيد التنفيذ على هذه المحادثة. يرجى إعادة المحاولة لاحقاً.',
            'conversation_busy',
        );
    }
}
