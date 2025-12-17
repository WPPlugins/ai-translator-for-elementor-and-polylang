<?php
/**
 * Add "Data is never shared" translation
 */

$baseDir = __DIR__;
$textDomain = 'ai-translator-for-elementor-and-polylang';

$allTranslations = [
    'Data is never shared with third parties.' => [
        'ru' => 'Данные никогда не передаются третьим лицам.',
        'es' => 'Los datos nunca se comparten con terceros.',
        'de' => 'Daten werden niemals an Dritte weitergegeben.',
        'fr' => 'Les données ne sont jamais partagées avec des tiers.',
        'uk' => 'Дані ніколи не передаються третім особам.',
        'it' => 'I dati non vengono mai condivisi con terze parti.',
        'nl' => 'Gegevens worden nooit gedeeld met derden.',
        'pl' => 'Dane nigdy nie są udostępniane osobom trzecim.',
        'pt' => 'Os dados nunca são compartilhados com terceiros.'
    ],
];

// Language file mappings
$langFiles = [
    'ru' => "$baseDir/$textDomain-ru_RU.po",
    'es' => "$baseDir/$textDomain-es_ES.po",
    'de' => "$baseDir/$textDomain-de_DE.po", 
    'fr' => "$baseDir/$textDomain-fr_FR.po",
    'uk' => "$baseDir/$textDomain-uk.po",
    'it' => "$baseDir/$textDomain-it_IT.po",
    'nl' => "$baseDir/$textDomain-nl_NL.po",
    'pl' => "$baseDir/$textDomain-pl_PL.po",
    'pt' => "$baseDir/$textDomain-pt_BR.po",
];

foreach ($langFiles as $langCode => $filePath) {
    if (!file_exists($filePath)) {
        echo "File not found: $filePath\n";
        continue;
    }
    
    $content = file_get_contents($filePath);
    $added = 0;
    
    foreach ($allTranslations as $msgid => $translations) {
        if (!isset($translations[$langCode])) continue;
        
        $escapedMsgid = preg_quote($msgid, '/');
        if (!preg_match('/msgid "' . $escapedMsgid . '"/u', $content)) {
            $content .= "\nmsgid \"$msgid\"\n";
            $content .= "msgstr \"{$translations[$langCode]}\"\n";
            $added++;
        }
    }
    
    file_put_contents($filePath, $content);
    echo "$langCode: Added $added new strings\n";
}

echo "\nRegenerating MO files...\n";
include __DIR__ . '/generate-mo-files.php';
