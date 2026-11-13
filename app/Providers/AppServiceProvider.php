<?php

namespace App\Providers;

use App\Models\AiPersona;
use App\Models\Conversation;
use App\Policies\AiPersonaPolicy;
use App\Policies\ConversationPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
   
    public function register(): void
    {
        
    }

  
    public function boot(): void
    {
        $this->registerPolicies();
        $this->configureRateLimiting();
    }

    private function registerPolicies(): void
    {
        Gate::policy(Conversation::class, ConversationPolicy::class);
        Gate::policy(AiPersona::class, AiPersonaPolicy::class);
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request): array {
            $email = $this->normalizedEmail($request);
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(5)->by($email.'|'.$ip),
                Limit::perMinute(20)->by($ip),
            ];
        });

        RateLimiter::for('register', function (Request $request): Limit {
            return Limit::perMinute(5)->by((string) $request->ip());
        });

        RateLimiter::for('api', function (Request $request): Limit {
            $user = $request->user() ?? $request->user('sanctum');

            return Limit::perMinute(60)
                ->by((string) ($user?->getAuthIdentifier() ?? $request->ip()));
        });

        RateLimiter::for('ai-stream', function (Request $request): Limit {
            $key = $request->user()?->getKey() ?? $request->ip();

            return Limit::perMinute(20)->by('ai-stream:'.$key);
        });  
        
        RateLimiter::for('api-ai', function (Request $request): Limit {
            $key = $request->user()?->getKey() ?? $request->ip();

            return Limit::perMinute(20)->by('api-ai:'.$key);
        });
    }

    private function normalizedEmail(Request $request): string
    {
        $email = $request->input('email');

        return is_string($email) ? Str::lower(trim($email)) : '';
    }
}
