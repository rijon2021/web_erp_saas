<?php

namespace App\Services\Pos;

use App\Models\Permission;
use App\Models\PosSetting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Manager PINs for POS item removal. The till asks who approves, then that
 * manager's PIN (4–6 digits), checked against users.pos_pin_hash — hashed like
 * a password, salted per user. Guessing is held back by the per-manager and
 * per-cashier limits in PosVoidsController.
 */
class PosApprovalPin
{
    /** Permission that lets a user remove POS items directly and approve others. */
    public const PERMISSION = 'pos_void_items';

    /** PIN changes (own profile or the user form) per acting user per hour. */
    protected const SET_ATTEMPTS = 10;

    /** How long an approved "delete this held draft" stays usable. */
    protected const DRAFT_APPROVAL_TTL = 600;

    private static array $ready = [];

    /** True once the 2026_10_27_000003 tenant migration has run. */
    public static function ready(): bool
    {
        $key = static::scope();
        if (! array_key_exists($key, static::$ready)) {
            try {
                static::$ready[$key] = Schema::hasTable('pos_voids') && Schema::hasColumn('users', 'pos_pin_hash');
            } catch (\Throwable $e) {
                static::$ready[$key] = false;
            }
        }

        return static::$ready[$key];
    }

    /** Prefix for cache / rate-limiter keys. */
    public static function scope(): string
    {
        return function_exists('tenant') && tenant() ? (string) tenant('id') : 'central';
    }

    public static function validFormat($pin): bool
    {
        return is_string($pin) && preg_match('/^\d{4,6}$/', $pin) === 1;
    }

    /** Throw unless $pin is 4–6 digits. Call it before other work (file uploads). */
    public static function assertValid(string $pin): void
    {
        if (! static::validFormat($pin)) {
            throw ValidationException::withMessages(['pos_pin' => __('The PIN must be 4 to 6 digits.')]);
        }
    }

    /** Cap PIN changes per acting user (the profile also checks a password). */
    public static function throttleSet(User $actor): void
    {
        $key = 'pos-pin-set:'.static::scope().':'.$actor->id;
        if (RateLimiter::tooManyAttempts($key, static::SET_ATTEMPTS)) {
            throw ValidationException::withMessages(['pos_pin' => __('Too many attempts. Try again later.')]);
        }
        RateLimiter::hit($key, 3600);
    }

    /** Set or clear (null) a user's PIN. */
    public static function set(User $user, ?string $pin): void
    {
        if ($pin === null || $pin === '') {
            $user->forceFill(['pos_pin_hash' => null])->save();

            return;
        }

        static::assertValid($pin);
        $user->forceFill(['pos_pin_hash' => Hash::make($pin)])->save();
    }

    /** The approver $approverId if their PIN is $pin and they may still approve, else null. */
    public static function verify(int $approverId, string $pin): ?User
    {
        if (! static::validFormat($pin)) {
            return null;
        }

        $user = User::whereNull('deleted_at')->where('statut', 1)->whereNotNull('pos_pin_hash')->find($approverId);
        if (! $user || ! Hash::check($pin, $user->pos_pin_hash) || ! static::canApprove($user)) {
            return null;
        }

        return $user;
    }

    /**
     * Who the till can offer as approver: active users with a PIN whose role has
     * the permission, and who work in $warehouseId (or in every warehouse).
     */
    public static function approvers(?int $warehouseId): Collection
    {
        $permission = Permission::where('name', static::PERMISSION)->first();
        if (! $permission) {
            return collect();
        }
        $roleIds = $permission->roles()->pluck('roles.id');

        return User::whereNull('deleted_at')
            ->where('statut', 1)
            ->whereNotNull('pos_pin_hash')
            ->whereHas('roles', fn ($q) => $q->whereIn('roles.id', $roleIds))
            ->when($warehouseId, fn ($q) => $q->where(fn ($w) => $w->where('is_all_warehouses', 1)
                ->orWhereHas('assignedWarehouses', fn ($a) => $a->where('warehouses.id', $warehouseId))))
            ->orderBy('firstname')->orderBy('username')
            ->get(['id', 'firstname', 'lastname', 'username']);
    }

    public static function canApprove(User $user): bool
    {
        $permission = Permission::where('name', static::PERMISSION)->first();

        return $permission ? $user->hasRole($permission->roles) : false;
    }

    /** POS Settings asks for a manager PIN and this user may not remove items alone. */
    public static function requiredFor(User $user): bool
    {
        if (! static::ready() || ! Schema::hasColumn('pos_settings', 'require_void_approval')) {
            return false;
        }
        $setting = PosSetting::whereNull('deleted_at')->first();

        return $setting && $setting->require_void_approval && ! static::canApprove($user);
    }

    /** A manager approved deleting held draft $draftId on this cashier's till. */
    public static function grantDraftDelete(User $cashier, int $draftId): void
    {
        Cache::put(static::draftKey($cashier, $draftId), true, static::DRAFT_APPROVAL_TTL);
    }

    /** Use up the approval for deleting $draftId (true when there was one). */
    public static function consumeDraftDelete(User $cashier, int $draftId): bool
    {
        return (bool) Cache::pull(static::draftKey($cashier, $draftId));
    }

    private static function draftKey(User $cashier, int $draftId): string
    {
        return 'pos-void-draft:'.static::scope().':'.$cashier->id.':'.$draftId;
    }
}
