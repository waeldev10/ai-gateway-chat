<?php

namespace App\Livewire\Auth\Concerns;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

trait HandlesRateLimiting
{
    
    private function resolveRateLimits(string $name, array $input = []): array
    {
        $request = clone request();

        if ($input !== []) {
            $request->merge($input);
        }

        $limiter = RateLimiter::limiter($name);

        return Collection::wrap($limiter($request))
            ->map(fn ($limit): array => [
                'key' => md5($name.$limit->key),
                'max' => $limit->maxAttempts,
                'decay' => $limit->decaySeconds,
            ])
            ->all();
    }


    private function ensureRateLimitNotExceeded(array $limits): void
    {
        foreach ($limits as $limit) {
            if (RateLimiter::tooManyAttempts($limit['key'], $limit['max'])) {
                throw ValidationException::withMessages([
                    'email' => __('auth.throttle', [
                        'seconds' => RateLimiter::availableIn($limit['key']),
                    ]),
                ]);
            }
        }
    }


    private function hitRateLimits(array $limits): void
    {
        foreach ($limits as $limit) {
            RateLimiter::hit($limit['key'], $limit['decay']);
        }
    }

    private function rateLimitExceeded(array $limits): bool
    {
        foreach ($limits as $limit) {
            if (RateLimiter::tooManyAttempts($limit['key'], $limit['max'])) {
                return true;
            }
        }

        return false;
    }
    
}
