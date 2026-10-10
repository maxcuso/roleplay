<?php

declare(strict_types=1);

// Проверка использует PHP нового каркаса, не подключая форум и его базу данных.
require __DIR__.'/../src/Presentation/InterfaceIcons.php';
$icons = new \Maxcuso\Roleplay\Presentation\InterfaceIcons();

echo json_encode([
    'entity' => $icons->catalogue('fas fa-swords'),
    'unknown' => $icons->catalogue('fas fa-custom'),
    'fallback' => $icons->catalogue(null),
    'permission' => $icons->get('permissions.view'),
    'extension' => $icons->get('extension'),
], JSON_THROW_ON_ERROR);