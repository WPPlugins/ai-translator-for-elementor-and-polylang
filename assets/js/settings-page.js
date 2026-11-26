// Normalize AJAX URL to match current origin/port (prevents CORS on custom LocalWP ports)
if (typeof aitrfoel_settings_ajax !== 'undefined' && aitrfoel_settings_ajax.ajax_url && window.location && window.location.origin) {
    try {
        var parsedUrl = new URL(aitrfoel_settings_ajax.ajax_url, window.location.origin);
        if (parsedUrl.hostname === window.location.hostname && parsedUrl.port !== window.location.port) {
            parsedUrl.port = window.location.port;
            parsedUrl.protocol = window.location.protocol;
            aitrfoel_settings_ajax.ajax_url = parsedUrl.toString();
        }
    } catch (e) {}
}

function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    if (field.type === 'password') {
        field.type = 'text';
    } else {
        field.type = 'password';
    }
}

function testApiConnection(provider) {
    if (provider !== 'openai') return; // Only support OpenAI now
    
    const button = document.getElementById('test-openai-api');
    const statusDiv = document.getElementById('openai-api-status');
    const apiKey = document.getElementById('aitrfoel_openai_api_key').value;
    
    if (!apiKey.trim()) {
        showApiStatus(statusDiv, 'error', 'Please enter an API key first');
        return;
    }
    
    // Disable button and show loading
    button.disabled = true;
    button.innerHTML = 'Testing...';
    showApiStatus(statusDiv, 'loading', 'Testing API connection...');
    
    // Make AJAX request
    const formData = new FormData();
    formData.append('action', 'aitrfoel_test_api');
    formData.append('provider', 'openai');
    formData.append('api_key', apiKey);
    formData.append('nonce', aitrfoel_settings_ajax.test_nonce);
    
    fetch(aitrfoel_settings_ajax.ajax_url, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        button.disabled = false;
        button.innerHTML = 'Test';
        
        if (data.success) {
            showApiStatus(statusDiv, 'success', data.data);
        } else {
            showApiStatus(statusDiv, 'error', data.data);
        }
    })
    .catch(error => {
        button.disabled = false;
        button.innerHTML = 'Test';
        showApiStatus(statusDiv, 'error', 'Network error: ' + error.message);
    });
}

function showApiStatus(statusDiv, type, message) {
    statusDiv.innerHTML = message;
    statusDiv.className = 'api-status api-status-' + type;
    
    // Auto-hide after 10 seconds for success messages
    if (type === 'success') {
        setTimeout(() => {
            statusDiv.innerHTML = '';
            statusDiv.className = 'api-status';
        }, 10000);
    }
}

// Clear cache button handler
document.addEventListener('DOMContentLoaded', function() {
    const clearCacheBtn = document.getElementById('aitrfoel-clear-cache-btn');
    const statusSpan = document.getElementById('aitrfoel-clear-cache-status');
    
    if (clearCacheBtn) {
        clearCacheBtn.addEventListener('click', function() {
            if (!confirm(aitrfoel_settings_ajax.clear_cache_confirm)) {
                return;
            }
            
            // Disable button and show loading
            clearCacheBtn.disabled = true;
            clearCacheBtn.innerHTML = aitrfoel_settings_ajax.clearing_text;
            statusSpan.innerHTML = '';
            
            // Make AJAX request
            const formData = new FormData();
            formData.append('action', 'aitrfoel_clear_cache');
            formData.append('nonce', aitrfoel_settings_ajax.clear_cache_nonce);
            
            fetch(aitrfoel_settings_ajax.ajax_url, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                clearCacheBtn.disabled = false;
                clearCacheBtn.innerHTML = aitrfoel_settings_ajax.clear_cache_text;
                
                if (data.success) {
                    statusSpan.innerHTML = '<span style="color: green;">' + data.data.message + '</span>';
                    setTimeout(() => {
                        statusSpan.innerHTML = '';
                    }, 5000);
                } else {
                    statusSpan.innerHTML = '<span style="color: red;">' + data.data + '</span>';
                }
            })
            .catch(error => {
                clearCacheBtn.disabled = false;
                clearCacheBtn.innerHTML = aitrfoel_settings_ajax.clear_cache_text;
                statusSpan.innerHTML = '<span style="color: red;">Error: ' + error.message + '</span>';
            });
        });
    }

    // -------------------------------------------------------------------------
    // Clear Smart Translation Memory Handler
    // -------------------------------------------------------------------------
    const clearTmBtn = document.getElementById('aitrfoel-clear-tm-btn');
    if (clearTmBtn) {
        clearTmBtn.addEventListener('click', function(e) {
            e.preventDefault();

            if (!confirm(aitrfoel_settings_ajax.clear_tm_confirm || 'Are you sure you want to clear all Translation Memory?')) {
                return;
            }

            const origLabel = document.getElementById('aitrfoel-clear-tm-label');
            const origHtml = origLabel ? origLabel.textContent : 'Clear Memory';
            clearTmBtn.disabled = true;
            if (origLabel) origLabel.textContent = aitrfoel_settings_ajax.clearing_text || 'Clearing...';

            const formData = new FormData();
            formData.append('action', 'aitrfoel_clear_translation_memory');
            formData.append('nonce', aitrfoel_settings_ajax.tm_nonce);

            fetch(aitrfoel_settings_ajax.ajax_url, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                clearTmBtn.disabled = false;
                if (origLabel) origLabel.textContent = origHtml;

                if (data.success) {
                    const entriesEl = document.getElementById('tm-stat-entries');
                    const savedEl = document.getElementById('tm-stat-words-saved');
                    const hitsEl = document.getElementById('tm-stat-hits');
                    if (entriesEl) entriesEl.textContent = '0';
                    if (savedEl) savedEl.textContent = '~0';
                    if (hitsEl) hitsEl.textContent = '0';
                    alert(data.data.message || 'Translation Memory cleared successfully.');
                } else {
                    alert('Error: ' + (data.data && data.data.message ? data.data.message : 'Failed to clear memory.'));
                }
            })
            .catch(err => {
                clearTmBtn.disabled = false;
                if (origLabel) origLabel.textContent = origHtml;
                alert('Request failed: ' + err.message);
            });
        });
    }

    // -------------------------------------------------------------------------
    // AI Engine Visual Card Selection Handler
    // -------------------------------------------------------------------------
    const engineCards = document.querySelectorAll('.aitrfoel-engine-card');
    const engineHiddenInput = document.getElementById('aitrfoel_ai_engine');
    if (engineCards.length && engineHiddenInput) {
        engineCards.forEach(card => {
            card.addEventListener('click', function() {
                // Claude / DeepL are paid-plan engines; on a free plan point to the plans note.
                if (this.getAttribute('data-locked') === '1') {
                    const note = document.querySelector('.aitrfoel-engine-upgrade-note');
                    if (note) {
                        note.classList.remove('is-flash');
                        void note.offsetWidth;
                        note.classList.add('is-flash');
                    }
                    return;
                }
                const engine = this.getAttribute('data-engine');
                if (engine) {
                    engineHiddenInput.value = engine;
                    engineCards.forEach(c => c.classList.remove('active'));
                    this.classList.add('active');
                }
            });
        });
    }

    // -------------------------------------------------------------------------
    // Tab Navigation & Cross-Tab Anchor Jumping
    // -------------------------------------------------------------------------
    const tabButtons = document.querySelectorAll('.aitrfoel-settings-tab-btn');
    const tabPanes = document.querySelectorAll('.aitrfoel-tab-pane');

    function switchTab(targetTabId) {
        if (!targetTabId) return;
        
        // Remove active class from all buttons and panes
        tabButtons.forEach(btn => btn.classList.remove('active'));
        tabPanes.forEach(pane => pane.classList.remove('active'));

        // Find active button & pane
        const activeBtn = document.querySelector(`.aitrfoel-settings-tab-btn[data-tab="${targetTabId}"]`);
        const activePane = document.getElementById(targetTabId);

        if (activeBtn && activePane) {
            activeBtn.classList.add('active');
            activePane.classList.add('active');
            if (typeof activeBtn.scrollIntoView === 'function') {
                activeBtn.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
            }
            if (history.replaceState) {
                history.replaceState(null, null, '#' + targetTabId);
            }
            try {
                sessionStorage.setItem('aitrfoel_active_settings_tab', targetTabId);
            } catch (e) {}
        }
    }

    // Expose globally for inline scripts
    window.switchSettingsTab = switchTab;

    // Tab Carousel Arrow Controllers
    const tabsWrapper = document.getElementById('aitrfoel-settings-tabs-wrapper');
    const tabsPrevBtn = document.getElementById('aitrfoel-tabs-prev');
    const tabsNextBtn = document.getElementById('aitrfoel-tabs-next');

    function updateCarouselArrows() {
        if (!tabsWrapper || !tabsPrevBtn || !tabsNextBtn) return;
        const maxScroll = tabsWrapper.scrollWidth - tabsWrapper.clientWidth;
        if (maxScroll <= 2) {
            tabsPrevBtn.style.display = 'none';
            tabsNextBtn.style.display = 'none';
            return;
        }
        tabsPrevBtn.style.display = 'inline-flex';
        tabsNextBtn.style.display = 'inline-flex';
        tabsPrevBtn.disabled = (tabsWrapper.scrollLeft <= 4);
        tabsNextBtn.disabled = (tabsWrapper.scrollLeft >= maxScroll - 4);
    }

    if (tabsPrevBtn && tabsWrapper) {
        tabsPrevBtn.addEventListener('click', function(e) {
            e.preventDefault();
            tabsWrapper.scrollBy({ left: -220, behavior: 'smooth' });
        });
    }
    if (tabsNextBtn && tabsWrapper) {
        tabsNextBtn.addEventListener('click', function(e) {
            e.preventDefault();
            tabsWrapper.scrollBy({ left: 220, behavior: 'smooth' });
        });
    }
    if (tabsWrapper) {
        tabsWrapper.addEventListener('scroll', updateCarouselArrows, { passive: true });
        window.addEventListener('resize', updateCarouselArrows);
        setTimeout(updateCarouselArrows, 120);
    }

    window.jumpToSetting = function(elementId) {
        if (!elementId) return;
        const targetEl = document.getElementById(elementId);
        if (!targetEl) return;

        const parentPane = targetEl.closest('.aitrfoel-tab-pane');
        if (parentPane) {
            switchTab(parentPane.id);
        }

        setTimeout(() => {
            targetEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            targetEl.style.transition = 'outline 0.3s ease, box-shadow 0.3s ease';
            targetEl.style.outline = '3px solid #3b82f6';
            targetEl.style.boxShadow = '0 0 15px rgba(59, 130, 246, 0.35)';
            setTimeout(() => {
                targetEl.style.outline = '';
                targetEl.style.boxShadow = '';
            }, 2000);
        }, 120);
    };

    tabButtons.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const tabId = this.getAttribute('data-tab');
            switchTab(tabId);
        });
    });

    // Intercept all internal anchor clicks on the settings page
    document.addEventListener('click', function(e) {
        const anchor = e.target.closest('a[href*="#"]');
        if (!anchor) return;

        const href = anchor.getAttribute('href');
        if (!href || href === '#' || href.indexOf('action=') !== -1) return;

        const hashIndex = href.indexOf('#');
        if (hashIndex === -1) return;

        const hash = href.substring(hashIndex + 1);
        if (!hash) return;

        // If clicking a direct tab hash (e.g. #tab-tools)
        if (hash.startsWith('tab-')) {
            const pane = document.getElementById(hash);
            if (pane && pane.classList.contains('aitrfoel-tab-pane')) {
                e.preventDefault();
                switchTab(hash);
                return;
            }
        }

        // If clicking a section or tool ID inside any tab pane
        const targetEl = document.getElementById(hash);
        if (targetEl) {
            const parentPane = targetEl.closest('.aitrfoel-tab-pane');
            if (parentPane) {
                e.preventDefault();
                window.jumpToSetting(hash);
            }
        }
    });

    // Auto-select tab and scroll based on initial URL hash
    let initialHash = window.location.hash ? window.location.hash.substring(1) : '';
    if (initialHash) {
        if (initialHash.startsWith('tab-')) {
            switchTab(initialHash);
        } else {
            const targetEl = document.getElementById(initialHash);
            if (targetEl) {
                const parentPane = targetEl.closest('.aitrfoel-tab-pane');
                if (parentPane) {
                    switchTab(parentPane.id);
                    setTimeout(() => {
                        window.jumpToSetting(initialHash);
                    }, 150);
                }
            } else {
                switchTab('tab-general');
            }
        }
    } else {
        let savedTab = '';
        try {
            savedTab = sessionStorage.getItem('aitrfoel_active_settings_tab') || '';
        } catch (e) {}
        if (savedTab && document.getElementById(savedTab)) {
            switchTab(savedTab);
        } else {
            switchTab('tab-general');
        }
    }

    // Sticky Save Bar dirty-state indicator
    const stickySaveBar = document.getElementById('aitrfoel-sticky-save-bar');
    const settingsForm = stickySaveBar ? stickySaveBar.closest('form') : null;
    if (stickySaveBar && settingsForm) {
        const statusText = stickySaveBar.querySelector('.aitrfoel-sticky-save-text');
        let isSubmitting = false;
        // Compare against a snapshot of the form instead of flagging on any event,
        // so reverting a change (e.g. re-ticking a checkbox) clears the "unsaved" state.
        const serializeForm = function() {
            const parts = [];
            Array.prototype.forEach.call(settingsForm.elements, function(el) {
                if (!el.name || el.disabled || el.closest('#aitrfoel-glossary-modal')) return;
                const type = (el.type || '').toLowerCase();
                if (type === 'submit' || type === 'button' || type === 'file') return;
                if (type === 'checkbox' || type === 'radio') {
                    parts.push(el.name + '=' + (el.checked ? (el.value || 'on') : ''));
                } else if (el.multiple && el.options) {
                    parts.push(el.name + '=' + Array.prototype.filter.call(el.options, function(o) { return o.selected; }).map(function(o) { return o.value; }).join(','));
                } else {
                    parts.push(el.name + '=' + el.value);
                }
            });
            return parts.join('&');
        };
        let baseline = serializeForm();
        // Other scripts may populate fields after load; take the snapshot again once they have run.
        window.addEventListener('load', function() { baseline = serializeForm(); });
        const updateDirty = function(e) {
            if (e && e.target && e.target.closest && e.target.closest('#aitrfoel-glossary-modal')) return;
            const dirty = serializeForm() !== baseline;
            stickySaveBar.classList.toggle('is-dirty', dirty);
            if (statusText) {
                const text = dirty ? statusText.dataset.dirtyText : statusText.dataset.defaultText;
                if (text) {
                    statusText.textContent = text;
                }
            }
        };
        settingsForm.addEventListener('change', updateDirty);
        settingsForm.addEventListener('input', updateDirty);
        settingsForm.addEventListener('submit', function() {
            isSubmitting = true;
            stickySaveBar.classList.remove('is-dirty');
        });
        window.addEventListener('beforeunload', function(e) {
            if (!isSubmitting && stickySaveBar.classList.contains('is-dirty')) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    }

    // -------------------------------------------------------------------------
    // Brand Glossary Manager AJAX Handlers
    // -------------------------------------------------------------------------
    const openAddModalBtn = document.getElementById('aitrfoel-open-add-term-modal');
    const addModal = document.getElementById('aitrfoel-glossary-modal');
    const cancelModalBtn = document.getElementById('aitrfoel-modal-cancel-btn');
    const saveTermBtn = document.getElementById('aitrfoel-save-term-btn');
    const glossaryTbody = document.getElementById('aitrfoel-glossary-tbody');

    if (openAddModalBtn && addModal) {
        openAddModalBtn.addEventListener('click', function() {
            addModal.style.display = 'flex';
            document.getElementById('aitrfoel-term-source').focus();
        });

        if (cancelModalBtn) {
            cancelModalBtn.addEventListener('click', function() {
                addModal.style.display = 'none';
            });
        }

        // Toggle target translation field based on action
        const actionSelect = document.getElementById('aitrfoel-term-action');
        const targetGroup = document.getElementById('aitrfoel-term-target-group');
        if (actionSelect && targetGroup) {
            actionSelect.addEventListener('change', function() {
                if (this.value === 'replace') {
                    targetGroup.style.display = 'block';
                } else {
                    targetGroup.style.display = 'none';
                }
            });
        }

        // Save Term AJAX
        if (saveTermBtn) {
            saveTermBtn.addEventListener('click', function() {
                const source = document.getElementById('aitrfoel-term-source').value.trim();
                const target = document.getElementById('aitrfoel-term-target').value.trim();
                const lang = document.getElementById('aitrfoel-term-lang').value;
                const termAction = document.getElementById('aitrfoel-term-action').value;
                const statusMsg = document.getElementById('aitrfoel-modal-status');

                if (!source) {
                    alert('Please enter a term or phrase.');
                    return;
                }
                if (termAction === 'replace' && !target) {
                    alert('Please enter the target translation replacement.');
                    return;
                }

                saveTermBtn.disabled = true;
                saveTermBtn.textContent = 'Saving...';
                if (statusMsg) statusMsg.textContent = '';

                const formData = new FormData();
                formData.append('action', 'aitrfoel_add_glossary_term');
                formData.append('source_text', source);
                formData.append('target_text', target);
                formData.append('lang_code', lang);
                formData.append('term_action', termAction);
                formData.append('nonce', aitrfoel_settings_ajax.glossary_nonce);

                fetch(aitrfoel_settings_ajax.ajax_url, {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    saveTermBtn.disabled = false;
                    saveTermBtn.textContent = 'Save Term';

                    if (data.success) {
                        addModal.style.display = 'none';
                        // Reset inputs
                        document.getElementById('aitrfoel-term-source').value = '';
                        document.getElementById('aitrfoel-term-target').value = '';

                        // Remove empty placeholder row if present
                        const emptyRow = document.getElementById('aitrfoel-glossary-empty-row');
                        if (emptyRow) emptyRow.remove();

                        // Prepend new row
                        const langBadge = lang === 'all' || !lang 
                            ? '<span class="badge" style="background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">All Languages</span>' 
                            : '<span class="badge" style="background:#f1f5f9;color:#334155;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">' + lang.toUpperCase() + '</span>';

                        const ruleDisplay = termAction === 'do_not_translate'
                            ? '<span style="color:#b91c1c;font-weight:500;">[Do Not Translate] Keep exact source</span>'
                            : '<span style="color:#15803d;font-weight:500;">&rarr; ' + data.data.target_text + '</span>';

                        const newRow = document.createElement('tr');
                        newRow.setAttribute('data-term-id', data.data.id);
                        newRow.innerHTML = `
                            <td><strong>${data.data.source_text}</strong></td>
                            <td>${langBadge}</td>
                            <td>${ruleDisplay}</td>
                            <td style="text-align: right;">
                                <button type="button" class="button button-small button-link-delete aitrfoel-delete-glossary-term" data-id="${data.data.id}">
                                    Delete
                                </button>
                            </td>
                        `;
                        glossaryTbody.insertBefore(newRow, glossaryTbody.firstChild);
                    } else {
                        alert(data.data || 'Failed to add term');
                    }
                })
                .catch(err => {
                    saveTermBtn.disabled = false;
                    saveTermBtn.textContent = 'Save Term';
                    alert('Error saving term: ' + err.message);
                });
            });
        }
    }

    // Delete Term handler (delegated)
    document.addEventListener('click', function(e) {
        if (e.target && e.target.classList.contains('aitrfoel-delete-glossary-term')) {
            const btn = e.target;
            const termId = btn.getAttribute('data-id');
            if (!confirm('Are you sure you want to remove this glossary rule?')) return;

            btn.disabled = true;
            btn.textContent = '...';

            const formData = new FormData();
            formData.append('action', 'aitrfoel_delete_glossary_term');
            formData.append('id', termId);
            formData.append('nonce', aitrfoel_settings_ajax.glossary_nonce);

            fetch(aitrfoel_settings_ajax.ajax_url, {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const row = btn.closest('tr');
                    if (row) {
                        row.style.opacity = '0';
                        setTimeout(() => row.remove(), 200);
                    }
                } else {
                    btn.disabled = false;
                    btn.textContent = 'Delete';
                    alert(data.data || 'Failed to delete term');
                }
            })
            .catch(err => {
                btn.disabled = false;
                btn.textContent = 'Delete';
                alert('Error: ' + err.message);
            });
        }
    });

    // CSV Export
    const exportBtn = document.getElementById('aitrfoel-export-glossary-csv');
    if (exportBtn) {
        exportBtn.addEventListener('click', function() {
            window.location.href = aitrfoel_settings_ajax.ajax_url + '?action=aitrfoel_export_glossary_csv&nonce=' + aitrfoel_settings_ajax.glossary_nonce;
        });
    }

    // CSV Import
    const importFileInput = document.getElementById('aitrfoel-import-glossary-file');
    if (importFileInput) {
        importFileInput.addEventListener('change', function() {
            if (!this.files || !this.files[0]) return;
            const file = this.files[0];
            const formData = new FormData();
            formData.append('action', 'aitrfoel_import_glossary_csv');
            formData.append('file', file);
            formData.append('nonce', aitrfoel_settings_ajax.glossary_nonce);

            const importLabel = this.closest('label');
            const origText = importLabel.textContent;
            importLabel.textContent = '⏳ Importing...';

            fetch(aitrfoel_settings_ajax.ajax_url, {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                importLabel.textContent = origText;
                if (data.success) {
                    alert(data.data.message);
                    location.reload();
                } else {
                    alert(data.data || 'Failed to import CSV');
                }
            })
            .catch(err => {
                importLabel.textContent = origText;
                alert('Error importing CSV: ' + err.message);
            });
        });
    }

    // Inventory Recalculate Stats
    const refreshInvBtn = document.getElementById('aitrfoel-refresh-inventory-btn');
    if (refreshInvBtn) {
        refreshInvBtn.addEventListener('click', function() {
            const btn = this;
            const label = document.getElementById('aitrfoel-refresh-inv-label');
            const origText = label ? label.textContent : 'Recalculate Stats';
            const icon = btn.querySelector('.aitrfoel-refresh-svg') || btn.querySelector('.dashicons');
            
            btn.disabled = true;
            if (icon) icon.classList.add('spin');
            if (label) label.textContent = (aitrfoel_settings_ajax && aitrfoel_settings_ajax.recalculating_text) ? aitrfoel_settings_ajax.recalculating_text : 'Recalculating...';

            const formData = new FormData();
            formData.append('action', 'aitrfoel_refresh_inventory_stats');
            formData.append('nonce', aitrfoel_settings_ajax.inventory_nonce);

            fetch(aitrfoel_settings_ajax.ajax_url, {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(res => {
                btn.disabled = false;
                if (icon) icon.classList.remove('spin');
                if (label) label.textContent = origText;

                if (res.success && res.data) {
                    const d = res.data;
                    const elItems = document.getElementById('inv-stat-items');
                    const elWords = document.getElementById('inv-stat-words');
                    const elLangs = document.getElementById('inv-stat-langs');
                    const elDemand = document.getElementById('inv-stat-demand');

                    if (elItems) elItems.textContent = Number(d.total_items).toLocaleString();
                    if (elWords) elWords.textContent = '~' + Number(d.total_source_words).toLocaleString();
                    if (elLangs) elLangs.textContent = Number(d.target_lang_count).toLocaleString();
                    if (elDemand) elDemand.textContent = '~' + Number(d.estimated_total_demand).toLocaleString();

                    // Update plan recommendation button and hint
                    if (d.recommended_plan) {
                        const planBtn = document.getElementById('inv-recommended-plan-btn');
                        const planLabel = document.getElementById('inv-recommended-plan-label');
                        const planHint = document.getElementById('inv-plan-hint');

                        if (planBtn && d.recommended_plan.upgrade_url) {
                            planBtn.setAttribute('href', d.recommended_plan.upgrade_url);
                        }
                        if (planLabel && d.recommended_plan.name) {
                            planLabel.textContent = `Get ${d.recommended_plan.name} Plan (~${d.recommended_plan.limit_k}k words)`;
                        }
                        if (planHint && d.recommended_plan.name) {
                            planHint.textContent = `Recommended: ${d.recommended_plan.name} Plan`;
                        }
                    }

                    const breakdownList = document.getElementById('inv-breakdown-list');
                    if (breakdownList && d.breakdown) {
                        const fallbackIcons = {
                            pages: '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
                            posts: '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>',
                            templates: '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>',
                            products: '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>',
                        };
                        let html = '';
                        for (const k in d.breakdown) {
                            const g = d.breakdown[k];
                            const iconSvg = (g.icon && g.icon.indexOf('<svg') !== -1) ? g.icon : (fallbackIcons[k] || fallbackIcons.pages);
                            html += `<div class="aitrfoel-inv-breakdown-row" data-group="${k}">
                                <div class="aitrfoel-inv-type-name">
                                    <span class="aitrfoel-inv-icon">${iconSvg}</span>
                                    <strong>${g.label}</strong>
                                </div>
                                <div class="aitrfoel-inv-type-stats">
                                    <span class="aitrfoel-inv-count">${Number(g.count).toLocaleString()} items</span>
                                    <span class="aitrfoel-inv-words">~${Number(g.words).toLocaleString()} words</span>
                                </div>
                            </div>`;
                        }
                        breakdownList.innerHTML = html;
                    }
                }
            })
            .catch(err => {
                btn.disabled = false;
                if (icon) icon.classList.remove('spin');
                if (label) label.textContent = origText;
            });
        });
    }

    // --- Brand Voice & Localization Tone (PRO Language Enhancer) ---
    const toneCards = document.querySelectorAll('.aitrfoel-tone-card');
    const toneInput = document.getElementById('aitrfoel_brand_tone_preset_input');
    const customRulesArea = document.getElementById('aitrfoel_brand_tone_custom');

    if (toneCards.length > 0 && toneInput) {
        toneCards.forEach(card => {
            card.addEventListener('click', function () {
                const selectedTone = this.getAttribute('data-tone');
                toneInput.value = selectedTone;

                toneCards.forEach(c => c.classList.remove('selected'));
                this.classList.add('selected');

                if (selectedTone === 'custom' && customRulesArea) {
                    customRulesArea.focus();
                }
            });
        });
    }

    // 1-Click Detect Site Context & Suggest Tone
    const detectToneBtn = document.getElementById('aitrfoel-detect-site-tone-btn');
    const toneResultBox = document.getElementById('aitrfoel-tone-scan-result');
    const toneResultTitle = document.getElementById('aitrfoel-tone-scan-title');
    const toneResultDesc = document.getElementById('aitrfoel-tone-scan-desc');
    const applyToneBtn = document.getElementById('aitrfoel-apply-suggested-tone');
    let detectedSuggestedPreset = null;

    if (detectToneBtn) {
        detectToneBtn.addEventListener('click', function (e) {
            e.preventDefault();
            const btn = this;
            const origLabel = document.getElementById('aitrfoel-detect-tone-label');
            const origText = origLabel ? origLabel.textContent : 'Detect Site Context & Suggest Tone';

            btn.disabled = true;
            btn.classList.add('loading');
            if (origLabel) origLabel.textContent = 'Scanning Site Context...';

            const formData = new FormData();
            formData.append('action', 'aitrfoel_detect_site_tone');
            formData.append('nonce', typeof aitrfoelDashboard !== 'undefined' ? aitrfoelDashboard.nonce : (typeof aitrfoel_settings_ajax !== 'undefined' ? (aitrfoel_settings_ajax.inventory_nonce || aitrfoel_settings_ajax.test_nonce) : ''));

            fetch(aitrfoel_settings_ajax.ajax_url, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(res => {
                btn.disabled = false;
                btn.classList.remove('loading');
                if (origLabel) origLabel.textContent = origText;

                if (res.success && res.data) {
                    const data = res.data;
                    detectedSuggestedPreset = data.suggested_preset;

                    if (toneResultBox) {
                        toneResultBox.style.display = 'flex';
                        if (toneResultTitle) {
                            toneResultTitle.textContent = `Recommended: ${data.preset_label}`;
                        }
                        if (toneResultDesc) {
                            const kwText = data.matched_keywords && data.matched_keywords.length > 0
                                ? ` (Keywords: ${data.matched_keywords.join(', ')})`
                                : '';
                            toneResultDesc.textContent = data.explanation + kwText;
                        }
                    }
                }
            })
            .catch(err => {
                btn.disabled = false;
                btn.classList.remove('loading');
                if (origLabel) origLabel.textContent = origText;
            });
        });
    }

    if (applyToneBtn) {
        applyToneBtn.addEventListener('click', function (e) {
            e.preventDefault();
            if (!detectedSuggestedPreset) return;

            const targetCard = document.querySelector(`.aitrfoel-tone-card[data-tone="${detectedSuggestedPreset}"]`);
            if (targetCard) {
                targetCard.click();
            }

            if (toneResultBox) {
                toneResultBox.style.display = 'none';
            }
        });
    }
});

