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
        // Tenant + API come ONLY from the admin settings (manage_options), never
        // from shortcode attributes, so a post author cannot point the embedded
        // <script> at an arbitrary remote origin.
        $tenant = esc_attr(get_option('masinga_booking_tenant', ''));
        $api = esc_url(get_option('masinga_booking_api', ''));
        if (! $tenant || ! $api) {
            return '<!-- masinga-booking: tenant/api not configured -->';
        }

        $src = esc_url(rtrim((string) get_option('masinga_booking_api', ''), '/') . '/widget/masinga-widget.js');

        return sprintf(
            '<div data-masinga-booking data-tenant="%s" data-api="%s"></div>' .
            '<script src="%s" defer></script>',
            $tenant, $api, $src
        );
    }
}
