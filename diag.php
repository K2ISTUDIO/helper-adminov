<?php
header('Content-Type: application/json');

$api_user = 'ca090d4c75f80521945812f3968b3df9';
$api_key  = 'f22301c7f17e2d7333cf553230cab99973da80a0b826396f7953d5375fa51859';

function ph($version, $path) {
    global $api_user, $api_key;
    $ch = curl_init("https://api.planethoster.net/$version$path");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
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

// v4 : liste tous les comptes hébergement avec leurs IDs
$r = ph('v4', '/hostings');
$out['v4_hostings'] = ['http' => $r['http'], 'body' => $r['body']];

// v4 : infos The World (sous-comptes)
$r = ph('v4', '/the-world/info');
$out['v4_the_world_info'] = ['http' => $r['http'], 'body' => $r['body']];

// v4 : infos compte revendeur
$r = ph('v4', '/account/info');
$out['v4_account_info'] = ['http' => $r['http'], 'body' => $r['body']];

// v3 (ancien) : test avec le bon endpoint email pour vérifier que les creds marchent
$r = ph('v3', '/hosting/emails?id=113185');
$out['v3_sanity_113185'] = ['http' => $r['http'], 'ok' => ($r['http'] === 200)];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
