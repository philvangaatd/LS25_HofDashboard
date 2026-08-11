<?php
declare(strict_types=1);

function load_dom(string $path): DOMDocument
{
    $dom = new DOMDocument('1.0', 'utf-8');
    $dom->preserveWhiteSpace = true;
    $dom->formatOutput = false;
    libxml_use_internal_errors(true);
    $ok = $dom->load($path, LIBXML_PARSEHUGE);
    if (!$ok) {
        http_response_code(500);
        echo json_encode(['error' => 'XML konnte nicht geparst werden.']);
        exit;
    }

    return $dom;
}

function get_valid_waypoint_ids(DOMDocument $dom): array
{
    $idNode = $dom->getElementsByTagName('id')->item(0);
    if (!$idNode) {
        return [];
    }

    return array_flip(explode(',', trim($idNode->textContent)));
}

function read_autodrive_markers(DOMDocument $dom): array
{
    $markerNode = $dom->getElementsByTagName('mapmarker')->item(0);
    $markers = [];
    $groups = [];

    if ($markerNode) {
        foreach ($markerNode->childNodes as $mm) {
            if ($mm->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            $id = trim($mm->getElementsByTagName('id')->item(0)->textContent ?? '');
            $name = trim($mm->getElementsByTagName('name')->item(0)->textContent ?? '');
            $groupNode = $mm->getElementsByTagName('group')->item(0);
            $group = $groupNode ? trim($groupNode->textContent) : '';
            $markers[] = ['key' => $mm->nodeName, 'id' => $id, 'name' => $name, 'group' => $group];
            if ($group !== '') {
                $groups[$group] = true;
            }
        }
    }

    return [
        'markers' => $markers,
        'groups' => array_keys($groups),
        'mapName' => $dom->getElementsByTagName('MapName')->item(0)->textContent ?? '',
    ];
}

function validate_autodrive_markers(array $markers, array $validIds): ?array
{
    foreach ($markers as $marker) {
        $id = (string)(int)floatval($marker['id']);
        if (!isset($validIds[$id])) {
            return [
                'status' => 422,
                'error' => "Wegpunkt-ID {$marker['id']} existiert nicht im Spielstand.",
            ];
        }

        if (trim($marker['name']) === '') {
            return [
                'status' => 422,
                'error' => 'Marker-Name darf nicht leer sein.',
            ];
        }
    }

    return null;
}

function replace_autodrive_markers(DOMDocument $dom, array $markers): void
{
    $markerNode = $dom->getElementsByTagName('mapmarker')->item(0);
    $autoDriveRoot = $dom->getElementsByTagName('AutoDrive')->item(0);

    if (!$markerNode) {
        $markerNode = $dom->createElement('mapmarker');
        $autoDriveRoot->appendChild($markerNode);
    }

    while ($markerNode->firstChild) {
        $markerNode->removeChild($markerNode->firstChild);
    }

    $i = 1;
    foreach ($markers as $marker) {
        $mm = $dom->createElement('mm' . $i);
        $idEl = $dom->createElement('id', (string)(int)floatval($marker['id']) . '.000000');
        $nameEl = $dom->createElement('name');
        $nameEl->appendChild($dom->createTextNode($marker['name']));
        $mm->appendChild($idEl);
        $mm->appendChild($nameEl);
        if (!empty($marker['group'])) {
            $groupEl = $dom->createElement('group');
            $groupEl->appendChild($dom->createTextNode($marker['group']));
            $mm->appendChild($groupEl);
        }
        $markerNode->appendChild($mm);
        $i++;
    }
}
