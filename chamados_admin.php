<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

$me = require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $tid = (int)($_POST['id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    try {
        $st = $pdo->prepare('SELECT * FROM tickets WHERE id = ?');
        $st->execute([$tid]);
        $t = $st->fetch();
        if (!$t) throw new RuntimeException('Chamado não encontrado.');
        $note = trim((string)($_POST['note'] ?? ''));
        $now = date('Y-m-d H:i:s');

        if ($action === 'reject') {
            if ($t['status'] !== 'pending') throw new RuntimeException('Este chamado já foi decidido.');
            if (strlen($note) < 5) throw new RuntimeException('Informe o motivo da recusa (mín. 5 caracteres).');
            $pdo->prepare("UPDATE tickets SET status='rejected', admin_id=?, admin_note=?, decided_at=? WHERE id=?")->execute([$me['id'], $note, $now, $tid]);
            audit_log((int)$t['user_id'], 'ticket_reject', (int)$me['id'], $note, null, null, null, $tid);
            flash('ok', 'Chamado recusado.');

        } elseif ($action === 'approve' && $t['type'] === 'medical') {
            if ($t['status'] !== 'pending') throw new RuntimeException('Este chamado já foi decidido.');
            $hours = (float)str_replace(',', '.', (string)($_POST['hours'] ?? '0'));
            if ($hours <= 0 || $hours > 24) throw new RuntimeException('Informe as horas abonadas por dia (entre 0,5 e 24).');
            $dates = abono_dates($t['date_from'], $t['date_to']);
            if (!$dates) throw new RuntimeException('O período não tem dias úteis (segunda a sexta).');
            $sec = (int)round($hours * 3600);

            $pdo->beginTransaction();
            try {
                $ins = $pdo->prepare('INSERT OR REPLACE INTO abonos (user_id, day, seconds, ticket_id, created_by, created_at) VALUES (?,?,?,?,?,?)');
                foreach ($dates as $d) $ins->execute([$t['user_id'], $d, $sec, $tid, $me['id'], $now]);
                $pdo->prepare("UPDATE tickets SET status='approved', admin_id=?, admin_note=?, decided_at=? WHERE id=?")
                    ->execute([$me['id'], $note !== '' ? $note : 'Atestado aprovado.', $now, $tid]);
                audit_log((int)$t['user_id'], 'abono_add', (int)$me['id'], $note !== '' ? $note : 'Atestado aprovado', null,
                    ['days' => $dates, 'hours' => $hours], null, $tid);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            flash('ok', 'Atestado aprovado: ' . count($dates) . ' dia(s) abonado(s) com ' . $hours . 'h cada.');

        } elseif ($action === 'reopen' && $t['type'] === 'medical' && $t['status'] === 'approved') {
            $del = $pdo->prepare('SELECT day FROM abonos WHERE ticket_id = ?');
            $del->execute([$tid]);
            $days = array_column($del->fetchAll(), 'day');
            $pdo->prepare('DELETE FROM abonos WHERE ticket_id = ?')->execute([$tid]);
            $pdo->prepare("UPDATE tickets SET status='pending', admin_note=NULL, decided_at=NULL WHERE id=?")->execute([$tid]);
            audit_log((int)$t['user_id'], 'abono_undo', (int)$me['id'], 'Aprovação desfeita', ['days' => $days, 'hours' => '?'], null, null, $tid);
            flash('ok', 'Aprovação desfeita: abonos removidos e chamado reaberto.');
        } else {
            throw new RuntimeException('Ação inválida.');
        }
    } catch (RuntimeException $e) {
        flash('err', $e->getMessage());
    } catch (Throwable $e) {
        flash('err', 'Erro ao salvar. Tente novamente.');
    }
    header('Location: chamados_admin.php?id=' . $tid);
    exit;
}

// ====== Visualização ======
$id = (int)($_GET['id'] ?? 0);
$detail = null;
if ($id) {
    $st = $pdo->prepare('SELECT t.*, u.name AS user_name, u.weekly_hours, a.name AS admin_name FROM tickets t JOIN users u ON u.id = t.user_id LEFT JOIN users a ON a.id = t.admin_id WHERE t.id = ?');
    $st->execute([$id]);
    $detail = $st->fetch() ?: null;
}

$filter = in_array($_GET['status'] ?? 'pending', ['pending', 'approved', 'rejected', 'all'], true) ? ($_GET['status'] ?? 'pending') : 'pending';
$sql = 'SELECT t.*, u.name AS user_name FROM tickets t JOIN users u ON u.id = t.user_id' . ($filter !== 'all' ? ' WHERE t.status = ' . $pdo->quote($filter) : '') . ' ORDER BY t.id DESC LIMIT 200';
$list = $pdo->query($sql)->fetchAll();

page_header('Chamados', $me);
flash_html();
?>
<?php if ($detail): $t = $detail; ?>
<div class="card">
  <h2>Chamado #<?= (int)$t['id'] ?> — <?= h(TICKET_TYPES[$t['type']] ?? $t['type']) ?>
    <span class="badge b-<?= h($t['status']) ?>" style="font-size:12px;vertical-align:middle"><?= h(ticket_status_label($t['status'])) ?></span></h2>
  <p><b>Funcionário:</b> <?= h($t['user_name']) ?> • <b>Aberto em:</b> <?= date('d/m/Y H:i', strtotime($t['created_at'])) ?><br>
     <b>Data(s):</b> <?= date('d/m/Y', strtotime($t['date_from'])) ?><?= $t['date_to'] !== $t['date_from'] ? ' a ' . date('d/m/Y', strtotime($t['date_to'])) : '' ?>
     <?php if ($t['req_type'] || $t['req_time']): ?><br><b>Correção pedida:</b> <?= h(PUNCH_LABELS[$t['req_type']] ?? '') ?> <?= h((string)$t['req_time']) ?><?php endif; ?></p>
  <p style="white-space:pre-wrap"><?= h($t['description']) ?></p>
  <?php if ($t['file']): ?>
    <p><a target="_blank" href="ticket_file.php?id=<?= (int)$t['id'] ?>">📎 Abrir anexo</a></p>
    <?php if ($t['file_mime'] !== 'application/pdf'): ?><p><img src="ticket_file.php?id=<?= (int)$t['id'] ?>" alt="Anexo" style="max-width:100%;max-height:480px;border:1px solid var(--line);border-radius:8px"></p><?php endif; ?>
  <?php endif; ?>
  <?php if ($t['status'] !== 'pending'): ?>
    <p class="muted"><b>Decisão:</b> <?= h(ticket_status_label($t['status'])) ?> por <?= h((string)$t['admin_name']) ?> em <?= $t['decided_at'] ? date('d/m/Y H:i', strtotime($t['decided_at'])) : '' ?> — <?= h((string)$t['admin_note']) ?></p>
  <?php endif; ?>

  <?php if ($t['status'] === 'pending'): ?>
    <hr style="border:0;border-top:1px solid var(--line);margin:16px 0">
    <?php if ($t['type'] === 'adjust'): ?>
      <p><a class="btn" href="adjust.php?<?= h(http_build_query(array_filter(['user' => $t['user_id'], 'date' => $t['date_from'], 'ticket' => $t['id'], 'type' => $t['req_type'], 'time' => $t['req_time']]))) ?>">Fazer o ajuste do ponto</a>
         <span class="muted small">Ao salvar o ajuste com este chamado aberto, ele é marcado como aprovado automaticamente.</span></p>
    <?php else: ?>
      <form method="post" class="row" style="align-items:end">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="action" value="approve">
        <div><label>Horas abonadas por dia</label><input name="hours" value="<?= h((string)round((float)$t['weekly_hours'] / 5, 2)) ?>"></div>
        <div style="grid-column:span 2"><label>Observação (opcional)</label><input name="note" maxlength="300"></div>
        <div><button type="submit">Aprovar e abonar</button></div>
      </form>
      <p class="muted small">Abona segunda a sexta do período (ou o dia único informado). As horas abonadas entram no total da semana, mas não geram hora extra.</p>
    <?php endif; ?>
    <form method="post" class="row" style="align-items:end;margin-top:10px">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="action" value="reject">
      <div style="grid-column:span 2"><label>Motivo da recusa</label><input name="note" required minlength="5" maxlength="300"></div>
      <div><button type="submit" style="background:var(--bad)">Recusar</button></div>
    </form>
  <?php elseif ($t['type'] === 'medical' && $t['status'] === 'approved'): ?>
    <form method="post" onsubmit="return confirm('Desfazer a aprovação e remover os abonos?')">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="action" value="reopen">
      <button type="submit" class="btn sec">Desfazer aprovação</button>
    </form>
  <?php endif; ?>
  <p style="margin-top:14px"><a href="chamados_admin.php">← Voltar à lista</a></p>
</div>
<?php endif; ?>

<div class="card">
  <h2>Chamados</h2>
  <p>
    <?php foreach (['pending' => 'Em análise', 'approved' => 'Aprovados', 'rejected' => 'Recusados', 'all' => 'Todos'] as $k => $l): ?>
      <a class="btn <?= $filter === $k ? '' : 'sec' ?>" style="padding:5px 12px" href="?status=<?= h($k) ?>"><?= h($l) ?></a>
    <?php endforeach; ?>
  </p>
  <?php if (!$list): ?><p class="muted">Nenhum chamado nesta lista.</p><?php else: ?>
  <table>
    <tr><th>#</th><th>Funcionário</th><th>Tipo</th><th>Data(s)</th><th>Status</th><th></th></tr>
    <?php foreach ($list as $r): ?>
    <tr>
      <td><?= (int)$r['id'] ?></td><td><?= h($r['user_name']) ?></td><td><?= h(TICKET_TYPES[$r['type']] ?? $r['type']) ?></td>
      <td><?= date('d/m/Y', strtotime($r['date_from'])) ?><?= $r['date_to'] !== $r['date_from'] ? ' a ' . date('d/m/Y', strtotime($r['date_to'])) : '' ?></td>
      <td><span class="badge b-<?= h($r['status']) ?>"><?= h(ticket_status_label($r['status'])) ?></span></td>
      <td><a href="?id=<?= (int)$r['id'] ?>&status=<?= h($filter) ?>">Abrir</a></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
<?php page_footer();
