<?php
// Módulo de facturas proforma (acuerdos internacionales), en español o inglés y en CLP, USD o EUR.
// Se incluye desde app.php después de cot.php: reutiliza los clientes de cotizaciones (cot_clients) y las utilidades oc*/doc*.
// Las tablas se crean en todas las empresas (para que en MRM no se lean las de OX3 a través de la base principal adjunta),
// pero el menú y las acciones solo aparecen donde empresas()[...]['proformas'] es true (ver pfOn()).
$db->exec('CREATE TABLE IF NOT EXISTS pf_invoices(id INTEGER PRIMARY KEY, numero TEXT NOT NULL UNIQUE, fecha TEXT NOT NULL DEFAULT \'\', vence TEXT NOT NULL DEFAULT \'\', estado TEXT NOT NULL, ceco TEXT NOT NULL DEFAULT \'\', cli_razon TEXT NOT NULL DEFAULT \'\', obj_titulo TEXT NOT NULL DEFAULT \'\', idioma TEXT NOT NULL DEFAULT \'es\', moneda TEXT NOT NULL DEFAULT \'CLP\', total REAL NOT NULL DEFAULT 0, cot_id INTEGER, data TEXT NOT NULL, owner INTEGER REFERENCES users(id), created TEXT NOT NULL, updated TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS pf_settings(key TEXT PRIMARY KEY, value TEXT NOT NULL);
CREATE INDEX IF NOT EXISTS pf_invoices_estado ON pf_invoices(estado);
CREATE INDEX IF NOT EXISTS pf_invoices_cot ON pf_invoices(cot_id);');

const PF_ESTADOS=['Borrador','Emitida','Pagada','Anulada'];
const PF_BANCO=['beneficiario','banco','direccion','cuenta','swift','aba','intermediario'];
/** Etiquetas de los datos bancarios: [español, inglés]. */
const PF_BANCO_LBL=['beneficiario'=>['Beneficiario','Beneficiary'],'banco'=>['Banco','Bank'],'direccion'=>['Dirección del banco','Bank address'],'cuenta'=>['N° de cuenta','Account number'],
    'swift'=>['SWIFT / BIC','SWIFT / BIC'],'aba'=>['ABA / IBAN / Routing','ABA / IBAN / Routing'],'intermediario'=>['Banco intermediario','Intermediary bank']];
const PF_ENVIO=['incoterm','incoterm_lugar','origen','carga','destino','transporte','fecha'];

/** El módulo se muestra en la empresa en uso. */
function pfOn(): bool { return !empty(empresa()['proformas']); }

function pfDefaults(): array {
    $em=empresa();
    return [
        'prefijo'=>'PF-'.$em['corto'].'-'.date('Y').'-', 'siguiente'=>1, 'validez'=>30, 'iva'=>0, 'anticipo'=>50, 'moneda'=>'USD', 'idioma'=>'en',
        'emisor'=>$em['emisor'] + ['telefono'=>''],
        'banco'=>array_fill_keys(PF_BANCO,'') + ['beneficiario'=>$em['emisor']['razon']],
        'es'=>[
            'pago'=>'50% de anticipo al confirmar la orden; 50% restante contra entrega del servicio e informe final. Transferencia bancaria; los gastos bancarios son de cargo del ordenante.',
            'declaracion'=>'Declaramos que la información de esta factura proforma es verdadera y correcta. Esta factura proforma no es un documento tributario; la factura definitiva se emite según los términos acordados.',
            'firmas'=>[['rol'=>'Firma autorizada','nombre'=>'Nombre','cargo'=>'Cargo'],['rol'=>'Aceptación del comprador','nombre'=>'Nombre, firma y timbre','cargo'=>'Representante del comprador']],
            'condiciones'=>[
                ['k'=>'Validez','v'=>'Esta proforma es válida hasta la fecha de vencimiento indicada.'],
                ['k'=>'Alcance','v'=>'Servicio, equipo y personal técnico según el detalle de esta proforma.'],
                ['k'=>'Impuestos','v'=>'Los impuestos, derechos y tasas del país de destino son de cargo del comprador.'],
                ['k'=>'Jurisdicción','v'=>'Este acuerdo se rige por las leyes de la República de Chile.'],
            ]],
        'en'=>[
            'pago'=>'50% advance payment upon order confirmation; 50% balance upon completion of the service and delivery of the final report. Bank transfer; bank charges are borne by the payer.',
            'declaracion'=>'We hereby certify that the information in this proforma invoice is true and correct. This proforma invoice is not a tax invoice; the final invoice will be issued according to the agreed terms.',
            'firmas'=>[['rol'=>'Authorized signature','nombre'=>'Name','cargo'=>'Position'],['rol'=>'Buyer acceptance','nombre'=>'Name, signature and stamp','cargo'=>'Buyer representative']],
            'condiciones'=>[
                ['k'=>'Validity','v'=>'This proforma invoice is valid until the expiry date stated above.'],
                ['k'=>'Scope','v'=>'Service, equipment and technical crew as detailed in this proforma invoice.'],
                ['k'=>'Taxes','v'=>'Taxes, duties and fees of the country of destination are borne by the buyer.'],
                ['k'=>'Governing law','v'=>'This agreement is governed by the laws of the Republic of Chile.'],
            ]],
    ];
}
if (query('SELECT COUNT(*) FROM pf_settings')->fetchColumn()==0) {
    // El emisor parte igual al de las cotizaciones de esta empresa.
    $d=pfDefaults(); $d['emisor']=cotCfg()['emisor']+['telefono'=>''];
    query('INSERT OR IGNORE INTO pf_settings(key,value) VALUES(?,?)',['cfg',json_encode($d,JSON_UNESCAPED_UNICODE)]);
}
function pfCfg(): array {
    $c=json_decode((string)query('SELECT value FROM pf_settings WHERE key=?',['cfg'])->fetchColumn(),true);
    $d=pfDefaults(); if(!is_array($c)) return $d;
    $c+=$d; foreach(['es','en'] as $l) $c[$l]=(is_array($c[$l])?$c[$l]:[])+$d[$l];
    return $c;
}
function pfSaveCfg(array $c): void { query('UPDATE pf_settings SET value=? WHERE key=?',[json_encode($c,JSON_UNESCAPED_UNICODE),'cfg']); }
/** Textos predeterminados de un idioma, con la forma que espera docSwapLang(). */
function pfLangDefaults(array $cfg, string $l): array { $t=$cfg[$l==='en'?'en':'es']; return ['condiciones'=>$t['condiciones'],'firmas'=>$t['firmas'],'pago'=>$t['pago'],'declaracion'=>$t['declaracion']]; }
function pfVencida(array $p): bool { return $p['estado']==='Emitida' && $p['vence']!=='' && $p['vence']<ocToday(); }

/** Limpia una proforma: {numero, fecha, validez, idioma, moneda, cliente{}, envio{}, objeto{}, items[], flete, seguro, anticipo, banco{}, ...}. */
function pfNormalize(array $r): array {
    $base=ocNormalize(['items'=>[],'condiciones'=>$r['condiciones']??[],'obs'=>$r['obs']??'']);
    $firmas=[]; foreach(array_slice(is_array($r['firmas']??null)?$r['firmas']:[],0,2) as $f){ if(is_array($f)) $firmas[]=['rol'=>ocText($f['rol']??'',40),'nombre'=>ocText($f['nombre']??'',80),'cargo'=>ocText($f['cargo']??'',80)]; }
    $moneda=docMoneda($r['moneda']??'USD');
    $pct=fn($v,$def=0)=>($n=ocNum($v??$def))>100?$def:$n;
    $fecha=ocDateIn($r['fecha']??''); $validez=(int)min(365,ocNum($r['validez']??30));
    $e=is_array($r['envio']??null)?$r['envio']:[];
    $inc=strtoupper(ocText($e['incoterm']??'',3));
    $ref=is_array($r['ref']??null)?$r['ref']:[];
    return [
        'numero'=>ocText($r['numero']??'',40), 'fecha'=>$fecha, 'validez'=>$validez, 'vence'=>cotVence($fecha,$validez),
        'estado'=>in_array($r['estado']??'',PF_ESTADOS,true)?$r['estado']:'Borrador', 'ceco'=>ocText($r['ceco']??'',120),
        'idioma'=>docLang($r['idioma']??'en'), 'moneda'=>$moneda,
        'ref'=>['cotizacion'=>ocText($ref['cotizacion']??'',40),'oc_cliente'=>ocText($ref['oc_cliente']??'',60)],
        'cot_id'=>max(0,(int)($r['cot_id']??0)),
        'cliente'=>ocFields($r['cliente']??[],COT_CLI),
        'envio'=>['incoterm'=>in_array($inc,DOC_INCOTERMS,true)?$inc:'','incoterm_lugar'=>ocText($e['incoterm_lugar']??'',120),'origen'=>ocText($e['origen']??'',80),
            'carga'=>ocText($e['carga']??'',120),'destino'=>ocText($e['destino']??'',120),'transporte'=>ocText($e['transporte']??'',60),'fecha'=>ocDateIn($e['fecha']??'')],
        'objeto'=>['titulo'=>ocText($r['objeto']['titulo']??'',160),'detalle'=>ocText($r['objeto']['detalle']??'',300)],
        'items'=>docItems($r['items']??[],$moneda),
        'descuento'=>$pct($r['descuento']??0), 'iva'=>$pct($r['iva']??0), 'anticipo'=>$pct($r['anticipo']??0),
        'flete'=>docRound(ocNum($r['flete']??0),$moneda), 'seguro'=>docRound(ocNum($r['seguro']??0),$moneda),
        'pago'=>ocText($r['pago']??'',600), 'declaracion'=>ocText($r['declaracion']??'',600),
        'banco'=>ocFields($r['banco']??[],PF_BANCO),
        'condiciones'=>$base['condiciones'], 'firmas'=>$firmas, 'obs'=>$base['obs'],
        'emisor'=>ocFields($r['emisor']??[],[...OC_EMISOR,'telefono']),
    ];
}
function pfTotals(array $p): array {
    $m=$p['moneda'];
    $sub=docRound(array_sum(array_map(fn($i)=>$i['cant']*$i['precio'],$p['items'])),$m);
    $desc=docRound($sub*$p['descuento']/100,$m); $neto=docRound($sub-$desc,$m);
    $base=docRound($neto+$p['flete']+$p['seguro'],$m); $iva=docRound($base*$p['iva']/100,$m); $total=docRound($base+$iva,$m);
    $ant=docRound($total*$p['anticipo']/100,$m);
    return ['subtotal'=>$sub,'descuento'=>$desc,'neto'=>$neto,'base'=>$base,'iva'=>$iva,'total'=>$total,'anticipo'=>$ant,'saldo'=>docRound($total-$ant,$m)];
}
function pfFromPost(): array {
    $r=['numero'=>post('numero'),'fecha'=>post('fecha'),'validez'=>post('validez'),'estado'=>post('estado'),'ceco'=>post('ceco'),'idioma'=>post('idioma'),'moneda'=>post('moneda'),
        'ref'=>['cotizacion'=>post('ref_cotizacion'),'oc_cliente'=>post('ref_oc')],'cot_id'=>post('cot_id'),
        'descuento'=>post('descuento'),'iva'=>post('iva'),'anticipo'=>post('anticipo'),'flete'=>post('flete'),'seguro'=>post('seguro'),
        'pago'=>post('pago'),'declaracion'=>post('declaracion'),'obs'=>post('obs'),
        'objeto'=>['titulo'=>post('obj_titulo'),'detalle'=>post('obj_detalle')],'cliente'=>[],'envio'=>[],'banco'=>[],'items'=>[],'condiciones'=>[],'firmas'=>[]];
    foreach(COT_CLI as $k) $r['cliente'][$k]=post('cli_'.$k);
    foreach(PF_ENVIO as $k) $r['envio'][$k]=post('en_'.$k);
    foreach(PF_BANCO as $k) $r['banco'][$k]=post('bk_'.$k);
    [$d,$u,$c,$pr]=[ocList('it_desc'),ocList('it_unidad'),ocList('it_cant'),ocList('it_precio')];
    foreach($d as $i=>$desc) $r['items'][]=['desc'=>$desc,'unidad'=>$u[$i]??'','cant'=>$c[$i]??'0','precio'=>$pr[$i]??'0'];
    [$k,$v]=[ocList('co_k'),ocList('co_v')]; foreach($k as $i=>$kk) $r['condiciones'][]=['k'=>$kk,'v'=>$v[$i]??''];
    [$fr,$fn,$fc]=[ocList('fi_rol'),ocList('fi_nombre'),ocList('fi_cargo')]; foreach($fr as $i=>$rol) $r['firmas'][]=['rol'=>$rol,'nombre'=>$fn[$i]??'','cargo'=>$fc[$i]??''];
    $sid=(int)post('cli_saved');
    if($sid>0 && ($s=query('SELECT * FROM cot_clients WHERE id=?',[$sid])->fetch(PDO::FETCH_ASSOC))) foreach(COT_CLI as $kk) $r['cliente'][$kk]=(string)($s[$kk]??'');
    return $r;
}
function pfGet(int $id): array|false {
    $row=$id>0?query('SELECT * FROM pf_invoices WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC):false;
    if(!$row) return false;
    return pfNormalize(json_decode($row['data'],true)?:[])+['id'=>(int)$row['id'],'owner'=>$row['owner'],'created'=>$row['created'],'updated'=>$row['updated']];
}
function pfNextNumero(array $cfg): string {
    $n=max(1,(int)$cfg['siguiente']);
    while(query('SELECT 1 FROM pf_invoices WHERE numero=?',[$cfg['prefijo'].str_pad((string)$n,4,'0',STR_PAD_LEFT)])->fetchColumn()) $n++;
    return $cfg['prefijo'].str_pad((string)$n,4,'0',STR_PAD_LEFT);
}
function pfBumpSiguiente(string $numero): void {
    $cfg=pfCfg();
    if($cfg['prefijo']!=='' && str_starts_with($numero,$cfg['prefijo']) && ctype_digit($rest=substr($numero,strlen($cfg['prefijo']))) && (int)$rest>=(int)$cfg['siguiente']) { $cfg['siguiente']=(int)$rest+1; pfSaveCfg($cfg); }
}
/** Proforma nueva (sin guardar) con los valores predeterminados del idioma indicado. */
function pfBlank(string $lang): array {
    $cfg=pfCfg(); $t=pfLangDefaults($cfg,$lang);
    return pfNormalize(['fecha'=>ocToday(),'validez'=>$cfg['validez'],'estado'=>'Borrador','idioma'=>$lang,'moneda'=>$cfg['moneda'],'iva'=>$cfg['iva'],'anticipo'=>$cfg['anticipo'],
        'emisor'=>$cfg['emisor'],'banco'=>$cfg['banco'],'pago'=>$t['pago'],'declaracion'=>$t['declaracion'],'condiciones'=>$t['condiciones'],'firmas'=>$t['firmas'],
        'envio'=>['origen'=>'Chile']]);
}
/** Inserta o actualiza una proforma normalizada. Devuelve su id. Llamar dentro de una transacción. */
function pfStore(array $p, int $id, ?int $owner): int {
    $t=pfTotals($p); $now=gmdate('c');
    $cols=[$p['numero'],$p['fecha'],$p['vence'],$p['estado'],$p['ceco'],$p['cliente']['razon'],$p['objeto']['titulo'],$p['idioma'],$p['moneda'],$t['total'],$p['cot_id']?:null,json_encode($p,JSON_UNESCAPED_UNICODE)];
    if($id>0){ query('UPDATE pf_invoices SET numero=?,fecha=?,vence=?,estado=?,ceco=?,cli_razon=?,obj_titulo=?,idioma=?,moneda=?,total=?,cot_id=?,data=?,updated=? WHERE id=?',[...$cols,$now,$id]); return $id; }
    query('INSERT INTO pf_invoices(numero,fecha,vence,estado,ceco,cli_razon,obj_titulo,idioma,moneda,total,cot_id,data,owner,created,updated) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[...$cols,$owner,$now,$now]);
    global $db; return (int)$db->lastInsertId();
}

/** Acciones POST del módulo (dentro del try de app.php, con usuario autenticado y pfOn()). */
function pfPost(string $action): void {
    global $db, $user, $canEdit, $isAdmin;
    if ($action==='pf_save' && $canEdit) {
        $id=(int)post('id'); $old=$id>0?pfGet($id):null;
        if($id>0 && !$old) throw new RuntimeException('La proforma no existe.');
        $p=pfNormalize(pfFromPost());
        $cfg=pfCfg(); $prev=docLang(post('idioma_prev'));
        if($prev!==$p['idioma']) {
            $dF=pfLangDefaults($cfg,$prev); $dT=pfLangDefaults($cfg,$p['idioma']);
            $p=docSwapLang($p,$prev,$p['idioma'],$dF,$dT);
            foreach(['pago','declaracion'] as $k) if($p[$k]===$dF[$k]) $p[$k]=$dT[$k];
        }
        if(!$p['items']) throw new RuntimeException('Agrega al menos un ítem con descripción o precio.');
        $p['emisor']=$old?$old['emisor']:$cfg['emisor'];
        $db->exec('BEGIN IMMEDIATE');
        try {
            if($p['numero']==='') { if($old) throw new RuntimeException('El N° de proforma no puede quedar vacío.'); $p['numero']=pfNextNumero(pfCfg()); }
            if(query('SELECT id FROM pf_invoices WHERE numero=? AND id<>?',[$p['numero'],$id])->fetchColumn()) throw new RuntimeException('Ya existe una proforma con el número '.$p['numero'].'. Cámbialo antes de guardar.');
            $id=pfStore($p,$id,(int)$user['id']);
            pfBumpSiguiente($p['numero']); cotUpsertClient($p['cliente']);
            $db->exec('COMMIT');
        } catch(Throwable $ex) { $db->exec('ROLLBACK'); throw $ex; }
        audit($old?'Edición de proforma':'Creación de proforma',$p['numero']);
        go('Proforma '.$p['numero'].' guardada.', post('after')==='edit' ? ['pf'=>$id,'edit'=>1] : ['pf'=>$id]);
    }
    if ($action==='pf_from_cot' && $canEdit) {
        $c=cotGet((int)post('id')); if(!$c) throw new RuntimeException('La cotización no existe.');
        $p=pfBlank($c['idioma']); $cfg=pfCfg();
        $p=pfNormalize(['moneda'=>$c['moneda'],'ceco'=>$c['ceco'],'cliente'=>$c['cliente'],'objeto'=>$c['objeto'],'items'=>$c['items'],'descuento'=>$c['descuento'],'iva'=>$c['iva'],
            'ref'=>['cotizacion'=>$c['numero'],'oc_cliente'=>''],'cot_id'=>$c['id'],
            'envio'=>['origen'=>'Chile','destino'=>$c['servicio']['lugar'],'fecha'=>$c['servicio']['desde']]]+$p);
        $p['emisor']=$cfg['emisor'];
        $db->exec('BEGIN IMMEDIATE');
        try { $p['numero']=pfNextNumero($cfg); $id=pfStore($p,0,(int)$user['id']); pfBumpSiguiente($p['numero']); $db->exec('COMMIT'); }
        catch(Throwable $ex) { $db->exec('ROLLBACK'); throw $ex; }
        audit('Creación de proforma',$p['numero'].' desde '.$c['numero']);
        go('Proforma '.$p['numero'].' creada como borrador desde la cotización '.$c['numero'].'. Revisa el embarque, el anticipo y los datos bancarios.',['pf'=>$id,'edit'=>1]);
    }
    if ($action==='pf_estado' && $canEdit) {
        $p=pfGet((int)post('id')); if(!$p) throw new RuntimeException('La proforma no existe.');
        $estado=post('estado'); if(!in_array($estado,PF_ESTADOS,true)) throw new RuntimeException('Estado no válido.');
        $id=$p['id']; unset($p['id'],$p['owner'],$p['created'],$p['updated']); $p['estado']=$estado;
        pfStore($p,$id,null); audit('Cambio de estado de proforma',$p['numero'].' → '.$estado);
        go('Proforma '.$p['numero'].': '.$estado.'.', post('back')==='pf' ? ['pf'=>$id] : ['tab'=>'pf']);
    }
    if ($action==='pf_delete' && $isAdmin) {
        $p=pfGet((int)post('id')); if(!$p) go('',['tab'=>'pf']);
        query('DELETE FROM pf_invoices WHERE id=?',[$p['id']]); audit('Eliminación de proforma',$p['numero']); go('Proforma '.$p['numero'].' eliminada.',['tab'=>'pf']);
    }
    if ($action==='pf_cfg' && $isAdmin) {
        $cfg=pfCfg();
        $sig=(int)post('siguiente'); if($sig<1||$sig>999999) throw new RuntimeException('El próximo correlativo debe ser un número entre 1 y 999999.');
        $validez=(int)ocNum(post('validez')); if($validez<1||$validez>365) throw new RuntimeException('La validez debe estar entre 1 y 365 días.');
        foreach(['iva'=>'El impuesto','anticipo'=>'El anticipo'] as $k=>$lbl) if(ocNum(post($k))>100) throw new RuntimeException($lbl.' debe estar entre 0 y 100 %.');
        $emisor=[]; foreach([...OC_EMISOR,'telefono'] as $k) $emisor[$k]=post('em_'.$k);
        $banco=[]; foreach(PF_BANCO as $k) $banco[$k]=post('bk_'.$k);
        $txt=[];
        foreach(['es','en'] as $l) {
            $n=pfNormalize(['firmas'=>array_map(fn($r,$nn,$c)=>['rol'=>$r,'nombre'=>$nn,'cargo'=>$c],ocList($l.'_fi_rol'),ocList($l.'_fi_nombre'),ocList($l.'_fi_cargo')),
                'condiciones'=>array_map(fn($k,$v)=>['k'=>$k,'v'=>$v],ocList($l.'_co_k'),ocList($l.'_co_v')),'pago'=>post($l.'_pago'),'declaracion'=>post($l.'_declaracion')]);
            $txt[$l]=['pago'=>$n['pago'],'declaracion'=>$n['declaracion'],'firmas'=>$n['firmas'],'condiciones'=>$n['condiciones']];
        }
        $n=pfNormalize(['emisor'=>$emisor,'banco'=>$banco]);
        pfSaveCfg(['prefijo'=>ocText(post('prefijo'),30),'siguiente'=>$sig,'validez'=>$validez,'iva'=>ocNum(post('iva')),'anticipo'=>ocNum(post('anticipo')),
            'moneda'=>docMoneda(post('moneda')),'idioma'=>docLang(post('idioma')),'emisor'=>$n['emisor'],'banco'=>$n['banco'],'es'=>$txt['es'],'en'=>$txt['en']]+$cfg);
        audit('Configuración de proformas'); go('Configuración guardada. Se aplica a las próximas proformas.',['tab'=>'pf_cfg']);
    }
}

/** Hoja carta de la proforma (mismo diseño que cotizaciones y OC), en el idioma y la moneda del documento. */
function pfSheet(array $p): void {
    $em=$p['emisor']; $c=$p['cliente']; $e=$p['envio']; $b=$p['banco']; $t=pfTotals($p); $l=$p['idioma']; $m=$p['moneda'];
    $T=fn(string $es, string $en)=>tr($l,$es,$en); $M=fn($v)=>money($v,$m,$l); $D=fn(string $d)=>docDate($d,$l);
    $blank='<span class="muted">__________________</span>';
    $stamp=['Anulada'=>$T('ANULADA','VOID'),'Borrador'=>$T('BORRADOR','DRAFT'),'Pagada'=>$T('PAGADA','PAID')][$p['estado']]??'';
    $hasEnvio=$e['incoterm']!==''||$e['carga']!==''||$e['destino']!==''||$e['transporte']!==''||$e['fecha']!=='';
    $bank=array_filter($b,'strlen');
    $half=(int)ceil(count($p['condiciones'])/2); ?>
<article class="sheet pf-sheet" lang="<?=e($l)?>">
<?php if($stamp):?><div class="stamp <?=ocEstadoClass($p['estado'])?>"><?=$stamp?></div><?php endif?>
<div class="s-head"><img src="<?=e(empresa()['logo'])?>" alt="<?=e(empresa()['nombre'])?>"><div class="s-title"><h2><?=$T('FACTURA PROFORMA','PROFORMA INVOICE')?></h2>
  <div class="meta"><?=$T('N°','No.')?> <span class="num"><?=e($p['numero']?:'—')?></span></div>
  <div class="meta"><?=$T('Fecha de emisión','Issue date')?>&nbsp; <span class="ink"><?=e($D($p['fecha']))?></span></div>
  <div class="meta"><?=$T('Válida hasta','Valid until')?>&nbsp; <span class="ink"><?=e($D($p['vence']))?></span></div>
  <?php if($p['ref']['cotizacion']!==''):?><div class="meta"><?=$T('Cotización','Quotation ref.')?>&nbsp; <span class="ink"><?=e($p['ref']['cotizacion'])?></span></div><?php endif?>
  <?php if($p['ref']['oc_cliente']!==''):?><div class="meta"><?=$T('OC del cliente','Buyer PO')?>&nbsp; <span class="ink"><?=e($p['ref']['oc_cliente'])?></span></div><?php endif?>
</div></div>
<div class="accent"></div>
<div class="s-parties">
  <div><div class="lbl"><?=$T('Vendedor / exportador','Seller / exporter')?></div><div class="strong"><?=e($em['razon'])?></div><?php if($em['rut']!==''):?><div><?=$T('R.U.T.','Tax ID (RUT)')?> <?=e($em['rut'])?></div><?php endif?><div><?=e($em['direccion'])?><?=$em['direccion']!==''&&!preg_match('/chile/i',$em['direccion'])?', Chile':''?></div><div class="link"><?=e(implode(' · ',array_filter([$em['email'],$em['telefono']??'',$em['web']],'strlen')))?></div></div>
  <div><div class="lbl"><?=$T('Comprador','Buyer')?></div><div class="strong"><?=$c['razon']!==''?e($c['razon']):$blank?></div><div><?=$T('Identificación tributaria','Tax ID')?> <?=$c['rut']!==''?e($c['rut']):$blank?></div>
    <div><?=e(implode(', ',array_filter([$c['direccion'],$c['ciudad']],'strlen')))?></div>
    <div class="strong"><?=$c['pais']!==''?e($c['pais']):''?></div>
    <?php if($c['contacto']!==''):?><div><span class="muted"><?=$T('At.','Attn.')?>&nbsp; </span><?=e($c['contacto'])?></div><?php endif?>
    <div class="link"><?=e(implode(' · ',array_filter([$c['email'],$c['telefono']],'strlen')))?></div></div>
  <div><div class="lbl"><?=$T('Entrega','Delivery terms')?></div>
    <div><span class="muted">Incoterm®&nbsp; </span><?=$e['incoterm']!==''?'<b>'.e($e['incoterm']).'</b> '.e($e['incoterm_lugar']).' <span class="muted">(Incoterms® 2020)</span>':$blank?></div>
    <div><span class="muted"><?=$T('País de origen','Country of origin')?>&nbsp; </span><?=e($e['origen']?:'—')?></div>
    <?php if($e['carga']!==''):?><div><span class="muted"><?=$T('Embarque','Place of loading')?>&nbsp; </span><?=e($e['carga'])?></div><?php endif?>
    <?php if($e['destino']!==''):?><div><span class="muted"><?=$T('Destino','Destination')?>&nbsp; </span><?=e($e['destino'])?></div><?php endif?>
    <?php if($e['transporte']!==''):?><div><span class="muted"><?=$T('Transporte','Transport')?>&nbsp; </span><?=e($e['transporte'])?></div><?php endif?>
    <?php if($e['fecha']!==''):?><div><span class="muted"><?=$T('Fecha estimada','Estimated date')?>&nbsp; </span><?=e($D($e['fecha']))?></div><?php endif?>
  </div>
</div>
<div class="s-summary">
  <div class="obj"><div class="lbl"><?=$T('Descripción general','General description')?></div><div class="t"><?=e($p['objeto']['titulo']?:'—')?></div><div class="muted"><?=e($p['objeto']['detalle'])?></div></div>
  <div class="tot"><div class="lbl"><?=$T('Total a pagar','Total amount due')?> (<?=e($m)?>)</div><div class="v"><?=$M($t['total'])?></div><div class="n"><?php if($p['anticipo']>0):?><?=$T('Anticipo','Advance')?> <?=e(docQty($p['anticipo'],$l))?>%: <?=$M($t['anticipo'])?><?php else:?><?=$p['iva']>0?$T('Incluye impuesto','Tax included'):$T('Exento de impuestos','Tax exempt')?><?php endif?></div></div>
</div>
<div class="s-sec"><?=$T('Detalle','Details')?></div>
<table class="items"><thead><tr><th class="c w4">#</th><th><?=$T('Descripción','Description')?></th><th class="c w11"><?=$T('Unidad','Unit')?></th><th class="c w8"><?=$T('Cant.','Qty')?></th><th class="r w15"><?=$T('Precio unit.','Unit price')?></th><th class="r w15"><?=$T('Total','Amount')?></th></tr></thead><tbody>
<?php foreach($p['items'] as $i=>$it):?><tr><td class="c muted"><?=$i+1?></td><td><?=e($it['desc'])?></td><td class="c muted"><?=e(docUnit($it['unidad'],$l))?></td><td class="c"><?=e(docQty($it['cant'],$l))?></td><td class="r"><?=$M($it['precio'])?></td><td class="r"><?=$M(docRound($it['cant']*$it['precio'],$m))?></td></tr><?php endforeach?>
<?php if(!$p['items']):?><tr><td colspan="6" class="muted c"><?=$T('Sin ítems','No items')?></td></tr><?php endif?>
</tbody><tfoot>
<tr><td colspan="4"></td><td class="r muted">Subtotal</td><td class="r"><?=$M($t['subtotal'])?></td></tr>
<?php if($t['descuento']>0):?><tr><td colspan="4"></td><td class="r muted"><?=$T('Descuento','Discount')?> <?=e(docQty($p['descuento'],$l))?>%</td><td class="r">− <?=$M($t['descuento'])?></td></tr><?php endif?>
<?php if($p['flete']>0):?><tr><td colspan="4"></td><td class="r muted"><?=$T('Flete','Freight')?></td><td class="r"><?=$M($p['flete'])?></td></tr><?php endif?>
<?php if($p['seguro']>0):?><tr><td colspan="4"></td><td class="r muted"><?=$T('Seguro','Insurance')?></td><td class="r"><?=$M($p['seguro'])?></td></tr><?php endif?>
<?php if($p['iva']>0):?><tr><td colspan="4"></td><td class="r muted"><?=$T('Impuesto','Tax')?> <?=e(docQty($p['iva'],$l))?>%</td><td class="r"><?=$M($t['iva'])?></td></tr><?php endif?>
<tr class="total"><td colspan="4"><?=$p['iva']>0?'':'<span class="muted">'.$T('Exento de impuestos','Tax exempt').'</span>'?></td><td class="r k">Total <?=e($m)?></td><td class="r v"><?=$M($t['total'])?></td></tr>
<?php if($p['anticipo']>0):?><tr><td colspan="4"></td><td class="r muted"><?=$T('Anticipo','Advance payment')?> <?=e(docQty($p['anticipo'],$l))?>%</td><td class="r"><b><?=$M($t['anticipo'])?></b></td></tr>
<tr><td colspan="4"></td><td class="r muted"><?=$T('Saldo','Balance')?></td><td class="r"><?=$M($t['saldo'])?></td></tr><?php endif?>
</tfoot></table>
<div class="pf-pay">
  <div><div class="s-sec"><?=$T('Condiciones de pago','Payment terms')?></div><div class="s-obs"><?=e($p['pago']?:'—')?></div></div>
  <div><div class="s-sec"><?=$T('Datos bancarios','Bank details')?></div><?php if($bank):?><dl class="pf-bank"><?php foreach($bank as $k=>$v):?><dt><?=e(PF_BANCO_LBL[$k][$l==='en'?1:0])?></dt><dd><?=e($v)?></dd><?php endforeach?></dl><?php else:?><div class="muted"><?=$T('Se informan al confirmar.','Provided upon confirmation.')?></div><?php endif?></div>
</div>
<?php if($p['condiciones']):?><div class="s-sec"><?=$T('Condiciones','Terms and conditions')?></div><div class="s-conds"><?php foreach([array_slice($p['condiciones'],0,$half),array_slice($p['condiciones'],$half)] as $col):?><div><?php foreach($col as $cc):?><div><b><?=e($cc['k'])?></b>&nbsp; <?=e($cc['v'])?></div><?php endforeach?></div><?php endforeach?></div><?php endif?>
<?php if($p['obs']!==''):?><div class="s-obs"><b><?=$T('Observaciones','Remarks')?></b>&nbsp; <?=e($p['obs'])?></div><?php endif?>
<?php if($p['declaracion']!==''):?><div class="s-obs pf-decl"><?=e($p['declaracion'])?></div><?php endif?>
<div class="s-sign cot-sign"><?php foreach($p['firmas'] as $f):?><div><div class="line"><b><?=e($f['nombre'])?></b></div><div class="muted"><?=e($f['cargo'])?></div><div class="rol"><?=e($f['rol'])?></div></div><?php endforeach?></div>
<div class="s-foot"><span><?=e($em['razon'])?><?=$em['rut']!==''?' · '.$T('R.U.T.','Tax ID').' '.e($em['rut']):''?><?=$em['web']!==''?' · '.e($em['web']):''?></span><span><?=$T('Valores en '.mb_strtolower(DOC_MONEDAS[$m][0]),'Amounts in '.DOC_MONEDAS[$m][1])?> · <?=$T('Documento sin valor tributario','Not a tax invoice')?></span></div>
</article>
<?php }

/** Descargas GET: hoja para imprimir y registro en CSV. */
function pfGetRequests(): void {
    global $user;
    if(!isset($_GET['pf_print']) && !isset($_GET['pf_csv'])) return;
    if(!$user) stop(403,'Debes iniciar sesión.');
    if(!pfOn()) stop(404,'Módulo no disponible en esta empresa.');
    if(isset($_GET['pf_print'])) {
        $p=pfGet((int)$_GET['pf_print']); if(!$p) stop(404,'Proforma no encontrada.');
        audit('Impresión de proforma',$p['numero']); ?><!doctype html>
<html lang="<?=e($p['idioma'])?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($p['numero'])?> · <?=tr($p['idioma'],'Proforma','Proforma invoice')?> <?=e(empresa()['corto'])?></title><link rel="stylesheet" href="assets/style.css?v=17"></head>
<body class="print-page"><div class="print-bar no-print"><a class="btn ghost small" href="<?=e('index.php?pf='.$p['id'])?>">← Volver</a><span>Para imprimir o guardar como PDF usa <b>Ctrl + P</b> (<b>Cmd + P</b> en Mac), tamaño Carta y márgenes «Ninguno».</span></div>
<div class="sheet-wrap"><?php pfSheet($p)?></div></body></html><?php exit;
    }
    [$where,$params]=pfFilter(is_string($_GET['q']??null)?trim($_GET['q']):'', is_string($_GET['estado']??null)?$_GET['estado']:'');
    $rows=query('SELECT data FROM pf_invoices'.$where.' ORDER BY id DESC',$params)->fetchAll(PDO::FETCH_COLUMN);
    session_write_close();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="registro_proformas_'.strtolower(empresa()['corto']).'_'.ocToday().'.csv"');
    $out=fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,['N° proforma','Fecha','Válida hasta','Estado','Idioma','Moneda','Cliente','País','Objeto','Incoterm','Cotización','OC cliente','Subtotal','Flete','Seguro','Impuesto','Total','Anticipo','Saldo','Proyecto'],';','"','');
    $n=fn($v)=>is_float($v)?str_replace('.',',',(string)$v):$v;
    foreach($rows as $json){ $p=pfNormalize(json_decode($json,true)?:[]); $t=pfTotals($p);
        $cells=array_map(fn($v)=>is_string($v)&&preg_match('/^[=+\-@\t\r]/',$v)?"'".$v:$n($v),[$p['numero'],$p['fecha'],$p['vence'],pfVencida($p)?'Vencida':$p['estado'],strtoupper($p['idioma']),$p['moneda'],$p['cliente']['razon'],$p['cliente']['pais'],$p['objeto']['titulo'],trim($p['envio']['incoterm'].' '.$p['envio']['incoterm_lugar']),$p['ref']['cotizacion'],$p['ref']['oc_cliente'],$t['subtotal'],$p['flete'],$p['seguro'],$t['iva'],$t['total'],$t['anticipo'],$t['saldo'],$p['ceco']]);
        fputcsv($out,$cells,';','"',''); }
    fclose($out); exit;
}
function pfFilter(string $q, string $estado): array {
    $w=[]; $p=[];
    if($q!==''){ $w[]='(numero LIKE ? OR cli_razon LIKE ? OR obj_titulo LIKE ? OR ceco LIKE ?)'; array_push($p,"%$q%","%$q%","%$q%","%$q%"); }
    if($estado==='Vencida'){ $w[]="estado='Emitida' AND vence<>'' AND vence<?"; $p[]=ocToday(); }
    elseif($estado==='Emitida'){ $w[]="estado='Emitida' AND (vence='' OR vence>=?)"; $p[]=ocToday(); }
    elseif(in_array($estado,PF_ESTADOS,true)){ $w[]='estado=?'; $p[]=$estado; }
    return [$w?' WHERE '.implode(' AND ',$w):'',$p];
}
