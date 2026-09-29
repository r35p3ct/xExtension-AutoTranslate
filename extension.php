<?php

declare(strict_types=1);

require_once __DIR__ . '/Labels.php';
require_once __DIR__ . '/Controllers/translateController.php';

/**
 * AutoTranslate extension for FreshRSS.
 *
 * Queues articles from selected feeds for translation (label set in the
 * entry_before_add hook) and replaces title/content with the translation
 * via a background worker, so translations are visible in every client.
 *
 * @version 0.1.0
 */
class AutoTranslateExtension extends Minz_Extension
{
    public function init(): void
    {
        $this->registerTranslates();
        $this->registerController('translate');
        $this->registerHook('entry_before_add', [$this, 'onEntryBeforeAdd']);
    }

    /**
     * Save settings.
     */
    public function handleConfigureAction(): void
    {
        if (Minz_Request::isPost()) {
            $channelsFilter = $_POST['auto_translate_channels_filter'] ?? [];
            if (!is_array($channelsFilter)) {
                $channelsFilter = [];
            }
            $channelsFilter = array_map('strval', $channelsFilter);
            $channelsFilter = array_values(array_unique(array_filter($channelsFilter)));

            $targetLang = strtolower(trim(Minz_Request::paramString('auto_translate_target_lang', true)));
            if (!preg_match('/^[a-z]{2}(-[a-z]{2,4})?$/i', $targetLang)) {
                $targetLang = 'en';
            }

            $labelPending = trim(Minz_Request::paramString('auto_translate_label_pending', true));
            $labelTranslated = trim(Minz_Request::paramString('auto_translate_label_translated', true));
            $labelAdvertisement = trim(Minz_Request::paramString('auto_translate_label_advertisement', true));

            // plaintext=true on text fields: otherwise quotes get HTML-escaped on every save
            $newConfig = [
                'engine'                => Minz_Request::paramString('auto_translate_engine') === 'llm' ? 'llm' : 'google',
                'target_lang'           => $targetLang !== '' ? $targetLang : 'en',
                'label_pending'         => $labelPending !== '' ? $labelPending : FreshExtension_AutoTranslate_Labels::DEFAULT_PENDING,
                'label_translated'      => $labelTranslated !== '' ? $labelTranslated : FreshExtension_AutoTranslate_Labels::DEFAULT_TRANSLATED,
                'label_advertisement'   => $labelAdvertisement !== '' ? $labelAdvertisement : FreshExtension_AutoTranslate_Labels::DEFAULT_ADVERTISEMENT,
                'skip_ads'              => Minz_Request::paramString('auto_translate_skip_ads') === '1',
                'wait_ads_minutes'      => max(0, min(120, (int)Minz_Request::param('auto_translate_wait_ads_minutes', 10))),
                'channels_filter'       => $channelsFilter,
                'batch_size'            => max(1, min(100, (int)Minz_Request::param('auto_translate_batch_size', 10))),
                'request_delay_ms'      => max(0, min(60000, (int)Minz_Request::param('auto_translate_request_delay_ms', 250))),
                'enable_logging'        => Minz_Request::paramString('auto_translate_enable_logging') === '1',
                'llm_api_key'           => Minz_Request::paramString('auto_translate_llm_api_key'),
                'llm_model'             => trim(Minz_Request::paramString('auto_translate_llm_model', true)) ?: 'openai/gpt-4o-mini',
                'max_content_chars'     => max(500, min(50000, (int)Minz_Request::param('auto_translate_max_content_chars', 6000))),
            ];

            $this->setSystemConfiguration($newConfig);
        }
    }

    /**
     * Hook entry_before_add: queue the entry for background translation.
     * No synchronous work here - the entry is added to the DB instantly and
     * the background script does the translation (see scripts/process_pending.php).
     *
     * @param FreshRSS_Entry|null $entry
     * @return FreshRSS_Entry|null
     */
    public function onEntryBeforeAdd($entry): ?FreshRSS_Entry
    {
        if (!$entry) {
            return $entry;
        }

        $enableLogging = $this->getSystemConfigurationValue('enable_logging');

        if (!$this->isChannelEnabled($entry)) {
            if ($enableLogging) {
                Minz_Log::warning(sprintf(
                    'AutoTranslate: Entry skipped — feed %s not in channels_filter',
                    $entry->feedId()
                ));
            }
            return $entry;
        }

        if ($this->hasAnyTranslateLabel($entry)) {
            if ($enableLogging) {
                Minz_Log::warning('AutoTranslate: Entry already has a translate label, skip');
            }
            return $entry;
        }

        if ($this->isLikelyInTargetLanguage($entry)) {
            if ($enableLogging) {
                Minz_Log::warning('AutoTranslate: Entry is likely already in the target language, skip');
            }
            return $entry;
        }

        $this->applyPendingLabel($entry);
        return $entry;
    }

    /**
     * @return bool true if the feed is in the selected list or the list is empty
     */
    private function isChannelEnabled(FreshRSS_Entry $entry): bool
    {
        $channelsFilter = $this->getSystemConfigurationValue('channels_filter');

        if (empty($channelsFilter) || !is_array($channelsFilter)) {
            return true;
        }

        $feedId = (string)$entry->feedId();
        return in_array($feedId, $channelsFilter, true);
    }

    /**
     * Check whether the entry already carries the pending or the translated
     * label (matched by configurable tag names).
     */
    private function hasAnyTranslateLabel(FreshRSS_Entry $entry): bool
    {
        $pendingName = (string)$this->getSystemConfigurationValue('label_pending') ?: FreshExtension_AutoTranslate_Labels::DEFAULT_PENDING;
        $translatedName = (string)$this->getSystemConfigurationValue('label_translated') ?: FreshExtension_AutoTranslate_Labels::DEFAULT_TRANSLATED;
        $known = [$pendingName, $translatedName];

        $tagIdToName = [];
        try {
            foreach (FreshRSS_Factory::createTagDao()->listTags() as $tag) {
                $tagIdToName[$tag->id()] = $tag->name();
            }
        } catch (Exception $e) {
            Minz_Log::warning('AutoTranslate: Failed to list tags: ' . $e->getMessage());
            return false;
        }

        foreach ($entry->tags() as $tag) {
            if (is_string($tag) && str_starts_with($tag, 't:')) {
                $tagId = (int)substr($tag, 2);
                if (isset($tagIdToName[$tagId]) && in_array($tagIdToName[$tagId], $known, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Cheap same-language pre-check in the hook, without any API call:
     * for Cyrillic-script target languages an article whose letters are
     * mostly Cyrillic is considered already translated.
     */
    private function isLikelyInTargetLanguage(FreshRSS_Entry $entry): bool
    {
        $target = strtolower(trim((string)$this->getSystemConfigurationValue('target_lang') ?: 'en'));
        if (!in_array($target, FreshExtension_AutoTranslate_Labels::CYRILLIC_TARGETS, true)) {
            return false;
        }

        $text = (string)$entry->title() . "\n" . strip_tags((string)$entry->content());
        $letters = preg_match_all('/\p{L}/u', $text);
        if ($letters < 40) {
            return false;
        }

        $cyrillic = preg_match_all('/\p{Cyrillic}/u', $text);
        return ($cyrillic / $letters) > 0.7;
    }

    /**
     * Add the pending label to the entry (before DB insert).
     * Creates the tag automatically if it does not exist.
     */
    private function applyPendingLabel(FreshRSS_Entry $entry): void
    {
        $enableLogging = (bool)$this->getSystemConfigurationValue('enable_logging');
        $labelName = (string)$this->getSystemConfigurationValue('label_pending') ?: FreshExtension_AutoTranslate_Labels::DEFAULT_PENDING;

        $tagDao = FreshRSS_Factory::createTagDao();
        $pendingLabel = null;

        try {
            $pendingLabel = $tagDao->searchByName($labelName);
        } catch (Exception $e) {
            Minz_Log::warning('AutoTranslate: Failed to search pending tag: ' . $e->getMessage());
            return;
        }

        if ($pendingLabel === null) {
            try {
                $tagId = $tagDao->addTag(['name' => $labelName]);
                if ($tagId === false) {
                    Minz_Log::warning('AutoTranslate: Failed to create pending tag');
                    return;
                }
                $pendingLabel = $tagDao->searchByName($labelName);
            } catch (Exception $e) {
                Minz_Log::warning('AutoTranslate: Failed to create pending tag: ' . $e->getMessage());
                return;
            }
        }

        if ($pendingLabel === null) {
            return;
        }

        $currentTagsId = [];
        foreach ($entry->tags() as $tag) {
            if (is_string($tag) && str_starts_with($tag, 't:')) {
                $currentTagsId[] = (int)substr($tag, 2);
            }
        }

        if (!in_array($pendingLabel->id(), $currentTagsId, true)) {
            $currentTagsId[] = $pendingLabel->id();
            $entry->_tags(array_map(fn(int $id): string => 't:' . $id, $currentTagsId));
            if ($enableLogging) {
                Minz_Log::warning(sprintf(
                    'AutoTranslate: Queued entry "%s" (tag id=%d)',
                    self::formatTitleForLog($entry),
                    $pendingLabel->id()
                ));
            }
        }
    }

    /**
     * Title for logs: no broken multibyte UTF-8 characters.
     */
    public static function formatTitleForLog(FreshRSS_Entry $entry): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', (string)$entry->title()) ?? '');
        if (mb_strlen($title) > 150) {
            $title = mb_substr($title, 0, 150) . '…';
        }
        return $title;
    }
}
