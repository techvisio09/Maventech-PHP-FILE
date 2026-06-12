<?php
// AI-powered review comment generator — uses Emergent LLM key (OpenAI-compatible).
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: application/json');

$rating = max(1, min(5, (int)($_GET['rating'] ?? 5)));
$product = trim($_GET['product'] ?? 'this product');

// Fallback template if no key
$fallback = [
    1 => "Unfortunately $product didn't meet my expectations. The activation process gave me trouble and customer support response was slow.",
    2 => "$product worked but the experience could be smoother. Setup took longer than I hoped and the documentation was a bit unclear.",
    3 => "$product is okay. It does the job, though installation took a few extra steps. Decent value for what you get.",
    4 => "Great experience with $product. Easy activation, key worked instantly, and the installation guide was clear. Would recommend.",
    5 => "Absolutely love $product! License key arrived in minutes, activation was seamless, and everything worked perfectly. Highly recommend to anyone looking for genuine software at a great price.",
];

if (!OPENAI_API_KEY) {
    echo json_encode(['comment' => $fallback[$rating], 'source' => 'template']);
    exit;
}

$prompt = "Write a short, authentic customer product review (2-3 sentences, ~40-60 words) for the software product \"$product\". The customer rated it $rating out of 5 stars. Match the tone to the rating: 1=disappointed, 3=mixed, 5=enthusiastic. Mention concrete details about the buying experience (license key delivery, activation, installation). Do not use markdown. First-person voice. Return ONLY the review text, no quotes.";

$ch = curl_init(rtrim(OPENAI_BASE_URL, '/') . '/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . OPENAI_API_KEY],
    CURLOPT_POSTFIELDS => json_encode([
        'model' => OPENAI_MODEL,
        'messages' => [['role'=>'user','content'=>$prompt]],
        'temperature' => 0.85,
        'max_tokens' => 200,
    ]),
    CURLOPT_TIMEOUT => 20,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($resp && $code >= 200 && $code < 300) {
    $d = json_decode($resp, true);
    $text = trim($d['choices'][0]['message']['content'] ?? '');
    if ($text !== '') { echo json_encode(['comment'=>$text, 'source'=>'ai']); exit; }
}
echo json_encode(['comment' => $fallback[$rating], 'source' => 'fallback', 'http' => $code]);
