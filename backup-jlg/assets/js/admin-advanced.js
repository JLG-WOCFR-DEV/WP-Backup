(function($) {
    'use strict';

    function parseLines(raw) {
        if (typeof raw !== 'string') {
            return [];
        }
        return raw.split(/\r?\n/).map(function(line) {
            return line.trim();
        }).filter(function(line) {
            return line !== '';
        });
    }

    function findInvalidPatterns(lines) {
        const invalid = [];
        const pattern = /^[A-Za-z0-9._\-\/\*]+$/;

        lines.forEach(function(line) {
            if (!pattern.test(line)) {
                invalid.push(line);
            }
        });

        return invalid;
    }

    function renderFeedback($feedback, invalidLines, totalLines) {
        if (!$feedback.length) {
            return;
        }

        if (!totalLines) {
            $feedback.text('').removeClass('is-error is-valid');
            return;
        }

        if (!invalidLines.length) {
            $feedback.text($feedback.data('successMessage') || 'Les motifs sont valides.').removeClass('is-error').addClass('is-valid');
            return;
        }

        const message = ($feedback.data('errorMessage') || 'Motifs non reconnus : ') + invalidLines.join(', ');
        $feedback.text(message).removeClass('is-valid').addClass('is-error');
    }

    function appendPattern($textarea, value) {
        if (!value) {
            return;
        }

        const existing = $textarea.val().toString();
        const lines = parseLines(existing);
        if (lines.indexOf(value) !== -1) {
            return;
        }

        const nextValue = existing.trim();
        $textarea.val(nextValue ? nextValue + '\n' + value : value);
        $textarea.trigger('input');
    }

    function setupPatternEditor($editor) {
        const $textarea = $editor.find('textarea[data-role="pattern-input"]');
        if (!$textarea.length) {
            return;
        }

        const patternType = ($textarea.data('patternType') || '').toString();
        const $feedback = $editor.closest('[data-role="advanced-panels"]').find('[data-role="pattern-feedback"][data-pattern-type="' + patternType + '"]');
        const $helper = $editor.find('[data-role="pattern-helper"]');
        const $suggestions = $editor.find('[data-role="pattern-suggestions"]');
        const $form = $editor.closest('form');

        const updateFeedback = function() {
            const lines = parseLines($textarea.val());
            const invalid = findInvalidPatterns(lines);
            renderFeedback($feedback, invalid, lines.length);

            if ($form.length) {
                $form.trigger('bjlg-backup-form-updated');
            }
        };

        $textarea.on('input blur', updateFeedback);

        if ($helper.length) {
            const $input = $helper.find('[data-role="pattern-autocomplete"]');
            const $addButton = $helper.find('[data-role="pattern-add"]');

            $addButton.on('click', function(event) {
                event.preventDefault();
                const value = ($input.val() || '').toString().trim();
                appendPattern($textarea, value);
                $input.val('');
            });

            $input.on('change', function() {
                const value = ($(this).val() || '').toString().trim();
                appendPattern($textarea, value);
                $(this).val('');
            });
        }

        if ($suggestions.length) {
            $suggestions.on('click', '[data-pattern-value]', function(event) {
                event.preventDefault();
                const value = ($(this).data('patternValue') || '').toString().trim();
                appendPattern($textarea, value);
            });
        }

        updateFeedback();
    }

    function initAdvancedPanels(context) {
        const $root = context ? $(context) : $(document);
        $root.find('[data-role="advanced-panels"]').each(function() {
            const $container = $(this);
            $container.find('.bjlg-pattern-editor').each(function() {
                const $editor = $(this);
                if ($editor.data('bjlgAdvancedBound')) {
                    return;
                }
                $editor.data('bjlgAdvancedBound', true);
                setupPatternEditor($editor);
            });
        });
    }

    $(document).on('bjlg-backup-step-activated', function(event, stepIndex) {
        if (parseInt(stepIndex, 10) === 2) {
            initAdvancedPanels($('#bjlg-backup-step-2'));
        }
    });

    $(function() {
        initAdvancedPanels($('#bjlg-backup-step-2'));
    });
})(jQuery);
