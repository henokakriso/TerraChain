<?php
declare(strict_types=1);

final class OrganizationController
{
    public function list(array $params = []): never
    {
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'organizations.view')) {
            Response::forbidden();
        }
        $wheres = ['1=1'];
        $args = [];
        if (Request::query('org_type') !== null && Request::query('org_type') !== '') {
            $wheres[] = 'org_type = ?';
            $args[] = Request::query('org_type');
        }
        if (Request::query('status') !== null && Request::query('status') !== '') {
            $wheres[] = 'status = ?';
            $args[] = Request::query('status');
        }
        if (Request::query('q') !== null && Request::query('q') !== '') {
            $wheres[] = '(name LIKE ? OR tin_number LIKE ?)';
            $q = '%' . Request::query('q') . '%';
            $args[] = $q;
            $args[] = $q;
        }
        $rows = App::db()->fetchAll(
            'SELECT o.*, u.name_en AS admin_unit_name FROM organizations o
             LEFT JOIN admin_units u ON u.id = o.admin_unit_id
             WHERE ' . implode(' AND ', $wheres) . ' ORDER BY o.name LIMIT 200',
            $args
        );
        Response::success(['organizations' => $rows, 'total' => count($rows)]);
    }

    public function detail(array $params): never
    {
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'organizations.view')) {
            Response::forbidden();
        }
        $row = App::db()->fetchOne(
            'SELECT o.*, u.name_en AS admin_unit_name FROM organizations o
             LEFT JOIN admin_units u ON u.id = o.admin_unit_id WHERE o.id = ?',
            [(int)$params['id']]
        );
        if ($row === null) {
            Response::notFound('Organization not found.');
        }
        Response::success($row);
    }

    public function create(array $params = []): never
    {
        Csrf::validate();
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'organizations.manage')) {
            Response::forbidden();
        }
        $data = Request::body();
        Validator::make()
            ->required('name', $data['name'] ?? null)
            ->unique('tin_number', $data['tin_number'] ?? null, 'organizations', 'tin_number', null, 'TIN already registered')
            ->in('org_type', $data['org_type'] ?? null, ['government','private','ngo','supplier'])
            ->in('status', $data['status'] ?? null, ['active','inactive','blacklisted'])
            ->email('email', $data['email'] ?? null)
            ->throwIfFails();
        $id = App::db()->insert('organizations', [
            'name' => $data['name'],
            'tin_number' => $data['tin_number'] ?? null,
            'org_type' => $data['org_type'] ?? 'private',
            'contact_person' => $data['contact_person'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'admin_unit_id' => $data['admin_unit_id'] ?? null,
            'status' => $data['status'] ?? 'active',
        ]);
        AuditService::logAction($user, 'CREATE_RECORD', 'organization', (string)$id, null, ['name' => $data['name']], true, 'Organization created');
        Response::success(['id' => $id], 'Organization created', 201);
    }

    public function update(array $params): never
    {
        Csrf::validate();
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'organizations.manage')) {
            Response::forbidden();
        }
        $id = (int)$params['id'];
        $db = App::db();
        $org = $db->fetchOne('SELECT * FROM organizations WHERE id = ?', [$id]);
        if ($org === null) {
            Response::notFound('Organization not found.');
        }
        $data = Request::body();
        $allowed = ['name', 'tin_number', 'org_type', 'contact_person', 'phone', 'email', 'address', 'admin_unit_id', 'status'];
        $changes = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $changes[$field] = $data[$field];
            }
        }
        if (count($changes) === 0) {
            Response::error('No updateable fields provided.', 422);
        }
        if (isset($changes['tin_number']) && $changes['tin_number'] !== null && $changes['tin_number'] !== $org['tin_number']) {
            $exists = $db->fetchOne('SELECT id FROM organizations WHERE tin_number = ? AND id <> ?', [$changes['tin_number'], $id]);
            if ($exists !== null) {
                Response::error('TIN already registered.', 422);
            }
        }
        $db->update('organizations', $changes, 'id = ?', [$id]);
        AuditService::logAction($user, 'UPDATE_RECORD', 'organization', (string)$id, $org, $changes, false, 'Organization updated');
        Response::success($db->fetchOne('SELECT * FROM organizations WHERE id = ?', [$id]), 'Organization updated');
    }

    public function setStatus(array $params): never
    {
        Csrf::validate();
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'organizations.manage')) {
            Response::forbidden();
        }
        $id = (int)$params['id'];
        $status = (string)Request::input('status', '');
        if (!in_array($status, ['active', 'inactive', 'blacklisted'], true)) {
            Response::error('Invalid status.', 422);
        }
        $db = App::db();
        $org = $db->fetchOne('SELECT * FROM organizations WHERE id = ?', [$id]);
        if ($org === null) {
            Response::notFound('Organization not found.');
        }
        $db->update('organizations', ['status' => $status], 'id = ?', [$id]);
        AuditService::logAction($user, 'UPDATE_RECORD', 'organization', (string)$id, ['status' => $org['status']], ['status' => $status], $status === 'blacklisted', 'Organization status changed');
        Response::success(null, 'Organization status updated');
    }
}