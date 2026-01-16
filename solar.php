<?php
/**
 * Deye Monitor - Deye Inverter Monitoring Script
 * 
 * This script connects to multiple Deye devices on a local network,
 * collects real-time power data and sends the information to the
 * PVOutput service for visualization and analysis.
 * 
 * @author Guilherme Rigon
 * @version 2.1
 * @license MIT
 */

// ===============================
// CONFIGURATION LOADING
// ===============================
$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    fwrite(STDERR, "ERROR: config.php file not found!\n");
    fwrite(STDERR, "Copy config.php.example to config.php and configure your credentials.\n");
    exit(1);
}

$config = require $configFile;

// Enhanced configuration validation
if (empty($config['devices']['host']) || 
    empty($config['pvoutput']['api_key']) || 
    empty($config['pvoutput']['system_id'])) {
    fwrite(STDERR, "ERROR: Incomplete configuration! Check the config.php file.\n");
    exit(1);
}

// Validate configuration types and formats
if (!is_string($config['devices']['host']) || 
    (!filter_var($config['devices']['host'], FILTER_VALIDATE_IP) && 
     !filter_var($config['devices']['host'], FILTER_VALIDATE_DOMAIN))) {
    fwrite(STDERR, "ERROR: Invalid host format in config.php\n");
    exit(1);
}

if (!is_array($config['devices']['ports']) || empty($config['devices']['ports'])) {
    fwrite(STDERR, "ERROR: Invalid ports configuration in config.php\n");
    exit(1);
}

if (!is_numeric($config['pvoutput']['system_id'])) {
    fwrite(STDERR, "ERROR: Invalid system_id format in config.php\n");
    exit(1);
}

// ===============================
// ERROR AND LOGGING CONFIGURATION
// ===============================
ini_set('log_errors', 1);
ini_set('error_log', $config['logging']['log_file'] ?? __DIR__ . '/deye_monitor.log');
ini_set('display_errors', ($config['logging']['display_errors'] ?? false) ? 1 : 0);
error_reporting(E_ALL);

// ===============================
// TIMEZONE CONFIGURATION
// ===============================
date_default_timezone_set($config['settings']['timezone'] ?? 'UTC');

// ===============================
// HELPER FUNCTIONS
// ===============================

/**
 * Validates if a value is numeric and positive
 * 
 * @param mixed $value Value to validate
 * @return bool True if valid power value
 */
function isValidPowerValue($value): bool {
    return $value !== null && is_numeric($value) && $value >= 0 && $value <= 1000000; // Max 1MW
}

/**
 * Extracts power value from HTML
 * 
 * @param string $html HTML content from device
 * @return int|null Extracted power value or null if not found
 */
function extractPowerValue(string $html): ?int {
    if (preg_match('/webdata_now_p\s*=\s*["\']?(\d+)["\']?/', $html, $matches)) {
        $value = (int)$matches[1];
        return isValidPowerValue($value) ? $value : null;
    }
    return null;
}

/**
 * Makes HTTP request with retry and exponential backoff
 * 
 * @param string $url URL to fetch
 * @param int $timeout Timeout in seconds
 * @param int $maxRetries Maximum number of retry attempts
 * @param int $baseDelay Base delay in seconds for exponential backoff
 * @return array Result array with 'success', 'data' or 'error', 'attempts'
 */
function fetchDeviceData(string $url, int $timeout, int $maxRetries, int $baseDelay): array {
    $attempt = 0;
    $lastError = null;
    
    // Clear any previous errors
    error_clear_last();
    
    while ($attempt < $maxRetries) {
        $attempt++;
        
        $context = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'method' => 'GET',
                'header' => [
                    'Connection: close',
                    'User-Agent: DeyeMonitor/2.1',
                ],
                'ignore_errors' => true, // Don't fail on HTTP errors, we'll check response
            ],
        ]);
        
        // Remove @ suppression - handle errors properly
        $contents = file_get_contents($url, false, $context);
        
        if ($contents !== false && $contents !== '') {
            // Check HTTP response code if available
            $httpResponse = isset($http_response_header) ? $http_response_header : [];
            $statusCode = 0;
            if (!empty($httpResponse) && preg_match('/HTTP\/\d\.\d\s+(\d+)/', $httpResponse[0], $matches)) {
                $statusCode = (int)$matches[1];
            }
            
            // Consider 2xx and 3xx as success
            if ($statusCode === 0 || ($statusCode >= 200 && $statusCode < 400)) {
                return ['success' => true, 'data' => $contents];
            }
            
            $lastError = "HTTP $statusCode";
        }
        
        $lastError = $lastError ?? (error_get_last()['message'] ?? 'Connection failed');
        
        if ($attempt < $maxRetries) {
            $delay = $baseDelay * (2 ** ($attempt - 1)); // exponential backoff
            sleep($delay);
        }
        
        // Clear errors before next attempt
        error_clear_last();
    }
    
    return [
        'success' => false,
        'error' => $lastError ?? 'Unknown error',
        'attempts' => $attempt,
    ];
}

/**
 * Validates power value before sending to PVOutput
 * 
 * @param int $power Power value in watts
 * @return bool True if value is reasonable
 */
function isValidPowerForPVOutput(int $power): bool {
    // Reasonable range: 0 to 10MW (for very large installations)
    return $power >= 0 && $power <= 10000000;
}

/**
 * Loads daily statistics from file
 * 
 * @param string $statsFile Path to statistics file
 * @param string $date Date in Ymd format
 * @return array Daily statistics array
 */
function loadDailyStats(string $statsFile, string $date): array {
    if (!file_exists($statsFile)) {
        return [];
    }
    
    $allStats = json_decode(file_get_contents($statsFile), true) ?? [];
    return $allStats[$date] ?? [];
}

/**
 * Saves execution statistics to daily file
 * 
 * @param string $statsFile Path to statistics file
 * @param string $date Date in Ymd format
 * @param array $executionStats Current execution statistics
 * @param int $totalPower Total power sent
 * @return bool True if saved successfully
 */
function saveDailyStats(string $statsFile, string $date, array $executionStats, int $totalPower): bool {
    $allStats = [];
    if (file_exists($statsFile)) {
        $allStats = json_decode(file_get_contents($statsFile), true) ?? [];
    }
    
    if (!isset($allStats[$date])) {
        $allStats[$date] = [
            'date' => $date,
            'executions' => [],
            'summary' => [
                'total_executions' => 0,
                'successful_executions' => 0,
                'failed_executions' => 0,
                'power_values' => [],
                'max_power' => 0,
                'min_power' => PHP_INT_MAX,
                'avg_power' => 0,
                'total_energy_estimate' => 0, // Rough estimate based on power readings
            ],
        ];
    }
    
    $execution = [
        'timestamp' => date('Y-m-d H:i:s'),
        'power' => $totalPower,
        'success' => $executionStats['pvoutput_success'] ?? false,
        'duration' => $executionStats['total_duration'] ?? 0,
        'devices_successful' => count(array_filter($executionStats['devices'] ?? [], fn($d) => $d['success'] ?? false)),
        'devices_failed' => count(array_filter($executionStats['devices'] ?? [], fn($d) => !($d['success'] ?? false))),
        'total_attempts' => $executionStats['total_attempts'] ?? 0,
    ];
    
    $allStats[$date]['executions'][] = $execution;
    
    // Update summary
    $summary = &$allStats[$date]['summary'];
    $summary['total_executions']++;
    
    if ($executionStats['pvoutput_success'] ?? false) {
        $summary['successful_executions']++;
        $summary['power_values'][] = $totalPower;
        
        if ($totalPower > $summary['max_power']) {
            $summary['max_power'] = $totalPower;
        }
        
        if ($totalPower < $summary['min_power']) {
            $summary['min_power'] = $totalPower;
        }
        
        if (!empty($summary['power_values'])) {
            $summary['avg_power'] = (int)round(array_sum($summary['power_values']) / count($summary['power_values']));
        }
    } else {
        $summary['failed_executions']++;
    }
    
    // Clean old data (keep only last 30 days)
    $cutoffDate = date('Ymd', strtotime('-30 days'));
    foreach ($allStats as $statDate => $data) {
        if ($statDate < $cutoffDate) {
            unset($allStats[$statDate]);
        }
    }
    
    return file_put_contents($statsFile, json_encode($allStats, JSON_PRETTY_PRINT)) !== false;
}

/**
 * Calculates and displays daily statistics
 * 
 * @param array $dailyStats Daily statistics array
 * @param string $date Current date
 */
function displayDailyStats(array $dailyStats, string $date): void {
    if (empty($dailyStats)) {
        return;
    }
    
    $summary = $dailyStats['summary'] ?? [];
    $executions = $dailyStats['executions'] ?? [];
    
    if (empty($summary) || empty($executions)) {
        return;
    }
    
    echo "\n=== DAILY STATISTICS (Today: " . date('Y-m-d', strtotime($date)) . ") ===\n";
    echo "Total executions today: {$summary['total_executions']}\n";
    echo "  Successful: {$summary['successful_executions']} (" . 
         round(($summary['successful_executions'] / max($summary['total_executions'], 1)) * 100, 1) . "%)\n";
    echo "  Failed: {$summary['failed_executions']} (" . 
         round(($summary['failed_executions'] / max($summary['total_executions'], 1)) * 100, 1) . "%)\n";
    echo "\n";
    
    if (!empty($summary['power_values'])) {
        echo "Power Statistics:\n";
        echo "  Maximum: " . number_format($summary['max_power']) . " W\n";
        echo "  Minimum: " . number_format($summary['min_power']) . " W\n";
        echo "  Average: " . number_format($summary['avg_power']) . " W\n";
        echo "  Readings: " . count($summary['power_values']) . "\n";
        
        // Show trend (last 3 readings)
        $recentReadings = array_slice($summary['power_values'], -3);
        if (count($recentReadings) >= 2) {
            $trend = end($recentReadings) - reset($recentReadings);
            $trendSymbol = $trend > 0 ? '↑' : ($trend < 0 ? '↓' : '→');
            echo "  Trend (last 3): {$trendSymbol} " . abs($trend) . " W\n";
        }
        echo "\n";
    }
    
    // Show last 5 executions
    $recentExecutions = array_slice($executions, -5);
    if (count($recentExecutions) > 1) {
        echo "Recent Executions:\n";
        foreach (array_reverse($recentExecutions) as $exec) {
            $status = $exec['success'] ? '✓' : '✗';
            $time = date('H:i:s', strtotime($exec['timestamp']));
            echo "  {$status} {$time} - " . number_format($exec['power']) . " W (" . 
                 round($exec['duration'], 1) . "s)\n";
        }
        echo "\n";
    }
}

/**
 * Finds the nearest Weather Underground station
 *
 * @param float $lat Latitude
 * @param float $lon Longitude
 * @param string $apiKey Weather Underground API key
 * @param int $timeout Request timeout in seconds
 * @return string|null Station ID or null if not found
 */
function findNearestWeatherStation(float $lat, float $lon, string $apiKey, int $timeout = 10): ?string {
    $url = sprintf(
        "https://api.weather.com/v3/location/near?geocode=%s,%s&product=pws&format=json&apiKey=%s",
        $lat,
        $lon,
        $apiKey
    );

    $curl = curl_init($url);
    if ($curl === false) {
        return null;
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'DeyeMonitor/2.1',
    ]);

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($response === false || $httpCode !== 200) {
        return null;
    }

    $data = json_decode($response, true);
    if (isset($data['location']['stationId'][0])) {
        return $data['location']['stationId'][0];
    }

    return null;
}

/**
 * Fetches current weather data from Weather Underground
 *
 * @param string $stationId Weather station ID
 * @param string $apiKey Weather Underground API key
 * @param int $timeout Request timeout in seconds
 * @return array|null Weather data or null if failed
 */
function fetchWeatherData(string $stationId, string $apiKey, int $timeout = 10): ?array {
    $url = sprintf(
        "https://api.weather.com/v2/pws/observations/current?stationId=%s&format=json&units=m&apiKey=%s",
        urlencode($stationId),
        $apiKey
    );

    $curl = curl_init($url);
    if ($curl === false) {
        return null;
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'DeyeMonitor/2.1',
    ]);

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($response === false || $httpCode !== 200) {
        return null;
    }

    $data = json_decode($response, true);
    if (!isset($data['observations'][0])) {
        return null;
    }

    $obs = $data['observations'][0];
    return [
        'station_id' => $obs['stationID'] ?? '',
        'temperature' => $obs['metric']['temp'] ?? null,
        'humidity' => $obs['humidity'] ?? null,
        'solar_radiation' => $obs['solarRadiation'] ?? null,
        'uv' => $obs['uv'] ?? null,
        'wind_speed' => $obs['metric']['windSpeed'] ?? null,
        'pressure' => $obs['metric']['pressure'] ?? null,
        'obs_time' => $obs['obsTimeLocal'] ?? null,
    ];
}

/**
 * Gets weather data using config settings
 *
 * @param array $config Configuration array
 * @return array|null Weather data or null if disabled/failed
 */
function getWeatherData(array $config): ?array {
    // Check if weather is enabled
    if (empty($config['weather']['enabled']) || empty($config['weather']['api_key'])) {
        return null;
    }

    $apiKey = $config['weather']['api_key'];
    $timeout = $config['weather']['timeout'] ?? 10;
    $stationId = $config['weather']['station_id'] ?? '';

    // If station configured, use it directly
    if (!empty($stationId)) {
        return fetchWeatherData($stationId, $apiKey, $timeout);
    }

    // Try preferred local stations first
    $preferredStations = $config['weather']['preferred_stations'] ?? [];

    $primaryData = null;
    $primaryStation = null;

    foreach ($preferredStations as $station) {
        $data = fetchWeatherData($station, $apiKey, $timeout);
        if ($data !== null && $data['temperature'] !== null) {
            echo "  Weather: Using station $station\n";
            $primaryData = $data;
            $primaryStation = $station;
            break;
        }
    }

    if ($primaryData === null) {
        // Fallback: auto-detect nearest station
        $lat = $config['dashboard']['latitude'] ?? null;
        $lon = $config['dashboard']['longitude'] ?? null;

        if ($lat === null || $lon === null) {
            echo "  Weather: No coordinates configured for auto-detection\n";
            return null;
        }

        $stationId = findNearestWeatherStation($lat, $lon, $apiKey, $timeout);
        if ($stationId === null) {
            echo "  Weather: Could not find nearby station\n";
            return null;
        }
        echo "  Weather: Auto-detected station $stationId\n";

        return fetchWeatherData($stationId, $apiKey, $timeout);
    }

    // If primary station missing pressure, try to get from other stations
    if ($primaryData['pressure'] === null) {
        foreach ($preferredStations as $station) {
            if ($station === $primaryStation) {
                continue;
            }
            $fallbackData = fetchWeatherData($station, $apiKey, $timeout);
            if ($fallbackData !== null && $fallbackData['pressure'] !== null) {
                $primaryData['pressure'] = $fallbackData['pressure'];
                echo "  Weather: Pressure from fallback station $station: {$fallbackData['pressure']} hPa\n";
                break;
            }
        }
    }

    return $primaryData;
}

/**
 * Fetches voltage data from Shelly EM
 *
 * @param array $config Configuration array
 * @param int $phase Phase index (0=A, 1=B, 2=C)
 * @return array|null Shelly data with voltage or null if failed
 */
function fetchShellyData(array $config, int $phase = 2): ?array {
    if (empty($config['shelly']['enabled']) || empty($config['shelly']['url'])) {
        return null;
    }

    $url = $config['shelly']['url'];
    $timeout = $config['shelly']['timeout'] ?? 5;

    $context = stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'method' => 'GET',
            'header' => [
                'Connection: close',
                'User-Agent: DeyeMonitor/2.1',
            ],
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return null;
    }

    $data = json_decode($response, true);
    if (!isset($data['emeters'][$phase])) {
        return null;
    }

    $meter = $data['emeters'][$phase];
    return [
        'voltage' => $meter['voltage'] ?? null,
        'power' => $meter['power'] ?? null,
        'phase' => $phase,
    ];
}

/**
 * Sends data to PVOutput
 *
 * @param int $total Total power in watts
 * @param array $config Configuration array
 * @param array $extraData Optional extra data (temperature, voltage, extended)
 * @return array Result array with 'success', 'response', 'http_code', 'error'
 */
function sendToPVOutput(int $total, array $config, array $extraData = []): array {
    // Validate power value before sending
    if (!isValidPowerForPVOutput($total)) {
        return [
            'success' => false,
            'response' => '',
            'http_code' => 0,
            'error' => "Invalid power value: $total W (out of range)",
        ];
    }
    
    $postData = [
        'd' => date('Ymd'),
        't' => date('H:i'),
        'v2' => $total,  // v2 = Power Generation (W) - instant value
    ];

    // Add temperature if available (v5 = Temperature in Celsius)
    $temperature = $extraData['temperature'] ?? null;
    if ($temperature !== null && $temperature >= -100 && $temperature <= 100) {
        $postData['v5'] = round($temperature, 1);
    }

    // Add voltage if available (v6 = Voltage in Volts)
    $voltage = $extraData['voltage'] ?? null;
    if ($voltage !== null && $voltage >= 0 && $voltage <= 500) {
        $postData['v6'] = round($voltage, 1);
    }

    // Extended data from Weather Underground
    // v7 = Humidity (%)
    $humidity = $extraData['humidity'] ?? null;
    if ($humidity !== null && $humidity >= 0 && $humidity <= 100) {
        $postData['v7'] = round($humidity, 0);
    }

    // v8 = Solar Radiation (W/m²)
    $solarRadiation = $extraData['solar_radiation'] ?? null;
    if ($solarRadiation !== null && $solarRadiation >= 0) {
        $postData['v8'] = round($solarRadiation, 1);
    }

    // v9 = UV Index
    $uv = $extraData['uv'] ?? null;
    if ($uv !== null && $uv >= 0) {
        $postData['v9'] = round($uv, 1);
    }

    // v10 = Wind Speed (km/h)
    $windSpeed = $extraData['wind_speed'] ?? null;
    if ($windSpeed !== null && $windSpeed >= 0) {
        $postData['v10'] = round($windSpeed, 1);
    }

    // v11 = Pressure (hPa)
    $pressure = $extraData['pressure'] ?? null;
    if ($pressure !== null && $pressure >= 800 && $pressure <= 1200) {
        $postData['v11'] = round($pressure, 1);
    }

    $curl = curl_init("https://pvoutput.org/service/r2/addstatus.jsp");
    if ($curl === false) {
        return [
            'success' => false,
            'response' => '',
            'http_code' => 0,
            'error' => 'Failed to initialize cURL',
        ];
    }
    
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $config['settings']['curl_timeout'] ?? 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postData),
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/x-www-form-urlencoded",
            "X-Pvoutput-Apikey: " . $config['pvoutput']['api_key'],
            "X-Pvoutput-SystemId: " . $config['pvoutput']['system_id'],
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'DeyeMonitor/2.1',
    ]);
    
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    
    curl_close($curl);
    
    // Enhanced success check
    $success = ($httpCode >= 200 && $httpCode < 300) && empty($error) && $response !== false;
    
    return [
        'success' => $success,
        'response' => $response !== false ? trim($response) : '',
        'http_code' => (int)$httpCode,
        'error' => $error ?: '',
    ];
}

// ===============================
// SCRIPT START
// ===============================
$scriptStartTime = microtime(true);
$currentDate = date('Ymd');
$statsFile = __DIR__ . '/daily_stats.json';
$executionStats = [
    'start_time' => $scriptStartTime,
    'devices' => [],
    'total_attempts' => 0,
    'pvoutput_send_time' => 0,
    'pvoutput_success' => false,
];

echo "=== SCRIPT START ===\n";
echo "Date/Time: " . date('Y-m-d H:i:s') . "\n\n";

// Load and display daily statistics
$dailyStats = loadDailyStats($statsFile, $currentDate);
if (!empty($dailyStats)) {
    displayDailyStats($dailyStats, $currentDate);
}

// ===============================
// DEVICE URL CONSTRUCTION
// ===============================
$devices = $config['devices'];
$urls = [];
foreach ($devices['ports'] as $port) {
    if (!is_numeric($port) || $port < 1 || $port > 65535) {
        fwrite(STDERR, "WARNING: Invalid port $port, skipping\n");
        continue;
    }
    
    $url = sprintf(
        "http://%s:%s@%s:%d%s",
        urlencode($devices['username'] ?? 'admin'),
        urlencode($devices['password'] ?? 'admin'),
        $devices['host'],
        (int)$port,
        $devices['path'] ?? '/status.html'
    );
    $urls[] = $url;
}

if (empty($urls)) {
    fwrite(STDERR, "ERROR: No valid device URLs configured\n");
    exit(1);
}

$results = [];
$numUrls = count($urls);

// ===============================
// MONITORING LOOP WITH BACKOFF
// ===============================
foreach ($urls as $index => $url) {
    $deviceNum = $index + 1;
    $deviceStartTime = microtime(true);
    
    echo "Device $deviceNum/$numUrls: Connecting...\n";
    
    $fetchResult = fetchDeviceData(
        $url,
        $config['settings']['http_timeout'] ?? 10,
        $config['settings']['max_retries'] ?? 3,
        $config['settings']['base_delay'] ?? 5
    );
    
    $deviceEndTime = microtime(true);
    $deviceDuration = round($deviceEndTime - $deviceStartTime, 2);
    $executionStats['total_attempts'] += $fetchResult['attempts'] ?? 1;
    
    if (!$fetchResult['success']) {
        $errorMsg = sprintf(
            "Failure after %d attempts: %s",
            $fetchResult['attempts'],
            $fetchResult['error']
        );
        echo "  ERROR: $errorMsg\n";
        error_log("Device $deviceNum: $errorMsg");
        $results[$url] = null;
        
        $executionStats['devices'][$deviceNum] = [
            'success' => false,
            'attempts' => $fetchResult['attempts'],
            'duration' => $deviceDuration,
            'error' => $fetchResult['error'],
        ];
    } else {
        echo "  Connected successfully!\n";
        $powerValue = extractPowerValue($fetchResult['data']);
        
        if (isValidPowerValue($powerValue)) {
            echo "  Power captured: {$powerValue} W\n";
            $results[$url] = $powerValue;
            
            $executionStats['devices'][$deviceNum] = [
                'success' => true,
                'attempts' => $fetchResult['attempts'],
                'duration' => $deviceDuration,
                'power' => $powerValue,
            ];
        } else {
            $errorMsg = "Could not extract valid value from HTML";
            echo "  ERROR: $errorMsg\n";
            error_log("Device $deviceNum: $errorMsg");
            $results[$url] = null;
            
            $executionStats['devices'][$deviceNum] = [
                'success' => false,
                'attempts' => $fetchResult['attempts'],
                'duration' => $deviceDuration,
                'error' => $errorMsg,
            ];
        }
    }
    
    echo "  Time: {$deviceDuration}s\n";
    
    // Delay between devices (except for the last one)
    if ($deviceNum < $numUrls) {
        $delay = $config['settings']['delay_between_devices'] ?? 5;
        echo "  Waiting {$delay}s before next device...\n";
        sleep($delay);
    }
    
    echo "---------------------------------------\n";
}

// ===============================
// TOTAL CALCULATION WITH FALLBACK
// ===============================
$successfulValues = array_filter($results, 'isValidPowerValue');
$numSuccessful = count($successfulValues);
$numFailed = $numUrls - $numSuccessful;

if ($numSuccessful > 0) {
    $average = (int)round(array_sum($successfulValues) / $numSuccessful);
    echo "Successful values (>0): " . implode(', ', $successfulValues) . " W\n";
    echo "Average calculated for fallback: $average W\n";
    echo "Failed devices: $numFailed\n\n";
    
    $total = 0;
    $fallbackCount = 0;
    
    foreach ($results as $url => $value) {
        if (isValidPowerValue($value)) {
            $total += $value;
        } else {
            $total += $average;
            $fallbackCount++;
        }
    }
    
    echo "Total calculated: $total W (with $fallbackCount fallback(s))\n";
} else {
    $total = 0;
    $errorMsg = "CRITICAL ERROR: No device responded with valid value. Total set to 0.";
    echo "$errorMsg\n";
    error_log($errorMsg);
}

echo "\n=== FINAL TOTAL: $total W ===\n\n";

// ===============================
// FETCH WEATHER DATA
// ===============================
$weatherData = null;
$temperature = null;

echo "Fetching weather data...\n";
$weatherData = getWeatherData($config);

if ($weatherData !== null) {
    $temperature = $weatherData['temperature'];
    echo "  Temperature: {$temperature}°C\n";
    echo "  Humidity: {$weatherData['humidity']}%\n";
    if ($weatherData['solar_radiation'] !== null) {
        echo "  Solar Radiation: {$weatherData['solar_radiation']} W/m²\n";
    }
    echo "  Observation time: {$weatherData['obs_time']}\n";
    $executionStats['weather'] = $weatherData;
} else {
    echo "  Weather data not available\n";
}

// ===============================
// FETCH SHELLY VOLTAGE DATA
// ===============================
$shellyData = null;
$voltage = null;

echo "Fetching Shelly voltage data...\n";
$shellyPhase = $config['shelly']['phase'] ?? 2;  // Default to Phase C (index 2)
$shellyData = fetchShellyData($config, $shellyPhase);

if ($shellyData !== null && $shellyData['voltage'] !== null) {
    $voltage = $shellyData['voltage'];
    $phaseLabel = chr(65 + $shellyPhase);  // 0=A, 1=B, 2=C
    echo "  Voltage (Phase $phaseLabel): {$voltage} V\n";
    if ($shellyData['power'] !== null) {
        echo "  Power (Phase $phaseLabel): {$shellyData['power']} W\n";
    }
    $executionStats['shelly'] = $shellyData;
} else {
    echo "  Shelly voltage data not available\n";
}

// ===============================
// SENDING DATA TO PVOUTPUT
// ===============================
if ($total > 0 || $numSuccessful > 0) {

    // Prepare extra data for PVOutput
    $extraData = [
        'temperature' => $weatherData['temperature'] ?? null,
        'voltage' => $voltage,
        'humidity' => $weatherData['humidity'] ?? null,
        'solar_radiation' => $weatherData['solar_radiation'] ?? null,
        'uv' => $weatherData['uv'] ?? null,
        'wind_speed' => $weatherData['wind_speed'] ?? null,
        'pressure' => $weatherData['pressure'] ?? null,
    ];

    $pvStartTime = microtime(true);
    echo "Sending data to PVOutput...\n";
    echo "  Power (v2): {$total} W\n";
    if ($extraData['temperature'] !== null) {
        echo "  Temperature (v5): {$extraData['temperature']}°C\n";
    }
    if ($extraData['voltage'] !== null) {
        echo "  Voltage (v6): {$extraData['voltage']} V\n";
    }
    if ($extraData['humidity'] !== null) {
        echo "  Humidity (v7): {$extraData['humidity']}%\n";
    }
    if ($extraData['solar_radiation'] !== null) {
        echo "  Solar Radiation (v8): {$extraData['solar_radiation']} W/m²\n";
    }
    if ($extraData['uv'] !== null) {
        echo "  UV Index (v9): {$extraData['uv']}\n";
    }
    if ($extraData['wind_speed'] !== null) {
        echo "  Wind Speed (v10): {$extraData['wind_speed']} km/h\n";
    }
    if ($extraData['pressure'] !== null) {
        echo "  Pressure (v11): {$extraData['pressure']} hPa\n";
    }

    $pvResult = sendToPVOutput((int)$total, $config, $extraData);
    $pvEndTime = microtime(true);
    $executionStats['pvoutput_send_time'] = round($pvEndTime - $pvStartTime, 2);
    
    if ($pvResult['success']) {
        echo "✓ Data sent successfully!\n";
        echo "  Response: {$pvResult['response']}\n";
        echo "  HTTP Code: {$pvResult['http_code']}\n";
        echo "  Send time: {$executionStats['pvoutput_send_time']}s\n";
        $executionStats['pvoutput_success'] = true;
    } else {
        $errorMsg = sprintf(
            "Failed to send data to PVOutput: %s (HTTP %d)",
            $pvResult['error'] ?: $pvResult['response'] ?: 'Unknown error',
            $pvResult['http_code']
        );
        echo "✗ $errorMsg\n";
        error_log($errorMsg);
        $executionStats['pvoutput_success'] = false;
        exit(1);
    }
} else {
    echo "Skipping PVOutput send (total = 0 and no valid devices)\n";
    exit(1);
}

// ===============================
// EXECUTION STATISTICS
// ===============================
$scriptEndTime = microtime(true);
$totalDuration = round($scriptEndTime - $scriptStartTime, 2);
$executionStats['end_time'] = $scriptEndTime;
$executionStats['total_duration'] = $totalDuration;

// Calculate device statistics
$successfulDevices = array_filter($executionStats['devices'], fn($d) => $d['success'] ?? false);
$failedDevices = array_filter($executionStats['devices'], fn($d) => !($d['success'] ?? false));
$avgDeviceTime = count($executionStats['devices']) > 0 
    ? round(array_sum(array_column($executionStats['devices'], 'duration')) / count($executionStats['devices']), 2)
    : 0;
$avgAttempts = count($executionStats['devices']) > 0
    ? round(array_sum(array_column($executionStats['devices'], 'attempts')) / count($executionStats['devices']), 2)
    : 0;

echo "\n=== EXECUTION STATISTICS ===\n";
echo "Total execution time: {$totalDuration}s\n";
echo "Device collection time: " . round($totalDuration - $executionStats['pvoutput_send_time'], 2) . "s\n";
echo "PVOutput send time: {$executionStats['pvoutput_send_time']}s\n";
echo "\n";
echo "Devices:\n";
echo "  Total devices: {$numUrls}\n";
echo "  Successful: " . count($successfulDevices) . " (" . round((count($successfulDevices) / $numUrls) * 100, 1) . "%)\n";
echo "  Failed: " . count($failedDevices) . " (" . round((count($failedDevices) / $numUrls) * 100, 1) . "%)\n";
echo "  Average time per device: {$avgDeviceTime}s\n";
echo "  Average attempts per device: {$avgAttempts}\n";
echo "  Total connection attempts: {$executionStats['total_attempts']}\n";
echo "\n";
echo "PVOutput:\n";
echo "  Status: " . ($executionStats['pvoutput_success'] ? "✓ Success" : "✗ Failed") . "\n";
echo "  Power sent: {$total} W\n";
echo "\n";

// Save daily statistics
if ($executionStats['pvoutput_success'] || $total > 0) {
    saveDailyStats($statsFile, $currentDate, $executionStats, (int)$total);
    
    // Reload and display updated daily stats
    $updatedDailyStats = loadDailyStats($statsFile, $currentDate);
    if (!empty($updatedDailyStats)) {
        displayDailyStats($updatedDailyStats, $currentDate);
    }
}

echo "=== SCRIPT END ===\n";
exit(0);
