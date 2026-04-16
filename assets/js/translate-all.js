/**
 * Translate All – Admin Page JavaScript
 */
(function ($) {
    'use strict';

    var TA = window.aitrfoel_translate_all || {};
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

    /* ── Helpers ─────────────────────────────────────── */

    function formatNumber(n) {
        return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
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
        var colors = { elementor: '#92003B', gutenberg: '#1e8cbe', classic: '#666' };
        return '<span class="aitrfoel-ta-badge" style="background:' + (colors[type] || '#666') + '">' +
            (labels[type] || type) + '</span>';
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
                $('#ta-scan-btn').prop('disabled', false).text('🔍 Scan Content');
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
                statusHtml = '<span class="aitrfoel-ta-status-done">✅ Fully Translated</span> ';
                if (p.translated_langs.length > 0) {
                    statusHtml += p.translated_langs.map(function (l) { return '<span class="aitrfoel-ta-lang-tag done">' + escHtml(l) + '</span>'; }).join(' ');
                }
            } else {
                statusHtml = '<span class="aitrfoel-ta-status-pending">⏳ Needs Translation</span> ';
                if (p.translated_langs.length > 0) {
                    statusHtml += p.translated_langs.map(function (l) { return '<span class="aitrfoel-ta-lang-tag done">' + escHtml(l) + '</span>'; }).join(' ') + ' ';
                }
                if (p.missing_langs.length > 0) {
                    statusHtml += p.missing_langs.map(function (l) { return '<span class="aitrfoel-ta-lang-tag missing">' + escHtml(l) + '</span>'; }).join(' ');
                }
            }

            var titleLink = '<a href="' + escHtml(p.edit_url || '#') + '" target="_blank" title="Edit">' + escHtml(p.title) + '</a>';
            if (p.view_url) {
                titleLink += ' <a href="' + escHtml(p.view_url) + '" target="_blank" class="aitrfoel-ta-view-link" title="View page">↗</a>';
            }

            var filterClass = p.is_fully_translated ? 'ta-row-done' : 'ta-row-needs';
            var needsTranslation = !p.is_fully_translated;
            var usageFactor = parseInt(TA.server_usage_factor, 10) || 2;
            var wordsNeededForPost = p.word_count * (p.missing_langs ? p.missing_langs.length : 0) * usageFactor;

            var langBadge = p.post_lang ? '<span class="eat-lang-flag eat-lang-exists">' + escHtml(p.post_lang) + '</span>' : '';

            $body.append(
                '<tr class="' + filterClass + '" data-post-id="' + p.post_id + '" data-words="' + p.word_count + '" data-words-needed="' + wordsNeededForPost + '" data-needs="' + (needsTranslation ? '1' : '0') + '">' +
                    '<td><input type="checkbox" class="ta-page-check" value="' + p.post_id + '"' + (needsTranslation ? ' checked' : '') + ' /></td>' +
                    '<td>' + titleLink + '</td>' +
                    '<td>' + langBadge + '</td>' +
                    '<td>' + escHtml(p.post_type) + '</td>' +
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
        $('.ta-page-check:checked').each(function () {
            var $row = $(this).closest('tr');
            count++;
            wordsNeeded += parseInt($row.attr('data-words-needed') || 0, 10);
        });
        $('#ta-selection-count').text(count);
        $('#ta-selection-words').text(formatNumber(wordsNeeded));
        $('#ta-selection-summary').toggle(count > 0);

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
                '<h4>✅ ' + escHtml(TA.strings.scan_complete) + '</h4>' +
                '<p>All content is already translated. Nothing to do!</p>'
            );
            $rec.show();
            $('#ta-step-4').hide();
            return;
        }

        if (isUnlimited || wordsNeeded <= wordsLeft) {
            $rec.addClass('fits').html(
                '<h4>✅ Good news!</h4>' +
                '<p>Your selection will use an estimated <span class="highlight">' + formatNumber(wordsNeeded) + ' words</span> of API quota ' +
                'and you have <span class="highlight">' + (isUnlimited ? 'unlimited' : formatNumber(wordsLeft)) + ' words</span> remaining.</p>' +
                '<p>You can translate everything now!</p>'
            );
            $('#ta-action-now').show();
            $('#ta-action-schedule').hide();
            $('#ta-action-upgrade').show();
        } else {
            var monthsNeeded = Math.ceil(wordsNeeded / wordLimit);
            var wordsOverLimit = wordsNeeded - wordsLeft;

            $rec.addClass('exceeds').html(
                '<h4>⚠️ Content exceeds your current word allowance</h4>' +
                '<p>Your selection will use an estimated <span class="highlight">' + formatNumber(wordsNeeded) + ' words</span> of API quota, ' +
                'but you only have <span class="highlight">' + formatNumber(wordsLeft) + ' words</span> remaining this month.</p>' +
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

            $('#ta-action-now').show().find('h4').text('Translate Now (Partial)');
            $('#ta-action-now').find('p').text(
                'Translate as much as possible with your remaining ' + formatNumber(wordsLeft) + ' words.'
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
        $('#ta-progress-detail').text(TA.strings.processing);
        $('#ta-progress-log').empty();
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
        if (state.polling) clearInterval(state.polling);
        state.done = false;
        state.polling = setInterval(pollStatus, 3000);
    }

    function stopPolling() {
        if (state.polling) {
            clearInterval(state.polling);
            state.polling = null;
        }
        // Abort any in-flight poll XHR to avoid duplicate callbacks
        if (state.pollXhr) {
            state.pollXhr.abort();
            state.pollXhr = null;
        }
    }

    function pollStatus() {
        if (!state.jobId || state.done || state.paused) return;

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
                    d.completed + ' ' + TA.strings.of + ' ' + d.total + (d.current_item ? ' — ' + d.current_item : '')
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
                            addLogEntry('⚠️ Stopped: monthly word limit reached. ' +
                                successCount + ' of ' + d.total + ' items translated. ' +
                                'Use Scheduled Translation to continue next month, or upgrade your plan.', 'error');
                        } else {
                            addLogEntry(escHtml(TA.strings.completed), 'success');
                        }
                        addLogEntry(
                            'Summary: ' + successCount + ' translated, ' + errorCount + ' errors',
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
                                    '<span style="font-size: 0.95em; color: #5a4e2f;">⭐ Enjoying Meep AI Translator? A quick review helps us a lot!</span>' +
                                    '<span style="white-space: nowrap; display: inline-flex; align-items: center; gap: 6px;">' +
                                        '<a href="https://wordpress.org/support/plugin/ai-translator-for-elementor-and-polylang/reviews/#new-post" target="_blank" class="button button-primary">Leave a Review</a>' +
                                        '<button type="button" class="button aitrfoel-ta-dismiss-review">No thanks</button>' +
                                    '</span>' +
                                '</div>'
                            );
                            $('#ta-progress-area').append($reviewPrompt);
                        }

                        // Auto-translate menus & widgets (built-in language system only)
                        if (TA.builtin_active && state.scanData && state.scanData.target_languages) {
                            translateMenusAndWidgets(state.scanData.target_languages);
                        }
                    } else if (d.status === 'cancelled') {
                        addLogEntry(escHtml(TA.strings.cancelled), 'info');
                    } else {
                        addLogEntry('Translation failed.', 'error');
                    }

                    hideProgressButtons();
                    resetActions();
                }
            },
            error: function (xhr, status) {
                state.pollXhr = null;
                // Ignore aborted requests
                if (status === 'abort') return;
                /* retry on next poll */
            },
        });
    }
    /* ── Auto-translate menus & widgets after page translation ── */

    function translateMenusAndWidgets(languages) {
        var langIndex = 0;

        addLogEntry('Translating menus & widgets...', 'info');

        function processNextLang() {
            if (langIndex >= languages.length) {
                addLogEntry('Menus & widgets translation completed!', 'success');
                return;
            }
            var lang = languages[langIndex];
            langIndex++;

            // Menus first
            $.post(TA.ajax_url, {
                action: 'aitrfoel_auto_translate_menus',
                nonce: TA.ajax_nonce,
                target_lang: lang,
            }).done(function (res) {
                if (res.success) {
                    addLogEntry('Menus → ' + lang.toUpperCase() + ': ' + (res.data.message || 'OK'), 'success');
                } else {
                    addLogEntry('Menus → ' + lang.toUpperCase() + ': ' + (res.data || 'Error'), 'error');
                }
            }).fail(function () {
                addLogEntry('Menus → ' + lang.toUpperCase() + ': request failed', 'error');
            }).always(function () {
                // Then widgets
                $.post(TA.ajax_url, {
                    action: 'aitrfoel_auto_translate_widgets',
                    nonce: TA.ajax_nonce,
                    target_lang: lang,
                }).done(function (res) {
                    if (res.success) {
                        addLogEntry('Widgets → ' + lang.toUpperCase() + ': ' + (res.data.message || 'OK'), 'success');
                    } else {
                        addLogEntry('Widgets → ' + lang.toUpperCase() + ': ' + (res.data || 'Error'), 'error');
                    }
                }).fail(function () {
                    addLogEntry('Widgets → ' + lang.toUpperCase() + ': request failed', 'error');
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
        html += '<h4>📋 Translation Results</h4>';

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
            html += '<h4 style="margin-top:16px;">❌ Failed Translations</h4>';
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
                $('#ta-resume-btn').show();
            },
            error: function () {
                state.paused = false;
                $('#ta-pause-btn').prop('disabled', false);
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
        $('#ta-scan-btn').prop('disabled', false).text('🔍 Scan Content');
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

        // Free version: limit to one language at a time — grey out others
        if (!TA.is_pro) {
            $(document).on('change', 'input[name="target_languages[]"]', function () {
                if ($(this).is(':checked')) {
                    // Uncheck all others
                    $('input[name="target_languages[]"]').not(this).prop('checked', false);
                    // Grey out all unchecked
                    $('input[name="target_languages[]"]').not(':checked').prop('disabled', true)
                        .closest('.aitrfoel-ta-lang-card').css('opacity', '0.45');
                } else {
                    // Nothing selected — re-enable all
                    $('input[name="target_languages[]"]').prop('disabled', false)
                        .closest('.aitrfoel-ta-lang-card').css('opacity', '1');
                }
            });

            // Disable Select All for free version and add upgrade prompt
            $('#ta-select-all-langs').prop('disabled', true).attr('title', 'Upgrade to Pro for multi-language translation');

            // Add upgrade notice after language cards
            if ($('.aitrfoel-ta-languages').length && !$('.aitrfoel-ta-free-lang-notice').length) {
                $('.aitrfoel-ta-languages').after(
                    '<div class="aitrfoel-ta-free-lang-notice" style="margin-top: 10px; padding: 10px 14px; background: linear-gradient(135deg, #f0f6ff, #e8f0fe); border: 1px solid #a8c7fa; border-radius: 6px; font-size: 0.9em; color: #1a3d6e;">' +
                        '⚡ Free version supports one language at a time. ' +
                        '<a href="' + escHtml(TA.upgrade_url) + '" target="_blank" style="font-weight: 600; color: #1a56db;">Upgrade to Pro</a> to translate into multiple languages at once.' +
                    '</div>'
                );
            }
        }

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
