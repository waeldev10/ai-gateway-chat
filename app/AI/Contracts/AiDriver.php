<?php

namespace App\AI\Contracts;

use App\AI\DTOs\AiRequest;
use App\AI\DTOs\AiResponse;
use Generator;

/** يحدد عقد المشغل او البروتوكل لتحويل الطلب الموحد  اما ل رد كامل أو تدفق أجزاء متتابعة. */
interface AiDriver
{
    public function generate(AiRequest $request): AiResponse;

    public function stream(AiRequest $request): Generator;
}
