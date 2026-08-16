<?php
declare(strict_types=1);

final class TenderRepository extends BaseRepository
{
    protected string $table = 'tenders';

    public function list(?string $status = null, ?string $q = null, int $limit = 50, int $offset = 0): array
    {
        $where = '1=1';
        $params = [];
        if ($status !== null && $status !== '') {
            $where .= ' AND t.status = ?';
            $params[] = $status;
        }
        if ($q !== null && $q !== '') {
            $where .= ' AND (t.tender_no LIKE ? OR t.title LIKE ?)';
            $params[] = "%$q%";
            $params[] = "%$q%";
        }
        return App::db()->fetchAll(
            "SELECT t.*, o.name AS org_name
             FROM tenders t JOIN organizations o ON o.id = t.issuing_org_id
             WHERE $where ORDER BY t.id DESC LIMIT $limit OFFSET $offset",
            $params
        );
    }

    public function detail(int $id): ?array
    {
        $tender = App::db()->fetchOne(
            'SELECT t.*, o.name AS org_name, o.contact_person, o.phone AS org_phone, o.email AS org_email
             FROM tenders t JOIN organizations o ON o.id = t.issuing_org_id WHERE t.id = ?',
            [$id]
        );
        if ($tender === null) {
            return null;
        }
        $tender['versions'] = App::db()->fetchAll(
            'SELECT version, snapshot, reason, created_at FROM tender_versions WHERE tender_id = ? ORDER BY version DESC',
            [$id]
        );
        $tender['bids'] = App::db()->fetchAll(
            'SELECT b.id, b.bid_no, b.status, b.opening_status, b.amount, b.submitted_at, o.name AS supplier_name
             FROM bids b JOIN organizations o ON o.id = b.supplier_org_id WHERE b.tender_id = ? ORDER BY b.id',
            [$id]
        );
        $tender['documents'] = App::db()->fetchAll(
            "SELECT id, document_no, title, document_type, status, created_at FROM documents WHERE owner_type = 'tender' AND owner_id = ?",
            [$id]
        );
        return $tender;
    }
}

