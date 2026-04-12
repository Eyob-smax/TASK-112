<?php

namespace App\Http\Controllers\Api;

use App\Application\Workflow\WorkflowService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workflow\StoreWorkflowInstanceRequest;
use App\Http\Requests\Workflow\WithdrawWorkflowRequest;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowInstanceController extends Controller
{
    public function __construct(
        private readonly WorkflowService $service,
    ) {}

    /**
     * POST /api/v1/workflow/instances
     */
    public function store(StoreWorkflowInstanceRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $template = WorkflowTemplate::findOrFail($validated['workflow_template_id']);

        $instance = $this->service->startInstance(
            $request->user(),
            $validated['record_type'],
            $validated['record_id'],
            $template,
            $validated['context'] ?? [],
            $request->ip()
        );

        $instance->load(['nodes']);

        return response()->json(['data' => $this->instanceShape($instance, withNodes: true)], 201);
    }

    /**
     * GET /api/v1/workflow/instances/{instance}
     */
    public function show(Request $request, WorkflowInstance $instance): JsonResponse
    {
        $this->authorize('view', $instance);

        $instance->load(['nodes.approvals']);

        return response()->json(['data' => $this->instanceShape($instance, withNodes: true)]);
    }

    /**
     * POST /api/v1/workflow/instances/{instance}/withdraw
     */
    public function withdraw(WithdrawWorkflowRequest $request, WorkflowInstance $instance): JsonResponse
    {
        $user = $request->user();

        $this->authorize('withdraw', $instance);

        $this->service->withdraw(
            $user,
            $instance,
            $request->input('reason', ''),
            $request->ip()
        );

        $instance->refresh()->load(['nodes']);

        return response()->json(['data' => $this->instanceShape($instance, withNodes: true)]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function instanceShape(WorkflowInstance $instance, bool $withNodes = false): array
    {
        $shape = [
            'id'                   => $instance->id,
            'workflow_template_id' => $instance->workflow_template_id,
            'record_type'          => $instance->record_type,
            'record_id'            => $instance->record_id,
            'status'               => $instance->status instanceof \BackedEnum
                                        ? $instance->status->value
                                        : $instance->status,
            'initiated_by'         => $instance->initiated_by,
            'started_at'           => $instance->started_at?->toIso8601String(),
            'completed_at'         => $instance->completed_at?->toIso8601String(),
            'withdrawn_at'         => $instance->withdrawn_at?->toIso8601String(),
            'withdrawn_by'         => $instance->withdrawn_by,
            'withdrawal_reason'    => $instance->withdrawal_reason,
            'created_at'           => $instance->created_at?->toIso8601String(),
            'updated_at'           => $instance->updated_at?->toIso8601String(),
        ];

        if ($withNodes && $instance->relationLoaded('nodes')) {
            $shape['nodes'] = $instance->nodes->map(fn($n) => $this->nodeShape($n))->values();
        }

        return $shape;
    }

    private function nodeShape($node): array
    {
        $shape = [
            'id'               => $node->id,
            'node_type'        => $node->node_type instanceof \BackedEnum ? $node->node_type->value : $node->node_type,
            'node_order'       => $node->node_order,
            'status'           => $node->status instanceof \BackedEnum ? $node->status->value : $node->status,
            'assigned_to'      => $node->assigned_to,
            'sla_due_at'       => $node->sla_due_at?->toIso8601String(),
            'completed_at'     => $node->completed_at?->toIso8601String(),
            'label'            => $node->label,
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

        return $shape;
    }
}
