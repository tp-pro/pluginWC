<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class RC_Settings_Page {

    public static function init() {
        add_filter( 'woocommerce_settings_tabs_array', __CLASS__ . '::add_settings_tab', 50 );
        add_action( 'woocommerce_settings_tabs_rc_settings', __CLASS__ . '::settings_tab' );
        add_action( 'woocommerce_update_options_rc_settings', __CLASS__ . '::update_settings' );
        add_action( 'woocommerce_admin_field_rc_grille_tarifaire', __CLASS__ . '::render_grille_tarifaire');
        add_action( 'woocommerce_admin_field_rc_offres_livraison', __CLASS__ . '::render_offres_livraison');

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
        woocommerce_admin_fields( self::get_settings() );
    }


    public static function update_settings() {
        // Sauvegarde de la grille tarifaire
        if (isset($_POST['critere'], $_POST['min'], $_POST['max'], $_POST['price'])) {
            $table_data = [];
            foreach ($_POST['critere'] as $index => $critere) {
                $table_data[] = [
                    'critere' => sanitize_text_field($critere),
                    'min'      => sanitize_text_field($_POST['min'][$index]),
                    'max'      => sanitize_text_field($_POST['max'][$index]),
                    'price'    => sanitize_text_field($_POST['price'][$index]),
                ];
            }
            update_option('rc_grille_tarifaire', wp_json_encode($table_data));
        }
        // Sauvegarde des offres de livraison
        if (isset($_POST['delivery_method'], $_POST['free_shipping_threshold'])) {
            $offers_data = [];
            foreach ($_POST['delivery_method'] as $index => $method) {
                if (!empty($method)) { // Assurez-vous que la méthode est définie
                    $offers_data[] = [
                        'method'    => sanitize_text_field($method),
                        'threshold' => sanitize_text_field($_POST['free_shipping_threshold'][$index]),
                    ];
                }
            }
            update_option('rc_offres_livraison', wp_json_encode($offers_data));
        }
        // Sauvegarde des autres paramètres
        woocommerce_update_options(self::get_settings());
    }


    public static function render_grille_tarifaire($section) {
        $saved_table = get_option('rc_grille_tarifaire', '[]');
        echo '<table class="form-table"><tbody>
                <tr>
                <th scope="row" class="titledesc">'.$section['title'].'</th>
                <td class="forminp forminp-checkbox ">
         ';
        echo '<div id="rc-pricing-table-editor">';
        echo '<table>';
        echo '<thead><tr><th>Critère</th><th>Min</th><th>Max</th><th>Prix (€)</th><th>Actions</th></tr></thead>';
        echo '<tbody id="rc-pricing-table-rows">';
        foreach (json_decode($saved_table, true) as $row) {
            echo '<tr>';
            echo '<td>
                    <select name="critere[]">
                        <option value="weight"' . selected($row['critere'], 'weight', false) . '>Poids</option>
                        <option value="price"' . selected($row['critere'], 'price', false) . '>Valeur Totale</option>
                    </select>
                  </td>';
            echo '<td><input type="number" name="min[]" value="' . esc_attr($row['min']) . '" step="0.01"></td>';
            echo '<td><input type="number" name="max[]" value="' . esc_attr($row['max']) . '" step="0.01"></td>';
            echo '<td><input type="number" name="price[]" value="' . esc_attr($row['price']) . '" step="0.01"></td>';
            echo '<td><button type="button" class="remove-row">❌</button></td>';
            echo '</tr>';
        }
        echo '</tbody>';
        echo '</table>';
        echo '<button type="button" id="add-pricing-row" class="button button-secondary">' . __('Ajouter un tarif', 'relais-colis-woocommerce') . '</button>';
        echo '</div>';
        echo '<script>
            jQuery(document).ready(function($) {
                $("#add-pricing-row").click(function() {
                    $("#rc-pricing-table-rows").append(`<tr>
                        <td>
                            <select name="critere[]">
                                <option value="weight">Poids</option>
                                <option value="price">Valeur Totale</option>
                            </select>
                        </td>
                        <td><input type="number" name="min[]" value="" step="0.01"></td>
                        <td><input type="number" name="max[]" value="" step="0.01"></td>
                        <td><input type="number" name="price[]" value="" step="0.01"></td>
                        <td><button type="button" class="remove-row">Supprimer</button></td>
                    </tr>`);
                });
                $(document).on("click", ".remove-row", function() {
                    $(this).closest("tr").remove();
                });
            });
        </script>';
        echo '</td></tr></tbody></table>';
    }


    public static function render_offres_livraison($section) {
        $saved_offers = get_option('rc_offres_livraison', '[]');
        $saved_offers = json_decode($saved_offers, true);

        // En-tête de la section
        echo '<table class="form-table"><tbody>
            <tr>
                <th scope="row" class="titledesc">' . esc_html($section['title']) . '</th>
                <td class="forminp">';

        // Conteneur principal
        echo '<div id="rc-offers-editor">';
        echo '<table>';
        echo '<thead>
            <tr>
                <th>' . __('Méthode de livraison', 'relais-colis-woocommerce') . '</th>
                <th>' . __('Prix Seuil de Gratuité', 'relais-colis-woocommerce') . '</th>
                <th>' . __('Actions', 'relais-colis-woocommerce') . '</th>
            </tr>
          </thead>';
        echo '<tbody id="rc-offers-rows">';

        // Lignes sauvegardées
        if (!empty($saved_offers)) {
            foreach ($saved_offers as $offer) {
                echo '<tr>';
                echo '<td>
                    <select name="delivery_method[]">
                        <option value="relais"' . selected($offer['method'], 'relais', false) . '>Relais</option>
                        <option value="domicile"' . selected($offer['method'], 'domicile', false) . '>Domicile</option>
                    </select>
                  </td>';
                echo '<td><input type="number" name="free_shipping_threshold[]" value="' . esc_attr($offer['threshold']) . '" step="0.01" placeholder="Prix en €"></td>';
                echo '<td><button type="button" class="remove-offer-row">❌</button></td>';
                echo '</tr>';
            }
        }

        echo '</tbody>';
        echo '</table>';
        echo '<button type="button" id="add-offer-row" class="button button-secondary">' . __('Ajouter une offre', 'relais-colis-woocommerce') . '</button>';
        echo '</div>';

        // Script JavaScript pour ajouter et supprimer dynamiquement des lignes
        echo '<script>
        jQuery(document).ready(function($) {
            // Ajouter une nouvelle ligne
            $("#add-offer-row").click(function() {
                $("#rc-offers-rows").append(`<tr>
                    <td>
                        <select name="delivery_method[]">
                            <option value="relais">Relais</option>
                            <option value="domicile">Domicile</option>
                        </select>
                    </td>
                    <td><input type="number" name="free_shipping_threshold[]" step="0.01" placeholder="Prix en €"></td>
                    <td><button type="button" class="remove-offer-row">❌</button></td>
                </tr>`);
            });

            // Supprimer une ligne existante
            $(document).on("click", ".remove-offer-row", function() {
                $(this).closest("tr").remove();
            });
        });
    </script>';

        echo '</td></tr></tbody></table>';
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
                            let infoHtml = `<strong>Nom :</strong> ${data.nom}<br>
                                            <strong>Prénom :</strong> ${data.prenom}<br>
                                            <strong>Solde :</strong> ${data.solde}`;
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


    public static function get_settings() {
        $settings = [

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
                    'type'     => 'radio',
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

            // Section : Tarification
            [
                'title' => __('Tarification', 'relais-colis-woocommerce'),
                'type'  => 'title',
                'id'    => 'rc_tarification_title',
            ],

                // Offre de livraison
                [
                    'title' => __('Offres de livraison', 'relais-colis-woocommerce'),
                    'type' => 'rc_offres_livraison',
                    'desc'  => __('Ajoutez des offres avec un seuil de gratuité.', 'relais-colis-woocommerce'),
                    'id'   => 'rc_offres_livraison',
                ],

                // Grille tarifaire
                [
                    'title' => __('Grille Tarifaire', 'relais-colis-woocommerce'),
                    'type' => 'rc_grille_tarifaire',
                    'desc'  => __('Définissez des tranches tarifaires basées sur le poids ou la valeur totale.', 'relais-colis-woocommerce'),
                    'id'   => 'rc_grille_tarifaire',
                ],

            [
                'type' => 'sectionend',
                'id'   => 'rc_tarification_section_end',
            ],

            // Section : Vos informations
            [
                'title' => __('Vos informations', 'relais-colis-woocommerce'),
                'type'  => 'title',
                'desc'  => __('Entrez votre clé d’activation pour synchroniser vos informations.', 'relais-colis-woocommerce'),
                'id'    => 'rc_informations_title',
            ],

                // Type de clé API
                [
                    'title'    => __('Type de clé d’activation', 'relais-colis-woocommerce'),
                    'id'       => 'rc_api_key_type',
                    'type'     => 'radio',
                    'options'  => [
                        'B2C' => __('B2C', 'relais-colis-woocommerce'),
                        'C2C' => __('C2C', 'relais-colis-woocommerce'),
                    ],
                    'default'  => 'B2C',
                    'desc_tip' => __('Type de clé d’activation B2C ou C2C.', 'relais-colis-woocommerce'),
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
