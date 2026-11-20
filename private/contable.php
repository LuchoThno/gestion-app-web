<?php
// Módulo contable: ingresos, gastos y rendiciones de gastos con comprobantes, distribuidos por centros de costo.
// Se incluye desde app.php después de oc.php y cot.php (reutiliza ocText, ocDateIn, ocNum, ocToday, ocList, clp, ocDate, ocQty).
$db->exec('CREATE TABLE IF NOT EXISTS cc_centers(id INTEGER PRIMARY KEY, codigo TEXT NOT NULL DEFAULT \'\', nombre TEXT NOT NULL UNIQUE COLLATE NOCASE, descripcion TEXT NOT NULL DEFAULT \'\', activo INTEGER NOT NULL DEFAULT 1, created TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS cc_rendiciones(id INTEGER PRIMARY KEY, numero TEXT NOT NULL UNIQUE, titulo TEXT NOT NULL, responsable TEXT NOT NULL DEFAULT \'\', fecha TEXT NOT NULL, fondo INTEGER NOT NULL DEFAULT 0, center_id INTEGER REFERENCES cc_centers(id), estado TEXT NOT NULL, obs TEXT NOT NULL DEFAULT \'\', revision TEXT NOT NULL DEFAULT \'\', reviewer INTEGER REFERENCES users(id), reviewed TEXT, sent TEXT, owner INTEGER NOT NULL REFERENCES users(id), created TEXT NOT NULL, updated TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS cc_movs(id INTEGER PRIMARY KEY, tipo TEXT NOT NULL, fecha TEXT NOT NULL, monto INTEGER NOT NULL, iva INTEGER NOT NULL DEFAULT 0, descripcion TEXT NOT NULL, contraparte TEXT NOT NULL DEFAULT \'\', rut TEXT NOT NULL DEFAULT \'\', doc_tipo TEXT NOT NULL DEFAULT \'\', doc_numero TEXT NOT NULL DEFAULT \'\', categoria TEXT NOT NULL DEFAULT \'\', medio TEXT NOT NULL DEFAULT \'\', referencia TEXT NOT NULL DEFAULT \'\', rendicion_id INTEGER REFERENCES cc_rendiciones(id), owner INTEGER NOT NULL REFERENCES users(id), created TEXT NOT NULL, updated TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS cc_splits(id INTEGER PRIMARY KEY, mov_id INTEGER NOT NULL REFERENCES cc_movs(id) ON DELETE CASCADE, center_id INTEGER REFERENCES cc_centers(id), monto INTEGER NOT NULL, pct REAL);
CREATE TABLE IF NOT EXISTS cc_files(id INTEGER PRIMARY KEY, mov_id INTEGER NOT NULL REFERENCES cc_movs(id), name TEXT NOT NULL, stored TEXT UNIQUE NOT NULL, size INTEGER NOT NULL, owner INTEGER NOT NULL REFERENCES users(id), created TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS cc_settings(key TEXT PRIMARY KEY, value TEXT NOT NULL);
CREATE INDEX IF NOT EXISTS cc_movs_fecha ON cc_movs(fecha);
CREATE INDEX IF NOT EXISTS cc_movs_rend ON cc_movs(rendicion_id);
CREATE INDEX IF NOT EXISTS cc_splits_mov ON cc_splits(mov_id);
CREATE INDEX IF NOT EXISTS cc_splits_center ON cc_splits(center_id);
CREATE INDEX IF NOT EXISTS cc_files_mov ON cc_files(mov_id);');

const CC_TIPOS=['ingreso'=>'Ingreso','gasto'=>'Gasto'];
const CC_REND_ESTADOS=['Borrador','Enviada','Aprobada','Rechazada'];
const CC_DOCS=['Factura','Factura exenta','Boleta','Boleta de honorarios','Comprobante de transferencia','Ticket o vale','Sin documento','Otro'];
const CC_MEDIOS=['Transferencia','Efectivo','Tarjeta de débito','Tarjeta de crédito','Cheque','Otro'];
/** Condición SQL (alias m = cc_movs, r = cc_rendiciones): los gastos de una rendición solo cuentan cuando está aprobada. */
const CC_COUNTED="(m.rendicion_id IS NULL OR r.estado='Aprobada')";

/** Categorías de ingreso de una empresa de inspecciones y trabajos con ROV (MRM). */
const CC_ING_ROV=['Inspecciones ROV','Trabajos submarinos','Servicios','Arriendo de equipos','Aportes','Otros ingresos'];
function ccDefaults(): array {
    return [
        'cat_gasto'=>['Alimentación','Alojamiento','Arriendo','Combustible','Honorarios','Materiales e insumos','Peajes y estacionamiento','Remuneraciones','Servicios básicos','Servicios externos','Transporte y pasajes','Impuestos y patentes','Otros gastos'],
        'cat_ingreso'=>empresa()['cursos'] ? ['Venta de cursos','Servicios','Arriendo de equipos','Aportes','Otros ingresos'] : CC_ING_ROV,
        'ret_honorarios'=>15.25, // % de retención de boletas de honorarios (2026: 15,25 %; sube cada año según la Ley 21.133)
    ];
}
if (query('SELECT COUNT(*) FROM cc_settings')->fetchColumn()==0) query('INSERT OR IGNORE INTO cc_settings(key,value) VALUES(?,?)',['cfg',json_encode(ccDefaults(),JSON_UNESCAPED_UNICODE)]);
// MRM no dicta cursos: si su configuración aún tiene «Venta de cursos» sin usar, se reemplaza por las categorías de ROV (migración única).
if (!empresa()['cursos']) {
    $ccIng=json_decode((string)query('SELECT value FROM cc_settings WHERE key=?',['cfg'])->fetchColumn(),true);
    if (is_array($ccIng) && in_array('Venta de cursos',$ccIng['cat_ingreso']??[],true)
        && !query("SELECT 1 FROM main.cc_movs WHERE categoria='Venta de cursos' LIMIT 1")->fetchColumn()
        && !(query("SELECT 1 FROM main.sqlite_master WHERE type='table' AND name='ps_lines'")->fetchColumn() && query("SELECT 1 FROM main.ps_lines WHERE categoria='Venta de cursos' LIMIT 1")->fetchColumn())) {
        $ccIng['cat_ingreso']=array_values(array_unique([...array_slice(CC_ING_ROV,0,2),...array_diff($ccIng['cat_ingreso'],['Venta de cursos'])]));
        query('UPDATE cc_settings SET value=? WHERE key=?',[json_encode($ccIng,JSON_UNESCAPED_UNICODE),'cfg']);
    }
}
// Reparación: un movimiento sin distribución por centro de costo no aparecería en el Resumen ni en el Dashboard
// (ambos suman cc_splits). Se le asigna su monto completo a «Sin centro de costo».
if (query('SELECT 1 FROM cc_movs m WHERE NOT EXISTS(SELECT 1 FROM cc_splits s WHERE s.mov_id=m.id) LIMIT 1')->fetchColumn())
    query('INSERT INTO cc_splits(mov_id,center_id,monto,pct) SELECT m.id, NULL, m.monto, 100 FROM cc_movs m WHERE NOT EXISTS(SELECT 1 FROM cc_splits s WHERE s.mov_id=m.id)');
function ccCfg(): array {
    $c=json_decode((string)query('SELECT value FROM cc_settings WHERE key=?',['cfg'])->fetchColumn(),true);
    return is_array($c) ? $c+ccDefaults() : ccDefaults();
}
/** Monto en pesos: acepta 15000, 15.000 o 15000,5 (se redondea). */
function ccMoney(mixed $v): int {
    $v=is_scalar($v)?str_replace(['$',' '],'',trim((string)$v)):'';
    if(preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/',$v)) $v=str_replace('.','',$v);
    return (int)round(ocNum($v));
}
/** Centros de costo por id. */
function ccCenters(bool $activeOnly=false): array {
    $out=[]; foreach(query('SELECT * FROM cc_centers'.($activeOnly?' WHERE activo=1':'').' ORDER BY codigo=\'\', codigo, nombre COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC) as $c) $out[(int)$c['id']]=$c;
    return $out;
}
function ccCenterLabel(?array $c): string { return $c ? ($c['codigo']!==''?$c['codigo'].' · ':'').$c['nombre'] : 'Sin centro de costo'; }
function ccRend(int $id): array|false { return $id>0 ? query('SELECT * FROM cc_rendiciones WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC) : false; }
function ccMov(int $id): array|false {
    $m=$id>0 ? query('SELECT * FROM cc_movs WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC) : false;
    if(!$m) return false;
    $m['splits']=query('SELECT * FROM cc_splits WHERE mov_id=? ORDER BY id',[$m['id']])->fetchAll(PDO::FETCH_ASSOC);
    $m['files']=query('SELECT * FROM cc_files WHERE mov_id=? ORDER BY id',[$m['id']])->fetchAll(PDO::FETCH_ASSOC);
    return $m;
}
/** La rendición se puede modificar: en borrador o rechazada, por quien la creó o un administrador. */
function ccRendEditable(array $r): bool {
    global $user, $canEdit, $isAdmin;
    return $canEdit && in_array($r['estado'],['Borrador','Rechazada'],true) && ($isAdmin || (int)$r['owner']===(int)$user['id']);
}
function ccMovEditable(array $m): bool {
    global $canEdit;
    if($m['rendicion_id']===null) return $canEdit;
    $r=ccRend((int)$m['rendicion_id']); return $r && ccRendEditable($r);
}
function ccNextRendNumero(): string {
    $pre='REN-'.substr(ocToday(),0,4).'-'; $n=1;
    foreach(query('SELECT numero FROM cc_rendiciones WHERE numero LIKE ?',[$pre.'%'])->fetchAll(PDO::FETCH_COLUMN) as $num) { $rest=substr($num,strlen($pre)); if(ctype_digit($rest)) $n=max($n,(int)$rest+1); }
    return $pre.str_pad((string)$n,4,'0',STR_PAD_LEFT);
}
/** Totales de una rendición: [gastos, n° gastos, saldo]. Saldo > 0: el responsable devuelve; < 0: se le reembolsa. */
function ccRendTotals(array $r): array {
    $t=query('SELECT COALESCE(SUM(monto),0), COUNT(*) FROM cc_movs WHERE rendicion_id=?',[$r['id']])->fetch(PDO::FETCH_NUM);
    return ['gastos'=>(int)$t[0],'n'=>(int)$t[1],'saldo'=>(int)$r['fondo']-(int)$t[0]];
}
/** Fecha ISO (UTC) como día en hora de Chile, con el formato de las hojas (dd / mm / aaaa). */
function ccDay(?string $iso): string { if(!$iso) return ''; try { return ocDate((new DateTime($iso))->setTimezone(new DateTimeZone('America/Santiago'))->format('Y-m-d')); } catch(Exception) { return ''; } }
/** Monto con signo: los resultados negativos se muestran como «− $ 1.000». */
function ccClp(int $n): string { return ($n<0?'− ':'').clp(abs($n)); }
function ccSaldoText(int $saldo): string { return $saldo>0 ? 'A devolver por el responsable' : ($saldo<0 ? 'A reembolsar al responsable' : 'Sin saldo pendiente'); }

/**
 * Distribución por centro de costo a partir de las filas del formulario.
 * Cada fila usa porcentaje o monto; una fila con centro y sin valores recibe el resto.
 * Sin filas, todo queda «Sin centro de costo».
 */
function ccSplitsFromPost(int $total): array {
    $valid=ccCenters(); [$cs,$ps,$ms]=[ocList('sp_center'),ocList('sp_pct'),ocList('sp_monto')];
    $rows=[]; $seen=[];
    foreach($cs as $i=>$c){
        $cid=(int)$c; $p=trim($ps[$i]??''); $m=trim($ms[$i]??'');
        if($cid<=0 && $p==='' && $m==='') continue;
        if($cid<=0 || !isset($valid[$cid])) throw new RuntimeException('Elige un centro de costo en cada fila de la distribución que tenga porcentaje o monto.');
        if(isset($seen[$cid])) throw new RuntimeException('El centro «'.$valid[$cid]['nombre'].'» aparece dos veces en la distribución. Usa una sola fila por centro.');
        if($p!=='' && $m!=='') throw new RuntimeException('En cada fila de la distribución escribe porcentaje o monto, no ambos.');
        $seen[$cid]=true;
        $rows[]=['center_id'=>$cid,'pct'=>$p!==''?ocNum($p):null,'monto'=>$m!==''?ccMoney($m):null];
    }
    if(!$rows) return [['center_id'=>null,'pct'=>100.0,'monto'=>$total]];
    $free=array_keys(array_filter($rows,fn($r)=>$r['pct']===null&&$r['monto']===null));
    if(count($free)>1) throw new RuntimeException('Solo una fila de la distribución puede quedar sin porcentaje ni monto: esa recibe el resto.');
    $sum=0; $lastPct=null;
    foreach($rows as $i=>&$r){
        if($r['pct']!==null){ if($r['pct']<=0||$r['pct']>100) throw new RuntimeException('Cada porcentaje debe ser mayor que 0 y como máximo 100.'); $r['monto']=(int)round($total*$r['pct']/100); $lastPct=$i; }
        elseif($r['monto']!==null && $r['monto']<=0) throw new RuntimeException('Cada monto de la distribución debe ser mayor que 0.');
        if($r['monto']!==null) $sum+=$r['monto'];
    } unset($r);
    if($free){
        $rest=$total-$sum;
        if($rest<=0) throw new RuntimeException('Los porcentajes y montos ya suman '.clp($sum).' de un total de '.clp($total).': la fila sin valor no recibiría nada. Quítala o ajusta los valores.');
        $rows[$free[0]]['monto']=$rest;
    } else {
        $diff=$total-$sum;
        // Diferencias de redondeo de los porcentajes (un peso por fila) se ajustan en la última fila con porcentaje.
        if($diff!==0 && $lastPct!==null && abs($diff)<=count($rows)) $rows[$lastPct]['monto']+=$diff;
        elseif($diff!==0) throw new RuntimeException('La distribución suma '.clp($sum).' y el total es '.clp($total).'. Ajusta los porcentajes o montos, o deja una fila con el centro y sin valores para asignarle el resto.');
    }
    foreach($rows as &$r) $r['pct']=$total>0 ? round($r['monto']*100/$total,2) : 0.0; unset($r);
    return $rows;
}
/** Lee el formulario del movimiento. */
function ccMovFromPost(string $tipo): array {
    $cfg=ccCfg(); $cats=$tipo==='ingreso'?$cfg['cat_ingreso']:$cfg['cat_gasto'];
    $m=['tipo'=>$tipo,'fecha'=>ocDateIn(post('fecha')),'monto'=>ccMoney(post('monto')),'iva'=>ccMoney(post('iva')),'descripcion'=>ocText(post('descripcion'),200),
        'contraparte'=>ocText(post('contraparte'),160),'rut'=>ocText(post('rut'),20),'doc_tipo'=>in_array(post('doc_tipo'),CC_DOCS,true)?post('doc_tipo'):'',
        'doc_numero'=>ocText(post('doc_numero'),40),'categoria'=>in_array(post('categoria'),$cats,true)?post('categoria'):'','medio'=>in_array(post('medio'),CC_MEDIOS,true)?post('medio'):'',
        'referencia'=>ocText(post('referencia'),60)];
    return $m;
}
function ccValidateMov(array $m): void {
    if($m['fecha']==='') throw new RuntimeException('Indica la fecha del documento o movimiento.');
    if($m['descripcion']==='') throw new RuntimeException('Escribe una descripción.');
    if($m['monto']<=0) throw new RuntimeException('El monto total debe ser mayor que 0.');
    if($m['iva']>=$m['monto']) throw new RuntimeException('El IVA debe ser menor que el monto total (el total incluye el IVA).');
}
/** Guarda los comprobantes enviados en el campo comprobantes[]. Devuelve [cargados, rechazados]. */
function ccSaveFiles(int $movId): array {
    global $db, $user, $config;
    $up=$_FILES['comprobantes']??null; $ok=0; $failed=[];
    if(!$up || !is_array($up['name'])) return [0,[]];
    $count=count($up['name']); if($count>10) throw new RuntimeException('Sube como máximo 10 comprobantes a la vez.');
    $types=['pdf'=>['application/pdf'],'jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],'png'=>['image/png']];
    for($i=0;$i<$count;$i++){
        if(($up['error'][$i]??null)===UPLOAD_ERR_NO_FILE) continue;
        $label=is_string($up['name'][$i]??null)?basename(str_replace('\\','/',$up['name'][$i])):'archivo';
        try {
            if(!is_int($up['error'][$i]??null) || $up['error'][$i]!==UPLOAD_ERR_OK) throw new RuntimeException('la carga falló (revisa el límite de tamaño)');
            $tmp=$up['tmp_name'][$i]; $size=filesize($tmp);
            if($size===false || $size<1 || $size>$config['max_upload_mb']*1048576) throw new RuntimeException('vacío o superior al límite');
            $name=preg_replace('/[\x00-\x1F\x7F]/u','',$label)??'comprobante';
            if(strlen($name)>180) throw new RuntimeException('nombre demasiado largo');
            $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
            $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
            if(!isset($types[$ext]) || !in_array($mime,$types[$ext],true)) throw new RuntimeException('solo PDF, JPG o PNG');
            if(!is_dir(dataDir().'/uploads') && !mkdir(dataDir().'/uploads',0700,true)) throw new RuntimeException('no se pudo crear el almacenamiento');
            $stored='cc_'.bin2hex(random_bytes(24)); $dest=dataDir().'/uploads/'.$stored;
            $db->exec('BEGIN IMMEDIATE');
            try {
                $total=(int)query('SELECT (SELECT COALESCE(SUM(size),0) FROM files)+(SELECT COALESCE(SUM(size),0) FROM cc_files)')->fetchColumn();
                if($total+$size>$config['quota_mb']*1048576) throw new RuntimeException('cuota de almacenamiento completa');
                if(!move_uploaded_file($tmp,$dest)) throw new RuntimeException('no se pudo guardar');
                query('INSERT INTO cc_files(mov_id,name,stored,size,owner,created) VALUES(?,?,?,?,?,?)',[$movId,$name,$stored,$size,$user['id'],gmdate('c')]);
                $db->exec('COMMIT');
            } catch(Throwable $ex) { $db->exec('ROLLBACK'); if(is_file($dest)) unlink($dest); throw $ex; }
            $ok++;
        } catch(RuntimeException $ex) { $failed[]=$label.': '.($ex instanceof PDOException?'error de almacenamiento':$ex->getMessage()); }
    }
    return [$ok,$failed];
}
function ccDeleteMov(array $m): void {
    foreach($m['files'] as $f){ $p=dataDir().'/uploads/'.$f['stored']; if(is_file($p) && !unlink($p)) throw new RuntimeException('No se pudo eliminar un comprobante.'); }
    query('DELETE FROM cc_files WHERE mov_id=?',[$m['id']]); query('DELETE FROM cc_splits WHERE mov_id=?',[$m['id']]); query('DELETE FROM cc_movs WHERE id=?',[$m['id']]);
}
function ccFilesMsg(int $ok, array $failed): string {
    return ($ok?($ok===1?' 1 comprobante adjunto.':' '.$ok.' comprobantes adjuntos.'):'').($failed?' No se adjuntaron: '.implode(' · ',$failed):'');
}
function ccTouchRend(?int $rid): void { if($rid) query('UPDATE cc_rendiciones SET updated=? WHERE id=?',[gmdate('c'),$rid]); }

/** Acciones POST del módulo (dentro del try de app.php, con usuario autenticado). */
function ccPost(string $action): void {
    global $db, $user, $canEdit, $isAdmin;
    if ($action==='cc_mov_save' && $canEdit) {
        $id=(int)post('id'); $old=$id>0?ccMov($id):null;
        if($id>0 && !$old) throw new RuntimeException('El movimiento no existe.');
        if($old && !ccMovEditable($old)) throw new RuntimeException('Este gasto pertenece a una rendición enviada o aprobada y ya no se puede modificar.');
        $rid=$old ? ($old['rendicion_id']!==null?(int)$old['rendicion_id']:null) : ((int)post('rend')?:null);
        if(!$old && $rid){ $r=ccRend($rid); if(!$r) throw new RuntimeException('La rendición no existe.'); if(!ccRendEditable($r)) throw new RuntimeException('La rendición ya fue enviada o aprobada; no se le pueden agregar gastos.'); }
        $tipo=$rid ? 'gasto' : ($old ? $old['tipo'] : (post('tipo')==='ingreso'?'ingreso':'gasto'));
        $m=ccMovFromPost($tipo); ccValidateMov($m);
        $splits=ccSplitsFromPost($m['monto']);
        $now=gmdate('c');
        $db->exec('BEGIN IMMEDIATE');
        try {
            $cols=[$m['tipo'],$m['fecha'],$m['monto'],$m['iva'],$m['descripcion'],$m['contraparte'],$m['rut'],$m['doc_tipo'],$m['doc_numero'],$m['categoria'],$m['medio'],$m['referencia']];
            if($old){ query('UPDATE cc_movs SET tipo=?,fecha=?,monto=?,iva=?,descripcion=?,contraparte=?,rut=?,doc_tipo=?,doc_numero=?,categoria=?,medio=?,referencia=?,updated=? WHERE id=?',[...$cols,$now,$id]); query('DELETE FROM cc_splits WHERE mov_id=?',[$id]); }
            else { query('INSERT INTO cc_movs(tipo,fecha,monto,iva,descripcion,contraparte,rut,doc_tipo,doc_numero,categoria,medio,referencia,rendicion_id,owner,created,updated) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[...$cols,$rid,$user['id'],$now,$now]); $id=(int)$db->lastInsertId(); }
            foreach($splits as $s) query('INSERT INTO cc_splits(mov_id,center_id,monto,pct) VALUES(?,?,?,?)',[$id,$s['center_id'],$s['monto'],$s['pct']]);
            ccTouchRend($rid);
            $db->exec('COMMIT');
        } catch(Throwable $ex) { $db->exec('ROLLBACK'); throw $ex; }
        [$ok,$failed]=ccSaveFiles($id);
        audit(($old?'Edición de ':'Registro de ').strtolower(CC_TIPOS[$m['tipo']]),$m['descripcion'].' · '.clp($m['monto']));
        $msg=CC_TIPOS[$m['tipo']].' guardado.'.ccFilesMsg($ok,$failed);
        if($rid && post('after')!=='mov') go($msg,['rend'=>$rid]);
        go($msg,['mov'=>$id]);
    }
    if ($action==='cc_mov_delete' && $canEdit) {
        $m=ccMov((int)post('id')); if(!$m) go('',['tab'=>'cc_movs']);
        if($m['rendicion_id']!==null ? !ccMovEditable($m) : !$isAdmin) throw new RuntimeException($m['rendicion_id']!==null?'Este gasto pertenece a una rendición enviada o aprobada y no se puede eliminar.':'Solo el administrador elimina movimientos.');
        ccDeleteMov($m); ccTouchRend($m['rendicion_id']!==null?(int)$m['rendicion_id']:null);
        audit('Eliminación de '.strtolower(CC_TIPOS[$m['tipo']]),$m['descripcion'].' · '.clp((int)$m['monto']));
        go(CC_TIPOS[$m['tipo']].' eliminado.', $m['rendicion_id']!==null?['rend'=>(int)$m['rendicion_id']]:['tab'=>'cc_movs']);
    }
    if ($action==='cc_files_add' && $canEdit) {
        $m=ccMov((int)post('id')); if(!$m) throw new RuntimeException('El movimiento no existe.');
        if(!ccMovEditable($m)) throw new RuntimeException('Este gasto pertenece a una rendición enviada o aprobada; ya no se le pueden agregar comprobantes.');
        [$ok,$failed]=ccSaveFiles((int)$m['id']);
        if(!$ok && !$failed) throw new RuntimeException('Selecciona al menos un comprobante.');
        if(!$ok) throw new RuntimeException('No se adjuntó ningún comprobante. '.implode(' · ',$failed));
        audit('Carga de comprobantes',$m['descripcion']); go(trim(ccFilesMsg($ok,$failed)),['mov'=>(int)$m['id']]);
    }
    if ($action==='cc_file_delete' && $canEdit) {
        $f=query('SELECT * FROM cc_files WHERE id=?',[(int)post('id')])->fetch(PDO::FETCH_ASSOC); if(!$f) go();
        $m=ccMov((int)$f['mov_id']);
        if(!$m || !ccMovEditable($m)) throw new RuntimeException('Este comprobante pertenece a una rendición enviada o aprobada y no se puede quitar.');
        if($m['rendicion_id']===null && !$isAdmin && (int)$f['owner']!==(int)$user['id']) throw new RuntimeException('Solo el administrador o quien lo subió puede quitar este comprobante.');
        $p=dataDir().'/uploads/'.$f['stored']; if(is_file($p) && !unlink($p)) throw new RuntimeException('No se pudo eliminar el comprobante.');
        query('DELETE FROM cc_files WHERE id=?',[$f['id']]); audit('Eliminación de comprobante',$f['name']); go('Comprobante quitado.',['mov'=>(int)$f['mov_id']]);
    }
    // --- Rendiciones ---
    if ($action==='cc_rend_save' && $canEdit) {
        $id=(int)post('id'); $old=$id>0?ccRend($id):null;
        if($id>0 && !$old) throw new RuntimeException('La rendición no existe.');
        if($old && !ccRendEditable($old)) throw new RuntimeException('Solo se puede editar una rendición en borrador o rechazada, y solo quien la creó o un administrador.');
        $titulo=ocText(post('titulo'),120); $fecha=ocDateIn(post('fecha')); $resp=ocText(post('responsable'),100);
        if($titulo===''||$fecha===''||$resp==='') throw new RuntimeException('Completa el motivo, la fecha y el responsable de la rendición.');
        $cid=(int)post('center_id'); if($cid>0 && !isset(ccCenters()[$cid])) $cid=0;
        $vals=[$titulo,$resp,$fecha,ccMoney(post('fondo')),$cid?:null,ocText(post('obs'),1000)];
        if($old){ query('UPDATE cc_rendiciones SET titulo=?,responsable=?,fecha=?,fondo=?,center_id=?,obs=?,updated=? WHERE id=?',[...$vals,gmdate('c'),$id]); audit('Edición de rendición',$old['numero']); go('Rendición actualizada.',['rend'=>$id]); }
        $db->exec('BEGIN IMMEDIATE');
        try { $num=ccNextRendNumero(); query('INSERT INTO cc_rendiciones(numero,titulo,responsable,fecha,fondo,center_id,obs,estado,owner,created,updated) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$num,...$vals,'Borrador',$user['id'],gmdate('c'),gmdate('c')]); $id=(int)$db->lastInsertId(); $db->exec('COMMIT'); }
        catch(Throwable $ex) { $db->exec('ROLLBACK'); throw $ex; }
        audit('Creación de rendición',$num); go('Rendición '.$num.' creada. Ahora agrega sus gastos con sus comprobantes.',['rend'=>$id]);
    }
    if ($action==='cc_rend_send' && $canEdit) {
        $r=ccRend((int)post('id')); if(!$r) throw new RuntimeException('La rendición no existe.');
        if(!ccRendEditable($r)) throw new RuntimeException('Esta rendición no se puede enviar en su estado actual.');
        if(ccRendTotals($r)['n']===0) throw new RuntimeException('Agrega al menos un gasto antes de enviar la rendición.');
        query('UPDATE cc_rendiciones SET estado=?,sent=?,updated=? WHERE id=?',['Enviada',gmdate('c'),gmdate('c'),$r['id']]);
        audit('Envío de rendición',$r['numero']); wfEvent('rend',ccRend((int)$r['id']),'Enviada'); go('Rendición '.$r['numero'].' enviada a aprobación. Ya no se puede modificar salvo que sea rechazada.',['rend'=>(int)$r['id']]);
    }
    if ($action==='cc_rend_review' && $isAdmin) {
        $r=ccRend((int)post('id')); if(!$r) throw new RuntimeException('La rendición no existe.');
        if($r['estado']!=='Enviada') throw new RuntimeException('Solo se puede aprobar o rechazar una rendición enviada.');
        $ok=post('decision')==='aprobar'; $nota=ocText(post('revision'),600);
        if(!$ok && $nota==='') throw new RuntimeException('Indica el motivo del rechazo para que el responsable pueda corregirla.');
        query('UPDATE cc_rendiciones SET estado=?,revision=?,reviewer=?,reviewed=?,updated=? WHERE id=?',[$ok?'Aprobada':'Rechazada',$nota,$user['id'],gmdate('c'),gmdate('c'),$r['id']]);
        audit($ok?'Aprobación de rendición':'Rechazo de rendición',$r['numero'].($nota!==''?' · '.$nota:''));
        wfEvent('rend',ccRend((int)$r['id']),$ok?'Aprobada':'Rechazada');
        go('Rendición '.$r['numero'].($ok?' aprobada. Sus gastos ya cuentan en los resultados.':' rechazada. El responsable puede corregirla y volver a enviarla.'),['rend'=>(int)$r['id']]);
    }
    if ($action==='cc_rend_reopen' && $isAdmin) {
        $r=ccRend((int)post('id')); if(!$r) throw new RuntimeException('La rendición no existe.');
        if($r['estado']==='Borrador') go('',['rend'=>(int)$r['id']]);
        query('UPDATE cc_rendiciones SET estado=?,updated=? WHERE id=?',['Borrador',gmdate('c'),$r['id']]);
        audit('Reapertura de rendición',$r['numero']); wfEvent('rend',ccRend((int)$r['id']),'Borrador'); go('Rendición '.$r['numero'].' devuelta a borrador.',['rend'=>(int)$r['id']]);
    }
    if ($action==='cc_rend_delete' && $canEdit) {
        $r=ccRend((int)post('id')); if(!$r) go('',['tab'=>'cc_rends']);
        if(!$isAdmin && !($r['estado']==='Borrador' && (int)$r['owner']===(int)$user['id'])) throw new RuntimeException('Solo el administrador elimina rendiciones; quien la creó puede eliminarla mientras está en borrador.');
        $db->exec('BEGIN IMMEDIATE');
        try { foreach(query('SELECT id FROM cc_movs WHERE rendicion_id=?',[$r['id']])->fetchAll(PDO::FETCH_COLUMN) as $mid) ccDeleteMov(ccMov((int)$mid)); query('DELETE FROM cc_rendiciones WHERE id=?',[$r['id']]); $db->exec('COMMIT'); }
        catch(Throwable $ex) { $db->exec('ROLLBACK'); throw $ex; }
        audit('Eliminación de rendición',$r['numero']); go('Rendición '.$r['numero'].' eliminada con sus gastos y comprobantes.',['tab'=>'cc_rends']);
    }
    // --- Centros de costo y categorías (administrador) ---
    if ($action==='cc_center_save' && $isAdmin) {
        $id=(int)post('id'); $nombre=cleanName(post('nombre'),80); $codigo=ocText(post('codigo'),20); $desc=ocText(post('descripcion'),200);
        if(query('SELECT id FROM cc_centers WHERE nombre=? COLLATE NOCASE AND id<>?',[$nombre,$id])->fetchColumn()) throw new RuntimeException('Ya existe un centro de costo con ese nombre.');
        if($id>0) query('UPDATE cc_centers SET nombre=?,codigo=?,descripcion=? WHERE id=?',[$nombre,$codigo,$desc,$id]);
        else query('INSERT INTO cc_centers(nombre,codigo,descripcion,created) VALUES(?,?,?,?)',[$nombre,$codigo,$desc,gmdate('c')]);
        audit($id>0?'Edición de centro de costo':'Creación de centro de costo',$nombre); go('Centro de costo guardado.',['tab'=>'cc_cfg']);
    }
    if ($action==='cc_center_import' && $isAdmin) {
        $n=0; foreach(query('SELECT name,description FROM projects ORDER BY name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC) as $p){
            if(!query('SELECT 1 FROM cc_centers WHERE nombre=? COLLATE NOCASE',[$p['name']])->fetchColumn()){ query('INSERT INTO cc_centers(nombre,descripcion,created) VALUES(?,?,?)',[$p['name'],shortText($p['description'],200),gmdate('c')]); $n++; } }
        audit('Importación de centros de costo',(string)$n); go($n?($n===1?'Se creó 1 centro de costo':'Se crearon '.$n.' centros de costo').' a partir de los proyectos.':'Todos los proyectos ya tienen su centro de costo.',['tab'=>'cc_cfg']);
    }
    if ($action==='cc_center_toggle' && $isAdmin) {
        query('UPDATE cc_centers SET activo=1-activo WHERE id=?',[(int)post('id')]); audit('Cambio de estado de centro de costo',post('id')); go('Centro de costo actualizado.',['tab'=>'cc_cfg']);
    }
    if ($action==='cc_center_delete' && $isAdmin) {
        $id=(int)post('id');
        if(query('SELECT (SELECT COUNT(*) FROM cc_splits WHERE center_id=?)+(SELECT COUNT(*) FROM cc_rendiciones WHERE center_id=?)+(SELECT COUNT(*) FROM ps_budgets WHERE center_id=?)',[$id,$id,$id])->fetchColumn()>0) throw new RuntimeException('Ese centro de costo tiene movimientos, rendiciones o presupuestos. Desactívalo en lugar de eliminarlo.');
        query('DELETE FROM cc_centers WHERE id=?',[$id]); audit('Eliminación de centro de costo',(string)$id); go('Centro de costo eliminado.',['tab'=>'cc_cfg']);
    }
    if ($action==='cc_cfg' && $isAdmin) {
        $lines=fn(string $k): array => array_values(array_unique(array_filter(array_map(fn($l)=>ocText($l,60),preg_split('/\r\n|\n|\r/',post($k))?:[]),'strlen')));
        $g=array_slice($lines('cat_gasto'),0,60); $i=array_slice($lines('cat_ingreso'),0,60);
        if(!$g||!$i) throw new RuntimeException('Deja al menos una categoría de gasto y una de ingreso.');
        $ret=post('ret_honorarios')===''?(float)ccCfg()['ret_honorarios']:round(ocNum(post('ret_honorarios')),2);
        if($ret<0||$ret>=50) throw new RuntimeException('La retención de honorarios debe estar entre 0 y 50 %.');
        query('UPDATE cc_settings SET value=? WHERE key=?',[json_encode(['cat_gasto'=>$g,'cat_ingreso'=>$i,'ret_honorarios'=>$ret]+ccCfg(),JSON_UNESCAPED_UNICODE),'cfg']);
        audit('Configuración contable','retención honorarios '.$ret.'%'); go('Configuración guardada. Las categorías ya usadas en movimientos se conservan.',['tab'=>'cc_cfg']);
    }
}

// ---------- Consultas compartidas por las pantallas y el CSV ----------
/** Filtro de movimientos: [where, params]. Alias m = cc_movs, r = cc_rendiciones. */
function ccMovFilter(array $f): array {
    $w=[]; $p=[];
    if(isset(CC_TIPOS[$f['tipo']??''])){ $w[]='m.tipo=?'; $p[]=$f['tipo']; }
    if(($f['desde']??'')!==''){ $w[]='m.fecha>=?'; $p[]=$f['desde']; }
    if(($f['hasta']??'')!==''){ $w[]='m.fecha<=?'; $p[]=$f['hasta']; }
    if(($f['centro']??0)>0){ $w[]='EXISTS(SELECT 1 FROM cc_splits s WHERE s.mov_id=m.id AND s.center_id=?)'; $p[]=(int)$f['centro']; }
    elseif(($f['centro']??0)===-1){ $w[]='EXISTS(SELECT 1 FROM cc_splits s WHERE s.mov_id=m.id AND s.center_id IS NULL)'; }
    if(($f['cat']??'')!==''){ $w[]='m.categoria=?'; $p[]=$f['cat']; }
    if(($f['q']??'')!==''){ $q='%'.$f['q'].'%'; $w[]='(m.descripcion LIKE ? OR m.contraparte LIKE ? OR m.doc_numero LIKE ? OR m.referencia LIKE ? OR r.numero LIKE ?)'; array_push($p,$q,$q,$q,$q,$q); }
    if(($f['solo']??'')==='cuenta') $w[]=CC_COUNTED;
    return [$w?' WHERE '.implode(' AND ',$w):'',$p];
}
/** Filtros desde la URL (GET), con valores ya validados. */
function ccFiltersFromGet(): array {
    $s=fn(string $k): string => is_string($_GET[$k]??null) ? trim($_GET[$k]) : '';
    $c=(int)$s('centro');
    return ['tipo'=>isset(CC_TIPOS[$s('tipo')])?$s('tipo'):'','desde'=>ocDateIn($s('desde')),'hasta'=>ocDateIn($s('hasta')),'centro'=>$c===-1?-1:max(0,$c),'cat'=>ocText($s('cat'),60),'q'=>ocText($s('q'),80),'solo'=>$s('solo')==='cuenta'?'cuenta':''];
}
function ccFilterQuery(array $f): array { return array_filter($f,fn($v)=>$v!==''&&$v!==0); }

/**
 * Lo que el Resumen y el Dashboard no suman con los filtros dados, para explicarlo en pantalla:
 * gastos de rendiciones aún no aprobadas (en el período) y movimientos con fecha fuera del período.
 */
function ccNotCounted(string $desde, string $hasta, int $centro): array {
    $wc=[]; $pc=[];
    if($centro>0){ $wc[]='s.center_id=?'; $pc[]=$centro; } elseif($centro===-1) $wc[]='s.center_id IS NULL';
    $base=' FROM cc_splits s JOIN cc_movs m ON m.id=s.mov_id LEFT JOIN cc_rendiciones r ON r.id=m.rendicion_id WHERE ';
    $wr=["m.rendicion_id IS NOT NULL AND COALESCE(r.estado,'')<>'Aprobada'",...$wc]; $pr=$pc;
    if($desde!==''){ $wr[]='m.fecha>=?'; $pr[]=$desde; } if($hasta!==''){ $wr[]='m.fecha<=?'; $pr[]=$hasta; }
    $rend=query('SELECT COUNT(DISTINCT m.id), COALESCE(SUM(s.monto),0), COUNT(DISTINCT m.rendicion_id)'.$base.implode(' AND ',$wr),$pr)->fetch(PDO::FETCH_NUM);
    $out=['rend'=>['n'=>(int)$rend[0],'v'=>(int)$rend[1],'r'=>(int)$rend[2]],'fuera'=>['n'=>0,'min'=>'','max'=>'']];
    $or=[]; $po=[]; if($desde!==''){ $or[]='m.fecha<?'; $po[]=$desde; } if($hasta!==''){ $or[]='m.fecha>?'; $po[]=$hasta; }
    if($or){
        $x=query('SELECT COUNT(DISTINCT m.id), MIN(m.fecha), MAX(m.fecha)'.$base.implode(' AND ',[CC_COUNTED,...$wc,'('.implode(' OR ',$or).')']),[...$pc,...$po])->fetch(PDO::FETCH_NUM);
        $out['fuera']=['n'=>(int)$x[0],'min'=>(string)$x[1],'max'=>(string)$x[2]];
    }
    return $out;
}
/** Aviso con lo que no se está sumando ('' si todo cuenta). $all: enlace al período «Todo». */
function ccNotCountedHtml(array $nc, string $all): string {
    $li=[];
    if($nc['rend']['n']>0) $li[]='<b>'.$nc['rend']['n'].($nc['rend']['n']===1?' gasto':' gastos').' ('.clp($nc['rend']['v']).')</b> '.($nc['rend']['n']===1?'está':'están').' en '.($nc['rend']['r']===1?'una rendición':$nc['rend']['r'].' rendiciones').' sin aprobar (borrador, enviada o rechazada). Se suman cuando el administrador la'.($nc['rend']['r']===1?'':'s').' aprueba. <a href="'.e(url(['tab'=>'cc_rends'])).'">Ver rendiciones</a>';
    if($nc['fuera']['n']>0) $li[]='<b>'.$nc['fuera']['n'].($nc['fuera']['n']===1?' movimiento tiene':' movimientos tienen').' fecha fuera de este período</b> ('.($nc['fuera']['min']===$nc['fuera']['max']?'el '.e(ocDate($nc['fuera']['min'])):'entre el '.e(ocDate($nc['fuera']['min'])).' y el '.e(ocDate($nc['fuera']['max']))).'). <a href="'.e($all).'">Ver todo el historial</a>';
    return $li ? '<div class="alert cu-warn cc-nc" role="status"><b>No incluido en estas cifras:</b><ul><li>'.implode('</li><li>',$li).'</li></ul></div>' : '';
}
/** Hoja carta de la rendición, con el mismo diseño que la OC y la cotización. */
function ccRendSheet(array $r): void {
    $em=ocCfg()['emisor']; $t=ccRendTotals($r); $centers=ccCenters();
    $gastos=query('SELECT * FROM cc_movs WHERE rendicion_id=? ORDER BY fecha, id',[$r['id']])->fetchAll(PDO::FETCH_ASSOC);
    $sp=[]; foreach(query('SELECT s.* FROM cc_splits s JOIN cc_movs m ON m.id=s.mov_id WHERE m.rendicion_id=? ORDER BY s.id',[$r['id']])->fetchAll(PDO::FETCH_ASSOC) as $s) $sp[(int)$s['mov_id']][]=$s;
    $nf=[]; foreach(query('SELECT f.mov_id, COUNT(*) FROM cc_files f JOIN cc_movs m ON m.id=f.mov_id WHERE m.rendicion_id=? GROUP BY f.mov_id',[$r['id']])->fetchAll(PDO::FETCH_NUM) as [$mid,$n]) $nf[(int)$mid]=(int)$n;
    $byCenter=[]; foreach($sp as $rows) foreach($rows as $s){ $k=$s['center_id']===null?0:(int)$s['center_id']; $byCenter[$k]=($byCenter[$k]??0)+(int)$s['monto']; }
    $reviewer=$r['reviewer']?(string)query('SELECT name FROM users WHERE id=?',[$r['reviewer']])->fetchColumn():'';
    $stamp=['Borrador'=>'BORRADOR','Rechazada'=>'RECHAZADA','Aprobada'=>'APROBADA','Enviada'=>'POR APROBAR'][$r['estado']]??''; ?>
<article class="sheet">
<?php if($stamp):?><div class="stamp <?=ocEstadoClass($r['estado'])?>"><?=$stamp?></div><?php endif?>
<div class="s-head"><img src="<?=e(empresa()['logo'])?>" alt="<?=e(empresa()['nombre'])?>"><div class="s-title"><h2>RENDICIÓN DE GASTOS</h2>
  <div class="meta">N° <span class="num"><?=e($r['numero'])?></span></div>
  <div class="meta">Fecha&nbsp; <span class="ink"><?=e(ocDate($r['fecha']))?></span></div>
  <?php if($r['center_id'] && isset($centers[(int)$r['center_id']])):?><div class="meta">Centro de costo&nbsp; <span class="ink"><?=e(ccCenterLabel($centers[(int)$r['center_id']]))?></span></div><?php endif?>
</div></div>
<div class="accent"></div>
<div class="s-parties">
  <div><div class="lbl">Empresa</div><div class="strong"><?=e($em['razon'])?></div><div>R.U.T. <?=e($em['rut'])?></div><div><?=e($em['direccion'])?></div></div>
  <div><div class="lbl">Rinde</div><div class="strong"><?=e($r['responsable'])?></div><?php if($r['sent']):?><div class="muted">Enviada el <?=e(ccDay($r['sent']))?></div><?php endif?></div>
  <div><div class="lbl">Resumen</div><div><span class="muted">Fondo recibido&nbsp; </span><?=clp((int)$r['fondo'])?></div><div><span class="muted">Total rendido&nbsp; </span><?=clp($t['gastos'])?></div><div><span class="muted"><?=e(ccSaldoText($t['saldo']))?>&nbsp; </span><b><?=clp(abs($t['saldo']))?></b></div></div>
</div>
<div class="s-summary">
  <div class="obj"><div class="lbl">Motivo de la rendición</div><div class="t"><?=e($r['titulo'])?></div><div class="muted"><?=$t['n']?> gasto<?=$t['n']===1?'':'s'?> con <?=array_sum($nf)?> comprobante<?=array_sum($nf)===1?'':'s'?> adjunto<?=array_sum($nf)===1?'':'s'?></div></div>
  <div class="tot"><div class="lbl">Total rendido (CLP)</div><div class="v"><?=clp($t['gastos'])?></div><div class="n"><?=e(ccSaldoText($t['saldo']))?>: <?=clp(abs($t['saldo']))?></div></div>
</div>
<div class="s-sec">Detalle de gastos</div>
<table class="items"><thead><tr><th class="c w4">#</th><th class="w11">Fecha</th><th>Documento y proveedor</th><th>Descripción</th><th>Centro de costo</th><th class="r w15">Monto</th></tr></thead><tbody>
<?php foreach($gastos as $i=>$m):?><tr><td class="c muted"><?=$i+1?></td><td class="muted"><?=e(ocDate($m['fecha']))?></td><td><?=e(trim($m['doc_tipo'].' '.$m['doc_numero']))?><?php if($m['contraparte']!==''):?><div class="muted"><?=e($m['contraparte'])?></div><?php endif?></td><td><?=e($m['descripcion'])?><?php if($m['categoria']!==''):?><div class="muted"><?=e($m['categoria'])?></div><?php endif?></td>
<td><?php foreach($sp[(int)$m['id']]??[] as $s):?><div><?=e(ccCenterLabel($s['center_id']===null?null:($centers[(int)$s['center_id']]??null)))?><?=count($sp[(int)$m['id']])>1?' <span class="muted">'.e(ocQty((float)$s['pct'])).'%</span>':''?></div><?php endforeach?></td><td class="r"><?=clp((int)$m['monto'])?></td></tr><?php endforeach?>
<?php if(!$gastos):?><tr><td colspan="6" class="muted c">Sin gastos</td></tr><?php endif?>
</tbody><tfoot>
<tr><td colspan="4"></td><td class="r muted">Fondo recibido</td><td class="r"><?=clp((int)$r['fondo'])?></td></tr>
<tr><td colspan="4"></td><td class="r muted"><?=e(ccSaldoText($t['saldo']))?></td><td class="r"><?=clp(abs($t['saldo']))?></td></tr>
<tr class="total"><td colspan="4"></td><td class="r k">Total rendido</td><td class="r v"><?=clp($t['gastos'])?></td></tr>
</tfoot></table>
<?php if(count($byCenter)>1):?><div class="s-sec">Por centro de costo</div><div class="s-conds"><?php foreach($byCenter as $cid=>$v):?><div><b><?=e(ccCenterLabel($cid?($centers[$cid]??null):null))?></b>&nbsp; <?=clp($v)?></div><?php endforeach?></div><?php endif?>
<?php if($r['obs']!==''):?><div class="s-obs"><b>Observaciones</b>&nbsp; <?=e($r['obs'])?></div><?php endif?>
<?php if($r['revision']!==''):?><div class="s-obs"><b>Revisión</b>&nbsp; <?=e($r['revision'])?></div><?php endif?>
<div class="s-sec">Firmas</div>
<div class="s-sign cot-sign"><div><div class="line"><b><?=e($r['responsable'])?></b></div><div class="rol">Rinde</div></div><div><div class="line"><b><?=$reviewer!==''?e($reviewer):'&nbsp;'?></b></div><div class="muted"><?=$r['reviewed']?($r['estado']==='Aprobada'?'Aprobada':'Revisada').' el '.e(ccDay($r['reviewed'])):''?></div><div class="rol">Revisa y aprueba</div></div></div>
<div class="s-foot"><span><?=e($em['razon'])?> · R.U.T. <?=e($em['rut'])?></span><span>Valores en pesos chilenos · Comprobantes disponibles en gestion.ox3.cl</span></div>
</article>
<?php }

/** Descargas GET del módulo: comprobantes, hoja de rendición e informe CSV. */
function ccGetRequests(): void {
    global $user;
    if(!isset($_GET['cc_file']) && !isset($_GET['cc_csv']) && !isset($_GET['cc_rend_print'])) return;
    if(!$user) stop(403,'Debes iniciar sesión.');
    if(isset($_GET['cc_file'])) {
        $f=query('SELECT * FROM cc_files WHERE id=?',[(int)$_GET['cc_file']])->fetch(PDO::FETCH_ASSOC);
        if(!$f || !is_file(dataDir().'/uploads/'.$f['stored'])) stop(404,'Comprobante no encontrado.');
        $ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION)); $mime=['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png'][$ext]??'application/octet-stream';
        session_write_close();
        if(isset($_GET['dl'])) { audit('Descarga de comprobante',$f['name']); header('Content-Type: application/octet-stream'); header("Content-Disposition: attachment; filename=\"comprobante.$ext\"; filename*=UTF-8''".rawurlencode($f['name'])); }
        else {
            header('Content-Type: '.$mime); header('X-Frame-Options: SAMEORIGIN'); header('Cache-Control: private, max-age=600');
            header($ext==='pdf' ? "Content-Security-Policy: frame-ancestors 'self'" : "Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'self'");
            header("Content-Disposition: inline; filename=\"comprobante.$ext\"; filename*=UTF-8''".rawurlencode($f['name']));
        }
        header('Content-Length: '.$f['size']); readfile(dataDir().'/uploads/'.$f['stored']); exit;
    }
    if(isset($_GET['cc_rend_print'])) {
        $r=ccRend((int)$_GET['cc_rend_print']); if(!$r) stop(404,'Rendición no encontrada.');
        audit('Impresión de rendición',$r['numero']); ?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($r['numero'])?> · Rendición <?=e(empresa()['corto'])?></title><link rel="stylesheet" href="assets/style.css?v=17"></head>
<body class="print-page"><div class="print-bar no-print"><a class="btn ghost small" href="<?=e('index.php?rend='.$r['id'])?>">← Volver</a><span>Para imprimir o guardar como PDF usa <b>Ctrl + P</b> (<b>Cmd + P</b> en Mac), tamaño Carta y márgenes «Ninguno».</span></div>
<div class="sheet-wrap"><?php ccRendSheet($r)?></div></body></html><?php exit;
    }
    // Informe CSV: una fila por movimiento y centro de costo (un gasto repartido en dos centros genera dos filas).
    $f=ccFiltersFromGet(); [$where,$params]=ccMovFilter($f);
    $centers=ccCenters();
    $rows=query('SELECT m.*, r.numero AS rnum, r.estado AS restado, s.center_id, s.monto AS smonto, s.pct FROM cc_movs m LEFT JOIN cc_rendiciones r ON r.id=m.rendicion_id JOIN cc_splits s ON s.mov_id=m.id'.$where.' ORDER BY m.fecha, m.id, s.id',$params)->fetchAll(PDO::FETCH_ASSOC);
    session_write_close();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="movimientos_contables_'.strtolower(empresa()['corto']).'_'.ocToday().'.csv"');
    $out=fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,['Fecha','Tipo','Descripción','Proveedor / cliente','RUT','Documento','N° documento','Categoría','Medio de pago','Referencia','Rendición','Estado rendición','Cuenta en resultados','Monto total','IVA','Centro de costo','% centro','Monto centro'],';','"','');
    foreach($rows as $m){
        if($f['centro']>0 && (int)$m['center_id']!==$f['centro']) continue;
        if($f['centro']===-1 && $m['center_id']!==null) continue;
        $cells=[$m['fecha'],CC_TIPOS[$m['tipo']]??$m['tipo'],$m['descripcion'],$m['contraparte'],$m['rut'],$m['doc_tipo'],$m['doc_numero'],$m['categoria'],$m['medio'],$m['referencia'],$m['rnum']??'',$m['restado']??'',($m['rendicion_id']===null||$m['restado']==='Aprobada')?'Sí':'No',(int)$m['monto'],(int)$m['iva'],ccCenterLabel($m['center_id']===null?null:($centers[(int)$m['center_id']]??null)),str_replace('.',',',(string)(float)$m['pct']),(int)$m['smonto']];
        // Evita que Excel interprete celdas como fórmulas.
        fputcsv($out,array_map(fn($v)=>is_string($v)&&preg_match('/^[=+\-@\t\r]/',$v)?"'".$v:$v,$cells),';','"','');
    }
    fclose($out); exit;
}
