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

        // Custom fields for sections
        add_action( 'woocommerce_admin_field_rc_prestations', __CLASS__ . '::render_prestations' );
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
        } if ('prestations' === $current_section) {
            // Vérifiez si le formulaire des prestations est soumis
            if (isset($_POST['prestations']) && is_array($_POST['prestations'])) {
                $prestations = [];

                // Parcours des prestations soumises pour validation
                foreach ($_POST['prestations'] as $index => $prestation) {
                    $client = isset($prestation['client']) ? 1 : 0;
                    $produits = isset($prestation['produits']) && is_array($prestation['produits'])
                        ? array_map('sanitize_text_field', $prestation['produits'])
                        : [];
                    $livraison = isset($prestation['livraison']) && is_array($prestation['livraison'])
                        ? array_map('sanitize_text_field', $prestation['livraison'])
                        : [];
                    $actif = isset($prestation['actif']) ? 1 : 0;
                    $prix = isset($prestation['prix']) ? floatval($prestation['prix']) : 0;

                    // Validation des grilles tarifaires
                    $grilles = [];
                    if (isset($prestation['grilles']) && is_array($prestation['grilles'])) {
                        foreach ($prestation['grilles'] as $grille) {
                            $grilles[] = [
                                'min' => isset($grille['min']) ? intval($grille['min']) : 0,
                                'max' => isset($grille['max']) ? intval($grille['max']) : 0,
                                'prix' => isset($grille['prix']) ? floatval($grille['prix']) : 0,
                            ];
                        }
                    }

                    // Ajout de la prestation validée au tableau final
                    $prestations[] = [
                        'client' => $client,
                        'produits' => $produits,
                        'livraison' => $livraison,
                        'actif' => $actif,
                        'prix' => $prix,
                        'grilles' => $grilles,
                    ];
                }

                // Enregistrement des prestations dans l'option WordPress
                update_option('rc_prestations', $prestations);
            }
        }  elseif ( 'informations' === $current_section ) {
            woocommerce_update_options( self::get_informations_settings() );
        }
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
                'title' => __('Prestations et grilles tarifaires', 'relais-colis-woocommerce'),
                'type'  => 'title',
                'id'    => 'rc_prestations_section_title',
            ],
                [
                    'title' => __('Prestations', 'relais-colis-woocommerce'),
                    'type'  => 'rc_prestations',
                    'id'    => 'rc_prestations',
                ],
            [
                'type' => 'sectionend',
                'id'   => 'rc_prestations_section_end',
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
    }

    public static function render_prestations() {
        $prestations = get_option('rc_prestations', []);

        if (!is_array($prestations)) {
            $prestations = [];
        }

        echo '
        <table class="form-table">
        <tbody><tr class="">
        <td class="forminp forminp-text">
            <div id="rc-prestations-container">';

        // Bouton pour ajouter une prestation
        echo '<button id="add-prestation" type="button" class="button button-primary">' . __('Ajouter une prestation', 'relais-colis-woocommerce') . '</button>';

        // Tableau des prestations
        echo '<table id="prestations-table" class="widefat striped">
        <thead>
            <tr>
                <th>' . __('Client', 'relais-colis-woocommerce') . '</th>
                <th>' . __('Produit', 'relais-colis-woocommerce') . '</th>
                <th>' . __('Méthode de livraison', 'relais-colis-woocommerce') . '</th>
                <th>' . __('Actif', 'relais-colis-woocommerce') . '</th>
                <th>' . __('Prix', 'relais-colis-woocommerce') . '</th>
                <th>' . __('Grilles tarifaires', 'relais-colis-woocommerce') . '</th>
                <th>' . __('Action', 'relais-colis-woocommerce') . '</th>
            </tr>
        </thead>
        <tbody>';

        if (!empty($prestations)) {
            foreach ($prestations as $index => $prestation) {
                self::render_prestation_row($index, $prestation);
            }
        }

        echo '</tbody></table>';

        // Champ caché pour stocker les prestations au format JSON
        echo '<input type="hidden" name="prestations" id="prestations-data" value="' . esc_attr(json_encode($prestations)) . '">';
        echo '</div></td></tr></tbody></table>'; // Fin du conteneur

        // Scripts JavaScript pour la gestion dynamique
        echo '<script>
        jQuery(document).ready(function($) {
            let prestationIndex = $("#prestations-table tbody tr").length;

            // Ajouter une prestation
            $("#add-prestation").click(function() {
                let rowHTML = `' . self::generate_prestation_row_template($index) . '`.replace(/{{index}}/g, prestationIndex);
                $("#prestations-table > tbody").append(rowHTML);
                prestationIndex++;
            });

            // Supprimer une prestation
            $(document).on("click", ".remove-prestation", function() {
                $(this).closest("tr").remove();
            });

            // Ajouter une grille tarifaire
            $(document).on("click", ".add-grille", function(e) {
                e.preventDefault();
                let prestationRow = $(this).closest("tr");
                let grillesContainer = prestationRow.find(".grilles-container");
                let grilleIndex = grillesContainer.data("grilleIndex") || 0;
                let grilleType = prestationRow.find(".grille-type").val();

                let grilleHTML = `<div class="grille" data-grille-index="` + grilleIndex + `">
                    <strong>` + grilleType + `</strong>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th>' . __('Min', 'relais-colis-woocommerce') . '</th>
                                <th>' . __('Max', 'relais-colis-woocommerce') . '</th>
                                <th>' . __('Prix', 'relais-colis-woocommerce') . '</th>
                                <th>' . __('Action', 'relais-colis-woocommerce') . '</th>
                            </tr>
                        </thead>
                        <tbody class="grille-rows">
                            <tr>
                                <td><input type="number" class="grille-min" /></td>
                                <td><input type="number" class="grille-max" /></td>
                                <td><input type="number" class="grille-price" /></td>
                                <td><button class="button remove-grille-row">❌</button></td>
                            </tr>
                        </tbody>
                    </table>
                    <button type="button" class="button add-grille-row">' . __('Ajouter une ligne', 'relais-colis-woocommerce') . '</button>
                </div>`;
                grillesContainer.append(grilleHTML);
                grillesContainer.data("grilleIndex", grilleIndex + 1);
            });

            // Ajouter une ligne dans une grille
            $(document).on("click", ".add-grille-row", function(e) {
                e.preventDefault();
                let grilleRows = $(this).siblings("table").find(".grille-rows");
                let rowHTML = `<tr>
                    <td><input type="number" class="grille-min" /></td>
                    <td><input type="number" class="grille-max" /></td>
                    <td><input type="number" class="grille-price" /></td>
                    <td><button class="button remove-grille-row">❌</button></td>
                </tr>`;
                grilleRows.append(rowHTML);
            });

            // Supprimer une ligne de grille
            $(document).on("click", ".remove-grille-row", function() {
                $(this).closest("tr").remove();
            });
        });
    </script>';
    }

    private static function render_prestation_row($index, $prestation) {
        $client = isset($prestation['client']) && $prestation['client'] ? 'checked' : '';
        $produits = $prestation['produits'] ?? [];
        $livraison = $prestation['livraison'] ?? [];
        $actif = isset($prestation['actif']) && $prestation['actif'] ? 'checked' : '';
        $prix = esc_attr($prestation['prix'] ?? '');

        echo "<tr data-index='{$index}'>
        <td><input type='checkbox' name='prestations[{$index}][client]' value='1' {$client} /></td>
        <td>
            <select name='prestations[{$index}][produits][]' multiple>
                <option value='Produit A' " . (in_array('Produit A', $produits) ? 'selected' : '') . ">" . __('Produit A', 'relais-colis-woocommerce') . "</option>
                <option value='Produit B' " . (in_array('Produit B', $produits) ? 'selected' : '') . ">" . __('Produit B', 'relais-colis-woocommerce') . "</option>
                <option value='Produit C' " . (in_array('Produit C', $produits) ? 'selected' : '') . ">" . __('Produit C', 'relais-colis-woocommerce') . "</option>
            </select>
        </td>
        <td>
            <select name='prestations[{$index}][livraison][]' multiple>
                <option value='rendez_vous' " . (in_array('rendez_vous', $livraison) ? 'selected' : '') . ">" . __('Prise de rendez-vous', 'relais-colis-woocommerce') . "</option>
                <option value='etage' " . (in_array('etage', $livraison) ? 'selected' : '') . ">" . __('Livraison à l’étage', 'relais-colis-woocommerce') . "</option>
                <option value='a_deux' " . (in_array('a_deux', $livraison) ? 'selected' : '') . ">" . __('Livraison à deux', 'relais-colis-woocommerce') . "</option>
                <option value='mes_electro' " . (in_array('mes_electro', $livraison) ? 'selected' : '') . ">" . __('M.E.S gros électroménager', 'relais-colis-woocommerce') . "</option>
                <option value='assemblage' " . (in_array('assemblage', $livraison) ? 'selected' : '') . ">" . __('Assemblage rapide', 'relais-colis-woocommerce') . "</option>
                <option value='hors_norme' " . (in_array('hors_norme', $livraison) ? 'selected' : '') . ">" . __('Hors Norme', 'relais-colis-woocommerce') . "</option>
                <option value='deballage' " . (in_array('deballage', $livraison) ? 'selected' : '') . ">" . __('Déballage produit', 'relais-colis-woocommerce') . "</option>
                <option value='evacuation' " . (in_array('evacuation', $livraison) ? 'selected' : '') . ">" . __('Evacuation Emballage', 'relais-colis-woocommerce') . "</option>
                <option value='ancien_materiel' " . (in_array('ancien_materiel', $livraison) ? 'selected' : '') . ">" . __('Reprise ancien matériel', 'relais-colis-woocommerce') . "</option>
                <option value='piece_souhaitee' " . (in_array('piece_souhaitee', $livraison) ? 'selected' : '') . ">" . __('Livraison dans la pièce souhaitée', 'relais-colis-woocommerce') . "</option>
                <option value='pas_de_porte' " . (in_array('pas_de_porte', $livraison) ? 'selected' : '') . ">" . __('Livraison au pas de porte', 'relais-colis-woocommerce') . "</option>
            </select>
        </td>
        <td><input type='checkbox' name='prestations[{$index}][actif]' value='1' {$actif} /></td>
        <td><input type='text' name='prestations[{$index}][prix]' value='{$prix}' /></td>
        <td>
            <div class='grilles-container' data-grille-index='0'>
                <select class='grille-type'>
                    <option value='Critère : Poids'>" . __('Poids', 'relais-colis-woocommerce') . "</option>
                    <option value='Critère : Valeur commande'>" . __('Valeur commande', 'relais-colis-woocommerce') . "</option>
                </select>
                <button class='button add-grille'>" . __('Ajouter une grille', 'relais-colis-woocommerce') . "</button>
            </div>
        </td>
        <td><button class='button remove-prestation'>❌</button></td>
        </tr>";
    }



    private static function generate_prestation_row_template($index) {
        return '<tr data-index="{$index}">
        <td><input type="checkbox" name="prestations[new][client]" value="1" /></td>
        <td>
            <select name="prestations[new][produits][]" multiple>
                <option value="Produit A">' . __('Produit A', 'relais-colis-woocommerce') . '</option>
                <option value="Produit B">' . __('Produit B', 'relais-colis-woocommerce') . '</option>
                <option value="Produit C">' . __('Produit C', 'relais-colis-woocommerce') . '</option>
            </select>
        </td>
        <td>
            <select name="prestations[new][livraison][]" multiple>
                <option value="rendez_vous">' . __('Prise de rendez-vous', 'relais-colis-woocommerce') . '</option>
                <option value="etage">' . __('Livraison à l’étage', 'relais-colis-woocommerce') . '</option>
                <option value="a_deux">' . __('Livraison à deux', 'relais-colis-woocommerce') . '</option>
                <option value="mes_electro">' . __('M.E.S gros électroménager', 'relais-colis-woocommerce') . '</option>
                <option value="assemblage">' . __('Assemblage rapide', 'relais-colis-woocommerce') . '</option>
                <option value="hors_norme">' . __('Hors Norme', 'relais-colis-woocommerce') . '</option>
                <option value="deballage">' . __('Déballage produit', 'relais-colis-woocommerce') . '</option>
                <option value="evacuation">' . __('Evacuation Emballage', 'relais-colis-woocommerce') . '</option>
                <option value="ancien_materiel">' . __('Reprise ancien matériel', 'relais-colis-woocommerce') . '</option>
                <option value="piece_souhaitee">' . __('Livraison dans la pièce souhaitée', 'relais-colis-woocommerce') . '</option>
                <option value="pas_de_porte">' . __('Livraison au pas de porte', 'relais-colis-woocommerce') . '</option>
            </select>
        </td>
         <td><input type="checkbox" name="prestations[new][actif]" value="1" /></td>
        <td><input type="text" name="prestations[new][prix]" /></td>
        <td>
            <div class="grilles-container" data-grille-index="0">
                <select class="grille-type">
                    <option value="Critère : Poids">' . __('Poids', 'relais-colis-woocommerce') . '</option>
                    <option value="Critère : Valeur commande">' . __('Valeur commande', 'relais-colis-woocommerce') . '</option>
                </select>
                <button class="button add-grille">' . __('Ajouter une grille', 'relais-colis-woocommerce') . '</button>
            </div>
        </td>
        <td><button class="button remove-prestation">❌</button></td>
        </tr>';
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
                    url: rc_ajax.ajax_url,
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

}

RC_Settings_Page::init();