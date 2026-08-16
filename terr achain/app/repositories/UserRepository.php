<?php
declare(strict_types=1);

final class UserRepository extends BaseRepository
{
    protected string $table = 'users';

    public function withRole(int $id): ?array
    {
        return App::db()->fetchOne(
            'SELECT u.*, r.name AS role_name, r.description AS role_description
             FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ?',
            [$id]
        );
    }

    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = '1=1';
        $params = [];
        if (!empty($filters['role_id'])) {
            $where .= ' AND role_id = ?';
            $params[] = $filters['role_id'];
        }
        if (!empty($filters['admin_unit_id'])) {
            $where .= ' AND admin_unit_id = ?';
            $params[] = $filters['admin_unit_id'];
        }
        if (!empty($filters['search'])) {
            $where .= ' AND (username LIKE ? OR full_name LIKE ?)';
            $params[] = '%' . $filters['search'] . '%';
            $params[] = '%' . $filters['search'] . '%';
        }
        return App::db()->fetchAll(
            "SELECT u.id, u.username, u.full_name, u.email, u.phone, u.role_id, r.name AS role_name,
                    u.admin_unit_id, u.language, u.is_active, u.last_login_at, u.created_at
             FROM users u LEFT JOIN roles r ON r.id = u.role_id
             WHERE $where ORDER BY u.id DESC LIMIT $limit OFFSET $offset",
            $params
        );
    }
}

