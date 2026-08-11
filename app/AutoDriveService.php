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
