<?php
declare(strict_types=1);

function api_json_response(array $payload, int $status = 200): void
{
    if ($status !== 200) {
        http_response_code($status);
    }

    echo json_encode($payload);
}

function api_json_error(string $message, int $status): void
{
    api_json_response(['error' => $message], $status);
}

function api_json_success(array $payload = []): void
{
    api_json_response(array_merge(['success' => true], $payload));
}
