<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Classe RC_Settings_Page
 * 
 * Gère la page de configuration du plugin Relais Colis dans WooCommerce
 */
class RC_Settings_Page {
    /**
     * Constantes de conversion
     */
    const WEIGHT_CONVERSIONS = [
        'mg' => 0.000001, // mg vers kg
        'g'  => 0.001,    // g vers kg
        'kg' => 1         // kg vers kg
    ];

    const SIZE_CONVERSIONS = [
        'mm' => 0.1,   // mm vers cm
        'cm' => 1,     // cm vers cm
        'm'  => 100    // m vers cm
    ];

    /**
     * Convertit un poids vers kilogrammes
     *
     * @param float $weight Le poids à convertir
     * @param string $from_unit L'unité source ('mg', 'g', 'kg')
     * @return float Le poids en kilogrammes
     */
    public static function convert_weight_to_kg($weight, $from_unit) {
        if (!isset(self::WEIGHT_CONVERSIONS[$from_unit])) {
            return 0; // Ou une autre valeur par défaut
        }
        return $weight * self::WEIGHT_CONVERSIONS[$from_unit];
        
    }

    /**
     * Convertit une dimension vers centimètres
     *
     * @param float $size La dimension à convertir
     * @param string $from_unit L'unité source ('mm', 'cm', 'm')
     * @return float La dimension en centimètres
     */
    public static function convert_size_to_cm($size, $from_unit) {
        return $size * self::SIZE_CONVERSIONS[$from_unit];
    }

    /**
     * Sauvegarde une commande avec ses unités d'origine
     *
     * @param int $order_id ID de la commande
     * @param array $data Données à sauvegarder
     * @return void
     */
    public static function save_order_units($order_id, $data) {
        update_post_meta($order_id, '_rc_weight_unit', get_option('rc_weight_unit', 'kg'));
        update_post_meta($order_id, '_rc_size_unit', get_option('rc_size_unit', 'cm'));
        
        // Sauvegarder les dimensions et poids originaux
        if (isset($data['weight'])) {
            update_post_meta($order_id, '_rc_original_weight', $data['weight']);
        }
        if (isset($data['length'])) {
            update_post_meta($order_id, '_rc_original_length', $data['length']);
        }
        if (isset($data['width'])) {
            update_post_meta($order_id, '_rc_original_width', $data['width']);
        }
        if (isset($data['height'])) {
            update_post_meta($order_id, '_rc_original_height', $data['height']);
        }
    }

    /**
     * Récupère les dimensions originales d'une commande
     *
     * @param int $order_id ID de la commande
     * @return array Les dimensions dans leurs unités d'origine
     */
    public static function get_order_original_dimensions($order_id) {
        return [
            'weight' => get_post_meta($order_id, '_rc_original_weight', true),
            'weight_unit' => get_post_meta($order_id, '_rc_weight_unit', true),
            'length' => get_post_meta($order_id, '_rc_original_length', true),
            'width' => get_post_meta($order_id, '_rc_original_width', true),
            'height' => get_post_meta($order_id, '_rc_original_height', true),
            'size_unit' => get_post_meta($order_id, '_rc_size_unit', true)
        ];
    }

    /**
     * Prépare les données pour l'API
     *
     * @param array $dimensions Les dimensions à convertir
     * @return array Les dimensions converties en kg/cm
     */
    public static function prepare_dimensions_for_api($dimensions) {
        $weight = isset($dimensions['weight']) ? floatval($dimensions['weight']) : 0;

        $weight_unit = get_option('rc_weight_unit', 'kg');
        $size_unit = get_option('rc_size_unit', 'cm');

        return [
            'weight' => self::convert_weight_to_kg($dimensions['weight'], $weight_unit),
            'length' => self::convert_size_to_cm($dimensions['length'], $size_unit),
            'width' => self::convert_size_to_cm($dimensions['width'], $size_unit),
            'height' => self::convert_size_to_cm($dimensions['height'], $size_unit)
        ];
    }

    /**
     * Initialise les hooks et actions WordPress
     *
     * @return void
     */
    public static function init() {
        add_action('woocommerce_admin_field_rc_contract_key', __CLASS__ . '::generate_rc_contract_key_html');
        add_action('wp_ajax_rc_verify_contract', __CLASS__ . '::verify_contract');
        
        add_filter('woocommerce_settings_tabs_array', __CLASS__ . '::add_settings_tab', 50);
        add_action('woocommerce_settings_tabs_rc_settings', __CLASS__ . '::settings_tab');
        add_action('woocommerce_update_options_rc_settings', __CLASS__ . '::update_settings');
        add_action('admin_enqueue_scripts', __CLASS__ . '::enqueue_admin_assets');

        // Ajax handlers
        add_action('wp_ajax_rc_verify_key', __CLASS__ . '::verify_api_key');
        add_action('wp_ajax_rc_refresh_balance', __CLASS__ . '::refresh_balance');

        // Update mode
        add_action('wp_ajax_rc_update_mode', __CLASS__ . '::update_mode');

        add_filter('woocommerce_admin_settings_sanitize_option_rc_mode', __CLASS__ . '::sanitize_mode_option', 10, 3);
    }

    /**
     * Sanitize l'option du mode de fonctionnement
     *
     * @param mixed $value Valeur à sanitizer
     * @param array $option Options du champ
     * @param mixed $raw_value Valeur brute
     * @return string 'live' ou 'test'
     */
    public static function sanitize_mode_option($value, $option, $raw_value) {
        $current_mode = get_option('rc_mode', 'test');  // Récupère le mode actuel
        return $raw_value === 'yes' ? 'live' : 'test';  // Convertit 'yes'/'no' en 'live'/'test'
    }

    /**
     * Met à jour le mode de fonctionnement via AJAX
     *
     * @return void
     */
    public static function update_mode() {
    // Vérification du nonce de sécurité
    check_ajax_referer('rc-settings-nonce', 'nonce');

    // Validation de l'utilisateur
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error([
            'message' => __('Vous n\'avez pas les permissions requises', 'relais-colis-woocommerce'),
            'code' => 'unauthorized'
        ]);
        exit;
    }

    // Récupération et validation du mode
    $mode = isset($_POST['mode']) ? sanitize_text_field($_POST['mode']) : 'test';
    $current_mode = get_option('rc_mode', 'test');

    // Validation stricte du mode
    if (!in_array($mode, ['live', 'test'])) {
        wp_send_json_error([
            'message' => __('Mode invalide', 'relais-colis-woocommerce'),
            'currentMode' => $current_mode,
            'code' => 'invalid_mode'
        ]);
        exit;
    }

    // Vérifications spécifiques pour le passage en mode live
    if ($mode === 'live') {
        $api_key = get_option('rc_api_key');
        
        // Vérification de la présence de la clé API
        if (empty($api_key)) {
            wp_send_json_error([
                'message' => __('Une clé API est requise pour passer en mode production', 'relais-colis-woocommerce'),
                'currentMode' => $current_mode,
                'code' => 'missing_api_key'
            ]);
            exit;
        }

        // Validation optionnelle de la clé API 
        // (à personnaliser selon votre logique de vérification)
        try {
            $api_validation = self::validate_api_key($api_key);
            if (!$api_validation) {
                wp_send_json_error([
                    'message' => __('La clé API n\'est pas valide', 'relais-colis-woocommerce'),
                    'currentMode' => $current_mode,
                    'code' => 'invalid_api_key'
                ]);
                exit;
            }
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => __('Erreur de validation de la clé API', 'relais-colis-woocommerce'),
                'currentMode' => $current_mode,
                'code' => 'api_validation_error',
                'details' => $e->getMessage()
            ]);
            exit;
        }
    }

    // Sauvegarde du mode
    $updated = update_option('rc_mode', $mode);
    
    if ($updated) {
        // Log du changement de mode
        error_log(sprintf(
            'Relais Colis Mode Change: %s -> %s by user %d at %s', 
            $current_mode, 
            $mode, 
            get_current_user_id(),
            current_time('mysql')
        ));

        // Action hook personnalisé
        do_action('rc_mode_changed', $mode, $current_mode);

        wp_send_json_success([
            'message' => sprintf(
                __('Mode %s activé avec succès', 'relais-colis-woocommerce'),
                $mode === 'test' ? 'Test' : 'Production'
            ),
            'mode' => $mode,
            'previousMode' => $current_mode
        ]);
    } else {
        wp_send_json_error([
            'message' => __('Erreur technique lors de la mise à jour du mode', 'relais-colis-woocommerce'),
            'currentMode' => $current_mode,
            'code' => 'update_failed'
        ]);
    }
}

/**
 * Méthode de validation de la clé API
 * À personnaliser selon votre logique de vérification
 *
 * @param string $api_key Clé API à valider
 * @return bool
 */
private static function validate_api_key($api_key) {
    // Implémentation de la validation de la clé API
    // Exemples possibles :
    // - Vérification de la structure
    // - Appel à un endpoint de l'API
    // - Vérification en base de données
    
    // Exemple minimal
    return !empty($api_key) && strlen($api_key) >= 10;
}

    /**
     * Charge les assets CSS et JavaScript nécessaires
     *
     * @param string $hook Hook courant de la page admin
     * @return void
     */
    public static function enqueue_admin_assets($hook) {
        if ('woocommerce_page_wc-settings' !== $hook) {
            return;
        }
    
        $version = defined('WP_DEBUG') && WP_DEBUG ? time() : '1.0.0';
    
        wp_enqueue_script(
            'rc-admin-scripts',
            plugins_url('assets/js/relais-colis.js', dirname(__FILE__)),
            array('jquery'),
            $version,
            true
        );
    
        wp_localize_script('rc-admin-scripts', 'rcSettings', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rc-settings-nonce')
        ));
    }

    /**
     * Ajoute l'onglet Relais Colis dans les paramètres WooCommerce
     *
     * @param array $settings_tabs Tableau des onglets existants
     * @return array Tableau mis à jour avec le nouvel onglet
     */
    public static function add_settings_tab($settings_tabs) {
        $settings_tabs['rc_settings'] = __('Relais Colis', 'relais-colis-woocommerce');
        return $settings_tabs;
    }

    /**
     * Affiche les champs de configuration dans l'onglet
     *
     * @return void
     */
    public static function settings_tab() {
        woocommerce_admin_fields(self::get_settings());
    }

    /**
     * Sauvegarde les paramètres de configuration
     *
     * @return void
     */
    public static function update_settings() {
        woocommerce_update_options(self::get_settings());
    }

    /**
     * Définit la structure des champs de configuration
     *
     * @return array Tableau des paramètres de configuration
     */
    public static function get_settings() {
        $settings = array(
            'section_title' => array(
                'name' => __('Configurations Relais Colis', 'relais-colis-woocommerce'),
                'type' => 'title',
                'desc' => '',
                'id' => 'rc_settings_section_title'
            ),
            
            'mode' => array(
                'name' => __('Mode de fonctionnement', 'relais-colis-woocommerce'),
                'type' => 'checkbox',
                'default' => 'no',
                'id' => 'rc_mode',
                'desc' => __('Activez pour passer en mode production', 'relais-colis-woocommerce')
            ),
            
            'api_key' => array(
                'name' => __('Clé d\'activation', 'relais-colis-woocommerce'),
                'type' => 'text',
                'desc' => __('Saisissez votre clé d\'activation Relais Colis.', 'relais-colis-woocommerce'),
                'id' => 'rc_api_key'
            ),
            
            'c2c_key' => array(
                'name' => __('Clé API/Hash pour C2C', 'relais-colis-woocommerce'),
                'type' => 'text',
                'desc' => '',
                'id' => 'rc_c2c_key',
                'class' => 'rc-c2c-field hidden'
            ),
            
            'b2c_section' => array(
                'name' => __('Configuration des Prestations B2C', 'relais-colis-woocommerce'),
                'type' => 'title',
                'desc' => '',
                'id' => 'rc_b2c_section'
            ),
            
            'b2c_services' => array(
                'name' => __('Prestations disponibles', 'relais-colis-woocommerce'),
                'type' => 'text',
                'desc' => '',
                'id' => 'rc_b2c_services'
            ),
            
            'pricing_section' => array(
                'name' => __('Grille tarifaire', 'relais-colis-woocommerce'),
                'type' => 'title',
                'desc' => '',
                'id' => 'rc_pricing_section'
            ),
            
            'pricing_type' => array(
                'name' => __('Type de tarification', 'relais-colis-woocommerce'),
                'type' => 'select',
                'options' => array(
                    'weight' => __('Par poids', 'relais-colis-woocommerce'),
                    'price' => __('Par prix', 'relais-colis-woocommerce')
                ),
                'id' => 'rc_pricing_type'
            ),
            
            'pricing_grid' => array(
                'name' => __('Grille des tarifs', 'relais-colis-woocommerce'),
                'type' => 'text',
                'desc' => '',
                'id' => 'rc_pricing_grid'
            ),
            
            'free_shipping' => array(
                'name' => __('Montant minimum pour livraison gratuite', 'relais-colis-woocommerce'),
                'type' => 'number',
                'desc' => __('Laissez vide pour désactiver', 'relais-colis-woocommerce'),
                'id' => 'rc_free_shipping',
                'custom_attributes' => array(
                    'min' => '0',
                    'step' => '0.01'
                )
            ),

            // Nouvelle section pour les seuils de gratuité par offre
            'free_shipping_thresholds_section' => array(
                'name' => __('Seuils de gratuité par offre', 'relais-colis-woocommerce'),
                'type' => 'title',
                'desc' => __('Définissez le montant minimum du panier pour la gratuité de chaque offre', 'relais-colis-woocommerce'),
                'id' => 'rc_free_shipping_thresholds_section'
            ),

            // Point Relais
            'relay_free_shipping' => array(
                'name' => __('Point Relais', 'relais-colis-woocommerce'),
                'type' => 'number',
                'desc' => __('Montant minimum pour la livraison gratuite en Point Relais', 'relais-colis-woocommerce'),
                'id' => 'rc_relay_free_shipping',
                'custom_attributes' => array(
                    'min' => '0',
                    'step' => '0.01'
                )
            ),

            // Domicile
            'home_free_shipping' => array(
                'name' => __('Domicile', 'relais-colis-woocommerce'),
                'type' => 'number',
                'desc' => __('Montant minimum pour la livraison gratuite à Domicile', 'relais-colis-woocommerce'),
                'id' => 'rc_home_free_shipping',
                'custom_attributes' => array(
                    'min' => '0',
                    'step' => '0.01'
                )
            ),

            // Rendez-vous
            'appointment_free_shipping' => array(
                'name' => __('Rendez-vous', 'relais-colis-woocommerce'),
                'type' => 'number',
                'desc' => __('Montant minimum pour la livraison gratuite sur Rendez-vous', 'relais-colis-woocommerce'),
                'id' => 'rc_appointment_free_shipping',
                'custom_attributes' => array(
                    'min' => '0',
                    'step' => '0.01'
                )
            ),
            
            'units_section' => array(
                'name' => __('Unités de mesure', 'relais-colis-woocommerce'),
                'type' => 'title',
                'desc' => '',
                'id' => 'rc_units_section'
            ),
            
            'weight_unit' => array(
                'name' => __('Unité de poids', 'relais-colis-woocommerce'),
                'type' => 'select',
                'options' => array(
                    'mg' => __('Milligrammes (mg)', 'relais-colis-woocommerce'),
                    'g' => __('Grammes (g)', 'relais-colis-woocommerce'),
                    'kg' => __('Kilogrammes (kg)', 'relais-colis-woocommerce')
                ),
                'id' => 'rc_weight_unit'
            ),
            
            'size_unit' => array(
                'name' => __('Unité de taille', 'relais-colis-woocommerce'),
                'type' => 'select',
                'options' => array(
                    'mm' => __('Millimètres (mm)', 'relais-colis-woocommerce'),
                    'cm' => __('Centimètres (cm)', 'relais-colis-woocommerce'),
                    'm' => __('Mètres (m)', 'relais-colis-woocommerce')
                ),
                'id' => 'rc_size_unit'
            ),
            
            'label_section' => array(
                'name' => __('Format d\'étiquette', 'relais-colis-woocommerce'),
                'type' => 'title',
                'desc' => '',
                'id' => 'rc_label_section'
            ),
            
            'label_format' => array(
                'name' => __('Format d\'impression', 'relais-colis-woocommerce'),
                'type' => 'select',
                'options' => array(
                    'A4' => __('A4', 'relais-colis-woocommerce'),
                    'A6' => __('A6', 'relais-colis-woocommerce'),
                    '10x15' => __('10x15', 'relais-colis-woocommerce')
                ),
                'id' => 'rc_label_format'
            ),

            'section_end' => array(
                'type' => 'sectionend',
                'id' => 'rc_settings_section_end'
            )
        );

        return apply_filters('woocommerce_get_settings_rc_settings', $settings);
    }

    /**
     * Vérifie la validité de la clé de contrat via AJAX
     *
     * @return void
     */
    public static function verify_contract() {
        check_ajax_referer('rc-settings-nonce', 'nonce');
    
        $key = sanitize_text_field($_POST['key']);
    
        // Détecter le type de clé
        $type = (strpos($key, 'C2C-') === 0) ? 'C2C' : 'B2C';
    
        // Simuler une réponse API
        $response = array(
            'success' => true,
            'data' => array(
                'type' => $type,
                'message' => sprintf(__('Clé %s valide', 'relais-colis-woocommerce'), $type)
            )
        );
    
        wp_send_json($response);
    }

    /**
     * Vérifie la validité de la clé API via AJAX
     *
     * @return void
     */
    public static function verify_api_key() {
        error_log('verify_api_key called - POST data: ' . print_r($_POST, true));
        
        check_ajax_referer('rc-settings-nonce', '_ajax_nonce');
        
        if (!isset($_POST['key'])) {
            wp_send_json_error(['message' => 'No key provided']);
            return;
        }
    
        $key = sanitize_text_field($_POST['key']);
        
        // Simuler une réponse API
        $response = array(
            'success' => true,
            'data' => array(
                'firstName' => 'John',
                'lastName' => 'Doe',
                'balance' => 1000.50,
                'type' => (strpos($key, 'C2C-') === 0) ? 'C2C' : 'B2C'
            )
        );
    
        wp_send_json_success($response['data']);
    }

    /**
     * Rafraîchit le solde du compte via AJAX
     *
     * @return void
     */
    public static function refresh_balance() {
        check_ajax_referer('rc-settings-nonce', 'nonce');

        // Simuler une réponse API
        $response = array(
            'success' => true,
            'data' => array(
                'balance' => 950.75
            )
        );

        wp_send_json($response);
    }

    public static function generate_rc_c2c_key_html($value) {
        $option_value = get_option($value['id']);
        
        ob_start();
        ?>
        <tr valign="top" class="rc-c2c-field hidden">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($value['id']); ?>">
                    <?php echo esc_html($value['name']); ?>
                </label>
            </th>
            <td class="forminp forminp-rc_c2c_key">
                <div class="rc-c2c-wrapper">
                    <!-- Formulaire de saisie -->
                    <div class="rc-c2c-form">
                        <input type="text" 
                               id="<?php echo esc_attr($value['id']); ?>"
                               name="<?php echo esc_attr($value['id']); ?>"
                               value="<?php echo esc_attr($option_value); ?>"
                               class="regular-text">
                        <button type="button" class="button rc-verify-c2c">
                            <?php esc_html_e('Vérifier la clé', 'relais-colis-woocommerce'); ?>
                        </button>
                    </div>
                    
                    <!-- Informations C2C (cachées par défaut) -->
                    <div class="rc-c2c-info hidden">
                        <div class="rc-welcome"></div>
                        <div class="rc-balance"></div>
                        <button type="button" class="button rc-refresh-balance">
                            <?php esc_html_e('Rafraîchir le solde', 'relais-colis-woocommerce'); ?>
                        </button>
                    </div>
                </div>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Vérifie si la livraison gratuite s'applique pour une offre donnée
     *
     * @param string $shipping_method Type de livraison ('relay', 'home', 'appointment')
     * @param float $cart_total Montant total du panier
     * @return bool True si la livraison est gratuite
     */
    public static function is_shipping_free($shipping_method, $cart_total) {
        // Récupérer le seuil pour la méthode spécifique
        $threshold = get_option('rc_' . $shipping_method . '_free_shipping', 0);
        
        // Si le seuil est 0 ou vide, la gratuité est désactivée
        if (empty($threshold)) {
            return false;
        }
        
        return $cart_total >= floatval($threshold);
    }

    /**
     * Récupère tous les seuils de gratuité configurés
     *
     * @return array Tableau des seuils par méthode de livraison
     */
    public static function get_free_shipping_thresholds() {
        return array(
            'relay' => get_option('rc_relay_free_shipping', 0),
            'home' => get_option('rc_home_free_shipping', 0),
            'appointment' => get_option('rc_appointment_free_shipping', 0)
        );
    }
}

RC_Settings_Page::init();