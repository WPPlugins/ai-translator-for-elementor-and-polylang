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
}
