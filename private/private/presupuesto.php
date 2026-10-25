<?php
// Módulo de presupuestos: presupuesto por curso, proyecto u operación, con partidas por categoría contable y control
// de ejecución contra los movimientos del módulo contable (centro de costo del presupuesto dentro de su período).
// Se incluye desde app.php después de dash.php (usa CC_COUNTED, ccCfg, ccCenters, ccCenterLabel, ccMoney, ccClp,
// ocText, ocDateIn, ocNum, ocToday, ocList, ocDate, ocQty, clp, dashHBar y los colores DASH_*).
$db->exec('CREATE TABLE IF NOT EXISTS ps_budgets(id INTEGER PRIMARY KEY, numero TEXT NOT NULL UNIQUE, nombre TEXT NOT NULL, tipo TEXT NOT NULL DEFAULT \'Curso\', codigo TEXT NOT NULL DEFAULT \'\', cliente TEXT NOT NULL DEFAULT \'\', project_id INTEGER REFERENCES projects(id), center_id INTEGER REFERENCES cc_centers(id), desde TEXT NOT NULL DEFAULT \'\', hasta TEXT NOT NULL DEFAULT \'\', participantes INTEGER NOT NULL DEFAULT 0, horas REAL NOT NULL DEFAULT 0, part_reales INTEGER NOT NULL DEFAULT 0, alerta INTEGER NOT NULL DEFAULT 80, estado TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1, obs TEXT NOT NULL DEFAULT \'\', revision TEXT NOT NULL DEFAULT \'\', aprob_ingreso INTEGER, aprob_gasto INTEGER, approved_by INTEGER REFERENCES users(id), approved TEXT, alert_level INTEGER NOT NULL DEFAULT 0, owner INTEGER NOT NULL REFERENCES users(id), created TEXT NOT NULL, updated TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS ps_lines(id INTEGER PRIMARY KEY, budget_id INTEGER NOT NULL REFERENCES ps_budgets(id) ON DELETE CASCADE, tipo TEXT NOT NULL, categoria TEXT NOT NULL, descripcion TEXT NOT NULL DEFAULT \'\', cantidad REAL NOT NULL DEFAULT 1, unidad TEXT NOT NULL DEFAULT \'\', precio INTEGER NOT NULL DEFAULT 0, base TEXT NOT NULL DEFAULT \'fijo\', monto INTEGER NOT NULL DEFAULT 0, orden INTEGER NOT NULL DEFAULT 0);
CREATE INDEX IF NOT EXISTS ps_lines_budget ON ps_lines(budget_id);
CREATE INDEX IF NOT EXISTS ps_budgets_center ON ps_budgets(center_id);
CREATE INDEX IF NOT EXISTS ps_budgets_project ON ps_budgets(project_id);');
// v10: % de retención de honorarios con que se calculó la partida (0 = el precio ya es bruto).
if(!in_array('ret',query('SELECT name FROM pragma_table_info(\'ps_lines\')')->fetchAll(PDO::FETCH_COLUMN),true)) $db->exec('ALTER TABLE ps_lines ADD COLUMN ret REAL NOT NULL DEFAULT 0');

const PS_ESTADOS=['Borrador','Por aprobar','Aprobado','Cerrado'];
const PS_TIPOS=['Curso','Proyecto','Operación','Otro'];
/** Cómo se calcula el monto de una partida: cantidad × precio × multiplicador. */
const PS_BASES=['fijo'=>'Monto fijo','part'=>'× participantes','hora'=>'× horas','parthora'=>'× participantes × horas'];
/** Estados de OC que comprometen un gasto que aún no se paga (una OC pagada ya debería estar registrada como gasto). */
const PS_OC_COMPROMISO=['Emitida','Aceptada','Recibida'];

function psEstadoClass(string $s): string { return ['Borrador'=>'st-borrador','Por aprobar'=>'st-enviada','Aprobado'=>'st-aprobada','Cerrado'=>'st-cerrado'][$s]??'st-borrador'; }
function psGet(int $id): array|false {
    $b=$id>0 ? query('SELECT * FROM ps_budgets WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC) : false;
    if(!$b) return false;
    $b['lines']=query('SELECT * FROM ps_lines WHERE budget_id=? ORDER BY tipo DESC, orden, id',[$b['id']])->fetchAll(PDO::FETCH_ASSOC);
    return $b;
}
/** Se modifica solo en borrador (cualquier editor o el administrador). Aprobado: el administrador lo reabre como nueva versión. */
function psEditable(array $b): bool { global $canEdit; return $canEdit && $b['estado']==='Borrador'; }
function psNextNumero(): string {
    $pre='PRE-'.substr(ocToday(),0,4).'-'; $n=1;
    foreach(query('SELECT numero FROM ps_budgets WHERE numero LIKE ?',[$pre.'%'])->fetchAll(PDO::FETCH_COLUMN) as $num) { $rest=substr($num,strlen($pre)); if(ctype_digit($rest)) $n=max($n,(int)$rest+1); }
    return $pre.str_pad((string)$n,4,'0',STR_PAD_LEFT);
}
function psMult(string $base, array $b): float {
    return match($base){ 'part'=>(float)$b['participantes'], 'hora'=>(float)$b['horas'], 'parthora'=>(float)$b['participantes']*(float)$b['horas'], default=>1.0 };
}
/** Retención de boletas de honorarios vigente (Configuración contable; 15,25 % en 2026). */
function psRetRate(): float { return (float)(ccCfg()['ret_honorarios']??15.25); }
/** Monto bruto a partir del líquido: el relator recibe el líquido y la OTEC paga además la retención al SII. */
function psGross(float $liquido, float $ret): float { return $ret>0 ? $liquido/(1-$ret/100) : $liquido; }
/** Líquido y retención de una partida guardada (si se ingresó en líquido). */
function psRetSplit(array $l): array { $r=(float)$l['ret']; if($r<=0) return [(int)$l['monto'],0]; $liq=(int)round((int)$l['monto']*(1-$r/100)); return [$liq,(int)$l['monto']-$liq]; }
function psRetLabel(float $r): string { return str_replace('.',',',rtrim(rtrim(number_format($r,2,'.',''),'0'),'.')).' %'; }
function psCatLabel(string $c): string { return $c!=='' ? $c : 'Sin categoría'; }
function psPeriod(array $b): string {
    if($b['desde']==='' && $b['hasta']==='') return 'Sin período definido';
    return ($b['desde']!==''?ocDate($b['desde']):'inicio').' – '.($b['hasta']!==''?ocDate($b['hasta']):'sin término');
}

/** Datos generales del formulario. */
function psFromPost(): array {
    return ['nombre'=>ocText(post('nombre'),120),'tipo'=>in_array(post('tipo'),PS_TIPOS,true)?post('tipo'):'Curso','codigo'=>ocText(post('codigo'),40),'cliente'=>ocText(post('cliente'),160),
        'project_id'=>max(0,(int)post('project_id')),'center'=>post('center_id'),'desde'=>ocDateIn(post('desde')),'hasta'=>ocDateIn(post('hasta')),
        'participantes'=>max(0,min(100000,(int)ccMoney(post('participantes')))),'horas'=>round(min(100000.0,ocNum(post('horas'))),1),
        'alerta'=>post('alerta')===''?80:max(50,min(100,(int)post('alerta'))),'obs'=>ocText(post('obs'),1000)];
}
/**
 * Partidas del formulario. La partida (categoría) llega como «gasto|Honorarios» o «ingreso|Venta de cursos».
 * Se ignoran las filas sin precio. Devuelve filas con su monto ya calculado.
 */
function psLinesFromPost(array $h, array $old=[]): array {
    $cfg=ccCfg(); $valid=['gasto'=>$cfg['cat_gasto'],'ingreso'=>$cfg['cat_ingreso']];
    foreach($old as $l) $valid[$l['tipo']][]=$l['categoria']; // una categoría ya usada se conserva aunque se haya quitado de la configuración
    [$cats,$ds,$cs,$us,$ps,$bs,$rs]=[ocList('ln_cat'),ocList('ln_desc'),ocList('ln_cant'),ocList('ln_unidad'),ocList('ln_precio'),ocList('ln_base'),ocList('ln_ret')];
    $out=[]; $rate=psRetRate();
    foreach($cats as $i=>$c){
        $precio=ccMoney($ps[$i]??''); $desc=ocText($ds[$i]??'',160);
        if($precio<=0) continue;
        if(count($out)>=200) throw new RuntimeException('Un presupuesto admite hasta 200 partidas.');
        [$tipo,$cat]=array_pad(explode('|',$c,2),2,'');
        if(!isset($valid[$tipo]) || !in_array($cat,$valid[$tipo],true)) throw new RuntimeException('Elige la partida (categoría) de la fila «'.($desc!==''?$desc:clp($precio)).'».');
        $cantRaw=trim($cs[$i]??''); $cant=$cantRaw===''?1.0:round(ocNum($cantRaw),3);
        if($cant<=0) throw new RuntimeException('La cantidad de «'.($desc!==''?$desc:$cat).'» debe ser mayor que 0.');
        $base=isset(PS_BASES[$bs[$i]??''])?$bs[$i]:'fijo';
        if(in_array($base,['part','parthora'],true) && $h['participantes']<=0) throw new RuntimeException('La partida «'.($desc!==''?$desc:$cat).'» se calcula por participante: indica el número de participantes.');
        if(in_array($base,['hora','parthora'],true) && $h['horas']<=0) throw new RuntimeException('La partida «'.($desc!==''?$desc:$cat).'» se calcula por hora: indica las horas del curso o proyecto.');
        $liq=($rs[$i]??'')==='liq';
        if($liq && $tipo!=='gasto') throw new RuntimeException('«'.($desc!==''?$desc:$cat).'» es un ingreso: el precio líquido con retención solo se usa en gastos (honorarios).');
        $ret=$liq?$rate:0.0;
        $out[]=['tipo'=>$tipo,'categoria'=>$cat,'descripcion'=>$desc,'cantidad'=>$cant,'unidad'=>ocText($us[$i]??'',30),'precio'=>$precio,'base'=>$base,'ret'=>$ret,'monto'=>(int)round(psGross($cant*$precio*psMult($base,$h),$ret))];
    }
    return $out;
}
/**
 * Centro de costo elegido: id existente, «auto» (el centro con el nombre del proyecto o del presupuesto; se crea si no existe)
 * o ninguno. Devuelve [id|null, nombre del centro creado|null].
 */
function psResolveCenter(string $v, array $h): array {
    global $db;
    if(ctype_digit($v) && (int)$v>0){ if(!isset(ccCenters()[(int)$v])) throw new RuntimeException('El centro de costo elegido no existe.'); return [(int)$v,null]; }
    if($v!=='auto') return [null,null];
    $pr=$h['project_id']>0 ? project($h['project_id']) : false;
    $name=shortText($pr ? $pr['name'] : $h['nombre'],80);
    $id=query('SELECT id FROM cc_centers WHERE nombre=? COLLATE NOCASE',[$name])->fetchColumn();
    if($id) return [(int)$id,null];
    query('INSERT INTO cc_centers(nombre,descripcion,created) VALUES(?,?,?)',[$name,'Creado desde un presupuesto',gmdate('c')]);
    return [(int)$db->lastInsertId(),$name];
}
/** Nombres con que una OC o cotización puede citar el centro en su campo «Centro de costo» (como en el dashboard). */
function psCecoMatch(array $c, string $ceco): bool {
    $ceco=mb_strtolower(trim($ceco)); if($ceco==='') return false;
    return in_array($ceco,array_filter([mb_strtolower(trim($c['nombre'])),mb_strtolower(trim($c['codigo'])),mb_strtolower(ccCenterLabel($c))]),true);
}

/** Condiciones de los movimientos que imputan al presupuesto (alias s, m, r como en contabilidad). */
function psMovWhere(array $b): array {
    $w=' FROM cc_splits s JOIN cc_movs m ON m.id=s.mov_id LEFT JOIN cc_rendiciones r ON r.id=m.rendicion_id WHERE s.center_id=?'; $p=[(int)$b['center_id']];
    if($b['desde']!==''){ $w.=' AND m.fecha>=?'; $p[]=$b['desde']; }
    if($b['hasta']!==''){ $w.=' AND m.fecha<=?'; $p[]=$b['hasta']; }
    return [$w,$p];
}
/** Nivel de una partida de gasto: 0 en rango, 1 alerta (llegó al % de alerta o no estaba presupuestada), 2 sobregiro. */
function psLevel(int $plan, int $real, int $alerta): int {
    if($real<=0) return 0;
    if($plan<=0) return 1;
    return $real>$plan ? 2 : ($real*100>=$plan*$alerta ? 1 : 0);
}
/**
 * Presupuesto frente a lo real. Real: movimientos que ya cuentan en resultados (gastos de rendiciones solo aprobadas)
 * asignados al centro dentro del período. En revisión: gastos de rendiciones enviadas, aún sin aprobar.
 * Comprometido: OC emitidas, aceptadas o recibidas del centro cuyo número no aparece como referencia de un movimiento.
 * Por facturar: cotizaciones aceptadas del centro sin un ingreso que las cite.
 */
function psExec(array $b): array {
    $alerta=(int)$b['alerta']; $plan=['ingreso'=>[],'gasto'=>[]]; $real=$plan; $rev=$plan; $nmov=$plan;
    foreach($b['lines'] as $l) $plan[$l['tipo']][$l['categoria']]=($plan[$l['tipo']][$l['categoria']]??0)+(int)$l['monto'];
    $x=['oc'=>[],'cot'=>[],'center'=>null,'nmov'=>0];
    $centers=ccCenters();
    if($b['center_id']!==null && isset($centers[(int)$b['center_id']])){
        $c=$centers[(int)$b['center_id']]; $x['center']=$c;
        [$w,$p]=psMovWhere($b);
        foreach(query('SELECT m.tipo, m.categoria, SUM(s.monto), COUNT(DISTINCT m.id)'.$w.' AND '.CC_COUNTED.' GROUP BY 1, 2',$p)->fetchAll(PDO::FETCH_NUM) as [$t,$cat,$v,$n]){ $real[$t][(string)$cat]=(int)$v; $nmov[$t][(string)$cat]=(int)$n; $x['nmov']+=(int)$n; }
        foreach(query('SELECT m.tipo, m.categoria, SUM(s.monto)'.$w." AND r.estado='Enviada' GROUP BY 1, 2",$p)->fetchAll(PDO::FETCH_NUM) as [$t,$cat,$v]) $rev[$t][(string)$cat]=(int)$v;
        $refs=[]; foreach(query("SELECT DISTINCT referencia FROM cc_movs WHERE trim(referencia)<>''")->fetchAll(PDO::FETCH_COLUMN) as $rf) $refs[mb_strtolower(trim($rf))]=true;
        // Las OC y cotizaciones suelen emitirse antes del inicio: cuentan todas las pendientes con fecha hasta el término.
        $inPeriod=fn(string $f): bool => $f==='' || $b['hasta']==='' || $f<=$b['hasta'];
        $ph=implode(',',array_fill(0,count(PS_OC_COMPROMISO),'?'));
        foreach(query("SELECT id,numero,fecha,estado,ceco,prov_razon,obj_titulo,total FROM oc_orders WHERE estado IN ($ph) ORDER BY fecha, id",PS_OC_COMPROMISO)->fetchAll(PDO::FETCH_ASSOC) as $o)
            if(psCecoMatch($c,$o['ceco']) && $inPeriod($o['fecha']) && !isset($refs[mb_strtolower(trim($o['numero']))])) $x['oc'][]=$o;
        foreach(query("SELECT id,numero,fecha,ceco,cli_razon,obj_titulo,total FROM cot_quotes WHERE estado='Aceptada' ORDER BY fecha, id")->fetchAll(PDO::FETCH_ASSOC) as $q)
            if(psCecoMatch($c,$q['ceco']) && $inPeriod($q['fecha']) && !isset($refs[mb_strtolower(trim($q['numero']))])) $x['cot'][]=$q;
    }
    $rows=['ingreso'=>[],'gasto'=>[]]; $tot=[]; $lvl=0;
    foreach(['ingreso','gasto'] as $t){
        $keys=array_values(array_unique(array_map('strval',[...array_keys($plan[$t]),...array_keys($real[$t]),...array_keys($rev[$t])])));
        $tot[$t]=['plan'=>0,'real'=>0,'rev'=>0];
        foreach($keys as $k){
            $pl=$plan[$t][$k]??0; $re=$real[$t][$k]??0; $rv=$rev[$t][$k]??0;
            $rows[$t][$k]=['plan'=>$pl,'real'=>$re,'rev'=>$rv,'n'=>$nmov[$t][$k]??0,'pct'=>$pl>0?100*$re/$pl:null,'lvl'=>$t==='gasto'?psLevel($pl,$re,$alerta):0];
            $tot[$t]['plan']+=$pl; $tot[$t]['real']+=$re; $tot[$t]['rev']+=$rv;
            if($t==='gasto') $lvl=max($lvl,$rows[$t][$k]['lvl']);
        }
        // Primero las partidas presupuestadas (en su orden), luego lo no presupuestado de mayor a menor.
        uksort($rows[$t],function($a,$c) use($plan,$rows,$t){ $pa=isset($plan[$t][$a]); $pc=isset($plan[$t][$c]); if($pa!==$pc) return $pa?-1:1; return $pa?0:$rows[$t][$c]['real']<=>$rows[$t][$a]['real']; });
    }
    $x['comp']=array_sum(array_map(fn($o)=>(int)$o['total'],$x['oc']));
    $x['porFacturar']=array_sum(array_map(fn($q)=>(int)$q['total'],$x['cot']));
    $g=$tot['gasto'];
    if($g['plan']>0){
        if($g['real']>$g['plan']) $lvl=2;
        elseif(($g['real']+$g['rev']+$x['comp'])*100>=$g['plan']*$alerta) $lvl=max($lvl,1);
    }
    $x['rows']=$rows; $x['tot']=$tot; $x['lvl']=$lvl;
    $x['proyGasto']=$g['real']+$g['rev']+$x['comp']; $x['proyIngreso']=$tot['ingreso']['real']+$x['porFacturar'];
    $x['disponible']=$g['plan']-$x['proyGasto'];
    // Avance del calendario: % del período transcurrido (null sin fechas de inicio y término).
    $x['tiempo']=null;
    if($b['desde']!=='' && $b['hasta']!==''){
        $d0=new DateTimeImmutable($b['desde']); $d1=new DateTimeImmutable($b['hasta']); $now=new DateTimeImmutable(ocToday());
        $span=max(1,(int)$d0->diff($d1)->days+1);
        $x['tiempo']=$now<$d0 ? 0 : ($now>$d1 ? 100 : (int)round(100*((int)$d0->diff($now)->days+1)/$span));
    }
    return $x;
}
/** Indicadores por participante y por hora, y punto de equilibrio (participantes mínimos para cubrir los costos). */
function psUnit(array $b, array $x): array {
    $P=(int)$b['participantes']; $H=(float)$b['horas'];
    $per=['ingreso'=>0.0,'gasto'=>0.0]; $fix=['ingreso'=>0.0,'gasto'=>0.0];
    foreach($b['lines'] as $l){
        $u=psGross((float)$l['cantidad']*(int)$l['precio'],(float)$l['ret']);
        if($l['base']==='part') $per[$l['tipo']]+=$u; elseif($l['base']==='parthora') $per[$l['tipo']]+=$u*$H;
        else $fix[$l['tipo']]+=$l['base']==='hora' ? $u*$H : $u;
    }
    $mu=$per['ingreso']-$per['gasto']; $need=$fix['gasto']-$fix['ingreso'];
    $gp=$x['tot']['gasto']['plan']; $gr=$x['tot']['gasto']['real']; $Pr=(int)$b['part_reales'];
    return ['ingresoPart'=>$per['ingreso'],'costoVarPart'=>$per['gasto'],'fijos'=>$fix['gasto'],
        'equilibrio'=>$per['ingreso']>0 ? ($mu>0 ? max(0,(int)ceil($need/$mu)) : null) : null,
        'precioMin'=>$P>0 ? $need/$P+$per['gasto'] : null,
        'costoPart'=>$P>0 ? $gp/$P : null, 'costoHora'=>$H>0 ? $gp/$H : null,
        'costoPartReal'=>$Pr>0 ? $gr/$Pr : null, 'costoHoraReal'=>$H>0 && $gr>0 ? $gr/$H : null];
}
/** Otros presupuestos vigentes con el mismo centro y períodos que se cruzan: los mismos movimientos cuentan en ambos. */
function psOverlaps(array $b): array {
    if($b['center_id']===null) return [];
    $out=[];
    foreach(query("SELECT id,numero,nombre,desde,hasta FROM ps_budgets WHERE center_id=? AND id<>? AND estado<>'Cerrado'",[(int)$b['center_id'],(int)$b['id']])->fetchAll(PDO::FETCH_ASSOC) as $o)
        if(($o['hasta']===''||$b['desde']===''||$o['hasta']>=$b['desde']) && ($o['desde']===''||$b['hasta']===''||$o['desde']<=$b['hasta'])) $out[]=$o;
    return $out;
}
function psLevelText(int $lvl): string { return ['En rango','Alerta','Sobregiro'][$lvl]??''; }
function psLevelPill(int $lvl): string { return '<span class="ps-lvl ps-lvl'.$lvl.'">'.psLevelText($lvl).'</span>'; }
function psPct(?float $p): string { return $p===null ? '—' : ($p>0 && $p<1 ? '< 1%' : number_format($p,0,',','.').'%'); }
/** Barra de ejecución: real sobre presupuesto (en rojo si se excede), con lo proyectado (en revisión + comprometido) atenuado. */
function psBar(int $plan, int $real, int $proy=0, string $tipo='gasto'): string {
    $max=max(1,$plan,$real,$proy);
    $col=$tipo==='ingreso' ? DASH_IN : ($real>$plan && $plan>=0 ? DASH_OUT : '#2a78d6');
    $o='<svg class="ps-bar" viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true"><rect width="100" height="10" fill="#edf1f5"/>';
    if($proy>$real) $o.='<rect width="'.round(100*$proy/$max,2).'" height="10" fill="'.($tipo==='ingreso'?'#bfe3cf':'#bcd3ee').'"/>';
    if($real>0) $o.='<rect width="'.round(max(0.8,100*$real/$max),2).'" height="10" fill="'.$col.'"/>';
    if($plan>0 && $plan<$max) $o.='<rect x="'.round(100*$plan/$max-0.4,2).'" width="0.8" height="10" fill="#0f2a45"/>';
    return $o.'</svg>';
}

// ---------- Alertas y pendientes ----------
/** Revisa los presupuestos aprobados y avisa (aviso + correo según preferencia) cuando suben de nivel de alerta. */
function psCheckAlerts(): void {
    foreach(query("SELECT id FROM ps_budgets WHERE estado='Aprobado'")->fetchAll(PDO::FETCH_COLUMN) as $id){
        $b=psGet((int)$id); if(!$b) continue;
        $x=psExec($b); $lvl=$x['lvl']; $prev=(int)$b['alert_level'];
        if($lvl>$prev){
            $g=$x['tot']['gasto']; $parts=[];
            foreach($x['rows']['gasto'] as $k=>$r) if($r['lvl']>0) $parts[]=psCatLabel((string)$k).' ('.($r['plan']>0?psPct($r['pct']).' de '.clp($r['plan']):'no presupuestada: '.clp($r['real'])).')';
            $title=$lvl===2 ? 'Sobregiro en el presupuesto '.$b['numero'] : 'Alerta de gasto en el presupuesto '.$b['numero'];
            $body='«'.$b['nombre'].'»: gasto real '.clp($g['real']).' de '.clp($g['plan']).' presupuestado ('.psPct($g['plan']>0?100*$g['real']/$g['plan']:null).')'
                .($x['comp']+$g['rev']>0?'; con lo comprometido y en revisión llega a '.clp($x['proyGasto']):'').'.'
                .($parts?"\nPartidas sobre el ".$b['alerta'].'%, excedidas o no presupuestadas: '.implode(', ',array_slice($parts,0,6)).'.':'');
            notify(array_merge(adminIds(),[(int)$b['owner']]),'ps_alerta',$title,$body,['pres'=>(int)$b['id']],'pref',false);
        }
        if($lvl!==$prev) query('UPDATE ps_budgets SET alert_level=? WHERE id=?',[$lvl,$b['id']]);
    }
}
/** Pendientes del módulo para la lista de Pendientes y el inicio. */
function psPending(array $u): array {
    $out=[]; $admin=$u['role']==='admin';
    if($admin) foreach(query("SELECT b.*, u.name AS who FROM ps_budgets b LEFT JOIN users u ON u.id=b.owner WHERE b.estado='Por aprobar' ORDER BY b.updated")->fetchAll(PDO::FETCH_ASSOC) as $b){
        $t=query('SELECT tipo, SUM(monto) FROM ps_lines WHERE budget_id=? GROUP BY tipo',[$b['id']])->fetchAll(PDO::FETCH_KEY_PAIR);
        $out[]=['kind'=>'aprobar','title'=>'Aprobar presupuesto '.$b['numero'],'detail'=>$b['nombre'].' · gastos '.clp((int)($t['gasto']??0)).' · ingresos '.clp((int)($t['ingreso']??0)).' · enviado por '.($b['who']?:'—'),'link'=>['pres'=>(int)$b['id']],'since'=>$b['updated']];
    }
    if(in_array($u['role'],['admin','editor'],true))
        foreach(query("SELECT * FROM ps_budgets WHERE estado='Borrador' AND revision<>'' AND owner=? ORDER BY updated",[$u['id']])->fetchAll(PDO::FETCH_ASSOC) as $b)
            $out[]=['kind'=>'corregir','title'=>'Corregir presupuesto '.$b['numero'],'detail'=>$b['nombre'].' · devuelto: '.$b['revision'],'link'=>['pres'=>(int)$b['id']],'since'=>$b['updated']];
    foreach(query("SELECT id FROM ps_budgets WHERE estado='Aprobado' AND alert_level>0".($admin?'':' AND owner='.(int)$u['id']).' ORDER BY alert_level DESC, id')->fetchAll(PDO::FETCH_COLUMN) as $id){
        $b=psGet((int)$id); $x=psExec($b); $g=$x['tot']['gasto']; if($x['lvl']===0) continue;
        $out[]=['kind'=>$x['lvl']===2?'sobregiro':'alerta','title'=>($x['lvl']===2?'Sobregiro en ':'Revisar gasto de ').'presupuesto '.$b['numero'],'detail'=>$b['nombre'].' · gastado '.clp($g['real']).' de '.clp($g['plan']).' ('.psPct($g['plan']>0?100*$g['real']/$g['plan']:null).')'.($x['comp']>0?' · comprometido en OC '.clp($x['comp']):''),'link'=>['pres'=>(int)$b['id']],'since'=>null];
    }
    if($admin){
        $lim=(new DateTimeImmutable(ocToday()))->modify('-30 days')->format('Y-m-d');
        foreach(query("SELECT id,numero,nombre,hasta FROM ps_budgets WHERE estado='Aprobado' AND hasta<>'' AND hasta<? ORDER BY hasta",[$lim])->fetchAll(PDO::FETCH_ASSOC) as $b)
            $out[]=['kind'=>'cerrar','title'=>'Cerrar presupuesto '.$b['numero'],'detail'=>$b['nombre'].' · terminó el '.ocDate($b['hasta']).'. Revisa el informe final y ciérralo.','link'=>['pres'=>(int)$b['id']],'since'=>null];
    }
    return $out;
}
// Después de cada acción que mueve cifras (contabilidad, OC o presupuestos) se revisan las alertas, ya enviada la respuesta.
if(!$cli) register_shutdown_function(function(): void {
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST' || empty($GLOBALS['user']) || !preg_match('/^(cc|oc|ps)_/',post('action'))) return;
    try { psCheckAlerts(); } catch(Throwable $ex) { error_log('Alertas de presupuesto: '.$ex); }
});

/** Avisos al cambiar de estado. */
function psEvent(array $b, string $estado): void {
    global $user; $who=$user?$user['name']:'Sistema'; $link=['pres'=>(int)$b['id']];
    if($estado==='Por aprobar') notify(adminIds(),'ps_pend','Presupuesto '.$b['numero'].' por aprobar',$who.' envió el presupuesto «'.$b['nombre'].'» a aprobación. Revísalo y apruébalo o devuélvelo con comentarios.',$link);
    elseif($estado==='Aprobado') notify([(int)$b['owner']],'ps_ok','Presupuesto '.$b['numero'].' aprobado',$who.' aprobó el presupuesto «'.$b['nombre'].'». Desde ahora se controla su ejecución contra la contabilidad.'.($b['revision']!==''?"\nComentario: ".$b['revision']:''),$link);
    elseif($estado==='Devuelto') notify([(int)$b['owner']],'ps_bad','Presupuesto '.$b['numero'].' devuelto',$who.' devolvió el presupuesto «'.$b['nombre'].'» a borrador.'."\nMotivo: ".$b['revision'],$link);
    elseif($estado==='Reabierto') notify([(int)$b['owner']],'ps_open','Presupuesto '.$b['numero'].' reabierto (versión '.$b['version'].')',$who.' reabrió el presupuesto «'.$b['nombre'].'» para reformularlo.'.($b['revision']!==''?"\nMotivo: ".$b['revision']:''),$link);
}

// ---------- Acciones POST ----------
function psPost(string $action): void {
    global $db, $user, $canEdit, $isAdmin;
    if ($action==='ps_save' && $canEdit) {
        $id=(int)post('id'); $old=$id>0?psGet($id):null;
        if($id>0 && !$old) throw new RuntimeException('El presupuesto no existe.');
        if($old && !psEditable($old)) throw new RuntimeException('Solo se modifica un presupuesto en borrador. Si ya está aprobado, el administrador puede reabrirlo como nueva versión.');
        $h=psFromPost();
        if($h['nombre']==='') throw new RuntimeException('Escribe el nombre del presupuesto (por ejemplo, el curso o proyecto).');
        if($h['desde']!=='' && $h['hasta']!=='' && $h['desde']>$h['hasta']) throw new RuntimeException('La fecha de término es anterior a la de inicio.');
        if($h['project_id']>0 && !project($h['project_id'])) $h['project_id']=0;
        $lines=psLinesFromPost($h,$old['lines']??[]);
        $now=gmdate('c');
        $db->exec('BEGIN IMMEDIATE');
        try {
            [$cid,$newCenter]=psResolveCenter($h['center'],$h);
            $vals=[$h['nombre'],$h['tipo'],$h['codigo'],$h['cliente'],$h['project_id']?:null,$cid,$h['desde'],$h['hasta'],$h['participantes'],$h['horas'],$h['alerta'],$h['obs']];
            if($old){ query('UPDATE ps_budgets SET nombre=?,tipo=?,codigo=?,cliente=?,project_id=?,center_id=?,desde=?,hasta=?,participantes=?,horas=?,alerta=?,obs=?,updated=? WHERE id=?',[...$vals,$now,$id]); query('DELETE FROM ps_lines WHERE budget_id=?',[$id]); $num=$old['numero']; }
            else { $num=psNextNumero(); query('INSERT INTO ps_budgets(nombre,tipo,codigo,cliente,project_id,center_id,desde,hasta,participantes,horas,alerta,obs,numero,estado,owner,created,updated) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[...$vals,$num,'Borrador',$user['id'],$now,$now]); $id=(int)$db->lastInsertId(); }
            foreach($lines as $i=>$l) query('INSERT INTO ps_lines(budget_id,tipo,categoria,descripcion,cantidad,unidad,precio,base,ret,monto,orden) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$id,$l['tipo'],$l['categoria'],$l['descripcion'],$l['cantidad'],$l['unidad'],$l['precio'],$l['base'],$l['ret'],$l['monto'],$i]);
            $db->exec('COMMIT');
        } catch(Throwable $ex) { $db->exec('ROLLBACK'); throw $ex; }
        if($newCenter!==null) audit('Creación de centro de costo',$newCenter.' (desde presupuesto)');
        audit($old?'Edición de presupuesto':'Creación de presupuesto',$num.' · '.$h['nombre']);
        $msg=($old?'Presupuesto guardado.':'Presupuesto '.$num.' creado.').($newCenter!==null?' Se creó el centro de costo «'.$newCenter.'»: asígnalo a los ingresos y gastos de este '.mb_strtolower($h['tipo']).'.':'').($lines?'':' Aún no tiene partidas con precio.');
        go($msg,post('after')==='edit'?['pres'=>$id,'edit'=>1]:['pres'=>$id]);
    }
    if ($action==='ps_send' && $canEdit) {
        $b=psGet((int)post('id')); if(!$b) throw new RuntimeException('El presupuesto no existe.');
        if(!psEditable($b)) throw new RuntimeException('Solo se envía a aprobación un presupuesto en borrador.');
        if(!array_filter($b['lines'],fn($l)=>$l['tipo']==='gasto')) throw new RuntimeException('Agrega al menos una partida de gasto antes de enviarlo a aprobación.');
        query('UPDATE ps_budgets SET estado=?,updated=? WHERE id=?',['Por aprobar',gmdate('c'),$b['id']]);
        audit('Envío de presupuesto',$b['numero']); psEvent($b,'Por aprobar');
        go('Presupuesto '.$b['numero'].' enviado a aprobación.',['pres'=>(int)$b['id']]);
    }
    if ($action==='ps_review' && $isAdmin) {
        $b=psGet((int)post('id')); if(!$b) throw new RuntimeException('El presupuesto no existe.');
        if(!in_array($b['estado'],['Borrador','Por aprobar'],true)) throw new RuntimeException('Este presupuesto ya está '.mb_strtolower($b['estado']).'.');
        $ok=post('decision')==='aprobar'; $nota=ocText(post('revision'),600);
        if($ok){
            if(!array_filter($b['lines'],fn($l)=>$l['tipo']==='gasto')) throw new RuntimeException('El presupuesto no tiene partidas de gasto.');
            $t=['ingreso'=>0,'gasto'=>0]; foreach($b['lines'] as $l) $t[$l['tipo']]+=(int)$l['monto'];
            // La primera aprobación queda como presupuesto original; las reformulaciones posteriores se comparan con ella.
            query('UPDATE ps_budgets SET estado=?,revision=?,approved_by=?,approved=?,aprob_ingreso=COALESCE(aprob_ingreso,?),aprob_gasto=COALESCE(aprob_gasto,?),alert_level=0,updated=? WHERE id=?',['Aprobado',$nota,$user['id'],gmdate('c'),$t['ingreso'],$t['gasto'],gmdate('c'),$b['id']]);
            audit('Aprobación de presupuesto',$b['numero'].($nota!==''?' · '.$nota:'')); psEvent(psGet((int)$b['id']),'Aprobado');
            go('Presupuesto '.$b['numero'].' aprobado. Su ejecución se controla contra la contabilidad del centro de costo.',['pres'=>(int)$b['id']]);
        }
        if($b['estado']!=='Por aprobar') throw new RuntimeException('El presupuesto ya está en borrador.');
        if($nota==='') throw new RuntimeException('Indica el motivo para devolverlo, para que se pueda corregir.');
        query('UPDATE ps_budgets SET estado=?,revision=?,updated=? WHERE id=?',['Borrador',$nota,gmdate('c'),$b['id']]);
        audit('Devolución de presupuesto',$b['numero'].' · '.$nota); psEvent(psGet((int)$b['id']),'Devuelto');
        go('Presupuesto '.$b['numero'].' devuelto a borrador.',['pres'=>(int)$b['id']]);
    }
    if ($action==='ps_reopen' && $isAdmin) {
        $b=psGet((int)post('id')); if(!$b) throw new RuntimeException('El presupuesto no existe.');
        if($b['estado']==='Borrador') go('',['pres'=>(int)$b['id']]);
        $nueva=in_array($b['estado'],['Aprobado','Cerrado'],true);
        query('UPDATE ps_budgets SET estado=?,version=version+?,revision=?,alert_level=0,updated=? WHERE id=?',['Borrador',$nueva?1:0,ocText(post('revision'),600),gmdate('c'),$b['id']]);
        audit('Reapertura de presupuesto',$b['numero']); if($nueva) psEvent(psGet((int)$b['id']),'Reabierto');
        go('Presupuesto '.$b['numero'].' devuelto a borrador'.($nueva?' como versión '.((int)$b['version']+1).'. Al aprobarlo de nuevo se mantiene la comparación con el presupuesto original.':'.'),['pres'=>(int)$b['id']]);
    }
    if ($action==='ps_close' && $isAdmin) {
        $b=psGet((int)post('id')); if(!$b) throw new RuntimeException('El presupuesto no existe.');
        if($b['estado']!=='Aprobado') throw new RuntimeException('Solo se cierra un presupuesto aprobado.');
        query('UPDATE ps_budgets SET estado=?,alert_level=0,updated=? WHERE id=?',['Cerrado',gmdate('c'),$b['id']]);
        audit('Cierre de presupuesto',$b['numero']); go('Presupuesto '.$b['numero'].' cerrado. Ya no genera alertas; su informe sigue disponible.',['pres'=>(int)$b['id']]);
    }
    if ($action==='ps_delete' && $canEdit) {
        $b=psGet((int)post('id')); if(!$b) go('',['tab'=>'ps']);
        if(!$isAdmin && !($b['estado']==='Borrador' && $b['aprob_gasto']===null && (int)$b['owner']===(int)$user['id'])) throw new RuntimeException('Solo el administrador elimina presupuestos; quien lo creó puede eliminarlo mientras sea un borrador nunca aprobado.');
        query('DELETE FROM ps_lines WHERE budget_id=?',[$b['id']]); query('DELETE FROM ps_budgets WHERE id=?',[$b['id']]);
        audit('Eliminación de presupuesto',$b['numero'].' · '.$b['nombre']); go('Presupuesto '.$b['numero'].' eliminado. Los movimientos contables no se modifican.',['tab'=>'ps']);
    }
    if ($action==='ps_dup' && $canEdit) {
        $b=psGet((int)post('id')); if(!$b) throw new RuntimeException('El presupuesto no existe.');
        $now=gmdate('c');
        $db->exec('BEGIN IMMEDIATE');
        try {
            $num=psNextNumero();
            query('INSERT INTO ps_budgets(numero,nombre,tipo,codigo,cliente,project_id,center_id,participantes,horas,alerta,obs,estado,owner,created,updated) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$num,shortText($b['nombre'].' (copia)',120),$b['tipo'],$b['codigo'],$b['cliente'],$b['project_id'],$b['center_id'],$b['participantes'],$b['horas'],$b['alerta'],$b['obs'],'Borrador',$user['id'],$now,$now]);
            $nid=(int)$db->lastInsertId();
            foreach($b['lines'] as $i=>$l) query('INSERT INTO ps_lines(budget_id,tipo,categoria,descripcion,cantidad,unidad,precio,base,ret,monto,orden) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$nid,$l['tipo'],$l['categoria'],$l['descripcion'],$l['cantidad'],$l['unidad'],$l['precio'],$l['base'],$l['ret'],$l['monto'],$i]);
            $db->exec('COMMIT');
        } catch(Throwable $ex) { $db->exec('ROLLBACK'); throw $ex; }
        audit('Duplicación de presupuesto',$b['numero'].' → '.$num);
        go('Copia creada como '.$num.'. Ajusta el nombre, las fechas y el centro de costo de la nueva versión antes de enviarla.',['pres'=>$nid,'edit'=>1]);
    }
    if ($action==='ps_real' && $canEdit) {
        $b=psGet((int)post('id')); if(!$b) throw new RuntimeException('El presupuesto no existe.');
        if($b['estado']==='Cerrado') throw new RuntimeException('El presupuesto está cerrado.');
        $n=max(0,min(100000,(int)post('part_reales')));
        query('UPDATE ps_budgets SET part_reales=?,updated=? WHERE id=?',[$n,gmdate('c'),$b['id']]);
        audit('Participantes reales de presupuesto',$b['numero'].' · '.$n); go('Participantes reales actualizados.',['pres'=>(int)$b['id'],'vista'=>'indicadores']);
    }
}

// ---------- Integración con otras pantallas ----------
/** Tarjeta del dashboard: presupuestos aprobados o cerrados cuyo período se cruza con el del panel. */
function psDashCard(array $f): void {
    $w=["estado IN ('Aprobado','Cerrado')"]; $p=[];
    if($f['desde']!==''){ $w[]="(hasta='' OR hasta>=?)"; $p[]=$f['desde']; }
    if($f['hasta']!==''){ $w[]="(desde='' OR desde<=?)"; $p[]=$f['hasta']; }
    if($f['centro']>0){ $w[]='center_id=?'; $p[]=$f['centro']; } elseif($f['centro']===-1) return;
    $ids=query('SELECT id FROM ps_budgets WHERE '.implode(' AND ',$w).' ORDER BY estado=\'Cerrado\', alert_level DESC, hasta DESC, id DESC LIMIT 8',$p)->fetchAll(PDO::FETCH_COLUMN);
    if(!$ids) return;
    $n=['tot'=>0,2=>0,1=>0]; $rows=[];
    foreach($ids as $id){ $b=psGet((int)$id); $x=psExec($b); $rows[]=[$b,$x]; $n['tot']++; if($b['estado']==='Aprobado' && $x['lvl']>0) $n[$x['lvl']]++; } ?>
<section class="card dash-card"><div class="dash-card-head"><div><h2>Presupuestos</h2><p class="hint">Gasto real frente a lo presupuestado en los presupuestos aprobados de este período<?=$f['centro']?' y este centro':''?>. Cada uno se mide en su propio período. Tramo claro: en revisión y comprometido en OC; raya oscura: el presupuesto.</p></div><a class="btn ghost small" href="<?=e(url(['tab'=>'ps']))?>">Ver todos</a></div>
<?php if($n[2]||$n[1]):?><p class="ps-dash-sum"><?=$n[2]?psLevelPill(2).' '.$n[2].' con sobregiro':''?><?=$n[2]&&$n[1]?' · ':''?><?=$n[1]?psLevelPill(1).' '.$n[1].' en alerta':''?></p><?php endif?>
<div class="dash-rank">
<?php foreach($rows as [$b,$x]): $g=$x['tot']['gasto']; $i=$x['tot']['ingreso'];?><a class="dash-row" href="<?=e(url(['pres'=>(int)$b['id']]))?>">
  <span class="dash-name"><?=e($b['nombre'])?><small><?=e($b['numero'])?><?=$b['estado']==='Cerrado'?' · cerrado':''?></small></span>
  <span class="dash-val"><?=clp($g['real'])?> <small class="ps-inline">de <?=clp($g['plan'])?> · <?=psPct($g['plan']>0?100*$g['real']/$g['plan']:null)?></small><small><?=$b['estado']==='Aprobado'?psLevelPill($x['lvl']):''?><?=$i['plan']>0?' Ingresos '.psPct(100*$i['real']/$i['plan']):''?><?=$x['tiempo']!==null?' · tiempo '.$x['tiempo'].'%':''?></small></span>
  <?=psBar($g['plan'],$g['real'],$x['proyGasto'])?></a>
<?php endforeach?></div></section>
<?php }
/** Resumen en la página de un proyecto de la gestión documental. */
function psProjectStrip(int $pid): void {
    global $canEdit;
    $ids=query("SELECT id FROM ps_budgets WHERE project_id=? ORDER BY estado='Cerrado', id DESC LIMIT 3",[$pid])->fetchAll(PDO::FETCH_COLUMN);
    if(!$ids){ if($canEdit):?><p class="ps-strip-empty"><?=ic('target')?><span>Este proyecto aún no tiene presupuesto.</span> <a href="<?=e(url(['tab'=>'ps_new','proyecto'=>$pid]))?>">Crear presupuesto</a></p><?php endif; return; } ?>
<div class="ps-strip"><?php foreach($ids as $id): $b=psGet((int)$id); $x=psExec($b); $g=$x['tot']['gasto']; $in=$x['tot']['ingreso'];?>
  <a href="<?=e(url(['pres'=>(int)$b['id']]))?>"><span class="ps-strip-h"><?=ic('target')?><b><?=e($b['numero'])?></b> <span class="oc-pill <?=psEstadoClass($b['estado'])?>"><?=e($b['estado'])?></span><?=$b['estado']==='Aprobado'?' '.psLevelPill($x['lvl']):''?></span>
  <span class="ps-strip-n"><?=e($b['nombre'])?></span>
  <span class="ps-strip-v">Gastos <?=clp($g['real'])?> de <?=clp($g['plan'])?> (<?=psPct($g['plan']>0?100*$g['real']/$g['plan']:null)?>)<?=$in['plan']>0?' · Ingresos '.clp($in['real']).' de '.clp($in['plan']):''?></span>
  <?=psBar($g['plan'],$g['real'],$x['proyGasto'])?></a>
<?php endforeach?></div>
<?php }
/** En el detalle de un movimiento: a qué presupuestos imputa (mismo centro y fecha dentro del período). */
function psMovTrace(array $mov): void {
    $cids=array_values(array_filter(array_map(fn($s)=>$s['center_id']===null?0:(int)$s['center_id'],$mov['splits'])));
    if(!$cids) return;
    $ph=implode(',',array_fill(0,count($cids),'?'));
    $list=query("SELECT id FROM ps_budgets WHERE center_id IN ($ph) AND (desde='' OR desde<=?) AND (hasta='' OR hasta>=?) ORDER BY estado='Cerrado', id DESC",[...$cids,$mov['fecha'],$mov['fecha']])->fetchAll(PDO::FETCH_COLUMN);
    if(!$list) return;
    $counted=$mov['rendicion_id']===null || (string)query('SELECT estado FROM cc_rendiciones WHERE id=?',[$mov['rendicion_id']])->fetchColumn()==='Aprobada'; ?>
<section class="card"><h2>Presupuesto</h2><ul class="ps-trace">
<?php foreach($list as $id): $b=psGet((int)$id); $x=psExec($b); $k=$mov['categoria']; $r=$x['rows'][$mov['tipo']][$k]??null;
  $parte=0; foreach($mov['splits'] as $s) if((int)$s['center_id']===(int)$b['center_id']) $parte+=(int)$s['monto'];?>
  <li><a href="<?=e(url(['pres'=>(int)$b['id']]))?>"><b><?=e($b['numero'])?></b> · <?=e($b['nombre'])?></a> <span class="oc-pill <?=psEstadoClass($b['estado'])?>"><?=e($b['estado'])?></span>
  <small><?=$counted?'Imputa':'Imputará al aprobarse la rendición'?> <?=clp($parte)?> a la partida <b><?=e(psCatLabel($k))?></b><?php if($r):?>: <?=clp($r['real'])?> de <?=clp($r['plan'])?> presupuestado<?=$r['plan']>0?' ('.psPct($r['pct']).')':' (no presupuestada)'?><?php if($mov['tipo']==='gasto'):?> <?=psLevelPill($r['lvl'])?><?php endif?><?php endif?>.</small></li>
<?php endforeach?></ul></section>
<?php }

/** Hoja carta: presupuesto con su control de ejecución. */
function psSheet(array $b): void {
    $em=ocCfg()['emisor']; $x=psExec($b); $u=psUnit($b,$x); $centers=ccCenters();
    $pr=$b['project_id']?project((int)$b['project_id']):false; $c=$b['center_id']!==null?($centers[(int)$b['center_id']]??null):null;
    $owner=userName((int)$b['owner']); $appr=userName($b['approved_by']!==null?(int)$b['approved_by']:null);
    $tp=$x['tot']; $res=$tp['ingreso']['plan']-$tp['gasto']['plan']; $resR=$tp['ingreso']['real']-$tp['gasto']['real'];
    $stamp=['Borrador'=>'BORRADOR','Por aprobar'=>'POR APROBAR','Cerrado'=>'CERRADO'][$b['estado']]??''; ?>
<article class="sheet">
<?php if($stamp):?><div class="stamp <?=psEstadoClass($b['estado'])?>"><?=$stamp?></div><?php endif?>
<div class="s-head"><img src="assets/logo-ox3.png" alt="OX3 Capacitaciones"><div class="s-title"><h2>PRESUPUESTO</h2>
  <div class="meta">N° <span class="num"><?=e($b['numero'])?></span><?=(int)$b['version']>1?' · versión '.(int)$b['version']:''?></div>
  <div class="meta">Período&nbsp; <span class="ink"><?=e(psPeriod($b))?></span></div>
  <div class="meta">Emitido&nbsp; <span class="ink"><?=e(ocDate(ocToday()))?></span></div>
</div></div>
<div class="accent"></div>
<div class="s-parties">
  <div><div class="lbl">Empresa</div><div class="strong"><?=e($em['razon'])?></div><div>R.U.T. <?=e($em['rut'])?></div><div><?=e($em['direccion'])?></div></div>
  <div><div class="lbl"><?=e($b['tipo'])?></div><div class="strong"><?=e($b['nombre'])?></div><?php if($b['codigo']!==''):?><div>Código <?=e($b['codigo'])?></div><?php endif?><?php if($b['cliente']!==''):?><div>Cliente: <?=e($b['cliente'])?></div><?php endif?><?php if($pr):?><div>Proyecto: <?=e($pr['name'])?></div><?php endif?></div>
  <div><div class="lbl">Control</div><div>Centro de costo: <?=e($c?ccCenterLabel($c):'sin asignar')?></div><?php if($b['participantes']):?><div>Participantes: <?=(int)$b['participantes']?><?=$b['part_reales']?' (reales '.(int)$b['part_reales'].')':''?></div><?php endif?><?php if($b['horas']>0):?><div>Horas: <?=e(ocQty((float)$b['horas']))?></div><?php endif?></div>
</div>
<div class="s-summary">
  <div class="obj"><div class="lbl">Resumen</div><div><span class="muted">Ingresos presupuestados&nbsp; </span><?=clp($tp['ingreso']['plan'])?> · <span class="muted">reales&nbsp; </span><?=clp($tp['ingreso']['real'])?></div><div><span class="muted">Gastos presupuestados&nbsp; </span><?=clp($tp['gasto']['plan'])?> · <span class="muted">reales&nbsp; </span><?=clp($tp['gasto']['real'])?></div><div><span class="muted">Resultado real&nbsp; </span><b><?=ccClp($resR)?></b><?=$x['tiempo']!==null?' · <span class="muted">tiempo transcurrido</span> '.$x['tiempo'].'%':''?></div></div>
  <div class="tot"><div class="lbl">Resultado presupuestado</div><div class="v"><?=ccClp($res)?></div><div class="n"><?=$tp['ingreso']['plan']>0?'Margen '.round(100*$res/$tp['ingreso']['plan']).'%':'Sin ingresos presupuestados'?></div></div>
</div>
<?php foreach(['ingreso'=>'Ingresos','gasto'=>'Gastos'] as $t=>$lbl): $ls=array_values(array_filter($b['lines'],fn($l)=>$l['tipo']===$t)); if(!$ls) continue;?>
<div class="s-sec"><?=$lbl?> presupuestados</div>
<table class="items"><thead><tr><th class="w15">Partida</th><th>Descripción</th><th class="r w8">Cant.</th><th class="r w11">Precio unit.</th><th class="w15">Base</th><th class="r w15">Monto</th></tr></thead><tbody>
<?php foreach($ls as $l):?><tr><td><?=e($l['categoria'])?></td><td><?=e($l['descripcion'])?></td><td class="r"><?=e(ocQty((float)$l['cantidad']))?><?=$l['unidad']!==''?' '.e($l['unidad']):''?></td><td class="r"><?=clp((int)$l['precio'])?></td><td class="muted"><?=e(PS_BASES[$l['base']]??'')?><?=$l['ret']>0?'<br>precio líquido + ret. '.e(psRetLabel((float)$l['ret'])):''?></td><td class="r"><?=clp((int)$l['monto'])?><?php if($l['ret']>0): [$lq,$rt]=psRetSplit($l);?><br><span class="muted">líq. <?=clp($lq)?> · ret. <?=clp($rt)?></span><?php endif?></td></tr><?php endforeach?>
</tbody><tfoot><tr class="total"><td colspan="4"></td><td class="r k">Total <?=mb_strtolower($lbl)?></td><td class="r v"><?=clp($tp[$t]['plan'])?></td></tr></tfoot></table>
<?php endforeach?>
<?php $retTot=array_sum(array_map(fn($l)=>psRetSplit($l)[1],$b['lines'])); if($retTot>0):?><div class="s-conds"><div><b>Retención de honorarios incluida en los gastos</b>&nbsp; <?=clp($retTot)?> <span class="muted">(se declara y paga al SII en el F29 del mes siguiente al pago de cada boleta)</span></div></div><?php endif?>
<div class="s-sec">Ejecución por partida</div>
<table class="items"><thead><tr><th>Partida</th><th class="r w15">Presupuesto</th><th class="r w15">Real</th><th class="r w15">Por ejecutar</th><th class="r w11">Ejecución</th></tr></thead><tbody>
<?php foreach(['ingreso'=>'Ingreso','gasto'=>'Gasto'] as $t=>$lbl) foreach($x['rows'][$t] as $k=>$r):?><tr><td><span class="muted"><?=$lbl?> ·</span> <?=e(psCatLabel((string)$k))?><?=$r['plan']===0?' <span class="muted">(no presupuestada)</span>':''?></td><td class="r"><?=clp($r['plan'])?></td><td class="r"><?=clp($r['real'])?></td><td class="r"><?=ccClp($r['plan']-$r['real'])?></td><td class="r"><?=psPct($r['pct'])?></td></tr><?php endforeach?>
<?php if(!$x['rows']['ingreso'] && !$x['rows']['gasto']):?><tr><td colspan="5" class="muted c">Sin partidas</td></tr><?php endif?>
</tbody></table>
<?php if($x['comp']||$x['porFacturar']||$tp['gasto']['rev']):?><div class="s-conds" style="margin-top:6px"><?php if($tp['gasto']['rev']):?><div><b>En rendiciones por aprobar</b>&nbsp; <?=clp($tp['gasto']['rev'])?></div><?php endif?><?php if($x['comp']):?><div><b>Comprometido en OC</b>&nbsp; <?=clp($x['comp'])?></div><?php endif?><?php if($x['porFacturar']):?><div><b>Ventas aceptadas por facturar</b>&nbsp; <?=clp($x['porFacturar'])?></div><?php endif?><div><b>Disponible para gastos</b>&nbsp; <?=ccClp($x['disponible'])?></div></div><?php endif?>
<?php if($b['participantes']>0 || $b['horas']>0):?><div class="s-sec">Indicadores</div><div class="s-conds">
  <?php if($u['costoPart']!==null):?><div><b>Costo por participante</b>&nbsp; <?=clp($u['costoPart'])?><?=$u['costoPartReal']!==null?' · real '.clp($u['costoPartReal']):''?></div><?php endif?>
  <?php if($u['costoHora']!==null):?><div><b>Costo por hora</b>&nbsp; <?=clp($u['costoHora'])?><?=$u['costoHoraReal']!==null?' · real '.clp($u['costoHoraReal']):''?></div><?php endif?>
  <?php if($u['equilibrio']!==null):?><div><b>Punto de equilibrio</b>&nbsp; <?=$u['equilibrio']?> participante<?=$u['equilibrio']===1?'':'s'?></div><?php endif?>
  <?php if($u['precioMin']!==null && $u['ingresoPart']>0):?><div><b>Ingreso mínimo por participante</b>&nbsp; <?=clp(max(0,$u['precioMin']))?></div><?php endif?>
</div><?php endif?>
<?php if($b['obs']!==''):?><div class="s-obs"><b>Observaciones</b>&nbsp; <?=e($b['obs'])?></div><?php endif?>
<div class="s-sec">Firmas</div>
<div class="s-sign cot-sign"><div><div class="line"><b><?=e($owner?:' ')?></b></div><div class="rol">Elabora</div></div><div><div class="line"><b><?=$appr!==''?e($appr):'&nbsp;'?></b></div><div class="muted"><?=$b['approved']&&$b['estado']!=='Borrador'?'Aprobado el '.e(ccDay($b['approved'])):''?></div><div class="rol">Aprueba</div></div></div>
<div class="s-foot"><span><?=e($em['razon'])?> · R.U.T. <?=e($em['rut'])?></span><span>Valores en pesos chilenos, IVA incluido · Real según contabilidad en gestion.ox3.cl</span></div>
</article>
<?php }

/** Descargas GET: hoja para imprimir y CSV del presupuesto con su ejecución. */
function psGetRequests(): void {
    global $user;
    if(!isset($_GET['ps_print']) && !isset($_GET['ps_csv'])) return;
    if(!$user) stop(403,'Debes iniciar sesión.');
    $b=psGet((int)($_GET['ps_print']??$_GET['ps_csv'])); if(!$b) stop(404,'Presupuesto no encontrado.');
    if(isset($_GET['ps_print'])) {
        audit('Impresión de presupuesto',$b['numero']); ?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($b['numero'])?> · Presupuesto OX3</title><link rel="stylesheet" href="assets/style.css?v=10"></head>
<body class="print-page"><div class="print-bar no-print"><a class="btn ghost small" href="<?=e('index.php?pres='.$b['id'])?>">← Volver</a><span>Para imprimir o guardar como PDF usa <b>Ctrl + P</b> (<b>Cmd + P</b> en Mac), tamaño Carta y márgenes «Ninguno».</span></div>
<div class="sheet-wrap"><?php psSheet($b)?></div></body></html><?php exit;
    }
    $x=psExec($b);
    audit('Exportación de presupuesto',$b['numero']);
    session_write_close();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="presupuesto_'.preg_replace('/[^A-Za-z0-9_-]/','_',$b['numero']).'_'.ocToday().'.csv"');
    $out=fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF");
    $put=fn(array $cells)=>fputcsv($out,array_map(fn($v)=>is_string($v)&&preg_match('/^[=+\-@\t\r]/',$v)?"'".$v:$v,$cells),';','"','');
    $put(['Sección','Tipo','Partida','Descripción','Cantidad','Unidad','Base','Precio unitario','Presupuestado','Real','En revisión','Diferencia','% ejecución','Líquido','Retención honorarios']);
    foreach($b['lines'] as $l) $put(['Detalle',CC_TIPOS[$l['tipo']],$l['categoria'],$l['descripcion'],str_replace('.',',',(string)(float)$l['cantidad']),$l['unidad'],PS_BASES[$l['base']]??'',(int)$l['precio'],(int)$l['monto'],'','','','',...($l['ret']>0?psRetSplit($l):['',''])]);
    foreach(['ingreso','gasto'] as $t) foreach($x['rows'][$t] as $k=>$r) $put(['Ejecución',CC_TIPOS[$t],psCatLabel((string)$k),$r['plan']===0?'No presupuestada':'','','','','',$r['plan'],$r['real'],$r['rev'],$r['plan']-$r['real'],$r['pct']===null?'':str_replace('.',',',(string)round($r['pct'],1))]);
    foreach($x['oc'] as $o) $put(['Comprometido (OC)','Gasto','',$o['numero'].' · '.$o['prov_razon'].' · '.$o['obj_titulo'].' ('.$o['estado'].')','','','','',(int)$o['total'],'','','','']);
    foreach($x['cot'] as $q) $put(['Por facturar (cotización)','Ingreso','',$q['numero'].' · '.$q['cli_razon'].' · '.$q['obj_titulo'],'','','','',(int)$q['total'],'','','','']);
    fclose($out); exit;
}
