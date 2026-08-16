<?php
declare(strict_types=1);

final class ProcurementController
{
    // ---------------- TENDERS ----------------

    public function tenders(array $params = []): never
    {
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'tenders.view')) {
            Response::forbidden();
        }
        $repo = new TenderRepository();
        $rows = $repo->list(Request::query('status'), Request::query('q'), (int)Request::query('limit', 50), (int)Request::query('offset', 0));
        Response::success(['tenders' => $rows, 'total' => count($rows)]);
    }

    public function tenderDetail(array $params): never
    {
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'tenders.view')) {
            Response::forbidden();
        }
        $repo = new TenderRepository();
        $tender = $repo->detail((int)$params['id']);
        if ($tender === null) {
            Response::notFound('Tender not found.');
        }
        if (!Auth::isSystemAdmin($user) && !Auth::inScope($user['admin_unit_id'] ?? null, (int)($tender['admin_unit_id'] ?? 0))
            && !Auth::hasPermission($user, 'bids.submit')) {
            Response::forbidden('Tender outside your administrative scope.');
        }
        Response::success($tender);
    }

    public function createTender(array $params = []): never
    {
        Csrf::validate();
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'tenders.create')) {
            Response::forbidden();
        }
        $data = Request::body();
        Validator::make()
            ->required('title', $data['title'] ?? null)
            ->required('issuing_org_id', $data['issuing_org_id'] ?? null)
            ->numeric('budget_estimate', $data['budget_estimate'] ?? null)
            ->date('publication_date', $data['publication_date'] ?? null)
            ->date('closing_date', $data['closing_date'] ?? null)
            ->throwIfFails();

        if (!Auth::isSystemAdmin($user) && !Auth::inScope($user['admin_unit_id'] ?? null, (int)($data['admin_unit_id'] ?? $user['admin_unit_id']))) {
            Response::forbidden('Unit outside your administrative scope.');
        }

        $tender = ProcurementService::createTender($user, $data);
        Response::success($tender, 'Tender created', 201);
    }

    public function publishTender(array $params): never
    {
        Csrf::validate();
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'tenders.publish')) {
            Response::forbidden();
        }
        $data = Request::body();
        $tender = ProcurementService::publishTender($user, (int)$params['id'], $data['publication_date'] ?? null, $data['closing_date'] ?? null);
        Response::success($tender, 'Tender published');
    }

    public function amendTender(array $params): never
    {
        Csrf::validate();
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'tenders.create')) {
            Response::forbidden();
        }
        $data = Request::body();
        $tender = ProcurementService::amendTender($user, (int)$params['id'], $data, $data['reason'] ?? null);
        Response::success($tender, 'Tender amended (new version created)');
    }

    public function tenderVersions(array $params): never
    {
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'tenders.view')) {
            Response::forbidden();
        }
        $versions = App::db()->fetchAll(
            'SELECT tv.*, u.username AS changed_by_name FROM tender_versions tv
             LEFT JOIN users u ON u.id = tv.changed_by WHERE tv.tender_id = ? ORDER BY tv.version DESC',
            [(int)$params['id']]
        );
        Response::success(['versions' => $versions]);
    }

    // ---------------- BIDS ----------------

    public function bids(array $params = []): never
    {
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'bids.view_sealed') && !Auth::hasPermission($user, 'tenders.view')) {
            Response::forbidden();
        }
        $tenderId = Request::query('tender_id');
        $where = '1=1';
        $args = [];
        if ($tenderId !== null && $tenderId !== '') {
            $where .= ' AND b.tender_id = ?';
            $args[] = (int)$tenderId;
        }
        $rows = App::db()->fetchAll(
            "SELECT b.id, b.bid_no, b.tender_id, t.tender_no, b.supplier_org_id, o.name AS supplier_name,
                    b.status, b.opening_status, b.amount, b.evaluation_score, b.submitted_at, b.opened_at
             FROM bids b
             JOIN tenders t ON t.id = b.tender_id
             JOIN organizations o ON o.id = b.supplier_org_id
             WHERE $where ORDER BY b.id DESC
             LIMIT 100",
            $args
        );
        // Confidentiality (section 22): sealed prices are never exposed; only metadata.
        foreach ($rows as &$row) {
            $row['amount'] = $row['opening_status'] === 'sealed' ? null : $row['amount'];
        }
        unset($row);
        Response::success(['bids' => $rows, 'total' => count($rows)]);
    }

    public function submitBid(array $params = []): never
    {
        Csrf::validate();
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'bids.submit')) {
            Response::forbidden();
        }
        $data = Request::body();
        Validator::make()
            ->required('tender_id', $data['tender_id'] ?? null)
            ->required('supplier_org_id', $data['supplier_org_id'] ?? null)
            ->required('amount', $data['amount'] ?? null)
            ->numeric('amount', $data['amount'] ?? null)
            ->throwIfFails();

        $uploads = [];
        foreach (Request::files() as $file) {
            if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $uploads[] = $file;
            }
        }
        $bid = ProcurementService::submitBid($user, $data, $uploads);
        Response::success($bid, 'Bid submitted (sealed until opening)', 201);
    }

    public function openBids(array $params): never
    {
        Csrf::validate();
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'bids.open')) {
            Response::forbidden();
        }
        $result = ProcurementService::openBids($user, (int)$params['id']);
        Response::success($result, 'Bids opened');
    }

    public function evaluateBid(array $params): never
    {
        Csrf::validate();
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'bids.evaluate')) {
            Response::forbidden();
        }
        $data = Request::body();
        Validator::make()
            ->required('score', $data['score'] ?? null)
            ->numeric('score', $data['score'] ?? null)
            ->throwIfFails();
        $bid = ProcurementService::evaluateBid($user, (int)$params['id'], (float)$data['score'], $data['comments'] ?? null, $data['criteria'] ?? null);
        Response::success($bid, 'Bid evaluated');
    }

    // ---------------- CONTRACTS & PAYMENTS ----------------

    public function contracts(array $params = []): never
    {
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'contracts.view')) {
            Response::forbidden();
        }
        $rows = App::db()->fetchAll(
            'SELECT c.*, o.name AS supplier_name, t.tender_no FROM contracts c
             LEFT JOIN organizations o ON o.id = c.supplier_org_id
             LEFT JOIN tenders t ON t.id = c.tender_id
             ORDER BY c.id DESC LIMIT 50'
        );
        Response::success(['contracts' => $rows]);
    }

    public function createContract(array $params = []): never
    {
        Csrf::validate();
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'contracts.create')) {
            Response::forbidden();
        }
        $data = Request::body();
        Validator::make()
            ->required('supplier_org_id', $data['supplier_org_id'] ?? null)
            ->required('title', $data['title'] ?? null)
            ->required('value_amount', $data['value_amount'] ?? null)
            ->required('start_date', $data['start_date'] ?? null)
            ->required('end_date', $data['end_date'] ?? null)
            ->numeric('value_amount', $data['value_amount'] ?? null)
            ->date('start_date', $data['start_date'] ?? null)
            ->date('end_date', $data['end_date'] ?? null)
            ->throwIfFails();

        $db = App::db();
        $count = (int)$db->fetchOne('SELECT COUNT(*) AS c FROM contracts')['c'];
        $contractNo = 'CT-' . date('Y') . '-' . str_pad((string)($count + 1), 4, '0', STR_PAD_LEFT);
        $contractId = $db->insert('contracts', [
            'contract_no' => $contractNo,
            'tender_id' => $data['tender_id'] ?? null,
            'bid_id' => $data['bid_id'] ?? null,
            'supplier_org_id' => (int)$data['supplier_org_id'],
            'title' => $data['title'],
            'value_amount' => $data['value_amount'],
            'currency' => $data['currency'] ?? 'ETB',
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'status' => $data['status'] ?? 'draft',
            'created_by' => $user['id'],
        ]);
        if (!empty($data['bid_id'])) {
            $db->update('bids', ['status' => 'awarded'], 'id = ?', [(int)$data['bid_id']]);
            $db->update('tenders', ['status' => 'awarded'], 'id = ?', [(int)$data['tender_id']]);
        }
        IntegrityService::append('audit', 'contract', (string)$contractId, $contractNo);
        AuditService::logAction($user, 'CREATE_CONTRACT', 'contract', (string)$contractId, null, ['contract_no' => $contractNo, 'value' => (float)$data['value_amount']], true, 'Contract created');
        Response::success(['id' => $contractId, 'contract_no' => $contractNo], 'Contract created', 201);
    }

    public function approveContract(array $params): never
    {
        Csrf::validate();
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'contracts.approve')) {
            Response::forbidden();
        }
        $db = App::db();
        $contract = $db->fetchOne('SELECT * FROM contracts WHERE id = ?', [(int)$params['id']]);
        if ($contract === null) {
            Response::notFound('Contract not found.');
        }
        $db->update('contracts', ['status' => 'active', 'approved_by' => $user['id'], 'approved_at' => App::now()], 'id = ?', [(int)$params['id']]);
        AuditService::logAction($user, 'APPROVE', 'contract', (string)$contract['id'], ['status' => 'draft'], ['status' => 'active'], true, 'Contract approved');
        Response::success(null, 'Contract approved');
    }

    public function cancelTender(array $params): never
    {
        Csrf::validate();
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'tenders.cancel')) {
            Response::forbidden();
        }
        try {
            $tender = ProcurementService::cancelTender($user, (int)$params['id'], Request::input('reason'));
        } catch (ApiException $e) {
            Response::error($e->getMessage(), $e->getCode());
        }
        Response::success($tender, 'Tender cancelled');
    }

    public function terminateContract(array $params): never
    {
        Csrf::validate();
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'contracts.terminate')) {
            Response::forbidden();
        }
        try {
            $contract = ProcurementService::terminateContract($user, (int)$params['id'], Request::input('reason'));
        } catch (ApiException $e) {
            Response::error($e->getMessage(), $e->getCode());
        }
        Response::success($contract, 'Contract terminated');
    }

    public function payments(array $params = []): never
    {
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'payments.view')) {
            Response::forbidden();
        }
        $rows = App::db()->fetchAll(
            'SELECT p.*, c.contract_no, o.name AS paid_to FROM payments p
             JOIN contracts c ON c.id = p.contract_id
             JOIN organizations o ON o.id = p.paid_to_org_id
             ORDER BY p.id DESC LIMIT 50'
        );
        Response::success(['payments' => $rows]);
    }

    public function createPayment(array $params = []): never
    {
        Csrf::validate();
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'payments.create')) {
            Response::forbidden();
        }
        $data = Request::body();
        Validator::make()
            ->required('contract_id', $data['contract_id'] ?? null)
            ->required('amount', $data['amount'] ?? null)
            ->required('payment_type', $data['payment_type'] ?? null)
            ->required('payment_date', $data['payment_date'] ?? null)
            ->numeric('amount', $data['amount'] ?? null)
            ->date('payment_date', $data['payment_date'] ?? null)
            ->in('payment_type', $data['payment_type'] ?? null, ['advance','interim','final','other'])
            ->throwIfFails();

        $db = App::db();
        $count = (int)$db->fetchOne('SELECT COUNT(*) AS c FROM payments')['c'];
        $paymentNo = 'PAY-' . date('Y') . '-' . str_pad((string)($count + 1), 4, '0', STR_PAD_LEFT);
        $contract = $db->fetchOne('SELECT * FROM contracts WHERE id = ?', [(int)$data['contract_id']]);
        if ($contract === null) {
            Response::notFound('Contract not found.');
        }
        $paymentId = $db->insert('payments', [
            'payment_no' => $paymentNo,
            'contract_id' => (int)$data['contract_id'],
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? 'ETB',
            'payment_type' => $data['payment_type'],
            'payment_date' => $data['payment_date'],
            'reference' => $data['reference'] ?? null,
            'paid_to_org_id' => (int)$contract['supplier_org_id'],
            'created_by' => $user['id'],
        ]);
        AuditService::logAction($user, 'CREATE_RECORD', 'payment', (string)$paymentId, null, ['payment_no' => $paymentNo, 'amount' => (float)$data['amount']], false, 'Payment recorded');
        Response::success(['id' => $paymentId, 'payment_no' => $paymentNo], 'Payment recorded', 201);
    }
}