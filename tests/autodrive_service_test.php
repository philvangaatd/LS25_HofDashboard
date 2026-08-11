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
file_put_contents($xmlPath, '<AutoDrive><waypoints><id>1,2,42</id></waypoints></AutoDrive>');

$dom = load_dom($xmlPath);
expect_autodrive_test($dom instanceof DOMDocument, 'Expected AutoDrive XML to load as DOMDocument.');

$ids = get_valid_waypoint_ids($dom);
expect_autodrive_test(isset($ids['1'], $ids['2'], $ids['42']), 'Expected waypoint IDs to be indexed.');
expect_autodrive_test(!isset($ids['3']), 'Expected unknown waypoint ID to be absent.');

$emptyDom = new DOMDocument('1.0', 'utf-8');
$emptyDom->loadXML('<AutoDrive/>');
expect_autodrive_test(get_valid_waypoint_ids($emptyDom) === [], 'Expected no IDs without id node.');

unlink($xmlPath);

echo "autodrive_service_test: ok\n";
