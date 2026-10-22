<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        if ($alerts = config('platform.ops_alert_email')) {
            Horizon::routeMailNotificationsTo($alerts); // long-wait alerts per `waits` in config/horizon.php
        }
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        // Job payloads can contain personal data: only staff who manage platform settings.
        // Uses the web session, so sign in through the admin console first.
        Gate::define('viewHorizon', fn (?User $user = null) => $user !== null && $user->isStaff() && $user->hasPermission('settings.manage'));
    }
}
