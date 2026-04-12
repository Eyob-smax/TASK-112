<?php

namespace App\Infrastructure\Security;

use App\Models\DocumentDownloadRecord;

/**
 * Records download events for document versions, including whether a watermark
 * was applied and what text it carried.
 *
 * Note: This service records the event only. Actual PDF watermark stamping is
 * handled by the download controller (implemented in a later prompt) using TCPDF/FPDI.
 */
class WatermarkEventService
{
    /**
     * Record that a document version was downloaded.
     *
     * @param string      $documentVersionId  UUID of the downloaded version
     * @param string      $downloadedByUserId UUID of the user performing the download
     * @param string      $ipAddress          Remote IP address of the request
     * @param string|null $watermarkText      The text stamped on the document, if any
     * @param bool        $watermarkApplied   Whether a watermark was actually applied
     */
    public function recordDownload(
        string $documentVersionId,
        string $downloadedByUserId,
        string $ipAddress,
        ?string $watermarkText,
        bool $watermarkApplied,
        bool $isPdf = false,
    ): DocumentDownloadRecord {
        return DocumentDownloadRecord::create([
            'document_version_id' => $documentVersionId,
            'downloaded_by'       => $downloadedByUserId,
            'downloaded_at'       => now(),
            'watermark_text'      => $watermarkText,
            'watermark_applied'   => $watermarkApplied,
            'is_pdf'              => $isPdf,
            'ip_address'          => $ipAddress,
        ]);
    }
}
