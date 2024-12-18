const RelaisColis = (($) => {
    'use strict';

    // Configuration
    const config = {
        selectors: {
            modeSwitch: 'input[name="rc_mode"]',
            apiKeyInput: '#rc_api_key',
            c2cKeyField: '.rc-c2c-field',
            c2cKeyInput: '#rc_c2c_key'
        },
        classes: {
            hidden: 'hidden',
            success: 'success',
            error: 'error',
            loading: 'loading'
        }
    };

    // Module Mode Switch
    const modeSwitch = {
        init() {
            const $switch = $(config.selectors.modeSwitch);
            this.setupSwitch($switch);
            this.bindEvents($switch);
        },

        setupSwitch($switch) {
            $switch
                .wrap('<label class="rc-switch"></label>')
                .after('<span class="rc-slider"></span><span class="rc-mode-label"></span>');
        },

        bindEvents($switch) {
            $switch.on('change', this.handleModeChange);
        },

        handleModeChange() {
            const $switch = $(this);
            const $label = $switch.siblings('.rc-mode-label');
            const isLive = $switch.is(':checked');

            $switch.prop('disabled', true);
            modeSwitch.updateLabel($label, isLive);
            modeSwitch.saveMode($switch, $label, isLive);
        },

        updateLabel($label, isLive) {
            $label.text(isLive ? 'Mode Production Activé' : 'Mode Test Activé');
        },

        saveMode($switch, $label, isLive) {
            ajaxHelper.post({
                action: 'rc_update_mode',
                mode: isLive ? 'live' : 'test',
                nonce: rcSettings.nonce
            }, {
                success: (response) => {
                    if (!response.success) {
                        this.handleError($switch, $label, isLive);
                    }
                },
                error: () => {
                    this.handleError($switch, $label, isLive);
                },
                complete: () => {
                    $switch.prop('disabled', false);
                }
            });
        },

        handleError($switch, $label, isLive) {
            $switch.prop('checked', !isLive);
            this.updateLabel($label, !isLive);
        }
    };

    // Module Key Handler
    const keyHandler = {
        init() {
            const $apiKeyInput = $(config.selectors.apiKeyInput);

            // Ajouter le bouton de vérification
            const $verifyButton = $('<button/>', {
                type: 'button',
                class: 'button',
                text: 'Vérifier la clé'
            });
            $apiKeyInput.after($verifyButton);

            // Ajouter les conteneurs pour les informations C2C
            const $infoContainer = $('<div/>', {
                class: 'rc-info hidden'
            }).insertAfter($verifyButton);

            $('<div/>', { class: 'rc-welcome' }).appendTo($infoContainer);
            $('<div/>', { class: 'rc-balance' }).appendTo($infoContainer);

            // Gérer les événements
            $verifyButton.on('click', () => this.handleVerification($apiKeyInput.val()));
        },

        handleVerification(key) {
            const $infoContainer = $('.rc-info');
            const $verifyButton = $('.button');

            console.log('Debug - Sending data:', {
                action: 'rc_verify_key',
                key: key,
                _ajax_nonce: rcSettings.nonce
            });

            // Masquer les informations précédentes
            $infoContainer.addClass(config.classes.hidden);
            $verifyButton.prop('disabled', true);

            ajaxHelper.post({
                action: 'rc_verify_key',
                key: key
            }, {
                success: (response) => {
                    console.log('Debug - Success response:', response);
                    if (response.success && response.data.type === 'C2C') {
                        this.displayC2CInfo(response.data);
                        $(config.selectors.c2cKeyField).removeClass(config.classes.hidden);
                    }
                },
                error: (xhr, status, error) => {
                    console.error('Debug - Error details:', {
                        status: status,
                        error: error,
                        response: xhr.responseText,
                        xhr: xhr
                    });
                    $verifyButton.prop('disabled', false);
                },
                complete: () => {
                    $verifyButton.prop('disabled', false);
                }
            });
        },

        displayC2CInfo(data) {
            const $info = $('.rc-info');
            $info.removeClass(config.classes.hidden);

            $('.rc-welcome').html(`<strong>Bonjour ${data.firstName} ${data.lastName}</strong>`);
            $('.rc-balance').html(`Solde du compte : ${data.balance.toFixed(2)} €`);

            // Ajouter le bouton de rafraîchissement
            if (!$('.rc-refresh-balance').length) {
                const $refreshButton = $('<button/>', {
                    type: 'button',
                    class: 'button rc-refresh-balance',
                    text: 'Rafraîchir le solde'
                });
                $('.rc-balance').after($refreshButton);

                $refreshButton.on('click', this.refreshBalance);
            }
        },

        refreshBalance() {
            ajaxHelper.post({
                action: 'rc_refresh_balance',
                nonce: rcSettings.nonce
            }, {
                success: (response) => {
                    if (response.success) {
                        $('.rc-balance').html(`Solde du compte : ${response.data.balance.toFixed(2)} €`);
                    }
                }
            });
        }
    };

    // AJAX Helper
    const ajaxHelper = {
        post(data, callbacks) {
            $.ajax({
                url: rcSettings.ajaxurl,
                type: 'POST',
                data: {
                    _ajax_nonce: rcSettings.nonce,  // Ajout du préfixe _ajax_
                    ...data
                },
                dataType: 'json',  // Spécifier le type de données attendu
                ...callbacks
            });
        }
    };

    // Public API
    return {
        init() {
            modeSwitch.init();
            keyHandler.init();
        }
    };
})(jQuery);

// Initialisation
jQuery(document).ready(() => {
    RelaisColis.init();
});