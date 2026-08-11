<?php
declare(strict_types=1);

function handle_farm_name_update(): void
{
$folder = $_SESSION['savegame_folder'] ?? null;
if (!$folder) {
    http_response_code(409);
    echo json_encode(['error' => 'no_savegame_selected']);
    exit;
}
$dir = get_general_savegame_dir($folder);
if (!$dir) {
    http_response_code(404);
    echo json_encode(['error' => 'Spielstand nicht gefunden.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$name = trim($body['name'] ?? '');
if ($name === '') {
    http_response_code(422);
    echo json_encode(['error' => 'Hofname darf nicht leer sein.']);
    exit;
}
if (strlen($name) > 96) {
    http_response_code(422);
    echo json_encode(['error' => 'Hofname darf maximal 64 Zeichen lang sein.']);
    exit;
}

$farmsFile = $dir . DIRECTORY_SEPARATOR . 'farms.xml';
if (!file_exists($farmsFile)) {
    http_response_code(404);
    echo json_encode(['error' => 'farms.xml nicht gefunden.']);
    exit;
}

$dom = new DOMDocument('1.0', 'utf-8');
$dom->preserveWhiteSpace = true;
$dom->formatOutput = false;
libxml_use_internal_errors(true);
if (!$dom->load($farmsFile, LIBXML_PARSEHUGE)) {
    http_response_code(500);
    echo json_encode(['error' => 'farms.xml konnte nicht gelesen werden.']);
    exit;
}

// Gleiche Logik wie get_farm_info(): erste echte Farm (farmId != 0, das ist die
// Umwelt-/Vorbesitzer-Sammelfarm)
$targetFarm = null;
foreach ($dom->getElementsByTagName('farm') as $farmNode) {
    if ($farmNode->getAttribute('farmId') !== '0') { $targetFarm = $farmNode; break; }
}
if (!$targetFarm) {
    http_response_code(404);
    echo json_encode(['error' => 'Keine eigene Farm in diesem Spielstand gefunden.']);
    exit;
}

// Backup vor dem Schreiben, gleiches Sicherheitsnetz wie bei den AutoDrive-Speicherungen
$backupFile = make_farms_backup_filename($folder);
copy($farmsFile, $backupFile);
prune_old_farms_backups($folder, 10);

// Zeitstempel erhalten, damit Steam Cloud keinen falschen Synchronisationskonflikt meldet
$originalMTime = filemtime($farmsFile);

$targetFarm->setAttribute('name', $name);
$dom->save($farmsFile);
if ($originalMTime !== false) touch($farmsFile, $originalMTime);

echo json_encode(['success' => true, 'name' => $name]);
}
