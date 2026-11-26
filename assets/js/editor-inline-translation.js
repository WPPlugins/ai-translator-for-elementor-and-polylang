/**
 * Meep AI Translator - Editor Inline Translation JS
 * Injects "Translate with Meep AI" triggers next to text/textarea controls in Elementor & Gutenberg.
 */
(function ($) {
    'use strict';

    console.log("🚀 Meep AI Translator: editor-inline-translation.js loaded.");

    if (typeof aitrfoelEditorInline === 'undefined') {
        console.error("❌ Meep AI Translator: aitrfoelEditorInline configuration not found.");
        return;
    }

    var cfg = aitrfoelEditorInline;

    $(document).ready(function () {
        console.log("🚀 Meep AI Translator: DOM ready, initializing observers.");
        
        // 1. Elementor Editor Visual Integration
        if (typeof window.elementor !== 'undefined' || $('#elementor-panel').length > 0) {
            console.log("🚀 Meep AI Translator: Elementor editor detected.");
            initElementorObserver();
        } else {
            console.log("ℹ️ Meep AI Translator: Elementor panel not detected yet.");
        }

        // 2. Gutenberg Block Editor Integration
        if (typeof window.wp !== 'undefined' && typeof window.wp.blocks !== 'undefined') {
            console.log("🚀 Meep AI Translator: Gutenberg editor detected.");
            initGutenbergObserver();
        }
    });

    /**
     * Scan and process existing Elementor controls
     */
    function scanElementorControls() {
        console.log("🚀 Meep AI Translator: Scanning existing Elementor controls...");
        $('.elementor-control').each(function () {
            var $control = $(this);
            processElementorControl($control);
        });
    }

    /**
     * Process a single Elementor control
     */
    function processElementorControl($control) {
        if ($control.find('.aitrfoel-inline-translate-btn').length > 0) {
            return;
        }

        var isTextControl = $control.hasClass('elementor-control-type-text') || 
                            $control.hasClass('elementor-control-type-textarea') || 
                            $control.hasClass('elementor-control-type-wysiwyg');
                            
        if (isTextControl) {
            injectElementorButton($control);
        }
    }

    /**
     * Elementor panel controls observer
     */
    function initElementorObserver() {
        // Run initial scan on start
        scanElementorControls();

        // Run scans periodically in case panel renders slowly
        var scansCount = 0;
        var interval = setInterval(function () {
            scanElementorControls();
            scansCount++;
            if (scansCount >= 10) {
                clearInterval(interval);
            }
        }, 1000);

        var observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                if (mutation.addedNodes.length === 0) return;
                
                // Scan added nodes for controls
                $(mutation.addedNodes).find('.elementor-control').each(function () {
                    var $control = $(this);
                    processElementorControl($control);
                });
                
                // Also handle cases where the added node itself is a control
                $(mutation.addedNodes).each(function () {
                    if ($(this).hasClass('elementor-control')) {
                        processElementorControl($(this));
                    }
                });
            });
        });

        // Watch Elementor panel (or body fallback)
        var panelEl = document.getElementById('elementor-panel') || document.body;
        console.log("🚀 Meep AI Translator: Starting Elementor panel MutationObserver on:", panelEl);
        observer.observe(panelEl, { childList: true, subtree: true });
    }

    /**
     * Inject button into Elementor control
     */
    function injectElementorButton($control) {
        var $input = $control.find('input[type="text"], textarea, .elementor-editor-element-settings');
        if ($input.length === 0) return;

        console.log("🚀 Meep AI Translator: Injecting button into Elementor control:", $control.attr('class'));

        // Create button
        var $btn = $('<button type="button" class="aitrfoel-inline-translate-btn" style="background: #1447e6; color: #fff; border: none; border-radius: 4px; padding: 4px 8px; font-size: 11px; cursor: pointer; margin-top: 4px; font-weight: 500; display: inline-flex; align-items: center; gap: 4px; transition: background 0.2s ease;"><span class="aitrfoel-icon">🚀</span> ' + cfg.strings.translate + '</button>');

        $btn.on('click', function (e) {
            e.preventDefault();
            
            var $targetInput = $control.find('input[type="text"], textarea').first();
            if ($targetInput.length === 0) return;

            var val = $targetInput.val();
            if (!val || val.trim() === '') {
                alert('Please enter some text to translate first.');
                return;
            }

            performInlineTranslation(val, $btn, function (translated) {
                // Update input value
                $targetInput.val(translated);
                // Trigger events to update Elementor visual canvas
                $targetInput.trigger('input').trigger('change');
            });
        });

        // Append below input/textarea
        $control.append($btn);
    }

    /**
     * Gutenberg block editor sidebar controls observer
     */
    function initGutenbergObserver() {
        // Run initial scan
        scanGutenbergControls();

        var scansCount = 0;
        var interval = setInterval(function () {
            scanGutenbergControls();
            scansCount++;
            if (scansCount >= 10) {
                clearInterval(interval);
            }
        }, 1000);

        var observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                if (mutation.addedNodes.length === 0) return;
                
                // Scan inspector controls
                $(mutation.addedNodes).find('.components-text-control, .components-textarea-control').each(function () {
                    var $control = $(this);
                    injectGutenbergButton($control);
                });
            });
        });

        // Watch editor sidebar inspector if it exists, otherwise watch body
        var watchEl = document.querySelector('.block-editor-sidebar') || document.body;
        console.log("🚀 Meep AI Translator: Starting Gutenberg MutationObserver on:", watchEl);
        observer.observe(watchEl, { childList: true, subtree: true });
    }

    /**
     * Scan and process existing Gutenberg controls
     */
    function scanGutenbergControls() {
        $('.components-text-control, .components-textarea-control').each(function () {
            var $control = $(this);
            injectGutenbergButton($control);
        });
    }

    /**
     * Inject button into Gutenberg control wrapper
     */
    function injectGutenbergButton($control) {
        if ($control.find('.aitrfoel-inline-translate-btn').length > 0) {
            return;
        }

        var $input = $control.find('input[type="text"], textarea');
        if ($input.length === 0) return;

        console.log("🚀 Meep AI Translator: Injecting button into Gutenberg control");

        var $btn = $('<button type="button" class="aitrfoel-inline-translate-btn" style="background: #1447e6; color: #fff; border: none; border-radius: 4px; padding: 4px 8px; font-size: 11px; cursor: pointer; margin-top: 4px; font-weight: 500; display: inline-flex; align-items: center; gap: 4px; transition: background 0.2s ease;"><span class="aitrfoel-icon">🚀</span> ' + cfg.strings.translate + '</button>');

        $btn.on('click', function (e) {
            e.preventDefault();
            
            var $targetInput = $control.find('input[type="text"], textarea').first();
            if ($targetInput.length === 0) return;

            var val = $targetInput.val();
            if (!val || val.trim() === '') {
                alert('Please enter some text to translate first.');
                return;
            }

            performInlineTranslation(val, $btn, function (translated) {
                $targetInput.val(translated);
                
                // React/Gutenberg event dispatch helper to update block attributes
                var nativeInputValueSetter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, "value").set;
                var nativeTextareaValueSetter = Object.getOwnPropertyDescriptor(window.HTMLTextAreaElement.prototype, "value").set;
                
                if ($targetInput.is('textarea')) {
                    nativeTextareaValueSetter.call($targetInput[0], translated);
                } else {
                    nativeInputValueSetter.call($targetInput[0], translated);
                }
                
                var ev = new Event('input', { bubbles: true });
                $targetInput[0].dispatchEvent(ev);
                
                var changeEv = new Event('change', { bubbles: true });
                $targetInput[0].dispatchEvent(changeEv);
            });
        });

        $control.append($btn);
    }

    /**
     * Perform REST API inline translation call
     */
    function performInlineTranslation(text, $btn, successCallback) {
        var originalHtml = $btn.html();
        
        $btn.prop('disabled', true)
            .css('background', '#a5b4fc')
            .html('<span class="aitrfoel-icon">🔄</span> ' + cfg.strings.translating);

        $.ajax({
            url: cfg.rest_url,
            type: 'POST',
            contentType: 'application/json',
            headers: {
                'X-WP-Nonce': cfg.nonce
            },
            data: JSON.stringify({
                text: text,
                target_lang: cfg.current_lang
            }),
            success: function (response) {
                $btn.prop('disabled', false)
                    .css('background', '#1447e6')
                    .html(originalHtml);

                if (response.success && response.translated_text) {
                    successCallback(response.translated_text);
                } else {
                    alert(cfg.strings.failed);
                }
            },
            error: function (xhr) {
                $btn.prop('disabled', false)
                    .css('background', '#1447e6')
                    .html(originalHtml);

                var msg = cfg.strings.failed;
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    msg += ': ' + xhr.responseJSON.message;
                }
                alert(msg);
            }
        });
    }

})(jQuery);
