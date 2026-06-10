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
        // The API base comes ONLY from the admin settings (manage_options), never
        // from shortcode attributes, so a post author cannot point the embedded
        // <script> at an arbitrary remote origin.
        $api = esc_url(get_option('masinga_booking_api', ''));
        if (! $api) {
            return '<!-- masinga-booking: api not configured -->';
        }

        $src = esc_url(
            add_query_arg(
                'ver',
                MASINGA_BOOKING_VERSION,
                rtrim((string) get_option('masinga_booking_api', ''), '/') . '/widget/masinga-widget.js'
            )
        );

        return sprintf(
            '<div data-masinga-booking data-api="%s"></div>' .
            '<script src="%s" defer></script>',
            $api, $src
        );
    }
}
