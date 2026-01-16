# Deye Monitor

PHP script for monitoring Deye inverters and sending data to PVOutput.

## Description

This script connects to multiple Deye devices on a local network, collects real-time power data, and sends the information to the PVOutput service for visualization and analysis.

## Features

- **Multiple device monitoring** - Collects data from multiple devices sequentially
- **Automatic retry with exponential backoff** - Retries connection on failure (up to 3 attempts)
- **Smart fallback calculation** - Uses average of successful values for failed devices
- **PVOutput integration** - Automatically sends data to the cloud service
- **Complete logging** - Logs all operations and errors to a log file
- **Secure configuration** - Credentials separated from source code

## Configuration

### Prerequisites

- PHP 7.4+
- cURL extension enabled
- Access to local network where Deye devices are connected
- Internet access for PVOutput communication

### Installation

1. Clone or download this repository:
   ```bash
   git clone https://github.com/ggrigon/deye-pvoutput-public.git
   cd deye-pvoutput-public
   ```

2. Configure credentials:
   ```bash
   cp config.php.example config.php
   nano config.php  # or use your preferred editor
   ```

3. Edit the `config.php` file and fill in:
   - Deye device credentials (host, username, password, ports)
   - PVOutput API key and System ID

4. Test execution:
   ```bash
   php solar.php
   ```

5. Set up scheduling (cron) for automatic execution

## Detailed Configuration

### config.php File

The `config.php` file contains all necessary settings:

```php
return [
    'devices' => [
        'host' => '192.168.1.100',     // IP or hostname of devices
        'username' => 'admin',          // HTTP username
        'password' => 'admin',          // HTTP password
        'ports' => [1231, 1232, 1233, 1234, 1235],  // Device ports
        'path' => '/status.html',       // Status page path
    ],
    'pvoutput' => [
        'api_key' => 'YOUR_API_KEY_HERE',     // Get from https://pvoutput.org/
        'system_id' => 'YOUR_SYSTEM_ID_HERE',  // Your System ID
    ],
    'settings' => [
        'timezone' => 'America/Sao_Paulo',
        'max_retries' => 3,
        'base_delay' => 5,
        'http_timeout' => 10,
        'curl_timeout' => 30,
        'delay_between_devices' => 5,
    ],
    'weather' => [
        'enabled' => true,
        'api_key' => 'YOUR_WUNDERGROUND_API_KEY',  // Weather Underground API
        'station_id' => '',             // Leave empty for auto-detect
        'timeout' => 10,
    ],
    'shelly' => [
        'enabled' => true,
        'url' => 'http://YOUR_SHELLY_IP/status',  // Shelly EM status URL
        'timeout' => 5,
        'phase' => 2,                   // Phase for voltage (0=A, 1=B, 2=C)
    ],
];
```

### Getting PVOutput Credentials

1. Visit https://pvoutput.org/
2. Log in to your account
3. Go to **Settings** → **API**
4. Copy your **API Key** and **System ID**
5. Paste into the `config.php` file

For detailed instructions, see [HOW_TO_GET_KEYS.md](HOW_TO_GET_KEYS.md).

## Data Architecture

### Data Sources

```
+---------------------+     +---------------------+     +---------------------+
|   Deye Inverters    |     |  Weather Underground|     |     Shelly EM       |
|   (N devices)       |     |  (PWS stations)     |     |    (Phase A/B/C)    |
+----------+----------+     +----------+----------+     +----------+----------+
           |                           |                           |
           v                           v                           v
      Power (W)                  Temperature                   Voltage (V)
                                 Humidity (%)
                                 Solar Radiation
                                 UV Index
                                 Wind Speed
                                 Pressure
           |                           |                           |
           +---------------------------+---------------------------+
                                       |
                                       v
                        +------------------------------+
                        |      solar.php               |
                        |  (Data Collection & Send)    |
                        +------------------------------+
                                       |
                                       v
                        +------------------------------+
                        |     PVOutput API             |
                        |  addstatus.jsp               |
                        +------------------------------+
```

### PVOutput Parameters

| Parameter | Data | Source | Description |
|-----------|------|--------|-------------|
| v2 | Power Generation (W) | Deye Inverters | Sum of all inverters |
| v5 | Temperature (°C) | Weather Underground | Ambient temperature |
| v6 | Voltage (V) | Shelly EM | Grid voltage |
| v7 | Humidity (%) | Weather Underground | Extended data |
| v8 | Solar Radiation (W/m²) | Weather Underground | Extended data |
| v9 | UV Index | Weather Underground | Extended data |
| v10 | Wind Speed (km/h) | Weather Underground | Extended data |
| v11 | Pressure (hPa) | Weather Underground | Extended data |

## How It Works

### Main Flow

1. **Loading** - Loads configuration from `config.php` file
2. **Connection** - Connects to each Deye device via HTTP
3. **Collection** - Extracts instant power value from HTML (`webdata_now_p`)
4. **Retry** - If it fails, retries with exponential delay (5s, 10s, 20s)
5. **Calculation** - Calculates total:
   - Sums successful values (> 0)
   - Uses average of successful values for failed devices
   - Returns 0 if no device responds
6. **Sending** - Sends total to PVOutput via API

### Error Handling

| Scenario | Action |
|----------|--------|
| Connection fails | Retries (max 3 attempts) with exponential backoff |
| Data extraction fails | Uses average value as fallback |
| All devices fail | Sets total to 0 |
| PVOutput send error | Logs error and continues |

## Usage

### Manual Execution

```bash
php solar.php
```

### Cron Scheduling

To collect data every 5 minutes:

```bash
*/5 * * * * cd /path/to/deye-pvoutput && php solar.php >> /dev/null 2>&1
```

To collect every hour:

```bash
0 * * * * cd /path/to/deye-pvoutput && php solar.php >> /dev/null 2>&1
```

To collect every minute (not recommended for free PVOutput):

```bash
* * * * * cd /path/to/deye-pvoutput && php solar.php >> /dev/null 2>&1
```

**Note**: Free PVOutput has request limits. Check the official documentation.

## Logs

The script generates logs in `deye_monitor.log` in the same directory.

View in real-time:
```bash
tail -f deye_monitor.log
```

View last 50 lines:
```bash
tail -n 50 deye_monitor.log
```

## Example Output

```
=== INÍCIO DO SCRIPT ===
Data/Hora: 2025-01-20 14:30:45

Dispositivo 1/5: Conectando...
  Conectado com sucesso!
  Potência capturada: 2500 W
---------------------------------------
Dispositivo 2/5: Conectando...
  Conectado com sucesso!
  Potência capturada: 2300 W
---------------------------------------
...
Valores bem-sucedidos (>0): 2500, 2300, 2400, 2200, 2100 W
Média calculada para fallback: 2300 W
Dispositivos com falha: 0

Total calculado: 11500 W (com 0 fallback(s))

=== TOTAL FINAL: 11500 W ===

Enviando dados ao PVOutput...
✓ Dados enviados com sucesso!
  Resposta: OK 200:Added
  HTTP Code: 200

=== FIM DO SCRIPT ===
```

## Troubleshooting

### "config.php file not found"

- Copy `config.php.example` to `config.php`
- Fill in all necessary settings

### "Incomplete configuration"

- Check if you filled in PVOutput `api_key` and `system_id`
- Check if you configured device `host`

### "Failure after 3 attempts"

- Check if device is online
- Test connection manually:
  ```bash
  curl http://username:password@your-host:port/status.html
  ```
- Check firewall/network permissions
- Confirm credentials in `config.php`

### "Could not extract valid value"

- Check if URL and port are correct
- Confirm device returns HTML with `webdata_now_p`
- Check if credentials are correct
- Test manually by accessing URL in browser

### Data doesn't appear on PVOutput

- Check API key and System ID in `config.php`
- Confirm your PVOutput account is active
- Check logs for cURL error messages
- Test API manually:
  ```bash
  curl -X POST "https://pvoutput.org/service/r2/addstatus.jsp" \
    -H "X-Pvoutput-Apikey: YOUR_API_KEY" \
    -H "X-Pvoutput-SystemId: YOUR_SYSTEM_ID" \
    -d "d=20250120&t=14:30&v1=1000"
  ```

## Configuration Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `max_retries` | 3 | Maximum number of attempts per device |
| `base_delay` | 5 | Initial delay in seconds (grows exponentially) |
| `http_timeout` | 10 | Timeout for HTTP connections in seconds |
| `curl_timeout` | 30 | Timeout for cURL requests in seconds |
| `delay_between_devices` | 5 | Delay between device requests (seconds) |
| `timezone` | America/Sao_Paulo | Timezone for timestamps |

## Security

### ⚠️ Important

- **NEVER** commit the `config.php` file to Git
- The `config.php` file is in `.gitignore` to protect your credentials
- Use strong credentials for Deye devices
- Keep your PVOutput API Key secure
- Run script on secure/private network when possible

### Best Practices

- Periodically review logs for suspicious activity
- Keep PHP and extensions updated
- Use HTTPS when available (future)
- Consider using token authentication if available on devices

## Project Structure

```
deye-pvoutput/
├── solar.php              # Main script
├── dashboard.php          # Web dashboard with charts
├── config.php.example     # Configuration template
├── config.php             # Your configurations (not versioned)
├── daily_stats.json       # Daily statistics (auto-generated)
├── .gitignore             # Files ignored by Git
├── deye_monitor.log       # Log file (not versioned)
├── deye.sh.example        # Bash script example
├── test_weather.php       # Weather Underground test script
├── test_pvoutput_weather.php  # PVOutput + Weather test script
├── test_full_integration.php  # Full integration test script
└── README.md              # This file
```

## Contributing

Contributions are welcome! Please:

1. Fork the project
2. Create a branch for your feature (`git checkout -b feature/MyFeature`)
3. Commit your changes (`git commit -m 'Add MyFeature'`)
4. Push to the branch (`git push origin feature/MyFeature`)
5. Open a Pull Request

## License

This project is under the MIT license. See the `LICENSE` file for details.

## Support

For questions or issues:

1. Check logs in `deye_monitor.log`
2. Consult PVOutput documentation: https://pvoutput.org/help.html
3. Consult your Deye installation documentation
4. Open an issue on GitHub

## Changelog

### v2.1
- Weather Underground integration for temperature data (v5)
- Shelly EM integration for voltage data (v6)
- Extended weather data: humidity (v7), solar radiation (v8), UV index (v9), wind speed (v10), pressure (v11)
- Auto-detection of nearest weather station
- Web dashboard with real-time charts
- Daily statistics tracking and analysis

### v2.0
- Credentials separated into configuration file
- Improved validation and error handling
- Refactored and better organized code
- Better documentation

### v1.0
- Initial version

## Related

This script was created to solve the issue described in the [PVOutput forum](https://forum.pvoutput.org/t/solarman-trannergy-auto-upload/8372) where automatic uploads from Deye/Solarman systems stopped working.
