<?php

use App\Domain\Audit\Contracts\AuditEventRepositoryInterface;
use App\Jobs\ExpireAttachmentLinksJob;
use App\Jobs\ExpireAttachmentsJob;
use App\Jobs\PruneBackupsJob;
use App\Jobs\PruneRetentionJob;
use App\Jobs\SendSlaRemindersJob;
use App\Models\Attachment;
use App\Models\AttachmentLink;
use App\Models\AuditEvent;
use App\Models\BackupJob;
use App\Models\Department;
use App\Models\MetricsSnapshot;
use App\Models\StructuredLog;
use App\Models\ToDoItem;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowNode;
use App\Models\WorkflowTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| Maintenance Jobs — Integration Tests
|--------------------------------------------------------------------------
| Covers side-effects of all scheduled maintenance jobs:
|   - PruneBackupsJob       : 14-day backup retention enforcement
|   - PruneRetentionJob     : 90-day log and metrics retention enforcement
|   - ExpireAttachmentLinksJob : Cleans up expired/consumed/revoked links
|   - ExpireAttachmentsJob  : Transitions expired attachments to 'expired' status
|   - SendSlaRemindersJob   : Sends SLA reminder to-do items for overdue nodes
*/

uses(RefreshDatabase::class);

// -------------------------------------------------------------------------
// PruneBackupsJob
// -------------------------------------------------------------------------

it('PruneBackupsJob deletes expired backup records beyond 14-day retention', function () {
    BackupJob::create([
        'started_at'           => now()->subDays(15),
        'status'               => 'success',
        'retention_expires_at' => now()->subDay(),
        'is_manual'            => false,
    ]);
    BackupJob::create([
        'started_at'           => now(),
        'status'               => 'pending',
        'retention_expires_at' => now()->addDays(14),
        'is_manual'            => false,
    ]);

    (new PruneBackupsJob())->handle(
        app(\App\Application\Backup\BackupMetadataService::class),
        app(\App\Application\Logging\StructuredLogger::class),
    );

    expect(BackupJob::count())->toBe(1)
        ->and(BackupJob::first()->status)->toBe('pending');
});

// -------------------------------------------------------------------------
// PruneRetentionJob
// -------------------------------------------------------------------------

it('PruneRetentionJob prunes expired structured logs', function () {
    StructuredLog::create([
        'level'          => 'info',
        'message'        => 'Old log',
        'channel'        => 'application',
        'recorded_at'    => now()->subDays(100),
        'retained_until' => now()->subDays(10),
    ]);
    StructuredLog::create([
        'level'          => 'info',
        'message'        => 'Recent log',
        'channel'        => 'application',
        'recorded_at'    => now(),
        'retained_until' => now()->addDays(90),
    ]);

    (new PruneRetentionJob())->handle(
        app(\App\Application\Logging\StructuredLogger::class),
        app(\App\Application\Metrics\MetricsRetentionService::class),
    );

    // Only the recent log survives
    expect(StructuredLog::where('message', 'Recent log')->count())->toBe(1);
});

it('PruneRetentionJob prunes expired metrics snapshots beyond 90-day retention', function () {
    MetricsSnapshot::create([
        'metric_type'    => 'queue_depth',
        'value'          => 5.0,
        'labels'         => [],
        'recorded_at'    => now()->subDays(100),
        'retained_until' => now()->subDays(10),
    ]);
    MetricsSnapshot::create([
        'metric_type'    => 'queue_depth',
        'value'          => 2.0,
        'labels'         => [],
        'recorded_at'    => now(),
        'retained_until' => now()->addDays(90),
    ]);

    (new PruneRetentionJob())->handle(
        app(\App\Application\Logging\StructuredLogger::class),
        app(\App\Application\Metrics\MetricsRetentionService::class),
    );

    expect(MetricsSnapshot::count())->toBe(1)
        ->and(MetricsSnapshot::first()->value)->toBe(2.0);
});

// -------------------------------------------------------------------------
// ExpireAttachmentLinksJob
// -------------------------------------------------------------------------

it('ExpireAttachmentLinksJob deletes links expired beyond the 24h grace period', function () {
    // Create a minimal user, attachment, and link
    $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
    $user = User::create([
        'username'      => 'ops_user',
        'email'         => 'ops@example.com',
        'password_hash' => bcrypt('password'),
        'display_name'  => 'Ops User',
        'department_id' => $dept->id,
        'is_active'     => true,
    ]);

    $attachment = Attachment::create([
        'record_type'        => 'test',
        'record_id'          => $user->id,
        'original_filename'  => 'file.pdf',
        'mime_type'          => 'application/pdf',
        'encrypted_path'     => 'attachments/file.pdf',
        'sha256_fingerprint' => hash('sha256', 'dummy'),
        'file_size_bytes'    => 1024,
        'status'             => 'active',
        'uploaded_by'        => $user->id,
        'department_id'      => $dept->id,
        'encryption_key_id'  => 'test-key-id',
    ]);

    // Link expired more than 24h ago (should be deleted)
    AttachmentLink::create([
        'attachment_id' => $attachment->id,
        'token'         => 'tok-expired',
        'expires_at'    => now()->subHours(30),
        'is_single_use' => false,
        'created_by'    => $user->id,
    ]);

    // Link expired within grace period (should NOT be deleted yet)
    AttachmentLink::create([
        'attachment_id' => $attachment->id,
        'token'         => 'tok-grace',
        'expires_at'    => now()->subHours(2),
        'is_single_use' => false,
        'created_by'    => $user->id,
    ]);

    (new ExpireAttachmentLinksJob())->handle(
        app(\App\Application\Logging\StructuredLogger::class),
        app(AuditEventRepositoryInterface::class),
    );

    expect(AttachmentLink::count())->toBe(1)
        ->and(AttachmentLink::first()->token)->toBe('tok-grace');
});

it('ExpireAttachmentLinksJob emits an audit Delete event for each TTL-expired link removed', function () {
    $dept = Department::create(['name' => 'Audit', 'code' => 'AUD']);
    $user = User::create([
        'username'      => 'aud_user',
        'email'         => 'aud@example.com',
        'password_hash' => bcrypt('pass'),
        'display_name'  => 'Aud User',
        'department_id' => $dept->id,
        'is_active'     => true,
    ]);
    $attachment = Attachment::create([
        'record_type'        => 'test',
        'record_id'          => $user->id,
        'original_filename'  => 'a.pdf',
        'mime_type'          => 'application/pdf',
        'encrypted_path'     => 'attachments/a.pdf',
        'sha256_fingerprint' => hash('sha256', 'aud'),
        'file_size_bytes'    => 512,
        'status'             => 'active',
        'uploaded_by'        => $user->id,
        'department_id'      => $dept->id,
        'encryption_key_id'  => 'test-key-id',
    ]);

    $link = AttachmentLink::create([
        'attachment_id' => $attachment->id,
        'token'         => 'tok-ttl-audit',
        'expires_at'    => now()->subHours(30),
        'is_single_use' => false,
        'created_by'    => $user->id,
    ]);

    (new ExpireAttachmentLinksJob())->handle(
        app(\App\Application\Logging\StructuredLogger::class),
        app(AuditEventRepositoryInterface::class),
    );

    expect(AttachmentLink::where('token', 'tok-ttl-audit')->exists())->toBeFalse();
    expect(AuditEvent::where('auditable_id', $link->id)
        ->where('action', 'delete')->exists())->toBeTrue();
});

it('PruneBackupsJob emits a Delete audit event for each pruned backup job', function () {
    $expired = BackupJob::create([
        'started_at'           => now()->subDays(15),
        'status'               => 'success',
        'retention_expires_at' => now()->subDay(),
        'is_manual'            => false,
    ]);

    (new PruneBackupsJob())->handle(
        app(\App\Application\Backup\BackupMetadataService::class),
        app(\App\Application\Logging\StructuredLogger::class),
    );

    expect(BackupJob::where('id', $expired->id)->exists())->toBeFalse();
    expect(AuditEvent::where('auditable_id', $expired->id)
        ->where('action', 'delete')->exists())->toBeTrue();
});

it('ExpireAttachmentsJob emits an Update audit event for each attachment transitioned to expired', function () {
    $dept = Department::create(['name' => 'Exp', 'code' => 'EXP']);
    $user = User::create([
        'username'      => 'exp_user',
        'email'         => 'exp@example.com',
        'password_hash' => bcrypt('pass'),
        'display_name'  => 'Exp User',
        'department_id' => $dept->id,
        'is_active'     => true,
    ]);
    $attachment = Attachment::create([
        'record_type'        => 'test',
        'record_id'          => $user->id,
        'original_filename'  => 'exp.pdf',
        'mime_type'          => 'application/pdf',
        'encrypted_path'     => 'attachments/exp.pdf',
        'sha256_fingerprint' => hash('sha256', 'exp'),
        'file_size_bytes'    => 512,
        'status'             => 'active',
        'expires_at'         => now()->subDay(),
        'uploaded_by'        => $user->id,
        'department_id'      => $dept->id,
        'encryption_key_id'  => 'test-key-id',
    ]);

    (new ExpireAttachmentsJob())->handle(
        app(\App\Application\Attachment\AttachmentService::class),
        app(\App\Application\Logging\StructuredLogger::class),
    );

    expect(Attachment::find($attachment->id)->status)->toBe(\App\Domain\Attachment\Enums\AttachmentStatus::Expired);
    expect(AuditEvent::where('auditable_id', $attachment->id)
        ->where('action', 'update')->exists())->toBeTrue();
});

// -------------------------------------------------------------------------
// SendSlaRemindersJob
// -------------------------------------------------------------------------

it('SendSlaRemindersJob creates a reminder to-do item for overdue nodes and stamps reminded_at', function () {
    $dept = Department::create(['name' => 'Sales', 'code' => 'SL']);
    $user = User::create([
        'username'      => 'approver',
        'email'         => 'approver@example.com',
        'password_hash' => bcrypt('pass'),
        'display_name'  => 'Approver',
        'department_id' => $dept->id,
        'is_active'     => true,
    ]);

    $template = WorkflowTemplate::create([
        'name'       => 'Test Template',
        'event_type' => 'test',
        'is_active'  => true,
        'created_by' => $user->id,
    ]);

    $instance = WorkflowInstance::create([
        'workflow_template_id' => $template->id,
        'record_type'          => 'test',
        'record_id'            => $user->id,
        'status'               => 'in_progress',
        'initiated_by'         => $user->id,
        'started_at'           => now(),
    ]);

    $node = WorkflowNode::create([
        'workflow_instance_id' => $instance->id,
        'node_order'           => 1,
        'node_type'            => 'sequential',
        'status'               => 'pending',
        'sla_due_at'           => now()->subDay(),   // overdue
        'assigned_to'          => $user->id,
        'label'                => 'Manager Approval',
    ]);

    (new SendSlaRemindersJob())->handle(
        app(\App\Application\Todo\TodoService::class),
        app(\App\Application\Logging\StructuredLogger::class),
    );

    $node->refresh();
    expect($node->reminded_at)->not->toBeNull();

    $todo = ToDoItem::where('user_id', $user->id)
        ->where('type', 'sla_reminder')
        ->first();

    expect($todo)->not->toBeNull()
        ->and($todo->title)->toContain('SLA Overdue')
        ->and($todo->workflow_node_id)->toBe($node->id);
});

it('SendSlaRemindersJob does not create a second reminder when reminded_at is already set', function () {
    $dept = Department::create(['name' => 'HR', 'code' => 'HR']);
    $user = User::create([
        'username'      => 'hr_approver',
        'email'         => 'hr@example.com',
        'password_hash' => bcrypt('pass'),
        'display_name'  => 'HR Approver',
        'department_id' => $dept->id,
        'is_active'     => true,
    ]);

    $template = WorkflowTemplate::create([
        'name'       => 'HR Template',
        'event_type' => 'hr',
        'is_active'  => true,
        'created_by' => $user->id,
    ]);

    $instance = WorkflowInstance::create([
        'workflow_template_id' => $template->id,
        'record_type'          => 'test',
        'record_id'            => $user->id,
        'status'               => 'in_progress',
        'initiated_by'         => $user->id,
        'started_at'           => now(),
    ]);

    WorkflowNode::create([
        'workflow_instance_id' => $instance->id,
        'node_order'           => 1,
        'node_type'            => 'sequential',
        'status'               => 'pending',
        'sla_due_at'           => now()->subDay(),
        'assigned_to'          => $user->id,
        'reminded_at'          => now()->subHours(1),  // already reminded
    ]);

    (new SendSlaRemindersJob())->handle(
        app(\App\Application\Todo\TodoService::class),
        app(\App\Application\Logging\StructuredLogger::class),
    );

    expect(ToDoItem::where('type', 'sla_reminder')->count())->toBe(0);
});
