<?php
if (! defined('ABSPATH')) { exit; }

class Masinga_Booking_Settings
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'fields']);
    }

    public function menu(): void
    {
        add_options_page('Masinga Booking', 'Masinga Booking', 'manage_options', 'masinga-booking', [$this, 'page']);
    }

    public function fields(): void
    {
        register_setting('masinga_booking', 'masinga_booking_api', [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
        ]);
    }

    public function page(): void
    {
        ?>
        <div class="wrap">
            <h1>Masinga Booking</h1>
            <form method="post" action="options.php">
                <?php settings_fields('masinga_booking'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="masinga_booking_api">API-URL</label></th>
                        <td><input name="masinga_booking_api" id="masinga_booking_api" type="url"
                                   value="<?php echo esc_attr(get_option('masinga_booking_api', '')); ?>" class="regular-text"
                                   placeholder="https://app.masinga-booking.de"></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
