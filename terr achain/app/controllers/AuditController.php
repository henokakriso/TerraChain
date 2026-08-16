<?php
declare(strict_types=1);

final class AuditController
{
    public function list(array $params = []): never
    {
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'audit.view')) {
            Response::forbidden();
        }
        $filters = Request::query('action');
        $where = '1=1';
        $args = [];
        if (!empty($filters)) {
            $where .= ' AND action = ?';
            $args[] = $filters;
        }
        $rows = App::db()->fetchAll(
            "SELECT id, user_id, username, action, resource_type, resource_id, is_high_risk, ip_address, reason, created_at
             FROM audit_logs WHERE $where ORDER BY id DESC LIMIT 200",
            $args
        );
        Response::success(['audit_logs' => $rows, 'total' => count($rows)]);
    }

    public function detail(array $params): never
    {
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'audit.view')) {
            Response::forbidden();
        }
        $row = App::db()->fetchOne('SELECT * FROM audit_logs WHERE id = ?', [(int)$params['id']]);
        if ($row === null) {
            Response::notFound('Audit record not found.');
        }
        Response::success($row);
    }
}

