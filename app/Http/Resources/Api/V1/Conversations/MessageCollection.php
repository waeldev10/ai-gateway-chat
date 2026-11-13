<?php

namespace App\Http\Resources\Api\V1\Conversations;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class MessageCollection extends ResourceCollection
{
    public $collects = MessageResource::class;

    public function toArray(Request $request): array
    {
        return array_reverse(parent::toArray($request));
    }
}
