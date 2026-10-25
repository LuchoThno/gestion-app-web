<?php
/**
 * Visor de Word (docx), Excel (xlsx) y PowerPoint (pptx) hecho en PHP.
 * Lee el archivo en el servidor y lo convierte a HTML: no envía documentos a servicios externos
 * ni necesita JavaScript. Es una vista aproximada; el formato exacto se ve descargando el archivo.
 * Requiere las extensiones zip y dom (habituales en Hostinger).
 */
const OFFICE_NS = [
    'w'=>'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
    'a'=>'http://schemas.openxmlformats.org/drawingml/2006/main',
    'r'=>'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
    'p'=>'http://schemas.openxmlformats.org/presentationml/2006/main',
    's'=>'http://schemas.openxmlformats.org/spreadsheetml/2006/main',
    'v'=>'urn:schemas-microsoft-com:vml',
    'pr'=>'http://schemas.openxmlformats.org/package/2006/relationships',
];

/** Estilos generados (posiciones de diapositivas); se imprimen en un <style> con nonce. */
$officeCss = [];
function officeClass(string $css): string {
    global $officeCss;
    $k = 'ox'.substr(md5($css), 0, 10);
    $officeCss[$k] = $css;
    return $k;
}
function officeStyleTag(string $nonce): string {
    global $officeCss;
    if (!$officeCss) return '';
    $out = '';
    foreach ($officeCss as $k => $css) $out .= '.'.$k.'{'.$css.'}';
    return '<style nonce="'.e($nonce).'">'.$out.'</style>';
}

function officeAvailable(): bool { return class_exists('ZipArchive') && class_exists('DOMDocument'); }

function officeOpen(string $path): ZipArchive {
    $z = new ZipArchive();
    if ($z->open($path) !== true) throw new RuntimeException('No se pudo leer el documento.');
    if ($z->numFiles > 5000) { $z->close(); throw new RuntimeException('El documento es demasiado complejo para la vista previa.'); }
    return $z;
}
/** Carga una parte XML del paquete con límites de tamaño y sin DTD. */
function officeXml(ZipArchive $z, string $name, int $max = 31457280): ?DOMXPath {
    $st = $z->statName($name);
    if ($st === false) return null;
    if ($st['size'] > $max) throw new RuntimeException('El documento es demasiado grande para la vista previa.');
    $xml = $z->getFromName($name);
    if (!is_string($xml) || $xml === '') return null;
    if (stripos($xml, '<!DOCTYPE') !== false) throw new RuntimeException('Documento no admitido para vista previa.');
    $d = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok = $d->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
    libxml_clear_errors(); libxml_use_internal_errors($prev);
    if (!$ok) return null;
    $x = new DOMXPath($d);
    foreach (OFFICE_NS as $p => $ns) $x->registerNamespace($p, $ns);
    return $x;
}
/** Relaciones de una parte: id => ['target'=>ruta resuelta, 'type'=>tipo corto]. */
function officeRels(ZipArchive $z, string $part): array {
    $dir = dirname($part); $base = basename($part);
    $x = officeXml($z, ($dir === '.' ? '' : $dir.'/').'_rels/'.$base.'.rels');
    if (!$x) return [];
    $out = [];
    foreach ($x->query('//pr:Relationship') as $r) {
        if ($r->getAttribute('TargetMode') === 'External') continue;
        $t = $r->getAttribute('Target');
        $path = str_starts_with($t, '/') ? ltrim($t, '/') : officeNormalize(($dir === '.' ? '' : $dir.'/').$t);
        $out[$r->getAttribute('Id')] = ['target' => $path, 'type' => basename($r->getAttribute('Type'))];
    }
    return $out;
}
function officeNormalize(string $p): string {
    $parts = [];
    foreach (explode('/', $p) as $s) { if ($s === '' || $s === '.') continue; if ($s === '..') array_pop($parts); else $parts[] = $s; }
    return implode('/', $parts);
}
/** Solo se sirven imágenes PNG/JPG/GIF de las carpetas media del documento. */
function officeMediaMime(string $part): ?string {
    if (!preg_match('#^(word|ppt|xl)/media/[A-Za-z0-9._ -]{1,120}\.(png|jpe?g|gif)$#i', $part, $m)) return null;
    return ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif'][strtolower($m[2])];
}
function officeImg(int $fileId, string $part, string $class = '', string $alt = ''): string {
    if (!officeMediaMime($part)) return '<span class="ofc-noimg'.($class ? ' '.$class : '').'">Imagen no disponible en la vista previa</span>';
    return '<img loading="lazy" class="'.e($class).'" src="'.e('index.php?'.http_build_query(['raw'=>$fileId,'part'=>$part])).'" alt="'.e($alt).'">';
}
/** Entrega una imagen incrustada (?raw=ID&part=...). */
function officeServeMedia(string $path, string $part): never {
    $mime = officeMediaMime($part);
    if (!$mime) stop(404, 'Imagen no encontrada.');
    $z = officeOpen($path);
    $st = $z->statName($part);
    if ($st === false || $st['size'] > 20971520) stop(404, 'Imagen no encontrada.');
    $data = $z->getFromName($part); $z->close();
    if (!is_string($data)) stop(404, 'Imagen no encontrada.');
    header('Content-Type: '.$mime); header('Content-Length: '.strlen($data));
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'self'");
    header('Cache-Control: private, max-age=600');
    echo $data; exit;
}
function officeOn(?DOMElement $el, string $attr = 'w:val'): bool {
    if (!$el) return false;
    $v = $el->getAttribute($attr);
    return !in_array($v, ['0', 'false', 'off', 'none'], true);
}

// ------------------------------------------------------------------ Word
function officeDocx(string $path, int $fileId): string {
    $z = officeOpen($path);
    try {
        $x = officeXml($z, 'word/document.xml');
        if (!$x) throw new RuntimeException('No se encontró el contenido del documento.');
        $rels = officeRels($z, 'word/document.xml');
        $ctx = ['x'=>$x, 'rels'=>$rels, 'id'=>$fileId, 'count'=>0, 'lists'=>[]];
        $body = $x->query('/w:document/w:body')->item(0);
        $html = $body ? docxBlocks($ctx, $body) : '';
        if ($ctx['count'] >= 4000) $html .= '<p class="ofc-cut">Vista previa parcial: el documento es muy largo. Descárgalo para verlo completo.</p>';
        return '<article class="ofc-page">'.($html !== '' ? $html : '<p class="ofc-empty">El documento no tiene texto visible.</p>').'</article>';
    } finally { $z->close(); }
}
function docxBlocks(array &$ctx, DOMElement $parent): string {
    $out = ''; $list = '';
    foreach ($parent->childNodes as $n) {
        if (!$n instanceof DOMElement || $ctx['count'] >= 4000) continue;
        if ($n->localName === 'p') {
            $ctx['count']++;
            [$tag, $html, $isList, $lvl] = docxParagraph($ctx, $n);
            if ($isList) { $out .= '<p class="ofc-li '.('lv'.min($lvl, 4)).'">'.$html.'</p>'; continue; }
            $out .= $html;
        } elseif ($n->localName === 'tbl') {
            $out .= docxTable($ctx, $n);
        } elseif ($n->localName === 'sdt') {
            $c = $ctx['x']->query('w:sdtContent', $n)->item(0);
            if ($c instanceof DOMElement) $out .= docxBlocks($ctx, $c);
        }
    }
    return $out;
}
function docxParagraph(array &$ctx, DOMElement $p): array {
    $x = $ctx['x'];
    $style = (string)$x->evaluate('string(w:pPr/w:pStyle/@w:val)', $p);
    $jc = (string)$x->evaluate('string(w:pPr/w:jc/@w:val)', $p);
    $isList = $x->query('w:pPr/w:numPr', $p)->length > 0 || preg_match('/^(List|Lista|Prrafodelista|ListParagraph)/i', $style);
    $lvl = (int)$x->evaluate('string(w:pPr/w:numPr/w:ilvl/@w:val)', $p);
    if (!$lvl && preg_match('/^List.*?(\d)$/i', $style, $lm)) $lvl = max(0, (int)$lm[1] - 1);
    $inner = '';
    foreach ($x->query('.//w:r[not(ancestor::w:del)]', $p) as $r) $inner .= docxRun($ctx, $r);
    $tag = 'p';
    if (preg_match('/^(Title|Ttulo|Titulo)$/i', $style)) $tag = 'h1';
    elseif (preg_match('/(heading|ttulo|titulo)\s*([1-6])/i', $style, $m)) $tag = 'h'.min(6, (int)$m[2] + 1);
    elseif (preg_match('/^(Subtitle|Subttulo)$/i', $style)) $tag = 'h3';
    $cls = ['center'=>'ac', 'right'=>'ar', 'end'=>'ar', 'both'=>'aj', 'distribute'=>'aj'][$jc] ?? '';
    if ($inner === '' && !$isList) return [$tag, '<p class="ofc-gap"></p>', false, 0];
    $html = $isList ? $inner : '<'.$tag.($cls ? ' class="'.$cls.'"' : '').'>'.$inner.'</'.$tag.'>';
    return [$tag, $html, (bool)$isList, $lvl];
}
function docxRun(array &$ctx, DOMElement $r): string {
    $x = $ctx['x'];
    $pr = $x->query('w:rPr', $r)->item(0);
    $cls = [];
    if ($pr instanceof DOMElement) {
        foreach (['b'=>'b', 'i'=>'i', 'strike'=>'s'] as $t => $c) { $el = $x->query('w:'.$t, $pr)->item(0); if ($el instanceof DOMElement && officeOn($el)) $cls[] = $c; }
        $u = $x->query('w:u', $pr)->item(0); if ($u instanceof DOMElement && $u->getAttribute('w:val') !== 'none') $cls[] = 'u';
        $va = (string)$x->evaluate('string(w:vertAlign/@w:val)', $pr);
        if ($va === 'superscript') $cls[] = 'sup'; elseif ($va === 'subscript') $cls[] = 'sub';
        $sz = (int)$x->evaluate('string(w:sz/@w:val)', $pr);
        if ($sz >= 36) $cls[] = 'big'; elseif ($sz > 0 && $sz <= 16) $cls[] = 'small';
    }
    $txt = '';
    foreach ($r->childNodes as $c) {
        if (!$c instanceof DOMElement) continue;
        switch ($c->localName) {
            case 't': $txt .= e($c->textContent); break;
            case 'tab': $txt .= '<span class="ofc-tab"></span>'; break;
            case 'br': $txt .= $c->getAttribute('w:type') === 'page' ? '<br class="ofc-pb">' : '<br>'; break;
            case 'cr': $txt .= '<br>'; break;
            case 'noBreakHyphen': $txt .= '-'; break;
            case 'sym': $txt .= '•'; break;
            case 'drawing': case 'pict': case 'object':
                foreach ($x->query('.//a:blip/@r:embed | .//v:imagedata/@r:id', $c) as $a) {
                    $rel = $ctx['rels'][$a->nodeValue] ?? null;
                    if ($rel) $txt .= officeImg($ctx['id'], $rel['target'], 'ofc-img');
                }
                break;
        }
    }
    if ($txt === '') return '';
    return $cls ? '<span class="'.implode(' ', $cls).'">'.$txt.'</span>' : $txt;
}
function docxTable(array &$ctx, DOMElement $tbl): string {
    $x = $ctx['x']; $rows = '';
    foreach ($x->query('w:tr', $tbl) as $tr) {
        $cells = '';
        foreach ($x->query('w:tc', $tr) as $tc) {
            $span = (int)$x->evaluate('string(w:tcPr/w:gridSpan/@w:val)', $tc);
            $vm = $x->query('w:tcPr/w:vMerge', $tc)->item(0);
            $cont = $vm instanceof DOMElement && $vm->getAttribute('w:val') !== 'restart';
            $content = $cont ? '' : docxBlocks($ctx, $tc);
            $cells .= '<td'.($span > 1 ? ' colspan="'.min($span, 60).'"' : '').($cont ? ' class="merged"' : '').'>'.$content.'</td>';
        }
        $rows .= '<tr>'.$cells.'</tr>';
    }
    return '<div class="ofc-tablewrap"><table class="ofc-table">'.$rows.'</table></div>';
}

// ------------------------------------------------------------------ Excel
function xlsxCol(string $ref): int {
    $n = 0; $l = preg_replace('/\d+/', '', strtoupper($ref)) ?? '';
    for ($i = 0; $i < strlen($l); $i++) $n = $n * 26 + (ord($l[$i]) - 64);
    return $n;
}
function xlsxColName(int $n): string { $s = ''; while ($n > 0) { $m = ($n - 1) % 26; $s = chr(65 + $m).$s; $n = intdiv($n - 1, 26); } return $s; }
/** Devuelve [lista de hojas, html de la hoja pedida]. */
function officeXlsx(string $path, int $sheetNo): array {
    $z = officeOpen($path);
    try {
        $wb = officeXml($z, 'xl/workbook.xml');
        if (!$wb) throw new RuntimeException('No se encontró el contenido del libro.');
        $rels = officeRels($z, 'xl/workbook.xml');
        $sheets = [];
        foreach ($wb->query('//s:sheets/s:sheet') as $s) {
            if ($s->getAttribute('state') === 'hidden' || $s->getAttribute('state') === 'veryHidden') continue;
            $rid = $s->getAttributeNS(OFFICE_NS['r'], 'id');
            if (isset($rels[$rid]) && $rels[$rid]['type'] === 'worksheet') $sheets[] = ['name'=>$s->getAttribute('name'), 'path'=>$rels[$rid]['target']];
        }
        if (!$sheets) throw new RuntimeException('El libro no tiene hojas visibles.');
        $sheetNo = max(0, min($sheetNo, count($sheets) - 1));
        // Textos compartidos
        $shared = [];
        if ($ss = officeXml($z, 'xl/sharedStrings.xml')) {
            foreach ($ss->query('/s:sst/s:si') as $si) {
                $t = ''; foreach ($ss->query('.//s:t[not(ancestor::s:rPh)]', $si) as $tn) $t .= $tn->textContent;
                $shared[] = $t;
            }
        }
        // Formatos de número (para fechas, porcentajes y decimales)
        $fmts = []; $xfs = [];
        if ($st = officeXml($z, 'xl/styles.xml')) {
            foreach ($st->query('//s:numFmts/s:numFmt') as $f) $fmts[(int)$f->getAttribute('numFmtId')] = $f->getAttribute('formatCode');
            foreach ($st->query('//s:cellXfs/s:xf') as $f) $xfs[] = (int)$f->getAttribute('numFmtId');
        }
        $date1904 = (string)$wb->evaluate('string(//s:workbookPr/@date1904)') === '1';
        $html = xlsxSheet($z, $sheets[$sheetNo]['path'], $shared, $fmts, $xfs, $date1904);
        return [$sheets, $sheetNo, $html];
    } finally { $z->close(); }
}
function xlsxFormat(string $v, int $fmtId, array $fmts, bool $date1904): string {
    if (!is_numeric($v)) return $v;
    $code = $fmts[$fmtId] ?? '';
    $isDate = in_array($fmtId, [14,15,16,17,18,19,20,21,22,45,46,47], true);
    if (!$isDate && $code !== '') {
        $clean = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', $code) ?? '';
        $isDate = (bool)preg_match('/[dmyhs]/i', $clean) && !preg_match('/^general$/i', $clean);
    }
    $n = (float)$v;
    if ($isDate && $n > -1 && $n < 2958466) {
        $days = (int)floor($n); $secs = (int)round(($n - $days) * 86400);
        $d = new DateTime($date1904 ? '1904-01-01' : '1899-12-30', new DateTimeZone('UTC'));
        $d->modify(($days >= 0 ? '+' : '').$days.' days')->modify('+'.$secs.' seconds');
        if ($days === 0 && $secs > 0) return $d->format('H:i');
        return $d->format($secs > 0 ? 'd-m-Y H:i' : 'd-m-Y');
    }
    $pct = $fmtId === 9 || $fmtId === 10 || str_contains($code, '%');
    if ($pct) $n *= 100;
    $dec = 0;
    if (in_array($fmtId, [2,4,10,11], true)) $dec = 2;
    elseif ($code !== '' && preg_match('/0\.(0+)/', $code, $m)) $dec = strlen($m[1]);
    elseif (!$pct && floor($n) != $n) $dec = min(6, strlen(rtrim(substr(sprintf('%.6F', abs($n - floor($n))), 2), '0')));
    $thousands = in_array($fmtId, [3,4], true) || str_contains($code, '#,##') || preg_match('/[$€]/u', $code);
    $s = number_format($n, $dec, ',', $thousands ? '.' : '');
    if ($pct) $s .= '%';
    if (preg_match('/\$/', $code)) $s = '$'.$s;
    return $s;
}
function xlsxSheet(ZipArchive $z, string $path, array $shared, array $fmts, array $xfs, bool $date1904): string {
    $x = officeXml($z, $path);
    if (!$x) return '<p class="ofc-empty">No se pudo leer esta hoja.</p>';
    $MAXR = 1000; $MAXC = 50;
    $grid = []; $maxC = 0; $maxR = 0; $cut = false;
    foreach ($x->query('//s:sheetData/s:row') as $row) {
        $r = (int)$row->getAttribute('r') ?: $maxR + 1;
        if ($r > $MAXR) { $cut = true; break; }
        $col = 0;
        foreach ($x->query('s:c', $row) as $c) {
            $ref = $c->getAttribute('r'); $col = $ref !== '' ? xlsxCol($ref) : $col + 1;
            if ($col > $MAXC) { $cut = true; continue; }
            $t = $c->getAttribute('t'); $v = (string)$x->evaluate('string(s:v)', $c);
            if ($t === 's') $val = $shared[(int)$v] ?? '';
            elseif ($t === 'inlineStr') { $val = ''; foreach ($x->query('s:is//s:t', $c) as $tn) $val .= $tn->textContent; }
            elseif ($t === 'b') $val = $v === '1' ? 'VERDADERO' : 'FALSO';
            elseif ($t === 'str' || $t === 'e') $val = $v;
            else $val = $v === '' ? '' : xlsxFormat($v, $xfs[(int)$c->getAttribute('s')] ?? 0, $fmts, $date1904);
            if ($val === '') continue;
            $grid[$r][$col] = ['v'=>$val, 'num'=>$t === '' || $t === 'n'];
            $maxC = max($maxC, $col); $maxR = max($maxR, $r);
        }
    }
    // Celdas combinadas
    $span = []; $hidden = [];
    foreach ($x->query('//s:mergeCells/s:mergeCell') as $m) {
        if (!preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', $m->getAttribute('ref'), $mm)) continue;
        $c1 = xlsxCol($mm[1]); $r1 = (int)$mm[2]; $c2 = min(xlsxCol($mm[3]), $maxC); $r2 = min((int)$mm[4], $maxR);
        if ($c1 > $maxC || $r1 > $maxR) continue;
        $span[$r1][$c1] = [$r2 - $r1 + 1, $c2 - $c1 + 1];
        for ($rr = $r1; $rr <= $r2; $rr++) for ($cc = $c1; $cc <= $c2; $cc++) if ($rr !== $r1 || $cc !== $c1) $hidden[$rr][$cc] = true;
    }
    if (!$grid) return '<p class="ofc-empty">Esta hoja está vacía.</p>';
    $h = '<div class="ofc-sheetwrap"><table class="ofc-sheet"><thead><tr><th class="corner"></th>';
    for ($c = 1; $c <= $maxC; $c++) $h .= '<th>'.xlsxColName($c).'</th>';
    $h .= '</tr></thead><tbody>';
    for ($r = 1; $r <= $maxR; $r++) {
        $h .= '<tr><th>'.$r.'</th>';
        for ($c = 1; $c <= $maxC; $c++) {
            if (isset($hidden[$r][$c])) continue;
            $cell = $grid[$r][$c] ?? null; $sp = $span[$r][$c] ?? null;
            $attr = ($sp && $sp[0] > 1 ? ' rowspan="'.$sp[0].'"' : '').($sp && $sp[1] > 1 ? ' colspan="'.$sp[1].'"' : '');
            $h .= '<td'.$attr.($cell && $cell['num'] ? ' class="num"' : '').'>'.($cell ? e($cell['v']) : '').'</td>';
        }
        $h .= '</tr>';
    }
    $h .= '</tbody></table></div>';
    if ($cut) $h .= '<p class="ofc-cut">Vista previa parcial (hasta '.$MAXR.' filas y '.$MAXC.' columnas). Descarga el archivo para verlo completo.</p>';
    return $h;
}

// ------------------------------------------------------------------ PowerPoint
/** Devuelve [cantidad de diapositivas, índice mostrado, html de esa diapositiva]. */
function officePptx(string $path, int $fileId, int $slideNo): array {
    $z = officeOpen($path);
    try {
        $pr = officeXml($z, 'ppt/presentation.xml');
        if (!$pr) throw new RuntimeException('No se encontró el contenido de la presentación.');
        $W = (int)$pr->evaluate('string(//p:sldSz/@cx)') ?: 12192000; $H = (int)$pr->evaluate('string(//p:sldSz/@cy)') ?: 6858000;
        $rels = officeRels($z, 'ppt/presentation.xml');
        $slides = [];
        foreach ($pr->query('//p:sldIdLst/p:sldId') as $s) {
            $rid = $s->getAttributeNS(OFFICE_NS['r'], 'id');
            if (isset($rels[$rid])) $slides[] = $rels[$rid]['target'];
        }
        if (!$slides) return [0, 0, '<p class="ofc-empty">La presentación no tiene diapositivas.</p>'];
        $slideNo = max(0, min($slideNo, count($slides) - 1));
        // La diapositiva se ajusta al alto disponible de la pantalla sin perder su proporción.
        $r = $W / max(1, $H);
        $fit = officeClass(sprintf('aspect-ratio:%d/%d;width:min(100%%,max(300px,calc((100vh - 420px)*%.4F)))', $W, $H, $r));
        $html = '<div class="ofc-slide '.$fit.'">'.pptxSlide($z, $slides[$slideNo], $fileId, $W, $H).'</div>';
        return [count($slides), $slideNo, $html];
    } finally { $z->close(); }
}
/** Colores del tema (para textos y fondos con colores de esquema). */
function pptxTheme(ZipArchive $z, string $masterPath): array {
    $colors = [];
    foreach (officeRels($z, $masterPath) as $r) {
        if ($r['type'] !== 'theme') continue;
        $t = officeXml($z, $r['target']); if (!$t) break;
        foreach ($t->query('//a:clrScheme/*') as $c) {
            $v = (string)$t->evaluate('string(.//a:srgbClr/@val)', $c) ?: (string)$t->evaluate('string(.//a:sysClr/@lastClr)', $c);
            if (preg_match('/^[0-9A-Fa-f]{6}$/', $v)) $colors[$c->localName] = $v;
        }
        break;
    }
    $map = ['bg1'=>'lt1', 'tx1'=>'dk1', 'bg2'=>'lt2', 'tx2'=>'dk2'];
    foreach ($map as $k => $v) if (isset($colors[$v])) $colors[$k] = $colors[$v];
    return $colors;
}
function pptxColor(DOMXPath $x, ?DOMNode $fill, array $theme): ?string {
    if (!$fill) return null;
    $v = (string)$x->evaluate('string(a:srgbClr/@val)', $fill);
    if ($v === '') { $s = (string)$x->evaluate('string(a:schemeClr/@val)', $fill); $v = $theme[$s] ?? ''; }
    return preg_match('/^[0-9A-Fa-f]{6}$/', $v) ? '#'.$v : null;
}
/** Posiciones de marcadores de posición en diseño y patrón (para formas sin posición propia). */
function pptxPlaceholders(ZipArchive $z, string $part): array {
    $x = officeXml($z, $part); if (!$x) return [];
    $out = [];
    foreach ($x->query('//p:sp[p:nvSpPr/p:nvPr/p:ph]') as $sp) {
        $ph = $x->query('p:nvSpPr/p:nvPr/p:ph', $sp)->item(0);
        $off = $x->query('p:spPr/a:xfrm/a:off', $sp)->item(0); $ext = $x->query('p:spPr/a:xfrm/a:ext', $sp)->item(0);
        if (!$off instanceof DOMElement || !$ext instanceof DOMElement || !$ph instanceof DOMElement) continue;
        $box = [(int)$off->getAttribute('x'), (int)$off->getAttribute('y'), (int)$ext->getAttribute('cx'), (int)$ext->getAttribute('cy')];
        $info = ['box'=>$box, 'algn'=>(string)$x->evaluate('string(p:txBody/a:lstStyle/a:lvl1pPr/@algn)', $sp), 'sz'=>(int)$x->evaluate('string(p:txBody/a:lstStyle/a:lvl1pPr/a:defRPr/@sz)', $sp), 'anchor'=>(string)$x->evaluate('string(p:txBody/a:bodyPr/@anchor)', $sp)];
        $type = $ph->getAttribute('type') ?: 'body';
        if ($ph->getAttribute('idx') !== '') $out['idx:'.$ph->getAttribute('idx')] ??= $info;
        $out['type:'.$type] ??= $info;
    }
    // Estilos generales del patrón (títulos y cuerpo)
    foreach (['title'=>'titleStyle', 'body'=>'bodyStyle'] as $k => $st) {
        $algn = (string)$x->evaluate('string(//p:txStyles/p:'.$st.'/a:lvl1pPr/@algn)'); $sz = (int)$x->evaluate('string(//p:txStyles/p:'.$st.'/a:lvl1pPr/a:defRPr/@sz)');
        if ($algn !== '' || $sz > 0) $out['style:'.$k] = ['box'=>null, 'algn'=>$algn, 'sz'=>$sz, 'anchor'=>''];
    }
    return $out;
}
/** Combina la información heredada de un marcador (diseño, patrón y estilo general). */
function pptxPhInfo(array $ph, string $type, string $idx): array {
    $alt = $type === 'ctrTitle' ? 'title' : ($type === 'subTitle' ? 'body' : $type);
    $cands = array_filter([$idx !== '' ? $ph['idx:'.$idx] ?? null : null, $ph['type:'.$type] ?? null, $ph['type:'.$alt] ?? null, $ph['style:'.(in_array($type, ['title', 'ctrTitle'], true) ? 'title' : 'body')] ?? null]);
    $info = ['box'=>null, 'algn'=>'', 'sz'=>0, 'anchor'=>''];
    foreach ($cands as $c) foreach ($info as $k => $v) if (!$v && $c[$k]) $info[$k] = $c[$k];
    return $info;
}
function pptxSlide(ZipArchive $z, string $path, int $fileId, int $W, int $H): string {
    $x = officeXml($z, $path);
    if (!$x) return '';
    $rels = officeRels($z, $path);
    $layout = ''; $master = '';
    foreach ($rels as $r) if ($r['type'] === 'slideLayout') $layout = $r['target'];
    if ($layout) foreach (officeRels($z, $layout) as $r) if ($r['type'] === 'slideMaster') $master = $r['target'];
    $theme = $master ? pptxTheme($z, $master) : [];
    $ph = ($layout ? pptxPlaceholders($z, $layout) : []) + ($master ? pptxPlaceholders($z, $master) : []);
    $ctx = ['x'=>$x, 'rels'=>$rels, 'id'=>$fileId, 'W'=>$W, 'H'=>$H, 'theme'=>$theme, 'ph'=>$ph];
    // Fondo: diapositiva, luego diseño y patrón.
    $bg = pptxColor($x, $x->query('//p:cSld/p:bg/p:bgPr/a:solidFill')->item(0), $theme);
    if (!$bg) foreach ([$layout, $master] as $part) {
        if (!$part || !($lx = officeXml($z, $part))) continue;
        $bg = pptxColor($lx, $lx->query('//p:cSld/p:bg/p:bgPr/a:solidFill')->item(0), $theme);
        if (!$bg) { $ref = $lx->query('//p:cSld/p:bg/p:bgRef')->item(0); if ($ref) $bg = pptxColor($lx, $ref, $theme); }
        if ($bg) break;
    }
    $bgImg = '';
    $blip = $x->query('//p:cSld/p:bg/p:bgPr/a:blipFill/a:blip')->item(0);
    if ($blip instanceof DOMElement && isset($rels[$blip->getAttributeNS(OFFICE_NS['r'], 'embed')])) $bgImg = officeImg($fileId, $rels[$blip->getAttributeNS(OFFICE_NS['r'], 'embed')]['target'], 'ofc-bgimg');
    $tree = $x->query('//p:cSld/p:spTree')->item(0);
    $inner = $tree instanceof DOMElement ? pptxTree($ctx, $tree, [0, 0, 1, 1, 0, 0]) : '';
    $cls = $bg ? officeClass('background:'.$bg) : '';
    return '<div class="ofc-slide-in '.$cls.'">'.$bgImg.$inner.'</div>';
}
/** $t = transformación de grupo [offX, offY, escalaX, escalaY, chOffX, chOffY]. */
function pptxTree(array $ctx, DOMElement $tree, array $t): string {
    $x = $ctx['x']; $out = '';
    foreach ($tree->childNodes as $n) {
        if (!$n instanceof DOMElement) continue;
        $name = $n->localName;
        if ($name === 'grpSp') {
            $xf = $x->query('p:grpSpPr/a:xfrm', $n)->item(0);
            if (!$xf instanceof DOMElement) { $out .= pptxTree($ctx, $n, $t); continue; }
            $g = fn(string $q, string $a) => (int)$x->evaluate('string('.$q.'/@'.$a.')', $xf);
            $ext = [$g('a:ext', 'cx'), $g('a:ext', 'cy')]; $chExt = [$g('a:chExt', 'cx'), $g('a:chExt', 'cy')];
            $sx = $chExt[0] ? $ext[0] / $chExt[0] : 1; $sy = $chExt[1] ? $ext[1] / $chExt[1] : 1;
            $ox = $t[0] + ($g('a:off', 'x') - $t[4]) * $t[2]; $oy = $t[1] + ($g('a:off', 'y') - $t[5]) * $t[3];
            $out .= pptxTree($ctx, $n, [$ox, $oy, $t[2] * $sx, $t[3] * $sy, $g('a:chOff', 'x'), $g('a:chOff', 'y')]);
            continue;
        }
        if (!in_array($name, ['sp', 'pic', 'graphicFrame'], true)) continue;
        $xf = $x->query($name === 'graphicFrame' ? 'p:xfrm' : 'p:spPr/a:xfrm', $n)->item(0);
        $box = null;
        $phEl = $x->query('.//p:nvPr/p:ph', $n)->item(0);
        $phInfo = $phEl instanceof DOMElement ? pptxPhInfo($ctx['ph'], $phEl->getAttribute('type') ?: 'body', $phEl->getAttribute('idx')) : ['box'=>null, 'algn'=>'', 'sz'=>0, 'anchor'=>''];
        if ($xf instanceof DOMElement && $x->query('a:off', $xf)->length) {
            $box = [(int)$x->evaluate('string(a:off/@x)', $xf), (int)$x->evaluate('string(a:off/@y)', $xf), (int)$x->evaluate('string(a:ext/@cx)', $xf), (int)$x->evaluate('string(a:ext/@cy)', $xf)];
        } else {
            $box = $phInfo['box'];
        }
        if (!$box) continue;
        $bx = $t[0] + ($box[0] - $t[4]) * $t[2]; $by = $t[1] + ($box[1] - $t[5]) * $t[3];
        $bw = $box[2] * $t[2]; $bh = $box[3] * $t[3];
        $pos = sprintf('left:%.3F%%;top:%.3F%%;width:%.3F%%;height:%.3F%%', 100 * $bx / $ctx['W'], 100 * $by / $ctx['H'], 100 * $bw / $ctx['W'], 100 * $bh / $ctx['H']);
        if ($name === 'pic') {
            $rid = (string)$x->evaluate('string(.//a:blip/@r:embed)', $n);
            if (isset($ctx['rels'][$rid])) $out .= '<div class="ofc-shape '.officeClass($pos).'">'.officeImg($ctx['id'], $ctx['rels'][$rid]['target'], 'ofc-pic').'</div>';
            continue;
        }
        if ($name === 'graphicFrame') {
            $tbl = $x->query('.//a:tbl', $n)->item(0);
            if ($tbl instanceof DOMElement) {
                $rows = '';
                foreach ($x->query('a:tr', $tbl) as $tr) {
                    $rows .= '<tr>';
                    foreach ($x->query('a:tc', $tr) as $tc) {
                        if ($tc->getAttribute('hMerge') === '1' || $tc->getAttribute('vMerge') === '1') continue;
                        $span = (int)$tc->getAttribute('gridSpan');
                        $tb = $x->query('a:txBody', $tc)->item(0);
                        $rows .= '<td'.($span > 1 ? ' colspan="'.min($span, 60).'"' : '').'>'.($tb instanceof DOMElement ? pptxText($ctx, $tb, false) : '').'</td>';
                    }
                    $rows .= '</tr>';
                }
                $out .= '<div class="ofc-shape ofc-shape-table '.officeClass($pos).'"><table>'.$rows.'</table></div>';
            } else {
                $out .= '<div class="ofc-shape ofc-chart '.officeClass($pos).'"><span>Gráfico u objeto: descarga el archivo para verlo</span></div>';
            }
            continue;
        }
        // Forma con texto
        $style = $pos;
        $fill = pptxColor($x, $x->query('p:spPr/a:solidFill', $n)->item(0), $ctx['theme']);
        if ($fill) $style .= ';background:'.$fill;
        $ln = $x->query('p:spPr/a:ln[not(a:noFill)]/a:solidFill', $n)->item(0);
        if ($ln && ($lc = pptxColor($x, $ln, $ctx['theme']))) $style .= ';border:1px solid '.$lc;
        $geom = (string)$x->evaluate('string(p:spPr/a:prstGeom/@prst)', $n);
        if ($geom === 'ellipse') $style .= ';border-radius:50%';
        elseif ($geom === 'roundRect') $style .= ';border-radius:6%';
        $blip = (string)$x->evaluate('string(p:spPr/a:blipFill/a:blip/@r:embed)', $n);
        $img = $blip !== '' && isset($ctx['rels'][$blip]) ? officeImg($ctx['id'], $ctx['rels'][$blip]['target'], 'ofc-pic') : '';
        $ph = $x->query('p:nvSpPr/p:nvPr/p:ph', $n)->item(0);
        $phType = $ph instanceof DOMElement ? ($ph->getAttribute('type') ?: 'body') : '';
        $anchor = (string)$x->evaluate('string(p:txBody/a:bodyPr/@anchor)', $n) ?: $phInfo['anchor'];
        if ($anchor === '' && in_array($phType, ['title', 'ctrTitle'], true)) $anchor = $phType === 'title' ? 'ctr' : 'b';
        $txt = $x->query('p:txBody', $n)->item(0);
        $text = $txt instanceof DOMElement ? pptxText($ctx, $txt, $phType === 'body', $phType, $phInfo) : '';
        if ($text === '' && $img === '' && !$fill && !$ln) continue;
        $cls = 'ofc-shape ofc-text '.officeClass($style).' '.(['ctr'=>'va-c', 'b'=>'va-b'][$anchor] ?? 'va-t');
        $out .= '<div class="'.$cls.'">'.$img.$text.'</div>';
    }
    return $out;
}
function pptxText(array $ctx, DOMElement $body, bool $bullets, string $phType = '', array $phInfo = []): string {
    $x = $ctx['x']; $out = '';
    $defSz = ($phInfo['sz'] ?? 0) ?: (in_array($phType, ['title', 'ctrTitle'], true) ? 4000 : ($phType === 'subTitle' ? 2400 : 1800));
    $lstAlgn = (string)$x->evaluate('string(a:lstStyle/a:lvl1pPr/@algn)', $body) ?: ($phInfo['algn'] ?? '');
    foreach ($x->query('a:p', $body) as $p) {
        $algn = (string)$x->evaluate('string(a:pPr/@algn)', $p) ?: $lstAlgn;
        $lvl = (int)$x->evaluate('string(a:pPr/@lvl)', $p);
        $runs = ''; $maxSz = 0;
        foreach ($p->childNodes as $r) {
            if (!$r instanceof DOMElement) continue;
            if ($r->localName === 'br') { $runs .= '<br>'; continue; }
            if ($r->localName !== 'r' && $r->localName !== 'fld') continue;
            $t = (string)$x->evaluate('string(a:t)', $r);
            if ($t === '') continue;
            $rpr = $x->query('a:rPr', $r)->item(0);
            $css = [];
            if ($rpr instanceof DOMElement) {
                $sz = (int)$rpr->getAttribute('sz'); if ($sz > 0) $maxSz = max($maxSz, $sz);
                if ($sz > 0) $css[] = sprintf('font-size:%.3Fcqw', $sz / 100 * 12700 / $ctx['W'] * 100);
                if ($rpr->getAttribute('b') === '1') $css[] = 'font-weight:700';
                if ($rpr->getAttribute('i') === '1') $css[] = 'font-style:italic';
                if (($u = $rpr->getAttribute('u')) !== '' && $u !== 'none') $css[] = 'text-decoration:underline';
                $c = pptxColor($x, $x->query('a:solidFill', $rpr)->item(0), $ctx['theme']);
                if ($c) $css[] = 'color:'.$c;
            }
            $runs .= $css ? '<span class="'.officeClass(implode(';', $css)).'">'.e($t).'</span>' : e($t);
        }
        $none = $x->query('a:pPr/a:buNone', $p)->length > 0;
        $hasBu = $x->query('a:pPr/a:buChar | a:pPr/a:buAutoNum', $p)->length > 0;
        $isBullet = $runs !== '' && !$none && ($hasBu || $bullets);
        $base = sprintf('font-size:%.3Fcqw', $defSz / 100 * 12700 / $ctx['W'] * 100);
        $cls = officeClass($base).(['ctr'=>' ac', 'r'=>' ar', 'just'=>' aj'][$algn] ?? '').($isBullet ? ' bu lv'.min($lvl, 4) : '');
        $out .= '<p class="'.$cls.'">'.($runs !== '' ? $runs : '&nbsp;').'</p>';
    }
    return $out;
}
