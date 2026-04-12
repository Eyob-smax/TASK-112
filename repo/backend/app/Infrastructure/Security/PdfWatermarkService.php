<?php

namespace App\Infrastructure\Security;

use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * Stamps a diagonal watermark text across all pages of a PDF document.
 *
 * Uses TCPDF (via the FPDI TCPDF adapter) to import the source PDF and overlay
 * a semi-transparent diagonal watermark on every page before returning the
 * stamped document as a raw string.
 *
 * If the source file is not a parseable PDF (e.g. corrupted, non-PDF content),
 * a \RuntimeException is thrown so callers can deny delivery.
 */
class PdfWatermarkService
{
    /**
     * Apply a diagonal watermark to every page of the PDF at $filePath.
     *
     * @param string $filePath      Absolute path to the source PDF file on disk
     * @param string $watermarkText Text to stamp (e.g. "Jane Doe - 2024-06-15 14:30:00")
     * @return string               Raw PDF content with watermark applied
     *
     * @throws \RuntimeException If the file cannot be opened or is not a valid PDF
     */
    public function stamp(string $filePath, string $watermarkText): string
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new \RuntimeException("PDF file not found or not readable: {$filePath}");
        }

        try {
            $pdf = new Fpdi();
            $pdf->SetCreator('Meridian DMS');
            $pdf->SetAuthor('Meridian DMS');
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);

            $pageCount = $pdf->setSourceFile($filePath);

            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $templateId  = $pdf->importPage($pageNo);
                $size        = $pdf->getTemplateSize($templateId);
                $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';

                $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                $pdf->useTemplate($templateId, 0, 0, $size['width'], $size['height']);

                // Diagonal watermark centred on the page
                $centreX = $size['width'] / 2;
                $centreY = $size['height'] / 2;

                $pdf->StartTransform();
                $pdf->Rotate(45, $centreX, $centreY);
                $pdf->SetFont('helvetica', 'B', 11);
                $pdf->SetTextColor(160, 160, 160);
                $pdf->SetAlpha(0.35);
                $pdf->SetXY(0, $centreY - 6);
                $pdf->Cell($size['width'], 12, $watermarkText, 0, 0, 'C');
                $pdf->StopTransform();
            }

            return $pdf->Output('watermarked.pdf', 'S');
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'PDF watermark stamping failed: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }
}
