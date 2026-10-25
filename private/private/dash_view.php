<?php
// Pantalla del dashboard ($mode === 'dash'). Interacción sin JavaScript: enlaces con filtros, detalle al pasar el cursor
// (o tocar, en el celular) y cambio de vista Mensual/Acumulado con botones de radio y CSS.
$f=dashFilters(); $d=dashData($f); $centers=ccCenters();
$tot=$d['tot']; $res=$tot['ingreso']-$tot['gasto']; $cat=$f['cat'];
$durl=function(array $over=[]) use($f): string {
    $q=array_merge(['tab'=>'dash','desde'=>$f['desde'],'hasta'=>$f['hasta'],'centro'=>$f['centro'],'cat'=>$f['cat']],$over);
    if(!$q['centro']) unset($q['centro']); if($q['cat']==='') unset($q['cat']);
    return url($q); // desde y hasta van siempre, aunque estén vacíos («Todo»), para no volver al período por defecto
};
$movsUrl=fn(array $q=[]): string => url(array_filter(['tab'=>'cc_movs','desde'=>$f['desde'],'hasta'=>$f['hasta'],'centro'=>$f['centro']]+$q,fn($v)=>$v!==''&&$v!==0));
$periodLink=function(string $k) use($durl): string { [$a,$b]=dashPeriodRange($k); return $durl(['desde'=>$a,'hasta'=>$b]); };
$centerName=fn(int $cid): string => $cid===-1 ? 'Sin centro de costo' : ccCenterLabel($centers[$cid]??null);
$pct=fn(int $a, int $b): string => $b>0 ? ($a>0 && 100*$a/$b<1 ? '< 1%' : (string)round(100*$a/$b).'%') : '—';
$deltaTxt=function(?int $dl, bool $costUp=false): string {
    if($dl===null) return '';
    $good=$costUp ? $dl<=0 : $dl>=0; $arrow=$dl>0?'▲':($dl<0?'▼':'＝');
    return '<em class="dash-delta '.($dl===0?'flat':($good?'good':'bad')).'">'.$arrow.' '.abs($dl).'%</em>';
};
$prevLabel=$d['prevRange'] ? 'vs. '.ocDate($d['prevRange'][0]).' – '.ocDate($d['prevRange'][1]) : '';
$rangeLabel=$f['desde']===''&&$f['hasta']==='' ? 'Todo el historial' : ($f['desde']!==''?ocDate($f['desde']):'inicio').' – '.($f['hasta']!==''?ocDate($f['hasta']):'hoy');

// Colores fijos por categoría para la composición; las que no están entre las 5 principales van a «Otras».
$colors=[]; foreach($d['top'] as $i=>$c) $colors[$c]=DASH_PAL[$i]; if(count($d['catOut'])>count($d['top'])) $colors['Otras']=DASH_OTHER;
$swatch=fn(string $c): string => '<svg class="dash-sw" viewBox="0 0 10 10" aria-hidden="true"><rect width="10" height="10" rx="2" fill="'.$c.'"/></svg>';

// Hallazgos automáticos: dónde se concentra el gasto.
$ins=[];
if($tot['gasto']>0){
    $c1=array_key_first($d['catOut']); $v1=$d['catOut'][$c1]['v'];
    $ins[]=['cat','<b>'.e($c1).'</b> es donde más se gasta: '.clp($v1).', el '.$pct($v1,$tot['gasto']).' de los costos'.($tot['ingreso']>0?' (equivale al '.$pct($v1,$tot['ingreso']).' de los ingresos)':'').'.'];
    $top3=array_sum(array_map(fn($x)=>$x['v'],array_slice($d['catOut'],0,3)));
    if(count($d['catOut'])>3) $ins[]=['list','Las 3 categorías principales suman el '.$pct($top3,$tot['gasto']).' de los costos.'];
    $up=null; foreach($d['catOut'] as $c=>$x){ $b=$d['catPrev'][$c]??0; $diff=$x['v']-$b; if($b>0 && $diff>0 && ($up===null||$diff>$up[1])) $up=[$c,$diff,$b]; }
    if($up) $ins[]=['up','<b>'.e($up[0]).'</b> es la categoría que más creció frente al período anterior: +'.clp($up[1]).' ('.$deltaTxt(dashDelta($up[1]+$up[2],$up[2]),true).').'];
    if($f['centro']===0 && count($d['centers'])>1){ $cid=array_key_first($d['centers']); $cv=$d['centers'][$cid]; $in=$cv['ingreso']??0;
        $ins[]=['center','El centro que más gasta es <b>'.e($centerName($cid)).'</b>: '.clp($cv['gasto']??0).' ('.$pct($cv['gasto']??0,$tot['gasto']).' del total)'.($in>0?', con un margen de '.round(100*($in-($cv['gasto']??0))/$in).'%':', sin ingresos asignados').'.']; }
    $p1=$d['prov'][0]??null; if($p1 && $p1['name']!=='') $ins[]=['cart','El proveedor con más pagos es <b>'.e($p1['name']).'</b>: '.clp((int)$p1['v']).' en '.(int)$p1['n'].' movimiento'.((int)$p1['n']===1?'':'s').'.'];
    $worst=null; foreach($d['series'] as $k=>$v) if($v['gasto']>0 && ($worst===null||$v['gasto']>$d['series'][$worst]['gasto'])) $worst=$k;
    if($worst && count($d['series'])>1) $ins[]=['clock','El período con más costos fue <b>'.e(dashPeriodLabel($worst)).'</b>: '.clp($d['series'][$worst]['gasto']).'.'];
    $neg=array_filter($d['series'],fn($v)=>$v['gasto']>$v['ingreso']);
    if($neg && count($d['series'])>1) $ins[]=['alert',count($neg).' de '.count($d['series']).' períodos cerraron con costos mayores que los ingresos.'];
}
$ic2=['cat'=>'chart','list'=>'list','up'=>'activity','center'=>'project','cart'=>'cart','clock'=>'clock','alert'=>'bell'];
?>
<div class="page-head"><div><span class="eyebrow muted">NEGOCIO</span><h1>Dashboard</h1><p class="subtitle">Ingresos frente a costos y en qué se va el dinero. Toca un mes, una categoría o un centro para filtrar todo el panel.</p></div>
<div class="head-actions"><a class="btn ghost" href="<?=e(url(array_filter(['tab'=>'cc','desde'=>$f['desde'],'hasta'=>$f['hasta'],'centro'=>$f['centro']],fn($v)=>$v!==0)))?>"><?=ic('list')?>Resumen contable</a><?php if($canEdit):?><a class="btn ghost" href="<?=e(url(['tab'=>'cc_new','tipo'=>'gasto']))?>"><?=ic('plus')?>Gasto</a><a class="btn primary" href="<?=e(url(['tab'=>'cc_new','tipo'=>'ingreso']))?>"><?=ic('plus')?>Ingreso</a><?php endif?></div></div>

<form method="get" class="oc-filter dash-filter"><input type="hidden" name="tab" value="dash"><?php if($cat!==''):?><input type="hidden" name="cat" value="<?=e($cat)?>"><?php endif?>
  <label><span class="sr">Desde</span><input type="date" name="desde" value="<?=e($f['desde'])?>" title="Desde"></label><label><span class="sr">Hasta</span><input type="date" name="hasta" value="<?=e($f['hasta'])?>" title="Hasta"></label>
  <label><span class="sr">Centro de costo</span><select name="centro"><option value="0">Todos los centros</option><?php foreach($centers as $cid=>$c):?><option value="<?=$cid?>"<?=$f['centro']===$cid?' selected':''?>><?=e(ccCenterLabel($c))?></option><?php endforeach?><option value="-1"<?=$f['centro']===-1?' selected':''?>>Sin centro de costo</option></select></label>
  <button class="ghost"><?=ic('search')?>Ver</button></form>
<div class="chips"><?php foreach(dashPeriods() as $lbl=>[$a,$b]):?><a class="chip<?=$f['desde']===$a&&$f['hasta']===$b?' on':''?>" href="<?=e($durl(['desde'=>$a,'hasta'=>$b]))?>"><?=e($lbl)?></a><?php endforeach?></div>
<?php if($f['centro']||$cat!==''):?><div class="dash-active" role="status"><span>Filtrando por:</span>
  <?php if($f['centro']):?><a class="dash-tag" href="<?=e($durl(['centro'=>0]))?>" title="Quitar filtro"><?=ic('project')?><?=e($centerName($f['centro']))?> <b aria-hidden="true">×</b><span class="sr">Quitar</span></a><?php endif?>
  <?php if($cat!==''):?><a class="dash-tag" href="<?=e($durl(['cat'=>'']))?>" title="Quitar filtro"><?=ic('chart')?><?=e($cat)?> <b aria-hidden="true">×</b><span class="sr">Quitar</span></a><?php endif?>
  <?php if($f['centro']):?><small>De los movimientos repartidos entre centros se cuenta solo la parte de este centro.</small><?php endif?></div><?php endif?>

<div class="stat-grid dash-kpis">
  <div class="stat"><?=ic('download')?><strong class="cc-in"><?=clp($tot['ingreso'])?></strong><span>Ingresos <?=$d['prev']?$deltaTxt(dashDelta($tot['ingreso'],$d['prev']['ingreso'])):''?></span></div>
  <div class="stat"><?=ic('upload')?><strong class="cc-out"><?=clp($tot['gasto'])?></strong><span>Costos <?=$d['prev']?$deltaTxt(dashDelta($tot['gasto'],$d['prev']['gasto']),true):''?></span></div>
  <div class="stat"><?=ic('activity')?><strong class="<?=$res<0?'cc-out':''?>"><?=ccClp($res)?></strong><span>Resultado<?=$tot['ingreso']>0?' · margen '.round(100*$res/$tot['ingreso']).'%':''?></span></div>
  <div class="stat"><?=ic('chart')?><strong><?=$tot['ingreso']>0?clp(round(100*$tot['gasto']/$tot['ingreso'])):'—'?></strong><span>de costo por cada $ 100 de ingreso</span></div>
</div>
<p class="hint dash-range"><?=e($rangeLabel)?><?=$prevLabel?' · las flechas comparan '.e($prevLabel):''?>. Gastos de rendiciones: solo aprobadas. Montos con IVA incluido.</p>

<?php if($tot['ingreso']===0 && $tot['gasto']===0):?>
<div class="empty-state dash-empty"><?=ic('chart')?><b>Sin ingresos ni gastos en este período</b><span><?=$canEdit?'Registra movimientos en Contabilidad o elige otro período.':'Elige otro período o espera a que se registren movimientos.'?></span><a class="btn ghost" href="<?=e($durl(['desde'=>'','hasta'=>'']))?>">Ver todo el historial</a></div>
<?php else:?>

<?php if($ins):?><section class="card dash-insights"><h2><?=ic('search')?>¿Dónde se va el dinero?</h2><ul><?php foreach($ins as [$k,$t]):?><li><?=ic($ic2[$k])?><span><?=$t?></span></li><?php endforeach?></ul></section><?php endif?>

<?php psDashCard($f)?>

<section class="card dash-card"><input type="radio" name="dash-v" id="dash-v1" class="dash-radio" checked><input type="radio" name="dash-v" id="dash-v2" class="dash-radio">
  <div class="dash-card-head"><div><h2>Ingresos vs. costos</h2><p class="hint">Barras: ingresos y costos de cada <?=$d['byYear']?'año':'mes'?>. Línea: resultado (ingresos − costos). Pasa el cursor para ver el detalle; haz clic para filtrar.</p></div>
    <div class="dash-seg" role="group" aria-label="Vista"><label for="dash-v1">Por <?=$d['byYear']?'año':'mes'?></label><label for="dash-v2">Acumulado</label></div></div>
  <div class="dash-legend"><span><?=$swatch(DASH_IN)?>Ingresos</span><span><?=$swatch(DASH_OUT)?><?=$cat!==''?'Costos de «'.e($cat).'»':'Costos'?></span><?php if($cat!==''):?><span><?=$swatch(DASH_OUT_SOFT)?>Resto de los costos</span><?php endif?><span class="v1"><svg class="dash-sw" viewBox="0 0 16 10" aria-hidden="true"><line x1="0" x2="16" y1="5" y2="5" stroke="<?=DASH_LINE?>" stroke-width="2"/><circle cx="8" cy="5" r="3" fill="<?=DASH_LINE?>"/></svg>Resultado</span></div>
  <div class="dash-view v1"><div class="dash-scroll"><?=dashChartFlow($d,$periodLink)?></div></div>
  <div class="dash-view v2"><div class="dash-scroll"><?=dashChartCum($d)?></div><p class="hint">Zona verde: los ingresos acumulados cubren los costos. Zona roja: los costos van por delante.</p></div>
  <details class="dash-table"><summary>Ver como tabla</summary><div class="table-wrap"><table class="cc-table"><thead><tr><th><?=$d['byYear']?'Año':'Mes'?></th><th class="right">Ingresos</th><th class="right">Costos</th><?php if($cat!==''):?><th class="right"><?=e($cat)?></th><?php endif?><th class="right">Resultado</th><th class="right hide-sm">Margen</th></tr></thead><tbody>
  <?php foreach($d['series'] as $k=>$v): $r=$v['ingreso']-$v['gasto'];?><tr><td><a href="<?=e($periodLink($k))?>"><?=e(dashPeriodLabel($k))?></a></td><td class="right nowrap cc-in"><?=clp($v['ingreso'])?></td><td class="right nowrap cc-out"><?=clp($v['gasto'])?></td><?php if($cat!==''):?><td class="right nowrap"><?=clp($v['cat'])?></td><?php endif?><td class="right nowrap"><b class="<?=$r<0?'cc-out':''?>"><?=ccClp($r)?></b></td><td class="right hide-sm"><?=$v['ingreso']>0?round(100*$r/$v['ingreso']).'%':'—'?></td></tr><?php endforeach?>
  </tbody></table></div></details>
</section>

<div class="dash-two">
<section class="card dash-card"><div class="dash-card-head"><div><h2>¿En qué se gasta?</h2><p class="hint">Costos por categoría. Haz clic en una para ver solo esa categoría en todo el panel.</p></div></div>
<?php if($d['catOut']): $mx=max(array_map(fn($x)=>$x['v'],$d['catOut']));?><div class="dash-rank">
<?php foreach($d['catOut'] as $c=>$x): $on=$cat===$c; $b=$d['catPrev'][$c]??null;?><a class="dash-row<?=$on?' on':''?><?=$cat!==''&&!$on?' dim':''?>" href="<?=e($durl(['cat'=>$on?'':$c]))?>" title="<?=$on?'Quitar el filtro':'Ver solo '.e($c)?>">
  <span class="dash-name"><?=isset($colors[$c])?$swatch($colors[$c]):(isset($colors['Otras'])?$swatch(DASH_OTHER):'')?><?=e($c)?><small><?=$x['n']?> mov.</small></span>
  <span class="dash-val"><?=clp($x['v'])?><small><?=$pct($x['v'],$tot['gasto'])?><?=$tot['ingreso']>0?' de costos · '.$pct($x['v'],$tot['ingreso']).' de ingresos':''?><?=$d['prev']&&$b?' '.$deltaTxt(dashDelta($x['v'],$b),true):''?></small></span>
  <?=dashHBar($x['v']/$mx,DASH_OUT)?></a>
<?php endforeach?></div><?php else:?><p class="hint">Sin costos en este período.</p><?php endif?></section>

<section class="card dash-card"><div class="dash-card-head"><div><h2>Por centro de costo</h2><p class="hint"><?=$cat!==''?'Ingresos y costos de «'.e($cat).'» por centro.':'Ingresos y costos de cada centro.'?> Haz clic para filtrar por centro.</p></div></div>
<?php if($d['centers']): $mx=1; foreach($d['centers'] as $v) $mx=max($mx,$v['ingreso']??0,$cat!==''?($v['cat']??0):($v['gasto']??0));?><div class="dash-rank">
<?php foreach($d['centers'] as $cid=>$v): $in=$v['ingreso']??0; $out=$cat!==''?($v['cat']??0):($v['gasto']??0); if($cat!==''&&$out===0) continue; $on=$f['centro']===$cid;?><a class="dash-row<?=$on?' on':''?><?=$f['centro']&&!$on?' dim':''?>" href="<?=e($durl(['centro'=>$on?0:$cid]))?>" title="<?=$on?'Quitar el filtro':'Ver solo este centro'?>">
  <span class="dash-name"><?=e($centerName($cid))?><?php if($cat===''):?><small><?=$in>0?'margen '.round(100*($in-$out)/$in).'%':'sin ingresos'?></small><?php endif?></span>
  <span class="dash-val"><span class="cc-in"><?=clp($in)?></span> · <span class="cc-out"><?=clp($out)?></span><?php if($cat===''):?><small>resultado <?=ccClp($in-$out)?></small><?php endif?></span>
  <span class="dash-pair"><?=dashHBar($in/$mx,DASH_IN)?><?=dashHBar($out/$mx,DASH_OUT)?></span></a>
<?php endforeach?></div><div class="dash-legend foot"><span><?=$swatch(DASH_IN)?>Ingresos</span><span><?=$swatch(DASH_OUT)?>Costos</span></div><?php else:?><p class="hint">Sin movimientos con centro de costo en este período.</p><?php endif?></section>
</div>

<?php if($tot['gasto']>0):?><section class="card dash-card"><div class="dash-card-head"><div><h2>Composición de los costos</h2><p class="hint">Cada barra es el costo total del <?=$d['byYear']?'año':'mes'?>, dividido por categoría (las 5 principales del período y el resto como «Otras»).</p></div></div>
  <div class="dash-legend"><?php foreach(array_reverse($colors,true) as $c=>$col):?><?php if($c==='Otras'):?><span><?=$swatch($col)?>Otras</span><?php else:?><a href="<?=e($durl(['cat'=>$cat===$c?'':$c]))?>"<?=$cat===$c?' class="on"':''?>><?=$swatch($col)?><?=e($c)?></a><?php endif?><?php endforeach?></div>
  <div class="dash-scroll"><?=dashChartMix($d,$colors,$periodLink)?></div></section><?php endif?>

<div class="dash-two">
<section class="card dash-card"><div class="dash-card-head"><div><h2>Principales proveedores</h2><p class="hint">A quién se le paga más<?=$cat!==''?' en «'.e($cat).'»':''?>, según el proveedor indicado en cada gasto.</p></div></div>
<?php if($d['prov']): $mx=max(1,(int)$d['prov'][0]['v']);?><div class="dash-rank">
<?php foreach($d['prov'] as $pv): $nm=$pv['name']!==''?$pv['name']:'';?><a class="dash-row" href="<?=e($movsUrl(['tipo'=>'gasto','q'=>$nm,'cat'=>$cat]))?>" title="Ver sus movimientos">
  <span class="dash-name"><?=$nm!==''?e($nm):'<i>Sin proveedor indicado</i>'?><small><?=(int)$pv['n']?> mov.</small></span>
  <span class="dash-val"><?=clp((int)$pv['v'])?><small><?=$pct((int)$pv['v'],$d['outCat'])?></small></span><?=dashHBar((int)$pv['v']/$mx,DASH_OUT)?></a>
<?php endforeach?></div><?php else:?><p class="hint">Sin gastos en este período.</p><?php endif?></section>

<section class="card dash-card"><div class="dash-card-head"><div><h2>Mayores gastos</h2><p class="hint">Los movimientos individuales más altos<?=$cat!==''?' de «'.e($cat).'»':''?>.</p></div><a class="btn ghost small" href="<?=e($movsUrl(['tipo'=>'gasto','cat'=>$cat]))?>">Ver todos</a></div>
<?php if($d['big']):?><ul class="dash-big"><?php foreach($d['big'] as $m):?><li><a href="<?=e(url(['mov'=>(int)$m['id']]))?>"><span><b><?=e($m['descripcion'])?></b><small><?=e(ocDate($m['fecha']))?><?=$m['contraparte']!==''?' · '.e($m['contraparte']):''?> · <?=e($m['categoria']?:'Sin categoría')?></small></span><strong class="cc-out"><?=clp((int)$m['v'])?></strong></a></li><?php endforeach?></ul><?php else:?><p class="hint">Sin gastos en este período.</p><?php endif?></section>
</div>
<?php endif?>

<div class="dash-two">
<section class="card dash-card"><div class="dash-card-head"><div><h2>¿De dónde vienen los ingresos?</h2><p class="hint">Ingresos por categoría.</p></div><a class="btn ghost small" href="<?=e($movsUrl(['tipo'=>'ingreso']))?>">Ver todos</a></div>
<?php if($d['catIn']): $mx=max(array_map(fn($x)=>$x['v'],$d['catIn']));?><div class="dash-rank"><?php foreach($d['catIn'] as $c=>$x):?><a class="dash-row" href="<?=e($movsUrl(['tipo'=>'ingreso','cat'=>$c==='Sin categoría'?'':$c]))?>">
  <span class="dash-name"><?=e($c)?><small><?=$x['n']?> mov.</small></span><span class="dash-val"><?=clp($x['v'])?><small><?=$pct($x['v'],$tot['ingreso'])?></small></span><?=dashHBar($x['v']/$mx,DASH_IN)?></a><?php endforeach?></div>
<?php else:?><p class="hint">Sin ingresos en este período.</p><?php endif?></section>

<?php $cm=$d['com'];?><section class="card dash-card"><div class="dash-card-head"><div><h2>Comercial y compras</h2><p class="hint">Cotizaciones y órdenes de compra con fecha en el período<?=$f['centro']?' y con este centro de costo escrito en el documento':''?>. Son compromisos: no se suman a los ingresos y costos de arriba.</p></div></div>
<div class="dash-com">
  <a href="<?=e(url(['tab'=>'cot','estado'=>'Aceptada']))?>"><small>Cotizaciones aceptadas</small><strong class="cc-in"><?=clp($cm['ganado'])?></strong><span><?=$cm['ganadoN']?> <?=$cm['ganadoN']===1?'cotización':'cotizaciones'?><?=$cm['tasa']!==null?' · '.$cm['tasa'].'% de aceptación':''?></span></a>
  <a href="<?=e(url(['tab'=>'cot','estado'=>'Enviada']))?>"><small>En negociación (enviadas vigentes)</small><strong><?=clp($cm['abierto'])?></strong><span><?=$cm['abiertoN']?> <?=$cm['abiertoN']===1?'cotización':'cotizaciones'?></span></a>
  <a href="<?=e(url(['tab'=>'oc']))?>"><small>Compras con OC (sin anuladas ni borradores)</small><strong class="cc-out"><?=clp($cm['ocTotal'])?></strong><span><?=$cm['ocN']?> <?=$cm['ocN']===1?'orden':'órdenes'?></span></a>
  <a href="<?=e(url(['tab'=>'oc']))?>"><small>OC por pagar</small><strong><?=clp($cm['porPagar'])?></strong><span><?=$cm['porPagarN']?> emitida<?=$cm['porPagarN']===1?'':'s'?>, aceptada<?=$cm['porPagarN']===1?'':'s'?> o recibida<?=$cm['porPagarN']===1?'':'s'?></span></a>
</div>
<?php $mx=max(1,$cm['ganado'],$cm['ocTotal']); if($cm['ganado']||$cm['ocTotal']):?><div class="dash-rank compact">
  <div class="dash-row static"><span class="dash-name">Vendido (aceptado)</span><span class="dash-val"><?=clp($cm['ganado'])?></span><?=dashHBar($cm['ganado']/$mx,DASH_IN)?></div>
  <div class="dash-row static"><span class="dash-name">Comprado (OC)</span><span class="dash-val"><?=clp($cm['ocTotal'])?><small><?=$cm['ganado']>0?$pct($cm['ocTotal'],$cm['ganado']).' de lo vendido':''?></small></span><?=dashHBar($cm['ocTotal']/$mx,DASH_OUT)?></div>
</div><?php endif?></section>
</div>
