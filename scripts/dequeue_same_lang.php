<?php
/**
 * One-off utility: remove the pending label from queued entries that are
 * already in the target language (no translation needed).
 *
 * Usage: docker exec freshrss php /tmp/at_dequeue.php
 */

declare(strict_types=1);

require '/var/www/FreshRSS/constants.php';
require LIB_PATH . '/lib_rss.php';
require LIB_PATH . '/lib_install.php';

Minz_Session::init('FreshRSS', true);
FreshRSS_Context::initSystem();
Minz_ExtensionManager::init();
Minz_Translate::init(Minz_Translate::DEFAULT_LANGUAGE);
FreshRSS_Context::$isCli = true;
FreshRSS_Context::initUser('admin');
FreshRSS_Auth::giveAccess();

// Load user extensions the same way process_pending.php does:
// this pulls in the AutoTranslate extension and its classes
$extList = FreshRSS_Context::userConf()->extensions_enabled ?? [];
Minz_ExtensionManager::enableByList($extList, 'user');

// pending tag id, target lang, feeds — sync with extension settings
$pendingTagId = 4;
$targetLang = 'ru';
$feeds = [98, 102, 104, 109, 112, 114, 117, 124, 127, 129, 135, 138, 158, 173, 174, 183, 260, 264, 268, 286, 287, 288];

$model = new FreshExtension_AutoTranslate_PendingEntries_Model();
$removed = $model->dequeueEntriesInTargetLanguage($pendingTagId, $targetLang, $feeds);
echo "dequeued (already in target language): {$removed}\n";
