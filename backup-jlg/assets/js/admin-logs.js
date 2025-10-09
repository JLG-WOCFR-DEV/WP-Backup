jQuery(function($) {
    'use strict';

// --- GESTIONNAIRE DU PACK DE SUPPORT ---
$('#bjlg-generate-support-package').on('click', function(e) {
    e.preventDefault();

    const $button = $(this);
    const $statusArea = $('#bjlg-support-package-status');

    $button.prop('disabled', true).attr('aria-busy', 'true');

    if ($statusArea.length) {
        $statusArea.removeClass('bjlg-status-error bjlg-status-success');
        $statusArea.text('Génération du pack de support en cours...');
    }

    $.ajax({
        url: bjlg_ajax.ajax_url,
        type: 'POST',
        dataType: 'json',
        data: {
            action: 'bjlg_generate_support_package',
            nonce: bjlg_ajax.nonce
        }
    })
    .done(function(response) {
        const data = response && response.data ? response.data : null;

        if (response && response.success && data && data.download_url) {
            const details = [];

            if (data.filename) {
                details.push('Fichier : ' + data.filename);
            }

            if (data.size) {
                details.push('Taille : ' + data.size);
            }

            if ($statusArea.length) {
                const message = data.message || 'Pack de support généré avec succès.';
                const suffix = details.length ? ' (' + details.join(' • ') + ')' : '';
                $statusArea.text(message + suffix);
            }

            const link = document.createElement('a');
            link.href = data.download_url;

            if (data.filename) {
                link.download = data.filename;
            }

            link.style.display = 'none';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        } else {
            const errorMessage = data && data.message
                ? data.message
                : 'Réponse inattendue du serveur.';

            if ($statusArea.length) {
                $statusArea.text('Erreur : ' + errorMessage);
            }
        }
    })
    .fail(function(xhr) {
        let errorMessage = 'Erreur de communication avec le serveur.';

        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
            errorMessage = xhr.responseJSON.data.message;
        } else if (xhr && xhr.responseText) {
            errorMessage = xhr.responseText;
        }

        if ($statusArea.length) {
            $statusArea.text('Erreur : ' + errorMessage);
        }
    })
    .always(function() {
        $button.prop('disabled', false).removeAttr('aria-busy');
    });
});
});
