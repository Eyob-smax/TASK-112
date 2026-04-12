<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Http\Controllers\Controller;
use App\Models\WorkflowNode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin endpoint for inspecting the approval backlog and to-do queue state.
 *
 * Returns workflow nodes that are pending or in_progress, optionally filtered
 * to overdue-only (sla_due_at < now()). Useful for offline operational visibility
 * into stuck or late approvals.
 *
 * Authorization: admin or manager role.
 */
class ApprovalBacklogController extends Controller
{
    /**
     * GET /api/v1/admin/approval-backlog
     *
     * Query params:
     *   - overdue_only      If '1' or 'true', only return nodes past their SLA due date
     *   - filter.instance_id  UUID — filter by workflow instance
     *   - per_page          Items per page (1–200, default 50)
     */
    public function index(Request $request): JsonResponse
    {
        if (!$request->user()->hasRole(['admin', 'manager'])) {
            abort(403, 'Admin or manager role required to view approval backlog.');
        }

        $query = WorkflowNode::query()
            ->with(['instance', 'assignedTo'])
            ->whereIn('status', [
                WorkflowStatus::Pending->value,
                WorkflowStatus::InProgress->value,
            ]);

        if (filter_var($request->input('overdue_only', false), FILTER_VALIDATE_BOOLEAN)) {
            $query->whereNotNull('sla_due_at')->where('sla_due_at', '<', now());
        }

        if ($request->has('filter.instance_id')) {
            $query->where('workflow_instance_id', $request->input('filter.instance_id'));
        }

        $perPage   = min((int) $request->input('per_page', 50), 200);
        $paginated = $query->orderBy('sla_due_at')->paginate($perPage);

        return response()->json([
            'data' => $paginated->map(fn(WorkflowNode $node) => [
                'id'                  => $node->id,
                'workflow_instance_id' => $node->workflow_instance_id,
                'node_order'          => $node->node_order,
                'node_type'           => $node->node_type instanceof \BackedEnum ? $node->node_type->value : $node->node_type,
                'status'              => $node->status instanceof \BackedEnum ? $node->status->value : $node->status,
                'label'               => $node->label,
                'assigned_to'         => $node->assigned_to,
                'sla_due_at'          => $node->sla_due_at?->toIso8601String(),
                'is_overdue'          => $node->sla_due_at !== null && $node->sla_due_at->isPast(),
                'reminded_at'         => $node->reminded_at?->toIso8601String(),
                'instance_status'     => $node->instance?->status instanceof \BackedEnum
                    ? $node->instance->status->value
                    : $node->instance?->status,
            ]),
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
}
