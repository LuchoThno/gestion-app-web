<?php
// Utilidades para documentos en dos idiomas y varias monedas (cotizaciones y proformas).
// La pantalla de la aplicación sigue en español; solo la hoja que se imprime cambia de idioma.

const DOC_IDIOMAS=['es'=>'Español','en'=>'English (inglés)'];
/** Moneda => [nombre en español, nombre en inglés, decimales]. */
const DOC_MONEDAS=['CLP'=>['Pesos chilenos','Chilean pesos',0],'USD'=>['Dólares estadounidenses','US dollars',2],'EUR'=>['Euros','Euros',2]];
const DOC_INCOTERMS=['EXW','FCA','CPT','CIP','DAP','DPU','DDP','FAS','FOB','CFR','CIF'];

function docLang(mixed $v): string { return is_string($v) && isset(DOC_IDIOMAS[$v]) ? $v : 'es'; }
function docMoneda(mixed $v): string { return is_string($v) && isset(DOC_MONEDAS[$v]) ? $v : 'CLP'; }
function docDec(string $m): int { return DOC_MONEDAS[$m][2] ?? 0; }
/** Texto según el idioma del documento. */
function tr(string $l, string $es, string $en): string { return $l==='en' ? $en : $es; }
/** Redondeo de un monto según los decimales de la moneda (CLP a entero). */
function docRound(float $n, string $m): float|int { $d=docDec($m); return $d===0 ? (int)round($n) : round($n,$d); }
/** Monto con símbolo: CLP «$ 1.234» / «CLP 1,234»; USD «US$ 1.234,50» / «USD 1,234.50». */
function money(float|int $n, string $m='CLP', string $l='es'): string {
    $d=docDec($m); $neg=$n<0; $n=abs((float)$n);
    $s=$l==='en' ? number_format($n,$d,'.',',') : number_format($n,$d,',','.');
    $p=$l==='en' ? $m.' ' : (['CLP'=>'$ ','USD'=>'US$ ','EUR'=>'€ '][$m]??$m.' ');
    return ($neg?'− ':'').$p.$s;
}
/** Fecha del documento: «16 / 11 / 2026» en español, «16 Nov 2026» en inglés (sin ambigüedad día/mes). */
function docDate(string $d, string $l='es', string $empty='____ / ____ / ______'): string {
    if($d==='') return $empty;
    if($l!=='en') return ocDate($d,$empty);
    $m=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][(int)substr($d,5,2)-1]??'';
    return (int)substr($d,8,2).' '.$m.' '.substr($d,0,4);
}
function docQty(float $n, string $l='es'): string {
    if($l!=='en') return ocQty($n);
    return floor($n)==$n ? number_format($n,0,'.',',') : rtrim(rtrim(number_format($n,3,'.',','),'0'),'.');
}
/** Ítems {desc, unidad, cant, precio} con el precio redondeado a los decimales de la moneda. */
function docItems(mixed $src, string $moneda): array {
    $items=[]; foreach(array_slice(is_array($src)?$src:[],0,60) as $it){ if(!is_array($it)) continue;
        $i=['desc'=>ocText($it['desc']??'',500),'unidad'=>ocText($it['unidad']??'',30),'cant'=>ocNum($it['cant']??0),'precio'=>docRound(ocNum($it['precio']??0),$moneda)];
        if($i['desc']!==''||$i['precio']>0) $items[]=$i; }
    return $items;
}
/** Valor para un input numérico (sin separador de miles). */
function docNumIn(mixed $n): string { return ocNumIn($n); }
/** Unidades frecuentes traducidas en la hoja en inglés (si el usuario escribió otra, se muestra tal cual). */
function docUnit(string $u, string $l): string {
    if($l!=='en') return $u;
    return ['servicio'=>'Service','día'=>'Day','dia'=>'Day','días'=>'Days','hora'=>'Hour','horas'=>'Hours','unidad'=>'Unit','un'=>'Unit','gl'=>'Lump sum','global'=>'Lump sum','mes'=>'Month','jornada'=>'Day','persona'=>'Person','curso'=>'Course','km'=>'km','kg'=>'kg','turno'=>'Shift','noche'=>'Night','viaje'=>'Trip','inspección'=>'Inspection','informe'=>'Report'][mb_strtolower(trim($u))]??$u;
}
/** Ítems frecuentes de inspecciones y trabajos con ROV (MRM), para sugerir en cotizaciones y proformas según el idioma. */
function docRovItems(string $l): array {
    return $l==='en' ? ['ROV spread mobilization and demobilization','ROV inspection day rate (pilot and technician)','ROV pilot / operator (per day)','ROV technician (per day)','Support vessel (per day)','Hull inspection with HD video','Mooring line inspection','Pier and sheet pile inspection','Subsea search and recovery','Hull and niche area cleaning','Multibeam / sonar survey','Inspection report with HD video','Travel and accommodation of the crew','Standby day (weather)']
        : ['Movilización y desmovilización de equipo ROV','Día de inspección ROV (piloto y técnico)','Piloto / operador ROV (por día)','Técnico ROV (por día)','Embarcación de apoyo (por día)','Inspección de casco con video HD','Inspección de líneas de fondeo','Inspección de muelle y tablestacas','Búsqueda y recuperación submarina','Limpieza de casco y tomas de mar','Levantamiento con sonar / multihaz','Informe de inspección con video HD','Traslado y alojamiento del equipo','Día de standby (clima)'];
}
/** Datalist con los ítems frecuentes de ROV (solo en empresas sin cursos) y el atributo list para los inputs. */
function docRovDatalist(string $id, string $l): string {
    if(empresa()['cursos']) return '';
    $o=''; foreach(docRovItems($l) as $v) $o.='<option value="'.e($v).'">';
    return '<datalist id="'.e($id).'">'.$o.'</datalist>';
}
/** Selector de idioma y moneda para los formularios. */
function docLangSelect(string $name, string $val): string {
    $o=''; foreach(DOC_IDIOMAS as $k=>$v) $o.='<option value="'.$k.'"'.($val===$k?' selected':'').'>'.e($v).'</option>';
    return '<select name="'.e($name).'">'.$o.'</select>';
}
function docMonedaSelect(string $name, string $val): string {
    $o=''; foreach(DOC_MONEDAS as $k=>$v) $o.='<option value="'.$k.'"'.($val===$k?' selected':'').'>'.$k.' · '.e($v[0]).'</option>';
    return '<select name="'.e($name).'">'.$o.'</select>';
}
/** Etiqueta corta para listas: «EN · USD». */
function docTag(string $l, string $m): string { return ($l!=='es'||$m!=='CLP') ? '<span class="doc-tag" title="Idioma y moneda del documento">'.strtoupper($l).' · '.e($m).'</span>' : ''; }
