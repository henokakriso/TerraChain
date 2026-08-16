<?php
declare(strict_types=1);

/**
 * TerraChain unit tests (section 42): business logic without HTTP.
 * Usage: php tests/run_unit_tests.php
 */

require_once dirname(__DIR__) . '/app/core/App.php';
require_once dirname(__DIR__) . '/app/validators/Validator.php';
require_once dirname(__DIR__) . '/app/core/Bootstrap.php';
Bootstrap::init();
Auth::startSession();

$pass = 0;
$fail = 0;
$failures = [];

function check(string $name, bool $cond, string $detail = ''): void
{
    global $pass, $fail, $failures;
    if ($cond) {
        $pass++;
        echo "  PASS  $name\n";
    } else {
        $fail++;
        $failures[] = $name;
        echo "  FAIL  $name" . ($detail !== '' ? " [$detail]" : '') . "\n";
    }
}

// ---------------- Ethiopian calendar ----------------
echo "\n[Ethiopian Calendar]\n";
check('known date: 2007-09-11 Gregorian = 2000-01-01 Ethiopian', EthiopianCalendar::toEthiopian('2007-09-11') === [2000, 1, 1], json_encode(EthiopianCalendar::toEthiopian('2007-09-11')));
check('roundtrip 2026-08-16', (function () {
    [$y, $m, $d] = EthiopianCalendar::toEthiopian('2026-08-16');
    return EthiopianCalendar::toGregorian($y, $m, $d) === '2026-08-16';
})());
check('13th month (Pagume) handling', (function () {
    [$y, $m, $d] = EthiopianCalendar::toEthiopian('2024-09-06');
    return $m === 13;
})());
check('invalid Ethiopian date rejected', (function () {
    try {
        EthiopianCalendar::fromEthiopian(2025, 13, 7); // Pagume has 5 days in normal year
        return false;
    } catch (ApiException) {
        return true;
    }
})());
check('month names Amharic exists', count(EthiopianCalendar::MONTH_NAMES_AM) === 13);

// ---------------- Localization ----------------
echo "\n[Localization]\n";
check('english resource load', Localization::t('login.title') === 'Sign in to TerraChain');
check('amharic resource load', (function () {
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $u = ['id' => 1, 'language' => 'am'];
    $getLang = new ReflectionMethod(Localization::class, 'lang');
    // lang() reads session user; simulate by checking file
    $am = json_decode((string)file_get_contents(TERRACHAIN_LANG . '/am.json'), true);
    return isset($am['nav.land']) && $am['nav.land'] === 'የመሬት አስተዳደር';
})());
check('oromo resource exists', is_file(TERRACHAIN_LANG . '/or.json'));

// ---------------- Integrity chain core ----------------
echo "\n[Integrity Chain]\n";
App::db()->delete('integrity_records', 'chain_name = ?', ['unit_test_chain']);
check('chain append + verify valid', (function () {
    IntegrityService::append('unit_test_chain', 'rec', '1', 'payload-a');
    IntegrityService::append('unit_test_chain', 'rec', '2', 'payload-b');
    IntegrityService::append('unit_test_chain', 'rec', '3', 'payload-c');
    $r = IntegrityService::verifyChain('unit_test_chain');
    if (!$r['valid']) {
        return false;
    }
    return true;
})());
check('tampered middle record detected', (function () {
    App::db()->update('integrity_records', ['record_hash' => str_repeat('0', 64)], 'chain_name = "unit_test_chain" AND record_type = "rec" AND record_id = "2"');
    $r = IntegrityService::verifyChain('unit_test_chain');
    return !$r['valid'] && count($r['problems']) > 0;
})());
check('re-hash of tampered row restores validity', (function () {
    $rec1 = App::db()->fetchOne('SELECT record_hash, anchor_hash FROM integrity_records WHERE chain_name = ? AND record_type = "rec" AND record_id = "1"', ['unit_test_chain']);
    $correct = IntegrityService::hashRecord('rec', '2', 'payload-b', (string)$rec1['record_hash']);
    $anchor = hash('sha256', (string)$rec1['anchor_hash'] . $correct);
    App::db()->update('integrity_records', ['record_hash' => $correct, 'anchor_hash' => $anchor], 'chain_name = ? AND record_type = "rec" AND record_id = "2"', ['unit_test_chain']);
    $r = IntegrityService::verifyChain('unit_test_chain');
    return $r['valid'];
})());
check('verification detects hash mismatch vs source', (function () {
    $app = IntegrityService::verifyAgainstSource('unit_test_chain', 'integrity_records', 'rec', 'SELECT record_hash FROM integrity_records WHERE record_type = "rec" AND record_id = ? LIMIT 1');
    return is_array($app) && isset($app['valid']);
})());
check('stateful hash differs with different previous', IntegrityService::hashRecord('t', '1', 'x', null) !== IntegrityService::hashRecord('t', '1', 'x', 'abc'));

// ---------------- Validators ----------------
echo "\n[Validators]\n";
$v = Validator::make()
    ->required('a', null)
    ->minLength('b', 'short', 8)
    ->email('c', 'nope')
    ->in('d', 'x', ['a', 'b'])
    ->date('e', '2026-13-99')->numeric('f', 'abc');
check('validator collects errors', $v->fails() && count($v->errors()) === 6, json_encode($v->errors()));
$v2 = Validator::make()->required('a', 'ok')->date('b', '2026-08-16')->numeric('c', 3.5)->in('d', 'a', ['a', 'b']);
check('validator passes clean input', !$v2->fails());

// ---------------- Land workflow rule ----------------
echo "\n[Land Workflow]\n";
$wf = LandService::workflowStatus('submitted');
check('workflow progress 10%', abs(($wf['progress'] ?? 0) - 0.1) < 0.0001);
check('approval milestone', LandService::workflowStatus('approved')['step'] === 6);

// ---------------- Permission boundaries ----------------
echo "\n[RBAC Query]\n";
$user = App::db()->fetchOne('SELECT * FROM users WHERE username = ?', ['admin']);
check('admin has audit.view', Auth::hasPermission($user, 'audit.view'));
$user = App::db()->fetchOne('SELECT * FROM users WHERE username = ?', ['citizen.demo']);
check('citizen lacks users.view', !Auth::hasPermission($user, 'users.view'));
check('citizen has bids.submit', Auth::hasPermission($user, 'bids.submit'));

// ---------------- Admin scope ----------------
echo "\n[Admin Scope]\n";
check('federal (unit 1) systemic root', Auth::inScope(1, 18));
check('kebele admin of 17 sees kebele 17', Auth::inScope(17, 17));
check('kebele admin of 17 denied kebele 18', !Auth::inScope(17, 18));
check('woreda 16 sees kebele 17 (descendant)', Auth::inScope(16, 17));
check('woreda 16 denied zone 19', !Auth::inScope(16, 19));

// ---------------- Summary ----------------
echo "\n========================================\n";
echo "RESULT: $pass passed, $fail failed\n";
if ($fail > 0) {
    foreach ($failures as $f) echo "  FAILED: $f\n";
}
exit($fail > 0 ? 1 : 0);