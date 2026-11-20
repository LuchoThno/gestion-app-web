<?php
// Pantallas del módulo de cursos. Se incluye desde view.php ($mode empieza con "cu").
$cuCfgV=cuCfg();
$cuPill=fn(string $s): string => '<span class="oc-pill '.cuEstadoClass($s).'">'.e($s).'</span>';
$cuHelp=fn(string $html, string $t='¿Cómo se calcula?'): string => '<details class="ps-help"><summary>'.ic('info').$t.'</summary><div>'.$html.'</div></details>';
$cuHead=function(string $title, string $sub, string $actions='', string $eyebrow='CURSOS'): void { ?>
<div class="page-head"><div><span class="eyebrow muted"><?=e($eyebrow)?></span><h1><?=$title?></h1><p class="subtitle"><?=$sub?></p></div><?php if($actions):?><div class="head-actions"><?=$actions?></div><?php endif?></div>
<?php };
$cuPosted=$_SERVER['REQUEST_METHOD']==='POST' && $error!=='' && post('action')==='cu_save';
$cuNum=fn($v): string => ($v===''||$v===null||(is_numeric($v)&&(float)$v==0.0)) ? '' : ocNumIn(is_numeric($v)?(float)$v:$v);
/** Costos directos típicos de un curso (las filas sin precio no se guardan). */
$cuTemplate=fn(): array => [
    ['desc'=>'Relator(es), valor hora','cant'=>1,'unidad'=>'hora','precio'=>'','base'=>'hora','ret'=>1],
    ['desc'=>'Coordinación académica','cant'=>1,'unidad'=>'curso','precio'=>'','base'=>'fijo','ret'=>0],
    ['desc'=>'Arriendo de sala o taller','cant'=>1,'unidad'=>'día','precio'=>'','base'=>'fijo','ret'=>0],
    ['desc'=>'Manual y materiales del participante','cant'=>1,'unidad'=>'kit','precio'=>'','base'=>'part','ret'=>0],
    ['desc'=>'Coffee break o alimentación','cant'=>1,'unidad'=>'servicio','precio'=>'','base'=>'part','ret'=>0],
    ['desc'=>'Certificado y credencial','cant'=>1,'unidad'=>'unidad','precio'=>'','base'=>'part','ret'=>0],
    ['desc'=>'Traslado y alojamiento del relator','cant'=>1,'unidad'=>'viaje','precio'=>'','base'=>'fijo','ret'=>0],
    ['desc'=>'Equipos e insumos de práctica','cant'=>1,'unidad'=>'','precio'=>'','base'=>'fijo','ret'=>0]];

/** Formulario del curso (sin JavaScript: filas vacías extra; «Guardar y seguir editando» agrega más). */
$cuForm=function(array $c, int $id, int $ver) use($cuHelp, $cuNum, $cuCfgV): void {
    $mods=$c['modulos']; for($i=count($mods),$n=0; ($n<3 || $i<4) && $i<40; $i++,$n++) $mods[]=['nombre'=>'','teo'=>'','pra'=>'','contenidos'=>''];
    $rows=$c['costos']; for($i=count($rows),$n=0; ($n<3 || $i<6) && $i<60; $i++,$n++) $rows[]=['desc'=>'','cant'=>'','unidad'=>'','precio'=>'','base'=>'fijo','ret'=>0];
    $fx=cuFixedSnapshot(cuHoras($c)); ?>
<form method="post" class="oc-form"><?=csrf()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="cu_save">
<section class="card"><h2><span class="ps-step">1</span>El curso</h2><div class="oc-grid">
  <label class="span2">Nombre del curso<input name="nombre" maxlength="120" required value="<?=e($c['nombre'])?>" placeholder="Ej: Operador de grúa horquilla"></label>
  <label>Área o rubro<input name="area" maxlength="80" list="cu-areas" value="<?=e($c['area'])?>" placeholder="Ej: Marítima"></label>
  <label>Modalidad<input name="modalidad" maxlength="40" list="cu-modalidad" value="<?=e($c['modalidad'])?>" placeholder="Presencial, en línea o mixta"></label>
  <label>Código SENCE u OMI <small>(opcional)</small><input name="sence" maxlength="40" value="<?=e($c['sence'])?>"></label>
  <label>Dirigido a<input name="dirigido" maxlength="300" value="<?=e($c['dirigido'])?>" placeholder="Ej: operarios de bodega y patio"></label>
  <label class="span2">Objetivo general<textarea name="objetivo" maxlength="600" rows="2" placeholder="Al finalizar, el participante será capaz de…"><?=e($c['objetivo'])?></textarea></label>
  <label>Requisitos de ingreso <small>(opcional)</small><input name="requisitos" maxlength="300" value="<?=e($c['requisitos'])?>"></label>
  <label>Evaluación y aprobación<input name="evaluacion" maxlength="300" value="<?=e($c['evaluacion'])?>" placeholder="Ej: prueba teórica 60% y práctica, 75% de asistencia"></label>
  <label>Certificación<input name="certificacion" maxlength="200" value="<?=e($c['certificacion'])?>" placeholder="Ej: certificado de aprobación OX3"></label>
  <label>Perfil del relator <small>(opcional)</small><input name="relator" maxlength="300" value="<?=e($c['relator'])?>"></label>
</div><datalist id="cu-modalidad"><option value="Presencial"><option value="En línea"><option value="Mixta"></datalist>
<datalist id="cu-areas"><?php foreach(query("SELECT DISTINCT area FROM cu_courses WHERE area<>'' ORDER BY area")->fetchAll(PDO::FETCH_COLUMN) as $a):?><option value="<?=e($a)?>"><?php endforeach?></datalist></section>

<section class="card"><h2><span class="ps-step">2</span>Malla: módulos y horas</h2>
<div class="cu-mods"><div class="cu-mod cu-mod-head"><span>Módulo</span><span>Horas teóricas</span><span>Horas prácticas</span><span>Contenidos</span></div>
<?php foreach($mods as $m):?><div class="cu-mod">
  <label><span class="sr">Módulo</span><input name="md_nombre[]" maxlength="120" value="<?=e($m['nombre'])?>" placeholder="Nombre del módulo"></label>
  <label><span class="sr">Horas teóricas</span><input type="number" name="md_teo[]" min="0" step="0.5" value="<?=e($cuNum($m['teo']))?>" placeholder="Teo."></label>
  <label><span class="sr">Horas prácticas</span><input type="number" name="md_pra[]" min="0" step="0.5" value="<?=e($cuNum($m['pra']))?>" placeholder="Prác."></label>
  <label><span class="sr">Contenidos</span><input name="md_cont[]" maxlength="600" value="<?=e($m['contenidos'])?>" placeholder="Temas, separados por coma"></label>
</div><?php endforeach?></div>
<div class="oc-grid cu-hours"><label>Horas totales del curso <small>(vacío = suma de los módulos)</small><input type="number" name="horas" min="0" step="0.5" value="<?=e($cuNum($c['horas']))?>" placeholder="<?=e(ocQty(cuModHoras($c))?:'Ej: 24')?>"></label></div>
<p class="hint">Las filas vacías no se guardan. Usa «Guardar y seguir editando» para obtener más filas.</p></section>

<section class="card"><h2><span class="ps-step">3</span>Costos directos del curso</h2>
<div class="ps-lines cu-lines"><div class="ps-line ps-line-head"><span>Concepto</span><span>Cant.</span><span>Unidad</span><span>Precio unit. ($)</span><span>El precio es</span><span>Se multiplica por</span></div>
<?php foreach($rows as $r):?><div class="ps-line">
  <label><span class="sr">Concepto</span><input name="ln_desc[]" maxlength="120" value="<?=e($r['desc'])?>" placeholder="Concepto"></label>
  <label><span class="sr">Cantidad</span><input type="number" name="ln_cant[]" min="0" step="any" value="<?=e($cuNum($r['cant']))?>" placeholder="1"></label>
  <label><span class="sr">Unidad</span><input name="ln_unidad[]" maxlength="30" value="<?=e($r['unidad'])?>" placeholder="Unidad"></label>
  <label><span class="sr">Precio unitario</span><input type="number" name="ln_precio[]" min="0" step="1" value="<?=e($cuNum($r['precio']))?>" placeholder="$"></label>
  <label><span class="sr">El precio es</span><select name="ln_ret[]"><option value="">Bruto o neto</option><option value="liq"<?=($r['ret']??0)>0?' selected':''?>>Líquido + ret.</option></select></label>
  <label><span class="sr">Se multiplica por</span><select name="ln_base[]"><?php foreach(PS_BASES as $k=>$lbl):?><option value="<?=$k?>"<?=$r['base']===$k?' selected':''?>><?=e($lbl)?></option><?php endforeach?></select></label>
</div><?php endforeach?></div>
<p class="hint">Monto = cantidad × precio unitario, multiplicado por participantes u horas según la última columna. Las filas sin precio no se guardan. En honorarios con boleta, <b>Líquido + ret.</b> suma la retención del <?=e(psRetLabel(psRetRate()))?>.</p>
<p class="cu-fixnote"><?=ic('info')?><span>Además se suman <b><?=clp($fx['total'])?></b> de costos fijos de la empresa (<?=e(cuFixedText($fx))?>). <?=$GLOBALS['isAdmin']?'<a href="'.e(url(['tab'=>'cu_cfg'])).'">Configurar costos fijos</a>':''?></span></p></section>

<section class="card"><h2><span class="ps-step">4</span>Participantes y precio</h2><div class="oc-grid cu-grid4">
  <label>Participantes previstos<input type="number" name="part" min="0" step="1" value="<?=e($cuNum($c['part']))?>" placeholder="Ej: 12"></label>
  <label>Mínimo para dictarlo <small>(opcional)</small><input type="number" name="part_min" min="0" step="1" value="<?=e($cuNum($c['part_min']))?>"></label>
  <label>Cupo máximo <small>(opcional)</small><input type="number" name="part_max" min="0" step="1" value="<?=e($cuNum($c['part_max']))?>"></label>
  <label>Precio por participante <small>(neto)</small><input type="number" name="precio" min="0" step="1" value="<?=e($cuNum($c['precio']))?>" placeholder="Vacío = ver sugerido"></label>
</div><p class="hint">Si dejas el precio vacío, el evaluador te sugiere uno para el margen objetivo de <?=e(ocQty((float)$cuCfgV['margen_obj']))?>%. La cotización agrega el IVA según su configuración.</p></section>

<?php if($id):?><section class="card"><h2><span class="ps-step">5</span>¿Qué cambió?</h2><label>Nota de esta versión <small>(opcional; queda en el historial)</small><input name="nota" maxlength="300" placeholder="Ej: se agregó un módulo práctico y subió el valor del relator"></label>
<p class="hint">Al guardar se crea la versión <?=$ver+1?>. Las versiones anteriores quedan en el historial y se pueden ver o restaurar.</p></section><?php endif?>
<div class="oc-actions"><button class="primary">Guardar y evaluar</button><button class="ghost" name="after" value="edit">Guardar y seguir editando</button><a class="btn ghost" href="<?=e($id?url(['curso'=>$id]):url(['tab'=>'cu']))?>">Cancelar</a></div>
</form>
<?php };
?>

<?php if($mode==='cu'):
$fe=in_array($g('estado'),CU_ESTADOS,true)?$g('estado'):''; $q=ocText($g('q'),80);
$w=[$fe?'estado=?':"estado<>'Archivado'"]; $p=$fe?[$fe]:[];
if($q!==''){ $w[]='(codigo LIKE ? OR nombre LIKE ? OR area LIKE ?)'; array_push($p,"%$q%","%$q%","%$q%"); }
$rows=query('SELECT * FROM cu_courses WHERE '.implode(' AND ',$w)." ORDER BY CASE estado WHEN 'Activo' THEN 0 WHEN 'En diseño' THEN 1 ELSE 2 END, nombre COLLATE NOCASE LIMIT 300",$p)->fetchAll(PDO::FETCH_ASSOC);
$list=[]; foreach($rows as $r){ $c=cuNormalize(json_decode($r['data'],true)?:[]); $ev=cuEval($c); [$v]=cuAssess($c,$ev); $list[]=[$r,$c,$ev,$v]; }
$cnt=query('SELECT estado,COUNT(*) FROM cu_courses GROUP BY estado')->fetchAll(PDO::FETCH_KEY_PAIR);
$act=array_filter($list,fn($x)=>$x[0]['estado']==='Activo' && $x[2]['pct']!==null);
$avg=$act?array_sum(array_map(fn($x)=>$x[2]['pct'],$act))/count($act):null;
$fx=cuFixedSnapshot(0); $nq=(int)query('SELECT COUNT(*) FROM cu_quotes')->fetchColumn();
$cuHead('Cursos','Diseña y evalúa cada curso: malla, costos (incluidos los fijos de la empresa) y margen. Desde un curso generas su cotización en un clic.',
    ($isAdmin?'<a class="btn ghost" href="'.e(url(['tab'=>'cu_cfg'])).'">'.ic('settings').'Costos fijos</a>':'').'<a class="btn ghost" href="'.e(url(['cu_xlsx'=>'export'])).'" title="Descarga los cursos vigentes en Excel">'.ic('download').'<span class="hide-sm">Exportar</span></a>'.($canEdit?'<a class="btn ghost" href="'.e(url(['tab'=>'cu_import'])).'">'.ic('upload').'Carga masiva</a><a class="btn primary" href="'.e(url(['tab'=>'cu_new'])).'">'.ic('plus').'Nuevo curso</a>':''),'VENTAS');
?>
<div class="stat-grid">
  <a class="stat" href="<?=e(url(['tab'=>'cu','estado'=>'Activo']))?>"><?=ic('check')?><strong><?=(int)($cnt['Activo']??0)?></strong><span>Cursos activos</span></a>
  <a class="stat" href="<?=e(url(['tab'=>'cu','estado'=>'En diseño']))?>"><?=ic('edit')?><strong><?=(int)($cnt['En diseño']??0)?></strong><span>En diseño</span></a>
  <div class="stat"><?=ic('trend')?><strong class="<?=$avg!==null&&$avg<(float)$cuCfgV['margen_min']?'cc-out':''?>"><?=$avg===null?'—':psPct($avg)?></strong><span>Margen promedio de los activos · objetivo <?=e(ocQty((float)$cuCfgV['margen_obj']))?>%</span></div>
  <?php if($isAdmin):?><a class="stat" href="<?=e(url(['tab'=>'cu_cfg']))?>"><?php else:?><div class="stat"><?php endif?><?=ic('receipt')?><strong><?=clp($fx['mensual'])?></strong><span>Costos fijos al mes<?=$fx['mensual']>0?($fx['metodo']==='curso'?' · '.clp($fx['mensual']/max(1,$fx['base'])).' por curso':' · '.clp($fx['mensual']/max(1,$fx['base'])).' por hora'):' · sin configurar'?></span><?=$isAdmin?'</a>':'</div>'?>
</div>
<?php $base=array_filter(['tab'=>'cu','q'=>$q],'strlen');?>
<nav class="ps-tabs" aria-label="Filtrar por estado"><a href="<?=e(url($base))?>"<?=$fe===''?' class="on" aria-current="page"':''?>>Vigentes<span class="ps-badge"><?=(int)($cnt['Activo']??0)+(int)($cnt['En diseño']??0)?></span></a><?php foreach(['Activo'=>'Activos','En diseño'=>'En diseño','Archivado'=>'Archivados'] as $s=>$lbl):?><a href="<?=e(url($base+['estado'=>$s]))?>"<?=$fe===$s?' class="on" aria-current="page"':''?>><?=$lbl?><?php if(!empty($cnt[$s])):?><span class="ps-badge"><?=(int)$cnt[$s]?></span><?php endif?></a><?php endforeach?></nav>
<form method="get" class="oc-filter" role="search"><input type="hidden" name="tab" value="cu"><?php if($fe):?><input type="hidden" name="estado" value="<?=e($fe)?>"><?php endif?><label class="grow"><span class="sr">Buscar</span><input name="q" value="<?=e($q)?>" placeholder="Buscar por nombre, código o área…"></label><button class="ghost"><?=ic('search')?>Buscar</button><?php if($q!==''):?><a class="btn ghost" href="<?=e(url(array_filter(['tab'=>'cu','estado'=>$fe],'strlen')))?>">Limpiar</a><?php endif?></form>
<?php if($list):?><div class="table-wrap"><table class="oc-reg cu-reg"><thead><tr><th>Curso</th><th class="right">Precio / participante</th><th class="right">Margen</th><th class="right hide-sm">Equilibrio</th><th>Evaluación</th><th class="hide-sm">Estado</th><th class="right">Acciones</th></tr></thead><tbody>
<?php foreach($list as [$r,$c,$ev,$v]):?><tr>
  <td><a class="ps-name" href="<?=e(url(['curso'=>(int)$r['id']]))?>"><?=e($r['nombre'])?></a><small><?=e(implode(' · ',array_filter([$r['codigo'],$c['area'],$ev['H']>0?ocQty($ev['H']).' h':'',$c['modalidad'],'v'.(int)$r['version']],'strlen')))?></small></td>
  <td class="right nowrap"><?=$ev['precio']>0?'<b>'.clp($ev['precio']).'</b>':'<span class="muted">Sin precio</span>'?><?php if($ev['sugerido'] && $ev['sugerido']!==$ev['precio']):?><small>sugerido <?=clp($ev['sugerido'])?></small><?php endif?></td>
  <td class="right nowrap"><b class="<?=$ev['pct']!==null&&$ev['pct']<(float)$cuCfgV['margen_min']?'cc-out':''?>"><?=psPct($ev['pct'])?></b><?php if($ev['pct']!==null):?><small><?=ccClp($ev['margen'])?> con <?=(int)$ev['P']?></small><?php endif?></td>
  <td class="right nowrap hide-sm"><?=$ev['equilibrio']===null?'—':$ev['equilibrio'].' part.'?></td>
  <td class="nowrap"><?=cuVerdictPill($v)?></td>
  <td class="nowrap hide-sm"><?=$cuPill($r['estado'])?></td>
  <td class="right nowrap"><a class="icon-btn" href="<?=e(url(['curso'=>(int)$r['id']]))?>" title="Abrir"><?=ic('eye')?><span class="sr">Abrir</span></a><a class="icon-btn" href="<?=e(url(['cu_print'=>(int)$r['id']]))?>" target="_blank" rel="noopener" title="Ficha del curso"><?=ic('print')?><span class="sr">Ficha del curso</span></a></td>
</tr><?php endforeach?></tbody></table></div>
<?=$cuHelp('<p><b>Margen</b> = (precio × participantes previstos − costo total) ÷ ingreso. El costo total suma los costos directos del curso y su parte de los <b>costos fijos</b> de la empresa (contadora, administración…).</p><p><b>Equilibrio</b>: participantes mínimos para no perder. <b>Evaluación</b>: «Viable» si el margen llega al objetivo ('.e(ocQty((float)$cuCfgV['margen_obj'])).'%) y el diseño está completo; «Con ajustes» si algo se puede mejorar; «No viable» si pierde o queda bajo el mínimo ('.e(ocQty((float)$cuCfgV['margen_min'])).'%).</p>','¿Cómo se evalúa?')?>
<?php else:?><div class="empty-state"><?=ic('list')?><b><?=($q!==''||$fe)?'No hay cursos que coincidan':'Todavía no hay cursos'?></b><span><?=$canEdit?'Crea el primero con «Nuevo curso»: la plantilla trae los costos típicos.':'Cuando un editor cree un curso, aparecerá aquí.'?></span></div><?php endif?>
<?php if($nq):?><p class="hint"><?=$nq?> cotizaci<?=$nq===1?'ón generada':'ones generadas'?> desde cursos.</p><?php endif?>

<?php elseif($mode==='cu_new'):
$c=$cuPosted ? cuNormalize(cuFromPost()) : cuNormalize(['costos'=>[]]);
if(!$cuPosted) $c['costos']=$cuTemplate();
?>
<nav class="crumbs" aria-label="Ruta"><a href="<?=e(url(['tab'=>'cu']))?>">Cursos</a><?=ic('chevron')?><span>Nuevo curso</span></nav>
<?php $cuHead('Nuevo curso','Escribe la ficha, la malla y los costos. Al guardar, el evaluador calcula el margen, el punto de equilibrio y un precio sugerido. Código asignado al guardar: '.e(cuNextCodigo()).'.')?>
<?php if(!$cuPosted):?><p class="hint cc-note">La plantilla propone los costos típicos de un curso: completa los precios de los que apliquen; los que queden sin precio no se guardan.</p><?php endif?>
<?php $cuForm($c,0,0)?>

<?php elseif($mode==='cu_cfg'):
$items=cuFixedItems(false); $extra=max(3,8-count($items)); for($i=0;$i<$extra;$i++) $items[]=['nombre'=>'','monto'=>'','activo'=>1];
$fx=cuFixedSnapshot(0); $per=$fx['mensual']/max(1,$fx['base']);
?>
<nav class="crumbs" aria-label="Ruta"><a href="<?=e(url(['tab'=>'cu']))?>">Cursos</a><?=ic('chevron')?><span>Costos fijos y márgenes</span></nav>
<?php $cuHead('Costos fijos y márgenes','Gastos mensuales de '.e(empresa()['nombre']).' que no dependen de un curso. Se reparten entre los cursos para que su margen sea real.','','ADMINISTRACIÓN')?>
<div class="stat-grid cu-stat3">
  <div class="stat"><?=ic('receipt')?><strong><?=clp($fx['mensual'])?></strong><span>Costos fijos al mes (activos)</span></div>
  <div class="stat"><?=ic('target')?><strong><?=clp($per)?></strong><span><?=$fx['metodo']==='hora'?'Por cada hora de curso':'Por cada curso'?></span></div>
  <div class="stat"><?=ic('trend')?><strong><?=e(ocQty((float)$cuCfgV['margen_obj']))?>%</strong><span>Margen objetivo · mínimo <?=e(ocQty((float)$cuCfgV['margen_min']))?>%</span></div>
</div>
<form method="post" class="oc-form"><?=csrf()?>
<section class="card"><h2><span class="ps-step">1</span>Costos fijos mensuales</h2>
<div class="cu-fixed"><div class="cu-fx cu-fx-head"><span>Concepto</span><span>Monto mensual ($)</span><span>¿Se carga a los cursos?</span></div>
<?php foreach($items as $f):?><div class="cu-fx">
  <label><span class="sr">Concepto</span><input name="fx_nombre[]" maxlength="80" value="<?=e($f['nombre'])?>" placeholder="Ej: Contadora"></label>
  <label><span class="sr">Monto mensual</span><input type="number" name="fx_monto[]" min="0" step="1" value="<?=e((int)$f['monto']?:'')?>" placeholder="$"></label>
  <label><span class="sr">¿Se carga a los cursos?</span><select name="fx_activo[]"><option value="1"<?=(int)$f['activo']?' selected':''?>>Sí</option><option value="0"<?=(int)$f['activo']?'':' selected'?>>No (pausado)</option></select></label>
</div><?php endforeach?></div>
<p class="hint">Escribe el monto mensual con IVA si no lo recuperas. Para quitar un costo, borra su nombre y su monto. Guarda para obtener más filas.</p></section>
<section class="card"><h2><span class="ps-step">2</span>Cómo se reparten entre los cursos</h2><div class="oc-grid">
  <label class="span2">Método<select name="metodo"><?php foreach(CU_METODOS as $k=>$lbl):?><option value="<?=$k?>"<?=$cuCfgV['metodo']===$k?' selected':''?>><?=e($lbl)?></option><?php endforeach?></select></label>
  <label>Cursos que se dictan al mes <small>(partes iguales)</small><input type="number" name="cursos_mes" min="1" max="500" step="1" required value="<?=(int)$cuCfgV['cursos_mes']?>"></label>
  <label>Horas de clase al mes <small>(según horas)</small><input type="number" name="horas_mes" min="1" max="20000" step="1" required value="<?=(int)$cuCfgV['horas_mes']?>"></label>
</div><?=$cuHelp('<p><b>Partes iguales</b>: cada curso carga (costos fijos del mes ÷ cursos al mes). Ej.: $ 1.200.000 ÷ 4 cursos = $ 300.000 por curso.</p><p><b>Según horas</b>: cada curso carga (costos fijos ÷ horas de clase al mes) × sus horas. Ej.: $ 1.200.000 ÷ 120 h = $ 10.000 por hora; un curso de 24 h carga $ 240.000. Es más justo si los cursos tienen duraciones muy distintas.</p><p>Usa un promedio realista: si estimas más cursos de los que dictas, los costos fijos quedan sin cubrir.</p>','¿Cuál conviene?')?></section>
<section class="card"><h2><span class="ps-step">3</span>Márgenes del evaluador</h2><div class="oc-grid cu-grid3">
  <label>Margen objetivo (%)<input type="number" name="margen_obj" min="0" max="90" step="0.5" required value="<?=e(ocNumIn((float)$cuCfgV['margen_obj']))?>"></label>
  <label>Margen mínimo aceptable (%)<input type="number" name="margen_min" min="0" max="90" step="0.5" required value="<?=e(ocNumIn((float)$cuCfgV['margen_min']))?>"></label>
  <label>Redondear el precio sugerido a<select name="redondeo"><?php foreach([0=>'Sin redondeo',100=>'$ 100',500=>'$ 500',1000=>'$ 1.000',5000=>'$ 5.000',10000=>'$ 10.000'] as $k=>$lbl):?><option value="<?=$k?>"<?=(int)$cuCfgV['redondeo']===$k?' selected':''?>><?=e($lbl)?></option><?php endforeach?></select></label>
</div><p class="hint">El margen se calcula sobre el precio neto: (ingreso − costo total) ÷ ingreso.</p></section>
<div class="oc-actions"><button class="primary" name="action" value="cu_cfg">Guardar configuración</button><a class="btn ghost" href="<?=e(url(['tab'=>'cu']))?>">Volver a cursos</a></div>
</form>
<p class="ps-note"><?=ic('info')?>Cada curso guarda los costos fijos con que se evaluó. Al cambiar esta configuración, el curso te avisa y ofrece «Actualizar costos fijos» (crea una versión nueva).</p>

<?php elseif($mode==='cu_doc'): $cu=cuGet($cuId);
if(!$cu):?><div class="empty-state"><?=ic('search')?><b>Curso no encontrado</b><span><a href="<?=e(url(['tab'=>'cu']))?>">Volver a cursos</a></span></div><?php else:
if($canEdit && $g('edit')==='1'):
  $c=$cuPosted ? cuNormalize(cuFromPost()+['fijos'=>$cu['c']['fijos']]) : $cu['c']; ?>
<nav class="crumbs" aria-label="Ruta"><a href="<?=e(url(['tab'=>'cu']))?>">Cursos</a><?=ic('chevron')?><a href="<?=e(url(['curso'=>(int)$cu['id']]))?>"><?=e($cu['nombre'])?></a><?=ic('chevron')?><span>Editar</span></nav>
<?php $cuHead('Editar '.e($cu['nombre']),e($cu['codigo']).' · versión vigente '.(int)$cu['version'])?>
<?php $cuForm($c,(int)$cu['id'],(int)$cu['version'])?>
<?php else:
// Versión que se muestra: la vigente o una anterior (?ver=N, solo lectura).
$verN=(int)$g('ver'); $old=false;
if($verN>0 && $verN!==(int)$cu['version']) $old=cuVersion((int)$cu['id'],$verN);
$c=$old?$old['c']:$cu['c']; $shownVer=$old?(int)$old['version']:(int)$cu['version'];
$ev=cuEval($c); [$v,$obs]=cuAssess($c,$ev); $H=$ev['H'];
$outdated=!$old && cuFixedOutdated($c);
$quotes=query('SELECT q.version, q.created, k.id, k.numero, k.estado, k.cli_razon, k.total, k.vence, k.data FROM cu_quotes q LEFT JOIN cot_quotes k ON k.id=q.cot_id WHERE q.course_id=? ORDER BY q.id DESC',[$cu['id']])->fetchAll(PDO::FETCH_ASSOC);
$nVers=(int)query('SELECT COUNT(*) FROM cu_versions WHERE course_id=?',[$cu['id']])->fetchColumn();
$nWarn=count(array_filter($obs,fn($o)=>$o['lvl']>0)); $maxLvl=$obs?max(array_column($obs,'lvl')):0;
$cuTabs=['resumen'=>['Evaluación',$nWarn?'<span class="ps-badge ps-badge'.$maxLvl.'">'.$nWarn.'</span>':''],'costos'=>['Costos y escenarios',''],'contenido'=>['Ficha y malla',''],
    'cotizaciones'=>['Cotizaciones',$quotes?'<span class="ps-badge">'.count($quotes).'</span>':''],'historial'=>['Historial','<span class="ps-badge">'.$nVers.'</span>']];
$vista=isset($cuTabs[$g('vista')])?$g('vista'):'resumen';
$cuTab=fn(string $vv): string => url(['curso'=>(int)$cu['id']]+($old?['ver'=>$shownVer]:[])+($vv==='resumen'?[]:['vista'=>$vv]));
$cotForm=function() use($cu,$c,$ev): void {
    $clients=query('SELECT id,razon,rut FROM cot_clients ORDER BY razon COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC); ?>
<form method="post" class="pop-body cu-cotform"><?=csrf()?><input type="hidden" name="id" value="<?=(int)$cu['id']?>"><input type="hidden" name="action" value="cu_cot">
  <strong>Cotizar este curso</strong>
  <?php if($clients):?><label>Cliente guardado<select name="cli_saved"><option value="0">— Cliente nuevo (escribir abajo) —</option><?php foreach($clients as $s):?><option value="<?=(int)$s['id']?>"><?=e($s['razon'].($s['rut']!==''?' · '.$s['rut']:''))?></option><?php endforeach?></select></label><?php endif?>
  <label>Cliente nuevo <small>(razón social; opcional)</small><input name="cli_razon" maxlength="300"></label>
  <div class="cu-two"><label>Participantes<input type="number" name="participantes" min="1" step="1" required value="<?=(int)$c['part']?:''?>"></label>
  <label>Precio c/u (neto)<input type="number" name="precio" min="0" step="1" value="<?=(int)$c['precio']?:($ev['sugerido']??'')?>"></label></div>
  <label>Cobrar<select name="modo"><option value="part">Por participante</option><option value="cerrado">Curso cerrado (un solo valor)</option></select></label>
  <div class="cu-two"><label>Desde<input type="date" name="desde"></label><label>Hasta<input type="date" name="hasta"></label></div>
  <label>Lugar <small>(opcional)</small><input name="lugar" maxlength="300"></label>
  <button class="primary"><?=ic('quote')?>Crear cotización</button>
  <p class="hint">Se crea como borrador con el número correlativo, el objeto, los contenidos y las condiciones de siempre. Luego la revisas y la imprimes.</p>
</form>
<?php };
?>
<nav class="crumbs no-print" aria-label="Ruta"><a href="<?=e(url(['tab'=>'cu']))?>">Cursos</a><?=ic('chevron')?><?php if($old):?><a href="<?=e(url(['curso'=>(int)$cu['id']]))?>"><?=e($cu['nombre'])?></a><?=ic('chevron')?><span>Versión <?=$shownVer?></span><?php else:?><span><?=e($cu['nombre'])?></span><?php endif?></nav>
<div class="page-head ps-head">
  <div class="title-row"><a class="icon-btn" href="<?=e(url(['tab'=>'cu']))?>" title="Volver"><?=ic('back')?><span class="sr">Volver</span></a><span class="tile-icon big tone2"><?=ic('list')?></span><div><h1><?=e($c['nombre'])?></h1><p class="subtitle"><?=$cuPill($cu['estado'])?> <?=cuVerdictPill($v)?> <?=e($cu['codigo'])?> · versión <?=$shownVer?><?=$H>0?' · '.e(ocQty($H)).' h':''?><?=$c['modalidad']!==''?' · '.e($c['modalidad']):''?></p></div></div>
  <div class="head-actions">
    <?php if($canEdit && !$old):?><details class="pop"><summary class="btn primary"><?=ic('quote')?>Generar cotización</summary><?php $cotForm()?></details>
    <a class="btn ghost" href="<?=e(url(['curso'=>(int)$cu['id'],'edit'=>1]))?>"><?=ic('edit')?>Editar</a><?php endif?>
    <a class="btn ghost" href="<?=e(url(['cu_print'=>(int)$cu['id']]+($old?['ver'=>$shownVer]:[])))?>" target="_blank" rel="noopener" title="Ficha para el cliente, sin costos"><?=ic('print')?><span class="hide-sm">Ficha</span></a>
    <?php if(!$old):?><details class="pop"><summary class="btn ghost" title="Más opciones"><?=ic('more')?><span class="sr">Más opciones</span></summary><div class="pop-body">
      <div class="stack"><strong>Ficha del curso</strong><a class="btn ghost" href="<?=e(url(['cu_print'=>(int)$cu['id'],'precio'=>1]))?>" target="_blank" rel="noopener"><?=ic('print')?>Ficha con valor por participante</a></div>
      <div class="stack"><strong>Excel</strong><a class="btn ghost" href="<?=e(url(['cu_xlsx'=>'export','id'=>(int)$cu['id']]))?>"><?=ic('download')?>Descargar en Excel</a><p class="hint">Edítalo y súbelo en Carga masiva para guardar una versión nueva, o deja vacía la columna Código para crear otro curso a partir de este.</p></div>
      <?php if($canEdit):?><form method="post" class="stack"><?=csrf()?><input type="hidden" name="id" value="<?=(int)$cu['id']?>"><strong>Estado</strong><label><span class="sr">Estado</span><select name="estado"><?php foreach(CU_ESTADOS as $s):?><option<?=$cu['estado']===$s?' selected':''?>><?=e($s)?></option><?php endforeach?></select></label><button class="ghost" name="action" value="cu_estado">Cambiar estado</button></form>
      <form method="post" class="stack"><?=csrf()?><input type="hidden" name="id" value="<?=(int)$cu['id']?>"><strong>Duplicar</strong><p class="hint">Crea otro curso con la misma malla y costos (por ejemplo, una versión para otra ciudad).</p><button class="ghost" name="action" value="cu_dup"><?=ic('copy')?>Duplicar curso</button></form><?php endif?>
      <?php if($isAdmin):?><form method="post" class="stack danger-zone"><?=csrf()?><input type="hidden" name="id" value="<?=(int)$cu['id']?>"><p>Se elimina el curso con todo su historial. Las cotizaciones generadas no se tocan. Si solo dejó de dictarse, mejor archívalo.</p><button class="danger" name="action" value="cu_delete"><?=ic('trash')?>Eliminar curso</button></form><?php endif?>
    </div></details><?php endif?>
  </div>
</div>
<?php if($old):?><div class="alert" role="status">Estás viendo la <b>versión <?=$shownVer?></b>, guardada el <?=e(fdate($old['created'],true))?> por <?=e(userName($old['owner']!==null?(int)$old['owner']:null)?:'—')?><?=$old['nota']!==''?' («'.e($old['nota']).'»)':''?>. La vigente es la versión <?=(int)$cu['version']?>.
  <span class="cu-alert-actions"><a class="btn ghost small" href="<?=e(url(['curso'=>(int)$cu['id'],'vista'=>'historial']))?>">Volver al historial</a><?php if($canEdit):?><form method="post" class="cc-inline"><?=csrf()?><input type="hidden" name="id" value="<?=(int)$cu['id']?>"><input type="hidden" name="ver" value="<?=$shownVer?>"><button class="primary small" name="action" value="cu_restore">Restaurar esta versión</button></form><?php endif?></span></div><?php endif?>
<?php if($outdated):?><div class="alert cu-warn" role="status"><?=ic('bell')?><span>Los costos fijos cambiaron desde que se guardó esta versión: hoy le corresponderían <b><?=clp(cuFixedSnapshot($H)['total'])?></b> (guardado: <?=clp((int)$c['fijos']['total'])?>).</span><?php if($canEdit):?><form method="post" class="cc-inline"><?=csrf()?><input type="hidden" name="id" value="<?=(int)$cu['id']?>"><button class="ghost small" name="action" value="cu_refresh">Actualizar costos fijos</button></form><?php endif?></div><?php endif?>

<nav class="ps-tabs no-print" aria-label="Secciones del curso"><?php foreach($cuTabs as $vv=>[$lbl,$badge]):?><a href="<?=e($cuTab($vv))?>"<?=$vv===$vista?' class="on" aria-current="page"':''?>><?=$lbl?><?=$badge?></a><?php endforeach?></nav>

<?php if($vista==='resumen'):?>
<section class="cu-verdict cu-vbox<?=$v?>"><span class="cu-verdict-ic"><?=ic($v===3?'check':($v===0?'info':'bell'))?></span><div><b><?=e(CU_VEREDICTOS[$v])?></b><span><?=match($v){
    3=>'El curso cubre sus costos, incluidos los fijos de la empresa, y llega al margen objetivo.',
    2=>'Es rentable, pero hay puntos por mejorar antes de ofrecerlo.',
    1=>'Con estos números el curso pierde dinero o queda bajo el margen mínimo.',
    default=>'Faltan datos para evaluarlo: revisa los puntos marcados abajo.'}?></span></div></section>
<div class="stat-grid">
  <div class="stat"><?=ic('quote')?><strong><?=$ev['precio']>0?clp($ev['precio']):'—'?></strong><span><?=$ev['precio']>0?'Precio por participante (neto)':'Sin precio'.($ev['sugerido']?' · sugerido '.clp($ev['sugerido']):'')?></span></div>
  <div class="stat"><?=ic('trend')?><strong class="<?=$ev['pct']!==null&&$ev['pct']<(float)$cuCfgV['margen_min']?'cc-out':($ev['pct']!==null&&$ev['pct']>=(float)$cuCfgV['margen_obj']?'cc-in':'')?>"><?=psPct($ev['pct'])?></strong><span>Margen<?=$ev['pct']!==null?' · '.ccClp($ev['margen']).' con '.(int)$ev['P'].' participantes':''?></span></div>
  <div class="stat"><?=ic('users')?><strong class="<?=$ev['equilibrio']===null||($ev['equilibrio']>$ev['P'])?'cc-out':''?>"><?=$ev['equilibrio']===null?'—':$ev['equilibrio']?></strong><span>Participantes para no perder<?=$ev['P']?' · previstos '.(int)$ev['P']:''?></span></div>
  <div class="stat"><?=ic('receipt')?><strong><?=clp($ev['costo'])?></strong><span>Costo total · <?=clp($ev['fijos'])?> son fijos de la empresa</span></div>
</div>
<div class="cc-two ps-sum">
<section class="block"><div class="block-head"><h2>Qué dice el evaluador</h2></div><div class="card">
  <ul class="cu-checks"><?php foreach($obs as $o):?><li class="cu-l<?=$o['lvl']?>"><span class="cu-dot"><?=ic($o['lvl']===0?'check':($o['lvl']===1?'info':'bell'))?></span><span><small><?=e($o['area'])?></small><?=e($o['txt'])?>
    <?php if($o['fix'] && $canEdit && !$old):?><form method="post" class="cc-inline"><?=csrf()?><input type="hidden" name="id" value="<?=(int)$cu['id']?>"><input type="hidden" name="precio" value="<?=(int)$o['fix']['precio']?>"><button class="link-btn" name="action" value="cu_precio">Usar <?=clp($o['fix']['precio'])?></button></form><?php endif?></span></li><?php endforeach?></ul>
  <?php if($canEdit && !$old && $nWarn):?><a class="ps-more" href="<?=e(url(['curso'=>(int)$cu['id'],'edit'=>1]))?>">Editar el curso<?=ic('chevron')?></a><?php endif?>
</div></section>
<section class="block"><div class="block-head"><h2>Precio</h2></div><div class="card">
  <dl class="ps-kv">
    <?php $gan=$ev['sugerido']&&$ev['precioMin']!==null?$ev['sugerido']-$ev['precioMin']:null; $ganAct=$ev['precio']>0&&$ev['precioMin']!==null?$ev['precio']-$ev['precioMin']:null;?>
    <?php if($ev['P']>0):?><div><dt>Costos directos por participante<small><?=clp($ev['directo'])?> ÷ <?=(int)$ev['P']?> participantes</small></dt><dd><?=clp($ev['directo']/$ev['P'])?></dd></div>
    <div><dt>+ Costos fijos de la empresa por participante<small><?=clp($ev['fijos'])?> ÷ <?=(int)$ev['P']?> participantes · <?=e(cuFixedText($c['fijos']))?></small></dt><dd><?=clp($ev['fijos']/$ev['P'])?></dd></div><?php endif?>
    <div><dt>= Costo por participante<small>Cubre todos los gastos, incluidos los fijos: con este valor no se gana ni se pierde</small></dt><dd><?=$ev['precioMin']!==null?clp($ev['precioMin']):'—'?></dd></div>
    <div><dt>+ Ganancia por participante<small>Margen objetivo del <?=e(ocQty((float)$cuCfgV['margen_obj']))?>% sobre el precio de venta</small></dt><dd><?=$gan!==null?clp($gan):'—'?></dd></div>
    <div class="ps-kv-total"><dt>= Precio por participante con margen</dt><dd><?=$ev['sugerido']?clp($ev['sugerido']):'—'?></dd></div>
    <div><dt>Precio actual<?=$ganAct!==null?'<small>'.($ganAct>=0?'Deja '.clp($ganAct).' de ganancia por participante':'Pierde '.clp(-$ganAct).' por participante').'</small>':''?></dt><dd class="<?=$ev['precio']>0&&$ev['precioMin']!==null&&$ev['precio']<$ev['precioMin']?'cc-out':''?>"><?=$ev['precio']>0?clp($ev['precio']):'Sin definir'?></dd></div>
    <?php if($ev['costoHora']!==null):?><div><dt>Costo por hora de curso</dt><dd><?=clp($ev['costoHora'])?></dd></div><?php endif?>
  </dl>
  <?php if($canEdit && !$old && $ev['sugerido'] && $ev['sugerido']!==$ev['precio']):?><form method="post" class="cu-apply"><?=csrf()?><input type="hidden" name="id" value="<?=(int)$cu['id']?>"><input type="hidden" name="precio" value="<?=(int)$ev['sugerido']?>"><button class="ghost small" name="action" value="cu_precio">Usar el precio sugerido (<?=clp($ev['sugerido'])?>)</button></form><?php endif?>
  <?=$cuHelp('<p><b>Costo total</b> = costos directos con '.(int)$ev['P'].' participantes ('.clp($ev['directo']).') + costos fijos de la empresa prorrateados ('.clp($ev['fijos']).').</p><p><b>Costo por participante</b> = costo total ÷ participantes: con ese valor el curso no gana ni pierde.</p><p><b>Precio con margen</b> = costo por participante ÷ (1 − margen objetivo), redondeado hacia arriba'.((int)$cuCfgV['redondeo']>0?' a '.clp((int)$cuCfgV['redondeo']):'').'. Con un margen de '.ocQty((float)$cuCfgV['margen_obj']).'%, de cada $ 100 que paga el participante quedan $ '.ocQty((float)$cuCfgV['margen_obj']).' de ganancia.</p><p><b>Participantes para no perder</b> = costos que no dependen de los participantes ÷ (precio − costo por participante).</p>')?>
</div></section>
</div>

<?php elseif($vista==='costos'):
$sc=array_values(array_unique(array_filter([$c['part_min'],$ev['equilibrio'],(int)$c['part'],$c['part_max'],(int)$c['part']>0?max(1,(int)$c['part']-5):0,(int)$c['part']>0?(int)$c['part']+5:0],fn($n)=>$n!==null&&$n>0&&$n<=1000))); sort($sc);
$part=fn(array $l): string => match($l['base']){ 'part'=>' × '.(int)$ev['P'].' part.', 'hora'=>' × '.ocQty($H).' h', 'parthora'=>' × '.(int)$ev['P'].' part. × '.ocQty($H).' h', default=>'' };
?>
<div class="cc-two">
<section class="block"><div class="block-head"><h2>Costos directos</h2><?php if($canEdit && !$old):?><a class="btn ghost small" href="<?=e(url(['curso'=>(int)$cu['id'],'edit'=>1]))?>"><?=ic('edit')?>Editar</a><?php endif?></div>
<?php if($ev['lines']):?><div class="table-wrap"><table class="cc-table"><thead><tr><th>Concepto</th><th class="right">Monto</th></tr></thead><tbody>
<?php foreach($ev['lines'] as $l):?><tr><td><?=e($l['desc']?:'—')?><small><?=e(ocQty($l['cant']))?><?=$l['unidad']!==''?' '.e($l['unidad']):''?> × <?=clp($l['precio'])?><?=$l['ret']>0?' líquido (bruto '.clp(psBrutoUnit((int)$l['precio'],(float)$l['ret'])).')':''?><?=e($part($l))?></small></td><td class="right nowrap"><b><?=clp($l['monto'])?></b></td></tr><?php endforeach?>
</tbody><tfoot><tr><td>Total directos<small>Variable: <?=clp($ev['varPart'])?> por participante</small></td><td class="right nowrap"><?=clp($ev['directo'])?></td></tr></tfoot></table></div>
<?php else:?><p class="hint">Sin costos directos.</p><?php endif?></section>
<section class="block"><div class="block-head"><h2>Costos fijos de la empresa</h2><?php if($isAdmin):?><a class="btn ghost small" href="<?=e(url(['tab'=>'cu_cfg']))?>"><?=ic('settings')?>Configurar</a><?php endif?></div>
<?php $f=$c['fijos']; if($f['items']):?><div class="table-wrap"><table class="cc-table"><thead><tr><th>Concepto</th><th class="right hide-sm">Mensual</th><th class="right">Carga al curso</th></tr></thead><tbody>
<?php foreach($f['items'] as $it):?><tr><td><?=e($it['nombre'])?></td><td class="right nowrap hide-sm"><?=clp($it['mensual'])?></td><td class="right nowrap"><b><?=clp($it['cuota'])?></b></td></tr><?php endforeach?>
</tbody><tfoot><tr><td>Total fijos<small><?=e(cuFixedText($f))?><?=$f['fecha']!==''?' · valores al '.e(ocDate($f['fecha'])):''?></small></td><td class="right nowrap hide-sm"><?=clp($f['mensual'])?></td><td class="right nowrap"><?=clp($f['total'])?></td></tr></tfoot></table></div>
<?php else:?><p class="hint">Sin costos fijos. <?=$isAdmin?'<a href="'.e(url(['tab'=>'cu_cfg'])).'">Configúralos</a> (contadora, administración, arriendo de oficina…) para que el margen sea real.':'El administrador los configura en Cursos → Costos fijos.'?></p><?php endif?>
</section>
</div>
<section class="block"><div class="block-head"><h2>Escenarios según participantes</h2></div>
<?php if($sc):?><div class="table-wrap"><table class="cc-table cu-sc"><thead><tr><th>Participantes</th><th class="right">Costo total<small>directos + fijos</small></th><th class="right">Costo por participante</th><th class="right">Precio con margen <?=e(ocQty((float)$cuCfgV['margen_obj']))?>%</th><?php if($ev['precio']>0):?><th class="right hide-sm">Ingreso a <?=clp($ev['precio'])?></th><th class="right">Resultado</th><th class="right">Margen</th><?php endif?></tr></thead><tbody>
<?php foreach($sc as $n): $e2=cuEval($c,$n); $tags=array_filter([$n===(int)$c['part']?'previstos':'',$n===$c['part_min']?'mínimo':'',$n===$c['part_max']?'cupo':'',$n===$ev['equilibrio']&&$ev['precio']>0?'equilibrio':'']);?><tr<?=$n===(int)$c['part']?' class="cu-cur"':''?>><td><b><?=$n?></b><?=$tags?' <small class="cu-tag">'.e(implode(' · ',$tags)).'</small>':''?></td><td class="right nowrap"><?=clp($e2['costo'])?><small><?=clp($e2['directo'])?> + <?=clp($e2['fijos'])?> fijos</small></td><td class="right nowrap"><?=$e2['precioMin']!==null?clp($e2['precioMin']):'—'?></td><td class="right nowrap"><b><?=$e2['sugerido']?clp($e2['sugerido']):'—'?></b><?php if($e2['sugerido'] && $e2['precioMin']!==null):?><small>ganancia <?=clp($e2['sugerido']-$e2['precioMin'])?> c/u</small><?php endif?></td><?php if($ev['precio']>0):?><td class="right nowrap hide-sm"><?=clp($e2['ingreso'])?></td><td class="right nowrap <?=$e2['margen']<0?'cc-out':'cc-in'?>"><?=ccClp($e2['margen'])?></td><td class="right nowrap <?=$e2['pct']!==null&&$e2['pct']<(float)$cuCfgV['margen_min']?'cc-out':''?>"><?=psPct($e2['pct'])?></td><?php endif?></tr><?php endforeach?>
</tbody></table></div>
<?=$cuHelp('<p>Cada fila recalcula el curso con esa cantidad de alumnos. Los costos que dependen de los participantes (materiales, alimentación…) cambian con la cantidad; el relator, la sala y los costos fijos de la empresa no, así que se reparten entre más o menos personas.</p><p><b>Costo por participante</b>: lo mínimo que debe pagar cada uno para no perder. <b>Precio con margen</b>: lo que debería cobrarse a cada uno para ganar el margen objetivo con esa cantidad de alumnos.'.($ev['precio']>0?' <b>Resultado</b> y <b>Margen</b>: lo que deja el precio actual ('.clp($ev['precio']).') con esa cantidad.':'').'</p>','¿Cómo leer los escenarios?')?>
<?php else:?><p class="hint">Indica los participantes previstos para ver los escenarios.</p><?php endif?></section>

<?php elseif($vista==='contenido'): $T=(float)array_sum(array_column($c['modulos'],'teo')); $Pr=(float)array_sum(array_column($c['modulos'],'pra'));?>
<section class="block"><div class="block-head"><h2>Ficha</h2><a class="btn ghost small" href="<?=e(url(['cu_print'=>(int)$cu['id']]+($old?['ver'=>$shownVer]:[])))?>" target="_blank" rel="noopener"><?=ic('print')?>Imprimir ficha</a></div><div class="card"><dl class="cc-dl">
  <div class="span-all"><dt>Objetivo general</dt><dd><?=e($c['objetivo']?:'—')?></dd></div>
  <div><dt>Área</dt><dd><?=e($c['area']?:'—')?></dd></div>
  <div><dt>Modalidad</dt><dd><?=e($c['modalidad']?:'—')?></dd></div>
  <div><dt>Duración</dt><dd><?=$H>0?e(ocQty($H)).' horas':'—'?></dd></div>
  <div><dt>Código SENCE u OMI</dt><dd><?=e($c['sence']?:'—')?></dd></div>
  <div><dt>Dirigido a</dt><dd><?=e($c['dirigido']?:'—')?></dd></div>
  <div><dt>Requisitos</dt><dd><?=e($c['requisitos']?:'—')?></dd></div>
  <div><dt>Evaluación y aprobación</dt><dd><?=e($c['evaluacion']?:'—')?></dd></div>
  <div><dt>Certificación</dt><dd><?=e($c['certificacion']?:'—')?></dd></div>
  <div><dt>Relator</dt><dd><?=e($c['relator']?:'—')?></dd></div>
  <div><dt>Participantes</dt><dd><?=(int)$c['part']?:'—'?><?php if($c['part_min']||$c['part_max']):?><small class="muted"><?=$c['part_min']?'mínimo '.(int)$c['part_min']:''?><?=$c['part_min']&&$c['part_max']?' · ':''?><?=$c['part_max']?'cupo '.(int)$c['part_max']:''?></small><?php endif?></dd></div>
  <div><dt>Creado por</dt><dd><?=e(userName($cu['owner']!==null?(int)$cu['owner']:null)?:'—')?><small class="muted"><?=e(fdate($cu['created'],true))?></small></dd></div>
</dl></div></section>
<section class="block"><div class="block-head"><h2>Malla <small><?=count($c['modulos'])?> módulos</small></h2></div>
<?php if($c['modulos']):?><div class="table-wrap"><table class="cc-table"><thead><tr><th>#</th><th>Módulo y contenidos</th><th class="right">Teo.</th><th class="right">Prác.</th><th class="right">Total</th></tr></thead><tbody>
<?php foreach($c['modulos'] as $i=>$m):?><tr><td class="muted"><?=$i+1?></td><td><b><?=e($m['nombre'])?></b><?php if($m['contenidos']!==''):?><small><?=e($m['contenidos'])?></small><?php endif?></td><td class="right"><?=e(ocQty($m['teo']))?></td><td class="right"><?=e(ocQty($m['pra']))?></td><td class="right"><b><?=e(ocQty($m['teo']+$m['pra']))?></b></td></tr><?php endforeach?>
</tbody><tfoot><tr><td></td><td>Total<?=$c['horas']>0&&abs(cuModHoras($c)-$c['horas'])>0.05?'<small class="cc-out">El curso indica '.e(ocQty((float)$c['horas'])).' h</small>':''?></td><td class="right"><?=e(ocQty($T))?></td><td class="right"><?=e(ocQty($Pr))?></td><td class="right"><?=e(ocQty($T+$Pr))?></td></tr></tfoot></table></div>
<?php else:?><div class="empty-state"><?=ic('list')?><b>Sin malla</b><span><?=$canEdit&&!$old?'<a href="'.e(url(['curso'=>(int)$cu['id'],'edit'=>1])).'">Agrega los módulos</a> con sus horas y contenidos.':'Este curso aún no tiene módulos.'?></span></div><?php endif?></section>

<?php elseif($vista==='cotizaciones'):?>
<section class="block"><div class="block-head"><h2>Cotizaciones generadas desde este curso</h2></div>
<?php if($quotes):?><div class="table-wrap"><table class="cc-table"><thead><tr><th>N°</th><th>Cliente</th><th class="right">Total</th><th>Estado</th><th class="hide-sm">Versión del curso</th></tr></thead><tbody>
<?php foreach($quotes as $q): if($q['id']===null):?><tr><td colspan="5" class="muted">Cotización eliminada (generada el <?=e(fdate($q['created']))?> con la versión <?=(int)$q['version']?>)</td></tr><?php continue; endif; $qq=['estado'=>$q['estado'],'vence'=>$q['vence']];?><tr>
  <td class="nowrap"><a class="oc-num" href="<?=e(url(['cot'=>(int)$q['id']]))?>"><?=e($q['numero'])?></a><small><?=e(fdate($q['created']))?></small></td><td><?=e($q['cli_razon']?:'Sin cliente')?></td><td class="right nowrap"><b><?=clp((int)$q['total'])?></b></td>
  <td class="nowrap"><?=cotVencida($qq)?'<span class="oc-pill st-vencida">Vencida</span>':'<span class="oc-pill '.ocEstadoClass($q['estado']).'">'.e($q['estado']).'</span>'?></td><td class="hide-sm">v<?=(int)$q['version']?></td></tr><?php endforeach?>
</tbody></table></div>
<?php else:?><div class="empty-state"><?=ic('quote')?><b>Aún no se cotiza este curso</b><span><?=$canEdit&&!$old?'Usa «Generar cotización» arriba: se crea en un clic con los datos del curso.':'Cuando se cotice, aparecerá aquí.'?></span></div><?php endif?></section>

<?php else: $vers=query('SELECT * FROM cu_versions WHERE course_id=? ORDER BY version DESC',[$cu['id']])->fetchAll(PDO::FETCH_ASSOC);?>
<section class="block"><div class="block-head"><h2>Historial de versiones</h2></div>
<div class="table-wrap"><table class="cc-table cu-hist"><thead><tr><th>Versión</th><th>Cambio</th><th class="right">Precio</th><th class="right hide-sm">Costo total</th><th class="right">Margen</th><th class="hide-sm">Evaluación</th><th class="right">Acciones</th></tr></thead><tbody>
<?php foreach($vers as $vr): $cur=(int)$vr['version']===(int)$cu['version'];?><tr<?=(int)$vr['version']===$shownVer?' class="cu-cur"':''?>>
  <td class="nowrap"><b>v<?=(int)$vr['version']?></b><?=$cur?' <span class="oc-pill st-aprobada">Vigente</span>':''?><small><?=e(fdate($vr['created'],true))?></small></td>
  <td><?=e($vr['nota']?:'—')?><small><?=e(userName($vr['owner']!==null?(int)$vr['owner']:null))?></small></td>
  <td class="right nowrap"><?=(int)$vr['precio']>0?clp((int)$vr['precio']):'—'?></td><td class="right nowrap hide-sm"><?=clp((int)$vr['costo'])?></td>
  <td class="right nowrap"><?=psPct($vr['margen']!==null?(float)$vr['margen']:null)?></td><td class="hide-sm"><?=cuVerdictPill((int)$vr['veredicto'])?></td>
  <td class="right nowrap"><?php if(!$cur):?><a class="icon-btn" href="<?=e(url(['curso'=>(int)$cu['id'],'ver'=>(int)$vr['version']]))?>" title="Ver versión <?=(int)$vr['version']?>"><?=ic('eye')?><span class="sr">Ver</span></a><?php if($canEdit):?><form method="post" class="cc-inline"><?=csrf()?><input type="hidden" name="id" value="<?=(int)$cu['id']?>"><input type="hidden" name="ver" value="<?=(int)$vr['version']?>"><button class="icon-btn" name="action" value="cu_restore" title="Restaurar versión <?=(int)$vr['version']?>"><?=ic('switch')?><span class="sr">Restaurar</span></button></form><?php endif?><?php else:?><span class="muted">—</span><?php endif?></td>
</tr><?php endforeach?></tbody></table></div>
<?=$cuHelp('<p>Cada vez que guardas el curso se crea una versión nueva; las anteriores no se modifican. Precio, costo y margen de cada fila son los que tenía esa versión, con los costos fijos vigentes en ese momento.</p><p><b>Restaurar</b> copia una versión anterior como versión nueva: nada se borra.</p>','¿Cómo funciona el historial?')?></section>
<?php endif?>
<?php endif; endif?>
<?php endif?>
<?php if($mode==='cu_import'):
$imp=$_SESSION['cu_import']??null; if($imp && ($imp['empresa']??'')!==empresa()['key']) $imp=null;
$fail=$_SESSION['cu_import_fail']??null; if($imp || ($fail && ($fail['empresa']??'')!==empresa()['key'])) $fail=null;
$cuPasos=['Archivo recibido','Formato .xlsx','Planilla de Excel válida y sin macros','Lectura del contenido','Hoja «Cursos» con las columnas de la plantilla','Hay cursos para cargar','Revisión fila por fila'];
?>
<nav class="crumbs" aria-label="Ruta"><a href="<?=e(url(['tab'=>'cu']))?>">Cursos</a><?=ic('chevron')?><span>Carga masiva</span></nav>
<?php $cuHead('Carga masiva de cursos','Descarga la planilla, complétala en Excel y súbela. Antes de guardar verás cada curso con su evaluación y los errores por fila.')?>
<?php if(!$imp):?>
<?php if($fail): $fp=(int)$fail['paso'];?>
<section class="card cu-rev cu-rev-fail" aria-labelledby="cu-rev-t"><h2 id="cu-rev-t"><?=ic('bell')?>La planilla no se pudo cargar<?=$fail['archivo']!==''?' · <span class="muted">'.e($fail['archivo']).'</span>':''?></h2>
  <ol class="cu-check"><?php foreach($cuPasos as $k=>$t):?><li class="<?=$k<$fp?'ok':($k===$fp?'bad':'off')?>"><span class="cu-mk" aria-hidden="true"><?=$k<$fp?'✓':($k===$fp?'✕':'○')?></span><span><?=e($t)?><?php if($k===$fp):?><b><?=e($fail['motivo'])?></b><?php elseif($k>$fp):?><small>No se revisó</small><?php endif?></span></li><?php endforeach?></ol>
  <p class="hint">Corrige lo indicado y sube la planilla de nuevo en el paso 3. No se guardó nada.</p>
  <form method="post" class="cc-inline"><?=csrf()?><button class="ghost" name="action" value="cu_import_fail_ok">Ocultar</button></form></section>
<?php endif?>
<div class="cu-steps">
  <section class="card"><h2><span class="ps-step">1</span>Descarga la planilla</h2>
    <p class="hint">Trae las hojas Cursos, Módulos y Costos, un ejemplo y dos catálogos con los costos y módulos que ya usaste (con su último precio) para copiarlos.</p>
    <div class="cu-dl"><a class="btn primary" href="<?=e(url(['cu_xlsx'=>'plantilla']))?>"><?=ic('download')?>Plantilla en blanco</a><a class="btn ghost" href="<?=e(url(['cu_xlsx'=>'export']))?>"><?=ic('download')?>Mis cursos vigentes</a></div>
    <p class="hint">«Mis cursos vigentes» descarga tus cursos con su código: cámbialos y súbelos para guardar versiones nuevas, o borra el código para crear cursos nuevos a partir de ellos.</p></section>
  <section class="card"><h2><span class="ps-step">2</span>Complétala</h2>
    <ul class="cu-tips"><li>Una fila por curso en <b>Cursos</b>; una fila por módulo o costo en <b>Módulos</b> y <b>Costos</b>, con el nombre o código del curso en la columna A.</li>
    <li><b>Copiar de</b>: escribe el código de un curso existente y el nuevo toma sus módulos, costos y todo lo que dejes en blanco.</li>
    <li>Con código en la columna A, el curso se actualiza como <b>versión nueva</b>; el historial se conserva.</li></ul></section>
  <section class="card"><h2><span class="ps-step">3</span>Súbela</h2>
    <form method="post" enctype="multipart/form-data" class="stack"><?=csrf()?><label>Planilla .xlsx<input type="file" name="archivo" accept=".xlsx" required></label><button class="primary" name="action" value="cu_import"><?=ic('upload')?>Revisar planilla</button></form>
    <p class="hint">Todavía no se guarda nada: primero verás la vista previa. Máximo <?=CU_X_MAX?> cursos y 5 MB.</p></section>
</div>
<?php else: $items=$imp['items']; $ok=array_filter($items,fn($i)=>!$i['errores']); $bad=count($items)-count($ok);
$nNew=count(array_filter($ok,fn($i)=>$i['accion']==='nuevo')); $nVer=count(array_filter($ok,fn($i)=>$i['accion']==='version'&&!$i['igual'])); $nEq=count(array_filter($ok,fn($i)=>$i['accion']==='version'&&$i['igual']));?>
<div class="stat-grid">
  <div class="stat"><?=ic('plus')?><strong><?=$nNew?></strong><span>Cursos nuevos</span></div>
  <div class="stat"><?=ic('switch')?><strong><?=$nVer?></strong><span>Actualizados (versión nueva)<?=$nEq?' · '.$nEq.' sin cambios':''?></span></div>
  <div class="stat"><?=ic('bell')?><strong class="<?=$bad?'cc-out':''?>"><?=$bad?></strong><span>Con errores (no se cargan)</span></div>
  <div class="stat"><?=ic('receipt')?><strong><?=count($items)?></strong><span>Filas en «<?=e($imp['archivo'])?>»</span></div>
</div>
<?php $iss=$imp['issues']??[]; $nErr=count(array_filter($iss,fn($x)=>$x['nivel']==='error')); $nAv=count($iss)-$nErr; $shown=array_slice($iss,0,300);?>
<section class="card cu-rev<?=$nErr?' cu-rev-fail':''?>" aria-labelledby="cu-rev-t">
  <div class="cu-rev-head"><h2 id="cu-rev-t"><?=ic($nErr?'bell':'check')?>Revisión de la planilla</h2>
    <?php if(!empty($imp['book'])):?><a class="btn ghost" href="<?=e(url(['cu_xlsx'=>'revision']))?>"><?=ic('download')?>Descargar planilla con errores marcados</a><?php endif?></div>
  <ol class="cu-check cu-check-row"><?php foreach($cuPasos as $k=>$t):?><li class="<?=$k<6||!$nErr?'ok':'bad'?>"><span class="cu-mk" aria-hidden="true"><?=$k<6||!$nErr?'✓':'✕'?></span><span><?=e($t)?></span></li><?php endforeach?></ol>
  <p><?=$nErr?'<b class="cc-out">'.$nErr.' error'.($nErr===1?'':'es').'</b>':'<b>Sin errores</b>'?><?=$nAv?' y '.$nAv.' aviso'.($nAv===1?'':'s'):''?>. <?=$nErr?'Las filas con error no se cargan; el resto sí se puede confirmar. En la planilla descargada, cada fila con problema queda en color, la celda exacta en rojo y el motivo en la última columna: corrígela ahí y súbela de nuevo.':($nAv?'Los avisos no impiden la carga.':'Todo listo para confirmar.')?></p>
  <?php if($shown):?><div class="table-wrap"><table class="cc-table cu-iss"><thead><tr><th>Nivel</th><th>Hoja</th><th class="right">Fila</th><th>Col.</th><th class="hide-sm">Valor escrito</th><th>Problema</th><th class="hide-sm">Curso</th></tr></thead><tbody>
  <?php foreach($shown as $x):?><tr><td><span class="ps-lvl <?=$x['nivel']==='error'?'cu-v1':'cu-v2'?>"><?=$x['nivel']==='error'?'Error':'Aviso'?></span></td><td class="nowrap"><?=e($x['hoja'])?></td><td class="right"><?=(int)$x['fila']?></td><td><?=e($x['col'])?:'<span class="muted">—</span>'?></td><td class="hide-sm"><?=$x['valor']!==''?'<code>'.e($x['valor']).'</code>':'<span class="muted">vacío</span>'?></td><td><?=e($x['motivo'])?></td><td class="hide-sm muted"><?=e($x['curso'])?></td></tr><?php endforeach?>
  </tbody></table></div><?php if(count($iss)>300):?><p class="hint">Se muestran los primeros 300; la planilla descargada trae todos (<?=count($iss)?>).</p><?php endif?><?php endif?>
</section>
<div class="table-wrap"><table class="cc-table cu-imp"><thead><tr><th>Fila</th><th>Curso</th><th class="right hide-sm">Módulos · horas</th><th class="right hide-sm">Costos</th><th class="right">Precio</th><th class="right">Margen</th><th>Evaluación</th></tr></thead><tbody>
<?php foreach($items as $it): $c=$it['c'];?><tr<?=$it['errores']?' class="ps-over"':''?>>
  <td class="nowrap muted"><?=(int)$it['row']?></td>
  <td><b><?=e($c['nombre']?:'(sin nombre)')?></b><small><?=$it['errores']?'<span class="cc-out">No se carga</span>':($it['accion']==='nuevo'?'Curso nuevo':($it['igual']?'Sin cambios en '.e($it['codigo']):'Versión nueva de '.e($it['codigo'])))?><?=$c['area']!==''?' · '.e($c['area']):''?></small>
    <?php foreach($it['errores'] as $m):?><small class="cc-out">✕ <?=e($m)?></small><?php endforeach?><?php foreach($it['avisos'] as $m):?><small class="cu-note">• <?=e($m)?></small><?php endforeach?></td>
  <td class="right nowrap hide-sm"><?=count($c['modulos'])?> · <?=e(ocQty(cuHoras($c)))?> h</td>
  <td class="right nowrap hide-sm"><?=count($c['costos'])?></td>
  <td class="right nowrap"><?=$c['precio']>0?clp($c['precio']):'<span class="muted">—</span>'?><?php if(!$c['precio'] && $it['sugerido']):?><small>sugerido <?=clp($it['sugerido'])?></small><?php endif?></td>
  <td class="right nowrap"><?=psPct($it['pct'])?></td>
  <td class="nowrap"><?=cuVerdictPill((int)$it['veredicto'])?></td>
</tr><?php endforeach?></tbody></table></div>
<div class="oc-actions"><?php if($ok && ($nNew+$nVer)>0):?><form method="post" class="cc-inline"><?=csrf()?><input type="hidden" name="token" value="<?=e($imp['token'])?>"><button class="primary" name="action" value="cu_import_ok"><?=ic('check')?>Confirmar: guardar <?=$nNew+$nVer?> curso<?=$nNew+$nVer===1?'':'s'?></button></form><?php endif?>
<form method="post" class="cc-inline"><?=csrf()?><button class="ghost" name="action" value="cu_import_cancel">Cancelar y subir otra planilla</button></form></div>
<?=$cuHelp('<p>La evaluación usa los costos fijos y márgenes vigentes. Los cursos con errores no se guardan: corrígelos en la planilla y súbela de nuevo (los que ya se cargaron, súbelos con su código o quítalos del archivo para no duplicarlos).</p>','¿Qué pasa al confirmar?')?>
<?php endif?>
<?php endif?>
