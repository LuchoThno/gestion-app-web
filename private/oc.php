<?php
// Módulo de órdenes de compra (OC). Se incluye desde app.php después de cargar el usuario.
$db->exec('CREATE TABLE IF NOT EXISTS oc_orders(id INTEGER PRIMARY KEY, numero TEXT NOT NULL UNIQUE, fecha TEXT NOT NULL DEFAULT \'\', estado TEXT NOT NULL, ceco TEXT NOT NULL DEFAULT \'\', prov_razon TEXT NOT NULL DEFAULT \'\', obj_titulo TEXT NOT NULL DEFAULT \'\', neto INTEGER NOT NULL DEFAULT 0, iva_monto INTEGER NOT NULL DEFAULT 0, total INTEGER NOT NULL DEFAULT 0, data TEXT NOT NULL, owner INTEGER REFERENCES users(id), created TEXT NOT NULL, updated TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS oc_suppliers(id INTEGER PRIMARY KEY, razon TEXT NOT NULL UNIQUE COLLATE NOCASE, rut TEXT NOT NULL DEFAULT \'\', giro TEXT NOT NULL DEFAULT \'\', direccion TEXT NOT NULL DEFAULT \'\', ciudad TEXT NOT NULL DEFAULT \'\', telefono TEXT NOT NULL DEFAULT \'\', email TEXT NOT NULL DEFAULT \'\');
CREATE TABLE IF NOT EXISTS oc_settings(key TEXT PRIMARY KEY, value TEXT NOT NULL);
CREATE INDEX IF NOT EXISTS oc_orders_estado ON oc_orders(estado);');

const OC_ESTADOS=['Borrador','Emitida','Aceptada','Recibida','Pagada','Anulada'];
const OC_PROV=['razon','rut','giro','direccion','ciudad','telefono','email'];
const OC_EMISOR=['razon','rut','giro','direccion','email','web'];

function ocDefaults(): array {
    $em=empresa();
    if($em['key']!==EMPRESA_BASE) return [
        // Empresa adicional: sus datos iniciales (completar RUT, dirección y firmas en Configuración de OC).
        'prefijo'=>'OC-'.$em['corto'].'-'.date('Y').'-', 'siguiente'=>1, 'iva'=>19,
        'emisor'=>$em['emisor'],
        'firmas'=>[['rol'=>'Solicita','nombre'=>'Nombre','cargo'=>'Cargo'],['rol'=>'Autoriza','nombre'=>'Nombre','cargo'=>'Gerente General'],['rol'=>'Acepta proveedor','nombre'=>'Nombre y timbre','cargo'=>'Representante proveedor']],
        'condiciones'=>[
            ['k'=>'Pago','v'=>'Por transferencia, ______ días desde la recepción de la factura.'],
            ['k'=>'Facturación','v'=>'A nombre de '.$em['emisor']['razon'].', citando N° de OC.'],
            ['k'=>'Entrega','v'=>'Con acta de recepción firmada por ambas partes.'],
            ['k'=>'Aceptación','v'=>'Se entiende aceptada sin objeción dentro de 48 horas.'],
        ],
    ];
    return [
        'prefijo'=>'OC-OX3-2026-', 'siguiente'=>2, 'iva'=>19,
        'emisor'=>['razon'=>'Capacitaciones OX3 Chile SpA','rut'=>'77.927.027-0','giro'=>'Capacitación','direccion'=>'Mejicana 518, Of. 1, Punta Arenas','email'=>'contacto@ox3.cl','web'=>'www.ox3.cl'],
        'firmas'=>[['rol'=>'Solicita','nombre'=>'Luis Vargas V.','cargo'=>'Director Académico'],['rol'=>'Autoriza','nombre'=>'Claudio Ojeda','cargo'=>'Gerente General'],['rol'=>'Acepta proveedor','nombre'=>'Nombre y timbre','cargo'=>'Representante proveedor']],
        'condiciones'=>[
            ['k'=>'Pago','v'=>'Por transferencia, ______ días desde la recepción de la factura.'],
            ['k'=>'Facturación','v'=>'A nombre de Capacitaciones OX3 Chile SpA, citando N° de OC.'],
            ['k'=>'Certificación','v'=>'Servicio vigente por estación autorizada durante todo el arriendo.'],
            ['k'=>'Entrega','v'=>'Con acta de recepción y devolución firmada por ambas partes.'],
            ['k'=>'Responsabilidad','v'=>'OX3 responde por daños por mal uso; no por desgaste normal.'],
            ['k'=>'Aceptación','v'=>'Se entiende aceptada sin objeción dentro de 48 horas.'],
        ],
    ];
}
// Primera instalación del módulo: configuración inicial y proveedor de ejemplo de la herramienta anterior.
if (query('SELECT COUNT(*) FROM oc_settings')->fetchColumn()==0) {
    query('INSERT OR IGNORE INTO oc_settings(key,value) VALUES(?,?)',['cfg',json_encode(ocDefaults(),JSON_UNESCAPED_UNICODE)]);
    if (empresa()['key']===EMPRESA_BASE) query('INSERT OR IGNORE INTO oc_suppliers(razon,rut,giro,direccion,ciudad,telefono,email) VALUES(?,?,?,?,?,?,?)',['STM Servicios SpA','77.534.316-8','Servicios industriales y marítimos','Los Pellines 229, Techo PA','Puerto Montt','65 243 5720','info@stmservicios.cl']);
}
function ocCfg(): array {
    $c=json_decode((string)query('SELECT value FROM oc_settings WHERE key=?',['cfg'])->fetchColumn(),true);
    return is_array($c) ? $c+ocDefaults() : ocDefaults();
}
function ocText(mixed $v, int $max=300): string { return is_scalar($v) ? shortText((string)$v,$max) : ''; }
function ocDateIn(mixed $v): string { $v=ocText($v,10); return preg_match('/^\d{4}-\d{2}-\d{2}$/',$v)&&checkdate((int)substr($v,5,2),(int)substr($v,8,2),(int)substr($v,0,4)) ? $v : ''; }
function ocNum(mixed $v): float { $v=is_scalar($v)?str_replace(',','.',trim((string)$v)):''; return is_numeric($v) ? max(0.0,min((float)$v,1e12)) : 0.0; }
function ocToday(): string { return (new DateTime('now',new DateTimeZone('America/Santiago')))->format('Y-m-d'); }
function ocFields(mixed $src, array $keys): array { $o=[]; foreach($keys as $k) $o[$k]=ocText(is_array($src)?($src[$k]??''):''); return $o; }
/** Limpia una OC con la forma {numero, fecha, proveedor{}, entrega{}, objeto{}, items[], ...}. */
function ocNormalize(array $r): array {
    $items=[]; foreach(array_slice(is_array($r['items']??null)?$r['items']:[],0,60) as $it){ if(!is_array($it)) continue;
        $i=['desc'=>ocText($it['desc']??'',500),'unidad'=>ocText($it['unidad']??'',30),'cant'=>ocNum($it['cant']??0),'precio'=>(int)round(ocNum($it['precio']??0))];
        if($i['desc']!==''||$i['precio']>0) $items[]=$i; }
    $conds=[]; foreach(array_slice(is_array($r['condiciones']??null)?$r['condiciones']:[],0,30) as $c){ if(!is_array($c)) continue;
        $c=['k'=>ocText($c['k']??'',60),'v'=>ocText($c['v']??'',400)]; if($c['k']!==''||$c['v']!=='') $conds[]=$c; }
    $firmas=[]; foreach(array_slice(is_array($r['firmas']??null)?$r['firmas']:[],0,3) as $f){ if(is_array($f)) $firmas[]=['rol'=>ocText($f['rol']??'',40),'nombre'=>ocText($f['nombre']??'',80),'cargo'=>ocText($f['cargo']??'',80)]; }
    $estado=in_array($r['estado']??'',OC_ESTADOS,true)?$r['estado']:'Borrador';
    $iva=ocNum($r['iva']??19); if($iva>100) $iva=19;
    return [
        'numero'=>ocText($r['numero']??'',40), 'fecha'=>ocDateIn($r['fecha']??''), 'cotizacion'=>ocText($r['cotizacion']??'',80),
        'estado'=>$estado, 'ceco'=>ocText($r['ceco']??'',120), 'iva'=>$iva,
        'proveedor'=>ocFields($r['proveedor']??[],OC_PROV),
        'entrega'=>['lugar'=>ocText($r['entrega']['lugar']??''),'desde'=>ocDateIn($r['entrega']['desde']??''),'hasta'=>ocDateIn($r['entrega']['hasta']??''),'solicitante'=>ocText($r['entrega']['solicitante']??'',100)],
        'objeto'=>['titulo'=>ocText($r['objeto']['titulo']??'',160),'detalle'=>ocText($r['objeto']['detalle']??'',300)],
        'items'=>$items, 'condiciones'=>$conds, 'firmas'=>$firmas, 'obs'=>ocText(str_replace(["\r\n","\r","\n"],' ',(string)(is_scalar($r['obs']??'')?$r['obs']??'':'')),1500),
        'emisor'=>ocFields($r['emisor']??[],OC_EMISOR),
    ];
}
function ocTotals(array $o): array {
    $neto=(int)round(array_sum(array_map(fn($i)=>$i['cant']*$i['precio'],$o['items'])));
    $iva=(int)round($neto*$o['iva']/100);
    return ['neto'=>$neto,'iva'=>$iva,'total'=>$neto+$iva];
}
function ocList(string $k): array { $v=$_POST[$k]??[]; return is_array($v) ? array_values(array_filter($v,'is_string')) : []; }
/** Lee el formulario del editor y lo deja con la forma de ocNormalize(). */
function ocFromPost(): array {
    $r=['numero'=>post('numero'),'fecha'=>post('fecha'),'cotizacion'=>post('cotizacion'),'estado'=>post('estado'),'ceco'=>post('ceco'),'iva'=>post('iva'),'obs'=>post('obs'),
        'proveedor'=>[],'entrega'=>['lugar'=>post('ent_lugar'),'desde'=>post('ent_desde'),'hasta'=>post('ent_hasta'),'solicitante'=>post('ent_solicitante')],
        'objeto'=>['titulo'=>post('obj_titulo'),'detalle'=>post('obj_detalle')],'items'=>[],'condiciones'=>[],'firmas'=>[]];
    foreach(OC_PROV as $k) $r['proveedor'][$k]=post('prov_'.$k);
    [$d,$u,$c,$p]=[ocList('it_desc'),ocList('it_unidad'),ocList('it_cant'),ocList('it_precio')];
    foreach($d as $i=>$desc) $r['items'][]=['desc'=>$desc,'unidad'=>$u[$i]??'','cant'=>$c[$i]??'0','precio'=>$p[$i]??'0'];
    [$k,$v]=[ocList('co_k'),ocList('co_v')]; foreach($k as $i=>$kk) $r['condiciones'][]=['k'=>$kk,'v'=>$v[$i]??''];
    [$fr,$fn,$fc]=[ocList('fi_rol'),ocList('fi_nombre'),ocList('fi_cargo')]; foreach($fr as $i=>$rol) $r['firmas'][]=['rol'=>$rol,'nombre'=>$fn[$i]??'','cargo'=>$fc[$i]??''];
    // Proveedor guardado elegido en la lista: reemplaza los datos del proveedor.
    $sid=(int)post('prov_saved');
    if($sid>0 && ($s=query('SELECT * FROM oc_suppliers WHERE id=?',[$sid])->fetch(PDO::FETCH_ASSOC))) foreach(OC_PROV as $kk) $r['proveedor'][$kk]=$s[$kk];
    return $r;
}
function ocGet(int $id): array|false {
    $row=$id>0?query('SELECT * FROM oc_orders WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC):false;
    if(!$row) return false;
    $o=ocNormalize(json_decode($row['data'],true)?:[]);
    return $o+['id'=>(int)$row['id'],'owner'=>$row['owner'],'created'=>$row['created'],'updated'=>$row['updated']];
}
function ocNextNumero(array $cfg): string {
    $n=max(1,(int)$cfg['siguiente']);
    while(query('SELECT 1 FROM oc_orders WHERE numero=?',[$cfg['prefijo'].str_pad((string)$n,4,'0',STR_PAD_LEFT)])->fetchColumn()) $n++;
    return $cfg['prefijo'].str_pad((string)$n,4,'0',STR_PAD_LEFT);
}
/** Si el número sigue el prefijo, avanza el correlativo para no repetirlo. */
function ocBumpSiguiente(string $numero): void {
    $cfg=ocCfg();
    if($cfg['prefijo']!=='' && str_starts_with($numero,$cfg['prefijo']) && ctype_digit($rest=substr($numero,strlen($cfg['prefijo']))) && (int)$rest>=(int)$cfg['siguiente']) {
        $cfg['siguiente']=(int)$rest+1; query('UPDATE oc_settings SET value=? WHERE key=?',[json_encode($cfg,JSON_UNESCAPED_UNICODE),'cfg']);
    }
}
function ocUpsertSupplier(array $p): void {
    if($p['razon']==='') return;
    query('INSERT INTO oc_suppliers(razon,rut,giro,direccion,ciudad,telefono,email) VALUES(?,?,?,?,?,?,?) ON CONFLICT(razon) DO UPDATE SET rut=excluded.rut,giro=excluded.giro,direccion=excluded.direccion,ciudad=excluded.ciudad,telefono=excluded.telefono,email=excluded.email',array_values($p));
}
/** Inserta o actualiza una OC ya normalizada. Devuelve su id. Llamar dentro de una transacción. */
function ocStore(array $o, int $id, ?int $owner): int {
    $t=ocTotals($o); $now=gmdate('c');
    $cols=[$o['numero'],$o['fecha'],$o['estado'],$o['ceco'],$o['proveedor']['razon'],$o['objeto']['titulo'],$t['neto'],$t['iva'],$t['total'],json_encode($o,JSON_UNESCAPED_UNICODE)];
    if($id>0){ query('UPDATE oc_orders SET numero=?,fecha=?,estado=?,ceco=?,prov_razon=?,obj_titulo=?,neto=?,iva_monto=?,total=?,data=?,updated=? WHERE id=?',[...$cols,$now,$id]); return $id; }
    query('INSERT INTO oc_orders(numero,fecha,estado,ceco,prov_razon,obj_titulo,neto,iva_monto,total,data,owner,created,updated) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',[...$cols,$owner,$now,$now]);
    global $db; return (int)$db->lastInsertId();
}

/** Acciones POST del módulo (dentro del try de app.php, con usuario autenticado). */
function ocPost(string $action): void {
    global $db, $user, $canEdit, $isAdmin;
    if ($action==='oc_save' && $canEdit) {
        $id=(int)post('id'); $old=$id>0?ocGet($id):null;
        if($id>0 && !$old) throw new RuntimeException('La orden de compra no existe.');
        $o=ocNormalize(ocFromPost());
        if(!$o['items']) throw new RuntimeException('Agrega al menos un ítem con descripción o precio.');
        $o['emisor']=$old?$old['emisor']:ocCfg()['emisor'];
        $db->exec('BEGIN IMMEDIATE');
        try {
            if($o['numero']==='') { if($old) throw new RuntimeException('El N° de OC no puede quedar vacío.'); $o['numero']=ocNextNumero(ocCfg()); }
            if(query('SELECT id FROM oc_orders WHERE numero=? AND id<>?',[$o['numero'],$id])->fetchColumn()) throw new RuntimeException('Ya existe una OC con el número '.$o['numero'].'. Cámbialo antes de guardar.');
            $id=ocStore($o,$id,(int)$user['id']);
            ocBumpSiguiente($o['numero']); ocUpsertSupplier($o['proveedor']);
            $db->exec('COMMIT');
        } catch(Throwable $ex) { $db->exec('ROLLBACK'); throw $ex; }
        audit($old?'Edición de OC':'Creación de OC',$o['numero']);
        if($old && $old['estado']!==$o['estado']) wfEvent('oc',['id'=>$id],$o['estado']);
        go('OC '.$o['numero'].' guardada.', post('after')==='edit' ? ['oc'=>$id,'edit'=>1] : ['oc'=>$id]);
    }
    if ($action==='oc_estado' && $canEdit) {
        $o=ocGet((int)post('id')); if(!$o) throw new RuntimeException('La orden de compra no existe.');
        $estado=post('estado'); if(!in_array($estado,OC_ESTADOS,true)) throw new RuntimeException('Estado no válido.');
        $id=$o['id']; unset($o['id'],$o['owner'],$o['created'],$o['updated']); $o['estado']=$estado;
        $prev=(string)query('SELECT estado FROM oc_orders WHERE id=?',[$id])->fetchColumn();
        ocStore($o,$id,null); audit('Cambio de estado de OC',$o['numero'].' → '.$estado);
        if($prev!==$estado) wfEvent('oc',['id'=>$id],$estado);
        go('OC '.$o['numero'].': '.$estado.'.', post('back')==='oc' ? ['oc'=>$id] : ['tab'=>'oc']);
    }
    if ($action==='oc_delete' && $isAdmin) {
        $o=ocGet((int)post('id')); if(!$o) go('',['tab'=>'oc']);
        query('DELETE FROM oc_orders WHERE id=?',[$o['id']]); audit('Eliminación de OC',$o['numero']); go('OC '.$o['numero'].' eliminada.',['tab'=>'oc']);
    }
    if ($action==='oc_cfg' && $isAdmin) {
        $cfg=ocCfg();
        $prefijo=ocText(post('prefijo'),30); $sig=(int)post('siguiente');
        if($sig<1||$sig>999999) throw new RuntimeException('El próximo correlativo debe ser un número entre 1 y 999999.');
        $iva=ocNum(post('iva')); if($iva>100) throw new RuntimeException('El IVA debe estar entre 0 y 100.');
        $emisor=[]; foreach(OC_EMISOR as $k) $emisor[$k]=post('em_'.$k);
        $n=ocNormalize(['emisor'=>$emisor,'firmas'=>array_map(fn($r,$nn,$c)=>['rol'=>$r,'nombre'=>$nn,'cargo'=>$c],ocList('fi_rol'),ocList('fi_nombre'),ocList('fi_cargo')),
            'condiciones'=>array_map(fn($k,$v)=>['k'=>$k,'v'=>$v],ocList('co_k'),ocList('co_v'))]);
        $cfg=['prefijo'=>$prefijo,'siguiente'=>$sig,'iva'=>$iva,'emisor'=>$n['emisor'],'firmas'=>$n['firmas'],'condiciones'=>$n['condiciones']]+$cfg;
        query('UPDATE oc_settings SET value=? WHERE key=?',[json_encode($cfg,JSON_UNESCAPED_UNICODE),'cfg']);
        audit('Configuración de OC'); go('Configuración guardada. Se aplica a las próximas OC.',['tab'=>'oc_cfg']);
    }
    if ($action==='oc_supplier_delete' && $isAdmin) {
        $name=(string)query('SELECT razon FROM oc_suppliers WHERE id=?',[(int)post('id')])->fetchColumn();
        query('DELETE FROM oc_suppliers WHERE id=?',[(int)post('id')]); audit('Eliminación de proveedor',$name); go('Proveedor quitado de la lista.',['tab'=>'oc_cfg']);
    }
    if ($action==='oc_import' && $isAdmin) {
        $up=$_FILES['json']??null;
        if(!is_array($up) || !is_int($up['error']??null) || $up['error']!==UPLOAD_ERR_OK || !is_string($up['tmp_name']??null)) throw new RuntimeException('Selecciona el archivo de respaldo JSON.');
        if(filesize($up['tmp_name'])>5*1048576) throw new RuntimeException('El respaldo supera 5 MB.');
        $d=json_decode((string)file_get_contents($up['tmp_name']),true);
        if(!is_array($d) || !is_array($d['regs']??null)) throw new RuntimeException('El archivo no es un respaldo válido de la herramienta de órdenes de compra.');
        $added=0; $skipped=[];
        $db->exec('BEGIN IMMEDIATE');
        try {
            foreach(array_slice($d['regs'],0,2000) as $raw){ if(!is_array($raw)) continue;
                $o=ocNormalize($raw); if($o['numero']===''){ $skipped[]='(sin número)'; continue; }
                if($o['emisor']['razon']==='') $o['emisor']=ocCfg()['emisor'];
                if(query('SELECT 1 FROM oc_orders WHERE numero=?',[$o['numero']])->fetchColumn()){ $skipped[]=$o['numero']; continue; }
                ocStore($o,0,(int)$user['id']); ocBumpSiguiente($o['numero']); ocUpsertSupplier($o['proveedor']); $added++;
            }
            foreach(array_slice(is_array($d['provs']??null)?$d['provs']:[],0,2000) as $p) if(is_array($p)) ocUpsertSupplier(ocFields($p,OC_PROV));
            if(is_array($d['cfg']??null) && (int)($d['cfg']['siguiente']??0)>(int)ocCfg()['siguiente']){ $c=ocCfg(); $c['siguiente']=min(999999,(int)$d['cfg']['siguiente']); query('UPDATE oc_settings SET value=? WHERE key=?',[json_encode($c,JSON_UNESCAPED_UNICODE),'cfg']); }
            $db->exec('COMMIT');
        } catch(Throwable $ex) { $db->exec('ROLLBACK'); throw $ex; }
        audit('Importación de OC',$added.' importadas');
        go($added.' OC importadas.'.($skipped?' Omitidas por número repetido o vacío: '.implode(', ',array_slice($skipped,0,20)).(count($skipped)>20?'…':''):''),['tab'=>'oc']);
    }
}

// ---------- Presentación (también la usa la vista de impresión) ----------
function clp(int|float $n): string { return '$ '.number_format((float)round($n),0,',','.'); }
function ocDate(string $d, string $empty='____ / ____ / ______'): string { return $d===''?$empty:substr($d,8,2).' / '.substr($d,5,2).' / '.substr($d,0,4); }
function ocQty(float $n): string { return floor($n)==$n ? number_format($n,0,',','.') : rtrim(rtrim(number_format($n,3,',','.'),'0'),','); }
/** Número para un campo <input type=number> (punto decimal, sin separador de miles). */
function ocNumIn(mixed $n): string { return is_float($n)||is_int($n) ? rtrim(rtrim(sprintf('%.3F',$n),'0'),'.') : (string)$n; }
function ocEstadoClass(string $s): string { return 'st-'.strtolower(strtr($s,['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u'])); }
/** Hoja carta de la OC, igual al formato de la herramienta original. */
function ocSheet(array $o): void {
    $em=$o['emisor']; $p=$o['proveedor']; $d=$o['entrega']; $t=ocTotals($o);
    $blank='<span class="muted">__________________</span>';
    $stamp=['Anulada'=>'ANULADA','Borrador'=>'BORRADOR','Pagada'=>'PAGADA'][$o['estado']]??'';
    $half=(int)ceil(count($o['condiciones'])/2); ?>
<article class="sheet">
<?php if($stamp):?><div class="stamp <?=ocEstadoClass($o['estado'])?>"><?=$stamp?></div><?php endif?>
<div class="s-head"><img src="<?=e(empresa()['logo'])?>" alt="<?=e(empresa()['nombre'])?>"><div class="s-title"><h2>ORDEN DE COMPRA</h2>
  <div class="meta">N° <span class="num"><?=e($o['numero']?:'—')?></span></div>
  <div class="meta">Fecha de emisión&nbsp; <span class="ink"><?=e(ocDate($o['fecha']))?></span></div>
  <div class="meta">Cotización ref.&nbsp; <span class="ink"><?=e($o['cotizacion']?:'______________')?></span></div>
  <?php if($o['ceco']!==''):?><div class="meta">Centro de costo&nbsp; <span class="ink"><?=e($o['ceco'])?></span></div><?php endif?>
</div></div>
<div class="accent"></div>
<div class="s-parties">
  <div><div class="lbl">Emitida por</div><div class="strong"><?=e($em['razon'])?></div><div>R.U.T. <?=e($em['rut'])?></div><div><?=e($em['direccion'])?></div><div class="link"><?=e($em['email'])?><?=$em['web']!==''?' · '.e($em['web']):''?></div></div>
  <div><div class="lbl">Proveedor</div><div class="strong"><?=$p['razon']!==''?e($p['razon']):$blank?></div><div>R.U.T. <?=$p['rut']!==''?e($p['rut']):$blank?></div>
    <?php if($p['giro']!==''):?><div class="muted"><?=e($p['giro'])?></div><?php endif?>
    <div><?=e(implode(', ',array_filter([$p['direccion'],$p['ciudad']],'strlen')))?></div><div class="link"><?=e(implode(' · ',array_filter([$p['email'],$p['telefono']],'strlen')))?></div></div>
  <div><div class="lbl">Entrega</div><div><span class="muted">Lugar&nbsp; </span><?=$d['lugar']!==''?e($d['lugar']):$blank?></div><div><span class="muted">Desde&nbsp; </span><?=e(ocDate($d['desde']))?></div><div><span class="muted">Hasta&nbsp; </span><?=e(ocDate($d['hasta']))?></div><div><span class="muted">Solicitante&nbsp; </span><?=e($d['solicitante'])?></div></div>
</div>
<div class="s-summary">
  <div class="obj"><div class="lbl">Objeto de la orden</div><div class="t"><?=e($o['objeto']['titulo']?:'—')?></div><div class="muted"><?=e($o['objeto']['detalle'])?></div></div>
  <div class="tot"><div class="lbl">Monto total (CLP)</div><div class="v"><?=clp($t['total'])?></div><div class="n">Neto <?=clp($t['neto'])?> + IVA <?=clp($t['iva'])?></div></div>
</div>
<div class="s-sec">Detalle</div>
<table class="items"><thead><tr><th class="c w4">#</th><th>Descripción</th><th class="c w11">Unidad</th><th class="c w8">Cant.</th><th class="r w15">Precio unit.</th><th class="r w15">Total</th></tr></thead><tbody>
<?php foreach($o['items'] as $i=>$it):?><tr><td class="c muted"><?=$i+1?></td><td><?=e($it['desc'])?></td><td class="c muted"><?=e($it['unidad'])?></td><td class="c"><?=e(ocQty($it['cant']))?></td><td class="r"><?=clp($it['precio'])?></td><td class="r"><?=clp($it['cant']*$it['precio'])?></td></tr><?php endforeach?>
<?php if(!$o['items']):?><tr><td colspan="6" class="muted c">Sin ítems</td></tr><?php endif?>
</tbody><tfoot>
<tr><td colspan="4"></td><td class="r muted">Subtotal neto</td><td class="r"><?=clp($t['neto'])?></td></tr>
<tr><td colspan="4"></td><td class="r muted">IVA <?=e(ocQty($o['iva']))?>%</td><td class="r"><?=clp($t['iva'])?></td></tr>
<tr class="total"><td colspan="4"></td><td class="r k">Total</td><td class="r v"><?=clp($t['total'])?></td></tr>
</tfoot></table>
<?php if($o['condiciones']):?><div class="s-sec">Condiciones</div><div class="s-conds"><?php foreach([array_slice($o['condiciones'],0,$half),array_slice($o['condiciones'],$half)] as $col):?><div><?php foreach($col as $c):?><div><b><?=e($c['k'])?></b>&nbsp; <?=e($c['v'])?></div><?php endforeach?></div><?php endforeach?></div><?php endif?>
<div class="s-obs"><b>Observaciones</b>&nbsp; <?=$o['obs']!==''?e($o['obs']):'<span class="muted">______________________________________________________________________________</span>'?></div>
<div class="s-sec">Autorización</div>
<div class="s-sign"><?php foreach($o['firmas'] as $f):?><div><div class="line"><b><?=e($f['nombre'])?></b></div><div class="muted"><?=e($f['cargo'])?></div><div class="rol"><?=e($f['rol'])?></div></div><?php endforeach?></div>
<div class="s-foot"><span><?=e($em['razon'])?> · R.U.T. <?=e($em['rut'])?><?=$em['web']!==''?' · '.e($em['web']):''?></span><span>Documento válido solo con firma de autorización</span></div>
</article>
<?php }

/** Descargas GET del módulo: hoja para imprimir y registro en CSV. */
function ocGetRequests(): void {
    global $user;
    if(!isset($_GET['oc_print']) && !isset($_GET['oc_csv'])) return;
    if(!$user) stop(403,'Debes iniciar sesión.');
    if(isset($_GET['oc_print'])) {
        $o=ocGet((int)$_GET['oc_print']); if(!$o) stop(404,'Orden de compra no encontrada.');
        audit('Impresión de OC',$o['numero']); ?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($o['numero'])?> · Orden de compra <?=e(empresa()['corto'])?></title><link rel="stylesheet" href="assets/style.css?v=11"></head>
<body class="print-page"><div class="print-bar no-print"><a class="btn ghost small" href="<?=e('index.php?oc='.$o['id'])?>">← Volver</a><span>Para imprimir o guardar como PDF usa <b>Ctrl + P</b> (<b>Cmd + P</b> en Mac), tamaño Carta y márgenes «Ninguno».</span></div>
<div class="sheet-wrap"><?php ocSheet($o)?></div></body></html><?php exit;
    }
    [$where,$params]=ocFilter(is_string($_GET['q']??null)?trim($_GET['q']):'', is_string($_GET['estado']??null)?$_GET['estado']:'');
    $rows=query('SELECT data FROM oc_orders'.$where.' ORDER BY id DESC',$params)->fetchAll(PDO::FETCH_COLUMN);
    session_write_close();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="registro_oc_'.strtolower(empresa()['corto']).'_'.ocToday().'.csv"');
    $out=fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,['N° OC','Fecha','Estado','Proveedor','RUT proveedor','Objeto','Lugar','Desde','Hasta','Neto','IVA','Total','Centro de costo'],';','"','');
    foreach($rows as $json){ $o=ocNormalize(json_decode($json,true)?:[]); $t=ocTotals($o);
        // Evita que Excel interprete celdas como fórmulas.
        $cells=array_map(fn($v)=>is_string($v)&&preg_match('/^[=+\-@\t\r]/',$v)?"'".$v:$v,[$o['numero'],$o['fecha'],$o['estado'],$o['proveedor']['razon'],$o['proveedor']['rut'],$o['objeto']['titulo'],$o['entrega']['lugar'],$o['entrega']['desde'],$o['entrega']['hasta'],$t['neto'],$t['iva'],$t['total'],$o['ceco']]);
        fputcsv($out,$cells,';','"',''); }
    fclose($out); exit;
}
function ocFilter(string $q, string $estado): array {
    $w=[]; $p=[];
    if($q!==''){ $w[]='(numero LIKE ? OR prov_razon LIKE ? OR obj_titulo LIKE ? OR ceco LIKE ?)'; array_push($p,"%$q%","%$q%","%$q%","%$q%"); }
    if(in_array($estado,OC_ESTADOS,true)){ $w[]='estado=?'; $p[]=$estado; }
    return [$w?' WHERE '.implode(' AND ',$w):'',$p];
}
