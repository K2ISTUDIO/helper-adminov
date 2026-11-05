<?php
header('Content-Type: application/json');
$out = [];

$home = '/home/atqadcksav';
$n0c  = $home . '/.n0c';

// Permissions du dossier .n0c
$out['n0c_exists']     = file_exists($n0c);
$out['n0c_is_dir']     = is_dir($n0c);
$out['n0c_readable']   = is_readable($n0c);
$out['n0c_perms']      = decoct(fileperms($n0c) & 0777);

// Contenu de .n0c via scandir (sans récursion)
$scan = @scandir($n0c);
$out['n0c_scandir'] = $scan ?: 'inaccessible';

// Lire chaque fichier dans .n0c
if (is_array($scan)) {
    foreach ($scan as $f) {
        if ($f === '.' || $f === '..') continue;
        $fp = "$n0c/$f";
        $out['n0c_contents'][$f] = [
            'type'     => is_dir($fp) ? 'dir' : 'file',
            'readable' => is_readable($fp),
            'size'     => @filesize($fp),
            'content'  => is_file($fp) && is_readable($fp) ? substr(@file_get_contents($fp), 0, 500) : null,
        ];
        // Si sous-répertoire, lister aussi
        if (is_dir($fp)) {
            $sub = @scandir($fp);
            $out['n0c_contents'][$f]['children'] = $sub ?: [];
            foreach ((array)$sub as $sf) {
                if ($sf === '.' || $sf === '..') continue;
                $sfp = "$fp/$sf";
                if (is_file($sfp) && is_readable($sfp)) {
                    $out['n0c_contents'][$f]['files'][$sf] = substr(@file_get_contents($sfp), 0, 300);
                }
            }
        }
    }
}

// Cherche aussi dans etc/
$etc = $home . '/etc';
if (is_dir($etc)) {
    $out['home_etc'] = @scandir($etc) ?: [];
    foreach ($out['home_etc'] as $f) {
        if ($f === '.' || $f === '..') continue;
        $fp = "$etc/$f";
        if (is_file($fp)) $out['home_etc_files'][$f] = substr(@file_get_contents($fp), 0, 200);
    }
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
