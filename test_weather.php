<?php
/**
 * Weather Underground API Test Script
 *
 * Tests the Weather Underground integration independently
 */

echo "=== WEATHER UNDERGROUND API TEST ===\n\n";

$apiKey = 'YOUR_WUNDERGROUND_API_KEY';
$preferredStations = ['YOURSTATION1', 'YOURSTATION2'];

/**
 * Fetches current weather data from Weather Underground
 */
function fetchWeatherData(string $stationId, string $apiKey, int $timeout = 10): ?array {
    $url = sprintf(
        "https://api.weather.com/v2/pws/observations/current?stationId=%s&format=json&units=m&apiKey=%s",
        urlencode($stationId),
        $apiKey
    );

    echo "Fetching from station: $stationId\n";
    echo "URL: $url\n";

    $curl = curl_init($url);
    if ($curl === false) {
        echo "  ERROR: Could not initialize cURL\n";
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
    $error = curl_error($curl);
    curl_close($curl);

    echo "  HTTP Code: $httpCode\n";

    if ($response === false || $httpCode !== 200) {
        echo "  ERROR: Request failed - $error\n";
        return null;
    }

    $data = json_decode($response, true);
    if (!isset($data['observations'][0])) {
        echo "  ERROR: No observations in response\n";
        echo "  Response: " . substr($response, 0, 200) . "\n";
        return null;
    }

    $obs = $data['observations'][0];
    return [
        'station_id' => $obs['stationID'] ?? '',
        'neighborhood' => $obs['neighborhood'] ?? '',
        'temperature' => $obs['metric']['temp'] ?? null,
        'humidity' => $obs['humidity'] ?? null,
        'solar_radiation' => $obs['solarRadiation'] ?? null,
        'uv' => $obs['uv'] ?? null,
        'wind_speed' => $obs['metric']['windSpeed'] ?? null,
        'pressure' => $obs['metric']['pressure'] ?? null,
        'obs_time' => $obs['obsTimeLocal'] ?? null,
    ];
}

// Test each preferred station
foreach ($preferredStations as $station) {
    echo "\n--- Testing $station ---\n";
    $data = fetchWeatherData($station, $apiKey);

    if ($data !== null) {
        echo "\n  SUCCESS!\n";
        echo "  Station: {$data['station_id']} ({$data['neighborhood']})\n";
        echo "  Temperature: {$data['temperature']}°C\n";
        echo "  Humidity: {$data['humidity']}%\n";
        echo "  Solar Radiation: {$data['solar_radiation']} W/m²\n";
        echo "  UV Index: {$data['uv']}\n";
        echo "  Wind Speed: {$data['wind_speed']} km/h\n";
        echo "  Pressure: {$data['pressure']} hPa\n";
        echo "  Observation Time: {$data['obs_time']}\n";
    } else {
        echo "  FAILED - Station not available\n";
    }
}

echo "\n=== TEST COMPLETE ===\n";
