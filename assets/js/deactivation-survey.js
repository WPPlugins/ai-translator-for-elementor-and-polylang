jQuery(document).ready(function($) {
    // Check if we are on the plugins page and have the localization data
    if (typeof aitrfoelDeactivation === 'undefined') {
        return;
    }

    var pluginSlug = 'ai-translator-for-elementor-and-polylang/ai-translator-for-elementor-and-polylang.php';
    var deactivateSelector = 'tr[data-plugin="' + pluginSlug + '"] .deactivate a';

    $(document).on('click', deactivateSelector, function(e) {
        e.preventDefault();
        var deactivateUrl = $(this).attr('href');
        openSurveyModal(deactivateUrl);
    });

    function openSurveyModal(deactivateUrl) {
        // Destroy existing modal just in case
        $('.aitrfoel-survey-modal-overlay').remove();

        var modalHtml = 
            '<div class="aitrfoel-survey-modal-overlay">' +
            '  <div class="aitrfoel-survey-modal">' +
            '    <div class="aitrfoel-survey-modal-header">' +
            '      <h3>Quick Feedback</h3>' +
            '      <button type="button" class="aitrfoel-survey-modal-close">&times;</button>' +
            '    </div>' +
            '    <div class="aitrfoel-survey-modal-body">' +
            '      <p>We are sorry to see you go! Could you please let us know why you are deactivating Meep AI Translator? Your feedback helps us improve the plugin.</p>' +
            '      <div class="aitrfoel-survey-reasons">' +
            '        <div class="aitrfoel-survey-reason-item">' +
            '          <input type="radio" id="reason_not_working" name="aitrfoel_reason" value="Could not get it to work">' +
            '          <label for="reason_not_working">I couldn\'t get the plugin to work / setup issues</label>' +
            '        </div>' +
            '        <div class="aitrfoel-survey-reason-item">' +
            '          <input type="radio" id="reason_alternative" name="aitrfoel_reason" value="Found better alternative">' +
            '          <label for="reason_alternative">I found a better alternative plugin</label>' +
            '        </div>' +
            '        <div class="aitrfoel-survey-reason-item">' +
            '          <input type="radio" id="reason_feature_missing" name="aitrfoel_reason" value="Missing feature">' +
            '          <label for="reason_feature_missing">It lacks a feature or language translation capabilities I need</label>' +
            '        </div>' +
            '        <div class="aitrfoel-survey-reason-item">' +
            '          <input type="radio" id="reason_temporary" name="aitrfoel_reason" value="Temporary deactivation">' +
            '          <label for="reason_temporary">This is temporary deactivation / debugging purposes</label>' +
            '        </div>' +
            '        <div class="aitrfoel-survey-reason-item">' +
            '          <input type="radio" id="reason_other" name="aitrfoel_reason" value="Other">' +
            '          <label for="reason_other">Other reason</label>' +
            '        </div>' +
            '      </div>' +
            '      <div class="aitrfoel-survey-details-wrap">' +
            '        <textarea placeholder="Care to share details or suggest improvements? (optional)"></textarea>' +
            '      </div>' +
            '      <div class="aitrfoel-survey-consent-notice">' +
            '        By submitting, you agree to share your deactivation reason, details, and site URL (' + aitrfoelDeactivation.siteUrl + ') with Meep Tech.' +
            '      </div>' +
            '    </div>' +
            '    <div class="aitrfoel-survey-modal-footer">' +
            '      <button type="button" class="aitrfoel-survey-btn aitrfoel-survey-btn-skip">Skip & Deactivate</button>' +
            '      <button type="button" class="aitrfoel-survey-btn aitrfoel-survey-btn-submit" disabled>Submit & Deactivate</button>' +
            '    </div>' +
            '  </div>' +
            '</div>';

        $('body').append(modalHtml);

        // Bind events
        var $overlay = $('.aitrfoel-survey-modal-overlay');
        var $submitBtn = $overlay.find('.aitrfoel-survey-btn-submit');
        var $detailsWrap = $overlay.find('.aitrfoel-survey-details-wrap');
        var $textarea = $overlay.find('textarea');

        // Close on X
        $overlay.on('click', '.aitrfoel-survey-modal-close', function() {
            $overlay.remove();
        });

        // Toggle submit button and text area visibility
        $overlay.on('change', 'input[name="aitrfoel_reason"]', function() {
            var selectedReason = $(this).val();
            $submitBtn.prop('disabled', false);
            
            // Show textarea for "Other" or "Missing feature" or "Could not get it to work"
            if (selectedReason === 'Other' || selectedReason === 'Missing feature' || selectedReason === 'Could not get it to work') {
                $detailsWrap.slideDown(200);
            } else {
                $detailsWrap.slideUp(200);
            }
        });

        // Skip & Deactivate
        $overlay.on('click', '.aitrfoel-survey-btn-skip', function() {
            $overlay.remove();
            window.location.href = deactivateUrl;
        });

        // Submit & Deactivate
        $overlay.on('click', '.aitrfoel-survey-btn-submit', function() {
            var reason = $overlay.find('input[name="aitrfoel_reason"]:checked').val();
            var details = $textarea.val() || '';
            
            // Add loading spinner and disable actions
            $submitBtn.html('<span class="aitrfoel-survey-spinner"></span>Submitting...').prop('disabled', true);
            $overlay.find('.aitrfoel-survey-btn-skip').prop('disabled', true);
            $overlay.find('.aitrfoel-survey-modal-close').hide();

            // Submit directly to meeptech.com API using fetch
            fetch('https://meeptech.com/api/eat/deactivation-feedback', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    site_url: aitrfoelDeactivation.siteUrl,
                    reason: reason,
                    details: details
                }),
                // Use a short timeout of 3.5 seconds so we never leave the user hanging
                signal: AbortSignal.timeout(3500)
            })
            .catch(function(err) {
                console.error('Failed to submit deactivation feedback:', err);
            })
            .then(function() {
                // Remove overlay and deactivate regardless of success/error
                $overlay.remove();
                window.location.href = deactivateUrl;
            });
        });
    }
});
