<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

$cu = current_user();
if ($cu) { header('Location: ' . ($cu['role'] === 'admin' ? 'chamados_admin.php' : 'punch.php')); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $id = trim($_POST['login'] ?? '');
    $st = db()->prepare('SELECT * FROM users WHERE lower(login) = lower(?) OR lower(email) = lower(?) ORDER BY (lower(login) = lower(?)) DESC LIMIT 1');
    $st->execute([$id, $id, $id]);
    $u = $st->fetch();
    if ($u && password_verify($_POST['senha'] ?? '', $u['password_hash'])) {
        if (($u['approval'] ?? 'approved') === 'pending') {
            $error = 'Seu cadastro está aguardando aprovação do administrador.';
        } elseif (($u['approval'] ?? 'approved') === 'rejected') {
            $error = 'Seu cadastro não foi aprovado. Procure o administrador.';
        } elseif (!$u['active']) {
            $error = 'Seu acesso está desativado. Procure o administrador.';
        } else {
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$u['id'];
            header('Location: ' . ($u['role'] === 'admin' ? 'chamados_admin.php' : 'punch.php'));
            exit;
        }
    } else {
        usleep(600000); // desacelera tentativas de força bruta
        $error = 'Login ou senha inválidos.';
    }
}

page_header('Entrar');
?>
<div class="card" style="max-width:380px;margin:40px auto">
  <h1>Entrar</h1>
  <p class="muted">Use o login e a senha do funcionário.</p>
  <?php flash_html(); ?>
  <?php if ($error): ?><div class="err"><?= h($error) ?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <label for="login">Login ou e-mail</label>
    <input id="login" name="login" required autofocus>
    <label for="senha">Senha</label>
    <input id="senha" name="senha" type="password" required>
    <p><button type="submit" style="width:100%">Entrar</button></p>
  </form>
  <?php if (ALLOW_SELF_REGISTRATION): ?><p class="muted" style="text-align:center">Primeiro acesso? <a href="register.php">Criar cadastro</a></p><?php endif; ?>
  <p class="muted small" style="text-align:center"><a href="admin_login.php">Acesso do administrador</a></p>
</div>
<?php page_footer();
