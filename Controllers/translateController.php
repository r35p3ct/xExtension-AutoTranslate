<?php

declare(strict_types=1);

require_once __DIR__ . '/../Labels.php';
require_once __DIR__ . '/../Models/PendingEntriesModel.php';

/**
 * Translation worker for the AutoTranslate extension.
 *
 * Engines:
 *   - google: free public Google Translate endpoint (no API key). HTML content
 *     is split into chunks at </p> boundaries and translated piece by piece.
 *   - llm:    any OpenRouter chat model (recommended: openai/gpt-4o-mini).
 *     Translates title + HTML content in one request and preserves markup.
 *
 * processPendingEntries() is called by scripts/process_pending.php (cron):
 * it takes entries carrying the pending label, replaces their title/content
 * with the translation and swaps the pending label for the translated one.
 */
class FreshExtension_AutoTranslate_Controller extends FreshRSS_ActionController
{
    private const GOOGLE_ENDPOINT   = 'https://translate.googleapis.com/translate_a/single';
    private const GOOGLE_ALT_ENDPOINT = 'https://clients5.google.com/translate_a/t';
    private const GOOGLE_CHUNK_SIZE = 4000;
    private const MAX_LOG_TITLE     = 150;

    /**
     * Human-readable language names for the LLM prompt.
     */
    private const LANGUAGE_NAMES = [
        'en' => 'English', 'ru' => 'Russian', 'uk' => 'Ukrainian', 'be' => 'Belarusian',
        'de' => 'German', 'fr' => 'French', 'es' => 'Spanish', 'it' => 'Italian',
        'pt' => 'Portuguese', 'pl' => 'Polish', 'nl' => 'Dutch', 'cs' => 'Czech',
        'sk' => 'Slovak', 'sv' => 'Swedish', 'da' => 'Danish', 'no' => 'Norwegian',
        'fi' => 'Finnish', 'et' => 'Estonian', 'lv' => 'Latvian', 'lt' => 'Lithuanian',
        'tr' => 'Turkish', 'ar' => 'Arabic', 'he' => 'Hebrew', 'fa' => 'Persian',
        'zh-cn' => 'Simplified Chinese', 'zh-tw' => 'Traditional Chinese',
        'ja' => 'Japanese', 'ko' => 'Korean', 'hi' => 'Hindi', 'th' => 'Thai',
        'id' => 'Indonesian', 'vi' => 'Vietnamese', 'ro' => 'Romanian',
        'el' => 'Greek', 'hu' => 'Hungarian', 'bg' => 'Bulgarian', 'sr' => 'Serbian',
        'hr' => 'Croatian', 'kk' => 'Kazakh', 'uz' => 'Uzbek', 'hy' => 'Armenian',
        'ka' => 'Georgian',
    ];

    private string $engine;
    private string $targetLang;
    private string $labelPending;
    private string $labelTranslated;
    private string $labelAdvertisement;
    private bool   $skipAds;
    private int    $waitAdsMinutes;
    private int    $requestDelayMs;
    private bool   $enableLogging;
    private string $llmApiKey;
    private string $llmModel;
    private int    $maxContentChars;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->engine            = ($config['engine'] ?? 'google') === 'llm' ? 'llm' : 'google';
        $this->targetLang        = strtolower(trim((string)($config['target_lang'] ?? 'en'))) ?: 'en';
        $this->labelPending      = trim((string)($config['label_pending'] ?? '')) ?: FreshExtension_AutoTranslate_Labels::DEFAULT_PENDING;
        $this->labelTranslated   = trim((string)($config['label_translated'] ?? '')) ?: FreshExtension_AutoTranslate_Labels::DEFAULT_TRANSLATED;
        $this->labelAdvertisement = trim((string)($config['label_advertisement'] ?? '')) ?: FreshExtension_AutoTranslate_Labels::DEFAULT_ADVERTISEMENT;
        $this->skipAds           = !empty($config['skip_ads']);
        $this->waitAdsMinutes    = max(0, (int)($config['wait_ads_minutes'] ?? 10));
        $this->requestDelayMs    = max(0, (int)($config['request_delay_ms'] ?? 250));
        $this->enableLogging     = !empty($config['enable_logging']);
        $this->llmApiKey         = (string)($config['llm_api_key'] ?? '');
        $this->llmModel          = trim((string)($config['llm_model'] ?? '')) ?: 'openai/gpt-4o-mini';
        $this->maxContentChars   = max(500, (int)($config['max_content_chars'] ?? 6000));

        parent::__construct();
    }

    // -------------------------------------------------------------------------
    // Background processing of entries with the pending label
    // -------------------------------------------------------------------------

    /**
     * Translate entries with the pending label and swap labels afterwards.
     *
     * @param int $limit max entries per run
     * @param int $delayMs delay between translation requests
     * @param array<int, string> $channelsFilter feed ids (empty = all)
     * @return array{processed: int, skipped: int, errors: int, details: array}
     */
    public function processPendingEntries(int $limit, int $delayMs, array $channelsFilter = []): array
    {
        $result = ['processed' => 0, 'skipped' => 0, 'errors' => 0, 'details' => []];

        if ($this->engine === 'llm' && $this->llmApiKey === '') {
            Minz_Log::warning('AutoTranslate: LLM engine selected but no API key configured, skipping run');
            return $result;
        }

        $tagDao = FreshRSS_Factory::createTagDao();

        $pendingTag = $this->ensureTag($tagDao, $this->labelPending);
        if ($pendingTag === null) {
            Minz_Log::warning('AutoTranslate: Pending tag not available, skipping run');
            return $result;
        }

        $translatedTag = $this->ensureTag($tagDao, $this->labelTranslated);
        $adsTag = null;
        try {
            $adsTag = $tagDao->searchByName($this->labelAdvertisement);
        } catch (Exception $e) {
            Minz_Log::warning('AutoTranslate: Failed to search advertisement tag: ' . $e->getMessage());
        }

        $model = new FreshExtension_AutoTranslate_PendingEntries_Model();
        $entryIds = $model->getPendingEntryIds((int)$pendingTag->id(), $limit, $channelsFilter);

        if ($this->enableLogging) {
            Minz_Log::warning(sprintf(
                'AutoTranslate: Found %d pending entries (tag_id=%d, engine=%s, limit=%d, channels=%s)',
                count($entryIds),
                $pendingTag->id(),
                $this->engine,
                $limit,
                empty($channelsFilter) ? 'all' : implode(',', $channelsFilter)
            ));
        }

        if (empty($entryIds)) {
            return $result;
        }

        $entryDao = FreshRSS_Factory::createEntryDao();

        foreach ($entryIds as $entryId) {
            $entry = $entryDao->searchById($entryId);
            if (!$entry) {
                $result['errors']++;
                $result['details'][] = ['entry_id' => $entryId, 'error' => 'Entry not found'];
                continue;
            }

            // Make the pending entry visible in the web UI label section
            // (the hook only writes the tags column, _entrytag needs a row too)
            $model->addTagToEntry((int)$pendingTag->id(), $entryId);

            // Skip entries already labelled as advertisement by the ad filter
            if ($this->skipAds && $adsTag !== null && $model->hasTag((int)$adsTag->id(), $entryId)) {
                $model->removeTagFromEntry((int)$pendingTag->id(), $entryId);
                $result['skipped']++;
                $result['details'][] = ['entry_id' => $entryId, 'status' => 'skipped_ad'];
                if ($this->enableLogging) {
                    Minz_Log::warning('AutoTranslate: Skipped advertisement entry_id=' . $entryId);
                }
                continue;
            }

            // Give the ad filter time to label fresh entries before translating them
            if ($this->skipAds && $this->waitAdsMinutes > 0) {
                $entryDate = $model->getEntryDate($entryId);
                if ($entryDate > 0 && (time() - $entryDate) < $this->waitAdsMinutes * 60) {
                    $result['details'][] = ['entry_id' => $entryId, 'status' => 'deferred'];
                    continue;
                }
            }

            $title = (string)$entry->title();
            $content = (string)$entry->content();

            try {
                if ($this->engine === 'llm') {
                    $translation = $this->translateWithLlm($title, $content);
                } else {
                    $translation = $this->translateWithGoogle($title, $content);
                }
            } catch (Throwable $e) {
                $result['errors']++;
                $result['details'][] = ['entry_id' => $entryId, 'error' => $e->getMessage()];
                if ($this->enableLogging) {
                    Minz_Log::warning(sprintf(
                        'AutoTranslate: Failed entry_id=%s error="%s"',
                        $entryId,
                        $e->getMessage()
                    ));
                }
                // Keep the pending label so the entry is retried on the next run
                continue;
            }

            // Already in the target language: just swap the labels
            if ($translation === null) {
                $model->removeTagFromEntry((int)$pendingTag->id(), $entryId);
                if ($translatedTag !== null) {
                    $model->addTagToEntry((int)$translatedTag->id(), $entryId);
                }
                $result['skipped']++;
                $result['details'][] = ['entry_id' => $entryId, 'status' => 'already_in_target'];
                continue;
            }

            if (!$model->updateEntryText($entryId, $translation['title'], $translation['content'])) {
                $result['errors']++;
                $result['details'][] = ['entry_id' => $entryId, 'error' => 'Failed to update entry in database'];
                continue;
            }

            $model->removeTagFromEntry((int)$pendingTag->id(), $entryId);
            if ($translatedTag !== null) {
                $model->addTagToEntry((int)$translatedTag->id(), $entryId);
            }

            $result['processed']++;
            $result['details'][] = ['entry_id' => $entryId, 'status' => 'translated'];

            if ($this->enableLogging) {
                Minz_Log::warning(sprintf(
                    'AutoTranslate: Translated entry_id=%s title="%s"',
                    $entryId,
                    self::formatTitleForLog($translation['title'])
                ));
            }

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        if ($this->enableLogging) {
            Minz_Log::warning(sprintf(
                'AutoTranslate: Run done — processed=%d, skipped=%d, errors=%d',
                $result['processed'],
                $result['skipped'],
                $result['errors']
            ));
        }

        return $result;
    }

    /**
     * Find a tag by name, creating it when missing. Null on failure.
     */
    private function ensureTag(FreshRSS_TagDAO $tagDao, string $name): ?FreshRSS_Tag
    {
        try {
            $tag = $tagDao->searchByName($name);
            if ($tag !== null) {
                return $tag;
            }
            if ($tagDao->addTag(['name' => $name]) === false) {
                return null;
            }
            return $tagDao->searchByName($name);
        } catch (Exception $e) {
            Minz_Log::warning('AutoTranslate: Tag operation failed for "' . $name . '": ' . $e->getMessage());
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Google engine
    // -------------------------------------------------------------------------

    /**
     * @return array{title: string, content: string}|null null = already in the target language
     */
    private function translateWithGoogle(string $title, string $content): ?array
    {
        [$titleTr, $srcTitle] = $this->googleTranslateChunk($title);
        [$contentTr, $srcContent] = $this->googleTranslateHtml($content);

        $src = $srcContent !== '' ? $srcContent : $srcTitle;
        if ($src !== '' && strtolower($src) === $this->targetLang) {
            return null;
        }

        return ['title' => $titleTr, 'content' => $contentTr];
    }

    /**
     * Translate HTML content preserving markup: Google returns plain text for
     * `dt=t`, so the content is split into chunks at </p> boundaries — every
     * chunk keeps whole paragraphs and survives the round-trip.
     *
     * @return array{0: string, 1: string} [translated html, detected source lang]
     */
    private function googleTranslateHtml(string $html): array
    {
        if (trim($html) === '') {
            return ['', ''];
        }

        $chunks = $this->chunkHtml($html);
        $out = '';
        $src = '';

        foreach ($chunks as $i => $chunk) {
            [$translated, $chunkSrc] = $this->googleTranslateChunk($chunk);
            $out .= $translated;
            if ($src === '' && $chunkSrc !== '') {
                $src = $chunkSrc;
            }
            if ($i < count($chunks) - 1 && $this->requestDelayMs > 0) {
                usleep($this->requestDelayMs * 1000);
            }
        }

        return [$out, $src];
    }

    /**
     * Split HTML into chunks no longer than GOOGLE_CHUNK_SIZE, preferably
     * at </p> boundaries so tags are not cut in half.
     *
     * @return list<string>
     */
    private function chunkHtml(string $html): array
    {
        if (mb_strlen($html) <= self::GOOGLE_CHUNK_SIZE) {
            return [$html];
        }

        $parts = preg_split('/(?<=<\/p>)/iu', $html) ?: [$html];
        $chunks = [];
        $current = '';

        foreach ($parts as $part) {
            while (mb_strlen($part) > self::GOOGLE_CHUNK_SIZE) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }
                $chunks[] = mb_substr($part, 0, self::GOOGLE_CHUNK_SIZE);
                $part = mb_substr($part, self::GOOGLE_CHUNK_SIZE);
            }
            if ($current !== '' && mb_strlen($current . $part) > self::GOOGLE_CHUNK_SIZE) {
                $chunks[] = $current;
                $current = '';
            }
            $current .= $part;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * One request to the free Google Translate endpoint.
     *
     * @return array{0: string, 1: string} [translated text, detected source lang]
     */
    private function googleTranslateChunk(string $text): array
    {
        if (trim($text) === '') {
            return ['', ''];
        }

        $maxRetries = 2;
        $attempt = 0;

        while (true) {
            $attempt++;

            $response = $this->googleTranslateOnce($text);

            if ($response['success'] || ($response['http_code'] ?? 0) !== 429) {
                break;
            }

            if ($attempt <= $maxRetries) {
                usleep(random_int(1000, 3000) * 1000);
            } else {
                break;
            }
        }

        if (!$response['success']) {
            throw new RuntimeException($response['error'] ?? 'Google Translate request failed');
        }

        return [$response['translated'], $response['src']];
    }

    /**
     * One translation attempt: the public endpoint first, and if it is
     * rate-limited for this IP (HTTP 429 happens on VPS ranges) — the
     * dict-chrome-ex fallback host, which also preserves HTML tags.
     *
     * @return array{success: bool, translated?: string, src?: string, error?: string, http_code?: int}
     */
    private function googleTranslateOnce(string $text): array
    {
        $result = $this->googleGet(
            self::GOOGLE_ENDPOINT . '?' . http_build_query(
                ['client' => 'gtx', 'sl' => 'auto', 'tl' => $this->targetLang, 'dt' => 't', 'q' => $text],
                '',
                '&',
                PHP_QUERY_RFC3986
            ),
            [$this, 'parseGoogleSingle']
        );

        if ($result['success']) {
            return $result;
        }

        return $this->googleGet(
            self::GOOGLE_ALT_ENDPOINT . '?' . http_build_query(
                ['client' => 'dict-chrome-ex', 'sl' => 'auto', 'tl' => $this->targetLang, 'q' => $text],
                '',
                '&',
                PHP_QUERY_RFC3986
            ),
            [$this, 'parseGoogleDict']
        );
    }

    /**
     * GET request to a Google endpoint + response parsing.
     *
     * @param callable $parser fn(string $body): ?array{translated: string, src: string}
     * @return array{success: bool, translated?: string, src?: string, error?: string, http_code?: int}
     */
    private function googleGet(string $url, callable $parser): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            if ($this->enableLogging) {
                Minz_Log::warning('AutoTranslate: CURL error (Google): ' . $error);
            }
            return ['success' => false, 'error' => 'CURL error: ' . $error];
        }

        if ($httpCode !== 200) {
            if ($this->enableLogging) {
                Minz_Log::warning('AutoTranslate: Google HTTP ' . $httpCode);
            }
            return ['success' => false, 'error' => 'Google Translate HTTP ' . $httpCode, 'http_code' => $httpCode];
        }

        $parsed = $parser((string)$response);
        if ($parsed === null || $parsed['translated'] === '') {
            if ($this->enableLogging) {
                Minz_Log::warning('AutoTranslate: Unexpected Google response: ' . substr((string)$response, 0, 200));
            }
            return ['success' => false, 'error' => 'Unexpected Google Translate response'];
        }

        return ['success' => true, 'translated' => $parsed['translated'], 'src' => $parsed['src']];
    }

    /**
     * Parser for translate_a/single?client=gtx&dt=t: nested segment arrays.
     */
    public function parseGoogleSingle(string $response): ?array
    {
        $json = json_decode($response, true);
        if (!is_array($json) || !isset($json[0]) || !is_array($json[0])) {
            return null;
        }

        $translated = '';
        foreach ($json[0] as $segment) {
            if (is_array($segment) && isset($segment[0]) && is_string($segment[0])) {
                $translated .= $segment[0];
            }
        }

        $src = is_string($json[2] ?? null) ? strtolower($json[2]) : '';
        return ['translated' => $translated, 'src' => $src];
    }

    /**
     * Parser for translate_a/t?client=dict-chrome-ex: [["translated","src"]],
     * HTML markup is preserved by this endpoint.
     */
    public function parseGoogleDict(string $response): ?array
    {
        $json = json_decode($response, true);
        if (is_array($json) && isset($json[0][0]) && is_string($json[0][0])) {
            $src = isset($json[0][1]) && is_string($json[0][1]) ? strtolower($json[0][1]) : '';
            return ['translated' => $json[0][0], 'src' => $src];
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // LLM engine (OpenRouter)
    // -------------------------------------------------------------------------

    /**
     * @return array{title: string, content: string}|null null = already in the target language
     */
    private function translateWithLlm(string $title, string $content): ?array
    {
        if (mb_strlen($content) > $this->maxContentChars) {
            $content = mb_substr($content, 0, $this->maxContentChars) . '…';
        }

        $langName = self::LANGUAGE_NAMES[$this->targetLang] ?? $this->targetLang;

        $prompt = "You are a professional translator working for an RSS reader.\n"
            . "Translate the following RSS entry into {$langName}.\n"
            . "Rules:\n"
            . "- Preserve the HTML markup of the content exactly: keep all tags (<p>, <a>, <b>, <i>, <blockquote>, <img> and so on),\n"
            . "  their attributes and URLs (href/src) unchanged. Translate only human-readable text.\n"
            . "- Do not add explanations, notes or extra markup.\n"
            . "- If the title and content are already in {$langName}, set already_in_target to true and copy both texts unchanged.\n"
            . "Return ONLY a JSON object without markdown or explanations:\n"
            . '{"title": "translated title", "content": "translated HTML content", "already_in_target": false}' . "\n\n"
            . "TITLE:\n{$title}\n\nCONTENT:\n{$content}";

        $response = $this->callOpenRouter($prompt);

        if (!$response['success']) {
            throw new RuntimeException($response['error'] ?? 'LLM request failed');
        }

        $json = $this->parseJsonResponse((string)($response['content'] ?? ''));

        if (!is_array($json) || !isset($json['title'], $json['content'])) {
            throw new RuntimeException('Invalid LLM response: ' . substr((string)($response['content'] ?? ''), 0, 200));
        }

        if (!empty($json['already_in_target'])) {
            return null;
        }

        return ['title' => (string)$json['title'], 'content' => (string)$json['content']];
    }

    /**
     * Call OpenRouter chat/completions with retries on rate limit.
     *
     * @return array{success: bool, content?: string, error?: string, http_code?: int}
     */
    private function callOpenRouter(string $prompt): array
    {
        $maxRetries = 2;
        $attempt = 0;

        while (true) {
            $attempt++;

            $result = $this->callOpenRouterOnce($prompt);

            if ($result['success'] || ($result['http_code'] ?? 0) !== 429) {
                return $result;
            }

            if ($attempt <= $maxRetries) {
                usleep(random_int(1000, 3000) * 1000);
            } else {
                return $result;
            }
        }
    }

    /**
     * @return array{success: bool, content?: string, error?: string, http_code?: int}
     */
    private function callOpenRouterOnce(string $prompt): array
    {
        $url = 'https://openrouter.ai/api/v1/chat/completions';
        $headers = [
            'Authorization: Bearer ' . $this->llmApiKey,
            'Content-Type: application/json',
            'HTTP-Referer: ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
            'X-Title: FreshRSS-AutoTranslate',
        ];
        $data = [
            'model'       => $this->llmModel,
            'temperature' => 0.2,
            'messages'    => [['role' => 'user', 'content' => $prompt]],
        ];

        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($payload === false) {
            return ['success' => false, 'error' => 'JSON encode error: ' . json_last_error_msg()];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 60,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            if ($this->enableLogging) {
                Minz_Log::warning('AutoTranslate: CURL error (LLM): ' . $error);
            }
            return ['success' => false, 'error' => 'CURL error: ' . $error];
        }

        if ($httpCode !== 200) {
            if ($this->enableLogging) {
                Minz_Log::warning('AutoTranslate: LLM HTTP ' . $httpCode . ' — ' . substr((string)$response, 0, 200));
            }
            return ['success' => false, 'error' => 'LLM HTTP ' . $httpCode, 'http_code' => $httpCode];
        }

        $decoded = json_decode((string)$response, true);
        if (!isset($decoded['choices'][0]['message']['content'])) {
            return ['success' => false, 'error' => 'Invalid LLM API response'];
        }

        return ['success' => true, 'content' => $decoded['choices'][0]['message']['content']];
    }

    /**
     * Extract a JSON object from the model output: strips <think> blocks and
     * markdown fences, falls back to the first {...} block in the text.
     */
    private function parseJsonResponse(string $content): ?array
    {
        $cleaned = preg_replace('/<think>.*?<\/think>/is', '', $content) ?? $content;
        $cleaned = preg_replace('/^\s*```(?:json)?\s*|\s*```\s*$/i', '', $cleaned) ?? $cleaned;
        $cleaned = trim($cleaned);

        $json = json_decode($cleaned, true);

        if (!is_array($json) && preg_match('/\{.*\}/s', $cleaned, $matches)) {
            $json = json_decode($matches[0], true);
        }

        return is_array($json) ? $json : null;
    }

    private static function formatTitleForLog(string $title): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? '');
        if (mb_strlen($title) > self::MAX_LOG_TITLE) {
            $title = mb_substr($title, 0, self::MAX_LOG_TITLE) . '…';
        }
        return $title;
    }
}
