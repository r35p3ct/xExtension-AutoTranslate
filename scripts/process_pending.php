#!/usr/bin/env php
<?php
/**
 * Background script translating entries with the pending label.
 *
 * Recommended cron (every 5 minutes):
 *   php /path/to/freshrss/extensions/xExtension-AutoTranslate/scripts/process_pending.php
 *
 * Docker cron line (see also README):
 *   every 5 minutes: docker exec freshrss php /var/www/FreshRSS/extensions/xExtension-AutoTranslate/scripts/process_pending.php
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Locate the FreshRSS root
// ---------------------------------------------------------------------------

$freshrssRoot = null;
$possiblePaths = [
    __DIR__ . '/../../../',
    __DIR__ . '/../../../../',
    __DIR__ . '/../../../../../',
];

foreach ($possiblePaths as $path) {
    $realPath = realpath($path);
    if ($realPath && file_exists($realPath . '/constants.php')) {
        $freshrssRoot = $realPath;
        break;
    }
}

if ($freshrssRoot === null) {
    fwrite(STDERR, "Failed to find FreshRSS root directory.\n");
    fwrite(STDERR, "Please run this script from inside the extension directory.\n");
    exit(1);
}

require $freshrssRoot . '/constants.php';
require LIB_PATH . '/lib_rss.php';
require LIB_PATH . '/lib_install.php';

Minz_Session::init('FreshRSS', true);
FreshRSS_Context::initSystem();
Minz_ExtensionManager::init();
Minz_Translate::init(Minz_Translate::DEFAULT_LANGUAGE);

FreshRSS_Context::$isCli = true;

if (!FreshRSS_Context::hasSystemConf()) {
    fwrite(STDERR, "Failed to initialize FreshRSS system context.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Parameters
// ---------------------------------------------------------------------------

$extensionName = 'AutoTranslate';
$mutexFile = TMP_PATH . '/autotranslate_background.lock';
$mutexTtl = 600; // 10 minutes — if a previous run hung

// ---------------------------------------------------------------------------
// Mutex: never run two copies at once
// ---------------------------------------------------------------------------

if (file_exists($mutexFile) && ((time() - (@filemtime($mutexFile) ?: 0)) > $mutexTtl)) {
    @unlink($mutexFile);
}

if (($handle = @fopen($mutexFile, 'x')) === false) {
    fwrite(STDERR, "AutoTranslate background process is already running.\n");
    exit(0);
}

fclose($handle);
register_shutdown_function(static function () use ($mutexFile) {
    @unlink($mutexFile);
});

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------

function notice(string $message): void {
    Minz_Log::notice($message, ADMIN_LOG);
    if (defined('STDOUT')) {
        fwrite(STDOUT, $message . "\n");
    }
}

// ---------------------------------------------------------------------------
// Process every user
// ---------------------------------------------------------------------------

$users = FreshRSS_user_Controller::listUsers();
$totalProcessed = 0;
$totalSkipped = 0;
$totalErrors = 0;

foreach ($users as $user) {
    FreshRSS_Context::initUser($user);

    if (!FreshRSS_Context::hasUserConf()) {
        notice("AutoTranslate: Skip invalid user {$user}");
        continue;
    }

    if (!FreshRSS_Context::userConf()->enabled) {
        notice("AutoTranslate: Skip disabled user {$user}");
        continue;
    }

    FreshRSS_Auth::giveAccess();

    $app = new FreshRSS();
    $app->init();

    // Explicitly enable user extensions
    $extList = FreshRSS_Context::userConf()->extensions_enabled ?? [];
    Minz_ExtensionManager::enableByList($extList, 'user');

    $isEnabled = false;
    if (is_array($extList)) {
        $isEnabled = isset($extList[$extensionName]) || in_array($extensionName, $extList, true);
    }
    if (!$isEnabled) {
        notice("AutoTranslate: Extension not enabled for user {$user}, skipping.");
        continue;
    }

    $extension = Minz_ExtensionManager::findExtension($extensionName);
    if ($extension === null) {
        notice("AutoTranslate: Extension not loaded for user {$user}");
        continue;
    }

    $batchSize = (int)($extension->getSystemConfigurationValue('batch_size') ?? 10);
    $requestDelayMs = (int)($extension->getSystemConfigurationValue('request_delay_ms') ?? 250);
    $channelsFilter = $extension->getSystemConfigurationValue('channels_filter') ?? [];
    $targetLang = (string)($extension->getSystemConfigurationValue('target_lang') ?? 'en');
    $engine = (string)($extension->getSystemConfigurationValue('engine') ?? 'google');

    $config = [
        'engine'                => $engine,
        'target_lang'           => $targetLang,
        'label_pending'         => $extension->getSystemConfigurationValue('label_pending'),
        'label_translated'      => $extension->getSystemConfigurationValue('label_translated'),
        'label_advertisement'   => $extension->getSystemConfigurationValue('label_advertisement'),
        'skip_ads'              => $extension->getSystemConfigurationValue('skip_ads'),
        'wait_ads_minutes'      => $extension->getSystemConfigurationValue('wait_ads_minutes'),
        'request_delay_ms'      => $requestDelayMs,
        'enable_logging'        => $extension->getSystemConfigurationValue('enable_logging'),
        'llm_api_key'           => $extension->getSystemConfigurationValue('llm_api_key'),
        'llm_model'             => $extension->getSystemConfigurationValue('llm_model'),
        'max_content_chars'     => $extension->getSystemConfigurationValue('max_content_chars'),
    ];

    notice(sprintf(
        'AutoTranslate: User %s config — engine=%s, target=%s, batch=%d, delay=%dms, channels=%s',
        $user,
        $engine,
        $targetLang,
        $batchSize,
        $requestDelayMs,
        empty($channelsFilter) ? 'all' : implode(',', $channelsFilter)
    ));

    $controller = new FreshExtension_AutoTranslate_Controller($config);
    $result = $controller->processPendingEntries($batchSize, $requestDelayMs, $channelsFilter);

    $totalProcessed += $result['processed'];
    $totalSkipped += $result['skipped'];
    $totalErrors += $result['errors'];

    if ($result['processed'] > 0 || $result['errors'] > 0 || $result['skipped'] > 0) {
        notice(sprintf(
            'AutoTranslate: User %s — processed=%d, skipped=%d, errors=%d',
            $user,
            $result['processed'],
            $result['skipped'],
            $result['errors']
        ));
    }

    gc_collect_cycles();
}

notice(sprintf(
    'AutoTranslate: Background translation finished — total processed=%d, skipped=%d, errors=%d',
    $totalProcessed,
    $totalSkipped,
    $totalErrors
));

exit(0);
