<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

$me = require_login();
$st = db()->prepare('SELECT user_id, photo FROM punches WHERE id = ?');
$st->execute([(int)($_GET['id'] ?? 0)]);
$p = $st->fetch();

if (!$p || !$p['photo'] || ($me['role'] !== 'admin' && (int)$p['user_id'] !== (int)$me['id'])) {
    http_response_code(404);
    exit('Foto não encontrada.');
}
$path = UPLOAD_DIR . '/' . basename($p['photo']);
if (!is_file($path)) { http_response_code(404); exit('Arquivo ausente.'); }

header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=3600');
readfile($path);
