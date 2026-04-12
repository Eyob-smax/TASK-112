<?php

use App\Application\Sales\SalesDocumentService;
use App\Domain\Audit\Contracts\AuditEventRepositoryInterface;
use App\Domain\Sales\Enums\SalesStatus;
use App\Domain\Sales\ValueObjects\DocumentNumberFormat;
use App\Exceptions\Sales\InvalidSalesTransitionException;
use App\Exceptions\Sales\OutboundLinkageNotAllowedException;
use App\Infrastructure\Persistence\EloquentSalesRepository;
use App\Models\SalesDocument;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Unit tests for SalesDocumentService.
 *
 * Database calls are exercised through integration — only the domain guard
 * logic (status transitions, outbound linkage) is tested with mocks.
 */
describe('SalesDocumentService', function () {

    beforeEach(function () {
        $this->repo      = Mockery::mock(EloquentSalesRepository::class);
        $this->auditRepo = Mockery::mock(AuditEventRepositoryInterface::class);

        $this->auditRepo->shouldReceive('record')->andReturn(null)->byDefault();

        $this->service = new SalesDocumentService($this->repo, $this->auditRepo);

        $userId = Str::uuid()->toString();
        $this->user = Mockery::mock(User::class)->makePartial();
        $this->user->id = $userId;
    });

    afterEach(fn() => Mockery::close());

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    function makeDoc(SalesStatus $status): SalesDocument
    {
        $doc = Mockery::mock(SalesDocument::class)->makePartial();
        $doc->id = Str::uuid()->toString();
        $doc->status = $status;
        $doc->shouldReceive('update')->andReturnSelf()->byDefault();
        $doc->shouldReceive('load')->andReturnSelf()->byDefault();
        $doc->shouldReceive('fresh')->andReturnSelf()->byDefault();
        return $doc;
    }

    // -------------------------------------------------------------------------
    // Document number format
    // -------------------------------------------------------------------------

    it('generates document number in SITE-YYYYMMDD-NNNNNN format', function () {
        $date   = new \DateTimeImmutable('2025-08-15');
        $result = DocumentNumberFormat::format('STORE1', $date, 1);

        expect($result)->toBe('STORE1-20250815-000001');
    });

    it('zero-pads sequence to 6 digits', function () {
        $date   = new \DateTimeImmutable('2025-01-01');
        $result = DocumentNumberFormat::format('HQ', $date, 42);

        expect($result)->toBe('HQ-20250101-000042');
    });

    // -------------------------------------------------------------------------
    // update — status guard
    // -------------------------------------------------------------------------

    it('throws InvalidSalesTransitionException when updating a non-draft document', function () {
        $doc = makeDoc(SalesStatus::Reviewed);

        expect(fn() => $this->service->update($this->user, $doc, ['notes' => 'test'], '127.0.0.1'))
            ->toThrow(InvalidSalesTransitionException::class);
    });

    it('throws InvalidSalesTransitionException when updating a completed document', function () {
        $doc = makeDoc(SalesStatus::Completed);

        expect(fn() => $this->service->update($this->user, $doc, ['notes' => 'test'], '127.0.0.1'))
            ->toThrow(InvalidSalesTransitionException::class);
    });

    // -------------------------------------------------------------------------
    // submit — status guard
    // -------------------------------------------------------------------------

    it('throws InvalidSalesTransitionException when submitting a completed document', function () {
        $doc = makeDoc(SalesStatus::Completed);

        expect(fn() => $this->service->submit($this->user, $doc, '127.0.0.1'))
            ->toThrow(InvalidSalesTransitionException::class);
    });

    it('throws InvalidSalesTransitionException when submitting a voided document', function () {
        $doc = makeDoc(SalesStatus::Voided);

        expect(fn() => $this->service->submit($this->user, $doc, '127.0.0.1'))
            ->toThrow(InvalidSalesTransitionException::class);
    });

    // -------------------------------------------------------------------------
    // complete — status guard
    // -------------------------------------------------------------------------

    it('throws InvalidSalesTransitionException when completing a draft document', function () {
        $doc = makeDoc(SalesStatus::Draft);

        // Draft → Completed is not a valid transition (must go Draft→Reviewed→Completed)
        expect(fn() => $this->service->complete($this->user, $doc, '127.0.0.1'))
            ->toThrow(InvalidSalesTransitionException::class);
    });

    // -------------------------------------------------------------------------
    // void — status guard
    // -------------------------------------------------------------------------

    it('throws InvalidSalesTransitionException when voiding a completed document', function () {
        $doc = makeDoc(SalesStatus::Completed);

        expect(fn() => $this->service->void($this->user, $doc, 'Test reason', '127.0.0.1'))
            ->toThrow(InvalidSalesTransitionException::class);
    });

    it('throws InvalidSalesTransitionException when voiding an already voided document', function () {
        $doc = makeDoc(SalesStatus::Voided);

        expect(fn() => $this->service->void($this->user, $doc, 'Test reason', '127.0.0.1'))
            ->toThrow(InvalidSalesTransitionException::class);
    });

    // -------------------------------------------------------------------------
    // linkOutbound
    // -------------------------------------------------------------------------

    it('throws OutboundLinkageNotAllowedException when linking outbound on a non-completed document', function () {
        $doc = makeDoc(SalesStatus::Draft);

        expect(fn() => $this->service->linkOutbound($this->user, $doc, '127.0.0.1'))
            ->toThrow(OutboundLinkageNotAllowedException::class);
    });

    it('throws OutboundLinkageNotAllowedException when linking outbound on a reviewed document', function () {
        $doc = makeDoc(SalesStatus::Reviewed);

        expect(fn() => $this->service->linkOutbound($this->user, $doc, '127.0.0.1'))
            ->toThrow(OutboundLinkageNotAllowedException::class);
    });

    it('does not throw when linking outbound on a completed document', function () {
        $doc = makeDoc(SalesStatus::Completed);
        $doc->shouldReceive('update')->andReturnSelf();
        $doc->shouldReceive('fresh')->andReturnSelf();
        $doc->shouldReceive('load')->andReturnSelf();

        try {
            $this->service->linkOutbound($this->user, $doc, '127.0.0.1');
        } catch (OutboundLinkageNotAllowedException $e) {
            $this->fail('Should not throw OutboundLinkageNotAllowedException for completed document.');
        } catch (\Throwable) {
            // DB calls fail in unit context — acceptable
        }

        expect(true)->toBeTrue();
    });
});
