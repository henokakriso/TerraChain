<?php
declare(strict_types=1);

final class ReportController
{
    public function dashboard(array $params = []): never
    {
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'reports.view')) {
            Response::forbidden();
        }
        $db = App::db();
        $unitCond = '';
        $args = [];
        if (!Auth::isSystemAdmin($user) && !empty($user['admin_unit_id'])) {
            $scope = self::scopeUnits((int)$user['admin_unit_id']);
            $unitCond = ' AND p.kebele_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')';
            $args = array_merge($args, $scope);
        }
        $land = [
            'total_parcels' => (int)$db->fetchOne("SELECT COUNT(*) AS c FROM parcels p WHERE 1=1 $unitCond", $args)['c'],
            'registered' => (int)$db->fetchOne("SELECT COUNT(*) AS c FROM parcels p WHERE p.status='registered' $unitCond", $args)['c'],
            'pending' => (int)$db->fetchOne("SELECT COUNT(*) AS c FROM parcels p WHERE p.status='pending' $unitCond", $args)['c'],
        ];
        $proc = [
            'active_tenders' => (int)$db->fetchOne("SELECT COUNT(*) AS c FROM tenders WHERE status IN ('published','pending_approval')")['c'],
            'closed_tenders' => (int)$db->fetchOne("SELECT COUNT(*) AS c FROM tenders WHERE status IN ('closed','awarded')")['c'],
            'open_bids' => (int)$db->fetchOne("SELECT COUNT(*) AS c FROM bids WHERE opening_status='sealed'")['c'],
            'contracts_active' => (int)$db->fetchOne("SELECT COUNT(*) AS c FROM contracts WHERE status='active'")['c'],
            'contract_value' => (float)$db->fetchOne("SELECT COALESCE(SUM(value_amount),0) AS s FROM contracts WHERE status IN ('active','completed')")['s'],
        ];
        $auditCount = (int)$db->fetchOne('SELECT COUNT(*) AS c FROM audit_logs')['c'];
        $appStats = $db->fetchAll('SELECT status, COUNT(*) AS c FROM applications GROUP BY status');
        $tenderStats = $db->fetchAll('SELECT status, COUNT(*) AS c FROM tenders GROUP BY status');
        Response::success([
            'land' => $land,
            'procurement' => $proc,
            'audit_events' => $auditCount,
            'applications_by_status' => $appStats,
            'tenders_by_status' => $tenderStats,
        ]);
    }

    private static function scopeUnits(int $unitId): array
    {
        $ids = [$unitId];
        $queue = [$unitId];
        while ($queue) {
            $parent = array_shift($queue);
            $children = App::db()->fetchAll('SELECT id FROM admin_units WHERE parent_id = ?', [$parent]);
            foreach ($children as $child) {
                $ids[] = (int)$child['id'];
                $queue[] = (int)$child['id'];
            }
        }
        return $ids;
    }
}

