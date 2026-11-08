<?php
declare(strict_types=1);

/**
 * Vercel entrypoint.
 *
 * `vercel.json` routes every request here; the real app stays in the project
 * root so `php -S 127.0.0.1:8000 -t .` keeps working locally. Overriding
 * SCRIPT_NAME keeps generated links clean (https://host/?p=...) instead of
 * exposing the internal /api/index.php path.
 */
$_SERVER['SCRIPT_NAME'] = '/';

require __DIR__ . '/../index.php';
