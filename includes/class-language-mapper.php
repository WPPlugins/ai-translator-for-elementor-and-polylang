<?php
/**
 * Language Code Mapper for OpenAI Translation Stability
 * 
 * Maps Polylang language codes to explicit language names that OpenAI 
 * models can reliably understand and translate to/from.
 * 
 * Addresses the issue where ambiguous or rare ISO codes (like 'be', 'bs', 'gd') 
 * cause partial or failed translations.
 */

if (!defined('ABSPATH')) exit;

class AITRFOEL_Language_Mapper {
    
    /**
     * Comprehensive mapping of Polylang language codes to explicit language names
     * organized by frequency and OpenAI model familiarity
     */
    private static $language_map = [
        // High-frequency, well-supported languages (keep these for performance)
        'en' => 'English',
        'fr' => 'French', 
        'de' => 'German',
        'es' => 'Spanish',
        'it' => 'Italian',
        'pt' => 'Portuguese',
        'ru' => 'Russian',
        'zh' => 'Chinese',
        'ja' => 'Japanese',
        'ko' => 'Korean',
        'ar' => 'Arabic',
        'hi' => 'Hindi',
        'tr' => 'Turkish',
        'nl' => 'Dutch',
        'sv' => 'Swedish',
        'no' => 'Norwegian',
        'da' => 'Danish',
        'fi' => 'Finnish',
        'pl' => 'Polish',
        'cs' => 'Czech',
        'sk' => 'Slovak',
        'hu' => 'Hungarian',
        'ro' => 'Romanian',
        'bg' => 'Bulgarian',
        'hr' => 'Croatian',
        'sr' => 'Serbian',
        'uk' => 'Ukrainian',
        'el' => 'Greek',
        'he' => 'Hebrew',
        'th' => 'Thai',
        'vi' => 'Vietnamese',
        'id' => 'Indonesian',
        'ms' => 'Malay',
        'tl' => 'Filipino',
        
        // Problematic/ambiguous codes that need explicit names
        'be' => 'Belarusian',           // Often confused with Belgian Dutch
        'bs' => 'Bosnian',             // Could be confused with other codes
        'gd' => 'Scottish Gaelic',     // Very rare code
        'ga' => 'Irish',               // Could be confused with Galician
        'gl' => 'Galician',            // Similar to Irish (ga)
        'cy' => 'Welsh',               // Could be confused with Cyprus
        'mt' => 'Maltese',             // Could be confused with empty/null
        'is' => 'Icelandic',           // Could be confused with "is" verb
        'fo' => 'Faroese',             // Very rare
        'lv' => 'Latvian',             // Less common Baltic language
        'lt' => 'Lithuanian',          // Less common Baltic language
        'et' => 'Estonian',            // Less common Baltic language
        'sl' => 'Slovenian',           // Could be confused with Slovak
        'mk' => 'Macedonian',          // Less common Slavic language
        'sq' => 'Albanian',            // Less common code
        'eu' => 'Basque',              // Unique isolate language
        'ca' => 'Catalan',             // Could be confused with Canada
        'rm' => 'Romansh',             // Very rare Swiss language
        'hy' => 'Armenian',            // Less common script
        'ka' => 'Georgian',            // Unique script
        'az' => 'Azerbaijani',         // Less common Turkic language
        'kk' => 'Kazakh',              // Less common Turkic language
        'ky' => 'Kyrgyz',              // Less common Turkic language
        'uz' => 'Uzbek',               // Less common Turkic language
        'mn' => 'Mongolian',           // Less common language
        'my' => 'Burmese',             // Could be confused with Myanmar
        'km' => 'Khmer',               // Cambodian language
        'lo' => 'Lao',                 // Laotian language
        'si' => 'Sinhala',             // Sri Lankan language
        'ta' => 'Tamil',               // Indian language
        'te' => 'Telugu',              // Indian language
        'ml' => 'Malayalam',           // Indian language
        'kn' => 'Kannada',             // Indian language
        'gu' => 'Gujarati',            // Indian language
        'bn' => 'Bengali',             // Bangladesh/India
        'pa' => 'Punjabi',             // Indian/Pakistani language
        'ur' => 'Urdu',                // Pakistani language
        'fa' => 'Persian',             // Iranian language, also Farsi
        'ps' => 'Pashto',              // Afghan language
        'sd' => 'Sindhi',              // Pakistani language
        'ne' => 'Nepali',              // Nepalese language
        'dz' => 'Dzongkha',            // Bhutanese language
        'bo' => 'Tibetan',             // Tibetan language
        'zh-cn' => 'Chinese Simplified',
        'zh-tw' => 'Chinese Traditional',
        'zh-hk' => 'Chinese Traditional (Hong Kong)',
        'pt-br' => 'Portuguese (Brazil)',
        'pt-pt' => 'Portuguese (Portugal)',
        'en-us' => 'English (US)',
        'en-gb' => 'English (UK)',
        'en-au' => 'English (Australia)',
        'en-ca' => 'English (Canada)',
        'fr-fr' => 'French (France)',
        'fr-ca' => 'French (Canada)',
        'de-de' => 'German (Germany)',
        'de-at' => 'German (Austria)',
        'de-ch' => 'German (Switzerland)',
        'es-es' => 'Spanish (Spain)',
        'es-mx' => 'Spanish (Mexico)',
        'es-ar' => 'Spanish (Argentina)',
        'it-it' => 'Italian (Italy)',
        'it-ch' => 'Italian (Switzerland)',
        
        // African languages
        'af' => 'Afrikaans',           // South African
        'sw' => 'Swahili',             // East African
        'zu' => 'Zulu',                // South African
        'xh' => 'Xhosa',               // South African
        'ha' => 'Hausa',               // West African
        'yo' => 'Yoruba',              // Nigerian language
        'ig' => 'Igbo',                // Nigerian language
        'am' => 'Amharic',             // Ethiopian language
        'ti' => 'Tigrinya',            // Ethiopian/Eritrean
        'om' => 'Oromo',               // Ethiopian language
        'so' => 'Somali',              // Somali language
        'mg' => 'Malagasy',            // Madagascar language
        
        // Additional European languages  
        'lb' => 'Luxembourgish',       // Luxembourg language
        'lb-lu' => 'Luxembourgish',    // Luxembourg language (regional variant)
        'lb_lu' => 'Luxembourgish',    // Luxembourg language (locale format)
        'li' => 'Limburgish',          // Netherlands/Belgium
        'fy' => 'Frisian',             // Netherlands/Germany
        'nn' => 'Norwegian Nynorsk',   // Norway variant
        'nb' => 'Norwegian Bokmål',    // Norway variant
        'se' => 'Northern Sami',       // Sami language
        'kw' => 'Cornish',             // Cornwall language
        'br' => 'Breton',              // Brittany language
        'oc' => 'Occitan',             // Southern France
        'co' => 'Corsican',            // Corsica language
        'sc' => 'Sardinian',           // Sardinia language
        'vec' => 'Venetian',           // Venice region
        'nap' => 'Neapolitan',         // Naples region
        'eml' => 'Emilian-Romagnol',   // Northern Italy
        'lij' => 'Ligurian',           // Liguria region
        'pms' => 'Piedmontese',        // Piedmont region
    ];
    
    /**
     * Get explicit language name for a Polylang language code
     * 
     * @param string $lang_code The Polylang language code (e.g., 'be', 'bs', 'gd')
     * @param bool $log_missing Whether to log missing mappings for debugging
     * @return string Explicit language name (e.g., 'Belarusian', 'Bosnian', 'Scottish Gaelic')
     */
    public static function get_language_name($lang_code, $log_missing = true) {
        if (empty($lang_code)) {
            return 'English'; // Safe fallback
        }
        
        // Normalize code to lowercase
        $lang_code = strtolower(trim($lang_code));
        
        // Check our comprehensive mapping first
        if (isset(self::$language_map[$lang_code])) {
            return self::$language_map[$lang_code];
        }
        
        // Fallback: Try to make a reasonable guess
        $fallback_name = self::generate_fallback_name($lang_code);
        
        // Log missing mapping for future improvement
        if ($log_missing) {
            AITRFOEL_Logger::log(
                sprintf(
                    '[EAT Language Mapper] Missing mapping for language code "%s", using fallback "%s". Consider adding to language_map.',
                    $lang_code,
                    $fallback_name
                ),
                'warning'
            );
        }
        
        return $fallback_name;
    }
    
    /**
     * Reverse lookup: get language code from a full language name.
     *
     * @param string $name Full language name (e.g. 'Luxembourgish')
     * @return string|null ISO language code or null
     */
    public static function get_code_from_language_name($name) {
        $name_lower = strtolower(trim($name));
        foreach (self::$language_map as $code => $mapped_name) {
            if (strtolower($mapped_name) === $name_lower) {
                // Prefer shortest code (e.g. 'lb' over 'lb-lu')
                if (strlen($code) <= 3 || !strpos($code, '-') && !strpos($code, '_')) {
                    return $code;
                }
            }
        }
        // Second pass: accept regional variants if no short code found
        foreach (self::$language_map as $code => $mapped_name) {
            if (strtolower($mapped_name) === $name_lower) {
                return $code;
            }
        }
        // Third pass: normalize parentheses and punctuation (e.g. 'Chinese (Simplified)' vs 'Chinese Simplified')
        $norm_input = preg_replace('/\s+/', ' ', strtolower(trim(str_replace(['(', ')', '-', '_'], ' ', $name))));
        foreach (self::$language_map as $code => $mapped_name) {
            $norm_mapped = preg_replace('/\s+/', ' ', strtolower(trim(str_replace(['(', ')', '-', '_'], ' ', $mapped_name))));
            if ($norm_mapped === $norm_input) {
                return $code;
            }
        }
        return null;
    }

    /**
     * Generate a reasonable fallback name for unmapped language codes
     * 
     * @param string $lang_code The language code
     * @return string Generated language name
     */
    private static function generate_fallback_name($lang_code) {
        // Handle common patterns
        if (strpos($lang_code, '-') !== false) {
            // Handle regional variants like 'en-us', 'fr-ca'
            $parts = explode('-', $lang_code);
            $base = $parts[0];
            $region = strtoupper($parts[1]);
            
            $base_name = self::$language_map[$base] ?? ucfirst($base);
            return sprintf('%s (%s)', $base_name, $region);
        }
        
        // Simple capitalization for unknown codes
        return ucfirst($lang_code);
    }
    
    /**
     * Check if a language code is in our mapping
     * 
     * @param string $lang_code The language code to check
     * @return bool True if mapped, false otherwise
     */
    public static function is_mapped($lang_code) {
        return isset(self::$language_map[strtolower(trim($lang_code))]);
    }
    
    /**
     * Get all available language mappings
     * 
     * @return array Array of code => name mappings
     */
    public static function get_all_mappings() {
        return self::$language_map;
    }
    
    /**
     * Add a custom language mapping (for extensibility)
     * 
     * @param string $code Language code
     * @param string $name Language name
     */
    public static function add_mapping($code, $name) {
        self::$language_map[strtolower(trim($code))] = $name;
    }
    
    /**
     * Get language-specific translation instructions for OpenAI
     * This provides context that helps OpenAI understand specific language requirements
     * 
     * @param string $lang_code The target language code
     * @return string Additional instructions for the specific language
     */
    public static function get_language_specific_instructions($lang_code) {
        $instructions = [];
        
        // Normalize code
        $lang_code = strtolower(trim($lang_code));
        
        // Add specific instructions for problematic languages
        switch ($lang_code) {
            case 'be':
                $instructions[] = "IMPORTANT: Target language is Belarusian (not Belgian). Use Cyrillic script, similar to Russian but with distinct Belarusian vocabulary and grammar.";
                break;
            case 'bs':
                $instructions[] = "Target language is Bosnian. Use Latin script, similar to Serbian and Croatian but with Bosnian-specific vocabulary.";
                break;
            case 'gd':
                $instructions[] = "Target language is Scottish Gaelic (Gàidhlig), a Celtic language native to Scotland. Not to be confused with Irish Gaelic.";
                break;
            case 'ga':
                $instructions[] = "Target language is Irish (Gaeilge), the Celtic language of Ireland. Not to be confused with Galician or Scottish Gaelic.";
                break;
            case 'gl':
                $instructions[] = "Target language is Galician, the Romance language of Galicia in Spain. Similar to Portuguese but distinct.";
                break;
            case 'cy':
                $instructions[] = "Target language is Welsh (Cymraeg), the Celtic language of Wales.";
                break;
            case 'mt':
                $instructions[] = "Target language is Maltese, the Semitic language of Malta written in Latin script.";
                break;
            case 'eu':
                $instructions[] = "Target language is Basque (Euskera), a language isolate spoken in the Basque Country. Completely unrelated to any other language.";
                break;
            case 'ka':
                $instructions[] = "Target language is Georgian, written in its unique Georgian script (not Latin or Cyrillic).";
                break;
            case 'hy':
                $instructions[] = "Target language is Armenian, written in Armenian script (not Latin or Cyrillic).";
                break;
            case 'lb':
            case 'lb-lu':
            case 'lb_lu':
                $instructions[] = "CRITICAL LUXEMBOURGISH TRANSLATION RULES:\n1. Target language is Luxembourgish (Lëtzebuergesch).\n2. ONLY translate text content - NEVER modify HTML tags, SVG elements, or tag structure.\n3. If input is '<span>The Goal</span>', output MUST be '<span>D'Zil</span>' - same tags, translated text only.\n4. NEVER add new tags like <div>, <h1>, <h2> that don't exist in original.\n5. NEVER close tags early or restructure HTML. Keep EXACT same tag structure.\n6. SVG elements and their attributes must remain 100% unchanged.\n7. REPLACE original text completely - do NOT append translation after original.\n8. Use Luxembourgish: Moien (Hello), Merci (Thanks), d'USA, ronderëm (around), Zil (Goal).";
                break;
            case 'fy':
                $instructions[] = "Target language is West Frisian, a language spoken in the province of Friesland in the Netherlands. It is distinct from Dutch.";
                break;
            case 'br':
                $instructions[] = "Target language is Breton, a Celtic language spoken in Brittany, France. It is related to Welsh and Cornish.";
                break;
            case 'oc':
                $instructions[] = "Target language is Occitan, a Romance language spoken in southern France. It is distinct from French.";
                break;
            case 'co':
                $instructions[] = "Target language is Corsican, a Romance language spoken on the island of Corsica. It is related to Italian but distinct.";
                break;
            case 'rm':
                $instructions[] = "Target language is Romansh, a Romance language spoken in Switzerland. It is one of the four national languages of Switzerland.";
                break;
        }
        
        return empty($instructions) ? '' : "\n\n" . implode("\n", $instructions);
    }
    
    /**
     * Map language code to country code for flag display
     * 
     * @param string $lang_code The language code (e.g., 'en', 'es', 'ja')
     * @return string Country code for flagcdn.com (e.g., 'gb', 'es', 'jp')
     */
    public static function get_country_code($lang_code) {
        $lang_to_country = [
            // Major languages where language code ≠ country code
            'en'    => 'gb',
            'ja'    => 'jp',
            'zh'    => 'cn',
            'zh-cn' => 'cn',
            'zh-tw' => 'tw',
            'zh-hk' => 'hk',
            'ko'    => 'kr',
            'ar'    => 'sa',
            'he'    => 'il',
            'uk'    => 'ua',
            'sv'    => 'se',
            'da'    => 'dk',
            'cs'    => 'cz',
            'el'    => 'gr',
            'fa'    => 'ir',
            'hi'    => 'in',
            'bn'    => 'bd',
            'vi'    => 'vn',
            'ms'    => 'my',
            'sl'    => 'si',
            'ka'    => 'ge',
            'hy'    => 'am',
            'ur'    => 'pk',
            'ta'    => 'lk',
            'te'    => 'in',
            'ml'    => 'in',
            'kn'    => 'in',
            'gu'    => 'in',
            'pa'    => 'in',
            'ne'    => 'np',
            'si'    => 'lk',
            'km'    => 'kh',
            'lo'    => 'la',
            'my'    => 'mm',
            'sw'    => 'tz',
            'am'    => 'et',
            'sq'    => 'al',
            'bs'    => 'ba',
            'sr'    => 'rs',
            'mk'    => 'mk',
            'be'    => 'by',
            'eu'    => 'es',  // Basque → Spain
            'ca'    => 'es',  // Catalan → Spain (Catalonia)
            'gl'    => 'es',  // Galician → Spain
            'an'    => 'es',  // Aragonese → Spain
            'ast'   => 'es',  // Asturian → Spain
            'eo'    => 'eu',  // Esperanto → EU flag
            'cy'    => 'gb',  // Welsh → UK
            'ga'    => 'ie',  // Irish → Ireland
            'gd'    => 'gb',  // Scottish Gaelic → UK
            'mt'    => 'mt',
            'lb'    => 'lu',
            'lb-lu' => 'lu',
            'lb_lu' => 'lu',
            'is'    => 'is',
            'fo'    => 'fo',
            'af'    => 'za',
            'tl'    => 'ph',
            'nb'    => 'no',
            'nn'    => 'no',
            'fy'    => 'nl',  // Frisian → Netherlands
            'br'    => 'fr',  // Breton → France
            'oc'    => 'fr',  // Occitan → France
            'co'    => 'fr',  // Corsican → France
            'rm'    => 'ch',  // Romansh → Switzerland
            'ps'    => 'af',  // Pashto → Afghanistan
            'sd'    => 'pk',  // Sindhi → Pakistan
            'zu'    => 'za',
            'xh'    => 'za',
            'ha'    => 'ng',
            'yo'    => 'ng',
            'ig'    => 'ng',
            'so'    => 'so',
            'mg'    => 'mg',
            'ti'    => 'er',
            'om'    => 'et',
            'az'    => 'az',
            'kk'    => 'kz',
            'ky'    => 'kg',
            'uz'    => 'uz',
            'mn'    => 'mn',
            'dz'    => 'bt',
            'bo'    => 'cn',  // Tibetan → China
            'se'    => 'no',  // Northern Sami → Norway
            'kw'    => 'gb',  // Cornish → UK
            // Regional variants
            'en-us' => 'us',
            'en-gb' => 'gb',
            'en-au' => 'au',
            'en-ca' => 'ca',
            'fr-fr' => 'fr',
            'fr-ca' => 'ca',
            'de-de' => 'de',
            'de-at' => 'at',
            'de-ch' => 'ch',
            'es-es' => 'es',
            'es-mx' => 'mx',
            'es-ar' => 'ar',
            'it-it' => 'it',
            'it-ch' => 'ch',
            'pt-br' => 'br',
            'pt-pt' => 'pt',
        ];
        
        $code = strtolower(trim($lang_code));
        
        // Exact match first
        if (isset($lang_to_country[$code])) {
            return $lang_to_country[$code];
        }
        
        // Try base code (e.g., 'pt_BR' → 'pt')
        $base = explode('-', str_replace('_', '-', $code))[0];
        if (isset($lang_to_country[$base])) {
            return $lang_to_country[$base];
        }
        
        // Fallback: use language code as country code (works for fr, de, es, it, pt, etc.)
        return $base;
    }
    
    /**
     * Get flag image URL (local bundled SVG, CDN fallback)
     * 
     * @param string $lang_code The language code
     * @param int $width Flag width in pixels (used for fallback CDN sizing)
     * @return string Flag image URL
     */
    /**
     * Flag URL for a country code: bundled SVG first, CDN only for flags we don't ship.
     */
    public static function get_flag_url_for_country($country_code, $width = 40) {
        $country_code = strtolower(preg_replace('/[^a-z]/i', '', (string) $country_code));
        $local_file = 'assets/img/flags/' . $country_code . '.svg';
        if ($country_code && defined('AITRFOEL_PLUGIN_DIR') && file_exists(AITRFOEL_PLUGIN_DIR . $local_file)) {
            return AITRFOEL_PLUGIN_URL . $local_file;
        }
        return 'https://flagcdn.com/w' . max(20, intval($width)) . '/' . $country_code . '.png';
    }

    public static function get_flag_url($lang_code, $width = 24) {
        $country_code = self::get_country_code($lang_code);
        $local_file = 'assets/img/flags/' . $country_code . '.svg';
        
        // Use bundled SVG if available
        if (defined('AITRFOEL_PLUGIN_DIR') && file_exists(AITRFOEL_PLUGIN_DIR . $local_file)) {
            return AITRFOEL_PLUGIN_URL . $local_file;
        }
        
        // Fallback to CDN for flags not bundled
        $w = max(20, intval($width));
        return 'https://flagcdn.com/w' . $w . '/' . $country_code . '.png';
    }
    
    /**
     * Get flag image HTML tag
     * 
     * @param string $lang_code The language code
     * @param int $width Flag width in pixels (default: 24)
     * @param string $extra_style Additional inline CSS
     * @return string HTML img tag
     */
    public static function get_flag_html($lang_code, $width = 24, $extra_style = '') {
        $url = self::get_flag_url($lang_code, $width);
        $name = self::get_language_name($lang_code, false);
        $height = intval($width * 0.75); // Standard flag aspect ratio
        $style = "vertical-align: middle; border-radius: 2px;";
        if ($extra_style) {
            $style .= ' ' . $extra_style;
        }
        return sprintf(
            '<img src="%s" alt="%s" width="%d" height="%d" style="%s" loading="lazy" />',
            esc_url($url),
            esc_attr($name),
            $width,
            $height,
            esc_attr($style)
        );
    }
}
