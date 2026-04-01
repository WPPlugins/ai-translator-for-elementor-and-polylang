/**
 * Translation Settings Modal
 * Handles the UI for exclude words and smart suggestions
 */

class AITRFOEL_SettingsModal {
    constructor() {
        this.modal = null;
        this.excludeWords = [];
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
                            
                            <div class="eat-exclude-words-container" id="eat-exclude-words-container">
                                <div class="eat-exclude-words-chips" id="eat-exclude-words-chips"></div>
                                <input type="text" 
                                       class="eat-exclude-words-input" 
                                       id="eat-exclude-words-input"
                                       placeholder="Type a word and press Enter...">
                            </div>
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
        const settingsBtn = document.getElementById('eat-bulk-settings');
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
        const input = document.getElementById('eat-exclude-words-input');
        const container = document.getElementById('eat-exclude-words-container');

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
        const container = document.getElementById('eat-exclude-words-chips');
        container.innerHTML = '';

        this.excludeWords.forEach(word => {
            const chip = document.createElement('div');
            chip.className = 'eat-exclude-chip';
            chip.innerHTML = `
                <span class="eat-exclude-chip-text">${this.escapeHtml(word)}</span>
                <button class="eat-exclude-chip-remove" type="button">&times;</button>
            `;

            // Add remove event
            chip.querySelector('.eat-exclude-chip-remove').addEventListener('click', () => {
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
        
        if (this.excludeWords.length === 0) {
            preview.textContent = 'Preview: No exclusions set';
        } else {
            const wordList = this.excludeWords.join(', ');
            preview.textContent = `Preview: "Translate everything except: ${wordList}"`;
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
                this.renderExcludeChips();
                this.updatePreview();
            }
        } catch (error) {
            console.error('Failed to load settings:', error);
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
                smart_suggestions_enabled: true
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
            const input = document.getElementById('eat-exclude-words-input');
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

        return await response.json();
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
