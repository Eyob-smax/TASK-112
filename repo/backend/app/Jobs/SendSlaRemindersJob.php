<?php

namespace App\Jobs;

use App\Application\Logging\StructuredLogger;
use App\Application\Todo\TodoService;
use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Models\WorkflowNode;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends SLA reminder to-do items for overdue workflow nodes.
 *
 * Scheduled hourly. Finds workflow nodes that:
 *   1. Are in a pending or in_progress state
 *   2. Have a non-null sla_due_at that is in the past
 *   3. Have not yet been reminded (reminded_at IS NULL)
 *   4. Have an assigned user (assigned_to IS NOT NULL)
 *
 * For each qualifying node, creates a 'sla_reminder' to-do item and stamps
 * reminded_at = now() to prevent duplicate reminders.
 */
class SendSlaRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(TodoService $todo, StructuredLogger $logger): void
    {
        $overdueNodes = WorkflowNode::query()
            ->whereIn('status', [
                WorkflowStatus::Pending->value,
                WorkflowStatus::InProgress->value,
            ])
            ->whereNotNull('sla_due_at')
            ->where('sla_due_at', '<', now())
            ->whereNull('reminded_at')
            ->whereNotNull('assigned_to')
            ->get();

        $sent = 0;
        foreach ($overdueNodes as $node) {
            $label = $node->label ?? 'Approval';

            $todo->create(
                userId:         $node->assigned_to,
                type:           'sla_reminder',
                title:          "SLA Overdue: {$label}",
                body:           "Workflow node '{$label}' (ID: {$node->id}) passed its SLA deadline on {$node->sla_due_at->toDateString()}. Immediate action required.",
                workflowNodeId: $node->id,
                dueAt:          Carbon::now(),
            );

            $node->update(['reminded_at' => now()]);
            $sent++;
        }

        if ($sent > 0) {
            $logger->info('SLA reminder to-do items sent', ['sent_count' => $sent], 'workflow');
        }
    }
}
