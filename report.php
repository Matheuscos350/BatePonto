<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

$me = require_login();
$isAdmin = $me['role'] === 'admin';

$uid = $isAdmin ? (int)($_GET['user'] ?? $me['id']) : (int)$me['id'];
$validDate = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
$from = $validDate($_GET['from'] ?? null) ? $_GET['from'] : date('Y-m-d', strtotime('monday this week'));
$to = $validDate($_GET['to'] ?? null) ? $_GET['to'] : date('Y-m-d');
if ($to < $from) [$from, $to] = [$to, $from];

$data = report_data($uid, $from, $to);
$user = $data['user'];
$employees = $isAdmin ? db()->query('SELECT id, name FROM users ORDER BY name')->fetchAll() : [];

$qs = http_build_query(['user' => $uid, 'from' => $from, 'to' => $to]);

// Marcações detalhadas
$ps = db()->prepare('SELECT * FROM punches WHERE user_id = ? AND punched_at >= ? AND punched_at <= ? ORDER BY punched_at');
$ps->execute([$uid, $from . ' 00:00:00', $to . ' 23:59:59']);
$punches = $ps->fetchAll();

page_header('Relatórios', $me);
?>
<div class="card">
  <h2>Relatório de horas</h2>
  <form method="get" class="row" style="align-items:end">
    <?php if ($isAdmin): ?>
    <div><label>Funcionário</label>
      <select name="user"><?php foreach ($employees as $e): ?>
        <option value="<?= (int)$e['id'] ?>" <?= (int)$e['id'] === $uid ? 'selected' : '' ?>><?= h($e['name']) ?></option>
      <?php endforeach; ?></select></div>
    <?php endif; ?>
    <div><label>De</label><input type="date" name="from" value="<?= h($from) ?>"></div>
    <div><label>Até</label><input type="date" name="to" value="<?= h($to) ?>"></div>
    <div><button type="submit">Filtrar</button></div>
  </form>
  <p style="margin-top:14px">
    <a class="btn" target="_blank" href="pdf.php?<?= h($qs) ?>">📄 Gerar PDF</a>
    <a class="btn sec" href="?<?= h(http_build_query(['user' => $uid, 'from' => date('Y-m-01'), 'to' => date('Y-m-t')])) ?>">Mês atual</a>
    <a class="btn sec" href="?<?= h(http_build_query(['user' => $uid])) ?>">Semana atual</a>
  </p>
  <p class="muted">Funcionário: <b><?= h($user['name']) ?></b> • Jornada: <?= h((string)$user['weekly_hours']) ?>h/semana • Limite de extras: <?= OVERTIME_LIMIT_PERCENT ?>% (<?= fmt_hm((int)round($user['weekly_hours'] * 3600 * OVERTIME_LIMIT_PERCENT / 100)) ?>/semana). Semanas de segunda a domingo. O horário de almoço não conta como trabalhado. Marcações com * foram ajustadas pelo administrador.</p>
</div>

<?php
$totOT = (int)array_sum(array_column($data['weeks'], 'overtime'));
$rate = hourly_rate($user);
$totPay = 0.0;
foreach ($data['weeks'] as $w0) $totPay += (float)overtime_pay($w0, $user);
?>
<div class="card">
  <h2>Horas extras no período</h2>
  <p>Total de horas extras: <b><?= fmt_hm($totOT) ?></b>
  <?php if ($rate !== null): ?> • Valor da hora: <?= h(money($rate)) ?> • Adicional: <?= OVERTIME_PREMIUM_PERCENT ?>% • <b>Estimativa a receber: <?= h(money($totPay)) ?></b>
  <?php else: ?><br><span class="muted">Salário não informado (o administrador cadastra em Funcionários), então não há estimativa em R$.</span><?php endif; ?></p>
  <p class="muted small">Estimativa bruta: valor da hora × horas extras × <?= h(number_format(1 + OVERTIME_PREMIUM_PERCENT / 100, 2, ',', '')) ?>. Não inclui DSR, adicional noturno nem descontos (INSS/IR); o valor final depende do acordo da empresa.</p>
</div>

<?php foreach ($data['weeks'] as $w): ?>
<div class="card">
  <h2>Semana <?= $w['start']->format('d/m/Y') ?> a <?= $w['end']->format('d/m/Y') ?>
    <span class="badge b-<?= h($w['status']) ?>" style="font-size:12px;vertical-align:middle"><?= h(status_label($w)) ?></span></h2>
  <table>
    <tr><th>Data</th><th>Dia</th><th>Marcações / obra</th><th>Almoço</th><th>Abono</th><th>Horas</th></tr>
    <?php foreach ($w['days'] as $d => $info): ?>
    <tr><td><?= date('d/m/Y', strtotime($d)) ?></td><td><?= dia_semana($d) ?></td>
        <td><?= h(events_text($info['events']) ?: '—') ?>
          <?php foreach ($info['sites'] as $sn => $sa): ?><br><span class="muted small">Obra: <?= h($sn) ?> — <?= h($sa) ?></span><?php endforeach; ?></td>
        <td><?= $info['lunch'] ? fmt_hm($info['lunch']) : '—' ?></td>
        <td><?= $info['abono'] ? fmt_hm($info['abono']) : '—' ?></td>
        <td><?= fmt_hm($info['seconds']) ?></td></tr>
    <?php endforeach; ?>
    <tr><th colspan="5">Total trabalhado</th><th><?= fmt_hm($w['total']) ?></th></tr>
    <?php if ($w['abono']): ?><tr><td colspan="5">Horas abonadas por atestado (creditadas, não geram hora extra)</td><td><?= fmt_hm($w['abono']) ?></td></tr>
    <tr><td colspan="5">Total creditado (trabalhado + abonado)</td><td><?= fmt_hm($w['credited']) ?></td></tr><?php endif; ?>
    <tr><td colspan="5">Jornada contratada</td><td><?= fmt_hm($w['contracted']) ?></td></tr>
    <tr><td colspan="5">Horas extras (<?= number_format($w['percent'], 1, ',', '.') ?>% da jornada)</td><td><?= fmt_hm($w['overtime']) ?></td></tr>
    <tr><td colspan="5">Falta para o limite de <?= OVERTIME_LIMIT_PERCENT ?>%</td><td><?= fmt_hm($w['remaining_to_limit']) ?></td></tr>
    <?php $pay = overtime_pay($w, $user); if ($pay !== null): ?><tr><td colspan="5">Valor estimado das horas extras (+<?= OVERTIME_PREMIUM_PERCENT ?>%)</td><td><b><?= h(money($pay)) ?></b></td></tr><?php endif; ?>
  </table>
  <?php if ($w['incomplete']): ?><p class="err" style="margin-top:10px">Há marcações incompletas ou fora de sequência nesta semana (ex.: entrada sem saída, almoço sem volta).</p><?php endif; ?>
</div>
<?php endforeach; ?>

<div class="card">
  <h2>Marcações com obra, local e foto</h2>
  <?php if (!$punches): ?><p class="muted">Nenhuma marcação no período.</p><?php else: ?>
  <table>
    <tr><th>Data/hora</th><th>Tipo</th><th>Obra / endereço</th><th>Local GPS</th><th>Foto</th></tr>
    <?php foreach ($punches as $r): ?>
    <tr>
      <td><?= date('d/m/Y H:i:s', strtotime($r['punched_at'])) ?></td>
      <td><?= h(PUNCH_LABELS[$r['type']] ?? $r['type']) ?><?php if ($r['adjusted']): ?> <span class="badge b-overtime" style="font-size:11px">ajustado pelo ADM</span><?php endif; ?></td>
      <td><?= h($r['site_name'] ?: '—') ?><br><span class="muted small"><?= h($r['site_address']) ?></span></td>
      <td><?php if ($r['lat'] !== null): ?><a target="_blank" rel="noopener" href="<?= h(gmaps_url((float)$r['lat'], (float)$r['lng'])) ?>"><?= number_format((float)$r['lat'], 5) ?>, <?= number_format((float)$r['lng'], 5) ?></a><?php if (!empty($r['geo_address'])): ?><br><span class="muted small"><?= h($r['geo_address']) ?></span><?php endif; ?><?php else: ?><span class="muted">lançamento manual</span><?php endif; ?></td>
      <td><?php if ($r['photo']): ?><a target="_blank" href="photo.php?id=<?= (int)$r['id'] ?>">ver foto</a><?php else: ?>—<?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
<?php $aud = audit_list($uid, 30); if ($aud): ?>
<div class="card">
  <h2>Ajustes feitos pelo administrador</h2>
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
