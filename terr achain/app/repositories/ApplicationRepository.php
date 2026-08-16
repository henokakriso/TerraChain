<?php
declare(strict_types=1);

final class ApplicationRepository extends BaseRepository
{
    protected string $table = 'applications';

    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = '1=1';
        $params = [];
        if (!empty($filters['status'])) {
            $where .= ' AND status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['type'])) {
            $where .= ' AND application_type = ?';
            $params[] = $filters['type'];
        }
        if (!empty($filters['parcel_id'])) {
            $where .= ' AND parcel_id = ?';
            $params[] = $filters['parcel_id'];
        }
        return App::db()->fetchAll(
            "SELECT a.*, c.first_name, c.father_name, p.parcel_no
             FROM applications a
             JOIN citizens c ON c.id = a.applicant_id
             LEFT JOIN parcels p ON p.id = a.parcel_id
             WHERE $where ORDER BY a.id DESC LIMIT $limit OFFSET $offset",
            $params
        );
    }

    public function detail(int $id): ?array
    {
        $app = App::db()->fetchOne(
            'SELECT a.*, c.first_name, c.father_name, c.grand_father_name, c.national_id, c.phone,
                    p.parcel_no, p.location_description
             FROM applications a
             JOIN citizens c ON c.id = a.applicant_id
             LEFT JOIN parcels p ON p.id = a.parcel_id
             WHERE a.id = ?',
            [$id]
        );
        if ($app === null) {
            return null;
        }
        $app['workflow'] = LandService::workflowStatus($app['status']);
        $app['approvals'] = App::db()->fetchAll(
            'SELECT ap.*, u.username AS approver_name FROM approvals ap LEFT JOIN users u ON u.id = ap.approver_id
             WHERE ap.approvable_type = "application" AND ap.approvable_id = ? ORDER BY ap.id ASC',
            [$id]
        );
        return $app;
    }
}

