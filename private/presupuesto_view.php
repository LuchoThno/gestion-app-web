<?php
// Pantallas del módulo de presupuestos. Se incluye desde view.php ($mode empieza con "ps").
$centers=ccCenters(); $ccCfg=ccCfg();
$psPill=fn(string $s): string => '<span class="oc-pill '.psEstadoClass($s).'">'.e($s).'</span>';
$psPosted=$_SERVER['REQUEST_METHOD']==='POST' && $error!=='' && post('action')==='ps_save';
$psProjects=query('SELECT id,name FROM projects ORDER BY name COLLATE NOCASE')->fetchAll(PDO::FETCH_KEY_PAIR);
$psHead=function(string $title, string $sub, string $actions=''): void { ?>
<div class="page-head"><div><span class="eyebrow muted">PRESUPUESTOS</span><h1><?=$title?></h1><p class="subtitle"><?=$sub?></p></div><?php if($actions):?><div class="head-actions"><?=$actions?></div><?php endif?></div>
<?php };
/** Enlace a los movimientos contables que imputan a una partida. */
$psMovsUrl=fn(array $b, array $q=[]): string => url(array_filter(['tab'=>'cc_movs','centro'=>(int)$b['center_id'],'desde'=>$b['desde'],'hasta'=>$b['hasta']]+$q,fn($v)=>$v!==''&&$v!==0));

/** Ayuda plegable: las explicaciones largas quedan a un clic y no compiten con los datos. */
$psHelp=fn(string $html, string $t='¿Cómo se calcula?'): string => '<details class="ps-help"><summary>'.ic('info').$t.'</summary><div>'.$html.'</div></details>';

/** Plantilla de partidas típicas de un curso (solo las categorías que existen en la configuración contable). */
$psTemplate=function() use($ccCfg): array {
    $t=[['ingreso','Venta de cursos','Valor por participante','1','alumno','part'],
        ['gasto','Honorarios','Relator(es), valor hora líquido','1','hora','hora','liq'],
        ['gasto','Arriendo','Sala, taller o espacio de práctica','1','día','fijo'],
        ['gasto','Materiales e insumos','Manual y materiales del participante','1','kit','part'],
        ['gasto','Alimentación','Coffee break o almuerzo','1','servicio','part'],
        ['gasto','Transporte y pasajes','Traslado del relator','1','viaje','fijo'],
        ['gasto','Alojamiento','Alojamiento del relator','1','noche','fijo'],
        ['gasto','Servicios externos','Certificación, plataforma o equipos','1','','fijo'],
        ['gasto','Otros gastos','Imprevistos','1','','fijo']];
    $out=[]; foreach($t as $row){ [$tipo,$cat,$d,$c,$u,$base]=$row; if(in_array($cat,$tipo==='ingreso'?$ccCfg['cat_ingreso']:$ccCfg['cat_gasto'],true)) $out[]=['tipo'=>$tipo,'categoria'=>$cat,'descripcion'=>$d,'cantidad'=>$c,'unidad'=>$u,'precio'=>'','base'=>$base,'ret'=>($row[6]??'')==='liq'?1:0]; }
    return $out;
};
$psPostedRows=function(): array {
    [$cats,$ds,$cs,$us,$ps,$bs,$rs]=[ocList('ln_cat'),ocList('ln_desc'),ocList('ln_cant'),ocList('ln_unidad'),ocList('ln_precio'),ocList('ln_base'),ocList('ln_ret')]; $o=[];
    foreach($cats as $i=>$c){ if($c===''&&($ds[$i]??'')===''&&($ps[$i]??'')==='') continue; [$t,$k]=array_pad(explode('|',$c,2),2,''); $o[]=['tipo'=>$t,'categoria'=>$k,'descripcion'=>$ds[$i]??'','cantidad'=>$cs[$i]??'','unidad'=>$us[$i]??'','precio'=>$ps[$i]??'','base'=>$bs[$i]??'fijo','ret'=>($rs[$i]??'')==='liq'?1:0]; }
    return $o;
};
$psPostedHead=fn(): array => ['nombre'=>post('nombre'),'tipo'=>post('tipo'),'codigo'=>post('codigo'),'cliente'=>post('cliente'),'project_id'=>(int)post('project_id'),'center_id'=>post('center_id'),'desde'=>post('desde'),'hasta'=>post('hasta'),'participantes'=>post('participantes'),'horas'=>post('horas'),'alerta'=>post('alerta'),'obs'=>post('obs')];

/** Formulario del presupuesto (sin JavaScript: filas vacías extra; «Guardar y seguir editando» agrega más). */
$psForm=function(array $h, array $rows, int $id) use($centers, $ccCfg, $psProjects, $psHelp): void {
    $cats=['ingreso'=>$ccCfg['cat_ingreso'],'gasto'=>$ccCfg['cat_gasto']];
    foreach($rows as $r) if(isset($cats[$r['tipo']]) && $r['categoria']!=='' && !in_array($r['categoria'],$cats[$r['tipo']],true)) $cats[$r['tipo']][]=$r['categoria'];
    $extra=max(3,8-count($rows)); for($i=0;$i<$extra;$i++) $rows[]=['tipo'=>'','categoria'=>'','descripcion'=>'','cantidad'=>'','unidad'=>'','precio'=>'','base'=>'fijo','ret'=>0];
    $num=fn($v)=>($v===''||$v===null||(float)$v==0.0)?'':ocNumIn(is_numeric($v)?(float)$v:$v); ?>
<form method="post" class="oc-form"><?=csrf()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="ps_save">
<section class="card"><h2><span class="ps-step">1</span>Curso o proyecto</h2><div class="oc-grid">
  <label class="span2">Nombre del presupuesto<input name="nombre" maxlength="120" required value="<?=e($h['nombre'])?>" placeholder="Ej: Curso Operador de Grúa Horquilla, Punta Arenas, noviembre 2026"></label>
  <label>Tipo<select name="tipo"><?php foreach(PS_TIPOS as $t):?><option<?=$h['tipo']===$t?' selected':''?>><?=e($t)?></option><?php endforeach?></select></label>
  <label>Código <small>(SENCE, interno u otro; opcional)</small><input name="codigo" maxlength="40" value="<?=e($h['codigo'])?>"></label>
  <label class="span2">Cliente o mandante <small>(opcional)</small><input name="cliente" maxlength="160" value="<?=e($h['cliente'])?>" placeholder="Ej: empresa, OTIC o SENCE"></label>
  <label>Inicio<input type="date" name="desde" value="<?=e($h['desde'])?>"></label>
  <label>Término<input type="date" name="hasta" value="<?=e($h['hasta'])?>"></label>
  <label>Participantes previstos<input type="number" name="participantes" min="0" step="1" value="<?=e($num($h['participantes']))?>" placeholder="Ej: 15"></label>
  <label>Horas del curso o proyecto<input type="number" name="horas" min="0" step="0.5" value="<?=e($num($h['horas']))?>" placeholder="Ej: 24"></label>
  <label class="span2">Observaciones <small>(opcional)</small><textarea name="obs" maxlength="1000" rows="2"><?=e($h['obs'])?></textarea></label>
</div></section>

<section class="card"><h2><span class="ps-step">2</span>Enlace con la contabilidad</h2><div class="oc-grid">
  <label>Proyecto <small>(de la gestión documental)</small><select name="project_id"><option value="0">— Sin proyecto —</option><?php foreach($psProjects as $pid=>$pn):?><option value="<?=$pid?>"<?=(int)$h['project_id']===(int)$pid?' selected':''?>><?=e($pn)?></option><?php endforeach?></select></label>
  <label>Centro de costo<select name="center_id"><option value="auto"<?=$h['center_id']==='auto'?' selected':''?>>Usar el del proyecto o crear uno con su nombre</option><option value=""<?=$h['center_id']===''?' selected':''?>>— Sin centro (no se controla la ejecución) —</option><?php foreach($centers as $cid=>$c): if(!$c['activo'] && (string)$cid!==(string)$h['center_id']) continue;?><option value="<?=$cid?>"<?=(string)$cid===(string)$h['center_id']?' selected':''?>><?=e(ccCenterLabel($c))?></option><?php endforeach?></select></label>
  <label>Avisar al llegar al <small>(% del gasto presupuestado)</small><input type="number" name="alerta" min="50" max="100" step="1" value="<?=e((string)$h['alerta'])?>"></label>
</div><?=$psHelp('<p>La ejecución se mide con los ingresos y gastos registrados en Contabilidad con este <b>centro de costo</b> y fecha dentro del período (sin fechas, se considera todo).</p><p>Usa un centro propio para cada curso o proyecto: así cada gasto queda trazado a su presupuesto.</p>','¿Para qué sirve el centro de costo?')?></section>

<section class="card"><h2><span class="ps-step">3</span>Partidas</h2>
<div class="ps-lines"><div class="ps-line ps-line-head"><span>Partida (categoría)</span><span>Descripción</span><span>Cant.</span><span>Unidad</span><span>Precio unit. ($)</span><span>El precio es</span><span>Se multiplica por</span></div>
<?php foreach($rows as $r): $sel=$r['tipo']!==''?$r['tipo'].'|'.$r['categoria']:'';?><div class="ps-line">
  <label><span class="sr">Partida</span><select name="ln_cat[]"><option value="">— Partida —</option><?php foreach(['ingreso'=>'Ingresos','gasto'=>'Gastos'] as $t=>$lbl):?><optgroup label="<?=$lbl?>"><?php foreach($cats[$t] as $c): $v=$t.'|'.$c;?><option value="<?=e($v)?>"<?=$sel===$v?' selected':''?>><?=e($c)?></option><?php endforeach?></optgroup><?php endforeach?></select></label>
  <label><span class="sr">Descripción</span><input name="ln_desc[]" maxlength="160" value="<?=e($r['descripcion'])?>" placeholder="Descripción"></label>
  <label><span class="sr">Cantidad</span><input type="number" name="ln_cant[]" min="0" step="any" value="<?=e($num($r['cantidad']))?>" placeholder="1"></label>
  <label><span class="sr">Unidad</span><input name="ln_unidad[]" maxlength="30" value="<?=e($r['unidad'])?>" placeholder="Unidad"></label>
  <label><span class="sr">Precio unitario</span><input type="number" name="ln_precio[]" min="0" step="1" value="<?=e($num($r['precio']))?>" placeholder="$"></label>
  <label><span class="sr">El precio es</span><select name="ln_ret[]"><option value="">Bruto o neto</option><option value="liq"<?=($r['ret']??0)>0?' selected':''?>>Líquido + ret.</option></select></label>
  <label><span class="sr">Se multiplica por</span><select name="ln_base[]"><?php foreach(PS_BASES as $k=>$lbl):?><option value="<?=$k?>"<?=$r['base']===$k?' selected':''?>><?=e($lbl)?></option><?php endforeach?></select></label>
</div><?php endforeach?></div>
<p class="hint">Monto = cantidad × precio unitario, multiplicado por participantes u horas según la última columna. Las filas sin precio no se guardan. En honorarios con boleta, elige <b>Líquido + ret.</b> si el valor acordado con el relator es líquido: el monto suma la retención del <?=e(psRetLabel(psRetRate()))?>.</p>
<?=$psHelp('<p><b>Monto = cantidad × precio</b>, multiplicado por los participantes, las horas o ambos según la última columna. Ejemplo: relator 1 × $ 35.000 × 24 horas; coffee 2 × $ 3.500 × 15 participantes; valor SENCE por hora participante: 1 × $ 6.000 × participantes × horas. Las filas sin precio no se guardan (para quitar una partida, borra su precio). Las partidas usan las mismas categorías que los ingresos y gastos, así cada una se compara con lo registrado en Contabilidad.</p>','¿Cómo se calcula el monto?')?></section>
<div class="oc-actions"><button class="primary">Guardar</button><button class="ghost" name="after" value="edit">Guardar y seguir editando</button><a class="btn ghost" href="<?=e($id?url(['pres'=>$id]):url(['tab'=>'ps']))?>">Cancelar</a></div>
</form>
<?php };
?>

<?php if($mode==='ps'):
$fe=in_array($g('estado'),PS_ESTADOS,true)?$g('estado'):''; $ft=in_array($g('tipo'),PS_TIPOS,true)?$g('tipo'):''; $q=ocText($g('q'),80);
$w=[]; $p=[]; if($fe){ $w[]='b.estado=?'; $p[]=$fe; } if($ft){ $w[]='b.tipo=?'; $p[]=$ft; }
if($q!==''){ $w[]='(b.numero LIKE ? OR b.nombre LIKE ? OR b.cliente LIKE ? OR b.codigo LIKE ? OR pr.name LIKE ?)'; array_push($p,"%$q%","%$q%","%$q%","%$q%","%$q%"); }
$ids=query('SELECT b.id FROM ps_budgets b LEFT JOIN projects pr ON pr.id=b.project_id'.($w?' WHERE '.implode(' AND ',$w):'')." ORDER BY CASE b.estado WHEN 'Por aprobar' THEN 0 WHEN 'Aprobado' THEN 1 WHEN 'Borrador' THEN 2 ELSE 3 END, b.alert_level DESC, b.id DESC LIMIT 200",$p)->fetchAll(PDO::FETCH_COLUMN);
$list=[]; foreach($ids as $id){ $b=psGet((int)$id); $list[]=[$b,psExec($b)]; }
// Indicadores de los presupuestos aprobados (vigentes), sin filtros.
$k=['n'=>0,'plan'=>0,'real'=>0,'alert'=>0,'over'=>0];
foreach(query("SELECT id FROM ps_budgets WHERE estado='Aprobado'")->fetchAll(PDO::FETCH_COLUMN) as $id){ $b=psGet((int)$id); $x=psExec($b); $k['n']++; $k['plan']+=$x['tot']['gasto']['plan']; $k['real']+=$x['tot']['gasto']['real']; if($x['lvl']===2) $k['over']++; elseif($x['lvl']===1) $k['alert']++; }
$nPor=(int)query("SELECT COUNT(*) FROM ps_budgets WHERE estado='Por aprobar'")->fetchColumn();
$psHead('Presupuestos','Presupuesto de cada curso, proyecto u operación, comparado con lo registrado en Contabilidad en su centro de costo.',$canEdit?'<a class="btn primary" href="'.e(url(['tab'=>'ps_new'])).'">'.ic('plus').'Nuevo presupuesto</a>':'');
?>
<div class="stat-grid">
  <a class="stat" href="<?=e(url(['tab'=>'ps','estado'=>'Aprobado']))?>"><?=ic('target')?><strong><?=$k['n']?></strong><span>Aprobados vigentes</span></a>
  <div class="stat"><?=ic('upload')?><strong><?=psPct($k['plan']>0?100*$k['real']/$k['plan']:null)?></strong><span>Gasto ejecutado · <?=clp($k['real'])?> de <?=clp($k['plan'])?></span></div>
  <a class="stat" href="<?=e(url(['tab'=>'ps','estado'=>'Aprobado']))?>"><?=ic('bell')?><strong class="<?=$k['over']?'cc-out':''?>"><?=$k['over']+$k['alert']?></strong><span><?=$k['over']?> con sobregiro · <?=$k['alert']?> en alerta</span></a>
  <a class="stat" href="<?=e(url(['tab'=>'ps','estado'=>'Por aprobar']))?>"><?=ic('clock')?><strong><?=$nPor?></strong><span>Por aprobar</span></a>
</div>
<?php $cnt=query('SELECT estado,COUNT(*) FROM ps_budgets GROUP BY estado')->fetchAll(PDO::FETCH_KEY_PAIR); $base=array_filter(['tab'=>'ps','q'=>$q,'tipo'=>$ft],'strlen');?>
<nav class="ps-tabs" aria-label="Filtrar por estado"><a href="<?=e(url($base))?>"<?=$fe===''?' class="on" aria-current="page"':''?>>Todos<span class="ps-badge"><?=array_sum($cnt)?></span></a><?php foreach(['Por aprobar'=>'Por aprobar','Aprobado'=>'Aprobados','Borrador'=>'Borradores','Cerrado'=>'Cerrados'] as $s=>$lbl):?><a href="<?=e(url($base+['estado'=>$s]))?>"<?=$fe===$s?' class="on" aria-current="page"':''?>><?=$lbl?><?php if(!empty($cnt[$s])):?><span class="ps-badge<?=$s==='Por aprobar'?' ps-badge1':''?>"><?=(int)$cnt[$s]?></span><?php endif?></a><?php endforeach?></nav>
<form method="get" class="oc-filter" role="search"><input type="hidden" name="tab" value="ps"><?php if($fe):?><input type="hidden" name="estado" value="<?=e($fe)?>"><?php endif?><label class="grow"><span class="sr">Buscar</span><input name="q" value="<?=e($q)?>" placeholder="Buscar por N°, nombre, proyecto, cliente o código…"></label>
  <label><span class="sr">Tipo</span><select name="tipo"><option value="">Todos los tipos</option><?php foreach(PS_TIPOS as $s):?><option<?=$ft===$s?' selected':''?>><?=e($s)?></option><?php endforeach?></select></label>
  <button class="ghost"><?=ic('search')?>Buscar</button><?php if($q!==''||$ft):?><a class="btn ghost" href="<?=e(url(array_filter(['tab'=>'ps','estado'=>$fe],'strlen')))?>">Limpiar</a><?php endif?></form>
<?php if($list):?><div class="table-wrap"><table class="oc-reg ps-reg"><thead><tr><th>N°</th><th>Presupuesto</th><th class="ps-col-bar">Gastos (real / presupuesto)</th><th class="right hide-sm">Ingresos</th><th class="right hide-sm">Resultado real</th><th>Estado</th><th class="right">Acciones</th></tr></thead><tbody>
<?php foreach($list as [$b,$x]): $gt=$x['tot']['gasto']; $it=$x['tot']['ingreso']; $pn=$b['project_id']?($psProjects[(int)$b['project_id']]??''):'';?><tr>
  <td class="nowrap"><a class="oc-num" href="<?=e(url(['pres'=>(int)$b['id']]))?>"><?=e($b['numero'])?></a><small><?=e($b['tipo'])?></small></td>
  <td><a class="ps-name" href="<?=e(url(['pres'=>(int)$b['id']]))?>"><?=e($b['nombre'])?></a><small><?=e(implode(' · ',array_filter([$pn,$x['center']?ccCenterLabel($x['center']):'Sin centro de costo',psPeriod($b)],'strlen')))?></small></td>
  <td class="ps-col-bar"><div class="ps-cell"><span><b><?=clp($gt['real'])?></b> de <?=clp($gt['plan'])?></span><span class="ps-cell-pct"><?=psPct($gt['plan']>0?100*$gt['real']/$gt['plan']:null)?></span></div><?=psBar($gt['plan'],$gt['real'],$x['proyGasto'])?></td>
  <td class="right nowrap hide-sm"><?=clp($it['real'])?><small>de <?=clp($it['plan'])?></small></td>
  <td class="right nowrap hide-sm"><b class="<?=$it['real']-$gt['real']<0?'cc-out':''?>"><?=ccClp($it['real']-$gt['real'])?></b><small>plan <?=ccClp($it['plan']-$gt['plan'])?></small></td>
  <td class="nowrap"><?=$psPill($b['estado'])?><?php if($b['estado']==='Aprobado'):?><small><?=psLevelPill($x['lvl'])?></small><?php endif?></td>
  <td class="right nowrap"><a class="icon-btn" href="<?=e(url(['pres'=>(int)$b['id']]))?>" title="Abrir"><?=ic('eye')?><span class="sr">Abrir</span></a><a class="icon-btn" href="<?=e(url(['ps_print'=>(int)$b['id']]))?>" target="_blank" rel="noopener" title="Imprimir / PDF"><?=ic('print')?><span class="sr">Imprimir / PDF</span></a></td>
</tr><?php endforeach?></tbody></table></div>
<?=$psHelp('<p>Barra azul: gasto real. Tramo claro: en rendiciones por aprobar y comprometido en OC. Raya oscura: el presupuesto. En rojo, lo que se pasó del presupuesto.</p>','¿Cómo leer las barras?')?>
<?php else:?><div class="empty-state"><?=ic('target')?><b><?=($q!==''||$fe||$ft)?'No hay presupuestos que coincidan':'Todavía no hay presupuestos'?></b><span><?=$canEdit?'Crea el primero con «Nuevo presupuesto»: por ejemplo, el de tu próximo curso.':'Cuando un editor cree un presupuesto, aparecerá aquí.'?></span></div><?php endif?>

<?php elseif($mode==='ps_new'):
$pid=(int)$g('proyecto'); if(!isset($psProjects[$pid])) $pid=0;
$vacia=$g('plantilla')==='vacia';
$h=$psPosted ? $psPostedHead() : ['nombre'=>$pid?$psProjects[$pid]:'','tipo'=>'Curso','codigo'=>'','cliente'=>'','project_id'=>$pid,'center_id'=>'auto','desde'=>'','hasta'=>'','participantes'=>'','horas'=>'','alerta'=>80,'obs'=>''];
$rows=$psPosted ? $psPostedRows() : ($vacia?[]:$psTemplate());
?>
<nav class="crumbs" aria-label="Ruta"><a href="<?=e(url(['tab'=>'ps']))?>">Presupuestos</a><?=ic('chevron')?><span>Nuevo presupuesto</span></nav>
<?php $psHead('Nuevo presupuesto','Define el período, el centro de costo y las partidas. Número asignado al guardar: '.e(psNextNumero()).'.','<div class="seg" role="group" aria-label="Partidas iniciales"><a href="'.e(url(['tab'=>'ps_new']+($pid?['proyecto'=>$pid]:[]))).'"'.(!$vacia?' class="on"':'').'>Plantilla de curso</a><a href="'.e(url(['tab'=>'ps_new','plantilla'=>'vacia']+($pid?['proyecto'=>$pid]:[]))).'"'.($vacia?' class="on"':'').'>En blanco</a></div>');?>
<?php if(!$vacia && !$psPosted):?><p class="hint cc-note">La plantilla propone las partidas típicas de un curso: completa los precios de las que apliquen; las que queden sin precio no se guardan.</p><?php endif?>
<?php $psForm($h,$rows,0)?>

<?php elseif($mode==='ps_doc'): $b=psGet($psId);
if(!$b):?><div class="empty-state"><?=ic('search')?><b>Presupuesto no encontrado</b><span><a href="<?=e(url(['tab'=>'ps']))?>">Volver a presupuestos</a></span></div><?php else:
$editable=psEditable($b);
if($editable && $g('edit')==='1'):
  $h=$psPosted ? $psPostedHead() : ['center_id'=>$b['center_id']===null?'':(string)$b['center_id']]+$b;
  $rows=$psPosted ? $psPostedRows() : $b['lines']; ?>
<nav class="crumbs" aria-label="Ruta"><a href="<?=e(url(['tab'=>'ps']))?>">Presupuestos</a><?=ic('chevron')?><a href="<?=e(url(['pres'=>(int)$b['id']]))?>"><?=e($b['numero'])?></a><?=ic('chevron')?><span>Editar</span></nav>
<?php $psHead('Editar '.e($b['numero']),e($b['nombre']).((int)$b['version']>1?' · versión '.(int)$b['version']:''))?>
<?php $psForm($h,$rows,(int)$b['id'])?>
<?php else:
$x=psExec($b); $u=psUnit($b,$x); $tp=$x['tot']; $gt=$tp['gasto']; $it=$tp['ingreso']; $c=$x['center'];
$pr=$b['project_id']?project((int)$b['project_id']):false;
$resPlan=$it['plan']-$gt['plan']; $resReal=$it['real']-$gt['real'];
$gPct=$gt['plan']>0?100*$gt['real']/$gt['plan']:null;
$canDel=$isAdmin || ($canEdit && $b['estado']==='Borrador' && $b['aprob_gasto']===null && (int)$b['owner']===(int)$user['id']);
$hasUnit=$b['participantes']>0 || $b['horas']>0;
$nMov=$c ? (function() use($b){ [$w,$p]=psMovWhere($b); return (int)query('SELECT COUNT(DISTINCT m.id)'.$w,$p)->fetchColumn(); })() : 0;
// Partidas de gasto que requieren atención (alerta, sobregiro o gasto no presupuestado), de mayor a menor ejecución.
$att=array_filter($x['rows']['gasto'],fn($r)=>$r['lvl']>0);
uasort($att,fn($a,$z)=>[$z['lvl'],$z['pct']??INF]<=>[$a['lvl'],$a['pct']??INF]);
$attLvl=$att?max(array_column($att,'lvl')):0;
// Pestañas: cada una muestra un tema; el resumen responde «¿cómo va?» de un vistazo.
$psTabs=['resumen'=>['Resumen',''],'partidas'=>['Partidas',$att?'<span class="ps-badge ps-badge'.$attLvl.'">'.count($att).'</span>':'']];
if($c) $psTabs['proyeccion']=['Proyección',($n=count($x['oc'])+count($x['cot']))?'<span class="ps-badge">'.$n.'</span>':''];
if($hasUnit) $psTabs['indicadores']=['Indicadores',''];
if($c) $psTabs['movimientos']=['Movimientos',$nMov?'<span class="ps-badge">'.$nMov.'</span>':''];
$psTabs['ficha']=['Plan y datos',''];
$vista=isset($psTabs[$g('vista')])?$g('vista'):'resumen';
$psTab=fn(string $v): string => url(['pres'=>(int)$b['id']]+($v==='resumen'?[]:['vista'=>$v]));
?>
<nav class="crumbs no-print" aria-label="Ruta"><a href="<?=e(url(['tab'=>'ps']))?>">Presupuestos</a><?=ic('chevron')?><span><?=e($b['numero'])?></span></nav>
<div class="page-head ps-head">
  <div class="title-row"><a class="icon-btn" href="<?=e(url(['tab'=>'ps']))?>" title="Volver"><?=ic('back')?><span class="sr">Volver</span></a><span class="tile-icon big tone1"><?=ic('target')?></span><div><h1><?=e($b['nombre'])?></h1><p class="subtitle"><?=$psPill($b['estado'])?><?=$b['estado']==='Aprobado'?' '.psLevelPill($x['lvl']):''?> <?=e($b['numero'])?><?=(int)$b['version']>1?' · versión '.(int)$b['version']:''?> · <?=e($b['tipo'])?> · <?=e(psPeriod($b))?></p></div></div>
  <div class="head-actions">
    <?php if($editable):?><a class="btn ghost" href="<?=e(url(['pres'=>(int)$b['id'],'edit'=>1]))?>"><?=ic('edit')?>Editar</a>
    <form method="post" class="cc-inline"><?=csrf()?><input type="hidden" name="id" value="<?=(int)$b['id']?>"><button class="primary" name="action" value="ps_send"><?=ic('external')?>Enviar a aprobación</button></form><?php endif?>
    <?php if($isAdmin && in_array($b['estado'],['Borrador','Por aprobar'],true)):?><details class="pop"><summary class="btn primary">Revisar</summary><form method="post" class="pop-body"><?=csrf()?><input type="hidden" name="id" value="<?=(int)$b['id']?>"><input type="hidden" name="action" value="ps_review"><strong>Aprobar<?=$b['estado']==='Por aprobar'?' o devolver':''?></strong><label>Comentario <small><?=$b['estado']==='Por aprobar'?'(obligatorio si lo devuelves)':'(opcional)'?></small><textarea name="revision" maxlength="600" rows="3" placeholder="Ej: ajustar honorarios al valor 2026"></textarea></label><button class="primary" name="decision" value="aprobar">Aprobar presupuesto</button><?php if($b['estado']==='Por aprobar'):?><button class="danger" name="decision" value="devolver">Devolver a borrador</button><?php endif?></form></details><?php endif?>
    <a class="btn ghost" href="<?=e(url(['ps_print'=>(int)$b['id']]))?>" target="_blank" rel="noopener" title="Imprimir o guardar en PDF"><?=ic('print')?><span class="hide-sm">Imprimir / PDF</span></a>
    <details class="pop"><summary class="btn ghost" title="Más opciones"><?=ic('more')?><span class="sr">Más opciones</span></summary><div class="pop-body">
      <div class="stack"><strong>Exportar</strong><a class="btn ghost" href="<?=e(url(['ps_csv'=>(int)$b['id']]))?>"><?=ic('download')?>Descargar CSV (Excel)</a></div>
      <?php if($canEdit):?><form method="post" class="stack"><?=csrf()?><input type="hidden" name="id" value="<?=(int)$b['id']?>"><strong>Duplicar</strong><p class="hint">Crea un borrador con las mismas partidas, útil para la siguiente versión del curso.</p><button class="ghost" name="action" value="ps_dup"><?=ic('copy')?>Duplicar presupuesto</button></form><?php endif?>
      <?php if($isAdmin && $b['estado']==='Aprobado'):?><form method="post" class="stack"><?=csrf()?><input type="hidden" name="id" value="<?=(int)$b['id']?>"><strong>Cerrar</strong><p class="hint">Al terminar el curso o proyecto: deja de generar alertas y queda como informe final.</p><button class="ghost" name="action" value="ps_close"><?=ic('check')?>Cerrar presupuesto</button></form><?php endif?>
      <?php if($isAdmin && $b['estado']!=='Borrador'):?><form method="post" class="stack"><?=csrf()?><input type="hidden" name="id" value="<?=(int)$b['id']?>"><strong>Reabrir para reformular</strong><label>Motivo <small>(opcional)</small><input name="revision" maxlength="600" placeholder="Ej: se agregó un segundo grupo"></label><p class="hint"><?=in_array($b['estado'],['Aprobado','Cerrado'],true)?'Vuelve a borrador como versión '.((int)$b['version']+1).'. Se conserva el presupuesto original aprobado para comparar.':'Vuelve a borrador.'?></p><button class="ghost" name="action" value="ps_reopen">Reabrir</button></form><?php endif?>
      <?php if($canDel):?><form method="post" class="stack danger-zone"><?=csrf()?><input type="hidden" name="id" value="<?=(int)$b['id']?>"><p>Se elimina el presupuesto y sus partidas. Los ingresos y gastos de Contabilidad no se tocan.</p><button class="danger" name="action" value="ps_delete"><?=ic('trash')?>Eliminar presupuesto</button></form><?php endif?>
    </div></details>
  </div>
</div>
<?php if($b['estado']==='Borrador' && $b['revision']!==''):?><div class="alert error" role="alert"><b><?=(int)$b['version']>1&&$b['aprob_gasto']!==null?'Reabierto':'Devuelto'?>:</b> <?=e($b['revision'])?></div>
<?php elseif($b['estado']==='Por aprobar'):?><div class="alert" role="status">Enviado a aprobación. Mientras se revisa no se puede modificar.</div><?php endif?>
<?php if(!$c):?><div class="alert error" role="alert">Sin centro de costo: no se puede comparar con la contabilidad.<?=$editable?' <a href="'.e(url(['pres'=>(int)$b['id'],'edit'=>1])).'">Elige uno</a>.':''?></div><?php endif?>

<nav class="ps-tabs no-print" aria-label="Secciones del presupuesto"><?php foreach($psTabs as $v=>[$lbl,$badge]):?><a href="<?=e($psTab($v))?>"<?=$v===$vista?' class="on" aria-current="page"':''?>><?=$lbl?><?=$badge?></a><?php endforeach?></nav>

<?php if($vista==='resumen'): $overl=psOverlaps($b);?>
<?php if($b['estado']==='Borrador' && $b['revision']===''):?><p class="ps-note"><?=ic('info')?>Borrador: ya muestra lo ejecutado, pero las alertas y el dashboard consideran solo presupuestos aprobados.</p><?php endif?>
<?php foreach($overl as $o):?><p class="ps-note"><?=ic('info')?>El presupuesto <a href="<?=e(url(['pres'=>(int)$o['id']]))?>"><?=e($o['numero'])?></a> usa el mismo centro de costo en un período que se cruza con este: los mismos movimientos cuentan en ambos.</p><?php endforeach?>
<div class="stat-grid">
  <div class="stat"><?=ic('upload')?><strong class="<?=$gt['real']>$gt['plan']?'cc-out':''?>"><?=clp($gt['real'])?></strong><span>Gastado de <?=clp($gt['plan'])?> (<?=psPct($gPct)?>)</span></div>
  <div class="stat"><?=ic('download')?><strong class="cc-in"><?=clp($it['real'])?></strong><span>Ingresado de <?=clp($it['plan'])?> (<?=psPct($it['plan']>0?100*$it['real']/$it['plan']:null)?>)</span></div>
  <div class="stat"><?=ic('activity')?><strong class="<?=$resReal<0?'cc-out':''?>"><?=ccClp($resReal)?></strong><span>Resultado a la fecha · plan <?=ccClp($resPlan)?></span></div>
  <div class="stat"><?=ic('target')?><strong class="<?=$x['disponible']<0?'cc-out':''?>"><?=ccClp($x['disponible'])?></strong><span>Disponible para gastar</span></div>
</div>
<div class="cc-two ps-sum">
<section class="block"><div class="block-head"><h2>¿Cómo va el gasto?</h2></div><div class="card">
  <div class="ps-big"><div class="ps-cell"><span><b><?=clp($gt['real'])?></b> de <?=clp($gt['plan'])?></span><span class="ps-cell-pct"><?=psPct($gPct)?></span></div><?=psBar($gt['plan'],$gt['real'],$x['proyGasto'])?>
  <?php if($x['tiempo']!==null):?><svg class="ps-time" viewBox="0 0 100 4" preserveAspectRatio="none" aria-hidden="true"><rect width="100" height="4" fill="#edf1f5"/><rect width="<?=min(100,max(0,(int)$x['tiempo']))?>" height="4" fill="#8a96a3"/></svg><small class="muted">Período transcurrido: <?=$x['tiempo']?>%</small><?php endif?></div>
  <?php if($x['tiempo']!==null && $gPct!==null && $x['tiempo']<100 && $gPct>$x['tiempo']+15 && $b['estado']!=='Cerrado'):?><p class="ps-warn"><?=ic('bell')?>El gasto va más rápido que el calendario.</p><?php endif?>
  <?php if($c):?><dl class="ps-kv"><div class="ps-kv-total"><dt>Proyección de cierre</dt><dd class="<?=$x['proyGasto']>$gt['plan']?'cc-out':''?>"><?=clp($x['proyGasto'])?></dd></div><div class="ps-kv-total"><dt>Resultado proyectado</dt><dd class="<?=$x['proyIngreso']-$x['proyGasto']<0?'cc-out':''?>"><?=ccClp($x['proyIngreso']-$x['proyGasto'])?></dd></div></dl>
  <a class="ps-more" href="<?=e($psTab('proyeccion'))?>">Ver qué incluye la proyección<?=ic('chevron')?></a><?php endif?>
</div></section>
<section class="block"><div class="block-head"><h2>Partidas que requieren atención</h2></div><div class="card">
  <?php if($att):?><ul class="ps-att"><?php foreach(array_slice($att,0,5,true) as $k=>$r):?><li><a href="<?=e($psMovsUrl($b,['tipo'=>'gasto','cat'=>(string)$k]))?>"><span><b><?=e(psCatLabel((string)$k))?></b><small><?=$r['plan']===0?'No presupuestada':clp($r['real']).' de '.clp($r['plan'])?></small></span><?=psLevelPill($r['lvl'])?></a></li><?php endforeach?></ul>
  <?php elseif($x['rows']['gasto']):?><div class="ps-ok"><?=ic('check')?><span><b>Todo en rango</b>Ninguna partida de gasto llegó al <?=(int)$b['alerta']?>% de su presupuesto.</span></div>
  <?php else:?><p class="hint">Aún no hay partidas de gasto.<?=$editable?' <a href="'.e(url(['pres'=>(int)$b['id'],'edit'=>1])).'">Agrégalas</a>.':''?></p><?php endif?>
  <a class="ps-more" href="<?=e($psTab('partidas'))?>"><?=count($att)>5?'Ver las '.count($att).' partidas con aviso y el resto':'Ver el control de todas las partidas'?><?=ic('chevron')?></a>
</div></section>
</div>

<?php elseif($vista==='partidas'): $retCat=[]; foreach($b['lines'] as $l) if($l['ret']>0) $retCat[$l['categoria']]=($retCat[$l['categoria']]??0)+psRetSplit($l)[1];?>
<section class="block"><div class="block-head"><h2>Control por partida</h2><?php if($c):?><a class="btn ghost small" href="<?=e($psMovsUrl($b))?>"><?=ic('list')?>Ver movimientos</a><?php endif?></div>
<?php if($x['rows']['ingreso']||$x['rows']['gasto']):?><div class="table-wrap"><table class="cc-table ps-ctrl"><thead><tr><th>Partida</th><th class="right">Presupuesto</th><th class="right">Real</th><th class="right hide-sm">Por ejecutar</th><th class="ps-col-bar">Ejecución</th></tr></thead>
<?php foreach(['gasto'=>'Gastos','ingreso'=>'Ingresos'] as $t=>$lbl): if(!$x['rows'][$t]) continue; $T=$tp[$t];?><tbody>
<tr class="ps-group"><td colspan="5"><?=$lbl?></td></tr>
<?php foreach($x['rows'][$t] as $k=>$r): $k=(string)$k; $dif=$r['plan']-$r['real'];?><tr<?=$r['lvl']===2?' class="ps-over"':''?>>
  <td><?php if($c):?><a href="<?=e($psMovsUrl($b,['tipo'=>$t,'cat'=>$k]))?>"><?=e(psCatLabel($k))?></a><?php else:?><?=e(psCatLabel($k))?><?php endif?><small><?=$r['plan']===0?'No presupuestada · ':''?><?=$t==='gasto'&&!empty($retCat[$k])?'incluye ret. '.clp($retCat[$k]).' · ':''?><?=$r['n']?> mov.<?=$r['rev']?' · en revisión '.clp($r['rev']):''?></small></td>
  <td class="right nowrap"><?=clp($r['plan'])?></td>
  <td class="right nowrap"><b class="<?=$t==='ingreso'?'cc-in':($r['lvl']===2?'cc-out':'')?>"><?=clp($r['real'])?></b></td>
  <td class="right nowrap hide-sm <?=$dif<0?($t==='gasto'?'cc-out':'cc-in'):''?>"><?=ccClp($dif)?></td>
  <td class="ps-col-bar"><div class="ps-cell"><?=psBar($r['plan'],$r['real'],$r['real']+$r['rev'],$t)?><span class="ps-cell-pct"><?=psPct($r['pct'])?></span><?php if($t==='gasto'):?><?=psLevelPill($r['lvl'])?><?php endif?></div></td>
</tr><?php endforeach?>
<tr class="ps-total"><td>Total <?=mb_strtolower($lbl)?></td><td class="right nowrap"><?=clp($T['plan'])?></td><td class="right nowrap"><?=clp($T['real'])?></td><td class="right nowrap hide-sm"><?=ccClp($T['plan']-$T['real'])?></td><td class="ps-col-bar"><div class="ps-cell"><?=psBar($T['plan'],$T['real'],$t==='gasto'?$x['proyGasto']:$x['proyIngreso'],$t)?><span class="ps-cell-pct"><?=psPct($T['plan']>0?100*$T['real']/$T['plan']:null)?></span></div></td></tr>
</tbody><?php endforeach?></table></div>
<?=$psHelp('<p>Haz clic en una partida para ver sus movimientos en Contabilidad.</p><p><b>Real</b>: ingresos y gastos registrados con el centro '.($c?'<b>'.e(ccCenterLabel($c)).'</b>':'de costo').' y fecha dentro del período (si un gasto se repartió entre centros, cuenta solo la parte de este). Los gastos de rendiciones cuentan al aprobarse; mientras tanto aparecen «en revisión» como tramo claro en la barra.</p><p><b>Alerta</b> al llegar al '.(int)$b['alerta'].'% de una partida o del total (contando lo comprometido) y <b>sobregiro</b> si se excede.</p>')?>
<?php else:?><div class="empty-state"><?=ic('target')?><b>Sin partidas</b><span><?=$editable?'<a href="'.e(url(['pres'=>(int)$b['id'],'edit'=>1])).'">Agrega las partidas</a> de ingresos y gastos.':'Este presupuesto aún no tiene partidas.'?></span></div><?php endif?>
</section>

<?php elseif($vista==='proyeccion'):?>
<div class="cc-two">
<section class="block"><div class="block-head"><h2>Gasto proyectado</h2></div><div class="card ps-proj">
  <dl class="ps-kv"><div><dt>Gasto real</dt><dd><?=clp($gt['real'])?></dd></div>
  <div><dt><a href="<?=e(url(['tab'=>'cc_rends','estado'=>'Enviada']))?>">En rendiciones por aprobar</a></dt><dd><?=clp($gt['rev'])?></dd></div>
  <div><dt>Comprometido en OC sin pagar</dt><dd><?=clp($x['comp'])?></dd></div>
  <div class="ps-kv-total"><dt>Proyección de cierre</dt><dd class="<?=$x['proyGasto']>$gt['plan']?'cc-out':''?>"><?=clp($x['proyGasto'])?> <small>de <?=clp($gt['plan'])?></small></dd></div></dl>
  <?php if($x['oc']):?><ul class="ps-docs"><?php foreach($x['oc'] as $o):?><li><a href="<?=e(url(['oc'=>(int)$o['id']]))?>"><b><?=e($o['numero'])?></b> · <?=e($o['prov_razon']?:'Sin proveedor')?><small><?=e($o['obj_titulo'])?><?=$o['fecha']!==''?' · '.e(ocDate($o['fecha'])):''?></small></a><span class="oc-pill <?=ocEstadoClass($o['estado'])?>"><?=e($o['estado'])?></span><strong><?=clp((int)$o['total'])?></strong></li><?php endforeach?></ul>
  <?php else:?><p class="hint">No hay OC pendientes con este centro de costo.</p><?php endif?>
  <?=$psHelp('<p>Cuentan las OC emitidas, aceptadas o recibidas cuyo campo «Centro de costo» dice '.e(ccCenterLabel($c)).' y cuyo número aún no aparece como referencia de un gasto.</p><p>Al registrar el gasto, escribe el N° de OC en «Referencia» para que no se cuente dos veces.</p>','¿Qué OC se cuentan?')?>
</div></section>
<section class="block"><div class="block-head"><h2>Ingreso proyectado</h2></div><div class="card ps-proj">
  <dl class="ps-kv"><div><dt>Ingreso real</dt><dd><?=clp($it['real'])?></dd></div>
  <div><dt>Ventas aceptadas por facturar</dt><dd><?=clp($x['porFacturar'])?></dd></div>
  <div class="ps-kv-total"><dt>Proyección de cierre</dt><dd class="<?=$it['plan']>0&&$x['proyIngreso']<$it['plan']?'cc-out':'cc-in'?>"><?=clp($x['proyIngreso'])?> <small>de <?=clp($it['plan'])?></small></dd></div>
  <div class="ps-kv-total"><dt>Resultado proyectado</dt><dd class="<?=$x['proyIngreso']-$x['proyGasto']<0?'cc-out':''?>"><?=ccClp($x['proyIngreso']-$x['proyGasto'])?></dd></div></dl>
  <?php if($x['cot']):?><ul class="ps-docs"><?php foreach($x['cot'] as $q):?><li><a href="<?=e(url(['cot'=>(int)$q['id']]))?>"><b><?=e($q['numero'])?></b> · <?=e($q['cli_razon']?:'Sin cliente')?><small><?=e($q['obj_titulo'])?></small></a><span class="oc-pill st-aceptada">Aceptada</span><strong><?=clp((int)$q['total'])?></strong></li><?php endforeach?></ul>
  <?php else:?><p class="hint">No hay cotizaciones aceptadas pendientes de facturar con este centro de costo.</p><?php endif?>
  <?=$psHelp('<p>Cuentan las cotizaciones aceptadas con este centro de costo cuyo número aún no aparece como referencia de un ingreso.</p>','¿Qué cotizaciones se cuentan?')?>
</div></section>
</div>

<?php elseif($vista==='indicadores'): $P=(int)$b['participantes'];?>
<section class="block"><div class="block-head"><h2>Indicadores del curso</h2></div><div class="dash-com ps-unit">
  <?php if($u['costoPart']!==null):?><div><small>Costo por participante</small><strong><?=clp($u['costoPart'])?></strong><span><?=$u['costoPartReal']!==null?'Real: '.clp($u['costoPartReal']).' con '.(int)$b['part_reales'].' participantes':'Presupuestado con '.$P.' participantes'?></span></div><?php endif?>
  <?php if($u['costoHora']!==null):?><div><small>Costo por hora</small><strong><?=clp($u['costoHora'])?></strong><span><?=$u['costoHoraReal']!==null?'Real: '.clp($u['costoHoraReal']):'Presupuestado, '.e(ocQty((float)$b['horas'])).' horas'?></span></div><?php endif?>
  <?php if($u['ingresoPart']>0):?><div><small>Punto de equilibrio</small><strong class="<?=$u['equilibrio']!==null&&$P>0&&$u['equilibrio']>$P?'cc-out':''?>"><?=$u['equilibrio']===null?'No se alcanza':$u['equilibrio'].' participante'.($u['equilibrio']===1?'':'s')?></strong><span><?=$u['equilibrio']===null?'El ingreso por participante no cubre su costo variable.':'Mínimo para cubrir los costos'.($P>0?' · previstos '.$P:'')?></span></div>
  <div><small>Ingreso por participante</small><strong><?=clp($u['ingresoPart'])?></strong><span><?=$u['precioMin']!==null?'Mínimo para no perder con '.$P.': '.clp(max(0,$u['precioMin'])):'Costo variable por participante '.clp($u['costoVarPart'])?></span></div><?php endif?>
</div>
<?php if($canEdit && $b['estado']!=='Cerrado'):?><form method="post" class="ps-real card"><?=csrf()?><input type="hidden" name="id" value="<?=(int)$b['id']?>"><label>Participantes reales (inscritos o asistentes)<input type="number" name="part_reales" min="0" step="1" value="<?=(int)$b['part_reales']?:''?>" placeholder="<?=$P?:''?>"></label><button class="ghost" name="action" value="ps_real">Actualizar</button></form><?php endif?>
<?=$psHelp('<p>El punto de equilibrio usa las partidas que se multiplican por participante (ingresos y costos variables) y las demás como costos fijos.</p><p>Al registrar los participantes reales, el costo por participante y por hora se recalcula con lo efectivamente gastado.</p>')?></section>

<?php elseif($vista==='movimientos'): $movs=(function() use($b){ [$w,$p]=psMovWhere($b); return query('SELECT m.id, m.tipo, m.fecha, m.descripcion, m.contraparte, m.categoria, m.rendicion_id, r.estado AS restado, SUM(s.monto) AS parte'.$w.' GROUP BY m.id ORDER BY m.fecha DESC, m.id DESC LIMIT 30',$p)->fetchAll(PDO::FETCH_ASSOC); })();?>
<section class="block"><div class="block-head"><h2>Movimientos del centro <small><?=$nMov?></small></h2><a class="btn ghost small" href="<?=e($psMovsUrl($b))?>"><?=ic('list')?>Abrir en Contabilidad</a></div>
<?php if($movs):?><ul class="dash-big card ps-movs"><?php foreach($movs as $m): $cnt=$m['rendicion_id']===null||$m['restado']==='Aprobada';?><li<?=$cnt?'':' class="cc-pending"'?>><a href="<?=e(url(['mov'=>(int)$m['id']]))?>"><span><b><?=e($m['descripcion'])?></b><small><?=e(ocDate($m['fecha']))?> · <?=e(psCatLabel($m['categoria']))?><?=$m['contraparte']!==''?' · '.e($m['contraparte']):''?><?=$cnt?'':' · rendición sin aprobar'?></small></span><strong class="<?=$m['tipo']==='ingreso'?'cc-in':'cc-out'?>"><?=$m['tipo']==='gasto'?'− ':''?><?=clp((int)$m['parte'])?></strong></a></li><?php endforeach?></ul>
<?php if($nMov>30):?><p class="hint">Se muestran los 30 más recientes. <a href="<?=e($psMovsUrl($b))?>">Ver los <?=$nMov?> en Contabilidad</a>.</p><?php endif?>
<?php else:?><div class="empty-state"><?=ic('list')?><b>Aún no hay movimientos</b><span>Al registrar ingresos y gastos en Contabilidad, elige el centro «<?=e(ccCenterLabel($c))?>» y aparecerán aquí.</span></div><?php endif?></section>

<?php else:?>
<section class="block"><div class="block-head"><h2>Partidas presupuestadas</h2><?php if($editable):?><a class="btn ghost small" href="<?=e(url(['pres'=>(int)$b['id'],'edit'=>1]))?>"><?=ic('edit')?>Editar partidas</a><?php endif?></div>
<?php if($b['lines']):?><div class="table-wrap"><table class="cc-table"><thead><tr><th>Partida</th><th>Descripción</th><th class="right hide-sm">Cantidad</th><th class="right hide-sm">Precio unit.</th><th class="hide-sm">Se multiplica</th><th class="right">Monto</th></tr></thead><tbody>
<?php foreach($b['lines'] as $l):?><tr><td><span class="cc-tipo <?=e($l['tipo'])?>"><?=e(CC_TIPOS[$l['tipo']])?></span> <?=e($l['categoria'])?></td><td><?=e($l['descripcion']?:'—')?></td><td class="right nowrap hide-sm"><?=e(ocQty((float)$l['cantidad']))?><?=$l['unidad']!==''?' '.e($l['unidad']):''?></td><td class="right nowrap hide-sm"><?=clp((int)$l['precio'])?></td><td class="hide-sm muted"><?=e(PS_BASES[$l['base']]??'')?><?=$l['ret']>0?'<small>precio líquido · bruto '.clp(psBrutoUnit((int)$l['precio'],(float)$l['ret'])).' c/u</small>':''?></td><td class="right nowrap"><b><?=clp((int)$l['monto'])?></b><?php if($l['ret']>0): [$lq,$rt]=psRetSplit($l);?><small>líquido <?=clp($lq)?> + ret. <?=e(psRetLabel((float)$l['ret']))?> <?=clp($rt)?></small><?php endif?></td></tr><?php endforeach?>
</tbody><tfoot><tr><td colspan="2">Ingresos <?=clp($it['plan'])?> · Gastos <?=clp($gt['plan'])?></td><td class="hide-sm" colspan="3"></td><td class="right nowrap"><?=ccClp($resPlan)?></td></tr></tfoot></table></div>
<?php $retTot=array_sum(array_map(fn($l)=>psRetSplit($l)[1],$b['lines'])); if($retTot>0):?><p class="hint">Los gastos incluyen <b><?=clp($retTot)?></b> de retención de honorarios: el relator recibe el líquido y <?=e(empresa()['corto'])?> paga la retención al SII en el F29 del mes siguiente. Al registrar la boleta en Contabilidad, ingresa su monto bruto.</p><?php endif?>
<?php if($b['aprob_gasto']!==null && ((int)$b['aprob_gasto']!==$gt['plan'] || (int)$b['aprob_ingreso']!==$it['plan'])):?><p class="hint">Presupuesto original aprobado: ingresos <?=clp((int)$b['aprob_ingreso'])?> y gastos <?=clp((int)$b['aprob_gasto'])?>. La versión vigente (<?=(int)$b['version']?>) cambia los gastos en <?=ccClp($gt['plan']-(int)$b['aprob_gasto'])?>.</p><?php endif?>
<?php else:?><p class="hint">Sin partidas.</p><?php endif?></section>
<section class="block"><div class="block-head"><h2>Datos</h2></div><div class="card"><dl class="cc-dl">
  <div><dt>Proyecto</dt><dd><?=$pr?'<a href="'.e(url(['p'=>(int)$pr['id']])).'">'.e($pr['name']).'</a>':'—'?></dd></div>
  <div><dt>Centro de costo</dt><dd><?=$c?'<a href="'.e(url(['tab'=>'cc','centro'=>(int)$b['center_id'],'desde'=>$b['desde'],'hasta'=>$b['hasta']])).'">'.e(ccCenterLabel($c)).'</a>':'—'?></dd></div>
  <div><dt>Período</dt><dd><?=e(psPeriod($b))?></dd></div>
  <?php if($b['cliente']!==''):?><div><dt>Cliente o mandante</dt><dd><?=e($b['cliente'])?></dd></div><?php endif?>
  <?php if($b['codigo']!==''):?><div><dt>Código</dt><dd><?=e($b['codigo'])?></dd></div><?php endif?>
  <div><dt>Participantes</dt><dd><?=(int)$b['participantes']?:'—'?><?=$b['part_reales']?' <small class="muted">reales '.(int)$b['part_reales'].'</small>':''?></dd></div>
  <div><dt>Horas</dt><dd><?=$b['horas']>0?e(ocQty((float)$b['horas'])):'—'?></dd></div>
  <div><dt>Alerta</dt><dd>al <?=(int)$b['alerta']?>% del gasto</dd></div>
  <div><dt>Elaborado por</dt><dd><?=e(userName((int)$b['owner']))?><small class="muted"><?=e(fdate($b['created'],true))?></small></dd></div>
  <?php if($b['approved']):?><div><dt>Aprobado por</dt><dd><?=e(userName($b['approved_by']!==null?(int)$b['approved_by']:null))?><small class="muted"><?=e(fdate($b['approved'],true))?></small></dd></div><?php endif?>
  <?php if($b['obs']!==''):?><div class="span-all"><dt>Observaciones</dt><dd><?=e($b['obs'])?></dd></div><?php endif?>
</dl></div></section>
<?php endif?>
<?php endif; endif?>
<?php endif?>
