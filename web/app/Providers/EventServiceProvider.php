<?php

namespace App\Providers;

use App\Listeners\InvitedReferralListener;
use Laravel\Lumen\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        'invitedReferral' => [
            InvitedReferralListener::class
        ],
        'getOwnerByPhone' => [
            'App\Listeners\GetOwnerByPhoneListener'
        ]
    ];

    /**
     * Determine if events and listeners should be automatically discovered.
     *
     * @return bool
     */
    public function shouldDiscoverEvents(): bool
    {
        return true;
    }
}
