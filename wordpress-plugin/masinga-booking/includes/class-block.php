<?php
if (! defined('ABSPATH')) { exit; }

class Masinga_Booking_Block
{
    public function register(): void
    {
        if (! function_exists('register_block_type')) {
            return;
        }
        register_block_type('masinga/booking', [
            'render_callback' => fn () => do_shortcode('[masinga_booking]'),
        ]);
    }
}
