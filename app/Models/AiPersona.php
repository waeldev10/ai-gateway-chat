<?php

namespace App\Models;

use Database\Factories\AiPersonaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'name', 'system_prompt', 'is_active', 'is_default'])]
class AiPersona extends Model
{
    /** @use HasFactory<AiPersonaFactory> */
    use HasFactory, HasUlids;

    public const SYSTEM_DEFAULT_NAME = 'Default Assistant';

    public const SYSTEM_DEFAULT_PROMPT = 'You are a helpful AI assistant. Answer clearly and concisely, and match the user language.';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isSystemDefault(): bool
    {
        return $this->user_id === null;
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->user_id === $user->getKey();
    }
}
