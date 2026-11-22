<?php

namespace App\Services\Licensing;

use App\Models\Central\UiLibLicense;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use UiLibLicenseClient;

/**
 * UI Lib licence for this install (see the licensing integration guide).
 *
 * The signed token ui-lib.com answers with is the only proof of a licence:
 * every "is this licensed?" question is answered offline by verifying it
 * (signature, product, this install's domain, expiry). The licence key is
 * kept only so the daily re-check and the Licence page can call the server.
 *
 * The domain always comes from config('app.url'), never from the request's
 * Host header, so a misconfigured proxy cannot change it.
 *
 * Becoming unlicensed happens only on a real answer from ui-lib.com
 * (invalid_license, license_inactive, wrong_product, or a check that says
 * not_activated). Network trouble, 5xx and throttling keep the stored
 * token, which expires on its own 7 days after the last successful check.
 *
 * The token is refreshed two ways, so no cron is required: the daily
 * scheduler job when one runs, and refreshIfStale() after any page view
 * (EnsurePlatformLicensed), which re-checks at most once a day.
 */
class LicenseService
{
    public const STATE_ACTIVE = 'active';

    public const STATE_UNLICENSED = 'unlicensed';

    /** The licence table is not migrated yet: nothing can be said. */
    public const STATE_UNKNOWN = 'unknown';

    public const LOCK_OPEN = 'open';

    /** Token gone but no answer from ui-lib.com yet: counting down to the lock. */
    public const LOCK_GRACE = 'grace';

    public const LOCK_LOCKED = 'locked';

    private const LOCK_CACHE = 'uilib:license:lock_state';

    private const MAX_GRACE_DAYS = 30;

    /** Answers from the server that really mean "no longer licensed". */
    private const DROP_CODES = ['invalid_license', 'license_inactive', 'wrong_product'];

    /** @var array|null|false per-request cache of the verified token (false = not loaded) */
    private $verified = false;

    /** @var UiLibLicense|null per-request memo of the single row */
    private $row = null;

    public function __construct(private UiLibLicenseClient $client)
    {
    }

    // ------------------------------------------------------------------
    // Offline questions
    // ------------------------------------------------------------------

    public function siteUrl(): string
    {
        return (string) config('app.url');
    }

    public function domain(): ?string
    {
        return UiLibLicenseClient::normalizeDomain($this->siteUrl());
    }

    /** Installed Stocky version (version.txt), shown to UI Lib support. */
    public function version(): string
    {
        try {
            return File::exists(base_path('version.txt')) ? trim((string) File::get(base_path('version.txt'))) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * The signed data of the stored token when it can be trusted, else null.
     */
    public function verified(): ?array
    {
        if ($this->verified !== false) {
            return $this->verified;
        }

        $row = $this->row();
        $token = (string) ($row->token ?? '');
        $data = $token === '' || $this->sodiumMissing() ? null : $this->client->verifyToken($token, $this->siteUrl());

        return $this->verified = ($data && ($data['status'] ?? null) === 'active') ? $data : null;
    }

    public function isLicensed(): bool
    {
        return $this->verified() !== null;
    }

    /**
     * One of the STATE_* constants; what the admin notice is based on.
     */
    public function publicState(): string
    {
        if ($this->row() === null) {
            return self::STATE_UNKNOWN;
        }

        return $this->isLicensed() ? self::STATE_ACTIVE : self::STATE_UNLICENSED;
    }

    /**
     * Everything the Licence page shows, from local state only.
     */
    public function state(): array
    {
        $row = $this->row();
        if ($row === null) {
            return [
                'available' => false,
                'status' => self::STATE_UNKNOWN,
                'licensed' => false,
            ];
        }

        $data = $this->verified();
        $key = $row->licenseKey();

        return [
            'available' => true,
            'status' => $data ? self::STATE_ACTIVE : self::STATE_UNLICENSED,
            'licensed' => (bool) $data,
            'site_url' => $this->siteUrl(),
            'domain' => $this->domain(),
            'version' => $this->version(),
            // A key is stored but the token is gone/expired: the page offers
            // to re-activate with it instead of asking the customer again.
            'has_license' => $key !== '',
            'license_masked' => $key !== '' ? $this->mask($key) : null,
            'email' => (string) ($row->email ?? ''),
            'data' => $data,
            'last_code' => $row->last_code,
            'last_checked_at' => optional($row->last_checked_at)->toIso8601String(),
            'grace_days' => $this->graceDays(),
        ];
    }

    // ------------------------------------------------------------------
    // Calls to ui-lib.com
    // ------------------------------------------------------------------

    /**
     * Activate this install's domain with a licence key or purchase code.
     * On success the key, email and token are stored.
     */
    public function activate(string $license, string $email): array
    {
        $row = $this->row();
        if ($row === null) {
            return $this->unavailable();
        }

        if ($missing = $this->sodiumMissing()) {
            return $missing;
        }

        $license = trim($license);
        $email = UiLibLicenseClient::needsEmail($license) ? trim($email) : '';

        $result = $this->client->activate($license, $email, $this->siteUrl(), $this->extra($row));

        if ($result['ok']) {
            $this->store($row, [
                'license' => $license,
                'email' => $email,
                'token' => $result['token'],
                'last_code' => null,
                'last_checked_at' => now(),
            ]);
        } elseif (! $result['network_error'] && ! $row->token && ! in_array($row->last_code, ['network_error', 'not_activated'], true)) {
            // Remembered only while nothing is stored at all: a typo must not
            // turn a valid token, or a running grace period, into an instant lock.
            $this->store($row, ['last_code' => $result['code'], 'last_checked_at' => now()]);
        }

        return $result;
    }

    /**
     * Activate again with the stored key (token lapsed, domain released,
     * APP_URL moved): what the "Activate again" button on the Licence page
     * does, so the owner never has to retype the key.
     */
    public function reactivate(): array
    {
        $row = $this->row();
        if ($row === null) {
            return $this->unavailable();
        }
        if ($row->licenseKey() === '') {
            return ['ok' => false, 'http' => 0, 'network_error' => false, 'code' => 'no_license', 'message' => null, 'data' => null, 'token' => null];
        }

        return $this->activate($row->licenseKey(), (string) ($row->email ?? ''));
    }

    /**
     * The installer finished without a licence because ui-lib.com could not
     * be reached: remembered so the platform runs for the grace period
     * instead of locking at once.
     */
    public function recordInstallSkip(): void
    {
        $row = $this->row();
        if ($row === null || $row->licenseKey() !== '' || $row->token) {
            return;
        }
        $this->store($row, ['last_code' => 'network_error', 'last_checked_at' => now()]);
    }

    /** The stored key, for the activation-limit "Deactivate there" forms. */
    public function storedKey(): string
    {
        $row = $this->row();

        return $row ? $row->licenseKey() : '';
    }

    /**
     * Re-check the stored licence on this domain (Licence page refresh and
     * the daily background check). Drops the token only on a real negative
     * answer; network trouble keeps it.
     */
    public function check(): array
    {
        $row = $this->row();
        if ($row === null) {
            return $this->unavailable();
        }

        if ($missing = $this->sodiumMissing()) {
            return $missing;
        }

        $license = $row->licenseKey();
        if ($license === '') {
            return ['ok' => false, 'http' => 0, 'network_error' => false, 'code' => 'no_license', 'message' => null, 'data' => null, 'token' => null];
        }

        $result = $this->client->check($license, (string) $row->email, $this->siteUrl(), $this->extra($row));

        if ($result['ok'] && ($result['data']['status'] ?? null) === 'active') {
            $this->store($row, ['token' => $result['token'], 'last_code' => null, 'last_checked_at' => now()]);
        } elseif ($result['ok']) {
            // Licence fine but released on this domain (from another install
            // or by support): no longer licensed here.
            $this->store($row, ['token' => null, 'last_code' => 'not_activated', 'last_checked_at' => now()]);
        } elseif (! $result['network_error']) {
            $drop = in_array($result['code'], self::DROP_CODES, true);
            $this->store($row, [
                'token' => $drop ? null : $row->token,
                'last_code' => $result['code'],
                'last_checked_at' => now(),
            ]);
        }

        return $result;
    }

    /**
     * Keep the token fresh without a cron: EnsurePlatformLicensed calls this
     * after a response at most once an hour, from any page anyone opens, so
     * a quiet back office cannot lapse into the lock. Only talks to
     * ui-lib.com when the last check is a day old.
     */
    public function refreshIfStale(): void
    {
        try {
            $row = $this->row();
            if ($row === null) {
                return;
            }
            $last = $row->last_checked_at;
            if ($last && $last->gt(now()->subDay())) {
                return;
            }
            if ($row->licenseKey() !== '') {
                $this->check();
            }
        } catch (\Throwable $e) {
            // the licence must never break a request
        }
    }

    /**
     * Whether HTTP traffic is allowed (see EnsurePlatformLicensed), decided
     * offline and cached for a minute:
     *
     *  open   - licensed, or the licence table is not migrated yet (unknown
     *           is never a reason to lock).
     *  grace  - a licence was there but cannot be confirmed right now: the
     *           token expired without any answer from ui-lib.com, or the
     *           server says "not active on this domain" (APP_URL moved,
     *           released from another install); 'until' says when the lock
     *           starts (config uilib.grace_days later). An install finished
     *           while ui-lib.com was unreachable gets the same grace from
     *           install (the installer records 'network_error').
     *  locked - never activated, deactivated here, ui-lib.com really said
     *           the licence is gone (refund, revoked, wrong product), or the
     *           grace period ran out.
     *
     * @return array{state:string, until:?string}
     */
    public function lockState(): array
    {
        try {
            return Cache::remember(self::LOCK_CACHE, 60, fn () => $this->computeLockState());
        } catch (\Throwable $e) {
            // Cache trouble must not decide the lock either way: compute it.
            try {
                return $this->computeLockState();
            } catch (\Throwable $e) {
                return ['state' => self::LOCK_OPEN, 'until' => null];
            }
        }
    }

    /** How long a lapsed licence keeps working. Capped: .env is not a way around the lock. */
    private function graceDays(): int
    {
        return min(self::MAX_GRACE_DAYS, max(0, (int) config('uilib.grace_days', 14)));
    }

    /** Tokens cannot be verified without ext-sodium: say so instead of a bare 500. */
    private function sodiumMissing(): ?array
    {
        return function_exists('sodium_crypto_sign_verify_detached') ? null : [
            'ok' => false, 'http' => 0, 'network_error' => false, 'code' => 'sodium_missing',
            'message' => 'The PHP sodium extension is missing on this server. Enable it, then try again.',
            'data' => null, 'token' => null,
        ];
    }

    private function computeLockState(): array
    {
        $row = $this->row();
        if ($row === null || $this->verified() !== null) {
            return ['state' => self::LOCK_OPEN, 'until' => null];
        }

        if (in_array($row->last_code, self::DROP_CODES, true)) {
            return ['state' => self::LOCK_LOCKED, 'until' => null];
        }

        $grace = $this->graceDays();
        $token = (string) ($row->token ?? '');

        // When this install stopped being licensed; recorded once by store(),
        // so later answers cannot push the lock back day after day.
        $started = $row->grace_started_at ?: ($row->last_checked_at ?: now());

        if ($row->last_code === 'not_activated') {
            // Licence fine, just not on this domain: grace from the first
            // such answer.
            $from = $started;
        } elseif ($token !== '') {
            // Token present but no longer verifiable (expired, APP_URL changed):
            // grace counts from the expiry it carries. Unverified, so it can only
            // move the clock back: a future date on a bad token buys nothing.
            $expiry = $this->tokenExpiry($token);
            $from = $expiry && $expiry->lte(now()) ? $expiry : $started;
        } elseif ($row->licenseKey() === '') {
            // Never activated or released here: locked at once (a grace here
            // would reset by deleting the row). The one exception is an
            // install that finished while ui-lib.com was unreachable.
            if ($row->last_code !== 'network_error') {
                return ['state' => self::LOCK_LOCKED, 'until' => null];
            }
            $from = $started;
        } else {
            // A key without a token and without a negative answer should not
            // happen (activation stores both); grace from when it was noticed.
            $from = $started;
        }

        $until = $from->copy()->addDays($grace);

        return [
            'state' => now()->lt($until) ? self::LOCK_GRACE : self::LOCK_LOCKED,
            'until' => $until->toIso8601String(),
        ];
    }

    /** The expiry carried by a token, read without verifying it (grace maths only). */
    private function tokenExpiry(string $token): ?\Carbon\Carbon
    {
        try {
            $payload = explode('.', $token, 2)[0] ?? '';
            $json = base64_decode(strtr($payload, '-_', '+/'), true);
            $data = $json ? json_decode($json, true) : null;
            $exp = (int) ($data['expires_at'] ?? 0);

            return $exp > 0 ? \Carbon\Carbon::createFromTimestamp($exp) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Stored state changed: drop the per-request and lock caches. */
    private function touched(): void
    {
        $this->verified = false;
        try {
            Cache::forget(self::LOCK_CACHE);
        } catch (\Throwable $e) {
            // cache trouble must not break licence handling
        }
    }

    /**
     * Release a domain. With no domain (or this install's own), the stored
     * licence is cleared, also when the server says it was already released.
     * With another domain of the same licence ("Deactivate there" on the
     * activation-limit error), nothing is stored; $license/$email may be
     * passed because the key is not saved before the first activation.
     */
    public function deactivate(?string $domain = null, ?string $license = null, ?string $email = null): array
    {
        $row = $this->row();
        if ($row === null) {
            return $this->unavailable();
        }

        $own = $domain === null || $domain === '' || UiLibLicenseClient::normalizeDomain($domain) === $this->domain();
        $license = trim((string) ($license ?: $row->licenseKey()));
        $email = $license !== '' && UiLibLicenseClient::needsEmail($license)
            ? trim((string) ($email ?? $row->email ?? ''))
            : '';

        if ($license === '') {
            if ($own) {
                $this->clear($row);
            }

            return ['ok' => true, 'http' => 0, 'network_error' => false, 'code' => null, 'message' => null, 'data' => null, 'token' => null];
        }

        if ($missing = $this->sodiumMissing()) {
            return $missing;
        }

        $result = $this->client->deactivate($license, $email, $own ? $this->siteUrl() : $domain);

        if ($own && ($result['ok'] || $result['code'] === 'not_activated')) {
            $this->clear($row);
        }

        return $result;
    }

    /**
     * Store an activation obtained elsewhere (the installer activates before
     * the database exists and hands the result over once it is migrated).
     * The token is verified again before being stored, so a stale or edited
     * session value can never be persisted as a licence.
     */
    public function importActivation(string $license, string $email, string $token, ?string $instanceId = null, ?string $siteUrl = null): bool
    {
        // $siteUrl: the URL the token was issued for. The installer passes
        // the one it just wrote to .env, because the running process still
        // has the pre-install config('app.url') at that point.
        $row = $this->row();
        if ($row === null || $this->client->verifyToken($token, $siteUrl ?: $this->siteUrl()) === null) {
            return false;
        }

        $this->store($row, [
            'license' => trim($license),
            'email' => trim($email),
            'token' => $token,
            'instance_id' => $instanceId ?: $row->instance_id,
            'last_code' => null,
            'last_checked_at' => now(),
        ]);

        return true;
    }

    // ------------------------------------------------------------------

    private function row(): ?UiLibLicense
    {
        if ($this->row === null) {
            $this->row = UiLibLicense::current();
        }

        return $this->row;
    }

    /**
     * Every write goes through here: applies the attributes, keeps
     * grace_started_at (when this install stopped being licensed) honest,
     * saves and drops the caches. The start of a grace period is recorded
     * once and never moved by later answers, so a daily "still not active on
     * this domain" cannot roll the lock forward forever.
     */
    private function store(UiLibLicense $row, array $attrs): void
    {
        $previousToken = (string) ($row->token ?? '');

        // "Refunded" stands until a licence is active again or the server
        // says it is fine but not on this domain: a stray code such as
        // activation_limit must not turn a hard lock into a grace period.
        if (in_array($row->last_code, self::DROP_CODES, true)
            && ! in_array($attrs['last_code'] ?? null, array_merge([null, 'not_activated'], self::DROP_CODES), true)) {
            unset($attrs['last_code']);
        }

        $row->fill($attrs);
        if ($row->token) {
            $row->grace_started_at = null;
        } elseif ($row->grace_started_at === null && ! in_array($row->last_code, self::DROP_CODES, true)) {
            // A token that had already lapsed started the clock back then.
            $expiry = $previousToken !== '' ? $this->tokenExpiry($previousToken) : null;
            $row->grace_started_at = $expiry && $expiry->lt(now()) ? $expiry : now();
        }
        $row->save();
        $this->touched();
    }

    private function clear(UiLibLicense $row): void
    {
        $this->store($row, ['license' => null, 'email' => null, 'token' => null, 'last_code' => null, 'last_checked_at' => now()]);
    }

    private function extra(UiLibLicense $row): array
    {
        $extra = ['version' => $this->version()];
        if ($row->instance_id) {
            $extra['instance_id'] = $row->instance_id;
        }

        return $extra;
    }

    private function unavailable(): array
    {
        return ['ok' => false, 'http' => 0, 'network_error' => false, 'code' => 'not_available', 'message' => null, 'data' => null, 'token' => null];
    }

    private function mask(string $key): string
    {
        $key = trim($key);
        if (strlen($key) <= 4) {
            return str_repeat('•', strlen($key));
        }

        return str_repeat('•', min(strlen($key) - 4, 12)).substr($key, -4);
    }
}
