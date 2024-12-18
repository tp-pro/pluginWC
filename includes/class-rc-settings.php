<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class RC_Settings_Page {

    public static function init() {
        add_filter( 'woocommerce_settings_tabs_array', __CLASS__ . '::add_settings_tab', 50 );
        add_action( 'woocommerce_settings_tabs_rc_settings', __CLASS__ . '::settings_tab' );
        add_action( 'woocommerce_update_options_rc_settings', __CLASS__ . '::update_settings' );
        
        // Add custom type for button
        add_action( 'woocommerce_admin_field_rc_validate_button', __CLASS__ . '::render_validate_button' );

        // Add custom AJAX action for API key validation
        add_action('wp_ajax_validate_rc_api_key', __CLASS__ . '::validate_api_key');
        add_action('wp_ajax_nopriv_validate_rc_api_key', __CLASS__ . '::validate_api_key');
    
        add_action('admin_enqueue_scripts', function() {
            $screen = get_current_screen();
            if ($screen->id === 'woocommerce_page_wc-settings' && 
                isset($_GET['tab']) && $_GET['tab'] === 'rc_settings') {
                
                wp_enqueue_script( 'rc-api-validation', plugin_dir_url( __FILE__ ) . '../assets/js/api-validation.js', array('jquery'), '1.0.0', true );
            }
        });
    }

    public static function validate_api_key() {
        // Log the raw incoming data for debugging
        error_log('RC API Validation - Incoming Request:');
        error_log('POST Data: ' . print_r($_POST, true));
        error_log('Server Data: ' . print_r($_SERVER, true));

        // Attempt to verify nonce - be more lenient for debugging
        $nonce_check = check_ajax_referer('rc-api-key-validation', 'security', false);
        if (!$nonce_check) {
            error_log('RC API Validation - Nonce check failed');
            wp_send_json_error( array(
                'message' => 'Nonce verification failed',
                'nonce_value' => $_POST['security'] ?? 'No nonce provided'
            ) );
            wp_die();
        }

        // Get the API key from the request
        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';

        // Validate the API key
        if ($api_key === 'C2C-API-KEY-123456789ABCDEF') {
            wp_send_json_success( array(
                'message' => 'Clé API validée avec succès',
                'welcome_name' => 'Utilisateur Démo'
            ) );
        } else {
            wp_send_json_error( array(
                'message' => 'Clé API invalide. Utilisez C2C-API-KEY-123456789ABCDEF'
            ) );
        }

        wp_die();
    }

    private static function is_valid_c2c_api_key($api_key) {
        // Validate C2C API key format
        // Format: C2C-API-KEY-[12 alphanumeric characters]
        $pattern = '/^C2C-API-KEY-[0-9A-F]{12}$/i';
        return preg_match($pattern, $api_key) === 1;
    }

    private static function get_welcome_name($api_key) {
        // Extract a potential name or identifier from the API key
        // This is a placeholder - you might want to replace with actual logic
        $identifier = substr($api_key, -6);
        $possible_names = [
            'Client C2C',
            'Utilisateur Relais Colis',
            'Partenaire Logistique'
        ];

        // Seed the random number generator with the API key to get consistent results
        srand(crc32($api_key));
        return $possible_names[rand(0, count($possible_names) - 1)];
    }

    public static function add_settings_tab( $settings_tabs ) {
        $settings_tabs['rc_settings'] = __( 'Relais Colis', 'relais-colis-woocommerce' );
        return $settings_tabs;
    }

    public static function settings_tab() {
        woocommerce_admin_fields( self::get_settings() );
    }

    public static function update_settings() {
        woocommerce_update_options( self::get_settings() );
    }

    public static function render_validate_button( $value ) {
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['title'] ); ?></label>
            </th>
            <td class="forminp forminp-<?php echo esc_attr( $value['type'] ); ?>">
                <button 
                    type="button" 
                    id="<?php echo esc_attr( $value['id'] ); ?>" 
                    class="button button-primary rc-validate-api-key"
                    onclick="validateRelaisColisAPIKey();"
                >
                    <?php echo esc_html( $value['button_text'] ); ?>
                </button>
                <?php if ( ! empty( $value['desc'] ) ) : ?>
                    <p class="description"><?php echo esc_html( $value['desc'] ); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    public static function get_settings() {
        $settings = array(
            'section_title' => array(
                'name' => __( 'Configurations Relais Colis', 'relais-colis-woocommerce' ),
                'type' => 'title',
                'desc' => '',
                'id' => 'rc_settings_section_title'
            ),
            'api_key' => array(
                'name' => __( 'Clé d\'activation', 'relais-colis-woocommerce' ),
                'type' => 'text',
                'desc' => __( 'Saisissez votre clé d\'activation Relais Colis.', 'relais-colis-woocommerce' ),
                'id' => 'rc_api_key'
            ),
            'validate_api_key' => array(
                'title'       => __( 'Valider la clé API', 'relais-colis-woocommerce' ),
                'type'        => 'rc_validate_button',
                'desc'        => __( 'Cliquez pour valider votre clé d\'activation', 'relais-colis-woocommerce' ),
                'id'          => 'rc_validate_api_key_button',
                'button_text' => __( 'Valider la clé API', 'relais-colis-woocommerce' )
            ),
            'section_end' => array(
                'type' => 'sectionend',
                'id' => 'rc_settings_section_end'
            )
        );

        return apply_filters( 'woocommerce_get_settings_' . 'rc_settings', $settings );
    }
}

RC_Settings_Page::init();
