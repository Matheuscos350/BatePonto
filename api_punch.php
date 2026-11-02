<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

function fail(string $msg, int $code = 400): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = current_user();
if (!$user) fail('Sessão expirada. Faça login novamente.', 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método inválido.', 405);

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) fail('Dados inválidos.');
if (!hash_equals($_SESSION['csrf'] ?? '', (string)($in['csrf'] ?? ''))) fail('Token inválido. Recarregue a página.', 419);

// Tipo de marcação (valida a sequência: entrada → saída almoço → volta almoço → saída)
$last = last_punch((int)$user['id']);
$type = (string)($in['type'] ?? '');
if (!isset(PUNCH_LABELS[$type])) fail('Tipo de marcação inválido.');
if (!in_array($type, allowed_punch_types($last), true)) {
    $ok = implode(' ou ', array_map(fn($t) => PUNCH_LABELS[$t], allowed_punch_types($last)));
    fail("Marcação não permitida agora. Próxima: $ok. Recarregue a página.");
}

// Obra (o funcionário digita o nome; é resolvida/cadastrada só depois das demais validações)
if (strlen(trim((string)($in['site_name'] ?? ''))) < 2) fail('Informe o nome da obra.');

// Localização
$lat = filter_var($in['lat'] ?? null, FILTER_VALIDATE_FLOAT);
$lng = filter_var($in['lng'] ?? null, FILTER_VALIDATE_FLOAT);
$acc = filter_var($in['accuracy'] ?? 0, FILTER_VALIDATE_FLOAT);
if ($lat === false || $lng === false || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    fail('Localização inválida ou não informada.');
}

// Foto (data URL JPEG)
$photo = (string)($in['photo'] ?? '');
if (!preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#', $photo, $m)) fail('Foto inválida.');
$bin = base64_decode($m[1], true);
if ($bin === false || strlen($bin) > MAX_PHOTO_BYTES || strncmp($bin, "\xFF\xD8", 2) !== 0) fail('Foto inválida ou muito grande.');

// Evita duplo clique (menos de 30s desde a última marcação)
if ($last && time() - strtotime($last['punched_at']) < 30) fail('Aguarde alguns segundos antes de bater o ponto novamente.');

try {
    $site = resolve_site((string)($in['site_name'] ?? ''), (string)($in['site_address'] ?? ''), (int)$user['id']);
} catch (RuntimeException $e) {
    fail($e->getMessage());
}

if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0750, true);
$fname = sprintf('%d_%s_%s.jpg', $user['id'], date('Ymd_His'), bin2hex(random_bytes(4)));
file_put_contents(UPLOAD_DIR . '/' . $fname, $bin);

$geoAddr = reverse_geocode((float)$lat, (float)$lng);   // endereço do GPS pelo Google (falha não impede o ponto)

// Guarda nome/endereço da obra junto da marcação (o histórico não muda se a obra for editada depois)
db()->prepare('INSERT INTO punches (user_id, type, punched_at, lat, lng, accuracy, photo, ip, site_id, site_name, site_address, geo_address) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
    ->execute([$user['id'], $type, date('Y-m-d H:i:s'), $lat, $lng, $acc ?: null, $fname, $_SERVER['REMOTE_ADDR'] ?? null,
        $site['id'], $site['name'], $site['address'], $geoAddr]);

echo json_encode([
    'ok' => true,
    'type' => $type,
    'label' => PUNCH_LABELS[$type],
    'time' => date('H:i:s'),
    'site' => $site['name'],
    'address' => $geoAddr,
], JSON_UNESCAPED_UNICODE);
