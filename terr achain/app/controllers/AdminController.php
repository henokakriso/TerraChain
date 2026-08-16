<?php
declare(strict_types=1);

final class AdminController
{
    // ---------------- USERS ----------------

    public function listUsers(array $params = []): never
    {
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'users.view')) {
            Response::forbidden();
        }
        $repo = new UserRepository();
        $rows = $repo->list([
            'role_id' => Request::query('role_id'),
            'admin_unit_id' => Request::query('admin_unit_id'),
            'search' => Request::query('q'),
        ], (int)Request::query('limit', 50), (int)Request::query('offset', 0));
        if (!Auth::isSystemAdmin($user)) {
            $rows = array_values(array_filter($rows, fn($r) => Auth::inScope($user['admin_unit_id'] ?? null, (int)($r['admin_unit_id'] ?? 0))));
        }
        Response::success(['users' => $rows]);
    }

    public function createUser(array $params = []): never
    {
        Csrf::validate();
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'users.create')) {
            Response::forbidden();
        }
        $data = Request::body();
        $minLen = (int)App::config('security.password_min_length', 8);
        Validator::make()
            ->required('username', $data['username'] ?? null)
            ->required('password', $data['password'] ?? null)
            ->required('full_name', $data['full_name'] ?? null)
            ->required('role_id', $data['role_id'] ?? null)
            ->minLength('password', $data['password'] ?? null, $minLen, "Password must be at least $minLen characters")
            ->unique('username', $data['username'] ?? null, 'users', 'username', null, 'Username already exists')
            ->string('username', $data['username'] ?? null, 80, 'Username too long')
            ->email('email', $data['email'] ?? null)
            ->throwIfFails();

        if (!empty($data['admin_unit_id']) && !Auth::isSystemAdmin($user)
            && !Auth::inScope($user['admin_unit_id'] ?? null, (int)$data['admin_unit_id'])) {
            Response::forbidden('Cannot assign a unit outside your scope.');
        }

        $userId = App::db()->insert('users', [
            'username' => $data['username'],
            'password_hash' => password_hash((string)$data['password'], PASSWORD_BCRYPT),
            'full_name' => $data['full_name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'role_id' => (int)$data['role_id'],
            'admin_unit_id' => $data['admin_unit_id'] ?? null,
            'language' => $data['language'] ?? 'en',
            'is_active' => isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1,
        ]);
        AuditService::logAction($user, 'CREATE_RECORD', 'user', (string)$userId, null, ['username' => $data['username'], 'role_id' => (int)$data['role_id']], true, 'User created');
        Response::success(['id' => $userId], 'User created', 201);
    }

    public function updateUser(array $params): never
    {
        Csrf::validate();
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'users.update')) {
            Response::forbidden();
        }
        $id = (int)$params['id'];
        $db = App::db();
        $target = $db->fetchOne('SELECT * FROM users WHERE id = ?', [$id]);
        if ($target === null) {
            Response::notFound('User not found.');
        }
        $data = Request::body();
        $allowed = ['full_name', 'email', 'phone', 'language', 'role_id', 'admin_unit_id', 'is_active'];
        $changes = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $changes[$field] = $data[$field];
            }
        }
        if (!empty($data['password'])) {
            $minLen = (int)App::config('security.password_min_length', 8);
            if (mb_strlen((string)$data['password']) < $minLen) {
                Response::error("Password must be at least $minLen characters.", 422);
            }
            $changes['password_hash'] = password_hash((string)$data['password'], PASSWORD_BCRYPT);
        }
        if (!empty($changes['admin_unit_id']) && !Auth::isSystemAdmin($user)
            && !Auth::inScope($user['admin_unit_id'] ?? null, (int)$changes['admin_unit_id'])) {
            Response::forbidden('Cannot assign a unit outside your scope.');
        }
        if (count($changes) === 0) {
            Response::error('No updateable fields provided.', 422);
        }
        $db->update('users', $changes, 'id = ?', [$id]);
        AuditService::logAction($user, 'UPDATE_RECORD', 'user', (string)$id, $target, $changes, true, 'User updated');
        Response::success(null, 'User updated');
    }

    public function deactivateUser(array $params): never
    {
        Csrf::validate();
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'users.delete')) {
            Response::forbidden();
        }
        $id = (int)$params['id'];
        $db = App::db();
        $target = $db->fetchOne('SELECT * FROM users WHERE id = ?', [$id]);
        if ($target === null) {
            Response::notFound('User not found.');
        }
        if ($id === (int)$user['id']) {
            Response::error('You cannot deactivate your own account.', 422);
        }
        $db->update('users', ['is_active' => 0], 'id = ?', [$id]);
        AuditService::logAction($user, 'DEACTIVATE', 'user', (string)$id, ['is_active' => 1], ['is_active' => 0], true, 'User deactivated');
        Response::success(null, 'User deactivated');
    }

    // ---------------- ROLES ----------------

    public function listRoles(array $params = []): never
    {
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'roles.view')) {
            Response::forbidden();
        }
        $rows = App::db()->fetchAll('SELECT r.*, COUNT(u.id) AS user_count FROM roles r LEFT JOIN users u ON u.role_id = r.id GROUP BY r.id ORDER BY r.id');
        Response::success(['roles' => $rows]);
    }

    public function rolePermissions(array $params): never
    {
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'roles.view')) {
            Response::forbidden();
        }
        $roleId = (int)$params['id'];
        $permissions = App::db()->fetchAll(
            'SELECT p.id, p.code, p.description, CASE WHEN rp.role_id IS NULL THEN 0 ELSE 1 END AS granted
             FROM permissions p LEFT JOIN role_permissions rp ON rp.permission_id = p.id AND rp.role_id = ?
             ORDER BY p.id',
            [$roleId]
        );
        Response::success(['role_id' => $roleId, 'permissions' => $permissions]);
    }

    public function updateRolePermissions(array $params): never
    {
        Csrf::validate();
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'roles.manage')) {
            Response::forbidden();
        }
        $roleId = (int)$params['id'];
        $grantIds = array_map('intval', (array)Request::input('permission_ids', []));
        $db = App::db();
        $db->transaction(function () use ($db, $roleId, $grantIds): void {
            $db->delete('role_permissions', 'role_id = ?', [$roleId]);
            foreach ($grantIds as $permId) {
                $db->insert('role_permissions', ['role_id' => $roleId, 'permission_id' => $permId]);
            }
        });
        AuditService::logAction($user, 'CHANGE_PERMISSION', 'role', (string)$roleId, null, ['permission_ids' => $grantIds], true, 'Role permissions changed');
        Response::success(null, 'Role permissions updated');
    }

    public function createRole(array $params = []): never
    {
        Csrf::validate();
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'roles.create')) {
            Response::forbidden();
        }
        $data = Request::body();
        Validator::make()
            ->required('name', $data['name'] ?? null)
            ->unique('name', $data['name'] ?? null, 'roles', 'name', null, 'Role name already exists')
            ->string('name', $data['name'] ?? null, 60, 'Role name too long')
            ->throwIfFails();
        $roleId = App::db()->insert('roles', [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_system' => 0,
        ]);
        AuditService::logAction($user, 'CREATE_RECORD', 'role', (string)$roleId, null, ['name' => $data['name']], true, 'Role created');
        Response::success(['id' => $roleId], 'Role created', 201);
    }

    public function deleteRole(array $params): never
    {
        Csrf::validate();
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'roles.delete')) {
            Response::forbidden();
        }
        $id = (int)$params['id'];
        $db = App::db();
        $role = $db->fetchOne('SELECT * FROM roles WHERE id = ?', [$id]);
        if ($role === null) {
            Response::notFound('Role not found.');
        }
        if ((int)$role['is_system'] === 1) {
            Response::error('System roles cannot be deleted.', 422);
        }
        $users = (int)$db->fetchOne('SELECT COUNT(*) AS c FROM users WHERE role_id = ?', [$id])['c'];
        if ($users > 0) {
            Response::error('Role is assigned to users and cannot be deleted.', 409);
        }
        $db->delete('role_permissions', 'role_id = ?', [$id]);
        $db->delete('roles', 'id = ?', [$id]);
        AuditService::logAction($user, 'DELETE_RECORD', 'role', (string)$id, $role, null, true, 'Role deleted');
        Response::success(null, 'Role deleted');
    }

    // ---------------- ADMIN UNITS ----------------

    public function listUnits(array $params = []): never
    {
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'admin_units.view')) {
            Response::forbidden();
        }
        $type = Request::query('type');
        $parentId = Request::query('parent_id');
        $where = '1=1';
        $args = [];
        if ($type !== null && $type !== '') {
            $where .= ' AND unit_type = ?';
            $args[] = $type;
        }
        if ($parentId !== null && $parentId !== '') {
            $where .= ' AND parent_id = ?';
            $args[] = (int)$parentId;
        }
        $rows = App::db()->fetchAll("SELECT id, unit_type, name_en, name_am, code, parent_id, status FROM admin_units WHERE $where ORDER BY unit_type, name_en", $args);
        Response::success(['admin_units' => $rows]);
    }

    public function unitTree(array $params = []): never
    {
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'admin_units.view')) {
            Response::forbidden();
        }
        $rows = App::db()->fetchAll('SELECT id, unit_type, name_en, parent_id FROM admin_units WHERE status = "active" ORDER BY parent_id, unit_type');
        $tree = [];
        foreach ($rows as $row) {
            $tree[(string)$row['parent_id']][] = $row;
        }
        $build = function (int $parentId) use (&$build, $tree): array {
            $children = [];
            foreach ($tree[(string)$parentId] ?? [] as $node) {
                $sub = $node;
                $sub['children'] = $build((int)$node['id']);
                $children[] = $sub;
            }
            return $children;
        };
        Response::success(['tree' => $build(0)]);
    }

    public function createUnit(array $params = []): never
    {
        Csrf::validate();
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'admin_units.create')) {
            Response::forbidden();
        }
        $data = Request::body();
        Validator::make()
            ->required('unit_type', $data['unit_type'] ?? null)
            ->required('name_en', $data['name_en'] ?? null)
            ->required('code', $data['code'] ?? null)
            ->in('unit_type', $data['unit_type'] ?? null, ['country','region','zone','woreda','kebele'])
            ->unique('code', $data['code'] ?? null, 'admin_units', 'code', null, 'Unit code already exists')
            ->throwIfFails();
        if (!empty($data['parent_id']) && !Auth::isSystemAdmin($user)
            && !Auth::inScope($user['admin_unit_id'] ?? null, (int)$data['parent_id'])) {
            Response::forbidden('Parent unit outside your administrative scope.');
        }
        $unitId = App::db()->insert('admin_units', [
            'unit_type' => $data['unit_type'],
            'name_en' => $data['name_en'],
            'name_am' => $data['name_am'] ?? $data['name_en'],
            'code' => $data['code'],
            'parent_id' => $data['parent_id'] ?? null,
            'status' => 'active',
        ]);
        AuditService::logAction($user, 'CREATE_RECORD', 'admin_unit', (string)$unitId, null, ['code' => $data['code'], 'type' => $data['unit_type']], true, 'Admin unit created');
        Response::success(['id' => $unitId], 'Admin unit created', 201);
    }

    public function updateUnit(array $params): never
    {
        Csrf::validate();
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'admin_units.update')) {
            Response::forbidden();
        }
        $id = (int)$params['id'];
        $db = App::db();
        $unit = $db->fetchOne('SELECT * FROM admin_units WHERE id = ?', [$id]);
        if ($unit === null) {
            Response::notFound('Admin unit not found.');
        }
        if (!Auth::isSystemAdmin($user) && !Auth::inScope($user['admin_unit_id'] ?? null, $id)) {
            Response::forbidden('Admin unit outside your administrative scope.');
        }
        $data = Request::body();
        $allowed = ['name_en', 'name_am', 'status'];
        $changes = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $changes[$field] = $data[$field] ?? '';
                if ($field === 'name_en' && $changes[$field] === '') {
                    Response::error('name_en cannot be empty.', 422);
                }
            }
        }
        if (count($changes) === 0) {
            Response::error('No updateable fields provided.', 422);
        }
        $db->update('admin_units', $changes, 'id = ?', [$id]);
        AuditService::logAction($user, 'UPDATE_RECORD', 'admin_unit', (string)$id, $unit, $changes, false, 'Admin unit updated');
        Response::success(null, 'Admin unit updated');
    }

    public function updateSettings(array $params = []): never
    {
        Csrf::validate();
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'settings.manage')) {
            Response::forbidden();
        }
        $data = Request::body();
        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        if (count($settings) === 0) {
            Response::error('settings map is required.', 422);
        }
        $db = App::db();
        foreach ($settings as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $existing = $db->fetchOne('SELECT setting_key FROM settings WHERE setting_key = ?', [$key]);
            $stored = is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_SLASHES);
            if ($existing === null) {
                $db->insert('settings', ['setting_key' => $key, 'setting_value' => $stored, 'is_public' => 0]);
            } else {
                $db->update('settings', ['setting_value' => $stored], 'setting_key = ?', [$key]);
            }
        }
        AuditService::logAction($user, 'UPDATE_RECORD', 'settings', 'system', null, array_keys($settings), true, 'Settings updated');
        Response::success(null, 'Settings updated');
    }
}