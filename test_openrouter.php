<?php
require_once __DIR__ . '/config/openrouter.php';

$api_key = OPENROUTER_API_KEY;
$url     = OPENROUTER_API_URL;
$model   = OPENROUTER_MODEL;

echo "API Key: " . substr($api_key, 0, 20) . "...<br>";
echo "URL: $url<br>";
echo "Model: $model<br><br>";

$payload = json_encode([
    'model'    => $model,
    'messages' => [['role' => 'user', 'content' => 'Say hello in one sentence.']],
    'max_tokens' => 50
]);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $api_key,
    'HTTP-Referer: http://localhost/NutriTrack',
    'X-Title: NutriTrack'
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);

$output    = curl_exec($ch);
$curl_err  = curl_error($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $http_code<br>";
echo "Curl Error: " . ($curl_err ?: 'none') . "<br>";
echo "Response: <pre>" . htmlspecialchars($output) . "</pre>";
