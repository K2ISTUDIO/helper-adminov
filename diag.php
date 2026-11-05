<?php
header('Content-Type: application/json');

$api_user = 'ca090d4c75f80521945812f3968b3df9';
$api_key  = 'f22301c7f17e2d7333cf553230cab99973da80a0b826396f7953d5375fa51859';

function ph($path) {
    global $api_user, $api_key;
    $ch = curl_init("https://api.planethoster.net$path");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => [
            'X-API-USER: ' . $api_user,
            'X-API-KEY: '  . $api_key,
            'Accept: application/json',
        ],
    ]);
    $res  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['http' => $http, 'body' => json_decode($res, true) ?? $res];
}

$out = [];

// Essai 1 : endpoints v3 avec nom de domaine au lieu d'un ID numérique
foreach ([
    '/v3/hosting/info?domain=formails.fr',
    '/v3/hosting/emails?domain=formails.fr',
    '/v3/n0c-account?domain=formails.fr',
    '/v3/hosting/list',
    '/v3/n0c-accounts',
] as $ep) {
    $r = ph($ep);
    $key = str_replace(['/', '?', '=', '.'], '_', $ep);
    $out['v3_domain'][$key] = ['http' => $r['http'], 'body' => $r['body']];
}

// Essai 2 : scan curl_multi de 125000-130000 (IDs récents, formails créé 2026)
$start  = isset($_GET['from']) ? (int)$_GET['from'] : 125000;
$end    = isset($_GET['to'])   ? (int)$_GET['to']   : 130000;
$batch  = 20;
$known  = [113185, 113188];
$found  = [];
$t0     = microtime(true);

for ($base = $start; $base <= $end; $base += $batch) {
    $mh   = curl_multi_init();
    $hs   = [];
    $ids  = [];
    for ($i = 0; $i < $batch && ($base + $i) <= $end; $i++) {
        $id = $base + $i;
        if (in_array($id, $known)) continue;
        $ids[] = $id;
        $ch = curl_init("https://api.planethoster.net/v3/hosting/emails?id=$id&limit=1");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => [
                'X-API-USER: ' . $api_user,
                'X-API-KEY: '  . $api_key,
            ],
        ]);
        curl_multi_add_handle($mh, $ch);
        $hs[$id] = $ch;
    }
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running > 0);
    foreach ($hs as $id => $ch) {
        $res = curl_multi_getcontent($ch);
        $d   = json_decode($res, true);
        if (isset($d['emails']) || (isset($d['error_code']) && $d['error_code'] != 5002)) {
            $found[] = ['id' => $id, 'body' => $d];
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    if ((microtime(true) - $t0) > 90) {
        $out['scan_stopped_at'] = $base + $batch;
        break;
    }
}

$out['scan'] = [
    'range'       => "$start-$end",
    'found'       => $found,
    'elapsed_s'   => round(microtime(true) - $t0, 1),
];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
