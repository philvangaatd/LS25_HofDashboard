from pathlib import Path

path = Path('api.php')
php = path.read_text(encoding='utf-8')

old = "        'weatherForecast'        => [],"
new = "        'weatherForecast'        => ($folder && $currentDayLive > 0 && isset($dir) && is_dir($dir))\n            ? get_weather_forecast($dir, $currentDayLive, 5)\n            : [],"

count = php.count(old)
if count != 1:
    raise SystemExit(f'expected exactly one disabled weatherForecast entry, found {count}')

php = php.replace(old, new, 1)

required = [
    "get_weather_forecast($dir, $currentDayLive, 5)",
    "'weatherForecast'",
    "function get_weather_forecast(",
]
for needle in required:
    if needle not in php:
        raise SystemExit(f'missing required weather contract: {needle}')

path.write_text(php, encoding='utf-8')
