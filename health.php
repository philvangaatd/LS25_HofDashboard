<?php
declare(strict_types=1);

require __DIR__ . '/version.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

echo json_encode([
    'status' => 'ok',
    'component' => 'dashboard',
    'version' => HOF_DASHBOARD_VERSION,
    'dataLayoutVersion' => HOF_DASHBOARD_DATA_LAYOUT_VERSION,
    'apiProtocol' => [
        'min' => HOF_DASHBOARD_PROTOCOL_MIN,
        'max' => HOF_DASHBOARD_PROTOCOL_MAX,
    ],
    'minimumModVersion' => HOF_DASHBOARD_MIN_MOD_VERSION,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
