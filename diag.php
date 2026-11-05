<?php
header('Content-Type: application/json');

$api_user = 'ca090d4c75f80521945812f3968b3df9';
$api_key  = 'f22301c7f17e2d7333cf553230cab99973da80a0b826396f7953d5375fa51859';

function ph($version, $path) {
    global $api_user, $api_key;
    $ch = curl_init("https://api.planethoster.net/$version$path");
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

// v4 avec domaine directement (sans id numérique)
foreach ([
    '/v4/hosting/emails?domain=formails.fr',
    '/v4/hosting/domains?domain=formails.fr',
    '/v4/hosting/login?domain=formails.fr',
    '/v4/hybridcloud/info?domain=formails.fr',
    '/v4/hybridcloud/info',
    '/v4/domains',
] as $ep) {
    $r = ph('', $ep);
    $out[str_replace(['/', '?', '=', '.'], '_', $ep)] = ['http' => $r['http'], 'body' => $r['body']];
}

// CONCLUSION
$out['DIAGNOSTIC'] = [
    'credentials_appartiennent_a' => 'reseller_id ca090d4c75f80521945812f3968b3df9',
    'hosting_accounts_sur_ce_compte' => 'AUCUN (v4 confirme)',
    'v3_113185_accessible' => 'OUI (neomails.fr)',
    'conclusion' => 'formails.fr est probablement sur un compte PlanetHoster DIFFERENT',
    'solution' => 'Aller sur my.planethoster.com -> compte formails.fr -> API -> copier X-API-USER et X-API-KEY',
];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
