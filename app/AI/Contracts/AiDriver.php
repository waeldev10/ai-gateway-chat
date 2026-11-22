<?php

namespace App\AI\Contracts;

use App\AI\DTOs\AiRequest;
use App\AI\DTOs\AiResponse;
use Generator;

/** يحدد عقد السائق لتحويل الطلب الموحد إلى رد كامل أو تدفق أجزاء متتابعة. */
interface AiDriver
{
    public function generate(AiRequest $request): AiResponse;

    public function stream(AiRequest $request): Generator;
}
