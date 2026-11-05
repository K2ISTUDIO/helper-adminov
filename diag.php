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

// Essaie des endpoints de liste de comptes N0C
foreach ([
    '/n0c/accounts',
    '/n0c/account/list',
    '/reseller/n0c/accounts',
    '/world/accounts',
    '/hosting/list',
    '/account/list',
] as $ep) {
    $r = ph('GET', $ep);
    if ($r['http'] < 400) {
        $results['listing_' . str_replace('/', '_', $ep)] = $r;
    } else {
        $results['listing_' . str_replace('/', '_', $ep)] = ['http' => $r['http']];
    }
}

// Scan de plages d'IDs — cherche formails.fr
$scan_ranges = [
    // Plage proche de neomails (113185)
    range(113100, 113200),
    // Plages plus lointaines — node19-ca est canadien, IDs peut-être différents
    range(50000, 50050),
    range(80000, 80050),
    range(150000, 150050),
    range(200000, 200050),
    range(1000, 1100),
];

$found = null;
foreach ($scan_ranges as $range) {
    foreach ($range as $id) {
        if ($id === 113185) continue; // déjà testé
        $r = ph('GET', '/hosting/emails?id=' . $id . '&domain=formails.fr');
        if ($r['http'] === 200 && isset($r['body']['success']) && $r['body']['success']) {
            // Vérifie si ce compte a formails.fr
            $has_formails = false;
            if (!empty($r['body']['data'])) {
                foreach ($r['body']['data'] as $email) {
                    if (strpos($email['domain'], 'formails') !== false) {
                        $has_formails = true;
                        break;
                    }
                }
            }
            if ($has_formails) {
                $found = ['id' => $id, 'data' => $r['body']];
                break 2;
            }
            // Compte existe mais pas de formails — log quand même si pas trop d'entrées
            if (empty($r['body']['data'])) {
                $results['empty_account_' . $id] = ['exists' => true, 'domain_count' => 0];
            }
        }
    }
}

if ($found) {
    $results['FORMAILS_ACCOUNT_FOUND'] = $found;
} else {
    $results['scan_result'] = 'not found in scanned ranges';
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
