<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Sales\ValueObjects\DocumentNumberFormat;
use App\Models\DocumentNumberSequence;
use Illuminate\Support\Facades\DB;

class EloquentSalesRepository
{
    /**
     * Atomically generate the next document number for the given site and business date.
     *
     * Uses SELECT ... FOR UPDATE to serialize concurrent requests that share the same
     * (site_code, business_date) bucket. The DB unique index on those two columns is
     * the safety net in case of race conditions outside a transaction.
     */
    public function nextDocumentNumber(string $siteCode, \DateTimeImmutable $businessDate): string
    {
        return DB::transaction(function () use ($siteCode, $businessDate) {
            $dateStr = $businessDate->format('Y-m-d');

            $seq = DocumentNumberSequence::where('site_code', $siteCode)
                ->where('business_date', $dateStr)
                ->lockForUpdate()
                ->first();

            if ($seq === null) {
                DocumentNumberSequence::create([
                    'site_code'     => $siteCode,
                    'business_date' => $dateStr,
                    'last_sequence' => 1,
                ]);

                return DocumentNumberFormat::format($siteCode, $businessDate, 1);
            }

            $seq->increment('last_sequence');

            return DocumentNumberFormat::format($siteCode, $businessDate, $seq->fresh()->last_sequence);
        });
    }
}
