<?php
$out = [];
$n0c = '/home/atqadcksav/.n0c';

foreach (['filebrowser', 'terminal'] as $sub) {
    $d = "$n0c/$sub";
    $scan = @scandir($d) ?: [];
    foreach ($scan as $f) {
        if ($f === '.' || $f === '..') continue;
        $fp = "$d/$f";
        $stat = @stat($fp);
        $type = $stat ? sprintf('%o', $stat['mode']) : '?';
        // Lire seulement si c'est un vrai fichier régulier (mode & 0170000 == 0100000)
        $content = null;
        if ($stat && ($stat['mode'] & 0170000) === 0100000 && $stat['size'] < 100000) {
            $content = @file_get_contents($fp);
        }
        $out[$sub][$f] = ['mode' => $type, 'size' => $stat['size'] ?? 0, 'content' => $content];
    }
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
