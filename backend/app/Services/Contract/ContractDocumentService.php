<?php

namespace App\Services\Contract;

use App\Models\Contract\Contract;
use App\Support\FrontendUrl;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Picqer\Barcode\BarcodeGeneratorPNG;

/**
 * Turning a contract into the document people actually sign and file.
 *
 * The authenticity marks are the point of this class. A signed PDF leaves the
 * system — it is printed, forwarded, attached to an e-mail — and once it has,
 * the only way to tell a real one from an edited copy is something on the page
 * that points back here. Hence the QR (a verification URL anybody can open), the
 * barcode (the reference, machine-readable at a desk), and the issuance
 * certificate numbers minted per signature.
 *
 * ── Why SVG for the QR and PNG for the barcode ──────────────────────────
 * DomPDF renders SVG through php-svg-lib, and a vector QR stays scannable at any
 * size a printer chooses. Bacon has no GD backend, so a raster QR would need
 * Imagick, which is not installed. Picqer's barcode does have a GD backend, and
 * a PNG barcode is both smaller and sharper than the SVG equivalent at the size
 * it is printed. Both are embedded as data URIs so the PDF has no outbound
 * fetch — a document that has to reach the network to draw itself is a document
 * that renders differently, or not at all, on a machine that is offline.
 */
class ContractDocumentService
{
    /** Everything the PDF template needs, resolved once. */
    public function renderData(Contract $contract): array
    {
        $contract->loadMissing(['pages', 'signatures', 'category:id,name', 'party']);

        return [
            'contract'    => $contract,
            'signatures'  => $contract->signatures->keyBy('signer_party'),
            'qr'          => $this->qrDataUri($this->verifyUrl($contract)),
            'barcode'     => $this->barcodeDataUri($contract->reference_no ?: 'CTR-'.$contract->id),
            'verify_url'  => $this->verifyUrl($contract),
            'logo'        => \App\Support\Brand::logoDataUri(),
            'brand'       => \App\Support\Brand::name(),
            'generated_at' => now(),
        ];
    }

    /**
     * The public verification address printed under the QR.
     *
     * Uses the public token, not the id: the id is guessable and would let
     * anyone walk the whole contract table by incrementing a number.
     */
    public function verifyUrl(Contract $contract): string
    {
        // Through FrontendUrl, never env(): this string is baked into the QR
        // code printed on every page of every contract PDF. A localhost value
        // here is put on paper and handed to somebody, and no later fix reaches
        // the copies already signed.
        return FrontendUrl::to('/contracts/verify/'.$contract->public_token);
    }

    /** QR as an inline SVG data URI. */
    public function qrDataUri(string $text, int $size = 150): string
    {
        $svg = (new Writer(new ImageRenderer(
            new RendererStyle($size, 1),
            new SvgImageBackEnd(),
        )))->writeString($text);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /** Code-128 barcode as an inline PNG data URI. */
    public function barcodeDataUri(string $text): string
    {
        $png = (new BarcodeGeneratorPNG())->getBarcode(
            $text, BarcodeGeneratorPNG::TYPE_CODE_128, 2, 40,
        );

        return 'data:image/png;base64,'.base64_encode($png);
    }

}
