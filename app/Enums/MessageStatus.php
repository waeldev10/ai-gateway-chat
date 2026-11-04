<?php

namespace App\Enums;

enum MessageStatus: string
{
    case Pending = 'pending';
    case Complete = 'complete';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
