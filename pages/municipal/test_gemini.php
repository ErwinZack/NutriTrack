<?php
require_once "../../config/openrouter.php";

$api_key = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
$url = defined('GEMINI_API_URL') ? GEMINI_API_URL : 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent';

$payload = json_encode([
    'contents' => [
        ['parts' => [['text' => 'Say hello in one sentence.']]]
    ],
    'generationConfig' => [
        'maxOutputTokens' => 100,
        'temperature' => 0.4
    ]
]);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'x-goog-api-key: ' . $api_key
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$output = curl_exec($ch);
$curl_err = curl_error($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<h2>Test Results</h2>";
echo "<p><strong>API Key:</strong> " . substr($api_key, 0, 10) . "..." . "</p>";
echo "<p><strong>URL:</strong> " . $url . "</p>";
echo "<p><strong>HTTP Code:</strong> " . $http_code . "</p>";
echo "<p><strong>CURL Error:</strong> " . ($curl_err ?: 'None') . "</p>";
echo "<h3>Raw Response:</h3>";
echo "<pre>" . htmlspecialchars($output) . "</pre>";
?>