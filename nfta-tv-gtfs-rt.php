<?php
declare(strict_types=1);

require_once __DIR__ . '/../private/require_auth.php';
require_once __DIR__ . '/../private/auth.php';

auth_require_roles(['owner', 'transit']);

$dataPath = __DIR__ . '/../uploads/app-data/nfta_tv_gtfs_rt.json';
$cachePath = __DIR__ . '/../uploads/app-data/raw_gtfs_rt_cache.json';
$fetchScriptPath = __DIR__ . '/../private/fetch_gtfs_rt_data.py';
$defaultTripUpdatesUrl = 'https://gtfsr.nfta.com/api/tripupdates?format=gtfs.proto';
$defaultVehiclePositionsUrl = 'https://gtfsr.nfta.com/api/vehiclepositions?format=gtfs.proto';
$defaultAlertsUrl = 'https://gtfsr.nfta.com/api/servicealerts?format=gtfs.proto';
$defaultRadiusMiles = 25.0;
$message = null;
$messageType = 'info';
$fetchMessage = null;
$fetchMessageType = 'info';
$cacheUpdatedAt = null;

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function nfta_tv_gtfs_rt_default_state(): array
{
    return [
        'latitude' => '',
        'longitude' => '',
        'radius_miles' => '25',
        'trip_updates_url' => $GLOBALS['defaultTripUpdatesUrl'],
        'vehicle_positions_url' => $GLOBALS['defaultVehiclePositionsUrl'],
        'alerts_url' => $GLOBALS['defaultAlertsUrl'],
        'updated_at_utc' => null,
    ];
}

function nfta_tv_gtfs_rt_ensure_directory_exists(string $filePath): void
{
    $directory = dirname($filePath);
    if (is_dir($directory)) {
        return;
    }

    if (!@mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create data directory for NFTA TV GTFS-RT settings.');
    }
}

function nfta_tv_gtfs_rt_load_state(string $path): array
{
    $state = nfta_tv_gtfs_rt_default_state();

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

    if (isset($decoded['updated_at_utc']) && is_string($decoded['updated_at_utc'])) {
        $state['updated_at_utc'] = $decoded['updated_at_utc'];
    }

    return $state;
}

function nfta_tv_gtfs_rt_save_state(string $path, array $state): void
{
    nfta_tv_gtfs_rt_ensure_directory_exists($path);

    $payload = [
        'latitude' => trim((string) ($state['latitude'] ?? '')),
        'longitude' => trim((string) ($state['longitude'] ?? '')),
        'radius_miles' => trim((string) ($state['radius_miles'] ?? '25')),
        'trip_updates_url' => trim((string) ($state['trip_updates_url'] ?? $GLOBALS['defaultTripUpdatesUrl'])),
        'vehicle_positions_url' => trim((string) ($state['vehicle_positions_url'] ?? $GLOBALS['defaultVehiclePositionsUrl'])),
        'alerts_url' => trim((string) ($state['alerts_url'] ?? $GLOBALS['defaultAlertsUrl'])),
        'updated_at_utc' => gmdate('c'),
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Unable to encode NFTA TV GTFS-RT settings JSON.');
    }

    $tmpPath = $path . '.tmp';
    if (file_put_contents($tmpPath, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write temporary NFTA TV GTFS-RT settings file.');
    }

    if (!@rename($tmpPath, $path)) {
        @unlink($tmpPath);
        throw new RuntimeException('Unable to finalize NFTA TV GTFS-RT settings save.');
    }
}

function nfta_tv_gtfs_rt_parse_coordinate(string $value, string $label): float
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

function nfta_tv_gtfs_rt_parse_radius_miles(string $value): float
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

function nfta_tv_gtfs_rt_parse_feed_url(string $value, string $label, bool $allowBlank = false): string
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

function nfta_tv_gtfs_rt_command_exists(string $command): bool
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

function nfta_tv_gtfs_rt_python_can_run(string $pythonCommand, string $code, ?string $prependPath = null): bool
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

function nfta_tv_gtfs_rt_resolve_python_command(): ?string
{
    $scriptImportPath = realpath(__DIR__ . '/../private') ?: (__DIR__ . '/../private');
    $envOverride = getenv('NFTA_TV_GTFS_RT_PYTHON');
    if (is_string($envOverride) && trim($envOverride) !== '') {
        $normalizedOverride = trim($envOverride);
        if (is_file($normalizedOverride) && is_executable($normalizedOverride)) {
            $overrideCommand = escapeshellarg($normalizedOverride);
            if (nfta_tv_gtfs_rt_python_can_run($overrideCommand, 'from google.transit import gtfs_realtime_pb2', $scriptImportPath)) {
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
                if (nfta_tv_gtfs_rt_python_can_run($command, 'from google.transit import gtfs_realtime_pb2', $scriptImportPath)) {
                    return $command;
                }
            }
            continue;
        }

        if (nfta_tv_gtfs_rt_command_exists($binary)) {
            if (nfta_tv_gtfs_rt_python_can_run($candidate, 'from google.transit import gtfs_realtime_pb2', $scriptImportPath)) {
                return $candidate;
            }
        }
    }

    return null;
}

function nfta_tv_gtfs_rt_run_fetch_script(
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

    $python = nfta_tv_gtfs_rt_resolve_python_command();
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

function nfta_tv_gtfs_rt_format_eastern_datetime(int $timestamp): string
{
    $dateTime = new DateTimeImmutable('@' . $timestamp);
    $dateTime = $dateTime->setTimezone(new DateTimeZone('America/New_York'));

    return $dateTime->format('M j, Y g:i A T');
}

function nfta_tv_gtfs_rt_get_file_updated_at(string $path): ?string
{
    if (!is_file($path)) {
        return null;
    }

    $modifiedTime = @filemtime($path);
    if (!is_int($modifiedTime) || $modifiedTime <= 0) {
        return null;
    }

    return nfta_tv_gtfs_rt_format_eastern_datetime($modifiedTime);
}

try {
    $state = nfta_tv_gtfs_rt_load_state($dataPath);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $state['latitude'] = isset($_POST['latitude']) ? trim((string) $_POST['latitude']) : '';
        $state['longitude'] = isset($_POST['longitude']) ? trim((string) $_POST['longitude']) : '';
        $state['radius_miles'] = isset($_POST['radius_miles']) ? trim((string) $_POST['radius_miles']) : (string) $defaultRadiusMiles;
        $state['trip_updates_url'] = isset($_POST['trip_updates_url']) ? trim((string) $_POST['trip_updates_url']) : $defaultTripUpdatesUrl;
        $state['vehicle_positions_url'] = isset($_POST['vehicle_positions_url']) ? trim((string) $_POST['vehicle_positions_url']) : $defaultVehiclePositionsUrl;
        $state['alerts_url'] = isset($_POST['alerts_url']) ? trim((string) $_POST['alerts_url']) : $defaultAlertsUrl;

        $latitude = nfta_tv_gtfs_rt_parse_coordinate($state['latitude'], 'Latitude');
        $longitude = nfta_tv_gtfs_rt_parse_coordinate($state['longitude'], 'Longitude');
        $radiusMiles = nfta_tv_gtfs_rt_parse_radius_miles($state['radius_miles']);
        $tripUpdatesUrl = nfta_tv_gtfs_rt_parse_feed_url($state['trip_updates_url'], 'Trip updates URL');
        $vehiclePositionsUrl = nfta_tv_gtfs_rt_parse_feed_url($state['vehicle_positions_url'], 'Vehicle positions URL');
        $alertsUrl = nfta_tv_gtfs_rt_parse_feed_url($state['alerts_url'], 'Service alerts URL', true);

        nfta_tv_gtfs_rt_save_state($dataPath, $state);
        $state = nfta_tv_gtfs_rt_load_state($dataPath);

        if (isset($_POST['run_fetch'])) {
            nfta_tv_gtfs_rt_run_fetch_script(
                $fetchScriptPath,
                $cachePath,
                $latitude,
                $longitude,
                $radiusMiles,
                $tripUpdatesUrl,
                $vehiclePositionsUrl,
                $alertsUrl
            );

            $fetchMessage = 'GTFS-RT cache refreshed successfully.';
            $fetchMessageType = 'success';
        } else {
            $message = 'NFTA TV GTFS-RT settings saved.';
            $messageType = 'success';
        }
    }

    $cacheUpdatedAt = nfta_tv_gtfs_rt_get_file_updated_at($cachePath);
} catch (Throwable $exception) {
    $state = $state ?? nfta_tv_gtfs_rt_default_state();
    $message = $exception->getMessage();
    $messageType = 'error';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NFTA TV Based on GTSF-RT | LoGIX DIY</title>
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
    </style>
</head>
<body>
    <main>
        <h1>NFTA TV Based on GTSF-RT</h1>
        <p class="meta">Save the coordinates, distance in miles, and NFTA GTFS-RT feed URLs, then fetch transit data into a cache file for this screen.</p>

        <?php if ($message !== null): ?>
            <div class="message <?= h($messageType) ?>"><?= h($message) ?></div>
        <?php endif; ?>

        <?php if ($fetchMessage !== null): ?>
            <div class="message <?= h($fetchMessageType) ?>"><?= h($fetchMessage) ?></div>
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
                Distance in Miles
                <input type="text" name="radius_miles" value="<?= h((string) $state['radius_miles']) ?>" placeholder="25">
            </label>

            <label>
                Trip Updates URL
                <input type="text" name="trip_updates_url" value="<?= h((string) $state['trip_updates_url']) ?>" placeholder="<?= h($defaultTripUpdatesUrl) ?>">
            </label>

            <label>
                Vehicle Positions URL
                <input type="text" name="vehicle_positions_url" value="<?= h((string) $state['vehicle_positions_url']) ?>" placeholder="<?= h($defaultVehiclePositionsUrl) ?>">
            </label>

            <label>
                Service Alerts URL
                <input type="text" name="alerts_url" value="<?= h((string) $state['alerts_url']) ?>" placeholder="<?= h($defaultAlertsUrl) ?>">
            </label>

            <p class="hint">Settings are stored in a persistent JSON file. The default NFTA feed URLs point to the Protocol Buffer endpoints at gtfsr.nfta.com.</p>
            <p class="hint">Expected cache file: <?= h(basename($cachePath)) ?></p>
            <p class="hint">Cache last updated: <?= h($cacheUpdatedAt ?? 'Not fetched yet') ?></p>

            <div class="actions">
                <button class="btn btn-primary" type="submit">Save Settings</button>
                <button class="btn btn-primary" type="submit" name="run_fetch" value="1">Fetch Transit Data</button>
                <a class="btn btn-primary" href="/university-transit-board-gtfs-rt.html?back=/nfta-tv-gtfs-rt.php">Open University GTFS-RT Board</a>
                <a class="btn btn-secondary" href="/app-demos.php">Back to App Demos</a>
            </div>
        </form>
    </main>
</body>
</html>