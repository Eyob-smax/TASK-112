<?php

namespace App\Jobs;

use App\Application\Attachment\AttachmentService;
use App\Application\Logging\StructuredLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Bulk-transitions active attachments that have passed their validity window to 'expired'.
 *
 * Scheduled daily. Delegates to AttachmentService::processExpiredAttachments() which
 * performs a bulk update on attachments where status='active' AND expires_at < now().
 *
 * Expired attachments remain in the database for audit and download-history purposes
 * but cannot be accessed for new downloads or link generation.
 */
class ExpireAttachmentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(AttachmentService $attachments, StructuredLogger $logger): void
    {
        $transitioned = $attachments->processExpiredAttachments();

        if ($transitioned > 0) {
            $logger->info('Expired attachments transitioned to expired status', [
                'transitioned_count' => $transitioned,
            ], 'maintenance');
        }
    }
}
