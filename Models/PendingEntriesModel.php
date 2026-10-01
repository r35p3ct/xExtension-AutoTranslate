<?php

declare(strict_types=1);

/**
 * Model for entries queued for background translation.
 *
 * IMPORTANT: in the entry_before_add hook the entry has no DB id yet, so the
 * pending label is attached via _tags() and stored in the `tags` column of
 * the `_entry` table only. The `_entrytag` table is NOT filled in that case,
 * therefore lookup and removal work on the `tags` column (and `_entrytag`
 * is also cleaned/checked for tags added manually via the UI).
 */
class FreshExtension_AutoTranslate_PendingEntries_Model extends Minz_ModelPdo
{
    /**
     * Ids of entries carrying the pending label.
     *
     * @param int $pendingTagId id of the pending tag
     * @param int $limit max entries
     * @param array<int, string> $channelsFilter feed ids (empty = all)
     * @return list<string>
     */
    public function getPendingEntryIds(int $pendingTagId, int $limit, array $channelsFilter = []): array
    {
        $tagPattern = '#t:' . $pendingTagId;

        $sql = <<<'SQL'
            SELECT id
            FROM `_entry`
            WHERE (
                tags = ? OR
                tags LIKE ? ESCAPE '\' OR
                tags LIKE ? ESCAPE '\' OR
                tags LIKE ? ESCAPE '\'
            )
        SQL;

        $params = [
            $tagPattern,
            $tagPattern . ' %',
            '% ' . $tagPattern . ' %',
            '% ' . $tagPattern,
        ];

        if (!empty($channelsFilter)) {
            $placeholders = implode(',', array_fill(0, count($channelsFilter), '?'));
            $sql .= " AND id_feed IN ({$placeholders})";
            foreach ($channelsFilter as $feedId) {
                $params[] = (int)$feedId;
            }
        }

        $sql .= ' ORDER BY date DESC';

        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int)$limit;
        }

        $stm = $this->pdo->prepare($sql);
        if ($stm === false || !$stm->execute($params)) {
            return [];
        }

        $entryIds = [];
        while (is_array($row = $stm->fetch(PDO::FETCH_ASSOC))) {
            if (!empty($row['id'])) {
                $entryIds[] = (string)$row['id'];
            }
        }

        return $entryIds;
    }

    /**
     * Whether the entry carries the given tag: in `_entrytag` (UI tagging)
     * or in the `tags` column (hook tagging).
     */
    public function hasTag(int $tagId, string $entryId): bool
    {
        $stm = $this->pdo->prepare('SELECT COUNT(*) AS c FROM `_entrytag` WHERE id_tag = :id_tag AND id_entry = :id_entry');
        if ($stm !== false) {
            $stm->bindValue(':id_tag', $tagId, PDO::PARAM_INT);
            $stm->bindValue(':id_entry', $entryId, PDO::PARAM_STR);
            if ($stm->execute() && is_array($row = $stm->fetch(PDO::FETCH_ASSOC)) && (int)($row['c'] ?? 0) > 0) {
                return true;
            }
        }

        $tags = $this->getEntryTags($entryId);
        if ($tags === null || $tags === '') {
            return false;
        }
        return in_array('#t:' . $tagId, explode(' ', $tags), true);
    }

    /**
     * Whether the entry for the given feed+guid (the hook entry_before_update
     * works with entries that have no DB id yet) carries the pending or the
     * translated label. Checks the entry's id via _entrytag (UI tagging) and
     * the tags column (hook tagging).
     */
    public function hasTranslateLabel(int $feedId, string $guid, int $pendingTagId, int $translatedTagId): bool
    {
        $stm = $this->pdo->prepare('SELECT id FROM `_entry` WHERE id_feed = :id_feed AND guid = :guid LIMIT 1');
        if ($stm === false) {
            return false;
        }
        $stm->bindValue(':id_feed', $feedId, PDO::PARAM_INT);
        $stm->bindValue(':guid', $guid, PDO::PARAM_STR);
        if (!$stm->execute() || !is_array($row = $stm->fetch(PDO::FETCH_ASSOC))) {
            return false;
        }

        $entryId = (string)($row['id'] ?? '');
        if ($entryId === '') {
            return false;
        }

        return $this->hasTag($pendingTagId, $entryId) || $this->hasTag($translatedTagId, $entryId);
    }

    /**
     * Unix timestamp of the entry publication date (0 if unknown).
     */
    public function getEntryDate(string $entryId): int
    {
        $stm = $this->pdo->prepare('SELECT date FROM `_entry` WHERE id = :id');
        if ($stm === false) {
            return 0;
        }
        $stm->bindValue(':id', $entryId, PDO::PARAM_STR);
        if (!$stm->execute() || !is_array($row = $stm->fetch(PDO::FETCH_ASSOC))) {
            return 0;
        }
        return (int)($row['date'] ?? 0);
    }

    /**
     * Replace title and content of an existing entry with their translation.
     */
    public function updateEntryText(string $entryId, string $title, string $content): bool
    {
        $stm = $this->pdo->prepare('UPDATE `_entry` SET title = :title, content = :content WHERE id = :id');
        if ($stm === false) {
            return false;
        }

        if ($stm->bindValue(':title', $title, PDO::PARAM_STR)
            && $stm->bindValue(':content', $content, PDO::PARAM_STR)
            && $stm->bindValue(':id', $entryId, PDO::PARAM_STR)
            && $stm->execute()
        ) {
            return true;
        }

        $info = $stm->errorInfo();
        Minz_Log::warning('AutoTranslate: Failed to update entry text: ' . json_encode($info));
        return false;
    }

    /**
     * Remove a tag from an existing entry: from `_entrytag` (in case it got
     * there via the UI) and from the `tags` column of `_entry`.
     */
    public function removeTagFromEntry(int $tagId, string $entryId): bool
    {
        $stm = $this->pdo->prepare('DELETE FROM `_entrytag` WHERE id_tag = :id_tag AND id_entry = :id_entry');
        if ($stm !== false) {
            $stm->bindValue(':id_tag', $tagId, PDO::PARAM_INT);
            $stm->bindValue(':id_entry', $entryId, PDO::PARAM_STR);
            $stm->execute();
        }

        $currentTags = $this->getEntryTags($entryId);
        if ($currentTags === null || $currentTags === '') {
            return true;
        }

        $tagPattern = '#t:' . $tagId;
        $newParts = [];
        foreach (explode(' ', $currentTags) as $part) {
            if ($part !== '' && $part !== $tagPattern) {
                $newParts[] = $part;
            }
        }
        $newTags = implode(' ', $newParts);

        if ($newTags === $currentTags) {
            return true;
        }

        return $this->setEntryTags($entryId, $newTags);
    }

    /**
     * Queue already-received articles of the given feeds (used when new feeds
     * are added to the translation list, so they are translated too and not
     * only the new arrivals). Entries already carrying the pending or the
     * translated tag are skipped, as well as entries that are already in the
     * target language.
     *
     * @param int $pendingTagId id of the pending tag
     * @param int|null $translatedTagId id of the translated tag (null = unknown)
     * @param array<int, string|int> $feedIds feed ids
     * @param string $targetLang target language code
     * @return int number of newly queued entries
     */
    public function queueExistingEntries(int $pendingTagId, ?int $translatedTagId, array $feedIds, string $targetLang = 'en'): int
    {
        if (empty($feedIds)) {
            return 0;
        }

        $pendingPattern = '#t:' . $pendingTagId;
        $translatedPattern = $translatedTagId !== null ? '#t:' . $translatedTagId : null;
        $marked = 0;
        $lastId = PHP_INT_MAX;

        while (true) {
            $placeholders = implode(',', array_fill(0, count($feedIds), '?'));
            $sql = "SELECT id, title, content, tags FROM `_entry`
                    WHERE id_feed IN ($placeholders) AND id < ?
                    ORDER BY id DESC LIMIT 300";
            $params = array_map(static fn($f): int => (int)$f, $feedIds);
            $params[] = $lastId;

            $stm = $this->pdo->prepare($sql);
            if ($stm === false || !$stm->execute($params)) {
                break;
            }

            $rows = $stm->fetchAll(PDO::FETCH_ASSOC);
            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $lastId = (int)$row['id'];
                $entryId = (string)($row['id'] ?? '');
                if ($entryId === '') {
                    continue;
                }
                $parts = ($row['tags'] ?? '') === '' ? [] : explode(' ', (string)$row['tags']);
                if (in_array($pendingPattern, $parts, true)) {
                    continue;
                }
                if ($translatedPattern !== null && in_array($translatedPattern, $parts, true)) {
                    continue;
                }
                if (FreshExtension_AutoTranslate_Controller::isLikelyInTargetText(
                    (string)($row['title'] ?? ''),
                    (string)($row['content'] ?? ''),
                    $targetLang
                )) {
                    continue;
                }
                $parts[] = $pendingPattern;
                if (!$this->setEntryTags($entryId, implode(' ', $parts))) {
                    continue;
                }
                $this->ensureEntrytagRow($pendingTagId, $entryId);
                $marked++;
            }

            if (count($rows) < 300) {
                break;
            }
        }

        return $marked;
    }

    /**
     * Remove the pending tag from all queued entries of the given feeds
     * (used when feeds are removed from the translation list).
     *
     * @param int $pendingTagId id of the pending tag
     * @param array<int, string|int> $feedIds feed ids
     * @return int number of dequeued entries
     */
    public function dequeueFeeds(int $pendingTagId, array $feedIds): int
    {
        if (empty($feedIds)) {
            return 0;
        }

        $pendingPattern = '#t:' . $pendingTagId;
        $removed = 0;
        $lastId = PHP_INT_MAX;

        while (true) {
            $placeholders = implode(',', array_fill(0, count($feedIds), '?'));
            $sql = "SELECT id FROM `_entry`
                    WHERE id_feed IN ($placeholders) AND id < ? AND (tags = ? OR tags LIKE ? OR tags LIKE ? OR tags LIKE ?)
                    ORDER BY id DESC LIMIT 300";
            $params = array_map(static fn($f): int => (int)$f, $feedIds);
            $params[] = $lastId;
            $params[] = $pendingPattern;
            $params[] = $pendingPattern . ' %';
            $params[] = '% ' . $pendingPattern . ' %';
            $params[] = '% ' . $pendingPattern;

            $stm = $this->pdo->prepare($sql);
            if ($stm === false || !$stm->execute($params)) {
                break;
            }

            $rows = $stm->fetchAll(PDO::FETCH_ASSOC);
            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $lastId = (int)$row['id'];
                $entryId = (string)($row['id'] ?? '');
                if ($entryId === '') {
                    continue;
                }
                if ($this->removeTagFromEntry($pendingTagId, $entryId)) {
                    $removed++;
                }
            }

            if (count($rows) < 300) {
                break;
            }
        }

        return $removed;
    }

    /**
     * Remove the pending tag from queued entries that are already in the
     * target language (no translation needed).
     *
     * @param int $pendingTagId id of the pending tag
     * @param string $targetLang target language code
     * @param array<int, string|int> $feedIds feed ids
     * @return int number of dequeued entries
     */
    public function dequeueEntriesInTargetLanguage(int $pendingTagId, string $targetLang, array $feedIds): int
    {
        if (empty($feedIds)) {
            return 0;
        }

        $pendingPattern = '#t:' . $pendingTagId;
        $removed = 0;
        $lastId = PHP_INT_MAX;

        while (true) {
            $placeholders = implode(',', array_fill(0, count($feedIds), '?'));
            $sql = "SELECT id, title, content FROM `_entry`
                    WHERE id_feed IN ($placeholders) AND id < ? AND (tags = ? OR tags LIKE ? OR tags LIKE ? OR tags LIKE ?)
                    ORDER BY id DESC LIMIT 300";
            $params = array_map(static fn($f): int => (int)$f, $feedIds);
            $params[] = $lastId;
            $params[] = $pendingPattern;
            $params[] = $pendingPattern . ' %';
            $params[] = '% ' . $pendingPattern . ' %';
            $params[] = '% ' . $pendingPattern;

            $stm = $this->pdo->prepare($sql);
            if ($stm === false || !$stm->execute($params)) {
                break;
            }

            $rows = $stm->fetchAll(PDO::FETCH_ASSOC);
            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $lastId = (int)$row['id'];
                $entryId = (string)($row['id'] ?? '');
                if ($entryId === '') {
                    continue;
                }
                if (FreshExtension_AutoTranslate_Controller::isLikelyInTargetText(
                    (string)($row['title'] ?? ''),
                    (string)($row['content'] ?? ''),
                    $targetLang
                )) {
                    if ($this->removeTagFromEntry($pendingTagId, $entryId)) {
                        $removed++;
                    }
                }
            }

            if (count($rows) < 300) {
                break;
            }
        }

        return $removed;
    }

    /**
     * Requeue entries whose translation was wiped by a feed update.
     *
     * Fingerprint of a wiped entry: the translated label row still exists in
     * `_entrytag` (updateEntry() does not touch that table), but the `tags`
     * column of `_entry` lost the #t:<translated> marker (updateEntry()
     * rewrites the tags column with feed-provided tags). Such entries get the
     * translated label swapped for the pending one, so the next worker run
     * translates them again.
     *
     * @param int $pendingTagId id of the pending tag
     * @param int $translatedTagId id of the translated tag
     * @param array<int, string|int> $feedIds feed ids (empty = none)
     * @return int number of requeued entries
     */
    public function requeueRevertedEntries(int $pendingTagId, int $translatedTagId, array $feedIds): int
    {
        if (empty($feedIds) || $pendingTagId <= 0 || $translatedTagId <= 0) {
            return 0;
        }

        $translatedPattern = '#t:' . $translatedTagId;
        $requeued = 0;
        $lastId = PHP_INT_MAX;

        while (true) {
            $placeholders = implode(',', array_fill(0, count($feedIds), '?'));
            $sql = "SELECT e.id FROM `_entry` e
                    JOIN `_entrytag` et ON et.id_entry = e.id AND et.id_tag = ?
                    WHERE e.id_feed IN ($placeholders) AND e.id < ?
                      AND NOT (e.tags = ? OR e.tags LIKE ? ESCAPE '\\' OR e.tags LIKE ? ESCAPE '\\' OR e.tags LIKE ? ESCAPE '\\')
                    ORDER BY e.id DESC LIMIT 300";
            $params = [$translatedTagId];
            foreach ($feedIds as $feedId) {
                $params[] = (int)$feedId;
            }
            $params[] = $lastId;
            $params[] = $translatedPattern;
            $params[] = $translatedPattern . ' %';
            $params[] = '% ' . $translatedPattern . ' %';
            $params[] = '% ' . $translatedPattern;

            $stm = $this->pdo->prepare($sql);
            if ($stm === false || !$stm->execute($params)) {
                break;
            }

            $rows = $stm->fetchAll(PDO::FETCH_ASSOC);
            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $entryId = (string)($row['id'] ?? '');
                if ($entryId === '') {
                    continue;
                }
                $lastId = (int)$entryId;
                if ($this->removeTagFromEntry($translatedTagId, $entryId) && $this->addTagToEntry($pendingTagId, $entryId)) {
                    $requeued++;
                }
            }

            if (count($rows) < 300) {
                break;
            }
        }

        return $requeued;
    }

    /**
     * Add a tag to an entry: to the `tags` column of `_entry` (used by the
     * GReader API and fast filters) AND to the `_entrytag` table (the web UI
     * builds label lists and counters from it). Idempotent.
     */
    public function addTagToEntry(int $tagId, string $entryId): bool
    {
        $this->ensureEntrytagRow($tagId, $entryId);

        // GReader API side: tags column of _entry
        $currentTags = $this->getEntryTags($entryId);
        if ($currentTags === null) {
            return false;
        }

        $tagPattern = '#t:' . $tagId;
        if ($currentTags === '') {
            return $this->setEntryTags($entryId, $tagPattern);
        }

        $parts = explode(' ', $currentTags);
        if (in_array($tagPattern, $parts, true)) {
            return true;
        }
        $parts[] = $tagPattern;

        return $this->setEntryTags($entryId, implode(' ', $parts));
    }

    /**
     * Make sure the `_entrytag` table has the (tag, entry) row — the web UI
     * builds label lists and counters from this table.
     */
    private function ensureEntrytagRow(int $tagId, string $entryId): void
    {
        $stm = $this->pdo->prepare('SELECT COUNT(*) AS c FROM `_entrytag` WHERE id_tag = :id_tag AND id_entry = :id_entry');
        if ($stm === false) {
            return;
        }
        $stm->bindValue(':id_tag', $tagId, PDO::PARAM_INT);
        $stm->bindValue(':id_entry', $entryId, PDO::PARAM_STR);
        if (!$stm->execute() || !is_array($row = $stm->fetch(PDO::FETCH_ASSOC)) || (int)($row['c'] ?? 0) > 0) {
            return;
        }

        $ins = $this->pdo->prepare('INSERT INTO `_entrytag` (id_tag, id_entry) VALUES (:id_tag, :id_entry)');
        if ($ins === false) {
            return;
        }
        $ins->bindValue(':id_tag', $tagId, PDO::PARAM_INT);
        $ins->bindValue(':id_entry', $entryId, PDO::PARAM_STR);
        if (!$ins->execute()) {
            $info = $ins->errorInfo();
            Minz_Log::warning('AutoTranslate: Failed to insert into _entrytag: ' . json_encode($info));
        }
    }

    private function setEntryTags(string $entryId, string $tags): bool
    {
        $stm = $this->pdo->prepare('UPDATE `_entry` SET tags = :tags WHERE id = :id');
        if ($stm === false) {
            return false;
        }

        if ($stm->bindValue(':tags', $tags, PDO::PARAM_STR)
            && $stm->bindValue(':id', $entryId, PDO::PARAM_STR)
            && $stm->execute()
        ) {
            return true;
        }

        $info = $stm->errorInfo();
        Minz_Log::warning('AutoTranslate: Failed to update entry tags: ' . json_encode($info));
        return false;
    }

    private function getEntryTags(string $entryId): ?string
    {
        $stm = $this->pdo->prepare('SELECT tags FROM `_entry` WHERE id = :id');
        if ($stm === false) {
            return null;
        }
        $stm->bindValue(':id', $entryId, PDO::PARAM_STR);
        if (!$stm->execute() || !is_array($row = $stm->fetch(PDO::FETCH_ASSOC))) {
            return null;
        }
        return $row['tags'] ?? null;
    }
}
