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
            size: 400,
            margin: 16,
        );

        $result = $writer->write($qrCode);

        return ['body' => $result->getString(), 'mime' => $result->getMimeType()];
    }

    private function writerFor(string $format): WriterInterface
    {
        return $format === 'svg' ? new SvgWriter : new PngWriter;
    }
}
