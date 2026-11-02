<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

// O funcionário corrige nome/endereço das obras que ELE cadastrou ao bater o ponto.
// Marcações já feitas guardam o nome da época (histórico não muda); a correção vale para as próximas.
$user = require_login();
$uid = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    try {
        $id = (int)($_POST['id'] ?? 0);
        $name = preg_replace('/\s+/u', ' ', trim((string)($_POST['name'] ?? '')));
        $addr = preg_replace('/\s+/u', ' ', trim((string)($_POST['address'] ?? '')));
        if (strlen($name) < 2 || strlen($name) > 120) throw new RuntimeException('Informe o nome da obra.');
        if (strlen($addr) > 250) throw new RuntimeException('Endereço muito longo (máx. 250 caracteres).');

        $own = db()->prepare('SELECT id FROM sites WHERE id = ? AND created_by = ?');
        $own->execute([$id, $uid]);
        if (!$own->fetch()) throw new RuntimeException('Você só pode editar obras que você mesmo cadastrou.');

        $key = norm_site_name($name);
        foreach (db()->query('SELECT id, name FROM sites')->fetchAll() as $s) {
            if ((int)$s['id'] !== $id && norm_site_name($s['name']) === $key) {
                throw new RuntimeException('Já existe uma obra com esse nome. Use o nome dela ao bater o ponto.');
            }
        }
        db()->prepare('UPDATE sites SET name = ?, address = ? WHERE id = ? AND created_by = ?')->execute([$name, $addr, $id, $uid]);
        flash('ok', 'Obra atualizada. Vale para as próximas marcações.');
    } catch (RuntimeException $e) {
        flash('err', $e->getMessage());
    }
    header('Location: minhas_obras.php');
    exit;
}

$st = db()->prepare('SELECT s.*, (SELECT COUNT(*) FROM punches p WHERE p.site_id = s.id) AS uses FROM sites s WHERE s.created_by = ? ORDER BY s.name');
$st->execute([$uid]);
$mine = $st->fetchAll();

page_header('Minhas obras', $user);
flash_html();
?>
<div class="card">
  <h2>Minhas obras</h2>
  <p class="muted">Obras que você cadastrou ao bater o ponto. Aqui você corrige o nome ou o endereço (por exemplo, um erro de digitação).
    Marcações que você já fez continuam com o nome da época; a correção vale daqui para frente. Se precisar corrigir uma marcação antiga, abra um chamado.</p>
  <?php if (!$mine): ?>
    <p class="muted">Você ainda não cadastrou nenhuma obra. Ao digitar um nome novo na hora de bater o ponto, ela aparece aqui.</p>
  <?php else: ?>
  <table>
    <tr><th>Nome da obra</th><th>Endereço</th><th></th></tr>
    <?php foreach ($mine as $s): $fid = 'mo' . (int)$s['id']; ?>
    <tr>
      <td><input form="<?= $fid ?>" name="name" value="<?= h($s['name']) ?>" required maxlength="120">
          <div class="muted small"><?= (int)$s['uses'] ?> marcação(ões) • <?= $s['active'] ? 'ativa' : 'desativada pelo administrador' ?></div></td>
      <td><input form="<?= $fid ?>" name="address" value="<?= h($s['address']) ?>" maxlength="250" placeholder="(opcional)"></td>
      <td><form id="<?= $fid ?>" method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
        <button type="submit" class="btn sec" style="padding:4px 12px">Salvar</button></form></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
  <p><a href="punch.php">← Voltar a bater ponto</a></p>
</div>
<?php page_footer();
