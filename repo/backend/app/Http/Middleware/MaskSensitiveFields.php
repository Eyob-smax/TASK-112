<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Masks the 'notes' field in JSON responses for users who lack cross-department
 * or system-wide permission scope.
 *
 * Users with admin or auditor roles always see unmasked notes.
 * All other users see [REDACTED] in place of notes values.
 *
 * This is an after-middleware — it modifies the response content.
 */
class MaskSensitiveFields
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only act on authenticated JSON responses
        if (!$request->user() || !$this->isJsonResponse($response)) {
            return $response;
        }

        $user = $request->user();

        // Admin and auditor roles always see unmasked data
        if ($user->hasRole(['admin', 'auditor'])) {
            return $response;
        }

        // Users with manage_configuration or system-wide permissions see unmasked data
        // (i.e., those with cross_department or system_wide scope)
        if ($user->hasPermissionTo('manage configuration') ||
            $user->hasRole('manager')) {
            return $response;
        }

        // Mask notes for staff, viewer, and any other role
        $content = json_decode($response->getContent(), associative: true);

        if (is_array($content)) {
            $content = $this->maskNotes($content);
            $response->setContent(json_encode($content));
        }

        return $response;
    }

    /**
     * Recursively replace 'notes' values with '[REDACTED]' at any depth.
     */
    private function maskNotes(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($key === 'notes' && $value !== null) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = $this->maskNotes($value);
            }
        }

        return $data;
    }

    private function isJsonResponse(Response $response): bool
    {
        $contentType = $response->headers->get('Content-Type', '');

        return str_contains($contentType, 'application/json');
    }
}
