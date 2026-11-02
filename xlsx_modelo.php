<?php
declare(strict_types=1);

/**
 * Gera o Excel de gastos usando o modelo padrão (templates/modelo_gastos.xlsx: logo, título,
 * campo "Nome:", cabeçalho e colunas). Só o miolo da planilha é reescrito; logo e formatação
 * do modelo são preservados. Retorna o caminho de um arquivo temporário .xlsx, ou null se não der.
 */
function build_expense_xlsx(array $rows, string $name, string $tplPath): ?string
{
    if (!class_exists('ZipArchive') || !is_file($tplPath)) return null;
    $in = new ZipArchive();
    if ($in->open($tplPath) !== true) return null;
    $sheet = $in->getFromName('xl/worksheets/sheet1.xml');
    $styles = $in->getFromName('xl/styles.xml');
    if ($sheet === false || $styles === false) { $in->close(); return null; }

    $esc = static fn(string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $str = static fn(string $ref, string $v, int $s) => '<c r="' . $ref . '" s="' . $s . '" t="inlineStr"><is><t xml:space="preserve">' . htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is></c>';

    // ---- estilos novos (mesma fonte/fundo do modelo, com borda fina) ----
    $n = (int)preg_match('/<cellXfs count="(\d+)"/', $styles, $mm) ? (int)$mm[1] : 19;
    $base = 'borderId="6" fillId="2" fontId="5" xfId="0" applyAlignment="1" applyBorder="1" applyFill="1" applyFont="1"';
    $new = '<xf ' . $base . ' numFmtId="164" applyNumberFormat="1"><alignment horizontal="center"/></xf>'        // data
         . '<xf ' . $base . ' numFmtId="0"/>'                                                                   // texto
         . '<xf ' . $base . ' numFmtId="165" applyNumberFormat="1"/>'                                           // valor
         . '<xf borderId="6" fillId="2" fontId="4" numFmtId="0" xfId="0" applyAlignment="1" applyBorder="1" applyFill="1" applyFont="1"><alignment horizontal="right"/></xf>'  // "TOTAL"
         . '<xf borderId="6" fillId="2" fontId="4" numFmtId="165" xfId="0" applyBorder="1" applyFill="1" applyFont="1" applyNumberFormat="1"/>'; // soma
    $S_DATE = $n; $S_TXT = $n + 1; $S_MONEY = $n + 2; $S_TLAB = $n + 3; $S_TVAL = $n + 4;
    $styles = preg_replace('/<cellXfs count="\d+">/', '<cellXfs count="' . ($n + 5) . '">', $styles, 1);
    $styles = str_replace('</cellXfs>', $new . '</cellXfs>', $styles);

    // ---- planilha: mantém cabeçalho do modelo (linhas 1–8) e reescreve dados ----
    $p = strpos($sheet, '<sheetData>');
    $q = strpos($sheet, '</sheetData>');
    if ($p === false || $q === false) { $in->close(); return null; }
    $head = substr($sheet, 0, $p + 11);
    $tail = substr($sheet, $q);
    preg_match_all('/<row r="(\d+)".*?<\/row>/s', substr($sheet, $p, $q - $p), $rm, PREG_SET_ORDER);
    $tpl = [];
    foreach ($rm as $r) $tpl[(int)$r[1]] = $r[0];

    $out = '';
    for ($i = 1; $i <= 8; $i++) {
        $row = $tpl[$i] ?? '';
        if ($i === 6) $row = str_replace('<c r="B6" s="7"/>', $str('B6', $name, 7), $row);
        if ($i === 8) $row = preg_replace('/<c r="E8" s="9" t="s"><v>\d+<\/v><\/c>/', $str('E8', 'Cidade/UF', 9), $row);
        if ($i === 4) $row = str_replace('<row r="4" ht="14.25"', '<row r="4" ht="26"', $row);
        $out .= $row;
    }
    $r = 9;
    $total = 0;
    foreach ($rows as $x) {
        $serial = (int)floor(strtotime($x['date'] . ' 12:00:00 UTC') / 86400) + 25569;
        $out .= '<row r="' . $r . '" ht="14.25" customHeight="1">'
            . '<c r="A' . $r . '" s="' . $S_DATE . '"><v>' . $serial . '</v></c>'
            . $str("B$r", (string)$x['supplier'], $S_TXT)
            . $str("C$r", (string)$x['description'], $S_TXT)
            . $str("D$r", (string)$x['department'], $S_TXT)
            . $str("E$r", $x['city'] . '/' . $x['state'], $S_TXT)
            . $str("F$r", (string)$x['project'], $S_TXT)
            . '<c r="G' . $r . '" s="' . $S_MONEY . '"><v>' . number_format($x['amount_cents'] / 100, 2, '.', '') . '</v></c></row>';
        $total += (int)$x['amount_cents'];
        $r++;
    }
    if ($rows) {
        $out .= '<row r="' . $r . '" ht="14.25" customHeight="1">' . $str("F$r", 'TOTAL', $S_TLAB)
            . '<c r="G' . $r . '" s="' . $S_TVAL . '"><f>SUM(G9:G' . ($r - 1) . ')</f><v>' . number_format($total / 100, 2, '.', '') . '</v></c></row>';
        $r++;
    }
    // sem linhas de fundo: esconde a grade (como o modelo, que é todo branco) e imprime em 1 página de largura, paisagem
    $head = str_replace('<sheetView workbookViewId="0"/>', '<sheetView showGridLines="0" workbookViewId="0"/>', $head);
    $head = str_replace('<pageSetUpPr/>', '<pageSetUpPr fitToPage="1"/>', $head);
    $tail = str_replace('<pageSetup paperSize="9" orientation="portrait"/>', '<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/>', $tail);
    $sheet = $head . $out . $tail;

    // ---- empacota: tudo do modelo, trocando só sheet1 e styles ----
    $wb = $in->getFromName('xl/workbook.xml');
    if ($wb !== false) {   // área de impressão só A:G (o modelo tem colunas vazias até Y)
        $wb = str_replace('<definedNames/>', '<definedNames><definedName name="_xlnm.Print_Area" localSheetId="0">\'Relatório\'!$A$1:$G$' . max(9, $r - 1) . '</definedName></definedNames>', $wb);
    }
    $tmp = tempnam(sys_get_temp_dir(), 'xl');
    $z = new ZipArchive();
    if ($z->open($tmp, ZipArchive::OVERWRITE) !== true) { $in->close(); return null; }
    for ($i = 0; $i < $in->numFiles; $i++) {
        $nm = $in->getNameIndex($i);
        if ($nm === 'xl/worksheets/sheet1.xml') $z->addFromString($nm, $sheet);
        elseif ($nm === 'xl/styles.xml') $z->addFromString($nm, $styles);
        elseif ($nm === 'xl/workbook.xml' && $wb !== false) $z->addFromString($nm, $wb);
        else $z->addFromString($nm, (string)$in->getFromIndex($i));
    }
    $in->close();
    $z->close();
    return $tmp;
}
