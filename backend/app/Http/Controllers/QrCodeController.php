<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\QrCodeRenderer;
use Symfony\Component\HttpFoundation\Response;

class QrCodeController extends Controller
{
    public function show(string $format, QrCodeRenderer $renderer): Response
    {
        $url = Setting::get('booking_url');

        // booking_url is operator-set; bail out if blank so we never encode an empty/whitespace QR.
        abort_if($url === null || trim($url) === '', 404);

        $image = $renderer->render($url, $format);

        return response($image['body'], 200, [
            'Content-Type' => $image['mime'],
            // 24h cache is fine: booking_url rarely changes; the admin preview uses a ?v= cache-buster.
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
