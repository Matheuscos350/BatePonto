<?php
// Redefine a senha do administrador. Só roda pelo terminal (CLI), nunca pelo navegador:
//   php reset_admin.php NOVA_SENHA [login]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/config.php';
$pass = $argv[1] ?? '';
$login = $argv[2] ?? 'admin';
if (strlen($pass) < 6) { fwrite(STDERR, "Uso: php reset_admin.php NOVA_SENHA [login]  (mín. 6 caracteres)\n"); exit(1); }
$st = db()->prepare("UPDATE users SET password_hash = ?, active = 1, approval = 'approved', role = 'admin' WHERE lower(login) = lower(?)");
$st->execute([password_hash($pass, PASSWORD_DEFAULT), $login]);
echo $st->rowCount() ? "Senha do administrador '$login' redefinida.\n" : "Nenhum usuário com login '$login'.\n";
