<?php

namespace App\Http\Controllers\Api\V1\Conversations;

use App\Enums\AiSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Conversations\ContinueConversationRequest;
use App\Http\Requests\Api\V1\Conversations\RegenerateMessageRequest;
use App\Http\Requests\Api\V1\Conversations\SendMessageRequest;
use App\Http\Requests\Api\V1\Conversations\UpdateMessageRequest;
use App\Http\Resources\Api\V1\Conversations\MessageExchangeResource;
use App\Http\Resources\Api\V1\Conversations\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Message\MessageEditingService;
use App\Services\Message\MessageRegenerationService;
use App\Services\Message\MessageService;
use App\Services\Persona\AiPersonaService;
use Illuminate\Http\JsonResponse;

class MessageController extends Controller
{
    public function __construct(
        private readonly MessageService $messageService,
        private readonly MessageRegenerationService $messageRegenerationService,
        private readonly MessageEditingService $messageEditingService,
        private readonly AiPersonaService $personaService,
    ) {}

    public function store(SendMessageRequest $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('sendMessage', $conversation);

        $persona = $this->personaService->resolveForRequest(
            $request->user(),
            $request->validated('persona_id'),
        );

        $exchange = $this->messageService->send(
            $conversation,
            $request->validated('content'),
            AiSource::Api,
            $persona,
        );

        return response()->json([
            'data' => (new MessageExchangeResource($exchange))->resolve($request),
            'message' => 'تم إرسال الرسالة بنجاح.',
        ], 201);
    }

    public function continueConversation(ContinueConversationRequest $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('sendMessage', $conversation);

        $persona = $this->personaService->resolveForRequest(
            $request->user(),
            $request->validated('persona_id'),
        );

        $message = $this->messageService->continueConversation(
            $conversation,
            AiSource::Api,
            $persona,
        );

        return response()->json([
            'data' => (new MessageResource($message))->resolve($request),
            'message' => 'تم متابعة المحادثة بنجاح.',
        ]);
    }

    public function regenerate(
        RegenerateMessageRequest $request,
        Conversation $conversation,
        Message $message,
    ): JsonResponse {
        $this->authorize('sendMessage', $conversation);

        $persona = $this->personaService->resolveForRequest(
            $request->user(),
            $request->validated('persona_id'),
        );

        $regenerated = $this->messageRegenerationService->regenerate(
            $conversation,
            $message,
            AiSource::Api,
            $persona,
        );

        return response()->json([
            'data' => (new MessageResource($regenerated))->resolve($request),
            'message' => 'تم إعادة توليد الرد بنجاح.',
        ]);
    }

    public function update(UpdateMessageRequest $request, Conversation $conversation, Message $message): JsonResponse
    {
        $this->authorize('sendMessage', $conversation);

        $persona = $this->personaService->resolveForRequest(
            $request->user(),
            $request->validated('persona_id'),
        );

        $exchange = $this->messageEditingService->edit(
            $conversation,
            $message,
            $request->validated('content'),
            AiSource::Api,
            $persona,
        );

        return response()->json([
            'data' => (new MessageExchangeResource($exchange))->resolve($request),
            'message' => 'تم تعديل الرسالة بنجاح.',
        ]);
    }
}
