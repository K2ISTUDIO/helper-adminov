<?php
set_time_limit(120);
header('Content-Type: application/json');

$api_user = 'ca090d4c75f80521945812f3968b3df9';
$api_key  = 'f22301c7f17e2d7333cf553230cab99973da80a0b826396f7953d5375fa51859';
$base     = 'https://api.planethoster.net/v3';
$known    = [113185, 113188];

function ph_get($path) {
    global $api_user, $api_key, $base;
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'GET',
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => ['X-API-USER: '.$api_user, 'X-API-KEY: '.$api_key, 'Accept: application/json'],
    ]);
    $res  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['http' => $http, 'body' => json_decode($res, true)];
}

function ph_batch(array $ids): array {
    global $api_user, $api_key, $base;
    $mh = curl_multi_init();
    $hs = [];
    foreach ($ids as $id) {
        $ch = curl_init($base . "/hosting/emails?id=$id");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'GET',
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => ['X-API-USER: '.$api_user, 'X-API-KEY: '.$api_key, 'Accept: application/json'],
        ]);
        curl_multi_add_handle($mh, $ch);
        $hs[$id] = $ch;
    }
    do { curl_multi_exec($mh, $r); curl_multi_select($mh, 0.1); } while ($r > 0);
    $out = [];
    foreach ($hs as $id => $ch) {
        $res  = curl_multi_getcontent($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        if ($http === 200) {
            $b = json_decode($res, true);
            if (!empty($b['success'])) {
                $data = $b['data'] ?? [];
                $dom  = $data[0]['domain'] ?? ($data[0]['email'] ? explode('@', $data[0]['email'])[1] : 'empty');
                $out[$id] = ['domain' => $dom, 'count' => count($data)];
            }
        }
    }
    curl_multi_close($mh);
    return $out;
}

$output = [];
$t0     = microtime(true);

// ── Phase 0 : endpoints par domaine (sans id) ─────────────
$domain_eps = [
    '/hosting/emails?domain=formails.fr',
    '/hosting/account?domain=formails.fr',
    '/n0c/account?domain=formails.fr',
    '/hosting/domain?domain=formails.fr',
    '/n0c/domain?domain=formails.fr',
    '/hosting/emails',           // sans id ni domain
    '/hosting/account',
];
foreach ($domain_eps as $ep) {
    $r = ph_get($ep);
    if ($r['http'] === 200 && !empty($r['body'])) {
        $output['domain_ep_' . preg_replace('/\W+/', '_', $ep)] = ['ep' => $ep, 'body' => $r['body']];
    }
}

// ── Phase 1 : scan range configurable ─────────────────────
$from = isset($_GET['from']) ? (int)$_GET['from'] : 118440;  // reprend là où on s'est arrêté
$to   = isset($_GET['to'])   ? (int)$_GET['to']   : ($from + 20000);
$step = isset($_GET['step']) ? (int)$_GET['step']  : 1;
$sz   = 20;

$found    = [];
$formails = null;

for ($s = $from; $s <= $to; $s += $sz * $step) {
    $batch = [];
    for ($i = 0; $i < $sz; $i++) {
        $id = $s + $i * $step;
        if ($id > $to) break;
        if (!in_array($id, $known)) $batch[] = $id;
    }
    if (empty($batch)) continue;
    $res = ph_batch($batch);
    foreach ($res as $id => $info) {
        $found[$id] = $info;
        if (stripos($info['domain'], 'formails') !== false) {
            $formails = $id;
            $output['FORMAILS_ID_FOUND'] = ['id' => $id, 'domain' => $info['domain']];
        }
    }
    if ($formails) break;
    if (microtime(true) - $t0 > 100) { $output['stopped_at'] = $s + $sz * $step; break; }
}

$output['all_accounts_found'] = $found;
$output['elapsed_s']          = round(microtime(true) - $t0, 1);
$output['range_scanned']      = "$from–$to step $step";
$output['next_ranges']        = [
    '118440-200000' => '?from=118440&to=200000',
    '50000-110000'  => '?from=50000&to=110000',
    '1-50000'       => '?from=1&to=50000',
    '200000-300000' => '?from=200000&to=300000',
    'step10 large'  => '?from=100000&to=500000&step=10',
];

echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
