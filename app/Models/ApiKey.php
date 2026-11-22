<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A key for the public REST API (/api/v1). The key is shown once; only its
 * SHA-256 is kept. `ability` read = GET only, write = everything. The key acts
 * as `user_id` — that user's permissions and warehouses scope every call.
 */
class ApiKey extends Model
{
    public const PREFIX = 'stk_';

    protected $fillable = ['name', 'key_prefix', 'key_hash', 'ability', 'user_id', 'created_by', 'last_used_at', 'last_used_ip', 'revoked_at'];

    protected $casts = [
        'user_id' => 'integer',
        'created_by' => 'integer',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected $hidden = ['key_hash'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Create a key; returns [model, plain key] — the plain key is never stored. */
    public static function issue(string $name, string $ability, int $userId, ?int $createdBy): array
    {
        $plain = self::PREFIX.Str::random(40);
        $key = self::create([
            'name' => $name,
            'key_prefix' => substr($plain, 0, 12),
            'key_hash' => hash('sha256', $plain),
            'ability' => $ability === 'write' ? 'write' : 'read',
            'user_id' => $userId,
            'created_by' => $createdBy,
        ]);

        return [$key, $plain];
    }

    public static function findActive(string $plain): ?self
    {
        return self::where('key_hash', hash('sha256', $plain))->whereNull('revoked_at')->first();
    }

    public function canWrite(): bool
    {
        return $this->ability === 'write';
    }
}
