<?php

declare(strict_types=1);

/**
 * Where the demos (demo.php, page-builder.php) read from. By default the local CMS of `make dev`
 * (API http://localhost:8090, admin app http://localhost:3090).
 *
 * On a server, a config.local.php next to this file overrides it (config.*.php are not in git) - e.g. the live demo
 * demo.excellent.jinx-digital.com:
 *
 *     <?php
 *     return [
 *         'url' => 'https://admin.demo.excellent.jinx-digital.com',
 *         'admin_origin' => 'https://admin.demo.excellent.jinx-digital.com',
 *     ];
 *
 * Or the environment variables EXCELLENT_URL, EXCELLENT_ADMIN_ORIGIN, EXCELLENT_PROJECT, EXCELLENT_ENTITY,
 * EXCELLENT_CLIENT_ID, EXCELLENT_CLIENT_SECRET.
 */
$config = [
    // The CMS (its API)
    'url' => getenv('EXCELLENT_URL') ?: 'http://localhost:8090',
    // The admin app that may live edit in the preview of page-builder.php
    'admin_origin' => getenv('EXCELLENT_ADMIN_ORIGIN') ?: 'http://localhost:3090',
    // demo.php: the project with the library
    'library_project' => getenv('EXCELLENT_PROJECT') ?: 'bibliothek',
    // page-builder.php: the project and entity with the landing pages
    'pages_project' => getenv('EXCELLENT_PROJECT') ?: 'docs',
    'pages_entity' => getenv('EXCELLENT_ENTITY') ?: 'landing_pages',
    // demo.php with an API client (protected entities such as books) - optional
    'client_id' => getenv('EXCELLENT_CLIENT_ID') ?: null,
    'client_secret' => getenv('EXCELLENT_CLIENT_SECRET') ?: null,
];

return is_file(__DIR__.'/config.local.php') ? array_replace($config, require __DIR__.'/config.local.php') : $config;
