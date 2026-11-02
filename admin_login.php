<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

// Entrada exclusiva do administrador (login ou e-mail). Contas de funcionário não entram por aqui.
$cu = current_user();
if ($cu && $cu['role'] === 'admin') { header('Location: chamados_admin.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $id = trim((string)($_POST['login'] ?? ''));
    $st = db()->prepare("SELECT * FROM users WHERE role = 'admin' AND active = 1 AND approval = 'approved' AND (lower(login) = lower(?) OR lower(email) = lower(?)) ORDER BY (lower(login) = lower(?)) DESC LIMIT 1");
    $st->execute([$id, $id, $id]);
    $u = $st->fetch();
    if ($u && password_verify((string)($_POST['senha'] ?? ''), $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        header('Location: chamados_admin.php');
        exit;
    }
    usleep(600000); // desacelera tentativas de força bruta
    $error = 'Login ou senha inválidos.'; // mesma mensagem para qualquer falha (não revela se a conta existe)
}

page_header('Acesso do administrador');
?>
<div class="card" style="max-width:380px;margin:40px auto;border-top:4px solid var(--brand)">
  <h1>Administrador</h1>
  <p class="muted">Acesso restrito à gestão do ponto: chamados, ajustes, obras e funcionários.</p>
  <?php flash_html(); ?>
  <?php if ($error): ?><div class="err"><?= h($error) ?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <label for="login">Login ou e-mail</label>
    <input id="login" name="login" required autofocus autocapitalize="none">
    <label for="senha">Senha</label>
    <input id="senha" name="senha" type="password" required>
    <p><button type="submit" style="width:100%">Entrar como administrador</button></p>
  </form>
  <p class="muted small" style="text-align:center"><a href="index.php">← Acesso do funcionário</a></p>
</div>
<?php page_footer();
