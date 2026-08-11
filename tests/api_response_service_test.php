<?php
declare(strict_types=1);

function expect_api_response_test(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

require __DIR__ . '/../app/ApiResponseService.php';

http_response_code(200);
ob_start();
api_json_response(['value' => 42]);
$jsonResponse = ob_get_clean();
expect_api_response_test($jsonResponse === '{"value":42}', 'Expected JSON response output.');
expect_api_response_test(http_response_code() === 200, 'Expected default response status to stay 200.');

http_response_code(200);
ob_start();
api_json_error('not_found', 404);
$errorResponse = ob_get_clean();
expect_api_response_test($errorResponse === '{"error":"not_found"}', 'Expected JSON error output.');
expect_api_response_test(http_response_code() === 404, 'Expected error status code.');

http_response_code(200);
ob_start();
api_json_success(['count' => 2]);
$successResponse = ob_get_clean();
expect_api_response_test($successResponse === '{"success":true,"count":2}', 'Expected JSON success output.');
expect_api_response_test(http_response_code() === 200, 'Expected success response status.');

echo "api_response_service_test: ok\n";
