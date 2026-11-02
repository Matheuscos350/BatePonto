<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

/** Gerador de PDF mínimo (texto + linhas), sem dependências externas. */
class SimplePDF
{
    public const W = 595.28, H = 841.89, M = 40;
    private array $pages = [];
    private string $cur = '';
    public float $y;

    public function __construct() { $this->y = self::H - self::M; }

    public function addPage(): void
    {
        $this->pages[] = $this->cur;
        $this->cur = '';
        $this->y = self::H - self::M;
    }

    private function enc(string $s): string
    {
        $s = (string)iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s);
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }

    public function text(float $x, string $s, int $size = 10, bool $bold = false, ?float $y = null): void
    {
        $this->cur .= sprintf("BT /%s %d Tf %.2F %.2F Td (%s) Tj ET\n",
            $bold ? 'F2' : 'F1', $size, $x, $y ?? $this->y, $this->enc($s));
    }

    public function rule(float $gray = 0.7): void
    {
        $this->cur .= sprintf("%.2F G 0.5 w %.2F %.2F m %.2F %.2F l S\n", $gray, self::M, $this->y, self::W - self::M, $this->y);
    }

    /** Escreve uma linha de colunas e avança. */
    public function row(array $cols, array $xs, int $size = 9, bool $bold = false, float $h = 14): void
    {
        $this->need($h);
        foreach ($cols as $i => $c) $this->text($xs[$i], (string)$c, $size, $bold);
        $this->y -= $h;
    }

    public function skip(float $h): void { $this->need($h); $this->y -= $h; }

    public function need(float $h): void
    {
        if ($this->y - $h < self::M + 20) $this->addPage();
    }

    public function output(): string
    {
        $this->pages[] = $this->cur;
        $this->cur = '';
        $pages = array_values(array_filter($this->pages, fn($p, $i) => $p !== '' || $i === 0, ARRAY_FILTER_USE_BOTH));
        $n = count($pages);

        $objs = [];
        $kids = [];
        for ($i = 0; $i < $n; $i++) $kids[] = (5 + 2 * $i) . ' 0 R';
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . "] /Count $n >>";
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        foreach ($pages as $i => $content) {
            $content .= sprintf("BT /F1 8 Tf %.2F %.2F Td (%s) Tj ET\n", self::W / 2 - 25, 22, $this->enc('Página ' . ($i + 1) . ' de ' . $n));
            $pid = 5 + 2 * $i;
            $objs[$pid] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', self::W, self::H, $pid + 1);
            $objs[$pid + 1] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . 'endstream';
        }

        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= "$id 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($out);
        $count = count($objs) + 1;
        $out .= "xref\n0 $count\n0000000000 65535 f \n";
        foreach ($offsets as $off) $out .= sprintf("%010d 00000 n \n", $off);
        $out .= "trailer\n<< /Size $count /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
        return $out;
    }
}

// ====== Execução (quando acessado diretamente) ======
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'pdf.php') {
    $me = require_login();
    $uid = $me['role'] === 'admin' ? (int)($_GET['user'] ?? $me['id']) : (int)$me['id'];
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d', strtotime('monday this week'));
    $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
    if ($to < $from) [$from, $to] = [$to, $from];

    $data = report_data($uid, $from, $to);
    $user = $data['user'];

    $pdf = new SimplePDF();
    $pdf->text(40, 'Relatório de Horas Trabalhadas', 16, true);
    $pdf->skip(20);
    $pdf->text(40, 'Funcionário: ' . $user['name'] . '   |   Login: ' . $user['login'] . ($user['email'] ? '   |   E-mail: ' . $user['email'] : ''), 10);
    $pdf->skip(14);
    $pdf->text(40, 'Período: ' . date('d/m/Y', strtotime($from)) . ' a ' . date('d/m/Y', strtotime($to))
        . '   |   Jornada semanal: ' . rtrim(rtrim(number_format((float)$user['weekly_hours'], 2, ',', ''), '0'), ',') . 'h'
        . '   |   Limite de extras: ' . OVERTIME_LIMIT_PERCENT . '%', 10);
    $pdf->skip(14);
    $pdf->text(40, 'Gerado em ' . date('d/m/Y H:i') . '   |   E = Entrada, SA = Saída para almoço, VA = Volta do almoço, S = Saída (almoço não é contado como trabalhado)', 7);
    $pdf->skip(10);
    $pdf->text(40, '* = marcação ajustada pelo administrador   |   Abono = horas justificadas por atestado (creditadas, não geram hora extra)', 7);
    if (hourly_rate($user) !== null) {
        $pdf->skip(10);
        $pdf->text(40, 'Valor da hora: ' . money(hourly_rate($user)) . '   |   Extras estimadas = horas extras x valor da hora x ' . number_format(1 + OVERTIME_PREMIUM_PERCENT / 100, 2, ',', '') . ' (bruto, sem DSR nem descontos)', 7);
    }
    $pdf->skip(16);

    $xs = [40, 100, 140, 470];
    $grandTotal = 0; $grandOver = 0; $grandPay = 0.0;
    foreach ($data['weeks'] as $w) {
        $pdf->need(140);
        $pdf->rule(0.3);
        $pdf->skip(14);
        $pdf->text(40, 'Semana ' . $w['start']->format('d/m/Y') . ' a ' . $w['end']->format('d/m/Y'), 11, true);
        $pdf->skip(16);
        $pdf->row(['Data', 'Dia', 'Marcações', 'Horas'], $xs, 9, true);
        foreach ($w['days'] as $d => $info) {
            $txt = events_text($info['events'], true);
            if ($info['abono']) $txt .= ($txt ? ' | ' : '') . 'Abono atestado ' . fmt_hm($info['abono']);
            $pdf->row([date('d/m/Y', strtotime($d)), dia_semana($d), cut($txt, 68) ?: '-', fmt_hm($info['seconds'])], $xs, 9);
            if ($info['sites']) {
                $parts = [];
                foreach ($info['sites'] as $sn => $sa) $parts[] = $sn . ' - ' . $sa;
                $pdf->row([cut('Obra: ' . implode(' ; ', $parts), 105)], [140], 7, false, 11);
            }
        }
        $pdf->skip(4);
        $pdf->row(['Total trabalhado:', '', '', fmt_hm($w['total'])], $xs, 10, true);
        if ($w['abono']) {
            $pdf->row(['Horas abonadas (atestado):', '', '', fmt_hm($w['abono'])], $xs, 10);
            $pdf->row(['Total creditado:', '', '', fmt_hm($w['credited'])], $xs, 10);
        }
        $pdf->row(['Jornada contratada:', '', '', fmt_hm($w['contracted'])], $xs, 10);
        $pdf->row(['Horas extras:', '', '', fmt_hm($w['overtime'])], $xs, 10);
        $pay = overtime_pay($w, $user);
        if ($pay !== null) { $pdf->row(['Valor estimado das extras (+' . OVERTIME_PREMIUM_PERCENT . '%):', '', '', money($pay)], $xs, 10, true); $grandPay += $pay; }
        $pdf->row(['Limite de ' . OVERTIME_LIMIT_PERCENT . '% (' . fmt_hm($w['limit_sec']) . '):', '', '', fmt_hm($w['remaining_to_limit']) . ' restantes'], $xs, 10);
        $pdf->row(['Situação: ' . status_label($w)], [40], 10, true);
        if ($w['incomplete']) $pdf->row(['Atenção: há marcações incompletas ou fora de sequência nesta semana.'], [40], 9);
        $pdf->skip(6);
        $grandTotal += $w['total'];
        $grandOver += $w['overtime'];
    }
    $pdf->need(50);
    $pdf->rule(0);
    $pdf->skip(16);
    $pdf->row(['TOTAL DO PERÍODO: ' . fmt_hm($grandTotal) . ' trabalhadas   |   ' . fmt_hm($grandOver) . ' em horas extras'], [40], 11, true);
    if (hourly_rate($user) !== null) $pdf->row(['Estimativa bruta das horas extras no período: ' . money($grandPay)], [40], 11, true);

    $bin = $pdf->output();
    $fname = 'horas_' . preg_replace('/\W+/', '_', $user['login']) . '_' . $from . '_' . $to . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($bin));
    echo $bin;
}
