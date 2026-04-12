<?php

namespace App\Http\Controllers\Api;

use App\Application\Workflow\WorkflowService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workflow\WorkflowNodeActionRequest;
use App\Models\WorkflowNode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowNodeController extends Controller
{
    public function __construct(
        private readonly WorkflowService $service,
    ) {}

    /**
     * GET /api/v1/workflow/nodes/{node}
     *
     * Authorization delegates to WorkflowInstancePolicy::view() on the node's parent
     * instance, enforcing initiator/assignee/scope checks at the object level.
     */
    public function show(Request $request, WorkflowNode $node): JsonResponse
    {
        $node->load(['approvals', 'instance']);
        $this->authorize('view', $node->instance);

        return response()->json(['data' => $this->nodeShape($node)]);
    }

    /**
     * POST /api/v1/workflow/nodes/{node}/approve
     */
    public function approve(WorkflowNodeActionRequest $request, WorkflowNode $node): JsonResponse
    {
        $this->authorize('approve', $node->instance);

        $this->service->approve(
            $request->user(),
            $node,
            $request->ip()
        );

        $node->refresh()->load(['approvals', 'instance']);

        return response()->json(['data' => $this->nodeShape($node)]);
    }

    /**
     * POST /api/v1/workflow/nodes/{node}/reject
     */
    public function reject(WorkflowNodeActionRequest $request, WorkflowNode $node): JsonResponse
    {
        $this->authorize('approve', $node->instance);

        $this->service->reject(
            $request->user(),
            $node,
            $request->input('reason') ?? '',
            $request->ip()
        );

        $node->refresh()->load(['approvals', 'instance']);

        return response()->json(['data' => $this->nodeShape($node)]);
    }

    /**
     * POST /api/v1/workflow/nodes/{node}/reassign
     */
    public function reassign(WorkflowNodeActionRequest $request, WorkflowNode $node): JsonResponse
    {
        $this->authorize('approve', $node->instance);

        $targetUserId = $this->validatedTargetUserId($request);

        $this->service->reassign(
            $request->user(),
            $node,
            $targetUserId,
            $request->input('reason') ?? '',
            $request->ip()
        );

        $node->refresh()->load(['approvals', 'instance']);

        return response()->json(['data' => $this->nodeShape($node)]);
    }

    /**
     * POST /api/v1/workflow/nodes/{node}/add-approver
     */
    public function addApprover(WorkflowNodeActionRequest $request, WorkflowNode $node): JsonResponse
    {
        $this->authorize('approve', $node->instance);

        $targetUserId = $this->validatedTargetUserId($request);

        $this->service->addApprover(
            $request->user(),
            $node,
            $targetUserId,
            $request->ip()
        );

        $node->instance->refresh()->load(['nodes.approvals']);

        return response()->json(['data' => $this->nodeShape($node->refresh()->load(['approvals', 'instance']))]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function validatedTargetUserId(WorkflowNodeActionRequest $request): string
    {
        $validated = $request->validate([
            'target_user_id' => ['required', 'uuid', 'exists:users,id'],
        ]);

        return $validated['target_user_id'];
    }

    private function nodeShape(WorkflowNode $node): array
    {
        $shape = [
            'id'                       => $node->id,
            'workflow_instance_id'     => $node->workflow_instance_id,
            'workflow_template_node_id' => $node->template_node_id,
            'node_type'                => $node->node_type instanceof \BackedEnum ? $node->node_type->value : $node->node_type,
            'node_order'               => $node->node_order,
            'status'                   => $node->status instanceof \BackedEnum ? $node->status->value : $node->status,
            'assigned_to'              => $node->assigned_to,
            'sla_due_at'               => $node->sla_due_at?->toIso8601String(),
            'completed_at'             => $node->completed_at?->toIso8601String(),
            'label'                    => $node->label,
        ];

        if ($node->relationLoaded('approvals')) {
            $shape['approvals'] = $node->approvals->map(fn($a) => [
                'id'             => $a->id,
                'action'         => $a->action instanceof \BackedEnum ? $a->action->value : $a->action,
                'actor_id'       => $a->actor_id,
                'reason'         => $a->reason,
                'target_user_id' => $a->target_user_id,
                'created_at'     => $a->created_at?->toIso8601String(),
            ])->values();
        }

        if ($node->relationLoaded('instance')) {
            $shape['instance_status'] = $node->instance->status instanceof \BackedEnum
                ? $node->instance->status->value
                : $node->instance->status;
        }

        return $shape;
    }
}
