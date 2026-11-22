<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

/**
 * Singleton: Software License delivery + licensing API settings.
 *
 * The Ed25519 key pair signs licensing API responses; the public key is shown
 * in Settings so the vendor can embed it in their software. secret_key is
 * stored encrypted and never returned by the API.
 */
class LicenseSetting extends Model
{
    protected $table = 'license_settings';

    protected $fillable = [
        'email_enabled', 'show_on_receipt', 'api_enabled',
        'offline_grace_days', 'reminder_days', 'public_key', 'secret_key',
    ];

    protected $hidden = ['secret_key'];

    protected $casts = [
        'email_enabled' => 'boolean',
        'show_on_receipt' => 'boolean',
        'api_enabled' => 'boolean',
        'offline_grace_days' => 'integer',
    ];

    protected $attributes = [
        'email_enabled' => true,
        'show_on_receipt' => false,
        'api_enabled' => true,
        'offline_grace_days' => 7,
        'reminder_days' => '30,7',
    ];

    private static array $memo = [];

    /** The row, created on first use. NULL when the table is missing (migration not run). */
    public static function current(): ?self
    {
        $key = function_exists('tenant') && tenant() ? (string) tenant()->getTenantKey() : 'central';
        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }
        if (! Schema::hasTable('license_settings')) {
            return self::$memo[$key] = null;
        }

        $row = static::query()->first() ?: static::create([]);

        return self::$memo[$key] = $row;
    }

    public static function forgetMemo(): void
    {
        self::$memo = [];
    }

    /** Reminder offsets in days before expiry, e.g. [30, 7]. */
    public function reminderOffsets(): array
    {
        return collect(explode(',', (string) $this->reminder_days))
            ->map(fn ($d) => (int) trim($d))
            ->filter(fn ($d) => $d > 0 && $d <= 365)
            ->unique()->sortDesc()->values()->all();
    }

    /** Make sure an Ed25519 key pair exists; returns [publicB64, secretRaw]. */
    public function ensureKeyPair(): array
    {
        if (! $this->secret_key || ! $this->public_key) {
            // First use: create the pair once, even when two requests race.
            \Illuminate\Support\Facades\DB::transaction(function () {
                $row = static::whereKey($this->id)->lockForUpdate()->first();
                if ($row && $row->secret_key && $row->public_key) {
                    $this->secret_key = $row->secret_key;
                    $this->public_key = $row->public_key;

                    return;
                }
                $pair = sodium_crypto_sign_keypair();
                $this->public_key = base64_encode(sodium_crypto_sign_publickey($pair));
                $this->secret_key = Crypt::encryptString(base64_encode(sodium_crypto_sign_secretkey($pair)));
                $this->save();
            });
        }

        // Fail closed: a key that no longer decrypts (APP_KEY changed) is never
        // silently replaced — that would break every copy of the software.
        try {
            $secret = base64_decode(Crypt::decryptString($this->secret_key), true);
        } catch (\Throwable $e) {
            $secret = false;
        }
        if (! $secret || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException('The license signing key cannot be read (was APP_KEY changed?). Rotate it in License Keys › Settings.');
        }

        return [$this->public_key, $secret];
    }

    /** Drop the key pair; a new one is made on next use (vendors must update their embedded key). */
    public function rotateKeyPair(): void
    {
        $this->forceFill(['public_key' => null, 'secret_key' => null])->save();
        $this->ensureKeyPair();
    }
}
