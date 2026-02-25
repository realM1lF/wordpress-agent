<?php
/**
 * Plugin Name: Dynamic Top Bar
 * Description: Zeigt eine responsive Top-Bar mit dynamischem Wochentag-, Tageszeit-Gruß und Wetter-Emoji an.
 * Version: 1.1.0
 * Author: Levi
 */

if (!defined('ABSPATH')) exit;

class Dynamic_Top_Bar {
    private $option_name = 'dynamic_top_bar_options';
    private $defaults = array(
        'background_color' => '#2c3e50',
        'text_color' => '#ffffff',
        'enabled' => true,
        'location' => 'Berlin',
        'show_weather' => true
    );
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_head', array($this, 'render_top_bar'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_styles'));
    }
    
    public function add_admin_menu() {
        add_options_page(
            'Dynamic Top Bar Einstellungen',
            'Top Bar',
            'manage_options',
            'dynamic-top-bar',
            array($this, 'render_settings_page')
        );
    }
    
    public function register_settings() {
        register_setting('dynamic_top_bar_options', $this->option_name, array($this, 'sanitize_options'));
    }
    
    public function sanitize_options($input) {
        $sanitized = $this->defaults;
        $sanitized['background_color'] = sanitize_hex_color($input['background_color'] ?? '#2c3e50');
        $sanitized['text_color'] = sanitize_hex_color($input['text_color'] ?? '#ffffff');
        $sanitized['location'] = sanitize_text_field($input['location'] ?? 'Berlin');
        $sanitized['enabled'] = isset($input['enabled']);
        $sanitized['show_weather'] = isset($input['show_weather']);
        return $sanitized;
    }

    private function get_options() {
        $options = get_option($this->option_name, array());
        if (!is_array($options)) {
            $options = array();
        }
        return wp_parse_args($options, $this->defaults);
    }
    
    public function render_settings_page() {
        $options = $this->get_options();
        ?>
        <div class="wrap">
            <h1>Dynamic Top Bar Einstellungen</h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('dynamic_top_bar_options'); ?>
                
                <table class="form-table">
                    <tr>
                        <th><label for="enabled">Top Bar aktivieren</label></th>
                        <td><input type="checkbox" id="enabled" name="<?php echo $this->option_name; ?>[enabled]" <?php checked($options['enabled']); ?>></td>
                    </tr>
                    <tr>
                        <th><label for="show_weather">Wetter anzeigen</label></th>
                        <td>
                            <input type="checkbox" id="show_weather" name="<?php echo $this->option_name; ?>[show_weather]" <?php checked($options['show_weather']); ?>>
                            <p class="description">Zeigt ein Wetter-Emoji basierend auf den aktuellen Bedingungen an.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="location">Standort für Wetter</label></th>
                        <td>
                            <input type="text" id="location" name="<?php echo $this->option_name; ?>[location]" value="<?php echo esc_attr($options['location']); ?>" class="regular-text">
                            <p class="description">Gib eine Stadt ein (z.B. "Berlin", "Hamburg", "München").</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="bg_color">Hintergrundfarbe</label></th>
                        <td><input type="color" id="bg_color" name="<?php echo $this->option_name; ?>[background_color]" value="<?php echo esc_attr($options['background_color']); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="text_color">Textfarbe</label></th>
                        <td><input type="color" id="text_color" name="<?php echo $this->option_name; ?>[text_color]" value="<?php echo esc_attr($options['text_color']); ?>"></td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
    
    public function enqueue_styles() {
        $options = $this->get_options();
        if (!$options['enabled']) return;
        
        $css = "#dynamic-top-bar{background:" . $options['background_color'] . ";color:" . $options['text_color'] . ";padding:10px 20px;text-align:center;font-family:system-ui,sans-serif;font-size:14px;position:relative;z-index:999999}#dynamic-top-bar .weather-emoji{margin-left:5px}@media(max-width:768px){#dynamic-top-bar{font-size:12px;padding:8px 15px}}";
        
        wp_register_style('dynamic-top-bar', false);
        wp_enqueue_style('dynamic-top-bar');
        wp_add_inline_style('dynamic-top-bar', $css);
    }
    
    public function render_top_bar() {
        $options = $this->get_options();
        if (!$options['enabled']) return;
        
        $greeting = $this->get_greeting_text();
        $location = esc_attr($options['location']);
        ?>
        <div id="dynamic-top-bar">
            <?php echo $greeting; ?>
            <?php if ($options['show_weather']) : ?>
                | <span class="weather-emoji" id="dtb-weather" data-location="<?php echo $location; ?>">⏳</span>
            <?php endif; ?>
        </div>
        
        <?php if ($options['show_weather']) : ?>
        <script>
        document.addEventListener('DOMContentLoaded',function(){
            var el=document.getElementById('dtb-weather');
            if(!el)return;
            var loc=el.dataset.location;
            function getEmoji(c){
                if(c===0)return'☀️';
                if(c>=1&&c<=3)return'☁️';
                if(c===45||c===48)return'🌫️';
                if(c>=51&&c<=55)return'🌦️';
                if(c>=56&&c<=57)return'🌨️';
                if(c>=61&&c<=65)return'🌧️';
                if(c>=66&&c<=67)return'🌨️';
                if(c>=71&&c<=77)return'❄️';
                if(c>=80&&c<=82)return'🌦️';
                if(c>=85&&c<=86)return'🌨️';
                if(c>=95)return'⛈️';
                return'⛅';
            }
            fetch('https://geocoding-api.open-meteo.com/v1/search?name='+encodeURIComponent(loc)+'&count=1&language=de&format=json')
                .then(r=>r.json())
                .then(d=>{
                    if(!d.results||!d.results[0]){el.textContent='📍';return;}
                    var lat=d.results[0].latitude,lon=d.results[0].longitude;
                    return fetch('https://api.open-meteo.com/v1/forecast?latitude='+lat+'&longitude='+lon+'&current_weather=true');
                })
                .then(r=>r?r.json():null)
                .then(d=>{
                    if(d&&d.current_weather){
                        el.textContent=getEmoji(d.current_weather.weathercode);
                        el.title=Math.round(d.current_weather.temperature)+'°C';
                    }else{el.textContent='⛅';}
                })
                .catch(e=>{console.error(e);el.textContent='⛅';});
        });
        </script>
        <?php endif;
    }
    
    private function get_greeting_text() {
        $days=['Sonntag','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag'];
        $d=date('w');$h=date('G');
        if($h>=5&&$h<12)$t='Morgen';
        elseif($h>=12&&$h<14)$t='Mittag';
        elseif($h>=14&&$h<18)$t='Nachmittag';
        elseif($h>=18&&$h<22)$t='Abend';
        else $t='Nacht';
        return"Schönen ".$days[$d]." ".$t;
    }
}

new Dynamic_Top_Bar();