/**
 * AI Translator – Translation Metabox JS
 * Handles the translation widget in the post/page edit screen
 */
(function ($) {
    'use strict';

    if (typeof aitrfoelMetabox === 'undefined') {
        return;
    }

    var cfg = aitrfoelMetabox;
    var isTranslating = false;

    /**
     * Initialise event listeners
     */
    function init() {
        $('#aitrfoel-mb-translate-btn').on('click', startTranslation);

        // Highlight retranslate checkboxes
        $('#aitrfoel-mb-target-langs input[data-has-translation]').on('change', function () {
            if (this.checked) {
                $(this).closest('label').addClass('retranslate-selected');
            } else {
                $(this).closest('label').removeClass('retranslate-selected');
            }
        });
    }

    /**
     * Collect selected target languages
     */
    function getSelectedLanguages() {
        var langs = [];
        $('#aitrfoel-mb-target-langs input:checked').each(function () {
            langs.push($(this).val());
        });
        return langs;
    }

    /**
     * Show / hide the spinner and disable / enable button
     */
    function setLoading(loading) {
        isTranslating = loading;
        var $btn = $('#aitrfoel-mb-translate-btn');
        var $spinner = $('#aitrfoel-mb-spinner');

        if (loading) {
            $btn.prop('disabled', true).text(cfg.strings.translating);
            $spinner.addClass('is-active');
        } else {
            $btn.prop('disabled', false).html('🚀 ' + cfg.strings.translate);
            $spinner.removeClass('is-active');
        }
    }

    /**
     * Display a status message
     */
    function showMessage(text, type) {
        var $msg = $('#aitrfoel-mb-message');
        $msg.removeClass('success error info').addClass(type).html(text).slideDown(200);

        if (type === 'success') {
            setTimeout(function () {
                $msg.slideUp(200);
            }, 8000);
        }
    }

    /**
     * Update progress bar
     */
    function updateProgress(current, total, langCode) {
        var $progress = $('#aitrfoel-mb-progress');
        var $fill = $('#aitrfoel-mb-progress-fill');
        var $text = $('#aitrfoel-mb-progress-text');
        var pct = total > 0 ? Math.round((current / total) * 100) : 0;

        $progress.show();
        $fill.css('width', pct + '%');
        $text.text(current + '/' + total + (langCode ? ' – ' + langCode.toUpperCase() : ''));
    }

    /**
     * Start translating to all selected languages
     */
    function startTranslation() {
        if (isTranslating) return;

        var targetLangs = getSelectedLanguages();
        if (targetLangs.length === 0) {
            showMessage(cfg.strings.select_language, 'error');
            return;
        }

        // Confirm retranslations if applicable
        var hasRetranslate = false;
        targetLangs.forEach(function (lang) {
            if ($('#aitrfoel-mb-target-langs input[value="' + lang + '"][data-has-translation]').length) {
                hasRetranslate = true;
            }
        });

        if (hasRetranslate) {
            if (!confirm(cfg.strings.confirm_translate + '\n\n⚠️ Some languages already have translations that will be replaced.')) {
                return;
            }
        }

        setLoading(true);
        updateProgress(0, targetLangs.length, '');

        // Hide any previous message
        $('#aitrfoel-mb-message').hide();

        $.ajax({
            url: cfg.ajax_url,
            type: 'POST',
            data: {
                action: 'aitrfoel_metabox_translate',
                nonce: cfg.nonce,
                post_id: cfg.post_id,
                target_langs: targetLangs
            },
            timeout: 300000, // 5 minutes for multiple languages
            success: function (response) {
                setLoading(false);

                if (response.success) {
                    var data = response.data;
                    var completedCount = data.translations ? Object.keys(data.translations).length : 0;
                    var errorCount = data.errors ? Object.keys(data.errors).length : 0;

                    updateProgress(completedCount, targetLangs.length, '');

                    var html = '<strong>' + data.message + '</strong>';

                    // Show created translations
                    if (data.translations) {
                        html += '<ul class="aitrfoel-mb-results">';
                        $.each(data.translations, function (lang, info) {
                            html += '<li>';
                            html += '<span class="aitrfoel-mb-lang-flag">' + lang.toUpperCase() + '</span> ';
                            html += info.title + ' ';
                            html += '<a href="' + info.edit_url + '">' + cfg.strings.edit + '</a> ';
                            html += '<a href="' + info.view_url + '" target="_blank">' + cfg.strings.view + '</a>';
                            html += '</li>';
                        });
                        html += '</ul>';
                    }

                    // Show errors
                    if (errorCount > 0) {
                        html += '<div class="aitrfoel-mb-errors"><strong>Errors:</strong><ul>';
                        $.each(data.errors, function (lang, msg) {
                            html += '<li><span class="aitrfoel-mb-lang-flag">' + lang.toUpperCase() + '</span> ' + msg + '</li>';
                        });
                        html += '</ul></div>';
                    }

                    showMessage(html, errorCount > 0 ? 'info' : 'success');

                    // Upgrade nudge when free plan is nearly exhausted
                    if (data.upgrade_nudge) {
                        var nudge = data.upgrade_nudge;
                        var nudgeHtml = '<div class="aitrfoel-mb-nudge">' +
                            '<strong>⚡ ' + nudge.words_left + ' words left</strong> — ' +
                            '<a href="' + nudge.upgrade_url + '" target="_blank">Upgrade for more</a>' +
                            '</div>';
                        $('#aitrfoel-mb-message').append(nudgeHtml);
                    }

                    // Refresh translation status list
                    refreshTranslationStatus();
                } else {
                    var errMsg = response.data && response.data.message
                        ? response.data.message
                        : cfg.strings.translation_failed;
                    showMessage(errMsg, 'error');
                    $('#aitrfoel-mb-progress').hide();
                }
            },
            error: function (xhr, status, error) {
                setLoading(false);
                $('#aitrfoel-mb-progress').hide();

                var errMsg = cfg.strings.translation_failed;
                if (status === 'timeout') {
                    errMsg = 'Request timed out. Please try again.';
                } else if (error) {
                    errMsg += ' (' + error + ')';
                }
                showMessage(errMsg, 'error');
            }
        });
    }

    /**
     * Refresh the translations list via AJAX
     */
    function refreshTranslationStatus() {
        $.ajax({
            url: cfg.ajax_url,
            type: 'POST',
            data: {
                action: 'aitrfoel_metabox_status',
                nonce: cfg.nonce,
                post_id: cfg.post_id
            },
            success: function (response) {
                if (!response.success) return;

                var data = response.data;
                var $list = $('#aitrfoel-mb-translations-list');
                var html = '';

                $.each(data.languages, function (code, lang) {
                    var hasTranslation = lang.translated;
                    var itemClass = hasTranslation ? 'has-translation' : 'no-translation';

                    html += '<li class="aitrfoel-mb-lang-item ' + itemClass + '" data-lang="' + code + '">';
                    html += '<span class="aitrfoel-mb-lang-flag">' + code.toUpperCase() + '</span>';
                    html += '<span class="aitrfoel-mb-lang-name">' + lang.name + '</span>';

                    if (hasTranslation) {
                        var statusLabel = lang.status;
                        var statusMap = {
                            'publish': cfg.strings.published,
                            'draft': cfg.strings.draft,
                            'pending': cfg.strings.pending
                        };
                        statusLabel = statusMap[lang.status] || lang.status;

                        html += '<span class="aitrfoel-mb-status aitrfoel-mb-status-' + lang.status + '">' + statusLabel + '</span>';
                        html += '<span class="aitrfoel-mb-actions">';
                        if (lang.edit_url) {
                            html += '<a href="' + lang.edit_url + '" title="' + cfg.strings.edit + '"><span class="dashicons dashicons-edit"></span></a>';
                        }
                        if (lang.view_url) {
                            html += '<a href="' + lang.view_url + '" target="_blank" title="' + cfg.strings.view + '"><span class="dashicons dashicons-visibility"></span></a>';
                        }
                        html += '</span>';
                    } else {
                        html += '<span class="aitrfoel-mb-status aitrfoel-mb-status-missing">Missing</span>';
                    }

                    html += '</li>';
                });

                $list.html(html);

                // Also update checkboxes – mark newly translated ones
                $.each(data.languages, function (code, lang) {
                    var $cb = $('#aitrfoel-mb-target-langs input[value="' + code + '"]');
                    if (lang.translated) {
                        $cb.attr('data-has-translation', '1')
                           .closest('label').addClass('already-translated');
                        // Add retranslate hint if not present
                        if (!$cb.closest('label').find('.aitrfoel-mb-retranslate-hint').length) {
                            $cb.closest('label').append('<span class="aitrfoel-mb-retranslate-hint" title="Will create a new translation (overwrites existing)">⟳</span>');
                        }
                    }
                    $cb.prop('checked', false);
                    $cb.closest('label').removeClass('retranslate-selected');
                });
            }
        });
    }

    // Boot
    $(document).ready(init);

})(jQuery);
