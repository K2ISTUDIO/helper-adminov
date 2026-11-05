<?php
set_time_limit(60);
header('Content-Type: application/json');

$api_user = 'ca090d4c75f80521945812f3968b3df9';
$api_key  = 'f22301c7f17e2d7333cf553230cab99973da80a0b826396f7953d5375fa51859';
$base     = 'https://api.planethoster.net/v3';

function ph($method, $path, $body = []) {
    global $api_user, $api_key, $base;
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => [
            'X-API-USER: ' . $api_user,
            'X-API-KEY: '  . $api_key,
            'Accept: application/json',
            'Content-Type: application/json',
        ],
    ]);
    if ($body) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $res  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['http' => $http, 'body' => json_decode($res, true) ?? $res, 'err' => $err ?: null];
}

$results = [];
$username = 'atqadcksav'; // username N0C de formails.fr

// Endpoints qui acceptent un username
$endpoints_with_user = [
    "/hosting/account?username=$username",
    "/hosting/info?username=$username",
    "/n0c/account?username=$username",
    "/n0c/account?user=$username",
    "/hosting?username=$username",
    "/reseller/hosting?username=$username",
];

foreach ($endpoints_with_user as $ep) {
    $r = ph('GET', $ep);
    $results['by_username_' . basename(strtok($ep, '?'))] = ['ep' => $ep, 'http' => $r['http'], 'body' => $r['body']];
}

// Endpoint qui liste tous les comptes du revendeur
foreach (['/n0c/accounts', '/hosting/accounts', '/reseller/accounts', '/world/n0c/accounts'] as $ep) {
    $r = ph('GET', $ep);
    $results['list_' . basename($ep)] = ['http' => $r['http'], 'body' => $r['http'] < 400 ? $r['body'] : 'error'];
}

// Scan ciblé autour des IDs connus (113185=neomails, 113188=adminov)
// Cherche un compte qui a le username atqadcksav
// On teste l'endpoint /hosting/emails, en cherchant formails.fr dans la réponse
$scan_found = null;
$ids_to_try = array_merge(
    range(113150, 113250),
    range(112000, 112100),
    range(114000, 114100)
);

foreach ($ids_to_try as $id) {
    if ($id === 113185 || $id === 113188) continue;
    $r = ph('GET', "/hosting/emails?id=$id&domain=formails.fr");
    if ($r['http'] === 200 && isset($r['body']['success']) && $r['body']['success']) {
        // Vérifie si ce compte a formails.fr
        $data = $r['body']['data'] ?? [];
        $has_formails = false;
        foreach ($data as $email) {
            if (isset($email['domain']) && stripos($email['domain'], 'formails') !== false) {
                $has_formails = true;
                break;
            }
        }
        // Même si vide, le compte existe — log-le
        if ($has_formails) {
            $scan_found = ['id' => $id, 'data' => $r['body']];
            break;
        }
        // Compte qui existe mais sans emails formails — pourrait être le bon (vide)
        $results["account_exists_$id"] = ['emails_count' => count($data), 'sample_domain' => $data[0]['domain'] ?? 'none'];
    }
}

if ($scan_found) {
    $results['FORMAILS_ID_FOUND'] = $scan_found;
} else {
    $results['scan_note'] = 'Pas trouvé dans la plage scannée';
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
