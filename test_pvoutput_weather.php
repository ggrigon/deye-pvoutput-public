<?php
/**
 * Test PVOutput with Weather Underground Temperature
 *
 * This script tests sending temperature data to PVOutput
 * without requiring the full Deye device setup.
 */

echo "=== PVOUTPUT + WEATHER TEST ===\n\n";

// Configuration
$pvoutputApiKey = 'YOUR_PVOUTPUT_API_KEY';
$pvoutputSystemId = 'YOUR_SYSTEM_ID';
$wundergroundApiKey = 'YOUR_WUNDERGROUND_API_KEY';

// Check if we have real credentials
if ($pvoutputApiKey === 'YOUR_PVOUTPUT_API_KEY') {
    echo "NOTICE: Please update the PVOutput credentials in this script.\n";
    echo "Or copy config.php.example to config.php and configure.\n\n";
    echo "For now, running in SIMULATION mode (no actual API calls).\n\n";
    $simulationMode = true;
} else {
    $simulationMode = false;
}

// Step 1: Fetch weather data
echo "1. Fetching weather data from Weather Underground...\n";

$preferredStations = ['YOURSTATION1', 'YOURSTATION2'];
$weatherData = null;

foreach ($preferredStations as $station) {
    $url = "https://api.weather.com/v2/pws/observations/current?stationId=$station&format=json&units=m&apiKey=$wundergroundApiKey";

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'DeyeMonitor/2.1',
    ]);

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if (isset($data['observations'][0])) {
            $obs = $data['observations'][0];
            $weatherData = [
                'station_id' => $obs['stationID'],
                'temperature' => $obs['metric']['temp'],
                'humidity' => $obs['humidity'],
                'solar_radiation' => $obs['solarRadiation'] ?? null,
            ];
            echo "   Using station: $station\n";
            break;
        }
    }
}

if ($weatherData === null) {
    die("ERROR: Could not fetch weather data\n");
}

echo "   Temperature: {$weatherData['temperature']}°C\n";
echo "   Humidity: {$weatherData['humidity']}%\n";
if ($weatherData['solar_radiation']) {
    echo "   Solar Radiation: {$weatherData['solar_radiation']} W/m²\n";
}
echo "\n";

// Step 2: Prepare PVOutput data
echo "2. Preparing PVOutput data...\n";

$testPower = 1000; // Simulated 1kW power for test
$temperature = $weatherData['temperature'];

$postData = [
    'd' => date('Ymd'),
    't' => date('H:i'),
    'v2' => $testPower,  // Power Generation (W)
    'v5' => round($temperature, 1),  // Temperature (°C)
];

echo "   Date: {$postData['d']}\n";
echo "   Time: {$postData['t']}\n";
echo "   Power (v2): {$postData['v2']} W\n";
echo "   Temperature (v5): {$postData['v5']}°C\n";
echo "\n";

// Step 3: Send to PVOutput (or simulate)
echo "3. Sending to PVOutput...\n";

if ($simulationMode) {
    echo "   [SIMULATION MODE]\n";
    echo "   Would send POST to: https://pvoutput.org/service/r2/addstatus.jsp\n";
    echo "   Headers:\n";
    echo "     X-Pvoutput-Apikey: {your-api-key}\n";
    echo "     X-Pvoutput-SystemId: {your-system-id}\n";
    echo "   Data: " . http_build_query($postData) . "\n";
    echo "\n   To test for real, update credentials in this script or config.php\n";
} else {
    $curl = curl_init("https://pvoutput.org/service/r2/addstatus.jsp");
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postData),
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/x-www-form-urlencoded",
            "X-Pvoutput-Apikey: $pvoutputApiKey",
            "X-Pvoutput-SystemId: $pvoutputSystemId",
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
}

echo "\n=== TEST COMPLETE ===\n";
