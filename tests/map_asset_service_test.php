<?php
declare(strict_types=1);

function expect_map_asset_test(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

define('FS_BASE_DIR', sys_get_temp_dir());
define('FS_INSTALL_DIR', '');

require __DIR__ . '/../app/MapAssetService.php';

expect_map_asset_test(overview_filename_matches('overview.png'), 'Expected overview.png to match.');
expect_map_asset_test(overview_filename_matches('textures/mapOverview.dds'), 'Expected mapOverview.dds to match.');
expect_map_asset_test(overview_filename_matches('foo/ingameMap.jpg'), 'Expected ingameMap.jpg to match.');
expect_map_asset_test(!overview_filename_matches('overviewShedRoof.dds'), 'Expected partial overview filename to be rejected.');
expect_map_asset_test(!overview_filename_matches('preview.dds'), 'Expected preview.dds to be rejected.');

expect_map_asset_test(overview_file_type('overview.png') === 'png', 'Expected PNG file type.');
expect_map_asset_test(overview_file_type('overview.jpeg') === 'png', 'Expected JPEG file type to be handled as png candidate.');
expect_map_asset_test(overview_file_type('overview.dds') === 'dds', 'Expected DDS file type.');
expect_map_asset_test(overview_file_type('overview.webp') === null, 'Expected WEBP overview search candidate to be rejected.');

$ddsOnly = pick_best_overview_candidate([
    ['name' => 'overview.dds', 'size' => 4096, 'type' => 'dds'],
]);
expect_map_asset_test($ddsOnly['found'] === false, 'Expected DDS-only candidate set not to be directly usable.');
expect_map_asset_test($ddsOnly['ddsOnly'] === true, 'Expected DDS-only result flag.');

$pick = pick_best_overview_candidate([
    ['name' => 'small/overview.png', 'size' => 100, 'type' => 'png'],
    ['name' => 'large/overview.jpg', 'size' => 200, 'type' => 'png'],
    ['name' => 'overview.dds', 'size' => 300, 'type' => 'dds'],
]);
expect_map_asset_test($pick['found'] === true, 'Expected PNG/JPEG candidate to be selected.');
expect_map_asset_test($pick['best']['name'] === 'large/overview.jpg', 'Expected largest PNG/JPEG candidate.');

$size = extract_map_size_from_xml_string('<map width="2048" height="4096"></map>');
expect_map_asset_test($size === ['width' => 2048, 'height' => 4096], 'Expected map size extraction.');
expect_map_asset_test(extract_map_size_from_xml_string('<vehicle width="2048" height="4096"></vehicle>') === null, 'Expected non-map XML rejection.');
expect_map_asset_test(extract_map_size_from_xml_string('<map width="0" height="4096"></map>') === null, 'Expected invalid map size rejection.');

echo "map_asset_service_test: ok\n";
