<?php

namespace App\AI\Contracts;

use App\AI\DTOs\AiRequest;
use App\AI\DTOs\AiResponse;
use Generator;

interface AiDriver
{
    public function generate(AiRequest $request): AiResponse;

    public function stream(AiRequest $request): Generator;
}
