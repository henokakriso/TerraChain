<?php
declare(strict_types=1);

final class SystemController
{
    public function settings(array $params = []): never
    {
        $user = Auth::requireLogin();
        $rows = App::db()->fetchAll('SELECT setting_key, setting_value FROM settings WHERE is_public = 1 OR ? = 1', [Auth::hasPermission($user, 'settings.manage') ? 1 : 0]);
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        Response::success(['settings' => $settings]);
    }

    public function integrityStatus(array $params = []): never
    {
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'audit.view')) {
            Response::forbidden();
        }
        $results = [];
        foreach (App::config('integrity.chains') as $chain) {
            $results[$chain] = IntegrityService::verifyChain($chain);
        }
        $overall = true;
        foreach ($results as $r) {
            $overall = $overall && $r['valid'];
        }
        Response::success(['valid' => $overall, 'chains' => $results]);
    }

    public function integrityExport(array $params): never
    {
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'audit.view')) {
            Response::forbidden();
        }
        $chain = (string)$params['chain'];
        if (!in_array($chain, App::config('integrity.chains'), true)) {
            Response::error('Unknown chain.', 404);
        }
        Response::success(IntegrityService::exportChain($chain));
    }

    /**
     * Runs the independent C verifier (c/bin/chain-verify) against the
     * exported chain. Demonstrates the spec's C layer (sections 41).
     */
    public function integrityVerifyC(array $params): never
    {
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'audit.view')) {
            Response::forbidden();
        }
        $chain = (string)$params['chain'];
        if (!in_array($chain, App::config('integrity.chains'), true)) {
            Response::error('Unknown chain.', 404);
        }
        $bin = (string)App::config('integrity.c_bin') . '/chain-verify';
        if (!is_file($bin) || !is_executable($bin)) {
            Response::error('C verifier not built. Run: make -C c', 500);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'tcchain');
        if ($tmp === false) {
            Response::error('Cannot create temp file.', 500);
        }
        $payload = json_encode(IntegrityService::exportChain($chain));
        if (file_put_contents($tmp, $payload) === false) {
            @unlink($tmp);
            Response::error('Cannot write temp file.', 500);
        }
        $cmd = escapeshellarg($bin) . ' ' . escapeshellarg($tmp);
        $output = [];
        $code = 0;
        exec($cmd . ' 2>&1', $output, $code);
        @unlink($tmp);
        Response::success([
            'chain' => $chain,
            'c_exit' => $code,
            'valid' => $code === 0,
            'output' => implode("\n", $output),
        ]);
    }
}
