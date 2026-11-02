<?php
declare(strict_types=1);
require __DIR__ . '/pdf.php';   // carrega config + a classe SimplePDF (não gera PDF de ponto aqui)

$me = require_login();
$isAdmin = $me['role'] === 'admin';
$pdo = db();

$m = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)($_GET['m'] ?? '')) ? $_GET['m'] : date('Y-m');
$from = $m . '-01';
$to = date('Y-m-t', strtotime($from));
$uf = $isAdmin ? (int)($_GET['user'] ?? 0) : (int)$me['id'];

// Seleção específica (select na tela): ids[] — só os gastos marcados
$ids = array_values(array_unique(array_filter(array_map('intval', (array)($_REQUEST['ids'] ?? [])))));
if ($ids) {
    $where = 'e.id IN (' . implode(',', $ids) . ')';
    $params = [];
} else {
    $where = 'e.date BETWEEN ? AND ?';
    $params = [$from, $to];
}
if ($uf) { $where .= ' AND e.user_id = ?'; $params[] = $uf; }
$st = $pdo->prepare("SELECT e.*, u.name AS user_name, (SELECT COUNT(*) FROM expense_files f WHERE f.expense_id = e.id) AS nfiles
                     FROM expenses e JOIN users u ON u.id = e.user_id WHERE $where ORDER BY e.date, e.id");
$st->execute($params);
$rows = $st->fetchAll();

$who = 'Todos os funcionários';
if ($uf) {
    $q = $pdo->prepare('SELECT name FROM users WHERE id = ?');
    $q->execute([$uf]);
    $who = (string)($q->fetchColumn() ?: '—');
}
$total = (int)array_sum(array_column($rows, 'amount_cents'));
$fmt = in_array($_REQUEST['fmt'] ?? '', ['csv', 'xlsx'], true) ? $_REQUEST['fmt'] : 'pdf';
$base = 'relatorio_gastos_' . $m . ($uf ? '_func' . $uf : '');
if ($ids) {
    $dts = array_column($rows, 'date');
    if ($dts) { $from = min($dts); $to = max($dts); }
    $base = 'gastos_selecionados_' . date('Ymd_His');
}
$periodo = $ids
    ? 'Gastos selecionados (' . date('d/m/Y', strtotime($from)) . ' a ' . date('d/m/Y', strtotime($to)) . ')'
    : null;

if ($fmt === 'xlsx') {
    require_once __DIR__ . '/xlsx_modelo.php';
    $names = array_values(array_unique(array_column($rows, 'user_name')));
    $nameTxt = count($names) === 1 ? (string)$names[0] : ($uf ? $who : 'Vários funcionários');
    $tmp = build_expense_xlsx($rows, $nameTxt, __DIR__ . '/templates/modelo_gastos.xlsx');
    if ($tmp) {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $base . '.xlsx"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    }
}
if ($fmt === 'xlsx') $fmt = 'csv';   // servidor sem a extensão zip: entrega CSV (abre no Excel)

if ($fmt === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $base . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM: o Excel abre os acentos certinho
    $o = fopen('php://output', 'w');
    fputcsv($o, ['Data', 'Funcionário', 'Fornecedor', 'Descrição da despesa', 'Departamento', 'Cidade', 'Estado', 'Projeto', 'Valor (R$)', 'Notas anexadas'], ';');
    foreach ($rows as $r) {
        fputcsv($o, [date('d/m/Y', strtotime($r['date'])), $r['user_name'], $r['supplier'], $r['description'], $r['department'],
            $r['city'], $r['state'], $r['project'], number_format($r['amount_cents'] / 100, 2, ',', ''), $r['nfiles']], ';');
    }
    fputcsv($o, ['', '', '', '', '', '', '', 'TOTAL', number_format($total / 100, 2, ',', ''), ''], ';');
    fclose($o);
    exit;
}

// ---------- PDF ----------
$meses = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
$pdf = new SimplePDF();
$pdf->text(40, 'Relatório de Gastos', 16, true);
$pdf->skip(20);
$pdf->text(40, ($periodo ?? 'Período: ' . $meses[(int)date('n', strtotime($from))] . ' de ' . date('Y', strtotime($from))) . '   |   Funcionário: ' . $who, 10);
$pdf->skip(14);
$pdf->text(40, 'Gerado em ' . date('d/m/Y H:i') . '   |   ' . count($rows) . ' lançamento(s)   |   Total: ' . money($total / 100), 8);
$pdf->skip(14);

$xs = [40, 92, 262, 470];
$pdf->rule(0.3);
$pdf->skip(12);
$pdf->row(['Data', 'Fornecedor', 'Projeto', 'Valor'], $xs, 9, true);
$byProject = []; $byDept = [];
foreach ($rows as $r) {
    $pdf->need(30);
    $pdf->row([date('d/m/Y', strtotime($r['date'])), cut($r['supplier'], 36), cut($r['project'], 40), money($r['amount_cents'] / 100)], $xs, 9, false, 11);
    $line = $r['description'] . ' | ' . $r['department'] . ' | ' . $r['city'] . '/' . $r['state'] . ' | notas: ' . $r['nfiles']
        . ($uf ? '' : ' | ' . $r['user_name']);
    $pdf->row([cut($line, 120)], [92], 7, false, 14);
    $byProject[$r['project']] = ($byProject[$r['project']] ?? 0) + (int)$r['amount_cents'];
    $byDept[$r['department']] = ($byDept[$r['department']] ?? 0) + (int)$r['amount_cents'];
}
if (!$rows) $pdf->row(['Nenhum gasto no período.'], [40], 10);

$pdf->need(60);
$pdf->rule(0);
$pdf->skip(16);
$pdf->row(['TOTAL DO PERÍODO: ' . money($total / 100)], [40], 12, true);
foreach (['Por projeto' => $byProject, 'Por departamento' => $byDept] as $title => $arr) {
    if (!$arr) continue;
    arsort($arr);
    $pdf->skip(6);
    $pdf->row([$title], [40], 10, true);
    foreach ($arr as $k => $v) $pdf->row([cut((string)$k, 60), money($v / 100)], [50, 300], 9, false, 12);
}

$bin = $pdf->output();
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $base . '.pdf"');
header('Content-Length: ' . strlen($bin));
echo $bin;
