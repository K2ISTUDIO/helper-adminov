<?php
header('Content-Type: application/json');
$api_user = 'ca090d4c75f80521945812f3968b3df9';
$api_key  = 'f22301c7f17e2d7333cf553230cab99973da80a0b826396f7953d5375fa51859';
$out = [];
foreach ([105469, 191406, 101563] as $id) {
    $ch = curl_init("https://api.planethoster.net/v3/hosting/emails?id=$id&limit=3");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => ["X-API-USER: $api_user", "X-API-KEY: $api_key"],
    ]);
    $r = curl_exec($ch);
    $h = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $out[$id] = ['http' => $h, 'body' => json_decode($r, true) ?? $r];
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
