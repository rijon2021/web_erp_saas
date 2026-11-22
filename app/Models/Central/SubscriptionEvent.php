<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail of super-admin changes to a tenant's subscription.
 */
class SubscriptionEvent extends Model
{
    protected $connection = 'central';

    protected $table = 'subscription_events';

    public const ACTION_EXTENDED        = 'extended';
    public const ACTION_PLAN_CHANGED    = 'plan_changed';
    public const ACTION_PAYMENT_RECORDED = 'payment_recorded';
    public const ACTION_ACTIVATED       = 'activated';
    public const ACTION_SUSPENDED       = 'suspended';
    public const ACTION_CANCELLED       = 'cancelled';
    public const ACTION_EDITED          = 'edited';
    /** Unused paid time carried over on renewal / plan change (system). */
    public const ACTION_CREDITED        = 'credited';

    protected $fillable = [
        'tenant_id',
        'tenant_subscription_id',
        'central_user_id',
        'action',
        'details',
        'note',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    public function admin()
    {
        return $this->belongsTo(CentralUser::class, 'central_user_id');
    }

    /** Whether the table exists yet (its migration may not have run). */
    public static function available(): bool
    {
        static $available = null;

        return $available ??= Schema::connection('central')->hasTable('subscription_events');
    }

    /**
     * Record an admin action. Never throws — an audit failure must not undo
     * or block the change it describes.
     */
    public static function record(TenantSubscription $subscription, string $action, array $details = [], ?string $note = null): void
    {
        try {
            if (! static::available()) {
                return;
            }

            static::create([
                'tenant_id'              => $subscription->tenant_id,
                'tenant_subscription_id' => $subscription->id,
                'central_user_id'        => Auth::guard('central')->id(),
                'action'                 => $action,
                'details'                => $details ?: null,
                'note'                   => $note !== null && trim($note) !== '' ? trim($note) : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning("SubscriptionEvent::record failed ({$action}) for subscription {$subscription->id}: {$e->getMessage()}");
        }
    }
}
