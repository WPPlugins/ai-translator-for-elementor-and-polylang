<?php
/**
 * Add missing translations for the SEO retranslate/check tool strings.
 * Usage: php add-seo-tool-translations.php
 */

$envFile = 'C:\\Users\\Andrei\\repos\\meeptech\\.env.local';
$envContent = file_get_contents($envFile);
preg_match('/OPENAI_API_KEY=(.+)/', $envContent, $m);
$apiKey = trim($m[1] ?? '');
if (!$apiKey) { die("No API key found\n"); }

$langDir = __DIR__;
$domain = 'ai-translator-for-elementor-and-polylang';

$languages = [
    'de_DE' => 'German',
    'es_ES' => 'Spanish',
    'fr_FR' => 'French',
    'it_IT' => 'Italian',
    'nl_NL' => 'Dutch',
    'pl_PL' => 'Polish',
    'pt_BR' => 'Brazilian Portuguese',
    'ru_RU' => 'Russian',
    'uk'    => 'Ukrainian',
];

// New strings introduced in v1.5.17
$allStrings = [
    'Retranslate Existing SEO Titles',
    'Retranslate SEO Titles',
    'Check Translation Status',
    'Checking…',
    'Retranslating 0 of',
    'Error during retranslating.',
    'Retranslated',
    'Done! Retranslated',
    'All',
    'translations have correct SEO titles.',
    'translations need retranslation',
    'appear correctly translated.',
    // also add v1.5.16 strings that may be missing in older .po files
    'Counting pages…',
    'No translated pages found.',
    'Request failed — try again.',
    'Could not connect to server.',
    'Error:',
    'of',
    'translations.',
    'Patching 0 of',
    'Error during patching.',
    'Patched',
    'Done! Patched',
    'Patch SEO Titles for All Translations',
    'Patch Existing SEO Titles',
];

foreach ($languages as $locale => $langName) {
    $poFile = $langDir . "/{$domain}-{$locale}.po";
    if (!file_exists($poFile)) {
        echo "SKIP: $poFile not found\n";
        continue;
    }

    echo "=== Processing: $langName ($locale) ===\n";
    $poContent = file_get_contents($poFile);

    $missing = [];
    foreach ($allStrings as $str) {
        $escaped = addcslashes($str, '"');
        if (strpos($poContent, 'msgid "' . $escaped . '"') === false) {
            $missing[] = $str;
        }
    }

    if (empty($missing)) {
        echo "  No missing strings!\n\n";
        continue;
    }

    echo "  Missing: " . count($missing) . " strings\n";

    $batches = array_chunk($missing, 40);
    $translated = [];

    foreach ($batches as $batchIdx => $batch) {
        echo "  Translating batch " . ($batchIdx + 1) . "/" . count($batches) . "...\n";
        $result = translateBatch($batch, $langName, $apiKey);
        if ($result === false) {
            echo "  ERROR: Translation failed\n";
            continue;
        }
        foreach ($batch as $i => $original) {
            if (isset($result[$i])) {
                $translated[$original] = $result[$i];
            }
        }
        if ($batchIdx < count($batches) - 1) usleep(500000);
    }

    echo "  Translated: " . count($translated) . " strings\n";

    if (!empty($translated)) {
        $appendBlock = "\n\n# --- Added by add-seo-tool-translations.php (" . date('Y-m-d') . ") ---\n";
        foreach ($translated as $original => $translation) {
            $appendBlock .= "\n" . formatPoEntry($original, $translation);
        }
        file_put_contents($poFile, $poContent . $appendBlock);
        echo "  Appended to $poFile\n";
    }
    echo "\n";
}

// Recompile .mo files
echo "=== Compiling .mo files ===\n";
require_once __DIR__ . '/generate-mo-files.php';
echo "\nDone!\n";

// ============================================================

function translateBatch(array $strings, string $targetLang, string $apiKey): array|false {
    $systemPrompt = "You are a professional translator specializing in WordPress plugin UI translations. Translate the following English strings to {$targetLang}.

RULES:
1. Return ONLY a JSON array of translated strings in the exact same order
2. Preserve all placeholders like %s, %d exactly as-is
3. Preserve emoji characters exactly as-is
4. Use formal/polite form (Sie/Vous/Usted/Lei etc.) for UI text
5. Keep brand names untranslated: Rank Math, Yoast SEO, WordPress
6. '…' (ellipsis) should be preserved as-is

Format: [\"translation 1\", \"translation 2\", ...]";

    $userPrompt = "Translate these " . count($strings) . " strings to {$targetLang}:\n\n" . json_encode($strings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    $payload = [
        'model' => 'gpt-4o-mini',
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ],
        'temperature' => 0.2,
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 120,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        echo "    API error ($httpCode): " . substr($response, 0, 200) . "\n";
        return false;
    }

    $data = json_decode($response, true);
    $content = trim($data['choices'][0]['message']['content'] ?? '');
    $content = preg_replace('/^```(?:json)?\s*/', '', $content);
    $content = preg_replace('/\s*```$/', '', $content);

    $result = json_decode($content, true);
    if (!is_array($result) || count($result) !== count($strings)) {
        echo "    Parse error (expected " . count($strings) . ", got " . (is_array($result) ? count($result) : 'null') . ")\n";
        echo "    Raw: " . substr($content, 0, 300) . "\n";
        return false;
    }
    return $result;
}

function formatPoEntry(string $msgid, string $msgstr): string {
    $escapedId  = addcslashes($msgid,  '"');
    $escapedStr = addcslashes($msgstr, '"');
    return "msgid \"{$escapedId}\"\nmsgstr \"{$escapedStr}\"\n";
}
