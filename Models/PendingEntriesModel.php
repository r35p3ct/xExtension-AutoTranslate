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
     * Add a tag to an entry: to the `tags` column of `_entry` (used by the
     * GReader API and fast filters) AND to the `_entrytag` table (the web UI
     * builds label lists and counters from it). Idempotent.
     */
    public function addTagToEntry(int $tagId, string $entryId): bool
    {
        // Web UI side: _entrytag
        $stm = $this->pdo->prepare('SELECT COUNT(*) AS c FROM `_entrytag` WHERE id_tag = :id_tag AND id_entry = :id_entry');
        if ($stm !== false) {
            $stm->bindValue(':id_tag', $tagId, PDO::PARAM_INT);
            $stm->bindValue(':id_entry', $entryId, PDO::PARAM_STR);
            if ($stm->execute() && is_array($row = $stm->fetch(PDO::FETCH_ASSOC)) && (int)($row['c'] ?? 0) === 0) {
                $ins = $this->pdo->prepare('INSERT INTO `_entrytag` (id_tag, id_entry) VALUES (:id_tag, :id_entry)');
                if ($ins !== false) {
                    $ins->bindValue(':id_tag', $tagId, PDO::PARAM_INT);
                    $ins->bindValue(':id_entry', $entryId, PDO::PARAM_STR);
                    if (!$ins->execute()) {
                        $info = $ins->errorInfo();
                        Minz_Log::warning('AutoTranslate: Failed to insert into _entrytag: ' . json_encode($info));
                    }
                }
            }
        }

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
