<?php
/**
 * Deye Monitor Dashboard
 *
 * Web dashboard to visualize statistics from daily_stats.json
 * Focused on solar generation metrics
 */

date_default_timezone_set('America/Sao_Paulo');

$statsFile = __DIR__ . '/daily_stats.json';
$configFile = __DIR__ . '/config.php';
$versionFile = __DIR__ . '/version.json';
$currentDate = date('Ymd');
$yesterdayDate = date('Ymd', strtotime('-1 day'));
$selectedDate = $_GET['date'] ?? $currentDate;

// Load version info
$version = null;
if (file_exists($versionFile)) {
    $version = json_decode(file_get_contents($versionFile));
}

// Load configuration
$config = [];
if (file_exists($configFile)) {
    $config = require $configFile;
}

// Shelly configuration
$shellyConfig = $config['shelly'] ?? [];
$shellyEnabled = $shellyConfig['enabled'] ?? false;
$shellyUrl = $shellyConfig['url'] ?? '';
$shellyTimeout = $shellyConfig['timeout'] ?? 5;

// Fetch Shelly data
$shellyData = null;
if ($shellyEnabled && !empty($shellyUrl)) {
    $context = stream_context_create([
        'http' => [
            'timeout' => $shellyTimeout,
            'method' => 'GET',
        ],
    ]);
    $shellyJson = @file_get_contents($shellyUrl, false, $context);
    if ($shellyJson !== false) {
        $shellyData = json_decode($shellyJson);
    }
}

// Load statistics
$allStats = [];
if (file_exists($statsFile)) {
    $allStats = json_decode(file_get_contents($statsFile), true) ?? [];
}

$selectedStats = $allStats[$selectedDate] ?? [];
$yesterdayStats = $allStats[$yesterdayDate] ?? [];

// Get available dates (last 30 days)
$availableDates = [];
$cutoffDate = date('Ymd', strtotime('-30 days'));
foreach ($allStats as $date => $data) {
    if ($date >= $cutoffDate) {
        $availableDates[] = $date;
    }
}
rsort($availableDates);

// Prepare data for charts
$powerData = [];
$timeLabels = [];
$energyData = [];

// Get current/last power reading
$currentPower = 0;
$lastTimestamp = null;

if (!empty($selectedStats['executions'])) {
    $lastPower = 0;
    $lastTime = null;
    foreach ($selectedStats['executions'] as $exec) {
        if ($exec['success'] ?? false) {
            $powerData[] = $exec['power'];
            $timeLabels[] = date('H:i', strtotime($exec['timestamp']));
            $currentPower = $exec['power'];
            $lastTimestamp = $exec['timestamp'];

            if ($lastTime !== null) {
                $timeDiffHours = (strtotime($exec['timestamp']) - $lastTime) / 3600;
                $avgPower = ($lastPower + $exec['power']) / 2;
                $energyData[] = ($avgPower * $timeDiffHours) / 1000;
            }
            $lastPower = $exec['power'];
            $lastTime = strtotime($exec['timestamp']);
        }
    }
}

$summary = $selectedStats['summary'] ?? [];
$yesterdaySummary = $yesterdayStats['summary'] ?? [];

// Calculate total energy
$totalEnergy = array_sum($energyData);

// Calculate yesterday's energy for comparison
$yesterdayEnergy = 0;
if (!empty($yesterdayStats['executions'])) {
    $lastP = 0;
    $lastT = null;
    foreach ($yesterdayStats['executions'] as $exec) {
        if ($exec['success'] ?? false) {
            if ($lastT !== null) {
                $tdh = (strtotime($exec['timestamp']) - $lastT) / 3600;
                $yesterdayEnergy += (($lastP + $exec['power']) / 2 * $tdh) / 1000;
            }
            $lastP = $exec['power'];
            $lastT = strtotime($exec['timestamp']);
        }
    }
}

// Calculate comparisons
$energyDiff = null;
$maxPowerDiff = null;
if ($yesterdayEnergy > 0 && $totalEnergy > 0 && $selectedDate == $currentDate) {
    $energyDiff = (($totalEnergy - $yesterdayEnergy) / $yesterdayEnergy) * 100;
}
if (($summary['max_power'] ?? 0) > 0 && ($yesterdaySummary['max_power'] ?? 0) > 0 && $selectedDate == $currentDate) {
    $maxPowerDiff = (($summary['max_power'] - $yesterdaySummary['max_power']) / $yesterdaySummary['max_power']) * 100;
}

// Prepare historical data (last 7 days)
$historicalDates = [];
$historicalMaxPower = [];
$historicalEnergy = [];
for ($i = 6; $i >= 0; $i--) {
    $histDate = date('Ymd', strtotime("-$i days"));
    $histStats = $allStats[$histDate] ?? [];
    $historicalDates[] = date('d/m', strtotime($histDate));
    $historicalMaxPower[] = $histStats['summary']['max_power'] ?? 0;

    $histEnergy = 0;
    if (!empty($histStats['executions'])) {
        $lastP = 0;
        $lastT = null;
        foreach ($histStats['executions'] as $exec) {
            if ($exec['success'] ?? false) {
                if ($lastT !== null) {
                    $tdh = (strtotime($exec['timestamp']) - $lastT) / 3600;
                    $histEnergy += (($lastP + $exec['power']) / 2 * $tdh) / 1000;
                }
                $lastP = $exec['power'];
                $lastT = strtotime($exec['timestamp']);
            }
        }
    }
    $historicalEnergy[] = round($histEnergy, 2);
}

// Success rate for footer
$successRate = ($summary['total_executions'] ?? 0) > 0
    ? round(($summary['successful_executions'] ?? 0) / $summary['total_executions'] * 100, 1)
    : 0;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deye Monitor - Solar Generation</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --bg-primary: #f5f7fa;
            --bg-secondary: #ffffff;
            --text-primary: #333333;
            --text-secondary: #666666;
            --accent-primary: #f59e0b;
            --accent-secondary: #d97706;
            --accent-gradient: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            --border-color: #f0f0f0;
            --card-shadow: 0 2px 4px rgba(0,0,0,0.1);
            --success-color: #10b981;
            --danger-color: #ef4444;
        }

        [data-theme="dark"] {
            --bg-primary: #0f172a;
            --bg-secondary: #1e293b;
            --text-primary: #f1f5f9;
            --text-secondary: #94a3b8;
            --border-color: #334155;
            --card-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            padding: 20px;
            transition: background 0.3s, color 0.3s;
        }

        .container { max-width: 1200px; margin: 0 auto; }

        /* Header */
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .logo-icon {
            font-size: 2em;
        }

        .logo h1 {
            font-size: 1.5em;
            font-weight: 600;
        }

        .header-controls {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .date-selector select, .theme-toggle {
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: var(--bg-secondary);
            color: var(--text-primary);
            cursor: pointer;
            font-size: 0.9em;
        }

        .theme-toggle {
            font-size: 1.1em;
            border: none;
            background: transparent;
        }

        /* Hero - Main Power Display */
        .hero {
            background: var(--accent-gradient);
            border-radius: 20px;
            padding: 40px;
            text-align: center;
            color: white;
            margin-bottom: 30px;
            box-shadow: 0 10px 40px rgba(245, 158, 11, 0.3);
        }

        .hero-label {
            font-size: 0.9em;
            text-transform: uppercase;
            letter-spacing: 2px;
            opacity: 0.9;
            margin-bottom: 10px;
        }

        .hero-value {
            font-size: 5em;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 5px;
        }

        .hero-unit {
            font-size: 1.5em;
            opacity: 0.9;
        }

        .hero-time {
            font-size: 0.85em;
            opacity: 0.8;
            margin-top: 15px;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--bg-secondary);
            padding: 25px 20px;
            border-radius: 15px;
            text-align: center;
            box-shadow: var(--card-shadow);
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-3px);
        }

        .stat-card h3 {
            color: var(--text-secondary);
            font-size: 0.75em;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 10px;
        }

        .stat-card .value {
            font-size: 2em;
            font-weight: 700;
            color: var(--text-primary);
        }

        .stat-card .unit {
            font-size: 0.5em;
            color: var(--text-secondary);
            margin-left: 3px;
        }

        .stat-card .comparison {
            margin-top: 8px;
            font-size: 0.8em;
            padding: 4px 10px;
            border-radius: 20px;
            display: inline-block;
        }

        .comparison.positive { background: rgba(16, 185, 129, 0.15); color: var(--success-color); }
        .comparison.negative { background: rgba(239, 68, 68, 0.15); color: var(--danger-color); }

        /* Chart Container */
        .chart-section {
            background: var(--bg-secondary);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: var(--card-shadow);
        }

        .chart-section h2 {
            font-size: 1.1em;
            color: var(--text-primary);
            margin-bottom: 20px;
            font-weight: 600;
        }

        .chart-wrapper {
            height: 350px;
        }

        /* Shelly Section (Secondary) */
        .secondary-section {
            background: var(--bg-secondary);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: var(--card-shadow);
        }

        .secondary-section h2 {
            font-size: 1em;
            color: var(--text-secondary);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .shelly-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
        }

        .shelly-card {
            background: var(--bg-primary);
            padding: 15px;
            border-radius: 10px;
            text-align: center;
        }

        .shelly-card h4 {
            font-size: 0.7em;
            color: var(--text-secondary);
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .shelly-card .value {
            font-size: 1.5em;
            font-weight: 600;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }

        .status-dot.online { background: var(--success-color); box-shadow: 0 0 6px var(--success-color); }
        .status-dot.offline { background: var(--danger-color); }

        /* No Data */
        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-secondary);
        }

        /* Footer */
        footer {
            text-align: center;
            padding: 20px;
            color: var(--text-secondary);
            font-size: 0.85em;
        }

        footer a { color: var(--accent-primary); }
        footer code { background: var(--bg-secondary); padding: 2px 6px; border-radius: 4px; }

        .footer-stats {
            margin-bottom: 10px;
            font-size: 0.8em;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .hero-value { font-size: 3.5em; }
            .chart-wrapper { height: 280px; }
            header { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div class="logo">
                <span class="logo-icon">☀️</span>
                <h1>Deye Monitor</h1>
            </div>
            <div class="header-controls">
                <div class="date-selector">
                    <select id="dateSelect" onchange="window.location.href='?date=' + this.value">
                        <?php foreach ($availableDates as $date): ?>
                            <option value="<?= $date ?>" <?= $date == $selectedDate ? 'selected' : '' ?>>
                                <?= date('d/m/Y', strtotime($date)) ?>
                                <?= $date == $currentDate ? '(Today)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="theme-toggle" onclick="toggleTheme()" title="Toggle theme">
                    <span id="themeIcon">🌙</span>
                </button>
            </div>
        </header>

        <?php if (empty($selectedStats)): ?>
            <div class="no-data">
                <h3>No data available</h3>
                <p>There are no statistics for the selected date.</p>
            </div>
        <?php else: ?>

            <!-- Hero: Current/Last Power -->
            <div class="hero">
                <div class="hero-label">Current Power</div>
                <div class="hero-value"><?= number_format($currentPower, 0, ',', '.') ?></div>
                <div class="hero-unit">Watts</div>
                <?php if ($lastTimestamp): ?>
                <div class="hero-time">Last reading: <?= date('H:i', strtotime($lastTimestamp)) ?></div>
                <?php endif; ?>
            </div>

            <!-- Main Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <h3>Energy Today</h3>
                    <div class="value">
                        <?= number_format($totalEnergy, 2, ',', '.') ?>
                        <span class="unit">kWh</span>
                    </div>
                    <?php if ($energyDiff !== null): ?>
                    <div class="comparison <?= $energyDiff >= 0 ? 'positive' : 'negative' ?>">
                        <?= $energyDiff >= 0 ? '↑' : '↓' ?> <?= abs(round($energyDiff, 1)) ?>% vs yesterday
                    </div>
                    <?php endif; ?>
                </div>

                <div class="stat-card">
                    <h3>Peak Power</h3>
                    <div class="value">
                        <?= number_format($summary['max_power'] ?? 0, 0, ',', '.') ?>
                        <span class="unit">W</span>
                    </div>
                    <?php if ($maxPowerDiff !== null): ?>
                    <div class="comparison <?= $maxPowerDiff >= 0 ? 'positive' : 'negative' ?>">
                        <?= $maxPowerDiff >= 0 ? '↑' : '↓' ?> <?= abs(round($maxPowerDiff, 1)) ?>% vs yesterday
                    </div>
                    <?php endif; ?>
                </div>

                <div class="stat-card">
                    <h3>Average</h3>
                    <div class="value">
                        <?= number_format($summary['avg_power'] ?? 0, 0, ',', '.') ?>
                        <span class="unit">W</span>
                    </div>
                </div>

                <div class="stat-card">
                    <h3>Minimum</h3>
                    <div class="value">
                        <?= number_format(($summary['min_power'] ?? PHP_INT_MAX) == PHP_INT_MAX ? 0 : ($summary['min_power'] ?? 0), 0, ',', '.') ?>
                        <span class="unit">W</span>
                    </div>
                </div>
            </div>

            <!-- Power Chart -->
            <?php if (!empty($powerData)): ?>
            <div class="chart-section">
                <h2>Generation Throughout the Day</h2>
                <div class="chart-wrapper">
                    <canvas id="powerChart"></canvas>
                </div>
            </div>
            <?php endif; ?>

            <!-- Historical Chart -->
            <div class="chart-section">
                <h2>History - Last 7 Days</h2>
                <div class="chart-wrapper">
                    <canvas id="historicalChart"></canvas>
                </div>
            </div>

        <?php endif; ?>

        <?php if ($shellyEnabled && $shellyData && isset($shellyData->emeters)): ?>
        <!-- Shelly Section (Secondary) -->
        <div class="secondary-section">
            <h2>
                <span class="status-dot <?= $shellyData ? 'online' : 'offline' ?>"></span>
                Consumption (Shelly EM)
            </h2>
            <div class="shelly-grid">
                <?php foreach ($shellyData->emeters as $index => $meter): ?>
                <div class="shelly-card">
                    <h4>Channel <?= $index + 1 ?></h4>
                    <div class="value"><?= number_format($meter->power ?? 0, 0, ',', '.') ?> W</div>
                </div>
                <?php endforeach; ?>
                <div class="shelly-card">
                    <h4>Total</h4>
                    <div class="value" style="color: var(--accent-primary);">
                        <?php
                        $totalPower = 0;
                        foreach ($shellyData->emeters as $meter) {
                            $totalPower += $meter->power ?? 0;
                        }
                        echo number_format($totalPower, 0, ',', '.');
                        ?> W
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <footer>
            <?php if (!empty($selectedStats)): ?>
            <div class="footer-stats">
                <?= $summary['total_executions'] ?? 0 ?> readings | <?= $successRate ?>% success | Refreshes in <span id="countdown">300</span>s
            </div>
            <?php endif; ?>
            <p>
                Deye Monitor<?php if ($version): ?> - version <code><?= htmlspecialchars($version->short_commit ?? '') ?></code> <?= date('d/m/Y', strtotime($version->date ?? 'now')) ?><?php endif; ?> | Updated: <span id="lastUpdate">--</span>
            </p>
            <p style="margin-top: 8px;">
                <a href="https://github.com/ggrigon/deye-pvoutput-public">GitHub</a>
            </p>
        </footer>
    </div>

    <script>
        // Theme
        function toggleTheme() {
            const body = document.body;
            const icon = document.getElementById('themeIcon');
            if (body.getAttribute('data-theme') === 'dark') {
                body.removeAttribute('data-theme');
                icon.textContent = '🌙';
                localStorage.setItem('theme', 'light');
            } else {
                body.setAttribute('data-theme', 'dark');
                icon.textContent = '☀️';
                localStorage.setItem('theme', 'dark');
            }
        }

        (function() {
            if (localStorage.getItem('theme') === 'dark') {
                document.body.setAttribute('data-theme', 'dark');
                document.getElementById('themeIcon').textContent = '☀️';
            }
            const now = new Date();
            document.getElementById('lastUpdate').textContent = now.toLocaleTimeString('pt-BR');
        })();

        // Auto-refresh
        let countdown = 300;
        const countdownEl = document.getElementById('countdown');
        if (countdownEl) {
            setInterval(() => {
                countdown--;
                countdownEl.textContent = countdown;
                if (countdown <= 0) location.reload();
            }, 1000);
        }

        // Chart colors
        function getChartColors() {
            const isDark = document.body.getAttribute('data-theme') === 'dark';
            return {
                text: isDark ? '#f1f5f9' : '#333333',
                grid: isDark ? '#334155' : '#f0f0f0',
                primary: '#f59e0b',
                secondary: '#d97706'
            };
        }
    </script>

    <?php if (!empty($powerData)): ?>
    <script>
        const colors = getChartColors();
        new Chart(document.getElementById('powerChart'), {
            type: 'line',
            data: {
                labels: <?= json_encode($timeLabels) ?>,
                datasets: [{
                    label: 'Power (W)',
                    data: <?= json_encode($powerData) ?>,
                    borderColor: '#f59e0b',
                    backgroundColor: 'rgba(245, 158, 11, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 3,
                    pointHoverRadius: 7,
                    pointBackgroundColor: '#f59e0b',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        enabled: true,
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleFont: { size: 14 },
                        bodyFont: { size: 16, weight: 'bold' },
                        padding: 12,
                        displayColors: false,
                        callbacks: {
                            title: ctx => 'Time: ' + ctx[0].label,
                            label: ctx => ctx.parsed.y.toLocaleString('en-US') + ' W'
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: colors.grid },
                        ticks: {
                            callback: v => v.toLocaleString('en-US') + ' W',
                            color: colors.text
                        }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: colors.text }
                    }
                }
            }
        });
    </script>
    <?php endif; ?>

    <script>
        const colors2 = getChartColors();
        const histCtx = document.getElementById('historicalChart');
        if (histCtx) {
            new Chart(histCtx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($historicalDates) ?>,
                    datasets: [
                        {
                            label: 'Energy (kWh)',
                            data: <?= json_encode($historicalEnergy) ?>,
                            backgroundColor: 'rgba(245, 158, 11, 0.7)',
                            borderColor: '#f59e0b',
                            borderWidth: 1,
                            borderRadius: 5,
                            yAxisID: 'y'
                        },
                        {
                            label: 'Peak (W)',
                            data: <?= json_encode($historicalMaxPower) ?>,
                            type: 'line',
                            borderColor: '#d97706',
                            backgroundColor: 'transparent',
                            borderWidth: 2,
                            tension: 0.4,
                            pointRadius: 4,
                            yAxisID: 'y1'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    scales: {
                        y: {
                            type: 'linear',
                            position: 'left',
                            title: { display: true, text: 'kWh', color: colors2.text },
                            grid: { color: colors2.grid },
                            ticks: { color: colors2.text }
                        },
                        y1: {
                            type: 'linear',
                            position: 'right',
                            title: { display: true, text: 'W', color: colors2.text },
                            grid: { drawOnChartArea: false },
                            ticks: { color: colors2.text }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { color: colors2.text }
                        }
                    }
                }
            });
        }
    </script>
</body>
</html>
