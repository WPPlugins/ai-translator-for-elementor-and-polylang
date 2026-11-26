/**
 * Review Editor — inline segment editing
 * assets/js/review-editor.js
 */
(function ($) {
    'use strict';

    var cfg = window.aitrfoelReview || {};

    function showNotice(msg, type) {
        var $n = $('#aitrfoel-review-notice');
        $n.removeClass('is-success is-error')
            .addClass(type === 'error' ? 'is-error' : 'is-success')
            .text(msg)
            .slideDown(180);

        clearTimeout($n.data('timer'));
        $n.data('timer', setTimeout(function () {
            $n.slideUp(200);
        }, 4000));
    }

    function enterEditMode($row) {
        if ($row.hasClass('is-editing')) return;

        $row.addClass('is-editing');
        $row.find('.aitrfoel-review-display').hide();
        $row.find('.aitrfoel-review-ta').show().focus();
        $row.find('.aitrfoel-review-edit-actions').show();
    }

    function exitEditMode($row) {
        $row.removeClass('is-editing');
        $row.find('.aitrfoel-review-ta').hide();
        $row.find('.aitrfoel-review-edit-actions').hide();
        $row.find('.aitrfoel-review-display').show();
    }

    function saveSegment($row) {
        var $ta       = $row.find('.aitrfoel-review-ta');
        var $saveBtn  = $row.find('.aitrfoel-save-btn');
        var newText   = $ta.val();
        var path      = $row.data('path');

        $saveBtn.prop('disabled', true).text(cfg.strings.saving || 'Saving…');

        $.post(cfg.ajax_url, {
            action:       'aitrfoel_save_segment_edit',
            nonce:        cfg.nonce,
            post_id:      cfg.post_id,
            path:         path,
            new_text:     newText,
            content_type: cfg.content_type
        })
        .done(function (res) {
            if (res.success) {
                // Update the display text
                var displayText = $('<div>').text(newText.replace(/\n/g, '<br>')).html();
                $row.find('.aitrfoel-review-display').html(displayText.replace(/\n/g, '<br>'));

                // Update data-original so cancel works correctly from now on
                $ta.data('original', newText);

                exitEditMode($row);

                // Flash the row green
                $row.addClass('just-saved');
                setTimeout(function () { $row.removeClass('just-saved'); }, 1400);

                showNotice(res.data && res.data.message ? res.data.message : (cfg.strings.saved || 'Saved'), 'success');
            } else {
                var errMsg = res.data && res.data.message ? res.data.message : (cfg.strings.save_failed || 'Save failed.');
                showNotice(errMsg, 'error');
                $saveBtn.prop('disabled', false).text(cfg.strings.save || 'Save');
            }
        })
        .fail(function () {
            showNotice(cfg.strings.save_failed || 'Save failed. Please try again.', 'error');
            $saveBtn.prop('disabled', false).text(cfg.strings.save || 'Save');
        });
    }

    $(function () {
        var $table = $('.aitrfoel-review-table');

        // Click display → enter edit mode
        $table.on('click', '.aitrfoel-review-display', function () {
            var $row = $(this).closest('.aitrfoel-review-row');
            enterEditMode($row);
        });

        // Save button
        $table.on('click', '.aitrfoel-save-btn', function () {
            saveSegment($(this).closest('.aitrfoel-review-row'));
        });

        // Cancel button
        $table.on('click', '.aitrfoel-cancel-btn', function () {
            var $row = $(this).closest('.aitrfoel-review-row');
            var $ta  = $row.find('.aitrfoel-review-ta');
            // Restore original value
            $ta.val($ta.data('original'));
            exitEditMode($row);
        });

        // Keyboard shortcuts inside textarea
        $table.on('keydown', '.aitrfoel-review-ta', function (e) {
            var $row = $(this).closest('.aitrfoel-review-row');
            // Ctrl/Cmd+Enter = save
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                saveSegment($row);
            }
            // Escape = cancel
            if (e.key === 'Escape') {
                e.preventDefault();
                var $ta = $row.find('.aitrfoel-review-ta');
                $ta.val($ta.data('original'));
                exitEditMode($row);
            }
        });
    });

}(jQuery));
