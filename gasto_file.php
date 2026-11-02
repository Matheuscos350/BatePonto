<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

// Entrega a foto/PDF de uma nota: só o dono do gasto ou o administrador.
$me = require_login();
$st = db()->prepare('SELECT f.*, e.user_id FROM expense_files f JOIN expenses e ON e.id = f.expense_id WHERE f.id = ?');
$st->execute([(int)($_GET['id'] ?? 0)]);
$f = $st->fetch();

if (!$f || ($me['role'] !== 'admin' && (int)$f['user_id'] !== (int)$me['id'])) {
    http_response_code(404);
    exit('Arquivo não encontrado.');
}
$path = UPLOAD_DIR . '/notas/' . basename($f['file']);
if (!is_file($path)) { http_response_code(404); exit('Arquivo ausente.'); }

$ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$f['mime']] ?? 'bin';
header('Content-Type: ' . $f['mime']);
header('Content-Disposition: ' . (isset($_GET['dl']) ? 'attachment' : 'inline') . '; filename="nota-' . (int)$f['expense_id'] . '-' . (int)$f['id'] . '.' . $ext . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
readfile($path);
