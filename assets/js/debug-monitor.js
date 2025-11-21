/**
 * Visual Debug Monitor for AI Translator
 * Real-time step-by-step translation debugging
 */

(function($) {
    'use strict';

    window.EAT_DebugMonitor = {
        isOpen: false,
        steps: [],
        
        /**
         * Initialize debug monitor
         */
        init: function() {
            this.createModal();
            this.attachStyles();
        },
        
        /**
         * Create debug modal HTML
         */
        createModal: function() {
            if ($('#aitrfoel-debug-modal').length) return;
            
            const modalHTML = `
                <div id="aitrfoel-debug-modal" class="aitrfoel-debug-modal" style="display: none;">
                    <div class="aitrfoel-debug-overlay"></div>
                    <div class="aitrfoel-debug-container">
                        <div class="aitrfoel-debug-header">
                            <h2>🔍 Translation Debug Monitor</h2>
                            <button class="aitrfoel-debug-close" title="Close">&times;</button>
                        </div>
                        <div class="aitrfoel-debug-body">
                            <div id="aitrfoel-debug-steps" class="aitrfoel-debug-steps"></div>
                        </div>
                        <div class="aitrfoel-debug-footer">
                            <button class="aitrfoel-debug-clear">Clear Log</button>
                            <button class="aitrfoel-debug-export">Export Log</button>
                            <span class="aitrfoel-debug-timestamp"></span>
                        </div>
                    </div>
                </div>
            `;
            
            $('body').append(modalHTML);
            
            // Attach event handlers
            $('#aitrfoel-debug-modal .aitrfoel-debug-close').on('click', () => this.close());
            $('#aitrfoel-debug-modal .aitrfoel-debug-overlay').on('click', () => this.close());
            $('#aitrfoel-debug-modal .aitrfoel-debug-clear').on('click', () => this.clear());
            $('#aitrfoel-debug-modal .aitrfoel-debug-export').on('click', () => this.exportLog());
        },
        
        /**
         * Attach CSS styles
         */
        attachStyles: function() {
            if ($('#aitrfoel-debug-styles').length) return;
            
            const styles = `
                <style id="aitrfoel-debug-styles">
                    .aitrfoel-debug-modal {
                        position: fixed;
                        top: 0;
                        left: 0;
                        right: 0;
                        bottom: 0;
                        z-index: 999999;
                    }
                    
                    .aitrfoel-debug-overlay {
                        position: absolute;
                        top: 0;
                        left: 0;
                        right: 0;
                        bottom: 0;
                        background: rgba(0, 0, 0, 0.7);
                    }
                    
                    .aitrfoel-debug-container {
                        position: relative;
                        width: 90%;
                        max-width: 900px;
                        height: 80vh;
                        margin: 5vh auto;
                        background: #fff;
                        border-radius: 8px;
                        display: flex;
                        flex-direction: column;
                        box-shadow: 0 10px 40px rgba(0,0,0,0.3);
                    }
                    
                    .aitrfoel-debug-header {
                        padding: 20px;
                        border-bottom: 2px solid #e0e0e0;
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                        color: white;
                        border-radius: 8px 8px 0 0;
                    }
                    
                    .aitrfoel-debug-header h2 {
                        margin: 0;
                        font-size: 20px;
                        font-weight: 600;
                    }
                    
                    .aitrfoel-debug-close {
                        background: rgba(255,255,255,0.2);
                        border: none;
                        color: white;
                        font-size: 28px;
                        cursor: pointer;
                        width: 35px;
                        height: 35px;
                        border-radius: 50%;
                        line-height: 1;
                        transition: all 0.2s;
                    }
                    
                    .aitrfoel-debug-close:hover {
                        background: rgba(255,255,255,0.3);
                        transform: rotate(90deg);
                    }
                    
                    .aitrfoel-debug-body {
                        flex: 1;
                        overflow-y: auto;
                        padding: 20px;
                        background: #f8f9fa;
                    }
                    
                    .aitrfoel-debug-steps {
                        font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
                        font-size: 13px;
                    }
                    
                    .aitrfoel-debug-step {
                        margin-bottom: 12px;
                        padding: 12px;
                        border-left: 4px solid #ccc;
                        background: white;
                        border-radius: 4px;
                        animation: slideIn 0.3s ease;
                    }
                    
                    @keyframes slideIn {
                        from {
                            opacity: 0;
                            transform: translateX(-20px);
                        }
                        to {
                            opacity: 1;
                            transform: translateX(0);
                        }
                    }
                    
                    .aitrfoel-debug-step.info { border-left-color: #2196F3; }
                    .aitrfoel-debug-step.success { border-left-color: #4CAF50; }
                    .aitrfoel-debug-step.warning { border-left-color: #FF9800; }
                    .aitrfoel-debug-step.error { border-left-color: #F44336; }
                    .aitrfoel-debug-step.loading { 
                        border-left-color: #9C27B0;
                        background: linear-gradient(90deg, #fff 0%, #f3e5f5 100%);
                    }
                    
                    .aitrfoel-debug-step-header {
                        display: flex;
                        align-items: center;
                        margin-bottom: 6px;
                    }
                    
                    .aitrfoel-debug-step-icon {
                        font-size: 18px;
                        margin-right: 8px;
                    }
                    
                    .aitrfoel-debug-step-time {
                        color: #666;
                        font-size: 11px;
                        margin-left: auto;
                    }
                    
                    .aitrfoel-debug-step-title {
                        font-weight: 600;
                        color: #333;
                    }
                    
                    .aitrfoel-debug-step-message {
                        color: #555;
                        margin-top: 6px;
                        line-height: 1.5;
                    }
                    
                    .aitrfoel-debug-step-data {
                        margin-top: 8px;
                        padding: 8px;
                        background: #f5f5f5;
                        border-radius: 3px;
                        font-size: 12px;
                        color: #666;
                        max-height: 200px;
                        overflow-y: auto;
                    }
                    
                    .aitrfoel-debug-footer {
                        padding: 15px 20px;
                        border-top: 1px solid #e0e0e0;
                        display: flex;
                        gap: 10px;
                        align-items: center;
                        background: white;
                        border-radius: 0 0 8px 8px;
                    }
                    
                    .aitrfoel-debug-footer button {
                        padding: 8px 16px;
                        border: none;
                        border-radius: 4px;
                        cursor: pointer;
                        font-size: 13px;
                        font-weight: 500;
                        transition: all 0.2s;
                    }
                    
                    .aitrfoel-debug-clear {
                        background: #f44336;
                        color: white;
                    }
                    
                    .aitrfoel-debug-clear:hover {
                        background: #d32f2f;
                    }
                    
                    .aitrfoel-debug-export {
                        background: #2196F3;
                        color: white;
                    }
                    
                    .aitrfoel-debug-export:hover {
                        background: #1976D2;
                    }
                    
                    .aitrfoel-debug-timestamp {
                        margin-left: auto;
                        color: #666;
                        font-size: 12px;
                    }
                    
                    .aitrfoel-debug-spinner {
                        display: inline-block;
                        width: 14px;
                        height: 14px;
                        border: 2px solid #9C27B0;
                        border-top-color: transparent;
                        border-radius: 50%;
                        animation: spin 0.8s linear infinite;
                    }
                    
                    @keyframes spin {
                        to { transform: rotate(360deg); }
                    }
                </style>
            `;
            
            $('head').append(styles);
        },
        
        /**
         * Open debug monitor
         */
        open: function() {
            this.isOpen = true;
            $('#aitrfoel-debug-modal').fadeIn(200);
            this.updateTimestamp();
        },
        
        /**
         * Close debug monitor
         */
        close: function() {
            this.isOpen = false;
            $('#aitrfoel-debug-modal').fadeOut(200);
        },
        
        /**
         * Clear all steps
         */
        clear: function() {
            this.steps = [];
            $('#aitrfoel-debug-steps').empty();
        },
        
        /**
         * Add step to debug log
         */
        addStep: function(title, message, type = 'info', data = null) {
            const timestamp = new Date().toLocaleTimeString();
            const icons = {
                info: 'ℹ️',
                success: '✅',
                warning: '⚠️',
                error: '❌',
                loading: '<span class="aitrfoel-debug-spinner"></span>'
            };
            
            const step = {
                title,
                message,
                type,
                data,
                timestamp,
                icon: icons[type] || 'ℹ️'
            };
            
            this.steps.push(step);
            this.renderStep(step);
            this.scrollToBottom();
            this.updateTimestamp();
        },
        
        /**
         * Render a single step
         */
        renderStep: function(step) {
            const dataHtml = step.data ? 
                `<div class="aitrfoel-debug-step-data"><pre>${JSON.stringify(step.data, null, 2)}</pre></div>` : '';
            
            const stepHtml = `
                <div class="aitrfoel-debug-step ${step.type}">
                    <div class="aitrfoel-debug-step-header">
                        <span class="aitrfoel-debug-step-icon">${step.icon}</span>
                        <span class="aitrfoel-debug-step-title">${step.title}</span>
                        <span class="aitrfoel-debug-step-time">${step.timestamp}</span>
                    </div>
                    <div class="aitrfoel-debug-step-message">${step.message}</div>
                    ${dataHtml}
                </div>
            `;
            
            $('#aitrfoel-debug-steps').append(stepHtml);
        },
        
        /**
         * Update last step (for loading states)
         */
        updateLastStep: function(title, message, type = 'success', data = null) {
            if (this.steps.length === 0) return;
            
            const lastStep = this.steps[this.steps.length - 1];
            lastStep.title = title;
            lastStep.message = message;
            lastStep.type = type;
            lastStep.data = data;
            lastStep.icon = type === 'success' ? '✅' : 
                           type === 'error' ? '❌' : 
                           type === 'warning' ? '⚠️' : 'ℹ️';
            
            // Re-render last step
            $('#aitrfoel-debug-steps .aitrfoel-debug-step:last').remove();
            this.renderStep(lastStep);
            this.scrollToBottom();
        },
        
        /**
         * Scroll to bottom of steps
         */
        scrollToBottom: function() {
            const $body = $('.aitrfoel-debug-body');
            $body.scrollTop($body[0].scrollHeight);
        },
        
        /**
         * Update timestamp
         */
        updateTimestamp: function() {
            const now = new Date().toLocaleString();
            $('.aitrfoel-debug-timestamp').text(`Last update: ${now}`);
        },
        
        /**
         * Export log as text file
         */
        exportLog: function() {
            const logText = this.steps.map(step => {
                let text = `[${step.timestamp}] ${step.type.toUpperCase()}: ${step.title}\n${step.message}`;
                if (step.data) {
                    text += `\nData: ${JSON.stringify(step.data, null, 2)}`;
                }
                return text;
            }).join('\n\n---\n\n');
            
            const blob = new Blob([logText], { type: 'text/plain' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `translation-debug-${Date.now()}.txt`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }
    };
    
    // Auto-initialize when document is ready
    $(document).ready(function() {
        EAT_DebugMonitor.init();
    });
    
})(jQuery);
