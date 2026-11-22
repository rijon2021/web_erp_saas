<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LicenseActivation;
use App\Models\LicenseKey;
use App\Models\LicenseSetting;
use App\Models\Product;
use App\Services\LicenseKeys\LicenseFulfilment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Public licensing API for the tenant's own software (generated keys).
 *
 *   POST /api/licensing/v1/activate    {license_key, product?, domain? | device_id?, device_name?, nonce?}
 *   POST /api/licensing/v1/validate    {license_key, product?, domain? | device_id?, nonce?}
 *   POST /api/licensing/v1/deactivate  {license_key, domain? | device_id?, nonce?}
 *   POST /api/licensing/v1/status      {license_key, product?, nonce?}
 *
 * The key itself authenticates the call. Every answer carries `token`
 * (base64 of the JSON answer) and `signature` (Ed25519 over the token, base64).
 *
 * Only an `activate` / `validate` answer can grant use: `valid` is true there
 * and nowhere else, and only then is `offline_until` set. The signed token is
 * bound to the request so it cannot be reused elsewhere — it carries:
 *   action, key_fingerprint (sha256 of the upper-cased, trimmed key),
 *   identifier (normalised domain / device id), nonce (echoed back) and host.
 * The software must check all of them against its own values. Fields outside
 * the token are a convenience copy and must not be trusted on their own.
 *
 * An identifier (domain / device_id) is required on activate / validate when
 * the license is domain- or device-locked, or when it has an activation limit.
 */
class LicensingApiController extends Controller
{
    /** Failed key look-ups allowed per tenant + IP per 10 minutes. */
    private const FAILED_LOOKUPS = 20;

    public function activate(Request $request)
    {
        return $this->handle($request, 'activate');
    }

    public function validateLicense(Request $request)
    {
        return $this->handle($request, 'validate');
    }

    public function deactivate(Request $request)
    {
        return $this->handle($request, 'deactivate');
    }

    public function status(Request $request)
    {
        return $this->handle($request, 'status');
    }

    private function handle(Request $request, string $action)
    {
        if (! LicenseFulfilment::ready()) {
            return $this->fail('unavailable', 'Licensing is not available.', 503);
        }
        $settings = LicenseSetting::current();
        if (! $settings || ! $settings->api_enabled) {
            return $this->fail('unavailable', 'Licensing API is turned off.', 503);
        }

        $limiterKey = 'lic-fail:'.(function_exists('tenant') && tenant() ? tenant()->getTenantKey() : 'central').':'.$request->ip();
        if (RateLimiter::tooManyAttempts($limiterKey, self::FAILED_LOOKUPS)) {
            return $this->fail('too_many_attempts', 'Too many invalid license keys — try again later.', 429);
        }

        $raw = trim((string) $request->input('license_key', ''));
        if ($raw === '' || mb_strlen($raw) > 200) {
            return $this->fail('invalid_request', 'license_key is required.', 422);
        }

        $ctx = [
            'action' => $action,
            'key_fingerprint' => hash('sha256', LicenseKey::normalize($raw, true)),
            'nonce' => mb_substr(trim((string) $request->input('nonce', '')), 0, 64) ?: null,
            'host' => $request->getHost(),
            'identifier' => null,
        ];

        $key = LicenseKey::where('key_hash', LicenseKey::hashKey(LicenseKey::normalize($raw, true)))
            ->where('source', LicenseKey::SOURCE_GENERATED)
            ->first();
        if (! $key) {
            RateLimiter::hit($limiterKey, 600);

            return $this->signed($settings, $ctx + ['valid' => false, 'error' => 'invalid_key', 'message' => 'License key not found.'], 404);
        }

        if ($request->filled('product') && ! $this->productMatches($key, (string) $request->input('product'))) {
            return $this->signed($settings, $ctx + ['valid' => false, 'error' => 'product_mismatch', 'message' => 'This key is for another product.'], 422);
        }

        $restriction = $key->restriction ?: 'none';
        [$kind, $identifier] = $this->identifier($request, $restriction);
        $ctx['identifier'] = $identifier ?: null;

        // ---- status: information only, never grants use
        if ($action === 'status') {
            return $this->signed($settings, $this->body($key, $settings, $ctx, false));
        }

        // ---- deactivate: free a slot whatever the license state; never grants use
        if ($action === 'deactivate') {
            if (! $identifier) {
                return $this->signed($settings, $ctx + ['valid' => false, 'error' => 'invalid_request', 'message' => 'domain or device_id is required.'], 422);
            }
            $n = LicenseActivation::where('license_key_id', $key->id)->where('identifier', $identifier)
                ->whereNull('deactivated_at')->update(['deactivated_at' => now(), 'updated_at' => now()]);
            if ($n) {
                app(LicenseFulfilment::class)->event($key->id, 'deactivated', $identifier, null, ['via' => 'api']);
            }

            return $this->signed($settings, $this->body($key, $settings, $ctx, false) + ['deactivated' => (bool) $n]);
        }

        // ---- activate / validate
        $needsIdentifier = $restriction !== 'none' || $key->max_activations !== null;
        if ($needsIdentifier && ! $identifier) {
            $what = $restriction === 'domain' ? 'domain' : 'device_id';

            return $this->signed($settings, $ctx + ['valid' => false, 'error' => 'invalid_request', 'message' => "{$what} is required for this license."], 422);
        }

        $state = $key->licenseState();
        if ($state !== 'active') {
            $messages = [
                'expired' => 'This license has expired.',
                'suspended' => 'This license is suspended.',
                'revoked' => 'This license has been revoked.',
                'inactive' => 'This license is not active.',
            ];

            return $this->signed($settings, $this->body($key, $settings, $ctx, false) + ['error' => 'license_'.$state, 'message' => $messages[$state] ?? 'Not active.'], 403);
        }

        // Unrestricted + unlimited license without an identifier: nothing to record.
        if (! $identifier) {
            return $this->signed($settings, $this->body($key, $settings, $ctx, true));
        }

        if ($action === 'validate') {
            $activation = LicenseActivation::where('license_key_id', $key->id)->where('identifier', $identifier)->whereNull('deactivated_at')->first();
            if ($activation) {
                $activation->update(['last_seen_at' => now(), 'ip' => $request->ip()]);

                return $this->signed($settings, $this->body($key, $settings, $ctx, true));
            }
            // An unrestricted, unlimited license does not need an activation to be used.
            if (! $needsIdentifier) {
                return $this->signed($settings, $this->body($key, $settings, $ctx, true));
            }

            return $this->signed($settings, $this->body($key, $settings, $ctx, false) + ['error' => 'not_activated',
                'message' => 'This license is not activated on this '.($kind === 'domain' ? 'domain' : 'device').'.'], 403);
        }

        // activate
        return DB::transaction(function () use ($key, $kind, $identifier, $request, $settings, $ctx) {
            $key = LicenseKey::whereKey($key->id)->lockForUpdate()->first();

            $existing = LicenseActivation::where('license_key_id', $key->id)->where('identifier', $identifier)->whereNull('deactivated_at')->first();
            if ($existing) {
                $existing->update(['last_seen_at' => now(), 'ip' => $request->ip()]);

                return $this->signed($settings, $this->body($key, $settings, $ctx, true) + ['activated' => true, 'already_active' => true]);
            }

            $used = LicenseActivation::where('license_key_id', $key->id)->whereNull('deactivated_at')->count();
            if ($key->max_activations !== null && $used >= $key->max_activations) {
                return $this->signed($settings, $this->body($key, $settings, $ctx, false) + ['error' => 'activation_limit_reached',
                    'message' => "This license is already active on {$used} of {$key->max_activations} ".($kind === 'domain' ? 'domains' : 'devices').'.'], 403);
            }

            LicenseActivation::create([
                'license_key_id' => $key->id,
                'kind' => $kind,
                'identifier' => $identifier,
                'label' => mb_substr(trim((string) $request->input('device_name', $request->input('label', ''))), 0, 191) ?: null,
                'ip' => $request->ip(),
                'activated_at' => now(),
                'last_seen_at' => now(),
            ]);
            app(LicenseFulfilment::class)->event($key->id, 'activated', $identifier, null, ['via' => 'api']);

            return $this->signed($settings, $this->body($key, $settings, $ctx, true) + ['activated' => true]);
        });
    }

    /**
     * [kind, identifier] from the request: '' when none given, false when the
     * license's restriction needs one that is missing.
     */
    private function identifier(Request $request, string $restriction): array
    {
        $domain = self::normalizeDomain((string) $request->input('domain', ''));
        $device = mb_substr(trim((string) $request->input('device_id', '')), 0, 255);

        if ($restriction === 'domain') {
            return $domain !== '' ? ['domain', $domain] : ['domain', false];
        }
        if ($restriction === 'device') {
            return $device !== '' ? ['device', $device] : ['device', false];
        }
        if ($domain !== '') {
            return ['domain', $domain];
        }

        return ['device', $device];
    }

    /** "https://www.Shop.com:8080/path" → "shop.com". */
    public static function normalizeDomain(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }
        if (! str_contains($value, '://')) {
            $value = 'http://'.$value;
        }
        $host = (string) parse_url($value, PHP_URL_HOST);
        $host = rtrim($host, '.');
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return mb_substr($host, 0, 255);
    }

    private function productMatches(LicenseKey $key, string $product): bool
    {
        $p = Product::find($key->product_id);
        if (! $p) {
            return false;
        }
        $product = trim($product);

        return (ctype_digit($product) && (int) $product === (int) $p->id)
            || strcasecmp($product, (string) $p->code) === 0
            || strcasecmp($product, \Illuminate\Support\Str::slug((string) $p->name)) === 0;
    }

    /** The answer body; $grant = this answer authorises use (activate / validate only). */
    private function body(LicenseKey $key, LicenseSetting $settings, array $ctx, bool $grant): array
    {
        $used = LicenseActivation::where('license_key_id', $key->id)->whereNull('deactivated_at')->count();
        $product = Product::find($key->product_id);
        $edition = $key->product_variant_id ? \App\Models\ProductVariant::whereKey($key->product_variant_id)->value('name') : null;

        $offlineUntil = null;
        if ($grant) {
            $offlineUntil = now()->addDays(max(0, (int) $settings->offline_grace_days));
            if ($key->expires_at && $key->expires_at->lt($offlineUntil)) {
                $offlineUntil = $key->expires_at->copy();
            }
        }

        return $ctx + [
            'valid' => $grant,
            'status' => $key->licenseState(),
            'product' => $product?->code,
            'product_name' => $product?->name,
            'edition' => $edition,
            'key_last4' => $key->key_last4,
            'expires_at' => $key->expires_at?->toIso8601String(),
            'restriction' => $key->restriction ?: 'none',
            'activations' => ['used' => $used, 'max' => $key->max_activations],
            'offline_until' => $offlineUntil?->toIso8601String(),
            'issued_at' => now()->toIso8601String(),
        ];
    }

    private function signed(LicenseSetting $settings, array $body, int $status = 200)
    {
        $body += ['issued_at' => now()->toIso8601String()];
        [, $secret] = $settings->ensureKeyPair();
        $token = base64_encode(json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $signature = base64_encode(sodium_crypto_sign_detached($token, $secret));

        return response()->json($body + ['token' => $token, 'signature' => $signature], $status);
    }

    private function fail(string $error, string $message, int $status)
    {
        return response()->json(['valid' => false, 'error' => $error, 'message' => $message], $status);
    }
}
