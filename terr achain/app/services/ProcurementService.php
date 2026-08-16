<?php
declare(strict_types=1);

/**
 * Procurement workflow (section 20):
 * Plan → Tender Creation → Approval → Publication → Bid Submission →
 * Bid Opening → Evaluation → Decision → Approval → Contract → Execution → Payment → Audit
 */
final class ProcurementService
{
    /**
     * Creates a tender (draft) and snapshots version 1 (section 21:
     * published tenders must not be silently modified).
     */
    public static function createTender(array $user, array $data): array
    {
        $db = App::db();
        return $db->transaction(function () use ($db, $user, $data) {
            $count = (int)$db->fetchOne('SELECT COUNT(*) AS c FROM tenders')['c'];
            $tenderNo = 'T-' . date('Y') . '-' . str_pad((string)($count + 1), 4, '0', STR_PAD_LEFT);

            $tenderId = $db->insert('tenders', [
                'tender_no' => $tenderNo,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'issuing_org_id' => $data['issuing_org_id'],
                'admin_unit_id' => $data['admin_unit_id'] ?? $user['admin_unit_id'],
                'category' => $data['category'] ?? null,
                'budget_estimate' => $data['budget_estimate'] ?? null,
                'currency' => $data['currency'] ?? 'ETB',
                'evaluation_criteria' => $data['evaluation_criteria'] ?? null,
                'status' => 'draft',
                'created_by' => $user['id'],
            ]);

            $db->insert('tender_versions', [
                'tender_id' => $tenderId,
                'version' => 1,
                'snapshot' => json_encode($data, JSON_UNESCAPED_SLASHES),
                'changed_by' => $user['id'],
                'reason' => 'Initial creation',
            ]);

            IntegrityService::append('tenders', 'tender', (string)$tenderId, json_encode($data, JSON_UNESCAPED_SLASHES));
            AuditService::log((int)$user['id'], 'CREATE_RECORD', 'tender', (string)$tenderId, null, ['tender_no' => $tenderNo], null, false, 'Tender created');
            NotificationService::notify((int)$user['id'], 'tender', "Tender $tenderNo created", 'Tender draft created.', '/tenders.html?no=' . $tenderNo);

            return $db->fetchOne('SELECT * FROM tenders WHERE id = ?', [$tenderId]);
        });
    }

    /**
     * Publishes a tender. Once published, critical fields cannot be modified;
     * changes create an auditable version instead (section 21).
     */
    public static function publishTender(array $user, int $tenderId, ?string $publicationDate = null, ?string $closingDate = null): array
    {
        $db = App::db();
        return $db->transaction(function () use ($db, $user, $tenderId, $publicationDate, $closingDate) {
            $tender = $db->fetchOne('SELECT * FROM tenders WHERE id = ?', [$tenderId]);
            if ($tender === null) {
                throw new ApiException('Tender not found.', 404);
            }
            if ($tender['status'] !== 'draft' && $tender['status'] !== 'pending_approval') {
                throw new ApiException('Only draft tenders can be published.', 409);
            }
            if ($closingDate !== null && strtotime($closingDate) <= time()) {
                throw new ApiException('Closing date must be in the future.', 422);
            }
            $db->update('tenders', [
                'status' => 'published',
                'publication_date' => $publicationDate ?? date('Y-m-d'),
                'closing_date' => $closingDate,
            ], 'id = ?', [$tenderId]);

            $nextVersion = (int)$tender['current_version'] + 1;
            $db->insert('tender_versions', [
                'tender_id' => $tenderId,
                'version' => $nextVersion,
                'snapshot' => json_encode(['status' => 'published', 'publication_date' => $publicationDate ?? date('Y-m-d'), 'closing_date' => $closingDate], JSON_UNESCAPED_SLASHES),
                'changed_by' => $user['id'],
                'reason' => 'Published',
            ]);
            $db->update('tenders', ['current_version' => $nextVersion], 'id = ?', [$tenderId]);

            IntegrityService::append('tenders', 'tender', (string)$tenderId, 'published');
            AuditService::log((int)$user['id'], 'PUBLISH_TENDER', 'tender', (string)$tenderId, ['status' => 'draft'], ['status' => 'published'], null, true, 'Tender published');

            return $db->fetchOne('SELECT * FROM tenders WHERE id = ?', [$tenderId]);
        });
    }

    /**
     * Amendments to a published tender create an auditable version (section 21).
     */
    public static function amendTender(array $user, int $tenderId, array $changes, ?string $reason = null): array
    {
        $db = App::db();
        return $db->transaction(function () use ($db, $user, $tenderId, $changes, $reason) {
            $tender = $db->fetchOne('SELECT * FROM tenders WHERE id = ?', [$tenderId]);
            if ($tender === null) {
                throw new ApiException('Tender not found.', 404);
            }
            if ($tender['status'] === 'closed' || $tender['status'] === 'awarded' || $tender['status'] === 'cancelled') {
                throw new ApiException('Cannot amend a closed, awarded or cancelled tender.', 409);
            }
            $allowed = ['title', 'description', 'evaluation_criteria', 'closing_date', 'category'];
            $clean = [];
            foreach ($allowed as $field) {
                if (array_key_exists($field, $changes)) {
                    $clean[$field] = $changes[$field];
                }
            }
            if (count($clean) === 0) {
                throw new ApiException('No amendable fields provided.', 422);
            }
            $nextVersion = (int)$tender['current_version'] + 1;
            $db->insert('tender_versions', [
                'tender_id' => $tenderId,
                'version' => $nextVersion,
                'snapshot' => json_encode(['changes' => $clean, 'previous' => array_intersect_key($tender, $clean)], JSON_UNESCAPED_SLASHES),
                'changed_by' => $user['id'],
                'reason' => $reason ?? 'Amendment',
            ]);
            $db->update('tenders', array_merge($clean, ['current_version' => $nextVersion]), 'id = ?', [$tenderId]);

            IntegrityService::append('tenders', 'tender', (string)$tenderId, json_encode($clean, JSON_UNESCAPED_SLASHES));
            AuditService::log((int)$user['id'], 'UPDATE_RECORD', 'tender', (string)$tenderId, $tender, array_merge($clean, ['version' => $nextVersion]), null, false, $reason ?? 'Amendment');

            return $db->fetchOne('SELECT * FROM tenders WHERE id = ?', [$tenderId]);
        });
    }

    /** Cancels a tender; snapshots the final state and appends it to the chain. */
    public static function cancelTender(array $user, int $tenderId, ?string $reason = null): array
    {
        $db = App::db();
        return $db->transaction(function () use ($db, $user, $tenderId, $reason) {
            $tender = $db->fetchOne('SELECT * FROM tenders WHERE id = ?', [$tenderId]);
            if ($tender === null) {
                throw new ApiException('Tender not found.', 404);
            }
            if ($tender['status'] === 'awarded' || $tender['status'] === 'cancelled') {
                throw new ApiException('Cannot cancel an awarded or already cancelled tender.', 409);
            }
            $nextVersion = (int)$tender['current_version'] + 1;
            $db->insert('tender_versions', [
                'tender_id' => $tenderId,
                'version' => $nextVersion,
                'snapshot' => json_encode(['status' => 'cancelled', 'reason' => $reason], JSON_UNESCAPED_SLASHES),
                'changed_by' => $user['id'],
                'reason' => $reason ?? 'Cancellation',
            ]);
            $db->update('tenders', ['status' => 'cancelled', 'current_version' => $nextVersion], 'id = ?', [$tenderId]);

            IntegrityService::append('tenders', 'tender', (string)$tenderId, 'cancelled:' . ($reason ?? 'no reason given'));
            AuditService::log((int)$user['id'], 'CANCEL', 'tender', (string)$tenderId, $tender, ['status' => 'cancelled', 'version' => $nextVersion], null, true, $reason ?? 'Cancellation');

            return $db->fetchOne('SELECT * FROM tenders WHERE id = ?', [$tenderId]);
        });
    }

    /** Terminates a contract and closes its remaining payment window. */
    public static function terminateContract(array $user, int $contractId, ?string $reason = null): array
    {
        $db = App::db();
        return $db->transaction(function () use ($db, $user, $contractId, $reason) {
            $contract = $db->fetchOne('SELECT * FROM contracts WHERE id = ?', [$contractId]);
            if ($contract === null) {
                throw new ApiException('Contract not found.', 404);
            }
            if ($contract['status'] === 'terminated' || $contract['status'] === 'cancelled') {
                throw new ApiException('Contract is already closed.', 409);
            }
            $db->update('contracts', ['status' => 'terminated'], 'id = ?', [$contractId]);
            IntegrityService::append('contracts', 'contract', (string)$contractId, 'terminated:' . ($reason ?? 'no reason given'));
            AuditService::log((int)$user['id'], 'TERMINATE', 'contract', (string)$contractId, $contract, ['status' => 'terminated'], null, true, $reason ?? 'Termination');
            return $db->fetchOne('SELECT * FROM contracts WHERE id = ?', [$contractId]);
        });
    }

    /**
     * Submits a bid. Bid price is encrypted at rest (section 22)
     * and the bid stays sealed until the opening stage.
     */
    public static function submitBid(array $user, array $data, array $uploads = []): array
    {
        $db = App::db();
        return $db->transaction(function () use ($db, $user, $data, $uploads) {
            $tender = $db->fetchOne('SELECT * FROM tenders WHERE id = ?', [(int)$data['tender_id']]);
            if ($tender === null || $tender['status'] !== 'published') {
                throw new ApiException('Tender is not open for bids.', 409);
            }
            if ($tender['closing_date'] !== null && strtotime((string)$tender['closing_date'] . ' 23:59:59') < time()) {
                throw new ApiException('Tender deadline has passed.', 409);
            }

            $count = (int)$db->fetchOne('SELECT COUNT(*) AS c FROM bids')['c'];
            $bidNo = 'B-' . date('Y') . '-' . str_pad((string)($count + 1), 4, '0', STR_PAD_LEFT);

            $key = hash('sha256', (string)App::config('app.base_url'), true);
            $amountStr = (string)$data['amount'];
            $encrypted = openssl_encrypt($amountStr, 'aes-256-cbc', $key, 0, substr(hash('sha256', $bidNo), 0, 16));
            if ($encrypted === false) {
                throw new ApiException('Could not secure bid price.', 500);
            }

            $bidId = $db->insert('bids', [
                'bid_no' => $bidNo,
                'tender_id' => $tender['id'],
                'supplier_org_id' => $data['supplier_org_id'],
                'price_encrypted' => $encrypted,
                'status' => 'submitted',
                'opening_status' => 'sealed',
            ]);

            foreach ($uploads as $upload) {
                $stored = DocumentService::storeUpload($upload);
                $docId = $db->insert('documents', [
                    'document_no' => 'DOC-' . date('Y') . '-' . str_pad((string)((int)$db->fetchOne('SELECT COUNT(*) AS c FROM documents')['c'] + 1), 6, '0', STR_PAD_LEFT),
                    'document_type' => 'bid_document',
                    'title' => 'Bid document ' . $bidNo,
                    'owner_type' => 'bid',
                    'owner_id' => $bidId,
                    'status' => 'active',
                    'current_version' => 1,
                    'content_hash' => $stored['content_hash'],
                    'verification_token' => strtoupper(bin2hex(random_bytes(8))),
                    'created_by' => $user['id'],
                ]);
                $db->insert('document_versions', [
                    'document_id' => $docId,
                    'version' => 1,
                    'storage_path' => $stored['storage_path'],
                    'file_name' => $stored['file_name'],
                    'mime_type' => $stored['mime_type'],
                    'file_size' => $stored['file_size'],
                    'content_hash' => $stored['content_hash'],
                    'uploaded_by' => $user['id'],
                ]);
                $db->insert('bid_documents', ['bid_id' => $bidId, 'document_id' => $docId, 'is_confidential' => 1]);
            }

            IntegrityService::append('bids', 'bid', (string)$bidId, $bidNo);
            AuditService::log((int)$user['id'], 'SUBMIT_BID', 'bid', (string)$bidId, null, ['bid_no' => $bidNo, 'tender' => $tender['tender_no']], null, true, 'Bid submitted (sealed)');

            return $db->fetchOne('SELECT bid_no, tender_id, supplier_org_id, status, opening_status, submitted_at FROM bids WHERE id = ?', [$bidId]);
        });
    }

    /** Bid opening (section 22): reveals price; requires the OPEN_BIDS permission. */
    public static function openBids(array $user, int $tenderId): array
    {
        $db = App::db();
        return $db->transaction(function () use ($db, $user, $tenderId) {
            $tender = $db->fetchOne('SELECT * FROM tenders WHERE id = ?', [$tenderId]);
            if ($tender === null) {
                throw new ApiException('Tender not found.', 404);
            }
            if ($tender['status'] === 'draft' || $tender['status'] === 'pending_approval') {
                throw new ApiException('Bids can only be opened after the closing date.', 409);
            }
            $bids = $db->fetchAll('SELECT * FROM bids WHERE tender_id = ?', [$tenderId]);
            $key = hash('sha256', (string)App::config('app.base_url'), true);
            $opened = [];
            foreach ($bids as $bid) {
                if ($bid['opening_status'] === 'sealed') {
                    $decrypted = openssl_decrypt($bid['price_encrypted'], 'aes-256-cbc', $key, 0, substr(hash('sha256', $bid['bid_no']), 0, 16));
                    $db->update('bids', [
                        'opening_status' => 'opened',
                        'opened_at' => App::now(),
                        'opened_by' => $user['id'],
                        'amount' => is_numeric($decrypted) ? (float)$decrypted : null,
                    ], 'id = ?', [$bid['id']]);
                    $opened[] = $bid['bid_no'];
                }
            }
            AuditService::log((int)$user['id'], 'OPEN_BIDS', 'tender', (string)$tenderId, null, ['opened' => $opened], null, true, 'Bid opening ceremony');
            return ['opened' => $opened, 'count' => count($bids)];
        });
    }

    public static function evaluateBid(array $user, int $bidId, float $score, ?string $comments = null, ?array $criteria = null): array
    {
        $db = App::db();
        return $db->transaction(function () use ($db, $user, $bidId, $score, $comments, $criteria) {
            $bid = $db->fetchOne('SELECT * FROM bids WHERE id = ?', [$bidId]);
            if ($bid === null) {
                throw new ApiException('Bid not found.', 404);
            }
            if ($bid['opening_status'] !== 'opened') {
                throw new ApiException('Bid must be opened before evaluation.', 409);
            }
            if ($score < 0 || $score > 100) {
                throw new ApiException('Score must be between 0 and 100.', 422);
            }
            $db->insert('evaluations', [
                'bid_id' => $bidId,
                'evaluator_id' => $user['id'],
                'score' => $score,
                'criteria' => $criteria !== null ? json_encode($criteria) : null,
                'comments' => $comments,
                'status' => 'submitted',
            ]);
            $db->update('bids', ['status' => 'evaluated', 'evaluation_score' => $score, 'evaluation_notes' => $comments], 'id = ?', [$bidId]);
            AuditService::log((int)$user['id'], 'EVALUATE_BID', 'bid', (string)$bidId, null, ['score' => $score], null, false, 'Bid evaluated');
            return $db->fetchOne('SELECT * FROM bids WHERE id = ?', [$bidId]);
        });
    }
}
