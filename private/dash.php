<?php
// Dashboard del negocio: ingresos frente a costos, en qué se gasta y compromisos comerciales.
// Solo lectura: no crea tablas ni acciones POST. Se incluye desde app.php después de contable.php
// (usa CC_COUNTED, ccCenters, ocDateIn, ocText, ocToday y clp). Los gráficos son SVG generados aquí, sin JavaScript.

/** Paleta categórica (orden fijo, validado para daltonismo en pares contiguos) y colores de ingresos y costos. */
const DASH_PAL=['#2a78d6','#eb6834','#1baf7a','#eda100','#e87ba4'];
const DASH_OTHER='#9aa7b4';
const DASH_IN='#2e9b68';
const DASH_OUT='#d65a4e';
const DASH_OUT_SOFT='#f1c3bd';
const DASH_LINE='#0f2a45';
const DASH_MONTHS=['01'=>'Enero','02'=>'Febrero','03'=>'Marzo','04'=>'Abril','05'=>'Mayo','06'=>'Junio','07'=>'Julio','08'=>'Agosto','09'=>'Septiembre','10'=>'Octubre','11'=>'Noviembre','12'=>'Diciembre'];

/** Filtros del dashboard desde la URL. Sin fechas: últimos 12 meses (incluido el actual). */
function dashFilters(): array {
    $s=fn(string $k): string => is_string($_GET[$k]??null) ? trim($_GET[$k]) : '';
    $c=(int)$s('centro');
    $f=['desde'=>ocDateIn($s('desde')),'hasta'=>ocDateIn($s('hasta')),'centro'=>$c===-1?-1:max(0,$c),'cat'=>ocText($s('cat'),60)];
    if(!isset($_GET['desde']) && !isset($_GET['hasta'])) [$f['desde'],$f['hasta']]=dashPeriods()['Últimos 12 meses'];
    if($f['desde']!=='' && $f['hasta']!=='' && $f['desde']>$f['hasta']) [$f['desde'],$f['hasta']]=[$f['hasta'],$f['desde']];
    if($f['centro']>0 && !isset(ccCenters()[$f['centro']])) $f['centro']=0;
    return $f;
}
/** Atajos de período: etiqueta => [desde, hasta]. */
function dashPeriods(): array {
    $t=new DateTimeImmutable(ocToday()); $m1=$t->modify('first day of this month'); $end=$t->modify('last day of this month')->format('Y-m-d'); $y=(int)$t->format('Y');
    $q=intdiv((int)$t->format('n')-1,3)*3+1; $q1=$t->setDate($y,$q,1);
    return [
        'Este mes'=>[$m1->format('Y-m-d'),$end],
        'Este trimestre'=>[$q1->format('Y-m-d'),$q1->modify('+2 months')->modify('last day of this month')->format('Y-m-d')],
        'Últimos 3 meses'=>[$m1->modify('-2 months')->format('Y-m-d'),$end],
        'Últimos 12 meses'=>[$m1->modify('-11 months')->format('Y-m-d'),$end],
        'Este año'=>[$y.'-01-01',$y.'-12-31'],
        'Año anterior'=>[($y-1).'-01-01',($y-1).'-12-31'],
        'Todo'=>['',''],
    ];
}
/** Condiciones SQL comunes (alias s = cc_splits, m = cc_movs, r = cc_rendiciones). */
function dashWhere(array $f, string $desde, string $hasta, bool $withCat=false): array {
    $w=[CC_COUNTED]; $p=[];
    if($desde!==''){ $w[]='m.fecha>=?'; $p[]=$desde; }
    if($hasta!==''){ $w[]='m.fecha<=?'; $p[]=$hasta; }
    if($f['centro']>0){ $w[]='s.center_id=?'; $p[]=$f['centro']; } elseif($f['centro']===-1) $w[]='s.center_id IS NULL';
    if($withCat && $f['cat']!==''){ $w[]="m.tipo='gasto' AND m.categoria=?"; $p[]=$f['cat']==='Sin categoría'?'':$f['cat']; }
    return [' FROM cc_splits s JOIN cc_movs m ON m.id=s.mov_id LEFT JOIN cc_rendiciones r ON r.id=m.rendicion_id WHERE '.implode(' AND ',$w),$p];
}
function dashTotals(array $f, string $desde, string $hasta): array {
    [$from,$p]=dashWhere($f,$desde,$hasta); $t=['ingreso'=>0,'gasto'=>0];
    foreach(query('SELECT m.tipo, SUM(s.monto)'.$from.' GROUP BY m.tipo',$p)->fetchAll(PDO::FETCH_NUM) as [$tp,$v]) $t[$tp]=(int)$v;
    return $t;
}
/** Período anterior de igual duración (para comparar). Null si el período no tiene inicio y fin. */
function dashPrevRange(array $f): ?array {
    if($f['desde']==='' || $f['hasta']==='') return null;
    $d=new DateTimeImmutable($f['desde']); $h=new DateTimeImmutable($f['hasta']);
    // Meses completos se comparan con los meses completos anteriores; otros rangos, con los mismos días previos.
    if($d->format('d')==='01' && $h->format('Y-m-d')===$h->modify('last day of this month')->format('Y-m-d')){
        $n=((int)$h->format('Y')-(int)$d->format('Y'))*12+(int)$h->format('n')-(int)$d->format('n')+1;
        return [$d->modify("-$n months")->format('Y-m-d'),$d->modify('-1 day')->format('Y-m-d')];
    }
    $days=(int)$d->diff($h)->days+1;
    return [$d->modify("-$days days")->format('Y-m-d'),$d->modify('-1 day')->format('Y-m-d')];
}
/** Variación porcentual; null si no hay base. */
function dashDelta(int $now, int $before): ?int { return $before>0 ? (int)round(100*($now-$before)/$before) : null; }

/** Todos los datos del dashboard para los filtros dados. */
function dashData(array $f): array {
    [$from,$p]=dashWhere($f,$f['desde'],$f['hasta']);
    [$fromC,$pC]=dashWhere($f,$f['desde'],$f['hasta'],true);
    $d=['f'=>$f,'tot'=>dashTotals($f,$f['desde'],$f['hasta'])];
    $prev=dashPrevRange($f); $d['prevRange']=$prev; $d['prev']=$prev?dashTotals($f,$prev[0],$prev[1]):null;

    // Agrupación temporal: por mes; si el rango supera 36 meses, por año.
    $rng=query('SELECT MIN(m.fecha), MAX(m.fecha)'.$from,$p)->fetch(PDO::FETCH_NUM);
    $a=$f['desde']!==''?$f['desde']:($rng[0]??ocToday()); $b=$f['hasta']!==''?$f['hasta']:($rng[1]??ocToday());
    if($f['hasta']==='' && $b<ocToday()) $b=ocToday();
    // Los meses que aún no llegan («Este año», «Este trimestre») no se dibujan.
    if($b>ocToday() && $a<=ocToday()) $b=ocToday();
    $nm=((int)substr($b,0,4)-(int)substr($a,0,4))*12+(int)substr($b,5,2)-(int)substr($a,5,2)+1;
    $len=$nm>36?4:7; $d['byYear']=$len===4;
    $keys=[]; $cur=new DateTimeImmutable(substr($a,0,7).'-01');
    while($cur->format('Y-m')<=substr($b,0,7)){ $keys[substr($cur->format('Y-m'),0,$len)]=true; $cur=$cur->modify('+1 month'); }
    $series=[]; foreach(array_keys($keys) as $k) $series[$k]=['ingreso'=>0,'gasto'=>0,'cat'=>0,'mix'=>[]];
    foreach(query("SELECT substr(m.fecha,1,$len), m.tipo, SUM(s.monto)".$from.' GROUP BY 1, 2',$p)->fetchAll(PDO::FETCH_NUM) as [$k,$tp,$v]) if(isset($series[$k])) $series[$k][$tp]=(int)$v;
    if($f['cat']!=='') foreach(query("SELECT substr(m.fecha,1,$len), SUM(s.monto)".$fromC.' GROUP BY 1',$pC)->fetchAll(PDO::FETCH_NUM) as [$k,$v]) if(isset($series[$k])) $series[$k]['cat']=(int)$v;

    // Costos e ingresos por categoría (el ranking siempre muestra todas para poder cambiar de categoría).
    $d['catOut']=[]; $d['catIn']=[];
    foreach(query('SELECT m.tipo, m.categoria, SUM(s.monto) AS v, COUNT(DISTINCT m.id) AS n'.$from.' GROUP BY 1, 2 ORDER BY 3 DESC',$p)->fetchAll(PDO::FETCH_ASSOC) as $r)
        $d[$r['tipo']==='gasto'?'catOut':'catIn'][$r['categoria']!==''?$r['categoria']:'Sin categoría']=['v'=>(int)$r['v'],'n'=>(int)$r['n']];
    $d['catPrev']=[];
    if($prev){ [$fp,$pp]=dashWhere($f,$prev[0],$prev[1]); foreach(query("SELECT m.categoria, SUM(s.monto)".$fp." AND m.tipo='gasto' GROUP BY 1",$pp)->fetchAll(PDO::FETCH_NUM) as [$c,$v]) $d['catPrev'][$c!==''?$c:'Sin categoría']=(int)$v; }

    // Composición de costos por período: 5 categorías principales (color fijo por categoría) + «Otras».
    $d['top']=array_slice(array_keys($d['catOut']),0,count(DASH_PAL));
    foreach(query("SELECT substr(m.fecha,1,$len), m.categoria, SUM(s.monto)".$from." AND m.tipo='gasto' GROUP BY 1, 2",$p)->fetchAll(PDO::FETCH_NUM) as [$k,$c,$v]){
        if(!isset($series[$k])) continue; $c=$c!==''?$c:'Sin categoría'; $c=in_array($c,$d['top'],true)?$c:'Otras';
        $series[$k]['mix'][$c]=($series[$k]['mix'][$c]??0)+(int)$v;
    }
    $d['series']=$series;

    // Por centro de costo (sin filtrar por centro, para comparar; con la categoría elegida si la hay).
    $fAll=['centro'=>0]+$f; [$fc,$pc]=dashWhere($fAll,$f['desde'],$f['hasta']); [$fcc,$pcc]=dashWhere($fAll,$f['desde'],$f['hasta'],true);
    $cen=[]; foreach(query('SELECT s.center_id, m.tipo, SUM(s.monto)'.$fc.' GROUP BY 1, 2',$pc)->fetchAll(PDO::FETCH_NUM) as [$cid,$tp,$v]) $cen[$cid===null?-1:(int)$cid][$tp]=(int)$v;
    if($f['cat']!=='') foreach(query('SELECT s.center_id, SUM(s.monto)'.$fcc.' GROUP BY 1',$pcc)->fetchAll(PDO::FETCH_NUM) as [$cid,$v]) $cen[$cid===null?-1:(int)$cid]['cat']=(int)$v;
    uasort($cen,fn($x,$y)=>($y['gasto']??0)<=>($x['gasto']??0));
    $d['centers']=$cen;

    // Proveedores y mayores gastos (respetan la categoría elegida).
    [$fg,$pg]=$f['cat']!=='' ? [$fromC,$pC] : [$from." AND m.tipo='gasto'",$p];
    $d['prov']=query("SELECT CASE WHEN trim(m.contraparte)='' THEN '' ELSE m.contraparte END AS name, SUM(s.monto) AS v, COUNT(DISTINCT m.id) AS n".$fg.' GROUP BY lower(trim(m.contraparte)) ORDER BY 2 DESC LIMIT 8',$pg)->fetchAll(PDO::FETCH_ASSOC);
    $d['outCat']=(int)query('SELECT COALESCE(SUM(s.monto),0)'.$fg,$pg)->fetchColumn();
    $d['big']=query('SELECT m.id, m.fecha, m.descripcion, m.contraparte, m.categoria, SUM(s.monto) AS v'.$fg.' GROUP BY m.id ORDER BY 6 DESC, m.fecha DESC LIMIT 8',$pg)->fetchAll(PDO::FETCH_ASSOC);

    $d['com']=dashCommercial($f);
    return $d;
}

/** Cotizaciones y órdenes de compra del período (por fecha del documento; montos con IVA). */
function dashCommercial(array $f): array {
    $w=['1=1']; $p=[];
    if($f['desde']!==''){ $w[]="fecha>=?"; $p[]=$f['desde']; }
    if($f['hasta']!==''){ $w[]="fecha<=?"; $p[]=$f['hasta']; }
    if($f['centro']>0){
        $c=ccCenters()[$f['centro']];
        $names=array_values(array_unique(array_filter([mb_strtolower(trim($c['nombre'])),mb_strtolower(trim($c['codigo'])),mb_strtolower(ccCenterLabel($c))])));
        $w[]='lower(trim(ceco)) IN ('.implode(',',array_fill(0,count($names),'?')).')'; array_push($p,...$names);
    } elseif($f['centro']===-1) $w[]="trim(ceco)=''";
    $sql=' WHERE '.implode(' AND ',$w);
    $cot=[]; foreach(query("SELECT CASE WHEN estado='Enviada' AND vence<>'' AND vence<? THEN 'Vencida' ELSE estado END, COUNT(*), COALESCE(SUM(total),0) FROM cot_quotes".$sql.' GROUP BY 1',[ocToday(),...$p])->fetchAll(PDO::FETCH_NUM) as [$e,$n,$v]) $cot[$e]=['n'=>(int)$n,'v'=>(int)$v];
    $oc=[]; foreach(query('SELECT estado, COUNT(*), COALESCE(SUM(total),0) FROM oc_orders'.$sql.' GROUP BY 1',$p)->fetchAll(PDO::FETCH_NUM) as [$e,$n,$v]) $oc[$e]=['n'=>(int)$n,'v'=>(int)$v];
    $g=fn(array $a, array $keys, string $k): int => array_sum(array_map(fn($e)=>$a[$e][$k]??0,$keys));
    $won=$g($cot,['Aceptada'],'v'); $decided=$g($cot,['Aceptada','Rechazada','Vencida'],'n');
    return ['cot'=>$cot,'oc'=>$oc,'ganado'=>$won,'ganadoN'=>$g($cot,['Aceptada'],'n'),'abierto'=>$g($cot,['Enviada'],'v'),'abiertoN'=>$g($cot,['Enviada'],'n'),
        'tasa'=>$decided>0?(int)round(100*$g($cot,['Aceptada'],'n')/$decided):null,
        'ocTotal'=>$g($oc,['Emitida','Aceptada','Recibida','Pagada'],'v'),'ocN'=>$g($oc,['Emitida','Aceptada','Recibida','Pagada'],'n'),
        'porPagar'=>$g($oc,['Emitida','Aceptada','Recibida'],'v'),'porPagarN'=>$g($oc,['Emitida','Aceptada','Recibida'],'n')];
}

// ---------- Gráficos SVG ----------
/** Monto corto para ejes y etiquetas: $ 850 mil, $ 1,2 M. */
function dashShort(int|float $n): string {
    $a=abs($n); $s=$n<0?'− ':'';
    if($a>=1e9) return $s.'$ '.rtrim(rtrim(number_format($a/1e9,1,',','.'),'0'),',').' MM';
    if($a>=1e6) return $s.'$ '.rtrim(rtrim(number_format($a/1e6,1,',','.'),'0'),',').' M';
    if($a>=1e3) return $s.'$ '.number_format($a/1e3,0,',','.').' mil';
    return $s.'$ '.number_format($a,0,',','.');
}
/** Paso «redondo» del eje (1, 2, 2,5 o 5 × 10^n) para dividir un rango en unas 4 partes. */
function dashStep(float $span): float {
    if($span<=0) return 1.0; $step=$span/4; $e=10**floor(log10($step)); $m=$step/$e;
    return ($m<=1?1:($m<=2?2:($m<=2.5?2.5:($m<=5?5:10))))*$e;
}
/** Tope del eje para valores desde cero. */
function dashNice(float $v): float { return dashStep($v)*4; }
/** Barra vertical con el extremo de datos redondeado (radio 3) y la base recta sobre la línea de cero. */
function dashBar(float $x, float $y0, float $y1, float $w, string $fill, string $extra=''): string {
    $h=abs($y1-$y0); if($h<0.5) return '';
    $r=min(3,$w/2,$h); $up=$y1<$y0; $t=$up?$y1:$y0; $bt=$up?$y0:$y1;
    $d=$up ? sprintf('M%.1f %.1fV%.1fQ%.1f %.1f %.1f %.1fH%.1fQ%.1f %.1f %.1f %.1fV%.1fZ',$x,$bt,$t+$r,$x,$t,$x+$r,$t,$x+$w-$r,$x+$w,$t,$x+$w,$t+$r,$bt)
           : sprintf('M%.1f %.1fV%.1fQ%.1f %.1f %.1f %.1fH%.1fQ%.1f %.1f %.1f %.1fV%.1fZ',$x,$t,$bt-$r,$x,$bt,$x+$r,$bt,$x+$w-$r,$x+$w,$bt,$x+$w,$bt-$r,$t);
    return '<path d="'.$d.'" fill="'.$fill.'"'.$extra.'/>';
}
function dashPeriodLabel(string $k, bool $short=false): string {
    if(strlen($k)===4) return $k;
    $m=DASH_MONTHS[substr($k,5,2)]; return $short ? mb_substr($m,0,3).' '.substr($k,2,2) : $m.' '.substr($k,0,4);
}
function dashPeriodRange(string $k): array {
    if(strlen($k)===4) return [$k.'-01-01',$k.'-12-31'];
    return [$k.'-01',(new DateTimeImmutable($k.'-01'))->modify('last day of this month')->format('Y-m-d')];
}
/** Recuadro de detalle que aparece al pasar el cursor (o tocar) un período. Filas: [color, etiqueta, valor]. */
function dashTip(float $cx, float $top, float $W, string $title, array $rows): string {
    $w=196; $h=26+18*count($rows); $x=max(4,min($W-$w-4,$cx-$w/2)); $y=max(2,$top-$h-8);
    $o='<g class="dash-tip"><rect x="'.$x.'" y="'.$y.'" width="'.$w.'" height="'.$h.'" rx="8" fill="#fff" stroke="#d6e0ea"/>';
    $o.='<text x="'.($x+12).'" y="'.($y+18).'" class="tt-h">'.e($title).'</text>';
    foreach($rows as $i=>[$c,$l,$v]){ $yy=$y+36+18*$i; $o.=($c?'<rect x="'.($x+12).'" y="'.($yy-8).'" width="8" height="8" rx="2" fill="'.$c.'"/>':'').'<text x="'.($x+($c?26:12)).'" y="'.$yy.'" class="tt-l">'.e($l).'</text><text x="'.($x+$w-12).'" y="'.$yy.'" class="tt-v" text-anchor="end">'.e($v).'</text>'; }
    return $o.'</g>';
}
/** Eje Y con líneas guía cada $step; devuelve [svg, función valor→y]. */
function dashAxis(float $min, float $max, float $W, float $L, float $T, float $H, float $step=0): array {
    $sy=fn(float $v): float => $T+($max-$v)/($max-$min)*$H; $o='';
    if($step<=0) $step=($max-$min)/4;
    for($v=$min;$v<=$max+$step/1000;$v+=$step){ $y=round($sy($v),1); $o.='<line x1="'.$L.'" x2="'.($W-8).'" y1="'.$y.'" y2="'.$y.'" class="'.(abs($v)<$step/1000?'zero':'grid').'"/><text x="'.($L-8).'" y="'.($y+4).'" class="ax" text-anchor="end">'.e(dashShort(abs($v)<$step/1000?0:$v)).'</text>'; }
    return [$o,$sy];
}

/** Ingresos y costos por período (barras pareadas) con el resultado como línea. */
function dashChartFlow(array $d, callable $link): string {
    $s=$d['series']; $n=max(1,count($s)); $cat=$d['f']['cat'];
    $W=max(880,min(1200,$n*64+80)); $Hh=300; $L=72; $T=16; $B=40; $H=$Hh-$T-$B;
    $vals=[0]; foreach($s as $v){ $vals[]=$v['ingreso']; $vals[]=$v['gasto']; $vals[]=$v['ingreso']-$v['gasto']; }
    // Un solo eje en pesos; baja de cero solo si algún resultado es negativo.
    $step=dashStep(max($vals)-min($vals)); $max=max(1,ceil(max($vals)/$step))*$step; $min=min(0,floor(min($vals)/$step))*$step;
    [$axis,$sy]=dashAxis($min,$max,$W,$L,$T,$H,$step);
    $slot=($W-8-$L)/$n; $bw=min(20,max(4,$slot*0.3)); $y0=$sy(0);
    $bars=''; $pts=[]; $hits=''; $i=0; $every=max(1,(int)ceil($n/14));
    foreach($s as $k=>$v){
        $cx=$L+$slot*($i+0.5); $res=$v['ingreso']-$v['gasto'];
        $bars.=dashBar($cx-$bw-1,$y0,$sy($v['ingreso']),$bw,DASH_IN);
        $bars.=dashBar($cx+1,$y0,$sy($v['gasto']),$bw,$cat!==''?DASH_OUT_SOFT:DASH_OUT);
        if($cat!=='' && $v['cat']>0) $bars.=dashBar($cx+1,$y0,$sy($v['cat']),$bw,DASH_OUT);
        $pts[]=[$cx,$sy($res)];
        if($i%$every===0) $bars.='<text x="'.round($cx,1).'" y="'.($Hh-14).'" class="ax" text-anchor="middle">'.e(dashPeriodLabel($k,true)).'</text>';
        $rows=[[DASH_IN,'Ingresos',clp($v['ingreso'])],[DASH_OUT,'Costos',clp($v['gasto'])]];
        if($cat!=='') $rows[]=[DASH_OUT,'· '.mb_strimwidth($cat,0,18,'…'),clp($v['cat'])];
        $rows[]=[DASH_LINE,'Resultado',ccClp($res)];
        if($v['ingreso']>0) $rows[]=['','Costo por $100',clp(round(100*$v['gasto']/$v['ingreso']))];
        $top=min($sy($v['ingreso']),$sy($v['gasto']),$sy($res));
        $hits.='<a class="dash-hit" href="'.e($link($k)).'"><title>'.e(dashPeriodLabel($k)).': ver el detalle de este período</title><rect x="'.round($L+$slot*$i,1).'" y="'.$T.'" width="'.round($slot,1).'" height="'.$H.'" fill="#000" fill-opacity="0"/>'.dashTip($cx,$top,$W,dashPeriodLabel($k),$rows).'</a>';
        $i++;
    }
    $line=''; if($n>1) $line='<polyline points="'.implode(' ',array_map(fn($p)=>round($p[0],1).','.round($p[1],1),$pts)).'" fill="none" stroke="'.DASH_LINE.'" stroke-width="2" stroke-linejoin="round"/>';
    foreach($pts as [$x,$y]) $line.='<circle cx="'.round($x,1).'" cy="'.round($y,1).'" r="4" fill="'.DASH_LINE.'" stroke="#fff" stroke-width="2"/>';
    return '<svg class="dash-svg" viewBox="0 0 '.$W.' '.$Hh.'" width="'.$W.'" height="'.$Hh.'" role="img" aria-label="Ingresos, costos y resultado por período"><g>'.$axis.'</g>'.$bars.$line.$hits.'</svg>';
}

/** Ingresos y costos acumulados en el período: muestra cuándo los ingresos cubren los costos. */
function dashChartCum(array $d): string {
    $s=$d['series']; $n=max(1,count($s));
    $W=max(880,min(1200,$n*64+80)); $Hh=300; $L=72; $T=16; $B=40; $H=$Hh-$T-$B;
    $ci=0; $co=0; $pi=[]; $po=[]; $keys=array_keys($s);
    foreach($s as $v){ $ci+=$v['ingreso']; $co+=$v['gasto']; $pi[]=$ci; $po[]=$co; }
    [$axis,$sy]=dashAxis(0,dashNice(max(1,$ci,$co)),$W,$L,$T,$H);
    $slot=($W-8-$L)/$n; $x=fn(int $i): float => $L+$slot*($i+0.5); $every=max(1,(int)ceil($n/14));
    $o='<svg class="dash-svg" viewBox="0 0 '.$W.' '.$Hh.'" width="'.$W.'" height="'.$Hh.'" role="img" aria-label="Ingresos y costos acumulados"><g>'.$axis.'</g>';
    // Zona entre ambas líneas: verde cuando los ingresos acumulados superan a los costos, roja cuando no.
    for($i=1;$i<$n;$i++){
        $fill=($pi[$i]+$pi[$i-1])>=($po[$i]+$po[$i-1])?DASH_IN:DASH_OUT;
        $o.='<polygon points="'.implode(' ',[round($x($i-1),1).','.round($sy($pi[$i-1]),1),round($x($i),1).','.round($sy($pi[$i]),1),round($x($i),1).','.round($sy($po[$i]),1),round($x($i-1),1).','.round($sy($po[$i-1]),1)]).'" fill="'.$fill.'" fill-opacity=".12"/>';
    }
    foreach([[$pi,DASH_IN],[$po,DASH_OUT]] as [$arr,$c]){
        if($n>1) $o.='<polyline points="'.implode(' ',array_map(fn($i)=>round($x($i),1).','.round($sy($arr[$i]),1),array_keys($arr))).'" fill="none" stroke="'.$c.'" stroke-width="2" stroke-linejoin="round"/>';
        foreach($arr as $i=>$v) $o.='<circle cx="'.round($x($i),1).'" cy="'.round($sy($v),1).'" r="4" fill="'.$c.'" stroke="#fff" stroke-width="2"/>';
    }
    $hits='';
    foreach($keys as $i=>$k){
        if($i%$every===0) $o.='<text x="'.round($x($i),1).'" y="'.($Hh-14).'" class="ax" text-anchor="middle">'.e(dashPeriodLabel($k,true)).'</text>';
        $hits.='<g class="dash-hit"><rect x="'.round($L+$slot*$i,1).'" y="'.$T.'" width="'.round($slot,1).'" height="'.$H.'" fill="#000" fill-opacity="0"/>'.dashTip($x($i),min($sy($pi[$i]),$sy($po[$i])),$W,'Acumulado a '.dashPeriodLabel($k),[[DASH_IN,'Ingresos',clp($pi[$i])],[DASH_OUT,'Costos',clp($po[$i])],[DASH_LINE,'Resultado',ccClp($pi[$i]-$po[$i])]]).'</g>';
    }
    // Etiquetas directas al final de cada línea: la mayor arriba de su línea; la menor, entre ambas si hay espacio (las líneas suben hacia la derecha).
    $lx=round($x($n-1)-8,1); $anchor=$n>1?'end':'middle'; $a=$sy($pi[$n-1]); $b=$sy($po[$n-1]);
    [$hiY,$loY,$hiT,$loT]=$pi[$n-1]>=$po[$n-1] ? [$a,$b,'Ingresos '.dashShort($pi[$n-1]),'Costos '.dashShort($po[$n-1])] : [$b,$a,'Costos '.dashShort($po[$n-1]),'Ingresos '.dashShort($pi[$n-1])];
    $o.='<text x="'.$lx.'" y="'.round($hiY-10,1).'" class="lbl" text-anchor="'.$anchor.'">'.e($hiT).'</text><text x="'.$lx.'" y="'.round($loY-$hiY>34?$loY-10:$loY+20,1).'" class="lbl" text-anchor="'.$anchor.'">'.e($loT).'</text>';
    return $o.$hits.'</svg>';
}

/** Costos por período apilados por categoría (5 principales + «Otras»). */
function dashChartMix(array $d, array $colors, callable $link): string {
    $s=$d['series']; $n=max(1,count($s)); $sel=$d['f']['cat'];
    $W=max(880,min(1200,$n*64+80)); $Hh=260; $L=72; $T=16; $B=40; $H=$Hh-$T-$B;
    [$axis,$sy]=dashAxis(0,dashNice(max(1,...array_values(array_map(fn($v)=>$v['gasto'],$s)))),$W,$L,$T,$H);
    $slot=($W-8-$L)/$n; $bw=min(34,max(6,$slot*0.5)); $y0=$sy(0); $every=max(1,(int)ceil($n/14));
    $o='<svg class="dash-svg" viewBox="0 0 '.$W.' '.$Hh.'" width="'.$W.'" height="'.$Hh.'" role="img" aria-label="Composición de los costos por categoría"><g>'.$axis.'</g>'; $hits=''; $i=0;
    foreach($s as $k=>$v){
        $cx=$L+$slot*($i+0.5); $acc=0; $rows=[]; $segs=array_keys($colors); $last=null;
        foreach($segs as $c) if(($v['mix'][$c]??0)>0) $last=$c;
        foreach($segs as $c){
            $val=$v['mix'][$c]??0; if($val<=0) continue;
            $ya=$sy($acc); $yb=$sy($acc+$val); $acc+=$val; $gap=$c===$last?0:1; // separación de 2 px entre segmentos
            $dim=($sel!=='' && $c!==$sel) ? ' fill-opacity=".3"' : ''; // con una categoría elegida, el resto queda atenuado
            if($c===$last) $o.=dashBar($cx-$bw/2,$ya,$yb,$bw,$colors[$c],$dim);
            elseif($ya-$yb-2*$gap>0.5) $o.='<rect x="'.round($cx-$bw/2,1).'" y="'.round($yb+$gap,1).'" width="'.round($bw,1).'" height="'.round($ya-$yb-2*$gap,1).'" fill="'.$colors[$c].'"'.$dim.'/>';
            $rows[]=[$colors[$c],mb_strimwidth($c,0,20,'…'),clp($val)];
        }
        if($i%$every===0) $o.='<text x="'.round($cx,1).'" y="'.($Hh-14).'" class="ax" text-anchor="middle">'.e(dashPeriodLabel($k,true)).'</text>';
        $rows=array_reverse($rows); $rows[]=['','Total',clp($v['gasto'])];
        $hits.='<a class="dash-hit" href="'.e($link($k)).'"><title>'.e(dashPeriodLabel($k)).': ver el detalle de este período</title><rect x="'.round($L+$slot*$i,1).'" y="'.$T.'" width="'.round($slot,1).'" height="'.$H.'" fill="#000" fill-opacity="0"/>'.($v['gasto']>0?dashTip($cx,$sy($v['gasto']),$W,'Costos · '.dashPeriodLabel($k),$rows):'').'</a>';
        $i++;
    }
    return $o.$hits.'</svg>';
}
/** Barra horizontal (proporción 0–1) para las filas de ranking. */
function dashHBar(float $ratio, string $fill, float $ratio2=-1, string $fill2=''): string {
    $w=max(0,min(100,$ratio*100));
    $o='<svg class="dash-hbar" viewBox="0 0 100 8" preserveAspectRatio="none" aria-hidden="true"><rect width="100" height="8" fill="#edf1f5"/>'.($w>0?'<rect width="'.round(max($w,0.6),2).'" height="8" fill="'.$fill.'"/>':'');
    if($ratio2>=0 && $ratio2*100>0.3) $o.='<rect width="'.round(min(100,$ratio2*100),2).'" height="8" fill="'.$fill2.'"/>';
    return $o.'</svg>';
}
