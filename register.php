<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

// Autocadastro: o funcionário cria a conta, mas SÓ entra depois que o administrador aprovar.
if (!ALLOW_SELF_REGISTRATION) { http_response_code(404); exit('Cadastro desativado. Procure o administrador.'); }
if (current_user()) { header('Location: punch.php'); exit; }

$error = '';
$v = ['name' => '', 'cpf' => '', 'email' => '', 'job_title' => '', 'login' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    foreach ($v as $k => $_) $v[$k] = trim((string)($_POST[$k] ?? ''));
    $pass = (string)($_POST['senha'] ?? '');
    $pass2 = (string)($_POST['senha2'] ?? '');
    $cpf = preg_replace('/\D/', '', $v['cpf']);
    $login = strtolower($v['login']);

    try {
        if (trim((string)($_POST['website'] ?? '')) !== '') throw new RuntimeException('Não foi possível concluir o cadastro.'); // anti-robô (campo oculto)
        if (strlen($v['name']) < 5 || strlen($v['name']) > 100 || !str_contains($v['name'], ' ')) throw new RuntimeException('Informe seu nome completo.');
        if (!valid_cpf($cpf)) throw new RuntimeException('CPF inválido.');
        $email = strtolower($v['email']);
        if (!valid_email($email)) throw new RuntimeException('E-mail inválido.');
        if (strlen($v['job_title']) < 2 || strlen($v['job_title']) > 60) throw new RuntimeException('Informe sua função (ex.: pedreiro, servente, encarregado).');
        if (!preg_match('/^[a-z0-9._-]{3,30}$/', $login)) throw new RuntimeException('Login: de 3 a 30 caracteres, só letras, números, ponto, hífen ou sublinhado.');
        if (strlen($pass) < 8) throw new RuntimeException('A senha precisa ter ao menos 8 caracteres.');
        if (!hash_equals($pass, $pass2)) throw new RuntimeException('As senhas não conferem.');
        $recent = db()->prepare('SELECT COUNT(*) FROM users WHERE created_at >= ?');
        $recent->execute([date('Y-m-d H:i:s', time() - 3600)]);
        if ((int)$recent->fetchColumn() >= 20) throw new RuntimeException('Muitos cadastros em pouco tempo. Tente novamente mais tarde.');
        if (pending_signups_count() >= 100) throw new RuntimeException('Há muitos cadastros aguardando aprovação. Procure o administrador.');

        $dup = db()->prepare('SELECT COUNT(*) FROM users WHERE lower(login) = ?');
        $dup->execute([$login]);
        if ((int)$dup->fetchColumn()) throw new RuntimeException('Esse login já está em uso. Escolha outro.');
        $dup = db()->prepare('SELECT COUNT(*) FROM users WHERE cpf = ?');
        $dup->execute([$cpf]);
        if ((int)$dup->fetchColumn()) throw new RuntimeException('Já existe um cadastro com este CPF. Procure o administrador.');
        $dup = db()->prepare('SELECT COUNT(*) FROM users WHERE lower(email) = ?');
        $dup->execute([$email]);
        if ((int)$dup->fetchColumn()) throw new RuntimeException('Já existe um cadastro com este e-mail.');

        db()->prepare("INSERT INTO users (name, login, password_hash, role, weekly_hours, active, cpf, job_title, email, approval, created_at) VALUES (?,?,?,'employee',?,?,?,?,?,?,?)")
            ->execute([$v['name'], $login, password_hash($pass, PASSWORD_DEFAULT), DEFAULT_WEEKLY_HOURS, REQUIRE_SIGNUP_APPROVAL ? 0 : 1, $cpf, $v['job_title'], $email,
                REQUIRE_SIGNUP_APPROVAL ? 'pending' : 'approved', date('Y-m-d H:i:s')]);
        $newId = (int)db()->lastInsertId();

        if (REQUIRE_SIGNUP_APPROVAL) {
            flash('ok', 'Cadastro enviado! Assim que o administrador aprovar, você poderá entrar com seu login e senha.');
            header('Location: index.php');
        } else {
            session_regenerate_id(true);
            $_SESSION['uid'] = $newId;   // já entra logado
            flash('ok', 'Cadastro criado! Você já pode bater o ponto.');
            header('Location: punch.php');
        }
        exit;
    } catch (RuntimeException $e) {
        usleep(400000);
        $error = $e->getMessage();
    }
}

page_header('Criar cadastro');
?>
<div class="card" style="max-width:460px;margin:30px auto">
  <h1>Criar cadastro</h1>
  <p class="muted"><?= REQUIRE_SIGNUP_APPROVAL ? 'Preencha seus dados. O acesso é liberado depois da aprovação do administrador.' : 'Preencha seus dados e já comece a bater o ponto.' ?></p>
  <?php if ($error): ?><div class="err"><?= h($error) ?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <div style="position:absolute;left:-9999px" aria-hidden="true"><label>Não preencha<input name="website" tabindex="-1" autocomplete="off"></label></div>
    <label for="name">Nome completo</label>
    <input id="name" name="name" value="<?= h($v['name']) ?>" required maxlength="100" autofocus>
    <label for="cpf">CPF</label>
    <input id="cpf" name="cpf" value="<?= h($v['cpf']) ?>" required inputmode="numeric" maxlength="14" placeholder="000.000.000-00">
    <label for="email">E-mail</label>
    <input id="email" name="email" type="email" value="<?= h($v['email']) ?>" required maxlength="120" autocapitalize="none" placeholder="voce@exemplo.com">
    <label for="job_title">Função / cargo</label>
    <input id="job_title" name="job_title" value="<?= h($v['job_title']) ?>" required maxlength="60" placeholder="Ex.: pedreiro">
    <label for="login">Login (para entrar)</label>
    <input id="login" name="login" value="<?= h($v['login']) ?>" required maxlength="30" autocapitalize="none" placeholder="Ex.: joao.silva">
    <label for="senha">Senha (mín. 8 caracteres)</label>
    <input id="senha" name="senha" type="password" required minlength="8" autocomplete="new-password">
    <label for="senha2">Repita a senha</label>
    <input id="senha2" name="senha2" type="password" required minlength="8" autocomplete="new-password">
    <p><button type="submit" style="width:100%">Enviar cadastro</button></p>
  </form>
  <p class="muted" style="text-align:center">Já tem cadastro? <a href="index.php">Entrar</a></p>
</div>
<script>
const c = document.getElementById('cpf');
c.addEventListener('input', () => {
  const d = c.value.replace(/\D/g, '').slice(0, 11);
  c.value = d.replace(/^(\d{3})(\d)/, '$1.$2').replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3').replace(/\.(\d{3})(\d)/, '.$1-$2');
});
</script>
<?php page_footer();
