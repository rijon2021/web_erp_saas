<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A sign-in of the super-admin mobile app. The plain token is returned once by
 * issue(); only its SHA-256 hash is stored.
 */
class CentralApiToken extends Model
{
    protected $connection = 'central';

    protected $table = 'central_api_tokens';

    /** Tokens stay valid this long after sign-in. */
    public const LIFETIME_DAYS = 90;

    protected $fillable = ['central_user_id', 'name', 'token_hash', 'last_used_at', 'expires_at'];

    protected $casts = [
        'last_used_at' => 'datetime',
        'expires_at'   => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(CentralUser::class, 'central_user_id');
    }

    /** @return array{0: self, 1: string} the stored row and the plain token */
    public static function issue(CentralUser $user, ?string $name): array
    {
        $plain = Str::random(64);

        $token = static::create([
            'central_user_id' => $user->id,
            'name'            => $name ? mb_substr($name, 0, 191) : null,
            'token_hash'      => hash('sha256', $plain),
            'expires_at'      => now()->addDays(self::LIFETIME_DAYS),
        ]);

        return [$token, $plain];
    }

    public static function findByPlain(string $plain): ?self
    {
        return static::with('user')->where('token_hash', hash('sha256', $plain))->first();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
