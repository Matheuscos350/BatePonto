<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

$me = require_login();
$st = db()->prepare('SELECT id, user_id, file, file_mime FROM tickets WHERE id = ?');
$st->execute([(int)($_GET['id'] ?? 0)]);
$t = $st->fetch();

if (!$t || !$t['file'] || ($me['role'] !== 'admin' && (int)$t['user_id'] !== (int)$me['id'])) {
    http_response_code(404);
    exit('Arquivo não encontrado.');
}
$path = UPLOAD_DIR . '/atestados/' . basename($t['file']);
if (!is_file($path)) { http_response_code(404); exit('Arquivo ausente.'); }

$ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$t['file_mime']] ?? 'bin';
header('Content-Type: ' . $t['file_mime']);
header('Content-Disposition: inline; filename="anexo-chamado-' . (int)$t['id'] . '.' . $ext . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=600');
readfile($path);
