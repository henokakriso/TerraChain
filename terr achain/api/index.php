<?php
declare(strict_types=1);

/**
 * Standalone API entry (Apache: DocumentRoot → api/ or public/).
 */
require_once dirname(__DIR__) . '/app/core/App.php';
require_once dirname(__DIR__) . '/app/core/Bootstrap.php';
require_once dirname(__DIR__) . '/api/index-req.php';