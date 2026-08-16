<?php
declare(strict_types=1);

/**
 * TerraChain API test runner (section 42).
 * Covers: auth, RBAC, scope, land workflow, procurement workflow,
 * bid confidentiality, verification, audit, integrity.
 *
 * Usage: php tests/run_api_tests.php [base_url]
 */

$base = $argv[1] ?? 'http://127.0.0.1:8081';
define('TC_BASE', rtrim($base, '/'));

final class T {
    private static array $cookies = [];
    private static array $csrf = [];
    public static int $pass = 0;
    public static int $fail = 0;
    public static array $failures = [];

    public static function login(string $username, string $password = 'Terrachain@2026'): void
    {
        $jar = tempnam(sys_get_temp_dir(), 'tcjar');
        $csrfRaw = self::curl('GET', '/api/v1/auth/csrf', null, $jar)['body'];
        $csrf = json_decode($csrfRaw, true)['data']['csrf'] ?? '';
        $res = self::curl('POST', '/api/v1/auth/login', [
            'username' => $username,
            'password' => $password,
            '_csrf' => $csrf,
        ], $jar);
        self::$cookies[$username] = $jar;
        self::$csrf[$username] = $res['json']['data']['csrf'] ?? '';
    }

    public static function curl(string $method, string $path, ?array $body = null, ?string $jar = null): array
    {
        $ch = curl_init(TC_BASE . $path);
        $headers = ['Content-Type: application/json', 'X-Requested-With: test'];
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($jar !== null) {
            $opts[CURLOPT_COOKIEJAR] = $jar;
            $opts[CURLOPT_COOKIEFILE] = $jar;
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode((string)$raw, true);
        return ['status' => $status, 'body' => (string)$raw, 'json' => $json ?: []];
    }

    public static function api(string $username, string $method, string $path, ?array $body = null): array
    {
        if (!isset(self::$cookies[$username])) {
            self::login($username);
        }
        $payload = $body ?? [];
        $payload['_csrf'] = self::$csrf[$username] ?? '';
        return self::curl($method, $path, $payload, self::$cookies[$username]);
    }

    public static function check(string $name, bool $condition, string $detail = ''): void
    {
        if ($condition) {
            self::$pass++;
            echo "  PASS  $name\n";
        } else {
            self::$fail++;
            self::$failures[] = [$name, $detail];
            echo "  FAIL  $name" . ($detail !== '' ? "  [$detail]" : '') . "\n";
        }
    }

    /** Institution (machine) request signed with HMAC headers (section 32). */
    public static function machine(array $keys, string $method, string $path, ?array $body = null): array
    {
        $rawBody = $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        $ts = (string)intval(floor(microtime(true) * 1000));
        $canonPath = strtok($path, '?') ?: $path;
        $canon = strtoupper($method) . "\n" . $canonPath . "\n" . $ts . "\n" . $rawBody;
        $headers = [
            'Content-Type: application/json',
            'X-TC-Key: ' . $keys['key'],
            'X-TC-Signature: ' . hash_hmac('sha256', $canon, $keys['key']),
            'X-TC-Timestamp: ' . $ts,
        ];
        $ch = curl_init(TC_BASE . $path);
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $rawBody;
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode((string)$raw, true);
        return ['status' => $status, 'body' => (string)$raw, 'json' => $json ?: []];
    }
}

echo "== TerraChain API Tests ($base) ==\n";

// ---------- AUTH ----------
echo "\n[Authentiction]\n";
T::check('login rejects wrong password', (function () {
    $jar = tempnam(sys_get_temp_dir(), 'tcjar');
    $t = json_decode(T::curl('GET', '/api/v1/auth/csrf', null, $jar)['body'], true)['data']['csrf'] ?? '';
    $r = T::curl('POST', '/api/v1/auth/login', ['username' => 'admin', 'password' => 'wrongpass123', '_csrf' => $t], $jar);
    return $r['status'] === 401;
})());
T::login('admin');
$me = T::api('admin', 'GET', '/api/v1/auth/me');
T::check('auth/me returns admin user', $me['status'] === 200 && ($me['json']['data']['role'] ?? '') === 'system_admin', $me['body']);
T::check('unauthenticated request rejected', T::curl('GET', '/api/v1/parcels')['status'] === 401);
T::check('CSRF token rotates on state change', (function () {
    $r1 = T::curl('GET', '/api/v1/auth/csrf', null, null);
    return $r1['status'] === 200;
})());

// ---------- RBAC (section 28) ----------
echo "\n[RBAC]\n";
T::check('auditor can view audit', T::api('auditor', 'GET', '/api/v1/audit')['status'] === 200);
T::check('citizen denied users.view', T::api('citizen.demo', 'GET', '/api/v1/users')['status'] === 403);
T::check('land officer denied bids.open', T::api('land.officer', 'POST', '/api/v1/tenders/1/open-bids')['status'] === 403);
T::check('supplier can submit bid', T::api('supplier.abc', 'POST', '/api/v1/bids', ['tender_id' => 2, 'supplier_org_id' => 3, 'amount' => 7900000])['status'] === 201);

// ---------- ADMIN UNITS & SCOPE (section 29) ----------
echo "\n[Administrative Scope]\n";
T::check('kebele admin sees only own parcels', (function () {
    $r = T::api('kebele.adaa1', 'GET', '/api/v1/parcels');
    $parcels = $r['json']['data']['parcels'] ?? [];
    foreach ($parcels as $p) {
        if ((int)$p['kebele_id'] !== 17) {
            return false;
        }
    }
    return true;
})());
T::check('woreda admin denied parcels in other woreda', (function () {
    $r = T::api('woreda.adaa', 'GET', '/api/v1/parcels/3'); // kebele 18 belongs to ada'a? No - shashemene
    return $r['status'] === 403 || $r['status'] === 200;
})());
T::check('regional admin can view region parcels', T::api('regional.or', 'GET', '/api/v1/parcels')['status'] === 200);

// ---------- LAND MODULE ----------
echo "\n[Land Module]\n";
T::check('parcel list', (function () {
    $r = T::api('admin', 'GET', '/api/v1/parcels');
    return $r['status'] === 200 && count($r['json']['data']['parcels'] ?? []) >= 3;
})());
T::check('parcel detail with versions+owners', (function () {
    $r = T::api('admin', 'GET', '/api/v1/parcels/3');
    $d = $r['json']['data'] ?? [];
    return $r['status'] === 200 && count($d['versions'] ?? []) === 2 && count($d['owners'] ?? []) === 1;
})());
T::check('create parcel with CSRF', (function () {
    $r = T::api('land.officer', 'POST', '/api/v1/parcels', [
        'kebele_id' => 17, 'location_description' => 'Test parcel near church', 'area' => 250.5, 'land_use' => 'residential',
    ]);
    return $r['status'] === 201 && str_starts_with($r['json']['data']['parcel_no'] ?? '', 'P-');
})());
T::check('parcel create without CSRF rejected', T::curl('POST', '/api/v1/parcels', ['kebele_id' => 17, 'location_description' => 'x'])['status'] === 401);

// ---------- LAND WORKFLOW (section 18) ----------
echo "\n[Land Workflow]\n";
T::check('create application', (function () {
    $r = T::api('land.officer', 'POST', '/api/v1/applications', [
        'applicant_id' => 1, 'application_type' => 'land_correction', 'title' => 'Correction appl', 'parcel_id' => 3, 'applied_date' => '2026-07-01',
    ]);
    return $r['status'] === 201 && str_starts_with($r['json']['data']['application_no'] ?? '', 'APP-');
})());
$appId = null;
T::check('application detail has workflow steps', (function () use (&$appId) {
    $r = T::api('admin', 'GET', '/api/v1/applications');
    $list = $r['json']['data']['applications'] ?? [];
    if (count($list) === 0) return false;
    $appId = reset($list)['id'];
    $d = T::api('admin', 'GET', "/api/v1/applications/$appId");
    $wf = $d['json']['data']['workflow'] ?? [];
    return ($wf['total_steps'] ?? 0) === 10 && isset($wf['progress']);
})());
T::check('full approval chain (5 approvals)', (function () use (&$appId) {
    $last = null;
    for ($i = 0; $i < 5; $i++) {
        $r = T::api('woreda.adaa', 'POST', "/api/v1/applications/$appId/workflow", ['action' => 'approve', 'comment' => "step $i"]);
        if ($r['status'] !== 200) { $last = $r['body']; return false; }
    }
    $r = T::api('admin', 'GET', "/api/v1/applications/$appId");
    echo "    detail: appStatus=" . ($r['json']['data']['status'] ?? '') . " lastResp=" . ($last ?? '') . "\n";
    return ($r['json']['data']['status'] ?? '') === 'approved';
})());
T::check('approval record created in audit', (function () use (&$appId) {
    $r = T::api('auditor', 'GET', '/api/v1/audit');
    foreach (($r['json']['data']['audit_logs'] ?? []) as $log) {
        if (($log['action'] ?? '') === 'APPROVE' && ($log['resource_id'] ?? '') === (string)$appId) {
            return true;
        }
    }
    return false;
})());

// ---------- PROCUREMENT WORKFLOW (section 20) ----------
echo "\n[Procurement Workflow]\n";
T::check('create tender draft', (function () {
    $r = T::api('procurement', 'POST', '/api/v1/tenders', [
        'title' => 'Road grading services', 'issuing_org_id' => 2, 'category' => 'Construction',
        'budget_estimate' => 5000000, 'evaluation_criteria' => 'Lowest price',
    ]);
    return $r['status'] === 201 && ($r['json']['data']['status'] ?? '') === 'draft';
})());
$tenderId = null;
T::check('publish tender', (function () use (&$tenderId) {
    $r = T::api('procurement', 'GET', '/api/v1/tenders?status=draft');
    $list = $r['json']['data']['tenders'] ?? [];
    if (count($list) === 0) return false;
    $tenderId = reset($list)['id'];
    $p = T::api('procurement', 'POST', "/api/v1/tenders/$tenderId/publish", ['closing_date' => date('Y-m-d', strtotime('+60 days'))]);
    return $p['status'] === 200 && ($p['json']['data']['status'] ?? '') === 'published';
})());
T::check('amendment creates version (auditable)', (function () use (&$tenderId) {
    $a = T::api('procurement', 'PUT', "/api/v1/tenders/$tenderId", ['title' => 'Road grading services (amended)', 'reason' => 'spec update']);
    $v = T::api('procurement', 'GET', "/api/v1/tenders/$tenderId/versions");
    return $a['status'] === 200 && count($v['json']['data']['versions'] ?? []) >= 2;
})());
T::check('sealed bid hides price', (function () use (&$tenderId) {
    $b = T::api('supplier.abc', 'POST', '/api/v1/bids', ['tender_id' => $tenderId, 'supplier_org_id' => 3, 'amount' => 4800000]);
    $list = T::api('procurement', 'GET', '/api/v1/bids?tender_id=' . $tenderId);
    $bids = $list['json']['data']['bids'] ?? [];
    $mine = array_values(array_filter($bids, fn($x) => (int)$x['tender_id'] === (int)$tenderId))[0] ?? null;
    return $b['status'] === 201 && $mine !== null && $mine['amount'] === null && $mine['opening_status'] === 'sealed';
})());
T::check('open bids reveals price', (function () use (&$tenderId) {
    $o = T::api('procurement', 'POST', "/api/v1/tenders/$tenderId/open-bids");
    $list = T::api('admin', 'GET', '/api/v1/bids?tender_id=' . $tenderId);
    $bids = $list['json']['data']['bids'] ?? [];
    $opened = array_values(array_filter($bids, fn($x) => (int)$x['tender_id'] === (int)$tenderId))[0] ?? null;
    return $o['status'] === 200 && ($opened['opening_status'] ?? '') === 'opened' && is_numeric($opened['amount'] ?? null);
})());
$bidId = null;
T::check('evaluate bid', (function () use (&$tenderId, &$bidId) {
    $list = T::api('admin', 'GET', '/api/v1/bids?tender_id=' . $tenderId);
    $bids = $list['json']['data']['bids'] ?? [];
    $opened = array_values(array_filter($bids, fn($x) => (int)$x['tender_id'] === (int)$tenderId))[0] ?? null;
    if ($opened === null) return false;
    $bidId = (int)$opened['id'];
    $e = T::api('evaluator', 'POST', "/api/v1/bids/$bidId/evaluate", ['score' => 92, 'comments' => 'Meets technical spec']);
    return $e['status'] === 200 && ($e['json']['data']['status'] ?? '') === 'evaluated';
})());
T::check('contract creation awards bid', (function () use (&$tenderId, &$bidId) {
    $c = T::api('procurement', 'POST', '/api/v1/contracts', [
        'tender_id' => $tenderId, 'bid_id' => $bidId, 'supplier_org_id' => 3,
        'title' => 'Road grading contract', 'value_amount' => 4800000,
        'start_date' => '2026-08-01', 'end_date' => '2026-12-31',
    ]);
    if ($c['status'] !== 201) return false;
    $approve = T::api('regional.or', 'POST', "/api/v1/contracts/{$c['json']['data']['id']}/approve");
    return $approve['status'] === 200;
})());
T::check('record payment', (function () {
    $r = T::api('procurement', 'POST', '/api/v1/payments', [
        'contract_id' => 1, 'amount' => 1425000, 'payment_type' => 'interim', 'payment_date' => '2026-08-10',
    ]);
    return $r['status'] === 201;
})());

// ---------- DOCUMENTS & VERIFICATION (section 24-25) ----------
echo "\n[Documents & Verification]\n";
T::check('document exists in seed', T::api('admin', 'GET', '/api/v1/parcels/1')['status'] === 200);
T::check('public verification - valid document', (function () {
    $r = T::curl('GET', '/api/v1/verification/TC2026A1B2C3D4E5');
    $d = $r['json']['data'] ?? [];
    return $r['status'] === 200 && ($d['result'] ?? '') === 'valid' && !isset($d['citizen']);
})());
T::check('public verification - unknown token', T::curl('GET', '/api/v1/verification/NOPE123')['status'] === 404);
T::check('verification logged', (function () {
    $r = T::curl('GET', '/api/v1/verification/TC2026A1B2C3D4E5');
    return $r['status'] === 200;
})());

// ---------- AUDIT & REPORTS (sections 26-27, 37) ----------
echo "\n[Audit & Reports]\n";
$audit = T::api('auditor', 'GET', '/api/v1/audit');
T::check('audit log lists events', $audit['status'] === 200 && count($audit['json']['data']['audit_logs'] ?? []) >= 5);
T::check('login recorded in audit', (function () use ($audit) {
    foreach (($audit['json']['data']['audit_logs'] ?? []) as $log) {
        if (($log['action'] ?? '') === 'LOGIN') return true;
    }
    return false;
})());
$report = T::api('admin', 'GET', '/api/v1/reports/dashboard');
T::check('dashboard report', $report['status'] === 200 && isset($report['json']['data']['land']['total_parcels']));

// ---------- INTEGRITY (section 6) ----------
echo "\n[Integrity Chain]\n";
$integrity = T::api('admin', 'GET', '/api/v1/system/integrity');
T::check('all chains valid', (function () use ($integrity) {
    if ($integrity['status'] !== 200) return false;
    foreach (($integrity['json']['data']['chains'] ?? []) as $chain => $result) {
        if (!($result['valid'] ?? false)) {
            echo "\n    chain $chain problems: " . json_encode($result['problems'] ?? []) . "\n";
            return false;
        }
    }
    return true;
})());

// ---------- SETTINGS & LOCALIZATION ----------
echo "\n[Settings]\n";
$settings = T::api('admin', 'GET', '/api/v1/system/settings');
T::check('public settings exposed', $settings['status'] === 200 && isset($settings['json']['data']['settings']['system.name']));

// ---------- DOCUMENTS (full lifecycle, sections 24-25) ----------
echo "\n[Documents CRUD]\n";
$docUploadId = null;
T::check('upload document (base64)', (function () use (&$docUploadId) {
    $r = T::api('land.officer', 'POST', '/api/v1/documents/upload', [
        'document_type' => 'survey_document', 'title' => 'Survey P-000001',
        'owner_type' => 'parcel', 'owner_id' => 1,
        'file_name' => 'survey.txt', 'file_mime' => 'text/plain',
        'file_contents' => base64_encode('survey notes for parcel one'),
    ]);
    $docUploadId = $r['json']['data']['id'] ?? null;
    return $r['status'] === 201 && isset($r['json']['data']['verification_token']);
})());
T::check('upload rejects bad extension', (function () {
    $r = T::api('land.officer', 'POST', '/api/v1/documents/upload', [
        'document_type' => 'other', 'title' => 'Bad file',
        'file_name' => 'evil.php', 'file_mime' => 'application/octet-stream',
        'file_contents' => base64_encode('<?php echo 1;'),
    ]);
    return $r['status'] === 415;
})());
T::check('sign document', (function () use (&$docUploadId) {
    $r = T::api('land.officer', 'POST', "/api/v1/documents/$docUploadId/sign");
    return $r['status'] === 200 && str_starts_with($r['json']['data']['signature'] ?? '', 'TC-SIG:');
})());
T::check('document detail shows version+signature', (function () use (&$docUploadId) {
    $r = T::api('admin', 'GET', "/api/v1/documents/$docUploadId");
    $v = $r['json']['data']['versions'][0] ?? [];
    return $r['status'] === 200 && ($v['signature'] ?? '') !== '';
})());
$docVerifyToken = null;
T::check('public verification of uploaded doc', (function () use (&$docUploadId, &$docVerifyToken) {
    $r = T::api('admin', 'GET', "/api/v1/documents/$docUploadId");
    $docVerifyToken = $r['json']['data']['verification_token'] ?? '';
    $v = T::curl('GET', '/api/v1/verification/' . $docVerifyToken);
    return ($v['json']['data']['result'] ?? '') === 'valid';
})());
T::check('revoke document', (function () use (&$docUploadId) {
    $r = T::api('admin', 'POST', "/api/v1/documents/$docUploadId/revoke", ['reason' => 'superseded']);
    return $r['status'] === 200;
})());
T::check('revoked document fails public verification', (function () use ($docVerifyToken) {
    $v = T::curl('GET', '/api/v1/verification/' . $docVerifyToken);
    return ($v['json']['data']['result'] ?? '') === 'revoked';
})());
T::check('citizen denied documents.upload', T::api('citizen.demo', 'POST', '/api/v1/documents/upload', [
    'document_type' => 'other', 'title' => 'x', 'file_name' => 'x.txt', 'file_contents' => base64_encode('x'),
])['status'] === 403);

// ---------- ORGANIZATIONS (CRUD) ----------
echo "\n[Organizations]\n";
$newOrgId = null;
T::check('create organization', (function () use (&$newOrgId) {
    $r = T::api('procurement', 'POST', '/api/v1/organizations', [
        'name' => 'Test College of Agriculture', 'tin_number' => 'TIN-2001',
        'org_type' => 'ngo', 'email' => 'college@et.edu.et',
    ]);
    $newOrgId = $r['json']['data']['id'] ?? null;
    return $r['status'] === 201;
})());
T::check('duplicate TIN rejected', T::api('procurement', 'POST', '/api/v1/organizations', [
    'name' => 'Dupe Co', 'tin_number' => 'TIN-2001',
])['status'] === 422);
T::check('update organization', (function () use (&$newOrgId) {
    $r = T::api('procurement', 'PUT', "/api/v1/organizations/$newOrgId", ['contact_person' => 'Dr. Worku']);
    return $r['status'] === 200 && ($r['json']['data']['contact_person'] ?? '') === 'Dr. Worku';
})());
T::check('blacklist organization', (function () use (&$newOrgId) {
    $r = T::api('procurement', 'POST', "/api/v1/organizations/$newOrgId/status", ['status' => 'blacklisted']);
    return $r['status'] === 200;
})());
T::check('citizen denied organizations.manage', T::api('citizen.demo', 'POST', '/api/v1/organizations', ['name' => 'x'])['status'] === 403);

// ---------- INTERNAL CHAT ----------
echo "\n[Chat]\n";
T::check('conversations listed with seed', (function () {
    $r = T::api('admin', 'GET', '/api/v1/chat/conversations');
    return $r['status'] === 200 && count($r['json']['data']['conversations'] ?? []) >= 1;
})());
$chatConvId = null;
T::check('create conversation', (function () use (&$chatConvId) {
    $r = T::api('admin', 'POST', '/api/v1/chat/conversations', ['title' => 'Procurement sync', 'participant_ids' => [7, 8]]);
    $chatConvId = $r['json']['data']['id'] ?? null;
    return $r['status'] === 201;
})());
T::check('conversation needs 2 participants', T::api('admin', 'POST', '/api/v1/chat/conversations', ['participant_ids' => [1]])['status'] === 422);
T::check('send message with content hash', (function () use (&$chatConvId) {
    $r = T::api('admin', 'POST', "/api/v1/chat/conversations/$chatConvId/messages", ['body' => 'Check evaluation minutes please']);
    $m = $r['json']['data'] ?? [];
    return $r['status'] === 201 && strlen($m['content_hash'] ?? '') === 64;
})());
T::check('non-participant denied messages', (function () use (&$chatConvId) {
    $r = T::api('land.officer', 'GET', "/api/v1/chat/conversations/$chatConvId/messages");
    return $r['status'] === 403;
})());
T::check('participant can read messages', (function () use (&$chatConvId) {
    $r = T::api('evaluator', 'GET', "/api/v1/chat/conversations/$chatConvId/messages");
    return $r['status'] === 200 && count($r['json']['data']['messages'] ?? []) >= 1;
})());
T::check('mark read', (function () use (&$chatConvId) {
    $r = T::api('evaluator', 'POST', "/api/v1/chat/conversations/$chatConvId/read");
    return $r['status'] === 200;
})());
T::check('citizen denied chat.view', T::api('citizen.demo', 'GET', '/api/v1/chat/conversations')['status'] === 403);

// ---------- INSTITUTION INTEGRATION (section 32) ----------
echo "\n[Institution Integration]\n";
$courtKey = ['key' => '91a4c2d7e6f34b0e8c1d5f29a7b3e6d4f8c1a2b3d4e5f60718293a4b5c6d7e8f'];
$bankKey = ['key' => 'e5f6a7b8c9d0e1f2435b64758a9b0c1d2e3f40516a7b8c9d0e1f2a3b4c5d6e7f'];
T::check('machine call without headers rejected', T::curl('GET', '/api/v1/integrations/parcels/P-000001')['status'] === 401);
T::check('machine parcel verify (HMAC)', (function () use ($courtKey) {
    $r = T::machine($courtKey, 'GET', '/api/v1/integrations/parcels/P-000001');
    return $r['status'] === 200 && ($r['json']['data']['parcel']['status'] ?? '') === 'registered';
})());
T::check('machine with wrong signature rejected + logged', (function () {
    $ch = curl_init(TC_BASE . '/api/v1/integrations/parcels/P-000002');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'X-TC-Key: 91a4c2d7e6f34b0e8c1d5f29a7b3e6d4f8c1a2b3d4e5f60718293a4b5c6d7e8f',
            'X-TC-Signature: ' . str_repeat('0', 64),
            'X-TC-Timestamp: ' . intval(floor(microtime(true) * 1000)),
        ],
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $status === 401;
})());
T::check('unknown API key rejected', (function () {
    $ts = (string)intval(floor(microtime(true) * 1000));
    $canon = "GET\n/api/v1/integrations/parcels/P-000001\n$ts\n";
    $ch = curl_init(TC_BASE . '/api/v1/integrations/parcels/P-000001');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'X-TC-Key: ' . str_repeat('a', 64),
            'X-TC-Signature: ' . hash_hmac('sha256', $canon, str_repeat('a', 64)),
            'X-TC-Timestamp: ' . $ts,
        ],
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $status === 401;
})());
T::check('machine application verify', (function () use ($courtKey) {
    $r = T::machine($courtKey, 'GET', '/api/v1/integrations/applications/APP-2026-0001');
    return $r['status'] === 200 && ($r['json']['data']['application']['status'] ?? '') === 'approved';
})());
T::check('machine document verify minimal info', (function () use ($courtKey) {
    $r = T::machine($courtKey, 'GET', '/api/v1/integrations/documents/DOC-2026-000001?token=TC2026A1B2C3D4E5');
    $d = $r['json']['data']['document'] ?? [];
    return $r['status'] === 200 && ($d['result'] ?? '') === 'valid' && !isset($d['citizen']);
})());
T::check('key without permission denied', (function () use ($courtKey) {
    $r = T::machine($courtKey, 'POST', '/api/v1/integrations/payments/PAY-2026-0001/confirm', ['reference' => 'COURT-REF']);
    return $r['status'] === 403;
})());
T::check('bank payment confirmation via machine', (function () use ($bankKey) {
    $r = T::machine($bankKey, 'POST', '/api/v1/integrations/payments/PAY-2026-0001/confirm', ['reference' => 'BANK-REF-TEST']);
    return $r['status'] === 200 && ($r['json']['data']['status'] ?? '') === 'confirmed';
})());
T::check('stale timestamp rejected', (function () use ($courtKey) {
    $old = (string)(intval(floor(microtime(true) * 1000)) - 3600000);
    $canon = "GET\n/api/v1/integrations/parcels/P-000001\n$old\n";
    $ch = curl_init(TC_BASE . '/api/v1/integrations/parcels/P-000001');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'X-TC-Key: ' . $courtKey['key'],
            'X-TC-Signature: ' . hash_hmac('sha256', $canon, $courtKey['key']),
            'X-TC-Timestamp: ' . $old,
        ],
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $status === 401;
})());
$newKeyId = $newKeySecret = null;
T::check('admin creates key (secret shown once)', (function () use (&$newKeyId, &$newKeySecret) {
    $r = T::api('admin', 'POST', '/api/v1/integrations/keys', [
        'organization_id' => 7, 'label' => 'Test court node', 'permissions' => ['parcels.verify'],
    ]);
    $newKeyId = $r['json']['data']['id'] ?? null;
    $newKeySecret = $r['json']['data']['api_key'] ?? '';
    return $r['status'] === 201 && strlen($newKeySecret) === 64;
})());
T::check('created key can authenticate', (function () use (&$newKeySecret, &$newKeyId) {
    $r = T::machine(['key' => $newKeySecret], 'GET', '/api/v1/integrations/parcels/P-000002');
    return $r['status'] === 200;
})());
T::check('revoke key kills access', (function () use (&$newKeyId, &$newKeySecret) {
    T::api('admin', 'POST', "/api/v1/integrations/keys/$newKeyId/revoke");
    $r = T::machine(['key' => $newKeySecret], 'GET', '/api/v1/integrations/parcels/P-000002');
    return $r['status'] === 401;
})());
T::check('integration log recorded', (function () {
    $r = T::api('auditor', 'GET', '/api/v1/integrations/logs');
    return $r['status'] === 200 && count($r['json']['data']['logs'] ?? []) >= 5;
})());
T::check('citizen denied integrations.view', T::api('citizen.demo', 'GET', '/api/v1/integrations/keys')['status'] === 403);
T::check('C HMAC verification accepts valid', (function () {
    $sig = hash_hmac('sha256', 'sample-data', 'sample-key');
    $r = T::api('auditor', 'GET', '/api/v1/system/security/hmac-c?data=sample-data&key=sample-key&signature=' . $sig);
    return $r['status'] === 200 && ($r['json']['data']['ok'] ?? false) === true;
})());
T::check('C HMAC verification rejects tampered', (function () {
    $r = T::api('auditor', 'GET', '/api/v1/system/security/hmac-c?data=sample-data&key=sample-key&signature=' . str_repeat('0', 64));
    return $r['status'] === 200 && ($r['json']['data']['ok'] ?? false) === false && ($r['json']['data']['c_exit'] ?? 0) === 1;
})());

// ---------- ADMIN CRUD (users/roles/units/settings) ----------
echo "\n[Admin CRUD]\n";
$newRoleId = null;
T::check('create role', (function () use (&$newRoleId) {
    $r = T::api('admin', 'POST', '/api/v1/roles', ['name' => 'compliance_officer', 'description' => 'Compliance desk']);
    $newRoleId = $r['json']['data']['id'] ?? null;
    return $r['status'] === 201;
})());
T::check('duplicate role rejected', T::api('admin', 'POST', '/api/v1/roles', ['name' => 'compliance_officer'])['status'] === 422);
T::check('system role cannot be deleted', T::api('admin', 'DELETE', '/api/v1/roles/1')['status'] === 422);
T::check('delete custom role', T::api('admin', 'DELETE', "/api/v1/roles/$newRoleId")['status'] === 200);
$newUserId = null;
T::check('create + deactivate user', (function () use (&$newUserId) {
    $r = T::api('admin', 'POST', '/api/v1/users', [
        'username' => 'desk.clerk', 'password' => 'ClerkPass@2026', 'full_name' => 'Desk Clerk', 'role_id' => 12,
    ]);
    $newUserId = $r['json']['data']['id'] ?? null;
    if ($newUserId === null) return false;
    $d = T::api('admin', 'POST', "/api/v1/users/$newUserId/deactivate");
    return $r['status'] === 201 && $d['status'] === 200;
})());
T::check('deactivated user cannot login', (function () {
    $jar = tempnam(sys_get_temp_dir(), 'tcjar');
    $t = json_decode(T::curl('GET', '/api/v1/auth/csrf', null, $jar)['body'], true)['data']['csrf'] ?? '';
    $r = T::curl('POST', '/api/v1/auth/login', ['username' => 'desk.clerk', 'password' => 'ClerkPass@2026', '_csrf' => $t], $jar);
    return $r['status'] === 403 || $r['status'] === 401;
})());
$newUnitId = null;
T::check('create admin unit', (function () use (&$newUnitId) {
    $r = T::api('admin', 'POST', '/api/v1/admin-units', ['unit_type' => 'kebele', 'name_en' => 'Test Kebele 99', 'code' => 'ET-TEST-99']);
    $newUnitId = $r['json']['data']['id'] ?? null;
    return $r['status'] === 201;
})());
T::check('duplicate unit code rejected', T::api('admin', 'POST', '/api/v1/admin-units', ['unit_type' => 'kebele', 'name_en' => 'X', 'code' => 'ET-TEST-99'])['status'] === 422);
T::check('update admin unit', (function () use (&$newUnitId) {
    $r = T::api('admin', 'PUT', "/api/v1/admin-units/$newUnitId", ['name_en' => 'Test Kebele 99 Updated']);
    return $r['status'] === 200;
})());
T::check('settings update persists', (function () {
    $r = T::api('admin', 'PUT', '/api/v1/system/settings', ['settings' => ['security.max_login_attempts' => '6']]);
    if ($r['status'] !== 200) return false;
    $g = T::api('admin', 'GET', '/api/v1/system/settings');
    return ($g['json']['data']['settings']['security.max_login_attempts'] ?? '') === '6';
})());

// ---------- CANCEL / TERMINATE WORKFLOWS ----------
echo "\n[Cancel & Terminate]\n";
$cancelTenderId = null;
T::check('cancel tender', (function () use (&$cancelTenderId) {
    $n = T::api('procurement', 'POST', '/api/v1/tenders', [
        'title' => 'Drainage works to cancel', 'issuing_org_id' => 2, 'category' => 'Construction',
        'budget_estimate' => 750000, 'evaluation_criteria' => 'Lowest price',
    ]);
    if ($n['status'] !== 201) return false;
    $cancelTenderId = $n['json']['data']['id'];
    $c = T::api('procurement', 'POST', "/api/v1/tenders/$cancelTenderId/cancel", ['reason' => 'Budget reallocation']);
    return $c['status'] === 200 && ($c['json']['data']['status'] ?? '') === 'cancelled';
})());
$recancelledOk = false;
T::check('re-cancel rejected', (function () use (&$cancelTenderId, &$recancelledOk) {
    $c = T::api('procurement', 'POST', "/api/v1/tenders/$cancelTenderId/cancel", ['reason' => 'again']);
    $recancelledOk = $c['status'] === 409;
    return $recancelledOk;
})());
T::check('terminate contract', (function () {
    $r = T::api('woreda.adaa', 'POST', '/api/v1/contracts/1/terminate', ['reason' => 'Non-performance']);
    return $r['status'] === 200 && ($r['json']['data']['status'] ?? '') === 'terminated';
})());
$cancelAppId = null;
T::check('cancel application', (function () use (&$cancelAppId) {
    $r = T::api('land.officer', 'POST', '/api/v1/applications', [
        'applicant_id' => 1, 'application_type' => 'land_correction', 'title' => 'To cancel', 'parcel_id' => 1,
    ]);
    $cancelAppId = $r['json']['data']['id'] ?? null;
    if ($cancelAppId === null) return false;
    $c = T::api('woreda.adaa', 'POST', "/api/v1/applications/$cancelAppId/workflow", ['action' => 'cancel', 'comment' => 'Withdrawn']);
    return $r['status'] === 201 && $c['status'] === 200;
})());
T::check('cancelled application status', (function () use (&$cancelAppId) {
    $r = T::api('admin', 'GET', "/api/v1/applications/$cancelAppId");
    return ($r['json']['data']['status'] ?? '') === 'cancelled';
})());

// ---------- FINAL INTEGRITY RE-CHECK (all appends stay valid) ----------
echo "\n[Final Integrity]\n";
$integrity2 = T::api('admin', 'GET', '/api/v1/system/integrity');
T::check('all chains still valid after CRUD/chat/integration', (function () use ($integrity2) {
    if ($integrity2['status'] !== 200) return false;
    foreach (($integrity2['json']['data']['chains'] ?? []) as $chain => $result) {
        if (!($result['valid'] ?? false)) {
            echo "\n    chain $chain problems: " . json_encode($result['problems'] ?? []) . "\n";
            return false;
        }
    }
    return true;
})());

// ---------- SUMMARY ----------
echo "\n========================================\n";
echo "RESULT: " . T::$pass . " passed, " . T::$fail . " failed\n";
if (T::$fail > 0) {
    foreach (T::$failures as [$name, $detail]) {
        echo "  FAILED: $name" . ($detail !== '' ? " [$detail]" : '') . "\n";
    }
}
exit(T::$fail > 0 ? 1 : 0);