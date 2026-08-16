<?php
declare(strict_types=1);

/**
 * Integrity service (sections 6, 31).
 * Builds a hash-linked chain over records so that modification of a
 * historical record is detectable: the next record's previous-hash fails.
 *
 * The chain anchors in the database (integrity_records table) and can be
 * exported and verified externally by the C utility (c/integrity/chain).
 */
final class IntegrityService
{
    public static function hashRecord(string $recordType, string $recordId, string $payload, ?string $previousHash): string
    {
        return hash('sha256', $recordType . '|' . $recordId . '|' . $payload . '|' . ($previousHash ?? 'GENESIS'));
    }

    /** Appends a record to the named chain, linking to the previous entry. */
    public static function append(string $chainName, string $recordType, string $recordId, string $payload): string
    {
        $db = App::db();
        $last = $db->fetchOne(
            'SELECT record_hash, anchor_hash FROM integrity_records WHERE chain_name = ? ORDER BY id DESC LIMIT 1',
            [$chainName]
        );
        $previous = $last['record_hash'] ?? null;
        // anchor_0 = GENESIS marker; anchor_n = hash(anchor_{n-1} . hash_n)
        $anchor = $last['anchor_hash'] ?? hash('sha256', 'GENESIS:' . $chainName);
        $recordHash = self::hashRecord($recordType, $recordId, $payload, $previous);
        $anchorHash = hash('sha256', $anchor . $recordHash);
        $db->insert('integrity_records', [
            'chain_name' => $chainName,
            'record_type' => $recordType,
            'record_id' => $recordId,
            'record_hash' => $recordHash,
            'previous_hash' => $previous,
            'anchor_hash' => $anchorHash,
            'created_at' => App::now(),
        ]);
        return $recordHash;
    }

    /** Canonical payload for a row (sorted key=value pairs, stable encoding). */
    public static function canonicalPayload(array $row, array $exclude = []): string
    {
        $copy = $row;
        foreach ($exclude as $key) {
            unset($copy[$key]);
        }
        ksort($copy);
        $parts = [];
        foreach ($copy as $k => $v) {
            $parts[] = $k . '=' . (is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_SLASHES));
        }
        return implode('|', $parts);
    }

    /**
     * Verifies an entire chain. Returns ['valid' => bool, 'problems' => [...]].
     * Detects: broken previous-hash links, missing entries, tampered anchors.
     */
    public static function verifyChain(string $chainName): array
    {
        $rows = App::db()->fetchAll(
            'SELECT * FROM integrity_records WHERE chain_name = ? ORDER BY id ASC',
            [$chainName]
        );
        $problems = [];
        $prevHash = null;
        $prevAnchorHash = null;
        foreach ($rows as $row) {
            if ($row['previous_hash'] !== $prevHash) {
                $problems[] = sprintf(
                    'Broken link at %s#%s: expected previous %s, found %s',
                    $row['record_type'], $row['record_id'],
                    $prevHash === null ? 'NULL' : substr((string)$prevHash, 0, 12),
                    $row['previous_hash'] === null ? 'NULL' : substr((string)$row['previous_hash'], 0, 12)
                );
            }
            // anchor_n = hash(anchor_{n-1} . hash_n), with anchor_0 = hash("GENESIS:" + chain)
            $aPrev = $prevAnchorHash === null ? hash('sha256', 'GENESIS:' . $chainName) : $prevAnchorHash;
            $expectedAnchor = hash('sha256', $aPrev . (string)$row['record_hash']);
            if ($row['anchor_hash'] !== $expectedAnchor) {
                $problems[] = sprintf(
                    'Anchor mismatch at %s#%s: expected %s, found %s',
                    $row['record_type'], $row['record_id'],
                    substr($expectedAnchor, 0, 12), substr((string)$row['anchor_hash'], 0, 12)
                );
            }
            $prevHash = $row['record_hash'];
            $prevAnchorHash = $row['anchor_hash'];
        }
        return ['valid' => count($problems) === 0, 'problems' => $problems, 'entries' => count($rows)];
    }

    /** Verifies that the stored chain matches a re-computation of record payloads. */
    public static function verifyAgainstSource(string $chainName, string $table, string $recordType, string $payloadSql): array
    {
        $rows = App::db()->fetchAll('SELECT * FROM integrity_records WHERE chain_name = ? ORDER BY id ASC', [$chainName]);
        $problems = [];
        foreach ($rows as $chainRow) {
            $source = App::db()->fetchOne($payloadSql, [(int)$chainRow['record_id']]);
            if ($source === null) {
                $problems[] = "Source record {$chainRow['record_type']}#{$chainRow['record_id']} not found";
                continue;
            }
            $payload = self::canonicalPayload($source);
            $recomputed = self::hashRecord($chainRow['record_type'], (string)$chainRow['record_id'], $payload, $chainRow['previous_hash']);
            if (!hash_equals($recomputed, (string)$chainRow['record_hash'])) {
                $problems[] = "Record {$chainRow['record_type']}#{$chainRow['record_id']} hash mismatch (tampered or modified)";
            }
        }
        return ['valid' => count($problems) === 0, 'problems' => $problems];
    }

    /** Exports the chain as JSON for external verification (C tool). */
    public static function exportChain(string $chainName): array
    {
        $rows = App::db()->fetchAll(
            'SELECT record_type, record_id, record_hash, previous_hash, anchor_hash, created_at
             FROM integrity_records WHERE chain_name = ? ORDER BY id ASC',
            [$chainName]
        );
        return [
            'chain' => $chainName,
            'entries' => $rows,
            'count' => count($rows),
        ];
    }
}
