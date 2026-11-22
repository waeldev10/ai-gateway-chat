<?php

namespace App\Http\Controllers\Api\V1\Conversations;

use App\Enums\AiSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Conversations\BulkDeleteConversationsRequest;
use App\Http\Requests\Api\V1\Conversations\ListConversationsRequest;
use App\Http\Requests\Api\V1\Conversations\ListMessagesRequest;
use App\Http\Requests\Api\V1\Conversations\SendMessageRequest;
use App\Http\Requests\Api\V1\Conversations\StoreConversationRequest;
use App\Http\Requests\Api\V1\Conversations\UpdateConversationRequest;
use App\Http\Resources\Api\V1\Conversations\ConversationResource;
use App\Http\Resources\Api\V1\Conversations\MessageCollection;
use App\Http\Resources\Api\V1\Conversations\MessageExchangeResource;
use App\Models\Conversation;
use App\Services\Conversation\ConversationBulkService;
use App\Services\Conversation\ConversationMessageService;
use App\Services\Conversation\ConversationSearchService;
use App\Services\Conversation\ConversationService;
use App\Services\Conversation\ConversationTitleService;
use App\Services\Message\MessageService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ConversationController extends Controller
{
    public function __construct(
        private readonly ConversationService $conversationService,
        private readonly ConversationSearchService $conversationSearchService,
        private readonly ConversationBulkService $conversationBulkService,
        private readonly ConversationMessageService $conversationMessageService,
        private readonly MessageService $messageService,
        private readonly ConversationTitleService $titles,
    ) {}

    public function index(ListConversationsRequest $request): JsonResponse
    {
        $conversations = $this->conversationSearchService->paginate(
            $request->user(),
            $request->validated('search'),
            $request->integer('per_page', 15),
        );

        return ApiResponse::paginated(
            ConversationResource::collection($conversations),
        );
    }

    public function store(StoreConversationRequest $request): JsonResponse
    {
        $conversation = $this->conversationService->create(
            $request->user(),
            $request->safe()->only(['title']),
        );

        return response()->json([
            'data' => (new ConversationResource($conversation))->resolve($request),
            'message' => 'تم إنشاء المحادثة بنجاح.',
        ], 201);
    }

    public function start(SendMessageRequest $request): JsonResponse
    {
        $conversation = $this->conversationService->create($request->user(), []);
        $this->authorize('sendMessage', $conversation);

        $content = $request->validated('content');
        $exchange = $this->messageService->send($conversation, $content, AiSource::Api);

        $this->titles->maybeGenerate(
            $conversation,
            $content,
            $exchange['assistant_message']->content,
            AiSource::Api,
        );

        $exchangeData = (new MessageExchangeResource($exchange))->resolve($request);

        return response()->json([
            'data' => [
                'conversation' => (new ConversationResource($conversation))->resolve($request),
                'user_message' => $exchangeData['user_message'],
                'assistant_message' => $exchangeData['assistant_message'],
            ],
            'message' => 'تم إنشاء المحادثة وإرسال الرسالة بنجاح.',
        ], 201);
    }

    public function show(Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        return ApiResponse::resource(
            new ConversationResource($this->conversationService->show($conversation)),
        );
    }

    public function update(UpdateConversationRequest $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $conversation);

        $conversation = $this->conversationService->rename(
            $conversation,
            $request->validated('title'),
        );

        return ApiResponse::resource(
            new ConversationResource($conversation),
            'تم تحديث المحادثة بنجاح.',
        );
    }

    public function destroy(Conversation $conversation): JsonResponse
    {
        $this->authorize('delete', $conversation);

        $this->conversationService->delete($conversation);

        return ApiResponse::action('تم حذف المحادثة بنجاح.');
    }

    public function pin(Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $conversation);

        return ApiResponse::resource(
            new ConversationResource($this->conversationService->pin($conversation)),
            'تم تثبيت المحادثة بنجاح.',
        );
    }

    public function unpin(Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $conversation);

        return ApiResponse::resource(
            new ConversationResource($this->conversationService->unpin($conversation)),
            'تم إلغاء تثبيت المحادثة بنجاح.',
        );
    }

    public function destroyMany(BulkDeleteConversationsRequest $request): JsonResponse
    {
        $deleted = $this->conversationBulkService->deleteFor(
            $request->user(),
            $request->validated('ids'),
        );

        return ApiResponse::action(
            $deleted === 1
                ? 'تم حذف المحادثة بنجاح.'
                : 'تم حذف المحادثات المحددة بنجاح.',
        );
    }

    public function messages(ListMessagesRequest $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $messages = $this->conversationMessageService->paginate(
            $conversation,
            $request->validated('limit'),
        );

        return ApiResponse::paginated(
            new MessageCollection($messages),
        );
    }
}
