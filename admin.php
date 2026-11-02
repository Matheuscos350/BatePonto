<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

$me = require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    try {
        if ($action === 'create') {
            $name = trim((string)($_POST['name'] ?? ''));
            $login = strtolower(trim((string)($_POST['login'] ?? '')));
            $pass = (string)($_POST['senha'] ?? '');
            $hours = (float)str_replace(',', '.', (string)($_POST['weekly_hours'] ?? (string)DEFAULT_WEEKLY_HOURS));
            $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'employee';
            $title = trim((string)($_POST['job_title'] ?? ''));
            $cpf = preg_replace('/\D/', '', (string)($_POST['cpf'] ?? ''));
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $salary = parse_money((string)($_POST['salary'] ?? ''));
            if ($name === '' || $login === '' || strlen($pass) < 6 || $hours <= 0 || $hours > 80) {
                throw new RuntimeException('Preencha nome, login, senha (mín. 6 caracteres) e jornada válida.');
            }
            if ($cpf !== '' && !valid_cpf($cpf)) throw new RuntimeException('CPF inválido.');
            if (!valid_email($email)) throw new RuntimeException('E-mail inválido.');
            try {
                $pdo->prepare('INSERT INTO users (name, login, password_hash, role, weekly_hours, cpf, job_title, email, salary, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$name, $login, password_hash($pass, PASSWORD_DEFAULT), $role, $hours, $cpf !== '' ? $cpf : null, $title !== '' ? $title : null, $email, $salary > 0 ? $salary : null, date('Y-m-d H:i:s')]);
                flash('ok', "Funcionário “$name” cadastrado. Login: $login");
            } catch (PDOException $e) {
                throw new RuntimeException('Esse login, CPF ou e-mail já está cadastrado.');
            }
        } elseif ($action === 'approve') {
            $hours = (float)str_replace(',', '.', (string)($_POST['weekly_hours'] ?? '0'));
            if ($hours <= 0 || $hours > 80) throw new RuntimeException('Informe a jornada semanal (em horas).');
            $pdo->prepare("UPDATE users SET approval='approved', active=1, weekly_hours=? WHERE id=? AND approval IN ('pending','rejected')")->execute([$hours, $id]);
            flash('ok', 'Cadastro aprovado. O funcionário já pode entrar.');
        } elseif ($action === 'reject') {
            $pdo->prepare("UPDATE users SET approval='rejected', active=0 WHERE id=? AND approval='pending'")->execute([$id]);
            flash('ok', 'Cadastro recusado.');
        } elseif ($action === 'toggle') {
            if ($id !== (int)$me['id']) {
                $pdo->prepare("UPDATE users SET active = 1 - active WHERE id = ? AND approval = 'approved'")->execute([$id]);
                flash('ok', 'Status atualizado.');
            }
        } elseif ($action === 'hours') {
            $hours = (float)str_replace(',', '.', (string)($_POST['weekly_hours'] ?? '0'));
            if ($hours <= 0 || $hours > 80) throw new RuntimeException('Jornada semanal inválida.');
            $pdo->prepare('UPDATE users SET weekly_hours = ? WHERE id = ?')->execute([$hours, $id]);
            flash('ok', 'Jornada atualizada.');
        } elseif ($action === 'salary') {
            $salary = parse_money((string)($_POST['salary'] ?? ''));
            if ($salary < 0 || $salary > 1000000) throw new RuntimeException('Salário inválido.');
            $pdo->prepare('UPDATE users SET salary = ? WHERE id = ?')->execute([$salary > 0 ? $salary : null, $id]);
            flash('ok', $salary > 0 ? 'Salário atualizado.' : 'Salário removido.');
        } elseif ($action === 'email') {
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            if (!valid_email($email)) throw new RuntimeException('E-mail inválido.');
            try {
                $pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$email, $id]);
            } catch (PDOException $e) {
                throw new RuntimeException('Já existe outro cadastro com este e-mail.');
            }
            flash('ok', 'E-mail atualizado.');
        } elseif ($action === 'password') {
            $pass = (string)($_POST['senha'] ?? '');
            if (strlen($pass) < 6) throw new RuntimeException('A nova senha precisa ter ao menos 6 caracteres.');
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
            flash('ok', 'Senha alterada.');
        }
    } catch (RuntimeException $e) {
        flash('err', $e->getMessage());
    }
    header('Location: admin.php');
    exit;
}

$pending = $pdo->query("SELECT * FROM users WHERE approval = 'pending' ORDER BY id")->fetchAll();
$users = $pdo->query("SELECT * FROM users WHERE approval != 'pending' ORDER BY name")->fetchAll();

page_header('Funcionários', $me);
flash_html();
?>
<?php if (password_verify('admin123', $me['password_hash'])): ?>
<div class="err"><b>Troque a senha padrão do administrador.</b> Você ainda usa <code>admin123</code>. Na lista abaixo, na sua linha, digite uma nova senha e clique em “Trocar”.</div>
<?php endif; ?>
<?php if ($pending): ?>
<div class="card" style="border-color:var(--warn)">
  <h2>Cadastros aguardando aprovação (<?= count($pending) ?>)</h2>
  <table>
    <tr><th>Nome</th><th>Função</th><th>CPF</th><th>Login / e-mail</th><th>Jornada (h/sem)</th><th></th></tr>
    <?php foreach ($pending as $p): $fid = 'ap' . (int)$p['id']; ?>
    <tr>
      <td><?= h($p['name']) ?></td><td><?= h((string)$p['job_title']) ?></td><td><?= h(fmt_cpf($p['cpf'])) ?></td><td><?= h($p['login']) ?><br><span class="muted small"><?= h((string)$p['email']) ?></span></td>
      <td><input form="<?= $fid ?>" name="weekly_hours" value="<?= h((string)$p['weekly_hours']) ?>" style="width:80px"></td>
      <td style="white-space:nowrap">
        <form id="<?= $fid ?>" method="post" style="display:inline-flex;gap:6px">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <button type="submit" name="action" value="approve" class="btn" style="padding:4px 12px">Aprovar</button>
          <button type="submit" name="action" value="reject" class="btn sec" style="padding:4px 12px;color:var(--bad);border-color:var(--bad)" onclick="return confirm('Recusar este cadastro?')">Recusar</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <p class="muted small">Confira se a pessoa realmente trabalha na empresa antes de aprovar.</p>
</div>
<?php endif; ?>

<div class="card">
  <h2>Cadastrar funcionário</h2>
  <p class="muted small">Cadastro feito por você já nasce aprovado. Os funcionários também podem se cadastrar sozinhos em <b>register.php</b> (você aprova aqui).</p>
  <form method="post" class="row" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="create">
    <div><label>Nome</label><input name="name" required></div>
    <div><label>CPF (opcional)</label><input name="cpf" inputmode="numeric" maxlength="14"></div>
    <div><label>E-mail</label><input name="email" type="email" required maxlength="120"></div>
    <div><label>Função / cargo</label><input name="job_title" maxlength="60"></div>
    <div><label>Login</label><input name="login" required></div>
    <div><label>Senha</label><input name="senha" type="password" minlength="6" required></div>
    <div><label>Jornada semanal (h)</label><input name="weekly_hours" value="<?= DEFAULT_WEEKLY_HOURS ?>"></div>
    <div><label>Salário mensal (R$)</label><input name="salary" inputmode="decimal" placeholder="Ex.: 2.640,00"></div>
    <div><label>Perfil</label><select name="role"><option value="employee">Funcionário</option><option value="admin">Administrador</option></select></div>
    <div style="align-self:end"><button type="submit">Cadastrar</button></div>
  </form>
</div>

<div class="card">
  <h2>Funcionários</h2>
  <style>
.emps{display:grid;gap:12px}
.emp{border:1px solid var(--line);border-radius:10px;padding:14px;background:#fff;min-width:0}
.emp.off{background:#f8f9fb}
.emp-head{display:flex;flex-wrap:wrap;gap:6px 12px;align-items:baseline;justify-content:space-between;margin-bottom:10px}
.emp-name{font-weight:700;font-size:16px;overflow-wrap:anywhere}
.emp-meta{color:var(--muted);font-size:13px;overflow-wrap:anywhere}
.pill{display:inline-block;border-radius:99px;padding:2px 10px;font-size:12px;font-weight:600;background:#eef1f6;color:#475066;margin-left:4px}
.pill.ok{background:#e3f6ea;color:#17703a}.pill.off{background:#fdeaea;color:#a12a2a}
.emp-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px 14px}
.emp-grid>div{min-width:0}
.emp-grid label{display:block;font-size:12px;font-weight:600;color:var(--muted);margin:0 0 3px}
.fld{display:flex;gap:6px;margin:0}
.fld input{flex:1 1 0;min-width:0;width:100%;padding:6px 8px;margin:0}
.fld button{flex:0 0 auto;padding:6px 12px}
.emp-acts{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:12px;padding-top:10px;border-top:1px solid var(--line)}
.emp-acts form{margin:0}.emp-acts .btn,.emp-acts button{padding:6px 12px}
.emp-hint{font-size:12px;color:var(--muted);margin-top:3px}
</style>
<div class="emps">
  <?php foreach ($users as $u): $isOff = !$u['active'] || $u['approval'] === 'rejected'; ?>
  <div class="emp<?= $isOff ? ' off' : '' ?>">
    <div class="emp-head">
      <div>
        <span class="emp-name"><?= h($u['name']) ?></span>
        <span class="pill"><?= $u['role'] === 'admin' ? 'Admin' : 'Funcionário' ?></span>
        <?php if ($u['approval'] === 'rejected'): ?><span class="pill off">Cadastro recusado</span>
        <?php elseif ($u['active']): ?><span class="pill ok">Ativo</span><?php else: ?><span class="pill off">Inativo</span><?php endif; ?>
      </div>
      <div class="emp-meta">
        <?= implode(' • ', array_filter([(string)$u['job_title'], !empty($u['cpf']) ? 'CPF ' . fmt_cpf($u['cpf']) : '', 'login ' . $u['login']])) ?>
      </div>
    </div>
    <div class="emp-grid">
      <div><label>E-mail</label>
        <form method="post" class="fld"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="email"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input name="email" type="email" value="<?= h((string)$u['email']) ?>" placeholder="e-mail" maxlength="120"><button type="submit" class="btn sec">OK</button></form></div>
      <div><label>Jornada (h/semana)</label>
        <form method="post" class="fld"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="hours"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input name="weekly_hours" value="<?= h((string)$u['weekly_hours']) ?>"><button type="submit" class="btn sec">OK</button></form></div>
      <div><label>Salário (R$/mês)</label>
        <form method="post" class="fld"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="salary"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input name="salary" inputmode="decimal" value="<?= $u['salary'] ? h(number_format((float)$u['salary'], 2, ',', '.')) : '' ?>" placeholder="não informado"><button type="submit" class="btn sec">OK</button></form>
        <?php if (hourly_rate($u) !== null): ?><div class="emp-hint">valor da hora: <?= h(money(hourly_rate($u))) ?></div><?php endif; ?></div>
      <div><label>Nova senha</label>
        <form method="post" class="fld"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="password"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input name="senha" type="password" placeholder="mín. 6 caracteres" minlength="6" autocomplete="new-password"><button type="submit" class="btn sec">Trocar</button></form></div>
    </div>
    <div class="emp-acts">
      <a class="btn sec" href="report.php?user=<?= (int)$u['id'] ?>">Relatório</a>
      <?php if ($u['approval'] === 'rejected'): ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="approve">
        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="weekly_hours" value="<?= h((string)$u['weekly_hours']) ?>">
        <button type="submit" class="btn sec">Aprovar</button>
      </form>
      <?php elseif ((int)$u['id'] !== (int)$me['id']): ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
        <button type="submit" class="btn sec"><?= $u['active'] ? 'Desativar' : 'Ativar' ?></button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
</div>
<?php page_footer();
