/**
 * Dashboard JavaScript for Elementor AI Translator
 * Handles bulk translation interface interactions
 */

jQuery(document).ready(function($) {
    'use strict';

    // Helper function to get localized string
    function __( key, fallback ) {
        if ( typeof aitrfoelDashboard !== 'undefined' && aitrfoelDashboard.strings && aitrfoelDashboard.strings[key] ) {
            return aitrfoelDashboard.strings[key];
        }
        return fallback || key;
    }

        function escapeHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

    const Dashboard = {
        activeTab: 'pages', // Track current active tab

        init: function() {
            this.bindEvents();
            this.setupLanguageDropdown();
            this.initContentTabs(); // Initialize pages/posts tab switching
            this.updateStartButtonState(); // Initialize button state
            this.updateDropdownsState(false); // Initially disable dropdowns
            this.checkForActiveTranslations(); // Check for ongoing translations on page load
            this.initTableAccordion(); // Initialize table accordion functionality
            this.initStickyBar(); // Initialize sticky translation bar
            
            // Initialize progress tracking
            this.progressInterval = null;
        },

        /**
         * Initialize content type tabs (Pages / Posts)
         */
        initContentTabs: function() {
            const self = this;
            
            $(document).on('click', '.aitrfoel-tab-btn', function(e) {
                e.preventDefault();
                const $btn = $(this);
                const tab = $btn.data('tab');
                
                if (tab === self.activeTab) return;
                
                // Update active tab
                self.activeTab = tab;
                
                // Update tab button states
                $('.aitrfoel-tab-btn').removeClass('active').attr('aria-selected', 'false');
                $btn.addClass('active').attr('aria-selected', 'true');
                
                // Show/hide tab panels
                $('.aitrfoel-tab-panel').hide();
                $('#aitrfoel-tab-' + tab).show();
                
                // Reset select-all checkboxes
                $('#cb-select-all, #cb-select-all-posts, #cb-select-all-products').prop('checked', false);
                
                // Uncheck any selected items in hidden tab, keep visible tab's selection
                // (Don't uncheck the active tab's items)
                
                // Re-initialize accordion for newly visible tab
                self.initTableAccordion();
                
                // Update button state
                self.updateStartButtonState();
            });
        },

        checkForActiveTranslations: function() {
            // ????????? localStorage ?? ??????? ????????? ????????
            const activeTranslation = localStorage.getItem('aitrfoel_active_translation');
            
            if (activeTranslation) {
                try {
                    const translationData = JSON.parse(activeTranslation);
                    const jobId = translationData.jobId;
                    // ??????: ????????? UI ???? ????????? ??????
                    this.blockTranslationUI(__('checking_status', 'Checking active translation status...'));
                    
                    // ?????????, ????????????? ?? ??????? ??? ???????
                    this.verifyActiveTranslation(jobId);
                    
                } catch (e) {                    this.clearActiveTranslation();
                }
            }
        },

        /**
         * ????????? ????????????? ?? ??????? ??????? ? ???? ??????
         */
        verifyActiveTranslation: function(jobId) {
            $.ajax({
                url: aitrfoelDashboard.ajax_url,
                type: 'POST',
                data: {
                    action: 'aitrfoel_get_translation_status',
                    nonce: aitrfoelDashboard.nonce,
                    job_id: jobId
                },
                success: (response) => {
                    if (response.success && response.data.status === 'processing') {
                        // ??????? ????????????? ??????? - ?????????? ??????????
                        this.showTranslationInProgress(response.data);
                        this.monitorTranslationProgress(jobId);
                    } else {
                        // ??????? ???????? ??? ?? ?????? - ??????? localStorage
                        this.clearActiveTranslation();
                        this.unblockTranslationUI();
                    }
                },
                error: () => {
                    this.clearActiveTranslation();
                    this.unblockTranslationUI();
                }
            });
        },

        /**
         * ???????? ??? ??????? ??? ? ????????
         */
        showTranslationInProgress: function(statusData) {
            const button = $('#aitrfoel-start-translation');
            
            // Show AI preparation state with dots animation
            $('#aitrfoel-estimated-words')
                .addClass('translating')
                .html(__('waiting_for_ai', 'AI is preparing your translations') + '<span class="aitrfoel-dots"><span class="dot">.</span><span class="dot">.</span><span class="dot">.</span></span>');
            
            // ????????? ??????
            button.addClass('loading')
                  .removeClass('button-primary success')
                  .html('⏳ ' + __('translation_in_progress', 'Translation in Progress') + '...')
                  .prop('disabled', true);
            
            // ?? ????????? flip ??????!
            this.startFlipLoader(statusData.total_items || 0, statusData.completed_items || 0);
            
            // ?????????? ???????? ???? ???? ??????
            if (statusData.progress !== undefined) {
                $('#aitrfoel-progress-container').show();
                this.updateProgress(statusData.progress, statusData.status_message || __('translation_in_progress', 'Translation in progress...'), statusData.details || '');
            }
            
            $('#aitrfoel-bulk-bar').addClass('processing');
            
            // ?????????? ???????????
            this.showNotice('⚠️ ' + __('translation_already_in_progress', 'Translation is already in progress. Please wait for completion.'), 'warning');
        },

        /**
         * ????????????? UI ?????????
         */
        blockTranslationUI: function(message) {
            const button = $('#aitrfoel-start-translation');
            button.prop('disabled', true)
                  .html('⚠️ ' + message)
                  .addClass('loading');
            
            // ????????????? ????????
            $('.aitrfoel-page-checkbox, .aitrfoel-language-checkbox').prop('disabled', true);
            $('.aitrfoel-source-languages, .aitrfoel-target-languages').addClass('disabled');
        },

        /**
         * ?????????????? UI ?????????
         */
        unblockTranslationUI: function() {
            const button = $('#aitrfoel-start-translation');
            button.removeClass('loading')
                  .addClass('button-primary')
                  .html('🚀 Start Translation');
            
            // ?????????????? ????????
            $('.aitrfoel-page-checkbox, .aitrfoel-language-checkbox').prop('disabled', false);
            $('.aitrfoel-source-languages, .aitrfoel-target-languages').removeClass('disabled');
            
            // ???????? ????????? ??????
            this.updateStartButtonState();
        },

        saveActiveTranslation: function(jobId, progress = 0) {
            localStorage.setItem('aitrfoel_active_translation', JSON.stringify({
                jobId: jobId,
                progress: progress,
                timestamp: Date.now()
            }));
        },

        clearActiveTranslation: function() {
            localStorage.removeItem('aitrfoel_active_translation');
            localStorage.removeItem('flipLoaderState');
            localStorage.removeItem('stageProgressState');
            
            // Stop progress indicators
            this.stopFlipLoader();
            this.stopStageProgress();
            
            // Reset text
            $('#aitrfoel-estimated-words').text(__('choose_pages', 'Choose pages to translate')).show();
        },

        /**
         * ? Reset UI after error
         */
        resetTranslationUI: function() {
            const button = $('#aitrfoel-start-translation');
            button.removeClass('loading non-clickable')
                  .addClass('button-primary')
                  .html('🚀 Start Translation')
                  .prop('disabled', false);
            
            $('#aitrfoel-bulk-bar').removeClass('processing');
            $('#aitrfoel-progress-container').hide();
            
            // Reset estimated words text
            $('#aitrfoel-estimated-words')
                .removeClass('translating')
                .text(__('choose_pages', 'Choose pages to translate'))
                .show();
            
            // Stop any animations and timer
            this.stopFlipLoader();
            this.stopStageProgress();
            this.stopElapsedTimer();
            this.stopAnimatedProgress();
            
            // Clear active translation
            this.clearActiveTranslation();
        },

        /**
         * ?? STAGE-BASED PROGRESS INDICATOR
         */
        startStageProgress: function(totalJobs = 0, completedJobs = 0) {
            // Hide simple text, show stage progress
            $('#aitrfoel-estimated-words').hide();
            $('#aitrfoel-stage-progress').show().addClass('active');
            
            // Initialize stages - only show stage 1 initially
            this.updateStageStatus(1, 'active', 0);
            this.updateStageStatus(2, 'pending', 0);
            
            // Hide stage 2 initially
            $('.aitrfoel-stage-2').addClass('pending').hide();
            
            // Save state
            this.stageProgress = {
                totalJobs: totalJobs,
                completedJobs: completedJobs,
                currentStage: 1,
                stage1Progress: 0,
                stage2Progress: 0
            };
            
            localStorage.setItem('stageProgressState', JSON.stringify({
                active: true,
                ...this.stageProgress
            }));
        },

        /**
         * Update specific stage status and progress
         */
        updateStageStatus: function(stage, status, progress = 0) {
            const stageElement = $(`.aitrfoel-stage-${stage}`);
            const statusElement = stageElement.find('.aitrfoel-stage-status');
            const progressBar = stageElement.find('.aitrfoel-stage-progress-fill');
            
            // Update status text and styling
            statusElement.removeClass('pending active completed error')
                        .addClass(status)
                        .text(this.getStatusText(status));
            
            // Update progress bar
            progressBar.css('width', progress + '%');
            
            // Update stored state
            if (this.stageProgress) {
                this.stageProgress[`stage${stage}Progress`] = progress;
                localStorage.setItem('stageProgressState', JSON.stringify({
                    active: true,
                    ...this.stageProgress
                }));
            }
        },

        /**
         * Get status text for display
         */
        getStatusText: function(status) {
            const statusMap = {
                'pending': 'Pending',
                'active': 'In Progress',
                'completed': __('status_completed', 'Completed'),
                'error': __('status_error', 'Error')
            };
            return statusMap[status] || status;
        },

        /**
         * Update stage descriptions
         */
        updateStageDescription: function(stage, description) {
            $(`.aitrfoel-stage-${stage} .aitrfoel-stage-description`).text(description);
        },

        /**
         * Stop stage progress and reset
         */
        stopStageProgress: function() {
            $('#aitrfoel-stage-progress').hide().removeClass('active');
            $('#aitrfoel-estimated-words').show();
            this.stageProgress = null;
            localStorage.removeItem('stageProgressState');
        },

        /**
         * Update stage progress based on server status
         */
        updateStageProgress: function(status) {
            if (!this.stageProgress) {
                return;
            }

            const stage = status.stage || 1;
            const progress = status.progress || 0;
            const stageDescription = status.stage_description || '';
            const currentStep = status.current_step || '';

            // Auto-expand source page if we have current page info
            if (status.current_page_id) {
                this.expandSourcePageForTranslation(status.current_page_id);
            }

            // Update current stage
            if (stage === 1) {
                // Stage 1: AI Translation
                this.updateStageStatus(1, 'active', Math.min(progress, 80));
                this.updateStageStatus(2, 'pending', 0);
                this.updateStageDescription(1, stageDescription || 'Sending content to OpenAI for translation');
                
                // Keep stage 2 hidden
                $('.aitrfoel-stage-2').hide();
                
            } else if (stage === 2) {
                // Stage 2: Gap-Filler - show stage 2, complete stage 1
                this.updateStageStatus(1, __('status_completed', 'Completed'), 100);
                this.updateStageStatus(2, 'active', Math.max(0, progress - 80) * 5); // Convert 80-100% to 0-100%
                this.updateStageDescription(2, stageDescription || 'Fixing missed translations and refining quality');
                
                // Show stage 2 now
                $('.aitrfoel-stage-2').show();
                
            } else if (stage === 3) {
                // Both stages completed
                this.updateStageStatus(1, __('status_completed', 'Completed'), 100);
                this.updateStageStatus(2, __('status_completed', 'Completed'), 100);
                $('.aitrfoel-stage-2').show();
                
                // Highlight completed translation if we have completion info
                if (status.completed_page_id && status.completed_language) {
                    this.highlightCompletedTranslation(status.completed_page_id, status.completed_language);
                }
            }

            // Update overall progress info
            if (status.completed_items && status.total_items) {
                const jobProgress = `Processing ${status.completed_items}/${status.total_items} pages`;
                if (stage === 1) {
                    this.updateStageDescription(1, jobProgress);
                } else if (stage === 2) {
                    this.updateStageDescription(2, jobProgress);
                }
            }
        },

        /**
         * Expand source page accordion when translation starts
         */
        expandSourcePageForTranslation: function(pageId) {
            const $sourceRow = $(`.aitrfoel-source-page[data-page-id="${pageId}"]`);
            const $expandButton = $(`.aitrfoel-expand-button[data-source-id="${pageId}"]`);
            
            
            if ($expandButton.length > 0 && String($expandButton.data('expanded')) !== 'true') {
                this.toggleTranslations(pageId, true, $expandButton);
            } else {
            }
        },

        /**
         * Highlight and scroll to completed translation
         */
        highlightCompletedTranslation: function(sourcePageId, targetLanguage) {
            // Find the translation row for this page and language
            const $translationRow = $(`.aitrfoel-translation-page[data-parent-id="${sourcePageId}"]`)
                .filter(function() {
                    return $(this).find('.column-language').text().toLowerCase().includes(targetLanguage.toLowerCase());
                });

            if ($translationRow.length > 0) {
                // Add highlight class
                $translationRow.addClass('aitrfoel-translation-highlight');
                
                // Scroll to the row
                $('html, body').animate({
                    scrollTop: $translationRow.offset().top - 100
                }, 1000);
                
                // Remove highlight after animation
                setTimeout(() => {
                    $translationRow.removeClass('aitrfoel-translation-highlight');
                }, 3000);
            }
        },

        /**
         * Restore stage progress from localStorage
         */
        restoreStageProgress: function() {
            const saved = localStorage.getItem('stageProgressState');
            if (saved) {
                try {
                    const state = JSON.parse(saved);
                    if (state.active) {
                        this.stageProgress = state;
                        $('#aitrfoel-estimated-words').hide();
                        $('#aitrfoel-stage-progress').show().addClass('active');
                        
                        // Restore stage states
                        this.updateStageStatus(1, 'active', state.stage1Progress);
                        this.updateStageStatus(2, 'pending', state.stage2Progress);
                    }
                } catch (e) {
                    console.error('Failed to restore stage progress:', e);
                }
            }
        },

        /**
         * Legacy flip loader compatibility - now redirects to stage progress
         */
        startFlipLoader: function(totalJobs = 0, completedJobs = 0) {
            this.startStageProgress(totalJobs, completedJobs);
        },

        updateFlipProgress: function(completedJobs, totalJobs) {
            if (this.stageProgress) {
                this.stageProgress.completedJobs = completedJobs;
                this.stageProgress.totalJobs = totalJobs;
                
                // Update stage 1 description with job progress
                const progressText = totalJobs > 0 
                    ? `Processing ${completedJobs}/${totalJobs} pages`
                    : 'Working on translations';
                
                this.updateStageDescription(1, progressText);
                
                localStorage.setItem('stageProgressState', JSON.stringify({
                    active: true,
                    ...this.stageProgress
                }));
            }
        },

        stopFlipLoader: function() {
            this.stopStageProgress();
        },

        showNotice: function(message, type = 'info') {
            // Modern toast notification
            const icons = { success: '✅', error: '❌', warning: '⚠️', info: 'ℹ️' };
            const icon = icons[type] || icons.info;
            const toast = $(`<div class="aitrfoel-toast aitrfoel-toast-${type}"><span class="aitrfoel-toast-icon">${icon}</span><span class="aitrfoel-toast-msg">${message}</span><button class="aitrfoel-toast-close">&times;</button></div>`);
            
            let container = $('#aitrfoel-toast-container');
            if (!container.length) {
                container = $('<div id="aitrfoel-toast-container"></div>').appendTo('body');
            }
            
            container.append(toast);
            requestAnimationFrame(function() { toast.addClass('aitrfoel-toast-show'); });
            
            toast.find('.aitrfoel-toast-close').on('click', function() {
                toast.removeClass('aitrfoel-toast-show');
                setTimeout(function() { toast.remove(); }, 300);
            });
            
            setTimeout(function() {
                toast.removeClass('aitrfoel-toast-show');
                setTimeout(function() { toast.remove(); }, 300);
            }, 6000);
        },

        bindEvents: function() {
            // Checkbox selection events
            $(document).on('change', '.aitrfoel-page-checkbox', this.handlePageSelection);
            $(document).on('change', '#cb-select-all, #cb-select-all-posts', this.handleSelectAll);

            // Translation checkbox selection events
            $(document).on('change', '.aitrfoel-translation-checkbox', this.handleTranslationCheckboxChange);
            $(document).on('click', '#aitrfoel-delete-translations-btn', this.handleBulkDeleteTranslations);
            
            // Language selection events
            $(document).on('click', '#aitrfoel-select-languages', this.toggleLanguageDropdown);
            $(document).on('change', '.aitrfoel-language-checkbox', this.handleLanguageSelection);
            $(document).on('change', '.aitrfoel-select-all-missing', this.handleSelectAllMissing);
            
            // Source language selection events
            $(document).on('click', '#aitrfoel-select-source-language', this.toggleSourceDropdown);
            $(document).on('change', 'input[name="source-language"]', this.handleSourceLanguageSelection);
            
            // Bulk action events
            $(document).on('click', '#aitrfoel-start-translation', this.startTranslation);
            $(document).on('click', '#aitrfoel-bulk-settings', this.openSettings);
            
            // Delete page events
            $(document).on('click', '.aitrfoel-delete-page', this.handleDeletePage);
            
            // Close dropdown when clicking outside
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.aitrfoel-target-languages').length) {
                    $('#aitrfoel-languages-dropdown').hide();
                }
                if (!$(e.target).closest('.aitrfoel-source-languages').length) {
                    $('#aitrfoel-source-dropdown').hide();
                }
                if (!$(e.target).closest('.aitrfoel-row-more').length) {
                    $('.aitrfoel-row-more[open]').removeAttr('open');
                }
            });
        },

        setupLanguageDropdown: function() {
            const languages = aitrfoelDashboard.languages || {};
            const dropdown = $('#aitrfoel-languages-dropdown');
            let html = '';
            
            // Add "Select all missing" option (Pro only)
            if (aitrfoelDashboard.is_pro) {
                html += '<div class="aitrfoel-dropdown-item">';
                html += '<label><input type="checkbox" class="aitrfoel-select-all-missing"> Select all missing</label>';
                html += '</div>';
                html += '<div class="aitrfoel-dropdown-divider"></div>';
            }
            
            // Add individual language options
            Object.keys(languages).forEach(function(code) {
                html += '<div class="aitrfoel-dropdown-item">';
                html += '<label>';
                html += '<input type="checkbox" class="aitrfoel-language-checkbox" value="' + code + '"> ';
                html += languages[code] + ' (' + code.toUpperCase() + ')';
                html += '</label>';
                html += '</div>';
            });

            // Free version: add upgrade notice
            if (!aitrfoelDashboard.is_pro) {
                html += '<div class="aitrfoel-dropdown-divider"></div>';
                html += '<div class="aitrfoel-dropdown-item" style="padding: 8px 12px; background: linear-gradient(135deg, #f0f6ff, #e8f0fe); font-size: 12px; color: #1a3d6e;">';
                html += '⚡ Free: 1 language at a time. <a href="' + aitrfoelDashboard.upgrade_url + '" target="_blank" style="font-weight:600;color:#1a56db;">Upgrade to Pro</a>';
                html += '</div>';
            }
            
            dropdown.html(html);
        },

        handlePageSelection: function() {
            const selectedPages = $('.aitrfoel-page-checkbox:checked').length;
            Dashboard.updateBulkBar(selectedPages);
            Dashboard.updateEstimatedWords();
            Dashboard.updateLanguageDropdown(); // Update available languages based on selection
            
            // Auto-expand/collapse accordion based on checkbox selection
            Dashboard.handleAccordionExpansion(this);
        },

        handleSelectAll: function() {
            const isChecked = $(this).is(':checked');
            // Only affect checkboxes in the active tab panel
            const $activePanel = $('.aitrfoel-tab-panel.active');
            if ($activePanel.length) {
                $activePanel.find('.aitrfoel-page-checkbox:not(:disabled)').prop('checked', isChecked);
            } else {
                $('.aitrfoel-page-checkbox:not(:disabled)').prop('checked', isChecked);
            }
            Dashboard.handlePageSelection();
        },

        updateBulkBar: function(selectedCount) {
            // Panel is always visible, just update the count and button state
            const text = selectedCount > 0 
                ? selectedCount + ' page' + (selectedCount > 1 ? 's' : '') + ' selected'
                : __('choose_pages', 'Choose pages to translate');
            $('#aitrfoel-estimated-words').text(text);
            
            // Update start button state based on selections
            this.updateStartButtonState();
        },

        updateStartButtonState: function() {
            const selectedPages = $('.aitrfoel-page-checkbox:checked').length;
            const selectedLanguages = $('.aitrfoel-language-checkbox:checked').length;
            const canStart = selectedPages > 0 && selectedLanguages > 0;
            const startButton = $('#aitrfoel-start-translation');
            
            // Update dropdowns state based on page selection
            this.updateDropdownsState(selectedPages > 0);
            
            if (canStart) {
                startButton.prop('disabled', false)
                          .removeClass('button-disabled')
                          .attr('title', 'Start translating ' + selectedPages + ' pages to ' + selectedLanguages + ' languages');
            } else if (selectedPages === 0) {
                startButton.prop('disabled', true)
                          .addClass('button-disabled')
                          .attr('title', __('select_page_tooltip', __('select_page_tooltip', 'Select at least one page to start translation')));
            } else {
                startButton.prop('disabled', true)
                          .addClass('button-disabled')
                          .attr('title', __('select_lang_tooltip', __('select_lang_tooltip', 'Select target languages to start translation')));
            }
        },

        updateDropdownsState: function(enabled) {
            const sourceDropdown = $('.aitrfoel-source-languages');
            const targetDropdown = $('.aitrfoel-target-languages');
            const sourceButton = $('#aitrfoel-select-source-language');
            const targetButton = $('#aitrfoel-select-languages');
            
            if (enabled) {
                // Enable dropdowns
                sourceDropdown.removeClass('disabled');
                targetDropdown.removeClass('disabled');
                sourceButton.prop('disabled', false)
                           .attr('title', 'Select source language for translation');
                targetButton.prop('disabled', false)
                          .attr('title', 'Select target languages for translation');
            } else {
                // Disable dropdowns
                sourceDropdown.addClass('disabled');
                targetDropdown.addClass('disabled');
                sourceButton.prop('disabled', true)
                           .attr('title', 'Select pages first to choose languages');
                targetButton.prop('disabled', true)
                          .attr('title', 'Select pages first to choose languages');
                
                // Close any open dropdowns
                $('#aitrfoel-source-dropdown, #aitrfoel-languages-dropdown').hide();
                
                // Clear selected languages
                $('.aitrfoel-language-checkbox:checked').prop('checked', false);
                targetButton.text(aitrfoelDashboard.strings.select_languages);
            }
        },

        updateLanguageDropdown: function() {
            const selectedCheckboxes = $('.aitrfoel-page-checkbox:checked');
            const languages = aitrfoelDashboard.languages || {};
            const dropdown = $('#aitrfoel-languages-dropdown');
            
            if (selectedCheckboxes.length === 0) {
                // No pages selected - show all languages
                this.setupLanguageDropdown();
                return;
            }
            
            // Calculate missing languages for selected pages
            const allMissingLanguages = new Set();
            
            selectedCheckboxes.each(function() {
                const missingLangs = JSON.parse($(this).attr('data-missing-languages') || '[]');
                missingLangs.forEach(lang => allMissingLanguages.add(lang));
            });
            
            // Convert to array and sort
            const missingLanguagesArray = Array.from(allMissingLanguages).sort();
            
            // Build dropdown HTML with only missing languages
            let html = '';
            
            if (missingLanguagesArray.length > 0) {
                // Add "Select all missing" option (Pro only)
                if (aitrfoelDashboard.is_pro) {
                    html += '<div class="aitrfoel-dropdown-item">';
                    html += '<label><input type="checkbox" class="aitrfoel-select-all-missing"> Select all missing</label>';
                    html += '</div>';
                    html += '<div class="aitrfoel-dropdown-divider"></div>';
                }
                
                // Add individual missing language options
                missingLanguagesArray.forEach(function(code) {
                    if (languages[code]) {
                        html += '<div class="aitrfoel-dropdown-item">';
                        html += '<label>';
                        html += '<input type="checkbox" class="aitrfoel-language-checkbox" value="' + code + '"> ';
                        html += languages[code] + ' (' + code.toUpperCase() + ')';
                        html += '</label>';
                        html += '</div>';
                    }
                });

                // Free version: add upgrade notice
                if (!aitrfoelDashboard.is_pro) {
                    html += '<div class="aitrfoel-dropdown-divider"></div>';
                    html += '<div class="aitrfoel-dropdown-item" style="padding: 8px 12px; background: linear-gradient(135deg, #f0f6ff, #e8f0fe); font-size: 12px; color: #1a3d6e;">';
                    html += '⚡ Free: 1 language at a time. <a href="' + aitrfoelDashboard.upgrade_url + '" target="_blank" style="font-weight:600;color:#1a56db;">Upgrade to Pro</a>';
                    html += '</div>';
                }
            } else {
                // No missing languages
                html += '<div class="aitrfoel-dropdown-item" style="color: #666; font-style: italic;">';
                html += escapeHtml(aitrfoelDashboard.strings.all_fully_translated);
                html += '</div>';
            }
            
            dropdown.html(html);
            
            // Clear any selected languages that are no longer available
            $('.aitrfoel-language-checkbox:checked').each(function() {
                const langCode = $(this).val();
                if (!allMissingLanguages.has(langCode)) {
                    $(this).prop('checked', false);
                }
            });
            
            // Update language selection display
            this.handleLanguageSelection();
        },

        updateEstimatedWords: function() {
            const selectedPages = $('.aitrfoel-page-checkbox:checked').length;
            const selectedLanguages = $('.aitrfoel-language-checkbox:checked').length;
            
            if (selectedPages === 0) {
                $('#aitrfoel-estimated-words').text(aitrfoelDashboard.strings.choose_pages);
                return;
            }
            
            if (selectedLanguages === 0) {
                var selStr = selectedPages === 1
                    ? aitrfoelDashboard.strings.selected_pages.replace('%d', selectedPages)
                    : aitrfoelDashboard.strings.selected_pages_plural.replace('%d', selectedPages);
                $('#aitrfoel-estimated-words').text(selStr);
                return;
            }
            
            const totalJobs = selectedPages * selectedLanguages;
            var readyStr = totalJobs === 1
                ? aitrfoelDashboard.strings.ready_jobs.replace('%d', totalJobs)
                : aitrfoelDashboard.strings.ready_jobs_plural.replace('%d', totalJobs);
            $('#aitrfoel-estimated-words').text(readyStr);
        },

        toggleLanguageDropdown: function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            // Don't open if disabled
            if ($('.aitrfoel-target-languages').hasClass('disabled')) {
                return;
            }
            
            $('#aitrfoel-languages-dropdown').toggle();
        },

        toggleSourceDropdown: function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            // Don't open if disabled
            if ($('.aitrfoel-source-languages').hasClass('disabled')) {
                return;
            }
            
            $('#aitrfoel-source-dropdown').toggle();
        },

        handleSourceLanguageSelection: function() {
            const selectedRadio = $('input[name="source-language"]:checked');
            const selectedValue = selectedRadio.val();
            const selectedText = selectedRadio.parent().text().replace(/^\s*[\u{1F300}-\u{1FAFF}\u2600-\u27BF]+\s*/u, '').trim();
            
            // Update button text
            $('#aitrfoel-select-source-language').text(selectedText);
            
            // Close dropdown
            $('#aitrfoel-source-dropdown').hide();
        },

        handleLanguageSelection: function() {
            const selectedLanguages = $('.aitrfoel-language-checkbox:checked');
            const selectedCount = selectedLanguages.length;

            // Free version: limit to one language at a time — grey out others
            if (!aitrfoelDashboard.is_pro) {
                if (selectedCount >= 1) {
                    // Keep only the last checked, uncheck others
                    if (selectedCount > 1) {
                        $('.aitrfoel-language-checkbox:checked').not(':last').prop('checked', false);
                    }
                    // Disable (grey out) all unchecked language checkboxes
                    $('.aitrfoel-language-checkbox').not(':checked').prop('disabled', true)
                        .closest('.aitrfoel-dropdown-item').css('opacity', '0.45');
                } else {
                    // Nothing selected — re-enable all
                    $('.aitrfoel-language-checkbox').prop('disabled', false)
                        .closest('.aitrfoel-dropdown-item').css('opacity', '1');
                }
            }

            const finalCount = $('.aitrfoel-language-checkbox:checked').length;

            // Update button text
            if (finalCount > 0) {
                const text = aitrfoelDashboard.strings.languages_selected.replace('%d', finalCount);
                $('#aitrfoel-select-languages').text(text);
            } else {
                $('#aitrfoel-select-languages').text(aitrfoelDashboard.strings.select_languages);
            }
            
            // Update start button state
            Dashboard.updateStartButtonState();
            Dashboard.updateEstimatedWords();
        },

        handleSelectAllMissing: function() {
            // Free version: don't allow select all
            if (!aitrfoelDashboard.is_pro) {
                $(this).prop('checked', false);
                return;
            }
            const isChecked = $(this).is(':checked');
            $('.aitrfoel-language-checkbox').prop('checked', isChecked);
            Dashboard.handleLanguageSelection();
        },

        startTranslation: function() {
            // ??????: ????????? ??? ?? ??? ????????? ????????
            const activeTranslation = localStorage.getItem('aitrfoel_active_translation');
            if (activeTranslation) {
                Dashboard.showNotice(' Translation is already in progress. Please wait for completion or refresh the page.', 'warning');
                return;
            }
            
            const selectedPages = $('.aitrfoel-page-checkbox:checked').map(function() {
                return $(this).val();
            }).get();
            
            const selectedLanguages = $('.aitrfoel-language-checkbox:checked').map(function() {
                return $(this).val();  
            }).get();
            
            if (selectedPages.length === 0) {
                Dashboard.showNotice(__('select_page_warning', __('select_page_warning', 'Please select at least one page to translate.')), 'warning');
                return;
            }
            
            if (selectedLanguages.length === 0) {
                Dashboard.showNotice(__('select_language_warning', __('select_language_warning', 'Please select at least one target language.')), 'warning');
                return;
            }
            
            // ? IMMEDIATE FEEDBACK: Show animation BEFORE AJAX call
            Dashboard.showTranslationProgress();
            
            // Make AJAX call to start translation
            $.ajax({
                url: aitrfoelDashboard.ajax_url,
                type: 'POST',
                data: {
                    action: 'aitrfoel_start_bulk_translation',
                    nonce: aitrfoelDashboard.nonce,
                    pages: selectedPages,
                    languages: selectedLanguages,
                    source_language: $('input[name="source-language"]:checked').val() || 'auto'
                },
                success: function(response) {
                    if (response.success) {
                        const d = response.data;
                        Dashboard.saveActiveTranslation(d.job_id, 5);
                        // Process items one-by-one to avoid 504 timeouts
                        Dashboard.processQueueItems(d.job_id, d.items, 0, 0, 0, d.source_language);
                    } else {
                        // Check if this is a structured error (limit_reached, cooldown, etc.)
                        const errorData = response.data || {};

                        // Handle translation errors with proper modals
                        Dashboard.handleTranslationError(errorData);

                        // Reset UI on error
                        Dashboard.resetTranslationUI();
                    }
                },
                error: function() {
                    Dashboard.showError('Network error. Please try again.');

                    // Reset UI on error
                    Dashboard.resetTranslationUI();
                }
            });
        },

        showTranslationProgress: function() {
            const selectedPages = $('.aitrfoel-page-checkbox:checked').map(function() {
                return $(this).val();
            }).get();
            const selectedLanguages = $('.aitrfoel-language-checkbox:checked').length;
            const totalJobs = selectedPages.length * selectedLanguages;
            
            // Store pages being translated for accordion persistence
            this.translatingPageIds = selectedPages;
            
            // Auto-expand accordions for pages being translated
            selectedPages.forEach(pageId => {
                this.expandSourcePageForTranslation(pageId);
            });
            
            // Show animated progress
            this.startAnimatedProgress();
            
            // ?? Start the elapsed timer
            this.startElapsedTimer();
            
            // Show AI preparation state with dots animation
            $('#aitrfoel-estimated-words')
                .addClass('translating')
                .html(__('waiting_for_ai', 'AI is preparing your translations') + '<span class="aitrfoel-dots"><span class="dot">.</span><span class="dot">.</span><span class="dot">.</span></span>');
            
            // ????????? ??????
            const button = $('#aitrfoel-start-translation');
            button.addClass('loading')
                  .removeClass('button-primary success')
                .html('⏳ ' + __('translating', 'Translating content...'))
                  .addClass('non-clickable');
            
            $('#aitrfoel-bulk-bar').addClass('processing');
        },

        /**
         * ?? PRE-ANALYSIS: ?????? ???? ????? ???????? ????????
         */
        runPreAnalysis: function(selectedPages, selectedLanguages) {
            const sourceLanguage = $('input[name="source-language"]:checked').val() || 'auto';
            
            // ?????????? ??????
            this.showAnalysisLoader();
            
            $.ajax({
                url: aitrfoelDashboard.ajax_url,
                type: 'POST',
                data: {
                    action: 'aitrfoel_pre_analyze_bulk',
                    nonce: aitrfoelDashboard.nonce,
                    pages: selectedPages,
                    languages: selectedLanguages,
                    source_language: sourceLanguage
                },
                success: (response) => {
                    this.hideAnalysisLoader();
                    
                    if (response.success) {
                        const data = response.data;
                        
                        if (data.can_proceed) {
                            // ? ?????????? ???? - ?????????? ????????????? ? ?????????
                            this.showAnalysisSuccess(data, selectedPages, selectedLanguages, sourceLanguage);
                        } else {
                            // ? ???????????? ???? - ?????????? ??????
                            this.showAnalysisError(data);
                        }
                    } else {
                        // ?????? ???????
                        this.showNotice(response.data?.message || __('pre_analysis_failed', __('pre_analysis_failed', 'Pre-analysis failed')), __('status_error', 'Error'));
                    }
                },
                error: () => {
                    this.hideAnalysisLoader();
                    this.showNotice(__('network_error_analysis', __('network_error_analysis', 'Network error during pre-analysis')), __('status_error', 'Error'));
                }
            });
        },

        /**
         * ???????? ?????? ???????
         */
        showAnalysisLoader: function() {
            const $button = $('#aitrfoel-start-translation');
            $button.prop('disabled', true)
                   .html('🔍 Analyzing...')
                   .addClass('loading');
        },

        /**
         * ?????? ?????? ???????
         */
        hideAnalysisLoader: function() {
            const $button = $('#aitrfoel-start-translation');
            $button.prop('disabled', false)
                   .removeClass('loading')
                   .html('🚀 Start Translation');
        },

        /**
         * ???????? ???????? ????????? ??????? ? ??????????????
         */
        showAnalysisSuccess: function(data, selectedPages, selectedLanguages, sourceLanguage) {
            const modal = this.createAnalysisModal(data);
            
            // ?????????? ????????? ????
            $('body').append(modal);
            
            // ?????????? ?????? "Start Translation"
            $('#aitrfoel-analysis-confirm').on('click', () => {
                $('#aitrfoel-analysis-modal').remove();
                this.proceedWithTranslation(selectedPages, selectedLanguages, sourceLanguage);
            });
            
            // ?????????? ?????? "Cancel"
            $('#aitrfoel-analysis-cancel').on('click', () => {
                $('#aitrfoel-analysis-modal').remove();
            });
            
            // ???????? ?? ????? ??? ?????????? ????
            $('#aitrfoel-analysis-modal').on('click', function(e) {
                if ($(e.target).hasClass('aitrfoel-modal-overlay')) {
                    $(this).remove();
                }
            });
        },

        /**
         * ??????? HTML ?????????? ???? ? ???????????? ???????
         */
        createAnalysisModal: function(data) {
            const breakdown = data.breakdown || [];
            const queuePreview = data.queue_preview || [];
            
            let breakdownHTML = '';
            breakdown.forEach(item => {
                const wordsPerPage = item.words || 0;
                const languagesCount = Array.isArray(item.languages) ? item.languages.length : 0;
                const totalWords = item.total_words || 0;
                
                breakdownHTML += `
                    <tr>
                        <td>${item.page_title || 'Untitled'}</td>
                        <td>${wordsPerPage.toLocaleString()}</td>
                        <td>${languagesCount}</td>
                        <td><strong>${totalWords.toLocaleString()}</strong></td>
                    </tr>
                `;
            });
            
            const statusClass = data.can_proceed ? 'success' : __('status_error', 'Error');
            const statusIcon = data.can_proceed ? '✅' : '⚠️';
            const statusText = data.can_proceed ? 'Sufficient words available' : 'Not enough words';
            
            const totalWordsNeeded = data.total_words_needed || 0;
            const wordLimit = data.word_limit || 0;
            const wordsUsed = data.words_used || 0;
            const wordsRemaining = data.words_left || 0;
            const isUnlimited = wordLimit === -1 || wordsRemaining === -1;
            const wordsAfter = isUnlimited ? -1 : wordsRemaining - totalWordsNeeded;
            
            return `
                <div id="aitrfoel-analysis-modal" class="aitrfoel-modal-overlay">
                    <div class="aitrfoel-modal-content aitrfoel-analysis-panel">
                        <button class="aitrfoel-modal-close" onclick="jQuery('#aitrfoel-analysis-modal').remove()">&times;</button>
                        
                        <h3>📊 Translation Analysis</h3>
                        
                        <div class="aitrfoel-analysis-summary">
                            <p>Selected: <strong>${data.pages_count || 0} pages &times; ${data.languages_count || 0} languages = ${data.total_jobs || 0} jobs</strong></p>
                            <p>Estimated words: <strong>${totalWordsNeeded.toLocaleString()}</strong></p>
                            <p>Your limit: <strong>${isUnlimited ? 'Unlimited' : wordLimit.toLocaleString()}</strong></p>
                            <p>Current usage: <strong>${wordsUsed.toLocaleString()}</strong></p>
                            <p>Available: <strong>${isUnlimited ? 'Unlimited' : wordsRemaining.toLocaleString()}</strong></p>
                            ${data.can_proceed ? (isUnlimited ? '' : `<p>After translation: <strong>${wordsAfter.toLocaleString()} words left</strong></p>`) : ''}
                        </div>
                        
                        <div class="aitrfoel-analysis-status ${statusClass}">
                            ${statusIcon} ${statusText}
                        </div>
                        
                        <table class="aitrfoel-breakdown-table">
                            <thead>
                                <tr>
                                    <th>Page</th>
                                    <th>Words</th>
                                    <th>Languages</th>
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${breakdownHTML}
                            </tbody>
                        </table>
                        
                        <div class="aitrfoel-modal-actions">
                            ${data.can_proceed ? 
                                '<button id="aitrfoel-analysis-confirm" class="button button-primary">🚀 Start Translation</button>' :
                                '<button onclick="window.open(\'' + (data.upgrade_url || '') + '\', \'_blank\')" class="button button-primary">⬆️ Upgrade Plan</button>'
                            }
                            <button id="aitrfoel-analysis-cancel" class="button">Cancel</button>
                        </div>
                    </div>
                </div>
            `;
        },

        /**
         * ???????? ?????? ??????? (???????????? ????)
         */
        showAnalysisError: function(data) {
            const modal = this.createAnalysisModal(data);
            $('body').append(modal);
            
            // ?????????? ?????? "Cancel"
            $('#aitrfoel-analysis-cancel').on('click', () => {
                $('#aitrfoel-analysis-modal').remove();
            });
            
            // ???????? ?? ????? ??? ?????????? ????
            $('#aitrfoel-analysis-modal').on('click', function(e) {
                if ($(e.target).hasClass('aitrfoel-modal-overlay')) {
                    $(this).remove();
                }
            });
        },

        /**
         * ?????????? ? ????????? ????? ?????????????
         */
        proceedWithTranslation: function(selectedPages, selectedLanguages, sourceLanguage) {
            // ?????????? ????????
            this.showTranslationProgress();
            
            // Make AJAX call to start translation
            $.ajax({
                url: aitrfoelDashboard.ajax_url,
                type: 'POST',
                data: {
                    action: 'aitrfoel_start_bulk_translation',
                    nonce: aitrfoelDashboard.nonce,
                    pages: selectedPages,
                    languages: selectedLanguages,
                    source_language: sourceLanguage
                },
                success: function(response) {
                    if (response.success) {
                        const d = response.data;
                        Dashboard.saveActiveTranslation(d.job_id, 5);
                        // Process items one-by-one to avoid 504 timeouts
                        Dashboard.processQueueItems(d.job_id, d.items, 0, 0, 0, d.source_language);
                    } else {
                        // Handle errors with enhanced error modals
                        Dashboard.handleTranslationError(response.data);
                    }
                },
                error: function() {
                    Dashboard.showError('Network error. Please try again.');
                }
            });
        },

        /**
         * ?? ????????? ?????? ? ??????????? ?????????? ??????
         */
        handleTranslationError: function(errorData) {
            
            const errorType = errorData.type || errorData.error_type;
            
            if (errorType === 'limit_reached' || errorType === 'limit_reached_during_process') {
                this.showLimitReachedModal(errorData);
            } else if (errorType === 'cooldown') {
                this.showCooldownModal(errorData);
            } else if (errorType === 'insufficient_words') {
                this.showInsufficientWordsModal(errorData);
            } else {
                // Generic error
                this.showError(errorData.message || __('translation_failed_start', __('translation_failed_start', 'Translation failed to start.')));
            }
            
            // Stop animated progress interval so it doesn't overwrite the reset
            this.stopAnimatedProgress();
            
            // Reset estimated words text
            $('#aitrfoel-estimated-words')
                .removeClass('translating')
                .text(__('choose_pages', 'Choose pages to translate'));
            
            // Reset the button state
            $('#aitrfoel-start-translation')
                .removeClass('loading non-clickable')
                .addClass('button-primary')
                .html('🚀 Start Translation');
            $('#aitrfoel-bulk-bar').removeClass('processing');
        },

        /**
         * ????????? ????: ????? ?????????
         */
        showLimitReachedModal: function(errorData) {
            const errorInfo = errorData.error_info || {};
            const completedJobs = errorInfo.completed_jobs || 0;
            const totalJobs = errorInfo.total_jobs || 0;
            const upgradeUrl = errorData.upgrade_url || errorInfo.upgrade_url || '#';
            
            const modal = `
                <div id="aitrfoel-error-modal" class="aitrfoel-modal-overlay" style="opacity: 1 !important;">
                    <div class="aitrfoel-modal-content aitrfoel-error-modal">
                        <button class="aitrfoel-modal-close" onclick="jQuery('#aitrfoel-error-modal').remove()">&times;</button>
                        
                        <h3> Translation Paused: Limit Reached</h3>
                        
                        <div class="aitrfoel-error-message">
                            <p>${errorData.message || 'Word limit exceeded during translation.'}</p>
                        </div>
                        
                        ${totalJobs > 0 ? `
                        <div class="aitrfoel-error-stats">
                            <p>Completed: <strong>${completedJobs} of ${totalJobs} jobs</strong></p>
                            <div class="aitrfoel-progress-bar">
                                <div class="aitrfoel-progress-fill" style="width: ${(completedJobs/totalJobs*100)}%"></div>
                            </div>
                        </div>
                        ` : ''}
                        
                        <div class="aitrfoel-error-details">
                            <p>Your translation was paused when the word limit was reached.</p>
                            <p>Upgrade your plan to continue translating.</p>
                        </div>
                        
                        <div class="aitrfoel-modal-actions">
                            <button onclick="window.open('${upgradeUrl}', '_blank')" class="button button-primary">⬆️ Upgrade Plan</button>
                            <button onclick="jQuery('#aitrfoel-error-modal').remove()" class="button">Close</button>
                        </div>
                    </div>
                </div>
            `;
            
            $('body').append(modal);
        },

        /**
         * ????????? ????: Cooldown
         */
        showCooldownModal: function(errorData) {
            const upgradeUrl = errorData.upgrade_url || '#';
            
            const modal = `
                <div id="aitrfoel-error-modal" class="aitrfoel-modal-overlay" style="opacity: 1 !important;">
                    <div class="aitrfoel-modal-content aitrfoel-error-modal">
                        <button class="aitrfoel-modal-close" onclick="jQuery('#aitrfoel-error-modal').remove()">&times;</button>
                        
                        <h3>⏳ Cooldown Period</h3>
                        
                        <div class="aitrfoel-error-message">
                            <p>${errorData.message || 'You are in cooldown period.'}</p>
                        </div>
                        
                        <div class="aitrfoel-error-details">
                            <p>Free plan users must wait before translating again.</p>
                            <p>Upgrade to remove cooldown restrictions.</p>
                        </div>
                        
                        <div class="aitrfoel-modal-actions">
                            <button onclick="window.open('${upgradeUrl}', '_blank')" class="button button-primary">⬆️ Upgrade Plan</button>
                            <button onclick="jQuery('#aitrfoel-error-modal').remove()" class="button">Close</button>
                        </div>
                    </div>
                </div>
            `;
            
            $('body').append(modal);
        },

        /**
         * ????????? ????: ???????????? ????
         */
        showInsufficientWordsModal: function(errorData) {
            // Use localized URL (PRO = Freemius pricing, FREE = external site)
            const upgradeUrl = aitrfoelDashboard.upgrade_url || 'https://meeptech.com/products/ai-translator-for-elementor-polylang#pricing';
            const isPro = aitrfoelDashboard.is_pro || false;
            // Support both old and new field names for backward compatibility
            const wordsNeeded = errorData.words_needed || errorData.estimated_words || 0;
            const wordsRemaining = errorData.words_remaining || errorData.words_left || 0;
            const wordsMissing = wordsNeeded - wordsRemaining;
            
            // PRO: open in same tab (Freemius), FREE: open in new tab (external)
            const upgradeAction = isPro 
                ? `window.location.href='${upgradeUrl}'` 
                : `window.open('${upgradeUrl}', '_blank')`;
            
            const modal = `
                <div id="aitrfoel-error-modal" class="aitrfoel-modal-overlay" style="opacity: 1 !important;">
                    <div class="aitrfoel-modal-content aitrfoel-error-modal">
                        <button class="aitrfoel-modal-close" onclick="jQuery('#aitrfoel-error-modal').remove()">✕</button>
                        
                        <h3>🚨 ${__('not_enough_words', 'Not Enough Words')}</h3>
                        
                        <div class="aitrfoel-error-message">
                            <p>${errorData.message || __('insufficient_words_message', 'Insufficient words for this translation.')}</p>
                        </div>
                        
                        <div class="aitrfoel-error-stats">
                            <p>${__('required', 'Required')}: <strong>${wordsNeeded.toLocaleString()} ${__('words', 'words')}</strong></p>
                            <p>${__('available', 'Available')}: <strong>${wordsRemaining.toLocaleString()} ${__('words', 'words')}</strong></p>
                            <p>${__('missing', 'Missing')}: <strong>${wordsMissing.toLocaleString()} ${__('words', 'words')}</strong></p>
                        </div>
                        
                        <div class="aitrfoel-error-details">
                            <p>${__('reduce_or_upgrade', 'Reduce your selection or upgrade your plan to proceed.')}</p>
                        </div>
                        
                        <div class="aitrfoel-modal-actions">
                            <button onclick="${upgradeAction}" class="button button-primary">⬆️ ${__('upgrade_plan', 'Upgrade Plan')}</button>
                            <button onclick="jQuery('#aitrfoel-error-modal').remove()" class="button">${__('close', 'Close')}</button>
                        </div>
                    </div>
                </div>
            `;
            
            $('body').append(modal);
        },

        /**
         * Start simple progress in estimated words area
         */
        startAnimatedProgress: function() {
            const messages = [
                __('sending_content', 'Sending content to AI'),
                __('processing_data', 'Preparing your content'),
                __('analyzing_structure', 'Analyzing page structure'),
                __('preparing_request', 'Building translation request'),
                __('waiting_for_ai', 'AI is preparing your translations'),
                __('processing_results', 'Reviewing AI response'),
                __('applying_translations', 'Applying translated content'),
                __('finalizing_creation', 'Finalizing translated pages'),
                __('optimizing_content', 'Optimizing translated output'),
                __('completing_process', 'Wrapping things up')
            ];
            
            let currentIndex = 0;
            this.updateAnimatedProgress(messages[currentIndex]);
            
            // Store interval reference for later cleanup
            this.progressInterval = setInterval(() => {
                currentIndex = (currentIndex + 1) % messages.length;
                this.updateAnimatedProgress(messages[currentIndex]);
            }, 10000); // Change message every 10 seconds
        },

        /**
         * Update progress text in estimated words area with animated dots
         */
        updateAnimatedProgress: function(text) {
            const $estimatedWords = $('#aitrfoel-estimated-words');
            if ($estimatedWords.length) {
                const dotsHtml = '<span class="aitrfoel-dots"><span class="dot">.</span><span class="dot">.</span><span class="dot">.</span></span>';
                $estimatedWords.addClass('translating').html(text + dotsHtml);
            }
        },

        /**
         * Get appropriate icon for current progress stage (kept for compatibility)
         */
        getProgressIcon: function(index) {
            const icons = ['🔄', '⏳', '🌐', '📝', '', '', '', '', '', ''];
            return icons[index % icons.length];
        },

        /**
         * Stop animated progress and reset estimated words
         */
        stopAnimatedProgress: function() {
            if (this.progressInterval) {
                clearInterval(this.progressInterval);
                this.progressInterval = null;
            }
            
            // ?? Stop the timer too
            this.stopElapsedTimer();
            
            // Reset to default state after a delay (if not already showing success/error)
            setTimeout(() => {
                const $estimatedWords = $('#aitrfoel-estimated-words');
                if (!$estimatedWords.hasClass('translating')) {
                    $estimatedWords.text(__('choose_pages', 'Choose pages to translate')).css('color', '#666');
                }
            }, 3000);
        },

        /**
         * ?? Start elapsed timer
         */
        startElapsedTimer: function() {
            this.timerStartTime = Date.now();
            $('#aitrfoel-elapsed-timer').show();
            
            // Update timer every second
            this.timerInterval = setInterval(() => {
                const elapsed = Math.floor((Date.now() - this.timerStartTime) / 1000);
                const minutes = Math.floor(elapsed / 60);
                const seconds = elapsed % 60;
                const timeString = `${minutes}:${seconds.toString().padStart(2, '0')}`;
                $('#aitrfoel-timer-value').text(timeString);
            }, 1000);
        },

        /**
         * ?? Stop elapsed timer
         */
        stopElapsedTimer: function() {
            if (this.timerInterval) {
                clearInterval(this.timerInterval);
                this.timerInterval = null;
            }
            $('#aitrfoel-elapsed-timer').hide();
            $('#aitrfoel-timer-value').text('0:00');
        },

        updateProgress: function(percentage, statusText, detailsText) {
            // Legacy function kept for compatibility
            // In new system, we use animated progress instead
            this.updateAnimatedProgress(statusText || __('processing_translation', 'Processing translation'), '⏳');
        },

        monitorTranslationProgress: function(jobId) {
            const self = this;
            let progressStep = 0;
            
            const pollInterval = setInterval(function() {
                $.ajax({
                    url: aitrfoelDashboard.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'aitrfoel_get_translation_status',
                        nonce: aitrfoelDashboard.nonce,
                        job_id: jobId
                    },
                    success: function(response) {
                        console.log('Translation status response:', response);
                        if (response.success) {
                            const status = response.data;
                            console.log('Status value:', status.status, 'Type:', typeof status.status);
                            
                            if (status.status === 'processing') {
                                // Simple progress tracking without complex stages
                                const completedItems = status.completed_items || 0;
                                const totalItems = status.total_items || 1;
                                const progressPercent = Math.round((completedItems / totalItems) * 100);
                                
                                // Update progress message based on current step
                                let progressMessage = __('processing_translation', 'Processing translation');
                                if (status.current_step) {
                                    if (status.current_step.includes('Analyzing')) {
                                        progressMessage = 'Analyzing page content';
                                    } else if (status.current_step.includes('AI Translating')) {
                                        progressMessage = 'AI translating content';
                                    } else if (status.current_step.includes('Gap-Filler')) {
                                        progressMessage = 'Refining translation quality';
                                    } else if (status.current_step.toLowerCase().includes('completed')) {
                                        progressMessage = 'Finalizing translations';
                                    }
                                }
                                
                                // Update the compact status bar
                                const statusText = `${progressMessage} (${completedItems}/${totalItems})`;
                                $('#aitrfoel-status-text').text(statusText);
                                
                                // Update animated progress text occasionally (not every poll)
                                if (Math.random() < 0.3) { // 30% chance to update text
                                    self.updateAnimatedProgress(
                                        `${progressMessage} (${completedItems}/${totalItems} pages)`,
                                        self.getProgressIcon(Math.floor(Math.random() * 10))
                                    );
                                }
                                
                                // Check for completed translations to highlight
                                if (status.completed_page_id && status.completed_language) {
                                    self.highlightCompletedTranslation(status.completed_page_id, status.completed_language);
                                }
                                
                                // Auto-expand source page if we have current page info
                                if (status.current_page_id) {
                                    self.expandSourcePageForTranslation(status.current_page_id);
                                }
                                
                                // Save progress
                                self.saveActiveTranslation(jobId, progressPercent);
                                
                            } else if (status.status === 'completed') {
                                clearInterval(pollInterval);
                                
                                // Show completion message
                                self.updateAnimatedProgress('All translations completed successfully!', '🎉');
                                
                                // Wait a moment to show completion, then clear
                                setTimeout(() => {
                                    self.clearActiveTranslation();
                                }, 2000);
                                
                                setTimeout(() => {
                                    Dashboard.showTranslationComplete(status);
                                }, 1000);
                                
                            } else if (status.status === 'failed' || status.status === 'paused') {
                                clearInterval(pollInterval);
                                self.clearActiveTranslation();
                                
                                // Reset estimated words text
                                $('#aitrfoel-estimated-words')
                                    .removeClass('translating')
                                    .text(__('choose_pages', 'Choose pages to translate'));
                                
                                // ?? ????????? error_type ??? ?????? ??????????? ?????????? ????
                                const errorType = status.error_type;
                                const errorInfo = status.error_info || {};
                                
                                if (errorType === 'limit_reached_during_process') {
                                    // ????? ????????? ?? ????? ???????? - ?????????? ??????????? ????
                                    self.showLimitReachedModal({
                                        type: errorType,
                                        message: status.error || __('translation_paused_limit', __('translation_paused_limit', 'Translation paused: word limit reached')),
                                        error_info: errorInfo,
                                        upgrade_url: errorInfo.upgrade_url
                                    });
                                } else if (errorType === 'cooldown') {
                                    self.showCooldownModal({
                                        type: errorType,
                                        message: status.error || 'Cooldown period active',
                                        upgrade_url: errorInfo.upgrade_url
                                    });
                                } else if (errorType === 'insufficient_words') {
                                    self.showInsufficientWordsModal({
                                        type: errorType,
                                        message: status.error || 'Not enough words',
                                        words_needed: errorInfo.words_needed,
                                        words_remaining: errorInfo.words_remaining,
                                        upgrade_url: errorInfo.upgrade_url
                                    });
                                } else {
                                    // ????? ??????
                                    Dashboard.showError(status.error || 'Translation failed.');
                                }
                            } else if (status.status === 'pending') {
                                self.updateAnimatedProgress(__('translation_queued', __('translation_queued', 'Translation queued, waiting to start')), '?');
                                self.saveActiveTranslation(jobId, 10);
                            }
                        }
                    },
                    error: function() {
                        // Network error handling
                        if (progressStep < 80) {
                            progressStep += 2;
                            self.updateProgress(progressStep, 'Translating pages...', 'Network delay, retrying...');
                        }
                    }
                });
            }, 1500); // Poll every 1.5 seconds
        },

        /**
         * Update stage progress based on detailed status
         */
        updateStageProgress: function(status) {
            // Parse current_step to determine stage and progress
            const currentStep = status.current_step || '';
            const details = status.details || '';
            const progress = status.progress || 0;
            
            if (currentStep.includes('🌐 AI Translating') || currentStep.includes('Analyzing') || currentStep.includes('Processing')) {
                // Stage 1: AI Translation
                this.updateStageStatus(1, 'active', Math.min(progress, 80));
                this.updateStageDescription(1, details || 'Translating with OpenAI...');
                this.updateStageStatus(2, 'pending', 0);
            } else if (currentStep.includes('Gap-Filler') || currentStep.includes('Refinement') || currentStep.includes('Finalizing')) {
                // Stage 2: Gap-Filler
                this.updateStageStatus(1, 'completed', 100);
                this.updateStageStatus(2, 'active', Math.max(0, progress - 80));
                this.updateStageDescription(2, details || 'Refining translation quality...');
            } else if (currentStep.toLowerCase().includes('completed') || currentStep.includes('✅')) {
                // Both stages completed
                this.updateStageStatus(1, 'completed', 100);
                this.updateStageStatus(2, 'completed', 100);
            } else if (currentStep.toLowerCase().includes('error') || currentStep.includes('❌') || currentStep.includes('⚠️')) {
                // Error state
                this.updateStageStatus(1, 'error', 0);
                this.updateStageStatus(2, 'error', 0);
            } else {
                // Default: Stage 1 active
                this.updateStageStatus(1, 'active', Math.min(progress, 80));
                this.updateStageDescription(1, details || 'Processing translation...');
            }
        },

        /**
         * Process bulk translation items one-by-one from JS.
         * Each call handles exactly one (page_id × language) pair to avoid 504 timeouts.
         * @param {string} jobId
         * @param {Array}  items          [{page_id, lang}, ...]
         * @param {number} index          0-based current index
         * @param {number} completedCount successfully translated so far
         * @param {number} errorCount     errors so far
         * @param {string} sourceLang
         */
        processQueueItems: function(jobId, items, index, completedCount, errorCount, sourceLang) {
            const self = this;

            if (index >= items.length) {
                // All items processed
                self.clearActiveTranslation();
                self.stopAnimatedProgress();
                self.showTranslationComplete({
                    status: 'completed',
                    completed_items: completedCount,
                    total_items: items.length,
                });
                return;
            }

            const item  = items[index];
            const total = items.length;

            // Inline progress update
            self.updateAnimatedProgress('Translating ' + (index + 1) + ' / ' + total);
            self.saveActiveTranslation(jobId, Math.round((index / total) * 100));

            // Phase 1: submit item for async translation (fast, < 5 s)
            // Falls back to synchronous processing for Elementor pages or
            // when the proxy async endpoint is not yet deployed.
            $.ajax({
                url:     aitrfoelDashboard.ajax_url,
                type:    'POST',
                timeout: 60000, // 60 s — submit is parse + enqueue, should be fast
                data: {
                    action:          'aitrfoel_submit_bulk_item',
                    nonce:           aitrfoelDashboard.nonce,
                    job_id:          jobId,
                    page_id:         item.page_id,
                    target_lang:     item.lang,
                    item_index:      index,
                    total_items:     total,
                    source_language: sourceLang || 'auto',
                },
                success: function(response) {
                    if (!response.success) {
                        console.warn('aitrfoel: submit item ' + index + ' server error, skipping');
                        self.processQueueItems(jobId, items, index + 1, completedCount, errorCount + 1, sourceLang);
                        return;
                    }

                    const d = response.data;

                    if (d.status === 'limit_reached') {
                        self.stopAnimatedProgress();
                        self.clearActiveTranslation();
                        self.handleTranslationError({
                            type:       'limit_reached_during_process',
                            message:    d.message || 'Word limit reached.',
                            error_info: { completed_jobs: completedCount, total_jobs: total },
                        });
                        self.resetTranslationUI();
                        return;
                    }

                    if (d.status === 'item_error') {
                        console.warn('aitrfoel: submit item ' + index + ' error: ' + d.error);
                        self.processQueueItems(jobId, items, index + 1, completedCount, errorCount + 1, sourceLang);
                        return;
                    }

                    // 'sync_done' = Elementor (inline placeholder processing) or all-cached
                    if (d.status === 'sync_done') {
                        if (d.source_page_id && d.target_language) {
                            self.highlightCompletedTranslation(d.source_page_id, d.target_language);
                        }
                        self.processQueueItems(jobId, items, index + 1, completedCount + 1, errorCount, sourceLang);
                        return;
                    }

                    // 'submitted' = job is enqueued on proxy; start polling
                    if (d.status === 'submitted') {
                        self.pollBulkItem(jobId, items, index, completedCount, errorCount, sourceLang, d.proxy_job_id, 0, 0);
                        return;
                    }

                    // Unknown status — skip
                    self.processQueueItems(jobId, items, index + 1, completedCount, errorCount + 1, sourceLang);
                },
                error: function(xhr) {
                    console.warn('aitrfoel: submit item ' + index + ' failed (HTTP ' + xhr.status + '), skipping');
                    self.processQueueItems(jobId, items, index + 1, completedCount, errorCount + 1, sourceLang);
                },
            });
        },

        // Poll the proxy for async job completion.
        // pollCount  = number of polls fired so far (max 72 = 6 min)
        // httpErrors = consecutive HTTP-level errors (max 3 before skipping)
        pollBulkItem: function(jobId, items, index, completedCount, errorCount, sourceLang, proxyJobId, pollCount, httpErrors) {
            const self          = this;
            const total         = items.length;
            const MAX_POLLS     = 72;   // 72 × 5 s = 6 min max wait per item
            const POLL_MS       = 5000;
            const MAX_HTTP_ERR  = 3;

            if (pollCount >= MAX_POLLS) {
                console.warn('aitrfoel: item ' + index + ' polling timed out, skipping');
                self.processQueueItems(jobId, items, index + 1, completedCount, errorCount + 1, sourceLang);
                return;
            }

            setTimeout(function() {
                $.ajax({
                    url:     aitrfoelDashboard.ajax_url,
                    type:    'POST',
                    timeout: 15000,
                    data: {
                        action:       'aitrfoel_poll_bulk_item',
                        nonce:        aitrfoelDashboard.nonce,
                        proxy_job_id: proxyJobId,
                        page_id:      items[index].page_id,
                        target_lang:  items[index].lang,
                        item_index:   index,
                        total_items:  total,
                    },
                    success: function(response) {
                        if (!response.success) {
                            console.warn('aitrfoel: poll item ' + index + ' server error, skipping');
                            self.processQueueItems(jobId, items, index + 1, completedCount, errorCount + 1, sourceLang);
                            return;
                        }

                        const d = response.data;

                        if (d.status === 'pending') {
                            self.pollBulkItem(jobId, items, index, completedCount, errorCount, sourceLang, proxyJobId, pollCount + 1, 0);
                            return;
                        }

                        if (d.status === 'limit_reached') {
                            self.stopAnimatedProgress();
                            self.clearActiveTranslation();
                            self.handleTranslationError({
                                type:       'limit_reached_during_process',
                                message:    d.error || 'Word limit reached.',
                                error_info: { completed_jobs: completedCount, total_jobs: total },
                            });
                            self.resetTranslationUI();
                            return;
                        }

                        if (d.status === 'item_error') {
                            console.warn('aitrfoel: poll item ' + index + ' error: ' + d.error);
                            self.processQueueItems(jobId, items, index + 1, completedCount, errorCount + 1, sourceLang);
                            return;
                        }

                        if (d.status === 'done') {
                            if (d.source_page_id && d.target_language) {
                                self.highlightCompletedTranslation(d.source_page_id, d.target_language);
                            }
                            self.processQueueItems(jobId, items, index + 1, completedCount + 1, errorCount, sourceLang);
                            return;
                        }

                        // Unknown — skip
                        self.processQueueItems(jobId, items, index + 1, completedCount, errorCount + 1, sourceLang);
                    },
                    error: function(xhr) {
                        const newErrors = httpErrors + 1;
                        if (newErrors < MAX_HTTP_ERR) {
                            // Transient network error — retry without burning a full pollCount
                            console.warn('aitrfoel: poll item ' + index + ' HTTP ' + xhr.status + ', retrying (' + newErrors + ')');
                            self.pollBulkItem(jobId, items, index, completedCount, errorCount, sourceLang, proxyJobId, pollCount + 1, newErrors);
                        } else {
                            console.warn('aitrfoel: poll item ' + index + ' exceeded HTTP error limit, skipping');
                            self.processQueueItems(jobId, items, index + 1, completedCount, errorCount + 1, sourceLang);
                        }
                    },
                });
            }, POLL_MS);
        },

        showTranslationComplete: function(status) {
            const button = $('#aitrfoel-start-translation');
            
            // Show success message in estimated words
            const $estimatedWords = $('#aitrfoel-estimated-words');
            $estimatedWords.removeClass('translating')
                          .text(__('translation_completed', 'Translation completed successfully!'))
                          .css('color', '#46b450');
            
            // Stop the progress interval
            this.stopAnimatedProgress();
            
            // ??????? ??????? ????? ? bounce
            button.removeClass('loading')
                  .addClass('success')
                  .html('✓ Complete!')
                  .addClass('non-clickable');
            
            $('#aitrfoel-bulk-bar').removeClass('processing').addClass(__('status_completed', 'Completed'));
            
            // Refresh word count stats from server
            Dashboard.refreshWordCountStats();
            
            // ????????? ??????? ? ???????????? ????? ????????
            setTimeout(() => {
                Dashboard.refreshTableData();
                Dashboard.highlightNewlyCreatedPages(status);
            }, 1500);
            
            // ?????????????? ????? ????? 4 ???????
            setTimeout(() => Dashboard.resetUIToInitialState(), 4000);
        },

        resetUIToInitialState: function() {
            // ??????? ???????? ???????
            this.clearActiveTranslation();
            
            // Clear translating page IDs
            this.translatingPageIds = null;
            
            // Reset estimated words to default state
            this.stopAnimatedProgress();
            
            // Reset estimated words text
            $('#aitrfoel-estimated-words')
                .removeClass('translating')
                .text(__('choose_pages', 'Choose pages to translate'));
            
            // ?????? ????? ??????
            const button = $('#aitrfoel-start-translation');
            button.removeClass('loading success non-clickable')
                  .addClass('button-primary')
                  .text('🚀 Start Translation')
                  .prop('disabled', true); // ????????? ????????????? ??? ??????
            
            // ????? ???? ?????????
            $('.aitrfoel-page-checkbox, .aitrfoel-language-checkbox').prop('checked', false);
            $('.aitrfoel-page-checkbox').prop('disabled', false);
            
            // ????? UI ?????????
            $('#aitrfoel-select-languages').text(aitrfoelDashboard.strings?.select_languages || 'Select Languages');
            $('#aitrfoel-estimated-words').text(__('choose_pages', 'Choose pages to translate'));
            $('#aitrfoel-bulk-bar').removeClass('processing completed error');
            
            // ????????? ?????????
            this.updateStartButtonState();
            this.updateDropdownsState(false);
        },

        /**
         * Refresh word count stats from server
         */
        refreshWordCountStats: function() {
            console.log('🔄 Refreshing word count stats from server...');
            
            $.ajax({
                url: aitrfoelDashboard.ajax_url,
                type: 'POST',
                data: {
                    action: 'aitrfoel_test_server_usage',
                    nonce: aitrfoelDashboard.nonce
                },
                success: function(response) {
                    if (response.success && response.data && response.data.data) {
                        const stats = response.data.data;
                        console.log('? Word count stats updated:', stats);
                        
                        // Update Words Translated
                        $('.aitrfoel-compact-stat').each(function() {
                            const label = $(this).find('.aitrfoel-compact-label').text();
                            
                            if (label.includes('Words Translated')) {
                                $(this).find('.aitrfoel-compact-value').text(stats.words_used.toLocaleString());
                            }
                            
                            if (label.includes('Words Left')) {
                                const $value = $(this).find('.aitrfoel-compact-value');
                                const isUnlimited = stats.word_limit === -1 || stats.words_left === -1;
                                $value.text(isUnlimited ? 'Unlimited' : stats.words_left.toLocaleString());
                                
                                // Update tooltip
                                $value.attr('title', isUnlimited ? 'Unlimited plan' : 'Used: ' + stats.words_used.toLocaleString() + ' of ' + stats.word_limit.toLocaleString() + ' words');
                                
                                // Add warning if low (not for unlimited)
                                if (!isUnlimited && stats.words_left <= 0) {
                                    $value.addClass('aitrfoel-compact-limit-reached');
                                } else {
                                    $value.removeClass('aitrfoel-compact-limit-reached');
                                }
                            }
                        });
                        
                        // Trigger a visual flash to show update
                        $('.aitrfoel-compact-stats-bar').addClass('aitrfoel-stats-updated');
                        setTimeout(function() {
                            $('.aitrfoel-compact-stats-bar').removeClass('aitrfoel-stats-updated');
                        }, 1000);
                    } else {
                        console.warn('❌ Could not refresh word count stats');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('? Error refreshing word count stats:', error);
                }
            });
        },

        refreshTableData: function() {
            // Store expanded accordion state before refresh
            const expandedStates = {};
            $('.aitrfoel-expand-button[data-expanded="true"]').each(function() {
                const sourceId = $(this).data('source-id');
                expandedStates[sourceId] = true;
            });
            
            // Also keep pages being translated expanded
            if (this.translatingPageIds && this.translatingPageIds.length > 0) {
                this.translatingPageIds.forEach(pageId => {
                    expandedStates[pageId] = true;
                });
            }
            
            // Add loading animation to table
            $('.aitrfoel-dashboard-table').addClass('aitrfoel-table-updating');
            
            $.ajax({
                url: aitrfoelDashboard.ajax_url,
                type: 'POST',
                data: {
                    action: 'aitrfoel_refresh_table_data',
                    nonce: aitrfoelDashboard.nonce
                },
                success: function(response) {
                    if (response.success && response.data) {
                        var pagesHtml = response.data.pages_html || response.data.html || '';
                        var postsHtml = response.data.posts_html || '';
                        var productsHtml = response.data.products_html || '';
                        
                        // Update pages tab tbody
                        var $pagesBody = $('#aitrfoel-tab-pages .aitrfoel-dashboard-table tbody');
                        // Update posts tab tbody
                        var $postsBody = $('#aitrfoel-tab-posts .aitrfoel-dashboard-table tbody');
                        // Update products tab tbody
                        var $productsBody = $('#aitrfoel-tab-products .aitrfoel-dashboard-table tbody');
                        
                        var tablesUpdated = 0;
                        var totalTables = ($pagesBody.length > 0 ? 1 : 0) + ($postsBody.length > 0 ? 1 : 0) + ($productsBody.length > 0 ? 1 : 0);
                        if (totalTables === 0) totalTables = 1; // safety
                        
                        function onTableUpdated() {
                            tablesUpdated++;
                            if (tablesUpdated >= totalTables) {
                                // Re-initialize accordion functionality
                                Dashboard.initTableAccordion();
                                
                                // Restore expanded accordion states
                                Object.keys(expandedStates).forEach(function(sourceId) {
                                    const $button = $(`.aitrfoel-expand-button[data-source-id="${sourceId}"]`);
                                    if ($button.length > 0) {
                                        Dashboard.toggleTranslations(sourceId, true, $button);
                                    }
                                });
                                
                                // Add highlight animation to updated rows
                                $('.aitrfoel-dashboard-table tbody tr').addClass('aitrfoel-row-updated');
                                
                                // Remove animations after completion
                                setTimeout(function() {
                                    $('.aitrfoel-dashboard-table').removeClass('aitrfoel-table-updating');
                                    $('.aitrfoel-row-updated').removeClass('aitrfoel-row-updated');
                                }, 1500);
                            }
                        }
                        
                        // Update pages table
                        if ($pagesBody.length > 0) {
                            $pagesBody.fadeOut(300, function() {
                                $(this).html(pagesHtml).fadeIn(300);
                                onTableUpdated();
                            });
                        }
                        
                        // Update posts table
                        if ($postsBody.length > 0) {
                            $postsBody.fadeOut(300, function() {
                                $(this).html(postsHtml).fadeIn(300);
                                onTableUpdated();
                            });
                        }
                        
                        // Update products table
                        if ($productsBody.length > 0) {
                            $productsBody.fadeOut(300, function() {
                                $(this).html(productsHtml).fadeIn(300);
                                onTableUpdated();
                            });
                        }
                        
                        // Update tab counts if provided
                        if (response.data.pages_count !== undefined) {
                            $('[data-tab="pages"] .aitrfoel-tab-count').text(response.data.pages_count);
                        }
                        if (response.data.posts_count !== undefined) {
                            $('[data-tab="posts"] .aitrfoel-tab-count').text(response.data.posts_count);
                        }
                        if (response.data.products_count !== undefined) {
                            $('[data-tab="products"] .aitrfoel-tab-count').text(response.data.products_count);
                        }
                    }
                },
                error: function() {
                    // Fallback to page reload if AJAX fails
                    Dashboard.showNotice('Refreshing page to show updated translations...', 'info');
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                }
            });
        },

        /**
         * Highlight and scroll to newly created translated pages
         */
        highlightNewlyCreatedPages: function(status) {
            // Check if we have results with newly created pages
            if (!status.results || !Array.isArray(status.results)) {
                return;
            }

            // Wait for table refresh to complete
            setTimeout(() => {
                let firstNewPage = null;
                const newPageIds = [];

                // Extract newly created page IDs from results
                status.results.forEach(result => {
                    if (result.success && result.source_page_id && result.target_language) {
                        newPageIds.push({
                            sourceId: result.source_page_id,
                            language: result.target_language,
                            newPageId: result.success,
                            title: result.page_title || 'Translated Page'
                        });
                    }
                });


                // Highlight each newly created page
                newPageIds.forEach((page, index) => {
                    // Find the translation row for this page
                    const $translationRow = $(`.aitrfoel-translation-page[data-parent-id="${page.sourceId}"]`)
                        .filter(function() {
                            return $(this).find('.column-language').text().toLowerCase().includes(page.language.toLowerCase());
                        });

                    if ($translationRow.length > 0) {
                        
                        // Store first page for scrolling
                        if (index === 0) {
                            firstNewPage = $translationRow;
                        }

                        // Add special highlight class for newly created pages
                        setTimeout(() => {
                            $translationRow.addClass('aitrfoel-newly-created-highlight');
                            
                            // Remove highlight after 4 seconds
                            setTimeout(() => {
                                $translationRow.removeClass('aitrfoel-newly-created-highlight');
                            }, 4000);
                        }, index * 200); // Stagger highlights for multiple pages
                    }
                });

                // Scroll to the first newly created page
                if (firstNewPage && firstNewPage.length > 0) {
                    
                    // Smooth scroll to the page with offset for fixed headers
                    $('html, body').animate({
                        scrollTop: firstNewPage.offset().top - 150
                    }, 1000, 'swing');
                    
                    // Show success notification
                    this.showNotice(
                        ` Successfully created ${newPageIds.length} new translation${newPageIds.length > 1 ? 's' : ''}!`, 
                        'success'
                    );
                }
            }, 2000); // Wait for table refresh to complete
        },

        showError: function(message) {
            // Show error message in estimated words
            const $estimatedWords = $('#aitrfoel-estimated-words');
            $estimatedWords.removeClass('translating')
                          .text(__('translation_failed', 'Translation failed. Please try again.'))
                          .css('color', '#dc3232');
            
            // Stop the progress interval
            this.stopAnimatedProgress();
            
            $('#aitrfoel-bulk-bar').removeClass('processing').addClass(__('status_error', 'Error'));
            // Remove any previous inline error, then show a compact one (not full-width WP notice)
            $('.aitrfoel-bulk-error-msg').remove();
            $('.aitrfoel-bulk-content').prepend('<div class="aitrfoel-bulk-error-msg"><span class="dashicons dashicons-warning"></span> ' + message + '</div>');
            
            // ??????? ?????? ? ???????? ????????? ??? ??????
            $('#aitrfoel-start-translation')
                .removeClass('loading success non-clickable')
                .addClass('button-primary')
                .text('🚀 Start Translation')
                .prop('disabled', false);
            
            // ???????? UI ? ???????? ????????? ??? ??????
            setTimeout(() => {
                Dashboard.resetUIToInitialState();
            }, 1000);
        },

        openSettings: function() {
            // Let settings-modal.js handle this via its own event binding
            if (window.eatSettingsModal) {
                window.eatSettingsModal.openModal();
            }
        },

        handleTranslationCheckboxChange: function() {
            const count = $('.aitrfoel-translation-checkbox:checked').length;
            const $bar = $('#aitrfoel-translation-bulk-bar');
            if (count > 0) {
                const label = count === 1
                    ? '1 translation selected'
                    : count + ' translations selected';
                $('#aitrfoel-translation-selected-count').text(label);
                $bar.slideDown(150);
            } else {
                $bar.slideUp(150);
            }
        },

        handleBulkDeleteTranslations: function() {
            const $checked = $('.aitrfoel-translation-checkbox:checked');
            const count = $checked.length;
            if (count === 0) return;

            const titles = $checked.map(function() {
                return '  • ' + ($(this).data('title') || '#' + $(this).val());
            }).get().join('\n');

            const confirmMessage = 'Permanently delete ' + count + ' translation(s)?\n\n' + titles + '\n\n⚠️ This action CANNOT be undone.';
            if (!confirm(confirmMessage)) return;

            const postIds = $checked.map(function() { return $(this).val(); }).get();
            const $btn = $('#aitrfoel-delete-translations-btn');
            $btn.prop('disabled', true).text('Deleting...');

            $.ajax({
                url: aitrfoelDashboard.ajax_url,
                type: 'POST',
                data: {
                    action: 'aitrfoel_bulk_delete_translations',
                    nonce: aitrfoelDashboard.nonce,
                    post_ids: postIds
                },
                success: function(response) {
                    if (response.success) {
                        const deleted = response.data.deleted || [];
                        deleted.forEach(function(id) {
                            $('tr.aitrfoel-translation-page[data-page-id="' + id + '"]').fadeOut(300, function() {
                                $(this).remove();
                            });
                        });
                        $('#aitrfoel-translation-bulk-bar').slideUp(150);
                        Dashboard.showNotice('🗑️ ' + response.data.message, 'success');
                        setTimeout(function() { window.location.reload(true); }, 1500);
                    } else {
                        Dashboard.showNotice('❌ ' + (response.data.message || 'Delete failed.'), 'error');
                        $btn.prop('disabled', false).html('🗑️ Delete selected translations');
                    }
                },
                error: function() {
                    Dashboard.showNotice('❌ Network error. Please try again.', 'error');
                    $btn.prop('disabled', false).html('🗑️ Delete selected translations');
                }
            });
        },

        handleDeletePage: function() {
            const button = $(this);
            const pageId = button.data('page-id');
            const pageTitle = button.data('page-title') || 'this page';
            const row = button.closest('tr');
            
            // Check if this is a translation using multiple methods
            // 1. Check explicit data attribute (most reliable)
            // 2. Check if row has translation class
            // 3. Check if row does NOT have source-page class
            let isTranslation = false;
            const dataAttr = button.attr('data-is-translation');
            if (dataAttr !== undefined) {
                isTranslation = (dataAttr === 'true' || dataAttr === true);
            } else {
                // Fallback: check CSS classes
                isTranslation = row.hasClass('aitrfoel-translation-page') || !row.hasClass('aitrfoel-source-page');
            }
            
            // Different confirmation message for translations vs originals
            const confirmMessage = isTranslation 
                ? `Are you sure you want to permanently delete the translation "${pageTitle}"?\n\n⚠️ This action CANNOT be undone.`
                : `Are you sure you want to move "${pageTitle}" to trash?\n\nThis action can be undone from WordPress trash.`;
            
            const confirmed = confirm(confirmMessage);
            
            if (!confirmed) {
                return;
            }
            
            // Show loading state
            button.prop('disabled', true).text('Deleting...');
            
            // Send AJAX request
            $.ajax({
                url: aitrfoelDashboard.ajax_url,
                type: 'POST',
                data: {
                    action: 'aitrfoel_delete_page',
                    nonce: aitrfoelDashboard.nonce,
                    page_id: pageId
                },
                success: function(response) {
                    if (response.success) {
                        const wasPermanentlyDeleted = response.data && response.data.permanently_deleted;
                        const actionText = wasPermanentlyDeleted 
                            ? 'permanently deleted' 
                            : 'moved to trash';
                        Dashboard.showNotice(`🗑️ "${pageTitle}" ${actionText} successfully.`, 'success');
                        
                        // Check if this is a source page or translation
                        if (row.hasClass('aitrfoel-source-page')) {
                            // This is a source page - remove all child rows
                            const sourcePageId = row.data('page-id');
                            const nextRows = row.nextAll('tr').filter(function() {
                                // Find all translation rows until next source page
                                return !$(this).hasClass('aitrfoel-source-page');
                            });
                            
                            // Remove source row and all its translation rows
                            row.add(nextRows).fadeOut(300, function() {
                                $(this).remove();
                                Dashboard.updateStartButtonState();
                            });
                        } else {
                            // This is a translation - remove only this row
                            row.fadeOut(300, function() {
                                row.remove();
                                Dashboard.updateStartButtonState();
                            });
                        }
                        
                        // If translation was permanently deleted, reload page to update available languages
                        if (wasPermanentlyDeleted) {
                            Dashboard.showNotice(`🔄 Refreshing to update available languages...`, 'info');
                            setTimeout(function() {
                                window.location.reload(true);
                            }, 1500);
                        }
                        
                    } else {
                        Dashboard.showNotice(`❌ Failed to delete "${pageTitle}": ${response.data.message}`, __('status_error', 'Error'));
                        button.prop('disabled', false).text('Delete');
                    }
                },
                error: function() {
                    Dashboard.showNotice('? Network error. Please try again.', __('status_error', 'Error'));
                    button.prop('disabled', false).text('Delete');
                }
            });
        },



        // Debug modal methods
        openDebugModal: function() {
            Dashboard.createDebugModal();
            Dashboard.runDiagnostics();
        },

        createDebugModal: function() {
            if ($('#aitrfoel-debug-modal').length > 0) {
                $('#aitrfoel-debug-modal').remove();
            }
            
            const modalHtml = `
                <div id="aitrfoel-debug-modal" class="aitrfoel-modal-overlay">
                    <div class="aitrfoel-modal-content">
                        <div class="aitrfoel-modal-header">
                            <h2>🔧 Translation Engine Debug</h2>
                            <button class="aitrfoel-modal-close">&times;</button>
                        </div>
                        <div class="aitrfoel-modal-body">
                            <div id="aitrfoel-debug-output">
                                <p>🔎 Running diagnostics...</p>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            $('body').append(modalHtml);
            
            // Add modal styles
            const modalStyles = `
                <style>
                .aitrfoel-modal-overlay {
                    position: fixed;
                    top: 0; left: 0;
                    width: 100%; height: 100%;
                    background: rgba(0,0,0,0.7);
                    z-index: 100000;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }
                .aitrfoel-modal-content {
                    background: white;
                    padding: 0;
                    border-radius: 8px;
                    max-width: 80%;
                    max-height: 80%;
                    width: 600px;
                    overflow: hidden;
                }
                .aitrfoel-modal-header {
                    background: #0073aa;
                    color: white;
                    padding: 15px 20px;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                }
                .aitrfoel-modal-header h2 {
                    margin: 0;
                    font-size: 18px;
                }
                .aitrfoel-modal-close {
                    background: none;
                    border: none;
                    color: white;
                    font-size: 24px;
                    cursor: pointer;
                }
                .aitrfoel-modal-body {
                    padding: 20px;
                    max-height: 400px;
                    overflow-y: auto;
                }
                #aitrfoel-debug-output {
                    font-family: monospace;
                    font-size: 12px;
                }
                #aitrfoel-debug-output pre {
                    background: #f5f5f5;
                    padding: 10px;
                    border-radius: 4px;
                    max-height: 200px;
                    overflow: auto;
                }
                </style>
            `;
            
            if ($('#aitrfoel-modal-styles').length === 0) {
                $('head').append('<div id="aitrfoel-modal-styles">' + modalStyles + '</div>');
            }
        },

        runDiagnostics: function() {
            const output = $('#aitrfoel-debug-output');
            output.html('<h3>🔎 Running System Diagnostics...</h3>');
            
            // Test 1: Check selected pages and languages
            const selectedPages = $('.aitrfoel-page-checkbox:checked').length;
            const selectedLanguages = $('.aitrfoel-language-checkbox:checked').length;
            
            output.append(`<p><strong>? UI State:</strong></p>`);
            output.append(`<p>Selected pages: ${selectedPages}</p>`);
            output.append(`<p>Selected languages: ${selectedLanguages}</p>`);
            
            // Test 2: AJAX connectivity test
            output.append(`<p><strong> Testing AJAX connection...</strong></p>`);
            
            $.ajax({
                url: aitrfoelDashboard.ajax_url,
                type: 'POST',
                data: {
                    action: 'aitrfoel_debug_test',
                    nonce: aitrfoelDashboard.nonce
                },
                success: function(response) {
                    output.append(`<p>? AJAX Connection: OK</p>`);
                    if (response.data) {
                        output.append(`<pre>${JSON.stringify(response.data, null, 2)}</pre>`);
                    }
                    
                    // Test 3: Try actual translation start
                    Dashboard.testTranslationStart();
                },
                error: function(xhr, status, error) {
                    output.append(`<p>? AJAX Connection: Failed (${status}: ${error})</p>`);
                    output.append(`<p>Response: ${xhr.responseText}</p>`);
                }
            });
        },

        testTranslationStart: function() {
            const output = $('#aitrfoel-debug-output');
            output.append(`<p><strong> Testing Translation Start...</strong></p>`);
            
            const selectedPages = $('.aitrfoel-page-checkbox:checked').map(function() {
                return $(this).val();
            }).get();
            
            const selectedLanguages = $('.aitrfoel-language-checkbox:checked').map(function() {
                return $(this).val();  
            }).get();
            
            if (selectedPages.length === 0 || selectedLanguages.length === 0) {
                output.append(`<p> No pages/languages selected for test</p>`);
                return;
            }
            
            $.ajax({
                url: aitrfoelDashboard.ajax_url,
                type: 'POST',
                data: {
                    action: 'aitrfoel_start_bulk_translation',
                    nonce: aitrfoelDashboard.nonce,
                    pages: selectedPages.slice(0, 1), // Test with just first page
                    languages: selectedLanguages.slice(0, 1), // Test with just first language
                    source_language: $('input[name="source-language"]:checked').val() || 'auto'
                },
                success: function(response) {
                    output.append(`<p>? Translation Start: Success</p>`);
                    output.append(`<pre>${JSON.stringify(response, null, 2)}</pre>`);
                    
                    // Monitor job progress if job_id returned
                    if (response.success && response.data && response.data.job_id) {
                        Dashboard.monitorJobProgress(response.data.job_id);
                    }
                },
                error: function(xhr, status, error) {
                    output.append(`<p>? Translation Start: Failed (${status})</p>`);
                    output.append(`<pre>Response: ${xhr.responseText}</pre>`);
                }
            });
        },

        monitorJobProgress: function(jobId) {
            const output = $('#aitrfoel-debug-output');
            output.append(`<p><strong>🕑 Monitoring Job Progress: ${jobId}</strong></p>`);
            
            let checkCount = 0;
            const maxChecks = 30; // 30 ???????? = 1 ?????? ??? ????????? 2 ???????
            
            const checkProgress = function() {
                checkCount++;
                output.append(`<p>🔎 Check ${checkCount}/${maxChecks}: Checking job status...</p>`);
                
                $.ajax({
                    url: aitrfoelDashboard.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'aitrfoel_get_translation_status',
                        nonce: aitrfoelDashboard.nonce,
                        job_id: jobId
                    },
                    success: function(response) {
                        if (response.success) {
                            const status = response.data.status;
                            const progress = response.data.progress || 0;
                            
                            output.append(`<p> Status: ${status}, Progress: ${progress}%</p>`);
                            
                            if (status === __('status_completed', 'Completed') || status === 'failed' || status === 'cancelled') {
                                output.append(`<p><strong>📋 Job ${status.toUpperCase()}</strong></p>`);
                                output.append(`<pre>${JSON.stringify(response.data, null, 2)}</pre>`);
                                return; // Stop monitoring
                            }
                            
                            if (checkCount < maxChecks) {
                                setTimeout(checkProgress, 2000); // Check again in 2 seconds
                            } else {
                                output.append(`<p>🕑 Monitoring timeout reached</p>`);
                            }
                        } else {
                            output.append(`<p>? Status check failed: ${response.data}</p>`);
                        }
                    },
                    error: function(xhr, status, error) {
                        output.append(`<p>? Status check error: ${status} - ${error}</p>`);
                    }
                });
            };
            
            // Start monitoring after 3 seconds delay
            setTimeout(checkProgress, 3000);
        },

        openMonitorModal: function() {
            Dashboard.createMonitorModal();
            Dashboard.loadJobsList();
        },

        createMonitorModal: function() {
            if ($('#aitrfoel-monitor-modal').length > 0) {
                $('#aitrfoel-monitor-modal').remove();
            }
            
            const modalHtml = `
                <div id="aitrfoel-monitor-modal" class="aitrfoel-modal-overlay">
                    <div class="aitrfoel-modal-content">
                        <div class="aitrfoel-modal-header" style="background: #28a745;">
                            <h2>📋 Jobs Monitor</h2>
                            <button class="aitrfoel-modal-close">&times;</button>
                        </div>
                        <div class="aitrfoel-modal-body">
                            <div id="aitrfoel-monitor-output">
                                <p>⏳ Loading jobs...</p>
                            </div>
                            <div style="margin-top: 15px;">
                                <button type="button" class="button" id="aitrfoel-refresh-jobs">🔄 Refresh</button>
                                <button type="button" class="button" id="aitrfoel-check-logs">🔎 Check Logs</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            $('body').append(modalHtml);
            
            // Add event handlers for modal buttons
            $(document).off('click', '#aitrfoel-refresh-jobs').on('click', '#aitrfoel-refresh-jobs', function() {
                Dashboard.loadJobsList();
            });
            
            $(document).off('click', '#aitrfoel-check-logs').on('click', '#aitrfoel-check-logs', function() {
                Dashboard.checkSystemLogs();
            });
        },

        loadJobsList: function() {
            const output = $('#aitrfoel-monitor-output');
            output.html('<p>⏳ Loading jobs list...</p>');
            
            $.ajax({
                url: aitrfoelDashboard.ajax_url,
                type: 'POST',
                data: {
                    action: 'aitrfoel_monitor_jobs',
                    nonce: aitrfoelDashboard.nonce
                },
                success: function(response) {
                    if (response.success && response.data) {
                        output.html('<h3>📋 Recent Jobs:</h3>');
                        
                        if (response.data.jobs && response.data.jobs.length > 0) {
                            response.data.jobs.forEach(function(job) {
                                const statusIcon = {
                                    'pending': '?',
                                    'processing': '', 
                                    'completed': '?',
                                    'failed': '?',
                                    'cancelled': '🚫'
                                }[job.status] || '?';
                                
                                output.append(`
                                    <div style="border: 1px solid #ddd; margin: 5px 0; padding: 10px; border-radius: 4px;">
                                        <strong>${statusIcon} ${job.job_id}</strong><br>
                                        Status: ${job.status}<br>
                                        Progress: ${job.completed_items || 0}/${job.total_items || 0}<br>
                                        Created: ${job.created_at}<br>
                                        ${job.started_at ? `Started: ${job.started_at}<br>` : ''}
                                        ${job.completed_at ? `Completed: ${job.completed_at}<br>` : ''}
                                    </div>
                                `);
                            });
                        } else {
                            output.append('<p>No jobs found.</p>');
                        }
                        
                        if (response.data.stats) {
                            output.append('<h3>📈 Statistics:</h3>');
                            output.append(`<pre>${JSON.stringify(response.data.stats, null, 2)}</pre>`);
                        }
                    } else {
                        output.html(`<p>? Failed to load jobs: ${response.data || 'Unknown error'}</p>`);
                    }
                },
                error: function(xhr, status, error) {
                    output.html(`<p>? AJAX Error: ${status} - ${error}</p>`);
                }
            });
        },

        checkSystemLogs: function() {
            const output = $('#aitrfoel-monitor-output');
            output.append('<h3>📋 System Logs:</h3>');
            output.append('<p>🔎 Checking recent activity...</p>');
            
            $.ajax({
                url: aitrfoelDashboard.ajax_url,
                type: 'POST',
                data: {
                    action: 'aitrfoel_get_system_logs',
                    nonce: aitrfoelDashboard.nonce
                },
                success: function(response) {
                    if (response.success && response.data) {
                        output.append('<div style="background: #f5f5f5; padding: 10px; border-radius: 4px; max-height: 200px; overflow-y: auto;">');
                        
                        if (response.data.logs && response.data.logs.length > 0) {
                            response.data.logs.forEach(function(log) {
                                output.append(`<p style="margin: 2px 0; font-size: 11px;">[${log.time}] ${log.message}</p>`);
                            });
                        } else {
                            output.append('<p>No recent logs found.</p>');
                        }
                        
                        output.append('</div>');
                        
                        if (response.data.cron_info) {
                            output.append('<h4>? Cron Status:</h4>');
                            output.append(`<pre style="font-size: 11px;">${JSON.stringify(response.data.cron_info, null, 2)}</pre>`);
                        }
                    } else {
                        output.append(`<p>? Failed to load logs: ${response.data || 'Unknown error'}</p>`);
                    }
                },
                error: function(xhr, status, error) {
                    output.append(`<p>? Log AJAX Error: ${status} - ${error}</p>`);
                }
            });
        },

        closeDebugModal: function() {
            $('#aitrfoel-debug-modal, #aitrfoel-monitor-modal').remove();
        },

        /**
         * Initialize table accordion functionality
         */
        initTableAccordion: function() {
            const self = this;
            
            // Force hide all translation rows initially
            $('.aitrfoel-translation-page').addClass('aitrfoel-translation-collapsed').hide();

            // Prevent duplicate delegated handlers when this initializer runs again.
            $(document).off('click.eatAccordion', '.aitrfoel-expand-button');
            $(document).off('click.eatAccordion', 'tr.aitrfoel-source-page');
            
            // Add has-translations class to source pages that have translation rows
            $('.aitrfoel-source-page').each(function() {
                const sourceId = $(this).data('page-id');
                const $translations = $(`.aitrfoel-translation-page[data-parent-id="${sourceId}"]`);
                
                if ($translations.length > 0) {
                    $(this).addClass('has-translations');
                    // Mark first and last translation in each group
                    $translations.first().addClass('aitrfoel-group-first');
                    $translations.last().addClass('aitrfoel-group-last');
                }
            });

            // Handle expand/collapse button clicks
            $(document).on('click.eatAccordion', '.aitrfoel-expand-button', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                const $button = $(this);
                const sourceId = $button.data('source-id');
                const isExpanded = String($button.data('expanded')) === 'true';
                
                self.toggleTranslations(sourceId, !isExpanded, $button);
            });

            // Make entire source row clickable to toggle (excluding interactive elements)
            $(document).on('click.eatAccordion', 'tr.aitrfoel-source-page', function(e) {
                // Ignore clicks on links, buttons, form controls, and within the expand button itself
                if (
                    $(e.target).closest('a, button, input, select, textarea, label, .aitrfoel-expand-button').length
                ) {
                    return;
                }
                const $row = $(this);
                const sourceId = $row.data('page-id');
                const $button = $(`.aitrfoel-expand-button[data-source-id="${sourceId}"]`);
                if ($button.length) {
                    const isExpanded = String($button.data('expanded')) === 'true';
                    self.toggleTranslations(sourceId, !isExpanded, $button);
                }
            });

            // Auto-expand any rows that are currently being translated
            this.autoExpandActiveTranslations();
        },

        /**
         * Toggle translations visibility for a source page
         */
        toggleTranslations: function(sourceId, expand, $button) {
            const $translationRows = $(`.aitrfoel-translation-page[data-parent-id="${sourceId}"]`);
            const $sourceRow = $(`.aitrfoel-source-page[data-page-id="${sourceId}"]`);
            
            if (expand) {
                // Expand
                $translationRows.removeClass('aitrfoel-translation-collapsed').addClass('aitrfoel-translation-expanded').show();
                $button
                    .data('expanded', 'true')
                    .attr('aria-expanded', 'true')
                    .attr('title', 'Collapse translations')
                    .addClass('is-expanded');
                $sourceRow.addClass('expanded');
            } else {
                // Collapse
                $translationRows.removeClass('aitrfoel-translation-expanded').addClass('aitrfoel-translation-collapsed').hide();
                $button
                    .data('expanded', 'false')
                    .attr('aria-expanded', 'false')
                    .attr('title', 'Expand translations')
                    .removeClass('is-expanded');
                $sourceRow.removeClass('expanded');
            }
        },

        /**
         * Handle accordion expansion/collapse when checkbox is selected/deselected
         */
        handleAccordionExpansion: function(checkbox) {
            const $checkbox = $(checkbox);
            const isChecked = $checkbox.is(':checked');
            const sourceRow = $checkbox.closest('.aitrfoel-source-page');
            const pageId = sourceRow.data('page-id');
            const $expandButton = $(`.aitrfoel-expand-button[data-source-id="${pageId}"]`);
            
            if ($expandButton.length > 0) {
                if (isChecked) {
                    // Expand the accordion when checkbox is checked
                    if (String($expandButton.data('expanded')) !== 'true') {
                        this.toggleTranslations(pageId, true, $expandButton);
                    }
                } else {
                    // Collapse the accordion when checkbox is unchecked
                    if (String($expandButton.data('expanded')) === 'true') {
                        this.toggleTranslations(pageId, false, $expandButton);
                    }
                }
            }
        },

        /**
         * Auto-expand any rows that are currently being translated
         */
        autoExpandActiveTranslations: function() {
            const self = this;
            
            // Check for pages with translation status indicators that suggest active translation
            $('.aitrfoel-translation-page').each(function() {
                const $row = $(this);
                const $statusCell = $row.find('.column-status');
                
                // Look for indicators of active translation (processing, pending, etc.)
                if ($statusCell.text().toLowerCase().includes('processing') || 
                    $statusCell.text().toLowerCase().includes('pending') ||
                    $statusCell.find('.aitrfoel-translation-spinner').length > 0) {
                    
                    const parentId = $row.data('parent-id');
                    const $expandButton = $(`.aitrfoel-expand-button[data-source-id="${parentId}"]`);
                    
                    if ($expandButton.length > 0) {
                        self.toggleTranslations(parentId, true, $expandButton);
                    }
                }
            });
        },

        /**
         * Initialize sticky translation bar behavior
         */
        initStickyBar: function() {
            const $stickyBar = $('.aitrfoel-bulk-translation-bar');
            
            if ($stickyBar.length === 0) return;
            
            let stickyBarTop = $stickyBar.offset().top;
            const adminBarHeight = $('#wpadminbar').outerHeight() || 32;
            
            $(window).on('scroll', function() {
                const scrollTop = $(window).scrollTop();
                
                if (scrollTop > (stickyBarTop - adminBarHeight)) {
                    $stickyBar.addClass('is-sticky');
                } else {
                    $stickyBar.removeClass('is-sticky');
                }
            });
            
            // Also trigger on window resize to recalculate positions
            $(window).on('resize', function() {
                const newStickyBarTop = $('.aitrfoel-bulk-translation-bar').offset().top;
                if (newStickyBarTop) {
                    stickyBarTop = newStickyBarTop;
                }
            });
        },
        
        /**
         * Clean HTML entities and tags from text
         */
        cleanHtmlFromText: function(text) {
            if (!text || typeof text !== 'string') {
                return text;
            }
            
            // Create a temporary element to decode HTML entities
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = text;
            
            // Get decoded text and strip any remaining HTML tags
            let cleaned = tempDiv.textContent || tempDiv.innerText || '';
            
            // Clean up extra whitespace
            cleaned = cleaned.replace(/\s+/g, ' ').trim();
            
            return cleaned;
        },

        /**
         * Clean existing page titles on page load
         */
        cleanExistingPageTitles: function() {
            $('.aitrfoel-page-title-link').each(function() {
                const $link = $(this);
                const originalText = $link.contents().filter(function() {
                    return this.nodeType === 3; // Text nodes only
                }).text();
                
                // Check if text contains HTML entities
                if (originalText.includes('&lt;') || originalText.includes('&gt;') || originalText.includes('&amp;')) {
                    const cleanedText = Dashboard.cleanHtmlFromText(originalText);
                    
                    // Replace the text content while preserving the language span
                    const $langSpan = $link.find('.aitrfoel-page-lang');
                    $link.contents().filter(function() {
                        return this.nodeType === 3;
                    }).remove();
                    
                    $link.prepend(cleanedText + ' ');
                }
            });
        }
    };

    // Initialize dashboard
    Dashboard.init();
    
    // Setup checklist dismiss
    $(document).on('click', '#aitrfoel-dismiss-checklist', function() {
        var $checklist = $('#aitrfoel-setup-checklist');
        $checklist.slideUp(200, function() { $checklist.remove(); });
        $.post(aitrfoelDashboard.ajax_url, {
            action: 'aitrfoel_dismiss_setup_checklist',
            nonce: aitrfoelDashboard.nonce
        });
    });

    // Setup checklist skip step
    $(document).on('click', '.aitrfoel-setup-skip-btn', function() {
        var $btn = $(this);
        var step = $btn.data('step');
        var $item = $btn.closest('.aitrfoel-setup-checklist-item');

        // Mark item as complete visually
        $item.addClass('is-complete');
        $item.find('.aitrfoel-setup-check-empty').replaceWith('<span class="dashicons dashicons-yes"></span>');
        $item.find('.aitrfoel-setup-check-content strong').css({'text-decoration': 'line-through', 'color': '#64748b'});
        $item.find('.button-small').remove();

        // Update progress bar
        var $checklist = $item.closest('.aitrfoel-setup-checklist, .aitrfoel-setup-checklist--wp-dashboard');
        var total = $checklist.find('.aitrfoel-setup-checklist-item').length;
        var done = $checklist.find('.aitrfoel-setup-checklist-item.is-complete').length;
        var pct = Math.round((done / total) * 100);
        $checklist.find('.aitrfoel-setup-checklist-progress-bar').css('width', pct + '%');
        $checklist.find('.aitrfoel-setup-checklist-progress-text').text(done + ' of ' + total + ' completed');

        // If all done, slide up
        if (done >= total) {
            setTimeout(function() {
                $checklist.slideUp(300, function() { $checklist.remove(); });
            }, 400);
        }

        // Save to server
        $.post(aitrfoelDashboard.ajax_url, {
            action: 'aitrfoel_skip_setup_step',
            nonce: aitrfoelDashboard.nonce,
            step: step
        });
    });
    
    
    // Clean existing page titles that might have HTML entities
    Dashboard.cleanExistingPageTitles();

    // Show review popup if ?show_review=1 is in the URL
    if (new URLSearchParams(window.location.search).get('show_review') === '1') {
        var reviewHtml = '<div class="aitrfoel-review-prompt" style="margin: 15px 0; padding: 14px 18px; background: linear-gradient(135deg, #fef9e7, #fdf2d1); border: 1px solid #f0d78c; border-radius: 8px; display: flex; align-items: center; justify-content: space-between; gap: 12px;">' +
            '<span style="font-size: 0.95em; color: #5a4e2f;">\u2B50 ' + escapeHtml(aitrfoelDashboard.strings.enjoying_plugin) + '</span>' +
            '<span style="white-space: nowrap;">' +
                '<a href="https://wordpress.org/support/plugin/ai-translator-for-elementor-and-polylang/reviews/#new-post" target="_blank" class="button button-small button-primary" style="margin-right: 6px;">' + escapeHtml(aitrfoelDashboard.strings.leave_review) + '</a>' +
                '<button type="button" class="button button-small aitrfoel-dismiss-review">' + escapeHtml(aitrfoelDashboard.strings.no_thanks) + '</button>' +
            '</span>' +
        '</div>';
        $('.aitrfoel-dashboard-wrapper').prepend(reviewHtml);
    }

    $(document).on('click', '.aitrfoel-dismiss-review', function() {
        var $prompt = $(this).closest('.aitrfoel-review-prompt');
        $.post(aitrfoelDashboard.ajax_url, {
            action: 'aitrfoel_dismiss_review',
            nonce: aitrfoelDashboard.nonce
        });
        $prompt.slideUp(200);
    });
});
