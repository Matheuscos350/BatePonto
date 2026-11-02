<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

// Endereço (Google Geocoding) para um par lat/lng — usado na tela de ponto. A chave nunca vai para o navegador.
header('Content-Type: application/json; charset=utf-8');
if (!current_user()) { http_response_code(401); exit(json_encode(['ok' => false])); }
$lat = filter_var($_GET['lat'] ?? null, FILTER_VALIDATE_FLOAT);
$lng = filter_var($_GET['lng'] ?? null, FILTER_VALIDATE_FLOAT);
if ($lat === false || $lng === false || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) exit(json_encode(['ok' => false]));
$a = reverse_geocode((float)$lat, (float)$lng);
echo json_encode(['ok' => $a !== null, 'address' => $a], JSON_UNESCAPED_UNICODE);
