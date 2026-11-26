/**
 * Translation Settings Modal
 * Handles the UI for exclude words and smart suggestions
 */

class AITRFOEL_SettingsModal {
    constructor() {
        this.modal = null;
        this.excludeWords = [];
        this.translatedPageStatus = 'draft';
        this.seoTranslationEnabled = true;
        this.seoPluginDetected = false;
        this.seoPluginName = '';
        this.isLoading = false;
        
        this.init();
    }

    init() {
        this.createModalHTML();
        this.bindEvents();
        this.loadSettings();
    }

    /**
     * Create modal HTML structure
     */
    createModalHTML() {
        const modalHTML = `
            <div id="aitrfoel-settings-modal-overlay" class="aitrfoel-settings-modal-overlay">
                <div class="aitrfoel-settings-modal">
                    <div class="aitrfoel-settings-modal-header">
                        <h3 class="aitrfoel-settings-modal-title">Translation Settings</h3>
                        <button class="aitrfoel-settings-modal-close" type="button">&times;</button>
                    </div>
                    
                    <div class="aitrfoel-settings-modal-body">
                        <!-- Exclude Words Section -->
                        <div class="aitrfoel-settings-section">
                            <label class="aitrfoel-settings-label">Exclude from Translation</label>
                            <div class="aitrfoel-settings-description">
                                Add words or terms that should not be translated. Press Enter or Space to add a word.
                            </div>
                            
                            <div class="aitrfoel-exclude-words-container" id="aitrfoel-exclude-words-container">
                                <div class="aitrfoel-exclude-words-chips" id="aitrfoel-exclude-words-chips"></div>
                                <input type="text" 
                                       class="aitrfoel-exclude-words-input" 
                                       id="aitrfoel-exclude-words-input"
                                       placeholder="Type a word and press Enter...">
                            </div>
                        </div>

                        <div class="aitrfoel-settings-section">
                            <label class="aitrfoel-settings-label" for="aitrfoel-modal-page-status">Translated Page Status</label>
                            <div class="aitrfoel-settings-description">
                                Choose how newly created translated pages should be saved.
                            </div>
                            <select id="aitrfoel-modal-page-status" class="aitrfoel-exclude-words-input" style="width: 100%; max-width: 320px; margin-top: 8px;">
                                <option value="draft">Draft (Recommended)</option>
                                <option value="publish">Published (Auto-publish)</option>
                                <option value="inherit">Inherit from Original</option>
                            </select>
                        </div>

                        <div class="aitrfoel-settings-section">
                            <label class="aitrfoel-settings-label" for="aitrfoel-modal-seo-enabled">SEO Meta Translation</label>
                            <div class="aitrfoel-settings-description" id="aitrfoel-modal-seo-note">
                                Automatically translates SEO title, description, and focus keywords when translating pages.
                            </div>
                            <label style="display: inline-flex; align-items: center; gap: 8px; margin-top: 8px;">
                                <input type="checkbox" id="aitrfoel-modal-seo-enabled" value="1">
                                <span>Translate SEO title &amp; meta description</span>
                            </label>
                        </div>
                    </div>
                    
                    <div class="aitrfoel-settings-modal-footer">
                        <div class="aitrfoel-settings-preview" id="aitrfoel-settings-preview">
                            Preview: No exclusions set
                        </div>
                        <div class="aitrfoel-settings-actions">
                            <button class="aitrfoel-settings-cancel" type="button">Cancel</button>
                            <button class="aitrfoel-settings-save" type="button">Save Settings</button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        // Add to body
        document.body.insertAdjacentHTML('beforeend', modalHTML);
        this.modal = document.getElementById('aitrfoel-settings-modal-overlay');
    }

    /**
     * Bind event handlers
     */
    bindEvents() {
        // Open modal when settings button is clicked
        const settingsBtn = document.getElementById('aitrfoel-bulk-settings');
        if (settingsBtn) {
            settingsBtn.addEventListener('click', () => this.openModal());
        }

        // Close modal events
        const closeBtn = this.modal.querySelector('.aitrfoel-settings-modal-close');
        const cancelBtn = this.modal.querySelector('.aitrfoel-settings-cancel');
        const overlay = this.modal;

        closeBtn.addEventListener('click', () => this.closeModal());
        cancelBtn.addEventListener('click', () => this.closeModal());
        
        // Close on overlay click (but not on modal click)
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                this.closeModal();
            }
        });

        // Exclude words input events
        const input = document.getElementById('aitrfoel-exclude-words-input');
        const container = document.getElementById('aitrfoel-exclude-words-container');

        input.addEventListener('keydown', (e) => this.handleInputKeydown(e));
        input.addEventListener('input', () => this.updatePreview());
        container.addEventListener('click', () => input.focus());

        // Save button
        const saveBtn = this.modal.querySelector('.aitrfoel-settings-save');
        saveBtn.addEventListener('click', () => this.saveSettings());

        // ESC key to close
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.modal.classList.contains('show')) {
                this.closeModal();
            }
        });
    }

    /**
     * Handle input keydown events
     */
    handleInputKeydown(e) {
        const input = e.target;
        const value = input.value.trim();

        if ((e.key === 'Enter' || e.key === ' ') && value) {
            e.preventDefault();
            this.addExcludeWord(value);
            input.value = '';
        } else if (e.key === 'Backspace' && !value && this.excludeWords.length > 0) {
            // Remove last chip if input is empty and backspace is pressed
            this.removeExcludeWord(this.excludeWords[this.excludeWords.length - 1]);
        }
    }

    /**
     * Add word to exclude list
     */
    addExcludeWord(word) {
        if (!word || this.excludeWords.includes(word)) {
            return;
        }

        this.excludeWords.push(word);
        this.renderExcludeChips();
        this.updatePreview();
    }

    /**
     * Remove word from exclude list
     */
    removeExcludeWord(word) {
        const index = this.excludeWords.indexOf(word);
        if (index > -1) {
            this.excludeWords.splice(index, 1);
            this.renderExcludeChips();
            this.updatePreview();
        }
    }

    /**
     * Render exclude word chips
     */
    renderExcludeChips() {
        const container = document.getElementById('aitrfoel-exclude-words-chips');
        container.innerHTML = '';

        this.excludeWords.forEach(word => {
            const chip = document.createElement('div');
            chip.className = 'aitrfoel-exclude-chip';
            chip.innerHTML = `
                <span class="aitrfoel-exclude-chip-text">${this.escapeHtml(word)}</span>
                <button class="aitrfoel-exclude-chip-remove" type="button">&times;</button>
            `;

            // Add remove event
            chip.querySelector('.aitrfoel-exclude-chip-remove').addEventListener('click', () => {
                this.removeExcludeWord(word);
            });

            container.appendChild(chip);
        });
    }

    /**
     * Update preview text
     */
    updatePreview() {
        const preview = document.getElementById('aitrfoel-settings-preview');
        const statusTextMap = {
            draft: 'Draft',
            publish: 'Published',
            inherit: 'Inherit from Original'
        };
        const statusText = statusTextMap[this.translatedPageStatus] || 'Draft';
        const seoState = this.seoPluginDetected
            ? (this.seoTranslationEnabled ? 'On' : 'Off')
            : 'Unavailable';
        
        if (this.excludeWords.length === 0) {
            preview.textContent = `Preview: No exclusions set | Page status: ${statusText} | SEO: ${seoState}`;
        } else {
            const wordList = this.excludeWords.join(', ');
            preview.textContent = `Preview: "Translate everything except: ${wordList}" | Page status: ${statusText} | SEO: ${seoState}`;
        }
    }

    /**
     * Load current settings
     */
    async loadSettings() {
        try {
            const response = await this.makeAjaxRequest('aitrfoel_get_translation_settings', {});
            
            if (response.success) {
                this.excludeWords = response.data.exclude_words || [];
                this.translatedPageStatus = response.data.translated_page_status || 'draft';
                this.seoTranslationEnabled = !!response.data.seo_translation_enabled;
                this.seoPluginDetected = !!response.data.seo_plugin_detected;
                this.seoPluginName = response.data.seo_plugin_name || '';
                this.renderExcludeChips();
                this.renderAdditionalSettings();
                this.updatePreview();
            }
        } catch (error) {
            console.error('Failed to load settings:', error);
        }
    }

    renderAdditionalSettings() {
        const pageStatus = document.getElementById('aitrfoel-modal-page-status');
        const seoToggle = document.getElementById('aitrfoel-modal-seo-enabled');
        const seoNote = document.getElementById('aitrfoel-modal-seo-note');

        if (pageStatus) {
            pageStatus.value = this.translatedPageStatus;
            pageStatus.addEventListener('change', () => {
                this.translatedPageStatus = pageStatus.value;
                this.updatePreview();
            });
        }

        if (seoToggle) {
            seoToggle.checked = this.seoTranslationEnabled;
            seoToggle.disabled = !this.seoPluginDetected;
            seoToggle.addEventListener('change', () => {
                this.seoTranslationEnabled = seoToggle.checked;
                this.updatePreview();
            });
        }

        if (seoNote) {
            if (this.seoPluginDetected) {
                const pluginName = this.seoPluginName ? `${this.seoPluginName} detected.` : 'SEO plugin detected.';
                seoNote.textContent = `${pluginName} Automatically translates SEO title, description, and focus keywords when translating pages.`;
                seoNote.style.opacity = '1';
            } else {
                seoNote.textContent = 'No supported SEO plugin detected (Yoast SEO or Rank Math). This option is currently unavailable.';
                seoNote.style.opacity = '0.7';
            }
        }
    }

    /**
     * Save settings
     */
    async saveSettings() {
        if (this.isLoading) return;

        const saveBtn = this.modal.querySelector('.aitrfoel-settings-save');
        const originalText = saveBtn.textContent;
        
        try {
            this.isLoading = true;
            saveBtn.disabled = true;
            saveBtn.textContent = 'Saving...';

            const response = await this.makeAjaxRequest('aitrfoel_save_translation_settings', {
                exclude_words: this.excludeWords,
                smart_suggestions_enabled: true,
                translated_page_status: this.translatedPageStatus,
                seo_translation_enabled: this.seoTranslationEnabled ? 1 : 0
            });

            if (response.success) {
                this.showNotification('Settings saved successfully!', 'success');
                this.closeModal();
            } else {
                throw new Error(response.data.message || 'Failed to save settings');
            }
        } catch (error) {
            console.error('Save error:', error);
            this.showNotification('Failed to save settings: ' + error.message, 'error');
        } finally {
            this.isLoading = false;
            saveBtn.disabled = false;
            saveBtn.textContent = originalText;
        }
    }

    /**
     * Open modal
     */
    openModal() {
        this.modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        
        // Focus input
        setTimeout(() => {
            const input = document.getElementById('aitrfoel-exclude-words-input');
            input.focus();
        }, 100);
    }

    /**
     * Close modal
     */
    closeModal() {
        this.modal.classList.remove('show');
        document.body.style.overflow = '';
    }

    /**
     * Make AJAX request
     */
    async makeAjaxRequest(action, data) {
        const formData = new FormData();
        formData.append('action', action);
        formData.append('nonce', aitrfoel_ajax.nonce);
        
        Object.entries(data).forEach(([key, value]) => {
            if (Array.isArray(value)) {
                value.forEach((item, index) => {
                    formData.append(`${key}[${index}]`, item);
                });
            } else {
                formData.append(key, value);
            }
        });

        const response = await fetch(aitrfoel_ajax.ajax_url, {
            method: 'POST',
            body: formData
        });

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const rawText = await response.text();
        const sanitizedText = rawText.replace(/^\uFEFF+/, '').trim();

        try {
            return JSON.parse(sanitizedText);
        } catch (error) {
            const preview = sanitizedText.slice(0, 160).replace(/\s+/g, ' ');
            throw new Error(`Invalid JSON response: ${preview}`);
        }
    }

    /**
     * Show notification
     */
    showNotification(message, type = 'info') {
        // Create notification element
        const notification = document.createElement('div');
        notification.className = `notice notice-${type} is-dismissible`;
        notification.style.cssText = `
            position: fixed;
            top: 32px;
            right: 20px;
            z-index: 170000;
            max-width: 300px;
            animation: slideInRight 0.3s ease;
        `;
        notification.innerHTML = `<p>${this.escapeHtml(message)}</p>`;

        document.body.appendChild(notification);

        // Auto remove after 3 seconds
        setTimeout(() => {
            notification.style.animation = 'slideOutRight 0.3s ease';
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
            }, 300);
        }, 3000);
    }

    /**
     * Escape HTML
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    if (typeof aitrfoel_ajax !== 'undefined') {
        window.eatSettingsModal = new AITRFOEL_SettingsModal();
    }
});

// Add CSS animations
const style = document.createElement('style');
style.textContent = `
    @keyframes slideInRight {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    @keyframes slideOutRight {
        from { transform: translateX(0); opacity: 1; }
        to { transform: translateX(100%); opacity: 0; }
    }
`;
document.head.appendChild(style);
