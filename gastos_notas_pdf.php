<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

// PDF só com as fotos das notas anexadas aos gastos (uma nota por página, com o resumo do gasto no topo).
// Escopo: mês (?m=AAAA-MM) ou um único dia (?d=AAAA-MM-DD). O administrador pode filtrar por funcionário (?user=ID).
$me = require_login();
$isAdmin = $me['role'] === 'admin';
$pdo = db();

if (valid_date($_GET['d'] ?? null)) {
    $from = $to = $_GET['d'];
    $m = substr($from, 0, 7);
} else {
    $m = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)($_GET['m'] ?? '')) ? $_GET['m'] : date('Y-m');
    $from = $m . '-01';
    $to = date('Y-m-t', strtotime($from));
}
$uf = $isAdmin ? (int)($_GET['user'] ?? 0) : (int)$me['id'];

$ids = array_values(array_unique(array_filter(array_map('intval', (array)($_REQUEST['ids'] ?? [])))));
if ($ids) {   // seleção específica feita na tela
    $where = 'e.id IN (' . implode(',', $ids) . ')';
    $params = [];
} else {
    $where = 'e.date BETWEEN ? AND ?';
    $params = [$from, $to];
}
if ($uf) { $where .= ' AND e.user_id = ?'; $params[] = $uf; }
$st = $pdo->prepare("SELECT f.id AS fid, f.file, f.mime, e.*, u.name AS user_name
                     FROM expense_files f JOIN expenses e ON e.id = f.expense_id JOIN users u ON u.id = e.user_id
                     WHERE $where ORDER BY e.date, e.id, f.id");
$st->execute($params);
$items = $st->fetchAll();

if (!$items) {
    flash('err', 'Não há notas anexadas neste período.');
    header('Location: gastos.php?' . http_build_query(array_filter(['m' => $m, 'd' => $from === $to ? $from : null, 'user' => $uf && $isAdmin ? $uf : null])));
    exit;
}

/** Prepara uma imagem para o PDF. Retorna null se não der para incorporar. */
function pdf_image(string $bin, string $mime): ?array
{
    if ($mime === 'image/png' && function_exists('imagecreatefromstring')) {   // PNG → JPEG (fundo branco)
        $im = @imagecreatefromstring($bin);
        if ($im) {
            $w = imagesx($im); $h = imagesy($im);
            $bg = imagecreatetruecolor($w, $h);
            imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));
            imagecopy($bg, $im, 0, 0, 0, 0, $w, $h);
            ob_start(); imagejpeg($bg, null, 85); $bin = (string)ob_get_clean();
            imagedestroy($im); imagedestroy($bg);
            $mime = 'image/jpeg';
        }
    }
    if ($mime === 'image/jpeg') {
        $info = @getimagesizefromstring($bin);
        if (!$info || ($info[2] ?? 0) !== IMAGETYPE_JPEG) return null;
        $ch = (int)($info['channels'] ?? 3);
        return [
            'w' => (int)$info[0], 'h' => (int)$info[1], 'data' => $bin,
            'dict' => '/ColorSpace ' . ($ch === 1 ? '/DeviceGray' : ($ch === 4 ? '/DeviceCMYK /Decode [1 0 1 0 1 0 1 0]' : '/DeviceRGB'))
                . ' /BitsPerComponent 8 /Filter /DCTDecode',
        ];
    }
    if ($mime === 'image/png') {   // sem GD: PNG simples (sem transparência e sem entrelaçamento) vai direto como Flate
        if (strncmp($bin, "\x89PNG\r\n\x1a\n", 8) !== 0) return null;
        $pos = 8; $idat = ''; $plte = ''; $ihdr = null;
        while ($pos + 8 <= strlen($bin)) {
            $len = unpack('N', substr($bin, $pos, 4))[1];
            $type = substr($bin, $pos + 4, 4);
            $data = substr($bin, $pos + 8, $len);
            if ($type === 'IHDR') $ihdr = unpack('Nw/Nh/Cdepth/Cctype/Ccomp/Cfilt/Cinter', $data);
            elseif ($type === 'PLTE') $plte = $data;
            elseif ($type === 'IDAT') $idat .= $data;
            elseif ($type === 'IEND') break;
            $pos += 12 + $len;
        }
        if (!$ihdr || $ihdr['inter'] !== 0 || !in_array($ihdr['ctype'], [0, 2, 3], true)) return null;
        $colors = $ihdr['ctype'] === 2 ? 3 : 1;
        $cs = $ihdr['ctype'] === 2 ? '/DeviceRGB' : ($ihdr['ctype'] === 0 ? '/DeviceGray'
            : '[/Indexed /DeviceRGB ' . (intdiv(strlen($plte), 3) - 1) . ' <' . bin2hex($plte) . '>]');
        return [
            'w' => $ihdr['w'], 'h' => $ihdr['h'], 'data' => $idat,
            'dict' => "/ColorSpace $cs /BitsPerComponent {$ihdr['depth']} /Filter /FlateDecode /DecodeParms << /Predictor 15 /Colors $colors /BitsPerComponent {$ihdr['depth']} /Columns {$ihdr['w']} >>",
        ];
    }
    return null;
}

function enc(string $s): string
{
    $s = (string)iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s);
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
}

// ---------- monta o PDF ----------
const PW = 595.28, PH = 841.89, PM = 40.0;
$meses = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
$periodo = $from === $to ? date('d/m/Y', strtotime($from)) : $meses[(int)date('n', strtotime($from))] . ' de ' . date('Y', strtotime($from));
$total = count($items);

$objs = [];
$nextId = 5;
$add = function (string $body) use (&$objs, &$nextId): int { $id = $nextId++; $objs[$id] = $body; return $id; };
$pageIds = [];
$skipped = 0;

foreach ($items as $i => $it) {
    $head = [
        ['Nota ' . ($i + 1) . ' de ' . $total . ' — ' . $it['supplier'], 13, true],
        [date('d/m/Y', strtotime($it['date'])) . '   |   ' . money($it['amount_cents'] / 100) . '   |   Projeto: ' . $it['project'], 10, false],
        [cut($it['description'], 110), 9, false],
        [$it['department'] . '   |   ' . $it['city'] . '/' . $it['state'] . '   |   ' . $it['user_name'], 9, false],
    ];
    $content = '';
    $y = PH - PM;
    foreach ($head as [$t, $sz, $bold]) {
        $content .= sprintf("BT /%s %d Tf %.2F %.2F Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $sz, PM, $y - $sz, enc(cut($t, 120)));
        $y -= $sz + 5;
    }
    $content .= sprintf("0.7 G 0.5 w %.2F %.2F m %.2F %.2F l S\n", PM, $y - 2, PW - PM, $y - 2);
    $top = $y - 12;

    $xobj = '';
    $img = null;
    $path = UPLOAD_DIR . '/notas/' . basename($it['file']);
    if (in_array($it['mime'], ['image/jpeg', 'image/png'], true) && is_file($path)) {
        $img = pdf_image((string)file_get_contents($path), $it['mime']);
    }
    if ($img) {
        $maxW = PW - 2 * PM; $maxH = $top - (PM + 22);
        $sc = min($maxW / $img['w'], $maxH / $img['h']);
        $dw = $img['w'] * $sc; $dh = $img['h'] * $sc;
        $x = PM + ($maxW - $dw) / 2; $yy = $top - $dh;
        $imgId = $add(sprintf("<< /Type /XObject /Subtype /Image /Width %d /Height %d %s /Length %d >>\nstream\n", $img['w'], $img['h'], $img['dict'], strlen($img['data'])) . $img['data'] . "\nendstream");
        $content .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /Im0 Do Q\n", $dw, $dh, $x, $yy);
        $xobj = "/XObject << /Im0 $imgId 0 R >>";
    } else {
        $skipped++;
        $why = $it['mime'] === 'application/pdf' ? 'Esta nota foi enviada em PDF e não pode ser incorporada aqui.' : 'Não foi possível incorporar esta imagem.';
        $content .= sprintf("BT /F2 11 Tf %.2F %.2F Td (%s) Tj ET\n", PM, $top - 20, enc($why));
        $content .= sprintf("BT /F1 10 Tf %.2F %.2F Td (%s) Tj ET\n", PM, $top - 38, enc('Abra o arquivo original no sistema: Relatório de Gastos > gasto de ' . date('d/m/Y', strtotime($it['date'])) . '.'));
    }
    $content .= sprintf("BT /F1 8 Tf %.2F %.2F Td (%s) Tj ET\n", PW / 2 - 40, 22, enc('Página ' . ($i + 1) . ' de ' . $total));

    $cid = $add("<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream");
    $pageIds[] = $add(sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> %s >> /Contents %d 0 R >>', PW, PH, $xobj, $cid));
}

$objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
$objs[2] = '<< /Type /Pages /Kids [' . implode(' ', array_map(fn($id) => "$id 0 R", $pageIds)) . '] /Count ' . count($pageIds) . ' >>';
$objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
$objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
ksort($objs);

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

$name = 'notas_' . ($from === $to ? $from : $m) . ($uf ? '_func' . $uf : '') . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $name . '"');
header('Content-Length: ' . strlen($out));
echo $out;
