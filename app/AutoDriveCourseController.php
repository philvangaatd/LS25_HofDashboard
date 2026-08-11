<?php
declare(strict_types=1);

function handle_autodrive_course_data(): void
{
    if (empty($_SESSION['savegame_folder'])) {
        api_json_error('no_savegame_selected', 409);
        return;
    }

    $configPath = get_selected_config_path();
    if (!$configPath) {
        api_json_error('no_autodrive', 409);
        return;
    }

    $dom = load_dom($configPath);
    api_json_response(read_autodrive_course_data($dom));
}
