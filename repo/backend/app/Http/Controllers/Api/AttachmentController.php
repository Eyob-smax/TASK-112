<?php

namespace App\Http\Controllers\Api;

use App\Application\Attachment\AttachmentService;
use App\Domain\Attachment\ValueObjects\FileConstraints;
use App\Exceptions\Attachment\AttachmentCapacityExceededException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attachment\StoreAttachmentRequest;
use App\Models\Attachment;
use App\Models\Document;
use App\Models\ReturnRecord;
use App\Models\SalesDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class AttachmentController extends Controller
{
    /**
     * Map of URL-friendly type slugs to fully-qualified model class names.
     * Used to resolve the polymorphic record_type from the route parameter.
     */
    private const MORPH_MAP = [
        'document'       => Document::class,
        'sales_document' => SalesDocument::class,
        'return'         => ReturnRecord::class,
    ];

    public function __construct(
        private readonly AttachmentService $service,
    ) {}

    /**
     * GET /api/v1/records/{type}/{id}/attachments
     *
     * List active attachments for a business record.
     *
     * Authorization is delegated to the parent record's own policy so that
     * department isolation and access_control_scope rules are respected. A user
     * who cannot view the parent record cannot list its attachments.
     */
    public function index(Request $request, string $type, string $id): JsonResponse
    {
        $recordType = $this->resolveRecordType($type);

        // Load the parent record and authorize via its own policy (enforces department scope)
        $record = $recordType::findOrFail($id);
        $this->authorize('view', $record);

        $attachments = Attachment::where('record_type', $recordType)
            ->where('record_id', $id)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $attachments->map(fn(Attachment $a) => $this->attachmentShape($a)),
        ]);
    }

    /**
     * POST /api/v1/records/{type}/{id}/attachments
     *
     * Upload one or more attachments to a business record.
     * Parent record is loaded and authorized via its own policy (department scope).
     * Batch cap is pre-checked before processing any files.
     */
    public function store(StoreAttachmentRequest $request, string $type, string $id): JsonResponse
    {
        $recordType = $this->resolveRecordType($type);

        // Load the parent record and enforce its own policy (department scope)
        $record = $recordType::findOrFail($id);
        $this->authorize('view', $record);

        $files        = $request->file('files');
        $validityDays = $request->has('validity_days') ? (int) $request->input('validity_days') : null;

        $results = DB::transaction(function () use ($recordType, $id, $record, $files, $request, $validityDays): array {
            // Serialize uploads per parent record so concurrent requests cannot overrun the cap.
            $recordType::query()
                ->whereKey($record->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $existingCount = Attachment::where('record_type', $recordType)
                ->where('record_id', $id)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->count();

            if ($existingCount + count($files) > FileConstraints::MAX_FILES_PER_RECORD) {
                throw new AttachmentCapacityExceededException(FileConstraints::MAX_FILES_PER_RECORD);
            }

            $uploaded = [];
            foreach ($files as $file) {
                $attachment = $this->service->upload(
                    $request->user(),
                    $recordType,
                    $id,
                    $file,
                    $validityDays,
                    $request->ip(),
                    $record->department_id,
                );
                $uploaded[] = $this->attachmentShape($attachment);
            }

            return $uploaded;
        });

        return response()->json(['data' => $results], 201);
    }

    /**
     * GET /api/v1/attachments/{attachment}
     *
     * Show metadata for a single attachment.
     */
    public function show(Request $request, Attachment $attachment): JsonResponse
    {
        $this->authorize('view', $attachment);

        return response()->json(['data' => $this->attachmentShape($attachment)]);
    }

    /**
     * DELETE /api/v1/attachments/{attachment}
     *
     * Revoke an attachment (soft-delete, status set to revoked).
     */
    public function destroy(Request $request, Attachment $attachment): Response
    {
        $this->authorize('delete', $attachment);

        $this->service->revokeAttachment($request->user(), $attachment, $request->ip());

        return response()->noContent();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve a URL slug to a fully-qualified model class name.
     * Returns 422 if the type is not in the morph map.
     */
    private function resolveRecordType(string $type): string
    {
        if (!isset(self::MORPH_MAP[$type])) {
            abort(422, "Unknown record type '{$type}'. Allowed: " . implode(', ', array_keys(self::MORPH_MAP)));
        }

        return self::MORPH_MAP[$type];
    }

    private function attachmentShape(Attachment $attachment): array
    {
        return [
            'id'                 => $attachment->id,
            'record_type'        => $attachment->record_type,
            'record_id'          => $attachment->record_id,
            'original_filename'  => $attachment->original_filename,
            'mime_type'          => $attachment->mime_type,
            'file_size_bytes'    => $attachment->file_size_bytes,
            'sha256_fingerprint' => $attachment->sha256_fingerprint,
            'status'             => $attachment->status instanceof \BackedEnum
                ? $attachment->status->value
                : $attachment->status,
            'validity_days'      => $attachment->validity_days,
            'expires_at'         => $attachment->expires_at?->toIso8601String(),
            'uploaded_by'        => $attachment->uploaded_by,
            'department_id'      => $attachment->department_id,
            'created_at'         => $attachment->created_at?->toIso8601String(),
        ];
    }
}
