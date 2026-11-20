<?php
// Carga masiva de cursos con una planilla Excel: plantilla descargable (con catálogo de valores ya usados para
// reutilizarlos), exportación de cursos existentes en el mismo formato, y subida con vista previa y confirmación.
// Se incluye desde cursos.php. Escribe y lee .xlsx con ZipArchive y DOM (sin librerías externas); la lectura usa
// officeOpen, officeXml y officeRels de office.php.

/** Columnas de cada hoja (el orden es el de la plantilla). */
const CU_X_CURSOS=['Código (vacío = curso nuevo)','Copiar de (código de un curso)','Nombre del curso *','Área','Modalidad','Código SENCE u OMI','Dirigido a','Objetivo general','Requisitos de ingreso','Evaluación y aprobación','Certificación','Perfil del relator','Horas totales (vacío = suma de módulos)','Participantes previstos','Mínimo para dictarlo','Cupo máximo','Precio por participante (neto)'];
const CU_X_CAMPOS=['codigo','base','nombre','area','modalidad','sence','dirigido','objetivo','requisitos','evaluacion','certificacion','relator','horas','part','part_min','part_max','precio'];
const CU_X_MODS=['Curso (código o nombre) *','Módulo *','Horas teóricas','Horas prácticas','Contenidos'];
const CU_X_COSTOS=['Curso (código o nombre) *','Concepto *','Cantidad','Unidad','Precio unitario *','¿Precio líquido + retención? (Sí/No)','Se multiplica por'];
/** Textos de «Se multiplica por» en la planilla. */
const CU_X_BASES=['fijo'=>'Monto fijo','part'=>'Participantes','hora'=>'Horas','parthora'=>'Participantes × horas'];
const CU_X_MAX=100;

// ---------- Escritura de .xlsx ----------
function cuXmlText(string $s): string { return htmlspecialchars(preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u','',$s)??'',ENT_XML1|ENT_QUOTES,'UTF-8'); }
/**
 * Hojas: [['name'=>..., 'rows'=>[[celda,...],...], 'widths'=>[...], 'header'=>bool, 'lists'=>['F'=>['Sí','No']], 'style'=>[fila=>estilo]]].
 * Celda: string (texto) o int|float (número). Estilos: 0 normal, 1 encabezado, 2 texto ajustado, 3 título, 4 fila con error,
 * 5 fila con aviso, 6 celda con error. 'cells'=>['fila,col'=>estilo] marca celdas sueltas (base 0).
 */
function cuXlsxWrite(array $sheets, string $file): void {
    $z=new ZipArchive(); if($z->open($file,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) throw new RuntimeException('No se pudo crear la planilla.');
    $ct='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    $wb=''; $rels=''; $sx=[];
    foreach($sheets as $i=>$s){ $n=$i+1;
        $ct.='<Override PartName="/xl/worksheets/sheet'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $wb.='<sheet name="'.cuXmlText($s['name']).'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
        $rels.='<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
        $x='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        if(!empty($s['header'])) $x.='<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        $x.='<sheetFormatPr defaultRowHeight="15"/>';
        if(!empty($s['widths'])){ $x.='<cols>'; foreach($s['widths'] as $c=>$w) $x.='<col min="'.($c+1).'" max="'.($c+1).'" width="'.$w.'" customWidth="1"/>'; $x.='</cols>'; }
        $x.='<sheetData>';
        foreach($s['rows'] as $r=>$cells){ $rn=$r+1; $st=$s['style'][$r]??(!empty($s['header'])&&$r===0?1:0);
            $x.='<row r="'.$rn.'">';
            foreach($cells as $c=>$v){ $cs=$s['cells'][$r.','.$c]??$st; if(($v===''||$v===null) && !$cs) continue; $ref=xlsxColName($c+1).$rn; $sa=$cs?' s="'.$cs.'"':''; if($v===''||$v===null){ $x.='<c r="'.$ref.'"'.$sa.'/>'; continue; }
                $x.=is_int($v)||is_float($v) ? '<c r="'.$ref.'"'.$sa.'><v>'.(is_float($v)?rtrim(rtrim(sprintf('%.4F',$v),'0'),'.'):$v).'</v></c>' : '<c r="'.$ref.'"'.$sa.' t="inlineStr"><is><t xml:space="preserve">'.cuXmlText((string)$v).'</t></is></c>'; }
            $x.='</row>'; }
        $x.='</sheetData>';
        if(!empty($s['lists'])){ $x.='<dataValidations count="'.count($s['lists']).'">'; foreach($s['lists'] as $col=>$opts) $x.='<dataValidation type="list" allowBlank="1" showErrorMessage="1" errorTitle="Valor no válido" error="Elige un valor de la lista." sqref="'.$col.'2:'.$col.'2000"><formula1>'.cuXmlText('"'.implode(',',$opts).'"').'</formula1></dataValidation>'; $x.='</dataValidations>'; }
        $x.='<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/></worksheet>';
        $sx['xl/worksheets/sheet'.$n.'.xml']=$x;
    }
    // [Content_Types].xml va primero: así los servidores reconocen el archivo como Excel.
    $z->addFromString('[Content_Types].xml',$ct.'</Types>');
    $z->addFromString('_rels/.rels','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $z->addFromString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$wb.'</sheets></workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'<Relationship Id="rId'.(count($sheets)+1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $z->addFromString('xl/styles.xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<fonts count="4"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="14"/><color rgb="FF0F2A45"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FF9B1C13"/><name val="Calibri"/></font></fonts>'
        .'<fills count="6"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0F2A45"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFDE4E2"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFFF4CC"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF4A9A3"/><bgColor indexed="64"/></patternFill></fill></fills>'
        .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        .'<cellXfs count="7"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment wrapText="1" vertical="center"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf><xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="0" fontId="0" fillId="3" borderId="0" xfId="0" applyFill="1"/><xf numFmtId="0" fontId="0" fillId="4" borderId="0" xfId="0" applyFill="1"/><xf numFmtId="0" fontId="3" fillId="5" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
        .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
    foreach($sx as $name=>$x) $z->addFromString($name,$x);
    $z->close();
}

/** Hojas de la planilla: instrucciones, cursos, módulos, costos y catálogos con lo ya usado. $courses: [[fila de cu_courses con 'c']]. */
function cuXlsxSheets(array $courses, bool $example): array {
    $si=fn(bool $b): string => $b?'Sí':'No';
    $num=fn($v)=>is_numeric($v)&&(float)$v!=0.0 ? ((float)$v==floor((float)$v)?(int)$v:(float)$v) : '';
    $cur=[CU_X_CURSOS]; $mod=[CU_X_MODS]; $cos=[CU_X_COSTOS];
    foreach($courses as $cu){ $c=$cu['c']; $k=$cu['codigo'];
        $cur[]=[$k,'',$c['nombre'],$c['area'],$c['modalidad'],$c['sence'],$c['dirigido'],$c['objetivo'],$c['requisitos'],$c['evaluacion'],$c['certificacion'],$c['relator'],$num($c['horas']),$num($c['part']),$num($c['part_min']),$num($c['part_max']),$num($c['precio'])];
        foreach($c['modulos'] as $m) $mod[]=[$k,$m['nombre'],$num($m['teo']),$num($m['pra']),$m['contenidos']];
        foreach($c['costos'] as $l) $cos[]=[$k,$l['desc'],$num($l['cant']),$l['unidad'],(int)$l['precio'],$si($l['ret']>0),CU_X_BASES[$l['base']]];
    }
    if($example){
        $n='EJEMPLO: Operador de grúa horquilla';
        $cur[]=['','',$n,'Logística','Presencial','','Operarios de bodega y patio','Operar la grúa horquilla de forma segura aplicando la normativa vigente.','Mayor de 18 años','Prueba teórica y práctica, 75% de asistencia','Certificado de aprobación','Instructor con licencia clase D y 5 años de experiencia','',12,8,16,''];
        foreach([['Normativa y seguridad',4,0,'DS 594, Ley 16.744, riesgos'],['Inspección del equipo',2,2,'Lista de chequeo, niveles, neumáticos'],['Operación práctica',2,6,'Maniobras, apilamiento']] as $m) $mod[]=[$n,...$m];
        foreach([['Relator(es), valor hora',1,'hora',25000,'Sí','Horas'],['Arriendo de sala o taller',2,'día',60000,'No','Monto fijo'],['Manual y materiales',1,'kit',8000,'No','Participantes'],['Coffee break',2,'servicio',3500,'No','Participantes']] as $l) $cos[]=[$n,...$l];
    }
    // Catálogos: lo ya usado en los cursos de la empresa, para copiar y pegar (agrupado y con el último precio).
    $catC=[CU_X_COSTOS]; $catC[0][0]='Usado en'; $seenC=[]; $catM=[CU_X_MODS]; $catM[0][0]='Usado en'; $seenM=[]; $list=[['Código','Nombre','Área','Horas','Precio por participante','Estado','Versión']];
    foreach(query('SELECT * FROM cu_courses ORDER BY updated DESC')->fetchAll(PDO::FETCH_ASSOC) as $r){ $c=cuNormalize(json_decode($r['data'],true)?:[]);
        $list[]=[$r['codigo'],$r['nombre'],$c['area'],$num(cuHoras($c)),$num($c['precio']),$r['estado'],(int)$r['version']];
        foreach($c['costos'] as $l){ $key=mb_strtolower($l['desc'].'|'.$l['unidad'].'|'.$l['base']); if(isset($seenC[$key])) continue; $seenC[$key]=1; $catC[]=[$r['codigo'],$l['desc'],$num($l['cant']),$l['unidad'],(int)$l['precio'],$si($l['ret']>0),CU_X_BASES[$l['base']]]; }
        foreach($c['modulos'] as $m){ $key=mb_strtolower($m['nombre']); if(isset($seenM[$key])) continue; $seenM[$key]=1; $catM[]=[$r['codigo'],$m['nombre'],$num($m['teo']),$num($m['pra']),$m['contenidos']]; }
    }
    $cfg=cuCfg();
    $ins=[['Carga masiva de cursos · '.empresa()['nombre']],[''],
        ['1. En «Cursos» escribe una fila por curso. Solo el nombre es obligatorio.'],
        ['2. En «Módulos» y «Costos» escribe una fila por módulo o costo. En la columna A va el código o el nombre exacto del curso de la hoja «Cursos».'],
        ['3. Para actualizar un curso que ya existe, deja su código en la columna A de «Cursos» (ej. CUR-0001): se guarda como versión nueva y el historial se conserva. Si un curso no tiene filas en «Módulos» o «Costos», se mantienen los que ya tenía.'],
        ['4. Para reutilizar un curso existente, escribe su código en «Copiar de»: el curso nuevo toma sus módulos y costos (si no escribes otros) y todo dato que dejes en blanco.'],
        ['5. Las hojas «Catálogo costos» y «Catálogo módulos» traen los costos y módulos ya usados en tus cursos, con su último precio: copia las filas que necesites a «Costos» o «Módulos» y cambia la columna A por tu curso.'],
        ['6. «¿Precio líquido + retención?»: Sí si el valor acordado con el relator es líquido; se suma la retención de honorarios vigente ('.psRetLabel(psRetRate()).').'],
        ['7. «Se multiplica por»: Monto fijo, Participantes, Horas o Participantes × horas. Montos en pesos, sin puntos ni signo $.'],
        ['8. Las filas cuyo nombre empieza con «EJEMPLO» no se cargan: bórralas o déjalas.'],
        ['9. Guarda como .xlsx y súbelo en Cursos → Carga masiva. Antes de guardar verás una vista previa con la evaluación de cada curso y los errores por fila. Máximo '.CU_X_MAX.' cursos por archivo.'],
        [''],['Los costos fijos de la empresa ('.cuFixedText(cuFixedSnapshot(8)).') y el margen objetivo ('.ocQty((float)$cfg['margen_obj']).'%) se aplican solos al cargar.']];
    return [
        ['name'=>'Instrucciones','rows'=>$ins,'widths'=>[130],'style'=>[0=>3]+array_fill(1,count($ins)-1,2)],
        ['name'=>'Cursos','rows'=>$cur,'header'=>true,'widths'=>[16,16,36,16,14,14,26,48,24,30,26,28,14,12,12,12,16],'lists'=>['E'=>['Presencial','En línea','Mixta']]],
        ['name'=>'Módulos','rows'=>$mod,'header'=>true,'widths'=>[30,32,12,12,60]],
        ['name'=>'Costos','rows'=>$cos,'header'=>true,'widths'=>[30,36,10,12,16,18,22],'lists'=>['F'=>['Sí','No'],'G'=>array_values(CU_X_BASES)]],
        ['name'=>'Catálogo costos','rows'=>$catC,'header'=>true,'widths'=>[14,36,10,12,16,18,22]],
        ['name'=>'Catálogo módulos','rows'=>$catM,'header'=>true,'widths'=>[14,32,12,12,60]],
        ['name'=>'Cursos existentes','rows'=>$list,'header'=>true,'widths'=>[12,40,18,10,18,12,10]],
    ];
}
/** Planilla subida con los problemas marcados: hoja «Revisión» con la lista y las hojas originales con filas y celdas en color y una columna «Revisión». */
function cuXlsxRevision(array $imp): array {
    $iss=$imp['issues']??[]; $book=$imp['book']??[];
    $rev=[['Nivel','Hoja','Fila','Columna','Valor escrito','Problema','Curso']]; $sty=[];
    foreach($iss as $k=>$x){ $rev[]=[$x['nivel']==='error'?'Error':'Aviso',$x['hoja'],$x['fila'],$x['col'],$x['valor'],$x['motivo'],$x['curso']]; $sty[$k+1]=$x['nivel']==='error'?4:5; }
    if(count($rev)===1) $rev[]=['','','','','','Sin errores ni avisos.',''];
    $out=[['name'=>'Revisión','rows'=>$rev,'header'=>true,'widths'=>[9,12,7,9,26,80,32],'style'=>$sty]];
    foreach(['Cursos'=>[CU_X_CURSOS,[16,16,36,16,14,14,26,48,24,30,26,28,14,12,12,12,16]],'Módulos'=>[CU_X_MODS,[30,32,12,12,60]],'Costos'=>[CU_X_COSTOS,[30,36,10,12,16,18,22]]] as $hoja=>[$hdr,$w]){
        $src=$book[$hoja]??[]; $n=count($hdr); $rows=[array_merge($hdr,['Revisión (puedes dejar esta columna: no se lee)'])]; $st=[]; $cells=[]; $map=[];
        foreach($iss as $x) if($x['hoja']===$hoja) $map[$x['fila']][]=$x;
        $last=$src?max(array_keys($src)):1;
        for($r=2;$r<=$last;$r++){ $vals=$src[$r]??[]; $row=[];
            for($c=0;$c<$n;$c++){ $v=(string)($vals[$c]??''); $row[]=is_numeric($v)&&!preg_match('/^0\d/',$v) ? ($v==(string)(int)$v?(int)$v:(float)$v) : $v; }
            $p=$map[$r]??[]; $row[]=implode(' · ',array_map(fn($x)=>($x['nivel']==='error'?'ERROR: ':'Aviso: ').$x['motivo'],$p));
            $i=count($rows); $rows[]=$row;
            if($p){ $err=array_filter($p,fn($x)=>$x['nivel']==='error'); $st[$i]=$err?4:5;
                foreach($err as $x) if($x['col']!=='') $cells[$i.','.(xlsxCol($x['col'].'1')-1)]=6; $cells[$i.','.$n]=$err?6:5; }
        }
        $out[]=['name'=>$hoja,'rows'=>$rows,'header'=>true,'widths'=>array_merge($w,[70]),'style'=>$st,'cells'=>$cells];
    }
    return $out;
}
/** Descarga: ?cu_xlsx=plantilla | ?cu_xlsx=export (cursos vigentes) | ?cu_xlsx=export&id=N (un curso). */
function cuXlsxDownload(): never {
    $mode=(string)$_GET['cu_xlsx']; $courses=[];
    if($mode==='revision'){
        $imp=$_SESSION['cu_import']??null; if(!$imp || ($imp['empresa']??'')!==empresa()['key'] || empty($imp['book'])) stop(404,'La revisión ya no está disponible. Sube la planilla otra vez.');
        $tmp=dataDir().'/cu-'.bin2hex(random_bytes(8)).'.xlsx';
        try { cuXlsxWrite(cuXlsxRevision($imp),$tmp); $data=(string)file_get_contents($tmp); } finally { if(is_file($tmp)) unlink($tmp); }
        $name='revision_'.preg_replace('/[^A-Za-z0-9_-]+/','_',pathinfo((string)$imp['archivo'],PATHINFO_FILENAME)?:'planilla').'.xlsx';
        session_write_close();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Length: '.strlen($data)); header('Content-Disposition: attachment; filename="'.$name.'"');
        echo $data; exit;
    }
    if($mode==='export'){
        $ids=isset($_GET['id']) ? [(int)$_GET['id']] : query("SELECT id FROM cu_courses WHERE estado<>'Archivado' ORDER BY nombre COLLATE NOCASE")->fetchAll(PDO::FETCH_COLUMN);
        foreach($ids as $id) if($cu=cuGet((int)$id)) $courses[]=$cu;
        if(isset($_GET['id']) && !$courses) stop(404,'Curso no encontrado.');
    }
    $tmp=dataDir().'/cu-'.bin2hex(random_bytes(8)).'.xlsx';
    try { cuXlsxWrite(cuXlsxSheets($courses,$mode!=='export'),$tmp); $data=(string)file_get_contents($tmp); } finally { if(is_file($tmp)) unlink($tmp); }
    $name=$mode==='export' ? (count($courses)===1?'curso_'.$courses[0]['codigo']:'cursos_'.strtolower(empresa()['corto'])).'_'.ocToday().'.xlsx' : 'plantilla_cursos_'.strtolower(empresa()['corto']).'.xlsx';
    audit($mode==='export'?'Exportación de cursos a Excel':'Descarga de plantilla de cursos',$mode==='export'?count($courses).' curso(s)':'');
    session_write_close();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Length: '.strlen($data));
    header('Content-Disposition: attachment; filename="'.$name.'"');
    echo $data; exit;
}

// ---------- Lectura ----------
/** Valores de cada hoja: [nombre normalizado => [n° fila => [n° columna (0..) => texto]]]. Sin formato: números tal como están guardados. */
function cuXlsxRead(string $path): array {
    $z=officeOpen($path);
    try {
        $wb=officeXml($z,'xl/workbook.xml'); if(!$wb) throw new RuntimeException('El archivo no es una planilla de Excel válida.');
        $rels=officeRels($z,'xl/workbook.xml'); $shared=[];
        if($ss=officeXml($z,'xl/sharedStrings.xml')) foreach($ss->query('/s:sst/s:si') as $si){ $t=''; foreach($ss->query('.//s:t[not(ancestor::s:rPh)]',$si) as $tn) $t.=$tn->textContent; $shared[]=$t; }
        $out=[];
        foreach($wb->query('//s:sheets/s:sheet') as $s){
            $rid=$s->getAttributeNS(OFFICE_NS['r'],'id'); if(!isset($rels[$rid]) || $rels[$rid]['type']!=='worksheet') continue;
            $x=officeXml($z,$rels[$rid]['target']); if(!$x) continue;
            $rows=[]; $last=0;
            foreach($x->query('//s:sheetData/s:row') as $row){
                $r=(int)$row->getAttribute('r') ?: $last+1; $last=$r; if($r>5000) break; $col=0;
                foreach($x->query('s:c',$row) as $c){
                    $ref=$c->getAttribute('r'); $col=$ref!==''?xlsxCol($ref):$col+1; if($col>40) continue;
                    $t=$c->getAttribute('t'); $v=(string)$x->evaluate('string(s:v)',$c);
                    if($t==='s') $v=$shared[(int)$v]??''; elseif($t==='inlineStr'){ $v=''; foreach($x->query('s:is//s:t',$c) as $tn) $v.=$tn->textContent; } elseif($t==='b') $v=$v==='1'?'Sí':'No'; elseif($t==='e') $v='';
                    $v=trim($v); if($v!=='') $rows[$r][$col-1]=$v;
                }
            }
            $out[cuKey($s->getAttribute('name'))]=$rows;
        }
        return $out;
    } finally { $z->close(); }
}
/** Texto comparable: minúsculas, sin tildes ni espacios repetidos. */
function cuKey(string $s): string { $s=mb_strtolower(trim($s)); $s=strtr($s,['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n','×'=>'x']); return preg_replace('/\s+/',' ',$s)??$s; }
function cuXBase(string $v): ?string {
    $k=preg_replace('/[^a-z]/','',cuKey($v))??''; if($k==='') return 'fijo';
    return match(true){ in_array($k,['montofijo','fijo','monto'],true)=>'fijo', in_array($k,['participantesxhoras','participantehora','participantesxhora','xparticipantesxhoras','parthora'],true)=>'parthora', in_array($k,['participantes','participante','xparticipantes','part','alumno','alumnos'],true)=>'part', in_array($k,['horas','hora','xhoras'],true)=>'hora', default=>null };
}
function cuXYes(string $v): bool { return in_array(cuKey($v),['si','sí','s','x','1','verdadero','true','yes','liquido'],true); }

/**
 * Convierte la planilla en cursos listos para guardar. Devuelve ['items'=>[...], 'general'=>[avisos sin fila]].
 * Cada ítem: row, accion (nuevo|version), id, codigo, c (curso normalizado con costos fijos vigentes), errores, avisos, veredicto.
 */
function cuImportParse(array $book): array {
    $sh=fn(string $n): ?array => $book[cuKey($n)] ?? null;
    $cur=$sh('Cursos'); if($cur===null) throw new RuntimeException('No se encontró la hoja «Cursos». Descarga la plantilla y úsala como base.');
    $hdr=$cur[1]??[]; if(!str_starts_with(cuKey($hdr[2]??''),'nombre')) throw new RuntimeException('La hoja «Cursos» no tiene las columnas de la plantilla (la columna C debe ser «Nombre del curso»; hoy dice «'.shortText((string)($hdr[2]??''),40).'»). Descarga la plantilla nuevamente.');
    $mods=$sh('Módulos')??$sh('Modulos')??[]; $cost=$sh('Costos')??[];
    $existing=[]; $byName=[];
    foreach(query('SELECT id,codigo,nombre FROM cu_courses')->fetchAll(PDO::FETCH_ASSOC) as $r){ $existing[strtoupper($r['codigo'])]=$r; $byName[cuKey($r['nombre'])][]=$r['codigo']; }
    $items=[]; $general=[]; $keys=[]; $names=[]; $ejemplos=0; $iss=[];
    $col=fn(string $k): string => xlsxColName(array_search($k,CU_X_CAMPOS,true)+1);
    $ttl=fn(string $k): string => rtrim(preg_replace('/\s*\(.*$|\s*\*$/','',CU_X_CURSOS[array_search($k,CU_X_CAMPOS,true)])??'');
    // Registra un problema: queda en la lista de revisión (hoja, fila, columna, valor, motivo) y en el curso afectado.
    $note=function(?int $i, string $nivel, string $hoja, int $fila, string $c, string $valor, string $motivo) use(&$items,&$iss,&$general): void {
        $iss[]=['nivel'=>$nivel,'hoja'=>$hoja,'fila'=>$fila,'col'=>$c,'valor'=>shortText($valor,80),'motivo'=>$motivo,'curso'=>$i];
        $txt=($hoja!=='Cursos'?'Fila '.$fila.' de «'.$hoja.'»: ':'').$motivo;
        if($i===null) $general[]='Fila '.$fila.' de «'.$hoja.'»: '.$motivo; else $items[$i][$nivel==='error'?'errores':'avisos'][]=$txt;
    };
    $isNum=fn(string $v): bool => (bool)preg_match('/^\s*\$?\s*-?[\d.,\s]*\d[\d.,\s]*$/u',$v);
    $int=fn(string $v): int => (int)preg_replace('/[^\d]/','',explode(',',$v)[0]);
    foreach($cur as $rn=>$cells){ if($rn===1) continue;
        $f=[]; foreach(CU_X_CAMPOS as $i=>$k) $f[$k]=$cells[$i]??'';
        if(implode('',$f)==='') continue;
        if(str_starts_with(cuKey($f['nombre']),'ejemplo')) { $ejemplos++; continue; }
        if(count($items)>=CU_X_MAX){ $note(null,'error','Cursos',$rn,'','','Se cargan como máximo '.CU_X_MAX.' cursos por archivo: esta fila y las siguientes no se consideraron.'); break; }
        $i=count($items); $items[$i]=['row'=>$rn,'accion'=>'nuevo','id'=>0,'codigo'=>'','errores'=>[],'avisos'=>[],'base'=>null];
        $code=strtoupper(trim($f['codigo']));
        if($code!==''){ if(!isset($existing[$code])) $note($i,'error','Cursos',$rn,'A',$f['codigo'],'No existe un curso con el código '.$code.'. Para crear uno nuevo, deja la columna A vacía.'); else { $items[$i]['accion']='version'; $items[$i]['id']=(int)$existing[$code]['id']; $items[$i]['codigo']=$code; } }
        $bcode=strtoupper(trim($f['base']));
        if($bcode!==''){ if(!isset($existing[$bcode])) $note($i,'error','Cursos',$rn,'B',$f['base'],'«Copiar de»: no existe el curso '.$bcode.'. Revisa el código en la hoja «Cursos existentes».'); else $items[$i]['base']=cuGet((int)$existing[$bcode]['id']); }
        $base=$items[$i]['base'];
        $prev=$items[$i]['id'] ? cuGet($items[$i]['id']) : null;
        // Valores del curso: lo escrito; lo vacío se toma del curso base («Copiar de»).
        $raw=['modulos'=>[],'costos'=>[]];
        foreach(array_slice(CU_X_CAMPOS,2) as $k) $raw[$k]=$f[$k];
        if($base) foreach($raw as $k=>$v) if($v==='' && !in_array($k,['modulos','costos'],true)) $raw[$k]=$base['c'][$k];
        foreach(['horas','part','part_min','part_max','precio'] as $k) if($f[$k]!=='' && !$isNum($f[$k])) $note($i,'error','Cursos',$rn,$col($k),$f[$k],'«'.$ttl($k).'» debe ser un número.');
        if($f['part_min']!=='' && $f['part_max']!=='' && $isNum($f['part_min']) && $isNum($f['part_max']) && $int($f['part_min'])>$int($f['part_max'])) $note($i,'error','Cursos',$rn,$col('part_min'),$f['part_min'],'El mínimo para dictarlo ('.$int($f['part_min']).') es mayor que el cupo máximo ('.$int($f['part_max']).').');
        if($f['modalidad']!=='' && !in_array(cuKey($f['modalidad']),['presencial','en linea','mixta'],true)) $note($i,'aviso','Cursos',$rn,$col('modalidad'),$f['modalidad'],'Modalidad fuera de la lista (Presencial, En línea, Mixta): se guarda tal como está escrita.');
        if(trim((string)$raw['nombre'])==='') $note($i,'error','Cursos',$rn,'C','','Falta el nombre del curso.');
        elseif(mb_strlen((string)$raw['nombre'])>120) $note($i,'aviso','Cursos',$rn,'C',(string)$raw['nombre'],'El nombre tiene más de 120 caracteres: se recorta.');
        $nk=cuKey((string)$raw['nombre']);
        if($nk!=='' && isset($names[$nk])) $note($i,'error','Cursos',$rn,'C',(string)$raw['nombre'],'El nombre se repite en la fila '.$names[$nk].' de «Cursos»: usa nombres distintos o el código.');
        if($nk!=='') $names[$nk]=$rn;
        if($items[$i]['accion']==='nuevo' && $nk!=='' && isset($byName[$nk])) $note($i,'aviso','Cursos',$rn,'C',(string)$raw['nombre'],'Ya existe un curso con este nombre ('.implode(', ',$byName[$nk]).'): se creará otro. Para actualizarlo, escribe su código en la columna A.');
        $items[$i]['raw']=$raw; $items[$i]['prev']=$prev;
        if($code!=='') $keys[cuKey($code)]=$i;
        if($nk!=='' && !isset($keys[$nk])) $keys[$nk]=$i;
    }
    if(!$items && !$general) throw new RuntimeException($ejemplos ? 'La planilla solo tiene la fila de ejemplo, y esa fila no se carga. Escribe tus cursos en la hoja «Cursos» desde la fila 3 (o cambia el nombre «EJEMPLO: …» por el nombre real del curso) y vuelve a subirla.' : 'La hoja «Cursos» está vacía: escribe al menos un curso (nombre en la columna C) y vuelve a subirla.');
    $find=function(string $v, string $hoja, int $rn) use(&$keys,$note): ?int { $k=cuKey($v); if($k==='') { $note(null,'error',$hoja,$rn,'A','','Falta el curso en la columna A: la fila no se carga.'); return null; } if(!isset($keys[$k])) { if(!str_starts_with($k,'ejemplo')) $note(null,'error',$hoja,$rn,'A',$v,'No hay un curso con ese nombre o código en la hoja «Cursos»: la fila no se carga.'); return null; } return $keys[$k]; };
    foreach($mods as $rn=>$cells){ if($rn===1 || !array_filter($cells,'strlen')) continue; $i=$find($cells[0]??'','Módulos',$rn); if($i===null) continue;
        $t=$cells[2]??''; $p=$cells[3]??''; $bad=false;
        foreach(['C'=>$t,'D'=>$p] as $cl=>$v) if($v!=='' && !is_numeric(str_replace(',','.',$v))) { $note($i,'error','Módulos',$rn,$cl,$v,'Las horas '.($cl==='C'?'teóricas':'prácticas').' deben ser un número.'); $bad=true; }
        if($bad) continue;
        if(($cells[1]??'')==='') $note($i,'aviso','Módulos',$rn,'B','','Módulo sin nombre: se nombra automáticamente.');
        if($t==='' && $p==='') $note($i,'aviso','Módulos',$rn,'C','','Módulo sin horas: no suma al total del curso.');
        $items[$i]['raw']['modulos'][]=['nombre'=>$cells[1]??'','teo'=>$t,'pra'=>$p,'contenidos'=>$cells[4]??'']; }
    $rate=psRetRate();
    foreach($cost as $rn=>$cells){ if($rn===1 || !array_filter($cells,'strlen')) continue; $i=$find($cells[0]??'','Costos',$rn); if($i===null) continue;
        $precio=ccMoney($cells[4]??''); $base=cuXBase($cells[6]??'');
        if(($cells[4]??'')!=='' && !$isNum($cells[4])) { $note($i,'error','Costos',$rn,'E',$cells[4],'El precio unitario debe ser un número (en pesos, sin puntos ni $).'); continue; }
        if($precio<=0){ $note($i,'aviso','Costos',$rn,'E',$cells[4]??'','«'.($cells[1]??'').'» no tiene precio: esta fila no se carga.'); continue; }
        if($base===null){ $note($i,'error','Costos',$rn,'G',$cells[6]??'','«Se multiplica por» debe ser Monto fijo, Participantes, Horas o Participantes × horas.'); continue; }
        $cant=$cells[2]??''; if($cant!=='' && !is_numeric(str_replace(',','.',$cant))){ $note($i,'error','Costos',$rn,'C',$cant,'La cantidad debe ser un número.'); continue; }
        if(($cells[5]??'')!=='' && !in_array(cuKey($cells[5]),['si','no','s','n','x','1','0','verdadero','falso','true','false','yes','liquido','bruto'],true)) $note($i,'aviso','Costos',$rn,'F',$cells[5],'«¿Precio líquido + retención?» debe ser Sí o No: se toma como No.');
        if(($cells[1]??'')==='') $note($i,'aviso','Costos',$rn,'B','','Costo sin concepto: se guarda sin descripción.');
        $items[$i]['raw']['costos'][]=['desc'=>$cells[1]??'','cant'=>str_replace(',','.',$cant),'unidad'=>$cells[3]??'','precio'=>$precio,'base'=>$base,'ret'=>cuXYes($cells[5]??'')?$rate:0]; }
    foreach($items as $i=>&$it){
        $r=$it['raw'];
        foreach(['modulos','costos'] as $k) if(!$r[$k]){
            if($it['prev']) { $r[$k]=$it['prev']['c'][$k]; if($r[$k]) $it['avisos'][]='Sin filas en «'.($k==='modulos'?'Módulos':'Costos').'»: se mantienen los '.count($r[$k]).' que ya tenía.'; }
            elseif($it['base']) { $r[$k]=$it['base']['c'][$k]; if($r[$k]) $it['avisos'][]=count($r[$k]).' '.($k==='modulos'?'módulos':'costos').' copiados de '.$it['base']['codigo'].'.'; }
        }
        if(count($r['modulos'])>40) $note($i,'aviso','Módulos',$it['row'],'','','El curso tiene '.count($r['modulos']).' módulos: se cargan los primeros 40.');
        if(count($r['costos'])>60) $note($i,'aviso','Costos',$it['row'],'','','El curso tiene '.count($r['costos']).' costos: se cargan los primeros 60.');
        $c=cuNormalize($r); $c['fijos']=cuFixedSnapshot(cuHoras($c)); $c=cuNormalize($c);
        $ev=cuEval($c); [$v]=cuAssess($c,$ev);
        if($it['prev']){ $a=$it['prev']['c']; $b=$c; unset($a['fijos']['fecha'],$b['fijos']['fecha']); $it['igual']=$a==$b; } else $it['igual']=false;
        $it['c']=$c; $it['veredicto']=$v; $it['pct']=$ev['pct']; $it['costo']=$ev['costo']; $it['sugerido']=$ev['sugerido'];
        unset($it['raw'],$it['prev'],$it['base']);
    }
    unset($it);
    // Orden de la revisión: primero errores, luego por hoja y fila.
    $ho=['Cursos'=>0,'Módulos'=>1,'Costos'=>2];
    usort($iss,fn($a,$b)=>[$a['nivel']!=='error',$ho[$a['hoja']]??3,$a['fila']]<=>[$b['nivel']!=='error',$ho[$b['hoja']]??3,$b['fila']]);
    foreach($iss as &$x) $x['curso']=$x['curso']!==null ? ($items[$x['curso']]['c']['nombre'] ?? '') : ''; unset($x);
    return ['items'=>$items,'general'=>array_values(array_unique($general)),'issues'=>$iss,'book'=>['Cursos'=>$cur,'Módulos'=>$mods,'Costos'=>$cost]];
}

/**
 * Explica por qué no llegó el archivo, con el código de PHP (UPLOAD_ERR_*) y la configuración del servidor,
 * para distinguir un problema del navegador, del límite de tamaño, de la carpeta temporal o de un filtro del hosting.
 */
function cuUploadDiag(mixed $up): string {
    $ini=fn(string $k): string => (string)(ini_get($k) ?: '—');
    $len=(int)($_SERVER['CONTENT_LENGTH']??0); $tmp=(string)(ini_get('upload_tmp_dir') ?: sys_get_temp_dir());
    $tmpOk=@is_dir($tmp) && @is_writable($tmp); $fuOff=in_array(strtolower(trim((string)ini_get('file_uploads'))),['','0','off'],true);
    $srv='Servidor: carga de archivos '.($fuOff?'DESACTIVADA':'activa').', upload_max_filesize='.$ini('upload_max_filesize').', post_max_size='.$ini('post_max_size').', carpeta temporal '.($tmpOk?'disponible':'NO disponible').', datos recibidos '.ocQty(round($len/1024,1)).' KB.';
    $code=is_array($up) && is_int($up['error']??null) ? $up['error'] : null;
    $why=match($code){
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo supera el límite de carga del servidor ('.$ini('upload_max_filesize').').',
        UPLOAD_ERR_PARTIAL => 'La carga se cortó a medio camino (conexión inestable). Intenta de nuevo.',
        UPLOAD_ERR_NO_FILE => $len>2048 ? 'El navegador envió datos, pero sin un archivo adjunto. Si la planilla está en OneDrive, iCloud o Google Drive, descárgala primero al equipo, elígela de nuevo y vuelve a presionar «Revisar planilla».' : 'No llegó ningún archivo. Elige la planilla y presiona «Revisar planilla».',
        UPLOAD_ERR_NO_TMP_DIR => 'El servidor no tiene carpeta temporal para recibir archivos. Hay que revisarlo en el hosting (configuración de PHP).',
        UPLOAD_ERR_CANT_WRITE => 'El servidor no pudo escribir el archivo en su carpeta temporal (disco lleno o permisos). Hay que revisarlo en el hosting.',
        UPLOAD_ERR_EXTENSION => 'Una extensión de PHP del servidor bloqueó la carga. Hay que revisarlo en el hosting.',
        null => $fuOff ? 'El servidor tiene desactivada la carga de archivos (file_uploads).' : ($len>2048 ? 'El servidor recibió '.ocQty(round($len/1024,1)).' KB pero descartó el archivo antes de entregarlo a la aplicación (posible filtro de seguridad del hosting).' : 'No llegó ningún archivo. Elige la planilla y presiona «Revisar planilla».'),
        default => 'La carga falló.',
    };
    error_log('cu_import sin archivo: codigo='.var_export($code,true).' files='.implode(',',array_keys($_FILES)).' '.$srv);
    return $why.' (código '.($code===null?'sin archivo':$code).'). '.$srv;
}

/** Acciones POST de la carga masiva (dentro de cuPost). */
function cuImportPost(string $action): void {
    global $user;
    if ($action==='cu_import') {
        unset($_SESSION['cu_import'],$_SESSION['cu_import_fail']);
        $up=$_FILES['archivo']??null; $name=is_array($up) && is_string($up['name']??null) ? shortText(basename(str_replace('\\','/',$up['name'])),120) : '';
        // Revisión por pasos: si uno falla, la página muestra cuál, por qué y cómo corregirlo (no solo un aviso).
        $paso=0;
        try {
            if(!is_array($up) || !is_int($up['error']??null) || $up['error']!==UPLOAD_ERR_OK) throw new RuntimeException(cuUploadDiag($up));
            $paso=1;
            if(strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='xlsx') throw new RuntimeException('El archivo «'.$name.'» no termina en .xlsx. Si la abriste en otro programa, usa «Guardar como» → Libro de Excel (.xlsx).');
            if((int)$up['size']<1 || (int)$up['size']>5*1048576) throw new RuntimeException('La planilla debe pesar entre 1 byte y 5 MB (pesa '.ocQty(round((int)$up['size']/1048576,1)).' MB).');
            // Se revisa la firma del archivo (un .xlsx es un ZIP) en vez del tipo MIME, que cambia según el hosting y el programa que guardó la planilla.
            $fh=fopen($up['tmp_name'],'rb'); $sig=$fh?(string)fread($fh,4):''; if($fh) fclose($fh);
            if($sig!=="PK\x03\x04") throw new RuntimeException(str_starts_with($sig,"\xD0\xCF\x11\xE0") ? 'La planilla está en formato Excel antiguo (.xls) o protegida con contraseña. Ábrela y usa «Guardar como» → Libro de Excel (.xlsx), sin contraseña.' : 'El archivo no es una planilla de Excel (.xlsx), aunque tenga ese nombre.');
            $paso=2;
            $z=new ZipArchive(); if($z->open($up['tmp_name'])!==true) throw new RuntimeException('El archivo está dañado y no se puede abrir. Ábrelo en Excel y guárdalo de nuevo.');
            $nowb=$z->locateName('xl/workbook.xml')===false; $macro=false; for($j=0;$j<$z->numFiles;$j++) if(str_contains(strtolower((string)$z->getNameIndex($j)),'vbaproject')) $macro=true; $z->close();
            if($nowb) throw new RuntimeException('El archivo no tiene la estructura de una planilla de Excel. Descarga la plantilla otra vez y copia tus datos en ella.');
            if($macro) throw new RuntimeException('La planilla tiene macros. Guárdala como «Libro de Excel (.xlsx)», sin macros.');
            $paso=3;
            try { $book=cuXlsxRead($up['tmp_name']); $paso=4; $res=cuImportParse($book); }
            catch(RuntimeException $ex) { if($paso===4 && (str_contains($ex->getMessage(),'fila de ejemplo') || str_contains($ex->getMessage(),'está vacía'))) $paso=5; throw $ex; }
            catch(Throwable $ex) { error_log('cu_import: '.$ex); throw new RuntimeException('No se pudo leer la planilla ('.shortText($ex->getMessage(),120).'). Descarga la plantilla otra vez y copia tus datos en ella.'); }
        } catch(RuntimeException $ex) {
            $_SESSION['cu_import_fail']=['archivo'=>$name,'paso'=>$paso,'motivo'=>$ex->getMessage(),'empresa'=>empresa()['key']];
            audit('Carga masiva de cursos rechazada',($name?:'sin archivo').' · '.shortText($ex->getMessage(),160));
            go('',['tab'=>'cu_import']);
        }
        $_SESSION['cu_import']=['token'=>bin2hex(random_bytes(8)),'archivo'=>$name,'empresa'=>empresa()['key'],'creado'=>time()]+$res;
        audit('Revisión de carga masiva de cursos',$name.' · '.count($res['items']).' curso(s)');
        $ne=count(array_filter($res['issues'],fn($x)=>$x['nivel']==='error'));
        go($ne ? 'Planilla revisada: tiene '.$ne.' error'.($ne===1?'':'es').'. Revisa el detalle abajo.' : 'Planilla revisada sin errores. Revisa la vista previa y confirma la carga.',['tab'=>'cu_import']);
    }
    if ($action==='cu_import_fail_ok') { unset($_SESSION['cu_import_fail']); go('',['tab'=>'cu_import']); }
    if ($action==='cu_import_cancel') { unset($_SESSION['cu_import'],$_SESSION['cu_import_fail']); go('Carga cancelada: no se guardó nada.',['tab'=>'cu_import']); }
    if ($action==='cu_import_ok') {
        $imp=$_SESSION['cu_import']??null;
        if(!$imp || !hash_equals((string)$imp['token'],post('token')) || $imp['empresa']!==empresa()['key']) throw new RuntimeException('La vista previa ya no está disponible. Sube la planilla otra vez.');
        $n=['nuevo'=>0,'version'=>0,'igual'=>0,'omitido'=>0]; $fail=[];
        $nota='Carga masiva desde Excel ('.$imp['archivo'].')';
        foreach($imp['items'] as $it){
            if($it['errores']){ $n['omitido']++; continue; }
            try {
                if($it['accion']==='version' && !cuGet((int)$it['id'])) throw new RuntimeException('el curso '.$it['codigo'].' ya no existe');
                [$id,$ver,$ch]=cuStore($it['c'],(int)$it['id'],$nota);
                if(!$ch) $n['igual']++; else $n[$it['accion']]++;
            } catch(RuntimeException $ex) { $fail[]='fila '.$it['row'].': '.$ex->getMessage(); }
        }
        unset($_SESSION['cu_import']);
        audit('Carga masiva de cursos',$imp['archivo'].' · '.$n['nuevo'].' nuevos, '.$n['version'].' actualizados');
        $msg='Carga lista: '.$n['nuevo'].' curso'.($n['nuevo']===1?' nuevo':'s nuevos').', '.$n['version'].' actualizado'.($n['version']===1?'':'s').' como versión nueva'.($n['igual']?', '.$n['igual'].' sin cambios':'').($n['omitido']?', '.$n['omitido'].' omitido'.($n['omitido']===1?'':'s').' por errores':'').'.'.($fail?' No se guardaron: '.implode(' · ',$fail):'');
        go($msg,['tab'=>'cu']);
    }
}
