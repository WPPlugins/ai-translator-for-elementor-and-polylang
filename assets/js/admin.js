// NUCLEAR: Ultra-aggressive Elementor bar removal
(function() {
    function nukeElementorBar() {
        const selectors = [
            '#e-admin-top-bar-root',
            '.e-admin-top-bar',
            '.e-admin-top-bar--active',
            '[id*="e-admin-top-bar"]',
            '[class*="e-admin-top-bar"]'
        ];
        
        selectors.forEach(function(selector) {
            document.querySelectorAll(selector).forEach(function(el) {
                el.remove();
            });
        });
        
        // Remove body classes and padding
        document.body.className = document.body.className.replace(/e-admin-top-bar[\w-]*/g, '');
        document.body.style.paddingTop = '0';
    }
    
    // Execute immediately
    nukeElementorBar();
    
    // Execute on DOMContentLoaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', nukeElementorBar);
    } else {
        nukeElementorBar();
    }
    
    // Poll every 100ms for 3 seconds
    let attempts = 0;
    const poll = setInterval(function() {
        nukeElementorBar();
        if (++attempts >= 30) clearInterval(poll);
    }, 100);
    
    // MutationObserver to catch dynamically added elements
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            mutation.addedNodes.forEach(function(node) {
                if (node.nodeType === 1) {
                    if ((node.id && node.id.includes('e-admin-top-bar')) || 
                        (node.className && typeof node.className === 'string' && node.className.includes('e-admin-top-bar'))) {
                        node.remove();
                    }
                }
            });
        });
    });
    
    if (document.body) {
        observer.observe(document.body, { childList: true, subtree: true });
    } else {
        document.addEventListener('DOMContentLoaded', function() {
            observer.observe(document.body, { childList: true, subtree: true });
        });
    }
})();
jQuery(document).ready(function($) {
    // Forcefully remove Elementor top bar on plugin pages
    function removeElementorTopBar() {
        const elementorSelectors = [
            '#e-admin-top-bar-root',
            '.e-admin-top-bar',
            '.e-admin-top-bar--active',
            'div[id*="e-admin-top-bar"]',
            'div[class*="e-admin-top-bar"]'
        ];
        
        elementorSelectors.forEach(selector => {
            $(selector).remove();
        });
        
        // Remove Elementor body classes
        $('body').removeClass('e-admin-top-bar--active');
        $('body').removeClass(function(index, className) {
            return (className.match(/(^|\s)e-admin-top-bar\S*/g) || []).join(' ');
        });
        
        // Reset content margin
        $('#wpcontent').css('margin-top', '0');
    }
    
    // Forcefully remove Elementor footer content on plugin pages
    function removeElementorFooter() {
        // Target the footer-left element and any Elementor content
        const footerSelectors = [
            '#footer-left',
            '#wpfooter #footer-left',
            'p#footer-left'
        ];
        
        footerSelectors.forEach(selector => {
            $(selector).each(function() {
                const $element = $(this);
                const content = $element.text();
                
                // Check if it contains Elementor promotional content
                if (content.includes('Elementor') && (content.includes('★') || content.includes('rating') || content.includes('review'))) {
                    $element.hide();
                    $element.remove();
                }
            });
        });
    }
    
    // Run both removal functions immediately and with delays
    removeElementorTopBar();
    removeElementorFooter();
    setTimeout(() => {
        removeElementorTopBar();
        removeElementorFooter();
    }, 100);
    setTimeout(() => {
        removeElementorTopBar();
        removeElementorFooter();
    }, 500);
    
    // Watch for DOM changes and remove if Elementor tries to add it back
    if (window.MutationObserver) {
        const observer = new MutationObserver(function(mutations) {
            let shouldRemoveTopBar = false;
            let shouldRemoveFooter = false;
            
            mutations.forEach(function(mutation) {
                if (mutation.type === 'childList') {
                    mutation.addedNodes.forEach(function(node) {
                        if (node.nodeType === 1) { // Element node
                            // Check for Elementor top bar
                            if (node.id && node.id.includes('e-admin-top-bar')) {
                                shouldRemoveTopBar = true;
                            }
                            if (node.className && typeof node.className === 'string' && node.className.includes('e-admin-top-bar')) {
                                shouldRemoveTopBar = true;
                            }
                            
                            // Check for Elementor footer content
                            if (node.id === 'footer-left' || node.id === 'wpfooter') {
                                shouldRemoveFooter = true;
                            }
                            
                            // Check text content for Elementor mentions
                            if (node.textContent && node.textContent.includes('Elementor') && node.textContent.includes('★')) {
                                shouldRemoveFooter = true;
                            }
                        }
                    });
                }
                
                // Also check for text changes in existing footer elements
                if (mutation.type === 'characterData' || mutation.type === 'childList') {
                    if (mutation.target.textContent && mutation.target.textContent.includes('Elementor') && mutation.target.textContent.includes('★')) {
                        shouldRemoveFooter = true;
                    }
                }
            });
            
            if (shouldRemoveTopBar) {
                removeElementorTopBar();
            }
            if (shouldRemoveFooter) {
                removeElementorFooter();
            }
        });
        
        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }

    const fetchBtn = $('#eat-fetch-strings-button');
    const translateBtn = $('#eat-translate-button');
    const resetBtn = $('#eat-reset-button');
    const statusDiv = $('#eat-status');
    const spinner = $('.spinner');
    const stringSelectionArea = $('#eat-string-selection-area');
    const stringListDiv = $('#eat-string-list');
    const selectAllCheckbox = $('#eat-select-all');
    const progressBar = $('#eat-progress-bar');
    const progressFill = $('#eat-progress-fill');
    const progressText = $('#eat-progress-text');
    const stringCount = $('#eat-string-count');
    
    let fetchedStrings = [];

    // Fetch content
    fetchBtn.on('click', function() {
        const pageId = $('#eat-page-select').val();
        if (!pageId) {
            showStatus('Please select a page.', 'error');
            return;
        }

        setLoadingState(true, 'Fetching content from Elementor...');
        resetBtn.hide();

        $.ajax({
            url: aitrfoel_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'aitrfoel_translate_page',
                nonce: aitrfoel_ajax.nonce,
                page_id: pageId
            },
            success: function(response) {
                if (response.success) {
                    fetchedStrings = response.data.strings || [];
                    displayStrings(fetchedStrings);
                    
                    if (fetchedStrings.length > 0) {
                        stringSelectionArea.show();
                        translateBtn.show();
                        resetBtn.show();
                        fetchBtn.hide();
                        stringCount.text(fetchedStrings.length);
                        showStatus(`Found ${fetchedStrings.length} translatable strings.`, 'success');
                    } else {
                        showStatus('No translatable content found on this page.', 'info');
                    }
                } else {
                    showStatus('Error: ' + (response.data?.message || 'Unknown error'), 'error');
                }
            },
            error: function(xhr, status, error) {
                showStatus('Failed to fetch content: ' + error, 'error');
            },
            complete: function() {
                setLoadingState(false);
            }
        });
    });

    // Translate selected strings
    translateBtn.on('click', function() {
        const pageId = $('#eat-page-select').val();
        const sourceLang = $('#eat-source-lang').val();
        const targetLang = $('#eat-target-lang').val();
        const selectedStrings = [];

        $('.eat-string-checkbox:checked').each(function() {
            const stringData = $(this).data('string');
            selectedStrings.push(stringData);
        });

        if (selectedStrings.length === 0) {
            showStatus('Please select at least one string to translate.', 'error');
            return;
        }

        if (!confirm(`Translate ${selectedStrings.length} strings to ${targetLang}?`)) {
            return;
        }

        // Open debug monitor
        if (typeof EAT_DebugMonitor !== 'undefined') {
            EAT_DebugMonitor.clear();
            EAT_DebugMonitor.open();
            EAT_DebugMonitor.addStep(
                'Translation Started',
                `Page ID: ${pageId}, Source: ${sourceLang}, Target: ${targetLang}`,
                'info',
                { pageId, sourceLang, targetLang, stringsCount: selectedStrings.length }
            );
        }

        setLoadingState(true, 'Starting translation...');
        showProgress(0);

        // Add debug step: AJAX request starting
        if (typeof EAT_DebugMonitor !== 'undefined') {
            EAT_DebugMonitor.addStep(
                'Sending AJAX Request',
                'Preparing translation request to server...',
                'loading'
            );
        }

        $.ajax({
            url: aitrfoel_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'aitrfoel_translate_page',
                nonce: aitrfoel_ajax.nonce,
                page_id: pageId,
                source_lang: sourceLang,
                target_lang: targetLang,
                strings: selectedStrings
            },
            xhr: function() {
                const xhr = new window.XMLHttpRequest();
                
                // Progress monitoring (simulated)
                let progress = 0;
                const interval = setInterval(function() {
                    if (progress < 90) {
                        progress += Math.random() * 10;
                        showProgress(Math.min(progress, 90));
                    }
                }, 500);
                
                xhr.addEventListener('loadend', function() {
                    clearInterval(interval);
                    showProgress(100);
                });
                
                return xhr;
            },
            success: function(response) {
                if (typeof EAT_DebugMonitor !== 'undefined') {
                    EAT_DebugMonitor.updateLastStep(
                        'AJAX Response Received',
                        'Server responded successfully',
                        'success',
                        { responseData: response }
                    );
                }
                
                if (response.success) {
                    if (typeof EAT_DebugMonitor !== 'undefined') {
                        EAT_DebugMonitor.addStep(
                            'Translation Completed',
                            'All strings translated successfully',
                            'success',
                            response.data
                        );
                    }
                    showTranslationSuccess(response.data);
                } else {
                    // Check if error data is already an object (new format) or needs parsing (old format)
                    let errorData = response.data;
                    
                    // If message is a string, try to parse it as JSON (backward compatibility)
                    if (typeof errorData.message === 'string' && errorData.message.startsWith('{')) {
                        try {
                            errorData = JSON.parse(errorData.message);
                        } catch (e) {
                            // Not JSON, use as plain message
                        }
                    }
                    
                    if (typeof EAT_DebugMonitor !== 'undefined') {
                        EAT_DebugMonitor.addStep(
                            'Translation Error',
                            errorData.message || 'Unknown error occurred',
                            'error',
                            errorData
                        );
                    }
                    
                    // Check if this is a structured error (limit_reached, cooldown, insufficient_words)
                    if (errorData && errorData.type && (errorData.type === 'cooldown' || errorData.type === 'limit_reached' || errorData.type === 'insufficient_words')) {
                        // Show cooldown/limit popup with countdown timer
                        if (typeof EAT_LicenseHelper !== 'undefined' && EAT_LicenseHelper.showCooldownPopup) {
                            EAT_LicenseHelper.showCooldownPopup(errorData.words_used || 0, errorData.word_limit || 1800, errorData);
                        } else {
                            // Fallback to alert
                            alert(errorData.message + '\n\nUpgrade at: ' + errorData.upgrade_url);
                        }
                        showStatus(errorData.message, 'error');
                    } else {
                        // Regular error message
                        showStatus('Translation failed: ' + (errorData.message || response.data?.message || 'Unknown error'), 'error');
                    }
                }
            },
            error: function(xhr, status, error) {
                // Debug monitor: AJAX error
                if (typeof EAT_DebugMonitor !== 'undefined') {
                    EAT_DebugMonitor.addStep(
                        'AJAX Error',
                        `Network error: ${error}`,
                        'error',
                        { 
                            status: status, 
                            statusCode: xhr.status, 
                            responseText: xhr.responseText,
                            readyState: xhr.readyState
                        }
                    );
                }
                
                // Try to parse error response
                try {
                    const errorResponse = JSON.parse(xhr.responseText);
                    if (errorResponse.data) {
                        let errorData = errorResponse.data;
                        
                        // If message is a string, try to parse it as JSON (backward compatibility)
                        if (typeof errorData.message === 'string' && errorData.message.startsWith('{')) {
                            try {
                                errorData = JSON.parse(errorData.message);
                            } catch (e) {
                                // Not JSON
                            }
                        }
                        
                        // Check if this is a structured error
                        if (errorData && errorData.type && (errorData.type === 'cooldown' || errorData.type === 'limit_reached' || errorData.type === 'insufficient_words')) {
                            if (typeof EAT_LicenseHelper !== 'undefined' && EAT_LicenseHelper.showCooldownPopup) {
                                EAT_LicenseHelper.showCooldownPopup(errorData.words_used || 0, errorData.word_limit || 1800, errorData);
                            } else {
                                alert(errorData.message);
                            }
                            showStatus(errorData.message, 'error');
                            return;
                        }
                    }
                } catch (e) {
                    // Continue with default error handling
                    if (typeof EAT_DebugMonitor !== 'undefined') {
                        EAT_DebugMonitor.addStep(
                            'Error Parsing Failed',
                            'Could not parse error response',
                            'warning',
                            { parseError: e.message }
                        );
                    }
                }
                
                showStatus('Translation failed: ' + error, 'error');
            },
            complete: function() {
                if (typeof EAT_DebugMonitor !== 'undefined') {
                    EAT_DebugMonitor.addStep(
                        'Request Completed',
                        'AJAX request finished (success or error)',
                        'info'
                    );
                }
                setLoadingState(false);
                hideProgress();
            }
        });
    });

    // Reset button
    resetBtn.on('click', function() {
        resetUI();
        showStatus('Ready to translate another page.', 'info');
    });

    // Clear cache button
    $('#aitrfoel-clear-cache-button').on('click', function() {
        if (!confirm('Are you sure you want to clear the translation cache? This will remove all cached translations and may increase API costs for future translations.')) {
            return;
        }

        setLoadingState(true, 'Clearing translation cache...');

        $.ajax({
            url: aitrfoel_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'aitrfoel_clear_cache',
                nonce: aitrfoel_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    showStatus(response.data.message, 'success');
                } else {
                    showStatus('Error: ' + (response.data?.message || 'Unknown error'), 'error');
                }
            },
            error: function(xhr, status, error) {
                showStatus('Failed to clear cache: ' + error, 'error');
            },
            complete: function() {
                setLoadingState(false);
            }
        });
    });

    // Clear cache button in settings page
    $('#aitrfoel-clear-cache-settings-btn').on('click', function() {
        if (!confirm('Are you sure you want to clear the translation cache? This will remove all cached translations and may increase API costs for future translations.')) {
            return;
        }

        const $btn = $(this);
        const originalText = $btn.text();
        $btn.prop('disabled', true).text('🔄 Clearing...');

        $.ajax({
            url: aitrfoel_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'aitrfoel_clear_cache',
                nonce: aitrfoel_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert('✅ ' + response.data.message);
                } else {
                    alert('❌ Error: ' + (response.data?.message || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                alert('❌ Failed to clear cache: ' + error);
            },
            complete: function() {
                $btn.prop('disabled', false).text(originalText);
            }
        });
    });

    // Select all checkbox
    selectAllCheckbox.on('change', function() {
        $('.eat-string-checkbox').prop('checked', $(this).prop('checked'));
        updateSelectedCount();
    });

    // Update count when individual checkboxes change
    $(document).on('change', '.eat-string-checkbox', function() {
        updateSelectedCount();
        
        // Update select all checkbox
        const total = $('.eat-string-checkbox').length;
        const checked = $('.eat-string-checkbox:checked').length;
        selectAllCheckbox.prop('checked', checked === total);
        selectAllCheckbox.prop('indeterminate', checked > 0 && checked < total);
    });

    // Display strings in the list
    function displayStrings(strings) {
        stringListDiv.empty();
        
        if (!strings || strings.length === 0) {
            stringListDiv.html('<div class="eat-empty-state">No translatable content found.</div>');
            return;
        }
        
        strings.forEach(function(str, index) {
            const original = str.original || '';
            const displayText = str.display_text || str.original || ''; // Use clean display text
            const fieldName = str.field_name || 'content';
            const widgetType = str.widget_type || 'unknown';
            
            const checkbox = $('<input>', {
                type: 'checkbox',
                class: 'eat-string-checkbox',
                id: 'string-' + index,
                checked: true
            }).data('string', str); // Keep original data for API
            
            const label = $('<label>', {
                for: 'string-' + index,
                html: `
                    <div class="eat-string-preview">
                        ${escapeHtml(displayText)}
                        <small style="color: #666; display: block; margin-top: 5px;">
                            Widget: ${widgetType} | Field: ${fieldName}
                        </small>
                    </div>
                `
            });
            
            const wrapper = $('<p>').append(checkbox).append(label);
            stringListDiv.append(wrapper);
        });
        
        updateSelectedCount();
    }

    // Update selected count
    function updateSelectedCount() {
        const total = $('.eat-string-checkbox').length;
        const checked = $('.eat-string-checkbox:checked').length;
        
        if (translateBtn.is(':visible')) {
            translateBtn.text(`Translate Selected (${checked})`);
        }
    }

    // Show status message (updated to preserve persistent translation success panel)
    function showStatus(message, type = 'info') {
        const hasPersistent = statusDiv.find('.eat-translation-success').length > 0;
        const isNewSuccessPanel = (type === 'success' && message.indexOf('eat-translation-success') !== -1);

        // Helper to build close button for persistent panel
        function ensurePersistentClose(msg) {
            if (msg.indexOf('eat-translation-success') !== -1 && msg.indexOf('eat-status-close') === -1) {
                const closeBtn = '<button type="button" class="eat-status-close" style="position:absolute;top:8px;right:8px;background:#fff;border:1px solid #ccc;border-radius:2px;cursor:pointer;">×</button>';
                return '<div style="position:relative;">' + closeBtn + msg + '</div>';
            }
            return msg;
        }

        // If we already have a persistent panel and this is NOT replacing it, append ephemeral notice
        if (hasPersistent && !isNewSuccessPanel) {
            // Create ephemeral container if not exists
            statusDiv.show();
            const ephemeral = $('<div class="eat-ephemeral-status ' + type + '" style="margin-top:8px;padding:8px 10px;background:#fff;border:1px solid #ccd0d4;border-left:4px solid;position:relative;">' + message + '</div>');
            // Color coding
            const colorMap = { success: '#00a32a', error: '#d63638', info: '#2271b1', warning: '#dba617' };
            ephemeral.css('border-left-color', colorMap[type] || '#2271b1');
            // Close button
            const closeBtn = $('<button type="button" style="position:absolute;top:4px;right:6px;background:transparent;border:none;font-size:16px;line-height:1;cursor:pointer;">×</button>');
            closeBtn.on('click', function() { ephemeral.fadeOut(200, () => ephemeral.remove()); });
            ephemeral.append(closeBtn);
            statusDiv.append(ephemeral);
            // Auto fade
            setTimeout(() => { ephemeral.fadeOut(400, () => ephemeral.remove()); }, 5000);
            return; // Do not replace persistent panel
        }

        // Otherwise (no persistent yet OR we are adding a new success panel) replace content
        statusDiv.removeClass('success error info warning').addClass(type);
        if (isNewSuccessPanel) {
            message = ensurePersistentClose(message);
        }
        statusDiv.html(message).show();

        // Auto-hide only if not a persistent success panel
        if (!isNewSuccessPanel) {
            if (type === 'success') {
                setTimeout(() => statusDiv.fadeOut(), 5000);
            }
        }
    }

    // Close persistent status
    $(document).on('click', '.eat-status-close', function() {
        $(this).closest('#eat-status').fadeOut();
    });

    // Set loading state
    function setLoadingState(isLoading, message = '') {
        if (isLoading) {
            spinner.addClass('is-active');
            fetchBtn.prop('disabled', true);
            translateBtn.prop('disabled', true);
            resetBtn.prop('disabled', true);
            if (message) {
                showStatus(message, 'info');
            }
        } else {
            spinner.removeClass('is-active');
            fetchBtn.prop('disabled', false);
            translateBtn.prop('disabled', false);
            resetBtn.prop('disabled', false);
        }
    }

    // Show progress bar
    function showProgress(percent) {
        progressBar.show();
        progressFill.css('width', percent + '%');
        progressText.text(Math.round(percent) + '%');
    }

    // Hide progress bar
    function hideProgress() {
        setTimeout(() => {
            progressBar.fadeOut();
            progressFill.css('width', '0%');
            progressText.text('0%');
        }, 1000);
    }

    // Reset UI
    function resetUI() {
        stringSelectionArea.hide();
        translateBtn.hide();
        resetBtn.hide();
        fetchBtn.show();
        stringListDiv.empty();
        selectAllCheckbox.prop('checked', false);
        fetchedStrings = [];
        statusDiv.hide();
        $('#eat-page-select').val('');
    }

    // Escape HTML
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, m => map[m]);
    }
    
    // Show translation success with action buttons
    function showTranslationSuccess(data) {
        const isDraft = data.is_draft;
        const translatedTitle = data.translated_title;
        const targetLanguage = data.target_language;
        
        let successHtml = `
            <div class="eat-translation-success">
                <div class="eat-success-message">
                    <strong>✅ ${data.message}</strong>
                    <p>"${translatedTitle}" has been created as a ${isDraft ? 'draft' : 'published page'}.</p>
                </div>
                <div class="eat-action-buttons">
        `;
        
        // Preview button (always available)
        successHtml += `
            <button class="button button-primary eat-preview-btn" data-page-id="${data.translated_page_id}">
                👁️ Preview Translation
            </button>
        `;
        
        // Publish button (only if draft)
        if (isDraft) {
            successHtml += `
                <button class="button button-secondary eat-publish-btn" data-page-id="${data.translated_page_id}">
                    🚀 Publish Now
                </button>
            `;
        }
        
        // Edit button
        successHtml += `
            <a href="${data.edit_url}" target="_blank" class="button button-secondary">
                ✏️ Edit Page
            </a>
        `;
        
        // View button (if published)
        if (!isDraft) {
            successHtml += `
                <a href="${data.view_url}" target="_blank" class="button button-secondary">
                    🔗 View Live Page
                </a>
            `;
        }
        
        successHtml += `
                </div>
                <div class="eat-translation-details" style="margin-top: 15px; padding: 10px; background: #f8f9fa; border-radius: 4px; font-size: 0.9em; color: #666;">
                    <strong>Translation Details:</strong><br>
                    • Original: "${data.original_title}"<br>
                    • Translated: "${translatedTitle}" (${targetLanguage})<br>
                    • Status: ${data.status}<br>
                    • Page ID: ${data.translated_page_id}
                </div>
            </div>
        `;
        
        showStatus(successHtml, 'success');
        
        // Hide form controls
        stringSelectionArea.hide();
        translateBtn.hide();
        
        // Show reset button
        resetBtn.show().text('Translate Another Page');
    }
    
    // Handle preview button click
    $(document).on('click', '.eat-preview-btn', function() {
        const pageId = $(this).data('page-id');
        const button = $(this);
        
        button.prop('disabled', true).text('Opening Preview...');
        
        $.ajax({
            url: aitrfoel_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'aitrfoel_get_preview_url',
                nonce: aitrfoel_ajax.nonce,
                page_id: pageId
            },
            success: function(response) {
                if (response.success) {
                    // Open preview in new tab
                    window.open(response.data.preview_url, '_blank');
                    
                    // Show success message
                    showStatus(`Preview opened for "${response.data.title}" ✨`, 'info');
                } else {
                    showStatus('Failed to open preview: ' + (response.data?.message || 'Unknown error'), 'error');
                }
            },
            error: function() {
                showStatus('Failed to open preview. Please try again.', 'error');
            },
            complete: function() {
                button.prop('disabled', false).text('👁️ Preview Translation');
            }
        });
    });
    
    // Handle publish button click
    $(document).on('click', '.eat-publish-btn', function() {
        const pageId = $(this).data('page-id');
        const button = $(this);
        
        if (!confirm('Are you sure you want to publish this translated page? It will be visible to your website visitors.')) {
            return;
        }
        
        button.prop('disabled', true).text('Publishing...');
        
        $.ajax({
            url: aitrfoel_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'aitrfoel_publish_translation',
                nonce: aitrfoel_ajax.nonce,
                page_id: pageId
            },
            success: function(response) {
                if (response.success) {
                    // Update the success message to reflect published status
                    const successDiv = $('.eat-translation-success');
                    successDiv.find('.eat-success-message p').text(`"${response.data.page_id}" has been published successfully!`);
                    
                    // Replace publish button with view button
                    button.replaceWith(`
                        <a href="${response.data.view_url}" target="_blank" class="button button-secondary">
                            🔗 View Live Page
                        </a>
                    `);
                    
                    // Update status details
                    successDiv.find('.eat-translation-details').append('<br>• ✅ Published at: ' + new Date().toLocaleString());
                    
                    showStatus(response.data.message + ' 🎉', 'success');
                } else {
                    showStatus('Failed to publish: ' + (response.data?.message || 'Unknown error'), 'error');
                }
            },
            error: function() {
                showStatus('Failed to publish. Please try again.', 'error');
            },
            complete: function() {
                if (button.length) {
                    button.prop('disabled', false).text('🚀 Publish Now');
                }
            }
        });
    });

    // --- Theme Compatibility UI ---
    const themeStatusWrapper = $('#theme-compatibility-status-wrapper');
    const diagnoseBtn = $('#eat-diagnose-theme-btn');

    // Main function to update the UI with status data
    function updateThemeStatusUI(status) {
        if (!status || !status.theme_name) {
            themeStatusWrapper.html('<p style="color: red;">Error: Could not retrieve theme status.</p>');
            return;
        }

        // Простая версия без сложной логики
        let icon = '';
        let statusText = '';
        
        if (status.confidence === 'high') {
            icon = '✅';
            statusText = 'Full Support';
        } else if (status.confidence === 'medium') {
            icon = '🟡';
            statusText = 'Automatic Compatibility';
        } else {
            icon = '🔴';
            statusText = 'Configuration Required';
        }

        const fieldsCount = Array.isArray(status.detected_fields) ? status.detected_fields.length : Object.keys(status.detected_fields || {}).length;
        const fieldsArray = Array.isArray(status.detected_fields) ? status.detected_fields : Object.values(status.detected_fields || {}); // ИСПРАВЛЕНО: Object.values вместо Object.keys
        
        const html = `
            <div style="padding: 15px; border: 1px solid #ddd; border-radius: 5px; background: #f9f9f9;">
                <p><strong>${icon} Theme: ${status.theme_name} v${status.theme_version}</strong></p>
                <p>Status: ${statusText}</p>
                <p>Found fields: ${fieldsCount} 
                    ${fieldsCount > 0 ? '<span class="theme-fields-info" style="cursor: pointer; color: #0073aa; font-weight: bold; margin-left: 5px;" title="Show field list">[i]</span>' : ''}
                </p>
                <p><em>${status.message}</em></p>
            </div>
        `;
        
        themeStatusWrapper.html(html);
        
        // Add click handler for the info icon
        if (fieldsCount > 0) {
            $('.theme-fields-info').on('click', function() {
                showFieldsPopup(fieldsArray, status.theme_name);
            });
        }
    }

    // Function to show popup with detected theme fields
    function showFieldsPopup(fields, themeName) {
        // Remove existing popup if any
        $('.theme-fields-popup').remove();
        
        // Create fields list HTML
        let fieldsHtml = '';
        if (fields.length === 0) {
            fieldsHtml = '<p><em>No fields found</em></p>';
        } else {
            fieldsHtml = '<ul style="margin: 0; padding-left: 20px; max-height: 300px; overflow-y: auto;">';
            fields.forEach(function(field) {
                fieldsHtml += `<li style="margin: 5px 0; font-family: monospace; font-size: 12px; word-break: break-all;">${field}</li>`;
            });
            fieldsHtml += '</ul>';
        }
        
        // Create popup HTML
        const popupHtml = `
            <div class="theme-fields-popup" style="
                position: fixed; 
                top: 0; 
                left: 0; 
                width: 100%; 
                height: 100%; 
                background: rgba(0,0,0,0.5); 
                z-index: 100000;
                display: flex;
                align-items: center;
                justify-content: center;
            ">
                <div style="
                    background: white; 
                    padding: 20px; 
                    border-radius: 8px; 
                    max-width: 600px; 
                    max-height: 80vh;
                    overflow-y: auto;
                    position: relative;
                    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
                ">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 1px solid #ddd; padding-bottom: 10px;">
                        <h3 style="margin: 0; color: #333;">Detected Theme Fields</h3>
                        <span class="close-popup" style="
                            cursor: pointer; 
                            font-size: 24px; 
                            color: #666; 
                            font-weight: bold;
                            width: 30px;
                            height: 30px;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            border-radius: 50%;
                            background: #f1f1f1;
                        " title="Close">&times;</span>
                    </div>
                    
                    <p><strong>Theme:</strong> ${themeName}</p>
                    <p><strong>Found fields:</strong> ${fields.length}</p>
                    
                    <div style="margin: 15px 0;">
                        <button class="copy-fields-btn" style="
                            background: #0073aa; 
                            color: white; 
                            border: none; 
                            padding: 8px 15px; 
                            border-radius: 4px; 
                            cursor: pointer;
                            font-size: 12px;
                        ">📋 Copy all fields</button>
                    </div>
                    
                    <div style="border: 1px solid #ddd; border-radius: 4px; padding: 10px; background: #f9f9f9;">
                        ${fieldsHtml}
                    </div>
                    
                    <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 11px; color: #666;">
                        <p><strong>What this means:</strong> These meta-fields are used by the theme to configure the appearance of pages. During translation they will be copied to preserve the layout.</p>
                    </div>
                </div>
            </div>
        `;
        
        // Add popup to page
        $('body').append(popupHtml);
        
        // Add event handlers
        $('.close-popup, .theme-fields-popup').on('click', function(e) {
            if (e.target === this) {
                $('.theme-fields-popup').remove();
            }
        });
        
        // Copy fields functionality
        $('.copy-fields-btn').on('click', function() {
            const fieldsText = fields.join('\n');
            const $btn = $(this); // Store reference to button
            
            // Disable button during operation
            $btn.prop('disabled', true);
            
            // Try to copy to clipboard
            if (navigator.clipboard) {
                navigator.clipboard.writeText(fieldsText).then(function() {
                    // Success animation
                    $btn.addClass('copied').text('✅ Copied!');
                    setTimeout(() => {
                        $btn.removeClass('copied').text('📋 Copy all fields').prop('disabled', false);
                    }, 2000);
                }).catch(function(err) {
                    console.log('Clipboard API failed, using fallback:', err);
                    // Fallback for older browsers
                    copyToClipboardFallback(fieldsText, $btn);
                });
            } else {
                copyToClipboardFallback(fieldsText, $btn);
            }
        });
        
        // Prevent popup close when clicking inside the modal content
        $('.theme-fields-popup > div').on('click', function(e) {
            e.stopPropagation();
        });
    }
    
    // Fallback function for copying to clipboard
    function copyToClipboardFallback(text, $btn) {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.position = 'fixed';
        textArea.style.left = '-999999px';
        textArea.style.top = '-999999px';
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        
        try {
            const successful = document.execCommand('copy');
            if (successful) {
                // Success animation
                $btn.addClass('copied').text('✅ Copied!');
                setTimeout(() => {
                    $btn.removeClass('copied').text('📋 Copy all fields').prop('disabled', false);
                }, 2000);
            } else {
                throw new Error('execCommand returned false');
            }
        } catch (err) {
            console.log('Fallback copy failed:', err);
            // Error animation
            $btn.addClass('error').text('❌ Copy error');
            setTimeout(() => {
                $btn.removeClass('error').text('📋 Copy all fields').prop('disabled', false);
            }, 3000);
        }
        
        document.body.removeChild(textArea);
    }

    // Function to trigger the diagnosis via AJAX
    function diagnoseTheme() {
        diagnoseBtn.prop('disabled', true).text('🔍 Checking...');
        themeStatusWrapper.html('<p><i>Running compatibility check...</i></p>');

        const ajaxData = {
            action: 'aitrfoel_diagnose_theme',
            nonce: aitrfoel_ajax.diagnose_nonce
        };

        $.ajax({
            url: aitrfoel_ajax.ajax_url,
            type: 'POST',
            data: ajaxData,
            success: function(response) {
                if (response.success) {
                    updateThemeStatusUI(response.data);
                } else {
                    themeStatusWrapper.html('<p style="color: red;">Error: ' + (response.data || 'Unknown error') + '</p>');
                }
            },
            error: function(xhr, status, error) {
                themeStatusWrapper.html('<p style="color: red;">AJAX request failed. Check browser console for details.</p>');
            },
            complete: function() {
                diagnoseBtn.prop('disabled', false).text('🔍 Re-check Compatibility');
            }
        });
    }
    
    // Function to estimate translation cost
    function estimateCost() {
        const pageId = $('#eat-page-select').val();
        const targetLang = $('#eat-target-lang').val() || 'Spanish';
        
        if (!pageId) {
            showStatus('Please select a page first.', 'error');
            return;
        }
        
        const $estimateBtn = $('#eat-estimate-cost-btn');
        $estimateBtn.prop('disabled', true).text('💰 Calculating...');
        
        $.ajax({
            url: aitrfoel_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'aitrfoel_estimate_cost',
                nonce: aitrfoel_ajax.nonce,
                page_id: pageId,
                target_lang: targetLang
            },
            success: function(response) {
                if (response.success) {
                    showCostEstimation(response.data);
                } else {
                    showStatus('Cost estimation failed: ' + (response.data?.message || 'Unknown error'), 'error');
                }
            },
            error: function(xhr, status, error) {
                showStatus('Failed to estimate cost: ' + error, 'error');
            },
            complete: function() {
                $estimateBtn.prop('disabled', false).text('💰 Estimate Cost');
            }
        });
    }
    
    // Function to show cost estimation
    function showCostEstimation(data) {
        const costHtml = `
            <div class="eat-cost-estimation" style="
                padding: 15px; 
                border: 1px solid #0073aa; 
                border-radius: 5px; 
                background: #f0f8ff; 
                margin: 10px 0;
            ">
                <h4 style="margin: 0 0 10px 0; color: #0073aa;">💰 Translation Cost Estimate</h4>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <div><strong>Estimated Cost:</strong> ${data.cost}</div>
                    <div><strong>Word Count:</strong> ${data.breakdown.word_count} words</div>
                    <div><strong>Elements:</strong> ${data.breakdown.total_elements}</div>
                    <div><strong>Tokens:</strong> ~${data.breakdown.estimated_tokens}</div>
                    <div><strong>Provider:</strong> ${data.breakdown.provider}</div>
                    <div><strong>Model:</strong> ${data.breakdown.model}</div>
                </div>
                <p style="margin: 10px 0 0 0; font-size: 0.9em; color: #666;">
                    ${data.message}
                </p>
            </div>
        `;
        
        // Remove any existing cost estimation
        $('.eat-cost-estimation').remove();
        
        // Add to cost estimation area
        $('#eat-cost-estimation-area').html(costHtml);
        
        showStatus('Cost estimation completed successfully!', 'success');
    }
    


    // --- Event Handlers ---

    // Handle theme diagnosis button click
    diagnoseBtn.on('click', function() {
        diagnoseTheme();
    });
    
    // Handle cost estimation button click
    $('#eat-estimate-cost-btn').on('click', function() {
        estimateCost();
    });
    


    


    // Initial check on page load, only if the wrapper exists
    if (themeStatusWrapper.length) {
        // Show ready state instead of auto-checking
        themeStatusWrapper.html('<p style="color: #666;">Click the button to check theme compatibility.</p>');
    }
    
});