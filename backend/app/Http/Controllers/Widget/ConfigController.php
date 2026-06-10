<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class ConfigController extends Controller
{
    /**
     * The current hardcoded widget look, verbatim. An unconfigured practice
     * MUST render exactly this — the widget also declares the same values as
     * CSS defaults for the first paint before this endpoint resolves.
     */
    public const DEFAULT_THEME = [
        'colorPrimary' => '#6B8FA3',
        'colorPrimaryTo' => '#C40C78',
        'colorAccent' => '#EC0A8C',
        'colorBackground' => '#FFFFFF',
        'colorText' => '#26257F',
        'fontHeading' => 'Fredoka',
        'fontBody' => 'Nunito',
        'radius' => '26px',
    ];

    public function show(): JsonResponse
    {
        $stored = json_decode(Setting::get('widget_theme') ?? '', true);
        $logoPath = Setting::get('widget_logo_path');

        return response()->json([
            'theme' => array_merge(
                self::DEFAULT_THEME,
                is_array($stored) ? array_intersect_key($stored, self::DEFAULT_THEME) : []
            ),
            'logoUrl' => $logoPath ? Storage::disk('public')->url($logoPath) : null,
            'datenschutzUrl' => Setting::get('datenschutz_url'),
            'impressumUrl' => Setting::get('impressum_url'),
        ]);
    }
}
