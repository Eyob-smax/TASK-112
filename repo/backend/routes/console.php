<?php

use App\Jobs\ExpireAttachmentLinksJob;
use App\Jobs\ExpireAttachmentsJob;
use App\Jobs\PruneBackupsJob;
use App\Jobs\PruneRetentionJob;
use App\Jobs\RecordQueueDepthJob;
use App\Jobs\RunBackupJob;
use App\Jobs\SendSlaRemindersJob;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Meridian Console Routes / Scheduled Tasks
|--------------------------------------------------------------------------
| Scheduled jobs are defined here and dispatched by the Laravel scheduler
| (php artisan schedule:run — invoked every minute by the Docker cron).
|
| All schedule times are in the application timezone (config app.timezone).
|
| Schedule summary:
|   02:00 daily  — Full local backup (DB manifest + attachment inventory)
|   03:00 daily  — Prune backup records beyond 14-day retention
|   03:30 daily  — Prune structured logs + metrics snapshots beyond 90-day retention
|   04:00 daily  — Expire attachments past their validity window
|   Every 15 min — Clean up expired/consumed/revoked attachment links
|   Every hour   — Send SLA reminder to-do items for overdue workflow nodes
*/

// Daily backup — runs at configured time (default 02:00 local)
// Orchestrates a manifest of all DB tables + attachment storage artifact inventory
Schedule::job(new RunBackupJob(isManual: false))
    ->dailyAt(config('meridian.backup.schedule_time', '02:00'))
    ->name('meridian:backup-daily')
    ->withoutOverlapping();

// Prune expired backup job records (14-day retention)
Schedule::job(new PruneBackupsJob())
    ->dailyAt('03:00')
    ->name('meridian:prune-backups')
    ->withoutOverlapping();

// Prune structured logs and metrics snapshots (90-day retention)
Schedule::job(new PruneRetentionJob())
    ->dailyAt('03:30')
    ->name('meridian:prune-retention')
    ->withoutOverlapping();

// Expire attachments past their validity window (transitions status → expired)
Schedule::job(new ExpireAttachmentsJob())
    ->dailyAt('04:00')
    ->name('meridian:expire-attachments')
    ->withoutOverlapping();

// Clean up expired/consumed/revoked attachment links (24h grace period before deletion)
Schedule::job(new ExpireAttachmentLinksJob())
    ->everyFifteenMinutes()
    ->name('meridian:expire-links')
    ->withoutOverlapping();

// Send SLA reminder to-do items for overdue workflow nodes (reminded_at not set)
Schedule::job(new SendSlaRemindersJob())
    ->hourly()
    ->name('meridian:sla-reminders')
    ->withoutOverlapping();

// Snapshot the database queue depth for operational metrics (every 5 minutes)
Schedule::job(new RecordQueueDepthJob())
    ->everyFiveMinutes()
    ->name('meridian:record-queue-depth')
    ->withoutOverlapping();
