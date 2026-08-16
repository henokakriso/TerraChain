<?php
declare(strict_types=1);

final class ParcelRepository extends BaseRepository
{
    protected string $table = 'parcels';

    public function search(?string $q = null, ?string $kebeleId = null, ?string $status = null, int $limit = 50, int $offset = 0): array
    {
        $where = '1=1';
        $params = [];
        if ($q !== null && $q !== '') {
            $where .= ' AND (parcel_no LIKE ? OR location_description LIKE ? OR geographic_ref LIKE ?)';
            $params[] = "%$q%";
            $params[] = "%$q%";
            $params[] = "%$q%";
        }
        if ($kebeleId !== null && $kebeleId !== '') {
            $where .= ' AND kebele_id = ?';
            $params[] = (int)$kebeleId;
        }
        if ($status !== null && $status !== '') {
            $where .= ' AND status = ?';
            $params[] = $status;
        }
        return App::db()->fetchAll(
            "SELECT p.*, u.name_en AS kebele_name
             FROM parcels p LEFT JOIN admin_units u ON u.id = p.kebele_id
             WHERE $where ORDER BY p.id DESC LIMIT $limit OFFSET $offset",
            $params
        );
    }

    public function detail(int $id): ?array
    {
        $parcel = App::db()->fetchOne(
            "SELECT p.*, u.name_en AS kebele_name, u.code AS kebele_code,
                    parent2.name_en AS woreda_name, parent3.name_en AS zone_name, parent4.name_en AS region_name
             FROM parcels p
             LEFT JOIN admin_units u ON u.id = p.kebele_id
             LEFT JOIN admin_units parent2 ON parent2.id = u.parent_id
             LEFT JOIN admin_units parent3 ON parent3.id = parent2.parent_id
             LEFT JOIN admin_units parent4 ON parent4.id = parent3.parent_id
             WHERE p.id = ?",
            [$id]
        );
        if ($parcel === null) {
            return null;
        }
        $parcel['versions'] = App::db()->fetchAll(
            'SELECT * FROM land_records WHERE parcel_id = ? ORDER BY version ASC',
            [$id]
        );
        $parcel['owners'] = App::db()->fetchAll(
            'SELECT o.*, c.first_name, c.father_name, c.grand_father_name, c.national_id
             FROM ownership_records o JOIN citizens c ON c.id = o.citizen_id
             WHERE o.parcel_id = ? AND o.is_current = 1',
            [$id]
        );
        $parcel['transactions'] = App::db()->fetchAll(
            'SELECT * FROM transactions WHERE parcel_id = ? ORDER BY id DESC',
            [$id]
        );
        $parcel['documents'] = App::db()->fetchAll(
            "SELECT * FROM documents WHERE owner_type = 'parcel' AND owner_id = ?",
            [$id]
        );
        return $parcel;
    }
}

