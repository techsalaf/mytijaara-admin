<?php
$ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'model' => 'llama-3.3-70b-versatile',
    'messages' => [['role' => 'user', 'content' => 'hi']]
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer test'
]);
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo $code . " -> " . $res . "\n";
