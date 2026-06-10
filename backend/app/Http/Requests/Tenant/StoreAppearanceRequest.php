<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAppearanceRequest extends FormRequest
{
    /** Curated fonts — must match FONT_FACES in resources/js/widget/useTheme.ts. */
    public const FONTS = ['Fredoka', 'Nunito', 'Inter', 'Poppins', 'System'];

    public function authorize(): bool
    {
        return true; // single-tenant: any authenticated user is staff
    }

    public function rules(): array
    {
        $hex = ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'];

        return [
            'colorPrimary' => $hex,
            'colorPrimaryTo' => $hex,
            'colorAccent' => $hex,
            'colorBackground' => $hex,
            'colorText' => $hex,
            'fontHeading' => ['required', Rule::in(self::FONTS)],
            'fontBody' => ['required', Rule::in(self::FONTS)],
            'radius' => ['required', 'integer', 'between:0,40'],
            // no svg: the image rule excludes it anyway, and svg logos are an XSS surface on the public disk
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:512'],
            'remove_logo' => ['nullable', 'boolean'],
            // url:http,https — bare `url` would accept data:/file:/200 IANA schemes
            'datenschutz_url' => ['nullable', 'url:http,https', 'max:2048'],
            'impressum_url' => ['nullable', 'url:http,https', 'max:2048'],
        ];
    }
}
