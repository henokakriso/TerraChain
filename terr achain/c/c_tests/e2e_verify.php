<?php
/**
 * End-to-end C verification check (spec section 41):
 * 1. exports each chain via the live API
 * 2. runs c/bin/chain-verify on each export (expect VALID)
 * 3. tampers ONE record, re-exports, expects the C tool to flag INVALID
 * 4. restores the tampered row from the pre-tamper backup
 */
$base = 'http://127.0.0.1:8081';
$C_BIN = '/home/sergio/Desktop/Github/TerraChain/terr achain/c/bin/chain-verify';
$jar = tempnam(sys_get_temp_dir(), 'tcjar');

function curl_json(string $m, string $p, ?array $b, string $jar): array
{
    $ch = curl_init($p);
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $m, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar];
    if ($b !== null) {
        $o[CURLOPT_POSTFIELDS] = json_encode($b);
    }
    curl_setopt_array($ch, $o);
    $raw = curl_exec($ch);
    $st = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, json_decode($raw, true)];
}

function c_verify(string $jsonPath): array
{
    global $C_BIN;
    $lines = [];
    $code = 0;
    exec(escapeshellarg($C_BIN) . ' ' . escapeshellarg($jsonPath) . ' 2>&1', $lines, $code);
    return [$code, trim(implode("\n", $lines))];
}

$app = new mysqli('127.0.0.1', 'terrachain', 'terrachain_local_dev', 'terrachain', 33306);
[$st, $j] = curl_json('GET', "$base/api/v1/auth/csrf", null, $jar);
$t = $j['data']['csrf'];
curl_json('POST', "$base/api/v1/auth/login", ['username' => 'admin', 'password' => 'Terrachain@2026', '_csrf' => $t], $jar);

$fails = 0;
foreach (['tenders'] as $chain) {
    [$st, $j] = curl_json('GET', "$base/api/v1/system/integrity/$chain/export", null, $jar);
    if ($st !== 200 || empty($j['data']['entries'])) {
        echo "$chain: export failed ($st)\n";
        continue;
    }
    $plain = "/tmp/opencode/chain_ec.json";
    file_put_contents($plain, json_encode($j['data']));
    [$code, $out] = c_verify($plain);
    $okGuard = $code === 0;
    echo "$chain: C exit=$code " . ($okGuard ? 'VALID' : 'INVALID') . "\n";

    // tamper the FIRST entry only (entries are ordered id ASC in the export)
    $tgt = $j['data']['entries'][0];
    $single = "chain_name = '$chain' AND record_type = '{$tgt['record_type']}' AND record_id = '{$tgt['record_id']}' AND created_at = '{$tgt['created_at']}' ORDER BY id ASC LIMIT 1";
    $bh = $app->real_escape_string($tgt['record_hash']);
    $ah = $app->real_escape_string($tgt['anchor_hash']);

    $app->query("UPDATE integrity_records SET record_hash = REPEAT('0',64), anchor_hash = REPEAT('0',64) WHERE $single");
    [$st2, $j2] = curl_json('GET', "$base/api/v1/system/integrity/$chain/export", null, $jar);
    file_put_contents("/tmp/opencode/chain_tampered.json", json_encode($j2['data']));
    [$code2, $out2] = c_verify("/tmp/opencode/chain_tampered.json");
    $tamperGuarded = $code2 !== 0;
    echo "tampered: C exit=$code2 " . ($tamperGuarded ? 'INVALID (detected)' : 'STILL VALID (BUG!)') . "\n";

    $app->query("UPDATE integrity_records SET record_hash = '$bh', anchor_hash = '$ah' WHERE $single");
    [$st3, $j3] = curl_json('GET', "$base/api/v1/system/integrity/$chain/export", null, $jar);
    file_put_contents("/tmp/opencode/chain_restored.json", json_encode($j3['data']));
    [$code3, $out3] = c_verify("/tmp/opencode/chain_restored.json");
    $restoreGuarded = $code3 === 0;
    echo "restored:  C exit=$code3 " . ($restoreGuarded ? 'VALID' : 'INVALID') . "\n";

    if (!$okGuard || !$tamperGuarded || !$restoreGuarded) {
        $fails++;
        echo "--- output:\n$out\n$out2\n$out3\n";
    }
}
$app->close();
echo $fails === 0 ? "E2E OK\n" : "E2E FAILURES: $fails\n";
exit($fails === 0 ? 0 : 1);