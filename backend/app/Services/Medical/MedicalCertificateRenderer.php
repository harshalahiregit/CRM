<?php

namespace App\Services\Medical;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer as QrWriter;
use Illuminate\Support\Facades\Log;
use Picqer\Barcode\Renderers\SvgRenderer;
use Picqer\Barcode\Types\TypeCode128;

/**
 * The machine-readable marks on a medical certificate.
 *
 * Both are rendered as SVG and embedded as data URIs: dompdf draws SVG through
 * php-svg-lib without needing GD, so a certificate renders identically on a
 * developer's laptop and on a server with a minimal PHP build.
 *
 * The barcode carries the certificate number for the people who scan documents
 * at a gate; the QR carries the full verification URL for anyone with a phone.
 */
class MedicalCertificateRenderer
{
    /** Code 128 of the certificate number, as an <img>-ready data URI. */
    public function barcodeDataUri(string $certificateNo, float $width = 320, float $height = 48): ?string
    {
        try {
            $barcode = (new TypeCode128())->getBarcode($certificateNo);
            $svg     = (new SvgRenderer())->render($barcode, $width, $height);

            return $this->dataUri($svg);
        } catch (\Throwable $e) {
            Log::warning('Medical barcode render failed', ['certificate_no' => $certificateNo, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** QR of the public verification URL, as an <img>-ready data URI. */
    public function qrDataUri(string $url, int $size = 240): ?string
    {
        try {
            $writer = new QrWriter(new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd()));

            return $this->dataUri($writer->writeString($url));
        } catch (\Throwable $e) {
            Log::warning('Medical QR render failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * A stored image (signature, camera capture) as a data URI so dompdf never
     * has to reach into the filesystem or the network while rendering.
     */
    public function imageDataUri(?string $absolutePath): ?string
    {
        if (! $absolutePath || ! is_file($absolutePath)) {
            return null;
        }

        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        // dompdf rasterises PNG, JPEG and GIF through the PHP GD extension and
        // throws outright when it is absent. Vector art (the barcode and the QR
        // code) goes through php-svg-lib instead and is unaffected.
        //
        // Without this guard a server with no GD cannot render ANY certificate
        // that carries a signature or a camera photo — which, now that both are
        // mandatory, means every certificate. Omitting the two pictures leaves a
        // document that still states the outcome, still carries the licence
        // number, and still verifies by QR; refusing to render leaves nothing.
        if (! extension_loaded('gd') && $extension !== 'svg') {
            Log::warning('Medical certificate rendered without its images: the PHP GD extension is not installed', [
                'path' => basename($absolutePath),
            ]);

            return null;
        }

        $mime = match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif'         => 'image/gif',
            'svg'         => 'image/svg+xml',
            default       => 'image/png',
        };

        $contents = @file_get_contents($absolutePath);

        return $contents === false ? null : 'data:'.$mime.';base64,'.base64_encode($contents);
    }

    private function dataUri(string $svg): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
