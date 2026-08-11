<?php
declare(strict_types=1);

function expect_autodrive_test(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

require __DIR__ . '/../app/AutoDriveService.php';

$xmlPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hofdashboard-autodrive-' . bin2hex(random_bytes(4)) . '.xml';
file_put_contents($xmlPath, '<AutoDrive><MapName>Test Map</MapName><waypoints><id>1,2,42</id></waypoints><mapmarker><mm1><id>1.000000</id><name>Farm</name><group>Home</group></mm1><mm2><id>2.000000</id><name>Field</name></mm2></mapmarker></AutoDrive>');

$dom = load_dom($xmlPath);
expect_autodrive_test($dom instanceof DOMDocument, 'Expected AutoDrive XML to load as DOMDocument.');

$ids = get_valid_waypoint_ids($dom);
expect_autodrive_test(isset($ids['1'], $ids['2'], $ids['42']), 'Expected waypoint IDs to be indexed.');
expect_autodrive_test(!isset($ids['3']), 'Expected unknown waypoint ID to be absent.');

$markerData = read_autodrive_markers($dom);
expect_autodrive_test($markerData['mapName'] === 'Test Map', 'Expected map name to be read.');
expect_autodrive_test(count($markerData['markers']) === 2, 'Expected two markers to be read.');
expect_autodrive_test($markerData['markers'][0] === ['key' => 'mm1', 'id' => '1.000000', 'name' => 'Farm', 'group' => 'Home'], 'Expected first marker data.');
expect_autodrive_test($markerData['groups'] === ['Home'], 'Expected marker groups.');

expect_autodrive_test(validate_autodrive_markers([
    ['id' => '1.000000', 'name' => 'Farm', 'group' => 'Home'],
], $ids) === null, 'Expected valid marker payload.');

$missingWaypoint = validate_autodrive_markers([
    ['id' => '999.000000', 'name' => 'Missing', 'group' => ''],
], $ids);
expect_autodrive_test($missingWaypoint['status'] === 422, 'Expected missing waypoint to be rejected.');

$emptyName = validate_autodrive_markers([
    ['id' => '1.000000', 'name' => ' ', 'group' => ''],
], $ids);
expect_autodrive_test($emptyName['error'] === 'Marker-Name darf nicht leer sein.', 'Expected empty marker name rejection.');

replace_autodrive_markers($dom, [
    ['id' => '42.000000', 'name' => 'Shop', 'group' => 'Sales'],
    ['id' => '2.000000', 'name' => 'Field', 'group' => ''],
]);
$updatedMarkerData = read_autodrive_markers($dom);
expect_autodrive_test(count($updatedMarkerData['markers']) === 2, 'Expected replaced marker count.');
expect_autodrive_test($updatedMarkerData['markers'][0] === ['key' => 'mm1', 'id' => '42.000000', 'name' => 'Shop', 'group' => 'Sales'], 'Expected replaced marker data.');

$emptyDom = new DOMDocument('1.0', 'utf-8');
$emptyDom->loadXML('<AutoDrive/>');
expect_autodrive_test(get_valid_waypoint_ids($emptyDom) === [], 'Expected no IDs without id node.');

unlink($xmlPath);

echo "autodrive_service_test: ok\n";
