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

        abort_if($url === null || $url === '', 404);

        $image = $renderer->render($url, $format);

        return response($image['body'], 200, [
            'Content-Type' => $image['mime'],
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
