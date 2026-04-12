<?php

namespace App\Http\Controllers\Api;

use App\Application\Document\DocumentService;
use App\Exceptions\Document\PdfWatermarkFailedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Document\StoreDocumentVersionRequest;
use App\Infrastructure\Security\PdfWatermarkService;
use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class DocumentVersionController extends Controller
{
    public function __construct(
        private readonly DocumentService $service,
        private readonly PdfWatermarkService $pdfWatermark,
    ) {}

    /**
     * GET /api/v1/documents/{document}/versions
     *
     * List all versions for a document, newest first.
     */
    public function index(Request $request, Document $document): JsonResponse
    {
        $this->authorize('view', $document);

        $versions = DocumentVersion::where('document_id', $document->id)
            ->orderBy('version_number', 'desc')
            ->get();

        return response()->json([
            'data' => $versions->map(fn(DocumentVersion $v) => $this->versionShape($v)),
        ]);
    }

    /**
     * POST /api/v1/documents/{document}/versions
     *
     * Upload a new version file for a document.
     * Automatically supersedes the previous current version.
     */
    public function store(StoreDocumentVersionRequest $request, Document $document): JsonResponse
    {
        $this->authorize('update', $document);

        $version = $this->service->createVersion(
            $request->user(),
            $document,
            $request->file('file'),
            $request->validated(),
            $request->ip()
        );

        return response()->json(['data' => $this->versionShape($version)], 201);
    }

    /**
     * GET /api/v1/documents/{document}/versions/{versionId}
     *
     * Get metadata for a specific document version.
     * {versionId} is the UUID of the DocumentVersion record.
     */
    public function show(Request $request, Document $document, string $versionId): JsonResponse
    {
        $this->authorize('view', $document);

        $version = DocumentVersion::where('id', $versionId)
            ->where('document_id', $document->id)
            ->firstOrFail();

        return response()->json(['data' => $this->versionShape($version)]);
    }

    /**
     * GET /api/v1/documents/{document}/versions/{versionId}/download
     *
     * Download a document version as a controlled copy.
     *
     * Requirements:
     *   - Document must be in a downloadable status (not draft)
     *   - Version must be in a downloadable status (not archived)
    *   - PDF files are watermarked with the downloader's username and timestamp
     *   - If PDF watermarking fails, the controlled download is denied
     *   - Response includes X-Watermark-Recorded and X-Watermark-Applied headers
     */
    public function download(Request $request, Document $document, string $versionId): Response
    {
        $this->authorize('view', $document);

        $version = DocumentVersion::where('id', $versionId)
            ->where('document_id', $document->id)
            ->firstOrFail();

        if (!$document->status->isDownloadable()) {
            abort(409, 'Document is in draft status and cannot be downloaded.');
        }

        if (!$version->status->isDownloadable()) {
            abort(409, 'This document version has been archived and cannot be downloaded.');
        }

        $absolutePath = $this->service->resolveFilePath($version);

        if ($version->mime_type === 'application/pdf') {
            $user = $request->user();
            $watermarkText = "{$user->username} - " . now()->format('Y-m-d H:i:s');

            try {
                $stamped = $this->pdfWatermark->stamp($absolutePath, $watermarkText);

                $this->service->recordDownload($user, $version, $request->ip(), watermarkApplied: true);

                return response($stamped, 200, [
                    'Content-Type'         => 'application/pdf',
                    'Content-Disposition'  => 'attachment; filename="' . $version->original_filename . '"',
                    'Content-Length'       => strlen($stamped),
                    'X-Watermark-Recorded' => 'true',
                    'X-Watermark-Applied'  => 'true',
                ]);
            } catch (\RuntimeException $e) {
                Log::warning('PDF watermark stamping failed; controlled download denied.', [
                    'document_id' => $document->id,
                    'version_id'  => $version->id,
                    'user_id'     => $user->id,
                ]);

                throw new PdfWatermarkFailedException();
            }
        }

        $this->service->recordDownload($request->user(), $version, $request->ip(), watermarkApplied: false);

        return response()->download($absolutePath, $version->original_filename, [
            'X-Watermark-Recorded' => 'true',
            'X-Watermark-Applied'  => 'false',
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function versionShape(DocumentVersion $version): array
    {
        return [
            'id'                  => $version->id,
            'document_id'         => $version->document_id,
            'version_number'      => $version->version_number,
            'status'              => $version->status instanceof \BackedEnum
                ? $version->status->value
                : $version->status,
            'original_filename'   => $version->original_filename,
            'mime_type'           => $version->mime_type,
            'file_size_bytes'     => $version->file_size_bytes,
            'sha256_fingerprint'  => $version->sha256_fingerprint,
            'page_count'          => $version->page_count,
            'sheet_count'         => $version->sheet_count,
            'is_previewable'      => $version->is_previewable,
            'thumbnail_available' => $version->thumbnail_available,
            'created_by'          => $version->created_by,
            'published_at'        => $version->published_at?->toIso8601String(),
            'created_at'          => $version->created_at?->toIso8601String(),
        ];
    }
}
