/**
 * Translate All – Admin Page JavaScript
 */
(function ($) {
    'use strict';

    var TA = window.aitrfoel_translate_all || {};

    // Ensure same-origin AJAX URL even if accessed on a custom port/host (e.g. LocalWP, reverse proxies)
    if (TA.ajax_url && window.location && window.location.origin) {
        try {
            var parsedUrl = new URL(TA.ajax_url, window.location.origin);
            if (parsedUrl.hostname === window.location.hostname && parsedUrl.port !== window.location.port) {
                parsedUrl.port = window.location.port;
                parsedUrl.protocol = window.location.protocol;
                TA.ajax_url = parsedUrl.toString();
            }
        } catch (e) {}
    }

    var state = {
        scanData: null,
        jobId: null,
        mode: null,
        polling: null,
        loggedResults: 0,
        loggedErrors: 0,
        done: false,
        paused: false,
        pollXhr: null,
    };

    function hasPhaseTwo() {
        return !!(TA.builtin_active && state.scanData && Array.isArray(state.scanData.target_languages) && state.scanData.target_languages.length);
    }

    function phasePrefix(phase) {
        if (!hasPhaseTwo()) {
            return 'Phase 1/1';
        }
        return phase === 2 ? 'Phase 2/2' : 'Phase 1/2';
    }

    /* ── Helpers ─────────────────────────────────────── */

    function formatNumber(n) {
        return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function setProgressState(status, titleText, detailText) {
        if (status === 'in_progress') {
            $('#ta-progress-spinner, #ta-progress-area .spinner').addClass('is-active').show();
            $('#ta-progress-title').text(titleText || 'Translation in Progress');
        } else if (status === 'completed') {
            $('#ta-progress-spinner, #ta-progress-area .spinner').removeClass('is-active').hide();
            $('#ta-progress-title').text('✅ ' + (titleText || TA.strings.completed || 'Translation Completed'));
            $('#ta-progress-fill').css('width', '100%');
            $('#ta-progress-text').text('100%');
            hideProgressButtons();
            resetActions();
        } else if (status === 'cancelled') {
            $('#ta-progress-spinner, #ta-progress-area .spinner').removeClass('is-active').hide();
            $('#ta-progress-title').text('⏸ ' + (titleText || TA.strings.cancelled || 'Translation Cancelled'));
            hideProgressButtons();
            resetActions();
        } else if (status === 'failed') {
            $('#ta-progress-spinner, #ta-progress-area .spinner').removeClass('is-active').hide();
            $('#ta-progress-title').text('❌ ' + (titleText || TA.strings.error || 'Translation Failed'));
            hideProgressButtons();
            resetActions();
        }
        if (detailText) {
            $('#ta-progress-detail').text(detailText);
        }
    }

    function getSelectedPostTypes() {
        var types = [];
        $('input[name="post_types[]"]:checked').each(function () {
            types.push($(this).val());
        });
        return types;
    }

    function getSelectedLanguages() {
        var langs = [];
        $('input[name="target_languages[]"]:checked').each(function () {
            langs.push($(this).val());
        });
        return langs;
    }

    function addLogEntry(text, type) {
        type = type || 'info';
        var $log = $('#ta-progress-log');
        $log.append('<div class="log-entry ' + type + '">' + text + '</div>');
        $log.scrollTop($log[0].scrollHeight);
    }

    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function editorBadge(type) {
        var labels = { elementor: 'Elementor', gutenberg: 'Gutenberg', classic: 'Classic' };
        var classes = { elementor: 'aitrfoel-ta-badge-elementor', gutenberg: 'aitrfoel-ta-badge-gutenberg', classic: 'aitrfoel-ta-badge-classic' };
        var t = (type || 'classic').toLowerCase();
        return '<span class="aitrfoel-ta-badge ' + (classes[t] || 'aitrfoel-ta-badge-classic') + '">' +
            (labels[t] || type) + '</span>';
    }

    /* ── Scan ────────────────────────────────────────── */

    function scanContent() {
        var postTypes = getSelectedPostTypes();
        var languages = getSelectedLanguages();

        if (postTypes.length === 0) {
            alert(TA.strings.select_post_type);
            return;
        }
        if (languages.length === 0) {
            alert(TA.strings.select_language);
            return;
        }

        $('#ta-scan-btn').prop('disabled', true).text(TA.strings.scanning);
        $('#ta-scan-spinner').addClass('is-active');
        $('#ta-scan-results').hide();
        $('#ta-step-4').hide();

        $.ajax({
            url: TA.ajax_url,
            type: 'POST',
            data: {
                action: 'aitrfoel_translate_all_scan',
                nonce: TA.nonce,
                post_types: postTypes,
                target_languages: languages,
            },
            success: function (response) {
                $('#ta-scan-btn').prop('disabled', false).text('🔍 ' + TA.strings.scan_complete);
                $('#ta-scan-spinner').removeClass('is-active');

                if (!response.success) {
                    alert(response.data || TA.strings.error);
                    return;
                }

                state.scanData = response.data;
                renderScanResults(response.data);
            },
            error: function () {
                $('#ta-scan-btn').prop('disabled', false).html('<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg> <span>' + escHtml(TA.strings.scan_content || 'Scan Content') + '</span>');
                $('#ta-scan-spinner').removeClass('is-active');
                alert(TA.strings.error);
            },
        });
    }

    /* ── Render Scan Results ─────────────────────────── */

    function renderScanResults(data) {
        var $body = $('#ta-results-body');
        $body.empty();

        if (!data.results || data.results.length === 0) {
            $body.append(
                '<tr><td colspan="5">' + escHtml(TA.strings.no_content) + '</td></tr>'
            );
            $('#ta-scan-results').show();
            return;
        }

        // Summary table
        data.results.forEach(function (r) {
            $body.append(
                '<tr>' +
                    '<td><strong>' + escHtml(r.label) + '</strong></td>' +
                    '<td>' + formatNumber(r.count) + '</td>' +
                    '<td>' + formatNumber(r.total_words) + '</td>' +
                    '<td><span class="aitrfoel-ta-count-done">' + formatNumber(r.already_translated) + '</span></td>' +
                    '<td><span class="aitrfoel-ta-count-pending">' + formatNumber(r.needs_translation) + '</span></td>' +
                '</tr>'
            );
        });

        // Footer totals
        $('#ta-results-footer').html(
            '<tr>' +
                '<td><strong>Total</strong></td>' +
                '<td><strong>' + formatNumber(data.total_items) + '</strong></td>' +
                '<td><strong>' + formatNumber(data.total_words) + '</strong></td>' +
                '<td><strong><span class="aitrfoel-ta-count-done">' + formatNumber(data.total_already) + '</span></strong></td>' +
                '<td><strong><span class="aitrfoel-ta-count-pending">' + formatNumber(data.total_needs) + '</span></strong></td>' +
            '</tr>'
        );

        // Per-page detail table
        if (data.posts_detail && data.posts_detail.length > 0) {
            renderPostsDetail(data.posts_detail);
        }

        $('#ta-scan-results').show();
        buildRecommendation(data);
    }

    /* ── Render Per-Page Detail ──────────────────────── */

    function renderPostsDetail(posts) {
        var $body = $('#ta-detail-body');
        $body.empty();

        posts.forEach(function (p) {
            var statusHtml = '';
            if (p.is_fully_translated) {
                statusHtml = '<span class="aitrfoel-ta-status-done" style="display:inline-flex;align-items:center;gap:4px;"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg> Fully Translated</span> ';
                if (p.translated_langs.length > 0) {
                    statusHtml += p.translated_langs.map(function (l) { return '<span class="aitrfoel-ta-lang-tag done">' + escHtml(l) + '</span>'; }).join(' ');
                }
            } else {
                statusHtml = '<span class="aitrfoel-ta-status-pending" style="display:inline-flex;align-items:center;gap:4px;"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Needs Translation</span> ';
                if (p.translated_langs.length > 0) {
                    statusHtml += p.translated_langs.map(function (l) { return '<span class="aitrfoel-ta-lang-tag done">' + escHtml(l) + '</span>'; }).join(' ') + ' ';
                }
                if (p.missing_langs.length > 0) {
                    statusHtml += p.missing_langs.map(function (l) { return '<span class="aitrfoel-ta-lang-tag missing">' + escHtml(l) + '</span>'; }).join(' ');
                }
            }

            // Empty drafts (no content and no real title) are listed but not pre-selected.
            var isEmptyPage = !(parseInt(p.word_count, 10) > 0) && (!p.title || /^\(no title\)/i.test(String(p.title).trim()));
            var titleLink = '<a href="' + escHtml(p.edit_url || '#') + '" target="_blank" title="Edit">' + escHtml(p.title) + '</a>';
            if (p.view_url) {
                titleLink += ' <a href="' + escHtml(p.view_url) + '" target="_blank" class="aitrfoel-ta-view-link" title="View page">↗</a>';
            }

            var filterClass = p.is_fully_translated ? 'ta-row-done' : 'ta-row-needs';
            var needsTranslation = !p.is_fully_translated;
            var usageFactor = parseInt(TA.server_usage_factor, 10) || 1;
            var wordsNeededForPost = p.word_count * (p.missing_langs ? p.missing_langs.length : 0) * usageFactor;

            var langBadge = p.post_lang ? '<span class="aitrfoel-lang-flag aitrfoel-lang-exists">' + escHtml(p.post_lang) + '</span>' : '';

            var typeBadge = function(type) {
                var map = {
                    'page': 'Page',
                    'post': 'Post',
                    'product': 'Product',
                    'elementor_library': 'Elementor Template',
                    'elementor-hf': 'Header & Footer',
                    'jet-theme-core': 'JetThemeCore',
                    'jet-engine': 'JetEngine Listing',
                    'sureforms_form': 'SureForms',
                    'jet-form-builder': 'JetFormBuilder',
                    'page-generator-pro': 'Page Generator Pro'
                };
                return map[type] || (type ? type.charAt(0).toUpperCase() + type.slice(1).replace(/[-_]/g, ' ') : 'Page');
            };

            $body.append(
                '<tr class="' + filterClass + '" data-post-id="' + p.post_id + '" data-words="' + p.word_count + '" data-words-needed="' + wordsNeededForPost + '" data-needs="' + (needsTranslation ? '1' : '0') + '">' +
                    '<td><input type="checkbox" class="ta-page-check" value="' + p.post_id + '"' + ((needsTranslation && !isEmptyPage) ? ' checked' : '') + ' /></td>' +
                    '<td>' + titleLink + '</td>' +
                    '<td>' + langBadge + '</td>' +
                    '<td><span class="aitrfoel-ta-type-pill">' + escHtml(typeBadge(p.post_type)) + '</span></td>' +
                    '<td>' + formatNumber(p.word_count) + '</td>' +
                    '<td>' + editorBadge(p.content_type) + '</td>' +
                    '<td>' + statusHtml + '</td>' +
                '</tr>'
            );
        });

        $('#ta-posts-detail').show();
        updateSelectionSummary();
    }

    function updateSelectionSummary() {
        var count = 0;
        var wordsNeeded = 0;
        var totalRows = $('.ta-page-check').length;
        $('.ta-page-check:checked').each(function () {
            var $row = $(this).closest('tr');
            count++;
            wordsNeeded += parseInt($row.attr('data-words-needed') || 0, 10);
        });
        $('#ta-selection-count').text(count);
        $('#ta-selection-words').text(formatNumber(wordsNeeded));
        $('#ta-selection-summary').toggle(count > 0);

        // Dynamic button label & notice for partial selection
        var allLabel = TA.strings.translate_all_now || 'Translate All Now';
        var selectedLabelTemplate = TA.strings.translate_selected_now || 'Translate Selected (%d) Now';
        
        if (count > 0 && count < totalRows) {
            $('#ta-start-now-label').text(selectedLabelTemplate.replace('%d', count));
            if (TA.builtin_active) {
                $('#ta-selected-notice').show();
            } else {
                $('#ta-selected-notice').hide();
            }
        } else {
            $('#ta-start-now-label').text(allLabel);
            $('#ta-selected-notice').hide();
        }

        // Store selected IDs for translation
        var selectedIds = [];
        $('.ta-page-check:checked').each(function () {
            selectedIds.push(parseInt($(this).val(), 10));
        });
        state.selectedPostIds = selectedIds;

        // Rebuild recommendation based on current selection
        if (state.scanData) {
            buildRecommendation(state.scanData, wordsNeeded);
        }
    }

    /* ── Build Recommendation ────────────────────────── */

    function buildRecommendation(data, selectedWordsNeeded) {
        var $rec = $('#ta-recommendation');
        var wordsNeeded = (typeof selectedWordsNeeded === 'number') ? selectedWordsNeeded : data.total_words_needed;
        var wordsLeft = parseInt(TA.words_left, 10);
        var wordLimit = parseInt(TA.word_limit, 10);
        var plan = TA.plan;
        var isUnlimited = wordLimit === -1 || wordsLeft === -1;

        $rec.empty().removeClass('fits exceeds over-limit');
        $('#ta-step-4').show();

        if (wordsNeeded === 0) {
            $rec.addClass('fits').html(
                '<h4><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:6px;" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>' + escHtml(TA.strings.scan_complete) + '</h4>' +
                '<p>' + escHtml(TA.strings.already_done) + '</p>'
            );
            $rec.show();
            $('#ta-step-4').hide();
            return;
        }

        if (isUnlimited || wordsNeeded <= wordsLeft) {
            var quotaFitsMsg = TA.strings.quota_fits
                .replace('%1$s', '<span class="highlight">' + formatNumber(wordsNeeded) + '</span>')
                .replace('%2$s', '<span class="highlight">' + (isUnlimited ? 'unlimited' : formatNumber(wordsLeft)) + '</span>');
            $rec.addClass('fits').html(
                '<h4><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:6px;" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>' + escHtml(TA.strings.good_news) + '</h4>' +
                '<p>' + quotaFitsMsg + '</p>' +
                '<p>' + escHtml(TA.strings.can_translate_now) + '</p>'
            );
            $('#ta-action-now').show();
            $('#ta-action-schedule').hide();
            $('#ta-action-upgrade').show();
        } else {
            var monthsNeeded = Math.ceil(wordsNeeded / wordLimit);
            var wordsOverLimit = wordsNeeded - wordsLeft;

            var quotaExceedsMsg = TA.strings.quota_exceeds
                .replace('%1$s', '<span class="highlight">' + formatNumber(wordsNeeded) + '</span>')
                .replace('%2$s', '<span class="highlight">' + formatNumber(wordsLeft) + '</span>');
            $rec.addClass('exceeds').html(
                '<h4><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:6px;" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>' + escHtml(TA.strings.exceeds_allowance) + '</h4>' +
                '<p>' + quotaExceedsMsg + '</p>' +
                '<p>You need <strong>' + formatNumber(wordsOverLimit) + '</strong> more words. ' +
                'With your <strong>' + escHtml(plan) + '</strong> plan (' + formatNumber(wordLimit) + ' words/month), ' +
                'scheduled translation would take approximately <strong>' + monthsNeeded + ' month' + (monthsNeeded > 1 ? 's' : '') + '</strong>.</p>'
            );

            $('#ta-schedule-info').html(
                '<strong>Scheduled Translation Plan:</strong>' +
                '<p>• ' + formatNumber(wordsNeeded) + ' words total needed<br>' +
                '• ' + formatNumber(wordLimit) + ' words available per month<br>' +
                '• ~' + monthsNeeded + ' billing cycle' + (monthsNeeded > 1 ? 's' : '') + ' to complete</p>'
            );

            $('#ta-action-now').show().find('h4').text(TA.strings.translate_now_partial);
            $('#ta-action-now').find('p').text(
                TA.strings.translate_partial_desc.replace('%s', formatNumber(wordsLeft))
            );
            $('#ta-action-schedule').show();
            $('#ta-action-upgrade').show();

            var suggestedPlan = getSuggestedPlan(wordsNeeded);
            if (suggestedPlan) {
                $('#ta-upgrade-text').text(
                    'Upgrade to ' + suggestedPlan.name + ' (' + formatNumber(suggestedPlan.words) + ' words/month) to translate everything at once.'
                );
            }
        }

        $rec.show();
    }

    function getSuggestedPlan(wordsNeeded) {
        var plans = [
            { name: 'Starter', words: 18000 },
            { name: 'Agency', words: 89000 },
            { name: 'Enterprise', words: 355000 },
        ];
        for (var i = 0; i < plans.length; i++) {
            if (plans[i].words >= wordsNeeded) return plans[i];
        }
        return plans[plans.length - 1];
    }

    /* ── Start Translation ───────────────────────────── */

    function startTranslation(mode) {
        if (!state.scanData) {
            alert(TA.strings.error);
            return;
        }
        if (!confirm(TA.strings.confirm_start)) return;

        // Use selected post IDs if available, otherwise fall back to all scanned
        var postIds = (state.selectedPostIds && state.selectedPostIds.length > 0)
            ? state.selectedPostIds
            : state.scanData.post_ids;

        if (postIds.length === 0) {
            alert('Please select at least one page to translate.');
            return;
        }

        state.mode = mode;
        state.loggedResults = 0;
        state.loggedErrors = 0;
        state.done = false;
        state.paused = false;

        $('#ta-start-now-btn, #ta-start-scheduled-btn').prop('disabled', true);
        $('#ta-progress-area').show();
        $('#ta-progress-fill').css('width', '0%').removeClass('stopped paused');
        $('#ta-progress-text').text('0%');
        $('#ta-progress-log').empty();
        setProgressState('in_progress', 'Translation in Progress', phasePrefix(1) + ' — ' + TA.strings.processing);
        // Show cancel & pause, hide resume
        $('#ta-cancel-btn').show();
        $('#ta-pause-btn').show().prop('disabled', false);
        $('#ta-resume-btn').hide();

        addLogEntry('Starting ' + (mode === 'scheduled' ? 'scheduled' : 'immediate') + ' translation...', 'info');

        $.ajax({
            url: TA.ajax_url,
            type: 'POST',
            data: {
                action: 'aitrfoel_translate_all_start',
                nonce: TA.nonce,
                mode: mode,
                post_ids: postIds,
                target_languages: state.scanData.target_languages,
                source_language: 'auto',
            },
            success: function (response) {
                if (!response.success) {
                    addLogEntry(escHtml(response.data || TA.strings.error), 'error');
                    resetActions();
                    return;
                }
                state.jobId = response.data.job_id;
                addLogEntry(escHtml(response.data.message), 'success');

                if (mode === 'scheduled') {
                    addLogEntry(escHtml(TA.strings.cron_scheduled), 'info');
                    $('#ta-progress-fill').css('width', '5%');
                    $('#ta-progress-text').text(TA.strings.cron_scheduled);
                    setTimeout(function () { location.reload(); }, 3000);
                } else {
                    startPolling();
                }
            },
            error: function () {
                addLogEntry(escHtml(TA.strings.error), 'error');
                resetActions();
            },
        });
    }

    /* ── Polling ─────────────────────────────────────── */

    function startPolling() {
        stopPolling();
        state.done = false;
        state.polling = true;
        // Self-chaining: each batch is followed immediately by the next request,
        // so the server-side time-budgeted batch (~12s) runs back-to-back with no
        // idle gap. Fire the first poll right away.
        pollStatus();
    }

    function stopPolling() {
        state.polling = false;
        if (state.pollTimer) {
            clearTimeout(state.pollTimer);
            state.pollTimer = null;
        }
        // Abort any in-flight poll XHR to avoid duplicate callbacks
        if (state.pollXhr) {
            state.pollXhr.abort();
            state.pollXhr = null;
        }
    }

    function scheduleNextPoll(delay) {
        if (!state.polling || state.done || state.paused) return;
        if (state.pollTimer) clearTimeout(state.pollTimer);
        state.pollTimer = setTimeout(pollStatus, delay);
    }

    function pollStatus() {
        if (!state.jobId || state.done || state.paused || !state.polling) return;

        // Skip if a poll is already in flight — wait for it to finish
        if (state.pollXhr) return;

        state.pollXhr = $.ajax({
            url: TA.ajax_url,
            type: 'POST',
            data: {
                action: 'aitrfoel_translate_all_status',
                nonce: TA.nonce,
                job_id: state.jobId,
                mode: state.mode,
            },
            success: function (response) {
                state.pollXhr = null;

                // Guard: if already handled completion, ignore this response
                if (state.done) return;

                if (!response.success) {
                    addLogEntry(escHtml(response.data || TA.strings.error), 'error');
                    stopPolling();
                    return;
                }

                var d = response.data;
                var pct = d.progress || 0;

                $('#ta-progress-fill').css('width', pct + '%');
                $('#ta-progress-text').text(pct + '%');
                $('#ta-progress-detail').text(
                    phasePrefix(1) + ' — ' + d.completed + ' ' + TA.strings.of + ' ' + d.total + (d.current_item ? ' — ' + d.current_item : '')
                );

                // Log only NEW results (use index counter)
                if (d.results && d.results.length > state.loggedResults) {
                    for (var i = state.loggedResults; i < d.results.length; i++) {
                        var r = d.results[i];
                        addLogEntry('✓ Translated: Post #' + r.post_id + ' → ' + escHtml(r.language ? r.language.toUpperCase() : ''), 'success');
                    }
                    state.loggedResults = d.results.length;
                }

                // Log only NEW errors
                if (d.errors && d.errors.length > state.loggedErrors) {
                    for (var j = state.loggedErrors; j < d.errors.length; j++) {
                        var e = d.errors[j];
                        addLogEntry('✗ Post #' + e.post_id + ' → ' + escHtml(e.language ? e.language.toUpperCase() : '') + ': ' + escHtml(e.error), 'error');
                    }
                    state.loggedErrors = d.errors.length;
                }

                // Handle paused status from server
                if (d.status === 'paused') {
                    state.paused = true;
                    $('#ta-progress-fill').addClass('paused');
                    $('#ta-pause-btn').hide();
                    $('#ta-resume-btn').show();
                    addLogEntry('⏸ Translation paused.', 'info');
                    return;
                }

                // Check terminal states (completed, cancelled, failed)
                if (d.status === 'completed' || d.status === 'cancelled' || d.status === 'failed') {
                    state.done = true; // Set BEFORE stopPolling to prevent re-entry
                    stopPolling();

                    if (d.status === 'completed') {
                        var successCount = d.results ? d.results.length : 0;
                        var errorCount = d.errors ? d.errors.length : 0;
                        var finalPct = d.total > 0 ? Math.round((d.completed / d.total) * 100) : 100;
                        $('#ta-progress-fill').css('width', finalPct + '%');
                        $('#ta-progress-text').text(finalPct + '%');

                        if (d.stopped_reason === 'word_limit') {
                            $('#ta-progress-fill').addClass('stopped');
                            addLogEntry('Monthly word limit reached. ' +
                                successCount + ' of ' + d.total + ' items translated. ' +
                                'Upgrade your plan to resume translating the remaining items immediately.', 'warning');

                            var upgradeUrl = TA.upgrade_url || TA.pricing_url || 'https://meeptech.com/upgrade?utm_source=wp-plugin&utm_medium=translate-all-limit&utm_campaign=pro-upgrade';
                            var targetAttr = upgradeUrl.indexOf('meeptech.com') !== -1 ? ' target="_blank" rel="noopener noreferrer"' : '';
                            var $upgradePrompt = $(
                                '<div class="aitrfoel-ta-upgrade-prompt" style="margin-top: 15px; padding: 14px 18px; background: linear-gradient(135deg, #eff6ff, #dbeafe); border: 1px solid #bfdbfe; border-radius: 8px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">' +
                                    '<div style="display: flex; align-items: center; gap: 10px;">' +
                                        '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#1e40af" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>' +
                                        '<div>' +
                                            '<strong style="color: #1e40af; font-size: 14px;">Monthly Word Limit Reached</strong>' +
                                            '<div style="font-size: 13px; color: #334155; margin-top: 2px;">' + successCount + ' of ' + d.total + ' items were successfully translated with layout preservation. Upgrade your plan to complete the remaining items!</div>' +
                                        '</div>' +
                                    '</div>' +
                                    '<div style="display: flex; gap: 8px; align-items: center;">' +
                                        '<a href="' + upgradeUrl + '"' + targetAttr + ' class="button button-primary" style="background: #1447e6; border-color: #1447e6; font-weight: 600; padding: 0 16px; height: 32px; line-height: 30px;">Upgrade Plan & Resume →</a>' +
                                    '</div>' +
                                '</div>'
                            );
                            $('#ta-progress-area').append($upgradePrompt);
                        } else {
                            addLogEntry(escHtml(TA.strings.completed), 'success');
                        }
                        var skippedCount = Math.max(0, (d.total || 0) - successCount - errorCount);
                        addLogEntry(
                            'Summary: ' + successCount + ' translated, ' + errorCount + ' errors, ' + skippedCount + ' skipped (' + (d.total || 0) + ' tasks)',
                            'info'
                        );

                        // Render translated pages list
                        if (d.results && d.results.length > 0) {
                            renderTranslatedList(d.results, d.errors || []);
                        }

                        // Review prompt (server-driven: shows after 3+ successful translations)
                        if (successCount > 0 && d.review_eligible) {
                            var $reviewPrompt = $(
                                '<div class="aitrfoel-ta-review-prompt" style="margin-top: 15px; padding: 12px 16px; background: linear-gradient(135deg, #fef9e7, #fdf2d1); border: 1px solid #f0d78c; border-radius: 6px; display: flex; align-items: center; justify-content: space-between; gap: 12px;">' +
                                    '<span style="font-size: 0.95em; color: #5a4e2f; display: inline-flex; align-items: center; gap: 6px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="#eab308" stroke="#eab308" stroke-width="1" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg> ' + escHtml(TA.strings.enjoying_plugin) + '</span>' +
                                    '<span style="white-space: nowrap; display: inline-flex; align-items: center; gap: 6px;">' +
                                        '<a href="https://wordpress.org/support/plugin/ai-translator-for-elementor-and-polylang/reviews/#new-post" target="_blank" class="button button-primary" style="display:inline-flex;align-items:center;gap:5px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg> ' + escHtml(TA.strings.leave_review) + '</a>' +
                                        '<button type="button" class="button aitrfoel-ta-dismiss-review">' + escHtml(TA.strings.no_thanks) + '</button>' +
                                    '</span>' +
                                '</div>'
                            );
                            $('#ta-progress-area').append($reviewPrompt);
                        }

                        // Auto-translate menus & widgets (built-in language system only)
                        if (TA.builtin_active && state.scanData && state.scanData.target_languages) {
                            $('#ta-progress-detail').text(phasePrefix(2) + ' — Translating menus & widgets...');
                            translateMenusAndWidgets(state.scanData.target_languages);
                        } else {
                            setProgressState('completed', TA.strings.completed, phasePrefix(1) + ' — Completed');
                        }
                    } else if (d.status === 'cancelled') {
                        addLogEntry(escHtml(TA.strings.cancelled), 'info');
                        setProgressState('cancelled', TA.strings.cancelled);
                    } else {
                        addLogEntry(escHtml(TA.strings.translation_failed), 'error');
                        setProgressState('failed', TA.strings.translation_failed);
                    }

                    hideProgressButtons();
                    resetActions();
                    return;
                }

                // Still processing — immediately chain the next batch request.
                scheduleNextPoll(400);
            },
            error: function (xhr, status) {
                state.pollXhr = null;
                // Ignore aborted requests
                if (status === 'abort') return;
                // Transient failure — retry after a short backoff.
                scheduleNextPoll(3000);
            },
        });
    }
    /* ── Auto-translate menus & widgets after page translation ── */

    function translateMenusAndWidgets(languages) {
        var langIndex = 0;
        var totalLangs = Array.isArray(languages) ? languages.length : 0;

        function setMenuWidgetDetail(stage, lang, index) {
            var label = lang ? lang.toUpperCase() : '';
            var step = Math.min(index + 1, totalLangs);
            $('#ta-progress-detail').text(phasePrefix(2) + ' — Translating menus & widgets: ' + stage + ' (' + step + '/' + totalLangs + ') ' + label);
        }

        addLogEntry('Translating menus & widgets...', 'info');
        if (totalLangs > 0) {
            $('#ta-progress-detail').text(phasePrefix(2) + ' — Translating menus & widgets... (0/' + totalLangs + ')');
        }

        function processNextLang() {
            if (langIndex >= languages.length) {
                // Auto-translate UI Strings & Form labels (WooCommerce checkout/gateways, JetSmartFilters, Forms)
                addLogEntry('UI Strings & Form Labels: processing...', 'info');
                $('#ta-progress-detail').text(phasePrefix(2) + ' — Translating UI strings, filters & form labels…');
                $.ajax({
                    url: TA.ajax_url,
                    type: 'POST',
                    timeout: 120000,
                    data: {
                        action: 'aitrfoel_autotranslate_strings',
                        nonce: TA.ajax_nonce,
                        force: 0
                    }
                }).done(function (res) {
                    if (res.success) {
                        addLogEntry('UI Strings & Forms: ' + (res.data && res.data.message ? res.data.message : 'OK'), 'success');
                    }
                }).always(function () {
                    setProgressState('completed', TA.strings.completed, phasePrefix(2) + ' — Complete site translation finished.');
                    addLogEntry('Complete site translation (content, templates, menus, widgets & strings) finished!', 'success');
                });
                return;
            }
            var lang = languages[langIndex];
            var currentIndex = langIndex;
            langIndex++;

            // Menus first
            setMenuWidgetDetail('menus', lang, currentIndex);
            addLogEntry('Menus → ' + lang.toUpperCase() + ': processing...', 'info');
            $.ajax({
                url: TA.ajax_url,
                type: 'POST',
                timeout: 120000,
                data: {
                    action: 'aitrfoel_auto_translate_menus',
                    nonce: TA.ajax_nonce,
                    target_lang: lang,
                },
            }).done(function (res) {
                if (res.success) {
                    addLogEntry('Menus → ' + lang.toUpperCase() + ': ' + (res.data.message || 'OK'), 'success');
                } else {
                    addLogEntry('Menus → ' + lang.toUpperCase() + ': ' + (res.data || 'Error'), 'error');
                }
            }).fail(function (xhr, status) {
                addLogEntry('Menus → ' + lang.toUpperCase() + ': ' + (status === 'timeout' ? 'request timeout' : 'request failed'), 'error');
            }).always(function () {
                // Then widgets
                setMenuWidgetDetail('widgets', lang, currentIndex);
                addLogEntry('Widgets → ' + lang.toUpperCase() + ': processing...', 'info');
                $.ajax({
                    url: TA.ajax_url,
                    type: 'POST',
                    timeout: 120000,
                    data: {
                        action: 'aitrfoel_auto_translate_widgets',
                        nonce: TA.ajax_nonce,
                        target_lang: lang,
                    },
                }).done(function (res) {
                    if (res.success) {
                        addLogEntry('Widgets → ' + lang.toUpperCase() + ': ' + (res.data.message || 'OK'), 'success');
                    } else {
                        addLogEntry('Widgets → ' + lang.toUpperCase() + ': ' + (res.data || 'Error'), 'error');
                    }
                }).fail(function (xhr, status) {
                    addLogEntry('Widgets → ' + lang.toUpperCase() + ': ' + (status === 'timeout' ? 'request timeout' : 'request failed'), 'error');
                }).always(function () {
                    processNextLang();
                });
            });
        }

        processNextLang();
    }

    /* ── Translated Pages List ────────────────────────── */

    function renderTranslatedList(results, errors) {
        var $area = $('#ta-progress-area');

        var html = '<div class="aitrfoel-ta-translated-list">';
        html += '<h4 style="display:flex;align-items:center;gap:6px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/></svg><span>Translation Results</span></h4>';

        // Success table
        if (results.length > 0) {
            html += '<table class="aitrfoel-ta-detail-table">';
            html += '<thead><tr><th>Original</th><th>Language</th><th>Translated Version</th></tr></thead><tbody>';
            results.forEach(function (r) {
                var title = r.title ? escHtml(r.title) : ('Post #' + r.post_id);
                var srcLink = r.view_url ? '<a href="' + escHtml(r.view_url) + '" target="_blank">' + title + ' ↗</a>' : title;
                var langLabel = r.language ? r.language.toUpperCase() : '';
                var typeLabel = r.post_type === 'page' ? 'Page' : 'Post';
                var newLink = '';
                if (r.new_post_id) {
                    var newTitle = escHtml(typeLabel + ' → ' + langLabel);
                    newLink = r.new_view_url
                        ? '<a href="' + escHtml(r.new_view_url) + '" target="_blank">View ' + escHtml(typeLabel) + ' ↗</a>'
                        : typeLabel + ' #' + r.new_post_id;
                }
                html += '<tr>' +
                    '<td>' + srcLink + '</td>' +
                    '<td><span class="aitrfoel-ta-lang-tag done">' + escHtml(langLabel) + '</span></td>' +
                    '<td>' + newLink + '</td>' +
                    '</tr>';
            });
            html += '</tbody></table>';
        }

        // Errors table
        if (errors.length > 0) {
            html += '<h4 style="margin-top:16px;display:flex;align-items:center;gap:6px;color:#dc2626;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg><span>Failed Translations</span></h4>';
            html += '<table class="aitrfoel-ta-detail-table">';
            html += '<thead><tr><th>Page</th><th>Language</th><th>Error</th></tr></thead><tbody>';
            errors.forEach(function (e) {
                var langLabel = e.language ? e.language.toUpperCase() : '';
                html += '<tr>' +
                    '<td>Post #' + e.post_id + '</td>' +
                    '<td><span class="aitrfoel-ta-lang-tag missing">' + escHtml(langLabel) + '</span></td>' +
                    '<td style="color:#d63638;font-size:12px;">' + escHtml(e.error) + '</td>' +
                    '</tr>';
            });
            html += '</tbody></table>';
        }

        html += '</div>';
        $area.append(html);
    }
    /* ── Pause / Resume ──────────────────────────────── */

    function pauseTranslation() {
        state.paused = true;
        stopPolling();
        $('#ta-pause-btn').prop('disabled', true);
        $.ajax({
            url: TA.ajax_url,
            type: 'POST',
            data: {
                action: 'aitrfoel_translate_all_pause',
                nonce: TA.nonce,
                mode: state.mode || 'now',
            },
            success: function (response) {
                if (response.success) {
                    addLogEntry('⏸ Translation paused.', 'info');
                    $('#ta-progress-fill').addClass('paused');
                }
                $('#ta-pause-btn').hide();
                $('#ta-resume-btn').show().prop('disabled', false);
            },
            error: function () {
                state.paused = false;
                $('#ta-pause-btn').prop('disabled', false);
                startPolling();
            },
        });
    }

    function resumeTranslation() {
        $('#ta-resume-btn').prop('disabled', true);
        $.ajax({
            url: TA.ajax_url,
            type: 'POST',
            data: {
                action: 'aitrfoel_translate_all_resume',
                nonce: TA.nonce,
                mode: state.mode || 'now',
            },
            success: function (response) {
                if (response.success) {
                    state.paused = false;
                    addLogEntry('▶ Translation resumed.', 'info');
                    $('#ta-progress-fill').removeClass('paused');
                    $('#ta-resume-btn').hide();
                    $('#ta-pause-btn').show().prop('disabled', false);
                    // Restart the (self-chaining) poll loop.
                    startPolling();
                }
            },
            error: function () {
                $('#ta-resume-btn').prop('disabled', false);
            },
        });
    }

    /* ── Cancel ──────────────────────────────────────── */

    function cancelTranslation(mode) {
        if (!confirm('Are you sure you want to cancel?')) return;
        state.done = true;
        $.ajax({
            url: TA.ajax_url,
            type: 'POST',
            data: {
                action: 'aitrfoel_translate_all_cancel',
                nonce: TA.nonce,
                mode: mode || state.mode || 'now',
            },
            success: function (response) {
                stopPolling();
                if (response.success) addLogEntry(escHtml(TA.strings.cancelled), 'info');
                hideProgressButtons();
                resetActions();
            },
        });
    }

    function hideProgressButtons() {
        $('#ta-cancel-btn').hide();
        $('#ta-pause-btn').hide();
        $('#ta-resume-btn').hide();
    }

    function resetActions() {
        $('#ta-start-now-btn, #ta-start-scheduled-btn').prop('disabled', false);
        $('#ta-scan-btn').prop('disabled', false).html('<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg> <span>' + escHtml(TA.strings.scan_content || 'Scan Content') + '</span>');
    }

    /* ── Init ────────────────────────────────────────── */

    $(document).ready(function () {
        $('#ta-scan-btn').on('click', scanContent);
        $('#ta-start-now-btn').on('click', function () { startTranslation('now'); });
        $('#ta-start-scheduled-btn').on('click', function () { startTranslation('scheduled'); });
        $('#ta-cancel-btn').on('click', function () { cancelTranslation(); });
        $('#ta-pause-btn').on('click', pauseTranslation);
        $('#ta-resume-btn').on('click', resumeTranslation);
        $('#ta-cancel-cron-btn').on('click', function () { cancelTranslation('scheduled'); });

        // Select All / Deselect All
        $('#ta-select-all-types').on('click', function () { $('input[name="post_types[]"]').prop('checked', true); });
        $('#ta-deselect-all-types').on('click', function () { $('input[name="post_types[]"]').prop('checked', false); });
        $('#ta-select-all-langs').on('click', function () { $('input[name="target_languages[]"]').prop('checked', true); });
        $('#ta-deselect-all-langs').on('click', function () { $('input[name="target_languages[]"]').prop('checked', false); });

        // Dismiss review prompt
        $(document).on('click', '.aitrfoel-ta-dismiss-review', function () {
            var $prompt = $(this).closest('.aitrfoel-ta-review-prompt');
            $.post(TA.ajax_url, {
                action: 'aitrfoel_dismiss_review',
                nonce: TA.nonce
            });
            $prompt.slideUp(200);
        });



        // Detail table filter buttons
        $(document).on('click', '.ta-detail-filter', function () {
            var filter = $(this).data('filter');
            $('.ta-detail-filter').removeClass('active');
            $(this).addClass('active');
            if (filter === 'all') {
                $('#ta-detail-body tr').show();
            } else if (filter === 'needs') {
                $('#ta-detail-body tr.ta-row-done').hide();
                $('#ta-detail-body tr.ta-row-needs').show();
            } else if (filter === 'done') {
                $('#ta-detail-body tr.ta-row-needs').hide();
                $('#ta-detail-body tr.ta-row-done').show();
            }
        });

        // Page selection checkboxes
        $(document).on('change', '.ta-page-check', updateSelectionSummary);
        $('#ta-check-all').on('change', function () {
            var checked = $(this).prop('checked');
            $('#ta-detail-body tr:visible .ta-page-check').prop('checked', checked);
            updateSelectionSummary();
        });
        $('#ta-select-all-pages').on('click', function () {
            $('#ta-detail-body .ta-page-check').prop('checked', true);
            $('#ta-check-all').prop('checked', true);
            updateSelectionSummary();
        });
        $('#ta-select-needs-pages').on('click', function () {
            $('#ta-detail-body .ta-page-check').prop('checked', false);
            $('#ta-detail-body tr.ta-row-needs .ta-page-check').prop('checked', true);
            updateSelectionSummary();
        });
        $('#ta-deselect-all-pages').on('click', function () {
            $('#ta-detail-body .ta-page-check').prop('checked', false);
            $('#ta-check-all').prop('checked', false);
            updateSelectionSummary();
        });
    });
})(jQuery);
