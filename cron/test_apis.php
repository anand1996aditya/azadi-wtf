<?php
require_once __DIR__ . '/../config.php';

echo "Testing Tavily...\n";
$ch = curl_init(TAVILY_API_URL);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode([
        'api_key' => TAVILY_API_KEY,
        'query' => 'Delhi protest latest news',
        'max_results' => 2,
        'include_answer' => false,
    ]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
]);
$r = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);
echo "Tavily HTTP $code\n";
if ($err) echo "Error: $err\n";
else echo "Response: " . substr($r, 0, 200) . "\n\n";

echo "Testing DeepSeek...\n";
$ch = curl_init(DEEPSEEK_API_URL);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode([
        'model' => DEEPSEEK_MODEL,
        'messages' => [['role' => 'user', 'content' => 'Say hello in one word']],
        'max_tokens' => 10,
    ]),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . DEEPSEEK_API_KEY,
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
]);
$r = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);
echo "DeepSeek HTTP $code\n";
if ($err) echo "Error: $err\n";
else echo "Response: " . substr($r, 0, 200) . "\n";
