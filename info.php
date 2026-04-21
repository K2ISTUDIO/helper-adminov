<?php
error_reporting(E_ALL);
ini_set('display_errors','1');
echo '<pre>';
echo 'PHP: ' . PHP_VERSION . "\n";
echo 'PDO drivers: ' . implode(', ', PDO::getAvailableDrivers()) . "\n";

// Test session
session_start();
$_SESSION['test'] = 1;
echo 'Session: OK' . "\n";

// Test SQLite
$dbpath = dirname($_SERVER['DOCUMENT_ROOT']) . '/test_adminov.db';
echo 'DB path: ' . $dbpath . "\n";
try {
    $db = new PDO('sqlite:' . $dbpath);
    $db->exec('CREATE TABLE IF NOT EXISTS t (id INTEGER)');
    echo 'SQLite: OK' . "\n";
    unlink($dbpath);
} catch (Exception $e) {
    echo 'SQLite ERROR: ' . $e->getMessage() . "\n";
}

// Test redirect (sans l'executer)
echo 'void return type: ';
function test_void(): void {}
test_void();
echo "OK\n";

echo 'const array: ';
const TEST_ARR = ['a','b'];
echo TEST_ARR[0] . "\n";

echo '</pre>ALL OK';
