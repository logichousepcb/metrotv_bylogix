<?php
declare(strict_types=1);

$stationsPath = __DIR__ . '/newnfta-transit-tv.json';
$transitSettingsPath = __DIR__ . '/../uploads/app-data/nfta_tv.json';
$transitCachePath = __DIR__ . '/../uploads/app-data/raw_transit_cache.json';
$fetchTransitScriptPath = __DIR__ . '/../private/fetch_transit_data.py';
$gtfsRtSettingsPath = __DIR__ . '/../uploads/app-data/nfta_tv_gtfs_rt.json';
$gtfsRtCachePath = __DIR__ . '/../uploads/app-data/raw_gtfs_rt_cache.json';
$fetchGtfsRtScriptPath = __DIR__ . '/../private/fetch_gtfs_rt_data.py';
$defaultRadiusMiles = 25.0;
$defaultTripUpdatesUrl = 'https://gtfsr.nfta.com/api/tripupdates?format=gtfs.proto';
$defaultVehiclePositionsUrl = 'https://gtfsr.nfta.com/api/vehiclepositions?format=gtfs.proto';
$defaultAlertsUrl = 'https://gtfsr.nfta.com/api/servicealerts?format=gtfs.proto';
$message = null;
$messageType = 'info';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function station_editor_default_station(): array
{
    return [
        'station_trigger' => '',
        'station_name' => '',
        'source' => 'TRANSIT APP',
        'station_settings_url' => '',
        'longitude' => '',
        'latitude' => '',
        'distance' => '',
        'scroll_speed' => 2,
        'rounded' => false,
        'alert' => true,
        'verticle' => false,
        'trip_count' => 3,
        'pinned_route' => '',
        'routes' => [],
    ];
}

function station_editor_load_stations(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Stored stations.json is invalid.');
    }

    return array_values(array_filter($decoded, 'is_array'));
}

function station_editor_save_stations(string $path, array $stations): void
{
    $json = json_encode($stations, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Unable to encode stations.json.');
    }

    $tmpPath = $path . '.tmp';
    if (file_put_contents($tmpPath, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write temporary stations.json file.');
    }

    if (!@rename($tmpPath, $path)) {
        @unlink($tmpPath);
        throw new RuntimeException('Unable to finalize stations.json save.');
    }
}

function station_editor_parse_bool(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function station_editor_normalize_routes(string $value): array
{
    $parts = preg_split('/[\r\n,]+/', $value);
    if ($parts === false) {
        return [];
    }

    $routes = [];
    foreach ($parts as $part) {
        $route = trim($part);
        if ($route !== '') {
            $routes[] = $route;
        }
    }

    return array_values(array_unique($routes));
}

function station_editor_normalize_source(mixed $value): string
{
    $normalized = strtoupper(trim((string) $value));
    if ($normalized === 'GTSFR') {
        return 'GTSFR';
    }

    return 'TRANSIT APP';
}

function station_editor_load_transit_state(string $path): array
{
    $state = [
        'latitude' => '',
        'longitude' => '',
        'api_key' => '',
        'radius_miles' => '25',
    ];

    if (!is_file($path) || !is_readable($path)) {
        return $state;
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return $state;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Stored NFTA TV settings JSON is invalid.');
    }

    foreach (['latitude', 'longitude', 'api_key', 'radius_miles'] as $field) {
        if (isset($decoded[$field]) && is_scalar($decoded[$field])) {
            $state[$field] = trim((string) $decoded[$field]);
        }
    }

    return $state;
}

function station_editor_load_gtfs_rt_state(string $path): array
{
    $state = [
        'latitude' => '',
        'longitude' => '',
        'radius_miles' => '25',
        'trip_updates_url' => $GLOBALS['defaultTripUpdatesUrl'],
        'vehicle_positions_url' => $GLOBALS['defaultVehiclePositionsUrl'],
        'alerts_url' => $GLOBALS['defaultAlertsUrl'],
    ];

    if (!is_file($path) || !is_readable($path)) {
        return $state;
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return $state;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Stored NFTA TV GTFS-RT settings JSON is invalid.');
    }

    foreach (['latitude', 'longitude', 'radius_miles', 'trip_updates_url', 'vehicle_positions_url', 'alerts_url'] as $field) {
        if (isset($decoded[$field]) && is_scalar($decoded[$field])) {
            $state[$field] = trim((string) $decoded[$field]);
        }
    }

    return $state;
}

function station_editor_command_exists(string $command): bool
{
    $trimmed = trim($command);
    if ($trimmed === '') {
        return false;
    }

    $output = [];
    $code = 1;
    @exec('where ' . escapeshellarg($trimmed), $output, $code);
    if ($code === 0 && $output !== []) {
        return true;
    }

    $output = [];
    $code = 1;
    @exec('command -v ' . escapeshellarg($trimmed) . ' 2>/dev/null', $output, $code);
    return $code === 0 && $output !== [];
}

function station_editor_python_can_run(string $pythonCommand, string $code, ?string $prependPath = null): bool
{
    $output = [];
    $exitCode = 1;
    $wrappedCode = $code;
    if ($prependPath !== null && $prependPath !== '') {
        $wrappedCode = 'import sys; sys.path.insert(0, ' . var_export($prependPath, true) . '); ' . $code;
    }

    @exec($pythonCommand . ' -c ' . escapeshellarg($wrappedCode) . ' 2>&1', $output, $exitCode);
    return $exitCode === 0;
}

function station_editor_resolve_python_command(?string $requiredImportCode = null): ?string
{
    $scriptImportPath = realpath(__DIR__ . '/../private') ?: (__DIR__ . '/../private');
    $envOverride = getenv('NFTA_TV_GTFS_RT_PYTHON');
    if (is_string($envOverride) && trim($envOverride) !== '') {
        $normalizedOverride = trim($envOverride);
        if (is_file($normalizedOverride) && is_executable($normalizedOverride)) {
            $overrideCommand = escapeshellarg($normalizedOverride);
            if ($requiredImportCode === null || station_editor_python_can_run($overrideCommand, $requiredImportCode, $scriptImportPath)) {
                return $overrideCommand;
            }
        }
    }

    $candidates = [
        'python3',
        'python',
        'py -3',
        'py',
        '/mnt/logixwww/.venv/bin/python',
        '/home/pascal/logixwww-venv/bin/python',
        '/usr/bin/python3',
        '/usr/local/bin/python3',
        'C:\\Windows\\py.exe -3',
        'C:\\Windows\\py.exe',
        'C:\\Python313\\python.exe',
        'C:\\Python312\\python.exe',
        'C:\\Python311\\python.exe',
        'C:\\Python310\\python.exe',
        'C:\\Program Files\\Python313\\python.exe',
        'C:\\Program Files\\Python312\\python.exe',
        'C:\\Program Files\\Python311\\python.exe',
        'C:\\Program Files\\Python310\\python.exe',
    ];

    foreach ($candidates as $candidate) {
        $parts = preg_split('/\s+/', trim($candidate));
        if ($parts === false || $parts === []) {
            continue;
        }

        $binary = $parts[0];
        if (str_contains($binary, ':\\') || str_starts_with($binary, '\\\\')) {
            if (is_file($binary)) {
                $command = escapeshellarg($binary) . (count($parts) > 1 ? ' ' . implode(' ', array_slice($parts, 1)) : '');
                if ($requiredImportCode === null || station_editor_python_can_run($command, $requiredImportCode, $scriptImportPath)) {
                    return $command;
                }
            }
            continue;
        }

        if (station_editor_command_exists($binary)) {
            if ($requiredImportCode === null || station_editor_python_can_run($candidate, $requiredImportCode, $scriptImportPath)) {
                return $candidate;
            }
        }
    }

    return null;
}

function station_editor_parse_coordinate(string $value, string $label): float
{
    if (!is_numeric($value)) {
        throw new RuntimeException($label . ' must be numeric.');
    }

    $number = (float) $value;
    if ($label === 'Latitude' && ($number < -90.0 || $number > 90.0)) {
        throw new RuntimeException('Latitude must be between -90 and 90.');
    }

    if ($label === 'Longitude' && ($number < -180.0 || $number > 180.0)) {
        throw new RuntimeException('Longitude must be between -180 and 180.');
    }

    return $number;
}

function station_editor_parse_radius_miles(string $value): float
{
    if (!is_numeric($value)) {
        throw new RuntimeException('Distance in miles must be numeric.');
    }

    $number = (float) $value;
    if ($number <= 0) {
        throw new RuntimeException('Distance in miles must be greater than zero.');
    }

    return $number;
}

function station_editor_parse_feed_url(string $value, string $label, bool $allowBlank = false): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        if ($allowBlank) {
            return '';
        }

        throw new RuntimeException($label . ' is required.');
    }

    if (filter_var($trimmed, FILTER_VALIDATE_URL) === false) {
        throw new RuntimeException($label . ' must be a valid URL.');
    }

    return $trimmed;
}

function station_editor_run_fetch_transit_script(string $scriptPath, string $cachePath, float $latitude, float $longitude, string $apiKey, float $radiusMiles): void
{
    if (!is_file($scriptPath) || !is_readable($scriptPath)) {
        throw new RuntimeException('Transit fetch script is missing or not readable.');
    }

    $python = station_editor_resolve_python_command();
    if ($python === null) {
        throw new RuntimeException('Python runtime was not found on this server.');
    }

    $command = $python
        . ' ' . escapeshellarg($scriptPath)
        . ' --lat ' . escapeshellarg(number_format($latitude, 6, '.', ''))
        . ' --lon ' . escapeshellarg(number_format($longitude, 6, '.', ''))
        . ' --api-key ' . escapeshellarg($apiKey)
        . ' --radius-miles ' . escapeshellarg(number_format($radiusMiles, 3, '.', ''))
        . ' --output ' . escapeshellarg($cachePath);

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = @proc_open($command, $descriptorSpec, $pipes, dirname($scriptPath));
    if (!is_resource($process)) {
        throw new RuntimeException('Failed to start the transit fetch script.');
    }

    fclose($pipes[0]);
    $stdout = trim(stream_get_contents($pipes[1]) ?: '');
    fclose($pipes[1]);
    $stderr = trim(stream_get_contents($pipes[2]) ?: '');
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        $details = $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'Unknown script error.');
        throw new RuntimeException('Transit fetch failed: ' . $details);
    }
}

function station_editor_run_fetch_gtfs_rt_script(
    string $scriptPath,
    string $cachePath,
    float $latitude,
    float $longitude,
    float $radiusMiles,
    string $tripUpdatesUrl,
    string $vehiclePositionsUrl,
    string $alertsUrl
): void {
    if (!is_file($scriptPath) || !is_readable($scriptPath)) {
        throw new RuntimeException('GTFS-RT fetch script is missing or not readable.');
    }

    $python = station_editor_resolve_python_command('from google.transit import gtfs_realtime_pb2');
    if ($python === null) {
        throw new RuntimeException('No Python runtime with google.transit.gtfs_realtime_pb2 was found on this server.');
    }

    $command = $python
        . ' ' . escapeshellarg($scriptPath)
        . ' --lat ' . escapeshellarg(number_format($latitude, 6, '.', ''))
        . ' --lon ' . escapeshellarg(number_format($longitude, 6, '.', ''))
        . ' --radius-miles ' . escapeshellarg(number_format($radiusMiles, 3, '.', ''))
        . ' --trip-updates-url ' . escapeshellarg($tripUpdatesUrl)
        . ' --vehicle-positions-url ' . escapeshellarg($vehiclePositionsUrl)
        . ' --alerts-url ' . escapeshellarg($alertsUrl)
        . ' --output ' . escapeshellarg($cachePath);

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = @proc_open($command, $descriptorSpec, $pipes, dirname($scriptPath));
    if (!is_resource($process)) {
        throw new RuntimeException('Failed to start the GTFS-RT fetch script.');
    }

    fclose($pipes[0]);
    $stdout = trim(stream_get_contents($pipes[1]) ?: '');
    fclose($pipes[1]);
    $stderr = trim(stream_get_contents($pipes[2]) ?: '');
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        $details = $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'Unknown script error.');
        throw new RuntimeException('GTFS-RT fetch failed: ' . $details);
    }
}

function station_editor_build_form_station(array $station): array
{
    $defaults = station_editor_default_station();
    $merged = array_merge($defaults, $station);
    $merged['station_trigger'] = trim((string) ($merged['station_trigger'] ?? ''));
    $merged['station_name'] = trim((string) ($merged['station_name'] ?? ''));
    $merged['source'] = station_editor_normalize_source($merged['source'] ?? 'TRANSIT APP');
    $merged['station_settings_url'] = trim((string) ($merged['station_settings_url'] ?? ''));
    $merged['routes'] = array_values(array_map('strval', is_array($merged['routes']) ? $merged['routes'] : []));
    $merged['scroll_speed'] = is_numeric($merged['scroll_speed']) ? (int) $merged['scroll_speed'] : 2;
    $merged['rounded'] = station_editor_parse_bool($merged['rounded']);
    $merged['alert'] = station_editor_parse_bool($merged['alert']);
    $merged['verticle'] = station_editor_parse_bool($merged['verticle']);
    $merged['trip_count'] = isset($merged['trip_count']) && is_numeric($merged['trip_count']) ? (int) $merged['trip_count'] : 3;
    $merged['pinned_route'] = trim((string) ($merged['pinned_route'] ?? ''));
    return $merged;
}

function station_editor_station_trigger(array $station): string
{
    // Prefer the stored station_trigger value; fall back to slugifying station_name.
    $stored = trim((string) ($station['station_trigger'] ?? ''));
    if ($stored !== '') {
        return strtolower($stored);
    }

    $name = strtolower(trim((string) ($station['station_name'] ?? '')));
    $slugChars = [];
    $lastWasDash = false;

    foreach (str_split($name) as $character) {
        if (ctype_alnum($character)) {
            $slugChars[] = $character;
            $lastWasDash = false;
            continue;
        }

        if (!$lastWasDash) {
            $slugChars[] = '-';
            $lastWasDash = true;
        }
    }

    $slug = trim(implode('', $slugChars), '-');
    return $slug !== '' ? $slug : 'station';
}

function station_editor_station_index_from_request(array $stations): int
{
    $requestedIndex = filter_input(INPUT_GET, 'station', FILTER_VALIDATE_INT);
    if ($requestedIndex !== null && $requestedIndex !== false && isset($stations[$requestedIndex])) {
        return $requestedIndex;
    }

    $requestedTrigger = trim((string) filter_input(INPUT_GET, 'station_trigger'));
    if ($requestedTrigger !== '') {
        foreach ($stations as $index => $station) {
            if (station_editor_station_trigger($station) === strtolower($requestedTrigger)) {
                return $index;
            }
        }
    }

    return array_key_exists(0, $stations) ? 0 : -1;
}

$stations = station_editor_load_stations($stationsPath);
$selectedIndex = station_editor_station_index_from_request($stations);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = trim((string) ($_POST['action'] ?? 'save'));
        $postedIndex = filter_input(INPUT_POST, 'station_index', FILTER_VALIDATE_INT);

        if ($action === 'fetch_transit_app') {
            $transitState = station_editor_load_transit_state($transitSettingsPath);
            $latitudeValue = trim((string) ($transitState['latitude'] ?? ''));
            $longitudeValue = trim((string) ($transitState['longitude'] ?? ''));
            $apiKeyValue = trim((string) ($transitState['api_key'] ?? ''));
            $radiusMilesValue = trim((string) (($transitState['radius_miles'] ?? '') !== '' ? $transitState['radius_miles'] : (string) $defaultRadiusMiles));

            if ($latitudeValue === '' || $longitudeValue === '' || $apiKeyValue === '') {
                throw new RuntimeException('Saved NFTA TV settings must include latitude, longitude, and a Transit API key before fetching.');
            }

            $latitude = station_editor_parse_coordinate($latitudeValue, 'Latitude');
            $longitude = station_editor_parse_coordinate($longitudeValue, 'Longitude');
            $radiusMiles = station_editor_parse_radius_miles($radiusMilesValue);
            station_editor_run_fetch_transit_script($fetchTransitScriptPath, $transitCachePath, $latitude, $longitude, $apiKeyValue, $radiusMiles);
            $message = 'Transit data fetched using the saved NFTA TV settings.';
            $messageType = 'success';
        } elseif ($action === 'fetch_gtsfr') {
            $gtfsRtState = station_editor_load_gtfs_rt_state($gtfsRtSettingsPath);
            $latitudeValue = trim((string) ($gtfsRtState['latitude'] ?? ''));
            $longitudeValue = trim((string) ($gtfsRtState['longitude'] ?? ''));
            $radiusMilesValue = trim((string) (($gtfsRtState['radius_miles'] ?? '') !== '' ? $gtfsRtState['radius_miles'] : (string) $defaultRadiusMiles));
            $tripUpdatesUrlValue = (string) ($gtfsRtState['trip_updates_url'] ?? $defaultTripUpdatesUrl);
            $vehiclePositionsUrlValue = (string) ($gtfsRtState['vehicle_positions_url'] ?? $defaultVehiclePositionsUrl);
            $alertsUrlValue = (string) ($gtfsRtState['alerts_url'] ?? $defaultAlertsUrl);

            if ($latitudeValue === '' || $longitudeValue === '') {
                throw new RuntimeException('Saved NFTA TV GTFS-RT settings must include latitude and longitude before fetching.');
            }

            $latitude = station_editor_parse_coordinate($latitudeValue, 'Latitude');
            $longitude = station_editor_parse_coordinate($longitudeValue, 'Longitude');
            $radiusMiles = station_editor_parse_radius_miles($radiusMilesValue);
            $tripUpdatesUrl = station_editor_parse_feed_url($tripUpdatesUrlValue, 'Trip updates URL');
            $vehiclePositionsUrl = station_editor_parse_feed_url($vehiclePositionsUrlValue, 'Vehicle positions URL');
            $alertsUrl = station_editor_parse_feed_url($alertsUrlValue, 'Service alerts URL', true);
            station_editor_run_fetch_gtfs_rt_script(
                $fetchGtfsRtScriptPath,
                $gtfsRtCachePath,
                $latitude,
                $longitude,
                $radiusMiles,
                $tripUpdatesUrl,
                $vehiclePositionsUrl,
                $alertsUrl
            );
            $message = 'GTFS-RT data fetched using the saved NFTA TV GTFS-RT settings.';
            $messageType = 'success';
        } elseif ($action === 'add') {
            $stations[] = station_editor_default_station();
            station_editor_save_stations($stationsPath, $stations);
            $selectedIndex = array_key_last($stations);
            $message = 'New station added.';
            $messageType = 'success';
        } elseif ($action === 'delete') {
            if ($postedIndex === null || $postedIndex === false || !isset($stations[$postedIndex])) {
                throw new RuntimeException('A valid station must be selected before deleting.');
            }

            array_splice($stations, $postedIndex, 1);
            station_editor_save_stations($stationsPath, $stations);
            $selectedIndex = $stations === [] ? -1 : min($postedIndex, count($stations) - 1);
            $message = 'Station deleted.';
            $messageType = 'success';
        } else {
            if ($postedIndex === null || $postedIndex === false || !isset($stations[$postedIndex])) {
                throw new RuntimeException('A valid station must be selected before saving.');
            }

            $updatedStation = [
                'station_trigger' => trim((string) ($_POST['station_trigger'] ?? '')),
                'station_name' => trim((string) ($_POST['station_name'] ?? '')),
                'source' => station_editor_normalize_source($_POST['source'] ?? 'TRANSIT APP'),
                'station_settings_url' => trim((string) ($_POST['station_settings_url'] ?? '')),
                'longitude' => trim((string) ($_POST['longitude'] ?? '')),
                'latitude' => trim((string) ($_POST['latitude'] ?? '')),
                'distance' => trim((string) ($_POST['distance'] ?? '')),
                'scroll_speed' => (int) ($_POST['scroll_speed'] ?? 2),
                'rounded' => station_editor_parse_bool($_POST['rounded'] ?? 'false'),
                'alert' => station_editor_parse_bool($_POST['alert'] ?? 'false'),
                'verticle' => station_editor_parse_bool($_POST['verticle'] ?? 'false'),
                'trip_count' => max(1, (int) ($_POST['trip_count'] ?? 3)),
                'pinned_route' => trim((string) ($_POST['pinned_route'] ?? '')),
                'routes' => station_editor_normalize_routes((string) ($_POST['routes'] ?? '')),
            ];

            if ($updatedStation['station_name'] === '') {
                throw new RuntimeException('Station name is required.');
            }

            if ($updatedStation['station_trigger'] === '') {
                throw new RuntimeException('Station trigger is required.');
            }

            $stations[$postedIndex] = $updatedStation;
            station_editor_save_stations($stationsPath, $stations);
            $message = 'Station settings saved.';
            $messageType = 'success';
            $selectedIndex = $postedIndex;
        }
    } catch (Throwable $exception) {
        $message = $exception->getMessage();
        $messageType = 'error';
        $selectedIndex = isset($postedIndex) && is_int($postedIndex) ? $postedIndex : $selectedIndex;
    }
}

$selectedStation = $selectedIndex >= 0 && isset($stations[$selectedIndex])
    ? station_editor_build_form_station($stations[$selectedIndex])
    : station_editor_default_station();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NFTA Transit TV Station Editor</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: #eef3f5;
            color: #13313e;
        }

        main {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1rem 2rem;
        }

        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        h1 {
            margin: 0;
            font-size: 2rem;
        }

        .subhead {
            margin: 0.5rem 0 0;
            color: #48616d;
            line-height: 1.5;
        }

        .layout {
            display: grid;
            grid-template-columns: 320px minmax(0, 1fr);
            gap: 1.5rem;
            align-items: start;
        }

        .panel {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 18px 44px rgba(19, 49, 62, 0.12);
            padding: 1.5rem;
        }

        .station-list {
            display: grid;
            gap: 0.75rem;
        }

        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .station-link {
            display: block;
            padding: 1rem 1.1rem;
            border-radius: 12px;
            background: #f4f8fa;
            color: #13313e;
            text-decoration: none;
            border: 1px solid transparent;
            font-weight: 700;
        }

        .station-link small {
            display: block;
            margin-top: 0.3rem;
            color: #58717c;
            font-weight: 400;
        }

        .station-link.active,
        .station-link:hover,
        .station-link:focus-visible {
            background: #e8f8ee;
            border-color: #2fb36d;
        }

        form {
            display: grid;
            gap: 1rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
        }

        label {
            display: grid;
            gap: 0.4rem;
            font-weight: 700;
        }

        input,
        select,
        textarea {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid #c8d6de;
            border-radius: 10px;
            padding: 0.8rem 0.9rem;
            font: inherit;
            color: inherit;
            background: #ffffff;
        }

        textarea {
            min-height: 180px;
            resize: vertical;
        }

        .full-width {
            grid-column: 1 / -1;
        }

        .message {
            margin-bottom: 1rem;
            padding: 0.9rem 1rem;
            border-radius: 12px;
            font-weight: 700;
        }

        .message.success {
            background: #e8f8ee;
            color: #1f5f33;
        }

        .message.error {
            background: #ffe8e6;
            color: #8a2d21;
        }

        .actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .actions form {
            display: inline;
            gap: 0;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.8rem 1.2rem;
            border-radius: 999px;
            text-decoration: none;
            font-weight: 700;
            border: 0;
            cursor: pointer;
            font: inherit;
        }

        .btn-primary {
            background: #2fb36d;
            color: #ffffff;
        }

        .btn-secondary {
            background: #e7eef2;
            color: #13313e;
        }

        .btn-danger {
            background: #d64541;
            color: #ffffff;
        }

        .empty-state {
            margin: 0;
            color: #58717c;
            line-height: 1.5;
        }

        @media (max-width: 900px) {
            .layout {
                grid-template-columns: 1fr;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .header {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <main>
        <div class="header">
            <div>
                <h1>NFTA Transit TV Station Editor</h1>
                <p class="subhead">Choose a station from newnfta-transit-tv.json, then edit and save all fields for that station.</p>
            </div>
            <div class="actions">
                <form method="post">
                    <input type="hidden" name="action" value="fetch_transit_app">
                    <button class="btn btn-secondary" type="submit">FETCH TRANSIT APP</button>
                </form>
                <form method="post">
                    <input type="hidden" name="action" value="fetch_gtsfr">
                    <button class="btn btn-secondary" type="submit">FETCH GTSFR</button>
                </form>
                <a class="btn btn-secondary" href="/nfta-transit-tv.html">Open TV Board</a>
                <a class="btn btn-secondary" href="/nfta-tv.php">Open Transit Admin</a>
            </div>
        </div>

        <div class="layout">
            <section class="panel" aria-label="Stations list">
                <div class="panel-header">
                    <h2>Stations</h2>
                    <form method="post">
                        <input type="hidden" name="action" value="add">
                        <button class="btn btn-primary" type="submit">Add Station</button>
                    </form>
                </div>
                <?php if ($stations === []): ?>
                    <p class="empty-state">No stations were found in newnfta-transit-tv.json.</p>
                <?php else: ?>
                    <div class="station-list">
                        <?php foreach ($stations as $index => $station): ?>
                            <a class="station-link <?= $index === $selectedIndex ? 'active' : '' ?>" href="?station=<?= h((string) $index) ?>">
                                <?= h((string) ($station['station_name'] ?? $station['name'] ?? 'Unnamed Station')) ?>
                                <small><?= h((string) ($station['station_trigger'] ?? '')) ?></small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="panel" aria-label="Station editor">
                <h2>Edit Station</h2>

                <?php if ($message !== null): ?>
                    <div class="message <?= h($messageType) ?>"><?= h($message) ?></div>
                <?php endif; ?>

                <?php if ($selectedIndex < 0 || !isset($stations[$selectedIndex])): ?>
                    <p class="empty-state">Select a station from the list to edit its settings.</p>
                <?php else: ?>
                    <form method="post">
                        <input type="hidden" name="station_index" value="<?= h((string) $selectedIndex) ?>">
                        <input type="hidden" name="action" value="save">

                        <div class="form-grid">
                            <label>
                                Station Name
                                <input type="text" name="station_name" value="<?= h((string) $selectedStation['station_name']) ?>" required>
                            </label>

                            <label>
                                Station Trigger
                                <input type="text" name="station_trigger" value="<?= h((string) $selectedStation['station_trigger']) ?>" required placeholder="e.g. university">
                            </label>

                            <label>
                                Source
                                <select name="source">
                                    <option value="GTSFR" <?= $selectedStation['source'] === 'GTSFR' ? 'selected' : '' ?>>GTSFR</option>
                                    <option value="TRANSIT APP" <?= $selectedStation['source'] === 'TRANSIT APP' ? 'selected' : '' ?>>TRANSIT APP</option>
                                </select>
                            </label>

                            <label class="full-width">
                                Station Settings URL
                                <input type="text" name="station_settings_url" value="<?= h((string) $selectedStation['station_settings_url']) ?>" placeholder="/uploads/app-data/university_transit_data.json">
                            </label>

                            <label>
                                Distance
                                <input type="text" name="distance" value="<?= h((string) $selectedStation['distance']) ?>">
                            </label>

                            <label>
                                Latitude
                                <input type="text" name="latitude" value="<?= h((string) $selectedStation['latitude']) ?>">
                            </label>

                            <label>
                                Longitude
                                <input type="text" name="longitude" value="<?= h((string) $selectedStation['longitude']) ?>">
                            </label>

                            <label>
                                Scroll Speed
                                <input type="number" name="scroll_speed" value="<?= h((string) $selectedStation['scroll_speed']) ?>" step="1">
                            </label>

                            <label>
                                Rounded
                                <select name="rounded">
                                    <option value="true" <?= $selectedStation['rounded'] ? 'selected' : '' ?>>True</option>
                                    <option value="false" <?= !$selectedStation['rounded'] ? 'selected' : '' ?>>False</option>
                                </select>
                            </label>

                            <label>
                                Alert
                                <select name="alert">
                                    <option value="true" <?= $selectedStation['alert'] ? 'selected' : '' ?>>True</option>
                                    <option value="false" <?= !$selectedStation['alert'] ? 'selected' : '' ?>>False</option>
                                </select>
                            </label>

                            <label>
                                Verticle
                                <select name="verticle">
                                    <option value="true" <?= $selectedStation['verticle'] ? 'selected' : '' ?>>True</option>
                                    <option value="false" <?= !$selectedStation['verticle'] ? 'selected' : '' ?>>False</option>
                                </select>
                            </label>

                            <label>
                                Trip Count
                                <input type="number" name="trip_count" value="<?= h((string) $selectedStation['trip_count']) ?>" min="1" max="10" step="1">
                            </label>

                            <label>
                                Pinned Route
                                <input type="text" name="pinned_route" value="<?= h((string) $selectedStation['pinned_route']) ?>" placeholder="e.g. 145">
                            </label>

                            <label class="full-width">
                                Routes
                                <textarea name="routes"><?= h(implode(PHP_EOL, $selectedStation['routes'])) ?></textarea>
                            </label>
                        </div>

                        <div class="actions">
                            <button class="btn btn-primary" type="submit">Save Station</button>
                            <a class="btn btn-secondary" href="?station=<?= h((string) $selectedIndex) ?>">Reset</a>
                            <button class="btn btn-danger" type="submit" name="action" value="delete" onclick="return confirm('Delete this station?');">Delete Station</button>
                        </div>
                    </form>
                <?php endif; ?>
            </section>
        </div>
    </main>
</body>
</html>