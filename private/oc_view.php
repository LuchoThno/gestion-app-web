<?php
// Pantallas del módulo de órdenes de compra. Se incluye desde view.php ($mode empieza con "oc").
$ocCfg=ocCfg();
$ocEdit=$mode==='oc_new' || ($mode==='oc_doc' && $canEdit && $g('edit')==='1');
$ocDoc=$mode==='oc_doc' ? ocGet($ocId) : false;
$pill=fn(string $s): string => '<span class="oc-pill '.ocEstadoClass($s).'">'.e($s).'</span>';

/** Formulario del editor de OC (sin JavaScript: filas vacías extra para ítems y condiciones). */
$ocForm=function(array $o, int $id) use($ocCfg): void {
    $suppliers=query('SELECT id,razon,rut FROM oc_suppliers ORDER BY razon COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
    $items=$o['items']; for($i=count($items), $n=0; $n<3 && $i<60; $i++, $n++) $items[]=['desc'=>'','unidad'=>'Servicio','cant'=>1,'precio'=>''];
    $conds=$o['condiciones']; for($i=count($conds), $n=0; $n<2 && $i<30; $i++, $n++) $conds[]=['k'=>'','v'=>''];
    $firmas=$o['firmas']?:$ocCfg['firmas'];
    $t=ocTotals($o); ?>
<form method="post" class="oc-form"><?=csrf()?><input type="hidden" name="id" value="<?=$id?>">
<section class="card"><h2>Orden</h2><div class="oc-grid">
  <label>N° OC<input name="numero" maxlength="40" value="<?=e($o['numero'])?>" <?=$id?'required':'placeholder="'.e(ocNextNumero($ocCfg)).' (automático)"'?>></label>
  <label>Fecha de emisión<input type="date" name="fecha" value="<?=e($o['fecha'])?>"></label>
  <label>Cotización ref.<input name="cotizacion" maxlength="80" value="<?=e($o['cotizacion'])?>"></label>
  <label>Estado<select name="estado"><?php foreach(OC_ESTADOS as $s):?><option<?=$o['estado']===$s?' selected':''?>><?=e($s)?></option><?php endforeach?></select></label>
  <label>Centro de costo / proyecto<input name="ceco" maxlength="120" list="oc-projects" value="<?=e($o['ceco'])?>" placeholder="Ej: OTEC Puerto Saavedra"></label>
  <label>IVA (%)<input type="number" name="iva" min="0" max="100" step="0.1" value="<?=e(ocNumIn($o['iva']))?>"></label>
</div><datalist id="oc-projects"><?php foreach(query('SELECT name FROM projects ORDER BY name COLLATE NOCASE')->fetchAll(PDO::FETCH_COLUMN) as $pn):?><option value="<?=e($pn)?>"><?php endforeach?></datalist></section>

<section class="card"><h2>Proveedor</h2>
<?php if($suppliers):?><label class="oc-saved">Usar un proveedor guardado<select name="prov_saved"><option value="0">— Escribir los datos abajo —</option><?php foreach($suppliers as $s):?><option value="<?=(int)$s['id']?>"><?=e($s['razon'].($s['rut']!==''?' · '.$s['rut']:''))?></option><?php endforeach?></select><small>Si eliges uno, al guardar se completan sus datos y reemplazan lo escrito abajo.</small></label><?php endif?>
<div class="oc-grid">
  <label class="span2">Razón social<input name="prov_razon" maxlength="300" value="<?=e($o['proveedor']['razon'])?>"></label>
  <label>R.U.T.<input name="prov_rut" maxlength="300" value="<?=e($o['proveedor']['rut'])?>"></label>
  <label>Giro<input name="prov_giro" maxlength="300" value="<?=e($o['proveedor']['giro'])?>"></label>
  <label class="span2">Dirección<input name="prov_direccion" maxlength="300" value="<?=e($o['proveedor']['direccion'])?>"></label>
  <label>Comuna / ciudad<input name="prov_ciudad" maxlength="300" value="<?=e($o['proveedor']['ciudad'])?>"></label>
  <label>Teléfono<input name="prov_telefono" maxlength="300" value="<?=e($o['proveedor']['telefono'])?>"></label>
  <label class="span2">Correo<input name="prov_email" maxlength="300" value="<?=e($o['proveedor']['email'])?>"></label>
</div><p class="hint">Al guardar, el proveedor queda en la lista para las próximas OC.</p></section>

<section class="card"><h2>Entrega y objeto</h2><div class="oc-grid">
  <label class="span2">Lugar de entrega<input name="ent_lugar" maxlength="300" value="<?=e($o['entrega']['lugar'])?>"></label>
  <label>Desde<input type="date" name="ent_desde" value="<?=e($o['entrega']['desde'])?>"></label>
  <label>Hasta<input type="date" name="ent_hasta" value="<?=e($o['entrega']['hasta'])?>"></label>
  <label class="span2">Solicitante<input name="ent_solicitante" maxlength="100" value="<?=e($o['entrega']['solicitante'])?>"></label>
  <label class="span2">Objeto de la orden<input name="obj_titulo" maxlength="160" value="<?=e($o['objeto']['titulo'])?>"></label>
  <label class="span2">Detalle del objeto<input name="obj_detalle" maxlength="300" value="<?=e($o['objeto']['detalle'])?>"></label>
</div></section>

<section class="card"><h2>Detalle (ítems)</h2>
<div class="oc-items"><div class="oc-item oc-item-head"><span>Descripción</span><span>Unidad</span><span>Cant.</span><span>Precio unit. (neto)</span></div>
<?php foreach($items as $it):?><div class="oc-item">
  <label><span class="sr">Descripción</span><input name="it_desc[]" maxlength="500" value="<?=e($it['desc'])?>" placeholder="Descripción"></label>
  <label><span class="sr">Unidad</span><input name="it_unidad[]" maxlength="30" value="<?=e($it['unidad'])?>"></label>
  <label><span class="sr">Cantidad</span><input type="number" name="it_cant[]" min="0" step="any" value="<?=e(ocNumIn($it['cant']))?>"></label>
  <label><span class="sr">Precio unitario neto</span><input type="number" name="it_precio[]" min="0" step="1" value="<?=e($it['precio'])?>" placeholder="0"></label>
</div><?php endforeach?></div>
<p class="hint">Se ignoran las filas sin descripción ni precio; para quitar un ítem, borra ambos. Usa «Guardar y seguir editando» para obtener más filas vacías.<?php if($o['items']):?> <b>Guardado: neto <?=clp($t['neto'])?> · IVA <?=clp($t['iva'])?> · total <?=clp($t['total'])?></b><?php endif?></p></section>

<section class="card"><h2>Condiciones y observaciones</h2>
<div class="oc-conds"><?php foreach($conds as $c):?><div class="oc-cond"><label><span class="sr">Condición</span><input name="co_k[]" maxlength="60" value="<?=e($c['k'])?>" placeholder="Ej: Pago"></label><label><span class="sr">Texto</span><input name="co_v[]" maxlength="400" value="<?=e($c['v'])?>"></label></div><?php endforeach?></div>
<p class="hint">Deja ambas casillas vacías para quitar una condición.</p>
<label class="oc-obs">Observaciones<textarea name="obs" maxlength="1500" rows="3"><?=e($o['obs'])?></textarea></label></section>

<section class="card"><h2>Firmas</h2><div class="oc-firmas">
<?php foreach($firmas as $f):?><fieldset><legend><?=e($f['rol'])?></legend><input type="hidden" name="fi_rol[]" value="<?=e($f['rol'])?>">
  <label>Nombre<input name="fi_nombre[]" maxlength="80" value="<?=e($f['nombre'])?>"></label><label>Cargo<input name="fi_cargo[]" maxlength="80" value="<?=e($f['cargo'])?>"></label></fieldset><?php endforeach?>
</div></section>

<div class="oc-actions"><button class="primary">Guardar</button><button class="ghost" name="after" value="edit">Guardar y seguir editando</button><a class="btn ghost" href="<?=e($id?url(['oc'=>$id]):url(['tab'=>'oc']))?>">Cancelar</a></div>
<input type="hidden" name="action" value="oc_save">
</form>
<?php };

// Si el guardado falló, se muestra lo que el usuario escribió.
$posted=($_SERVER['REQUEST_METHOD']==='POST' && post('action')==='oc_save' && $error) ? ocNormalize(ocFromPost()) : null;
?>

<?php if($mode==='oc'):
$q=$g('q'); $fe=in_array($g('estado'),OC_ESTADOS,true)?$g('estado'):'';
[$where,$params]=ocFilter($q,$fe);
$list=query('SELECT id,numero,fecha,estado,prov_razon,obj_titulo,total,ceco FROM oc_orders'.$where.' ORDER BY id DESC LIMIT 500',$params)->fetchAll(PDO::FETCH_ASSOC);
$k=query("SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN estado NOT IN ('Anulada','Borrador') THEN total END),0) AS comprometido, COALESCE(SUM(CASE WHEN estado IN ('Emitida','Aceptada','Recibida') THEN total END),0) AS pendiente FROM oc_orders")->fetch(PDO::FETCH_ASSOC);
$nprov=(int)query('SELECT COUNT(*) FROM oc_suppliers')->fetchColumn();
?>
<div class="page-head"><div><span class="eyebrow muted">COMPRAS</span><h1>Órdenes de compra</h1><p class="subtitle">Registro de las OC emitidas por <?=e(empresa()['corto'])?>. Cada orden se imprime o guarda como PDF en formato carta.</p></div>
<div class="head-actions"><?php if($isAdmin):?><a class="btn ghost" href="<?=e(url(['tab'=>'oc_cfg']))?>"><?=ic('settings')?>Configuración</a><?php endif?><a class="btn ghost" href="<?=e(url(['oc_csv'=>1]+($q!==''?['q'=>$q]:[])+($fe?['estado'=>$fe]:[])))?>"><?=ic('download')?>Exportar CSV</a><?php if($canEdit):?><a class="btn primary" href="<?=e(url(['tab'=>'oc_new']))?>"><?=ic('plus')?>Nueva OC</a><?php endif?></div></div>
<div class="stat-grid">
  <div class="stat"><?=ic('cart')?><strong><?=(int)$k['n']?></strong><span>OC registradas</span></div>
  <div class="stat"><?=ic('activity')?><strong><?=clp((int)$k['comprometido'])?></strong><span>Monto comprometido</span></div>
  <div class="stat"><?=ic('clock')?><strong><?=clp((int)$k['pendiente'])?></strong><span>Por pagar (emitidas, aceptadas y recibidas)</span></div>
  <div class="stat"><?=ic('users')?><strong><?=$nprov?></strong><span>Proveedores</span></div>
</div>
<form method="get" class="oc-filter" role="search"><input type="hidden" name="tab" value="oc"><label class="grow"><span class="sr">Buscar</span><input name="q" value="<?=e($q)?>" placeholder="Buscar por N°, proveedor, objeto o centro de costo…"></label><label><span class="sr">Estado</span><select name="estado"><option value="">Todos los estados</option><?php foreach(OC_ESTADOS as $s):?><option<?=$fe===$s?' selected':''?>><?=e($s)?></option><?php endforeach?></select></label><button class="ghost"><?=ic('search')?>Filtrar</button><?php if($q!==''||$fe):?><a class="btn ghost" href="<?=e(url(['tab'=>'oc']))?>">Limpiar</a><?php endif?></form>
<?php if($list):?><div class="table-wrap"><table class="oc-reg"><thead><tr><th>N° OC</th><th class="hide-sm">Fecha</th><th>Proveedor</th><th class="hide-sm">Objeto</th><th class="right">Total</th><th>Estado</th><th class="right">Acciones</th></tr></thead><tbody>
<?php foreach($list as $r):?><tr>
  <td class="nowrap"><a class="oc-num" href="<?=e(url(['oc'=>(int)$r['id']]))?>"><?=e($r['numero'])?></a><?php if($r['ceco']!==''):?><small><?=e($r['ceco'])?></small><?php endif?></td>
  <td class="hide-sm nowrap"><?=e(ocDate($r['fecha'],'—'))?></td><td><?=e($r['prov_razon']?:'—')?></td><td class="hide-sm"><?=e($r['obj_titulo']?:'—')?></td>
  <td class="right nowrap"><b><?=clp((int)$r['total'])?></b></td>
  <td class="nowrap"><?php if($canEdit):?><form method="post" class="oc-state"><?=csrf()?><input type="hidden" name="id" value="<?=(int)$r['id']?>"><label><span class="sr">Estado de <?=e($r['numero'])?></span><select name="estado" class="<?=ocEstadoClass($r['estado'])?>"><?php foreach(OC_ESTADOS as $s):?><option<?=$r['estado']===$s?' selected':''?>><?=e($s)?></option><?php endforeach?></select></label><button class="ghost small" name="action" value="oc_estado" title="Aplicar estado">✓<span class="sr">Aplicar estado</span></button></form><?php else:?><?=$pill($r['estado'])?><?php endif?></td>
  <td class="right nowrap"><a class="icon-btn" href="<?=e(url(['oc'=>(int)$r['id']]))?>" title="Abrir"><?=ic('eye')?><span class="sr">Abrir</span></a><?php if($canEdit):?><a class="icon-btn" href="<?=e(url(['tab'=>'oc_new','from'=>(int)$r['id']]))?>" title="Duplicar"><?=ic('copy')?><span class="sr">Duplicar</span></a><?php endif?><a class="icon-btn" href="<?=e(url(['oc_print'=>(int)$r['id']]))?>" target="_blank" rel="noopener" title="Imprimir / PDF"><?=ic('print')?><span class="sr">Imprimir / PDF</span></a></td>
</tr><?php endforeach?></tbody></table></div>
<?php if(count($list)>=500):?><p class="hint">Se muestran las 500 más recientes; usa la búsqueda para encontrar otras.</p><?php endif?>
<?php else:?><div class="empty-state"><?=ic('cart')?><b><?=($q!==''||$fe)?'No hay órdenes que coincidan':'Todavía no hay órdenes de compra'?></b><span><?=$canEdit?'Crea la primera con «Nueva OC».':'Cuando un editor cree una OC, aparecerá aquí.'?></span></div><?php endif?>

<?php elseif($mode==='oc_new'):
$src=ocGet((int)$g('from'));
if($posted) $o=$posted;
elseif($src){ $o=$src; unset($o['id'],$o['owner'],$o['created'],$o['updated']); $o['numero']=''; $o['fecha']=ocToday(); $o['estado']='Borrador'; $o['emisor']=$ocCfg['emisor']; }
else $o=ocNormalize(['fecha'=>ocToday(),'estado'=>'Borrador','iva'=>$ocCfg['iva'],'entrega'=>['solicitante'=>$ocCfg['firmas'][0]['nombre']??''],'items'=>[],'condiciones'=>$ocCfg['condiciones'],'firmas'=>$ocCfg['firmas']]);
?>
<nav class="crumbs" aria-label="Ruta"><a href="<?=e(url(['tab'=>'oc']))?>">Órdenes de compra</a><?=ic('chevron')?><span><?=$src?'Copia de '.e($src['numero']):'Nueva OC'?></span></nav>
<div class="page-head"><div><span class="eyebrow muted">COMPRAS</span><h1><?=$src?'Duplicar '.e($src['numero']):'Nueva orden de compra'?></h1><p class="subtitle">El número se asigna al guardar (próximo: <?=e(ocNextNumero($ocCfg))?>). Puedes escribir otro si lo necesitas.</p></div></div>
<?php $ocForm($o,0)?>

<?php elseif($mode==='oc_doc' && !$ocDoc):?>
<div class="empty-state"><?=ic('search')?><b>Orden de compra no encontrada</b><span><a href="<?=e(url(['tab'=>'oc']))?>">Volver al registro</a></span></div>

<?php elseif($mode==='oc_doc' && $ocEdit):?>
<nav class="crumbs" aria-label="Ruta"><a href="<?=e(url(['tab'=>'oc']))?>">Órdenes de compra</a><?=ic('chevron')?><a href="<?=e(url(['oc'=>$ocDoc['id']]))?>"><?=e($ocDoc['numero'])?></a><?=ic('chevron')?><span>Editar</span></nav>
<div class="page-head"><div><span class="eyebrow muted">COMPRAS</span><h1>Editar <?=e($ocDoc['numero'])?></h1><p class="subtitle">Al guardar verás la orden en formato carta, lista para imprimir.</p></div></div>
<?php $ocForm($posted??$ocDoc,$ocDoc['id'])?>

<?php elseif($mode==='oc_doc'): $t=ocTotals($ocDoc);?>
<nav class="crumbs no-print" aria-label="Ruta"><a href="<?=e(url(['tab'=>'oc']))?>">Órdenes de compra</a><?=ic('chevron')?><span><?=e($ocDoc['numero'])?></span></nav>
<div class="page-head no-print">
  <div class="title-row"><a class="icon-btn" href="<?=e(url(['tab'=>'oc']))?>" title="Volver"><?=ic('back')?><span class="sr">Volver</span></a><span class="tile-icon big tone0"><?=ic('cart')?></span><div><h1><?=e($ocDoc['numero'])?> <?=$pill($ocDoc['estado'])?></h1><p class="subtitle"><?=e($ocDoc['proveedor']['razon']?:'Sin proveedor')?> · <?=clp($t['total'])?> · actualizada el <?=e(fdate($ocDoc['updated'],true))?></p></div></div>
  <div class="head-actions">
    <a class="btn primary" href="<?=e(url(['oc_print'=>$ocDoc['id']]))?>" target="_blank" rel="noopener"><?=ic('print')?>Imprimir / PDF</a>
    <?php if($canEdit):?><a class="btn ghost" href="<?=e(url(['oc'=>$ocDoc['id'],'edit'=>1]))?>"><?=ic('edit')?>Editar</a><a class="btn ghost" href="<?=e(url(['tab'=>'oc_new','from'=>$ocDoc['id']]))?>"><?=ic('copy')?>Duplicar</a>
    <details class="pop"><summary class="btn ghost">Estado</summary><form method="post" class="pop-body"><?=csrf()?><input type="hidden" name="id" value="<?=$ocDoc['id']?>"><input type="hidden" name="back" value="oc"><strong>Cambiar estado</strong><label>Estado<select name="estado"><?php foreach(OC_ESTADOS as $s):?><option<?=$ocDoc['estado']===$s?' selected':''?>><?=e($s)?></option><?php endforeach?></select></label><button class="primary" name="action" value="oc_estado">Aplicar</button></form></details><?php endif?>
    <?php if($isAdmin):?><details class="pop"><summary class="btn ghost" title="Más opciones"><?=ic('more')?><span class="sr">Más opciones</span></summary><form method="post" class="pop-body danger-zone"><?=csrf()?><input type="hidden" name="id" value="<?=$ocDoc['id']?>"><strong>Eliminar <?=e($ocDoc['numero'])?></strong><p>La eliminación es definitiva. Si la orden ya se envió al proveedor, considera marcarla como Anulada.</p><button class="danger" name="action" value="oc_delete"><?=ic('trash')?>Eliminar OC</button></form></details><?php endif?>
  </div>
</div>
<div class="sheet-wrap"><?php ocSheet($ocDoc)?></div>
<p class="hint no-print">También puedes imprimir esta página directamente con Ctrl + P (Cmd + P en Mac): solo se imprime la hoja.</p>

<?php elseif($mode==='oc_cfg'): $sup=query('SELECT * FROM oc_suppliers ORDER BY razon COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
$conds=$ocCfg['condiciones']; for($n=0;$n<2;$n++) $conds[]=['k'=>'','v'=>''];
$firmas=$ocCfg['firmas']; while(count($firmas)<3) $firmas[]=['rol'=>['Solicita','Autoriza','Acepta proveedor'][count($firmas)],'nombre'=>'','cargo'=>''];
?>
<nav class="crumbs" aria-label="Ruta"><a href="<?=e(url(['tab'=>'oc']))?>">Órdenes de compra</a><?=ic('chevron')?><span>Configuración</span></nav>
<div class="page-head"><div><span class="eyebrow muted">ADMINISTRACIÓN</span><h1>Configuración de OC</h1><p class="subtitle">Estos datos se usan en las próximas órdenes; las ya guardadas conservan los suyos.</p></div></div>
<form method="post" class="oc-form"><?=csrf()?>
<section class="card"><h2>Empresa emisora</h2><div class="oc-grid">
  <label class="span2">Razón social<input name="em_razon" maxlength="300" value="<?=e($ocCfg['emisor']['razon'])?>"></label>
  <label>R.U.T.<input name="em_rut" maxlength="300" value="<?=e($ocCfg['emisor']['rut'])?>"></label>
  <label>Giro<input name="em_giro" maxlength="300" value="<?=e($ocCfg['emisor']['giro'])?>"></label>
  <label class="span2">Dirección<input name="em_direccion" maxlength="300" value="<?=e($ocCfg['emisor']['direccion'])?>"></label>
  <label>Correo<input name="em_email" maxlength="300" value="<?=e($ocCfg['emisor']['email'])?>"></label>
  <label>Sitio web<input name="em_web" maxlength="300" value="<?=e($ocCfg['emisor']['web'])?>"></label>
</div></section>
<section class="card"><h2>Numeración e IVA</h2><div class="oc-grid">
  <label>Prefijo<input name="prefijo" maxlength="30" value="<?=e($ocCfg['prefijo'])?>"></label>
  <label>Próximo correlativo<input type="number" name="siguiente" min="1" max="999999" required value="<?=(int)$ocCfg['siguiente']?>"></label>
  <label>IVA predeterminado (%)<input type="number" name="iva" min="0" max="100" step="0.1" value="<?=e(ocNumIn($ocCfg['iva']))?>"></label>
</div><p class="hint">Próximo número que se asignará: <b><?=e(ocNextNumero($ocCfg))?></b>. Si ese número ya existe, se salta al siguiente libre.</p></section>
<section class="card"><h2>Firmas predeterminadas</h2><div class="oc-firmas">
<?php foreach(array_slice($firmas,0,3) as $f):?><fieldset><legend><?=e($f['rol'])?></legend><input type="hidden" name="fi_rol[]" value="<?=e($f['rol'])?>"><label>Nombre<input name="fi_nombre[]" maxlength="80" value="<?=e($f['nombre'])?>"></label><label>Cargo<input name="fi_cargo[]" maxlength="80" value="<?=e($f['cargo'])?>"></label></fieldset><?php endforeach?>
</div></section>
<section class="card"><h2>Condiciones predeterminadas</h2><div class="oc-conds"><?php foreach($conds as $c):?><div class="oc-cond"><label><span class="sr">Condición</span><input name="co_k[]" maxlength="60" value="<?=e($c['k'])?>" placeholder="Ej: Pago"></label><label><span class="sr">Texto</span><input name="co_v[]" maxlength="400" value="<?=e($c['v'])?>"></label></div><?php endforeach?></div><p class="hint">Deja ambas casillas vacías para quitar una condición. Guarda para obtener más filas.</p></section>
<div class="oc-actions"><button class="primary" name="action" value="oc_cfg">Guardar configuración</button></div>
</form>
<section class="card"><h2>Proveedores guardados</h2>
<?php if($sup):?><div class="table-wrap"><table><thead><tr><th>Proveedor</th><th class="hide-sm">Contacto</th><th class="right">Acción</th></tr></thead><tbody><?php foreach($sup as $s):?><tr><td><b><?=e($s['razon'])?></b><small><?=e(implode(' · ',array_filter([$s['rut'],$s['ciudad']],'strlen')))?></small></td><td class="hide-sm"><?=e(implode(' · ',array_filter([$s['email'],$s['telefono']],'strlen')))?></td><td class="right"><form method="post"><?=csrf()?><input type="hidden" name="id" value="<?=(int)$s['id']?>"><button class="ghost small" name="action" value="oc_supplier_delete">Quitar</button></form></td></tr><?php endforeach?></tbody></table></div>
<p class="hint">Los proveedores se agregan o actualizan solos al guardar una OC. Quitar uno no modifica las OC existentes.</p>
<?php else:?><p class="hint">Sin proveedores guardados.</p><?php endif?></section>
<section class="card"><h2>Importar desde la herramienta anterior</h2>
<form method="post" enctype="multipart/form-data" class="row-form"><?=csrf()?><label>Respaldo JSON («Respaldo JSON» del archivo ordenes_compra_ox3.html)<input type="file" name="json" accept=".json,application/json" required></label><button class="primary" name="action" value="oc_import"><?=ic('upload')?>Importar</button></form>
<p class="hint">Se agregan las OC y proveedores del respaldo. Las OC cuyo número ya existe aquí se omiten; no se borra nada.</p></section>
<?php endif?>
