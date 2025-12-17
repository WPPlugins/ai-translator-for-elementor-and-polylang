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

    const Dashboard = {
        init: function() {
            this.bindEvents();
            this.setupLanguageDropdown();
            this.updateStartButtonState(); // Initialize button state
            this.updateDropdownsState(false); // Initially disable dropdowns
            this.checkForActiveTranslations(); // Check for ongoing translations on page load
            this.initTableAccordion(); // Initialize table accordion functionality
            this.initStickyBar(); // Initialize sticky translation bar
            
            // Initialize progress tracking
            this.progressInterval = null;
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
            const button = $('#eat-start-translation');
            
            // Show "Waiting for AI response..." with dots animation
            $('#eat-estimated-words')
                .addClass('translating')
                .html(__('translation_in_progress', 'Translation in Progress') + '<span class="eat-dots"><span class="dot">.</span><span class="dot">.</span><span class="dot">.</span></span>');
            
            // ????????? ??????
            button.addClass('loading')
                  .removeClass('button-primary success')
                  .html('⏳ ' + __('translation_in_progress', 'Translation in Progress') + '...')
                  .prop('disabled', true);
            
            // ?? ????????? flip ??????!
            this.startFlipLoader(statusData.total_items || 0, statusData.completed_items || 0);
            
            // ?????????? ???????? ???? ???? ??????
            if (statusData.progress !== undefined) {
                $('#eat-progress-container').show();
                this.updateProgress(statusData.progress, statusData.status_message || __('translation_in_progress', 'Translation in progress...'), statusData.details || '');
            }
            
            $('#eat-bulk-bar').addClass('processing');
            
            // ?????????? ???????????
            this.showNotice('⚠️ ' + __('translation_already_in_progress', 'Translation is already in progress. Please wait for completion.'), 'warning');
        },

        /**
         * ????????????? UI ?????????
         */
        blockTranslationUI: function(message) {
            const button = $('#eat-start-translation');
            button.prop('disabled', true)
                  .html('⚠️ ' + message)
                  .addClass('loading');
            
            // ????????????? ????????
            $('.eat-page-checkbox, .eat-language-checkbox').prop('disabled', true);
            $('.eat-source-languages, .eat-target-languages').addClass('disabled');
        },

        /**
         * ?????????????? UI ?????????
         */
        unblockTranslationUI: function() {
            const button = $('#eat-start-translation');
            button.removeClass('loading')
                  .addClass('button-primary')
                  .html('🚀 Start Translation');
            
            // ?????????????? ????????
            $('.eat-page-checkbox, .eat-language-checkbox').prop('disabled', false);
            $('.eat-source-languages, .eat-target-languages').removeClass('disabled');
            
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
            $('#eat-estimated-words').text('Select pages for translation').show();
        },

        /**
         * ? Reset UI after error
         */
        resetTranslationUI: function() {
            const button = $('#eat-start-translation');
            button.removeClass('loading non-clickable')
                  .addClass('button-primary')
                  .html('🚀 Start Translation')
                  .prop('disabled', false);
            
            $('#eat-bulk-bar').removeClass('processing');
            $('#eat-progress-container').hide();
            
            // Reset estimated words text
            $('#eat-estimated-words')
                .removeClass('translating')
                .text('Select pages for translation')
                .show();
            
            // Stop any animations and timer
            this.stopFlipLoader();
            this.stopStageProgress();
            this.stopElapsedTimer();
            
            // Clear active translation
            this.clearActiveTranslation();
        },

        /**
         * ?? STAGE-BASED PROGRESS INDICATOR
         */
        startStageProgress: function(totalJobs = 0, completedJobs = 0) {
            // Hide simple text, show stage progress
            $('#eat-estimated-words').hide();
            $('#eat-stage-progress').show().addClass('active');
            
            // Initialize stages - only show stage 1 initially
            this.updateStageStatus(1, 'active', 0);
            this.updateStageStatus(2, 'pending', 0);
            
            // Hide stage 2 initially
            $('.eat-stage-2').addClass('pending').hide();
            
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
            const stageElement = $(`.eat-stage-${stage}`);
            const statusElement = stageElement.find('.eat-stage-status');
            const progressBar = stageElement.find('.eat-stage-progress-fill');
            
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
            $(`.eat-stage-${stage} .eat-stage-description`).text(description);
        },

        /**
         * Stop stage progress and reset
         */
        stopStageProgress: function() {
            $('#eat-stage-progress').hide().removeClass('active');
            $('#eat-estimated-words').show();
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
                $('.eat-stage-2').hide();
                
            } else if (stage === 2) {
                // Stage 2: Gap-Filler - show stage 2, complete stage 1
                this.updateStageStatus(1, __('status_completed', 'Completed'), 100);
                this.updateStageStatus(2, 'active', Math.max(0, progress - 80) * 5); // Convert 80-100% to 0-100%
                this.updateStageDescription(2, stageDescription || 'Fixing missed translations and refining quality');
                
                // Show stage 2 now
                $('.eat-stage-2').show();
                
            } else if (stage === 3) {
                // Both stages completed
                this.updateStageStatus(1, __('status_completed', 'Completed'), 100);
                this.updateStageStatus(2, __('status_completed', 'Completed'), 100);
                $('.eat-stage-2').show();
                
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
            const $sourceRow = $(`.eat-source-page[data-page-id="${pageId}"]`);
            const $expandButton = $(`.eat-expand-button[data-source-id="${pageId}"]`);
            
            
            if ($expandButton.length > 0 && $expandButton.data('expanded') !== 'true') {
                this.toggleTranslations(pageId, true, $expandButton);
            } else {
            }
        },

        /**
         * Highlight and scroll to completed translation
         */
        highlightCompletedTranslation: function(sourcePageId, targetLanguage) {
            // Find the translation row for this page and language
            const $translationRow = $(`.eat-translation-page[data-parent-id="${sourcePageId}"]`)
                .filter(function() {
                    return $(this).find('.column-language').text().toLowerCase().includes(targetLanguage.toLowerCase());
                });

            if ($translationRow.length > 0) {
                // Add highlight class
                $translationRow.addClass('eat-translation-highlight');
                
                // Scroll to the row
                $('html, body').animate({
                    scrollTop: $translationRow.offset().top - 100
                }, 1000);
                
                // Remove highlight after animation
                setTimeout(() => {
                    $translationRow.removeClass('eat-translation-highlight');
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
                        $('#eat-estimated-words').hide();
                        $('#eat-stage-progress').show().addClass('active');
                        
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
            const noticeClass = 'notice notice-' + type;
            const noticeHtml = `<div class="${noticeClass} is-dismissible"><p>${message}</p></div>`;
            
            // Remove existing notices
            $('.aitrfoel-dashboard-wrapper .notice').remove();
            
            // Add new notice
            $('.aitrfoel-dashboard-wrapper h1').after(noticeHtml);
            
            // Auto-dismiss after 5 seconds
            setTimeout(function() {
                $('.aitrfoel-dashboard-wrapper .notice').fadeOut();
            }, 5000);
        },

        bindEvents: function() {
            // Checkbox selection events
            $(document).on('change', '.eat-page-checkbox', this.handlePageSelection);
            $(document).on('change', '#cb-select-all', this.handleSelectAll);
            
            // Language selection events
            $(document).on('click', '#eat-select-languages', this.toggleLanguageDropdown);
            $(document).on('change', '.eat-language-checkbox', this.handleLanguageSelection);
            $(document).on('change', '.eat-select-all-missing', this.handleSelectAllMissing);
            
            // Source language selection events
            $(document).on('click', '#eat-select-source-language', this.toggleSourceDropdown);
            $(document).on('change', 'input[name="source-language"]', this.handleSourceLanguageSelection);
            
            // Bulk action events
            $(document).on('click', '#eat-start-translation', this.startTranslation);
            $(document).on('click', '#eat-bulk-settings', this.openSettings);
            
            // Delete page events
            $(document).on('click', '.eat-delete-page', this.handleDeletePage);
            
            // Close dropdown when clicking outside
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.eat-target-languages').length) {
                    $('#eat-languages-dropdown').hide();
                }
                if (!$(e.target).closest('.eat-source-languages').length) {
                    $('#eat-source-dropdown').hide();
                }
            });
        },

        setupLanguageDropdown: function() {
            const languages = aitrfoelDashboard.languages || {};
            const dropdown = $('#eat-languages-dropdown');
            let html = '';
            
            // Add "Select all missing" option
            html += '<div class="eat-dropdown-item">';
            html += '<label><input type="checkbox" class="eat-select-all-missing"> Select all missing</label>';
            html += '</div>';
            html += '<div class="eat-dropdown-divider"></div>';
            
            // Add individual language options
            Object.keys(languages).forEach(function(code) {
                html += '<div class="eat-dropdown-item">';
                html += '<label>';
                html += '<input type="checkbox" class="eat-language-checkbox" value="' + code + '"> ';
                html += languages[code] + ' (' + code.toUpperCase() + ')';
                html += '</label>';
                html += '</div>';
            });
            
            dropdown.html(html);
        },

        handlePageSelection: function() {
            const selectedPages = $('.eat-page-checkbox:checked').length;
            Dashboard.updateBulkBar(selectedPages);
            Dashboard.updateEstimatedWords();
            Dashboard.updateLanguageDropdown(); // Update available languages based on selection
            
            // Auto-expand/collapse accordion based on checkbox selection
            Dashboard.handleAccordionExpansion(this);
        },

        handleSelectAll: function() {
            const isChecked = $(this).is(':checked');
            $('.eat-page-checkbox:not(:disabled)').prop('checked', isChecked);
            Dashboard.handlePageSelection();
        },

        updateBulkBar: function(selectedCount) {
            // Panel is always visible, just update the count and button state
            const text = selectedCount > 0 
                ? selectedCount + ' page' + (selectedCount > 1 ? 's' : '') + ' selected'
                : 'Select pages for translation';
            $('#eat-estimated-words').text(text);
            
            // Update start button state based on selections
            this.updateStartButtonState();
        },

        updateStartButtonState: function() {
            const selectedPages = $('.eat-page-checkbox:checked').length;
            const selectedLanguages = $('.eat-language-checkbox:checked').length;
            const canStart = selectedPages > 0 && selectedLanguages > 0;
            const startButton = $('#eat-start-translation');
            
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
            const sourceDropdown = $('.eat-source-languages');
            const targetDropdown = $('.eat-target-languages');
            const sourceButton = $('#eat-select-source-language');
            const targetButton = $('#eat-select-languages');
            
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
                $('#eat-source-dropdown, #eat-languages-dropdown').hide();
                
                // Clear selected languages
                $('.eat-language-checkbox:checked').prop('checked', false);
                targetButton.text(aitrfoelDashboard.strings.select_languages);
            }
        },

        updateLanguageDropdown: function() {
            const selectedCheckboxes = $('.eat-page-checkbox:checked');
            const languages = aitrfoelDashboard.languages || {};
            const dropdown = $('#eat-languages-dropdown');
            
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
                // Add "Select all missing" option
                html += '<div class="eat-dropdown-item">';
                html += '<label><input type="checkbox" class="eat-select-all-missing"> Select all missing</label>';
                html += '</div>';
                html += '<div class="eat-dropdown-divider"></div>';
                
                // Add individual missing language options
                missingLanguagesArray.forEach(function(code) {
                    if (languages[code]) {
                        html += '<div class="eat-dropdown-item">';
                        html += '<label>';
                        html += '<input type="checkbox" class="eat-language-checkbox" value="' + code + '"> ';
                        html += languages[code] + ' (' + code.toUpperCase() + ')';
                        html += '</label>';
                        html += '</div>';
                    }
                });
            } else {
                // No missing languages
                html += '<div class="eat-dropdown-item" style="color: #666; font-style: italic;">';
                html += 'All selected pages are fully translated';
                html += '</div>';
            }
            
            dropdown.html(html);
            
            // Clear any selected languages that are no longer available
            $('.eat-language-checkbox:checked').each(function() {
                const langCode = $(this).val();
                if (!allMissingLanguages.has(langCode)) {
                    $(this).prop('checked', false);
                }
            });
            
            // Update language selection display
            this.handleLanguageSelection();
        },

        updateEstimatedWords: function() {
            const selectedPages = $('.eat-page-checkbox:checked').length;
            const selectedLanguages = $('.eat-language-checkbox:checked').length;
            
            if (selectedPages === 0) {
                $('#eat-estimated-words').text('Select pages for translation');
                return;
            }
            
            if (selectedLanguages === 0) {
                $('#eat-estimated-words').text(selectedPages + ' page' + (selectedPages > 1 ? 's' : '') + ' selected');
                return;
            }
            
            const totalJobs = selectedPages * selectedLanguages;
            $('#eat-estimated-words').text(totalJobs + ' translation job' + (totalJobs > 1 ? 's' : '') + ' will be created');
        },

        toggleLanguageDropdown: function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            // Don't open if disabled
            if ($('.eat-target-languages').hasClass('disabled')) {
                return;
            }
            
            $('#eat-languages-dropdown').toggle();
        },

        toggleSourceDropdown: function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            // Don't open if disabled
            if ($('.eat-source-languages').hasClass('disabled')) {
                return;
            }
            
            $('#eat-source-dropdown').toggle();
        },

        handleSourceLanguageSelection: function() {
            const selectedRadio = $('input[name="source-language"]:checked');
            const selectedValue = selectedRadio.val();
            const selectedText = selectedRadio.parent().text().trim();
            
            // Update button text
            $('#eat-select-source-language').text(selectedText + ' ?');
            
            // Close dropdown
            $('#eat-source-dropdown').hide();
        },

        handleLanguageSelection: function() {
            const selectedLanguages = $('.eat-language-checkbox:checked');
            const selectedCount = selectedLanguages.length;
            
            // Update button text
            if (selectedCount > 0) {
                const text = aitrfoelDashboard.strings.languages_selected.replace('%d', selectedCount);
                $('#eat-select-languages').text(text);
            } else {
                $('#eat-select-languages').text(aitrfoelDashboard.strings.select_languages);
            }
            
            // Update start button state
            Dashboard.updateStartButtonState();
            Dashboard.updateEstimatedWords();
        },

        handleSelectAllMissing: function() {
            const isChecked = $(this).is(':checked');
            $('.eat-language-checkbox').prop('checked', isChecked);
            Dashboard.handleLanguageSelection();
        },

        startTranslation: function() {
            // ??????: ????????? ??? ?? ??? ????????? ????????
            const activeTranslation = localStorage.getItem('aitrfoel_active_translation');
            if (activeTranslation) {
                Dashboard.showNotice(' Translation is already in progress. Please wait for completion or refresh the page.', 'warning');
                return;
            }
            
            const selectedPages = $('.eat-page-checkbox:checked').map(function() {
                return $(this).val();
            }).get();
            
            const selectedLanguages = $('.eat-language-checkbox:checked').map(function() {
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
                        // Animation already showing - just continue with monitoring
                        // ????????? ???????? ??????? ? localStorage
                        Dashboard.saveActiveTranslation(response.data.job_id, 5);
                        Dashboard.monitorTranslationProgress(response.data.job_id);
                    } else {
                        // Check if this is a structured error (limit_reached, cooldown, etc.)
                        const errorData = response.data || {};
                        
                        // Handle translation errors with proper modals
                        Dashboard.handleTranslationError(errorData);
                        
                        // ? Reset UI on error
                        Dashboard.resetTranslationUI();
                    }
                },
                error: function() {
                    Dashboard.showError('Network error. Please try again.');
                    
                    // ? Reset UI on error
                    Dashboard.resetTranslationUI();
                }
            });
        },

        showTranslationProgress: function() {
            const selectedPages = $('.eat-page-checkbox:checked').map(function() {
                return $(this).val();
            }).get();
            const selectedLanguages = $('.eat-language-checkbox:checked').length;
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
            
            // Show "Waiting for AI response..." with dots animation
            $('#eat-estimated-words')
                .addClass('translating')
                .html(__('waiting_for_ai', 'Waiting for AI response') + '<span class="eat-dots"><span class="dot">.</span><span class="dot">.</span><span class="dot">.</span></span>');
            
            // ????????? ??????
            const button = $('#eat-start-translation');
            button.addClass('loading')
                  .removeClass('button-primary success')
                  .html('⏳ ' + __('translating', 'Translating...'))
                  .addClass('non-clickable');
            
            $('#eat-bulk-bar').addClass('processing');
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
            const $button = $('#eat-start-translation');
            $button.prop('disabled', true)
                   .html('🔍 Analyzing...')
                   .addClass('loading');
        },

        /**
         * ?????? ?????? ???????
         */
        hideAnalysisLoader: function() {
            const $button = $('#eat-start-translation');
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
            $('#eat-analysis-confirm').on('click', () => {
                $('#eat-analysis-modal').remove();
                this.proceedWithTranslation(selectedPages, selectedLanguages, sourceLanguage);
            });
            
            // ?????????? ?????? "Cancel"
            $('#eat-analysis-cancel').on('click', () => {
                $('#eat-analysis-modal').remove();
            });
            
            // ???????? ?? ????? ??? ?????????? ????
            $('#eat-analysis-modal').on('click', function(e) {
                if ($(e.target).hasClass('eat-modal-overlay')) {
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
            const wordsAfter = wordsRemaining - totalWordsNeeded;
            
            return `
                <div id="eat-analysis-modal" class="eat-modal-overlay">
                    <div class="eat-modal-content eat-analysis-panel">
                        <button class="eat-modal-close" onclick="jQuery('#eat-analysis-modal').remove()">�</button>
                        
                        <h3>📊 Translation Analysis</h3>
                        
                        <div class="eat-analysis-summary">
                            <p>Selected: <strong>${data.pages_count || 0} pages � ${data.languages_count || 0} languages = ${data.total_jobs || 0} jobs</strong></p>
                            <p>Estimated words: <strong>${totalWordsNeeded.toLocaleString()}</strong></p>
                            <p>Your limit: <strong>${wordLimit.toLocaleString()}</strong></p>
                            <p>Current usage: <strong>${wordsUsed.toLocaleString()}</strong></p>
                            <p>Available: <strong>${wordsRemaining.toLocaleString()}</strong></p>
                            ${data.can_proceed ? `<p>After translation: <strong>${wordsAfter.toLocaleString()} words left</strong></p>` : ''}
                        </div>
                        
                        <div class="eat-analysis-status ${statusClass}">
                            ${statusIcon} ${statusText}
                        </div>
                        
                        <table class="eat-breakdown-table">
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
                        
                        <div class="eat-modal-actions">
                            ${data.can_proceed ? 
                                '<button id="eat-analysis-confirm" class="button button-primary">🚀 Start Translation</button>' :
                                '<button onclick="window.open(\'' + (data.upgrade_url || '') + '\', \'_blank\')" class="button button-primary">⬆️ Upgrade Plan</button>'
                            }
                            <button id="eat-analysis-cancel" class="button">Cancel</button>
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
            $('#eat-analysis-cancel').on('click', () => {
                $('#eat-analysis-modal').remove();
            });
            
            // ???????? ?? ????? ??? ?????????? ????
            $('#eat-analysis-modal').on('click', function(e) {
                if ($(e.target).hasClass('eat-modal-overlay')) {
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
                        // ????????? ???????? ??????? ? localStorage
                        Dashboard.saveActiveTranslation(response.data.job_id, 5);
                        Dashboard.monitorTranslationProgress(response.data.job_id);
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
            
            // Reset estimated words text
            $('#eat-estimated-words')
                .removeClass('translating')
                .text('Select pages for translation');
            
            // Reset the button state
            $('#eat-start-translation')
                .removeClass('loading non-clickable')
                .addClass('button-primary')
                .html('🚀 Start Translation');
            $('#eat-bulk-bar').removeClass('processing');
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
                <div id="eat-error-modal" class="eat-modal-overlay" style="opacity: 1 !important;">
                    <div class="eat-modal-content eat-error-modal">
                        <button class="eat-modal-close" onclick="jQuery('#eat-error-modal').remove()">�</button>
                        
                        <h3> Translation Paused: Limit Reached</h3>
                        
                        <div class="eat-error-message">
                            <p>${errorData.message || 'Word limit exceeded during translation.'}</p>
                        </div>
                        
                        ${totalJobs > 0 ? `
                        <div class="eat-error-stats">
                            <p>Completed: <strong>${completedJobs} of ${totalJobs} jobs</strong></p>
                            <div class="eat-progress-bar">
                                <div class="eat-progress-fill" style="width: ${(completedJobs/totalJobs*100)}%"></div>
                            </div>
                        </div>
                        ` : ''}
                        
                        <div class="eat-error-details">
                            <p>Your translation was paused when the word limit was reached.</p>
                            <p>Upgrade your plan to continue translating.</p>
                        </div>
                        
                        <div class="eat-modal-actions">
                            <button onclick="window.open('${upgradeUrl}', '_blank')" class="button button-primary">⬆️ Upgrade Plan</button>
                            <button onclick="jQuery('#eat-error-modal').remove()" class="button">Close</button>
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
                <div id="eat-error-modal" class="eat-modal-overlay" style="opacity: 1 !important;">
                    <div class="eat-modal-content eat-error-modal">
                        <button class="eat-modal-close" onclick="jQuery('#eat-error-modal').remove()">�</button>
                        
                        <h3>? Cooldown Period</h3>
                        
                        <div class="eat-error-message">
                            <p>${errorData.message || 'You are in cooldown period.'}</p>
                        </div>
                        
                        <div class="eat-error-details">
                            <p>Free plan users must wait before translating again.</p>
                            <p>Upgrade to remove cooldown restrictions.</p>
                        </div>
                        
                        <div class="eat-modal-actions">
                            <button onclick="window.open('${upgradeUrl}', '_blank')" class="button button-primary">⬆️ Upgrade Plan</button>
                            <button onclick="jQuery('#eat-error-modal').remove()" class="button">Close</button>
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
                <div id="eat-error-modal" class="eat-modal-overlay" style="opacity: 1 !important;">
                    <div class="eat-modal-content eat-error-modal">
                        <button class="eat-modal-close" onclick="jQuery('#eat-error-modal').remove()">✕</button>
                        
                        <h3>🚨 ${__('not_enough_words', 'Not Enough Words')}</h3>
                        
                        <div class="eat-error-message">
                            <p>${errorData.message || __('insufficient_words_message', 'Insufficient words for this translation.')}</p>
                        </div>
                        
                        <div class="eat-error-stats">
                            <p>${__('required', 'Required')}: <strong>${wordsNeeded.toLocaleString()} ${__('words', 'words')}</strong></p>
                            <p>${__('available', 'Available')}: <strong>${wordsRemaining.toLocaleString()} ${__('words', 'words')}</strong></p>
                            <p>${__('missing', 'Missing')}: <strong>${wordsMissing.toLocaleString()} ${__('words', 'words')}</strong></p>
                        </div>
                        
                        <div class="eat-error-details">
                            <p>${__('reduce_or_upgrade', 'Reduce your selection or upgrade your plan to proceed.')}</p>
                        </div>
                        
                        <div class="eat-modal-actions">
                            <button onclick="${upgradeAction}" class="button button-primary">⬆️ ${__('upgrade_plan', 'Upgrade Plan')}</button>
                            <button onclick="jQuery('#eat-error-modal').remove()" class="button">${__('close', 'Close')}</button>
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
                __('sending_content', 'Sending content for translation'),
                __('processing_data', 'Processing the data'),
                __('analyzing_structure', 'Analyzing page structure'),
                __('preparing_request', 'Preparing translation request'),
                __('waiting_for_ai', 'Waiting for AI response'),
                __('processing_results', 'Processing translation results'),
                __('applying_translations', 'Applying translations to content'),
                __('finalizing_creation', 'Finalizing page creation'),
                __('optimizing_content', 'Optimizing translated content'),
                __('completing_process', 'Completing translation process')
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
            const $estimatedWords = $('#eat-estimated-words');
            if ($estimatedWords.length) {
                const dotsHtml = '<span class="eat-dots"><span class="dot">.</span><span class="dot">.</span><span class="dot">.</span></span>';
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
                const $estimatedWords = $('#eat-estimated-words');
                if (!$estimatedWords.hasClass('translating')) {
                    $estimatedWords.text('Select pages for translation').css('color', '#666');
                }
            }, 3000);
        },

        /**
         * ?? Start elapsed timer
         */
        startElapsedTimer: function() {
            this.timerStartTime = Date.now();
            $('#eat-elapsed-timer').show();
            
            // Update timer every second
            this.timerInterval = setInterval(() => {
                const elapsed = Math.floor((Date.now() - this.timerStartTime) / 1000);
                const minutes = Math.floor(elapsed / 60);
                const seconds = elapsed % 60;
                const timeString = `${minutes}:${seconds.toString().padStart(2, '0')}`;
                $('#eat-timer-value').text(timeString);
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
            $('#eat-elapsed-timer').hide();
            $('#eat-timer-value').text('0:00');
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
                                $('#eat-status-text').text(statusText);
                                
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
                                $('#eat-estimated-words')
                                    .removeClass('translating')
                                    .text('Select pages for translation');
                                
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

        showTranslationComplete: function(status) {
            const button = $('#eat-start-translation');
            
            // Show success message in estimated words
            const $estimatedWords = $('#eat-estimated-words');
            $estimatedWords.removeClass('translating')
                          .text(__('translation_completed', 'Translation completed successfully!'))
                          .css('color', '#46b450');
            
            // Stop the progress interval
            this.stopAnimatedProgress();
            
            // ??????? ??????? ????? ? bounce
            button.removeClass('loading')
                  .addClass('success')
                  .html('✅ Complete!')
                  .addClass('non-clickable');
            
            $('#eat-bulk-bar').removeClass('processing').addClass(__('status_completed', 'Completed'));
            
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
            $('#eat-estimated-words')
                .removeClass('translating')
                .text('Select pages for translation');
            
            // ?????? ????? ??????
            const button = $('#eat-start-translation');
            button.removeClass('loading success non-clickable')
                  .addClass('button-primary')
                  .text('🚀 Start Translation')
                  .prop('disabled', true); // ????????? ????????????? ??? ??????
            
            // ????? ???? ?????????
            $('.eat-page-checkbox, .eat-language-checkbox').prop('checked', false);
            $('.eat-page-checkbox').prop('disabled', false);
            
            // ????? UI ?????????
            $('#eat-select-languages').text(aitrfoelDashboard.strings?.select_languages || 'Select Languages ?');
            $('#eat-estimated-words').text('Select pages for translation');
            $('#eat-bulk-bar').removeClass('processing completed error');
            
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
                        $('.eat-compact-stat').each(function() {
                            const label = $(this).find('.eat-compact-label').text();
                            
                            if (label.includes('Words Translated')) {
                                $(this).find('.eat-compact-value').text(stats.words_used.toLocaleString());
                            }
                            
                            if (label.includes('Words Left')) {
                                const $value = $(this).find('.eat-compact-value');
                                $value.text(stats.words_left.toLocaleString());
                                
                                // Update tooltip
                                $value.attr('title', 'Used: ' + stats.words_used.toLocaleString() + ' of ' + stats.word_limit.toLocaleString() + ' words');
                                
                                // Add warning if low
                                if (stats.words_left <= 0) {
                                    $value.addClass('eat-compact-limit-reached');
                                } else {
                                    $value.removeClass('eat-compact-limit-reached');
                                }
                            }
                        });
                        
                        // Trigger a visual flash to show update
                        $('.eat-compact-stats-bar').addClass('eat-stats-updated');
                        setTimeout(function() {
                            $('.eat-compact-stats-bar').removeClass('eat-stats-updated');
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
            $('.eat-expand-button[data-expanded="true"]').each(function() {
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
            $('.aitrfoel-dashboard-table').addClass('eat-table-updating');
            
            $.ajax({
                url: aitrfoelDashboard.ajax_url,
                type: 'POST',
                data: {
                    action: 'aitrfoel_refresh_table_data',
                    nonce: aitrfoelDashboard.nonce
                },
                success: function(response) {
                    if (response.success && response.data.html) {
                        // Fade out old content
                        $('.aitrfoel-dashboard-table tbody').fadeOut(300, function() {
                            // Replace with new content
                            $(this).html(response.data.html).fadeIn(300);
                            
                            // Re-initialize accordion functionality
                            Dashboard.initTableAccordion();
                            
                            // Restore expanded accordion states
                            Object.keys(expandedStates).forEach(function(sourceId) {
                                const $button = $(`.eat-expand-button[data-source-id="${sourceId}"]`);
                                if ($button.length > 0) {
                                    Dashboard.toggleTranslations(sourceId, true, $button);
                                }
                            });
                            
                            // Add highlight animation to updated rows
                            $('.aitrfoel-dashboard-table tbody tr').addClass('eat-row-updated');
                            
                            // Remove animations after completion
                            setTimeout(function() {
                                $('.aitrfoel-dashboard-table').removeClass('eat-table-updating');
                                $('.eat-row-updated').removeClass('eat-row-updated');
                            }, 1500);
                        });
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
                    const $translationRow = $(`.eat-translation-page[data-parent-id="${page.sourceId}"]`)
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
                            $translationRow.addClass('eat-newly-created-highlight');
                            
                            // Remove highlight after 4 seconds
                            setTimeout(() => {
                                $translationRow.removeClass('eat-newly-created-highlight');
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
            const $estimatedWords = $('#eat-estimated-words');
            $estimatedWords.removeClass('translating')
                          .text(__('translation_failed', 'Translation failed. Please try again.'))
                          .css('color', '#dc3232');
            
            // Stop the progress interval
            this.stopAnimatedProgress();
            
            $('#eat-bulk-bar').removeClass('processing').addClass(__('status_error', 'Error'));
            $('.eat-bulk-content').prepend('<div class="notice notice-error"><p>' + message + '</p></div>');
            
            // ??????? ?????? ? ???????? ????????? ??? ??????
            $('#eat-start-translation')
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
            Dashboard.showNotice('⚙️ Settings panel coming soon! Currently using default settings.', 'info');
        },

        handleDeletePage: function() {
            const button = $(this);
            const pageId = button.data('page-id');
            const pageTitle = button.data('page-title');
            
            // ?????????? ?????????????
            const confirmed = confirm(
                `Are you sure you want to move "${pageTitle}" to trash?\n\n` +
                `This action can be undone from WordPress trash.`
            );
            
            if (!confirmed) {
                return;
            }
            
            // ????????? ??????
            button.prop('disabled', true).text('Deleting...');
            
            // ?????????? AJAX ??????
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
                        Dashboard.showNotice(`? "${pageTitle}" moved to trash successfully.`, 'success');
                        
                        const row = button.closest('tr');
                        
                        // ?????????, ??? ???????? ???????? ??? ???????
                        if (row.hasClass('eat-source-page')) {
                            // ???? ??? ???????? ???????? - ??????? ??? ??????
                            const sourcePageId = row.data('page-id');
                            const nextRows = row.nextAll('tr').filter(function() {
                                // ??????? ??? ????????? ?????? ?? ????????? ???????? ????????
                                return !$(this).hasClass('eat-source-page');
                            });
                            
                            // ??????? ???????? ?????? ? ??? ????????? ????????
                            row.add(nextRows).fadeOut(300, function() {
                                $(this).remove();
                                Dashboard.updateStartButtonState();
                            });
                        } else {
                            // ???? ??? ??????? - ??????? ?????? ??? ??????
                            row.fadeOut(300, function() {
                                row.remove();
                                Dashboard.updateStartButtonState();
                            });
                        }
                        
                    } else {
                        Dashboard.showNotice(`? Failed to delete "${pageTitle}": ${response.data.message}`, __('status_error', 'Error'));
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
                <div id="aitrfoel-debug-modal" class="eat-modal-overlay">
                    <div class="eat-modal-content">
                        <div class="eat-modal-header">
                            <h2>🔧 Translation Engine Debug</h2>
                            <button class="eat-modal-close">&times;</button>
                        </div>
                        <div class="eat-modal-body">
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
                .eat-modal-overlay {
                    position: fixed;
                    top: 0; left: 0;
                    width: 100%; height: 100%;
                    background: rgba(0,0,0,0.7);
                    z-index: 100000;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }
                .eat-modal-content {
                    background: white;
                    padding: 0;
                    border-radius: 8px;
                    max-width: 80%;
                    max-height: 80%;
                    width: 600px;
                    overflow: hidden;
                }
                .eat-modal-header {
                    background: #0073aa;
                    color: white;
                    padding: 15px 20px;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                }
                .eat-modal-header h2 {
                    margin: 0;
                    font-size: 18px;
                }
                .eat-modal-close {
                    background: none;
                    border: none;
                    color: white;
                    font-size: 24px;
                    cursor: pointer;
                }
                .eat-modal-body {
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
            
            if ($('#eat-modal-styles').length === 0) {
                $('head').append('<div id="eat-modal-styles">' + modalStyles + '</div>');
            }
        },

        runDiagnostics: function() {
            const output = $('#aitrfoel-debug-output');
            output.html('<h3>🔎 Running System Diagnostics...</h3>');
            
            // Test 1: Check selected pages and languages
            const selectedPages = $('.eat-page-checkbox:checked').length;
            const selectedLanguages = $('.eat-language-checkbox:checked').length;
            
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
            
            const selectedPages = $('.eat-page-checkbox:checked').map(function() {
                return $(this).val();
            }).get();
            
            const selectedLanguages = $('.eat-language-checkbox:checked').map(function() {
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
            if ($('#eat-monitor-modal').length > 0) {
                $('#eat-monitor-modal').remove();
            }
            
            const modalHtml = `
                <div id="eat-monitor-modal" class="eat-modal-overlay">
                    <div class="eat-modal-content">
                        <div class="eat-modal-header" style="background: #28a745;">
                            <h2>📋 Jobs Monitor</h2>
                            <button class="eat-modal-close">&times;</button>
                        </div>
                        <div class="eat-modal-body">
                            <div id="eat-monitor-output">
                                <p>⏳ Loading jobs...</p>
                            </div>
                            <div style="margin-top: 15px;">
                                <button type="button" class="button" id="eat-refresh-jobs">🔄 Refresh</button>
                                <button type="button" class="button" id="eat-check-logs">🔎 Check Logs</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            $('body').append(modalHtml);
            
            // Add event handlers for modal buttons
            $(document).off('click', '#eat-refresh-jobs').on('click', '#eat-refresh-jobs', function() {
                Dashboard.loadJobsList();
            });
            
            $(document).off('click', '#eat-check-logs').on('click', '#eat-check-logs', function() {
                Dashboard.checkSystemLogs();
            });
        },

        loadJobsList: function() {
            const output = $('#eat-monitor-output');
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
            const output = $('#eat-monitor-output');
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
            $('#aitrfoel-debug-modal, #eat-monitor-modal').remove();
        },

        /**
         * Initialize table accordion functionality
         */
        initTableAccordion: function() {
            const self = this;
            
            // Force hide all translation rows initially
            $('.eat-translation-page').addClass('eat-translation-collapsed').hide();
            
            // Add has-translations class to source pages that have translation rows
            $('.eat-source-page').each(function() {
                const sourceId = $(this).data('page-id');
                const $translations = $(`.eat-translation-page[data-parent-id="${sourceId}"]`);
                
                if ($translations.length > 0) {
                    $(this).addClass('has-translations');
                    // Mark first and last translation in each group
                    $translations.first().addClass('eat-group-first');
                    $translations.last().addClass('eat-group-last');
                }
            });

            // Handle expand/collapse button clicks
            $(document).on('click', '.eat-expand-button', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                const $button = $(this);
                const sourceId = $button.data('source-id');
                const isExpanded = $button.data('expanded') === 'true';
                
                self.toggleTranslations(sourceId, !isExpanded, $button);
            });

            // Make entire source row clickable to toggle (excluding interactive elements)
            $(document).on('click', 'tr.eat-source-page', function(e) {
                // Ignore clicks on links, buttons, form controls, and within the expand button itself
                if (
                    $(e.target).closest('a, button, input, select, textarea, label, .eat-expand-button').length
                ) {
                    return;
                }
                const $row = $(this);
                const sourceId = $row.data('page-id');
                const $button = $(`.eat-expand-button[data-source-id="${sourceId}"]`);
                if ($button.length) {
                    const isExpanded = $button.data('expanded') === 'true';
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
            const $translationRows = $(`.eat-translation-page[data-parent-id="${sourceId}"]`);
            const $sourceRow = $(`.eat-source-page[data-page-id="${sourceId}"]`);
            
            if (expand) {
                // Expand
                $translationRows.removeClass('eat-translation-collapsed').addClass('eat-translation-expanded').show();
                $button
                    .data('expanded', 'true')
                    .find('.dashicons')
                    .removeClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2')
                    .addClass('dashicons-arrow-up-alt2');
                $sourceRow.addClass('expanded');
            } else {
                // Collapse
                $translationRows.removeClass('eat-translation-expanded').addClass('eat-translation-collapsed').hide();
                $button
                    .data('expanded', 'false')
                    .find('.dashicons')
                    .removeClass('dashicons-arrow-up-alt2 dashicons-arrow-down-alt2')
                    .addClass('dashicons-arrow-down-alt2');
                $sourceRow.removeClass('expanded');
            }
        },

        /**
         * Handle accordion expansion/collapse when checkbox is selected/deselected
         */
        handleAccordionExpansion: function(checkbox) {
            const $checkbox = $(checkbox);
            const isChecked = $checkbox.is(':checked');
            const sourceRow = $checkbox.closest('.eat-source-page');
            const pageId = sourceRow.data('page-id');
            const $expandButton = $(`.eat-expand-button[data-source-id="${pageId}"]`);
            
            if ($expandButton.length > 0) {
                if (isChecked) {
                    // Expand the accordion when checkbox is checked
                    if ($expandButton.data('expanded') !== 'true') {
                        this.toggleTranslations(pageId, true, $expandButton);
                    }
                } else {
                    // Collapse the accordion when checkbox is unchecked
                    if ($expandButton.data('expanded') === 'true') {
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
            $('.eat-translation-page').each(function() {
                const $row = $(this);
                const $statusCell = $row.find('.column-status');
                
                // Look for indicators of active translation (processing, pending, etc.)
                if ($statusCell.text().toLowerCase().includes('processing') || 
                    $statusCell.text().toLowerCase().includes('pending') ||
                    $statusCell.find('.eat-translation-spinner').length > 0) {
                    
                    const parentId = $row.data('parent-id');
                    const $expandButton = $(`.eat-expand-button[data-source-id="${parentId}"]`);
                    
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
            const $stickyBar = $('.eat-bulk-translation-bar');
            
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
                const newStickyBarTop = $('.eat-bulk-translation-bar').offset().top;
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
            $('.eat-page-title-link').each(function() {
                const $link = $(this);
                const originalText = $link.contents().filter(function() {
                    return this.nodeType === 3; // Text nodes only
                }).text();
                
                // Check if text contains HTML entities
                if (originalText.includes('&lt;') || originalText.includes('&gt;') || originalText.includes('&amp;')) {
                    const cleanedText = Dashboard.cleanHtmlFromText(originalText);
                    
                    // Replace the text content while preserving the language span
                    const $langSpan = $link.find('.eat-page-lang');
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
    
    
    // Clean existing page titles that might have HTML entities
    Dashboard.cleanExistingPageTitles();
});