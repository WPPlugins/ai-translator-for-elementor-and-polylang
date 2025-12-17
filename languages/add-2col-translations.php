<?php
/**
 * Add new welcome popup translations for 2-column layout
 */

$baseDir = __DIR__;
$textDomain = 'ai-translator-for-elementor-and-polylang';

$allTranslations = [
    'Translate your Elementor pages instantly with AI.' => [
        'ru' => 'Мгновенно переводите страницы Elementor с помощью ИИ.',
        'es' => 'Traduce tus páginas de Elementor instantáneamente con IA.',
        'de' => 'Übersetzen Sie Ihre Elementor-Seiten sofort mit KI.',
        'fr' => 'Traduisez instantanément vos pages Elementor avec l\'IA.',
        'uk' => 'Миттєво перекладайте сторінки Elementor за допомогою ШІ.',
        'it' => 'Traduci istantaneamente le tue pagine Elementor con l\'IA.',
        'nl' => 'Vertaal je Elementor-pagina\'s direct met AI.',
        'pl' => 'Natychmiast tłumacz strony Elementor za pomocą AI.',
        'pt' => 'Traduza suas páginas Elementor instantaneamente com IA.'
    ],
    'One-click translation with advanced AI' => [
        'ru' => 'Перевод одним кликом с продвинутым ИИ',
        'es' => 'Traducción con un clic usando IA avanzada',
        'de' => 'Ein-Klick-Übersetzung mit fortschrittlicher KI',
        'fr' => 'Traduction en un clic avec IA avancée',
        'uk' => 'Переклад одним кліком з передовим ШІ',
        'it' => 'Traduzione con un clic con IA avanzata',
        'nl' => 'Vertaling met één klik met geavanceerde AI',
        'pl' => 'Tłumaczenie jednym kliknięciem z zaawansowanym AI',
        'pt' => 'Tradução com um clique usando IA avançada'
    ],
    'Translate multiple pages at once' => [
        'ru' => 'Переводите несколько страниц одновременно',
        'es' => 'Traduce varias páginas a la vez',
        'de' => 'Übersetzen Sie mehrere Seiten gleichzeitig',
        'fr' => 'Traduisez plusieurs pages à la fois',
        'uk' => 'Перекладайте кілька сторінок одночасно',
        'it' => 'Traduci più pagine contemporaneamente',
        'nl' => 'Vertaal meerdere pagina\'s tegelijk',
        'pl' => 'Tłumacz wiele stron jednocześnie',
        'pt' => 'Traduza várias páginas de uma vez'
    ],
    'Works with all Elementor widgets' => [
        'ru' => 'Работает со всеми виджетами Elementor',
        'es' => 'Funciona con todos los widgets de Elementor',
        'de' => 'Funktioniert mit allen Elementor-Widgets',
        'fr' => 'Fonctionne avec tous les widgets Elementor',
        'uk' => 'Працює з усіма віджетами Elementor',
        'it' => 'Funziona con tutti i widget Elementor',
        'nl' => 'Werkt met alle Elementor-widgets',
        'pl' => 'Działa ze wszystkimi widgetami Elementor',
        'pt' => 'Funciona com todos os widgets do Elementor'
    ],
    'Set up languages in' => [
        'ru' => 'Настройте языки в',
        'es' => 'Configura los idiomas en',
        'de' => 'Sprachen einrichten in',
        'fr' => 'Configurez les langues dans',
        'uk' => 'Налаштуйте мови в',
        'it' => 'Configura le lingue in',
        'nl' => 'Talen instellen in',
        'pl' => 'Skonfiguruj języki w',
        'pt' => 'Configure os idiomas em'
    ],
    'Select pages & target languages' => [
        'ru' => 'Выберите страницы и целевые языки',
        'es' => 'Selecciona páginas e idiomas de destino',
        'de' => 'Seiten und Zielsprachen auswählen',
        'fr' => 'Sélectionnez les pages et les langues cibles',
        'uk' => 'Виберіть сторінки та цільові мови',
        'it' => 'Seleziona pagine e lingue di destinazione',
        'nl' => 'Selecteer pagina\'s en doeltalen',
        'pl' => 'Wybierz strony i języki docelowe',
        'pt' => 'Selecione páginas e idiomas de destino'
    ],
    'Click translate!' => [
        'ru' => 'Нажмите перевести!',
        'es' => '¡Haz clic en traducir!',
        'de' => 'Klicken Sie auf Übersetzen!',
        'fr' => 'Cliquez sur traduire !',
        'uk' => 'Натисніть перекласти!',
        'it' => 'Clicca su traduci!',
        'nl' => 'Klik op vertalen!',
        'pl' => 'Kliknij tłumacz!',
        'pt' => 'Clique em traduzir!'
    ],
    'Documentation' => [
        'ru' => 'Документация',
        'es' => 'Documentación',
        'de' => 'Dokumentation',
        'fr' => 'Documentation',
        'uk' => 'Документація',
        'it' => 'Documentazione',
        'nl' => 'Documentatie',
        'pl' => 'Dokumentacja',
        'pt' => 'Documentação'
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
