<?php
/**
 * Plugin Name: Masinga Booking
 * Description: Bindet das Masinga-Booking-Widget per Shortcode/Block ein.
 * Version: 1.0.0
 * Requires PHP: 8.0
 */
if (! defined('ABSPATH')) {
    exit;
}

define('MASINGA_BOOKING_PATH', plugin_dir_path(__FILE__));

require_once MASINGA_BOOKING_PATH . 'includes/class-settings.php';
require_once MASINGA_BOOKING_PATH . 'includes/class-shortcode.php';
require_once MASINGA_BOOKING_PATH . 'includes/class-block.php';

add_action('init', function () {
    (new Masinga_Booking_Settings())->register();
    (new Masinga_Booking_Shortcode())->register();
    (new Masinga_Booking_Block())->register();
});
