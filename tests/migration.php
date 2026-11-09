<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Healthspace.php';

$root = sys_get_temp_dir() . '/healthspace-migration-' . bin2hex(random_bytes(8));
mkdir($root . '/.data', 0700, true);
$application = new \vielhuber\healthspace\Healthspace(rootDir: $root);
$database = (new ReflectionMethod($application, 'openDatabase'))->invoke($application);
$legacyFile = $root . '/legacy.sqlite';
try {
    $database->exec('ATTACH DATABASE ' . $database->quote($legacyFile) . ' AS previous');
    foreach (['health_days', 'health_measurements'] as $table) {
        $columns = $database->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC);
        $reordered = array_reverse(array_column($columns, 'name'));
        $database->exec(
            'CREATE TABLE previous.' . $table . ' AS SELECT ' . implode(', ', $reordered) . ' FROM main.' . $table
        );
    }
    $database->exec('DETACH DATABASE previous');
    $legacy = new PDO('sqlite:' . $legacyFile);
    $legacy->exec("INSERT INTO health_days
        (day, steps, floors, calories, sleep_minutes, sleep_start, sleep_end, updated_at)
        VALUES ('2026-11-08', 12345, 12, 2300, 420, '2026-11-07 22:00:00', '2026-11-08 06:00:00', 1234567)");
    $legacy->exec("INSERT INTO health_measurements
        (id, day, weight_kg, body_fat_percentage, blood_pressure_systolic, blood_pressure_diastolic, pulse, created_at, updated_at)
        VALUES (17, '2026-11-08', 104.5, 20.5, 120, 80, 65, 1234567, 1234568)");
    $readme = file_get_contents(dirname(__DIR__) . '/README.md');
    if (preg_match('/```sql\n(.*?)```/s', $readme, $matches) !== 1) {
        throw new RuntimeException('Missing documented migration.');
    }
    $sql = str_replace("'/path/to/extrablatt/.data/database.sqlite'", $database->quote($legacyFile), $matches[1]);
    $database->exec($sql);
    foreach (['health_days', 'health_measurements'] as $table) {
        $expected = $legacy->query('SELECT * FROM ' . $table)->fetch(PDO::FETCH_ASSOC);
        $actual = $database->query('SELECT * FROM ' . $table)->fetch(PDO::FETCH_ASSOC);
        ksort($expected);
        ksort($actual);
        if ($expected !== $actual) {
            throw new RuntimeException('Migration changed measurements when column order differs: ' . $table);
        }
    }
    $database->exec('DELETE FROM health_days');
    try {
        $database->exec($sql);
        throw new RuntimeException('Migration accepted conflicting measurements.');
    } catch (PDOException $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
            throw new RuntimeException(
                'Migration did not roll back its first table on a conflict.',
                previous: $exception
            );
        }
    }
    if ((int) $database->query('SELECT COUNT(*) FROM health_days')->fetchColumn() !== 0) {
        throw new RuntimeException('Migration committed only part of the health data.');
    }
} finally {
    if ($database->inTransaction()) {
        $database->rollBack();
    }
    foreach (glob($root . '/.data/*') as $file) {
        unlink($file);
    }
    unlink($legacyFile);
    rmdir($root . '/.data');
    rmdir($root);
}
echo "Documented migration preserves column mappings and rolls back conflicts\n";
