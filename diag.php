<?php
// Diagnostic temporaire — supprimer après usage
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
        CURLOPT_TIMEOUT        => 30,
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
    curl_close($ch);
    return ['http' => $http, 'body' => json_decode($res, true) ?? $res];
}

$results = [];

// Essaie les endpoints de listing d'hébergements
foreach (['/reseller/n0c-accounts', '/reseller/hosting', '/n0c/accounts', '/hosting/accounts'] as $ep) {
    $r = ph('GET', $ep);
    if ($r['http'] < 400) {
        $results[$ep] = $r;
        break;
    }
    $results[$ep] = ['http' => $r['http'], 'err' => $r['body']['error'] ?? '?'];
}

// Teste directement l'ID 113185 avec formails.fr
$results['test_113185_formails'] = ph('GET', '/hosting/emails?id=113185&domain=formails.fr');

// Teste avec neomails.fr pour comparer
$results['test_113185_neomails'] = ph('GET', '/hosting/emails?id=113185&domain=neomails.fr');

// Essaie quelques IDs proches
foreach ([113184, 113186, 113187, 113188] as $id) {
    $r = ph('GET', '/hosting/emails?id=' . $id . '&domain=formails.fr');
    if ($r['http'] === 200) {
        $results['found_id_' . $id] = $r;
    }
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
