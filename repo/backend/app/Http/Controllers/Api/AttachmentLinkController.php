<?php

namespace App\Http\Controllers\Api;

use App\Application\Attachment\AttachmentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attachment\StoreAttachmentLinkRequest;
use App\Models\Attachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentLinkController extends Controller
{
    public function __construct(
        private readonly AttachmentService $service,
    ) {}

    /**
     * POST /api/v1/attachments/{attachment}/links
     *
     * Generate a LAN share link for an attachment.
     * Requires 'download attachments' permission (view policy).
     */
    public function store(StoreAttachmentLinkRequest $request, Attachment $attachment): JsonResponse
    {
        $this->authorize('view', $attachment);

        $link = $this->service->createLink(
            $request->user(),
            $attachment,
            (int) $request->input('ttl_hours'),
            $request->boolean('is_single_use', false),
            $request->input('ip_restriction'),
            $request->ip()
        );

        $url = rtrim(config('meridian.lan_base_url'), '/') . '/api/v1/links/' . $link->token;

        return response()->json([
            'data' => [
                'id'            => $link->id,
                'attachment_id' => $link->attachment_id,
                'url'           => $url,
                'expires_at'    => $link->expires_at->toIso8601String(),
                'is_single_use' => $link->is_single_use,
                'created_by'    => $link->created_by,
            ],
        ], 201);
    }

    /**
     * GET /api/v1/links/{token}
     *
     * Resolve and stream a LAN share link attachment.
     *
     * PUBLIC ROUTE — no Bearer token required. The opaque token is the credential.
     * This endpoint is configured WITHOUT the auth:sanctum middleware in routes/api.php.
     *
     * The response streams the decrypted file content directly. Single-use links
     * are atomically consumed on the first successful resolution.
     */
    public function resolve(Request $request, string $token): StreamedResponse
    {
        $resolverUserId = $request->bearerToken() !== null
            ? $request->user('sanctum')?->id
            : null;

        $result = $this->service->resolveLink(
            $token,
            $request->ip(),
            $resolverUserId,
            $request->userAgent(),
        );

        $content  = $result['content'];
        $mimeType = $result['mime_type'];
        $filename = $result['filename'];

        return response()->stream(
            function () use ($content) {
                echo $content;
            },
            200,
            [
                'Content-Type'        => $mimeType,
                'Content-Disposition' => 'attachment; filename="' . addslashes($filename) . '"',
                'Content-Length'      => strlen($content),
            ]
        );
    }
}
