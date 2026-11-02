<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

// Relatório de Gastos: calendário + lançamento de despesas com foto das notas.
// Cada pessoa vê/edita os próprios gastos; o administrador vê (e pode editar) os de todos.
const BR_STATES = [
    'AC' => 'Acre', 'AL' => 'Alagoas', 'AP' => 'Amapá', 'AM' => 'Amazonas', 'BA' => 'Bahia', 'CE' => 'Ceará',
    'DF' => 'Distrito Federal', 'ES' => 'Espírito Santo', 'GO' => 'Goiás', 'MA' => 'Maranhão', 'MT' => 'Mato Grosso',
    'MS' => 'Mato Grosso do Sul', 'MG' => 'Minas Gerais', 'PA' => 'Pará', 'PB' => 'Paraíba', 'PR' => 'Paraná',
    'PE' => 'Pernambuco', 'PI' => 'Piauí', 'RJ' => 'Rio de Janeiro', 'RN' => 'Rio Grande do Norte',
    'RS' => 'Rio Grande do Sul', 'RO' => 'Rondônia', 'RR' => 'Roraima', 'SC' => 'Santa Catarina',
    'SP' => 'São Paulo', 'SE' => 'Sergipe', 'TO' => 'Tocantins',
];
const MAX_FILES_PER_EXPENSE = 8;

$me = require_login();
$isAdmin = $me['role'] === 'admin';
$pdo = db();

function uploaded_files(string $key): array
{
    if (empty($_FILES[$key])) return [];
    $f = $_FILES[$key];
    $out = [];
    if (!is_array($f['name'])) return ($f['error'] === UPLOAD_ERR_NO_FILE) ? [] : [$f];
    foreach ($f['name'] as $i => $n) {
        if ($f['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
        $out[] = ['name' => $n, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    }
    return $out;
}
function load_expense(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM expenses WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}
function can_touch(array $e, array $me): bool
{
    return $me['role'] === 'admin' || (int)$e['user_id'] === (int)$me['id'];
}
function clean(string $s): string
{
    return preg_replace('/\s+/u', ' ', trim($s));
}

// ====================== POST: salvar / excluir ======================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
    $uf = $isAdmin ? (int)($_POST['uf'] ?? 0) : 0;
    $back = static fn(string $date) => 'gastos.php?' . http_build_query(array_filter(['m' => substr($date, 0, 7), 'd' => $date, 'user' => $uf ?: null]));
    $redirect = $back(valid_date($_POST['date'] ?? null) ? $_POST['date'] : date('Y-m-d'));
    $saved = [];
    try {
        $action = (string)($_POST['action'] ?? 'save');
        $id = (int)($_POST['id'] ?? 0);

        if ($action === 'delete') {
            $e = load_expense($id);
            if (!$e || !can_touch($e, $me)) throw new RuntimeException('Gasto não encontrado.');
            $fs = $pdo->prepare('SELECT file FROM expense_files WHERE expense_id = ?');
            $fs->execute([$id]);
            $names = array_column($fs->fetchAll(), 'file');
            $pdo->prepare('DELETE FROM expense_files WHERE expense_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM expenses WHERE id = ?')->execute([$id]);
            foreach ($names as $n) @unlink(UPLOAD_DIR . '/notas/' . basename($n));
            $redirect = $back($e['date']);
            flash('ok', 'Gasto excluído.');

        } else {
            $date = (string)($_POST['date'] ?? '');
            if (!valid_date($date)) throw new RuntimeException('Informe a data do gasto.');
            if ($date > date('Y-m-d')) throw new RuntimeException('A data do gasto não pode ser no futuro.');
            $supplier = clean((string)($_POST['supplier'] ?? ''));
            $desc = clean((string)($_POST['description'] ?? ''));
            $dept = clean((string)($_POST['department'] ?? ''));
            $city = clean((string)($_POST['city'] ?? ''));
            $state = strtoupper(trim((string)($_POST['state'] ?? '')));
            $project = clean((string)($_POST['project'] ?? ''));
            $cents = (int)round(parse_money((string)($_POST['amount'] ?? '')) * 100);

            if (strlen($supplier) < 2 || strlen($supplier) > 120) throw new RuntimeException('Informe o fornecedor.');
            if (strlen($desc) < 3 || strlen($desc) > 500) throw new RuntimeException('Descreva a despesa (3 a 500 caracteres).');
            if ($dept === '' || strlen($dept) > 80) throw new RuntimeException('Informe o departamento.');
            if (strlen($city) < 2 || strlen($city) > 80) throw new RuntimeException('Informe a cidade.');
            if (!isset(BR_STATES[$state])) throw new RuntimeException('Escolha o estado.');
            if ($project === '' || strlen($project) > 120) throw new RuntimeException('Informe o projeto.');
            if ($cents <= 0 || $cents > 1000000000) throw new RuntimeException('Informe um valor maior que zero (ex.: 125,90).');

            $existing = [];
            $owner = (int)$me['id'];
            if ($id) {
                $e = load_expense($id);
                if (!$e || !can_touch($e, $me)) throw new RuntimeException('Gasto não encontrado.');
                $owner = (int)$e['user_id'];
                $q = $pdo->prepare('SELECT * FROM expense_files WHERE expense_id = ?');
                $q->execute([$id]);
                $existing = $q->fetchAll();
            }
            $remove = array_map('intval', (array)($_POST['remove'] ?? []));
            $removeRows = array_values(array_filter($existing, fn($r) => in_array((int)$r['id'], $remove, true)));
            $new = uploaded_files('files');
            if (count($existing) - count($removeRows) + count($new) > MAX_FILES_PER_EXPENSE) {
                throw new RuntimeException('No máximo ' . MAX_FILES_PER_EXPENSE . ' fotos/arquivos por gasto.');
            }

            // Salva os arquivos primeiro; se algum falhar, descarta os que já entraram.
            try {
                foreach ($new as $f) {
                    [$name, $mime] = save_attachment_to($f, 'notas');
                    $saved[] = ['file' => $name, 'mime' => $mime, 'orig' => substr((string)$f['name'], 0, 120)];
                }
            } catch (RuntimeException $e2) {
                foreach ($saved as $s) @unlink(UPLOAD_DIR . '/notas/' . $s['file']);
                $saved = [];
                throw $e2;
            }

            $now = date('Y-m-d H:i:s');
            $pdo->beginTransaction();
            try {
                if ($id) {
                    $pdo->prepare('UPDATE expenses SET date=?, supplier=?, description=?, department=?, city=?, state=?, project=?, amount_cents=?, updated_at=? WHERE id=?')
                        ->execute([$date, $supplier, $desc, $dept, $city, $state, $project, $cents, $now, $id]);
                } else {
                    $pdo->prepare('INSERT INTO expenses (user_id, date, supplier, description, department, city, state, project, amount_cents, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
                        ->execute([$owner, $date, $supplier, $desc, $dept, $city, $state, $project, $cents, $now]);
                    $id = (int)$pdo->lastInsertId();
                }
                $ins = $pdo->prepare('INSERT INTO expense_files (expense_id, file, mime, orig_name, created_at) VALUES (?,?,?,?,?)');
                foreach ($saved as $s) $ins->execute([$id, $s['file'], $s['mime'], $s['orig'], $now]);
                $del = $pdo->prepare('DELETE FROM expense_files WHERE id = ?');
                foreach ($removeRows as $r) $del->execute([$r['id']]);
                $pdo->commit();
            } catch (Throwable $e3) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                foreach ($saved as $s) @unlink(UPLOAD_DIR . '/notas/' . $s['file']);
                throw new RuntimeException('Não foi possível salvar o gasto. Tente novamente.');
            }
            foreach ($removeRows as $r) @unlink(UPLOAD_DIR . '/notas/' . basename($r['file']));
            $redirect = $back($date);
            flash('ok', 'Gasto salvo' . ($new ? ' com ' . count($new) . ' nota(s) anexada(s)' : '') . '.');
        }
        if ($ajax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => true, 'redirect' => $redirect]); exit; }
    } catch (RuntimeException $e) {
        if ($ajax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE); exit; }
        flash('err', $e->getMessage());
    }
    header('Location: ' . $redirect);
    exit;
}

// ====================== Visualização ======================
$m = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)($_GET['m'] ?? '')) ? $_GET['m'] : date('Y-m');
$first = new DateTimeImmutable($m . '-01');
$dim = (int)$first->format('t');
$lead = (int)$first->format('w'); // domingo = 0
$prevM = $first->modify('-1 month')->format('Y-m');
$nextM = $first->modify('+1 month')->format('Y-m');
$from = $m . '-01';
$to = $m . '-' . sprintf('%02d', $dim);
$sel = (valid_date($_GET['d'] ?? null) && substr($_GET['d'], 0, 7) === $m) ? $_GET['d'] : (date('Y-m') === $m ? date('Y-m-d') : $from);
$uf = $isAdmin ? (int)($_GET['user'] ?? 0) : (int)$me['id'];   // 0 = todos (só admin)
$ufParam = $isAdmin && $uf ? ['user' => $uf] : [];
$link = static fn(array $p) => 'gastos.php?' . http_build_query($p);

$where = 'e.date BETWEEN ? AND ?';
$params = [$from, $to];
if ($uf) { $where .= ' AND e.user_id = ?'; $params[] = $uf; }

$st = $pdo->prepare("SELECT e.date, COUNT(*) n, SUM(e.amount_cents) s FROM expenses e WHERE $where GROUP BY e.date");
$st->execute($params);
$byDay = [];
foreach ($st->fetchAll() as $r) $byDay[$r['date']] = $r;

$st = $pdo->prepare("SELECT e.*, u.name AS user_name FROM expenses e JOIN users u ON u.id = e.user_id WHERE $where ORDER BY e.date, e.id");
$st->execute($params);
$month = $st->fetchAll();
$filesBy = [];
if ($month) {
    $ids = implode(',', array_map(fn($r) => (int)$r['id'], $month));
    foreach ($pdo->query("SELECT * FROM expense_files WHERE expense_id IN ($ids) ORDER BY id")->fetchAll() as $f) $filesBy[(int)$f['expense_id']][] = $f;
}
$dayRows = array_values(array_filter($month, fn($r) => $r['date'] === $sel));
$monthTotal = (int)array_sum(array_column($month, 'amount_cents'));
$notesMonth = 0; $notesDay = 0;
foreach ($month as $r) { $n = count($filesBy[(int)$r['id']] ?? []); $notesMonth += $n; if ($r['date'] === $sel) $notesDay += $n; }
$byProject = []; $byDept = [];
foreach ($month as $r) {
    $byProject[$r['project']] = ($byProject[$r['project']] ?? 0) + (int)$r['amount_cents'];
    $byDept[$r['department']] = ($byDept[$r['department']] ?? 0) + (int)$r['amount_cents'];
}
arsort($byProject); arsort($byDept);

// edição
$edit = null;
if (isset($_GET['edit'])) {
    $e = load_expense((int)$_GET['edit']);
    if ($e && can_touch($e, $me)) $edit = $e;
}
$fv = $edit ?? ['id' => 0, 'date' => $sel, 'supplier' => '', 'description' => '', 'department' => '', 'city' => '', 'state' => '', 'project' => '', 'amount_cents' => 0];
$editFiles = $edit ? ($pdo->query('SELECT * FROM expense_files WHERE expense_id = ' . (int)$edit['id'])->fetchAll()) : [];

// sugestões (valores já usados por você + nomes de obras)
$own = (int)$me['id'];
$sug = static function (string $col) use ($pdo, $own): array {
    $q = $pdo->prepare("SELECT DISTINCT $col AS v FROM expenses WHERE user_id = ? ORDER BY $col LIMIT 100");
    $q->execute([$own]);
    return array_column($q->fetchAll(), 'v');
};
$sugProject = array_values(array_unique(array_merge($sug('project'), array_column($pdo->query('SELECT name FROM sites WHERE active = 1 ORDER BY name')->fetchAll(), 'name'))));
$employees = $isAdmin ? $pdo->query('SELECT id, name FROM users ORDER BY name')->fetchAll() : [];

$meses = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
$exp = http_build_query(['m' => $m] + $ufParam);

// Seleção para exportar: mês atual + mês anterior (independe do mês aberto no calendário)
$curM = date('Y-m');
$prevCurM = date('Y-m', strtotime('first day of last month'));
$wSel = 'e.date BETWEEN ? AND ?';
$pSel = [$prevCurM . '-01', date('Y-m-t', strtotime($curM . '-01'))];
if ($uf) { $wSel .= ' AND e.user_id = ?'; $pSel[] = $uf; }
$st = $pdo->prepare("SELECT e.*, u.name AS user_name, (SELECT COUNT(*) FROM expense_files f WHERE f.expense_id = e.id) AS nfiles
                     FROM expenses e JOIN users u ON u.id = e.user_id WHERE $wSel ORDER BY e.date DESC, e.id DESC");
$st->execute($pSel);
$selRows = $st->fetchAll();

page_header('Relatório de Gastos', $me);
flash_html();
?>
<style>
.cal{display:grid;grid-template-columns:repeat(7,1fr);gap:4px}
.cal .dow{font-size:12px;color:var(--muted);text-align:center;font-weight:600;padding:4px 0}
.cal .cell{min-height:62px;border:1px solid var(--line);border-radius:8px;padding:4px 6px;text-decoration:none;color:var(--ink);background:var(--card);display:flex;flex-direction:column;gap:2px;overflow:hidden}
.cal .cell.empty{border:0;background:transparent}
.cal .cell:hover{border-color:var(--brand)}
.cal .cell.today .dn{background:var(--brand);color:#fff;border-radius:99px;padding:0 6px}
.cal .cell.sel{border:2px solid var(--brand);background:#eef3ff}
.cal .dn{font-weight:700;font-size:13px;align-self:flex-start}
.cal .cn{font-size:11px;color:var(--muted)}.cal .cv{font-size:12px;font-weight:700;color:var(--ok)}
@media(max-width:600px){.cal .cell{min-height:52px;padding:3px}.cal .cn{display:none}.cal .cv{font-size:10px}}
.monthnav{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px}.monthnav h2{margin:0;flex:1;min-width:160px}
.thumbs{display:flex;gap:6px;flex-wrap:wrap}.thumbs img{height:44px;width:44px;object-fit:cover;border-radius:6px;border:1px solid var(--line)}
.fileico{display:inline-flex;align-items:center;justify-content:center;height:44px;width:44px;border:1px solid var(--line);border-radius:6px;font-size:11px;font-weight:700;color:var(--bad)}
.prev{display:flex;gap:8px;flex-wrap:wrap;margin:8px 0}.prev .p{position:relative}.prev img{height:70px;width:70px;object-fit:cover;border-radius:8px;border:1px solid var(--line)}
.prev button{position:absolute;top:-6px;right:-6px;padding:0;width:22px;height:22px;border-radius:99px;background:var(--bad);line-height:22px;text-align:center}
.warn{color:var(--warn);font-weight:600}
</style>

<div class="card">
  <div class="monthnav">
    <a class="btn sec" style="padding:6px 12px" href="<?= h($link(['m' => $prevM] + $ufParam)) ?>">‹</a>
    <h2><?= h($meses[(int)$first->format('n')]) ?> de <?= $first->format('Y') ?></h2>
    <a class="btn sec" style="padding:6px 12px" href="<?= h($link(['m' => $nextM] + $ufParam)) ?>">›</a>
    <a class="btn sec" style="padding:6px 12px" href="<?= h($link(['m' => date('Y-m'), 'd' => date('Y-m-d')] + $ufParam)) ?>">Hoje</a>
  </div>
  <?php if ($isAdmin): ?>
  <form method="get" style="margin-bottom:10px">
    <input type="hidden" name="m" value="<?= h($m) ?>"><input type="hidden" name="d" value="<?= h($sel) ?>">
    <label>Ver gastos de</label>
    <select name="user" onchange="this.form.submit()">
      <option value="0">Todos os funcionários</option>
      <?php foreach ($employees as $e): ?><option value="<?= (int)$e['id'] ?>" <?= (int)$e['id'] === $uf ? 'selected' : '' ?>><?= h($e['name']) ?></option><?php endforeach; ?>
    </select>
  </form>
  <?php endif; ?>
  <div class="cal">
    <?php foreach (['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'] as $d): ?><div class="dow"><?= $d ?></div><?php endforeach; ?>
    <?php for ($i = 0; $i < $lead; $i++): ?><div class="cell empty"></div><?php endfor; ?>
    <?php for ($d = 1; $d <= $dim; $d++): $ds = sprintf('%s-%02d', $m, $d); $info = $byDay[$ds] ?? null; ?>
      <a class="cell<?= $ds === $sel ? ' sel' : '' ?><?= $ds === date('Y-m-d') ? ' today' : '' ?>" href="<?= h($link(['m' => $m, 'd' => $ds] + $ufParam)) ?>#novo">
        <span class="dn"><?= $d ?></span>
        <?php if ($info): ?><span class="cn"><?= (int)$info['n'] ?> gasto<?= (int)$info['n'] > 1 ? 's' : '' ?></span><span class="cv"><?= h(money((int)$info['s'] / 100)) ?></span><?php endif; ?>
      </a>
    <?php endfor; ?>
  </div>
  <p class="muted small" style="margin:10px 0 0">Toque em um dia para lançar ou ver os gastos dele. Total do mês: <b><?= h(money($monthTotal / 100)) ?></b></p>
</div>

<div class="card" id="novo">
  <h2><?= $edit ? 'Editar gasto #' . (int)$edit['id'] : 'Novo gasto' ?>
    <span class="muted small">— <?= h(date('d/m/Y', strtotime($fv['date']))) ?></span></h2>
  <div id="fmsg"></div>
  <form method="post" action="gastos.php" id="gf" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$fv['id'] ?>">
    <input type="hidden" name="uf" value="<?= (int)$uf ?>">
    <div class="row">
      <div><label>Data</label><input type="date" name="date" value="<?= h($fv['date']) ?>" max="<?= date('Y-m-d') ?>" required></div>
      <div><label>Fornecedor</label><input name="supplier" value="<?= h($fv['supplier']) ?>" required maxlength="120" list="l_sup"></div>
      <div><label>Valor (R$)</label><input name="amount" value="<?= $fv['amount_cents'] ? h(number_format($fv['amount_cents'] / 100, 2, ',', '.')) : '' ?>" required inputmode="decimal" placeholder="0,00"></div>
    </div>
    <label>Descrição da despesa</label>
    <textarea name="description" rows="2" required maxlength="500" placeholder="Ex.: almoço da equipe, combustível, material elétrico"><?= h($fv['description']) ?></textarea>
    <div class="row">
      <div><label>Departamento</label><input name="department" value="<?= h($fv['department']) ?>" required maxlength="80" list="l_dep"></div>
      <div><label>Projeto</label><input name="project" value="<?= h($fv['project']) ?>" required maxlength="120" list="l_proj"></div>
      <div><label>Cidade</label><input name="city" value="<?= h($fv['city']) ?>" required maxlength="80" list="l_city"></div>
      <div><label>Estado</label>
        <select name="state" required><option value="">Selecione</option>
          <?php foreach (BR_STATES as $k => $n): ?><option value="<?= $k ?>" <?= $fv['state'] === $k ? 'selected' : '' ?>><?= $k ?> — <?= h($n) ?></option><?php endforeach; ?>
        </select></div>
    </div>
    <datalist id="l_sup"><?php foreach ($sug('supplier') as $v): ?><option value="<?= h($v) ?>"><?php endforeach; ?></datalist>
    <datalist id="l_dep"><?php foreach ($sug('department') as $v): ?><option value="<?= h($v) ?>"><?php endforeach; ?></datalist>
    <datalist id="l_city"><?php foreach ($sug('city') as $v): ?><option value="<?= h($v) ?>"><?php endforeach; ?></datalist>
    <datalist id="l_proj"><?php foreach ($sugProject as $v): ?><option value="<?= h($v) ?>"><?php endforeach; ?></datalist>

    <label>Fotos das notas</label>
    <?php if ($editFiles): ?>
      <div class="thumbs" style="margin-bottom:6px">
        <?php foreach ($editFiles as $f): ?>
        <label style="display:flex;flex-direction:column;align-items:center;font-weight:400;font-size:12px;margin:0">
          <?php if ($f['mime'] === 'application/pdf'): ?><span class="fileico">PDF</span><?php else: ?><img src="gasto_file.php?id=<?= (int)$f['id'] ?>" alt="nota"><?php endif; ?>
          <span><input type="checkbox" name="remove[]" value="<?= (int)$f['id'] ?>" style="width:auto"> remover</span>
        </label>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <input type="file" id="cam" accept="image/*" capture="environment" hidden>
    <input type="file" id="pick" accept="image/*,application/pdf" multiple hidden>
    <button type="button" class="btn sec" id="btnCam">📷 Tirar foto da nota</button>
    <button type="button" class="btn sec" id="btnPick">🖼 Escolher da galeria / PDF</button>
    <div class="prev" id="prev"></div>
    <p class="muted small" style="margin:0">As fotos são reduzidas automaticamente para economizar espaço. Até <?= MAX_FILES_PER_EXPENSE ?> arquivos por gasto.</p>
    <p style="margin-top:14px"><button type="submit" id="save"><?= $edit ? 'Salvar alterações' : 'Salvar gasto' ?></button>
      <?php if ($edit): ?><a class="btn sec" href="<?= h($link(['m' => $m, 'd' => $edit['date']] + $ufParam)) ?>">Cancelar</a><?php endif; ?></p>
  </form>
</div>

<div class="card">
  <h2>Gastos de <?= h(date('d/m/Y', strtotime($sel))) ?> <span class="muted small">(<?= count($dayRows) ?>)</span></h2>
  <?php if ($notesDay): ?><p><a class="btn sec" target="_blank" href="gastos_notas_pdf.php?<?= h(http_build_query(['d' => $sel] + $ufParam)) ?>">🧾 PDF das notas deste dia (<?= $notesDay ?>)</a></p><?php endif; ?>
  <?php if (!$dayRows): ?><p class="muted">Nenhum gasto neste dia.</p><?php else: ?>
  <table>
    <tr><th>Fornecedor / descrição</th><th>Depto / projeto</th><th>Local</th><th>Valor</th><th>Notas</th><th></th></tr>
    <?php foreach ($dayRows as $r): $fl = $filesBy[(int)$r['id']] ?? []; ?>
    <tr>
      <td><b><?= h($r['supplier']) ?></b><br><span class="muted small"><?= h($r['description']) ?></span><?= $isAdmin ? '<br><span class="muted small">por ' . h($r['user_name']) . '</span>' : '' ?></td>
      <td><?= h($r['department']) ?><br><span class="muted small"><?= h($r['project']) ?></span></td>
      <td><?= h($r['city']) ?>/<?= h($r['state']) ?></td>
      <td><b><?= h(money((int)$r['amount_cents'] / 100)) ?></b></td>
      <td><?php if (!$fl): ?><span class="warn small">sem nota</span><?php else: ?><div class="thumbs"><?php foreach ($fl as $f): ?>
        <a target="_blank" href="gasto_file.php?id=<?= (int)$f['id'] ?>"><?= $f['mime'] === 'application/pdf' ? '<span class="fileico">PDF</span>' : '<img src="gasto_file.php?id=' . (int)$f['id'] . '" alt="nota">' ?></a>
      <?php endforeach; ?></div><?php endif; ?></td>
      <td style="white-space:nowrap">
        <a href="<?= h($link(['m' => $m, 'd' => $r['date'], 'edit' => $r['id']] + $ufParam)) ?>#novo">Editar</a>
        <form method="post" style="display:inline" onsubmit="return confirm('Excluir este gasto e as fotos das notas?')">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <input type="hidden" name="date" value="<?= h($r['date']) ?>"><input type="hidden" name="uf" value="<?= (int)$uf ?>">
          <button type="submit" class="btn sec" style="padding:2px 10px;color:var(--bad);border-color:var(--bad)">Excluir</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>

<div class="card" id="exportar">
  <h2>Exportar gastos selecionados</h2>
  <?php if (!$selRows): ?><p class="muted">Nenhum gasto neste mês nem no anterior.</p><?php else: ?>
  <form method="post" id="selform" target="_blank">
    <p class="small muted">Marque os gastos que quer levar para o PDF ou para o Excel.</p>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:8px">
      <label>Período
        <select id="selmes">
          <option value="all">Mês atual + anterior</option>
          <option value="<?= h($curM) ?>"><?= h($meses[(int)date('n', strtotime($curM . '-01'))]) ?> (mês atual)</option>
          <option value="<?= h($prevCurM) ?>"><?= h($meses[(int)date('n', strtotime($prevCurM . '-01'))]) ?> (mês anterior)</option>
        </select></label>
      <label style="display:flex;align-items:center;gap:6px"><input type="checkbox" id="selall"> Marcar todos do período</label>
      <label style="display:flex;align-items:center;gap:6px"><input type="checkbox" id="selnotes"> Só os que têm nota</label>
      <b id="selcount" class="small"></b>
    </div>
    <div style="max-height:340px;overflow:auto">
    <table>
      <tr><th></th><th>Data</th><?= $isAdmin ? '<th>Funcionário</th>' : '' ?><th>Fornecedor</th><th>Projeto</th><th>Valor</th><th>Notas</th></tr>
      <?php foreach ($selRows as $r): ?>
      <tr class="selrow" data-m="<?= h(substr($r['date'], 0, 7)) ?>" data-n="<?= (int)$r['nfiles'] ?>" data-c="<?= (int)$r['amount_cents'] ?>">
        <td><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" aria-label="Selecionar gasto"></td>
        <td><?= date('d/m', strtotime($r['date'])) ?></td>
        <?= $isAdmin ? '<td>' . h($r['user_name']) . '</td>' : '' ?>
        <td><?= h($r['supplier']) ?></td><td><?= h($r['project']) ?></td>
        <td><?= h(money((int)$r['amount_cents'] / 100)) ?></td>
        <td><?= (int)$r['nfiles'] ?: '<span class="warn">0</span>' ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    </div>
    <p style="margin-top:10px">
      <button class="btn" type="submit" formaction="gastos_notas_pdf.php" data-need="notes">🧾 PDF das notas selecionadas</button>
      <button class="btn sec" type="submit" formaction="gastos_export.php?fmt=pdf">📄 PDF da tabela</button>
      <button class="btn sec" type="submit" formaction="gastos_export.php?fmt=xlsx">📊 Excel (.xlsx)</button>
    </p>
    <p id="selerr" class="small warn"></p>
  </form>
  <script>
  (function () {
    const rows = [...document.querySelectorAll('.selrow')], mes = document.getElementById('selmes'),
          all = document.getElementById('selall'), onlyN = document.getElementById('selnotes'),
          cnt = document.getElementById('selcount'), err = document.getElementById('selerr');
    const brl = c => (c / 100).toLocaleString('pt-BR', {style: 'currency', currency: 'BRL'});
    const vis = r => !r.hidden;
    function filter() {
      rows.forEach(r => {
        const ok = (mes.value === 'all' || r.dataset.m === mes.value) && (!onlyN.checked || +r.dataset.n > 0);
        r.hidden = !ok;
        if (!ok) r.querySelector('input').checked = false;
      });
      all.checked = false; count();
    }
    function count() {
      const ch = rows.filter(r => vis(r) && r.querySelector('input').checked);
      cnt.textContent = ch.length + ' selecionado(s) — ' + brl(ch.reduce((s, r) => s + +r.dataset.c, 0));
      err.textContent = '';
    }
    mes.onchange = onlyN.onchange = filter;
    all.onchange = () => { rows.filter(vis).forEach(r => r.querySelector('input').checked = all.checked); count(); };
    rows.forEach(r => r.querySelector('input').onchange = count);
    document.getElementById('selform').addEventListener('submit', e => {
      const ch = rows.filter(r => vis(r) && r.querySelector('input').checked);
      const btn = e.submitter;
      if (!ch.length) { e.preventDefault(); err.textContent = 'Marque pelo menos um gasto.'; return; }
      if (btn && btn.dataset.need === 'notes' && !ch.some(r => +r.dataset.n > 0)) { e.preventDefault(); err.textContent = 'Nenhum dos gastos marcados tem nota anexada.'; }
    });
    count();
  })();
  </script>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Resumo do mês — <?= h($meses[(int)$first->format('n')]) ?>/<?= $first->format('Y') ?></h2>
  <p>
    <?php if ($notesMonth): ?><a class="btn" target="_blank" href="gastos_notas_pdf.php?<?= h($exp) ?>">🧾 PDF das notas (<?= $notesMonth ?>) — só as fotos</a>
    <?php else: ?><span class="muted small">Nenhuma nota anexada neste mês.</span><?php endif; ?>
    <a class="btn sec" target="_blank" href="gastos_export.php?<?= h($exp) ?>&fmt=pdf">📄 PDF do relatório (tabela)</a>
    <a class="btn sec" href="gastos_export.php?<?= h($exp) ?>&fmt=xlsx">📊 Excel (.xlsx) do mês</a>
    <a class="btn sec" href="gastos_export.php?<?= h($exp) ?>&fmt=csv">⬇ CSV</a>
  </p>
  <?php if (!$month): ?><p class="muted">Nenhum gasto neste mês.</p><?php else: ?>
  <table>
    <tr><th>Data</th><?= $isAdmin ? '<th>Funcionário</th>' : '' ?><th>Fornecedor</th><th>Descrição</th><th>Depto</th><th>Projeto</th><th>Cidade/UF</th><th>Valor</th><th>Notas</th></tr>
    <?php foreach ($month as $r): ?>
    <tr>
      <td><a href="<?= h($link(['m' => $m, 'd' => $r['date']] + $ufParam)) ?>#novo"><?= date('d/m', strtotime($r['date'])) ?></a></td>
      <?= $isAdmin ? '<td>' . h($r['user_name']) . '</td>' : '' ?>
      <td><?= h($r['supplier']) ?></td><td><?= h($r['description']) ?></td><td><?= h($r['department']) ?></td><td><?= h($r['project']) ?></td>
      <td><?= h($r['city']) ?>/<?= h($r['state']) ?></td><td><?= h(money((int)$r['amount_cents'] / 100)) ?></td>
      <td><?= count($filesBy[(int)$r['id']] ?? []) ?: '<span class="warn">0</span>' ?></td>
    </tr>
    <?php endforeach; ?>
    <tr><th colspan="<?= $isAdmin ? 7 : 6 ?>">Total do mês</th><th colspan="2"><?= h(money($monthTotal / 100)) ?></th></tr>
  </table>
  <div class="row" style="margin-top:14px">
    <div><b>Por projeto</b><table><?php foreach ($byProject as $k => $v): ?><tr><td><?= h($k) ?></td><td><?= h(money($v / 100)) ?></td></tr><?php endforeach; ?></table></div>
    <div><b>Por departamento</b><table><?php foreach ($byDept as $k => $v): ?><tr><td><?= h($k) ?></td><td><?= h(money($v / 100)) ?></td></tr><?php endforeach; ?></table></div>
  </div>
  <?php endif; ?>
</div>

<script>
const pics = [];                      // {file, url}
const prev = document.getElementById('prev'), fmsg = document.getElementById('fmsg');
function showMsg(t, ok) { fmsg.innerHTML = '<div class="' + (ok ? 'okmsg' : 'err') + '"></div>'; fmsg.firstChild.textContent = t; }

async function prep(file) {            // reduz foto (máx. 1600px, JPEG) para caber no servidor
  if (file.type === 'application/pdf') return file;
  try {
    const bmp = await createImageBitmap(file);
    const sc = Math.min(1, 1600 / Math.max(bmp.width, bmp.height));
    const c = document.createElement('canvas');
    c.width = Math.round(bmp.width * sc); c.height = Math.round(bmp.height * sc);
    c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
    const blob = await new Promise(r => c.toBlob(r, 'image/jpeg', 0.82));
    return new File([blob], (file.name || 'nota').replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' });
  } catch (e) { return file; }
}
function render() {
  prev.innerHTML = '';
  pics.forEach((p, i) => {
    const d = document.createElement('div'); d.className = 'p';
    if (p.file.type === 'application/pdf') { d.innerHTML = '<span class="fileico">PDF</span>'; }
    else { const im = document.createElement('img'); im.src = p.url; d.appendChild(im); }
    const b = document.createElement('button'); b.type = 'button'; b.textContent = '×'; b.onclick = () => { pics.splice(i, 1); render(); };
    d.appendChild(b); prev.appendChild(d);
  });
}
async function addFiles(list) {
  for (const f of list) { const p = await prep(f); pics.push({ file: p, url: p.type.startsWith('image/') ? URL.createObjectURL(p) : '' }); }
  render();
}
for (const [btn, inp] of [['btnCam', 'cam'], ['btnPick', 'pick']]) {
  const i = document.getElementById(inp);
  document.getElementById(btn).onclick = () => i.click();
  i.onchange = async () => { await addFiles([...i.files]); i.value = ''; };
}
document.getElementById('gf').addEventListener('submit', async ev => {
  ev.preventDefault();
  const btn = document.getElementById('save'); btn.disabled = true; fmsg.innerHTML = '';
  const fd = new FormData(ev.target);
  pics.forEach(p => fd.append('files[]', p.file, p.file.name));
  try {
    const r = await fetch('gastos.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } });
    const j = await r.json();
    if (!j.ok) throw new Error(j.error || 'Erro ao salvar.');
    location.href = j.redirect;
  } catch (e) {
    showMsg(e.message && e.message !== 'Unexpected token' ? e.message : 'Não foi possível salvar. Verifique os campos e o tamanho dos arquivos.', false);
    btn.disabled = false;
  }
});
</script>
<?php page_footer();
