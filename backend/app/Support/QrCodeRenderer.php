<?php

namespace App\Support;

use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use Endroid\QrCode\Writer\WriterInterface;

class QrCodeRenderer
{
    private const SIZE = 400;

    private const MARGIN = 16;

    /**
     * @return array{body: string, mime: string}
     */
    public function render(string $data, string $format): array
    {
        $writer = $this->writerFor($format);

        $qrCode = new QrCode(
            data: $data,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: self::SIZE,
            margin: self::MARGIN,
        );

        $result = $writer->write($qrCode);

        return ['body' => $result->getString(), 'mime' => $result->getMimeType()];
    }

    private function writerFor(string $format): WriterInterface
    {
        return match ($format) {
            'svg' => new SvgWriter,
            'png' => new PngWriter,
            default => throw new \InvalidArgumentException("Unsupported QR format: {$format}"),
        };
    }
}
