<?php

namespace App\Http\Middleware;

use App\Application\Logging\StructuredLogger;
use App\Application\Metrics\MetricsRetentionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records wall-clock request duration as a `request_timing` metrics snapshot.
 *
 * Applied to the authenticated route group so every API call under auth:sanctum
 * produces an observable data point for operational monitoring.
 *
 * This is an after-middleware — it measures total time including all inner middleware.
 */
class RecordRequestTimingMiddleware
{
    public function __construct(
        private readonly MetricsRetentionService $metrics,
        private readonly StructuredLogger $logger,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);

        $response = $next($request);

        $durationMs = round((microtime(true) - $start) * 1000, 2);
        $statusCode = $response->getStatusCode();

        $this->metrics->record('request_timing', $durationMs, [
            'method' => $request->method(),
            'path'   => $request->path(),
            'status' => $statusCode,
        ]);

        $context = [
            'method'                 => $request->method(),
            'path'                   => $request->path(),
            'status'                 => $statusCode,
            'duration_ms'            => $durationMs,
            'actor_id'               => $request->user()?->id,
            'idempotency_key_present' => $request->headers->has('X-Idempotency-Key'),
        ];

        if ($statusCode >= 500) {
            $this->logger->error('API request completed with server error', $context, 'api');
        } elseif ($statusCode >= 400) {
            $channel = in_array($statusCode, [401, 403], true) ? 'security' : 'api';
            $this->logger->warning('API request completed with client error', $context, $channel);
        } elseif (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $this->logger->info('Mutating API request completed', $context, 'api');
        }

        return $response;
    }
}
