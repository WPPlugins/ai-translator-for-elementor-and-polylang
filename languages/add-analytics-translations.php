<?php
/**
 * Add analytics consent translations
 */

$baseDir = __DIR__;
$textDomain = 'ai-translator-for-elementor-and-polylang';

$allTranslations = [
    'Help us improve by enabling anonymous usage tracking' => [
        'ru' => 'Помогите нам улучшить плагин, включив анонимный сбор статистики',
        'es' => 'Ayúdanos a mejorar habilitando el seguimiento anónimo de uso',
        'de' => 'Helfen Sie uns, uns zu verbessern, indem Sie anonymes Nutzungs-Tracking aktivieren',
        'fr' => 'Aidez-nous à nous améliorer en activant le suivi anonyme de l\'utilisation',
        'uk' => 'Допоможіть нам покращити плагін, увімкнувши анонімне відстеження використання',
        'it' => 'Aiutaci a migliorare abilitando il tracciamento anonimo dell\'utilizzo',
        'nl' => 'Help ons verbeteren door anonieme gebruiksregistratie in te schakelen',
        'pl' => 'Pomóż nam się doskonalić, włączając anonimowe śledzenie użytkowania',
        'pt' => 'Ajude-nos a melhorar ativando o rastreamento anônimo de uso'
    ],
    'What we collect:' => [
        'ru' => 'Что мы собираем:',
        'es' => 'Qué recopilamos:',
        'de' => 'Was wir erfassen:',
        'fr' => 'Ce que nous collectons :',
        'uk' => 'Що ми збираємо:',
        'it' => 'Cosa raccogliamo:',
        'nl' => 'Wat we verzamelen:',
        'pl' => 'Co zbieramy:',
        'pt' => 'O que coletamos:'
    ],
    'Plugin version & WordPress version' => [
        'ru' => 'Версия плагина и WordPress',
        'es' => 'Versión del plugin y WordPress',
        'de' => 'Plugin-Version und WordPress-Version',
        'fr' => 'Version du plugin et de WordPress',
        'uk' => 'Версія плагіна та WordPress',
        'it' => 'Versione del plugin e di WordPress',
        'nl' => 'Plugin-versie en WordPress-versie',
        'pl' => 'Wersja wtyczki i WordPress',
        'pt' => 'Versão do plugin e WordPress'
    ],
    'Features used (translation, bulk mode)' => [
        'ru' => 'Используемые функции (перевод, массовый режим)',
        'es' => 'Funciones utilizadas (traducción, modo masivo)',
        'de' => 'Verwendete Funktionen (Übersetzung, Massenmodus)',
        'fr' => 'Fonctionnalités utilisées (traduction, mode groupé)',
        'uk' => 'Використані функції (переклад, масовий режим)',
        'it' => 'Funzionalità utilizzate (traduzione, modalità batch)',
        'nl' => 'Gebruikte functies (vertaling, bulkmodus)',
        'pl' => 'Używane funkcje (tłumaczenie, tryb masowy)',
        'pt' => 'Recursos usados (tradução, modo em lote)'
    ],
    'Error reports for debugging' => [
        'ru' => 'Отчеты об ошибках для отладки',
        'es' => 'Informes de errores para depuración',
        'de' => 'Fehlerberichte für Debugging',
        'fr' => 'Rapports d\'erreurs pour le débogage',
        'uk' => 'Звіти про помилки для налагодження',
        'it' => 'Report degli errori per il debug',
        'nl' => 'Foutrapporten voor debugging',
        'pl' => 'Raporty błędów do debugowania',
        'pt' => 'Relatórios de erros para depuração'
    ],
    'AI model usage statistics' => [
        'ru' => 'Статистика использования моделей ИИ',
        'es' => 'Estadísticas de uso del modelo de IA',
        'de' => 'KI-Modell-Nutzungsstatistiken',
        'fr' => 'Statistiques d\'utilisation du modèle IA',
        'uk' => 'Статистика використання моделей ШІ',
        'it' => 'Statistiche di utilizzo del modello AI',
        'nl' => 'AI-model gebruiksstatistieken',
        'pl' => 'Statystyki użycia modelu AI',
        'pt' => 'Estatísticas de uso do modelo de IA'
    ],
    'We never collect:' => [
        'ru' => 'Мы никогда не собираем:',
        'es' => 'Nunca recopilamos:',
        'de' => 'Wir erfassen niemals:',
        'fr' => 'Nous ne collectons jamais :',
        'uk' => 'Ми ніколи не збираємо:',
        'it' => 'Non raccogliamo mai:',
        'nl' => 'We verzamelen nooit:',
        'pl' => 'Nigdy nie zbieramy:',
        'pt' => 'Nunca coletamos:'
    ],
    'Personal data or content' => [
        'ru' => 'Личные данные или контент',
        'es' => 'Datos personales o contenido',
        'de' => 'Persönliche Daten oder Inhalte',
        'fr' => 'Données personnelles ou contenu',
        'uk' => 'Особисті дані або вміст',
        'it' => 'Dati personali o contenuti',
        'nl' => 'Persoonlijke gegevens of inhoud',
        'pl' => 'Dane osobowe lub treści',
        'pt' => 'Dados pessoais ou conteúdo'
    ],
    'API keys or credentials' => [
        'ru' => 'API ключи или учетные данные',
        'es' => 'Claves API o credenciales',
        'de' => 'API-Schlüssel oder Anmeldedaten',
        'fr' => 'Clés API ou identifiants',
        'uk' => 'API ключі або облікові дані',
        'it' => 'Chiavi API o credenziali',
        'nl' => 'API-sleutels of referenties',
        'pl' => 'Klucze API lub dane uwierzytelniające',
        'pt' => 'Chaves de API ou credenciais'
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
