<?php
// Módulo de cotizaciones a clientes. Se incluye desde app.php después de oc.php (reutiliza sus utilidades de texto, fechas y montos).
$db->exec('CREATE TABLE IF NOT EXISTS cot_quotes(id INTEGER PRIMARY KEY, numero TEXT NOT NULL UNIQUE, fecha TEXT NOT NULL DEFAULT \'\', vence TEXT NOT NULL DEFAULT \'\', estado TEXT NOT NULL, ceco TEXT NOT NULL DEFAULT \'\', cli_razon TEXT NOT NULL DEFAULT \'\', obj_titulo TEXT NOT NULL DEFAULT \'\', neto INTEGER NOT NULL DEFAULT 0, iva_monto INTEGER NOT NULL DEFAULT 0, total INTEGER NOT NULL DEFAULT 0, data TEXT NOT NULL, owner INTEGER REFERENCES users(id), created TEXT NOT NULL, updated TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS cot_clients(id INTEGER PRIMARY KEY, razon TEXT NOT NULL UNIQUE COLLATE NOCASE, rut TEXT NOT NULL DEFAULT \'\', giro TEXT NOT NULL DEFAULT \'\', direccion TEXT NOT NULL DEFAULT \'\', ciudad TEXT NOT NULL DEFAULT \'\', contacto TEXT NOT NULL DEFAULT \'\', telefono TEXT NOT NULL DEFAULT \'\', email TEXT NOT NULL DEFAULT \'\');
CREATE TABLE IF NOT EXISTS cot_settings(key TEXT PRIMARY KEY, value TEXT NOT NULL);
CREATE INDEX IF NOT EXISTS cot_quotes_estado ON cot_quotes(estado);');
// Migración aditiva (cotizaciones en inglés y en otras monedas): neto, iva_monto y total quedan en su equivalente en pesos
// (para el Dashboard y los presupuestos); moneda y total_mon guardan la moneda y el total del documento.
$cotCols=query("SELECT name FROM pragma_table_info('cot_quotes')")->fetchAll(PDO::FETCH_COLUMN);
if(!in_array('moneda',$cotCols,true)) $db->exec("ALTER TABLE cot_quotes ADD COLUMN moneda TEXT NOT NULL DEFAULT 'CLP'");
if(!in_array('total_mon',$cotCols,true)) $db->exec('ALTER TABLE cot_quotes ADD COLUMN total_mon REAL NOT NULL DEFAULT 0');
if(!in_array('pais',query("SELECT name FROM pragma_table_info('cot_clients')")->fetchAll(PDO::FETCH_COLUMN),true)) $db->exec("ALTER TABLE cot_clients ADD COLUMN pais TEXT NOT NULL DEFAULT ''");

const COT_ESTADOS=['Borrador','Enviada','Aceptada','Rechazada','Anulada'];
const COT_CLI=['razon','rut','giro','direccion','ciudad','pais','contacto','telefono','email'];

function cotDefaults(): array {
    $em=empresa();
    if($em['key']!==EMPRESA_BASE) return [
        'prefijo'=>'COT-'.$em['corto'].'-'.date('Y').'-', 'siguiente'=>1, 'iva'=>19, 'validez'=>30,
        'emisor'=>$em['emisor'],
        'firmas'=>[['rol'=>'Preparada por','nombre'=>'Nombre','cargo'=>'Cargo'],['rol'=>'Aceptación cliente','nombre'=>'Nombre, firma y timbre','cargo'=>'Representante del cliente']],
        'condiciones'=>[
            ['k'=>'Validez','v'=>'La presente cotización es válida hasta la fecha de vencimiento indicada.'],
            ['k'=>'Forma de pago','v'=>'Transferencia bancaria, ______ días desde la emisión de la factura.'],
            ['k'=>'Facturación','v'=>$em['emisor']['razon'].' emite la factura al aceptar la cotización.'],
            ['k'=>'Incluye','v'=>'Equipo, personal técnico e informe según el detalle de esta cotización.'],
            ['k'=>'No incluye','v'=>'Traslados, alojamiento ni embarcación de apoyo, salvo que se indique.'],
            ['k'=>'Aceptación','v'=>'Se confirma con la firma de esta cotización o una orden de compra.'],
        ],
        'en'=>['moneda'=>'USD','iva'=>0,
            'firmas'=>[['rol'=>'Prepared by','nombre'=>'Name','cargo'=>'Position'],['rol'=>'Client acceptance','nombre'=>'Name, signature and stamp','cargo'=>'Client representative']],
            'condiciones'=>[
                ['k'=>'Validity','v'=>'This quotation is valid until the expiry date stated above.'],
                ['k'=>'Payment terms','v'=>'Bank transfer, ______ days from the invoice date.'],
                ['k'=>'Invoicing','v'=>$em['emisor']['razon'].' issues the invoice once this quotation is accepted.'],
                ['k'=>'Included','v'=>'ROV equipment, technical crew and report as detailed in this quotation.'],
                ['k'=>'Not included','v'=>'Travel, accommodation and support vessel, unless otherwise stated.'],
                ['k'=>'Acceptance','v'=>'Confirmed by signing this quotation or by issuing a purchase order.'],
            ]],
        'tc'=>['USD'=>0,'EUR'=>0],
    ];
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
        'en'=>['moneda'=>'USD','iva'=>0,
            'firmas'=>[['rol'=>'Prepared by','nombre'=>'Luis Vargas V.','cargo'=>'Academic Director'],['rol'=>'Client acceptance','nombre'=>'Name, signature and stamp','cargo'=>'Client representative']],
            'condiciones'=>[
                ['k'=>'Validity','v'=>'This quotation is valid until the expiry date stated above.'],
                ['k'=>'Payment terms','v'=>'Bank transfer, ______ days from the invoice date.'],
                ['k'=>'Invoicing','v'=>'Capacitaciones OX3 Chile SpA issues the invoice once this quotation is accepted.'],
                ['k'=>'Included','v'=>'Instructor, course material and certificate of attendance.'],
                ['k'=>'Not included','v'=>'Travel, accommodation and meals, unless otherwise stated.'],
                ['k'=>'Acceptance','v'=>'Confirmed by signing this quotation or by issuing a purchase order.'],
            ]],
        'tc'=>['USD'=>0,'EUR'=>0],
    ];
}
if (query('SELECT COUNT(*) FROM cot_settings')->fetchColumn()==0) {
    // El emisor parte igual al configurado para las OC.
    $d=cotDefaults(); $d['emisor']=ocCfg()['emisor'];
    query('INSERT OR IGNORE INTO cot_settings(key,value) VALUES(?,?)',['cfg',json_encode($d,JSON_UNESCAPED_UNICODE)]);
}
function cotCfg(): array {
    $c=json_decode((string)query('SELECT value FROM cot_settings WHERE key=?',['cfg'])->fetchColumn(),true);
    $d=cotDefaults(); if(!is_array($c)) return $d;
    $c+=$d; $c['en']=(is_array($c['en'])?$c['en']:[])+$d['en']; $c['tc']=(is_array($c['tc'])?$c['tc']:[])+$d['tc'];
    return $c;
}
/** Condiciones, firmas, moneda e IVA predeterminados para una cotización nueva en el idioma indicado. */
function cotLangDefaults(array $cfg, string $l): array {
    return $l==='en' ? ['condiciones'=>$cfg['en']['condiciones'],'firmas'=>$cfg['en']['firmas'],'moneda'=>docMoneda($cfg['en']['moneda']),'iva'=>(float)$cfg['en']['iva']]
                     : ['condiciones'=>$cfg['condiciones'],'firmas'=>$cfg['firmas'],'moneda'=>'CLP','iva'=>(float)$cfg['iva']];
}
/** Al cambiar el idioma de un documento: si las condiciones o firmas siguen siendo las predeterminadas del idioma anterior,
 * se reemplazan por las del idioma nuevo (lo que el usuario escribió a mano no se toca). */
function docSwapLang(array $c, string $from, string $to, array $dFrom, array $dTo): array {
    if($from===$to) return $c;
    $norm=fn(array $l)=>json_encode(array_map(fn($x)=>[trim($x['k']??''),trim($x['v']??'')],$l),JSON_UNESCAPED_UNICODE);
    if($norm($c['condiciones'])===$norm($dFrom['condiciones'])) $c['condiciones']=$dTo['condiciones'];
    $roles=[]; foreach($dFrom['firmas'] as $i=>$f) $roles[$f['rol']]=$dTo['firmas'][$i]??null;
    foreach($c['firmas'] as $i=>$f) if(isset($roles[$f['rol']])) {
        $n=$roles[$f['rol']]; $old=$dFrom['firmas'][array_search($f['rol'],array_column($dFrom['firmas'],'rol'),true)];
        $c['firmas'][$i]=['rol'=>$n['rol'],'nombre'=>$f['nombre']===$old['nombre']?$n['nombre']:$f['nombre'],'cargo'=>$f['cargo']===$old['cargo']?$n['cargo']:$f['cargo']];
    }
    return $c;
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
    $base=ocNormalize(['items'=>[],'condiciones'=>$r['condiciones']??[],'emisor'=>$r['emisor']??[],'obs'=>$r['obs']??'']);
    $firmas=[]; foreach(array_slice(is_array($r['firmas']??null)?$r['firmas']:[],0,2) as $f){ if(is_array($f)) $firmas[]=['rol'=>ocText($f['rol']??'',40),'nombre'=>ocText($f['nombre']??'',80),'cargo'=>ocText($f['cargo']??'',80)]; }
    $estado=in_array($r['estado']??'',COT_ESTADOS,true)?$r['estado']:'Borrador';
    $iva=ocNum($r['iva']??19); if($iva>100) $iva=19;
    $desc=ocNum($r['descuento']??0); if($desc>100) $desc=0;
    $validez=(int)min(365,ocNum($r['validez']??30));
    $fecha=ocDateIn($r['fecha']??'');
    $s=is_array($r['servicio']??null)?$r['servicio']:[];
    $moneda=docMoneda($r['moneda']??'CLP'); $tc=$moneda==='CLP'?0:round(ocNum($r['tc']??0),4);
    return [
        'numero'=>ocText($r['numero']??'',40), 'fecha'=>$fecha, 'validez'=>$validez, 'vence'=>cotVence($fecha,$validez),
        'estado'=>$estado, 'ceco'=>ocText($r['ceco']??'',120), 'iva'=>$iva, 'descuento'=>$desc,
        'idioma'=>docLang($r['idioma']??'es'), 'moneda'=>$moneda, 'tc'=>$tc,
        'cliente'=>ocFields($r['cliente']??[],COT_CLI),
        'servicio'=>['modalidad'=>ocText($s['modalidad']??'',60),'lugar'=>ocText($s['lugar']??''),'desde'=>ocDateIn($s['desde']??''),'hasta'=>ocDateIn($s['hasta']??''),'participantes'=>ocText($s['participantes']??'',80)],
        'objeto'=>['titulo'=>ocText($r['objeto']['titulo']??'',160),'detalle'=>ocText($r['objeto']['detalle']??'',300)],
        'items'=>docItems($r['items']??[],$moneda), 'condiciones'=>$base['condiciones'], 'firmas'=>$firmas, 'obs'=>$base['obs'], 'emisor'=>$base['emisor'],
    ];
}
/** Totales en la moneda del documento; 'clp' es el total en pesos (con el tipo de cambio; 0 si falta). */
function cotTotals(array $c): array {
    $m=$c['moneda']??'CLP';
    $sub=docRound(array_sum(array_map(fn($i)=>$i['cant']*$i['precio'],$c['items'])),$m);
    $desc=docRound($sub*$c['descuento']/100,$m);
    $neto=docRound($sub-$desc,$m); $iva=docRound($neto*$c['iva']/100,$m); $total=docRound($neto+$iva,$m);
    $toClp=fn($v)=>$m==='CLP'?(int)$v:(int)round($v*($c['tc']??0));
    return ['subtotal'=>$sub,'descuento'=>$desc,'neto'=>$neto,'iva'=>$iva,'total'=>$total,'clp'=>$toClp($total),'neto_clp'=>$toClp($neto),'iva_clp'=>$toClp($iva)];
}
/** Lee el formulario del editor y lo deja con la forma de cotNormalize(). */
function cotFromPost(): array {
    $r=['numero'=>post('numero'),'fecha'=>post('fecha'),'validez'=>post('validez'),'estado'=>post('estado'),'ceco'=>post('ceco'),'iva'=>post('iva'),'descuento'=>post('descuento'),'obs'=>post('obs'),
        'idioma'=>post('idioma'),'moneda'=>post('moneda'),'tc'=>post('tc'),
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
    query('INSERT INTO cot_clients(razon,rut,giro,direccion,ciudad,pais,contacto,telefono,email) VALUES(?,?,?,?,?,?,?,?,?) ON CONFLICT(razon) DO UPDATE SET rut=excluded.rut,giro=excluded.giro,direccion=excluded.direccion,ciudad=excluded.ciudad,pais=excluded.pais,contacto=excluded.contacto,telefono=excluded.telefono,email=excluded.email',
        [$p['razon'],$p['rut'],$p['giro'],$p['direccion'],$p['ciudad'],$p['pais']??'',$p['contacto'],$p['telefono'],$p['email']]);
}
/** Inserta o actualiza una cotización ya normalizada. Devuelve su id. Llamar dentro de una transacción. */
function cotStore(array $c, int $id, ?int $owner): int {
    $t=cotTotals($c); $now=gmdate('c');
    $cols=[$c['numero'],$c['fecha'],$c['vence'],$c['estado'],$c['ceco'],$c['cliente']['razon'],$c['objeto']['titulo'],$t['neto_clp'],$t['iva_clp'],$t['clp'],$c['moneda'],$t['total'],json_encode($c,JSON_UNESCAPED_UNICODE)];
    if($id>0){ query('UPDATE cot_quotes SET numero=?,fecha=?,vence=?,estado=?,ceco=?,cli_razon=?,obj_titulo=?,neto=?,iva_monto=?,total=?,moneda=?,total_mon=?,data=?,updated=? WHERE id=?',[...$cols,$now,$id]); return $id; }
    query('INSERT INTO cot_quotes(numero,fecha,vence,estado,ceco,cli_razon,obj_titulo,neto,iva_monto,total,moneda,total_mon,data,owner,created,updated) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[...$cols,$owner,$now,$now]);
    global $db; return (int)$db->lastInsertId();
}

/** Acciones POST del módulo (dentro del try de app.php, con usuario autenticado). */
function cotPost(string $action): void {
    global $db, $user, $canEdit, $isAdmin;
    if ($action==='cot_save' && $canEdit) {
        $id=(int)post('id'); $old=$id>0?cotGet($id):null;
        if($id>0 && !$old) throw new RuntimeException('La cotización no existe.');
        $c=cotNormalize(cotFromPost());
        $cfgL=cotCfg(); $prev=docLang(post('idioma_prev'));
        $c=docSwapLang($c,$prev,$c['idioma'],cotLangDefaults($cfgL,$prev),cotLangDefaults($cfgL,$c['idioma']));
        if($c['moneda']!=='CLP' && $c['tc']<=0 && (float)($cfgL['tc'][$c['moneda']]??0)>0) $c['tc']=(float)$cfgL['tc'][$c['moneda']];
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
        if($old && $old['estado']!==$c['estado']) wfEvent('cot',['id'=>$id],$c['estado']);
        go('Cotización '.$c['numero'].' guardada.', post('after')==='edit' ? ['cot'=>$id,'edit'=>1] : ['cot'=>$id]);
    }
    if ($action==='cot_estado' && $canEdit) {
        $c=cotGet((int)post('id')); if(!$c) throw new RuntimeException('La cotización no existe.');
        $estado=post('estado'); if(!in_array($estado,COT_ESTADOS,true)) throw new RuntimeException('Estado no válido.');
        $id=$c['id']; unset($c['id'],$c['owner'],$c['created'],$c['updated']); $c['estado']=$estado;
        $prev=(string)query('SELECT estado FROM cot_quotes WHERE id=?',[$id])->fetchColumn();
        cotStore($c,$id,null); audit('Cambio de estado de cotización',$c['numero'].' → '.$estado);
        if($prev!==$estado) wfEvent('cot',['id'=>$id],$estado);
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
        $ivaEn=ocNum(post('en_iva')); if($ivaEn>100) throw new RuntimeException('El IVA de las cotizaciones en inglés debe estar entre 0 y 100.');
        $en=cotNormalize(['firmas'=>array_map(fn($r,$nn,$c)=>['rol'=>$r,'nombre'=>$nn,'cargo'=>$c],ocList('fe_rol'),ocList('fe_nombre'),ocList('fe_cargo')),
            'condiciones'=>array_map(fn($k,$v)=>['k'=>$k,'v'=>$v],ocList('ce_k'),ocList('ce_v'))]);
        $tc=[]; foreach(array_keys(DOC_MONEDAS) as $m) if($m!=='CLP') $tc[$m]=round(ocNum(post('tc_'.$m)),4);
        cotSaveCfg(['prefijo'=>$prefijo,'siguiente'=>$sig,'iva'=>$iva,'validez'=>$validez,'emisor'=>$n['emisor'],'firmas'=>$n['firmas'],'condiciones'=>$n['condiciones'],
            'en'=>['moneda'=>docMoneda(post('en_moneda')),'iva'=>$ivaEn,'firmas'=>$en['firmas'],'condiciones'=>$en['condiciones']],'tc'=>$tc]+$cfg);
        audit('Configuración de cotizaciones'); go('Configuración guardada. Se aplica a las próximas cotizaciones.',['tab'=>'cot_cfg']);
    }
    if ($action==='cot_client_delete' && $isAdmin) {
        $name=(string)query('SELECT razon FROM cot_clients WHERE id=?',[(int)post('id')])->fetchColumn();
        query('DELETE FROM cot_clients WHERE id=?',[(int)post('id')]); audit('Eliminación de cliente',$name); go('Cliente quitado de la lista.',['tab'=>'cot_cfg']);
    }
}

// ---------- Presentación (también la usa la vista de impresión) ----------
/** Hoja carta de la cotización, con el mismo diseño que la OC, en el idioma y la moneda del documento. */
function cotSheet(array $c): void {
    $em=$c['emisor']; $p=$c['cliente']; $s=$c['servicio']; $t=cotTotals($c); $l=$c['idioma']; $m=$c['moneda'];
    $T=fn(string $es, string $en)=>tr($l,$es,$en); $M=fn($v)=>money($v,$m,$l); $D=fn(string $d)=>docDate($d,$l);
    $blank='<span class="muted">__________________</span>';
    $stamp=['Anulada'=>$T('ANULADA','VOID'),'Borrador'=>$T('BORRADOR','DRAFT'),'Rechazada'=>$T('RECHAZADA','REJECTED'),'Aceptada'=>$T('ACEPTADA','ACCEPTED')][$c['estado']]??'';
    $rut=$T('R.U.T.','Tax ID'); $rov=!empresa()['cursos'];
    $half=(int)ceil(count($c['condiciones'])/2); ?>
<article class="sheet" lang="<?=e($l)?>">
<?php if($stamp):?><div class="stamp <?=ocEstadoClass($c['estado'])?>"><?=$stamp?></div><?php endif?>
<div class="s-head"><img src="<?=e(empresa()['logo'])?>" alt="<?=e(empresa()['nombre'])?>"><div class="s-title"><h2><?=$T('COTIZACIÓN','QUOTATION')?></h2>
  <div class="meta"><?=$T('N°','No.')?> <span class="num"><?=e($c['numero']?:'—')?></span></div>
  <div class="meta"><?=$T('Fecha de emisión','Issue date')?>&nbsp; <span class="ink"><?=e($D($c['fecha']))?></span></div>
  <div class="meta"><?=$T('Válida hasta','Valid until')?>&nbsp; <span class="ink"><?=e($D($c['vence']))?></span><?=$c['validez']>0?' <span class="muted">('.(int)$c['validez'].' '.$T('días','days').')</span>':''?></div>
  <?php if($c['ceco']!==''):?><div class="meta"><?=$T('Proyecto','Project')?>&nbsp; <span class="ink"><?=e($c['ceco'])?></span></div><?php endif?>
</div></div>
<div class="accent"></div>
<div class="s-parties">
  <div><div class="lbl"><?=$T('Emitida por','Issued by')?></div><div class="strong"><?=e($em['razon'])?></div><?php if($em['rut']!==''||$l!=='en'):?><div><?=$T('R.U.T.','Tax ID (RUT)')?> <?=e($em['rut'])?></div><?php endif?><div><?=e($em['direccion'])?><?=$l==='en'&&$em['direccion']!==''&&!preg_match('/chile/i',$em['direccion'])?', Chile':''?></div><div class="link"><?=e($em['email'])?><?=$em['web']!==''?' · '.e($em['web']):''?></div></div>
  <div><div class="lbl"><?=$T('Cliente','Client')?></div><div class="strong"><?=$p['razon']!==''?e($p['razon']):$blank?></div><div><?=$rut?> <?=$p['rut']!==''?e($p['rut']):$blank?></div>
    <?php if($p['giro']!==''):?><div class="muted"><?=e($p['giro'])?></div><?php endif?>
    <div><?=e(implode(', ',array_filter([$p['direccion'],$p['ciudad'],$p['pais']??''],'strlen')))?></div>
    <?php if($p['contacto']!==''):?><div><span class="muted"><?=$T('At.','Attn.')?>&nbsp; </span><?=e($p['contacto'])?></div><?php endif?>
    <div class="link"><?=e(implode(' · ',array_filter([$p['email'],$p['telefono']],'strlen')))?></div></div>
  <div><div class="lbl"><?=$T('Ejecución','Execution')?></div><div><span class="muted"><?=$rov?$T('Trabajo','Type of work'):$T('Modalidad','Mode')?>&nbsp; </span><?=$s['modalidad']!==''?e($s['modalidad']):$blank?></div><div><span class="muted"><?=$T('Lugar','Location')?>&nbsp; </span><?=$s['lugar']!==''?e($s['lugar']):$blank?></div><div><span class="muted"><?=$T('Desde','From')?>&nbsp; </span><?=e($D($s['desde']))?></div><div><span class="muted"><?=$T('Hasta','To')?>&nbsp; </span><?=e($D($s['hasta']))?></div><?php if($s['participantes']!==''):?><div><span class="muted"><?=$rov?$T('Nave / instalación','Vessel / facility'):$T('Participantes','Participants')?>&nbsp; </span><?=e($s['participantes'])?></div><?php endif?></div>
</div>
<div class="s-summary">
  <div class="obj"><div class="lbl"><?=$T('Objeto de la cotización','Scope of this quotation')?></div><div class="t"><?=e($c['objeto']['titulo']?:'—')?></div><div class="muted"><?=e($c['objeto']['detalle'])?></div></div>
  <div class="tot"><div class="lbl"><?=$T('Monto total','Total amount')?> (<?=e($m)?>)</div><div class="v"><?=$M($t['total'])?></div><div class="n"><?=$T('Neto','Net')?> <?=$M($t['neto'])?><?=$c['iva']>0?' + '.$T('IVA','VAT').' '.$M($t['iva']):' · '.$T('exento de IVA','VAT exempt')?></div></div>
</div>
<div class="s-sec"><?=$T('Detalle','Details')?></div>
<table class="items"><thead><tr><th class="c w4">#</th><th><?=$T('Descripción','Description')?></th><th class="c w11"><?=$T('Unidad','Unit')?></th><th class="c w8"><?=$T('Cant.','Qty')?></th><th class="r w15"><?=$T('Precio unit.','Unit price')?></th><th class="r w15">Total</th></tr></thead><tbody>
<?php foreach($c['items'] as $i=>$it):?><tr><td class="c muted"><?=$i+1?></td><td><?=e($it['desc'])?></td><td class="c muted"><?=e(docUnit($it['unidad'],$l))?></td><td class="c"><?=e(docQty($it['cant'],$l))?></td><td class="r"><?=$M($it['precio'])?></td><td class="r"><?=$M(docRound($it['cant']*$it['precio'],$m))?></td></tr><?php endforeach?>
<?php if(!$c['items']):?><tr><td colspan="6" class="muted c"><?=$T('Sin ítems','No items')?></td></tr><?php endif?>
</tbody><tfoot>
<?php if($t['descuento']>0):?><tr><td colspan="4"></td><td class="r muted">Subtotal</td><td class="r"><?=$M($t['subtotal'])?></td></tr>
<tr><td colspan="4"></td><td class="r muted"><?=$T('Descuento','Discount')?> <?=e(docQty($c['descuento'],$l))?>%</td><td class="r">− <?=$M($t['descuento'])?></td></tr><?php endif?>
<tr><td colspan="4"></td><td class="r muted"><?=$T('Subtotal neto','Net subtotal')?></td><td class="r"><?=$M($t['neto'])?></td></tr>
<tr><td colspan="4"></td><td class="r muted"><?=$c['iva']>0?$T('IVA','VAT').' '.e(docQty($c['iva'],$l)).'%':$T('IVA (exento)','VAT (exempt)')?></td><td class="r"><?=$M($t['iva'])?></td></tr>
<tr class="total"><td colspan="4"></td><td class="r k">Total <?=e($m)?></td><td class="r v"><?=$M($t['total'])?></td></tr>
</tfoot></table>
<?php if($c['condiciones']):?><div class="s-sec"><?=$T('Condiciones comerciales','Commercial terms')?></div><div class="s-conds"><?php foreach([array_slice($c['condiciones'],0,$half),array_slice($c['condiciones'],$half)] as $col):?><div><?php foreach($col as $cc):?><div><b><?=e($cc['k'])?></b>&nbsp; <?=e($cc['v'])?></div><?php endforeach?></div><?php endforeach?></div><?php endif?>
<?php if($c['obs']!==''):?><div class="s-obs"><b><?=$T('Observaciones','Remarks')?></b>&nbsp; <?=e($c['obs'])?></div><?php endif?>
<div class="s-sec"><?=$T('Aceptación','Acceptance')?></div>
<div class="s-sign cot-sign"><?php foreach($c['firmas'] as $f):?><div><div class="line"><b><?=e($f['nombre'])?></b></div><div class="muted"><?=e($f['cargo'])?></div><div class="rol"><?=e($f['rol'])?></div></div><?php endforeach?></div>
<div class="s-foot"><span><?=e($em['razon'])?><?=$em['rut']!==''?' · '.$T('R.U.T.','Tax ID').' '.e($em['rut']):''?><?=$em['web']!==''?' · '.e($em['web']):''?></span><span><?=$T('Valores en '.mb_strtolower(DOC_MONEDAS[$m][0]),'Amounts in '.DOC_MONEDAS[$m][1])?> · <?=$T('Cotización sujeta a disponibilidad','Quotation subject to availability')?></span></div>
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
<html lang="<?=e($c['idioma'])?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($c['numero'])?> · <?=tr($c['idioma'],'Cotización','Quotation')?> <?=e(empresa()['corto'])?></title><link rel="stylesheet" href="assets/style.css?v=17"></head>
<body class="print-page"><div class="print-bar no-print"><a class="btn ghost small" href="<?=e('index.php?cot='.$c['id'])?>">← Volver</a><span>Para imprimir o guardar como PDF usa <b>Ctrl + P</b> (<b>Cmd + P</b> en Mac), tamaño Carta y márgenes «Ninguno».</span></div>
<div class="sheet-wrap"><?php cotSheet($c)?></div></body></html><?php exit;
    }
    [$where,$params]=cotFilter(is_string($_GET['q']??null)?trim($_GET['q']):'', is_string($_GET['estado']??null)?$_GET['estado']:'');
    $rows=query('SELECT data FROM cot_quotes'.$where.' ORDER BY id DESC',$params)->fetchAll(PDO::FETCH_COLUMN);
    session_write_close();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="registro_cotizaciones_'.strtolower(empresa()['corto']).'_'.ocToday().'.csv"');
    $out=fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,['N° cotización','Fecha','Válida hasta','Estado','Cliente','RUT cliente','Contacto','Objeto',txEmp('Modalidad','Tipo de trabajo'),'Desde','Hasta','Descuento %','Neto','IVA','Total','Proyecto','Idioma','Moneda','Tipo de cambio','Total en CLP'],';','"','');
    foreach($rows as $json){ $c=cotNormalize(json_decode($json,true)?:[]); $t=cotTotals($c);
        $estado=cotVencida($c)?'Vencida':$c['estado'];
        // Evita que Excel interprete celdas como fórmulas.
        $cells=array_map(fn($v)=>is_string($v)&&preg_match('/^[=+\-@\t\r]/',$v)?"'".$v:(is_float($v)?str_replace('.',',',(string)$v):$v),[$c['numero'],$c['fecha'],$c['vence'],$estado,$c['cliente']['razon'],$c['cliente']['rut'],$c['cliente']['contacto'],$c['objeto']['titulo'],$c['servicio']['modalidad'],$c['servicio']['desde'],$c['servicio']['hasta'],ocQty($c['descuento']),$t['neto'],$t['iva'],$t['total'],$c['ceco'],strtoupper($c['idioma']),$c['moneda'],$c['moneda']==='CLP'?'':ocNumIn($c['tc']),$c['moneda']==='CLP'||$c['tc']>0?$t['clp']:'']);
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
