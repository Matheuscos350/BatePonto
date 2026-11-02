<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

// Reajuste de ponto: SOMENTE administrador. Todo ajuste exige motivo e fica registrado (auditoria).
$me = require_admin();
$pdo = db();

function req_dt(string $v): string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $v) || ($ts = strtotime(str_replace('T', ' ', $v))) === false) {
        throw new RuntimeException('Data/hora inválida.');
    }
    if ($ts > time() + 60) throw new RuntimeException('Não é possível lançar marcação no futuro.');
    return date('Y-m-d H:i:00', $ts);
}
function site_snapshot(int $id): array
{
    $st = db()->prepare('SELECT id, name, address FROM sites WHERE id = ?');
    $st->execute([$id]);
    $s = $st->fetch();
    return $s ? [(int)$s['id'], $s['name'], $s['address']] : [null, null, null];
}
function close_ticket(int $tid, int $uid, int $adminId, string $note): void
{
    if ($tid <= 0) return;
    db()->prepare("UPDATE tickets SET status='approved', admin_id=?, admin_note=?, decided_at=? WHERE id=? AND user_id=? AND type='adjust' AND status='pending'")
        ->execute([$adminId, 'Ajuste realizado: ' . $note, date('Y-m-d H:i:s'), $tid, $uid]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $action = (string)($_POST['action'] ?? '');
    $uid = (int)($_POST['user_id'] ?? 0);
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    $backDate = valid_date($_POST['date'] ?? null) ? $_POST['date'] : date('Y-m-d');
    try {
        $reason = trim((string)($_POST['reason'] ?? ''));
        if (strlen($reason) < 5) throw new RuntimeException('Informe o motivo do ajuste (mín. 5 caracteres).');
        if (strlen($reason) > 500) throw new RuntimeException('Motivo muito longo (máx. 500 caracteres).');

        if ($action === 'add') {
            $chk = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = ?');
            $chk->execute([$uid]);
            if (!(int)$chk->fetchColumn()) throw new RuntimeException('Funcionário não encontrado.');
            $type = (string)($_POST['type'] ?? '');
            if (!isset(PUNCH_LABELS[$type])) throw new RuntimeException('Tipo de marcação inválido.');
            $when = req_dt((string)($_POST['when'] ?? ''));
            [$sid, $sname, $saddr] = site_snapshot((int)($_POST['site_id'] ?? 0));

            $pdo->prepare('INSERT INTO punches (user_id, type, punched_at, lat, lng, accuracy, photo, ip, site_id, site_name, site_address, adjusted, adjusted_by) VALUES (?,?,?,NULL,NULL,NULL,NULL,NULL,?,?,?,1,?)')
                ->execute([$uid, $type, $when, $sid, $sname, $saddr, $me['id']]);
            audit_log($uid, 'add', (int)$me['id'], $reason, null, ['type' => $type, 'punched_at' => $when, 'site' => $sname], (int)$pdo->lastInsertId(), $ticketId ?: null);
            close_ticket($ticketId, $uid, (int)$me['id'], $reason);
            $backDate = substr($when, 0, 10);
            flash('ok', 'Marcação incluída.');

        } elseif ($action === 'edit' || $action === 'delete') {
            $st = $pdo->prepare('SELECT * FROM punches WHERE id = ?');
            $st->execute([(int)($_POST['id'] ?? 0)]);
            $old = $st->fetch();
            if (!$old) throw new RuntimeException('Marcação não encontrada.');
            $uid = (int)$old['user_id'];
            $oldSnap = ['type' => $old['type'], 'punched_at' => $old['punched_at'], 'site' => $old['site_name']];

            if ($action === 'edit') {
                $type = (string)($_POST['type'] ?? '');
                if (!isset(PUNCH_LABELS[$type])) throw new RuntimeException('Tipo de marcação inválido.');
                $when = req_dt((string)($_POST['when'] ?? ''));
                $sidIn = (int)($_POST['site_id'] ?? 0);
                // Se a obra não mudou, preserva o nome/endereço gravados na época.
                if ($sidIn === (int)$old['site_id']) { $sid = $old['site_id']; $sname = $old['site_name']; $saddr = $old['site_address']; }
                else { [$sid, $sname, $saddr] = site_snapshot($sidIn); }
                $pdo->prepare('UPDATE punches SET type=?, punched_at=?, site_id=?, site_name=?, site_address=?, adjusted=1, adjusted_by=? WHERE id=?')
                    ->execute([$type, $when, $sid, $sname, $saddr, $me['id'], $old['id']]);
                audit_log($uid, 'edit', (int)$me['id'], $reason, $oldSnap, ['type' => $type, 'punched_at' => $when, 'site' => $sname], (int)$old['id'], $ticketId ?: null);
                $backDate = substr($when, 0, 10);
                flash('ok', 'Marcação alterada.');
            } else {
                $pdo->prepare('DELETE FROM punches WHERE id = ?')->execute([$old['id']]);
                audit_log($uid, 'delete', (int)$me['id'], $reason, $oldSnap, null, (int)$old['id'], $ticketId ?: null);
                $backDate = substr($old['punched_at'], 0, 10);
                flash('ok', 'Marcação excluída (a foto original continua guardada).');
            }
            close_ticket($ticketId, $uid, (int)$me['id'], $reason);
        } else {
            throw new RuntimeException('Ação inválida.');
        }
    } catch (RuntimeException $e) {
        flash('err', $e->getMessage());
    }
    header('Location: adjust.php?' . http_build_query(array_filter(['user' => $uid, 'date' => $backDate, 'ticket' => $ticketId ?: null])));
    exit;
}

// ====== Visualização ======
$users = $pdo->query('SELECT id, name, active, weekly_hours FROM users ORDER BY name')->fetchAll();
$ticketId = (int)($_GET['ticket'] ?? 0);
$ticket = null;
if ($ticketId) {
    $st = $pdo->prepare("SELECT * FROM tickets WHERE id = ? AND type = 'adjust'");
    $st->execute([$ticketId]);
    $ticket = $st->fetch() ?: null;
    if (!$ticket) $ticketId = 0;
}
$uid = (int)($_GET['user'] ?? ($ticket['user_id'] ?? ($users[0]['id'] ?? 0)));
$date = valid_date($_GET['date'] ?? null) ? $_GET['date'] : ($ticket['date_from'] ?? date('Y-m-d'));
$sel = null;
foreach ($users as $u) if ((int)$u['id'] === $uid) $sel = $u;
if (!$sel) { $sel = $users[0] ?? null; $uid = (int)($sel['id'] ?? 0); }

$sites = $pdo->query('SELECT id, name, active FROM sites ORDER BY active DESC, name')->fetchAll();

$ps = $pdo->prepare('SELECT * FROM punches WHERE user_id = ? AND punched_at >= ? AND punched_at <= ? ORDER BY punched_at, id');
$ps->execute([$uid, $date . ' 00:00:00', $date . ' 23:59:59']);
$dayPunches = $ps->fetchAll();
$week = summarize_week($uid, week_start($date), (float)($sel['weekly_hours'] ?? DEFAULT_WEEKLY_HOURS));

$preType = isset(PUNCH_LABELS[$_GET['type'] ?? '']) ? $_GET['type'] : 'in';
$preTime = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $_GET['time'] ?? '') ? $_GET['time'] : ($date === date('Y-m-d') ? date('H:i') : '08:00');
$preWhen = $date . 'T' . $preTime;

page_header('Ajustar ponto', $me);
flash_html();

function site_options(array $sites, ?int $selected): void
{
    echo '<option value="0">(sem obra)</option>';
    foreach ($sites as $s) {
        echo '<option value="' . (int)$s['id'] . '"' . ((int)$s['id'] === (int)$selected ? ' selected' : '') . '>' . h($s['name']) . ($s['active'] ? '' : ' (inativa)') . '</option>';
    }
}
?>
<div class="card">
  <h2>Ajustar ponto <span class="muted small">— somente administrador</span></h2>
  <form method="get" class="row" style="align-items:end">
    <div><label>Funcionário</label>
      <select name="user"><?php foreach ($users as $u): ?>
        <option value="<?= (int)$u['id'] ?>" <?= (int)$u['id'] === $uid ? 'selected' : '' ?>><?= h($u['name']) ?><?= $u['active'] ? '' : ' (inativo)' ?></option>
      <?php endforeach; ?></select></div>
    <div><label>Dia</label><input type="date" name="date" value="<?= h($date) ?>"></div>
    <?php if ($ticketId): ?><input type="hidden" name="ticket" value="<?= $ticketId ?>"><?php endif; ?>
    <div><button type="submit">Ver dia</button></div>
  </form>
</div>

<?php if ($ticket): ?>
<div class="card" style="border-color:var(--warn)">
  <h2>Chamado #<?= (int)$ticket['id'] ?> (<?= h(ticket_status_label($ticket['status'])) ?>)</h2>
  <p style="white-space:pre-wrap;margin:0"><?= h($ticket['description']) ?></p>
  <?php if ($ticket['req_type'] || $ticket['req_time']): ?><p class="muted">Correção pedida: <?= h(PUNCH_LABELS[$ticket['req_type']] ?? '') ?> <?= h((string)$ticket['req_time']) ?></p><?php endif; ?>
  <?php if ($ticket['file']): ?><p><a target="_blank" href="ticket_file.php?id=<?= (int)$ticket['id'] ?>">📎 anexo</a></p><?php endif; ?>
  <p class="muted small" style="margin:0">Ao salvar qualquer ajuste abaixo, o chamado é marcado como aprovado. Para recusar, use a página do chamado.</p>
</div>
<?php endif; ?>

<div class="card">
  <h2>Marcações de <?= h($sel['name'] ?? '') ?> em <?= date('d/m/Y', strtotime($date)) ?> (<?= dia_semana($date) ?>)</h2>
  <?php if (!$dayPunches): ?><p class="muted">Nenhuma marcação neste dia.</p><?php else: ?>
  <table>
    <tr><th>Tipo</th><th>Data e hora</th><th>Obra</th><th>Motivo do ajuste *</th><th></th></tr>
    <?php foreach ($dayPunches as $p): $fid = 'pf' . (int)$p['id']; ?>
    <tr>
      <td><select form="<?= $fid ?>" name="type"><?php foreach (PUNCH_LABELS as $k => $l): ?><option value="<?= h($k) ?>" <?= $p['type'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select>
        <div class="small muted"><?= $p['adjusted'] ? 'ajustada pelo ADM' : 'registrada pelo funcionário' ?><?= $p['photo'] ? ' • <a target="_blank" href="photo.php?id=' . (int)$p['id'] . '">foto</a>' : '' ?></div></td>
      <td><input form="<?= $fid ?>" type="datetime-local" name="when" value="<?= h(str_replace(' ', 'T', substr($p['punched_at'], 0, 16))) ?>" required></td>
      <td><select form="<?= $fid ?>" name="site_id"><?php site_options($sites, $p['site_id'] !== null ? (int)$p['site_id'] : null); ?></select></td>
      <td><input form="<?= $fid ?>" name="reason" placeholder="Obrigatório" minlength="5" maxlength="500" required></td>
      <td style="white-space:nowrap">
        <form id="<?= $fid ?>" method="post" style="display:inline-flex;gap:6px">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="user_id" value="<?= $uid ?>">
          <input type="hidden" name="date" value="<?= h($date) ?>"><input type="hidden" name="ticket_id" value="<?= $ticketId ?>">
          <button type="submit" name="action" value="edit" class="btn sec" style="padding:4px 10px">Salvar</button>
          <button type="submit" name="action" value="delete" class="btn sec" style="padding:4px 10px;color:var(--bad);border-color:var(--bad)" onclick="return confirm('Excluir esta marcação?')">Excluir</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Incluir marcação</h2>
  <form method="post" class="row" style="align-items:end">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="add">
    <input type="hidden" name="user_id" value="<?= $uid ?>"><input type="hidden" name="date" value="<?= h($date) ?>"><input type="hidden" name="ticket_id" value="<?= $ticketId ?>">
    <div><label>Tipo</label><select name="type"><?php foreach (PUNCH_LABELS as $k => $l): ?><option value="<?= h($k) ?>" <?= $k === $preType ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
    <div><label>Data e hora</label><input type="datetime-local" name="when" value="<?= h($preWhen) ?>" required></div>
    <div><label>Obra</label><select name="site_id"><?php site_options($sites, (int)($dayPunches ? end($dayPunches)['site_id'] : 0)); ?></select></div>
    <div style="grid-column:span 2"><label>Motivo do ajuste *</label><input name="reason" required minlength="5" maxlength="500" placeholder="Ex.: esquecimento confirmado com o encarregado"></div>
    <div><button type="submit">Incluir</button></div>
  </form>
</div>

<div class="card">
  <h2>Resumo da semana (<?= $week['start']->format('d/m') ?> a <?= $week['end']->format('d/m') ?>)</h2>
  <p>Trabalhado: <b><?= fmt_hm($week['total']) ?></b><?= $week['abono'] ? ' + abono ' . fmt_hm($week['abono']) : '' ?> • Extras: <b><?= fmt_hm($week['overtime']) ?></b> •
    <span class="badge b-<?= h($week['status']) ?>"><?= h(status_label($week)) ?></span></p>
  <?php if ($week['incomplete']): ?><p class="err">Esta semana ainda tem marcações incompletas ou fora de sequência (ex.: entrada sem saída, almoço sem volta).</p><?php endif; ?>
</div>

<?php $aud = audit_list($uid, 30); if ($aud): ?>
<div class="card">
  <h2>Histórico de ajustes deste funcionário</h2>
  <table>
    <tr><th>Quando</th><th>Administrador</th><th>Ação</th><th>Motivo</th><th>Antes → Depois</th></tr>
    <?php foreach ($aud as $a): ?>
    <tr><td><?= date('d/m/Y H:i', strtotime($a['created_at'])) ?></td><td><?= h($a['admin_name']) ?></td>
        <td><?= h(audit_action_label($a['action'])) ?></td><td><?= h($a['reason']) ?></td>
        <td class="small"><?= h(audit_summary($a['old_json'])) ?> → <?= h(audit_summary($a['new_json'])) ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>
<?php page_footer();
