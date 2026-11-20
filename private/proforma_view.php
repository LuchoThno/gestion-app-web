<?php
// Pantallas del módulo de proformas. Se incluye desde view.php ($mode empieza con "pf").
$pfCfg=pfCfg();
$pfEdit=$mode==='pf_new' || ($mode==='pf_doc' && $canEdit && $g('edit')==='1');
$pfDoc=$mode==='pf_doc' ? pfGet($pfId) : false;
$pfPill=fn(array $p): string => pfVencida($p) ? '<span class="oc-pill st-vencida" title="Emitida; la validez terminó el '.e(ocDate($p['vence'],'')).'">Vencida</span>' : '<span class="oc-pill '.ocEstadoClass($p['estado']).'">'.e($p['estado']).'</span>';
$pfBankLbl=fn(string $k): string => PF_BANCO_LBL[$k][0];

/** Formulario del editor (sin JavaScript: filas vacías extra para ítems y condiciones). */
$pfForm=function(array $p, int $id) use($pfCfg, $pfBankLbl): void {
    $clients=query('SELECT id,razon,rut,pais FROM cot_clients ORDER BY razon COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
    $items=$p['items']; for($i=count($items), $n=0; $n<3 && $i<60; $i++, $n++) $items[]=['desc'=>'','unidad'=>$p['idioma']==='en'?'Service':'Servicio','cant'=>1,'precio'=>''];
    $conds=$p['condiciones']; for($i=count($conds), $n=0; $n<2 && $i<30; $i++, $n++) $conds[]=['k'=>'','v'=>''];
    $firmas=$p['firmas']?:pfLangDefaults($pfCfg,$p['idioma'])['firmas'];
    $t=pfTotals($p); $M=fn($v)=>money($v,$p['moneda']); $e=$p['envio']; ?>
<form method="post" class="oc-form"><?=csrf()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="idioma_prev" value="<?=e($p['idioma'])?>"><input type="hidden" name="cot_id" value="<?=(int)$p['cot_id']?>">
<section class="card"><h2>Proforma</h2><div class="oc-grid">
  <label>Idioma del documento<?=docLangSelect('idioma',$p['idioma'])?></label>
  <label>Moneda<?=docMonedaSelect('moneda',$p['moneda'])?></label>
  <label>N° proforma<input name="numero" maxlength="40" value="<?=e($p['numero'])?>" <?=$id?'required':'placeholder="'.e(pfNextNumero($pfCfg)).' (automático)"'?>></label>
  <label>Estado<select name="estado"><?php foreach(PF_ESTADOS as $s):?><option<?=$p['estado']===$s?' selected':''?>><?=e($s)?></option><?php endforeach?></select></label>
  <label>Fecha de emisión<input type="date" name="fecha" value="<?=e($p['fecha'])?>"></label>
  <label>Validez (días)<input type="number" name="validez" min="0" max="365" step="1" value="<?=(int)$p['validez']?>"></label>
  <label>Cotización de referencia<input name="ref_cotizacion" maxlength="40" value="<?=e($p['ref']['cotizacion'])?>" placeholder="Ej: COT-MRM-2026-0004"></label>
  <label>OC del cliente<input name="ref_oc" maxlength="60" value="<?=e($p['ref']['oc_cliente'])?>" placeholder="Si el cliente envió una"></label>
  <label class="span2">Proyecto / centro de costo<input name="ceco" maxlength="120" list="pf-projects" value="<?=e($p['ceco'])?>"></label>
</div><datalist id="pf-projects"><?php foreach(query('SELECT name FROM projects ORDER BY name COLLATE NOCASE')->fetchAll(PDO::FETCH_COLUMN) as $pn):?><option value="<?=e($pn)?>"><?php endforeach?></datalist>
<p class="hint">El idioma cambia la hoja impresa; lo que escribas en descripciones y condiciones se imprime tal cual, así que escríbelo en el idioma del documento.<?=$p['vence']!==''?' Válida hasta el <b>'.e(ocDate($p['vence'])).'</b>.':''?></p></section>

<section class="card"><h2>Comprador</h2>
<?php if($clients):?><label class="oc-saved">Usar un cliente guardado<select name="cli_saved"><option value="0">— Escribir los datos abajo —</option><?php foreach($clients as $s):?><option value="<?=(int)$s['id']?>"><?=e($s['razon'].($s['pais']!==''?' · '.$s['pais']:($s['rut']!==''?' · '.$s['rut']:'')))?></option><?php endforeach?></select><small>Son los mismos clientes de Cotizaciones. Si eliges uno, al guardar reemplaza lo escrito abajo.</small></label><?php endif?>
<div class="oc-grid">
  <label class="span2">Razón social / company name<input name="cli_razon" maxlength="300" value="<?=e($p['cliente']['razon'])?>"></label>
  <label>Identificación tributaria (Tax ID)<input name="cli_rut" maxlength="300" value="<?=e($p['cliente']['rut'])?>"></label>
  <label>País<input name="cli_pais" maxlength="300" value="<?=e($p['cliente']['pais'])?>" placeholder="Ej: Argentina"></label>
  <label class="span2">Dirección<input name="cli_direccion" maxlength="300" value="<?=e($p['cliente']['direccion'])?>"></label>
  <label>Ciudad<input name="cli_ciudad" maxlength="300" value="<?=e($p['cliente']['ciudad'])?>"></label>
  <label>Giro / actividad<input name="cli_giro" maxlength="300" value="<?=e($p['cliente']['giro'])?>"></label>
  <label>Contacto (atención)<input name="cli_contacto" maxlength="300" value="<?=e($p['cliente']['contacto'])?>"></label>
  <label>Teléfono<input name="cli_telefono" maxlength="300" value="<?=e($p['cliente']['telefono'])?>"></label>
  <label class="span2">Correo<input name="cli_email" maxlength="300" value="<?=e($p['cliente']['email'])?>"></label>
</div></section>

<section class="card"><h2>Entrega (Incoterm y embarque)</h2><div class="oc-grid">
  <label>Incoterm<select name="en_incoterm"><option value="">— Sin Incoterm —</option><?php foreach(DOC_INCOTERMS as $ic):?><option<?=$e['incoterm']===$ic?' selected':''?>><?=$ic?></option><?php endforeach?></select></label>
  <label>Lugar del Incoterm<input name="en_incoterm_lugar" maxlength="120" value="<?=e($e['incoterm_lugar'])?>" placeholder="Ej: Punta Arenas, Chile"></label>
  <label>País de origen<input name="en_origen" maxlength="80" value="<?=e($e['origen'])?>"></label>
  <label>Medio de transporte<input name="en_transporte" maxlength="60" list="pf-transporte" value="<?=e($e['transporte'])?>"></label>
  <label>Lugar / puerto de embarque<input name="en_carga" maxlength="120" value="<?=e($e['carga'])?>"></label>
  <label>Destino / lugar del servicio<input name="en_destino" maxlength="120" value="<?=e($e['destino'])?>"></label>
  <label>Fecha estimada (embarque o servicio)<input type="date" name="en_fecha" value="<?=e($e['fecha'])?>"></label>
</div><datalist id="pf-transporte"><option value="Sea freight"><option value="Air freight"><option value="Road"><option value="Marítimo"><option value="Aéreo"><option value="Terrestre"><option value="Courier"></datalist>
<details class="hint-more"><summary>¿Qué Incoterm uso?</summary><p class="hint">Para servicios con ROV en el lugar del cliente suele bastar con dejarlo vacío y completar «Destino / lugar del servicio». Para venta o arriendo de equipos: <b>EXW</b> (el cliente retira en tus instalaciones), <b>FCA</b> (lo entregas al transportista del cliente), <b>CPT/CIP</b> (pagas el transporte; en CIP también el seguro), <b>DAP</b> (entregado en destino, sin derechos de importación), <b>DDP</b> (entregado con los derechos pagados). FOB, CFR y CIF son solo para transporte marítimo. Si el flete o el seguro se cobran aparte, anótalos en el detalle.</p></details></section>

<section class="card"><h2>Detalle</h2><div class="oc-grid">
  <label class="span2">Descripción general<input name="obj_titulo" maxlength="160" value="<?=e($p['objeto']['titulo'])?>" placeholder="Ej: ROV inspection of mooring lines"></label>
  <label class="span2">Detalle de la descripción<input name="obj_detalle" maxlength="300" value="<?=e($p['objeto']['detalle'])?>"></label>
</div>
<div class="oc-items"><div class="oc-item oc-item-head"><span>Descripción</span><span>Unidad</span><span>Cant.</span><span>Precio unit. (<?=e($p['moneda'])?>)</span></div>
<?php foreach($items as $it):?><div class="oc-item">
  <label><span class="sr">Descripción</span><input name="it_desc[]" maxlength="500" value="<?=e($it['desc'])?>" placeholder="Descripción" list="pf-items"></label>
  <label><span class="sr">Unidad</span><input name="it_unidad[]" maxlength="30" value="<?=e($it['unidad'])?>"></label>
  <label><span class="sr">Cantidad</span><input type="number" name="it_cant[]" min="0" step="any" value="<?=e(ocNumIn($it['cant']))?>"></label>
  <label><span class="sr">Precio unitario</span><input type="number" name="it_precio[]" min="0" step="any" value="<?=e(docNumIn($it['precio']))?>" placeholder="0"></label>
</div><?php endforeach?></div>
<?=docRovDatalist('pf-items',$p['idioma'])?><div class="oc-grid pf-amounts">
  <label>Descuento (%)<input type="number" name="descuento" min="0" max="100" step="0.1" value="<?=e(ocNumIn($p['descuento']))?>"></label>
  <label>Flete (<?=e($p['moneda'])?>)<input type="number" name="flete" min="0" step="any" value="<?=$p['flete']>0?e(docNumIn($p['flete'])):''?>" placeholder="0"></label>
  <label>Seguro (<?=e($p['moneda'])?>)<input type="number" name="seguro" min="0" step="any" value="<?=$p['seguro']>0?e(docNumIn($p['seguro'])):''?>" placeholder="0"></label>
  <label>Impuesto / IVA (%)<input type="number" name="iva" min="0" max="100" step="0.1" value="<?=e(ocNumIn($p['iva']))?>"></label>
  <label>Anticipo (%)<input type="number" name="anticipo" min="0" max="100" step="1" value="<?=e(ocNumIn($p['anticipo']))?>"></label>
</div>
<p class="hint">Se ignoran las filas sin descripción ni precio. Usa «Guardar y seguir editando» para obtener más filas vacías. Una exportación de servicios normalmente va con impuesto 0.<?php if($p['items']):?> <b>Guardado: subtotal <?=$M($t['subtotal'])?><?=$t['descuento']>0?' · descuento '.$M($t['descuento']):''?><?=$p['flete']+$p['seguro']>0?' · flete y seguro '.$M($p['flete']+$p['seguro']):''?> · total <?=$M($t['total'])?><?=$p['anticipo']>0?' · anticipo '.$M($t['anticipo']):''?></b><?php endif?></p></section>

<section class="card"><h2>Pago</h2>
<label class="oc-obs">Condiciones de pago<textarea name="pago" maxlength="600" rows="3"><?=e($p['pago'])?></textarea></label>
<details class="hint-more"<?=array_filter($p['banco'],'strlen')?'':' open'?>><summary>Datos bancarios para la transferencia</summary><div class="oc-grid">
<?php foreach(PF_BANCO as $k):?><label class="<?=in_array($k,['direccion','intermediario'],true)?'span2':''?>"><?=e($pfBankLbl($k))?><input name="bk_<?=$k?>" maxlength="300" value="<?=e($p['banco'][$k])?>"></label><?php endforeach?>
</div><p class="hint">Vienen de la configuración de proformas; aquí puedes cambiarlos solo para este documento (por ejemplo, otra cuenta para EUR).</p></details></section>

<section class="card"><h2>Condiciones y observaciones</h2>
<div class="oc-conds"><?php foreach($conds as $cc):?><div class="oc-cond"><label><span class="sr">Condición</span><input name="co_k[]" maxlength="60" value="<?=e($cc['k'])?>" placeholder="Ej: Validity"></label><label><span class="sr">Texto</span><input name="co_v[]" maxlength="400" value="<?=e($cc['v'])?>"></label></div><?php endforeach?></div>
<p class="hint">Deja ambas casillas vacías para quitar una condición.</p>
<label class="oc-obs">Observaciones<textarea name="obs" maxlength="1500" rows="2"><?=e($p['obs'])?></textarea></label>
<label class="oc-obs">Declaración al pie<textarea name="declaracion" maxlength="600" rows="2"><?=e($p['declaracion'])?></textarea></label></section>

<section class="card"><h2>Firmas</h2><div class="oc-firmas">
<?php foreach($firmas as $f):?><fieldset><legend><?=e($f['rol'])?></legend><input type="hidden" name="fi_rol[]" value="<?=e($f['rol'])?>">
  <label>Nombre<input name="fi_nombre[]" maxlength="80" value="<?=e($f['nombre'])?>"></label><label>Cargo<input name="fi_cargo[]" maxlength="80" value="<?=e($f['cargo'])?>"></label></fieldset><?php endforeach?>
</div></section>

<div class="oc-actions"><button class="primary">Guardar</button><button class="ghost" name="after" value="edit">Guardar y seguir editando</button><a class="btn ghost" href="<?=e($id?url(['pf'=>$id]):url(['tab'=>'pf']))?>">Cancelar</a></div>
<input type="hidden" name="action" value="pf_save">
</form>
<?php };

$posted=($_SERVER['REQUEST_METHOD']==='POST' && post('action')==='pf_save' && $error) ? pfNormalize(pfFromPost()) : null;
$pfFiltros=[...PF_ESTADOS,'Vencida'];
?>

<?php if($mode==='pf'):
$q=$g('q'); $fe=in_array($g('estado'),$pfFiltros,true)?$g('estado'):'';
[$where,$params]=pfFilter($q,$fe);
$list=query('SELECT id,data FROM pf_invoices'.$where.' ORDER BY id DESC LIMIT 500',$params)->fetchAll(PDO::FETCH_ASSOC);
$n=(int)query('SELECT COUNT(*) FROM pf_invoices')->fetchColumn();
// Montos por moneda (no se mezclan): emitidas vigentes y pagadas.
$byCur=function(string $cond, array $prm=[]) { $o=[]; foreach(query("SELECT moneda, SUM(total) FROM pf_invoices WHERE $cond GROUP BY moneda ORDER BY moneda",$prm)->fetchAll(PDO::FETCH_NUM) as [$m,$v]) $o[]=money((float)$v,docMoneda($m)); return $o?implode(' · ',$o):'—'; };
?>
<div class="page-head"><div><span class="eyebrow muted">VENTAS</span><h1>Proformas</h1><p class="subtitle">Facturas proforma para acuerdos internacionales, en español o inglés y en CLP, USD o EUR. No son documentos tributarios.</p></div>
<div class="head-actions"><?php if($isAdmin):?><a class="btn ghost" href="<?=e(url(['tab'=>'pf_cfg']))?>"><?=ic('settings')?>Configuración</a><?php endif?><a class="btn ghost" href="<?=e(url(['pf_csv'=>1]+($q!==''?['q'=>$q]:[])+($fe?['estado'=>$fe]:[])))?>"><?=ic('download')?>Exportar CSV</a><?php if($canEdit):?><details class="pop"><summary class="btn primary"><?=ic('plus')?>Nueva proforma</summary><div class="pop-body"><strong>Idioma de la proforma</strong><a class="btn ghost" href="<?=e(url(['tab'=>'pf_new','lang'=>'en']))?>"><?=ic('globe')?>English</a><a class="btn ghost" href="<?=e(url(['tab'=>'pf_new','lang'=>'es']))?>">Español</a><small class="muted">También puedes crearla desde una cotización con «Crear proforma».</small></div></details><?php endif?></div></div>
<div class="stat-grid">
  <div class="stat"><?=ic('receipt')?><strong><?=$n?></strong><span>Proformas registradas</span></div>
  <div class="stat"><?=ic('clock')?><strong><?=e($byCur("estado='Emitida' AND (vence='' OR vence>=?)",[ocToday()]))?></strong><span>Emitidas vigentes (por cobrar)</span></div>
  <div class="stat"><?=ic('check')?><strong><?=e($byCur("estado='Pagada'"))?></strong><span>Pagadas</span></div>
</div>
<form method="get" class="oc-filter" role="search"><input type="hidden" name="tab" value="pf"><label class="grow"><span class="sr">Buscar</span><input name="q" value="<?=e($q)?>" placeholder="Buscar por N°, cliente, descripción o proyecto…"></label><label><span class="sr">Estado</span><select name="estado"><option value="">Todos los estados</option><?php foreach($pfFiltros as $s):?><option<?=$fe===$s?' selected':''?>><?=e($s)?></option><?php endforeach?></select></label><button class="ghost"><?=ic('search')?>Filtrar</button><?php if($q!==''||$fe):?><a class="btn ghost" href="<?=e(url(['tab'=>'pf']))?>">Limpiar</a><?php endif?></form>
<?php if($list):?><div class="table-wrap"><table class="oc-reg"><thead><tr><th>N° proforma</th><th class="hide-sm">Fecha</th><th>Cliente</th><th class="hide-sm">Descripción</th><th class="right">Total</th><th>Estado</th><th class="right">Acciones</th></tr></thead><tbody>
<?php foreach($list as $row): $r=pfNormalize(json_decode($row['data'],true)?:[]); $rid=(int)$row['id']; $t=pfTotals($r);?><tr>
  <td class="nowrap"><a class="oc-num" href="<?=e(url(['pf'=>$rid]))?>"><?=e($r['numero'])?></a> <span class="doc-tag"><?=strtoupper(e($r['idioma']))?></span><?php if($r['ref']['cotizacion']!==''):?><small><?=e($r['ref']['cotizacion'])?></small><?php endif?></td>
  <td class="hide-sm nowrap"><?=e(ocDate($r['fecha'],'—'))?><?php if($r['vence']!==''):?><small>Vence <?=e(ocDate($r['vence'],''))?></small><?php endif?></td><td><?=e($r['cliente']['razon']?:'—')?><?php if($r['cliente']['pais']!==''):?><small><?=e($r['cliente']['pais'])?></small><?php endif?></td><td class="hide-sm"><?=e($r['objeto']['titulo']?:'—')?></td>
  <td class="right nowrap"><b><?=money($t['total'],$r['moneda'])?></b><?php if($r['anticipo']>0):?><small>Anticipo <?=money($t['anticipo'],$r['moneda'])?></small><?php endif?></td>
  <td class="nowrap"><?php if($canEdit):?><form method="post" class="oc-state"><?=csrf()?><input type="hidden" name="id" value="<?=$rid?>"><label><span class="sr">Estado de <?=e($r['numero'])?></span><select name="estado" class="<?=pfVencida($r)?'st-vencida':ocEstadoClass($r['estado'])?>"><?php foreach(PF_ESTADOS as $s):?><option value="<?=e($s)?>"<?=$r['estado']===$s?' selected':''?>><?=e($s==='Emitida'&&pfVencida($r)?'Emitida (vencida)':$s)?></option><?php endforeach?></select></label><button class="ghost small" name="action" value="pf_estado" title="Aplicar estado">✓<span class="sr">Aplicar estado</span></button></form><?php else:?><?=$pfPill($r)?><?php endif?></td>
  <td class="right nowrap"><a class="icon-btn" href="<?=e(url(['pf'=>$rid]))?>" title="Abrir"><?=ic('eye')?><span class="sr">Abrir</span></a><?php if($canEdit):?><a class="icon-btn" href="<?=e(url(['tab'=>'pf_new','from'=>$rid]))?>" title="Duplicar"><?=ic('copy')?><span class="sr">Duplicar</span></a><?php endif?><a class="icon-btn" href="<?=e(url(['pf_print'=>$rid]))?>" target="_blank" rel="noopener" title="Imprimir / PDF"><?=ic('print')?><span class="sr">Imprimir / PDF</span></a></td>
</tr><?php endforeach?></tbody></table></div>
<?php if(count($list)>=500):?><p class="hint">Se muestran las 500 más recientes; usa la búsqueda para encontrar otras.</p><?php endif?>
<?php else:?><div class="empty-state"><?=ic('receipt')?><b><?=($q!==''||$fe)?'No hay proformas que coincidan':'Todavía no hay proformas'?></b><span><?=$canEdit?'Crea la primera con «Nueva proforma», o ábrela desde una cotización con «Crear proforma».':'Cuando un editor cree una proforma, aparecerá aquí.'?></span></div><?php endif?>

<?php elseif($mode==='pf_new'):
$src=pfGet((int)$g('from'));
if($posted) $p=$posted;
elseif($src){ $p=$src; unset($p['id'],$p['owner'],$p['created'],$p['updated']); $p['numero']=''; $p['fecha']=ocToday(); $p['vence']=cotVence($p['fecha'],$p['validez']); $p['estado']='Borrador'; $p['emisor']=$pfCfg['emisor']; }
else $p=pfBlank(docLang($g('lang')?:$pfCfg['idioma']));
?>
<nav class="crumbs" aria-label="Ruta"><a href="<?=e(url(['tab'=>'pf']))?>">Proformas</a><?=ic('chevron')?><span><?=$src?'Copia de '.e($src['numero']):'Nueva proforma'?></span></nav>
<div class="page-head"><div><span class="eyebrow muted">VENTAS</span><h1><?=$src?'Duplicar '.e($src['numero']):'Nueva proforma'?> <span class="doc-tag"><?=$p['idioma']==='en'?'English':'Español'?></span></h1><p class="subtitle">El número se asigna al guardar (próximo: <?=e(pfNextNumero($pfCfg))?>).</p></div></div>
<?php $pfForm($p,0)?>

<?php elseif($mode==='pf_doc' && !$pfDoc):?>
<div class="empty-state"><?=ic('search')?><b>Proforma no encontrada</b><span><a href="<?=e(url(['tab'=>'pf']))?>">Volver al registro</a></span></div>

<?php elseif($mode==='pf_doc' && $pfEdit):?>
<nav class="crumbs" aria-label="Ruta"><a href="<?=e(url(['tab'=>'pf']))?>">Proformas</a><?=ic('chevron')?><a href="<?=e(url(['pf'=>$pfDoc['id']]))?>"><?=e($pfDoc['numero'])?></a><?=ic('chevron')?><span>Editar</span></nav>
<div class="page-head"><div><span class="eyebrow muted">VENTAS</span><h1>Editar <?=e($pfDoc['numero'])?></h1><p class="subtitle">Al guardar verás la proforma en formato carta, lista para imprimir.</p></div></div>
<?php $pfForm($posted??$pfDoc,$pfDoc['id'])?>

<?php elseif($mode==='pf_doc'): $t=pfTotals($pfDoc);?>
<nav class="crumbs no-print" aria-label="Ruta"><a href="<?=e(url(['tab'=>'pf']))?>">Proformas</a><?=ic('chevron')?><span><?=e($pfDoc['numero'])?></span></nav>
<div class="page-head no-print">
  <div class="title-row"><a class="icon-btn" href="<?=e(url(['tab'=>'pf']))?>" title="Volver"><?=ic('back')?><span class="sr">Volver</span></a><span class="tile-icon big tone2"><?=ic('receipt')?></span><div><h1><?=e($pfDoc['numero'])?> <?=$pfPill($pfDoc)?></h1><p class="subtitle"><?=e($pfDoc['cliente']['razon']?:'Sin cliente')?><?=$pfDoc['cliente']['pais']!==''?' ('.e($pfDoc['cliente']['pais']).')':''?> · <?=money($t['total'],$pfDoc['moneda'])?> · <?=$pfDoc['idioma']==='en'?'en inglés':'en español'?> · actualizada el <?=e(fdate($pfDoc['updated'],true))?></p></div></div>
  <div class="head-actions">
    <a class="btn primary" href="<?=e(url(['pf_print'=>$pfDoc['id']]))?>" target="_blank" rel="noopener"><?=ic('print')?>Imprimir / PDF</a>
    <?php if($canEdit):?><a class="btn ghost" href="<?=e(url(['pf'=>$pfDoc['id'],'edit'=>1]))?>"><?=ic('edit')?>Editar</a>
    <details class="pop"><summary class="btn ghost">Estado</summary><form method="post" class="pop-body"><?=csrf()?><input type="hidden" name="id" value="<?=$pfDoc['id']?>"><input type="hidden" name="back" value="pf"><strong>Cambiar estado</strong><label>Estado<select name="estado"><?php foreach(PF_ESTADOS as $s):?><option<?=$pfDoc['estado']===$s?' selected':''?>><?=e($s)?></option><?php endforeach?></select></label><button class="primary" name="action" value="pf_estado">Aplicar</button></form></details><?php endif?>
    <?php if($canEdit):?><details class="pop"><summary class="btn ghost" title="Más opciones"><?=ic('more')?><span class="sr">Más opciones</span></summary><div class="pop-body"><a class="btn ghost" href="<?=e(url(['tab'=>'pf_new','from'=>$pfDoc['id']]))?>"><?=ic('copy')?>Duplicar proforma</a><?php if($isAdmin):?><form method="post" class="danger-zone stack"><?=csrf()?><input type="hidden" name="id" value="<?=$pfDoc['id']?>"><strong>Eliminar <?=e($pfDoc['numero'])?></strong><p>La eliminación es definitiva. Si la proforma ya se envió al cliente, considera marcarla como Anulada.</p><button class="danger" name="action" value="pf_delete"><?=ic('trash')?>Eliminar proforma</button></form><?php endif?></div></details><?php endif?>
  </div>
</div>
<?php if(pfVencida($pfDoc)):?><div class="alert no-print" role="status">La validez de esta proforma terminó el <?=e(ocDate($pfDoc['vence']))?>. Si el cliente aún la necesita, duplícala para emitir una nueva con fecha actual.</div><?php endif?>
<?php if($pfDoc['cot_id'] && ($oc=query('SELECT id,numero FROM cot_quotes WHERE id=?',[$pfDoc['cot_id']])->fetch(PDO::FETCH_ASSOC))):?><p class="ps-note no-print"><?=ic('quote')?>Creada desde la cotización <a href="<?=e(url(['cot'=>(int)$oc['id']]))?>"><?=e($oc['numero'])?></a>.</p><?php endif?>
<?php if(!array_filter($pfDoc['banco'],'strlen')):?><p class="ps-note no-print"><?=ic('info')?>Esta proforma no tiene datos bancarios. <?=$isAdmin?'Complétalos en <a href="'.e(url(['tab'=>'pf_cfg'])).'">Configuración</a> para las próximas, o edita esta.':'Edítala para agregarlos.'?></p><?php endif?>
<div class="sheet-wrap"><?php pfSheet($pfDoc)?></div>
<p class="hint no-print">También puedes imprimir esta página directamente con Ctrl + P (Cmd + P en Mac): solo se imprime la hoja.</p>

<?php elseif($mode==='pf_cfg'):?>
<nav class="crumbs" aria-label="Ruta"><a href="<?=e(url(['tab'=>'pf']))?>">Proformas</a><?=ic('chevron')?><span>Configuración</span></nav>
<div class="page-head"><div><span class="eyebrow muted">ADMINISTRACIÓN</span><h1>Configuración de proformas</h1><p class="subtitle">Estos datos se usan en las próximas proformas; las ya guardadas conservan los suyos.</p></div></div>
<form method="post" class="oc-form"><?=csrf()?>
<section class="card"><h2>Empresa emisora (vendedor)</h2><div class="oc-grid">
  <label class="span2">Razón social<input name="em_razon" maxlength="300" value="<?=e($pfCfg['emisor']['razon'])?>"></label>
  <label>R.U.T.<input name="em_rut" maxlength="300" value="<?=e($pfCfg['emisor']['rut'])?>"></label>
  <label>Teléfono<input name="em_telefono" maxlength="300" value="<?=e($pfCfg['emisor']['telefono']??'')?>" placeholder="+56 9 ..."></label>
  <label class="span2">Dirección<input name="em_direccion" maxlength="300" value="<?=e($pfCfg['emisor']['direccion'])?>"></label>
  <label>Correo<input name="em_email" maxlength="300" value="<?=e($pfCfg['emisor']['email'])?>"></label>
  <label>Sitio web<input name="em_web" maxlength="300" value="<?=e($pfCfg['emisor']['web'])?>"></label>
  <input type="hidden" name="em_giro" value="<?=e($pfCfg['emisor']['giro'])?>">
</div></section>
<section class="card"><h2>Datos bancarios</h2><div class="oc-grid">
<?php foreach(PF_BANCO as $k):?><label class="<?=in_array($k,['direccion','intermediario'],true)?'span2':''?>"><?=e($pfBankLbl($k))?><input name="bk_<?=$k?>" maxlength="300" value="<?=e($pfCfg['banco'][$k]??'')?>"></label><?php endforeach?>
</div><p class="hint">Se imprimen en cada proforma para la transferencia internacional. Para recibir USD necesitas una cuenta en dólares y el código SWIFT de tu banco.</p></section>
<section class="card"><h2>Numeración y valores predeterminados</h2><div class="oc-grid">
  <label>Prefijo<input name="prefijo" maxlength="30" value="<?=e($pfCfg['prefijo'])?>"></label>
  <label>Próximo correlativo<input type="number" name="siguiente" min="1" max="999999" required value="<?=(int)$pfCfg['siguiente']?>"></label>
  <label>Validez (días)<input type="number" name="validez" min="1" max="365" required value="<?=(int)$pfCfg['validez']?>"></label>
  <label>Idioma predeterminado<?=docLangSelect('idioma',docLang($pfCfg['idioma']))?></label>
  <label>Moneda predeterminada<?=docMonedaSelect('moneda',docMoneda($pfCfg['moneda']))?></label>
  <label>Impuesto / IVA (%)<input type="number" name="iva" min="0" max="100" step="0.1" value="<?=e(ocNumIn((float)$pfCfg['iva']))?>"></label>
  <label>Anticipo (%)<input type="number" name="anticipo" min="0" max="100" step="1" value="<?=e(ocNumIn((float)$pfCfg['anticipo']))?>"></label>
</div><p class="hint">Próximo número: <b><?=e(pfNextNumero($pfCfg))?></b>.</p></section>
<?php foreach(['en'=>'Textos en inglés','es'=>'Textos en español'] as $l=>$titulo): $tx=$pfCfg[$l]; $conds=$tx['condiciones']; for($n=0;$n<2;$n++) $conds[]=['k'=>'','v'=>''];
$firmas=$tx['firmas']; while(count($firmas)<2) $firmas[]=['rol'=>$l==='en'?['Authorized signature','Buyer acceptance'][count($firmas)]:['Firma autorizada','Aceptación del comprador'][count($firmas)],'nombre'=>'','cargo'=>''];?>
<details class="card cfg-more"<?=$l==='en'?' open':''?>><summary><h2><?=$l==='en'?ic('globe'):''?><?=e($titulo)?></h2><span class="muted">Condiciones de pago, condiciones, declaración y firmas de las proformas en <?=$l==='en'?'inglés':'español'?>.</span></summary>
<label class="oc-obs">Condiciones de pago<textarea name="<?=$l?>_pago" maxlength="600" rows="3"><?=e($tx['pago'])?></textarea></label>
<div class="oc-conds"><?php foreach($conds as $cc):?><div class="oc-cond"><label><span class="sr">Condición</span><input name="<?=$l?>_co_k[]" maxlength="60" value="<?=e($cc['k'])?>"></label><label><span class="sr">Texto</span><input name="<?=$l?>_co_v[]" maxlength="400" value="<?=e($cc['v'])?>"></label></div><?php endforeach?></div>
<label class="oc-obs">Declaración al pie<textarea name="<?=$l?>_declaracion" maxlength="600" rows="2"><?=e($tx['declaracion'])?></textarea></label>
<div class="oc-firmas"><?php foreach(array_slice($firmas,0,2) as $f):?><fieldset><legend><?=e($f['rol'])?></legend><input type="hidden" name="<?=$l?>_fi_rol[]" value="<?=e($f['rol'])?>"><label>Nombre<input name="<?=$l?>_fi_nombre[]" maxlength="80" value="<?=e($f['nombre'])?>"></label><label>Cargo<input name="<?=$l?>_fi_cargo[]" maxlength="80" value="<?=e($f['cargo'])?>"></label></fieldset><?php endforeach?></div>
</details>
<?php endforeach?>
<div class="oc-actions"><button class="primary" name="action" value="pf_cfg">Guardar configuración</button></div>
</form>
<?php endif?>
