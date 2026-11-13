<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=300');

function rawgJsonResponse(array $body, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode(
        $body,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    rawgJsonResponse(['error' => 'Only GET requests are supported.'], 405);
}

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    rawgJsonResponse(['error' => 'Admin access is required.'], 403);
}

$apiKey = getenv('RAWG_API_KEY');
if ($apiKey === false || $apiKey === '') {
    rawgJsonResponse(['error' => 'Game search is not configured. Set RAWG_API_KEY on the server.'], 503);
}

$search = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($search) > 80) {
    rawgJsonResponse(['error' => 'Search must be 80 characters or fewer.'], 400);
}

$query = [
    'key' => $apiKey,
    'page_size' => 12,
    'ordering' => '-rating',
];
if ($search !== '') {
    $query['search'] = $search;
}

$client = curl_init('https://api.rawg.io/api/games?' . http_build_query($query));
if ($client === false) {
    rawgJsonResponse(['error' => 'Game search is temporarily unavailable.'], 502);
}

curl_setopt_array($client, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 12,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
]);
$response = curl_exec($client);
$statusCode = (int) curl_getinfo($client, CURLINFO_RESPONSE_CODE);
$curlErrorCode = curl_errno($client);
curl_close($client);

if (!is_string($response) || $statusCode < 200 || $statusCode >= 300) {
    error_log('GameGrid RAWG request failed with HTTP status ' . $statusCode . ' and cURL code ' . $curlErrorCode . '.');
    rawgJsonResponse(['error' => 'Game search is temporarily unavailable. Please try again.'], 502);
}

try {
    $payload = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    error_log('GameGrid RAWG returned invalid JSON: ' . $exception->getMessage());
    rawgJsonResponse(['error' => 'Game search returned an invalid response. Please try again.'], 502);
}

if (!is_array($payload) || !is_array($payload['results'] ?? null)) {
    error_log('GameGrid RAWG returned an unexpected response shape.');
    rawgJsonResponse(['error' => 'Game search returned an invalid response. Please try again.'], 502);
}

$games = [];
foreach ($payload['results'] as $game) {
    if (!is_array($game) || !isset($game['name']) || !is_string($game['name'])) {
        continue;
    }

    $platforms = [];
    $gamePlatforms = is_array($game['platforms'] ?? null) ? $game['platforms'] : [];
    foreach ($gamePlatforms as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $platformName = $entry['platform']['name'] ?? null;
        if (is_string($platformName) && $platformName !== '') {
            $platforms[] = mb_substr($platformName, 0, 50);
        }
    }

    $games[] = [
        'name' => mb_substr($game['name'], 0, 150),
        'released' => isset($game['released']) && is_string($game['released']) ? $game['released'] : '',
        'rating' => isset($game['rating']) && is_numeric($game['rating']) ? (float) $game['rating'] : null,
        'platforms' => array_values(array_unique($platforms)),
    ];
}

rawgJsonResponse(['results' => $games]);
