<?php
/**
 * Full Integration Test - Weather + Shelly + PVOutput
 *
 * Run this script on the server to test the complete integration.
 */

echo "=== FULL INTEGRATION TEST ===\n";
echo "Date/Time: " . date('Y-m-d H:i:s') . "\n\n";

// Configuration
$config = [
    'pvoutput' => [
        'api_key' => 'YOUR_PVOUTPUT_API_KEY',
        'system_id' => 'YOUR_SYSTEM_ID',
    ],
    'weather' => [
        'enabled' => true,
        'api_key' => 'YOUR_WUNDERGROUND_API_KEY',
        'preferred_stations' => ['YOURSTATION1', 'YOURSTATION2'],
        'timeout' => 10,
    ],
    'shelly' => [
        'enabled' => true,
        'url' => 'http://YOUR_SHELLY_IP/status',
        'timeout' => 5,
        'phase' => 2,  // Phase C
    ],
];

// ===============================
// 1. TEST WEATHER UNDERGROUND
// ===============================
echo "1. Testing Weather Underground API...\n";

$temperature = null;
$weatherSuccess = false;

foreach ($config['weather']['preferred_stations'] as $station) {
    $url = sprintf(
        "https://api.weather.com/v2/pws/observations/current?stationId=%s&format=json&units=m&apiKey=%s",
        $station,
        $config['weather']['api_key']
    );

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $config['weather']['timeout'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'DeyeMonitor/2.1',
    ]);

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if (isset($data['observations'][0]['metric']['temp'])) {
            $obs = $data['observations'][0];
            $temperature = $obs['metric']['temp'];
            $weatherData = [
                'temperature' => $obs['metric']['temp'],
                'humidity' => $obs['humidity'] ?? null,
                'solar_radiation' => $obs['solarRadiation'] ?? null,
                'uv' => $obs['uv'] ?? null,
                'wind_speed' => $obs['metric']['windSpeed'] ?? null,
                'pressure' => $obs['metric']['pressure'] ?? null,
            ];
            echo "   Station: $station\n";
            echo "   Temperature: {$temperature}°C\n";
            echo "   Humidity: " . ($weatherData['humidity'] ?? 'N/A') . "%\n";
            echo "   Solar Radiation: " . ($weatherData['solar_radiation'] ?? 'N/A') . " W/m²\n";
            echo "   UV Index: " . ($weatherData['uv'] ?? 'N/A') . "\n";
            echo "   Wind Speed: " . ($weatherData['wind_speed'] ?? 'N/A') . " km/h\n";
            echo "   Pressure: " . ($weatherData['pressure'] ?? 'N/A') . " hPa\n";
            $weatherSuccess = true;
            break;
        }
    }
}

if (!$weatherSuccess) {
    echo "   FAILED: Could not fetch weather data\n";
}
echo "\n";

// ===============================
// 2. TEST SHELLY EM
// ===============================
echo "2. Testing Shelly EM...\n";

$voltage = null;
$shellySuccess = false;

$context = stream_context_create([
    'http' => [
        'timeout' => $config['shelly']['timeout'],
        'method' => 'GET',
    ],
]);

$shellyResponse = @file_get_contents($config['shelly']['url'], false, $context);

if ($shellyResponse !== false) {
    $shellyData = json_decode($shellyResponse, true);
    $phase = $config['shelly']['phase'];

    if (isset($shellyData['emeters'][$phase])) {
        $meter = $shellyData['emeters'][$phase];
        $voltage = $meter['voltage'] ?? null;
        $power = $meter['power'] ?? null;
        $phaseLabel = chr(65 + $phase);  // 0=A, 1=B, 2=C

        echo "   URL: {$config['shelly']['url']}\n";
        echo "   Phase: $phaseLabel (index $phase)\n";
        echo "   Voltage: {$voltage} V\n";
        echo "   Power: {$power} W\n";

        // Show all phases
        echo "   All phases:\n";
        foreach ($shellyData['emeters'] as $i => $m) {
            $label = chr(65 + $i);
            echo "     Phase $label: " . ($m['voltage'] ?? 'N/A') . " V, " . ($m['power'] ?? 'N/A') . " W\n";
        }
        $shellySuccess = true;
    } else {
        echo "   ERROR: Phase {$phase} not found in emeters\n";
        echo "   Raw response: " . substr($shellyResponse, 0, 500) . "\n";
    }
} else {
    echo "   FAILED: Could not connect to Shelly\n";
    echo "   URL: {$config['shelly']['url']}\n";
}
echo "\n";

// ===============================
// 3. TEST PVOUTPUT SUBMISSION
// ===============================
echo "3. Testing PVOutput submission...\n";

$testPower = 500;  // Simulated power for test

$postData = [
    'd' => date('Ymd'),
    't' => date('H:i'),
    'v2' => $testPower,
];

// Add weather data
if ($temperature !== null) {
    $postData['v5'] = round($temperature, 1);
    echo "   Temperature (v5): {$postData['v5']}°C\n";
}

if ($voltage !== null) {
    $postData['v6'] = round($voltage, 1);
    echo "   Voltage (v6): {$postData['v6']} V\n";
}

// Extended data from weather
if (isset($weatherData['humidity'])) {
    $postData['v7'] = round($weatherData['humidity'], 0);
    echo "   Humidity (v7): {$postData['v7']}%\n";
}

if (isset($weatherData['solar_radiation']) && $weatherData['solar_radiation'] !== null) {
    $postData['v8'] = round($weatherData['solar_radiation'], 1);
    echo "   Solar Radiation (v8): {$postData['v8']} W/m²\n";
}

if (isset($weatherData['uv']) && $weatherData['uv'] !== null) {
    $postData['v9'] = round($weatherData['uv'], 1);
    echo "   UV Index (v9): {$postData['v9']}\n";
}

if (isset($weatherData['wind_speed']) && $weatherData['wind_speed'] !== null) {
    $postData['v10'] = round($weatherData['wind_speed'], 1);
    echo "   Wind Speed (v10): {$postData['v10']} km/h\n";
}

if (isset($weatherData['pressure']) && $weatherData['pressure'] !== null) {
    $postData['v11'] = round($weatherData['pressure'], 1);
    echo "   Pressure (v11): {$postData['v11']} hPa\n";
}

echo "   Power (v2): {$testPower} W\n";
echo "   Data: " . http_build_query($postData) . "\n";

$curl = curl_init("https://pvoutput.org/service/r2/addstatus.jsp");
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($postData),
    CURLOPT_HTTPHEADER => [
        "Content-Type: application/x-www-form-urlencoded",
        "X-Pvoutput-Apikey: {$config['pvoutput']['api_key']}",
        "X-Pvoutput-SystemId: {$config['pvoutput']['system_id']}",
    ],
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_USERAGENT => 'DeyeMonitor/2.1',
]);

$response = curl_exec($curl);
$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
$error = curl_error($curl);
curl_close($curl);

if ($httpCode >= 200 && $httpCode < 300) {
    echo "   SUCCESS!\n";
    echo "   Response: $response\n";
    echo "   HTTP Code: $httpCode\n";
} else {
    echo "   FAILED!\n";
    echo "   HTTP Code: $httpCode\n";
    echo "   Response: $response\n";
    echo "   Error: $error\n";
}

echo "\n=== TEST SUMMARY ===\n";
echo "Weather:  " . ($weatherSuccess ? "OK" : "FAILED") . "\n";
echo "Shelly:   " . ($shellySuccess ? "OK" : "FAILED") . "\n";
echo "PVOutput: " . ($httpCode >= 200 && $httpCode < 300 ? "OK" : "FAILED") . "\n";
echo "\n=== TEST COMPLETE ===\n";
