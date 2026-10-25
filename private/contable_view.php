<?php
// Pantallas del módulo contable. Se incluye desde view.php ($mode empieza con "cc").
$ccCfg=ccCfg(); $centers=ccCenters();
$ccPill=fn(string $estado): string => '<span class="oc-pill '.ocEstadoClass($estado).'">'.e($estado).'</span>';
$ccTipo=fn(string $tipo): string => '<span class="cc-tipo '.e($tipo).'">'.e(CC_TIPOS[$tipo]??$tipo).'</span>';
$ccCenter=fn($cid): string => ccCenterLabel($cid===null||$cid===''?null:($centers[(int)$cid]??null));
$ccMonths=['01'=>'Enero','02'=>'Febrero','03'=>'Marzo','04'=>'Abril','05'=>'Mayo','06'=>'Junio','07'=>'Julio','08'=>'Agosto','09'=>'Septiembre','10'=>'Octubre','11'=>'Noviembre','12'=>'Diciembre'];
$ccPosted=$_SERVER['REQUEST_METHOD']==='POST' && $error!=='';

/** Formulario de ingreso o gasto (sin JavaScript: filas vacías extra para la distribución). */
$ccMovForm=function(array $m, int $id, int $rendId, array $rows) use($ccCfg, $centers): void {
    $tipo=$m['tipo']; $cats=$tipo==='ingreso'?$ccCfg['cat_ingreso']:$ccCfg['cat_gasto'];
    if($m['categoria']!=='' && !in_array($m['categoria'],$cats,true)) $cats[]=$m['categoria'];
    $extra=count($rows)>=3?1:3-count($rows); for($i=0;$i<$extra;$i++) $rows[]=['center_id'=>'','pct'=>'','monto'=>''];
    $refs=query('SELECT numero FROM oc_orders UNION SELECT numero FROM cot_quotes ORDER BY 1 DESC LIMIT 300')->fetchAll(PDO::FETCH_COLUMN);
    $n=fn($v)=>($v===''||$v===null||(int)$v===0)?'':(string)$v; ?>
<form method="post" enctype="multipart/form-data" class="oc-form"><?=csrf()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="tipo" value="<?=e($tipo)?>"><input type="hidden" name="rend" value="<?=$rendId?>">
<section class="card"><h2><?=$tipo==='ingreso'?'Datos del ingreso':'Datos del gasto'?></h2><div class="oc-grid">
  <label>Fecha del documento<input type="date" name="fecha" required value="<?=e($m['fecha'])?>"></label>
  <label>Monto total (CLP, IVA incluido)<input type="number" name="monto" min="1" step="1" required value="<?=e($n($m['monto']))?>" placeholder="Ej: 45000"></label>
  <label class="span2">Descripción<input name="descripcion" maxlength="200" required value="<?=e($m['descripcion'])?>" placeholder="<?=$tipo==='ingreso'?'Ej: Pago curso OMI, 12 alumnos':'Ej: Almuerzo equipo relatores'?>"></label>
  <label><?=$tipo==='ingreso'?'Cliente':'Proveedor'?> <small>(opcional)</small><input name="contraparte" maxlength="160" value="<?=e($m['contraparte'])?>"></label>
  <label>R.U.T. <small>(opcional)</small><input name="rut" maxlength="20" value="<?=e($m['rut'])?>" placeholder="Ej: 76.123.456-7"></label>
  <label>Tipo de documento<select name="doc_tipo"><option value="">— Elegir —</option><?php foreach(CC_DOCS as $d):?><option<?=$m['doc_tipo']===$d?' selected':''?>><?=e($d)?></option><?php endforeach?></select></label>
  <label>N° de documento<input name="doc_numero" maxlength="40" value="<?=e($m['doc_numero'])?>"></label>
  <label>Categoría<select name="categoria"><option value="">— Sin categoría —</option><?php foreach($cats as $c):?><option<?=$m['categoria']===$c?' selected':''?>><?=e($c)?></option><?php endforeach?></select></label>
  <label>Medio de pago<select name="medio"><option value="">— Elegir —</option><?php foreach(CC_MEDIOS as $d):?><option<?=$m['medio']===$d?' selected':''?>><?=e($d)?></option><?php endforeach?></select></label>
  <label>IVA incluido <small>(opcional, en pesos)</small><input type="number" name="iva" min="0" step="1" value="<?=e($n($m['iva']))?>"></label>
  <label>Referencia <small>(OC, cotización u otra)</small><input name="referencia" maxlength="60" list="cc-refs" value="<?=e($m['referencia'])?>"></label>
</div><?php if($tipo==='gasto'):?><p class="hint"><b>Boleta de honorarios:</b> registra el <b>monto bruto</b> de la boleta (líquido pagado al relator + retención del <?=e(str_replace('.',',',(string)(float)$ccCfg['ret_honorarios']))?> % que OX3 paga al SII en el F29). Así el gasto real se compara bien con el presupuesto.</p><?php endif?><datalist id="cc-refs"><?php foreach($refs as $r):?><option value="<?=e($r)?>"><?php endforeach?></datalist></section>

<section class="card"><h2>Centros de costo</h2>
<?php if(!$centers):?><p class="hint">Aún no hay centros de costo. <?=$GLOBALS['isAdmin']?'Créalos en <a href="'.e(url(['tab'=>'cc_cfg'])).'">Configuración contable</a>.':'Pide al administrador que los cree.'?> Mientras tanto, el movimiento queda «Sin centro de costo».</p>
<?php else:?><div class="cc-splits"><div class="cc-split cc-split-head"><span>Centro de costo</span><span>%</span><span>o monto ($)</span></div>
<?php foreach($rows as $r):?><div class="cc-split">
  <label><span class="sr">Centro de costo</span><select name="sp_center[]"><option value="">—</option><?php foreach($centers as $cid=>$c): if(!$c['activo'] && (string)$cid!==(string)$r['center_id']) continue;?><option value="<?=$cid?>"<?=(string)$cid===(string)$r['center_id']?' selected':''?>><?=e(ccCenterLabel($c))?></option><?php endforeach?></select></label>
  <label><span class="sr">Porcentaje</span><input type="number" name="sp_pct[]" min="0" max="100" step="0.01" value="<?=e((string)$r['pct'])?>" placeholder="%"></label>
  <label><span class="sr">Monto</span><input type="number" name="sp_monto[]" min="0" step="1" value="<?=e((string)$r['monto'])?>" placeholder="$"></label>
</div><?php endforeach?></div>
<p class="hint">Para cargar todo a un centro, elígelo y deja % y monto vacíos. Para dividir, escribe en cada fila un porcentaje <b>o</b> un monto; una fila con el centro elegido y sin valores recibe lo que falta. Si no eliges ninguno, queda «Sin centro de costo».</p><?php endif?></section>

<section class="card"><h2>Comprobantes</h2>
<label>Adjuntar boletas, facturas o fotos<input type="file" name="comprobantes[]" multiple accept=".pdf,.jpg,.jpeg,.png"></label>
<p class="hint">PDF, JPG o PNG · hasta 10 archivos a la vez · máx. <?=e($GLOBALS['config']['max_upload_mb'])?> MB cada uno.<?=$id?' Los comprobantes ya adjuntos se mantienen; se gestionan en el detalle del movimiento.':''?></p></section>

<div class="oc-actions"><button class="primary" name="action" value="cc_mov_save"><?=$id?'Guardar cambios':($rendId?'Agregar gasto a la rendición':'Guardar '.strtolower(CC_TIPOS[$tipo]))?></button><a class="btn ghost" href="<?=e($id?url(['mov'=>$id]):($rendId?url(['rend'=>$rendId]):url(['tab'=>'cc_movs'])))?>">Cancelar</a></div>
</form>
<?php };
/** Filas de distribución desde lo guardado o lo enviado. Un único centro se muestra sin valores (recibe el 100 %). */
$ccRows=function(array $splits): array {
    if(count($splits)===1) return $splits[0]['center_id']===null ? [] : [['center_id'=>$splits[0]['center_id'],'pct'=>'','monto'=>'']];
    return array_map(fn($s)=>['center_id'=>$s['center_id'],'pct'=>'','monto'=>(int)$s['monto']],$splits);
};
$ccPostedRows=function(): array { [$c,$p,$m]=[ocList('sp_center'),ocList('sp_pct'),ocList('sp_monto')]; $o=[]; foreach($c as $i=>$v) if($v!==''||($p[$i]??'')!==''||($m[$i]??'')!=='') $o[]=['center_id'=>$v,'pct'=>$p[$i]??'','monto'=>$m[$i]??'']; return $o; };
$ccEmptyMov=fn(string $tipo): array => ['tipo'=>$tipo,'fecha'=>ocToday(),'monto'=>'','iva'=>'','descripcion'=>'','contraparte'=>'','rut'=>'','doc_tipo'=>$tipo==='ingreso'?'Factura':'Boleta','doc_numero'=>'','categoria'=>'','medio'=>'','referencia'=>''];
$ccHead=function(string $title, string $sub, string $actions=''): void { ?>
<div class="page-head"><div><span class="eyebrow muted">CONTABILIDAD</span><h1><?=$title?></h1><p class="subtitle"><?=$sub?></p></div><?php if($actions):?><div class="head-actions"><?=$actions?></div><?php endif?></div>
<?php };
$ccNewBtns=$canEdit ? '<a class="btn ghost" href="'.e(url(['tab'=>'cc_new','tipo'=>'ingreso'])).'">'.ic('plus').'Ingreso</a><a class="btn ghost" href="'.e(url(['tab'=>'cc_new','tipo'=>'gasto'])).'">'.ic('plus').'Gasto</a><a class="btn primary" href="'.e(url(['tab'=>'cc_rend_new'])).'">'.ic('plus').'Nueva rendición</a>' : '';
?>

<?php if($mode==='cc'):
$y=substr(ocToday(),0,4); $mo=substr(ocToday(),0,7);
$f=ccFiltersFromGet(); if(!isset($_GET['desde']) && !isset($_GET['hasta'])) { $f['desde']=$y.'-01-01'; $f['hasta']=$y.'-12-31'; }
$w=[CC_COUNTED]; $p=[]; if($f['desde']!==''){ $w[]='m.fecha>=?'; $p[]=$f['desde']; } if($f['hasta']!==''){ $w[]='m.fecha<=?'; $p[]=$f['hasta']; }
$wc=$w; $pc=$p; if($f['centro']>0){ $wc[]='s.center_id=?'; $pc[]=$f['centro']; } elseif($f['centro']===-1) $wc[]='s.center_id IS NULL';
$base=' FROM cc_splits s JOIN cc_movs m ON m.id=s.mov_id LEFT JOIN cc_rendiciones r ON r.id=m.rendicion_id WHERE ';
$byC=[]; foreach(query('SELECT s.center_id, m.tipo, SUM(s.monto)'.$base.implode(' AND ',$w).' GROUP BY s.center_id, m.tipo',$p)->fetchAll(PDO::FETCH_NUM) as [$cid,$tp,$v]) $byC[$cid===null?0:(int)$cid][$tp]=(int)$v;
$tot=['ingreso'=>0,'gasto'=>0]; foreach(query('SELECT m.tipo, SUM(s.monto)'.$base.implode(' AND ',$wc).' GROUP BY m.tipo',$pc)->fetchAll(PDO::FETCH_NUM) as [$tp,$v]) $tot[$tp]=(int)$v;
$byM=[]; foreach(query('SELECT substr(m.fecha,1,7), m.tipo, SUM(s.monto)'.$base.implode(' AND ',$wc).' GROUP BY 1, 2 ORDER BY 1',$pc)->fetchAll(PDO::FETCH_NUM) as [$mm,$tp,$v]) $byM[$mm][$tp]=(int)$v;
$byCat=query('SELECT m.tipo, m.categoria, SUM(s.monto) AS v'.$base.implode(' AND ',$wc).' GROUP BY 1, 2 ORDER BY 3 DESC',$pc)->fetchAll(PDO::FETCH_ASSOC);
$pend=query("SELECT COUNT(*) AS n, COALESCE(SUM((SELECT COALESCE(SUM(monto),0) FROM cc_movs WHERE rendicion_id=cc_rendiciones.id)),0) AS v FROM cc_rendiciones WHERE estado='Enviada'")->fetch(PDO::FETCH_ASSOC);
$res=$tot['ingreso']-$tot['gasto'];
$periods=['Este mes'=>[$mo.'-01',(new DateTime($mo.'-01'))->modify('last day of this month')->format('Y-m-d')],'Mes anterior'=>[(new DateTime($mo.'-01'))->modify('-1 month')->format('Y-m-d'),(new DateTime($mo.'-01'))->modify('-1 day')->format('Y-m-d')],'Este año'=>[$y.'-01-01',$y.'-12-31'],'Año anterior'=>[($y-1).'-01-01',($y-1).'-12-31'],'Todo'=>['','']];
$keep=$f['centro']?['centro'=>$f['centro']]:[];
$ccHead('Resumen contable','Ingresos y gastos por centro de costo. Los gastos de una rendición cuentan cuando el administrador la aprueba.',$ccNewBtns);
?>
<form method="get" class="oc-filter"><input type="hidden" name="tab" value="cc">
  <label><span class="sr">Desde</span><input type="date" name="desde" value="<?=e($f['desde'])?>" title="Desde"></label><label><span class="sr">Hasta</span><input type="date" name="hasta" value="<?=e($f['hasta'])?>" title="Hasta"></label>
  <label><span class="sr">Centro de costo</span><select name="centro"><option value="0">Todos los centros</option><?php foreach($centers as $cid=>$c):?><option value="<?=$cid?>"<?=$f['centro']===$cid?' selected':''?>><?=e(ccCenterLabel($c))?></option><?php endforeach?><option value="-1"<?=$f['centro']===-1?' selected':''?>>Sin centro de costo</option></select></label>
  <button class="ghost"><?=ic('search')?>Ver</button></form>
<div class="chips cc-periods"><?php foreach($periods as $lbl=>[$d,$h]):?><a class="chip<?=$f['desde']===$d&&$f['hasta']===$h?' on':''?>" href="<?=e(url(['tab'=>'cc','desde'=>$d,'hasta'=>$h]+$keep))?>"><?=e($lbl)?></a><?php endforeach?></div>
<?php if($f['centro']):?><div class="alert" role="status">Mostrando solo <b><?=e($ccCenter($f['centro']===-1?null:$f['centro']))?></b>: de los gastos e ingresos divididos se considera la parte asignada a este centro. <a href="<?=e(url(['tab'=>'cc','desde'=>$f['desde'],'hasta'=>$f['hasta']]))?>">Ver todos</a></div><?php endif?>
<div class="stat-grid">
  <div class="stat"><?=ic('download')?><strong class="cc-in"><?=clp($tot['ingreso'])?></strong><span>Ingresos</span></div>
  <div class="stat"><?=ic('upload')?><strong class="cc-out"><?=clp($tot['gasto'])?></strong><span>Gastos</span></div>
  <div class="stat"><?=ic('activity')?><strong class="<?=$res<0?'cc-out':''?>"><?=ccClp($res)?></strong><span>Resultado<?=$tot['ingreso']>0?' · margen '.round(100*$res/$tot['ingreso']).'%':''?></span></div>
  <a class="stat" href="<?=e(url(['tab'=>'cc_rends','estado'=>'Enviada']))?>"><?=ic('clock')?><strong><?=(int)$pend['n']?></strong><span>Rendiciones por aprobar · <?=clp((int)$pend['v'])?></span></a>
</div>
<section class="block"><div class="block-head"><h2>Por centro de costo</h2></div>
<?php if($byC):?><div class="table-wrap"><table class="cc-table"><thead><tr><th>Centro de costo</th><th class="right">Ingresos</th><th class="right">Gastos</th><th class="right">Resultado</th><th class="right hide-sm">Margen</th></tr></thead><tbody>
<?php $sum=['ingreso'=>0,'gasto'=>0]; $order=array_keys($centers); if(isset($byC[0])) $order[]=0;
foreach($order as $cid): if(!isset($byC[$cid])) continue; $in=$byC[$cid]['ingreso']??0; $out=$byC[$cid]['gasto']??0; $sum['ingreso']+=$in; $sum['gasto']+=$out;?><tr<?=($f['centro']===$cid||($cid===0&&$f['centro']===-1))?' class="cc-on"':''?>>
  <td><a class="oc-num" href="<?=e(url(['tab'=>'cc','desde'=>$f['desde'],'hasta'=>$f['hasta'],'centro'=>$cid?:-1]))?>"><?=e($ccCenter($cid?:null))?></a><?php if($cid && !$centers[$cid]['activo']):?><small>Inactivo</small><?php endif?></td>
  <td class="right nowrap cc-in"><?=clp($in)?></td><td class="right nowrap cc-out"><?=clp($out)?></td><td class="right nowrap"><b class="<?=$in-$out<0?'cc-out':''?>"><?=ccClp($in-$out)?></b></td><td class="right hide-sm"><?=$in>0?round(100*($in-$out)/$in).'%':'—'?></td>
</tr><?php endforeach?></tbody><tfoot><tr><td>Total</td><td class="right nowrap"><?=clp($sum['ingreso'])?></td><td class="right nowrap"><?=clp($sum['gasto'])?></td><td class="right nowrap"><?=ccClp($sum['ingreso']-$sum['gasto'])?></td><td class="right hide-sm"><?=$sum['ingreso']>0?round(100*($sum['ingreso']-$sum['gasto'])/$sum['ingreso']).'%':'—'?></td></tr></tfoot></table></div>
<p class="hint">Haz clic en un centro para ver solo sus cifras. Los montos repartidos entre varios centros se suman a cada uno según su parte.</p>
<?php else:?><div class="empty-state"><?=ic('activity')?><b>Sin movimientos en este período</b><span><?=$canEdit?'Registra un ingreso, un gasto o crea una rendición.':'Cuando se registren movimientos, aparecerán aquí.'?></span></div><?php endif?></section>
<?php if($byM||$byCat):?><div class="cc-two">
<section class="block"><div class="block-head"><h2>Por mes</h2></div><div class="table-wrap"><table class="cc-table"><thead><tr><th>Mes</th><th class="right">Ingresos</th><th class="right">Gastos</th><th class="right">Resultado</th></tr></thead><tbody>
<?php foreach($byM as $mm=>$v): $in=$v['ingreso']??0; $out=$v['gasto']??0;?><tr><td><a href="<?=e(url(['tab'=>'cc_movs','desde'=>$mm.'-01','hasta'=>(new DateTime($mm.'-01'))->modify('last day of this month')->format('Y-m-d')]+$keep))?>"><?=e($ccMonths[substr($mm,5,2)].' '.substr($mm,0,4))?></a></td><td class="right nowrap cc-in"><?=clp($in)?></td><td class="right nowrap cc-out"><?=clp($out)?></td><td class="right nowrap"><b class="<?=$in-$out<0?'cc-out':''?>"><?=ccClp($in-$out)?></b></td></tr><?php endforeach?>
</tbody></table></div></section>
<section class="block"><div class="block-head"><h2>Por categoría</h2></div><div class="table-wrap"><table class="cc-table"><thead><tr><th>Categoría</th><th class="right">Monto</th><th class="hide-sm">Peso</th></tr></thead><tbody>
<?php foreach($byCat as $c): $max=max(1,$tot[$c['tipo']]);?><tr><td><?=$ccTipo($c['tipo'])?> <?=e($c['categoria']?:'Sin categoría')?></td><td class="right nowrap"><?=clp((int)$c['v'])?></td><td class="hide-sm cc-bar"><progress class="<?=e($c['tipo'])?>" max="<?=$max?>" value="<?=(int)$c['v']?>" title="<?=round(100*(int)$c['v']/$max)?>% de los <?=$c['tipo']==='ingreso'?'ingresos':'gastos'?>"></progress></td></tr><?php endforeach?>
</tbody></table></div></section>
</div><?php endif?>

<?php elseif($mode==='cc_movs'):
$f=ccFiltersFromGet(); [$where,$params]=ccMovFilter($f);
$cSel=$f['centro'];
$share=$cSel>0 ? '(SELECT SUM(monto) FROM cc_splits WHERE mov_id=m.id AND center_id='.(int)$cSel.')' : ($cSel===-1 ? '(SELECT SUM(monto) FROM cc_splits WHERE mov_id=m.id AND center_id IS NULL)' : 'm.monto');
$list=query('SELECT m.*, r.numero AS rnum, r.estado AS restado, '.$share.' AS parte, (SELECT COUNT(*) FROM cc_files WHERE mov_id=m.id) AS nfiles FROM cc_movs m LEFT JOIN cc_rendiciones r ON r.id=m.rendicion_id'.$where.' ORDER BY m.fecha DESC, m.id DESC LIMIT 500',$params)->fetchAll(PDO::FETCH_ASSOC);
$sp=[]; if($list){ $ids=implode(',',array_map(fn($x)=>(int)$x['id'],$list)); foreach(query('SELECT mov_id, center_id, pct FROM cc_splits WHERE mov_id IN ('.$ids.') ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $s) $sp[(int)$s['mov_id']][]=$s; }
$sums=['ingreso'=>0,'gasto'=>0]; [$w2,$p2]=ccMovFilter(['solo'=>'cuenta']+$f);
foreach(query('SELECT m.tipo, SUM('.$share.') FROM cc_movs m LEFT JOIN cc_rendiciones r ON r.id=m.rendicion_id'.$w2.' GROUP BY m.tipo',$p2)->fetchAll(PDO::FETCH_NUM) as [$tp,$v]) $sums[$tp]=(int)$v;
$cats=array_values(array_unique([...$ccCfg['cat_gasto'],...$ccCfg['cat_ingreso'],...query("SELECT DISTINCT categoria FROM cc_movs WHERE categoria<>''")->fetchAll(PDO::FETCH_COLUMN)]));
$active=array_filter(ccFilterQuery($f),fn($k)=>$k!=='solo',ARRAY_FILTER_USE_KEY);
$ccHead('Movimientos','Todos los ingresos y gastos, incluidos los de rendiciones. Exporta a Excel lo que estés filtrando.','<a class="btn ghost" href="'.e(url(['cc_csv'=>1]+ccFilterQuery($f))).'">'.ic('download').'Exportar CSV</a>'.$ccNewBtns);
?>
<form method="get" class="oc-filter" role="search"><input type="hidden" name="tab" value="cc_movs">
  <label class="grow"><span class="sr">Buscar</span><input name="q" value="<?=e($f['q'])?>" placeholder="Buscar por descripción, proveedor, N° documento o rendición…"></label>
  <label><span class="sr">Tipo</span><select name="tipo"><option value="">Ingresos y gastos</option><?php foreach(CC_TIPOS as $k=>$v):?><option value="<?=$k?>"<?=$f['tipo']===$k?' selected':''?>><?=$v?>s</option><?php endforeach?></select></label>
  <label><span class="sr">Centro de costo</span><select name="centro"><option value="0">Todos los centros</option><?php foreach($centers as $cid=>$c):?><option value="<?=$cid?>"<?=$cSel===$cid?' selected':''?>><?=e(ccCenterLabel($c))?></option><?php endforeach?><option value="-1"<?=$cSel===-1?' selected':''?>>Sin centro de costo</option></select></label>
  <label><span class="sr">Categoría</span><select name="cat"><option value="">Todas las categorías</option><?php foreach($cats as $c):?><option<?=$f['cat']===$c?' selected':''?>><?=e($c)?></option><?php endforeach?></select></label>
  <label><span class="sr">Desde</span><input type="date" name="desde" value="<?=e($f['desde'])?>" title="Desde"></label><label><span class="sr">Hasta</span><input type="date" name="hasta" value="<?=e($f['hasta'])?>" title="Hasta"></label>
  <button class="ghost"><?=ic('search')?>Filtrar</button><?php if($active):?><a class="btn ghost" href="<?=e(url(['tab'=>'cc_movs']))?>">Limpiar</a><?php endif?></form>
<div class="stat-grid cc-mini">
  <div class="stat"><?=ic('download')?><strong class="cc-in"><?=clp($sums['ingreso'])?></strong><span>Ingresos<?=$active?' (filtro)':''?></span></div>
  <div class="stat"><?=ic('upload')?><strong class="cc-out"><?=clp($sums['gasto'])?></strong><span>Gastos<?=$active?' (filtro)':''?></span></div>
  <div class="stat"><?=ic('activity')?><strong><?=ccClp($sums['ingreso']-$sums['gasto'])?></strong><span>Resultado<?=$cSel?' del centro':''?></span></div>
</div>
<?php if($list):?><div class="table-wrap"><table class="oc-reg"><thead><tr><th>Fecha</th><th>Movimiento</th><th class="hide-sm">Centro de costo</th><th class="hide-sm">Categoría</th><th class="right">Monto</th><th class="right">Acciones</th></tr></thead><tbody>
<?php foreach($list as $m): $mid=(int)$m['id']; $counted=$m['rendicion_id']===null||$m['restado']==='Aprobada';?><tr<?=$counted?'':' class="cc-pending"'?>>
  <td class="nowrap"><?=e(ocDate($m['fecha'],'—'))?><small><?=$ccTipo($m['tipo'])?></small></td>
  <td><a class="oc-num" href="<?=e(url(['mov'=>$mid]))?>"><?=e($m['descripcion'])?></a><small><?=e(implode(' · ',array_filter([$m['contraparte'],trim($m['doc_tipo'].' '.$m['doc_numero'])],'strlen')))?></small><?php if($m['rnum']):?><small><a href="<?=e(url(['rend'=>(int)$m['rendicion_id']]))?>"><?=e($m['rnum'])?></a> <?=$ccPill($m['restado'])?><?=$counted?'':' · aún no cuenta'?></small><?php endif?></td>
  <td class="hide-sm"><?php foreach($sp[$mid]??[] as $s):?><div class="cc-split-line"><?=e($ccCenter($s['center_id']))?><?=count($sp[$mid])>1?' <small>'.e(ocQty((float)$s['pct'])).'%</small>':''?></div><?php endforeach?></td>
  <td class="hide-sm"><?=e($m['categoria']?:'—')?></td>
  <td class="right nowrap"><b class="<?=$m['tipo']==='ingreso'?'cc-in':'cc-out'?>"><?=$m['tipo']==='gasto'?'− ':''?><?=clp((int)$m['parte'])?></b><?php if($cSel && (int)$m['parte']!==(int)$m['monto']):?><small>de <?=clp((int)$m['monto'])?></small><?php endif?></td>
  <td class="right nowrap"><?php if($m['nfiles']):?><span class="cc-clip" title="<?=(int)$m['nfiles']?> comprobante(s)">📎<?=(int)$m['nfiles']?></span><?php endif?><a class="icon-btn" href="<?=e(url(['mov'=>$mid]))?>" title="Abrir"><?=ic('eye')?><span class="sr">Abrir</span></a></td>
</tr><?php endforeach?></tbody></table></div>
<?php if(count($list)>=500):?><p class="hint">Se muestran los 500 más recientes; usa los filtros para encontrar otros.</p><?php endif?>
<p class="hint">Los gastos de rendiciones que aún no están aprobadas se ven atenuados y no suman en los totales.</p>
<?php else:?><div class="empty-state"><?=ic('activity')?><b><?=$active?'No hay movimientos que coincidan':'Todavía no hay movimientos'?></b><span><?=$canEdit?'Registra un ingreso o un gasto con los botones de arriba.':'Cuando se registren movimientos, aparecerán aquí.'?></span></div><?php endif?>

<?php elseif($mode==='cc_new'):
$tipo=$g('tipo')==='ingreso'?'ingreso':'gasto';
$m=$ccPosted && post('action')==='cc_mov_save' ? ['tipo'=>$tipo]+ccMovFromPost($tipo) : $ccEmptyMov($tipo);
if($ccPosted){ $m['fecha']=post('fecha'); }
?>
<nav class="crumbs" aria-label="Ruta"><a href="<?=e(url(['tab'=>'cc_movs']))?>">Movimientos</a><?=ic('chevron')?><span><?=$tipo==='ingreso'?'Nuevo ingreso':'Nuevo gasto'?></span></nav>
<?php $ccHead($tipo==='ingreso'?'Nuevo ingreso':'Nuevo gasto','Registra el documento, adjunta su respaldo y asígnalo a uno o varios centros de costo.','<div class="seg" role="group" aria-label="Tipo"><a href="'.e(url(['tab'=>'cc_new','tipo'=>'ingreso'])).'"'.($tipo==='ingreso'?' class="on"':'').'>Ingreso</a><a href="'.e(url(['tab'=>'cc_new','tipo'=>'gasto'])).'"'.($tipo==='gasto'?' class="on"':'').'>Gasto</a></div>');?>
<?php if($tipo==='gasto'):?><p class="hint cc-note">¿Gastos pagados por una persona con su dinero o con un fondo por rendir? Usa una <a href="<?=e(url(['tab'=>'cc_rend_new']))?>">rendición</a>, que el administrador aprueba.</p><?php endif?>
<?php $ccMovForm($m,0,0,$ccPosted?$ccPostedRows():[])?>

<?php elseif($mode==='cc_mov_doc'): $mov=ccMov($movId);
if(!$mov):?><div class="empty-state"><?=ic('search')?><b>Movimiento no encontrado</b><span><a href="<?=e(url(['tab'=>'cc_movs']))?>">Volver a movimientos</a></span></div><?php else:
$rend=$mov['rendicion_id']!==null?ccRend((int)$mov['rendicion_id']):false; $editable=ccMovEditable($mov);
$canDelete=$rend?$editable:$isAdmin;
if($editable && $g('edit')==='1'):
  $m=$ccPosted ? ['tipo'=>$mov['tipo']]+ccMovFromPost($mov['tipo']) : $mov; if($ccPosted) $m['fecha']=post('fecha');?>
<nav class="crumbs" aria-label="Ruta"><?php if($rend):?><a href="<?=e(url(['tab'=>'cc_rends']))?>">Rendiciones</a><?=ic('chevron')?><a href="<?=e(url(['rend'=>(int)$rend['id']]))?>"><?=e($rend['numero'])?></a><?php else:?><a href="<?=e(url(['tab'=>'cc_movs']))?>">Movimientos</a><?php endif?><?=ic('chevron')?><a href="<?=e(url(['mov'=>(int)$mov['id']]))?>"><?=e($mov['descripcion'])?></a><?=ic('chevron')?><span>Editar</span></nav>
<?php $ccHead('Editar '.strtolower(CC_TIPOS[$mov['tipo']]),e($mov['descripcion']))?>
<?php $ccMovForm($m,(int)$mov['id'],0,$ccPosted?$ccPostedRows():$ccRows($mov['splits']))?>
<?php else: $ver=(int)$g('ver'); $verFile=null; foreach($mov['files'] as $fi) if((int)$fi['id']===$ver) $verFile=$fi;
$owner=(string)query('SELECT name FROM users WHERE id=?',[$mov['owner']])->fetchColumn(); ?>
<nav class="crumbs" aria-label="Ruta"><?php if($rend):?><a href="<?=e(url(['tab'=>'cc_rends']))?>">Rendiciones</a><?=ic('chevron')?><a href="<?=e(url(['rend'=>(int)$rend['id']]))?>"><?=e($rend['numero'])?></a><?php else:?><a href="<?=e(url(['tab'=>'cc_movs']))?>">Movimientos</a><?php endif?><?=ic('chevron')?><span><?=e($mov['descripcion'])?></span></nav>
<div class="page-head">
  <div class="title-row"><a class="icon-btn" href="<?=e($rend?url(['rend'=>(int)$rend['id']]):url(['tab'=>'cc_movs']))?>" title="Volver"><?=ic('back')?><span class="sr">Volver</span></a><span class="tile-icon big <?=$mov['tipo']==='ingreso'?'tone2':'tone4'?>"><?=ic($mov['tipo']==='ingreso'?'download':'upload')?></span><div><h1><?=e($mov['descripcion'])?> <?=$ccTipo($mov['tipo'])?></h1><p class="subtitle"><?=clp((int)$mov['monto'])?> · <?=e(ocDate($mov['fecha']))?> · registrado por <?=e($owner)?> el <?=e(fdate($mov['created'],true))?></p></div></div>
  <div class="head-actions">
    <?php if($editable):?><a class="btn primary" href="<?=e(url(['mov'=>(int)$mov['id'],'edit'=>1]))?>"><?=ic('edit')?>Editar</a><?php endif?>
    <?php if($canDelete):?><details class="pop"><summary class="btn ghost" title="Más opciones"><?=ic('more')?><span class="sr">Más opciones</span></summary><form method="post" class="pop-body danger-zone"><?=csrf()?><input type="hidden" name="id" value="<?=(int)$mov['id']?>"><strong>Eliminar <?=strtolower(CC_TIPOS[$mov['tipo']])?></strong><p>Se elimina junto con sus comprobantes. No se puede deshacer.</p><button class="danger" name="action" value="cc_mov_delete"><?=ic('trash')?>Eliminar</button></form></details><?php endif?>
  </div>
</div>
<?php if($rend && !$editable):?><div class="alert" role="status">Este gasto es parte de la rendición <a href="<?=e(url(['rend'=>(int)$rend['id']]))?>"><?=e($rend['numero'])?></a> (<?=e(strtolower($rend['estado']))?>)<?=$rend['estado']==='Aprobada'?' y ya cuenta en los resultados':''?>. Solo se puede modificar mientras la rendición está en borrador o rechazada.</div><?php endif?>
<section class="card"><h2>Detalle</h2><dl class="cc-dl">
  <div><dt>Fecha</dt><dd><?=e(ocDate($mov['fecha']))?></dd></div>
  <div><dt>Monto total</dt><dd><b><?=clp((int)$mov['monto'])?></b><?=$mov['iva']>0?' <small class="muted">IVA incluido '.clp((int)$mov['iva']).'</small>':''?></dd></div>
  <div><dt><?=$mov['tipo']==='ingreso'?'Cliente':'Proveedor'?></dt><dd><?=e($mov['contraparte']?:'—')?><?=$mov['rut']!==''?' <small class="muted">'.e($mov['rut']).'</small>':''?></dd></div>
  <div><dt>Documento</dt><dd><?=e(trim($mov['doc_tipo'].' '.$mov['doc_numero'])?:'—')?></dd></div>
  <div><dt>Categoría</dt><dd><?=e($mov['categoria']?:'—')?></dd></div>
  <div><dt>Medio de pago</dt><dd><?=e($mov['medio']?:'—')?></dd></div>
  <?php if($mov['referencia']!==''):?><div><dt>Referencia</dt><dd><?=e($mov['referencia'])?></dd></div><?php endif?>
  <?php if($rend):?><div><dt>Rendición</dt><dd><a href="<?=e(url(['rend'=>(int)$rend['id']]))?>"><?=e($rend['numero'])?></a> <?=$ccPill($rend['estado'])?></dd></div><?php endif?>
</dl></section>
<section class="card"><h2>Distribución por centro de costo</h2><div class="table-wrap"><table><thead><tr><th>Centro de costo</th><th class="right">%</th><th class="right">Monto</th></tr></thead><tbody>
<?php foreach($mov['splits'] as $s):?><tr><td><?=e($ccCenter($s['center_id']))?></td><td class="right"><?=e(ocQty((float)$s['pct']))?>%</td><td class="right nowrap"><?=clp((int)$s['monto'])?></td></tr><?php endforeach?>
</tbody></table></div></section>
<?php psMovTrace($mov)?>
<section class="card"><h2>Comprobantes <small class="muted"><?=count($mov['files'])?></small></h2>
<?php if($mov['files']):?><div class="table-wrap"><table><tbody><?php foreach($mov['files'] as $fi): [$tl,$tc]=ftype($fi['name']);?><tr<?=$verFile&&(int)$fi['id']===(int)$verFile['id']?' class="cc-on"':''?>>
  <td><a class="doc" href="<?=e(url(['mov'=>(int)$mov['id'],'ver'=>(int)$fi['id']]))?>"><span class="ftype <?=$tc?>"><?=$tl?></span><span><b><?=e($fi['name'])?></b><small><?=size($fi['size'])?> · <?=e(fdate($fi['created'],true))?></small></span></a></td>
  <td class="right nowrap"><a class="icon-btn" href="<?=e(url(['mov'=>(int)$mov['id'],'ver'=>(int)$fi['id']]))?>" title="Ver"><?=ic('eye')?><span class="sr">Ver</span></a><a class="icon-btn" href="<?=e(url(['cc_file'=>(int)$fi['id'],'dl'=>1]))?>" title="Descargar"><?=ic('download')?><span class="sr">Descargar</span></a>
  <?php if($editable && ($rend || $isAdmin || (int)$fi['owner']===(int)$user['id'])):?><form method="post" class="cc-inline"><?=csrf()?><input type="hidden" name="id" value="<?=(int)$fi['id']?>"><button class="icon-btn" name="action" value="cc_file_delete" title="Quitar comprobante"><?=ic('trash')?><span class="sr">Quitar</span></button></form><?php endif?></td></tr><?php endforeach?></tbody></table></div>
<?php else:?><p class="hint">Sin comprobantes adjuntos.</p><?php endif?>
<?php if($editable):?><form method="post" enctype="multipart/form-data" class="cc-addfiles"><?=csrf()?><input type="hidden" name="id" value="<?=(int)$mov['id']?>"><label>Agregar comprobantes<input type="file" name="comprobantes[]" multiple accept=".pdf,.jpg,.jpeg,.png" required></label><button class="primary" name="action" value="cc_files_add"><?=ic('upload')?>Adjuntar</button></form><?php endif?>
</section>
<?php if($verFile): $k=previewKind($verFile['name']);?><section class="viewer viewer-<?=$k?>"><?php if($k==='pdf'):?><iframe src="<?=e(url(['cc_file'=>(int)$verFile['id']]))?>" title="Comprobante <?=e($verFile['name'])?>"></iframe><?php else:?><img src="<?=e(url(['cc_file'=>(int)$verFile['id']]))?>" alt="Comprobante <?=e($verFile['name'])?>"><?php endif?></section><?php endif?>
<?php endif; endif?>

<?php elseif($mode==='cc_rends'):
$fe=in_array($g('estado'),CC_REND_ESTADOS,true)?$g('estado'):''; $q=ocText($g('q'),80); $mine=$g('mias')==='1';
$w=[]; $p=[]; if($fe){ $w[]='r.estado=?'; $p[]=$fe; } if($mine){ $w[]='r.owner=?'; $p[]=$user['id']; } if($q!==''){ $w[]='(r.numero LIKE ? OR r.titulo LIKE ? OR r.responsable LIKE ?)'; array_push($p,"%$q%","%$q%","%$q%"); }
$list=query('SELECT r.*, COALESCE((SELECT SUM(monto) FROM cc_movs WHERE rendicion_id=r.id),0) AS total, (SELECT COUNT(*) FROM cc_movs WHERE rendicion_id=r.id) AS n FROM cc_rendiciones r'.($w?' WHERE '.implode(' AND ',$w):'').' ORDER BY r.id DESC LIMIT 500',$p)->fetchAll(PDO::FETCH_ASSOC);
$k=[]; foreach(query('SELECT estado, COUNT(*), COALESCE(SUM((SELECT COALESCE(SUM(monto),0) FROM cc_movs WHERE rendicion_id=cc_rendiciones.id)),0) FROM cc_rendiciones GROUP BY estado')->fetchAll(PDO::FETCH_NUM) as [$st,$n,$v]) $k[$st]=[(int)$n,(int)$v];
$ccHead('Rendiciones','Gastos pagados por una persona, con sus comprobantes. Se envían a aprobación y, al aprobarse, cuentan en los resultados.',$canEdit?'<a class="btn primary" href="'.e(url(['tab'=>'cc_rend_new'])).'">'.ic('plus').'Nueva rendición</a>':'');
?>
<div class="stat-grid">
  <a class="stat" href="<?=e(url(['tab'=>'cc_rends','estado'=>'Borrador']))?>"><?=ic('edit')?><strong><?=$k['Borrador'][0]??0?></strong><span>En borrador · <?=clp($k['Borrador'][1]??0)?></span></a>
  <a class="stat" href="<?=e(url(['tab'=>'cc_rends','estado'=>'Enviada']))?>"><?=ic('clock')?><strong><?=$k['Enviada'][0]??0?></strong><span>Por aprobar · <?=clp($k['Enviada'][1]??0)?></span></a>
  <a class="stat" href="<?=e(url(['tab'=>'cc_rends','estado'=>'Aprobada']))?>"><?=ic('activity')?><strong><?=$k['Aprobada'][0]??0?></strong><span>Aprobadas · <?=clp($k['Aprobada'][1]??0)?></span></a>
  <a class="stat" href="<?=e(url(['tab'=>'cc_rends','estado'=>'Rechazada']))?>"><?=ic('back')?><strong><?=$k['Rechazada'][0]??0?></strong><span>Rechazadas (por corregir)</span></a>
</div>
<form method="get" class="oc-filter" role="search"><input type="hidden" name="tab" value="cc_rends"><label class="grow"><span class="sr">Buscar</span><input name="q" value="<?=e($q)?>" placeholder="Buscar por N°, motivo o responsable…"></label><label><span class="sr">Estado</span><select name="estado"><option value="">Todos los estados</option><?php foreach(CC_REND_ESTADOS as $s):?><option<?=$fe===$s?' selected':''?>><?=e($s)?></option><?php endforeach?></select></label><label class="cc-check"><input type="checkbox" name="mias" value="1"<?=$mine?' checked':''?>> Solo las mías</label><button class="ghost"><?=ic('search')?>Filtrar</button><?php if($q!==''||$fe||$mine):?><a class="btn ghost" href="<?=e(url(['tab'=>'cc_rends']))?>">Limpiar</a><?php endif?></form>
<?php if($list):?><div class="table-wrap"><table class="oc-reg"><thead><tr><th>N°</th><th>Motivo</th><th class="hide-sm">Responsable</th><th class="right">Rendido</th><th class="right hide-sm">Saldo</th><th>Estado</th><th class="right">Acciones</th></tr></thead><tbody>
<?php foreach($list as $r): $saldo=(int)$r['fondo']-(int)$r['total'];?><tr>
  <td class="nowrap"><a class="oc-num" href="<?=e(url(['rend'=>(int)$r['id']]))?>"><?=e($r['numero'])?></a><small><?=e(ocDate($r['fecha'],'—'))?></small></td>
  <td><?=e($r['titulo'])?><small><?=(int)$r['n']?> gasto<?=(int)$r['n']===1?'':'s'?><?=$r['center_id']?' · '.e($ccCenter($r['center_id'])):''?></small></td><td class="hide-sm"><?=e($r['responsable'])?></td>
  <td class="right nowrap"><b><?=clp((int)$r['total'])?></b><?=$r['fondo']>0?'<small>Fondo '.clp((int)$r['fondo']).'</small>':''?></td>
  <td class="right nowrap hide-sm"><?=clp(abs($saldo))?><small><?=e($saldo>0?'a devolver':($saldo<0?'a reembolsar':'—'))?></small></td>
  <td class="nowrap"><?=$ccPill($r['estado'])?></td>
  <td class="right nowrap"><a class="icon-btn" href="<?=e(url(['rend'=>(int)$r['id']]))?>" title="Abrir"><?=ic('eye')?><span class="sr">Abrir</span></a><a class="icon-btn" href="<?=e(url(['cc_rend_print'=>(int)$r['id']]))?>" target="_blank" rel="noopener" title="Imprimir / PDF"><?=ic('print')?><span class="sr">Imprimir / PDF</span></a></td>
</tr><?php endforeach?></tbody></table></div>
<?php else:?><div class="empty-state"><?=ic('inbox')?><b><?=($q!==''||$fe||$mine)?'No hay rendiciones que coincidan':'Todavía no hay rendiciones'?></b><span><?=$canEdit?'Crea la primera con «Nueva rendición».':'Cuando un editor cree una rendición, aparecerá aquí.'?></span></div><?php endif?>

<?php elseif($mode==='cc_rend_new'):
$r=$ccPosted ? ['titulo'=>post('titulo'),'fecha'=>post('fecha'),'responsable'=>post('responsable'),'fondo'=>post('fondo'),'center_id'=>post('center_id'),'obs'=>post('obs')] : ['titulo'=>'','fecha'=>ocToday(),'responsable'=>$user['name'],'fondo'=>'','center_id'=>'','obs'=>''];
?>
<nav class="crumbs" aria-label="Ruta"><a href="<?=e(url(['tab'=>'cc_rends']))?>">Rendiciones</a><?=ic('chevron')?><span>Nueva rendición</span></nav>
<?php $ccHead('Nueva rendición','Primero indica el motivo y el fondo recibido; luego agregarás cada gasto con su comprobante. Número asignado al guardar: '.e(ccNextRendNumero()).'.');?>
<form method="post" class="oc-form"><?=csrf()?><section class="card"><h2>Rendición</h2><div class="oc-grid">
  <label class="span2">Motivo<input name="titulo" maxlength="120" required value="<?=e($r['titulo'])?>" placeholder="Ej: Curso OMI Puerto Natales, octubre"></label>
  <label>Responsable (quien rinde)<input name="responsable" maxlength="100" required value="<?=e($r['responsable'])?>"></label>
  <label>Fecha<input type="date" name="fecha" required value="<?=e($r['fecha'])?>"></label>
  <label>Fondo recibido / anticipo <small>(0 si pagó con su dinero)</small><input type="number" name="fondo" min="0" step="1" value="<?=e((string)$r['fondo'])?>" placeholder="0"></label>
  <label>Centro de costo predeterminado <small>(opcional)</small><select name="center_id"><option value="">—</option><?php foreach($centers as $cid=>$c): if(!$c['activo']) continue;?><option value="<?=$cid?>"<?=(string)$cid===(string)$r['center_id']?' selected':''?>><?=e(ccCenterLabel($c))?></option><?php endforeach?></select></label>
  <label class="span2">Observaciones <small>(opcional)</small><textarea name="obs" maxlength="1000" rows="2"><?=e($r['obs'])?></textarea></label>
</div><p class="hint">El centro predeterminado se propone en cada gasto; puedes cambiarlo o dividir un gasto entre varios centros.</p></section>
<div class="oc-actions"><button class="primary" name="action" value="cc_rend_save">Crear rendición</button><a class="btn ghost" href="<?=e(url(['tab'=>'cc_rends']))?>">Cancelar</a></div></form>

<?php elseif($mode==='cc_rend_doc'): $rend=ccRend($rendId);
if(!$rend):?><div class="empty-state"><?=ic('search')?><b>Rendición no encontrada</b><span><a href="<?=e(url(['tab'=>'cc_rends']))?>">Volver a rendiciones</a></span></div><?php else:
$t=ccRendTotals($rend); $editable=ccRendEditable($rend);
$gastos=query('SELECT m.*, (SELECT COUNT(*) FROM cc_files WHERE mov_id=m.id) AS nfiles FROM cc_movs m WHERE rendicion_id=? ORDER BY fecha, id',[$rend['id']])->fetchAll(PDO::FETCH_ASSOC);
$sp=[]; foreach(query('SELECT s.* FROM cc_splits s JOIN cc_movs m ON m.id=s.mov_id WHERE m.rendicion_id=? ORDER BY s.id',[$rend['id']])->fetchAll(PDO::FETCH_ASSOC) as $s) $sp[(int)$s['mov_id']][]=$s;
$sinComp=count(array_filter($gastos,fn($x)=>!(int)$x['nfiles'])); $nComp=array_sum(array_column($gastos,'nfiles'));
$canDel=$isAdmin || ($rend['estado']==='Borrador' && (int)$rend['owner']===(int)$user['id'] && $canEdit);
$reviewer=$rend['reviewer']?(string)query('SELECT name FROM users WHERE id=?',[$rend['reviewer']])->fetchColumn():'';
?>
<nav class="crumbs no-print" aria-label="Ruta"><a href="<?=e(url(['tab'=>'cc_rends']))?>">Rendiciones</a><?=ic('chevron')?><span><?=e($rend['numero'])?></span></nav>
<div class="page-head">
  <div class="title-row"><a class="icon-btn" href="<?=e(url(['tab'=>'cc_rends']))?>" title="Volver"><?=ic('back')?><span class="sr">Volver</span></a><span class="tile-icon big tone3"><?=ic('inbox')?></span><div><h1><?=e($rend['numero'])?> <?=$ccPill($rend['estado'])?></h1><p class="subtitle"><?=e($rend['titulo'])?> · rinde <?=e($rend['responsable'])?> · <?=e(ocDate($rend['fecha']))?><?=$rend['center_id']?' · '.e($ccCenter($rend['center_id'])):''?></p></div></div>
  <div class="head-actions">
    <a class="btn ghost" href="<?=e(url(['cc_rend_print'=>(int)$rend['id']]))?>" target="_blank" rel="noopener"><?=ic('print')?>Imprimir / PDF</a>
    <?php if($editable):?><details class="pop"><summary class="btn ghost"><?=ic('edit')?>Editar datos</summary><form method="post" class="pop-body"><?=csrf()?><input type="hidden" name="id" value="<?=(int)$rend['id']?>"><strong>Datos de la rendición</strong>
      <label>Motivo<input name="titulo" maxlength="120" required value="<?=e($rend['titulo'])?>"></label><label>Responsable<input name="responsable" maxlength="100" required value="<?=e($rend['responsable'])?>"></label>
      <label>Fecha<input type="date" name="fecha" required value="<?=e($rend['fecha'])?>"></label><label>Fondo recibido<input type="number" name="fondo" min="0" step="1" value="<?=(int)$rend['fondo']?:''?>" placeholder="0"></label>
      <label>Centro predeterminado<select name="center_id"><option value="">—</option><?php foreach($centers as $cid=>$c): if(!$c['activo'] && (int)$rend['center_id']!==$cid) continue;?><option value="<?=$cid?>"<?=(int)$rend['center_id']===$cid?' selected':''?>><?=e(ccCenterLabel($c))?></option><?php endforeach?></select></label>
      <label>Observaciones<textarea name="obs" maxlength="1000" rows="2"><?=e($rend['obs'])?></textarea></label>
      <button class="primary" name="action" value="cc_rend_save">Guardar</button></form></details>
    <form method="post" class="cc-inline"><?=csrf()?><input type="hidden" name="id" value="<?=(int)$rend['id']?>"><button class="primary" name="action" value="cc_rend_send"<?=$t['n']?'':' disabled title="Agrega al menos un gasto"'?>><?=ic('external')?><?=$rend['estado']==='Rechazada'?'Reenviar a aprobación':'Enviar a aprobación'?></button></form><?php endif?>
    <?php if($isAdmin && $rend['estado']==='Enviada'):?><details class="pop"><summary class="btn primary">Revisar</summary><form method="post" class="pop-body"><?=csrf()?><input type="hidden" name="id" value="<?=(int)$rend['id']?>"><strong>Aprobar o rechazar</strong><label>Comentario <small>(obligatorio si rechazas)</small><textarea name="revision" maxlength="600" rows="3" placeholder="Ej: falta la boleta del almuerzo del día 12"></textarea></label><button class="primary" name="decision" value="aprobar">Aprobar rendición</button><button class="danger" name="decision" value="rechazar">Rechazar y devolver</button><input type="hidden" name="action" value="cc_rend_review"></form></details><?php endif?>
    <?php if($isAdmin && in_array($rend['estado'],['Aprobada','Rechazada','Enviada'],true) || $canDel):?><details class="pop"><summary class="btn ghost" title="Más opciones"><?=ic('more')?><span class="sr">Más opciones</span></summary><div class="pop-body">
      <?php if($isAdmin && in_array($rend['estado'],['Aprobada','Rechazada','Enviada'],true)):?><form method="post" class="stack"><?=csrf()?><input type="hidden" name="id" value="<?=(int)$rend['id']?>"><strong>Devolver a borrador</strong><p class="hint">Permite volver a modificarla. Si estaba aprobada, sus gastos dejan de contar hasta que se apruebe de nuevo.</p><button class="ghost" name="action" value="cc_rend_reopen">Devolver a borrador</button></form><?php endif?>
      <?php if($canDel):?><form method="post" class="stack danger-zone"><?=csrf()?><input type="hidden" name="id" value="<?=(int)$rend['id']?>"><p>Se elimina con todos sus gastos y comprobantes. No se puede deshacer.</p><button class="danger" name="action" value="cc_rend_delete"><?=ic('trash')?>Eliminar rendición</button></form><?php endif?>
    </div></details><?php endif?>
  </div>
</div>
<?php if($rend['estado']==='Rechazada'):?><div class="alert error" role="alert"><b>Rechazada<?=$reviewer!==''?' por '.e($reviewer):''?><?=$rend['reviewed']?' el '.e(fdate($rend['reviewed'],true)):''?>.</b> <?=e($rend['revision'])?> Corrige lo indicado y vuelve a enviarla.</div>
<?php elseif($rend['estado']==='Enviada'):?><div class="alert" role="status">Enviada a aprobación el <?=e(fdate((string)$rend['sent'],true))?>. Mientras se revisa no se puede modificar.</div>
<?php elseif($rend['estado']==='Aprobada'):?><div class="alert" role="status">Aprobada<?=$reviewer!==''?' por '.e($reviewer):''?> el <?=e(fdate((string)$rend['reviewed'],true))?>. Sus gastos ya cuentan en los resultados.<?=$rend['revision']!==''?' Comentario: '.e($rend['revision']):''?></div><?php endif?>
<div class="stat-grid">
  <div class="stat"><?=ic('download')?><strong><?=clp((int)$rend['fondo'])?></strong><span>Fondo recibido</span></div>
  <div class="stat"><?=ic('upload')?><strong><?=clp($t['gastos'])?></strong><span>Total rendido · <?=$t['n']?> gasto<?=$t['n']===1?'':'s'?></span></div>
  <div class="stat"><?=ic('activity')?><strong class="<?=$t['saldo']<0?'cc-out':''?>"><?=clp(abs($t['saldo']))?></strong><span><?=e(ccSaldoText($t['saldo']))?></span></div>
  <div class="stat"><?=ic('inbox')?><strong><?=$nComp?></strong><span>Comprobantes<?=$sinComp?' · '.$sinComp.' gasto'.($sinComp===1?'':'s').' sin respaldo':''?></span></div>
</div>
<?php if($rend['obs']!==''):?><p class="hint cc-note"><b>Observaciones:</b> <?=e($rend['obs'])?></p><?php endif?>
<section class="block"><div class="block-head"><h2>Gastos <small><?=$t['n']?></small></h2></div>
<?php if($gastos):?><div class="table-wrap"><table class="oc-reg"><thead><tr><th class="hide-sm">Fecha</th><th>Gasto</th><th class="hide-sm">Centro de costo</th><th class="right">Monto</th><th class="right">Acciones</th></tr></thead><tbody>
<?php foreach($gastos as $m): $mid=(int)$m['id'];?><tr>
  <td class="nowrap hide-sm"><?=e(ocDate($m['fecha'],'—'))?></td>
  <td><a class="oc-num" href="<?=e(url(['mov'=>$mid]))?>"><?=e($m['descripcion'])?></a><small class="cc-date-sm"><?=e(ocDate($m['fecha'],'—'))?></small><small><?=e(implode(' · ',array_filter([$m['contraparte'],trim($m['doc_tipo'].' '.$m['doc_numero']),$m['categoria']],'strlen')))?></small></td>
  <td class="hide-sm"><?php foreach($sp[$mid]??[] as $s):?><div class="cc-split-line"><?=e($ccCenter($s['center_id']))?><?=count($sp[$mid])>1?' <small>'.e(ocQty((float)$s['pct'])).'%</small>':''?></div><?php endforeach?></td>
  <td class="right nowrap"><b><?=clp((int)$m['monto'])?></b></td>
  <td class="right nowrap"><?php if($m['nfiles']):?><span class="cc-clip" title="<?=(int)$m['nfiles']?> comprobante(s)">📎<?=(int)$m['nfiles']?></span><?php else:?><span class="cc-missing" title="Sin comprobante">Sin respaldo</span><?php endif?><a class="icon-btn" href="<?=e(url(['mov'=>$mid]))?>" title="Ver"><?=ic('eye')?><span class="sr">Ver</span></a><?php if($editable):?><a class="icon-btn" href="<?=e(url(['mov'=>$mid,'edit'=>1]))?>" title="Editar"><?=ic('edit')?><span class="sr">Editar</span></a><form method="post" class="cc-inline"><?=csrf()?><input type="hidden" name="id" value="<?=$mid?>"><button class="icon-btn" name="action" value="cc_mov_delete" title="Quitar gasto"><?=ic('trash')?><span class="sr">Quitar gasto</span></button></form><?php endif?></td>
</tr><?php endforeach?></tbody><tfoot><tr><td class="hide-sm"></td><td colspan="2" class="right">Total rendido</td><td class="right nowrap"><?=clp($t['gastos'])?></td><td></td></tr></tfoot></table></div>
<?php else:?><div class="empty-state"><?=ic('upload')?><b>Sin gastos todavía</b><span><?=$editable?'Agrega cada boleta o factura con el formulario de abajo.':'Esta rendición no tiene gastos.'?></span></div><?php endif?>
</section>
<?php if($editable):
  $isPost=$ccPosted && post('action')==='cc_mov_save' && (int)post('rend')===(int)$rend['id'];
  $m=$isPost ? ['tipo'=>'gasto']+ccMovFromPost('gasto') : $ccEmptyMov('gasto'); if($isPost) $m['fecha']=post('fecha');
  $rows=$isPost ? $ccPostedRows() : ($rend['center_id']?[['center_id'=>$rend['center_id'],'pct'=>'','monto'=>'']]:[]); ?>
<section class="block cc-addgasto" id="agregar"><div class="block-head"><h2>Agregar gasto</h2></div><?php $ccMovForm($m,0,(int)$rend['id'],$rows)?></section>
<?php endif; endif?>

<?php elseif($mode==='cc_cfg'):
$used=[]; foreach(query('SELECT center_id, COUNT(*) FROM cc_splits WHERE center_id IS NOT NULL GROUP BY center_id')->fetchAll(PDO::FETCH_NUM) as [$cid,$n]) $used[(int)$cid]=(int)$n;
$missing=(int)query('SELECT COUNT(*) FROM projects WHERE name NOT IN (SELECT nombre FROM cc_centers)')->fetchColumn();
?>
<nav class="crumbs" aria-label="Ruta"><a href="<?=e(url(['tab'=>'cc']))?>">Contabilidad</a><?=ic('chevron')?><span>Configuración</span></nav>
<?php $ccHead('Configuración contable','Centros de costo y categorías de ingresos y gastos.');?>
<section class="card"><h2>Nuevo centro de costo</h2><form method="post" class="row-form"><?=csrf()?><label>Código <small>(opcional)</small><input name="codigo" maxlength="20" placeholder="Ej: CC-01"></label><label>Nombre<input name="nombre" maxlength="80" required placeholder="Ej: Curso OMI 2026"></label><label>Descripción <small>(opcional)</small><input name="descripcion" maxlength="200"></label><button class="primary" name="action" value="cc_center_save">Crear</button></form>
<?php if($missing):?><form method="post" class="cc-import"><?=csrf()?><p class="hint"><?=$missing?> proyecto<?=$missing===1?'':'s'?> de la gestión documental aún no tiene<?=$missing===1?'':'n'?> centro de costo.</p><button class="ghost small" name="action" value="cc_center_import"><?=ic('project')?>Crear centros desde los proyectos</button></form><?php endif?></section>
<section class="card"><h2>Centros de costo <small class="muted"><?=count($centers)?></small></h2>
<?php if($centers):?><div class="table-wrap"><table><thead><tr><th>Centro</th><th class="hide-sm">Uso</th><th>Estado</th><th class="right">Acciones</th></tr></thead><tbody>
<?php foreach($centers as $cid=>$c):?><tr><td><b><?=e(ccCenterLabel($c))?></b><?php if($c['descripcion']!==''):?><small><?=e($c['descripcion'])?></small><?php endif?></td><td class="hide-sm"><?=($used[$cid]??0)?(int)$used[$cid].' movimientos':'Sin uso'?></td><td><span class="status <?=$c['activo']?'on':'off'?>"><?=$c['activo']?'Activo':'Inactivo'?></span></td>
<td class="right nowrap"><details class="pop cc-pop-inline"><summary class="ghost small btn">Editar</summary><form method="post" class="pop-body"><?=csrf()?><input type="hidden" name="id" value="<?=$cid?>"><strong>Editar centro de costo</strong><label>Código<input name="codigo" maxlength="20" value="<?=e($c['codigo'])?>"></label><label>Nombre<input name="nombre" maxlength="80" required value="<?=e($c['nombre'])?>"></label><label>Descripción<input name="descripcion" maxlength="200" value="<?=e($c['descripcion'])?>"></label><button class="primary" name="action" value="cc_center_save">Guardar</button></form></details>
<form method="post" class="cc-inline"><?=csrf()?><input type="hidden" name="id" value="<?=$cid?>"><button class="ghost small" name="action" value="cc_center_toggle"><?=$c['activo']?'Desactivar':'Activar'?></button><?php if(!isset($used[$cid])):?><button class="ghost small" name="action" value="cc_center_delete" title="Eliminar">Eliminar</button><?php endif?></form></td></tr><?php endforeach?>
</tbody></table></div><p class="hint">Un centro inactivo deja de ofrecerse en los formularios, pero conserva sus movimientos y sigue en los informes. Solo se pueden eliminar centros sin uso.</p>
<?php else:?><p class="hint">Aún no hay centros de costo. Crea el primero arriba o a partir de los proyectos.</p><?php endif?></section>
<form method="post" class="oc-form"><?=csrf()?><section class="card"><h2>Categorías</h2><div class="oc-grid">
  <label>Categorías de gasto <small>(una por línea)</small><textarea name="cat_gasto" rows="12"><?=e(implode("\n",$ccCfg['cat_gasto']))?></textarea></label>
  <label>Categorías de ingreso <small>(una por línea)</small><textarea name="cat_ingreso" rows="12"><?=e(implode("\n",$ccCfg['cat_ingreso']))?></textarea></label>
</div><p class="hint">Cambiar o quitar una categoría no modifica los movimientos ya registrados.</p></section>
<section class="card"><h2>Retención de honorarios</h2><div class="oc-grid">
  <label>Retención de boletas de honorarios (%)<input type="number" name="ret_honorarios" min="0" max="49.99" step="0.01" value="<?=e((string)(float)$ccCfg['ret_honorarios'])?>"></label>
</div><p class="hint">Se usa en los presupuestos cuando el honorario de un relator se ingresa en líquido: el monto presupuestado pasa a ser el bruto (líquido + retención). En 2026 es 15,25 %; súbelo cada enero según el calendario de la Ley 21.133 (16 % en 2027 y 17 % desde 2028). Los presupuestos ya guardados conservan la tasa con que se calcularon.</p></section>
<div class="oc-actions"><button class="primary" name="action" value="cc_cfg">Guardar configuración</button></div></form>
<?php endif?>
