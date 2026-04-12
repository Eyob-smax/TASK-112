<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Models\WorkflowInstance;
use App\Models\WorkflowNode;

/**
 * Encapsulates workflow persistence operations including parallel sign-off evaluation.
 *
 * CRITICAL INVARIANTS:
 *   - allParallelNodesApproved() checks ALL nodes at the given order level — not just one
 *   - findNextPendingNode() returns only Pending nodes, skipping already-completed ones
 */
class EloquentWorkflowRepository
{
    /**
     * Persist a new workflow instance.
     */
    public function createInstance(array $data): WorkflowInstance
    {
        return WorkflowInstance::create($data);
    }

    /**
     * Persist a new workflow node (template-derived or dynamically added).
     */
    public function createNode(array $data): WorkflowNode
    {
        return WorkflowNode::create($data);
    }

    /**
     * Find the next pending node after a given node_order position.
     *
     * Used to advance the workflow after a sequential or parallel node completes.
     */
    public function findNextPendingNode(string $instanceId, int $afterOrder): ?WorkflowNode
    {
        return WorkflowNode::where('workflow_instance_id', $instanceId)
            ->where('node_order', '>', $afterOrder)
            ->where('status', WorkflowStatus::Pending->value)
            ->orderBy('node_order')
            ->first();
    }

    /**
     * Check whether all nodes at a given node_order position within an instance
     * have been approved. Used for parallel sign-off gating.
     *
     * A parallel group is "fully approved" when every node at that order level
     * has status=Approved (including the one being approved right now, so the
     * check must be called AFTER updating the current node's status).
     */
    public function allParallelNodesApproved(string $instanceId, int $nodeOrder): bool
    {
        $total = WorkflowNode::where('workflow_instance_id', $instanceId)
            ->where('node_order', $nodeOrder)
            ->count();

        if ($total === 0) {
            return false;
        }

        $approved = WorkflowNode::where('workflow_instance_id', $instanceId)
            ->where('node_order', $nodeOrder)
            ->where('status', WorkflowStatus::Approved->value)
            ->count();

        return $approved === $total;
    }
}
