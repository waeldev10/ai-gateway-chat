<?php

namespace App\Http\Resources\Api\V1\Conversations;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array{user_message: Message, assistant_message: Message} $resource
 */
class MessageExchangeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'user_message' => new MessageResource($this->resource['user_message']),
            'assistant_message' => new MessageResource($this->resource['assistant_message']),
        ];
    }
}
