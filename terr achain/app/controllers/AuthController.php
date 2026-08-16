<?php
declare(strict_types=1);

final class AuthController
{
    public function login(): never
    {
        Csrf::validate();
        $username = Request::input('username');
        $password = Request::input('password');

        Validator::make()
            ->required('username', $username)
            ->required('password', $password)
            ->string('username', $username, 80)
            ->minLength('password', $password, (int)App::config('security.password_min_length', 8), 'Password too short')
            ->throwIfFails();

        $user = Auth::attempt((string)$username, (string)$password, Request::ip());
        if ($user === false) {
            AuditService::log(null, 'LOGIN_FAILED', 'user', $username, null, null, Request::ip(), true, 'Unknown username');
            Response::unauthorized('Invalid username or password.');
        }

        $token = Auth::createSession((int)$user['id']);
        Auth::setSession($token, $user);
        AuditService::log((int)$user['id'], 'LOGIN', 'session', $token, null, null, Request::ip(), true, 'Login');

        Response::success([
            'user' => [
                'id' => (int)$user['id'],
                'username' => $user['username'],
                'full_name' => $user['full_name'],
                'role' => Auth::roleName((int)$user['role_id']),
                'role_id' => (int)$user['role_id'],
                'admin_unit_id' => $user['admin_unit_id'] ? (int)$user['admin_unit_id'] : null,
                'language' => $user['language'],
            ],
            'csrf' => Csrf::token(),
        ], 'Login successful');
    }

    public function logout(): never
    {
        $user = Auth::requireLogin();
        AuditService::logAction($user, 'LOGOUT', 'session', null, null, null, true, 'Logout');
        Auth::logout();
        Response::success(null, 'Logged out');
    }

    public function me(): never
    {
        $user = Auth::requireLogin();
        Response::success([
            'id' => (int)$user['id'],
            'username' => $user['username'],
            'full_name' => $user['full_name'],
            'email' => $user['email'],
            'phone' => $user['phone'],
            'role' => Auth::roleName((int)$user['role_id']),
            'role_id' => (int)$user['role_id'],
            'admin_unit_id' => $user['admin_unit_id'] ? (int)$user['admin_unit_id'] : null,
            'language' => $user['language'],
            'must_change_password' => (bool)$user['must_change_password'],
        ]);
    }

    public function csrfToken(): never
    {
        Response::success(['csrf' => Csrf::token()]);
    }

    public function notifications(): never
    {
        $user = Auth::requireLogin();
        $rows = App::db()->fetchAll(
            'SELECT id, type, title, message, link, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 50',
            [(int)$user['id']]
        );
        Response::success([
            'notifications' => $rows,
            'unread' => NotificationService::unreadCount((int)$user['id']),
        ]);
    }

    public function markNotificationRead(): never
    {
        Csrf::validate();
        $user = Auth::requireLogin();
        $id = (int)Request::input('id', 0);
        App::db()->update('notifications', ['is_read' => 1], 'id = ? AND user_id = ?', [$id, (int)$user['id']]);
        Response::success(null, 'Notification marked as read');
    }
}