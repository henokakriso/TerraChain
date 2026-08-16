<?php
declare(strict_types=1);

final class LandController
{
    public function list(array $params = []): never
    {
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'parcels.view')) {
            Response::forbidden();
        }
        $repo = new ParcelRepository();
        $rows = $repo->search(
            Request::query('q'),
            Request::query('kebele_id'),
            Request::query('status'),
            (int)Request::query('limit', 50),
            (int)Request::query('offset', 0)
        );
        $filtered = $this->scopeFilter($user, $rows, 'kebele_id');
        Response::success(['parcels' => $filtered, 'total' => count($filtered)]);
    }

    public function detail(array $params): never
    {
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'parcels.view')) {
            Response::forbidden();
        }
        $repo = new ParcelRepository();
        $parcel = $repo->detail((int)$params['id']);
        if ($parcel === null) {
            Response::notFound('Parcel not found.');
        }
        if (!Auth::isSystemAdmin($user) && !Auth::inScope($user['admin_unit_id'] ?? null, (int)$parcel['kebele_id'])) {
            Response::forbidden('Parcel outside your administrative scope.');
        }
        Response::success($parcel);
    }

    public function create(array $params = []): never
    {
        Csrf::validate();
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'parcels.create')) {
            Response::forbidden();
        }
        $data = Request::body();
        Validator::make()
            ->required('kebele_id', $data['kebele_id'] ?? null)
            ->required('location_description', $data['location_description'] ?? null)
            ->numeric('area', $data['area'] ?? null)
            ->in('land_use', $data['land_use'] ?? null, ['residential','agricultural','commercial','industrial','institutional','public','mixed'])
            ->unique('parcel_no', $data['parcel_no'] ?? null, 'parcels', 'parcel_no', null, 'Parcel number already exists')
            ->throwIfFails();

        if (!Auth::isSystemAdmin($user) && !Auth::inScope($user['admin_unit_id'] ?? null, (int)$data['kebele_id'])) {
            Response::forbidden('Kebele outside your administrative scope.');
        }

        $db = App::db();
        $parcelNo = $data['parcel_no'] ?? $this->nextParcelNo();
        $parcelId = $db->insert('parcels', [
            'parcel_no' => $parcelNo,
            'kebele_id' => (int)$data['kebele_id'],
            'location_description' => $data['location_description'],
            'geographic_ref' => $data['geographic_ref'] ?? null,
            'area' => $data['area'] ?? null,
            'area_unit' => $data['area_unit'] ?? 'sqm',
            'land_use' => $data['land_use'] ?? null,
            'status' => 'pending',
            'created_by' => $user['id'],
        ]);
        AuditService::logAction($user, 'CREATE_RECORD', 'parcel', (string)$parcelId, null, ['parcel_no' => $parcelNo, 'kebele_id' => (int)$data['kebele_id']], false, 'Parcel created');
        Response::success(['id' => $parcelId, 'parcel_no' => $parcelNo], 'Parcel created', 201);
    }

    public function update(array $params): never
    {
        Csrf::validate();
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'parcels.update')) {
            Response::forbidden();
        }
        $id = (int)$params['id'];
        $repo = new ParcelRepository();
        $parcel = $repo->find($id);
        if ($parcel === null) {
            Response::notFound('Parcel not found.');
        }
        if (!Auth::isSystemAdmin($user) && !Auth::inScope($user['admin_unit_id'] ?? null, (int)$parcel['kebele_id'])) {
            Response::forbidden('Parcel outside your administrative scope.');
        }
        $data = Request::body();
        $allowed = ['status', 'location_description', 'geographic_ref', 'area', 'land_use', 'area_unit'];
        $changes = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $changes[$field] = $data[$field];
            }
        }
        if (count($changes) === 0) {
            Response::error('No updateable fields provided.', 422);
        }
        $repo->update($id, $changes);
        AuditService::logAction($user, 'UPDATE_RECORD', 'parcel', (string)$id, $parcel, $changes, false, 'Parcel updated');
        Response::success($repo->find($id), 'Parcel updated');
    }

    public function versions(array $params): never
    {
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'land_records.view')) {
            Response::forbidden();
        }
        $id = (int)$params['id'];
        $db = App::db();
        $parcel = $db->fetchOne('SELECT * FROM parcels WHERE id = ?', [$id]);
        if ($parcel === null) {
            Response::notFound('Parcel not found.');
        }
        if (!Auth::isSystemAdmin($user) && !Auth::inScope($user['admin_unit_id'] ?? null, (int)$parcel['kebele_id'])) {
            Response::forbidden();
        }
        $versions = $db->fetchAll(
            'SELECT lr.*, u.username AS created_by_name FROM land_records lr
             LEFT JOIN users u ON u.id = lr.created_by WHERE lr.parcel_id = ? ORDER BY lr.version DESC',
            [$id]
        );
        Response::success(['parcel_no' => $parcel['parcel_no'], 'current_version' => $parcel['current_version'], 'versions' => $versions]);
    }

    private function scopeFilter(array $user, array $rows, string $unitColumn): array
    {
        if (Auth::isSystemAdmin($user)) {
            return $rows;
        }
        return array_values(array_filter($rows, fn($r) => Auth::inScope($user['admin_unit_id'] ?? null, (int)$r[$unitColumn])));
    }

    private function nextParcelNo(): string
    {
        $count = (int)App::db()->fetchOne('SELECT COUNT(*) AS c FROM parcels')['c'];
        return 'P-' . str_pad((string)($count + 1), 6, '0', STR_PAD_LEFT);
    }
}