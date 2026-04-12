<?php

use App\Exceptions\Attachment\AttachmentCapacityExceededException;
use App\Exceptions\Attachment\AttachmentExpiredException;
use App\Exceptions\Attachment\AttachmentRevokedException;
use App\Exceptions\Attachment\DuplicateAttachmentException;
use App\Exceptions\Attachment\InvalidMimeTypeException;
use App\Exceptions\Attachment\LinkConsumedException;
use App\Exceptions\Attachment\LinkExpiredException;
use App\Exceptions\Attachment\LinkRevokedException;
use App\Exceptions\Auth\AccountLockedException;
use App\Exceptions\Configuration\CanaryCapExceededException;
use App\Exceptions\Configuration\CanaryNotReadyToPromoteException;
use App\Exceptions\Configuration\CanaryStoreCountMisconfiguredException;
use App\Exceptions\Configuration\InvalidRolloutTransitionException;
use App\Exceptions\Document\DocumentArchivedException;
use App\Exceptions\Document\PdfWatermarkFailedException;
use App\Exceptions\Sales\InvalidSalesTransitionException;
use App\Exceptions\Sales\OutboundLinkageNotAllowedException;
use App\Exceptions\Sales\ReturnWindowExpiredException;
use App\Exceptions\Workflow\ReasonRequiredException;
use App\Exceptions\Workflow\WorkflowNodeNotActionableException;
use App\Exceptions\Workflow\WorkflowInstanceAlreadyLinkedException;
use App\Exceptions\Workflow\WorkflowTemplateApplicabilityException;
use App\Exceptions\Workflow\WorkflowTerminatedException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'idempotency'    => \App\Http\Middleware\IdempotencyMiddleware::class,
            'mask.sensitive' => \App\Http\Middleware\MaskSensitiveFields::class,
            'record.timing'  => \App\Http\Middleware\RecordRequestTimingMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // 423 — Account locked after repeated failed login attempts
        $exceptions->render(function (AccountLockedException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code'    => 'account_locked',
                        'message' => 'Account is temporarily locked due to repeated failed login attempts.',
                        'details' => [
                            'locked_until' => $e->getLockedUntil()?->format(\DateTimeInterface::ATOM),
                        ],
                    ],
                ], 423);
            }
        });

        // 409 — Document archived (cannot modify or add versions)
        $exceptions->render(function (DocumentArchivedException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code'    => 'document_archived',
                        'message' => $e->getMessage(),
                        'details' => (object) [],
                    ],
                ], 409);
            }
        });

        // 409 — Controlled PDF download denied because watermarking failed
        $exceptions->render(function (PdfWatermarkFailedException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code'    => 'pdf_watermark_failed',
                        'message' => $e->getMessage(),
                        'details' => (object) [],
                    ],
                ], 409);
            }
        });

        // 409 — Duplicate attachment fingerprint
        $exceptions->render(function (DuplicateAttachmentException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code'    => 'duplicate_attachment',
                        'message' => $e->getMessage(),
                        'details' => (object) [],
                    ],
                ], 409);
            }
        });

        // 422 — Attachment count limit exceeded
        $exceptions->render(function (AttachmentCapacityExceededException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code'    => 'attachment_limit_exceeded',
                        'message' => $e->getMessage(),
                        'details' => (object) [],
                    ],
                ], 422);
            }
        });

        // 422 — File header/MIME mismatch for attachment upload
        $exceptions->render(function (InvalidMimeTypeException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code'    => 'invalid_mime_type',
                        'message' => $e->getMessage(),
                        'details' => (object) [],
                    ],
                ], 422);
            }
        });

        // 410 — Attachment expired
        $exceptions->render(function (AttachmentExpiredException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code'    => 'attachment_expired',
                        'message' => $e->getMessage(),
                        'details' => (object) [],
                    ],
                ], 410);
            }
        });

        // 410 — Attachment revoked
        $exceptions->render(function (AttachmentRevokedException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code'    => 'attachment_revoked',
                        'message' => $e->getMessage(),
                        'details' => (object) [],
                    ],
                ], 410);
            }
        });

        // 410 — Share link expired
        $exceptions->render(function (LinkExpiredException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code'    => 'link_expired',
                        'message' => $e->getMessage(),
                        'details' => (object) [],
                    ],
                ], 410);
            }
        });

        // 410 — Share link already consumed (single-use)
        $exceptions->render(function (LinkConsumedException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code'    => 'link_consumed',
                        'message' => $e->getMessage(),
                        'details' => (object) [],
                    ],
                ], 410);
            }
        });

        // 410 — Share link revoked
        $exceptions->render(function (LinkRevokedException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code'    => 'link_revoked',
                        'message' => $e->getMessage(),
                        'details' => (object) [],
                    ],
                ], 410);
            }
        });

        // 422 — Canary rollout cap exceeded
        $exceptions->render(function (CanaryCapExceededException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code'    => 'canary_cap_exceeded',
                        'message' => $e->getMessage(),
                        'details' => (object) [],
                    ],
                ], 422);
            }
        });

        // 409 — Store-target canary rollout is misconfigured
        $exceptions->render(function (CanaryStoreCountMisconfiguredException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code'    => 'canary_store_count_misconfigured',
                        'message' => $e->getMessage(),
                        'details' => (object) [],
                    ],
                ], 409);
            }
        });

        // 409 — Canary rollout not ready to promote (24h window not elapsed)
        $exceptions->render(function (CanaryNotReadyToPromoteException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code'    => 'canary_not_ready',
                        'message' => $e->getMessage(),
                        'details' => (object) [],
                    ],
                ], 409);
            }
        });

        // 409 — Invalid rollout status transition
        $exceptions->render(function (InvalidRolloutTransitionException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code'    => 'invalid_rollout_transition',
                        'message' => $e->getMessage(),
                        'details' => (object) [],
                    ],
                ], 409);
            }
        });

        // 409 — Sales document already has a linked workflow instance
        $exceptions->render(function (WorkflowInstanceAlreadyLinkedException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code'    => 'workflow_instance_already_linked',
                        'message' => $e->getMessage(),
                        'details' => (object) [],
                    ],
                ], 409);
            }
        });

        // 409 — Workflow instance already in terminal state
        $exceptions->render(function (WorkflowTerminatedException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code'    => 'workflow_terminated',
                        'message' => $e->getMessage(),
                        'details' => (object) [],
                    ],
                ], 409);
            }
        });

        // 422 — Workflow template event type or amount threshold mismatch
        $exceptions->render(function (WorkflowTemplateApplicabilityException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code'    => 'workflow_template_not_applicable',
                        'message' => $e->getMessage(),
                        'details' => (object) [],
                    ],
                ], 422);
            }
        });

        // 422 — Reason required for workflow action
        $exceptions->render(function (ReasonRequiredException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code'    => 'reason_required',
                        'message' => $e->getMessage(),
                        'details' => (object) [],
                    ],
                ], 422);
            }
        });

        // 409 — Workflow node not in actionable state
        $exceptions->render(function (WorkflowNodeNotActionableException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code'    => 'node_not_actionable',
                        'message' => $e->getMessage(),
                        'details' => (object) [],
                    ],
                ], 409);
            }
        });

        // 409 — Invalid sales document status transition
        $exceptions->render(function (InvalidSalesTransitionException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code'    => 'invalid_sales_transition',
                        'message' => $e->getMessage(),
                        'details' => (object) [],
                    ],
                ], 409);
            }
        });

        // 409 — Outbound linkage only allowed on completed documents
        $exceptions->render(function (OutboundLinkageNotAllowedException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code'    => 'outbound_linkage_not_allowed',
                        'message' => $e->getMessage(),
                        'details' => (object) [],
                    ],
                ], 409);
            }
        });

        // 422 — Return window has expired
        $exceptions->render(function (ReturnWindowExpiredException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code'    => 'return_window_expired',
                        'message' => $e->getMessage(),
                        'details' => (object) [],
                    ],
                ], 422);
            }
        });

        // 422 — Validation failure
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code'    => 'validation_error',
                        'message' => 'The given data was invalid.',
                        'details' => $e->errors(),
                    ],
                ], 422);
            }
        });

        // 401 — Authentication required
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code'    => 'unauthenticated',
                        'message' => 'Authentication required.',
                        'details' => (object) [],
                    ],
                ], 401);
            }
        });

        // 403 — Insufficient permissions
        // Laravel's mapException() converts AuthorizationException → AccessDeniedHttpException
        // BEFORE render callbacks are checked, so we must catch the mapped class.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e, Request $request) {
            return response()->json([
                'error' => [
                    'code'    => 'forbidden',
                    'message' => 'You are not authorized to perform this action.',
                    'details' => (object) [],
                ],
            ], 403);
        });

        // 429 — Too many requests
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/*')) {
                $response = response()->json([
                    'error' => [
                        'code'    => 'rate_limited',
                        'message' => 'Too many requests. Please retry later.',
                        'details' => (object) [],
                    ],
                ], 429);

                $retryAfter = $e->getHeaders()['Retry-After'] ?? null;
                if ($retryAfter !== null) {
                    $response->headers->set('Retry-After', $retryAfter);
                }

                return $response;
            }
        });

        // 404 — Resource not found
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code'    => 'not_found',
                        'message' => 'The requested resource was not found.',
                        'details' => (object) [],
                    ],
                ], 404);
            }
        });

        // 405 — Method not allowed
        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code'    => 'method_not_allowed',
                        'message' => 'HTTP method not allowed for this endpoint.',
                        'details' => (object) [],
                    ],
                ], 405);
            }
        });

        // 500 — Generic server error (production only — dev shows stack trace)
        $exceptions->render(function (\Throwable $e, Request $request) {
            if ($request->is('api/*') && app()->environment('production')) {
                return response()->json([
                    'error' => [
                        'code'    => 'server_error',
                        'message' => 'An unexpected error occurred.',
                        'details' => (object) [],
                    ],
                ], 500);
            }
        });
    })
    ->create();
