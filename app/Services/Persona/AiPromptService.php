<?php

namespace App\Services\Persona;

use App\Models\AiPersona;
use App\Models\Conversation;

class AiPromptService
{
    public function __construct(
        private readonly AiPersonaService $personaService,
    ) {}

    public function withSystemPrompt(Conversation $conversation, array $messages, ?AiPersona $persona = null): array
    {
        $systemPrompt = $this->systemPrompt($conversation, $persona);

        if ($systemPrompt === '') {
            return $messages;
        }

        return [
            ['role' => 'system', 'content' => $systemPrompt],
            ...$messages,
        ];
    }

    public function systemPrompt(Conversation $conversation, ?AiPersona $persona = null): string
    {
        $persona ??= $this->personaService->resolveForConversation($conversation);

        return trim((string) $persona->system_prompt);
    }
}
