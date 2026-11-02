<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

// Chamados do funcionário: pedir reajuste de ponto ou enviar atestado.
// O funcionário NÃO altera o ponto — só o administrador decide e ajusta.
$user = require_login();
$uid = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    try {
        $type = (string)($_POST['type'] ?? '');
        if (!isset(TICKET_TYPES[$type])) throw new RuntimeException('Tipo de chamado inválido.');

        $from = (string)($_POST['date_from'] ?? '');
        $to = $type === 'medical' ? (string)($_POST['date_to'] ?? '') : $from;
        if (!valid_date($from) || !valid_date($to)) throw new RuntimeException('Informe a data corretamente.');
        if ($to < $from) throw new RuntimeException('A data final não pode ser anterior à inicial.');
        if ($to > date('Y-m-d')) throw new RuntimeException('Não é possível abrir chamado para data futura.');
        if ((strtotime($to) - strtotime($from)) / 86400 > 30) throw new RuntimeException('Período máximo de 31 dias por atestado.');

        $desc = trim((string)($_POST['description'] ?? ''));
        if (strlen($desc) < 10) throw new RuntimeException('Descreva o motivo (mín. 10 caracteres).');
        if (strlen($desc) > 2000) throw new RuntimeException('Descrição muito longa (máx. 2000 caracteres).');

        $reqType = ''; $reqTime = '';
        if ($type === 'adjust') {
            $reqType = (string)($_POST['req_type'] ?? '');
            if ($reqType !== '' && !isset(PUNCH_LABELS[$reqType])) throw new RuntimeException('Tipo de marcação inválido.');
            $reqTime = (string)($_POST['req_time'] ?? '');
            if ($reqTime !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $reqTime)) throw new RuntimeException('Horário inválido.');
        }

        $pend = db()->prepare("SELECT COUNT(*) FROM tickets WHERE user_id = ? AND status = 'pending'");
        $pend->execute([$uid]);
        if ((int)$pend->fetchColumn() >= 20) throw new RuntimeException('Você já tem muitos chamados em análise. Aguarde a resposta do administrador.');

        $file = null; $mime = null;
        $hasFile = isset($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if ($type === 'medical' && !$hasFile) throw new RuntimeException('Anexe a foto ou o PDF do atestado.');
        if ($hasFile) [$file, $mime] = save_attachment($_FILES['file']);

        db()->prepare('INSERT INTO tickets (user_id, type, date_from, date_to, req_type, req_time, description, file, file_mime, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$uid, $type, $from, $to, $reqType ?: null, $reqTime ?: null, $desc, $file, $mime, date('Y-m-d H:i:s')]);
        flash('ok', 'Chamado enviado. O administrador vai analisar e responder aqui.');
    } catch (RuntimeException $e) {
        flash('err', $e->getMessage());
    }
    header('Location: chamados.php');
    exit;
}

$st = db()->prepare('SELECT * FROM tickets WHERE user_id = ? ORDER BY id DESC LIMIT 100');
$st->execute([$uid]);
$tickets = $st->fetchAll();

page_header('Meus chamados', $user);
flash_html();
?>
<div class="card">
  <h2>Abrir chamado</h2>
  <p class="muted small">Você não altera o ponto diretamente: o chamado vai para o administrador, que analisa e faz o ajuste ou abona as horas.</p>
  <form method="post" enctype="multipart/form-data" id="tf">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <div class="row">
      <div><label>Tipo</label>
        <select name="type" id="type">
          <?php foreach (TICKET_TYPES as $k => $l): ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?>
        </select></div>
      <div><label id="lblFrom">Data</label><input type="date" name="date_from" max="<?= date('Y-m-d') ?>" required></div>
      <div id="boxTo"><label>Até (último dia do atestado)</label><input type="date" name="date_to" max="<?= date('Y-m-d') ?>"></div>
    </div>
    <div class="row" id="boxAdj">
      <div><label>Marcação a corrigir (opcional)</label>
        <select name="req_type"><option value="">—</option>
          <?php foreach (PUNCH_LABELS as $k => $l): ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?>
        </select></div>
      <div><label>Horário correto (opcional)</label><input type="time" name="req_time"></div>
    </div>
    <label>Descrição / motivo</label>
    <textarea name="description" rows="3" required minlength="10" maxlength="2000" placeholder="Ex.: esqueci de bater a saída do almoço; ou: atestado médico de 2 dias."></textarea>
    <label id="lblFile">Anexo (PDF, JPG ou PNG, até <?= (int)(MAX_ATTACH_BYTES / 1048576) ?> MB)</label>
    <input type="file" name="file" id="file" accept="application/pdf,image/jpeg,image/png">
    <p><button type="submit">Enviar chamado</button></p>
  </form>
</div>

<div class="card">
  <h2>Meus chamados</h2>
  <?php if (!$tickets): ?><p class="muted">Você ainda não abriu chamados.</p><?php else: ?>
  <table>
    <tr><th>#</th><th>Tipo</th><th>Data(s)</th><th>Status</th><th>Resposta do administrador</th><th>Anexo</th></tr>
    <?php foreach ($tickets as $t): ?>
    <tr>
      <td><?= (int)$t['id'] ?><br><span class="muted small"><?= date('d/m H:i', strtotime($t['created_at'])) ?></span></td>
      <td><?= h(TICKET_TYPES[$t['type']] ?? $t['type']) ?><br><span class="muted small"><?= h($t['description']) ?></span></td>
      <td><?= date('d/m/Y', strtotime($t['date_from'])) ?><?= $t['date_to'] !== $t['date_from'] ? ' a ' . date('d/m/Y', strtotime($t['date_to'])) : '' ?></td>
      <td><span class="badge b-<?= h($t['status']) ?>"><?= h(ticket_status_label($t['status'])) ?></span></td>
      <td><?= h($t['admin_note'] ?: '—') ?></td>
      <td><?php if ($t['file']): ?><a target="_blank" href="ticket_file.php?id=<?= (int)$t['id'] ?>">ver anexo</a><?php else: ?>—<?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>

<script>
const type = document.getElementById('type');
function upd() {
  const med = type.value === 'medical';
  document.getElementById('boxTo').style.display = med ? '' : 'none';
  document.getElementById('boxAdj').style.display = med ? 'none' : '';
  document.getElementById('lblFrom').textContent = med ? 'Primeiro dia do atestado' : 'Data da marcação';
  document.getElementById('file').required = med;
  document.getElementById('lblFile').textContent = (med ? 'Atestado (obrigatório) — ' : 'Comprovante (opcional) — ') + 'PDF, JPG ou PNG, até <?= (int)(MAX_ATTACH_BYTES / 1048576) ?> MB';
}
type.onchange = upd; upd();
</script>
<?php page_footer();
