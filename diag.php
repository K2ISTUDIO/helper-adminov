<?php
set_time_limit(120);
header('Content-Type: application/json');

$api_user = 'ca090d4c75f80521945812f3968b3df9';
$api_key  = 'f22301c7f17e2d7333cf553230cab99973da80a0b826396f7953d5375fa51859';
$base     = 'https://api.planethoster.net/v3';
$known    = [113185, 113188]; // neomails=113185, adminov=113188

// ── curl_multi batch ──────────────────────────────────────
function ph_batch(array $ids): array {
    global $api_user, $api_key, $base;
    $mh      = curl_multi_init();
    $handles = [];
    foreach ($ids as $id) {
        $ch = curl_init($base . "/hosting/emails?id=$id");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'GET',
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => [
                'X-API-USER: ' . $api_user,
                'X-API-KEY: '  . $api_key,
                'Accept: application/json',
            ],
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$id] = $ch;
    }
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.1);
    } while ($running > 0);

    $results = [];
    foreach ($handles as $id => $ch) {
        $res  = curl_multi_getcontent($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        if ($http === 200) {
            $body = json_decode($res, true);
            if (isset($body['success']) && $body['success']) {
                $data   = $body['data'] ?? [];
                $domain = $data[0]['domain'] ?? 'empty';
                $results[$id] = ['domain' => $domain, 'count' => count($data)];
            }
        }
    }
    curl_multi_close($mh);
    return $results;
}

$output = [];
$t0     = microtime(true);

// ── Plage principale : autour de neomails(113185) / adminov(113188) ──
// Scan 110000-120000 step 1 en batches de 20
$found     = [];
$formails  = null;
$batch_sz  = 20;
$range_min = isset($_GET['from']) ? (int)$_GET['from'] : 110000;
$range_max = isset($_GET['to'])   ? (int)$_GET['to']   : 120000;

for ($start = $range_min; $start <= $range_max; $start += $batch_sz) {
    $batch = [];
    for ($id = $start; $id < $start + $batch_sz && $id <= $range_max; $id++) {
        if (!in_array($id, $known)) $batch[] = $id;
    }
    if (empty($batch)) continue;

    $res = ph_batch($batch);
    foreach ($res as $id => $info) {
        $found[$id] = $info;
        if (stripos($info['domain'], 'formails') !== false) {
            $formails = $id;
            $output['FORMAILS_ID_FOUND'] = ['id' => $id, 'domain' => $info['domain'], 'emails' => $info['count']];
        }
    }
    if ($formails) break;
    if (microtime(true) - $t0 > 100) {
        $output['stopped_at'] = $start + $batch_sz - 1;
        break;
    }
}

$output['all_accounts_found'] = $found;
$output['elapsed_s']          = round(microtime(true) - $t0, 1);
$output['range']              = "$range_min-$range_max";
$output['hint']               = 'Passer ?from=X&to=Y pour changer la plage';

echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
