<?php
header('Content-Type: application/json');
$api_user = 'ca090d4c75f80521945812f3968b3df9';
$api_key  = 'f22301c7f17e2d7333cf553230cab99973da80a0b826396f7953d5375fa51859';

function ph($url, $api_user, $api_key) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ["X-API-USER: $api_user", "X-API-KEY: $api_key"],
    ]);
    $r = curl_exec($ch);
    $h = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['http' => $h, 'body' => json_decode($r, true) ?? $r];
}

$out = [];

// Try listing ALL hosting accounts under this World Account
$out['v3_world_hostings']   = ph("https://api.planethoster.net/v3/the-world/hostings", $api_user, $api_key);
$out['v3_world_info']       = ph("https://api.planethoster.net/v3/the-world/info", $api_user, $api_key);
$out['v4_hostings']         = ph("https://api.planethoster.net/v4/hostings", $api_user, $api_key);
$out['v4_world_hostings']   = ph("https://api.planethoster.net/v4/the-world/hostings", $api_user, $api_key);

// Also try with formails.fr as domain param (v3)
$out['v3_domain_formails']  = ph("https://api.planethoster.net/v3/hosting/info?domain=formails.fr", $api_user, $api_key);

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
