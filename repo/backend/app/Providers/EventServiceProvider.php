<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * Domain events and their listeners are registered here as the
     * application is built in Prompts 3–7.
     */
    protected $listen = [
        // \App\Domain\Workflow\Events\WorkflowNodeOverdue::class => [
        //     \App\Application\Workflow\Listeners\CreateSlaReminderTodoItem::class,
        // ],
        // \App\Domain\Attachment\Events\AttachmentLinkConsumed::class => [
        //     \App\Application\Audit\Listeners\RecordLinkConsumptionAuditEvent::class,
        // ],
    ];

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
