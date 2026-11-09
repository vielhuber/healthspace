<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Healthspace.php';

use vielhuber\healthspace\Healthspace;

$rootDirectory = sys_get_temp_dir() . '/healthspace-test-' . bin2hex(random_bytes(8));
mkdir($rootDirectory . '/.data', 0700, true);
$application = new Healthspace(rootDir: $rootDirectory);
$invoke = static function (string $method, mixed ...$arguments) use ($application): mixed {
    return (new ReflectionMethod($application, $method))->invoke($application, ...$arguments);
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$contains = static function (string $needle, string $haystack) use ($assert): void {
    $assert(str_contains($haystack, $needle), 'Missing: ' . $needle);
};
$submit = static function (array $values) use ($application): void {
    $_POST = $values;
    ob_start();
    $application->run();
    ob_end_clean();
    $_POST = [];
};

try {
    $database = $invoke('openDatabase');
    $empty = $invoke('buildHealthBlock');
    $contains('Noch keine manuellen Messwerte', $empty);
    $contains('/?health=connect', $empty);
    $measurement = [
        'health_action' => 'save',
        'day' => '2026-11-01',
        'weight_kg' => '104,5',
        'body_fat_percentage' => '20,5',
        'blood_pressure_systolic' => '120',
        'blood_pressure_diastolic' => '80',
        'pulse' => '65'
    ];
    $submit($measurement);
    $row = $database->query('SELECT * FROM health_measurements')->fetch(PDO::FETCH_ASSOC);
    $assert((float) $row['weight_kg'] === 104.5 && (float) $row['body_fat_percentage'] === 20.5, 'Decimal input lost.');
    $assert((int) $row['pulse'] === 65, 'Pulse lost.');
    $html = $invoke('buildHealthBlock');
    foreach (
        ['104,5 kg', '20,5 %', '120/80', 'healthWeight', 'healthBodyFat', 'healthBloodPressure', 'healthPulse']
        as $needle
    ) {
        $contains($needle, $html);
    }
    $submit($measurement);
    $assert(
        (int) $database->query('SELECT COUNT(*) FROM health_measurements')->fetchColumn() === 1,
        'Duplicate date accepted.'
    );
    foreach (
        [
            ['day' => '2026-02-30'],
            ['weight_kg' => '-1'],
            ['body_fat_percentage' => '101'],
            ['weight_kg' => ''],
            ['blood_pressure_diastolic' => ''],
            ['pulse' => 'invalid'],
            ['measurement_id' => '0'],
            ['measurement_id' => '999'],
            ['health_action' => 'invalid']
        ]
        as $invalid
    ) {
        $submit(array_replace($measurement, ['day' => '2026-11-02'], $invalid));
        $assert(
            (int) $database->query('SELECT COUNT(*) FROM health_measurements')->fetchColumn() === 1,
            'Invalid input accepted.'
        );
    }
    $_GET = ['edit_measurement' => (string) $row['id']];
    $contains('Messwerte bearbeiten', $invoke('buildHealthBlock'));
    $_GET = [];
    $submit(array_replace($measurement, ['measurement_id' => (string) $row['id'], 'weight_kg' => '103.2']));
    $assert(
        (float) $database->query('SELECT weight_kg FROM health_measurements')->fetchColumn() === 103.2,
        'Edit failed.'
    );
    $insert = $database->prepare('INSERT INTO health_days
        (day, steps, calories, floors, sleep_minutes, sleep_period_minutes, sleep_deep_minutes,
        sleep_light_minutes, sleep_rem_minutes, sleep_awake_minutes, nap_minutes, nap_count,
        sleep_start, sleep_end, updated_at) VALUES (?, ?, 2500, 10, ?, 480, 60, 240, 120, 30, 20, 1, ?, ?, ?)');
    for ($offset = 0; $offset < 14; $offset++) {
        $day = (new DateTimeImmutable('today'))->modify('-' . $offset . ' days')->format('Y-m-d');
        $insert->execute([
            $day,
            $offset < 7 ? 12000 : 10000,
            $offset < 7 ? 420 : 400,
            $day . ' 00:00:00',
            $day . ' 08:00:00',
            time()
        ]);
    }
    $stats = $invoke('healthDigestStats');
    $assert($stats['steps']['average'] === 12000 && $stats['steps']['change'] === 20, 'Step trend changed.');
    $assert($stats['sleep']['average'] === 420 && $stats['sleep']['change'] === 5, 'Sleep trend changed.');
    $html = $invoke('buildHealthBlock');
    foreach (
        [
            'healthSleep',
            'healthSteps',
            'healthCalories',
            'Schlafeffizienz',
            'REM-Anteil',
            'health__lights',
            'Mittagsschlaf'
        ]
        as $needle
    ) {
        $contains($needle, $html);
    }
    preg_match('/data-chart-config="([^"]+)"/', $html, $matches);
    $charts = json_decode(html_entity_decode($matches[1], ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);
    $assert(count($charts) === 7, 'Expected all seven metric charts.');
    $assert(count($charts[0]['stacks']) === 5, 'Sleep stages lost.');
    $assert(count($charts[1]['trend']) === 14, 'Moving average lost.');
    $contains('12.000 Schritte', $invoke('buildHealthDigestBlock', []));
    $contains(
        '<strong>Trend</strong>',
        $invoke('buildHealthDigestBlock', ['prose' => '**Trend** <script>alert(1)</script>'])
    );
    $assert(
        !str_contains($invoke('buildHealthDigestBlock', ['prose' => '<script>']), '<script>'),
        'Digest HTML not escaped.'
    );
    $assert($invoke('generateHealthProse', $stats, [], '') === null, 'Unconfigured AI must be optional.');
    $submit(['health_action' => 'delete', 'measurement_id' => (string) $row['id']]);
    $assert((int) $database->query('SELECT COUNT(*) FROM health_measurements')->fetchColumn() === 0, 'Delete failed.');
} finally {
    $_GET = $_POST = $_COOKIE = [];
    foreach (glob($rootDirectory . '/.data/*') as $file) {
        unlink($file);
    }
    rmdir($rootDirectory . '/.data');
    rmdir($rootDirectory);
}

echo "Health measurements, charts and digest tests passed\n";
