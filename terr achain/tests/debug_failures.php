<?php
// Replicates suite procurement sequence with raw responses
$base = 'http://127.0.0.1:8081';
$cookies = [];
$csrf = [];
function tcurl($m, $p, $b, $jar) {
    global $base;
    $ch = curl_init($base . $p);
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $m, CURLOPT_HTTPHEADER => ['Content-Type: application/json']];
    if ($b !== null) $o[CURLOPT_POSTFIELDS] = json_encode($b);
    if ($jar) { $o[CURLOPT_COOKIEJAR] = $jar; $o[CURLOPT_COOKIEFILE] = $jar; }
    curl_setopt_array($ch, $o);
    $raw = curl_exec($ch);
    $st = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, json_decode($raw, true)];
}
function tlogin($user) {
    global $cookies, $csrf;
    $jar = tempnam(sys_get_temp_dir(), 'tcjar');
    $t = tcurl('GET', '/api/v1/auth/csrf', null, $jar)[1]['data']['csrf'];
    $r = tcurl('POST', '/api/v1/auth/login', ['username' => $user, 'password' => 'Terrachain@2026', '_csrf' => $t], $jar);
    $cookies[$user] = $jar;
    $csrf[$user] = $r[1]['data']['csrf'] ?? '';
}
function tapi($user, $m, $p, $b = null) {
    global $cookies, $csrf;
    if (!isset($cookies[$user])) tlogin($user);
    if ($b !== null) $b = array_merge(['_csrf' => $csrf[$user] ?? ''], $b);
    return tcurl($m, $p, $b, $cookies[$user]);
}

$r = tapi('procurement', 'POST', '/api/v1/tenders', ['title' => 'Grading', 'issuing_org_id' => 2, 'budget_estimate' => 5000000, 'evaluation_criteria' => 'Lowest']);
echo "create => {$r[0]} " . json_encode($r[1]) . "\n";
$list = tapi('procurement', 'GET', '/api/v1/tenders?status=draft');
$tenderId = reset($list[1]['data']['tenders'])['id'];
echo "tenderId=$tenderId\n";
$p = tapi('procurement', 'POST', "/api/v1/tenders/$tenderId/publish", ['closing_date' => '2026-12-31']);
echo "publish => {$p[0]} " . json_encode($p[1]) . "\n";
$b = tapi('supplier.abc', 'POST', '/api/v1/bids', ['tender_id' => $tenderId, 'supplier_org_id' => 3, 'amount' => 4900000]);
echo "bid submit => {$b[0]} " . json_encode($b[1]) . "\n";
$o = tapi('procurement', 'POST', "/api/v1/tenders/$tenderId/open-bids");
echo "open => {$o[0]} " . json_encode($o[1]) . "\n";
$bids = tapi('admin', 'GET', "/api/v1/bids?tender_id=$tenderId");
echo "bids => " . json_encode($bids[1]['data']['bids'] ?? $bids[1]) . "\n";