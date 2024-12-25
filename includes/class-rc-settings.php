<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class RC_Settings_Page {

    public static function init() {
        add_filter( 'woocommerce_settings_tabs_array', __CLASS__ . '::add_settings_tab', 50 );
        add_action( 'woocommerce_settings_tabs_rc_settings', __CLASS__ . '::settings_tab' );
        add_action( 'woocommerce_sections_rc_settings', __CLASS__ . '::output_sections' );
        add_action( 'woocommerce_update_options_rc_settings', __CLASS__ . '::update_settings' );
        add_action( 'woocommerce_admin_field_rc_grilles_tarifaires', __CLASS__ . '::render_grilles_tarifaires');
        add_action( 'woocommerce_admin_field_rc_prestations', __CLASS__ . '::render_prestations');

        // Add custom type for button
        add_action( 'woocommerce_admin_field_rc_action_buttons', __CLASS__ . '::render_action_buttons' );

        // Add custom AJAX action for API key validation
        add_action('wp_ajax_validate_rc_api_key', __CLASS__ . '::validate_api_key');
        add_action('wp_ajax_nopriv_validate_rc_api_key', __CLASS__ . '::validate_api_key');
        add_action('wp_ajax_rc_refresh_client_info', __CLASS__ . '::fetch_client_info');
        add_action('wp_ajax_nopriv_rc_refresh_client_info', __CLASS__ . '::fetch_client_info');
        add_action('wp_ajax_rc_extract_client_info', __CLASS__ . '::fetch_client_info');
        add_action('wp_ajax_nopriv_rc_extract_client_info', __CLASS__ . '::fetch_client_info');

        add_action('admin_enqueue_scripts', function() {
            $screen = get_current_screen();
            if ($screen->id === 'woocommerce_page_wc-settings' &&
                isset($_GET['tab']) && $_GET['tab'] === 'rc_settings') {

                wp_enqueue_script( 'rc-api-validation', plugin_dir_url( __FILE__ ) . '../assets/js/api-validation.js', array('jquery'), '1.0.0', true );
                wp_enqueue_style( 'rc-syles', plugin_dir_url( __FILE__ ) . '../assets/css/relais-colis.css');

                // Passer le nonce au script JS
                wp_localize_script( 'rc-api-validation', 'rc_ajax', [
                    'ajax_url' => admin_url( 'admin-ajax.php' ),
                    'nonce'    => wp_create_nonce( 'rc-api-action' )
                ]);
            }
        });
    }


    public static function validate_api_key() {
        $nonce_check = check_ajax_referer('rc-api-key-validation', 'security', false);
        if (!$nonce_check) {
            wp_send_json_error(['message' => 'Nonce verification failed']);
        }

        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';

        if (empty($api_key)) {
            wp_send_json_error(['message' => __('Clé API manquante', 'relais-colis-woocommerce')]);
        }

        wp_send_json_success(['message' => __('Clé API valide', 'relais-colis-woocommerce')]);
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
        global $current_section;

        if ( '' === $current_section ) {
            woocommerce_admin_fields( self::get_general_settings() );
        } elseif ( 'prestations' === $current_section ) {
            woocommerce_admin_fields( self::get_prestations_settings() );
        } elseif ( 'informations' === $current_section ) {
            woocommerce_admin_fields( self::get_informations_settings() );
        }
    }


    public static function output_sections() {
        global $current_section;

        $sections = self::get_sections();
        echo '<ul class="subsubsub">';
        foreach ( $sections as $id => $label ) {
            $class = ( $current_section === $id ) ? 'current' : '';
            printf( '<li><a href="%s" class="%s">%s</a> | </li>',
                esc_url( admin_url( 'admin.php?page=wc-settings&tab=rc_settings&section=' . $id ) ),
                esc_attr( $class ),
                esc_html( $label )
            );
        }
        echo '</ul><br class="clear" />';
    }


    private static function get_sections() {
        return [
            '' => __( 'Réglages Relais Colis', 'relais-colis-woocommerce' ),
            'prestations' => __( 'Prestations', 'relais-colis-woocommerce' ),
            'informations' => __( 'Vos informations', 'relais-colis-woocommerce' ),
        ];
    }


    public static function update_settings() {
        global $current_section;

        if ( '' === $current_section ) {
            woocommerce_update_options( self::get_general_settings() );
        } elseif ( 'prestations' === $current_section ) {
            // Sauvegarde des grilles tarifaires
            if (isset($_POST['grilles']) && is_array($_POST['grilles'])) {
                $grilles_data = [];
                foreach ($_POST['grilles'] as $grille_index => $grille) {
                    if (!empty($grille['prestation_name']) && !empty($grille['critere'])) {
                        $lines = [];
                        if (isset($grille['lines']) && is_array($grille['lines'])) {
                            foreach ($grille['lines'] as $line_index => $line) {
                                if (isset($line['min'], $line['max'], $line['price'])) {
                                    $lines[] = [
                                        'min' => sanitize_text_field($line['min']),
                                        'max' => sanitize_text_field($line['max']),
                                        'price' => sanitize_text_field($line['price']),
                                    ];
                                }
                            }
                        }
                        $grilles_data[] = [
                            'method_name' => sanitize_text_field($grille['method_name']), // Enregistrement du nouveau champ
                            'prestation_name' => sanitize_text_field($grille['prestation_name']),
                            'critere' => sanitize_text_field($grille['critere']),
                            'lines' => $lines,
                        ];
                    }
                }
                update_option('rc_grilles_tarifaires', wp_json_encode($grilles_data));
            }
            // Sauvegarde des prestations
            $fixed_prestations = [
                'Prise de Rendez-vous',
                'Livraison à l’étage',
                'Livraison à deux',
                'M.E.S gros électroménager',
                'Assemblage rapide',
                'Hors Norme',
                'Déballage produit',
                'Evacuation Emballage',
                'Reprise de votre ancien matériel',
                'Livraison dans la pièce souhaitée',
                'Livraison au pas de porte',
            ];

            $prestations_data = [];
            foreach ($fixed_prestations as $index => $name) {
                $prestations_data[] = [
                    'name'          => $name,
                    'client_choice' => sanitize_text_field($_POST['client_choice'][$index] ?? ''),
                    'method'        => sanitize_text_field($_POST['delivery_method'][$index] ?? ''),
                    'active'        => isset($_POST['active'][$index]) ? 'yes' : 'no',
                    'price'         => sanitize_text_field($_POST['price'][$index] ?? ''),
                ];
            }
            update_option('rc_prestations', wp_json_encode($prestations_data));
        } elseif ( 'informations' === $current_section ) {
            woocommerce_update_options( self::get_informations_settings() );
        }
    }


    public static function render_grilles_tarifaires($section) {
        $saved_grilles = get_option('rc_grilles_tarifaires', '[]');
        $saved_grilles = json_decode($saved_grilles, true) ?: [];

        $fixed_prestations = [
            'Prise de Rendez-vous',
            'Livraison à l’étage',
            'Livraison à deux',
            'M.E.S gros électroménager',
            'Assemblage rapide',
            'Hors Norme',
            'Déballage produit',
            'Evacuation Emballage',
            'Reprise de votre ancien matériel',
            'Livraison dans la pièce souhaitée',
            'Livraison au pas de porte',
        ];

        ?>
        <div id="rc-grilles-tarifaires">
            <button type="button" id="add-grille" class="button button-secondary">
                <?php _e('Ajouter une grille tarifaire', 'relais-colis-woocommerce'); ?>
            </button>
            <div id="grilles-container">
                <?php foreach ($saved_grilles as $grille_index => $grille): ?>
                    <?php self::render_single_grille($grille_index, $grille, $fixed_prestations); ?>
                <?php endforeach; ?>
            </div>
        </div>
        <script>
            jQuery(document).ready(function($) {
                let grilleIndex = $("#grilles-container .grille-container").length;

                // Ajouter une nouvelle grille
                $("#add-grille").click(function() {
                    const newGrille = <?= json_encode(self::render_single_grille_template($fixed_prestations)); ?>;
                    $("#grilles-container").append(newGrille.replace(/__INDEX__/g, grilleIndex));
                    grilleIndex++;
                });

                // Ajouter une nouvelle ligne
                $(document).on("click", ".add-line", function() {
                    const parent = $(this).closest(".grille-container");
                    const grilleIndex = parent.data("index");
                    const lineIndex = parent.find(".line-row").length;
                    const newLine = <?= json_encode(self::render_single_grille__line_template()); ?>;
                    parent.find(".lines-container").append(
                        newLine.replace(/__GRILLE_INDEX__/g, grilleIndex).replace(/__LINE_INDEX__/g, lineIndex)
                    );
                });

                // Supprimer une ligne
                $(document).on("click", ".remove-line", function() {
                    $(this).closest(".line-row").remove();
                });

                // Supprimer une grille
                $(document).on("click", ".remove-grille", function() {
                    $(this).closest(".grille-container").remove();
                });
            });
        </script>
        <?php
    }



    private static function render_single_grille($grille_index, $grille, $fixed_prestations) {
    ?>
        <div class="grille-container" data-index="<?= $grille_index; ?>">
            <button type="button" class="remove-grille">❌</button>
            <div class="line-g">
                <label><?php _e('Intitulé de la méthode de livraison', 'relais-colis-woocommerce'); ?></label>
                <input type="text" name="grilles[<?= $grille_index; ?>][method_name]" value="<?= esc_attr($grille['method_name'] ?? ''); ?>" placeholder="<?php _e('Méthode livraison', 'relais-colis-woocommerce'); ?>">
            </div>
            <div class="grille-header">
                <div class="line-g">
                    <label><?php _e('Prestation associée', 'relais-colis-woocommerce'); ?></label>
                    <select name="grilles[<?= $grille_index; ?>][prestation_name]">
                        <option value=""><?php _e('Sélectionner une prestation', 'relais-colis-woocommerce'); ?></option>
                        <?php foreach ($fixed_prestations as $prestation): ?>
                            <option value="<?= esc_attr($prestation); ?>" <?php selected($grille['prestation_name'] ?? '', $prestation); ?>>
                                <?= esc_html($prestation); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="line-g">
                    <label><?php _e('Type de tranche tarifaire', 'relais-colis-woocommerce'); ?></label>
                    <select name="grilles[<?= $grille_index; ?>][critere]">
                        <option value="price" <?php selected($grille['critere'] ?? '', 'price'); ?>><?php _e('Prix total de la commande', 'relais-colis-woocommerce'); ?></option>
                        <option value="weight" <?php selected($grille['critere'] ?? '', 'weight'); ?>><?php _e('Poids de la commande', 'relais-colis-woocommerce'); ?></option>
                    </select>
                </div>
            </div>
            <div class="lines-container">
                <?php foreach ($grille['lines'] ?? [] as $line_index => $line): ?>
                    <?php self::render_single_grille__line($grille_index, $line_index, $line); ?>
                <?php endforeach; ?>
            </div>
            <button type="button" class="add-line button button-secondary"><?php _e('Ajouter une ligne', 'relais-colis-woocommerce'); ?></button>
        </div>
    <?php
    }


    private static function render_single_grille__line($grille_index, $line_index, $line) {
        ?>
        <div class="line-row">
            <input type="number" name="grilles[<?= $grille_index; ?>][lines][<?= $line_index; ?>][min]" value="<?= esc_attr($line['min'] ?? ''); ?>" placeholder="Min">
            <input type="number" name="grilles[<?= $grille_index; ?>][lines][<?= $line_index; ?>][max]" value="<?= esc_attr($line['max'] ?? ''); ?>" placeholder="Max">
            <input type="number" step="0.01" name="grilles[<?= $grille_index; ?>][lines][<?= $line_index; ?>][price]" value="<?= esc_attr($line['price'] ?? ''); ?>" placeholder="Prix">
            <button type="button" class="remove-line">❌</button>
        </div>
        <?php
    }


    private static function render_single_grille_template($fixed_prestations) {
        ob_start();
        ?>
        <div class="grille-container" data-index="__INDEX__">
            <button type="button" class="remove-grille">❌</button>
            <div class="line-g">
                <label><?php _e('Intitulé de la méthode de livraison', 'relais-colis-woocommerce'); ?></label>
                <input type="text" name="grilles[__INDEX__][method_name]" placeholder="<?php _e('Exemple : Livraison à domicile', 'relais-colis-woocommerce'); ?>">
            </div>
            <div class="grille-header">
                <div class="line-g">
                    <label><?php _e('Prestation associée', 'relais-colis-woocommerce'); ?></label>
                    <select name="grilles[__INDEX__][prestation_name]">
                        <option value=""><?php _e('Sélectionner une prestation', 'relais-colis-woocommerce'); ?></option>
                        <?php foreach ($fixed_prestations as $prestation): ?>
                            <option value="<?= esc_attr($prestation); ?>"><?= esc_html($prestation); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="line-g">
                    <label><?php _e('Type de tranche tarifaire', 'relais-colis-woocommerce'); ?></label>
                    <select name="grilles[__INDEX__][critere]">
                        <option value="price"><?php _e('Prix total de la commande', 'relais-colis-woocommerce'); ?></option>
                        <option value="weight"><?php _e('Poids de la commande', 'relais-colis-woocommerce'); ?></option>
                    </select>
                </div>
            </div>
            <div class="lines-container"></div>
            <button type="button" class="add-line button button-secondary"><?php _e('Ajouter une ligne', 'relais-colis-woocommerce'); ?></button>
        </div>
        <?php
        return ob_get_clean();
    }


    private static function render_single_grille__line_template() {
        ob_start();
        ?>
        <div class="line-row">
            <input type="number" name="grilles[__GRILLE_INDEX__][lines][__LINE_INDEX__][min]" placeholder="Min">
            <input type="number" name="grilles[__GRILLE_INDEX__][lines][__LINE_INDEX__][max]" placeholder="Max">
            <input type="number" step="0.01" name="grilles[__GRILLE_INDEX__][lines][__LINE_INDEX__][price]" placeholder="Prix">
            <button type="button" class="remove-line">❌</button>
        </div>
        <?php
        return ob_get_clean();
    }


    public static function render_prestations($section) {
        $fixed_prestations = [
            'Prise de Rendez-vous',
            'Livraison à l’étage',
            'Livraison à deux',
            'M.E.S gros électroménager',
            'Assemblage rapide',
            'Hors Norme',
            'Déballage produit',
            'Evacuation Emballage',
            'Reprise de votre ancien matériel',
            'Livraison dans la pièce souhaitée',
            'Livraison au pas de porte',
        ];

        $saved_prestations = get_option('rc_prestations', '[]');
        $saved_prestations = json_decode($saved_prestations, true);

        if (!is_array($saved_prestations)) {
            $saved_prestations = [];
        }

        ?>
        <div id="rc-prestations-editor">
            <div id="prestations-container">
                <header class="prestah">
                    <span><?php _e('Nom de la prestation', 'relais-colis-woocommerce'); ?></span>
                    <span><?php _e('Choix du client', 'relais-colis-woocommerce'); ?></span>
                    <span><?php _e('Méthode de livraison', 'relais-colis-woocommerce'); ?></span>
                    <span><?php _e('Actif', 'relais-colis-woocommerce'); ?></span>
                    <span><?php _e('Prix', 'relais-colis-woocommerce'); ?></span>
                </header>
                <?php foreach ($fixed_prestations as $index => $prestation_name): ?>
                    <?php
                    $saved_prestation = $saved_prestations[$index] ?? [];
                    $client_choice = esc_attr($saved_prestation['client_choice'] ?? '');
                    $method = esc_attr($saved_prestation['method'] ?? '');
                    $active = isset($saved_prestation['active']) && $saved_prestation['active'] === 'yes' ? 'checked' : '';
                    $price = esc_attr($saved_prestation['price'] ?? '');
                    ?>
                    <div class="prestation-container" data-index="<?= $index; ?>">
                        <div class="line-g">
                            <input type="text" name="prestation_name[<?= $index; ?>]" value="<?= esc_attr($prestation_name); ?>" readonly>
                        </div>

                        <div class="line-g">
                            <select name="client_choice[<?= $index; ?>]">
                                <option value="oui" <?php selected($client_choice, 'oui'); ?>><?php _e('Oui', 'relais-colis-woocommerce'); ?></option>
                                <option value="non" <?php selected($client_choice, 'non'); ?>><?php _e('Non', 'relais-colis-woocommerce'); ?></option>
                            </select>
                        </div>

                        <div class="line-g">
                            <select name="delivery_method[<?= $index; ?>]">
                                <option value="home" <?php selected($method, 'home'); ?>><?php _e('Home', 'relais-colis-woocommerce'); ?></option>
                                <option value="home+" <?php selected($method, 'home+'); ?>><?php _e('Home+', 'relais-colis-woocommerce'); ?></option>
                            </select>
                        </div>

                        <div class="line-g">
                            <input type="checkbox" name="active[<?= $index; ?>]" value="yes" <?= $active; ?>>
                        </div>

                        <div class="line-g">
                            <input type="text" name="price[<?= $index; ?>]" value="<?= $price; ?>" placeholder="<?php _e('Prix en €', 'relais-colis-woocommerce'); ?>">
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }


    public static function fetch_client_info() {
        if ( ! check_ajax_referer( 'rc-api-action', 'security', false ) ) {
            wp_send_json_error(['message' => __('Nonce invalide.', 'relais-colis-woocommerce')]);
        }

        // TODO: Appel API
        $api_response = [
            'nom'     => 'Dupont',
            'prenom'  => 'Jean',
            'solde'   => '123.45 €',
        ];

        wp_send_json_success($api_response);
    }


    public static function render_action_buttons( $value ) {
        echo '<button type="button" id="rc-refresh-info" class="button">' . esc_html__('Rafraîchir les informations', 'relais-colis-woocommerce') . '</button>';
        echo '<button type="button" id="rc-extract-info" class="button">' . esc_html__('Extraire les informations', 'relais-colis-woocommerce') . '</button>';
        echo '<div id="rc-client-info" style="margin-top: 10px;"></div>';
        echo '<script>
        jQuery(document).ready(function($) {
            $("#rc-refresh-info").click(function() {
                $.ajax({
                    url: rc_ajax.ajax_url, // Passé depuis wp_localize_script
                    method: "POST",
                    data: {
                        action: "rc_refresh_client_info",
                        security: rc_ajax.nonce // Nonce dédié pour AJAX
                    },
                    success: function(response) {
                        if (response.success) {
                            let data = response.data;
                            let infoHtml = `<strong>' . esc_html__('Nom', 'relais-colis-woocommerce') . ' :</strong> ${data.nom}<br>
                                            <strong>' . esc_html__('Prénom', 'relais-colis-woocommerce') . ' :</strong> ${data.prenom}<br>
                                            <strong>' . esc_html__('Solde', 'relais-colis-woocommerce') . ' :</strong> ${data.solde}`;
                            $("#rc-client-info").html(infoHtml);
                        } else {
                            alert(response.data.message || "Une erreur est survenue.");
                        }
                    },
                    error: function() {
                        alert("Erreur de connexion avec l\'API.");
                    }
                });
            });
        
            $("#rc-extract-info").click(function() {
                $.ajax({
                    url: rc_ajax.ajax_url,
                    method: "POST",
                    data: {
                    action: "rc_extract_client_info",
                        security: rc_ajax.nonce
                    },
                    success: function(response) {
                    if (response.success) {
                        let data = response.data;
                            alert(`Nom : ${data.nom}\\nPrénom : ${data.prenom}\\nSolde : ${data.solde}`);
                        } else {
                        alert(response.data.message || "Une erreur est survenue.");
                    }
                },
                    error: function() {
                    alert("Erreur de connexion avec l\'API.");
                }
                });
            });
        });
    </script>';
    }


    private static function get_general_settings() {
        return [
            // Section : Paramètres généraux
            [
                'title' => __('Paramètres Relais Colis', 'relais-colis-woocommerce'),
                'type'  => 'title',
                'id'    => 'rc_settings_title',
            ],

            // Mode Live/Test
            [
                'title'    => __('Mode Live/Test', 'relais-colis-woocommerce'),
                'desc'     => __('Basculer entre le mode Live (coché) et Test (décoché).', 'relais-colis-woocommerce'),
                'id'       => 'rc_mode_test',
                'default'  => 'no',
                'type'     => 'checkbox',
            ],

            // Unités de poids
            [
                'title'    => __('Unités de poids', 'relais-colis-woocommerce'),
                'desc'     => __('Sélectionnez l\'unité de poids à utiliser.', 'relais-colis-woocommerce'),
                'id'       => 'rc_weight_unit',
                'type'     => 'select',
                'options'  => [
                    'g' => __('Grammes (g)', 'relais-colis-woocommerce'),
                    'dg' => __('Décigrammes (dg)', 'relais-colis-woocommerce'),
                    'kg' => __('Kilogrammes (kg)', 'relais-colis-woocommerce'),
                    'lb' => __('Livres (lb)', 'relais-colis-woocommerce'),
                ],
                'default'  => 'g',
                'class'    => 'wc-enhanced-select',
                'desc_tip' => true,
            ],

            // Unités de longueur
            [
                'title'    => __('Unités de longueur', 'relais-colis-woocommerce'),
                'desc'     => __('Sélectionnez l\'unité de longueur à utiliser.', 'relais-colis-woocommerce'),
                'id'       => 'rc_length_unit',
                'type'     => 'select',
                'options'  => [
                    'mm' => __('Millimètres (mm)', 'relais-colis-woocommerce'),
                    'cm' => __('Centimètres (cm)', 'relais-colis-woocommerce'),
                    'dm' => __('Décimètres (dm)', 'relais-colis-woocommerce'),
                    'm' => __('Mètres (m)', 'relais-colis-woocommerce'),
                    'in' => __('Pouces (in)', 'relais-colis-woocommerce'),
                ],
                'default'  => 'cm',
                'class'    => 'wc-enhanced-select',
                'desc_tip' => true,
            ],

            // Format d’étiquette
            [
                'title'    => __('Format d’étiquette', 'relais-colis-woocommerce'),
                'desc'     => __('Choisissez le format d’étiquette à imprimer.', 'relais-colis-woocommerce'),
                'id'       => 'rc_label_format',
                'type'     => 'select',
                'options'  => [
                    'A4' => __('Format A4', 'relais-colis-woocommerce'),
                    'A5' => __('Format A5', 'relais-colis-woocommerce'),
                    'carre' => __('Format Carré', 'relais-colis-woocommerce'),
                    '10x15' => __('Format 10x15', 'relais-colis-woocommerce'),
                ],
                'default'  => 'A4',
            ],

            [
                'type' => 'sectionend',
                'id'   => 'rc_settings_section_end',
            ],
        ];
    }


    private static function get_prestations_settings() {
        return [
            // Section : Prestations
            [
                'title' => __('Prestations', 'relais-colis-woocommerce'),
                'type'  => 'title',
                'desc'  => __('Ajoutez des prestations avec un seuil de gratuité.', 'relais-colis-woocommerce'),
                'id'    => 'rc_prestations_title',
            ],
            [
                'type' => 'rc_prestations',
                'id'   => 'rc_prestations',
            ],
            [
                'type' => 'sectionend',
                'id'   => 'rc_prestations_section_end',
            ],
            [
                'title' => __('Grilles Tarifaires', 'relais-colis-woocommerce'),
                'type'  => 'title',
                'desc'  => __('Définissez des tranches tarifaires basées sur le poids ou la valeur totale.', 'relais-colis-woocommerce'),
                'id'    => 'rc_grilles_tarifaires_title',
            ],
            // Grilles tarifaires
            [
                'type' => 'rc_grilles_tarifaires',
                'id'   => 'rc_grilles_tarifaires',
            ],
            [
                'type' => 'sectionend',
                'id'   => 'rc_grilles_tarifaires_section_end',
            ],

        ];
    }


    private static function get_informations_settings() {
        return [
            // Section : Vos informations
            [
                'title' => __('Vos informations', 'relais-colis-woocommerce'),
                'type'  => 'title',
                'desc'  => __('Entrez votre clé d’activation pour synchroniser vos informations.', 'relais-colis-woocommerce'),
                'id'    => 'rc_informations_title',
            ],
            // Clé d'activation
            [
                'title'    => __('Clé d’activation', 'relais-colis-woocommerce'),
                'id'       => 'rc_api_key',
                'type'     => 'text',
                'default'  => '',
                'desc_tip' => __('Votre clé d’activation C2C ou B2C.', 'relais-colis-woocommerce'),
            ],
            // Boutons Extraire et rafraichir
            [
                'type' => 'rc_action_buttons',
                'id'   => 'rc_action_buttons',
            ],
            // Section : Options B2C
            [
                'title' => __('Options B2C', 'relais-colis-woocommerce'),
                'type'  => 'title',
                'desc'  => __('Configurez les options incluses votre compte B2C.', 'relais-colis-woocommerce'),
                'id'    => 'rc_b2c_options_title',
            ],
            // Liste produits
            [
                'title'    => __('Liste de Produits', 'relais-colis-woocommerce'),
                'id'       => 'rc_b2c_product_list',
                'type'     => 'multiselect',
                'options'  => [
                    'product_a' => 'Produit A',
                    'product_b' => 'Produit B',
                    'product_c' => 'Produit C',
                ],
            ],
            // Méthodes de livraison
            [
                'title'    => __('Méthodes de livraison', 'relais-colis-woocommerce'),
                'id'       => 'rc_b2c_shipping_methods',
                'type'     => 'multiselect',
                'options'  => [
                    'rendez_vous'        => 'Prise de rendez-vous',
                    'etage'              => 'Livraison à l’étage',
                    'a_deux'             => 'Livraison à deux',
                    'mes_electro'        => 'M.E.S gros électroménager',
                    'assemblage'         => 'Assemblage rapide',
                    'hors_norme'         => 'Hors Norme',
                    'deballage'          => 'Déballage produit',
                    'evacuation'         => 'Evacuation Emballage',
                    'ancien_materiel'    => 'Reprise ancien matériel',
                    'piece_souhaitee'    => 'Livraison dans la pièce souhaitée',
                    'pas_de_porte'       => 'Livraison au pas de porte',
                ],
            ],
            // Attribution de prix
            [
                'title'    => __('Attribution de Prix', 'relais-colis-woocommerce'),
                'id'       => 'rc_b2c_pricing',
                'type'     => 'text',
                'default'  => '',
                'desc'     => __('Par défaut offert', 'relais-colis-woocommerce'),
            ],
            [
                'type' => 'sectionend',
                'id'   => 'rc_b2c_section_end',
            ],
            [
                'type' => 'sectionend',
                'id'   => 'rc_informations_section_end',
            ],
        ];

        return $settings;
    }

}

RC_Settings_Page::init();