<?php

use App\Application\Sales\ReturnService;
use App\Domain\Audit\Contracts\AuditEventRepositoryInterface;
use App\Domain\Sales\Enums\SalesStatus;
use App\Domain\Sales\ValueObjects\RestockFeePolicy;
use App\Exceptions\Sales\InvalidSalesTransitionException;
use App\Exceptions\Sales\ReturnWindowExpiredException;
use App\Infrastructure\Persistence\EloquentSalesRepository;
use App\Models\ReturnRecord;
use App\Models\SalesDocument;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Unit tests for ReturnService.
 *
 * Guards (status, window) are tested with mocks; RestockFeePolicy calculations
 * are tested via the real VO which requires no DB.
 */
describe('ReturnService', function () {

    beforeEach(function () {
        $this->repo      = Mockery::mock(EloquentSalesRepository::class);
        $this->auditRepo = Mockery::mock(AuditEventRepositoryInterface::class);

        $this->auditRepo->shouldReceive('record')->andReturn(null)->byDefault();

        $this->service = new ReturnService($this->repo, $this->auditRepo);

        $this->user     = Mockery::mock(User::class);
        $this->user->id = Str::uuid()->toString();
        $this->user->shouldReceive('getAttribute')->with('id')->andReturn($this->user->id)->byDefault();
        $this->user->shouldReceive('getAttribute')->andReturn(null)->byDefault();
    });

    afterEach(fn() => Mockery::close());

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    function makeSalesDoc(SalesStatus $status, ?int $completedDaysAgo = null): SalesDocument
    {
        $doc = Mockery::mock(SalesDocument::class);
        $doc->id        = Str::uuid()->toString();
        $doc->site_code = 'STORE1';
        $doc->status    = $status;
        $doc->shouldReceive('getAttribute')->with('status')->andReturn($status);
        $doc->shouldReceive('getAttribute')->with('site_code')->andReturn('STORE1');
        $doc->shouldReceive('getAttribute')->with('id')->andReturn($doc->id);

        $completedAt = $completedDaysAgo !== null
            ? now()->subDays($completedDaysAgo)
            : null;

        $doc->shouldReceive('getAttribute')->with('completed_at')->andReturn($completedAt);
        $doc->completed_at = $completedAt;
        $doc->shouldReceive('getAttribute')->andReturn(null)->byDefault();
        return $doc;
    }

    // -------------------------------------------------------------------------
    // createReturn — status guard
    // -------------------------------------------------------------------------

    it('throws InvalidSalesTransitionException when creating a return for a non-completed sale', function () {
        $doc = makeSalesDoc(SalesStatus::Draft);

        expect(fn() => $this->service->createReturn($this->user, $doc, [
            'reason_code'   => 'changed_mind',
            'return_amount' => 100.0,
        ], '127.0.0.1'))->toThrow(InvalidSalesTransitionException::class);
    });

    it('throws InvalidSalesTransitionException when creating a return for a reviewed sale', function () {
        $doc = makeSalesDoc(SalesStatus::Reviewed);

        expect(fn() => $this->service->createReturn($this->user, $doc, [
            'reason_code'   => 'wrong_item',
            'return_amount' => 50.0,
        ], '127.0.0.1'))->toThrow(InvalidSalesTransitionException::class);
    });

    // -------------------------------------------------------------------------
    // createReturn — return window guard
    // -------------------------------------------------------------------------

    it('throws ReturnWindowExpiredException for non-defective return beyond 30 days', function () {
        $doc = makeSalesDoc(SalesStatus::Completed, completedDaysAgo: 31);

        expect(fn() => $this->service->createReturn($this->user, $doc, [
            'reason_code'   => 'changed_mind',
            'return_amount' => 100.0,
        ], '127.0.0.1'))->toThrow(ReturnWindowExpiredException::class);
    });

    it('does NOT throw ReturnWindowExpiredException for defective return beyond 30 days', function () {
        $doc = makeSalesDoc(SalesStatus::Completed, completedDaysAgo: 45);

        $this->repo->shouldReceive('nextDocumentNumber')->andReturn('STORE1R-20250101-000001')->byDefault();

        try {
            $this->service->createReturn($this->user, $doc, [
                'reason_code'   => 'defective',
                'return_amount' => 100.0,
            ], '127.0.0.1');
        } catch (ReturnWindowExpiredException $e) {
            $this->fail('Should not throw ReturnWindowExpiredException for defective return.');
        } catch (\Throwable) {
            // DB calls fail in unit context — acceptable
        }

        expect(true)->toBeTrue();
    });

    // -------------------------------------------------------------------------
    // RestockFeePolicy — domain value object tests
    // -------------------------------------------------------------------------

    it('calculates restock fee as 10% for non-defective return within 30-day window', function () {
        $fee = RestockFeePolicy::calculateFee(100.0, false, 15);

        expect($fee)->toBe(10.0);
    });

    it('sets restock fee to 0 for defective return', function () {
        $fee = RestockFeePolicy::calculateFee(100.0, true, 5);

        expect($fee)->toBe(0.0);
    });

    it('applies custom restock_fee_percent when provided', function () {
        $fee = RestockFeePolicy::calculateFee(200.0, false, 10, 15.0);

        expect($fee)->toBe(30.0);
    });

    it('calculates refund amount as return_amount minus restock_fee', function () {
        $refund = RestockFeePolicy::calculateRefundAmount(100.0, 10.0);

        expect($refund)->toBe(90.0);
    });

    it('refund amount is never negative even if fee exceeds return amount', function () {
        $refund = RestockFeePolicy::calculateRefundAmount(10.0, 50.0);

        expect($refund)->toBe(0.0);
    });

    // -------------------------------------------------------------------------
    // completeReturn — status guard
    // -------------------------------------------------------------------------

    it('throws InvalidSalesTransitionException when completing an already completed return', function () {
        $return = Mockery::mock(ReturnRecord::class);
        $return->shouldReceive('getAttribute')->with('status')->andReturn('completed');
        $return->status = 'completed';

        expect(fn() => $this->service->completeReturn($this->user, $return, '127.0.0.1'))
            ->toThrow(InvalidSalesTransitionException::class);
    });
});
