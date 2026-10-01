#!/usr/bin/env php
<?php
/**
 * Maintenance: requeue entries whose translation was wiped by a feed update.
 *
 * Fingerprint of a wiped entry: the translated label row still exists in
 * _entrytag (updateEntry() does not touch that table), but the tags column
 * of _entry lost the #t:<translated> marker (updateEntry() rewrites the tags
 * column with feed-provided tags). Those entries get the translated label
 * swapped for the pending one, so the next worker run translates them again.
 *
 * Usage (Docker):
 *   docker exec freshrss php /var/www/FreshRSS/extensions/xExtension-AutoTranslate/scripts/requeue_reverted.php
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

$totalRequeued = 0;

foreach (FreshRSS_user_Controller::listUsers() as $user) {
    FreshRSS_Context::initUser($user);

    if (!FreshRSS_Context::hasUserConf() || !FreshRSS_Context::userConf()->enabled) {
        continue;
    }

    FreshRSS_Auth::giveAccess();

    $extList = FreshRSS_Context::userConf()->extensions_enabled ?? [];
    Minz_ExtensionManager::enableByList($extList, 'user');

    $extension = Minz_ExtensionManager::findExtension('AutoTranslate');
    if ($extension === null) {
        continue;
    }

    $labelPending = (string)($extension->getSystemConfigurationValue('label_pending') ?: FreshExtension_AutoTranslate_Labels::DEFAULT_PENDING);
    $labelTranslated = (string)($extension->getSystemConfigurationValue('label_translated') ?: FreshExtension_AutoTranslate_Labels::DEFAULT_TRANSLATED);
    $channelsFilter = $extension->getSystemConfigurationValue('channels_filter') ?? [];
    if (!is_array($channelsFilter)) {
        $channelsFilter = [];
    }
    if (empty($channelsFilter)) {
        echo "user {$user}: channels_filter is empty, nothing to requeue\n";
        continue;
    }

    $tagDao = FreshRSS_Factory::createTagDao();
    $pendingTag = $tagDao->searchByName($labelPending);
    $translatedTag = $tagDao->searchByName($labelTranslated);
    if ($pendingTag === null || $translatedTag === null) {
        echo "user {$user}: labels \"{$labelPending}\"/\"{$labelTranslated}\" not found, skipping\n";
        continue;
    }

    $model = new FreshExtension_AutoTranslate_PendingEntries_Model();
    $requeued = $model->requeueRevertedEntries((int)$pendingTag->id(), (int)$translatedTag->id(), $channelsFilter);
    $totalRequeued += $requeued;

    echo "user {$user}: requeued {$requeued} wiped translations\n";
}

echo "Done — total requeued: {$totalRequeued}\n";
exit(0);
