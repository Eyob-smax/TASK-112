<?php

namespace App\Application\Workflow;

use App\Application\Metrics\MetricsRetentionService;
use App\Application\Todo\TodoService;
use App\Domain\Audit\Contracts\AuditEventRepositoryInterface;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Workflow\Enums\ApprovalAction;
use App\Domain\Workflow\Enums\NodeType;
use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Domain\Workflow\ValueObjects\SlaDefaults;
use App\Exceptions\Workflow\ReasonRequiredException;
use App\Exceptions\Workflow\WorkflowNodeNotActionableException;
use App\Exceptions\Workflow\WorkflowInstanceAlreadyLinkedException;
use App\Exceptions\Workflow\WorkflowTemplateApplicabilityException;
use App\Exceptions\Workflow\WorkflowTerminatedException;
use Illuminate\Auth\Access\AuthorizationException;
use App\Infrastructure\Persistence\EloquentWorkflowRepository;
use App\Models\Approval;
use App\Models\ConfigurationVersion;
use App\Models\Document;
use App\Models\ReturnRecord;
use App\Models\SalesDocument;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowNode;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateNode;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Orchestrates workflow template and instance lifecycle.
 *
 * Responsibilities:
 *   - Create workflow templates with multi-level, parallel, and conditional nodes
 *   - Instantiate workflows against business records (sales docs, config versions, etc.)
 *   - Process approval actions: approve, reject, reassign, add-approver, withdraw
 *   - Create to-do items for each assigned approver
 *   - Advance workflow state machine when nodes complete
 *
 * CRITICAL INVARIANTS:
 *   - reject() and reassign() REQUIRE a non-empty reason at the service layer
 *   - Parallel sign-off: ALL nodes at the same node_order must be approved before advancing
 *   - withdrawal only allowed in non-terminal (Pending/InProgress) states
 *   - AuditAction enum values referenced by case name (PascalCase), not string value
 */
class WorkflowService
{
    public function __construct(
        private readonly EloquentWorkflowRepository $repo,
        private readonly AuditEventRepositoryInterface $audit,
        private readonly TodoService $todo,
        private readonly MetricsRetentionService $metrics,
    ) {}

    // -------------------------------------------------------------------------
    // Templates
    // -------------------------------------------------------------------------

    /**
     * Create a workflow template with its ordered node definitions.
     *
     * @param array $nodeDefinitions Array of node configs (node_type, node_order, role_required, etc.)
     */
    public function createTemplate(
        User $user,
        array $data,
        array $nodeDefinitions,
        string $ipAddress
    ): WorkflowTemplate {
        $template = WorkflowTemplate::create([
            'name'                 => $data['name'],
            'description'          => $data['description'] ?? null,
            'event_type'           => $data['event_type'],
            'department_id'        => $data['department_id'] ?? null,
            'amount_threshold_min' => $data['amount_threshold_min'] ?? null,
            'amount_threshold_max' => $data['amount_threshold_max'] ?? null,
            'is_active'            => true,
            'created_by'           => $user->id,
        ]);

        foreach ($nodeDefinitions as $i => $nodeDef) {
            WorkflowTemplateNode::create([
                'workflow_template_id' => $template->id,
                'node_order'           => $nodeDef['node_order'] ?? ($i + 1),
                'node_type'            => $nodeDef['node_type'],
                'role_required'        => $nodeDef['role_required'] ?? null,
                'user_required'        => $nodeDef['user_required'] ?? null,
                'sla_business_days'    => $nodeDef['sla_business_days'] ?? SlaDefaults::DEFAULT_SLA_BUSINESS_DAYS,
                'is_parallel'          => $nodeDef['is_parallel'] ?? false,
                'condition_field'      => $nodeDef['condition_field'] ?? null,
                'condition_operator'   => $nodeDef['condition_operator'] ?? null,
                'condition_value'      => $nodeDef['condition_value'] ?? null,
                'label'                => $nodeDef['label'] ?? null,
            ]);
        }

        $this->recordAudit(AuditAction::Create, $user->id, WorkflowTemplate::class, $template->id, $ipAddress);

        return $template->load(['nodes']);
    }

    // -------------------------------------------------------------------------
    // Instances
    // -------------------------------------------------------------------------

    /**
     * Start a workflow instance for a business record.
     *
     * Creates instance, instantiates template nodes (skipping conditional nodes
     * whose conditions evaluate false), computes SLA due dates, and creates
     * to-do items for assigned approvers.
     *
    * @param string $recordType Supported short type string: document | sales_document | return | configuration_version
     * @param string $recordId   UUID of the business record requiring approval
     * @param array  $contextData Key-value data used to evaluate conditional nodes
     */
    public function startInstance(
        User $user,
        string $recordType,
        string $recordId,
        WorkflowTemplate $template,
        array $contextData,
        string $ipAddress
    ): WorkflowInstance {
        $targetRecord = $this->resolveTargetRecordOrFail($recordType, $recordId);
        $this->authorizeTargetRecord($user, $targetRecord);
        $this->assertTemplateApplicable($template, $contextData);

        $templateNodes = $template->nodes()->orderBy('node_order')->get();
        $instance = DB::transaction(function () use ($recordType, $recordId, $template, $templateNodes, $contextData, $user) {
            $salesDocument = null;

            if ($recordType === 'sales_document') {
                $salesDocument = SalesDocument::query()
                    ->lockForUpdate()
                    ->find($recordId);

                if ($salesDocument === null) {
                    abort(404, "Workflow target record was not found for type '{$recordType}'.");
                }

                if ($salesDocument->workflow_instance_id !== null) {
                    throw new WorkflowInstanceAlreadyLinkedException();
                }
            }

            $instance = $this->repo->createInstance([
                'workflow_template_id' => $template->id,
                'record_type'          => $recordType,
                'record_id'            => $recordId,
                'status'               => WorkflowStatus::InProgress->value,
                'initiated_by'         => $user->id,
                'started_at'           => now(),
            ]);

            foreach ($templateNodes as $templateNode) {
                // Skip conditional nodes whose condition evaluates to false
                if ($templateNode->node_type === NodeType::Conditional
                    && !$this->evaluateCondition($templateNode, $contextData)
                ) {
                    continue;
                }

                $slaBusinessDays = $templateNode->sla_business_days ?? SlaDefaults::DEFAULT_SLA_BUSINESS_DAYS;
                $slaDueAt = SlaDefaults::calculateDueAt(new \DateTimeImmutable(), $slaBusinessDays);

                $assignedTo = $templateNode->user_required
                    ?? $this->resolveRoleAssignee($templateNode->role_required, $template->department_id)
                    ?? null;

                $node = $this->repo->createNode([
                    'workflow_instance_id' => $instance->id,
                    'template_node_id'     => $templateNode->id,
                    'node_order'           => $templateNode->node_order,
                    'node_type'            => $templateNode->node_type->value,
                    'assigned_to'          => $assignedTo,
                    'status'               => WorkflowStatus::Pending->value,
                    'sla_due_at'           => $slaDueAt,
                    'condition_field'      => $templateNode->condition_field,
                    'condition_operator'   => $templateNode->condition_operator,
                    'condition_value'      => $templateNode->condition_value,
                    'label'                => $templateNode->label,
                ]);

                // Create a to-do item for the assigned user
                if ($assignedTo) {
                    $this->todo->create(
                        userId:         $assignedTo,
                        type:           'workflow_approval',
                        title:          "Approval Required: {$template->name}",
                        body:           "A workflow node requires your approval. Record type: {$recordType}, ID: {$recordId}.",
                        workflowNodeId: $node->id,
                        dueAt:          Carbon::instance(\DateTime::createFromImmutable($slaDueAt)),
                    );
                }
            }

            if ($salesDocument !== null) {
                $salesDocument->update(['workflow_instance_id' => $instance->id]);
            }

            return $instance;
        });

        $this->recordAudit(AuditAction::Create, $user->id, WorkflowInstance::class, $instance->id, $ipAddress);

        return $instance->load(['nodes', 'template']);
    }

    // -------------------------------------------------------------------------
    // Approval Actions
    // -------------------------------------------------------------------------

    /**
     * Approve a workflow node.
     *
     * For parallel nodes: only advances the workflow when ALL nodes at the same
     * node_order have been approved. For sequential nodes: advances immediately.
     *
     * @throws WorkflowNodeNotActionableException If node is not in an actionable state
     * @throws WorkflowTerminatedException        If the parent instance is already terminal
     */
    public function approve(User $user, WorkflowNode $node, string $ipAddress): void
    {
        DB::transaction(function () use ($user, $node, $ipAddress) {
            $this->guardNodeActionable($node, $user);

            $operationId = $this->newWorkflowAuditOperationId(AuditAction::Approve, $node->id);
            $actionedAt = now();

            Approval::create([
                'workflow_node_id' => $node->id,
                'actor_id'         => $user->id,
                'action'           => ApprovalAction::Approve->value,
                'actioned_at'      => $actionedAt,
            ]);

            $beforeHash = $this->modelHash($node);

            $node->update([
                'status'       => WorkflowStatus::Approved->value,
                'completed_at' => $actionedAt,
            ]);

            $node = $node->fresh();

            $this->recordWorkflowMutationAudit(
                action: AuditAction::Approve,
                actorId: $user->id,
                auditableType: WorkflowNode::class,
                auditableId: $node->id,
                ipAddress: $ipAddress,
                operationId: $operationId,
                mutation: 'acted_node_approved',
                payload: [
                    'workflow_instance_id' => $node->workflow_instance_id,
                    'node_order' => $node->node_order,
                ],
                beforeHash: $beforeHash,
                afterHash: $this->modelHash($node),
            );

            $shouldAdvance = true;

            if ($node->node_type === NodeType::Parallel) {
                $shouldAdvance = $this->repo->allParallelNodesApproved($node->workflow_instance_id, $node->node_order);
            }

            if (!$shouldAdvance) {
                return;
            }

            $nextNode = $this->repo->findNextPendingNode($node->workflow_instance_id, $node->node_order);

            if ($nextNode !== null) {
                $nextNodeBeforeHash = $this->modelHash($nextNode);

                $nextNode->update(['status' => WorkflowStatus::InProgress->value]);
                $nextNode = $nextNode->fresh();

                $this->recordWorkflowMutationAudit(
                    action: AuditAction::Approve,
                    actorId: $user->id,
                    auditableType: WorkflowNode::class,
                    auditableId: $nextNode->id,
                    ipAddress: $ipAddress,
                    operationId: $operationId,
                    mutation: 'next_node_activated',
                    payload: [
                        'workflow_instance_id' => $nextNode->workflow_instance_id,
                        'node_order' => $nextNode->node_order,
                        'source_node_id' => $node->id,
                    ],
                    beforeHash: $nextNodeBeforeHash,
                    afterHash: $this->modelHash($nextNode),
                );

                if ($nextNode->assigned_to !== null && $nextNode->sla_due_at !== null) {
                    $this->todo->create(
                        userId: $nextNode->assigned_to,
                        type: 'workflow_approval',
                        title: 'Approval Required',
                        body: 'Your approval is needed for a workflow node.',
                        workflowNodeId: $nextNode->id,
                        dueAt: Carbon::instance($nextNode->sla_due_at->toDateTime()),
                    );
                }

                return;
            }

            $instance = WorkflowInstance::query()->findOrFail($node->workflow_instance_id);
            $instanceBeforeHash = $this->modelHash($instance);

            $instance->update([
                'status'       => WorkflowStatus::Approved->value,
                'completed_at' => $actionedAt,
            ]);

            $instance = $instance->fresh();

            $this->recordWorkflowMutationAudit(
                action: AuditAction::Approve,
                actorId: $user->id,
                auditableType: WorkflowInstance::class,
                auditableId: $instance->id,
                ipAddress: $ipAddress,
                operationId: $operationId,
                mutation: 'instance_completed',
                payload: [
                    'record_type' => $instance->record_type,
                    'record_id' => $instance->record_id,
                    'source_node_id' => $node->id,
                ],
                beforeHash: $instanceBeforeHash,
                afterHash: $this->modelHash($instance),
            );
        });
    }

    /**
     * Reject a workflow node.
     *
     * Reason is MANDATORY ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â throws ReasonRequiredException if empty.
     * Rejection is terminal: propagates to the parent instance.
     *
     * @throws WorkflowNodeNotActionableException If node is not actionable
     * @throws WorkflowTerminatedException        If instance is already terminal
     * @throws ReasonRequiredException            If reason is empty
     */
    public function reject(User $user, WorkflowNode $node, string $reason, string $ipAddress): void
    {
        if (empty(trim($reason))) {
            throw new ReasonRequiredException();
        }

        DB::transaction(function () use ($user, $node, $reason, $ipAddress) {
            $this->guardNodeActionable($node, $user);

            $operationId = $this->newWorkflowAuditOperationId(AuditAction::Reject, $node->id);
            $actionedAt = now();

            Approval::create([
                'workflow_node_id' => $node->id,
                'actor_id'         => $user->id,
                'action'           => ApprovalAction::Reject->value,
                'reason'           => $reason,
                'actioned_at'      => $actionedAt,
            ]);

            $beforeHash = $this->modelHash($node);

            $node->update([
                'status'       => WorkflowStatus::Rejected->value,
                'completed_at' => $actionedAt,
            ]);

            $node = $node->fresh();

            $this->recordWorkflowMutationAudit(
                action: AuditAction::Reject,
                actorId: $user->id,
                auditableType: WorkflowNode::class,
                auditableId: $node->id,
                ipAddress: $ipAddress,
                operationId: $operationId,
                mutation: 'acted_node_rejected',
                payload: [
                    'workflow_instance_id' => $node->workflow_instance_id,
                    'reason' => $reason,
                ],
                beforeHash: $beforeHash,
                afterHash: $this->modelHash($node),
            );

            $instance = WorkflowInstance::query()->findOrFail($node->workflow_instance_id);
            $instanceBeforeHash = $this->modelHash($instance);

            $instance->update([
                'status'       => WorkflowStatus::Rejected->value,
                'completed_at' => $actionedAt,
            ]);

            $instance = $instance->fresh();

            $this->recordWorkflowMutationAudit(
                action: AuditAction::Reject,
                actorId: $user->id,
                auditableType: WorkflowInstance::class,
                auditableId: $instance->id,
                ipAddress: $ipAddress,
                operationId: $operationId,
                mutation: 'instance_rejected',
                payload: [
                    'record_type' => $instance->record_type,
                    'record_id' => $instance->record_id,
                    'reason' => $reason,
                    'source_node_id' => $node->id,
                ],
                beforeHash: $instanceBeforeHash,
                afterHash: $this->modelHash($instance),
            );

            $this->metrics->record('failed_approvals', 1.0, [
                'node_id'     => $node->id,
                'instance_id' => $node->workflow_instance_id,
            ]);
        });
    }

    /**
     * Reassign a workflow node to a different user.
     *
     * Reason is MANDATORY ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â throws ReasonRequiredException if empty.
     * Creates a new to-do item for the new assignee.
     *
     * @throws WorkflowNodeNotActionableException If node is not actionable
     * @throws WorkflowTerminatedException        If instance is already terminal
     * @throws ReasonRequiredException            If reason is empty
     */
    public function reassign(
        User $user,
        WorkflowNode $node,
        string $newAssigneeId,
        string $reason,
        string $ipAddress
    ): void {
        $this->guardNodeActionable($node, $user);

        if (empty(trim($reason))) {
            throw new ReasonRequiredException();
        }

        Approval::create([
            'workflow_node_id' => $node->id,
            'actor_id'         => $user->id,
            'action'           => ApprovalAction::Reassign->value,
            'reason'           => $reason,
            'target_user_id'   => $newAssigneeId,
            'actioned_at'      => now(),
        ]);

        $beforeHash = hash('sha256', json_encode($node->toArray()));
        $node->update(['assigned_to' => $newAssigneeId]);
        $afterHash = hash('sha256', json_encode($node->fresh()->toArray()));

        $this->todo->create(
            userId:         $newAssigneeId,
            type:           'workflow_approval',
            title:          'Reassigned Approval Required',
            body:           'A workflow approval has been reassigned to you.',
            workflowNodeId: $node->id,
            dueAt:          Carbon::instance($node->sla_due_at->toDateTime()),
        );

        $this->recordAudit(AuditAction::Reassign, $user->id, WorkflowNode::class, $node->id, $ipAddress, beforeHash: $beforeHash, afterHash: $afterHash);
    }

    /**
     * Add an additional approver to a workflow node by creating a new parallel node.
     *
     * The new node is created at the same node_order with type=Parallel and
     * template_node_id=null (dynamically added). The original node is unchanged.
     *
     * @throws WorkflowTerminatedException If the parent instance is already terminal
     */
    public function addApprover(
        User $user,
        WorkflowNode $node,
        string $newApproverId,
        string $ipAddress
    ): void {
        if (
            $node->assigned_to !== null
            && $node->assigned_to !== $user->id
            && !$this->isSupervisor($user)
        ) {
            throw new AuthorizationException('You are not the assigned approver for this workflow node.');
        }

        $instance = $node->instance;

        if ($instance->status->isTerminal()) {
            throw new WorkflowTerminatedException();
        }

        DB::transaction(function () use ($user, $node, $newApproverId, $ipAddress) {
            $operationId = $this->newWorkflowAuditOperationId(AuditAction::AddApprover, $node->id);

            Approval::create([
                'workflow_node_id' => $node->id,
                'actor_id'         => $user->id,
                'action'           => ApprovalAction::AddApprover->value,
                'target_user_id'   => $newApproverId,
                'actioned_at'      => now(),
            ]);

            $newNode = $this->repo->createNode([
                'workflow_instance_id' => $node->workflow_instance_id,
                'template_node_id'     => null,
                'node_order'           => $node->node_order,
                'node_type'            => NodeType::Parallel->value,
                'assigned_to'          => $newApproverId,
                'status'               => WorkflowStatus::Pending->value,
                'sla_due_at'           => $node->sla_due_at,
                'label'                => 'Additional approver (added dynamically)',
            ]);

            $dueAt = $newNode->sla_due_at !== null
                ? Carbon::instance($newNode->sla_due_at->toDateTime())
                : null;

            $this->todo->create(
                userId: $newApproverId,
                type: 'workflow_approval',
                title: 'Additional Approval Required',
                body: 'You have been added as an additional approver for a workflow node.',
                workflowNodeId: $newNode->id,
                dueAt: $dueAt,
            );

            $this->recordWorkflowMutationAudit(
                action: AuditAction::AddApprover,
                actorId: $user->id,
                auditableType: WorkflowNode::class,
                auditableId: $newNode->id,
                ipAddress: $ipAddress,
                operationId: $operationId,
                mutation: 'added_node_created',
                payload: [
                    'workflow_instance_id' => $newNode->workflow_instance_id,
                    'source_node_id' => $node->id,
                    'assigned_to' => $newApproverId,
                ],
                afterHash: $this->modelHash($newNode),
            );
        });
    }

    /**
     * Withdraw a workflow instance before it reaches a terminal approval state.
     *
     * Cancels all pending/in-progress nodes. Only allowed in non-terminal states.
     *
     * @throws WorkflowTerminatedException If the instance is already in a terminal state
     */
    public function withdraw(
        User $user,
        WorkflowInstance $instance,
        string $reason,
        string $ipAddress
    ): void {
        DB::transaction(function () use ($user, $instance, $reason, $ipAddress) {
            $instance = WorkflowInstance::query()->lockForUpdate()->findOrFail($instance->id);

            if (!$instance->status->allowsWithdrawal()) {
                throw new WorkflowTerminatedException(
                    'This workflow instance has already reached a terminal state and cannot be withdrawn.'
                );
            }

            $operationId = $this->newWorkflowAuditOperationId(AuditAction::Withdraw, $instance->id);
            $withdrawnAt = now();
            $instanceBeforeHash = $this->modelHash($instance);

            $instance->update([
                'status'            => WorkflowStatus::Withdrawn->value,
                'withdrawn_at'      => $withdrawnAt,
                'withdrawn_by'      => $user->id,
                'withdrawal_reason' => $reason,
            ]);

            $instance = $instance->fresh();

            $this->recordWorkflowMutationAudit(
                action: AuditAction::Withdraw,
                actorId: $user->id,
                auditableType: WorkflowInstance::class,
                auditableId: $instance->id,
                ipAddress: $ipAddress,
                operationId: $operationId,
                mutation: 'instance_withdrawn',
                payload: [
                    'record_type' => $instance->record_type,
                    'record_id' => $instance->record_id,
                    'reason' => $reason,
                ],
                beforeHash: $instanceBeforeHash,
                afterHash: $this->modelHash($instance),
            );

            $activeNodes = WorkflowNode::query()
                ->where('workflow_instance_id', $instance->id)
                ->whereIn('status', [
                    WorkflowStatus::Pending->value,
                    WorkflowStatus::InProgress->value,
                ])
                ->get();

            foreach ($activeNodes as $activeNode) {
                $nodeBeforeHash = $this->modelHash($activeNode);

                $activeNode->update([
                    'status'       => WorkflowStatus::Withdrawn->value,
                    'completed_at' => $withdrawnAt,
                ]);

                $activeNode = $activeNode->fresh();

                $this->recordWorkflowMutationAudit(
                    action: AuditAction::Withdraw,
                    actorId: $user->id,
                    auditableType: WorkflowNode::class,
                    auditableId: $activeNode->id,
                    ipAddress: $ipAddress,
                    operationId: $operationId,
                    mutation: 'node_withdrawn',
                    payload: [
                        'workflow_instance_id' => $instance->id,
                        'node_order' => $activeNode->node_order,
                        'reason' => $reason,
                    ],
                    beforeHash: $nodeBeforeHash,
                    afterHash: $this->modelHash($activeNode),
                );
            }
        });
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Guard that a node is in an actionable state, its instance is not terminal,
     * and the acting user is the assigned approver (or holds a supervisory role).
     *
     * @throws AuthorizationException             If user is not the assigned approver
     * @throws WorkflowNodeNotActionableException If node is not in an actionable state
     * @throws WorkflowTerminatedException        If the parent instance is already terminal
     */
    private function guardNodeActionable(WorkflowNode $node, User $user): void
    {
        // BLOCKER-2: Enforce sequential predecessor ordering ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â all lower-order nodes must be terminal.
        // This prevents out-of-order approval bypasses in multi-step workflows.
        $blockedByPredecessor = WorkflowNode::where('workflow_instance_id', $node->workflow_instance_id)
            ->where('node_order', '<', $node->node_order)
            ->whereNotIn('status', [WorkflowStatus::Approved->value, WorkflowStatus::Rejected->value])
            ->exists();

        if ($blockedByPredecessor) {
            throw new WorkflowNodeNotActionableException('Predecessor nodes must complete before this node can be actioned.');
        }

        // Unassigned nodes are restricted to supervisors only.
        if ($node->assigned_to === null) {
            if (!$this->isSupervisor($user)) {
                throw new AuthorizationException('This node has no assigned approver. Only managers or admins may act on unassigned nodes.');
            }
        } elseif ($node->assigned_to !== $user->id && !$this->isSupervisor($user)) {
            throw new AuthorizationException('You are not the assigned approver for this workflow node.');
        }

        if (!$node->status->isActionable()) {
            throw new WorkflowNodeNotActionableException();
        }

        if ($node->instance->status->isTerminal()) {
            throw new WorkflowTerminatedException();
        }
    }

    /**
     * Assert that the template's event_type and amount thresholds are satisfied by the context.
     *
     * - If context supplies 'event_type', it must match the template's event_type.
     * - If the template has amount_threshold_min or amount_threshold_max set and context
     *   supplies 'amount', the amount must fall within the configured range.
     *
     * @throws WorkflowTemplateApplicabilityException
     */
    private function assertTemplateApplicable(WorkflowTemplate $template, array $contextData): void
    {
        // Event-type gate: context-supplied event type must match template
        if (isset($contextData['event_type']) && $template->event_type !== null) {
            if ($contextData['event_type'] !== $template->event_type) {
                throw new WorkflowTemplateApplicabilityException(
                    "event_type '{$contextData['event_type']}' does not match template event_type '{$template->event_type}'"
                );
            }
        }

        // Amount-threshold gate: if template has thresholds and context provides an amount,
        // verify the amount falls within [min, max] (inclusive, null = unbounded).
        if (isset($contextData['amount'])
            && ($template->amount_threshold_min !== null || $template->amount_threshold_max !== null)
        ) {
            $amount = (float) $contextData['amount'];

            if ($template->amount_threshold_min !== null && $amount < (float) $template->amount_threshold_min) {
                throw new WorkflowTemplateApplicabilityException(
                    "amount {$amount} is below the template minimum threshold {$template->amount_threshold_min}"
                );
            }

            if ($template->amount_threshold_max !== null && $amount > (float) $template->amount_threshold_max) {
                throw new WorkflowTemplateApplicabilityException(
                    "amount {$amount} exceeds the template maximum threshold {$template->amount_threshold_max}"
                );
            }
        }
    }

    /**
     * Evaluate a conditional node's branch condition against the context data.
     *
     * Returns true if no condition is configured (unconditional node).
     * Supported operators: gt, lt, eq, gte, lte.
     */
    private function evaluateCondition(WorkflowTemplateNode $node, array $contextData): bool
    {
        if (empty($node->condition_field)) {
            return true;
        }

        $fieldValue    = $contextData[$node->condition_field] ?? null;
        $condValue     = $node->condition_value;
        $condOperator  = $node->condition_operator;

        if ($fieldValue === null || $condValue === null || $condOperator === null) {
            return true;
        }

        return match ($condOperator) {
            'gt'    => $fieldValue > $condValue,
            'lt'    => $fieldValue < $condValue,
            'eq'    => $fieldValue == $condValue,
            'gte'   => $fieldValue >= $condValue,
            'lte'   => $fieldValue <= $condValue,
            default => true,
        };
    }

    /**
     * Resolve a supported workflow target record by type/id.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    private function resolveTargetRecordOrFail(string $recordType, string $recordId): mixed
    {
        $modelClass = match ($recordType) {
            'document'              => Document::class,
            'sales_document'        => SalesDocument::class,
            'return'                => ReturnRecord::class,
            'configuration_version' => ConfigurationVersion::class,
            default                 => null,
        };

        if ($modelClass === null) {
            abort(422, "Unsupported workflow record_type '{$recordType}'.");
        }

        $record = $modelClass::find($recordId);

        if ($record === null) {
            abort(404, "Workflow target record was not found for type '{$recordType}'.");
        }

        return $record;
    }

    /**
     * Enforce object-level authorization on the workflow target record.
     */
    private function authorizeTargetRecord(User $user, mixed $targetRecord): void
    {
        if ($targetRecord instanceof ConfigurationVersion) {
            $targetRecord->loadMissing('configurationSet');
            Gate::forUser($user)->authorize('view', $targetRecord->configurationSet);
            return;
        }

        Gate::forUser($user)->authorize('view', $targetRecord);
    }

    private function isSupervisor(User $user): bool
    {
        return $user->hasRole(['admin', 'manager']);
    }

    /**
     * Resolve the first available user with the given role (and optionally department).
     *
     * Returns null if no matching user is found ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â the node will have assigned_to=null.
     */
    private function resolveRoleAssignee(?string $roleId, ?string $departmentId): ?string
    {
        if ($roleId === null) {
            return null;
        }

        $query = User::whereHas('roles', fn($q) => $q->where('id', $roleId))
            ->where('is_active', true);

        if ($departmentId !== null) {
            $query->where('department_id', $departmentId);
        }

        return $query->value('id');
    }

    private function recordAudit(
        AuditAction $action,
        ?string $actorId,
        string $auditableType,
        string $auditableId,
        string $ipAddress,
        array $payload = [],
        ?string $beforeHash = null,
        ?string $afterHash = null,
        ?string $correlationSuffix = null,
        ?string $correlationSeed = null,
    ): void {
        $correlationBase = $correlationSeed
            ?? request()->header('X-Idempotency-Key')
            ?? (string) Str::uuid();

        $segments = [$correlationBase, $action->value, $auditableType, $auditableId];
        if ($correlationSuffix !== null) {
            $segments[] = $correlationSuffix;
        }

        $correlationId = hash('sha256', implode(':', $segments));

        $this->audit->record(
            correlationId:  $correlationId,
            action:         $action,
            actorId:        $actorId,
            auditableType:  $auditableType,
            auditableId:    $auditableId,
            beforeHash:     $beforeHash,
            afterHash:      $afterHash,
            payload:        $payload,
            ipAddress:      $ipAddress,
        );
    }

    private function recordWorkflowMutationAudit(
        AuditAction $action,
        ?string $actorId,
        string $auditableType,
        string $auditableId,
        string $ipAddress,
        string $operationId,
        string $mutation,
        array $payload = [],
        ?string $beforeHash = null,
        ?string $afterHash = null,
    ): void {
        $this->recordAudit(
            action: $action,
            actorId: $actorId,
            auditableType: $auditableType,
            auditableId: $auditableId,
            ipAddress: $ipAddress,
            payload: array_merge([
                'operation_id' => $operationId,
                'mutation' => $mutation,
            ], $payload),
            beforeHash: $beforeHash,
            afterHash: $afterHash,
            correlationSuffix: $mutation,
            correlationSeed: $operationId,
        );
    }

    private function newWorkflowAuditOperationId(AuditAction $action, string $subjectId): string
    {
        $idempotencyKey = request()->header('X-Idempotency-Key');

        return $idempotencyKey !== null
            ? hash('sha256', $idempotencyKey . ':workflow_operation:' . $action->value . ':' . $subjectId)
            : (string) Str::uuid();
    }

    private function modelHash(Model $model): string
    {
        return hash('sha256', json_encode($model->toArray()));
    }
}
