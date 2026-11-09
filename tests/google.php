<?php
declare(strict_types=1);

namespace vielhuber\healthspace {
    final class GoogleRequestFixture
    {
        public array $options = [];

        /**
         * Retain the requested endpoint for the transport fixture.
         */
        public function __construct(public string $url) {}
    }

    /**
     * Replace the Google transport without contacting an external service.
     */
    function curl_init(string $url): GoogleRequestFixture
    {
        return new GoogleRequestFixture($url);
    }

    /**
     * Record headers and payloads for validation by the fixture response.
     */
    function curl_setopt_array(GoogleRequestFixture $handle, array $options): bool
    {
        $handle->options = $options;
        return true;
    }

    /**
     * Supply paginated sleep fragments and bounded activity rollups.
     */
    function curl_exec(GoogleRequestFixture $handle): string
    {
        $GLOBALS['google_requests'][] = $handle;
        if (str_contains($handle->url, 'oauth2.googleapis.com')) {
            return '{"access_token":"fixture-access"}';
        }
        if (str_contains($handle->url, 'dailyRollUp')) {
            $body = json_decode($handle->options[CURLOPT_POSTFIELDS], true, flags: JSON_THROW_ON_ERROR);
            $start = $body['range']['start']['date'];
            $end = $body['range']['end']['date'];
            $from = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $start['year'], $start['month'], $start['day']));
            $until = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $end['year'], $end['month'], $end['day']));
            $limit = str_contains($handle->url, 'total-calories') ? 14 : 90;
            if ($from->diff($until)->days + 1 > $limit || $body['windowSizeDays'] !== 1) {
                throw new \RuntimeException('Rollup exceeds the API window.');
            }
            return (string) json_encode([
                'rollupDataPoints' => [
                    [
                        'civilStartTime' => ['date' => $end],
                        'steps' => ['countSum' => 12345],
                        'floors' => ['countSum' => 12],
                        'totalCalories' => ['kcalSum' => 2300.4]
                    ]
                ]
            ]);
        }
        if (str_contains($handle->url, 'pageToken=')) {
            $response = [
                'dataPoints' => [
                    [
                        'sleep' => [
                            'interval' => [
                                'startTime' => '2026-11-08T12:00:00Z',
                                'endTime' => '2026-11-08T12:30:00Z',
                                'startUtcOffset' => '3600s',
                                'endUtcOffset' => '3600s'
                            ],
                            'summary' => ['minutesAsleep' => 30, 'minutesInSleepPeriod' => 30]
                        ]
                    ]
                ]
            ];
            if ($GLOBALS['google_sleep_repeat_page'] ?? false) {
                $response['nextPageToken'] = 'fixture-repeat';
            }
            return (string) json_encode($response);
        }
        return (string) json_encode([
            'nextPageToken' => 'fixture-next',
            'dataPoints' => [
                [
                    'sleep' => [
                        'interval' => [
                            'startTime' => '2026-11-07T21:00:00Z',
                            'endTime' => '2026-11-07T22:00:00Z',
                            'startUtcOffset' => '3600s',
                            'endUtcOffset' => '3600s'
                        ],
                        'summary' => [
                            'minutesAsleep' => 60,
                            'minutesInSleepPeriod' => 60,
                            'stagesSummary' => [['type' => 'DEEP', 'minutes' => 60]]
                        ]
                    ]
                ],
                [
                    'sleep' => [
                        'interval' => [
                            'startTime' => '2026-11-07T23:00:00Z',
                            'endTime' => '2026-11-08T05:00:00Z',
                            'startUtcOffset' => '3600s',
                            'endUtcOffset' => '3600s'
                        ],
                        'summary' => [
                            'minutesAsleep' => 360,
                            'minutesInSleepPeriod' => 400,
                            'stagesSummary' => [
                                ['type' => 'LIGHT', 'minutes' => 240],
                                ['type' => 'REM', 'minutes' => 120]
                            ]
                        ]
                    ]
                ]
            ]
        ]);
    }

    /**
     * Mark fixture responses as successful without an HTTP connection.
     */
    function curl_getinfo(GoogleRequestFixture $handle, int $option): int
    {
        return 200;
    }
}

namespace {
    require dirname(__DIR__) . '/src/Healthspace.php';

    $root = sys_get_temp_dir() . '/healthspace-google-' . bin2hex(random_bytes(8));
    mkdir($root . '/.data', 0700, true);
    file_put_contents($root . '/.data/.env', "GOOGLE_HEALTH_CLIENT_ID=fixture\nGOOGLE_HEALTH_CLIENT_SECRET=fixture\n");
    file_put_contents($root . '/.data/google_health.json', '{"refresh_token":"fixture-refresh"}');
    $application = new \vielhuber\healthspace\Healthspace(rootDir: $root);
    $invoke = static function (string $method, mixed ...$arguments) use ($application): mixed {
        return (new ReflectionMethod($application, $method))->invoke($application, ...$arguments);
    };
    $GLOBALS['google_requests'] = [];
    try {
        $database = $invoke('openDatabase');
        $database->exec("INSERT INTO health_days (day, distance_meters, sleep_awake_minutes, updated_at)
            VALUES ('2026-11-08', 777, 40, 0)");
        $messages = [];
        $invoke('syncGoogleHealth', $database, static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });
        $night = $database->query("SELECT * FROM health_days WHERE day = '2026-11-08'")->fetch(PDO::FETCH_ASSOC);
        foreach (
            [
                'sleep_minutes' => 450,
                'nap_minutes' => 30,
                'nap_count' => 1,
                'sleep_deep_minutes' => 60,
                'sleep_light_minutes' => 240,
                'sleep_rem_minutes' => 120,
                'sleep_awake_minutes' => 40,
                'distance_meters' => 777
            ]
            as $column => $expected
        ) {
            if ((int) $night[$column] !== $expected) {
                throw new RuntimeException('Sleep merge or partial-data preservation failed: ' . $column);
            }
        }
        if ($night['sleep_start'] !== '2026-11-07 22:00:00' || $night['sleep_end'] !== '2026-11-08 13:30:00') {
            throw new RuntimeException('Local timestamps or fragmented nights changed.');
        }
        if (count($GLOBALS['google_requests']) !== 12) {
            throw new RuntimeException('Missing rollup chunks or paginated sleep requests.');
        }
        $today = $database
            ->query('SELECT steps, floors, calories FROM health_days ORDER BY day DESC LIMIT 1')
            ->fetch(PDO::FETCH_ASSOC);
        if (array_map('intval', $today) !== ['steps' => 12345, 'floors' => 12, 'calories' => 2300]) {
            throw new RuntimeException('Activity rollups changed.');
        }
        $GLOBALS['google_sleep_repeat_page'] = true;
        $invoke('syncGoogleHealth', $database, static function (string $message): void {});
        if (
            (int) $database->query("SELECT sleep_minutes FROM health_days WHERE day = '2026-11-08'")->fetchColumn() !==
            450
        ) {
            throw new RuntimeException('Truncated sleep pagination overwrote a complete night.');
        }
    } finally {
        foreach (glob($root . '/.data/*') as $file) {
            unlink($file);
        }
        unlink($root . '/.data/.env');
        rmdir($root . '/.data');
        rmdir($root);
    }
    echo "Google sync, pagination, sleep and partial-data tests passed\n";
}
