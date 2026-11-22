<?php

namespace App\AI;

use App\AI\Contracts\AiDriver;
use App\AI\DTOs\AiRequest;
use App\AI\DTOs\AiResponse;
use Generator;

/** يوفر واجهة موحدة للتوليد والبث ويختار الاتصال الافتراضي ثم يفوض التنفيذ للمشغل الخاص به */
class AiService
{
    public function __construct(private readonly AiDriverFactory $factory) {}

    public function generate(AiRequest $request): AiResponse
    {
        return $this->driver()->generate($request);
    }

    public function stream(AiRequest $request): Generator
    {
        return $this->driver()->stream($request);
    }

    private function driver(): AiDriver
    {
        $connection = config('ai.default');

        return $this->factory->make(is_string($connection) ? trim($connection) : '');
    }
}
