<?php

use App\Enums\AiRequestStatus;
use App\Enums\AiSource;
use App\Models\AiRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('casts and persists status and source enums', function () {
    $user = User::factory()->create();

    $request = AiRequest::create([
        'user_id' => $user->id,
        'provider' => 'openai',
        'model' => 'gpt-4.1',
        'status' => AiRequestStatus::Completed,
        'source' => AiSource::Api,
    ]);

    $request->refresh();

    expect($request->status)->toBe(AiRequestStatus::Completed)
        ->and($request->source)->toBe(AiSource::Api)
        ->and($request->getRawOriginal('status'))->toBe('completed')
        ->and($request->getRawOriginal('source'))->toBe('api');
});

it('defaults source to web', function () {
    $user = User::factory()->create();

    $request = AiRequest::create([
        'user_id' => $user->id,
        'provider' => 'openai',
        'model' => 'gpt-4.1',
        'status' => AiRequestStatus::Started,
    ]);

    $request->refresh();

    expect($request->source)->toBe(AiSource::Web)
        ->and($request->getRawOriginal('source'))->toBe('web');
});

it('normalizes raw source values', function () {
    expect(AiSource::fromRaw(' API '))->toBe(AiSource::Api)
        ->and(AiSource::fromRaw('telegram'))->toBe(AiSource::Telegram)
        ->and(AiSource::fromRaw('unknown'))->toBe(AiSource::Web)
        ->and(AiSource::fromRaw(null))->toBe(AiSource::Web);
});
