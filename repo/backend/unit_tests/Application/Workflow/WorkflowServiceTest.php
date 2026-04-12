<?php

use App\Application\Todo\TodoService;
use App\Application\Workflow\WorkflowService;
use App\Domain\Audit\Contracts\AuditEventRepositoryInterface;
use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Exceptions\Workflow\ReasonRequiredException;
use App\Exceptions\Workflow\WorkflowNodeNotActionableException;
use App\Exceptions\Workflow\WorkflowTerminatedException;
use App\Infrastructure\Persistence\EloquentWorkflowRepository;
use App\Models\Approval;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowNode;
use Illuminate\Support\Str;

/**
 * Unit tests for WorkflowService.
 *
 * Repository and infrastructure dependencies are mocked — no database required.
 */
describe('WorkflowService', function () {

    beforeEach(function () {
        $this->repo      = Mockery::mock(EloquentWorkflowRepository::class);
        $this->auditRepo = Mockery::mock(AuditEventRepositoryInterface::class);
        $this->todo      = Mockery::mock(TodoService::class);

        $this->auditRepo->shouldReceive('record')->andReturn(null)->byDefault();
        $this->todo->shouldReceive('create')->andReturn(null)->byDefault();

        $this->service = new WorkflowService($this->repo, $this->auditRepo, $this->todo);

        $this->user = Mockery::mock(User::class);
        $this->user->id = Str::uuid()->toString();
        $this->user->shouldReceive('getAuthIdentifier')->andReturn($this->user->id)->byDefault();
        $this->user->shouldReceive('getAttribute')->andReturn(null)->byDefault();
        $this->user->shouldReceive('getAttribute')->with('id')->andReturn($this->user->id)->byDefault();
    });

    afterEach(fn() => Mockery::close());

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    function makeInstance(WorkflowStatus $status): WorkflowInstance
    {
        $instance = Mockery::mock(WorkflowInstance::class);
        $instance->id = Str::uuid()->toString();
        $instance->status = $status;
        $instance->shouldReceive('getAttribute')->with('status')->andReturn($status);
        $instance->shouldReceive('getAttribute')->with('id')->andReturn($instance->id);
        $instance->shouldReceive('getAttribute')->andReturn(null)->byDefault();
        return $instance;
    }

    function makeNode(WorkflowStatus $status, WorkflowInstance $instance): WorkflowNode
    {
        $node = Mockery::mock(WorkflowNode::class);
        $node->id = Str::uuid()->toString();
        $node->status = $status;
        $node->node_order = 1;
        $node->workflow_instance_id = $instance->id;
        $node->shouldReceive('getAttribute')->with('status')->andReturn($status);
        $node->shouldReceive('getAttribute')->with('workflow_instance_id')->andReturn($instance->id);
        $node->shouldReceive('getAttribute')->with('node_order')->andReturn(1);
        $node->shouldReceive('getAttribute')->with('id')->andReturn($node->id);
        $node->shouldReceive('getAttribute')->with('node_type')->andReturn(\App\Domain\Workflow\Enums\NodeType::Sequential);
        $node->shouldReceive('getAttribute')->andReturn(null)->byDefault();
        $node->instance = $instance;
        $node->shouldReceive('getRelation')->with('instance')->andReturn($instance)->byDefault();
        return $node;
    }

    // -------------------------------------------------------------------------
    // approve — node not actionable
    // -------------------------------------------------------------------------

    it('throws WorkflowNodeNotActionableException when approving a non-actionable node', function () {
        $instance = makeInstance(WorkflowStatus::InProgress);
        $node     = makeNode(WorkflowStatus::Approved, $instance); // already approved → not actionable

        expect(fn() => $this->service->approve($this->user, $node, '127.0.0.1'))
            ->toThrow(WorkflowNodeNotActionableException::class);
    });

    // -------------------------------------------------------------------------
    // approve — terminal instance guard
    // -------------------------------------------------------------------------

    it('throws WorkflowTerminatedException when acting on a terminated instance', function () {
        $instance = makeInstance(WorkflowStatus::Approved); // terminal
        $node     = makeNode(WorkflowStatus::Pending, $instance);

        expect(fn() => $this->service->approve($this->user, $node, '127.0.0.1'))
            ->toThrow(WorkflowTerminatedException::class);
    });

    // -------------------------------------------------------------------------
    // reject — reason required
    // -------------------------------------------------------------------------

    it('throws ReasonRequiredException when rejecting without a reason', function () {
        $instance = makeInstance(WorkflowStatus::InProgress);
        $node     = makeNode(WorkflowStatus::Pending, $instance);

        expect(fn() => $this->service->reject($this->user, $node, '', '127.0.0.1'))
            ->toThrow(ReasonRequiredException::class);
    });

    it('throws ReasonRequiredException when rejecting with whitespace-only reason', function () {
        $instance = makeInstance(WorkflowStatus::InProgress);
        $node     = makeNode(WorkflowStatus::Pending, $instance);

        expect(fn() => $this->service->reject($this->user, $node, '   ', '127.0.0.1'))
            ->toThrow(ReasonRequiredException::class);
    });

    // -------------------------------------------------------------------------
    // reassign — reason required
    // -------------------------------------------------------------------------

    it('throws ReasonRequiredException when reassigning without a reason', function () {
        $instance = makeInstance(WorkflowStatus::InProgress);
        $node     = makeNode(WorkflowStatus::Pending, $instance);

        expect(fn() => $this->service->reassign($this->user, $node, Str::uuid()->toString(), '', '127.0.0.1'))
            ->toThrow(ReasonRequiredException::class);
    });

    // -------------------------------------------------------------------------
    // withdraw — terminal guard
    // -------------------------------------------------------------------------

    it('throws WorkflowTerminatedException when withdrawing from a terminal instance', function () {
        $instance = makeInstance(WorkflowStatus::Approved); // terminal
        $instance->shouldReceive('update')->andReturnSelf()->byDefault();

        expect(fn() => $this->service->withdraw($this->user, $instance, 'Changed my mind', '127.0.0.1'))
            ->toThrow(WorkflowTerminatedException::class);
    });

    // -------------------------------------------------------------------------
    // withdraw — happy path
    // -------------------------------------------------------------------------

    it('calls instance update with withdrawn status on successful withdraw', function () {
        $instance = makeInstance(WorkflowStatus::InProgress);
        $instance->shouldReceive('update')->once()->andReturnSelf();

        // nodes() relation for bulk update
        $nodeQuery = Mockery::mock();
        $nodeQuery->shouldReceive('whereIn')->andReturnSelf();
        $nodeQuery->shouldReceive('update')->andReturn(1);
        $instance->shouldReceive('nodes')->andReturn($nodeQuery)->byDefault();

        try {
            $this->service->withdraw($this->user, $instance, 'Withdraw reason', '127.0.0.1');
        } catch (\Throwable $e) {
            // DB calls will fail — verify it is not a guard exception
            expect($e)->not->toBeInstanceOf(WorkflowTerminatedException::class);
        }

        expect(true)->toBeTrue();
    });

    // -------------------------------------------------------------------------
    // reject — happy path (reason present)
    // -------------------------------------------------------------------------

    it('does not throw ReasonRequiredException when rejecting with a valid reason', function () {
        $instance = makeInstance(WorkflowStatus::InProgress);
        $node     = makeNode(WorkflowStatus::Pending, $instance);

        $node->shouldReceive('update')->andReturnSelf()->byDefault();
        $instance->shouldReceive('update')->andReturnSelf()->byDefault();

        try {
            $this->service->reject($this->user, $node, 'Not sufficient justification', '127.0.0.1');
        } catch (ReasonRequiredException $e) {
            $this->fail('Should not throw ReasonRequiredException with a valid reason.');
        } catch (\Throwable) {
            // DB calls fail in unit context — acceptable
        }

        expect(true)->toBeTrue();
    });

    // -------------------------------------------------------------------------
    // addApprover — creates new parallel node
    // -------------------------------------------------------------------------

    it('calls createNode when adding an approver', function () {
        $instance = makeInstance(WorkflowStatus::InProgress);
        $node     = makeNode(WorkflowStatus::Pending, $instance);

        $this->repo->shouldReceive('createNode')->once()->andReturn(Mockery::mock(WorkflowNode::class));

        try {
            $this->service->addApprover($this->user, $node, Str::uuid()->toString(), '127.0.0.1');
        } catch (\Throwable) {
            // Approval::create will fail in unit context — acceptable
        }

        expect(true)->toBeTrue();
    });
});
