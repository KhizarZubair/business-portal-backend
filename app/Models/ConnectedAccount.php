<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'provider',
    'provider_account_id',
    'account_type',
    'display_name',
    'email',
    'picture_url',
    'access_token',
    'refresh_token',
    'token_expires_at',
    'scopes',
    'status',
    'metadata',
    'last_synced_at',
])]
#[Hidden(['access_token', 'refresh_token'])]
class ConnectedAccount extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'scopes' => 'array',
            'metadata' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
