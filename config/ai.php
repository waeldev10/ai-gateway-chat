<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Connection
    |--------------------------------------------------------------------------
    |
    | This connection is used when the application requests an AI response.
    | Select a connection name from the "connections" array below. The
    | connection may be changed through the AI_CONNECTION environment
    | variable without changing application code.
    |
    */

    'default' => env('AI_CONNECTION', 'openai'),

    /*
    |--------------------------------------------------------------------------
    | AI Connections
    |--------------------------------------------------------------------------
    |
    | Each connection defines the protocol driver, credentials, endpoint,
    | and default model used by the AI layer. The "driver" value is a
    | protocol identifier, not a company name. Providers that expose a
    | compatible protocol share the same driver implementation.
    |
    | Supported drivers:
    | - chat-completions      OpenAI Chat Completions protocol
    | - responses             OpenAI Responses protocol
    | - anthropic-messages    Anthropic Messages protocol
    | - gemini-interactions   Google Gemini Interactions API
    |
    | Every connection accepts a <PREFIX>_DRIVER environment variable so the
    | protocol can be changed without editing application code. There is no
    | global driver override; each connection selects its own driver.
    |
    */

    'connections' => [

        'openai' => [
            'driver' => env('OPENAI_DRIVER', 'chat-completions'),
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'model' => env('OPENAI_MODEL'),
            'timeout' => (int) env('OPENAI_TIMEOUT', 60),
        ],

        'openai-responses' => [
            'driver' => env('OPENAI_RESPONSES_DRIVER', 'responses'),
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'model' => env('OPENAI_RESPONSES_MODEL') ?: env('OPENAI_MODEL'),
            'timeout' => (int) env('OPENAI_TIMEOUT', 60),
        ],

        'anthropic' => [
            'driver' => env('ANTHROPIC_DRIVER', 'anthropic-messages'),
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
            'model' => env('ANTHROPIC_MODEL'),
            'timeout' => (int) env('ANTHROPIC_TIMEOUT', 60),
            'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 4096),
            'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
        ],

        'gemini' => [
            'driver' => env('GEMINI_DRIVER', 'gemini-interactions'),
            'api_key' => env('GEMINI_API_KEY'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'model' => env('GEMINI_MODEL'),
            'timeout' => (int) env('GEMINI_TIMEOUT', 60),
        ],

        'deepseek' => [
            'driver' => env('DEEPSEEK_DRIVER', 'chat-completions'),
            'api_key' => env('DEEPSEEK_API_KEY'),
            'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com/v1'),
            'model' => env('DEEPSEEK_MODEL'),
            'timeout' => (int) env('DEEPSEEK_TIMEOUT', 60),
        ],

        'opencode' => [
            'driver' => env('OPENCODE_DRIVER', 'chat-completions'),
            'api_key' => env('OPENCODE_API_KEY'),
            'base_url' => env('OPENCODE_BASE_URL', 'https://opencode.ai/inference/openai/v1'),
            'model' => env('OPENCODE_MODEL'),
            'timeout' => (int) env('OPENCODE_TIMEOUT', 60),
        ],

        'glm' => [
            'driver' => env('GLM_DRIVER', 'chat-completions'),
            'api_key' => env('GLM_API_KEY'),
            'base_url' => env('GLM_BASE_URL', 'https://api.z.ai/api/paas/v4'),
            'model' => env('GLM_MODEL'),
            'timeout' => (int) env('GLM_TIMEOUT', 60),
        ],

        'kimi' => [
            'driver' => env('KIMI_DRIVER', 'chat-completions'),
            'api_key' => env('KIMI_API_KEY'),
            'base_url' => env('KIMI_BASE_URL', 'https://api.moonshot.ai/v1'),
            'model' => env('KIMI_MODEL'),
            'timeout' => (int) env('KIMI_TIMEOUT', 60),
        ],

        'openrouter' => [
            'driver' => env('OPENROUTER_DRIVER', 'chat-completions'),
            'api_key' => env('OPENROUTER_API_KEY'),
            'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
            'model' => env('OPENROUTER_MODEL'),
            'timeout' => (int) env('OPENROUTER_TIMEOUT', 60),
        ],

        'ollama' => [
            'driver' => env('OLLAMA_DRIVER', 'chat-completions'),
            'api_key' => env('OLLAMA_API_KEY') ?: 'ollama',
            'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434/v1'),
            'model' => env('OLLAMA_MODEL'),
            'timeout' => (int) env('OLLAMA_TIMEOUT', 60),
        ],

        'qwen' => [
            'driver' => env('QWEN_DRIVER', 'chat-completions'),
            'api_key' => env('DASHSCOPE_API_KEY'),
            'base_url' => env('QWEN_BASE_URL', 'https://dashscope.aliyuncs.com/compatible-mode/v1'),
            'model' => env('QWEN_MODEL'),
            'timeout' => (int) env('QWEN_TIMEOUT', 60),
        ],

        'llama' => [
            'driver' => env('LLAMA_DRIVER', 'chat-completions'),
            'api_key' => env('LLAMA_API_KEY'),
            'base_url' => env('LLAMA_BASE_URL', 'https://api.llama.com/compat/v1'),
            'model' => env('LLAMA_MODEL'),
            'timeout' => (int) env('LLAMA_TIMEOUT', 60),
        ],

        'grok' => [
            'driver' => env('GROK_DRIVER', 'chat-completions'),
            'api_key' => env('XAI_API_KEY'),
            'base_url' => env('GROK_BASE_URL', 'https://api.x.ai/v1'),
            'model' => env('GROK_MODEL'),
            'timeout' => (int) env('GROK_TIMEOUT', 60),
        ],

    ],

];
