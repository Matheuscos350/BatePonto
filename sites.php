<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

$me = require_admin();
$msg = ''; $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $action = $_POST['action'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $addr = trim($_POST['address'] ?? '');

    if ($action === 'create') {
        if ($name === '' || $addr === '') { $err = 'Informe o nome da obra e o endereço.'; }
        else {
            db()->prepare('INSERT INTO sites (name, address) VALUES (?,?)')->execute([$name, $addr]);
            $msg = 'Obra cadastrada.';
        }
    } elseif ($action === 'update') {
        if ($name === '') { $err = 'Informe o nome da obra.'; }
        else {
            db()->prepare('UPDATE sites SET name = ?, address = ? WHERE id = ?')->execute([$name, $addr, (int)($_POST['id'] ?? 0)]);
            $msg = 'Obra atualizada (marcações antigas mantêm o nome/endereço da época).';
        }
    } elseif ($action === 'toggle') {
        db()->prepare('UPDATE sites SET active = 1 - active WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        $msg = 'Status atualizado.';
    }
}

$sites = db()->query('SELECT s.*, u.name AS creator FROM sites s LEFT JOIN users u ON u.id = s.created_by ORDER BY s.active DESC, s.name')->fetchAll();

page_header('Obras', $me);
?>
<?php if ($msg): ?><div class="okmsg"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>

<div class="card">
  <h2>Nova obra</h2>
  <form method="post" class="row" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="create">
    <div><label>Nome da obra</label><input name="name" required maxlength="120"></div>
    <div style="grid-column:span 2"><label>Endereço</label><input name="address" required maxlength="250" placeholder="Rua, número, bairro, cidade/UF"></div>
    <div style="align-self:end"><button type="submit">Cadastrar</button></div>
  </form>
</div>

<div class="card">
  <h2>Obras cadastradas</h2>
  <table>
    <tr><th>Nome</th><th>Endereço</th><th>Status</th><th>Ações</th></tr>
    <?php foreach ($sites as $s): $fid = 'sf' . (int)$s['id']; ?>
    <tr>
      <td><input form="<?= $fid ?>" name="name" value="<?= h($s['name']) ?>" required maxlength="120"><?php if ($s['creator']): ?><div class="muted small">cadastrada por <?= h($s['creator']) ?> ao bater o ponto</div><?php endif; ?></td>
      <td><input form="<?= $fid ?>" name="address" value="<?= h($s['address']) ?>" maxlength="250" placeholder="(sem endereço — complete aqui)"></td>
      <td><?= $s['active'] ? 'Ativa' : 'Inativa' ?></td>
      <td style="white-space:nowrap">
        <form id="<?= $fid ?>" method="post" style="display:inline-flex;gap:6px">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <button type="submit" name="action" value="update" class="btn sec" style="padding:4px 10px">Salvar</button>
          <button type="submit" name="action" value="toggle" class="btn sec" style="padding:4px 10px"><?= $s['active'] ? 'Desativar' : 'Ativar' ?></button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <p class="muted small">Obras desativadas não aparecem na hora de bater o ponto, mas continuam nos relatórios antigos.</p>
</div>
<?php page_footer();
