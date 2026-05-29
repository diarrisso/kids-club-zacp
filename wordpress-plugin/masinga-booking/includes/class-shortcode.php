<?php
if (! defined('ABSPATH')) { exit; }

class Masinga_Booking_Shortcode
{
    public function register(): void
    {
        add_shortcode('masinga_booking', [$this, 'render']);
    }

    public function render($atts): string
    {
        $atts = shortcode_atts([
            'tenant' => get_option('masinga_booking_tenant', ''),
            'api' => get_option('masinga_booking_api', ''),
        ], $atts, 'masinga_booking');

        $tenant = esc_attr($atts['tenant']);
        $api = esc_url($atts['api']);
        if (! $tenant || ! $api) {
            return '<!-- masinga-booking: tenant/api not configured -->';
        }

        $src = esc_url(rtrim($atts['api'], '/') . '/widget/masinga-widget.js');

        return sprintf(
            '<div data-masinga-booking data-tenant="%s" data-api="%s"></div>' .
            '<script src="%s" defer></script>',
            $tenant, $api, $src
        );
    }
}
