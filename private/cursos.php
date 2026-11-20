<?php
// Módulo de cursos: diseño (ficha y malla), costos directos, costos fijos de la empresa prorrateados, evaluación de
// viabilidad, historial de versiones y cotización en un clic. Se incluye desde app.php después de presupuesto.php
// (usa ocText, ocNum, ocList, ocDateIn, ocToday, ocDate, ocQty, clp, ccClp, ccMoney, PS_BASES, psBrutoUnit, psRetRate,
// psRetLabel, psPct y las funciones de cotizaciones cotCfg, cotNormalize, cotNextNumero, cotStore, cotBumpSiguiente, cotUpsertClient).
$db->exec('CREATE TABLE IF NOT EXISTS cu_courses(id INTEGER PRIMARY KEY, codigo TEXT NOT NULL UNIQUE, nombre TEXT NOT NULL, area TEXT NOT NULL DEFAULT \'\', estado TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1, horas REAL NOT NULL DEFAULT 0, participantes INTEGER NOT NULL DEFAULT 0, precio INTEGER NOT NULL DEFAULT 0, costo INTEGER NOT NULL DEFAULT 0, margen REAL, veredicto INTEGER NOT NULL DEFAULT 0, data TEXT NOT NULL, owner INTEGER REFERENCES users(id), created TEXT NOT NULL, updated TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS cu_versions(id INTEGER PRIMARY KEY, course_id INTEGER NOT NULL REFERENCES cu_courses(id) ON DELETE CASCADE, version INTEGER NOT NULL, nota TEXT NOT NULL DEFAULT \'\', precio INTEGER NOT NULL DEFAULT 0, costo INTEGER NOT NULL DEFAULT 0, margen REAL, veredicto INTEGER NOT NULL DEFAULT 0, data TEXT NOT NULL, owner INTEGER REFERENCES users(id), created TEXT NOT NULL, UNIQUE(course_id, version));
CREATE TABLE IF NOT EXISTS cu_fixed(id INTEGER PRIMARY KEY, nombre TEXT NOT NULL, monto INTEGER NOT NULL DEFAULT 0, activo INTEGER NOT NULL DEFAULT 1, orden INTEGER NOT NULL DEFAULT 0);
CREATE TABLE IF NOT EXISTS cu_settings(key TEXT PRIMARY KEY, value TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS cu_quotes(id INTEGER PRIMARY KEY, course_id INTEGER NOT NULL REFERENCES cu_courses(id) ON DELETE CASCADE, version INTEGER NOT NULL, cot_id INTEGER NOT NULL, created TEXT NOT NULL);
CREATE INDEX IF NOT EXISTS cu_versions_course ON cu_versions(course_id);
CREATE INDEX IF NOT EXISTS cu_quotes_course ON cu_quotes(course_id);
CREATE INDEX IF NOT EXISTS cu_quotes_cot ON cu_quotes(cot_id);');

/** El módulo se usa solo en las empresas que dictan cursos (OX3). Las tablas se crean igual en todas, así nunca se lee la de otra empresa. */
function cuOn(): bool { return !empty(empresa()['cursos']); }
const CU_ESTADOS=['En diseño','Activo','Archivado'];
/** Veredicto del evaluador: 0 faltan datos, 1 no viable, 2 viable con ajustes, 3 viable. */
const CU_VEREDICTOS=['Incompleto','No viable','Viable con ajustes','Viable'];
/** Cómo se reparten los costos fijos mensuales entre los cursos. */
const CU_METODOS=['curso'=>'Partes iguales por curso','hora'=>'Según las horas de cada curso'];

function cuDefaults(): array { return ['metodo'=>'curso','cursos_mes'=>4,'horas_mes'=>120,'margen_obj'=>30,'margen_min'=>15,'redondeo'=>1000]; }
// Primera carga del módulo en cada empresa: configuración y gastos fijos típicos en $ 0, para completar.
if (query('SELECT COUNT(*) FROM cu_settings')->fetchColumn()==0) {
    query('INSERT OR IGNORE INTO cu_settings(key,value) VALUES(?,?)',['cfg',json_encode(cuDefaults(),JSON_UNESCAPED_UNICODE)]);
    if (query('SELECT COUNT(*) FROM cu_fixed')->fetchColumn()==0)
        foreach(['Contadora','Administración','Arriendo de oficina','Software y plataformas','Servicios básicos e internet','Difusión y marketing'] as $i=>$n) query('INSERT INTO cu_fixed(nombre,monto,activo,orden) VALUES(?,0,1,?)',[$n,$i]);
}
function cuCfg(): array {
    $c=json_decode((string)query('SELECT value FROM cu_settings WHERE key=?',['cfg'])->fetchColumn(),true);
    return is_array($c) ? $c+cuDefaults() : cuDefaults();
}
function cuFixedItems(bool $activeOnly=true): array { return query('SELECT * FROM cu_fixed'.($activeOnly?' WHERE activo=1 AND monto>0':'').' ORDER BY orden, id')->fetchAll(PDO::FETCH_ASSOC); }

/**
 * Parte de los costos fijos mensuales que carga un curso de $horas horas, con la configuración vigente.
 * Método «curso»: cada gasto mensual ÷ cursos al mes. Método «hora»: cada gasto ÷ horas de clase al mes × horas del curso.
 * Se guarda una copia en cada versión del curso, así las versiones anteriores conservan los valores con que se evaluaron.
 */
function cuFixedSnapshot(float $horas): array {
    $cfg=cuCfg(); $m=isset(CU_METODOS[$cfg['metodo']])?$cfg['metodo']:'curso';
    $base=$m==='hora' ? max(1.0,(float)$cfg['horas_mes']) : max(1.0,(float)$cfg['cursos_mes']);
    $factor=$m==='hora' ? $horas/$base : 1/$base;
    $items=[]; $tot=0; $mens=0;
    foreach(cuFixedItems() as $f){ $q=(int)round((int)$f['monto']*$factor); $items[]=['nombre'=>$f['nombre'],'mensual'=>(int)$f['monto'],'cuota'=>$q]; $tot+=$q; $mens+=(int)$f['monto']; }
    return ['metodo'=>$m,'base'=>$base,'horas'=>$horas,'items'=>$items,'total'=>$tot,'mensual'=>$mens,'fecha'=>ocToday()];
}
function cuFixedNormalize(mixed $f): array {
    $f=is_array($f)?$f:[]; $items=[];
    foreach(array_slice(is_array($f['items']??null)?$f['items']:[],0,40) as $i) if(is_array($i)) $items[]=['nombre'=>ocText($i['nombre']??'',80),'mensual'=>(int)ocNum($i['mensual']??0),'cuota'=>(int)ocNum($i['cuota']??0)];
    return ['metodo'=>isset(CU_METODOS[$f['metodo']??''])?$f['metodo']:'curso','base'=>(float)ocNum($f['base']??0),'horas'=>(float)ocNum($f['horas']??0),'items'=>$items,
        'total'=>array_sum(array_column($items,'cuota')),'mensual'=>array_sum(array_column($items,'mensual')),'fecha'=>ocDateIn($f['fecha']??'')];
}
/** Texto del prorrateo guardado, por ejemplo «1/4 del mes (partes iguales entre 4 cursos)». */
function cuFixedText(array $f): string {
    if(!$f['items']) return 'Sin costos fijos configurados';
    $b=ocQty($f['base']);
    return $f['metodo']==='hora' ? ocQty($f['horas']).' h de '.$b.' h de clase al mes' : ((float)$f['base']<=1 ? 'todo el mes (1 curso al mes)' : '1/'.$b.' del mes (partes iguales entre '.$b.' cursos)');
}

/** Limpia un curso con la forma {nombre, ..., modulos[], costos[], fijos{}}. */
function cuNormalize(array $r): array {
    $t=fn(string $k, int $n): string => ocText($r[$k]??'',$n);
    $int=fn(string $k): int => max(0,min(100000,(int)round(ocNum($r[$k]??0))));
    $mods=[]; foreach(array_slice(is_array($r['modulos']??null)?$r['modulos']:[],0,40) as $m){ if(!is_array($m)) continue;
        $x=['nombre'=>ocText($m['nombre']??'',120),'teo'=>round(min(10000.0,ocNum($m['teo']??0)),1),'pra'=>round(min(10000.0,ocNum($m['pra']??0)),1),'contenidos'=>ocText($m['contenidos']??'',600)];
        if($x['nombre']===''&&$x['teo']<=0&&$x['pra']<=0&&$x['contenidos']==='') continue;
        if($x['nombre']==='') $x['nombre']='Módulo '.(count($mods)+1);
        $mods[]=$x; }
    $cost=[]; foreach(array_slice(is_array($r['costos']??null)?$r['costos']:[],0,60) as $l){ if(!is_array($l)) continue;
        $precio=ccMoney($l['precio']??''); if($precio<=0) continue;
        $cr=trim((string)(is_scalar($l['cant']??'')?$l['cant']??'':'')); $cant=$cr===''?1.0:round(ocNum($cr),3); if($cant<=0) continue;
        $ret=(float)($l['ret']??0); $ret=$ret>0&&$ret<100?round($ret,2):0.0;
        $cost[]=['desc'=>ocText($l['desc']??'',120),'cant'=>$cant,'unidad'=>ocText($l['unidad']??'',30),'precio'=>min($precio,1000000000),'base'=>isset(PS_BASES[$l['base']??''])?$l['base']:'fijo','ret'=>$ret]; }
    return ['nombre'=>$t('nombre',120),'area'=>$t('area',80),'modalidad'=>$t('modalidad',40),'sence'=>$t('sence',40),'dirigido'=>$t('dirigido',300),'objetivo'=>$t('objetivo',600),
        'requisitos'=>$t('requisitos',300),'evaluacion'=>$t('evaluacion',300),'certificacion'=>$t('certificacion',200),'relator'=>$t('relator',300),
        'horas'=>round(min(10000.0,ocNum($r['horas']??0)),1),'part'=>$int('part'),'part_min'=>$int('part_min'),'part_max'=>$int('part_max'),
        'precio'=>min(ccMoney($r['precio']??0),1000000000),'modulos'=>$mods,'costos'=>$cost,'fijos'=>cuFixedNormalize($r['fijos']??[])];
}
/** Lee el formulario del editor (sin JavaScript: filas paralelas). */
function cuFromPost(): array {
    $r=['modulos'=>[],'costos'=>[]];
    foreach(['nombre','area','modalidad','sence','dirigido','objetivo','requisitos','evaluacion','certificacion','relator','horas','part','part_min','part_max','precio'] as $k) $r[$k]=post($k);
    [$mn,$mt,$mp,$mc]=[ocList('md_nombre'),ocList('md_teo'),ocList('md_pra'),ocList('md_cont')];
    foreach($mn as $i=>$n) $r['modulos'][]=['nombre'=>$n,'teo'=>$mt[$i]??'','pra'=>$mp[$i]??'','contenidos'=>$mc[$i]??''];
    [$d,$c,$u,$p,$b,$rt]=[ocList('ln_desc'),ocList('ln_cant'),ocList('ln_unidad'),ocList('ln_precio'),ocList('ln_base'),ocList('ln_ret')]; $rate=psRetRate();
    foreach($d as $i=>$x) $r['costos'][]=['desc'=>$x,'cant'=>$c[$i]??'','unidad'=>$u[$i]??'','precio'=>$p[$i]??'','base'=>$b[$i]??'fijo','ret'=>($rt[$i]??'')==='liq'?$rate:0];
    return $r;
}
/** Horas del curso: las indicadas o, si no se indicaron, la suma de los módulos. */
function cuModHoras(array $c): float { return (float)array_sum(array_map(fn($m)=>$m['teo']+$m['pra'],$c['modulos'])); }
function cuHoras(array $c): float { return $c['horas']>0 ? (float)$c['horas'] : cuModHoras($c); }
/** Redondea hacia arriba al múltiplo configurado (precio sugerido). */
function cuRoundUp(float $v): int { $r=(int)cuCfg()['redondeo']; return $r>0 ? (int)(ceil($v/$r-1e-9)*$r) : (int)ceil($v-1e-9); }

/**
 * Evaluación económica con $P participantes (por defecto, los previstos).
 * Costos directos: cantidad × precio (bruto si se ingresó líquido con retención) × participantes u horas según su base.
 * Costos fijos: la parte prorrateada guardada en la versión. Punto de equilibrio = costos que no dependen de los
 * participantes ÷ (precio − costo variable por participante).
 */
function cuEval(array $c, ?int $P=null): array {
    $cfg=cuCfg(); $P=$P??(int)$c['part']; $H=cuHoras($c);
    $lines=[]; $dir=0.0; $var=0.0;
    foreach($c['costos'] as $l){
        $u=$l['cant']*psBrutoUnit((int)$l['precio'],(float)$l['ret']);
        $m=match($l['base']){ 'part'=>$u*$P, 'hora'=>$u*$H, 'parthora'=>$u*$P*$H, default=>$u };
        $vp=match($l['base']){ 'part'=>$u, 'parthora'=>$u*$H, default=>0.0 };
        $lines[]=$l+['monto'=>(int)round($m),'unit'=>$vp]; $dir+=round($m); $var+=$vp;
    }
    $fix=(int)$c['fijos']['total']; $dir=(int)$dir; $costo=$dir+$fix; $noVar=$costo-$var*$P;
    $obj=min(95.0,max(0.0,(float)$cfg['margen_obj'])); $precio=(int)$c['precio']; $ing=$precio*$P;
    return ['P'=>$P,'H'=>$H,'lines'=>$lines,'directo'=>$dir,'fijos'=>$fix,'costo'=>$costo,'varPart'=>$var,'noVar'=>$noVar,
        'precio'=>$precio,'ingreso'=>$ing,'margen'=>$ing-$costo,'pct'=>$ing>0?100*($ing-$costo)/$ing:null,
        'costoPart'=>$P>0?$costo/$P:null,'costoHora'=>$H>0?$costo/$H:null,
        'precioMin'=>$P>0?(int)ceil($costo/$P):null,
        'sugerido'=>$P>0&&$costo>0?cuRoundUp($costo/$P/(1-$obj/100)):null,
        'equilibrio'=>$precio>$var ? max(0,(int)ceil($noVar/($precio-$var)-1e-9)) : null];
}
/**
 * Observaciones del evaluador: cada una con nivel 0 (bien), 1 (revisar) o 2 (problema), área y texto.
 * Devuelve [veredicto, observaciones]. Sin participantes, horas, costos o precio el veredicto es «Incompleto».
 */
function cuAssess(array $c, array $ev): array {
    $cfg=cuCfg(); $obj=(float)$cfg['margen_obj']; $min=(float)$cfg['margen_min']; $o=[];
    $add=function(int $lvl, string $area, string $txt, array $fix=[]) use(&$o): void { $o[]=['lvl'=>$lvl,'area'=>$area,'txt'=>$txt,'fix'=>$fix]; };
    $inc=false;
    // Diseño del curso
    $mh=cuModHoras($c); $H=$ev['H'];
    if($c['objetivo']==='') $add(1,'Diseño','Falta el objetivo general del curso.'); else $add(0,'Diseño','Objetivo general definido.');
    if(!$c['modulos']) $add(1,'Diseño','Aún no tiene malla: agrega los módulos con sus horas y contenidos.');
    else {
        $add(0,'Diseño',count($c['modulos']).' módulo'.(count($c['modulos'])===1?'':'s').' · '.ocQty($mh).' h ('.ocQty((float)array_sum(array_column($c['modulos'],'teo'))).' teóricas y '.ocQty((float)array_sum(array_column($c['modulos'],'pra'))).' prácticas).');
        $sin=count(array_filter($c['modulos'],fn($m)=>$m['contenidos']===''));
        if($sin) $add(1,'Diseño',$sin.' módulo'.($sin===1?' no tiene':'s no tienen').' contenidos escritos.');
        if($c['horas']>0 && $mh>0 && abs($mh-$c['horas'])>0.05) $add(1,'Diseño','Los módulos suman '.ocQty($mh).' h y el curso indica '.ocQty((float)$c['horas']).' h.');
        if(count(array_filter($c['modulos'],fn($m)=>$m['teo']+$m['pra']<=0))) $add(1,'Diseño','Hay módulos sin horas.');
    }
    if($H<=0){ $add(2,'Diseño','Indica las horas del curso (o las de cada módulo).'); $inc=true; }
    if($c['evaluacion']==='') $add(1,'Diseño','Define cómo se evalúa y aprueba el curso.');
    if($c['part']<=0){ $add(2,'Participantes','Indica los participantes previstos.'); $inc=true; }
    else {
        if($c['part_min']>0 && $c['part_min']>$c['part']) $add(1,'Participantes','El mínimo para dictar ('.$c['part_min'].') supera a los previstos ('.$c['part'].').');
        if($c['part_max']>0 && $c['part']>$c['part_max']) $add(1,'Participantes','Los previstos ('.$c['part'].') superan el cupo máximo ('.$c['part_max'].').');
    }
    // Economía
    if(!$c['costos']){ $add(2,'Costos','Agrega los costos directos (relator, sala, materiales…).'); $inc=true; }
    if($c['fijos']['mensual']<=0) $add(1,'Costos','No hay costos fijos configurados: el margen no descuenta contadora, administración ni otros gastos de la empresa.');
    else $add(0,'Costos','Incluye '.clp($ev['fijos']).' de costos fijos de la empresa ('.cuFixedText($c['fijos']).').');
    if($ev['precio']<=0){ $add(2,'Precio','Define el precio por participante.'.($ev['sugerido']?' Sugerido: '.clp($ev['sugerido']).' para un margen de '.ocQty($obj).'%.':''),$ev['sugerido']?['precio'=>$ev['sugerido']]:[]); $inc=true; }
    elseif($c['part']>0 && $ev['costo']>0) {
        $p=$ev['pct']; $fix=$ev['sugerido']&&$ev['sugerido']>$ev['precio']?['precio'=>$ev['sugerido']]:[];
        if($p>=$obj) $add(0,'Precio','Margen de '.psPct($p).' ('.ccClp($ev['margen']).'), sobre el objetivo de '.ocQty($obj).'%.');
        elseif($p>=$min) $add(1,'Precio','Margen de '.psPct($p).': bajo el objetivo de '.ocQty($obj).'%.'.($fix?' Precio para llegarle: '.clp($fix['precio']).'.':''),$fix);
        else $add(2,'Precio',($ev['margen']<0?'Pierde '.clp(-$ev['margen']).' con '.$c['part'].' participantes':'Margen de '.psPct($p).', bajo el mínimo de '.ocQty($min).'%').'.'.($fix?' Precio para el objetivo: '.clp($fix['precio']).'.':''),$fix);
        $eq=$ev['equilibrio'];
        if($eq===null) $add(2,'Participantes','El precio no cubre el costo variable por participante ('.clp($ev['varPart']).').');
        elseif($eq>$c['part']) $add(2,'Participantes','Se necesitan '.$eq.' participantes para no perder y hay '.$c['part'].' previstos.');
        elseif($c['part_min']>0 && $eq>$c['part_min']) $add(1,'Participantes','Con el mínimo de '.$c['part_min'].' participantes se pierde: el equilibrio está en '.$eq.'.');
        else $add(0,'Participantes','Punto de equilibrio: '.$eq.' participante'.($eq===1?'':'s').($c['part_min']>0?' (mínimo para dictar: '.$c['part_min'].')':'').'.');
    }
    usort($o,fn($a,$b)=>$b['lvl']<=>$a['lvl']);
    $max=$o?max(array_column($o,'lvl')):0;
    $v=$inc ? 0 : ($max===2 ? 1 : ($max===1 ? 2 : 3));
    return [$v,$o];
}
function cuVerdictPill(int $v): string { return '<span class="ps-lvl cu-v'.$v.'">'.e(CU_VEREDICTOS[$v]??'').'</span>'; }
function cuEstadoClass(string $s): string { return ['En diseño'=>'st-borrador','Activo'=>'st-aprobada','Archivado'=>'st-cerrado'][$s]??'st-borrador'; }

function cuGet(int $id): array|false {
    $row=$id>0 ? query('SELECT * FROM cu_courses WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC) : false;
    if(!$row) return false;
    $row['c']=cuNormalize(json_decode($row['data'],true)?:[]);
    return $row;
}
function cuVersion(int $courseId, int $v): array|false {
    $row=query('SELECT * FROM cu_versions WHERE course_id=? AND version=?',[$courseId,$v])->fetch(PDO::FETCH_ASSOC);
    if(!$row) return false;
    $row['c']=cuNormalize(json_decode($row['data'],true)?:[]);
    return $row;
}
function cuNextCodigo(): string {
    $n=1; foreach(query("SELECT codigo FROM cu_courses WHERE codigo LIKE 'CUR-%'")->fetchAll(PDO::FETCH_COLUMN) as $k){ $r=substr($k,4); if(ctype_digit($r)) $n=max($n,(int)$r+1); }
    return 'CUR-'.str_pad((string)$n,4,'0',STR_PAD_LEFT);
}
/** Los costos fijos cambiaron desde que se guardó la versión (misma cantidad de horas, configuración vigente). */
function cuFixedOutdated(array $c): bool {
    $now=cuFixedSnapshot(cuHoras($c));
    return $now['total']!==(int)$c['fijos']['total'] || $now['mensual']!==(int)$c['fijos']['mensual'];
}
/**
 * Guarda una versión nueva del curso (o lo crea). Cada guardado queda en el historial y no se modifica después.
 * $freshFixed: recalcula los costos fijos con la configuración vigente. Devuelve [id, versión, hubo cambios].
 */
function cuStore(array $c, int $id, string $nota, bool $freshFixed=true): array {
    global $db, $user;
    $db->exec('BEGIN IMMEDIATE');
    try {
        $old=$id>0 ? query('SELECT version,data FROM cu_courses WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC) : false;
        if($id>0 && !$old) throw new RuntimeException('El curso no existe.');
        $prev=$old ? cuNormalize(json_decode($old['data'],true)?:[]) : null;
        if($freshFixed){
            $c['fijos']=cuFixedSnapshot(cuHoras(cuNormalize($c)));
            // Mismos valores que la versión anterior: se conserva su fecha (no es un cambio).
            if($prev){ $a=$prev['fijos']; $b=cuFixedNormalize($c['fijos']); unset($a['fecha'],$b['fecha']); if($a==$b) $c['fijos']=$prev['fijos']; }
        }
        $c=cuNormalize($c); $ev=cuEval($c); [$v]=cuAssess($c,$ev); $now=gmdate('c');
        $json=json_encode($c,JSON_UNESCAPED_UNICODE);
        if($old){
            if($prev==$c){ $db->exec('ROLLBACK'); return [$id,(int)$old['version'],false]; }
            $ver=(int)$old['version']+1;
            query('UPDATE cu_courses SET nombre=?,area=?,version=?,horas=?,participantes=?,precio=?,costo=?,margen=?,veredicto=?,data=?,updated=? WHERE id=?',[$c['nombre'],$c['area'],$ver,$ev['H'],$c['part'],$c['precio'],$ev['costo'],$ev['pct'],$v,$json,$now,$id]);
        } else {
            $ver=1;
            query('INSERT INTO cu_courses(codigo,nombre,area,estado,version,horas,participantes,precio,costo,margen,veredicto,data,owner,created,updated) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[cuNextCodigo(),$c['nombre'],$c['area'],'En diseño',1,$ev['H'],$c['part'],$c['precio'],$ev['costo'],$ev['pct'],$v,$json,$user['id'],$now,$now]);
            $id=(int)$db->lastInsertId();
        }
        query('INSERT INTO cu_versions(course_id,version,nota,precio,costo,margen,veredicto,data,owner,created) VALUES(?,?,?,?,?,?,?,?,?,?)',[$id,$ver,ocText($nota,300),$c['precio'],$ev['costo'],$ev['pct'],$v,$json,$user['id'],$now]);
        $db->exec('COMMIT');
    } catch(Throwable $ex) { $db->exec('ROLLBACK'); throw $ex; }
    return [$id,$ver,true];
}
/** Curso desde el que se generó una cotización (para mostrarlo en la cotización). */
function cuQuoteOrigin(int $cotId): array|false {
    return query('SELECT c.id, c.codigo, c.nombre, q.version FROM cu_quotes q JOIN cu_courses c ON c.id=q.course_id WHERE q.cot_id=? ORDER BY q.id DESC LIMIT 1',[$cotId])->fetch(PDO::FETCH_ASSOC);
}

// ---------- Acciones POST ----------
function cuPost(string $action): void {
    global $db, $user, $canEdit, $isAdmin;
    if (str_starts_with($action,'cu_import') && $canEdit) cuImportPost($action);
    if ($action==='cu_save' && $canEdit) {
        $id=(int)post('id'); $old=$id>0?cuGet($id):null;
        if($id>0 && !$old) throw new RuntimeException('El curso no existe.');
        $c=cuNormalize(cuFromPost());
        if($c['nombre']==='') throw new RuntimeException('Escribe el nombre del curso.');
        [$id,$ver,$changed]=cuStore($c,$id,post('nota')!==''?post('nota'):($old?'Edición':'Creación del curso'));
        if($changed) audit($old?'Edición de curso':'Creación de curso',$c['nombre'].' · versión '.$ver);
        $msg=!$changed ? 'Sin cambios: se mantiene la versión '.$ver.'.' : ($old ? 'Curso guardado como versión '.$ver.'.' : 'Curso creado. Revisa la evaluación.');
        go($msg,post('after')==='edit'?['curso'=>$id,'edit'=>1]:['curso'=>$id]);
    }
    if ($action==='cu_precio' && $canEdit) {
        $cu=cuGet((int)post('id')); if(!$cu) throw new RuntimeException('El curso no existe.');
        $p=ccMoney(post('precio')); if($p<=0) throw new RuntimeException('Precio no válido.');
        $c=$cu['c']; $c['precio']=$p;
        [$id,$ver]=cuStore($c,(int)$cu['id'],'Precio ajustado a '.clp($p).' (sugerido por el evaluador)',false);
        audit('Precio de curso',$cu['nombre'].' · '.clp($p).' · versión '.$ver); go('Precio actualizado a '.clp($p).' por participante (versión '.$ver.').',['curso'=>$id]);
    }
    if ($action==='cu_refresh' && $canEdit) {
        $cu=cuGet((int)post('id')); if(!$cu) throw new RuntimeException('El curso no existe.');
        [$id,$ver,$ch]=cuStore($cu['c'],(int)$cu['id'],'Costos fijos actualizados a la configuración vigente');
        if($ch) audit('Actualización de costos fijos de curso',$cu['nombre'].' · versión '.$ver);
        go($ch?'Costos fijos actualizados (versión '.$ver.'). Revisa el margen.':'Los costos fijos ya estaban al día.',['curso'=>$id]);
    }
    if ($action==='cu_restore' && $canEdit) {
        $cu=cuGet((int)post('id')); if(!$cu) throw new RuntimeException('El curso no existe.');
        $v=cuVersion((int)$cu['id'],(int)post('ver')); if(!$v) throw new RuntimeException('Esa versión no existe.');
        [$id,$ver,$ch]=cuStore($v['c'],(int)$cu['id'],'Restaurada la versión '.(int)$v['version'],false);
        if($ch) audit('Restauración de versión de curso',$cu['nombre'].' · versión '.(int)$v['version'].' → '.$ver);
        go($ch?'Se restauró la versión '.(int)$v['version'].' como versión '.$ver.'. El historial se conserva completo.':'Esa versión es igual a la vigente.',['curso'=>$id]);
    }
    if ($action==='cu_estado' && $canEdit) {
        $cu=cuGet((int)post('id')); if(!$cu) throw new RuntimeException('El curso no existe.');
        $s=post('estado'); if(!in_array($s,CU_ESTADOS,true)) throw new RuntimeException('Estado no válido.');
        query('UPDATE cu_courses SET estado=?,updated=? WHERE id=?',[$s,gmdate('c'),$cu['id']]);
        audit('Estado de curso',$cu['nombre'].' → '.$s); go('Curso «'.$cu['nombre'].'»: '.$s.'.',['curso'=>(int)$cu['id']]);
    }
    if ($action==='cu_dup' && $canEdit) {
        $cu=cuGet((int)post('id')); if(!$cu) throw new RuntimeException('El curso no existe.');
        $c=$cu['c']; $c['nombre']=shortText($c['nombre'].' (copia)',120);
        [$id]=cuStore($c,0,'Copia de '.$cu['codigo'].' versión '.(int)$cu['version']);
        audit('Duplicación de curso',$cu['codigo'].' → '.$c['nombre']); go('Copia creada. Ajusta el nombre y lo que cambie.',['curso'=>$id,'edit'=>1]);
    }
    if ($action==='cu_delete' && $isAdmin) {
        $cu=cuGet((int)post('id')); if(!$cu) go('',['tab'=>'cu']);
        query('DELETE FROM cu_quotes WHERE course_id=?',[$cu['id']]); query('DELETE FROM cu_versions WHERE course_id=?',[$cu['id']]); query('DELETE FROM cu_courses WHERE id=?',[$cu['id']]);
        audit('Eliminación de curso',$cu['codigo'].' · '.$cu['nombre']); go('Curso eliminado con su historial. Las cotizaciones generadas no se modifican.',['tab'=>'cu']);
    }
    if ($action==='cu_cot' && $canEdit) {
        $cu=cuGet((int)post('id')); if(!$cu) throw new RuntimeException('El curso no existe.');
        $c=$cu['c']; $H=cuHoras($c);
        $P=max(0,min(100000,(int)ccMoney(post('participantes')))); if($P<=0) throw new RuntimeException('Indica cuántos participantes se cotizan.');
        $precio=ccMoney(post('precio')); if($precio<=0) $precio=(int)$c['precio'];
        if($precio<=0) throw new RuntimeException('El curso no tiene precio: defínelo o escribe uno para esta cotización.');
        $modo=post('modo')==='cerrado'?'cerrado':'part';
        $cfg=cotCfg();
        $desc='Curso «'.$c['nombre'].'»'.($H>0?' · '.ocQty($H).' horas':'').($c['modalidad']!==''?' · '.mb_strtolower($c['modalidad']):'').($c['sence']!==''?' · código '.$c['sence']:'');
        $item=$modo==='cerrado' ? ['desc'=>$desc.' · curso cerrado para '.$P.' participantes','unidad'=>'Curso','cant'=>1,'precio'=>$precio*$P] : ['desc'=>$desc,'unidad'=>'Participante','cant'=>$P,'precio'=>$precio];
        $mods=implode('; ',array_map(fn($m,$i)=>($i+1).'. '.$m['nombre'].' ('.ocQty($m['teo']+$m['pra']).' h)',$c['modulos'],array_keys($c['modulos'])));
        $obs=trim(($mods!==''?'Contenidos: '.$mods.'. ':'').($c['certificacion']!==''?'Certificación: '.$c['certificacion'].'. ':'').($c['evaluacion']!==''?'Evaluación: '.$c['evaluacion'].'.':''));
        $cli=['razon'=>post('cli_razon'),'rut'=>'','giro'=>'','direccion'=>'','ciudad'=>'','contacto'=>post('cli_contacto'),'telefono'=>'','email'=>post('cli_email')];
        $sid=(int)post('cli_saved');
        if($sid>0 && ($s=query('SELECT * FROM cot_clients WHERE id=?',[$sid])->fetch(PDO::FETCH_ASSOC))) foreach(COT_CLI as $k) $cli[$k]=$s[$k];
        $q=cotNormalize(['fecha'=>ocToday(),'validez'=>$cfg['validez'],'estado'=>'Borrador','iva'=>$cfg['iva'],'cliente'=>$cli,
            'servicio'=>['modalidad'=>$c['modalidad'],'lugar'=>post('lugar'),'desde'=>post('desde'),'hasta'=>post('hasta'),'participantes'=>(string)$P],
            'objeto'=>['titulo'=>$c['nombre'],'detalle'=>$c['objetivo']],'items'=>[$item],'condiciones'=>$cfg['condiciones'],'firmas'=>$cfg['firmas'],'obs'=>$obs,'emisor'=>$cfg['emisor']]);
        $db->exec('BEGIN IMMEDIATE');
        try {
            $q['numero']=cotNextNumero(cotCfg());
            $cid=cotStore($q,0,(int)$user['id']);
            cotBumpSiguiente($q['numero']); cotUpsertClient($q['cliente']);
            query('INSERT INTO cu_quotes(course_id,version,cot_id,created) VALUES(?,?,?,?)',[$cu['id'],$cu['version'],$cid,gmdate('c')]);
            $db->exec('COMMIT');
        } catch(Throwable $ex) { $db->exec('ROLLBACK'); throw $ex; }
        audit('Creación de cotización',$q['numero'].' (desde el curso '.$cu['codigo'].')');
        go('Cotización '.$q['numero'].' creada como borrador desde el curso (versión '.(int)$cu['version'].'). Completa los datos del cliente y guárdala.',['cot'=>$cid,'edit'=>1]);
    }
    if ($action==='cu_cfg' && $isAdmin) {
        $cfg=cuCfg();
        $m=isset(CU_METODOS[post('metodo')])?post('metodo'):'curso';
        $cm=(int)ocNum(post('cursos_mes')); $hm=(int)ocNum(post('horas_mes'));
        if($cm<1||$cm>500) throw new RuntimeException('Los cursos al mes deben estar entre 1 y 500.');
        if($hm<1||$hm>20000) throw new RuntimeException('Las horas de clase al mes deben estar entre 1 y 20.000.');
        $mo=ocNum(post('margen_obj')); $mi=ocNum(post('margen_min'));
        if($mo>90||$mi>90) throw new RuntimeException('Los márgenes deben estar entre 0 y 90 %.');
        if($mi>$mo) throw new RuntimeException('El margen mínimo no puede ser mayor que el objetivo.');
        $red=in_array((int)post('redondeo'),[0,100,500,1000,5000,10000],true)?(int)post('redondeo'):1000;
        $rows=[]; [$fn,$fm,$fa]=[ocList('fx_nombre'),ocList('fx_monto'),ocList('fx_activo')];
        foreach($fn as $i=>$n){ $n=ocText($n,80); $mm=ccMoney($fm[$i]??''); if($n==='') { if($mm>0) throw new RuntimeException('Escribe el nombre del costo fijo de '.clp($mm).'.'); continue; } $rows[]=[$n,min($mm,1000000000),($fa[$i]??'1')==='0'?0:1]; }
        if(count($rows)>40) throw new RuntimeException('Se admiten hasta 40 costos fijos.');
        $db->exec('BEGIN IMMEDIATE');
        try {
            query('UPDATE cu_settings SET value=? WHERE key=?',[json_encode(['metodo'=>$m,'cursos_mes'=>$cm,'horas_mes'=>$hm,'margen_obj'=>round($mo,1),'margen_min'=>round($mi,1),'redondeo'=>$red]+$cfg,JSON_UNESCAPED_UNICODE),'cfg']);
            query('DELETE FROM cu_fixed');
            foreach($rows as $i=>[$n,$mm,$a]) query('INSERT INTO cu_fixed(nombre,monto,activo,orden) VALUES(?,?,?,?)',[$n,$mm,$a,$i]);
            $db->exec('COMMIT');
        } catch(Throwable $ex) { $db->exec('ROLLBACK'); throw $ex; }
        audit('Configuración de cursos y costos fijos','Total mensual '.clp(array_sum(array_map(fn($r)=>$r[2]?$r[1]:0,$rows))));
        go('Configuración guardada. Cada curso la toma al guardarlo o con «Actualizar costos fijos»; las versiones anteriores conservan sus valores.',['tab'=>'cu_cfg']);
    }
}

// ---------- Ficha del curso (hoja carta para el cliente, sin costos) ----------
function cuSheet(array $row, array $c, bool $withPrice): void {
    $em=cotCfg()['emisor']; $H=cuHoras($c); $T=(float)array_sum(array_column($c['modulos'],'teo')); $Pr=(float)array_sum(array_column($c['modulos'],'pra')); ?>
<article class="sheet">
<div class="s-head"><img src="<?=e(empresa()['logo'])?>" alt="<?=e(empresa()['nombre'])?>"><div class="s-title"><h2>FICHA DEL CURSO</h2>
  <div class="meta">Código&nbsp; <span class="num"><?=e($row['codigo'])?></span><?=$c['sence']!==''?' · SENCE '.e($c['sence']):''?></div>
  <div class="meta">Versión&nbsp; <span class="ink"><?=(int)($row['version'])?></span></div>
  <div class="meta">Emitida&nbsp; <span class="ink"><?=e(ocDate(ocToday()))?></span></div>
</div></div>
<div class="accent"></div>
<div class="s-parties">
  <div><div class="lbl">Curso</div><div class="strong"><?=e($c['nombre'])?></div><?php if($c['area']!==''):?><div><?=e($c['area'])?></div><?php endif?><?php if($c['modalidad']!==''):?><div>Modalidad <?=e($c['modalidad'])?></div><?php endif?></div>
  <div><div class="lbl">Dirigido a</div><div><?=$c['dirigido']!==''?e($c['dirigido']):'—'?></div><?php if($c['requisitos']!==''):?><div class="muted">Requisitos: <?=e($c['requisitos'])?></div><?php endif?></div>
  <div><div class="lbl">Duración</div><div class="strong"><?=e(ocQty($H))?> horas</div><?php if($T+$Pr>0):?><div><?=e(ocQty($T))?> teóricas · <?=e(ocQty($Pr))?> prácticas</div><?php endif?><?php if($c['part_max']>0):?><div>Cupo máximo <?=(int)$c['part_max']?> participantes</div><?php endif?></div>
</div>
<div class="s-summary">
  <div class="obj"><div class="lbl">Objetivo general</div><div class="muted"><?=e($c['objetivo']?:'—')?></div></div>
  <?php if($withPrice && $c['precio']>0):?><div class="tot"><div class="lbl">Valor por participante</div><div class="v"><?=clp($c['precio'])?></div><div class="n">Valor neto referencial · sujeto a cotización</div></div>
  <?php else:?><div class="tot"><div class="lbl">Duración total</div><div class="v"><?=e(ocQty($H))?> h</div><div class="n"><?=count($c['modulos'])?> módulo<?=count($c['modulos'])===1?'':'s'?></div></div><?php endif?>
</div>
<div class="s-sec">Contenidos</div>
<table class="items"><thead><tr><th class="c w4">#</th><th class="w15">Módulo</th><th>Contenidos</th><th class="r w8">Teo.</th><th class="r w8">Prác.</th><th class="r w8">Total</th></tr></thead><tbody>
<?php foreach($c['modulos'] as $i=>$m):?><tr><td class="c muted"><?=$i+1?></td><td><b><?=e($m['nombre'])?></b></td><td><?=e($m['contenidos'])?></td><td class="r"><?=e(ocQty($m['teo']))?></td><td class="r"><?=e(ocQty($m['pra']))?></td><td class="r"><?=e(ocQty($m['teo']+$m['pra']))?></td></tr><?php endforeach?>
<?php if(!$c['modulos']):?><tr><td colspan="6" class="muted c">Malla por definir</td></tr><?php endif?>
</tbody><?php if($c['modulos']):?><tfoot><tr class="total"><td colspan="3" class="r k">Total horas</td><td class="r"><?=e(ocQty($T))?></td><td class="r"><?=e(ocQty($Pr))?></td><td class="r v"><?=e(ocQty($T+$Pr))?></td></tr></tfoot><?php endif?></table>
<?php $conds=array_filter(['Evaluación y aprobación'=>$c['evaluacion'],'Certificación'=>$c['certificacion'],'Relator'=>$c['relator']],'strlen'); if($conds):?><div class="s-sec">Evaluación, certificación y relatoría</div>
<div class="s-conds"><div><?php foreach($conds as $k=>$v):?><div><b><?=e($k)?></b>&nbsp; <?=e($v)?></div><?php endforeach?></div></div><?php endif?>
<div class="s-foot"><span><?=e($em['razon'])?><?=$em['rut']!==''?' · R.U.T. '.e($em['rut']):''?><?=$em['web']!==''?' · '.e($em['web']):''?></span><span>Ficha técnica del curso · <?=e($row['codigo'])?></span></div>
</article>
<?php }

/** Descargas GET: ficha para imprimir (?cu_print=ID[&ver=N][&precio=1]). */
function cuGetRequests(): void {
    global $user;
    if((!isset($_GET['cu_print']) && !isset($_GET['cu_xlsx'])) || !cuOn()) return;
    if(!$user) stop(403,'Debes iniciar sesión.');
    if(isset($_GET['cu_xlsx'])) cuXlsxDownload();
    $cu=cuGet((int)$_GET['cu_print']); if(!$cu) stop(404,'Curso no encontrado.');
    $row=$cu; $c=$cu['c'];
    if(isset($_GET['ver']) && (int)$_GET['ver']!==(int)$cu['version']){ $v=cuVersion((int)$cu['id'],(int)$_GET['ver']); if(!$v) stop(404,'Versión no encontrada.'); $row=['codigo'=>$cu['codigo'],'version'=>$v['version']]; $c=$v['c']; }
    audit('Impresión de ficha de curso',$cu['codigo'].' · versión '.(int)$row['version']); ?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($cu['codigo'])?> · Ficha del curso <?=e(empresa()['corto'])?></title><link rel="stylesheet" href="assets/style.css?v=17"></head>
<body class="print-page"><div class="print-bar no-print"><a class="btn ghost small" href="<?=e('index.php?curso='.$cu['id'])?>">← Volver</a><span>Para imprimir o guardar como PDF usa <b>Ctrl + P</b> (<b>Cmd + P</b> en Mac), tamaño Carta y márgenes «Ninguno».</span></div>
<div class="sheet-wrap"><?php cuSheet($row,$c,isset($_GET['precio']))?></div></body></html><?php exit;
}
require __DIR__.'/cursos_xlsx.php';
