/**
 * Meep AI - WP List Table (edit.php) Dynamic Translation & Word Calculation
 */

(function($) {
    'use strict';

    if (typeof aitrfoelListTranslator === 'undefined') {
        return;
    }

    var Config = aitrfoelListTranslator;
    var wordCounts = Config.word_counts || {};
    var dismissed = false;
    var isTranslating = false;
    var timerInterval = null;
    var startTime = 0;
    var activeJobId = null;

    function formatNumber(num) {
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function getSelectedPostIds() {
        var ids = [];
        $('tbody th.check-column input[type="checkbox"]:checked, input[name="post[]"]:checked').each(function() {
            var val = parseInt($(this).val(), 10);
            if (val && ids.indexOf(val) === -1) {
                ids.push(val);
            }
        });
        return ids;
    }

    function getSelectedTargetLanguages() {
        var langs = [];
        $('.aitrfoel-list-target-lang-cb:checked').each(function() {
            langs.push($(this).val());
        });
        return langs;
    }

    function updateLanguageButtonText() {
        var selectedLangs = getSelectedTargetLanguages();
        var totalLangs = $('.aitrfoel-list-target-lang-cb').length;
        var $btnText = $('#aitrfoel-list-lang-btn-text');

        if (selectedLangs.length === totalLangs) {
            $btnText.text(Config.strings.all_languages + ' (' + totalLangs + ')');
            $('#aitrfoel-list-lang-toggle-all').prop('checked', true).prop('indeterminate', false);
        } else if (selectedLangs.length === 0) {
            $btnText.text(Config.strings.select_target_langs);
            $('#aitrfoel-list-lang-toggle-all').prop('checked', false).prop('indeterminate', false);
        } else {
            $btnText.text(selectedLangs.length + ' ' + (selectedLangs.length === 1 ? 'language' : 'languages'));
            $('#aitrfoel-list-lang-toggle-all').prop('indeterminate', true);
        }
    }

    function isSessionHidden() {
        try {
            return sessionStorage.getItem('aitrfoel_list_bar_session_hidden') === '1';
        } catch (e) {
            return false;
        }
    }

    function isPermanentlyDisabled() {
        if (Config.toolbar_enabled === false) {
            return true;
        }
        try {
            return localStorage.getItem('aitrfoel_list_bar_disabled') === '1';
        } catch (e) {
            return false;
        }
    }

    function calculateDynamicWords() {
        var selectedIds = getSelectedPostIds();
        var selectedLangs = getSelectedTargetLanguages();
        var totalWords = 0;

        for (var i = 0; i < selectedIds.length; i++) {
            var id = selectedIds[i];
            totalWords += (wordCounts[id] || 0);
        }

        var totalNeeded = totalWords * selectedLangs.length;
        return {
            selectedCount: selectedIds.length,
            targetLangsCount: selectedLangs.length,
            baseWords: totalWords,
            totalNeeded: totalNeeded
        };
    }

    function updateToolbar() {
        if (isTranslating || isSessionHidden() || isPermanentlyDisabled()) return;

        var calc = calculateDynamicWords();
        var $bar = $('#aitrfoel-list-bar');

        if (calc.selectedCount === 0) {
            $bar.slideUp(150);
            dismissed = false;
            return;
        }

        // Update counts and words
        $('#aitrfoel-list-count').text(calc.selectedCount);
        $('#aitrfoel-list-words-num').text('~' + formatNumber(calc.totalNeeded));

        // Quota status pill
        var $quotaPill = $('#aitrfoel-list-quota-pill');
        if (Config.is_unlimited) {
            $quotaPill.removeClass('danger warning').addClass('success').text(Config.strings.unlimited_words);
        } else {
            var wordsLeft = Config.words_left;
            if (calc.totalNeeded > wordsLeft) {
                $quotaPill.removeClass('success').addClass('danger')
                    .text(Config.strings.quota_exceeded.replace('%s', formatNumber(wordsLeft)));
            } else {
                $quotaPill.removeClass('danger warning').addClass('success')
                    .text(Config.strings.words_remaining.replace('%s', formatNumber(wordsLeft)));
            }
        }

        // Translate button state
        var $btn = $('#aitrfoel-list-translate-btn');
        if (calc.selectedCount > 0 && calc.targetLangsCount > 0) {
            $btn.prop('disabled', false).removeClass('disabled');
        } else {
            $btn.prop('disabled', true).addClass('disabled');
        }

        if (!dismissed) {
            $bar.slideDown(150);
        }
    }

    function startTimer() {
        startTime = Date.now();
        var $timerVal = $('#aitrfoel-list-timer-val');
        if (timerInterval) clearInterval(timerInterval);

        timerInterval = setInterval(function() {
            var elapsedSec = Math.floor((Date.now() - startTime) / 1000);
            var m = Math.floor(elapsedSec / 60);
            var s = elapsedSec % 60;
            $timerVal.text(m + ':' + (s < 10 ? '0' : '') + s);
        }, 1000);
    }

    function stopTimer() {
        if (timerInterval) {
            clearInterval(timerInterval);
            timerInterval = null;
        }
    }

    function logProgress(msg, type) {
        type = type || 'info';
        var $log = $('#aitrfoel-list-log');
        $log.append('<div class="aitrfoel-list-log-entry ' + type + '">' + msg + '</div>');
        $log.scrollTop($log[0].scrollHeight);
    }

    function openModal() {
        isTranslating = true;
        $('#aitrfoel-list-modal-progress-view').show();
        $('#aitrfoel-list-modal-done-view').hide();
        $('#aitrfoel-list-modal-cancel').show().prop('disabled', false);
        $('#aitrfoel-list-modal-done').hide();
        $('#aitrfoel-list-log').empty();
        $('#aitrfoel-list-progress-fill').css('width', '5%');
        $('#aitrfoel-list-progress-pct').text('5%');
        $('#aitrfoel-list-progress-title').text(Config.strings.translating);
        $('#aitrfoel-list-current-item-text').text('Starting translation queue…');
        $('#aitrfoel-list-modal').fadeIn(200);
        startTimer();
    }

    function closeModal() {
        isTranslating = false;
        stopTimer();
        $('#aitrfoel-list-modal').fadeOut(200);
    }

    function executeTranslation() {
        var selectedIds = getSelectedPostIds();
        var selectedLangs = getSelectedTargetLanguages();

        if (selectedIds.length === 0 || selectedLangs.length === 0) {
            return;
        }

        openModal();
        logProgress('Selected ' + selectedIds.length + ' item(s) across ' + selectedLangs.length + ' language(s)…', 'info');

        $.ajax({
            url: Config.ajax_url,
            type: 'POST',
            data: {
                action: 'aitrfoel_start_bulk_translation',
                nonce: Config.nonce,
                pages: selectedIds,
                languages: selectedLangs,
                source_language: 'auto'
            },
            success: function(res) {
                if (res.success && res.data) {
                    activeJobId = res.data.job_id;
                    var items = res.data.items || [];
                    if (items.length > 0) {
                        processBatchItems(activeJobId, items, 0, []);
                    } else {
                        handleTranslationComplete([]);
                    }
                } else {
                    var errData = res.data || {};
                    if (errData.type === 'insufficient_words' || errData.type === 'limit_reached' || errData.type === 'cooldown') {
                        renderInModalUpgradeFlow(errData.message || 'Monthly word limit reached.');
                        return;
                    }
                    var errMsg = errData.message ? errData.message : Config.strings.error;
                    logProgress('❌ Error: ' + errMsg, 'error');
                    $('#aitrfoel-list-progress-title').text('Translation Failed');
                }
            },
            error: function(xhr, status, error) {
                logProgress('❌ Network Error: ' + error, 'error');
                $('#aitrfoel-list-progress-title').text('Network Error');
            }
        });
    }

    function processBatchItems(jobId, items, index, completedResults) {
        if (!isTranslating) return;

        if (index >= items.length) {
            handleTranslationComplete(completedResults);
            return;
        }

        var item = items[index];
        var itemNum = index + 1;
        var totalItems = items.length;
        var pct = Math.round((itemNum / totalItems) * 95);

        $('#aitrfoel-list-progress-fill').css('width', pct + '%');
        $('#aitrfoel-list-progress-pct').text(pct + '%');

        var itemTitle = item.title || ('Page #' + item.page_id);
        var rawLang = item.target_lang || item.lang || '';
        var targetLang = rawLang.toUpperCase();
        var statusMsg = Config.strings.translating_item.replace('%s', itemTitle).replace('%s', targetLang) + ' (' + itemNum + '/' + totalItems + ')';

        $('#aitrfoel-list-current-item-text').text(statusMsg);
        logProgress('Translating "' + itemTitle + '" to ' + targetLang + '…', 'info');

        $.ajax({
            url: Config.ajax_url,
            type: 'POST',
            data: {
                action: 'aitrfoel_process_bulk_item',
                nonce: Config.nonce,
                job_id: jobId,
                page_id: item.page_id,
                target_lang: rawLang,
                item_index: index,
                total_items: totalItems,
                completed_so_far: completedResults.length,
                source_language: item.source_lang || 'auto'
            },
            success: function(res) {
                if (res.success && res.data && res.data.status === 'limit_reached') {
                    renderInModalUpgradeFlow(res.data.message || 'Word limit reached during translation.');
                    return;
                }
                if (res.success && (!res.data || res.data.status !== 'item_error')) {
                    logProgress('✓ Finished "' + itemTitle + '" (' + targetLang + ')', 'success');
                    completedResults.push({
                        title: itemTitle,
                        lang: targetLang,
                        page_id: res.data ? (res.data.translated_id || res.data.new_page_id) : item.page_id,
                        edit_url: res.data ? res.data.edit_url : ''
                    });
                } else {
                    var err = (res.data && (res.data.message || res.data.error)) ? (res.data.message || res.data.error) : 'Error processing item';
                    logProgress('⚠ Warning on "' + itemTitle + '" (' + targetLang + '): ' + err, 'warning');
                }
                processBatchItems(jobId, items, index + 1, completedResults);
            },
            error: function(xhr, status, error) {
                logProgress('⚠ Item error on "' + itemTitle + '" (' + targetLang + '): ' + error, 'warning');
                processBatchItems(jobId, items, index + 1, completedResults);
            }
        });
    }

    function renderInModalUpgradeFlow(message) {
        stopTimer();
        isTranslating = false;
        $('#aitrfoel-list-progress-title').text('Monthly Word Limit Reached');
        $('#aitrfoel-list-current-item-text').text(message);
        $('#aitrfoel-list-modal-cancel').hide();
        $('#aitrfoel-list-modal-done').show().text('Close');

        var upgradeUrl = Config.upgrade_url || 'https://meeptech.com/products/ai-translator-for-elementor-polylang/?utm_source=wp-plugin&utm_medium=list-modal&utm_campaign=pro-upgrade#pricing';
        var topupUrl = Config.topup_url || 'https://meeptech.com/products/ai-translator-for-elementor-polylang/?utm_source=wp-plugin&utm_medium=list-modal&utm_campaign=extra-words#pricing';
        var bonusHtml = !Config.bonus_claimed ? (
            '<div id="aitrfoel-list-bonus-box" style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:10px 12px;margin-top:10px;">' +
                '<div style="font-size:12px;font-weight:700;color:#0369a1;margin-bottom:6px;">🎁 Or claim +2,500 Free Bonus Words instantly:</div>' +
                '<div class="aitrfoel-list-share-btns" style="display:flex;gap:8px;flex-wrap:wrap;">' +
                    '<button type="button" class="aitrfoel-list-share-btn" data-platform="x" style="background:#0f172a;color:#fff;border:none;border-radius:6px;padding:5px 10px;font-size:11.5px;font-weight:600;cursor:pointer;">𝕏 Share on X (+2,500)</button>' +
                    '<button type="button" class="aitrfoel-list-share-btn" data-platform="linkedin" style="background:#0a66c2;color:#fff;border:none;border-radius:6px;padding:5px 10px;font-size:11.5px;font-weight:600;cursor:pointer;">in Share on LinkedIn (+2,500)</button>' +
                '</div>' +
            '</div>'
        ) : '';

        var isPro = !!Config.is_pro;
        var cardHtml =
            '<div class="aitrfoel-list-upgrade-card" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 14px;margin-top:12px;">' +
                '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">' +
                    '<strong style="font-size:13px;color:#1e40af;">' + (isPro ? 'Instant Plan Upgrade & Top-Up' : 'Upgrade to Pro (50,000+ Words + Claude & DeepL)') + '</strong>' +
                    '<span style="background:#dbeafe;color:#1d4ed8;font-size:11px;font-weight:700;padding:2px 6px;border-radius:4px;font-family:monospace;">20% OFF: MEEP20</span>' +
                '</div>' +
                '<div style="display:flex;gap:8px;flex-wrap:wrap;">' +
                    '<a href="' + upgradeUrl + '" target="_blank" class="button button-primary" style="background:#1447e6;border-color:#1447e6;font-weight:600;">' + (isPro ? 'Upgrade Plan Tier →' : 'Upgrade to Starter ($9.99/mo) →') + '</a>' +
                    (isPro ? '<a href="' + topupUrl + '" target="_blank" class="button" style="border-color:#93c5fd;color:#1d4ed8;background:#fff;font-weight:600;">+50K Words Pack ($4.99)</a>' : '') +
                '</div>' +
                bonusHtml +
            '</div>';

        $('#aitrfoel-list-log').prepend(cardHtml);
        $('#aitrfoel-list-log').off('click', '.aitrfoel-list-share-btn').on('click', '.aitrfoel-list-share-btn', function(e) {
            e.preventDefault();
            var platform = $(this).data('platform') || 'x';
            var productUrl = 'https://meeptech.com/products/ai-translator-for-elementor-polylang';
            var shareText = "I'm using Meep AI Translator for WordPress to effortlessly translate Elementor pages with AI! Check it out:";
            var shareUrl = platform === 'linkedin'
                ? 'https://www.linkedin.com/sharing/share-offsite/?url=' + encodeURIComponent(productUrl)
                : 'https://twitter.com/intent/tweet?text=' + encodeURIComponent(shareText) + '&url=' + encodeURIComponent(productUrl) + '&hashtags=WordPress,AI,Elementor,Polylang';
            window.open(shareUrl, 'meep_share', 'width=600,height=450,toolbar=0,status=0');
            var $btns = $('#aitrfoel-list-bonus-box .aitrfoel-list-share-btns');
            $btns.html('<span style="color:#0284c7;font-weight:600;font-size:12px;">Claiming +2,500 bonus words...</span>');
            $.post(Config.ajax_url, {
                action: 'aitrfoel_claim_share_bonus',
                nonce: Config.nonce,
                platform: platform
            }, function(res) {
                if (res && res.success) {
                    Config.bonus_claimed = true;
                    $btns.html('<span style="color:#059669;font-weight:700;font-size:12px;">✓ +2,500 Bonus Words Unlocked! Refreshing...</span>');
                    setTimeout(function() { window.location.reload(); }, 1200);
                } else {
                    $btns.html('<span style="color:#dc2626;font-size:12px;">' + (res && res.data && res.data.message ? res.data.message : 'Could not claim bonus words.') + '</span>');
                }
            });
        });
    }

    function handleTranslationComplete(results) {
        stopTimer();
        $('#aitrfoel-list-progress-fill').css('width', '100%');
        $('#aitrfoel-list-progress-pct').text('100%');

        $('#aitrfoel-list-modal-progress-view').hide();
        $('#aitrfoel-list-modal-cancel').hide();
        $('#aitrfoel-list-modal-done').show();
        $('#aitrfoel-list-modal-done-view').show();

        $('#aitrfoel-list-done-summary').text(
            'Successfully processed ' + results.length + ' translation(s)!'
        );

        var $resultsList = $('#aitrfoel-list-results-list');
        $resultsList.empty();

        if (results.length > 0) {
            results.forEach(function(r) {
                var editBtn = r.edit_url ? '<a href="' + r.edit_url + '" class="button button-small" target="_blank">' + Config.strings.edit_page + '</a>' : '';
                $resultsList.append(
                    '<div class="aitrfoel-list-result-row">' +
                        '<div class="aitrfoel-list-result-info">' +
                            '<strong>' + r.title + '</strong> <span class="aitrfoel-lang-tag">' + r.lang + '</span>' +
                        '</div>' +
                        '<div class="aitrfoel-list-result-actions">' + editBtn + '</div>' +
                    '</div>'
                );
            });
        }
    }

    // Initialize Event Bindings
    $(document).ready(function() {
        updateLanguageButtonText();

        // Checkbox change listener
        $(document).on('change', 'tbody th.check-column input[type="checkbox"], input[name="post[]"], #cb-select-all-1, #cb-select-all-2', function() {
            dismissed = false; // reset temporary dismiss state when user makes new selection
            updateToolbar();
        });

        // Target language dropdown toggle
        $('#aitrfoel-list-lang-btn').on('click', function(e) {
            e.stopPropagation();
            $('#aitrfoel-list-lang-dropdown').toggle();
            $('#aitrfoel-list-dismiss-menu').hide();
        });

        // Click outside closes dropdowns
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.aitrfoel-list-lang-dropdown-wrap').length) {
                $('#aitrfoel-list-lang-dropdown').hide();
            }
            if (!$(e.target).closest('.aitrfoel-list-dismiss-wrap').length) {
                $('#aitrfoel-list-dismiss-menu').hide();
            }
        });

        // Toggle all languages
        $('#aitrfoel-list-lang-toggle-all').on('change', function() {
            var checked = $(this).is(':checked');
            $('.aitrfoel-list-target-lang-cb').prop('checked', checked);
            updateLanguageButtonText();
            updateToolbar();
        });

        // Single target language checkbox
        $(document).on('change', '.aitrfoel-list-target-lang-cb', function() {
            updateLanguageButtonText();
            updateToolbar();
        });

        // Clear / Deselect button
        $('#aitrfoel-list-clear').on('click', function(e) {
            e.preventDefault();
            $('tbody th.check-column input[type="checkbox"], input[name="post[]"], #cb-select-all-1, #cb-select-all-2').prop('checked', false);
            updateToolbar();
        });

        // Dismiss toggle button
        $('#aitrfoel-list-dismiss').on('click', function(e) {
            e.stopPropagation();
            $('#aitrfoel-list-lang-dropdown').hide();
            $('#aitrfoel-list-dismiss-menu').toggle();
        });

        // Hide for this session
        $('#aitrfoel-dismiss-session').on('click', function(e) {
            e.preventDefault();
            try {
                sessionStorage.setItem('aitrfoel_list_bar_session_hidden', '1');
            } catch (err) {}
            $('#aitrfoel-list-dismiss-menu').hide();
            $('#aitrfoel-list-bar').slideUp(150);
        });

        // Hide permanently (save to database)
        $('#aitrfoel-dismiss-forever').on('click', function(e) {
            e.preventDefault();
            try {
                localStorage.setItem('aitrfoel_list_bar_disabled', '1');
            } catch (err) {}
            $('#aitrfoel-list-dismiss-menu').hide();
            $('#aitrfoel-list-bar').slideUp(150);

            $.ajax({
                url: Config.ajax_url,
                type: 'POST',
                data: {
                    action: 'aitrfoel_save_toolbar_visibility',
                    nonce: Config.nonce,
                    enabled: 0
                }
            });
        });

        // Translate button click
        $('#aitrfoel-list-translate-btn').on('click', function(e) {
            e.preventDefault();
            executeTranslation();
        });

        // Modal cancel button
        $('#aitrfoel-list-modal-cancel').on('click', function() {
            if (confirm('Are you sure you want to stop the translation?')) {
                closeModal();
            }
        });

        // Modal done button
        $('#aitrfoel-list-modal-done').on('click', function() {
            closeModal();
            window.location.reload();
        });

        // Intercept native WordPress Bulk Actions dropdown
        $('#doaction, #doaction2').on('click', function(e) {
            var actionTop = $('#bulk-action-selector-top').val();
            var actionBottom = $('#bulk-action-selector-bottom').val();

            if (actionTop === 'aitrfoel_translate' || actionBottom === 'aitrfoel_translate') {
                e.preventDefault();
                var selected = getSelectedPostIds();
                if (selected.length === 0) {
                    alert('Please select at least one item to translate.');
                    return;
                }
                executeTranslation();
            }
        });
    });

})(jQuery);
