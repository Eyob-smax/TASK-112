<?php

namespace App\Application\Todo;

use App\Domain\Audit\Contracts\AuditEventRepositoryInterface;
use App\Domain\Audit\Enums\AuditAction;
use App\Models\ToDoItem;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/**
 * Manages to-do items for users, primarily driven by workflow node assignments.
 *
 * To-do items are the user-visible work queue — each pending workflow node
 * generates a to-do item for the assigned approver.
 */
class TodoService
{
    public function __construct(
        private readonly AuditEventRepositoryInterface $audit,
    ) {}

    /**
     * Create a new to-do item for a user.
     *
     * @param string      $userId         UUID of the user responsible for this item
     * @param string      $type           Category, e.g. 'workflow_approval', 'reminder'
     * @param string      $title          Short display title
     * @param string      $body           Detailed instructions or context
     * @param string|null $workflowNodeId UUID of the associated workflow node, if any
     * @param Carbon|null $dueAt          Optional due date (uses workflow SLA if from a node)
     */
    public function create(
        string $userId,
        string $type,
        string $title,
        string $body,
        ?string $workflowNodeId = null,
        ?Carbon $dueAt = null,
    ): ToDoItem {
        $item = ToDoItem::create([
            'user_id'          => $userId,
            'workflow_node_id' => $workflowNodeId,
            'type'             => $type,
            'title'            => $title,
            'body'             => $body,
            'due_at'           => $dueAt,
        ]);

        $this->recordAudit(
            action: AuditAction::Create,
            actorId: auth()->id(),
            auditableId: $item->id,
            afterHash: hash('sha256', json_encode($item->toArray())),
        );

        return $item;
    }

    /**
     * Mark a to-do item as completed.
     *
     * @throws AuthorizationException If the acting user does not own the item
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If item not found
     */
    public function complete(string $itemId, string $actingUserId): ToDoItem
    {
        $item = ToDoItem::findOrFail($itemId);

        $beforeHash = hash('sha256', json_encode($item->toArray()));

        if ($item->user_id !== $actingUserId) {
            throw new AuthorizationException('You may only complete your own to-do items.');
        }

        $item->update(['completed_at' => now()]);

        $fresh = $item->fresh();

        $this->recordAudit(
            action: AuditAction::Update,
            actorId: $actingUserId,
            auditableId: $item->id,
            beforeHash: $beforeHash,
            afterHash: hash('sha256', json_encode($fresh->toArray())),
        );

        return $fresh;
    }

    private function recordAudit(
        AuditAction $action,
        ?string $actorId,
        string $auditableId,
        array $payload = [],
        ?string $beforeHash = null,
        ?string $afterHash = null,
    ): void {
        $idempotencyKey = $this->resolveIdempotencyKey();
        $correlationId  = $idempotencyKey !== null
            ? hash('sha256', $idempotencyKey . ':' . $auditableId . ':' . $action->value)
            : (string) Str::uuid();

        $this->audit->record(
            correlationId: $correlationId,
            action: $action,
            actorId: $actorId,
            auditableType: ToDoItem::class,
            auditableId: $auditableId,
            beforeHash: $beforeHash,
            afterHash: $afterHash,
            payload: $payload,
            ipAddress: $this->resolveIpAddress(),
        );
    }

    private function resolveIdempotencyKey(): ?string
    {
        try {
            return request()->header('X-Idempotency-Key');
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveIpAddress(): string
    {
        try {
            return request()->ip() ?? '127.0.0.1';
        } catch (\Throwable) {
            return '127.0.0.1';
        }
    }
}
