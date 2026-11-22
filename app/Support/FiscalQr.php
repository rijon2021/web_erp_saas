<?php

namespace App\Support;

use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Server-side QR images for fiscal documents (AGT / DGI). Returned as a
 * data: URI so the same string works in the POS receipt, the reprint
 * popup and the dompdf invoice. PNG via GD when available (safest for
 * dompdf and thermal printers), SVG otherwise.
 */
class FiscalQr
{
    public static function dataUri(?string $text, int $size = 240): ?string
    {
        $text = (string) $text;
        if ($text === '') {
            return null;
        }

        try {
            if (extension_loaded('gd') && class_exists(GDLibRenderer::class)) {
                $png = (new Writer(new GDLibRenderer($size, 2)))->writeString($text);

                return 'data:image/png;base64,'.base64_encode($png);
            }

            $svg = (new Writer(new ImageRenderer(new RendererStyle($size, 2), new SvgImageBackEnd())))->writeString($text);

            return 'data:image/svg+xml;base64,'.base64_encode($svg);
        } catch (\Throwable $e) {
            \Log::warning('Fiscal QR rendering failed: '.$e->getMessage());

            return null;
        }
    }
}
