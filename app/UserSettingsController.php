<?php
declare(strict_types=1);

function handle_user_settings(): void
{
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $folder = (string)($_GET['folder'] ?? ($_SESSION['savegame_folder'] ?? ''));
    if (!get_general_savegame_dir($folder)) {
        http_response_code(404);
        echo json_encode(['error' => 'Spielstand nicht gefunden.']);
        exit;
    }

    echo json_encode([
        'folder' => $folder,
        'settings' => load_user_settings($folder),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $body = json_decode(file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültige Einstellungsdaten.']);
        exit;
    }

    $folder = is_array($body)
        ? (string)($body['folder'] ?? ($_SESSION['savegame_folder'] ?? ''))
        : '';
    if (!get_general_savegame_dir($folder)) {
        http_response_code(404);
        echo json_encode(['error' => 'Spielstand nicht gefunden.']);
        exit;
    }

    try {
        $settings = save_user_settings($folder, $body['settings'] ?? null);
        echo json_encode([
            'success' => true,
            'folder' => $folder,
            'settings' => $settings,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        http_response_code(500);
        echo json_encode(['error' => $exception->getMessage()]);
    }
    exit;
}

http_response_code(405);
header('Allow: GET, POST');
echo json_encode(['error' => 'method_not_allowed']);
}
