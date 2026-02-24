<?php
/**
 * Plugin Name: Custom Top Bar
 * Plugin URI: 
 * Description: Fügt eine anpassbare Top-Bar mit Rabatt-Nachricht hinzu
 * Version: 1.0.0
 * Author: Levi
 * Author URI: 
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

class Levi_Custom_Top_Bar {
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_footer', array($this, 'render_top_bar'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    public function enqueue_scripts() {
        wp_enqueue_style(
            'levi-top-bar-style',
            plugins_url('css/top-bar.css', __FILE__),
            array(),
            '1.0.0'
        );
        
        wp_enqueue_script(
            'levi-top-bar-script',
            plugins_url('js/top-bar.js', __FILE__),
            array('jquery'),
            '1.0.0',
            true
        );
    }

    public function render_top_bar() {
        $options = get_option('levi_top_bar_options', array(
            'bg_color' => '#000000',
            'text_color' => '#ffffff'
        ));
        
        $styles = sprintf(
            'background-color: %s; color: %s;',
            esc_attr($options['bg_color']),
            esc_attr($options['text_color'])
        );
        
        ?>
        <div id="levi-top-bar" style="<?php echo $styles; ?>">
            <div class="top-bar-content">10 % Rabatt auf alles!</div>
            <button id="close-top-bar">&times;</button>
        </div>
        <?php
    }

    public function add_admin_menu() {
        add_options_page(
            'Top Bar Einstellungen',
            'Top Bar',
            'manage_options',
            'levi-top-bar',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings() {
        register_setting('levi_top_bar_options', 'levi_top_bar_options');
    }

    public function render_settings_page() {
        $options = get_option('levi_top_bar_options', array(
            'bg_color' => '#000000',
            'text_color' => '#ffffff'
        ));
        ?>
        <div class="wrap">
            <h1>Top Bar Einstellungen</h1>
            <form method="post" action="options.php">
                <?php settings_fields('levi_top_bar_options'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Hintergrundfarbe</th>
                        <td>
                            <input type="color" name="levi_top_bar_options[bg_color]" 
                                   value="<?php echo esc_attr($options['bg_color']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Textfarbe</th>
                        <td>
                            <input type="color" name="levi_top_bar_options[text_color]" 
                                   value="<?php echo esc_attr($options['text_color']); ?>">
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}

// Plugin initialisieren
add_action('plugins_loaded', array('Levi_Custom_Top_Bar', 'get_instance'));