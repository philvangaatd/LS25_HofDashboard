<?php
declare(strict_types=1);

// -----------------------------------------------------------------
// Kartenhintergrundbild: gemeinsame Verarbeitung (Formatprüfung, Downscale,
// Speichern) für sowohl manuellen Upload als auch automatisches Laden aus den
// Moddateien – identische Logik, nur die Bildquelle unterscheidet sich.
// -----------------------------------------------------------------
function save_terrain_image_from_path(string $srcPath, string $destPath): array {
    $info = @getimagesize($srcPath);
    if (!$info) {
        return ['error' => 'Datei ist kein gültiges Bild.'];
    }

    // Anhand der IMAGETYPE-Konstante statt des rohen Mime-Strings prüfen: manche Tools
    // (Screenshot-Programme, Bildbearbeitung) liefern Varianten wie "image/x-png" oder
    // "image/pjpeg" statt der Standard-Strings.
    $loadersByType = [
        IMAGETYPE_PNG => 'imagecreatefrompng',
        IMAGETYPE_JPEG => 'imagecreatefromjpeg',
        IMAGETYPE_WEBP => 'imagecreatefromwebp',
    ];
    $type = $info[2];
    if (!isset($loadersByType[$type]) || !function_exists($loadersByType[$type])) {
        return ['error' => 'Bildformat nicht unterstützt (erlaubt: PNG, JPEG, WEBP). Erkannt: ' . ($info['mime'] ?? 'unbekannt')];
    }

    $src = @$loadersByType[$type]($srcPath);
    if (!$src) {
        return ['error' => 'Bild konnte nicht gelesen werden.'];
    }

    $srcW = imagesx($src);
    $srcH = imagesy($src);

    // Sehr große Bilder auf eine handhabbare Kantenlänge herunterskalieren – die Karte
    // braucht keine Auflösung jenseits dessen, was am Bildschirm sichtbar ist, und hält
    // die Datei klein genug für schnelles Laden im Browser.
    $maxDim = 2048;
    if ($srcW > $maxDim || $srcH > $maxDim) {
        $ratio = min($maxDim / $srcW, $maxDim / $srcH);
        $dstW = max(1, (int)round($srcW * $ratio));
        $dstH = max(1, (int)round($srcH * $ratio));
        $dst = imagecreatetruecolor($dstW, $dstH);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
        imagedestroy($src);
    } else {
        $dst = $src;
        $dstW = $srcW;
        $dstH = $srcH;
    }

    $assetsDir = dirname($destPath);
    if (!is_dir($assetsDir)) mkdir($assetsDir, 0777, true);

    if (!imagepng($dst, $destPath)) {
        imagedestroy($dst);
        return ['error' => 'Bild konnte nicht gespeichert werden.'];
    }
    imagedestroy($dst);

    return ['success' => true, 'width' => $dstW, 'height' => $dstH];
}

// Sucht nach einem als Kartenbild nutzbaren Bild – je nach mapId entweder in der
// Mod-ZIP (Community-Karten) oder direkt in den Spieldateien (offizielle GIANTS-
// Karten). Community-Karten haben ein mapId-Format "<ModName>.<KartenSchlüssel>"
// (z. B. "FS25_Szpakowo.SampleModMap"); offizielle Karten haben keinen Punkt im
// mapId (z. B. "mapUS") und liegen nicht im Spielstand-mods-Ordner, sondern direkt
// im Installationsverzeichnis des Spiels. Wichtige Einschränkung in beiden Fällen:
// Kartenbilder liegen in FS25 so gut wie immer als DDS-Textur vor, die PHP/GD nicht
// lesen kann – verifiziert an der echten mapUS-Installation: die eigene Karten-XML
// referenziert zwar "overview.png", tatsächlich ausgeliefert wird aber nur
// "overview.dds". Diese Funktion findet nur PNG/JPEG-Kandidaten; ein DDS-Fund wird
// als Hinweis mitgegeben, aber nicht nutzbar gemacht.
function find_map_overview_image(string $mapId): array {
    if (!class_exists('ZipArchive')) {
        return ['found' => false, 'ddsOnly' => false, 'reason' => 'no_zip_extension'];
    }
    if ($mapId === '') {
        return ['found' => false, 'ddsOnly' => false, 'reason' => 'no_map_id'];
    }

    if (str_contains($mapId, '.')) {
        return find_map_overview_image_in_mod($mapId);
    }
    return find_map_overview_image_in_install($mapId);
}

// Heuristik: nur Dateien, deren DATEINAME (nicht der gesamte Pfad!) exakt auf eine
// Kartenübersicht hindeutet. Vorher wurde der komplette Pfad nach diesen Begriffen als
// Teilstring durchsucht – bei großen Mods mit tausenden Texturdateien konnte das
// versehentlich eine völlig unbezogene Datei treffen, deren Pfad zufällig "overview"
// als Teilstring enthält (z. B. ".../overviewShedRoof.dds"), und lieferte dann ein
// komplett falsches Kartenbild aus – genau das ist bei Szpakowo passiert. Der exakte
// Dateiname (ohne Endung) muss jetzt komplett übereinstimmen. "preview" ist bewusst
// NICHT dabei: mapUS/mapEU liefern getrennt "overview.dds" (volle Karte) und
// "preview.dds" (kleines Store-Icon) – verifiziert an den echten Spieldateien.
function overview_filename_matches(string $path): bool {
    $base = strtolower(pathinfo($path, PATHINFO_FILENAME));
    return in_array($base, ['overview', 'mapoverview', 'ingamemap', 'mapimage', 'minimap'], true);
}

function overview_file_type(string $lower): ?string {
    if (preg_match('/\.(png|jpe?g)$/', $lower)) return 'png';
    if (str_ends_with($lower, '.dds')) return 'dds';
    return null;
}

// Gemeinsame Auswahllogik für beide Quellen (ZIP-Einträge oder Dateisystem-Treffer):
// größte PNG/JPEG-Kandidatendatei bevorzugen (kleine Icons/Vorschaubilder sind i. d. R.
// deutlich kleiner als eine echte Kartenübersicht in nutzbarer Auflösung).
function pick_best_overview_candidate(array $entries): array {
    $pngCandidates = array_values(array_filter($entries, fn($e) => $e['type'] === 'png'));
    $ddsFound = count(array_filter($entries, fn($e) => $e['type'] === 'dds')) > 0;
    if (empty($pngCandidates)) {
        return ['found' => false, 'ddsOnly' => $ddsFound, 'reason' => $ddsFound ? 'dds_only' : 'no_candidate'];
    }
    usort($pngCandidates, fn($a, $b) => $b['size'] <=> $a['size']);
    return ['found' => true, 'best' => $pngCandidates[0]];
}

function find_map_overview_image_in_mod(string $mapId): array {
    // mapId-Format: "<ModName>.<KartenSchlüssel>". Kein zugehöriges ZIP im
    // mods-Ordner ist kein Fehler, sondern schlicht keine Community-Karte.
    $modName = strtok($mapId, '.');
    if (!$modName) {
        return ['found' => false, 'ddsOnly' => false, 'reason' => 'no_map_id'];
    }

    $zipPath = FS_BASE_DIR . DIRECTORY_SEPARATOR . 'mods' . DIRECTORY_SEPARATOR . $modName . '.zip';
    if (!file_exists($zipPath)) {
        return ['found' => false, 'ddsOnly' => false, 'reason' => 'no_mod_zip'];
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return ['found' => false, 'ddsOnly' => false, 'reason' => 'zip_open_failed'];
    }

    $entries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false) continue;
        $lower = strtolower($name);
        if (!overview_filename_matches($lower)) continue;
        $type = overview_file_type($lower);
        if (!$type) continue;
        $stat = $zip->statIndex($i);
        $entries[] = ['name' => $name, 'size' => $stat['size'] ?? 0, 'type' => $type];
    }

    $pick = pick_best_overview_candidate($entries);
    if (!$pick['found']) {
        $zip->close();
        return $pick;
    }

    $imageData = $zip->getFromName($pick['best']['name']);
    $zip->close();

    if ($imageData === false) {
        return ['found' => false, 'ddsOnly' => false, 'reason' => 'extract_failed'];
    }

    return ['found' => true, 'ddsOnly' => false, 'data' => $imageData, 'sourceName' => $pick['best']['name']];
}

// Offizielle GIANTS-Karte: liegt nicht im Spielstand-mods-Ordner, sondern direkt im
// Installationsverzeichnis des Spiels unter data/maps/<mapId>/. FS_INSTALL_DIR wird
// in config.php automatisch erkannt oder lässt sich dort manuell setzen; ist der
// Ordner nicht bekannt, ist das kein Fehler des Tools, sondern fehlende Information
// – der Nutzer bekommt dafür eine gezielte Meldung statt eines stillen Fehlschlags.
function find_map_overview_image_in_install(string $mapId): array {
    if (!defined('FS_INSTALL_DIR') || FS_INSTALL_DIR === '') {
        return ['found' => false, 'ddsOnly' => false, 'reason' => 'no_install_dir'];
    }

    $mapDir = FS_INSTALL_DIR . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'maps' . DIRECTORY_SEPARATOR . $mapId;
    if (!is_dir($mapDir)) {
        return ['found' => false, 'ddsOnly' => false, 'reason' => 'map_dir_not_found'];
    }

    $entries = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($mapDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $lower = strtolower($file->getFilename());
        if (!overview_filename_matches($lower)) continue;
        $type = overview_file_type($lower);
        if (!$type) continue;
        $entries[] = ['name' => $file->getPathname(), 'size' => $file->getSize(), 'type' => $type];
    }

    $pick = pick_best_overview_candidate($entries);
    if (!$pick['found']) {
        return $pick;
    }

    $imageData = @file_get_contents($pick['best']['name']);
    if ($imageData === false) {
        return ['found' => false, 'ddsOnly' => false, 'reason' => 'extract_failed'];
    }

    return ['found' => true, 'ddsOnly' => false, 'data' => $imageData, 'sourceName' => basename($pick['best']['name'])];
}

// -----------------------------------------------------------------
// Exakte Kartengröße ermitteln (unabhängig vom Bildformat-Problem oben): Jede
// FS25-Karte – offiziell oder Mod – muss eine Wurzel-XML mit <map width="..."
// height="..."> besitzen, das ist Teil des Kartenformats selbst (verifiziert an
// mapUS.xml: <map width="2048" height="2048" ...>). Diese Zahl ist genauer als
// die Schätzung anhand der Wegpunkt-Ausdehnung im Frontend und funktioniert auch,
// wenn das eigentliche Kartenbild nur als DDS vorliegt.
// -----------------------------------------------------------------
function extract_map_size_from_xml_string(string $xmlContent): ?array {
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlContent);
    if (!$xml || $xml->getName() !== 'map') return null;
    $w = (int)($xml['width'] ?? 0);
    $h = (int)($xml['height'] ?? 0);
    if ($w <= 0 || $h <= 0) return null;
    return ['width' => $w, 'height' => $h];
}

function find_map_size(string $mapId): ?array {
    if ($mapId === '') return null;
    if (str_contains($mapId, '.')) {
        return find_map_size_in_mod($mapId);
    }
    return find_map_size_in_install($mapId);
}

function find_map_size_in_install(string $mapId): ?array {
    if (!defined('FS_INSTALL_DIR') || FS_INSTALL_DIR === '') return null;
    $xmlPath = FS_INSTALL_DIR . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'maps'
        . DIRECTORY_SEPARATOR . $mapId . DIRECTORY_SEPARATOR . $mapId . '.xml';
    if (!file_exists($xmlPath)) return null;
    $content = @file_get_contents($xmlPath);
    if ($content === false) return null;
    return extract_map_size_from_xml_string($content);
}

function find_map_size_in_mod(string $mapId): ?array {
    if (!class_exists('ZipArchive')) return null;
    $modName = strtok($mapId, '.');
    if (!$modName) return null;
    $zipPath = FS_BASE_DIR . DIRECTORY_SEPARATOR . 'mods' . DIRECTORY_SEPARATOR . $modName . '.zip';
    if (!file_exists($zipPath)) return null;

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) return null;

    $result = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false || !preg_match('/\.xml$/i', $name)) continue;
        // Die Kartendefinitions-XML liegt bei jeder FS25-Karte im Wurzelverzeichnis
        // (höchstens einen Unterordner tief) – tiefer verschachtelte XMLs
        // (Fahrzeuge, Geräte usw.) werden so ausgeschlossen.
        if (substr_count($name, '/') > 1) continue;
        $content = $zip->getFromName($name);
        if ($content === false) continue;
        $size = extract_map_size_from_xml_string($content);
        if ($size) { $result = $size; break; }
    }
    $zip->close();
    return $result;
}

// -----------------------------------------------------------------
// Rohe DDS-Kartentextur ausliefern, wenn keine PNG/JPEG-Variante existiert.
// PHP/GD kann DDS nicht dekodieren, ein Browser mit JavaScript aber sehr wohl
// (siehe DXT1-Dekoder im Frontend) – dieser Endpunkt liefert daher einfach die
// unveränderten Rohbytes, die Dekodierung passiert komplett client-seitig.
// -----------------------------------------------------------------
function find_map_overview_dds(string $mapId): ?string {
    if ($mapId === '') return null;
    if (str_contains($mapId, '.')) {
        return find_map_overview_dds_in_mod($mapId);
    }
    return find_map_overview_dds_in_install($mapId);
}

function find_map_overview_dds_in_mod(string $mapId): ?string {
    if (!class_exists('ZipArchive')) return null;
    $modName = strtok($mapId, '.');
    if (!$modName) return null;
    $zipPath = FS_BASE_DIR . DIRECTORY_SEPARATOR . 'mods' . DIRECTORY_SEPARATOR . $modName . '.zip';
    if (!file_exists($zipPath)) return null;

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) return null;

    $ddsCandidates = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false) continue;
        $lower = strtolower($name);
        if (!overview_filename_matches($lower) || !str_ends_with($lower, '.dds')) continue;
        $stat = $zip->statIndex($i);
        $ddsCandidates[] = ['name' => $name, 'size' => $stat['size'] ?? 0];
    }
    if (empty($ddsCandidates)) {
        $zip->close();
        return null;
    }
    usort($ddsCandidates, fn($a, $b) => $b['size'] <=> $a['size']);
    $data = $zip->getFromName($ddsCandidates[0]['name']);
    $zip->close();
    return $data !== false ? $data : null;
}

function find_map_overview_dds_in_install(string $mapId): ?string {
    if (!defined('FS_INSTALL_DIR') || FS_INSTALL_DIR === '') return null;
    $mapDir = FS_INSTALL_DIR . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'maps' . DIRECTORY_SEPARATOR . $mapId;
    if (!is_dir($mapDir)) return null;

    $ddsCandidates = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($mapDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $lower = strtolower($file->getFilename());
        if (!overview_filename_matches($lower) || !str_ends_with($lower, '.dds')) continue;
        $ddsCandidates[] = ['path' => $file->getPathname(), 'size' => $file->getSize()];
    }
    if (empty($ddsCandidates)) return null;
    usort($ddsCandidates, fn($a, $b) => $b['size'] <=> $a['size']);
    $data = @file_get_contents($ddsCandidates[0]['path']);
    return $data !== false ? $data : null;
}
