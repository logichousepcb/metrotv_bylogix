<?php
declare(strict_types=1);

require_once __DIR__ . '/../private/require_auth.php';
require_once __DIR__ . '/../private/auth.php';

auth_require_roles(['owner', 'transit']);

$dataPath = __DIR__ . '/../uploads/app-data/nfta_tv.json';
$cachePath = __DIR__ . '/../uploads/app-data/raw_transit_cache.json';
$fetchScriptPath = __DIR__ . '/../private/fetch_transit_data.py';
$defaultRadiusMiles = 25.0;
$message = null;
$messageType = 'info';
$fetchMessage = null;
$fetchMessageType = 'info';
$cacheUpdatedAt = null;
$departureRows = [];
$apiPayload = null;

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function nfta_tv_default_state(): array
{
    return [
        'latitude' => '',
        'longitude' => '',
        'api_key' => '',
        'radius_miles' => '25',
        'updated_at_utc' => null,
    ];
}

function nfta_tv_ensure_directory_exists(string $filePath): void
{
    $directory = dirname($filePath);
    if (is_dir($directory)) {
        return;
    }

    if (!@mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create data directory for NFTA TV settings.');
    }
}

function nfta_tv_load_state(string $path): array
{
    $state = nfta_tv_default_state();

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

    if (isset($decoded['updated_at_utc']) && is_string($decoded['updated_at_utc'])) {
        $state['updated_at_utc'] = $decoded['updated_at_utc'];
    }

    return $state;
}

function nfta_tv_save_state(string $path, array $state): void
{
    nfta_tv_ensure_directory_exists($path);

    $payload = [
        'latitude' => trim((string) ($state['latitude'] ?? '')),
        'longitude' => trim((string) ($state['longitude'] ?? '')),
        'api_key' => trim((string) ($state['api_key'] ?? '')),
        'radius_miles' => trim((string) ($state['radius_miles'] ?? '25')),
        'updated_at_utc' => gmdate('c'),
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Unable to encode NFTA TV settings JSON.');
    }

    $tmpPath = $path . '.tmp';
    if (file_put_contents($tmpPath, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write temporary NFTA TV settings file.');
    }

    if (!@rename($tmpPath, $path)) {
        @unlink($tmpPath);
        throw new RuntimeException('Unable to finalize NFTA TV settings save.');
    }
}

function nfta_tv_parse_coordinate(string $value, string $label): float
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

function nfta_tv_parse_radius_miles(string $value): float
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

function nfta_tv_load_cached_payload(string $path): ?array
{
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Cached transit data JSON is invalid.');
    }

    return $decoded;
}

function nfta_tv_command_exists(string $command): bool
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

function nfta_tv_resolve_python_command(): ?string
{
    $candidates = [
        'python3',
        'python',
        'py -3',
        'py',
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
                return escapeshellarg($binary) . (count($parts) > 1 ? ' ' . implode(' ', array_slice($parts, 1)) : '');
            }
            continue;
        }

        if (nfta_tv_command_exists($binary)) {
            return $candidate;
        }
    }

    return null;
}

function nfta_tv_run_fetch_script(string $scriptPath, string $cachePath, float $latitude, float $longitude, string $apiKey, float $radiusMiles): void
{
    if (!is_file($scriptPath) || !is_readable($scriptPath)) {
        throw new RuntimeException('Transit fetch script is missing or not readable.');
    }

    $python = nfta_tv_resolve_python_command();
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

function nfta_tv_http_get_json(string $url, array $headers): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL for Transit API request.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
        ]);

        $response = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (!is_string($response)) {
            throw new RuntimeException('Transit API request failed: ' . ($curlError !== '' ? $curlError : 'unknown cURL error') . '.');
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers) . "\r\n",
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if (!is_string($response)) {
            throw new RuntimeException('Transit API request failed.');
        }

        $statusCode = 0;
        $httpResponseHeader = $http_response_header ?? [];
        foreach ($httpResponseHeader as $headerLine) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $headerLine, $matches) === 1) {
                $statusCode = (int) $matches[1];
                break;
            }
        }
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Transit API returned invalid JSON.');
    }

    if ($statusCode >= 400) {
        $apiMessage = isset($decoded['message']) && is_string($decoded['message']) ? $decoded['message'] : 'Transit API request failed.';
        throw new RuntimeException($apiMessage);
    }

    return $decoded;
}

function nfta_tv_normalize_text(mixed $value): string
{
    if (!is_scalar($value)) {
        return '';
    }

    return trim((string) $value);
}

function nfta_tv_mode_from_route(array $route): string
{
    $routeType = $route['route_type'] ?? null;
    if (is_numeric($routeType)) {
        $normalizedType = (int) $routeType;
        if ($normalizedType === 3 || $normalizedType === 700) {
            return 'bus';
        }

        if (in_array($normalizedType, [0, 1, 2, 100, 109, 400, 401, 402, 403, 404], true)) {
            return 'train';
        }
    }

    $text = strtolower(implode(' ', array_filter([
        nfta_tv_normalize_text($route['route_long_name'] ?? ''),
        nfta_tv_normalize_text($route['route_short_name'] ?? ''),
        nfta_tv_normalize_text($route['route_display_short_name']['elements'][1] ?? ''),
    ])));

    if (str_contains($text, 'rail') || str_contains($text, 'metro') || str_contains($text, 'train') || str_contains($text, 'subway') || str_contains($text, 'tram')) {
        return 'train';
    }

    return 'bus';
}

function nfta_tv_parse_departure_timestamp(mixed $value): ?int
{
    if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
        $number = (float) $value;
        if ($number > 999999999999) {
            $number /= 1000.0;
        }

        if ($number > 0) {
            return (int) round($number);
        }
    }

    if (!is_string($value)) {
        return null;
    }

    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }

    if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $trimmed, $matches) === 1) {
        $hour = (int) $matches[1];
        $minute = (int) $matches[2];
        $second = isset($matches[3]) ? (int) $matches[3] : 0;
        return mktime($hour, $minute, $second);
    }

    $parsed = strtotime($trimmed);
    return $parsed === false ? null : $parsed;
}

function nfta_tv_format_eastern_time(int $timestamp): string
{
    $dateTime = new DateTimeImmutable('@' . $timestamp);
    $dateTime = $dateTime->setTimezone(new DateTimeZone('America/New_York'));

    return $dateTime->format('g:i A T');
}

function nfta_tv_format_eastern_datetime(int $timestamp): string
{
    $dateTime = new DateTimeImmutable('@' . $timestamp);
    $dateTime = $dateTime->setTimezone(new DateTimeZone('America/New_York'));

    return $dateTime->format('M j, Y g:i A T');
}

function nfta_tv_get_file_updated_at(string $path): ?string
{
    if (!is_file($path)) {
        return null;
    }

    $modifiedTime = @filemtime($path);
    if (!is_int($modifiedTime) || $modifiedTime <= 0) {
        return null;
    }

    return nfta_tv_format_eastern_datetime($modifiedTime);
}

function nfta_tv_extract_distance_meters(array $node): ?float
{
    foreach (['distance', 'distance_meters', 'distance_metres', 'walking_distance', 'walking_distance_meters'] as $key) {
        if (array_key_exists($key, $node) && is_numeric($node[$key])) {
            return (float) $node[$key];
        }
    }

    return null;
}

function nfta_tv_normalize_departures(array $payload, float $radiusMeters): array
{
    $rows = [];

    $routes = $payload['routes'] ?? null;
    if (!is_array($routes)) {
        return [];
    }

    foreach ($routes as $route) {
        if (!is_array($route)) {
            continue;
        }

        $mode = nfta_tv_mode_from_route($route);
        $routeLabel = nfta_tv_normalize_text(
            $route['route_short_name']
            ?? $route['route_display_short_name']['elements'][1]
            ?? $route['route_long_name']
            ?? ''
        );
        if ($routeLabel === '') {
            $routeLabel = strtoupper($mode);
        }

        $itineraries = $route['itineraries'] ?? null;
        if (!is_array($itineraries)) {
            continue;
        }

        foreach ($itineraries as $itinerary) {
            if (!is_array($itinerary)) {
                continue;
            }

            $closestStop = isset($itinerary['closest_stop']) && is_array($itinerary['closest_stop'])
                ? $itinerary['closest_stop']
                : [];
            $distanceMeters = nfta_tv_extract_distance_meters($closestStop);
            if ($distanceMeters !== null && $distanceMeters > $radiusMeters) {
                continue;
            }

            $stopName = nfta_tv_normalize_text($closestStop['stop_name'] ?? $itinerary['stop_name'] ?? '');
            $headsign = nfta_tv_normalize_text($itinerary['merged_headsign'] ?? $itinerary['headsign'] ?? $itinerary['direction_name'] ?? '');
            $scheduleItems = $itinerary['schedule_items'] ?? null;
            if (!is_array($scheduleItems)) {
                continue;
            }

            foreach ($scheduleItems as $scheduleItem) {
                if (!is_array($scheduleItem) || !array_key_exists('departure_time', $scheduleItem)) {
                    continue;
                }

                $timestamp = nfta_tv_parse_departure_timestamp($scheduleItem['departure_time']);
                if ($timestamp === null) {
                    continue;
                }

                $rows[] = [
                    'mode' => $mode,
                    'route' => $routeLabel,
                    'stop' => $stopName,
                    'headsign' => $headsign,
                    'departure_unix' => $timestamp,
                    'departure_label' => nfta_tv_format_eastern_time($timestamp),
                    'distance_meters' => $distanceMeters,
                    'is_real_time' => !empty($scheduleItem['is_real_time']),
                ];
            }
        }
    }

    $deduped = [];
    foreach ($rows as $row) {
        $signature = implode('|', [
            $row['mode'],
            $row['route'],
            $row['stop'],
            $row['headsign'],
            (string) ($row['departure_unix'] ?? $row['departure_label']),
        ]);
        $deduped[$signature] = $row;
    }

    $rows = array_values($deduped);
    usort(
        $rows,
        static function (array $left, array $right): int {
            $leftTs = $left['departure_unix'] ?? PHP_INT_MAX;
            $rightTs = $right['departure_unix'] ?? PHP_INT_MAX;

            if ($leftTs === $rightTs) {
                return strcmp($left['route'], $right['route']);
            }

            return $leftTs <=> $rightTs;
        }
    );

    return $rows;
}

function nfta_tv_fetch_nearby_departures(string $baseUrl, float $latitude, float $longitude, string $apiKey, int $radiusMeters): array
{
    $query = http_build_query([
        'lat' => number_format($latitude, 6, '.', ''),
        'lon' => number_format($longitude, 6, '.', ''),
        'max_distance' => $radiusMeters,
    ]);

    $payload = nfta_tv_http_get_json($baseUrl . '?' . $query, [
        'Accept: application/json',
        'apiKey: ' . $apiKey,
    ]);

    return [
        'payload' => $payload,
        'departures' => nfta_tv_normalize_departures($payload, (float) $radiusMeters),
    ];
}

try {
    $state = nfta_tv_load_state($dataPath);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $state['latitude'] = isset($_POST['latitude']) ? trim((string) $_POST['latitude']) : '';
        $state['longitude'] = isset($_POST['longitude']) ? trim((string) $_POST['longitude']) : '';
        $state['api_key'] = isset($_POST['api_key']) ? trim((string) $_POST['api_key']) : '';
        $state['radius_miles'] = isset($_POST['radius_miles']) ? trim((string) $_POST['radius_miles']) : (string) $defaultRadiusMiles;

        nfta_tv_save_state($dataPath, $state);
        $state = nfta_tv_load_state($dataPath);
        $message = 'NFTA TV settings saved.';
        $messageType = 'success';
    }

    $latitudeValue = trim((string) $state['latitude']);
    $longitudeValue = trim((string) $state['longitude']);
    $apiKeyValue = trim((string) $state['api_key']);
    $radiusMilesValue = trim((string) ($state['radius_miles'] !== '' ? $state['radius_miles'] : (string) $defaultRadiusMiles));

    if ($latitudeValue !== '' && $longitudeValue !== '' && $apiKeyValue !== '') {
        try {
            $latitude = nfta_tv_parse_coordinate($latitudeValue, 'Latitude');
            $longitude = nfta_tv_parse_coordinate($longitudeValue, 'Longitude');
            $radiusMiles = nfta_tv_parse_radius_miles($radiusMilesValue);
            $radiusMeters = (int) round($radiusMiles * 1609.344);
            $radiusMilesLabel = rtrim(rtrim(number_format($radiusMiles, 2, '.', ''), '0'), '.');

            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_fetch'])) {
                nfta_tv_run_fetch_script($fetchScriptPath, $cachePath, $latitude, $longitude, $apiKeyValue, $radiusMiles);
                $message = 'NFTA TV settings saved and transit data fetched.';
                $messageType = 'success';
            }

            $apiPayload = nfta_tv_load_cached_payload($cachePath);
            if ($apiPayload === null) {
                $fetchMessage = 'No cached transit data found yet. Click Fetch Transit Data to create ' . basename($cachePath) . '.';
                $fetchMessageType = 'info';
            } else {
                $cacheUpdatedAt = nfta_tv_get_file_updated_at($cachePath);
                $departureRows = nfta_tv_normalize_departures($apiPayload, (float) $radiusMeters);

                if ($departureRows === []) {
                    $fetchMessage = 'Cached transit data loaded, but no bus or train departures were found within ' . $radiusMilesLabel . ' miles.';
                    $fetchMessageType = 'info';
                } else {
                    $fetchMessage = 'Loaded ' . count($departureRows) . ' cached bus/train departures within ' . $radiusMilesLabel . ' miles.';
                    $fetchMessageType = 'success';
                }
            }
        } catch (Throwable $exception) {
            $fetchMessage = $exception->getMessage();
            $fetchMessageType = 'error';
        }
    } else {
        $fetchMessage = 'Enter coordinates, distance, and a Transit API key to fetch nearby departures into the cache.';
        $fetchMessageType = 'info';
    }
} catch (Throwable $exception) {
    $state = $state ?? nfta_tv_default_state();
    $message = $exception->getMessage();
    $messageType = 'error';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NFTA TV | LoGIX DIY</title>
    <style>
        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: #f2f6f8;
            color: #1c2a33;
            min-height: 100vh;
            padding: 1rem;
        }

        main {
            width: min(760px, 100%);
            margin: 2rem auto;
            background: #ffffff;
            border-radius: 14px;
            box-shadow: 0 16px 40px rgba(28, 42, 51, 0.12);
            padding: 2rem;
        }

        h1 {
            margin-top: 0;
            margin-bottom: 0.35rem;
        }

        .meta {
            color: #546471;
            margin-top: 0;
        }

        .message {
            padding: 0.85rem 1rem;
            border-radius: 8px;
            margin: 1rem 0;
            font-weight: 600;
        }

        .message.success {
            background: #e8f8ee;
            color: #1f5f33;
            border: 1px solid #b7e4c7;
        }

        .message.error {
            background: #ffecec;
            color: #7d1d1d;
            border: 1px solid #ffcaca;
        }

        .message.info {
            background: #eef4fb;
            color: #24507a;
            border: 1px solid #c8dbef;
        }

        form {
            display: grid;
            gap: 1rem;
            margin-top: 1.25rem;
        }

        label {
            display: grid;
            gap: 0.45rem;
            font-weight: 600;
        }

        input {
            width: 100%;
            box-sizing: border-box;
            padding: 0.7rem 0.8rem;
            border: 1px solid #c8d4de;
            border-radius: 8px;
            font: inherit;
        }

        .actions {
            margin-top: 0.5rem;
            display: flex;
            gap: 0.7rem;
            flex-wrap: wrap;
        }

        .btn {
            text-decoration: none;
            border: none;
            border-radius: 8px;
            padding: 0.65rem 0.95rem;
            font-weight: 700;
            cursor: pointer;
        }

        .btn-primary {
            background: #0f8b8d;
            color: #ffffff;
        }

        .btn-secondary {
            background: #ebf0f4;
            color: #1c2a33;
        }

        .hint {
            font-size: 0.92rem;
            color: #5b6d79;
            margin: 0;
        }

        .departures {
            margin-top: 2rem;
            border-top: 1px solid #dbe5ec;
            padding-top: 1.5rem;
        }

        .departure-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .departure-table th,
        .departure-table td {
            padding: 0.7rem;
            border-bottom: 1px solid #e1e8ee;
            text-align: left;
            vertical-align: top;
        }

        .departure-table th {
            background: #f8fbfd;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: #3f5564;
        }

        .pill {
            display: inline-block;
            padding: 0.25rem 0.55rem;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .pill.bus {
            background: #e8f8ee;
            color: #1f5f33;
        }

        .pill.train {
            background: #e9f2ff;
            color: #15457a;
        }

        details {
            margin-top: 1rem;
        }

        pre {
            margin: 0.75rem 0 0;
            padding: 1rem;
            background: #0f1720;
            color: #e6edf3;
            border-radius: 10px;
            overflow: auto;
            font-size: 0.85rem;
        }

        @media (max-width: 760px) {
            main {
                margin: 1rem auto;
                padding: 1.2rem;
            }

            .departure-table,
            .departure-table thead,
            .departure-table tbody,
            .departure-table th,
            .departure-table td,
            .departure-table tr {
                display: block;
            }

            .departure-table thead {
                display: none;
            }

            .departure-table tr {
                border: 1px solid #dbe5ec;
                border-radius: 10px;
                margin-bottom: 0.8rem;
                padding: 0.35rem;
            }

            .departure-table td {
                border: none;
                padding: 0.35rem;
            }

            .departure-table td::before {
                content: attr(data-label) ': ';
                display: block;
                font-weight: 700;
                color: #4f6574;
                margin-bottom: 0.2rem;
            }
        }
    </style>
</head>
<body>
    <main>
        <h1>NFTA TV</h1>
        <p class="meta">Save the coordinates, distance in miles, and Transit API key, then fetch transit data into a cache file for this screen.</p>

        <?php if ($message !== null): ?>
            <div class="message <?= h($messageType) ?>"><?= h($message) ?></div>
        <?php endif; ?>

        <form method="post">
            <label>
                Latitude
                <input type="text" name="latitude" value="<?= h((string) $state['latitude']) ?>" placeholder="42.8864">
            </label>

            <label>
                Longitude
                <input type="text" name="longitude" value="<?= h((string) $state['longitude']) ?>" placeholder="-78.8784">
            </label>

            <label>
                API Key
                <input type="text" name="api_key" value="<?= h((string) $state['api_key']) ?>" placeholder="Enter API key">
            </label>

            <label>
                Distance in Miles
                <input type="text" name="radius_miles" value="<?= h((string) $state['radius_miles']) ?>" placeholder="25">
            </label>

            <p class="hint">Settings are stored in a persistent JSON file and fetched Transit data is cached in <?= h(basename($cachePath)) ?>.</p>
            <p class="hint">The Transit API is only called when you click Fetch Transit Data. Page loads and board refreshes use the cached JSON only.</p>
            <p class="hint">Cache last updated: <?= h($cacheUpdatedAt ?? 'Not fetched yet') ?></p>

            <div class="actions">
                <button class="btn btn-primary" type="submit" name="save_settings" value="1">Save Settings</button>
                <button class="btn btn-primary" type="submit" name="run_fetch" value="1">Fetch Transit Data</button>
                <a class="btn btn-primary" href="/university-transit-board.html">Open University Board</a>
                <a class="btn btn-secondary" href="/app-demos.php">Back to App Demos</a>
            </div>
        </form>

        <section class="departures" aria-label="Nearby departures">
            <h2>Nearby Departures</h2>

            <?php if ($fetchMessage !== null): ?>
                <div class="message <?= h($fetchMessageType) ?>"><?= h($fetchMessage) ?></div>
            <?php endif; ?>

            <?php if ($departureRows !== []): ?>
                <table class="departure-table">
                    <thead>
                        <tr>
                            <th>Mode</th>
                            <th>Route</th>
                            <th>Departure</th>
                            <th>Stop</th>
                            <th>Headsign</th>
                            <th>Distance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($departureRows as $row): ?>
                            <tr>
                                <td data-label="Mode"><span class="pill <?= h($row['mode']) ?>"><?= h($row['mode']) ?></span></td>
                                <td data-label="Route"><?= h($row['route']) ?></td>
                                <td data-label="Departure"><?= h($row['departure_label']) ?></td>
                                <td data-label="Stop"><?= h($row['stop'] !== '' ? $row['stop'] : 'Unknown stop') ?></td>
                                <td data-label="Headsign"><?= h($row['headsign'] !== '' ? $row['headsign'] : 'Unknown destination') ?></td>
                                <td data-label="Distance"><?= h($row['distance_meters'] !== null ? number_format(((float) $row['distance_meters']) / 1609.344, 1) . ' mi' : 'N/A') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if (is_array($apiPayload)): ?>
                <details>
                    <summary>Transit API Response</summary>
                    <pre><?= h((string) json_encode($apiPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
                </details>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>