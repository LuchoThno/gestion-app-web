<?php
// Pantallas del módulo de cotizaciones. Se incluye desde view.php ($mode empieza con "cot").
$cotCfg=cotCfg();
$cotEdit=$mode==='cot_new' || ($mode==='cot_doc' && $canEdit && $g('edit')==='1');
$cotDoc=$mode==='cot_doc' ? cotGet($cotId) : false;
$cotPill=fn(array $c): string => cotVencida($c) ? '<span class="oc-pill st-vencida" title="Enviada; la validez terminó el '.e(ocDate($c['vence'],'')).'">Vencida</span>' : '<span class="oc-pill '.ocEstadoClass($c['estado']).'">'.e($c['estado']).'</span>';

/** Formulario del editor de cotizaciones (sin JavaScript: filas vacías extra para ítems y condiciones). */
$cotForm=function(array $c, int $id) use($cotCfg): void {
    $clients=query('SELECT id,razon,rut FROM cot_clients ORDER BY razon COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
    $items=$c['items']; for($i=count($items), $n=0; $n<3 && $i<60; $i++, $n++) $items[]=['desc'=>'','unidad'=>'Servicio','cant'=>1,'precio'=>''];
    $conds=$c['condiciones']; for($i=count($conds), $n=0; $n<2 && $i<30; $i++, $n++) $conds[]=['k'=>'','v'=>''];
    $firmas=$c['firmas']?:$cotCfg['firmas'];
    $t=cotTotals($c); ?>
<form method="post" class="oc-form"><?=csrf()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="idioma_prev" value="<?=e($c['idioma'])?>">
<section class="card"><h2>Cotización</h2><div class="oc-grid">
  <label>Idioma del documento<?=docLangSelect('idioma',$c['idioma'])?></label>
  <label>Moneda<?=docMonedaSelect('moneda',$c['moneda'])?></label>
  <label>Tipo de cambio <small>(CLP por 1 unidad; solo USD/EUR)</small><input type="number" name="tc" min="0" step="any" value="<?=$c['tc']>0?e(docNumIn($c['tc'])):''?>" placeholder="<?=($d=(float)($cotCfg['tc'][$c['moneda']]??0))>0?e(docNumIn($d)):'Ej: 950'?>"></label>
  <label>N° cotización<input name="numero" maxlength="40" value="<?=e($c['numero'])?>" <?=$id?'required':'placeholder="'.e(cotNextNumero($cotCfg)).' (automático)"'?>></label>
  <label>Fecha de emisión<input type="date" name="fecha" value="<?=e($c['fecha'])?>"></label>
  <label>Validez (días)<input type="number" name="validez" min="0" max="365" step="1" value="<?=(int)$c['validez']?>"></label>
  <label>Estado<select name="estado"><?php foreach(COT_ESTADOS as $s):?><option<?=$c['estado']===$s?' selected':''?>><?=e($s)?></option><?php endforeach?></select></label>
  <label>Proyecto / centro de costo<input name="ceco" maxlength="120" list="cot-projects" value="<?=e($c['ceco'])?>" placeholder="<?=e(txEmp('Ej: Curso OMI 2026','Ej: Inspección ROV Terminal Mardones'))?>"></label>
  <label>IVA (%)<input type="number" name="iva" min="0" max="100" step="0.1" value="<?=e(ocNumIn($c['iva']))?>"></label>
  <label>Descuento (%)<input type="number" name="descuento" min="0" max="100" step="0.1" value="<?=e(ocNumIn($c['descuento']))?>"></label>
</div><datalist id="cot-projects"><?php foreach(query('SELECT name FROM projects ORDER BY name COLLATE NOCASE')->fetchAll(PDO::FETCH_COLUMN) as $pn):?><option value="<?=e($pn)?>"><?php endforeach?></datalist>
<p class="hint">La fecha de vencimiento se calcula con la fecha de emisión y los días de validez<?=$c['vence']!==''?' (actual: <b>'.e(ocDate($c['vence'])).'</b>)':''?>. Usa IVA 0 para servicios exentos o de exportación.</p>
<details class="hint-more"><summary>¿Qué cambia con el idioma y la moneda?</summary><p class="hint">El idioma cambia solo la hoja que se imprime (títulos, fechas y montos); lo que escribes en descripciones y condiciones se imprime tal cual. Si cambias el idioma y las condiciones o firmas siguen siendo las predeterminadas, al guardar se reemplazan por las del otro idioma. En USD o EUR los precios admiten decimales; el tipo de cambio solo sirve para sumar esta cotización en pesos en el Dashboard y los presupuestos (no se imprime). Sin tipo de cambio, la cotización no suma allí.</p></details></section>

<section class="card"><h2>Cliente</h2>
<?php if($clients):?><label class="oc-saved">Usar un cliente guardado<select name="cli_saved"><option value="0">— Escribir los datos abajo —</option><?php foreach($clients as $s):?><option value="<?=(int)$s['id']?>"><?=e($s['razon'].($s['rut']!==''?' · '.$s['rut']:''))?></option><?php endforeach?></select><small>Si eliges uno, al guardar se completan sus datos y reemplazan lo escrito abajo.</small></label><?php endif?>
<div class="oc-grid">
  <label class="span2">Razón social<input name="cli_razon" maxlength="300" value="<?=e($c['cliente']['razon'])?>"></label>
  <label>R.U.T. / Tax ID<input name="cli_rut" maxlength="300" value="<?=e($c['cliente']['rut'])?>"></label>
  <label>Giro<input name="cli_giro" maxlength="300" value="<?=e($c['cliente']['giro'])?>"></label>
  <label class="span2">Dirección<input name="cli_direccion" maxlength="300" value="<?=e($c['cliente']['direccion'])?>"></label>
  <label>Comuna / ciudad<input name="cli_ciudad" maxlength="300" value="<?=e($c['cliente']['ciudad'])?>"></label>
  <label>País<input name="cli_pais" maxlength="300" value="<?=e($c['cliente']['pais'])?>" placeholder="Ej: Argentina"></label>
  <label>Contacto (atención)<input name="cli_contacto" maxlength="300" value="<?=e($c['cliente']['contacto'])?>"></label>
  <label>Teléfono<input name="cli_telefono" maxlength="300" value="<?=e($c['cliente']['telefono'])?>"></label>
  <label>Correo<input name="cli_email" maxlength="300" value="<?=e($c['cliente']['email'])?>"></label>
</div><p class="hint">Al guardar, el cliente queda en la lista para las próximas cotizaciones.</p></section>

<section class="card"><h2>Servicio y ejecución</h2><div class="oc-grid">
  <label class="span2">Objeto de la cotización<input name="obj_titulo" maxlength="160" value="<?=e($c['objeto']['titulo'])?>" placeholder="<?=e(txEmp('Ej: Curso de supervivencia en el mar','Ej: Inspección ROV de casco y líneas de fondeo'))?>"></label>
  <label class="span2">Detalle del objeto<input name="obj_detalle" maxlength="300" value="<?=e($c['objeto']['detalle'])?>"></label>
  <label><?=txEmp('Modalidad','Tipo de trabajo')?><input name="sv_modalidad" maxlength="60" list="cot-modalidad" value="<?=e($c['servicio']['modalidad'])?>"></label>
  <label><?=txEmp('Participantes','Nave o instalación')?><input name="sv_participantes" maxlength="80" value="<?=e($c['servicio']['participantes'])?>" placeholder="<?=e(txEmp('Ej: 12','Ej: M/N Skua, muelle 2'))?>"></label>
  <label class="span2">Lugar de ejecución<input name="sv_lugar" maxlength="300" value="<?=e($c['servicio']['lugar'])?>"></label>
  <label>Desde<input type="date" name="sv_desde" value="<?=e($c['servicio']['desde'])?>"></label>
  <label>Hasta<input type="date" name="sv_hasta" value="<?=e($c['servicio']['hasta'])?>"></label>
</div><datalist id="cot-modalidad"><?php foreach(empresa()['cursos']?['Presencial','En línea','Mixta']:($c['idioma']==='en'?['ROV inspection','Hull inspection','Mooring inspection','Underwater works','Search and recovery','Survey']:['Inspección ROV','Inspección de casco','Inspección de fondeos','Trabajos submarinos','Búsqueda y recuperación','Levantamiento']) as $mo):?><option value="<?=e($mo)?>"><?php endforeach?></datalist></section>

<section class="card"><h2>Detalle (ítems)</h2>
<div class="oc-items"><div class="oc-item oc-item-head"><span>Descripción</span><span>Unidad</span><span>Cant.</span><span>Precio unit. (neto, <?=e($c['moneda'])?>)</span></div>
<?php foreach($items as $it):?><div class="oc-item">
  <label><span class="sr">Descripción</span><input name="it_desc[]" maxlength="500" value="<?=e($it['desc'])?>" placeholder="Descripción"<?=empresa()['cursos']?'':' list="rov-items"'?>></label>
  <label><span class="sr">Unidad</span><input name="it_unidad[]" maxlength="30" value="<?=e($it['unidad'])?>"></label>
  <label><span class="sr">Cantidad</span><input type="number" name="it_cant[]" min="0" step="any" value="<?=e(ocNumIn($it['cant']))?>"></label>
  <label><span class="sr">Precio unitario neto</span><input type="number" name="it_precio[]" min="0" step="any" value="<?=e(docNumIn($it['precio']))?>" placeholder="0"></label>
</div><?php endforeach?></div>
<?=docRovDatalist('rov-items',$c['idioma'])?><p class="hint">Se ignoran las filas sin descripción ni precio; para quitar un ítem, borra ambos.<?=empresa()['cursos']?'':' Al escribir la descripción se sugieren ítems frecuentes de ROV en el idioma de la cotización.'?> Usa «Guardar y seguir editando» para obtener más filas vacías.<?php if($c['items']):?> <?php $M=fn($v)=>money($v,$c['moneda'])?><b>Guardado: <?=$t['descuento']>0?'descuento '.$M($t['descuento']).' · ':''?>neto <?=$M($t['neto'])?> · IVA <?=$M($t['iva'])?> · total <?=$M($t['total'])?></b><?php endif?></p></section>

<section class="card"><h2>Condiciones comerciales y observaciones</h2>
<div class="oc-conds"><?php foreach($conds as $cc):?><div class="oc-cond"><label><span class="sr">Condición</span><input name="co_k[]" maxlength="60" value="<?=e($cc['k'])?>" placeholder="Ej: Forma de pago"></label><label><span class="sr">Texto</span><input name="co_v[]" maxlength="400" value="<?=e($cc['v'])?>"></label></div><?php endforeach?></div>
<p class="hint">Deja ambas casillas vacías para quitar una condición.</p>
<label class="oc-obs">Observaciones<textarea name="obs" maxlength="1500" rows="3"><?=e($c['obs'])?></textarea></label></section>

<section class="card"><h2>Firmas</h2><div class="oc-firmas">
<?php foreach($firmas as $f):?><fieldset><legend><?=e($f['rol'])?></legend><input type="hidden" name="fi_rol[]" value="<?=e($f['rol'])?>">
  <label>Nombre<input name="fi_nombre[]" maxlength="80" value="<?=e($f['nombre'])?>"></label><label>Cargo<input name="fi_cargo[]" maxlength="80" value="<?=e($f['cargo'])?>"></label></fieldset><?php endforeach?>
</div></section>

<div class="oc-actions"><button class="primary">Guardar</button><button class="ghost" name="after" value="edit">Guardar y seguir editando</button><a class="btn ghost" href="<?=e($id?url(['cot'=>$id]):url(['tab'=>'cot']))?>">Cancelar</a></div>
<input type="hidden" name="action" value="cot_save">
</form>
<?php };

// Si el guardado falló, se muestra lo que el usuario escribió.
$posted=($_SERVER['REQUEST_METHOD']==='POST' && post('action')==='cot_save' && $error) ? cotNormalize(cotFromPost()) : null;
$cotFiltros=[...COT_ESTADOS,'Vencida'];
?>

<?php if($mode==='cot'):
$q=$g('q'); $fe=in_array($g('estado'),$cotFiltros,true)?$g('estado'):'';
[$where,$params]=cotFilter($q,$fe);
$list=query('SELECT id,data FROM cot_quotes'.$where.' ORDER BY id DESC LIMIT 500',$params)->fetchAll(PDO::FETCH_ASSOC);
$k=query("SELECT COUNT(*) AS n, SUM(moneda<>'CLP') AS nx, COALESCE(SUM(CASE WHEN estado='Enviada' AND (vence='' OR vence>=?) THEN total END),0) AS espera, COALESCE(SUM(CASE WHEN estado='Aceptada' THEN total END),0) AS aceptado, SUM(estado='Aceptada') AS na, SUM(estado='Rechazada') AS nr FROM cot_quotes",[ocToday()])->fetch(PDO::FETCH_ASSOC);
$tasa=((int)$k['na']+(int)$k['nr'])>0 ? (int)round(100*(int)$k['na']/((int)$k['na']+(int)$k['nr'])) : null;
$ncli=(int)query('SELECT COUNT(*) FROM cot_clients')->fetchColumn();
?>
<div class="page-head"><div><span class="eyebrow muted">VENTAS</span><h1>Cotizaciones</h1><p class="subtitle">Cotizaciones enviadas a clientes. Cada una se imprime o guarda como PDF en formato carta.</p></div>
<div class="head-actions"><?php if($isAdmin):?><a class="btn ghost" href="<?=e(url(['tab'=>'cot_cfg']))?>"><?=ic('settings')?>Configuración</a><?php endif?><a class="btn ghost" href="<?=e(url(['cot_csv'=>1]+($q!==''?['q'=>$q]:[])+($fe?['estado'=>$fe]:[])))?>"><?=ic('download')?>Exportar CSV</a><?php if($canEdit && pfOn()):?><details class="pop"><summary class="btn primary"><?=ic('plus')?>Nueva cotización</summary><div class="pop-body"><strong>Idioma de la cotización</strong><a class="btn ghost" href="<?=e(url(['tab'=>'cot_new']))?>">Español · CLP</a><a class="btn ghost" href="<?=e(url(['tab'=>'cot_new','lang'=>'en']))?>"><?=ic('globe')?>English · <?=e(docMoneda($cotCfg['en']['moneda']))?></a><small class="muted">Puedes cambiar idioma y moneda dentro del formulario.</small></div></details><?php elseif($canEdit):?><a class="btn primary" href="<?=e(url(['tab'=>'cot_new']))?>"><?=ic('plus')?>Nueva cotización</a><?php endif?></div></div>
<div class="stat-grid">
  <div class="stat"><?=ic('quote')?><strong><?=(int)$k['n']?></strong><span>Cotizaciones registradas</span></div>
  <div class="stat"><?=ic('clock')?><strong><?=clp((int)$k['espera'])?></strong><span>En espera de respuesta (enviadas vigentes)<?=(int)$k['nx']>0?' · en pesos, con tipo de cambio':''?></span></div>
  <div class="stat"><?=ic('activity')?><strong><?=clp((int)$k['aceptado'])?></strong><span>Aceptado<?=$tasa!==null?' · '.$tasa.'% de las respondidas':''?></span></div>
  <div class="stat"><?=ic('users')?><strong><?=$ncli?></strong><span>Clientes</span></div>
</div>
<form method="get" class="oc-filter" role="search"><input type="hidden" name="tab" value="cot"><label class="grow"><span class="sr">Buscar</span><input name="q" value="<?=e($q)?>" placeholder="Buscar por N°, cliente, objeto o proyecto…"></label><label><span class="sr">Estado</span><select name="estado"><option value="">Todos los estados</option><?php foreach($cotFiltros as $s):?><option<?=$fe===$s?' selected':''?>><?=e($s)?></option><?php endforeach?></select></label><button class="ghost"><?=ic('search')?>Filtrar</button><?php if($q!==''||$fe):?><a class="btn ghost" href="<?=e(url(['tab'=>'cot']))?>">Limpiar</a><?php endif?></form>
<?php if($list):?><div class="table-wrap"><table class="oc-reg"><thead><tr><th>N° cotización</th><th class="hide-sm">Fecha</th><th>Cliente</th><th class="hide-sm">Objeto</th><th class="right">Total</th><th>Estado</th><th class="right">Acciones</th></tr></thead><tbody>
<?php foreach($list as $row): $r=cotNormalize(json_decode($row['data'],true)?:[]); $rid=(int)$row['id']; $t=cotTotals($r);?><tr>
  <td class="nowrap"><a class="oc-num" href="<?=e(url(['cot'=>$rid]))?>"><?=e($r['numero'])?></a> <?=docTag($r['idioma'],$r['moneda'])?><?php if($r['ceco']!==''):?><small><?=e($r['ceco'])?></small><?php endif?></td>
  <td class="hide-sm nowrap"><?=e(ocDate($r['fecha'],'—'))?><?php if($r['vence']!==''):?><small>Vence <?=e(ocDate($r['vence'],''))?></small><?php endif?></td><td><?=e($r['cliente']['razon']?:'—')?></td><td class="hide-sm"><?=e($r['objeto']['titulo']?:'—')?></td>
  <td class="right nowrap"><b><?=money($t['total'],$r['moneda'])?></b><?php if($r['moneda']!=='CLP'):?><small><?=$t['clp']>0?'≈ '.clp($t['clp']):'sin tipo de cambio'?></small><?php endif?></td>
  <td class="nowrap"><?php if($canEdit):?><form method="post" class="oc-state"><?=csrf()?><input type="hidden" name="id" value="<?=$rid?>"><label><span class="sr">Estado de <?=e($r['numero'])?></span><select name="estado" class="<?=cotVencida($r)?'st-vencida':ocEstadoClass($r['estado'])?>"><?php foreach(COT_ESTADOS as $s):?><option value="<?=e($s)?>"<?=$r['estado']===$s?' selected':''?>><?=e($s==='Enviada'&&cotVencida($r)?'Enviada (vencida)':$s)?></option><?php endforeach?></select></label><button class="ghost small" name="action" value="cot_estado" title="Aplicar estado">✓<span class="sr">Aplicar estado</span></button></form><?php else:?><?=$cotPill($r)?><?php endif?></td>
  <td class="right nowrap"><a class="icon-btn" href="<?=e(url(['cot'=>$rid]))?>" title="Abrir"><?=ic('eye')?><span class="sr">Abrir</span></a><?php if($canEdit):?><a class="icon-btn" href="<?=e(url(['tab'=>'cot_new','from'=>$rid]))?>" title="Duplicar"><?=ic('copy')?><span class="sr">Duplicar</span></a><?php endif?><a class="icon-btn" href="<?=e(url(['cot_print'=>$rid]))?>" target="_blank" rel="noopener" title="Imprimir / PDF"><?=ic('print')?><span class="sr">Imprimir / PDF</span></a></td>
</tr><?php endforeach?></tbody></table></div>
<?php if(count($list)>=500):?><p class="hint">Se muestran las 500 más recientes; usa la búsqueda para encontrar otras.</p><?php endif?>
<?php else:?><div class="empty-state"><?=ic('quote')?><b><?=($q!==''||$fe)?'No hay cotizaciones que coincidan':'Todavía no hay cotizaciones'?></b><span><?=$canEdit?'Crea la primera con «Nueva cotización».':'Cuando un editor cree una cotización, aparecerá aquí.'?></span></div><?php endif?>

<?php elseif($mode==='cot_new'):
$src=cotGet((int)$g('from'));
if($posted) $c=$posted;
elseif($src){ $c=$src; unset($c['id'],$c['owner'],$c['created'],$c['updated']); $c['numero']=''; $c['fecha']=ocToday(); $c['vence']=cotVence($c['fecha'],$c['validez']); $c['estado']='Borrador'; $c['emisor']=$cotCfg['emisor']; }
else { $lang=docLang($g('lang')); $ld=cotLangDefaults($cotCfg,$lang);
    $c=cotNormalize(['fecha'=>ocToday(),'validez'=>$cotCfg['validez'],'estado'=>'Borrador','iva'=>$ld['iva'],'idioma'=>$lang,'moneda'=>$ld['moneda'],'tc'=>$cotCfg['tc'][$ld['moneda']]??0,'items'=>[],'condiciones'=>$ld['condiciones'],'firmas'=>$ld['firmas']]); }
?>
<nav class="crumbs" aria-label="Ruta"><a href="<?=e(url(['tab'=>'cot']))?>">Cotizaciones</a><?=ic('chevron')?><span><?=$src?'Copia de '.e($src['numero']):'Nueva cotización'?></span></nav>
<div class="page-head"><div><span class="eyebrow muted">VENTAS</span><h1><?=$src?'Duplicar '.e($src['numero']):'Nueva cotización'?></h1><p class="subtitle">El número se asigna al guardar (próximo: <?=e(cotNextNumero($cotCfg))?>). Puedes escribir otro si lo necesitas.</p></div></div>
<?php $cotForm($c,0)?>

<?php elseif($mode==='cot_doc' && !$cotDoc):?>
<div class="empty-state"><?=ic('search')?><b>Cotización no encontrada</b><span><a href="<?=e(url(['tab'=>'cot']))?>">Volver al registro</a></span></div>

<?php elseif($mode==='cot_doc' && $cotEdit):?>
<nav class="crumbs" aria-label="Ruta"><a href="<?=e(url(['tab'=>'cot']))?>">Cotizaciones</a><?=ic('chevron')?><a href="<?=e(url(['cot'=>$cotDoc['id']]))?>"><?=e($cotDoc['numero'])?></a><?=ic('chevron')?><span>Editar</span></nav>
<div class="page-head"><div><span class="eyebrow muted">VENTAS</span><h1>Editar <?=e($cotDoc['numero'])?></h1><p class="subtitle">Al guardar verás la cotización en formato carta, lista para imprimir.</p></div></div>
<?php $cotForm($posted??$cotDoc,$cotDoc['id'])?>

<?php elseif($mode==='cot_doc'): $t=cotTotals($cotDoc);?>
<nav class="crumbs no-print" aria-label="Ruta"><a href="<?=e(url(['tab'=>'cot']))?>">Cotizaciones</a><?=ic('chevron')?><span><?=e($cotDoc['numero'])?></span></nav>
<div class="page-head no-print">
  <div class="title-row"><a class="icon-btn" href="<?=e(url(['tab'=>'cot']))?>" title="Volver"><?=ic('back')?><span class="sr">Volver</span></a><span class="tile-icon big tone1"><?=ic('quote')?></span><div><h1><?=e($cotDoc['numero'])?> <?=$cotPill($cotDoc)?></h1><p class="subtitle"><?=e($cotDoc['cliente']['razon']?:'Sin cliente')?> · <?=money($t['total'],$cotDoc['moneda'])?><?=$cotDoc['idioma']==='en'?' · en inglés':''?> · <?=$cotDoc['vence']!==''?'válida hasta el '.e(ocDate($cotDoc['vence'])).' · ':''?>actualizada el <?=e(fdate($cotDoc['updated'],true))?></p></div></div>
  <div class="head-actions">
    <a class="btn primary" href="<?=e(url(['cot_print'=>$cotDoc['id']]))?>" target="_blank" rel="noopener"><?=ic('print')?>Imprimir / PDF</a>
    <?php if($canEdit):?><a class="btn ghost" href="<?=e(url(['cot'=>$cotDoc['id'],'edit'=>1]))?>"><?=ic('edit')?>Editar</a><?php if(!pfOn()):?><a class="btn ghost" href="<?=e(url(['tab'=>'cot_new','from'=>$cotDoc['id']]))?>"><?=ic('copy')?>Duplicar</a><?php endif?>
    <?php if(pfOn()):?><form method="post" class="inline-form"><?=csrf()?><input type="hidden" name="id" value="<?=$cotDoc['id']?>"><button class="ghost" name="action" value="pf_from_cot" title="Crea una proforma en borrador con el cliente, los ítems, el idioma y la moneda de esta cotización"><?=ic('receipt')?>Crear proforma</button></form><?php endif?>
    <details class="pop"><summary class="btn ghost">Estado</summary><form method="post" class="pop-body"><?=csrf()?><input type="hidden" name="id" value="<?=$cotDoc['id']?>"><input type="hidden" name="back" value="cot"><strong>Cambiar estado</strong><label>Estado<select name="estado"><?php foreach(COT_ESTADOS as $s):?><option<?=$cotDoc['estado']===$s?' selected':''?>><?=e($s)?></option><?php endforeach?></select></label><button class="primary" name="action" value="cot_estado">Aplicar</button></form></details><?php endif?>
    <?php if($canEdit && pfOn()):?><details class="pop"><summary class="btn ghost" title="Más opciones"><?=ic('more')?><span class="sr">Más opciones</span></summary><div class="pop-body"><a class="btn ghost" href="<?=e(url(['tab'=>'cot_new','from'=>$cotDoc['id']]))?>"><?=ic('copy')?>Duplicar cotización</a><?php if($isAdmin):?><form method="post" class="danger-zone stack"><?=csrf()?><input type="hidden" name="id" value="<?=$cotDoc['id']?>"><strong>Eliminar <?=e($cotDoc['numero'])?></strong><p>La eliminación es definitiva. Si la cotización ya se envió al cliente, considera marcarla como Anulada.</p><button class="danger" name="action" value="cot_delete"><?=ic('trash')?>Eliminar cotización</button></form><?php endif?></div></details>
    <?php elseif($isAdmin):?><details class="pop"><summary class="btn ghost" title="Más opciones"><?=ic('more')?><span class="sr">Más opciones</span></summary><form method="post" class="pop-body danger-zone"><?=csrf()?><input type="hidden" name="id" value="<?=$cotDoc['id']?>"><strong>Eliminar <?=e($cotDoc['numero'])?></strong><p>La eliminación es definitiva. Si la cotización ya se envió al cliente, considera marcarla como Anulada.</p><button class="danger" name="action" value="cot_delete"><?=ic('trash')?>Eliminar cotización</button></form></details><?php endif?>
  </div>
</div>
<?php if(cotVencida($cotDoc)):?><div class="alert no-print" role="status">La validez de esta cotización terminó el <?=e(ocDate($cotDoc['vence']))?>. Si el cliente aún la quiere, duplícala para emitir una nueva con fecha actual.</div><?php endif?>
<?php if($cuO=cuQuoteOrigin((int)$cotDoc['id'])):?><p class="ps-note no-print"><?=ic('list')?>Generada desde el curso <a href="<?=e(url(['curso'=>(int)$cuO['id']]))?>"><?=e($cuO['nombre'])?></a> (<?=e($cuO['codigo'])?>, versión <?=(int)$cuO['version']?>).</p><?php endif?>
<?php if(pfOn() && ($pfs=query('SELECT id,numero,estado,data FROM pf_invoices WHERE cot_id=? ORDER BY id DESC',[$cotDoc['id']])->fetchAll(PDO::FETCH_ASSOC))):?><p class="ps-note no-print"><?=ic('receipt')?>Proformas de esta cotización: <?php foreach($pfs as $i=>$pf):?><?=$i?', ':''?><a href="<?=e(url(['pf'=>(int)$pf['id']]))?>"><?=e($pf['numero'])?></a> (<?=e($pf['estado'])?>)<?php endforeach?>.</p><?php endif?>
<div class="sheet-wrap"><?php cotSheet($cotDoc)?></div>
<p class="hint no-print">También puedes imprimir esta página directamente con Ctrl + P (Cmd + P en Mac): solo se imprime la hoja.</p>

<?php elseif($mode==='cot_cfg'): $cli=query('SELECT * FROM cot_clients ORDER BY razon COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
$conds=$cotCfg['condiciones']; for($n=0;$n<2;$n++) $conds[]=['k'=>'','v'=>''];
$firmas=$cotCfg['firmas']; while(count($firmas)<2) $firmas[]=['rol'=>['Preparada por','Aceptación cliente'][count($firmas)],'nombre'=>'','cargo'=>''];
?>
<nav class="crumbs" aria-label="Ruta"><a href="<?=e(url(['tab'=>'cot']))?>">Cotizaciones</a><?=ic('chevron')?><span>Configuración</span></nav>
<div class="page-head"><div><span class="eyebrow muted">ADMINISTRACIÓN</span><h1>Configuración de cotizaciones</h1><p class="subtitle">Estos datos se usan en las próximas cotizaciones; las ya guardadas conservan los suyos.</p></div></div>
<form method="post" class="oc-form"><?=csrf()?>
<section class="card"><h2>Empresa emisora</h2><div class="oc-grid">
  <label class="span2">Razón social<input name="em_razon" maxlength="300" value="<?=e($cotCfg['emisor']['razon'])?>"></label>
  <label>R.U.T.<input name="em_rut" maxlength="300" value="<?=e($cotCfg['emisor']['rut'])?>"></label>
  <label>Giro<input name="em_giro" maxlength="300" value="<?=e($cotCfg['emisor']['giro'])?>"></label>
  <label class="span2">Dirección<input name="em_direccion" maxlength="300" value="<?=e($cotCfg['emisor']['direccion'])?>"></label>
  <label>Correo<input name="em_email" maxlength="300" value="<?=e($cotCfg['emisor']['email'])?>"></label>
  <label>Sitio web<input name="em_web" maxlength="300" value="<?=e($cotCfg['emisor']['web'])?>"></label>
</div></section>
<section class="card"><h2>Numeración, IVA y validez</h2><div class="oc-grid">
  <label>Prefijo<input name="prefijo" maxlength="30" value="<?=e($cotCfg['prefijo'])?>"></label>
  <label>Próximo correlativo<input type="number" name="siguiente" min="1" max="999999" required value="<?=(int)$cotCfg['siguiente']?>"></label>
  <label>IVA predeterminado (%)<input type="number" name="iva" min="0" max="100" step="0.1" value="<?=e(ocNumIn($cotCfg['iva']))?>"></label>
  <label>Validez predeterminada (días)<input type="number" name="validez" min="1" max="365" required value="<?=(int)$cotCfg['validez']?>"></label>
</div><p class="hint">Próximo número que se asignará: <b><?=e(cotNextNumero($cotCfg))?></b>. Si ese número ya existe, se salta al siguiente libre.</p></section>
<section class="card"><h2>Tipo de cambio de referencia</h2><div class="oc-grid">
<?php foreach(DOC_MONEDAS as $mk=>$mv): if($mk==='CLP') continue;?><label>CLP por 1 <?=e($mk)?><input type="number" name="tc_<?=e($mk)?>" min="0" step="any" value="<?=($v=(float)($cotCfg['tc'][$mk]??0))>0?e(docNumIn($v)):''?>" placeholder="Sin definir"></label><?php endforeach?>
</div><p class="hint">Se propone en las cotizaciones nuevas en otra moneda, para sumarlas en pesos en el Dashboard y los presupuestos. Cada cotización guarda el suyo; cambiarlo aquí no modifica las ya guardadas.</p></section>
<section class="card"><h2>Firmas predeterminadas</h2><div class="oc-firmas">
<?php foreach(array_slice($firmas,0,2) as $f):?><fieldset><legend><?=e($f['rol'])?></legend><input type="hidden" name="fi_rol[]" value="<?=e($f['rol'])?>"><label>Nombre<input name="fi_nombre[]" maxlength="80" value="<?=e($f['nombre'])?>"></label><label>Cargo<input name="fi_cargo[]" maxlength="80" value="<?=e($f['cargo'])?>"></label></fieldset><?php endforeach?>
</div></section>
<section class="card"><h2>Condiciones predeterminadas</h2><div class="oc-conds"><?php foreach($conds as $cc):?><div class="oc-cond"><label><span class="sr">Condición</span><input name="co_k[]" maxlength="60" value="<?=e($cc['k'])?>" placeholder="Ej: Forma de pago"></label><label><span class="sr">Texto</span><input name="co_v[]" maxlength="400" value="<?=e($cc['v'])?>"></label></div><?php endforeach?></div><p class="hint">Deja ambas casillas vacías para quitar una condición. Guarda para obtener más filas.</p></section>
<?php $en=$cotCfg['en']; $conds=$en['condiciones']; for($n=0;$n<2;$n++) $conds[]=['k'=>'','v'=>''];
$firmas=$en['firmas']; while(count($firmas)<2) $firmas[]=['rol'=>['Prepared by','Client acceptance'][count($firmas)],'nombre'=>'','cargo'=>''];?>
<details class="card cfg-more"<?=pfOn()?' open':''?>><summary><h2><?=ic('globe')?>Cotizaciones en inglés</h2><span class="muted">Moneda, IVA, firmas y condiciones que se proponen al crear una cotización en inglés.</span></summary>
<div class="oc-grid"><label>Moneda predeterminada<?=docMonedaSelect('en_moneda',docMoneda($en['moneda']))?></label><label>IVA predeterminado (%)<input type="number" name="en_iva" min="0" max="100" step="0.1" value="<?=e(ocNumIn((float)$en['iva']))?>"></label></div>
<div class="oc-firmas"><?php foreach(array_slice($firmas,0,2) as $f):?><fieldset><legend><?=e($f['rol'])?></legend><input type="hidden" name="fe_rol[]" value="<?=e($f['rol'])?>"><label>Name<input name="fe_nombre[]" maxlength="80" value="<?=e($f['nombre'])?>"></label><label>Position<input name="fe_cargo[]" maxlength="80" value="<?=e($f['cargo'])?>"></label></fieldset><?php endforeach?></div>
<div class="oc-conds"><?php foreach($conds as $cc):?><div class="oc-cond"><label><span class="sr">Condition</span><input name="ce_k[]" maxlength="60" value="<?=e($cc['k'])?>" placeholder="E.g. Payment terms"></label><label><span class="sr">Text</span><input name="ce_v[]" maxlength="400" value="<?=e($cc['v'])?>"></label></div><?php endforeach?></div>
<p class="hint">Escríbelas directamente en inglés: se imprimen tal cual. Deja ambas casillas vacías para quitar una condición.</p></details>
<div class="oc-actions"><button class="primary" name="action" value="cot_cfg">Guardar configuración</button></div>
</form>
<section class="card"><h2>Clientes guardados</h2>
<?php if($cli):?><div class="table-wrap"><table><thead><tr><th>Cliente</th><th class="hide-sm">Contacto</th><th class="right">Acción</th></tr></thead><tbody><?php foreach($cli as $s):?><tr><td><b><?=e($s['razon'])?></b><small><?=e(implode(' · ',array_filter([$s['rut'],$s['ciudad']],'strlen')))?></small></td><td class="hide-sm"><?=e(implode(' · ',array_filter([$s['contacto'],$s['email'],$s['telefono']],'strlen')))?></td><td class="right"><form method="post"><?=csrf()?><input type="hidden" name="id" value="<?=(int)$s['id']?>"><button class="ghost small" name="action" value="cot_client_delete">Quitar</button></form></td></tr><?php endforeach?></tbody></table></div>
<p class="hint">Los clientes se agregan o actualizan solos al guardar una cotización. Quitar uno no modifica las cotizaciones existentes.</p>
<?php else:?><p class="hint">Sin clientes guardados. Se agregan solos al guardar la primera cotización.</p><?php endif?></section>
<?php endif?>
