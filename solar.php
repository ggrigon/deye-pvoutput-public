<?php
/**
 * Deye Monitor - Deye Inverter Monitoring Script
 * 
 * This script connects to multiple Deye devices on a local network,
 * collects real-time power data and sends the information to the
 * PVOutput service for visualization and analysis.
 * 
 * @author Guilherme Rigon
 * @version 2.0
 * @license MIT
 */

// ===============================
// CONFIGURATION LOADING
// ===============================
$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    die("ERROR: config.php file not found!\n"
        . "Copy config.php.example to config.php and configure your credentials.\n");
}

$config = require $configFile;

// Basic configuration validation
if (empty($config['devices']['host']) || 
    empty($config['pvoutput']['api_key']) || 
    empty($config['pvoutput']['system_id'])) {
    die("ERROR: Incomplete configuration! Check the config.php file.\n");
}

// ===============================
// ERROR AND LOGGING CONFIGURATION
// ===============================
ini_set('log_errors', 1);
ini_set('error_log', $config['logging']['log_file']);
ini_set('display_errors', $config['logging']['display_errors'] ? 1 : 0);
error_reporting(E_ALL);

// ===============================
// TIMEZONE CONFIGURATION
// ===============================
date_default_timezone_set($config['settings']['timezone']);

// ===============================
// HELPER FUNCTIONS
// ===============================

/**
 * Validates if a value is numeric and positive
 */
function isValidPowerValue($value) {
    return $value !== null && is_numeric($value) && $value >= 0;
}

/**
 * Extracts power value from HTML
 */
function extractPowerValue($html) {
    if (preg_match('/webdata_now_p\s*=\s*["\']?(\d+)["\']?/', $html, $matches)) {
        return (int)$matches[1];
    }
    return null;
}

/**
 * Makes HTTP request with retry and exponential backoff
 */
function fetchDeviceData($url, $timeout, $maxRetries, $baseDelay) {
    $attempt = 0;
    $lastError = null;
    
    while ($attempt < $maxRetries) {
        $attempt++;
        
        $context = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'method' => 'GET',
                'header' => [
                    'Connection: close',
                ],
            ],
        ]);
        
        $contents = @file_get_contents($url, false, $context);
        
        if ($contents !== false) {
            return ['success' => true, 'data' => $contents];
        }
        
        $lastError = error_get_last();
        
        if ($attempt < $maxRetries) {
            $delay = $baseDelay * (2 ** ($attempt - 1)); // exponential backoff
            sleep($delay);
        }
    }
    
    return [
        'success' => false,
        'error' => $lastError['message'] ?? 'Unknown error',
        'attempts' => $attempt,
    ];
}

/**
 * Sends data to PVOutput
 */
function sendToPVOutput($total, $config) {
    $postData = [
        'd' => date('Ymd'),
        't' => date('H:i'),
        'v2' => $total,  // v2 = Power Generation (W) - instant value
    ];
    
    $curl = curl_init("https://pvoutput.org/service/r2/addstatus.jsp");
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $config['settings']['curl_timeout'],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postData),
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/x-www-form-urlencoded",
            "X-Pvoutput-Apikey: " . $config['pvoutput']['api_key'],
            "X-Pvoutput-SystemId: " . $config['pvoutput']['system_id'],
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    
    curl_close($curl);
    
    return [
        'success' => ($httpCode >= 200 && $httpCode < 300) && empty($error),
        'response' => $response,
        'http_code' => $httpCode,
        'error' => $error,
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
    $url = sprintf(
        "http://%s:%s@%s:%d%s",
        urlencode($devices['username']),
        urlencode($devices['password']),
        $devices['host'],
        $port,
        $devices['path']
    );
    $urls[] = $url;
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
        $config['settings']['http_timeout'],
        $config['settings']['max_retries'],
        $config['settings']['base_delay']
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
        echo "  Waiting {$config['settings']['delay_between_devices']}s before next device...\n";
        sleep($config['settings']['delay_between_devices']);
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
    $pvResult = sendToPVOutput($total, $config);
    
    if ($pvResult['success']) {
        echo "✓ Data sent successfully!\n";
        echo "  Response: {$pvResult['response']}\n";
        echo "  HTTP Code: {$pvResult['http_code']}\n";
    } else {
        $errorMsg = sprintf(
            "Failed to send data to PVOutput: %s (HTTP %d)",
            $pvResult['error'] ?: $pvResult['response'],
            $pvResult['http_code']
        );
        echo "✗ $errorMsg\n";
        error_log($errorMsg);
    }
} else {
    echo "Skipping PVOutput send (total = 0 and no valid devices)\n";
}

echo "\n=== SCRIPT END ===\n";
