<?php
set_time_limit(110);
header('Content-Type: application/json');

$api_user = 'ca090d4c75f80521945812f3968b3df9';
$api_key  = 'f22301c7f17e2d7333cf553230cab99973da80a0b826396f7953d5375fa51859';
$base     = 'https://api.planethoster.net/v3';
$username = 'atqadcksav';
$known    = [113185, 113188]; // neomails, adminov

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

function check_id($id) {
    // Returns: null=not found, []= found but 0 emails, [domain=>..., count=>N]=found with emails
    $r = ph("/hosting/emails?id=$id");
    if ($r['http'] !== 200 || !isset($r['body']['success']) || !$r['body']['success']) return null;
    $data   = $r['body']['data'] ?? [];
    $domain = $data[0]['domain'] ?? 'none';
    return ['domain' => $domain, 'count' => count($data), 'emails_sample' => array_slice(array_column($data, 'email'), 0, 3)];
}

$results  = [];
$t0       = microtime(true);

// ── Phase 0 : endpoints par username ──────────────────────
foreach ([
    "/hosting/account?username=$username",
    "/hosting/info?username=$username",
    "/n0c/account?username=$username",
    "/n0c/account?user=$username",
    "/world/n0c/account?username=$username",
    "/world/hosting/account?username=$username",
    "/reseller/hosting?username=$username",
] as $ep) {
    $r = ph($ep);
    if ($r['http'] === 200) {
        $results['username_HIT'] = ['ep' => $ep, 'body' => $r['body']];
        break;
    }
}

// ── Phase 1 : scan large pas 10000 (0 → 2 000 000) ───────
// Cherche un compte dont les emails ont domain=formails.fr
$hit10k    = null;
$zero_cands = [];
for ($id = 10000; $id <= 2000000; $id += 10000) {
    if (in_array($id, $known)) continue;
    $info = check_id($id);
    if ($info === null) continue; // pas de compte
    if (stripos($info['domain'], 'formails') !== false) {
        $results['FORMAILS_ID_FOUND'] = ['id' => $id, 'info' => $info];
        $hit10k = $id;
        break;
    }
    if ($info['count'] === 0) {
        $zero_cands[] = $id; // compte vide, candidat possible
    }
    if (microtime(true) - $t0 > 55) {
        $results['phase1_stopped_at'] = $id;
        break;
    }
}

// ── Phase 2 : affine dans le bloc trouvé (pas 1000) ───────
if ($hit10k && !isset($results['FORMAILS_ID_FOUND'])) {
    for ($id = max(1, $hit10k - 10000); $id <= $hit10k + 10000; $id += 1000) {
        if (in_array($id, $known)) continue;
        $info = check_id($id);
        if ($info && stripos($info['domain'], 'formails') !== false) {
            $results['FORMAILS_ID_FOUND'] = ['id' => $id, 'info' => $info];
            break;
        }
        if (microtime(true) - $t0 > 75) break;
    }
}

// ── Phase 3 : pin-point final (pas 1) ─────────────────────
if (isset($results['FORMAILS_ID_FOUND']) && !is_array($results['FORMAILS_ID_FOUND']['id'] ?? null)) {
    $approx = $results['FORMAILS_ID_FOUND']['id'];
    for ($id = max(1, $approx - 1000); $id <= $approx + 1000; $id++) {
        if (in_array($id, $known)) continue;
        $info = check_id($id);
        if ($info && stripos($info['domain'], 'formails') !== false) {
            $results['FORMAILS_ID_EXACT'] = ['id' => $id, 'info' => $info];
            break;
        }
        if (microtime(true) - $t0 > 95) break;
    }
}

$results['zero_email_accounts'] = $zero_cands;
$results['elapsed_s']           = round(microtime(true) - $t0, 1);

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
