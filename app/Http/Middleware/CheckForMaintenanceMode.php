<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as Middleware;

class CheckForMaintenanceMode extends Middleware
{
    /**
     * The URIs that should be reachable while maintenance mode is enabled.
     *
     * @var array
     */
    protected $except = [
        // System Update (super admin) must stay reachable while the platform
        // is down for updating: its page drives the resumable update steps
        // and polls progress, and the standalone recovery console lives
        // under it too.
        'super/system-update',
        'super/system-update/*',
        // The login routes needed to get back in if the super admin's
        // session expired mid-update.
        'super/login',
        'super/login/*',
        'super/logout',
    ];
}
