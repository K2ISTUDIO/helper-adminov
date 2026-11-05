<?php
header('Content-Type: application/json');
set_time_limit(120);

$out = [];
$home = '/home/atqadcksav';
$user = 'atqadcksav';
$out['uid'] = 1687;

// Lecture fichiers config N0C / cPanel
$candidates = [
    '/etc/trueuserdomains',
    '/etc/userdomains',
    '/var/cpanel/users/' . $user,
    $home . '/etc/main_domain',
    $home . '/.cpanel/json-api/userid',
    $home . '/.cpanel/nvdata/userid',
    $home . '/.my.cnf',
    $home . '/.n0c/account',
    $home . '/.n0c_id',
];
foreach ($candidates as $f) {
    if (@file_exists($f) && @is_readable($f)) {
        $out['files'][$f] = substr(@file_get_contents($f), 0, 300);
    } else {
        $out['missing'][] = $f;
    }
}

// Liste fichiers cachés dans le home
$hidden = @glob($home . '/.*');
$out['home_hidden'] = $hidden ?: [];

// Env vars
foreach (array_keys($_SERVER) as $k) {
    if (preg_match('/id|cpanel|n0c|account/i', $k)) {
        $out['env'][$k] = $_SERVER[$k];
    }
}

// API v3 - endpoints listing/reseller (peut-être sans ID)
$api_user = 'ca090d4c75f80521945812f3968b3df9';
$api_key  = 'f22301c7f17e2d7333cf553230cab99973da80a0b826396f7953d5375fa51859';

function ph1($ep, $api_user, $api_key) {
    $ch = curl_init("https://api.planethoster.net$ep");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => [
            'X-API-USER: ' . $api_user,
            'X-API-KEY: '  . $api_key,
        ],
    ]);
    $r = curl_exec($ch);
    $h = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['http' => $h, 'body' => json_decode($r, true) ?? $r];
}

// On attend 65 secondes pour laisser le rate limit se réinitialiser
sleep(65);

// Maintenant on a 10 requêtes disponibles
$endpoints = [
    '/v3/reseller/accounts',
    '/v3/reseller/n0c-accounts',
    '/v3/hosting/list',
    "/v3/hosting/emails?username=$user",
    "/v3/hosting/account?username=$user",
    "/v3/n0c-hosting?username=$user",
];
foreach ($endpoints as $ep) {
    $r = ph1($ep, $api_user, $api_key);
    $out['api'][$ep] = $r;
    // Si on trouve un compte, on s'arrête
    if (isset($r['body']['id']) || isset($r['body']['accounts'])) break;
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
