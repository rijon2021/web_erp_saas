<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sign-in audit row written by App\Support\Security\LoginGuard
 * (System Settings > Security > Sign-in activity).
 */
class LoginAttempt extends Model
{
    public const RETENTION_DAYS = 90;

    public const EVENTS = ['success', 'failed', 'locked_out', 'blocked', 'captcha_failed'];

    public const UPDATED_AT = null;

    protected $table = 'login_attempts';

    protected $fillable = ['email', 'ip_address', 'user_agent', 'event', 'user_id', 'created_at'];

    protected $casts = [
        'user_id' => 'integer',
        'created_at' => 'datetime',
    ];
}
