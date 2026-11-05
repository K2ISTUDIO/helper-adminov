<?php
$out = [];
$fb = '/home/atqadcksav/.n0c/filebrowser';

// Lire s3_config.json
$s3 = @file_get_contents("$fb/s3_config.json");
$out['s3_config'] = $s3 ? json_decode($s3, true) : null;

// Lire filebrowser.log (fin)
$log = @file_get_contents("$fb/filebrowser.log");
$out['log_tail'] = $log ? substr($log, -1000) : null;

// Lire filebrowser.db via SQLite3
if (class_exists('SQLite3')) {
    try {
        $db = new SQLite3("$fb/filebrowser.db", SQLITE3_OPEN_READONLY);
        // Lister les tables
        $res = $db->query("SELECT name FROM sqlite_master WHERE type='table'");
        $tables = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) $tables[] = $row['name'];
        $out['db_tables'] = $tables;
        // Lire chaque table
        foreach ($tables as $t) {
            $r2 = $db->query("SELECT * FROM \"$t\" LIMIT 10");
            while ($row = $r2->fetchArray(SQLITE3_ASSOC)) {
                $out['db'][$t][] = $row;
            }
        }
        $db->close();
    } catch (Exception $e) {
        $out['db_error'] = $e->getMessage();
    }
} else {
    // Fallback: lire le fichier binaire et chercher des patterns
    $raw = @file_get_contents("$fb/filebrowser.db");
    if ($raw) {
        preg_match_all('/[a-z0-9]{6,}/', $raw, $m);
        $out['db_strings'] = array_unique(array_filter($m[0], fn($s) => strlen($s) > 5));
    }
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
