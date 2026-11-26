<?php
/**
 * Upgrade links for the FREE build.
 *
 * Free users can't buy inside WordPress (no Freemius SDK in the WordPress.org zip),
 * so every free upgrade button opens meeptech.com/upgrade. The page reads:
 *   utm_medium = where the click came from (limit-modal, admin-bar, ...)
 *   plan       = plan we suggest (optional)
 *   used/limit = this month's words, from the cached status only (no remote call)
 *   v          = plugin version
 * No site URL, e-mail or licence key is sent.
 *
 * PRO builds never call this: they keep using the Freemius in-plugin pricing page.
 *
 * @package AI_Translator_For_Elementor_And_Polylang
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('aitrfoel_free_upgrade_url')) {
    /**
     * @param string $from     Placement, e.g. 'limit-modal'.
     * @param string $campaign 'pro-upgrade' (default), 'extra-words', 'engine-upgrade'.
     * @param string $plan     Optional plan slug to pre-select (starter, agency, enterprise).
     * @return string
     */
    function aitrfoel_free_upgrade_url($from, $campaign = 'pro-upgrade', $plan = '') {
        $args = array(
            'utm_source'   => 'wp-plugin',
            'utm_medium'   => sanitize_key($from),
            'utm_campaign' => sanitize_key($campaign),
        );

        if ($plan !== '') {
            $args['plan'] = sanitize_key($plan);
        }

        $status = get_transient('aitrfoel_license_status_cache');
        if (is_array($status)) {
            if (isset($status['words_used']) && is_numeric($status['words_used'])) {
                $args['used'] = max(0, (int) $status['words_used']);
            }
            if (isset($status['word_limit']) && is_numeric($status['word_limit']) && (int) $status['word_limit'] > 0) {
                $args['limit'] = (int) $status['word_limit'];
            }
        }

        if (defined('AITRFOEL_VERSION')) {
            $args['v'] = AITRFOEL_VERSION;
        }

        return add_query_arg($args, 'https://meeptech.com/upgrade');
    }
}
