function validateRelaisColisAPIKey() {
    var apiKeyInputs = [
        jQuery('#woocommerce_rc_settings_rc_api_key'),
        jQuery('input[name="woocommerce_rc_settings_rc_api_key"]'),
        jQuery('input[id$="rc_api_key"]')
    ];

    var apiKey = '';
    apiKeyInputs.forEach(function (input) {
        if (input.length && input.val().trim()) {
            apiKey = input.val().trim();
            console.log('Found API Key Input:', input, 'Value:', apiKey);
        }
    });

    // Validate input is not empty
    if (!apiKey) {
        alert('Veuillez saisir une clé API');
        return;
    }

    // Find nonce
    var nonce = jQuery('#_wpnonce').val() ||
        jQuery('input[name="_wpnonce"]').val() ||
        jQuery('input[name="rc_api_key_nonce"]').val();

    console.log('Attempting API Validation:', {
        apiKey: apiKey,
        nonce: nonce,
        ajaxUrl: ajaxurl
    });

    // Disable validation button
    var validationButton = jQuery('.rc-validate-api-key');
    validationButton.prop('disabled', true).text('Validation en cours...');

    // Log nonce for debugging
    console.log('Nonce:', nonce);

    jQuery.ajax({
        url: ajaxurl,
        type: 'POST',
        data: {
            action: 'validate_rc_api_key',
            api_key: apiKey,
            security: nonce
        },
        success: function (response) {
            console.log('AJAX Success Response:', response);

            if (response.success) {
                var alertHtml =
                    '<div class="notice notice-success is-dismissible">' +
                    '<p><strong>✓ Validation réussie !</strong></p>' +
                    '<p>' + (response.data.message || 'Clé API validée') + '</p>' +
                    '</div>';

                jQuery('.rc-validation-message').html(alertHtml);
                validationButton.text('Clé API Validée').addClass('button-success');
            } else {
                var alertHtml =
                    '<div class="notice notice-error is-dismissible">' +
                    '<p><strong>✗ Erreur de validation</strong></p>' +
                    '<p>' + (response.data.message || 'Validation échouée') + '</p>' +
                    '</div>';

                jQuery('.rc-validation-message').html(alertHtml);
                validationButton.text('Valider la clé API').removeClass('button-success');
            }
        },
        error: function (xhr, status, error) {
            console.error('AJAX Error Details:', {
                status: status,
                error: error,
                responseText: xhr.responseText
            });

            var errorMessage = 'Erreur de communication avec le serveur';

            if (xhr.responseJSON && xhr.responseJSON.data) {
                errorMessage = xhr.responseJSON.data.message || errorMessage;
            }

            var alertHtml =
                '<div class="notice notice-error is-dismissible">' +
                '<p><strong>✗ Erreur</strong></p>' +
                '<p>' + errorMessage + '</p>' +
                '</div>';

            jQuery('.rc-validation-message').html(alertHtml);
            validationButton.text('Valider la clé API').prop('disabled', false);
        },
        complete: function () {
            validationButton.prop('disabled', false);
        }
    });
}

// Add validation message container
jQuery(document).ready(function ($) {
    $('.rc-validate-api-key').each(function () {
        if ($(this).next('.rc-validation-message').length === 0) {
            $(this).after('<div class="rc-validation-message"></div>');
        }
    });
});