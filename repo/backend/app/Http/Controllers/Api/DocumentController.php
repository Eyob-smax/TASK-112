<?php

namespace App\Http\Controllers\Api;

use App\Application\Document\DocumentService;
use App\Domain\Document\Enums\VersionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Document\StoreDocumentRequest;
use App\Http\Requests\Document\UpdateDocumentRequest;
use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    public function __construct(
        private readonly DocumentService $service,
    ) {}

    /**
     * GET /api/v1/documents
     *
     * List documents. Department-scoped for non-cross-scope users.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Document::class);

        $user  = $request->user();
        $query = Document::query()->with(['department', 'owner']);

        // Department scope: admin, manager, and auditor see all; others see own department only
        if (!$user->hasRole(['admin', 'manager', 'auditor'])) {
            $query->where('department_id', $user->department_id);
        }

        // Optional filters
        if ($request->has('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        if ($request->has('filter.department_id') && $user->hasRole(['admin', 'manager', 'auditor'])) {
            $query->where('department_id', $request->input('filter.department_id'));
        }

        $perPage = min((int) $request->input('per_page', 25), 100);

        $paginated = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'data' => $paginated->map(fn(Document $d) => $this->documentShape($d)),
            'meta' => [
                'pagination' => [
                    'current_page' => $paginated->currentPage(),
                    'per_page'     => $paginated->perPage(),
                    'total'        => $paginated->total(),
                    'last_page'    => $paginated->lastPage(),
                ],
            ],
        ]);
    }

    /**
     * POST /api/v1/documents
     *
     * Create a new document (draft status).
     * Authorization is enforced in StoreDocumentRequest::authorize().
     */
    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $document = $this->service->create(
            $request->user(),
            $request->validated(),
            $request->ip()
        );

        return response()->json(['data' => $this->documentShape($document)], 201);
    }

    /**
     * GET /api/v1/documents/{document}
     *
     * Show a document with its current version preview metadata.
     */
    public function show(Request $request, Document $document): JsonResponse
    {
        $this->authorize('view', $document);

        $document->load(['department', 'owner']);

        $currentVersion = DocumentVersion::where('document_id', $document->id)
            ->where('status', VersionStatus::Current->value)
            ->first();

        return response()->json([
            'data' => $this->documentShape($document, $currentVersion),
        ]);
    }

    /**
     * PUT /api/v1/documents/{document}
     *
     * Update document metadata. Rejected if document is archived.
     */
    public function update(UpdateDocumentRequest $request, Document $document): JsonResponse
    {
        $this->authorize('update', $document);

        $document = $this->service->update(
            $request->user(),
            $document,
            $request->validated(),
            $request->ip()
        );

        $document->load(['department', 'owner']);

        return response()->json(['data' => $this->documentShape($document)]);
    }

    /**
     * POST /api/v1/documents/{document}/archive
     *
     * Archive a document, freezing it to read-only.
     */
    public function archive(Request $request, Document $document): JsonResponse
    {
        $this->authorize('archive', $document);

        $document = $this->service->archive(
            $request->user(),
            $document,
            $request->ip()
        );

        $document->load(['department', 'owner']);

        return response()->json(['data' => $this->documentShape($document)]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Normalize a Document model to the API response shape.
     */
    private function documentShape(Document $doc, ?DocumentVersion $currentVersion = null): array
    {
        $shape = [
            'id'                   => $doc->id,
            'title'                => $doc->title,
            'document_type'        => $doc->document_type,
            'status'               => $doc->status instanceof \BackedEnum ? $doc->status->value : $doc->status,
            'is_archived'          => $doc->is_archived,
            'archived_at'          => $doc->archived_at?->toIso8601String(),
            'archived_by'          => $doc->archived_by,
            'access_control_scope' => $doc->access_control_scope instanceof \BackedEnum
                ? $doc->access_control_scope->value
                : $doc->access_control_scope,
            'description'          => $doc->description,
            'department_id'        => $doc->department_id,
            'owner_id'             => $doc->owner_id,
            'created_at'           => $doc->created_at?->toIso8601String(),
            'updated_at'           => $doc->updated_at?->toIso8601String(),
        ];

        if ($currentVersion !== null) {
            $shape['current_version'] = [
                'id'                  => $currentVersion->id,
                'version_number'      => $currentVersion->version_number,
                'status'              => $currentVersion->status instanceof \BackedEnum
                    ? $currentVersion->status->value
                    : $currentVersion->status,
                'original_filename'   => $currentVersion->original_filename,
                'mime_type'           => $currentVersion->mime_type,
                'file_size_bytes'     => $currentVersion->file_size_bytes,
                'sha256_fingerprint'  => $currentVersion->sha256_fingerprint,
                'page_count'          => $currentVersion->page_count,
                'sheet_count'         => $currentVersion->sheet_count,
                'is_previewable'      => $currentVersion->is_previewable,
                'thumbnail_available' => $currentVersion->thumbnail_available,
                'created_by'          => $currentVersion->created_by,
                'published_at'        => $currentVersion->published_at?->toIso8601String(),
            ];
        }

        return $shape;
    }
}
