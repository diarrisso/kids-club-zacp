<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FontController extends Controller
{
    /**
     * Whitelisted, self-hosted font files (GDPR: no Google Fonts — visitor
     * IPs must not leak to third parties from embedding practice sites).
     * Served through a route (not public/) so CORS + cache headers are
     * guaranteed regardless of the web server in front.
     *
     * Provenance — vendored from the @fontsource devDependencies (latin
     * subset; covers German umlauts/ß). To refresh, copy from:
     *   node_modules/@fontsource-variable/fredoka/files/fredoka-latin-wght-normal.woff2
     *   node_modules/@fontsource-variable/nunito/files/nunito-latin-wght-normal.woff2
     *   node_modules/@fontsource-variable/inter/files/inter-latin-wght-normal.woff2
     *   node_modules/@fontsource/poppins/files/poppins-latin-{400,600,700}-normal.woff2
     * into resources/fonts/ under the names below.
     */
    private const FILES = [
        'fredoka.woff2' => 'fredoka.woff2',
        'nunito.woff2' => 'nunito.woff2',
        'inter.woff2' => 'inter.woff2',
        'poppins-400.woff2' => 'poppins-400.woff2',
        'poppins-600.woff2' => 'poppins-600.woff2',
        'poppins-700.woff2' => 'poppins-700.woff2',
    ];

    public function show(string $file): BinaryFileResponse
    {
        abort_unless(isset(self::FILES[$file]), 404);

        return response()->file(resource_path('fonts/'.self::FILES[$file]), [
            'Content-Type' => 'font/woff2',
            'Cache-Control' => 'immutable, max-age=31536000, public',
        ]);
    }
}
