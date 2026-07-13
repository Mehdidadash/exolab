<?php
/**
 * QR Code PNG generator – pure PHP, no external processes.
 * Uses chillerlan/php-qrcode v6 (Composer).
 * Returns raw PNG binary string suitable for mPDF embedding.
 */
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;

class QrGenerator
{
    /**
     * Render a QR code as a raw PNG binary string.
     */
    public static function png(string $text): string
    {
        try {
            $options = new QROptions([
                'outputInterface' => QRGdImagePNG::class,
                'eccLevel'       => EccLevel::M,
                'scale'          => 4,
                'outputBase64'   => false,
                'returnResource' => false,
                'addQuietzone'   => true,
                'quietzoneSize'  => 1,
            ]);
            $qrcode = new QRCode($options);
            return $qrcode->render($text);
        } catch (\Throwable $e) {
            error_log('QR: ' . $e->getMessage());
            return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        }
    }

    /**
     * Return base64 data URI for direct <img> use.
     */
    public static function dataUri(string $text): string
    {
        return 'data:image/png;base64,' . base64_encode(self::png($text));
    }
}

