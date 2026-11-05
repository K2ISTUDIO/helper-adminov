<?php
set_time_limit(120);
header('Content-Type: application/json');

$api_user = 'ca090d4c75f80521945812f3968b3df9';
$api_key  = 'f22301c7f17e2d7333cf553230cab99973da80a0b826396f7953d5375fa51859';
$base     = 'https://api.planethoster.net/v3';
$known    = [113185, 113188]; // neomails=113185, adminov=113188 — skip

function ph($path) {
    global $api_user, $api_key, $base;
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'GET',
        CURLOPT_TIMEOUT        => 4,
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
    return ['http' => $http, 'body' => json_decode($res, true)];
}

$results = [];
$t0      = microtime(true);

// ── Phase 0 : scan pas 1 de 113000 à 115000 ──────────────
// On sait que neomails=113185, adminov=113188. Formails.fr devrait
// être dans la même plage si créé via le même revendeur.
$formails_id = null;
$all_found   = [];

for ($id = 112500; $id <= 115500; $id++) {
    if (in_array($id, $known)) continue;

    $r = ph("/hosting/emails?id=$id");
    if ($r['http'] !== 200 || !isset($r['body']['success']) || !$r['body']['success']) continue;

    $data   = $r['body']['data'] ?? [];
    $domain = $data[0]['domain'] ?? ($data[0]['email'] ? explode('@', $data[0]['email'])[1] : 'empty');
    $count  = count($data);
    $all_found[$id] = ['domain' => $domain, 'count' => $count];

    if (stripos($domain, 'formails') !== false || $count === 0) {
        // Compte formails.fr (ou compte vide = candidat)
        $results['CANDIDATE'] = ['id' => $id, 'domain' => $domain, 'emails' => $count, 'sample' => array_slice($data, 0, 2)];
        if (stripos($domain, 'formails') !== false) {
            $formails_id = $id;
            $results['FORMAILS_ID_FOUND'] = $id;
            break;
        }
    }

    if (microtime(true) - $t0 > 100) {
        $results['stopped_at'] = $id;
        break;
    }
}

$results['all_accounts_found'] = $all_found;
$results['elapsed_s']          = round(microtime(true) - $t0, 1);
$results['range_scanned']      = '112500-115500 step 1';

if (!$formails_id && empty($all_found)) {
    // Aucun compte trouvé dans ce range — essayer plage 2
    $results['note'] = 'Aucun compte dans 112500-115500. Formails.fr pourrait etre dans un autre range (serveur node19-ca different). Ajoutez ?range=2 pour scanner 50000-80000.';

    if (isset($_GET['range']) && $_GET['range'] === '2') {
        $results['range2_scan'] = 'scanning 50000-80000 step 2...';
        $found2 = [];
        for ($id = 50000; $id <= 80000; $id += 2) {
            if (in_array($id, $known)) continue;
            $r = ph("/hosting/emails?id=$id");
            if ($r['http'] === 200 && isset($r['body']['success']) && $r['body']['success']) {
                $data  = $r['body']['data'] ?? [];
                $dom   = $data[0]['domain'] ?? 'empty';
                $found2[$id] = ['domain' => $dom, 'count' => count($data)];
                if (stripos($dom, 'formails') !== false) {
                    $results['FORMAILS_ID_FOUND'] = $id;
                    break;
                }
            }
            if (microtime(true) - $t0 > 110) { $results['range2_stopped_at'] = $id; break; }
        }
        $results['range2_accounts'] = $found2;
        $results['elapsed_s']       = round(microtime(true) - $t0, 1);
    }
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
