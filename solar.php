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
 * Sends data to PVOutput
 * 
 * @param int $total Total power in watts
 * @param array $config Configuration array
 * @return array Result array with 'success', 'response', 'http_code', 'error'
 */
function sendToPVOutput(int $total, array $config): array {
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
echo "=== SCRIPT START ===\n";
echo "Date/Time: " . date('Y-m-d H:i:s') . "\n\n";

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
    echo "Device $deviceNum/$numUrls: Connecting...\n";
    
    $fetchResult = fetchDeviceData(
        $url,
        $config['settings']['http_timeout'] ?? 10,
        $config['settings']['max_retries'] ?? 3,
        $config['settings']['base_delay'] ?? 5
    );
    
    if (!$fetchResult['success']) {
        $errorMsg = sprintf(
            "Failure after %d attempts: %s",
            $fetchResult['attempts'],
            $fetchResult['error']
        );
        echo "  ERROR: $errorMsg\n";
        error_log("Device $deviceNum: $errorMsg");
        $results[$url] = null;
    } else {
        echo "  Connected successfully!\n";
        $powerValue = extractPowerValue($fetchResult['data']);
        
        if (isValidPowerValue($powerValue)) {
            echo "  Power captured: {$powerValue} W\n";
            $results[$url] = $powerValue;
        } else {
            $errorMsg = "Could not extract valid value from HTML";
            echo "  ERROR: $errorMsg\n";
            error_log("Device $deviceNum: $errorMsg");
            $results[$url] = null;
        }
    }
    
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
// SENDING DATA TO PVOUTPUT
// ===============================
if ($total > 0 || $numSuccessful > 0) {
    echo "Sending data to PVOutput...\n";
    $pvResult = sendToPVOutput((int)$total, $config);
    
    if ($pvResult['success']) {
        echo "✓ Data sent successfully!\n";
        echo "  Response: {$pvResult['response']}\n";
        echo "  HTTP Code: {$pvResult['http_code']}\n";
    } else {
        $errorMsg = sprintf(
            "Failed to send data to PVOutput: %s (HTTP %d)",
            $pvResult['error'] ?: $pvResult['response'] ?: 'Unknown error',
            $pvResult['http_code']
        );
        echo "✗ $errorMsg\n";
        error_log($errorMsg);
        exit(1);
    }
} else {
    echo "Skipping PVOutput send (total = 0 and no valid devices)\n";
    exit(1);
}

echo "\n=== SCRIPT END ===\n";
exit(0);
