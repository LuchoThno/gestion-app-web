<?php
// Módulo de cotizaciones a clientes. Se incluye desde app.php después de oc.php (reutiliza sus utilidades de texto, fechas y montos).
$db->exec('CREATE TABLE IF NOT EXISTS cot_quotes(id INTEGER PRIMARY KEY, numero TEXT NOT NULL UNIQUE, fecha TEXT NOT NULL DEFAULT \'\', vence TEXT NOT NULL DEFAULT \'\', estado TEXT NOT NULL, ceco TEXT NOT NULL DEFAULT \'\', cli_razon TEXT NOT NULL DEFAULT \'\', obj_titulo TEXT NOT NULL DEFAULT \'\', neto INTEGER NOT NULL DEFAULT 0, iva_monto INTEGER NOT NULL DEFAULT 0, total INTEGER NOT NULL DEFAULT 0, data TEXT NOT NULL, owner INTEGER REFERENCES users(id), created TEXT NOT NULL, updated TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS cot_clients(id INTEGER PRIMARY KEY, razon TEXT NOT NULL UNIQUE COLLATE NOCASE, rut TEXT NOT NULL DEFAULT \'\', giro TEXT NOT NULL DEFAULT \'\', direccion TEXT NOT NULL DEFAULT \'\', ciudad TEXT NOT NULL DEFAULT \'\', contacto TEXT NOT NULL DEFAULT \'\', telefono TEXT NOT NULL DEFAULT \'\', email TEXT NOT NULL DEFAULT \'\');
CREATE TABLE IF NOT EXISTS cot_settings(key TEXT PRIMARY KEY, value TEXT NOT NULL);
CREATE INDEX IF NOT EXISTS cot_quotes_estado ON cot_quotes(estado);');

const COT_ESTADOS=['Borrador','Enviada','Aceptada','Rechazada','Anulada'];
const COT_CLI=['razon','rut','giro','direccion','ciudad','contacto','telefono','email'];

function cotDefaults(): array {
    return [
        'prefijo'=>'COT-OX3-2026-', 'siguiente'=>1, 'iva'=>19, 'validez'=>30,
        'emisor'=>ocDefaults()['emisor'],
        'firmas'=>[['rol'=>'Preparada por','nombre'=>'Luis Vargas V.','cargo'=>'Director Académico'],['rol'=>'Aceptación cliente','nombre'=>'Nombre, firma y timbre','cargo'=>'Representante del cliente']],
        'condiciones'=>[
            ['k'=>'Validez','v'=>'La presente cotización es válida hasta la fecha de vencimiento indicada.'],
            ['k'=>'Forma de pago','v'=>'Transferencia bancaria, ______ días desde la emisión de la factura.'],
            ['k'=>'Facturación','v'=>'Capacitaciones OX3 Chile SpA emite la factura al aceptar la cotización.'],
            ['k'=>'Incluye','v'=>'Relator, material del curso y certificado de participación.'],
            ['k'=>'No incluye','v'=>'Traslados, alojamiento ni alimentación, salvo que se indique.'],
            ['k'=>'Aceptación','v'=>'Se confirma con la firma de esta cotización o una orden de compra.'],
        ],
    ];
}
if (query('SELECT COUNT(*) FROM cot_settings')->fetchColumn()==0) {
    // El emisor parte igual al configurado para las OC.
    $d=cotDefaults(); $d['emisor']=ocCfg()['emisor'];
    query('INSERT OR IGNORE INTO cot_settings(key,value) VALUES(?,?)',['cfg',json_encode($d,JSON_UNESCAPED_UNICODE)]);
}
function cotCfg(): array {
    $c=json_decode((string)query('SELECT value FROM cot_settings WHERE key=?',['cfg'])->fetchColumn(),true);
    return is_array($c) ? $c+cotDefaults() : cotDefaults();
}
function cotSaveCfg(array $c): void { query('UPDATE cot_settings SET value=? WHERE key=?',[json_encode($c,JSON_UNESCAPED_UNICODE),'cfg']); }
/** Fecha de vencimiento: fecha de emisión + días de validez. */
function cotVence(string $fecha, int $dias): string {
    if($fecha===''||$dias<=0) return '';
    return (new DateTime($fecha))->modify('+'.$dias.' days')->format('Y-m-d');
}
/** Una cotización enviada cuya validez ya pasó se muestra como vencida (el estado guardado no cambia). */
function cotVencida(array $c): bool { return $c['estado']==='Enviada' && $c['vence']!=='' && $c['vence']<ocToday(); }
/** Limpia una cotización con la forma {numero, fecha, validez, cliente{}, servicio{}, objeto{}, items[], ...}. */
function cotNormalize(array $r): array {
    $base=ocNormalize(['items'=>$r['items']??[],'condiciones'=>$r['condiciones']??[],'emisor'=>$r['emisor']??[],'obs'=>$r['obs']??'']);
    $firmas=[]; foreach(array_slice(is_array($r['firmas']??null)?$r['firmas']:[],0,2) as $f){ if(is_array($f)) $firmas[]=['rol'=>ocText($f['rol']??'',40),'nombre'=>ocText($f['nombre']??'',80),'cargo'=>ocText($f['cargo']??'',80)]; }
    $estado=in_array($r['estado']??'',COT_ESTADOS,true)?$r['estado']:'Borrador';
    $iva=ocNum($r['iva']??19); if($iva>100) $iva=19;
    $desc=ocNum($r['descuento']??0); if($desc>100) $desc=0;
    $validez=(int)min(365,ocNum($r['validez']??30));
    $fecha=ocDateIn($r['fecha']??'');
    $s=is_array($r['servicio']??null)?$r['servicio']:[];
    return [
        'numero'=>ocText($r['numero']??'',40), 'fecha'=>$fecha, 'validez'=>$validez, 'vence'=>cotVence($fecha,$validez),
        'estado'=>$estado, 'ceco'=>ocText($r['ceco']??'',120), 'iva'=>$iva, 'descuento'=>$desc,
        'cliente'=>ocFields($r['cliente']??[],COT_CLI),
        'servicio'=>['modalidad'=>ocText($s['modalidad']??'',60),'lugar'=>ocText($s['lugar']??''),'desde'=>ocDateIn($s['desde']??''),'hasta'=>ocDateIn($s['hasta']??''),'participantes'=>ocText($s['participantes']??'',40)],
        'objeto'=>['titulo'=>ocText($r['objeto']['titulo']??'',160),'detalle'=>ocText($r['objeto']['detalle']??'',300)],
        'items'=>$base['items'], 'condiciones'=>$base['condiciones'], 'firmas'=>$firmas, 'obs'=>$base['obs'], 'emisor'=>$base['emisor'],
    ];
}
function cotTotals(array $c): array {
    $sub=(int)round(array_sum(array_map(fn($i)=>$i['cant']*$i['precio'],$c['items'])));
    $desc=(int)round($sub*$c['descuento']/100);
    $neto=$sub-$desc; $iva=(int)round($neto*$c['iva']/100);
    return ['subtotal'=>$sub,'descuento'=>$desc,'neto'=>$neto,'iva'=>$iva,'total'=>$neto+$iva];
}
/** Lee el formulario del editor y lo deja con la forma de cotNormalize(). */
function cotFromPost(): array {
    $r=['numero'=>post('numero'),'fecha'=>post('fecha'),'validez'=>post('validez'),'estado'=>post('estado'),'ceco'=>post('ceco'),'iva'=>post('iva'),'descuento'=>post('descuento'),'obs'=>post('obs'),
        'cliente'=>[],'servicio'=>['modalidad'=>post('sv_modalidad'),'lugar'=>post('sv_lugar'),'desde'=>post('sv_desde'),'hasta'=>post('sv_hasta'),'participantes'=>post('sv_participantes')],
        'objeto'=>['titulo'=>post('obj_titulo'),'detalle'=>post('obj_detalle')],'items'=>[],'condiciones'=>[],'firmas'=>[]];
    foreach(COT_CLI as $k) $r['cliente'][$k]=post('cli_'.$k);
    [$d,$u,$c,$p]=[ocList('it_desc'),ocList('it_unidad'),ocList('it_cant'),ocList('it_precio')];
    foreach($d as $i=>$desc) $r['items'][]=['desc'=>$desc,'unidad'=>$u[$i]??'','cant'=>$c[$i]??'0','precio'=>$p[$i]??'0'];
    [$k,$v]=[ocList('co_k'),ocList('co_v')]; foreach($k as $i=>$kk) $r['condiciones'][]=['k'=>$kk,'v'=>$v[$i]??''];
    [$fr,$fn,$fc]=[ocList('fi_rol'),ocList('fi_nombre'),ocList('fi_cargo')]; foreach($fr as $i=>$rol) $r['firmas'][]=['rol'=>$rol,'nombre'=>$fn[$i]??'','cargo'=>$fc[$i]??''];
    // Cliente guardado elegido en la lista: reemplaza los datos del cliente.
    $sid=(int)post('cli_saved');
    if($sid>0 && ($s=query('SELECT * FROM cot_clients WHERE id=?',[$sid])->fetch(PDO::FETCH_ASSOC))) foreach(COT_CLI as $kk) $r['cliente'][$kk]=$s[$kk];
    return $r;
}
function cotGet(int $id): array|false {
    $row=$id>0?query('SELECT * FROM cot_quotes WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC):false;
    if(!$row) return false;
    $c=cotNormalize(json_decode($row['data'],true)?:[]);
    return $c+['id'=>(int)$row['id'],'owner'=>$row['owner'],'created'=>$row['created'],'updated'=>$row['updated']];
}
function cotNextNumero(array $cfg): string {
    $n=max(1,(int)$cfg['siguiente']);
    while(query('SELECT 1 FROM cot_quotes WHERE numero=?',[$cfg['prefijo'].str_pad((string)$n,4,'0',STR_PAD_LEFT)])->fetchColumn()) $n++;
    return $cfg['prefijo'].str_pad((string)$n,4,'0',STR_PAD_LEFT);
}
/** Si el número sigue el prefijo, avanza el correlativo para no repetirlo. */
function cotBumpSiguiente(string $numero): void {
    $cfg=cotCfg();
    if($cfg['prefijo']!=='' && str_starts_with($numero,$cfg['prefijo']) && ctype_digit($rest=substr($numero,strlen($cfg['prefijo']))) && (int)$rest>=(int)$cfg['siguiente']) {
        $cfg['siguiente']=(int)$rest+1; cotSaveCfg($cfg);
    }
}
function cotUpsertClient(array $p): void {
    if($p['razon']==='') return;
    query('INSERT INTO cot_clients(razon,rut,giro,direccion,ciudad,contacto,telefono,email) VALUES(?,?,?,?,?,?,?,?) ON CONFLICT(razon) DO UPDATE SET rut=excluded.rut,giro=excluded.giro,direccion=excluded.direccion,ciudad=excluded.ciudad,contacto=excluded.contacto,telefono=excluded.telefono,email=excluded.email',array_values($p));
}
/** Inserta o actualiza una cotización ya normalizada. Devuelve su id. Llamar dentro de una transacción. */
function cotStore(array $c, int $id, ?int $owner): int {
    $t=cotTotals($c); $now=gmdate('c');
    $cols=[$c['numero'],$c['fecha'],$c['vence'],$c['estado'],$c['ceco'],$c['cliente']['razon'],$c['objeto']['titulo'],$t['neto'],$t['iva'],$t['total'],json_encode($c,JSON_UNESCAPED_UNICODE)];
    if($id>0){ query('UPDATE cot_quotes SET numero=?,fecha=?,vence=?,estado=?,ceco=?,cli_razon=?,obj_titulo=?,neto=?,iva_monto=?,total=?,data=?,updated=? WHERE id=?',[...$cols,$now,$id]); return $id; }
    query('INSERT INTO cot_quotes(numero,fecha,vence,estado,ceco,cli_razon,obj_titulo,neto,iva_monto,total,data,owner,created,updated) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[...$cols,$owner,$now,$now]);
    global $db; return (int)$db->lastInsertId();
}

/** Acciones POST del módulo (dentro del try de app.php, con usuario autenticado). */
function cotPost(string $action): void {
    global $db, $user, $canEdit, $isAdmin;
    if ($action==='cot_save' && $canEdit) {
        $id=(int)post('id'); $old=$id>0?cotGet($id):null;
        if($id>0 && !$old) throw new RuntimeException('La cotización no existe.');
        $c=cotNormalize(cotFromPost());
        if(!$c['items']) throw new RuntimeException('Agrega al menos un ítem con descripción o precio.');
        $c['emisor']=$old?$old['emisor']:cotCfg()['emisor'];
        $db->exec('BEGIN IMMEDIATE');
        try {
            if($c['numero']==='') { if($old) throw new RuntimeException('El N° de cotización no puede quedar vacío.'); $c['numero']=cotNextNumero(cotCfg()); }
            if(query('SELECT id FROM cot_quotes WHERE numero=? AND id<>?',[$c['numero'],$id])->fetchColumn()) throw new RuntimeException('Ya existe una cotización con el número '.$c['numero'].'. Cámbialo antes de guardar.');
            $id=cotStore($c,$id,(int)$user['id']);
            cotBumpSiguiente($c['numero']); cotUpsertClient($c['cliente']);
            $db->exec('COMMIT');
        } catch(Throwable $ex) { $db->exec('ROLLBACK'); throw $ex; }
        audit($old?'Edición de cotización':'Creación de cotización',$c['numero']);
        go('Cotización '.$c['numero'].' guardada.', post('after')==='edit' ? ['cot'=>$id,'edit'=>1] : ['cot'=>$id]);
    }
    if ($action==='cot_estado' && $canEdit) {
        $c=cotGet((int)post('id')); if(!$c) throw new RuntimeException('La cotización no existe.');
        $estado=post('estado'); if(!in_array($estado,COT_ESTADOS,true)) throw new RuntimeException('Estado no válido.');
        $id=$c['id']; unset($c['id'],$c['owner'],$c['created'],$c['updated']); $c['estado']=$estado;
        cotStore($c,$id,null); audit('Cambio de estado de cotización',$c['numero'].' → '.$estado);
        go('Cotización '.$c['numero'].': '.$estado.'.', post('back')==='cot' ? ['cot'=>$id] : ['tab'=>'cot']);
    }
    if ($action==='cot_delete' && $isAdmin) {
        $c=cotGet((int)post('id')); if(!$c) go('',['tab'=>'cot']);
        query('DELETE FROM cot_quotes WHERE id=?',[$c['id']]); audit('Eliminación de cotización',$c['numero']); go('Cotización '.$c['numero'].' eliminada.',['tab'=>'cot']);
    }
    if ($action==='cot_cfg' && $isAdmin) {
        $cfg=cotCfg();
        $prefijo=ocText(post('prefijo'),30); $sig=(int)post('siguiente');
        if($sig<1||$sig>999999) throw new RuntimeException('El próximo correlativo debe ser un número entre 1 y 999999.');
        $iva=ocNum(post('iva')); if($iva>100) throw new RuntimeException('El IVA debe estar entre 0 y 100.');
        $validez=(int)ocNum(post('validez')); if($validez<1||$validez>365) throw new RuntimeException('La validez debe estar entre 1 y 365 días.');
        $emisor=[]; foreach(OC_EMISOR as $k) $emisor[$k]=post('em_'.$k);
        $n=cotNormalize(['emisor'=>$emisor,'firmas'=>array_map(fn($r,$nn,$c)=>['rol'=>$r,'nombre'=>$nn,'cargo'=>$c],ocList('fi_rol'),ocList('fi_nombre'),ocList('fi_cargo')),
            'condiciones'=>array_map(fn($k,$v)=>['k'=>$k,'v'=>$v],ocList('co_k'),ocList('co_v'))]);
        cotSaveCfg(['prefijo'=>$prefijo,'siguiente'=>$sig,'iva'=>$iva,'validez'=>$validez,'emisor'=>$n['emisor'],'firmas'=>$n['firmas'],'condiciones'=>$n['condiciones']]+$cfg);
        audit('Configuración de cotizaciones'); go('Configuración guardada. Se aplica a las próximas cotizaciones.',['tab'=>'cot_cfg']);
    }
    if ($action==='cot_client_delete' && $isAdmin) {
        $name=(string)query('SELECT razon FROM cot_clients WHERE id=?',[(int)post('id')])->fetchColumn();
        query('DELETE FROM cot_clients WHERE id=?',[(int)post('id')]); audit('Eliminación de cliente',$name); go('Cliente quitado de la lista.',['tab'=>'cot_cfg']);
    }
}

// ---------- Presentación (también la usa la vista de impresión) ----------
/** Hoja carta de la cotización, con el mismo diseño que la OC. */
function cotSheet(array $c): void {
    $em=$c['emisor']; $p=$c['cliente']; $s=$c['servicio']; $t=cotTotals($c);
    $blank='<span class="muted">__________________</span>';
    $stamp=['Anulada'=>'ANULADA','Borrador'=>'BORRADOR','Rechazada'=>'RECHAZADA','Aceptada'=>'ACEPTADA'][$c['estado']]??'';
    $half=(int)ceil(count($c['condiciones'])/2); ?>
<article class="sheet">
<?php if($stamp):?><div class="stamp <?=ocEstadoClass($c['estado'])?>"><?=$stamp?></div><?php endif?>
<div class="s-head"><img src="assets/logo-ox3.png" alt="OX3 Capacitaciones"><div class="s-title"><h2>COTIZACIÓN</h2>
  <div class="meta">N° <span class="num"><?=e($c['numero']?:'—')?></span></div>
  <div class="meta">Fecha de emisión&nbsp; <span class="ink"><?=e(ocDate($c['fecha']))?></span></div>
  <div class="meta">Válida hasta&nbsp; <span class="ink"><?=e(ocDate($c['vence']))?></span><?=$c['validez']>0?' <span class="muted">('.(int)$c['validez'].' días)</span>':''?></div>
  <?php if($c['ceco']!==''):?><div class="meta">Proyecto&nbsp; <span class="ink"><?=e($c['ceco'])?></span></div><?php endif?>
</div></div>
<div class="accent"></div>
<div class="s-parties">
  <div><div class="lbl">Emitida por</div><div class="strong"><?=e($em['razon'])?></div><div>R.U.T. <?=e($em['rut'])?></div><div><?=e($em['direccion'])?></div><div class="link"><?=e($em['email'])?><?=$em['web']!==''?' · '.e($em['web']):''?></div></div>
  <div><div class="lbl">Cliente</div><div class="strong"><?=$p['razon']!==''?e($p['razon']):$blank?></div><div>R.U.T. <?=$p['rut']!==''?e($p['rut']):$blank?></div>
    <?php if($p['giro']!==''):?><div class="muted"><?=e($p['giro'])?></div><?php endif?>
    <div><?=e(implode(', ',array_filter([$p['direccion'],$p['ciudad']],'strlen')))?></div>
    <?php if($p['contacto']!==''):?><div><span class="muted">At.&nbsp; </span><?=e($p['contacto'])?></div><?php endif?>
    <div class="link"><?=e(implode(' · ',array_filter([$p['email'],$p['telefono']],'strlen')))?></div></div>
  <div><div class="lbl">Ejecución</div><div><span class="muted">Modalidad&nbsp; </span><?=$s['modalidad']!==''?e($s['modalidad']):$blank?></div><div><span class="muted">Lugar&nbsp; </span><?=$s['lugar']!==''?e($s['lugar']):$blank?></div><div><span class="muted">Desde&nbsp; </span><?=e(ocDate($s['desde']))?></div><div><span class="muted">Hasta&nbsp; </span><?=e(ocDate($s['hasta']))?></div><?php if($s['participantes']!==''):?><div><span class="muted">Participantes&nbsp; </span><?=e($s['participantes'])?></div><?php endif?></div>
</div>
<div class="s-summary">
  <div class="obj"><div class="lbl">Objeto de la cotización</div><div class="t"><?=e($c['objeto']['titulo']?:'—')?></div><div class="muted"><?=e($c['objeto']['detalle'])?></div></div>
  <div class="tot"><div class="lbl">Monto total (CLP)</div><div class="v"><?=clp($t['total'])?></div><div class="n">Neto <?=clp($t['neto'])?><?=$c['iva']>0?' + IVA '.clp($t['iva']):' · exento de IVA'?></div></div>
</div>
<div class="s-sec">Detalle</div>
<table class="items"><thead><tr><th class="c w4">#</th><th>Descripción</th><th class="c w11">Unidad</th><th class="c w8">Cant.</th><th class="r w15">Precio unit.</th><th class="r w15">Total</th></tr></thead><tbody>
<?php foreach($c['items'] as $i=>$it):?><tr><td class="c muted"><?=$i+1?></td><td><?=e($it['desc'])?></td><td class="c muted"><?=e($it['unidad'])?></td><td class="c"><?=e(ocQty($it['cant']))?></td><td class="r"><?=clp($it['precio'])?></td><td class="r"><?=clp($it['cant']*$it['precio'])?></td></tr><?php endforeach?>
<?php if(!$c['items']):?><tr><td colspan="6" class="muted c">Sin ítems</td></tr><?php endif?>
</tbody><tfoot>
<?php if($t['descuento']>0):?><tr><td colspan="4"></td><td class="r muted">Subtotal</td><td class="r"><?=clp($t['subtotal'])?></td></tr>
<tr><td colspan="4"></td><td class="r muted">Descuento <?=e(ocQty($c['descuento']))?>%</td><td class="r">− <?=clp($t['descuento'])?></td></tr><?php endif?>
<tr><td colspan="4"></td><td class="r muted">Subtotal neto</td><td class="r"><?=clp($t['neto'])?></td></tr>
<tr><td colspan="4"></td><td class="r muted"><?=$c['iva']>0?'IVA '.e(ocQty($c['iva'])).'%':'IVA (exento)'?></td><td class="r"><?=clp($t['iva'])?></td></tr>
<tr class="total"><td colspan="4"></td><td class="r k">Total</td><td class="r v"><?=clp($t['total'])?></td></tr>
</tfoot></table>
<?php if($c['condiciones']):?><div class="s-sec">Condiciones comerciales</div><div class="s-conds"><?php foreach([array_slice($c['condiciones'],0,$half),array_slice($c['condiciones'],$half)] as $col):?><div><?php foreach($col as $cc):?><div><b><?=e($cc['k'])?></b>&nbsp; <?=e($cc['v'])?></div><?php endforeach?></div><?php endforeach?></div><?php endif?>
<?php if($c['obs']!==''):?><div class="s-obs"><b>Observaciones</b>&nbsp; <?=e($c['obs'])?></div><?php endif?>
<div class="s-sec">Aceptación</div>
<div class="s-sign cot-sign"><?php foreach($c['firmas'] as $f):?><div><div class="line"><b><?=e($f['nombre'])?></b></div><div class="muted"><?=e($f['cargo'])?></div><div class="rol"><?=e($f['rol'])?></div></div><?php endforeach?></div>
<div class="s-foot"><span><?=e($em['razon'])?> · R.U.T. <?=e($em['rut'])?><?=$em['web']!==''?' · '.e($em['web']):''?></span><span>Valores en pesos chilenos · Cotización sujeta a disponibilidad</span></div>
</article>
<?php }

/** Descargas GET del módulo: hoja para imprimir y registro en CSV. */
function cotGetRequests(): void {
    global $user;
    if(!isset($_GET['cot_print']) && !isset($_GET['cot_csv'])) return;
    if(!$user) stop(403,'Debes iniciar sesión.');
    if(isset($_GET['cot_print'])) {
        $c=cotGet((int)$_GET['cot_print']); if(!$c) stop(404,'Cotización no encontrada.');
        audit('Impresión de cotización',$c['numero']); ?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($c['numero'])?> · Cotización OX3</title><link rel="stylesheet" href="assets/style.css?v=4"></head>
<body class="print-page"><div class="print-bar no-print"><a class="btn ghost small" href="<?=e('index.php?cot='.$c['id'])?>">← Volver</a><span>Para imprimir o guardar como PDF usa <b>Ctrl + P</b> (<b>Cmd + P</b> en Mac), tamaño Carta y márgenes «Ninguno».</span></div>
<div class="sheet-wrap"><?php cotSheet($c)?></div></body></html><?php exit;
    }
    [$where,$params]=cotFilter(is_string($_GET['q']??null)?trim($_GET['q']):'', is_string($_GET['estado']??null)?$_GET['estado']:'');
    $rows=query('SELECT data FROM cot_quotes'.$where.' ORDER BY id DESC',$params)->fetchAll(PDO::FETCH_COLUMN);
    session_write_close();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="registro_cotizaciones_ox3_'.ocToday().'.csv"');
    $out=fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,['N° cotización','Fecha','Válida hasta','Estado','Cliente','RUT cliente','Contacto','Objeto','Modalidad','Desde','Hasta','Descuento %','Neto','IVA','Total','Proyecto'],';','"','');
    foreach($rows as $json){ $c=cotNormalize(json_decode($json,true)?:[]); $t=cotTotals($c);
        $estado=cotVencida($c)?'Vencida':$c['estado'];
        // Evita que Excel interprete celdas como fórmulas.
        $cells=array_map(fn($v)=>is_string($v)&&preg_match('/^[=+\-@\t\r]/',$v)?"'".$v:$v,[$c['numero'],$c['fecha'],$c['vence'],$estado,$c['cliente']['razon'],$c['cliente']['rut'],$c['cliente']['contacto'],$c['objeto']['titulo'],$c['servicio']['modalidad'],$c['servicio']['desde'],$c['servicio']['hasta'],ocQty($c['descuento']),$t['neto'],$t['iva'],$t['total'],$c['ceco']]);
        fputcsv($out,$cells,';','"',''); }
    fclose($out); exit;
}
/** Filtro del registro. «Vencida» no es un estado guardado: son las enviadas cuya validez ya pasó. */
function cotFilter(string $q, string $estado): array {
    $w=[]; $p=[];
    if($q!==''){ $w[]='(numero LIKE ? OR cli_razon LIKE ? OR obj_titulo LIKE ? OR ceco LIKE ?)'; array_push($p,"%$q%","%$q%","%$q%","%$q%"); }
    if($estado==='Vencida'){ $w[]="estado='Enviada' AND vence<>'' AND vence<?"; $p[]=ocToday(); }
    elseif($estado==='Enviada'){ $w[]="estado='Enviada' AND (vence='' OR vence>=?)"; $p[]=ocToday(); }
    elseif(in_array($estado,COT_ESTADOS,true)){ $w[]='estado=?'; $p[]=$estado; }
    return [$w?' WHERE '.implode(' AND ',$w):'',$p];
}
