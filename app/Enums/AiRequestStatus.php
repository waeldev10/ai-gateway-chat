<?php

namespace App\Enums;

enum AiRequestStatus: string
{
    case Started = 'started';
    case Completed = 'completed';
    case Failed = 'failed';
    case Blocked = 'blocked';
}
