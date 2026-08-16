<?php
declare(strict_types=1);

final class ApplicationController
{
    public function list(array $params = []): never
    {
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'applications.view')) {
            Response::forbidden();
        }
        $repo = new ApplicationRepository();
        $rows = $repo->list([
            'status' => Request::query('status'),
            'type' => Request::query('type'),
            'parcel_id' => Request::query('parcel_id'),
        ], (int)Request::query('limit', 50), (int)Request::query('offset', 0));
        // Scope filter: applications whose parcel kebele is within the user's scope
        $db = App::db();
        $filtered = [];
        foreach ($rows as $row) {
            $kebeleId = null;
            if (!empty($row['parcel_id'])) {
                $p = $db->fetchOne('SELECT kebele_id FROM parcels WHERE id = ?', [(int)$row['parcel_id']]);
                $kebeleId = $p['kebele_id'] ?? null;
            }
            if (Auth::isSystemAdmin($user) || Auth::inScope($user['admin_unit_id'] ?? null, (int)$kebeleId)) {
                $filtered[] = $row;
            }
        }
        Response::success(['applications' => $filtered, 'total' => count($filtered)]);
    }

    public function detail(array $params): never
    {
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'applications.view')) {
            Response::forbidden();
        }
        $repo = new ApplicationRepository();
        $app = $repo->detail((int)$params['id']);
        if ($app === null) {
            Response::notFound('Application not found.');
        }
        if (!empty($app['parcel_id'])) {
            $p = App::db()->fetchOne('SELECT kebele_id FROM parcels WHERE id = ?', [(int)$app['parcel_id']]);
            if (!Auth::isSystemAdmin($user) && !Auth::inScope($user['admin_unit_id'] ?? null, (int)($p['kebele_id'] ?? 0))) {
                Response::forbidden('Application outside your administrative scope.');
            }
        }
        Response::success($app);
    }

    public function create(array $params = []): never
    {
        Csrf::validate();
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'applications.create') && !Auth::hasPermission($user, 'citizens.create')) {
            Response::forbidden();
        }
        $data = Request::body();
        Validator::make()
            ->required('applicant_id', $data['applicant_id'] ?? null)
            ->required('application_type', $data['application_type'] ?? null)
            ->required('title', $data['title'] ?? null)
            ->in('application_type', $data['application_type'] ?? null, ['land_registration','land_transfer','land_lease','land_correction','parcel_search','other'])
            ->date('applied_date', $data['applied_date'] ?? null)
            ->throwIfFails();

        $db = App::db();
        $count = (int)$db->fetchOne('SELECT COUNT(*) AS c FROM applications')['c'];
        $applicationNo = 'APP-' . date('Y') . '-' . str_pad((string)($count + 1), 4, '0', STR_PAD_LEFT);
        $appId = $db->insert('applications', [
            'application_no' => $applicationNo,
            'applicant_id' => (int)$data['applicant_id'],
            'application_type' => $data['application_type'],
            'parcel_id' => $data['parcel_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'status' => 'submitted',
            'current_step' => 1,
            'assigned_to' => $user['id'],
            'language' => $data['language'] ?? $user['language'],
            'applied_date' => $data['applied_date'] ?? date('Y-m-d'),
            'created_by' => $user['id'],
        ]);
        AuditService::logAction($user, 'CREATE_RECORD', 'application', (string)$appId, null, ['application_no' => $applicationNo], false, 'Application created');
        Response::success(['id' => $appId, 'application_no' => $applicationNo], 'Application submitted', 201);
    }

    public function workflow(array $params): never
    {
        Csrf::validate();
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'applications.process')) {
            Response::forbidden();
        }
        $action = Request::input('action', 'approve');
        if (!in_array($action, ['approve', 'reject', 'cancel'], true)) {
            Response::error('Invalid workflow action.', 422);
        }
        $result = LandService::advance($user, (int)$params['id'], $action, Request::input('comment'));
        Response::success($result, 'Workflow advanced');
    }
}