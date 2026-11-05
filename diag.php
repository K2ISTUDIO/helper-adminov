<?php
header('Content-Type: application/json');

$out = [];

// Infos serveur / environnement
$out['server_name']     = $_SERVER['SERVER_NAME'] ?? 'n/a';
$out['document_root']   = $_SERVER['DOCUMENT_ROOT'] ?? 'n/a';
$out['home']            = getenv('HOME') ?: 'n/a';
$out['user']            = get_current_user();
$out['php_user']        = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : 'posix_n/a';

// Essai lecture de fichiers N0C
$candidates = [
    '/etc/trueuserdomains',
    '/etc/userdomains',
    '/etc/trueuserowners',
    getenv('HOME') . '/.cpanel/json-api/userid',
    getenv('HOME') . '/.cpanel/nvdata/userid',
    '/var/cpanel/users/' . get_current_user(),
    getenv('HOME') . '/.my.cnf',
    getenv('HOME') . '/etc/main_domain',
];
foreach ($candidates as $f) {
    if (file_exists($f) && is_readable($f)) {
        $out['files'][$f] = substr(file_get_contents($f), 0, 500);
    }
}

// Cherche un ID numérique dans les vars d'environnement
foreach (['CPANEL', 'USER', 'LOGNAME', 'N0C_ACCOUNT_ID', 'ACCOUNT_ID'] as $var) {
    $v = getenv($var);
    if ($v !== false) $out['env'][$var] = $v;
}

// API N0C depuis le serveur lui-même (IP whitelist)
$api_user = 'ca090d4c75f80521945812f3968b3df9';
$api_key  = 'f22301c7f17e2d7333cf553230cab99973da80a0b826396f7953d5375fa51859';

// Recherche par username N0C via v3
$username = get_current_user();  // 'atqadcksav'
foreach ([
    "/v3/hosting/emails?username=$username",
    "/v3/n0c-account?username=$username",
    "/v3/hosting/info?username=$username",
] as $ep) {
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
    $out['api_by_username'][$ep] = ['http' => $h, 'body' => json_decode($r, true) ?? $r];
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
