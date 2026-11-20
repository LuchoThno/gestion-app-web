<?php
// Carga datos ficticios en la copia de demostración (demo/.run). Lo ejecuta demo/levantar.sh; nunca sobre una instalación real.
//   php demo/seed.php demo/.run/private ox3|mrm
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
[$priv, $emp] = [$argv[1] ?? '', $argv[2] ?? ''];
if (!is_file($priv.'/app.php') || !str_contains(str_replace('\\', '/', realpath($priv) ?: ''), '/demo/.run/')) {
    fwrite(STDERR, "Uso: php demo/seed.php demo/.run/private ox3|mrm (solo sobre la copia de demostración)\n"); exit(1);
}
define('OX3_CLI', true);
define('OX3_EMPRESA', $emp);
require $priv.'/app.php';
if (empresa()['key'] !== $emp) { fwrite(STDERR, "Empresa desconocida: $emp\n"); exit(1); }

$day = fn(int $d): string => date('Y-m-d', strtotime(($d >= 0 ? '+' : '').$d.' days'));
$now = gmdate('c');

// ---------- Usuarios (base común, en la primera pasada) ----------
if ($emp === EMPRESA_BASE) {
    $pw = password_hash('Demo-Gestion-2026', PASSWORD_DEFAULT);
    foreach ([['Administración Demo', 'admin@demo.test', 'admin'], ['Editora Demo', 'editor@demo.test', 'editor'], ['Lector Demo', 'lector@demo.test', 'viewer']] as [$n, $m, $r])
        query('INSERT OR IGNORE INTO users(name,email,password,role) VALUES(?,?,?,?)', [$n, $m, $pw, $r]);
    foreach (query('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN) as $id)
        foreach (array_keys(empresas()) as $k) query('INSERT OR IGNORE INTO user_companies(user_id,empresa) VALUES(?,?)', [$id, $k]);
}
$user = query("SELECT * FROM users WHERE role='admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$editor = (int)query("SELECT id FROM users WHERE role='editor' ORDER BY id LIMIT 1")->fetchColumn();
$uid = (int)$user['id']; $_SESSION['uid'] = $uid;
if ((int)query('SELECT COUNT(*) FROM projects')->fetchColumn() > 0) { echo "[$emp] ya tiene datos de demostración.\n"; exit(0); }

$ox3 = $emp === 'ox3';
$em = empresa()['emisor'];
$db->exec('BEGIN IMMEDIATE');

// ---------- Proyectos, carpetas y documentos ----------
$projects = $ox3
    ? [['Curso Operador de Grúa Horquilla · Puerto Demo', 'Capacitación para 12 operadores de un cliente ficticio.'], ['Curso Primeros Auxilios · Minera Ejemplo', 'Curso de 16 horas para supervisores.'], ['Administración general', 'Documentos internos.']]
    : [['Inspección de casco · Barcaza Ejemplo', 'Inspección visual con ROV y registro en video.'], ['Revisión de redes · Salmonera Demo', 'Revisión mensual de redes de cultivo.'], ['Administración general', 'Documentos internos.']];
$pids = [];
foreach ($projects as [$n, $d]) { query('INSERT INTO projects(name,description,created) VALUES(?,?,?)', [$n, $d, $now]); $pids[] = (int)$db->lastInsertId(); }
$folders = [];
foreach (['Contrato y cotización', 'Registros', 'Informe final'] as $fn) { query('INSERT INTO folders(name,project_id,parent_id,created) VALUES(?,?,?,?)', [$fn, $pids[0], null, $now]); $folders[$fn] = (int)$db->lastInsertId(); }

$up = dataDir().'/uploads';
if (!is_dir($up)) mkdir($up, 0700, true);
$pdf = function (string $text): string { // PDF mínimo de una página (texto ASCII)
    $t = preg_replace('/[^\x20-\x7E]/', '', $text);
    $s = "BT /F1 16 Tf 60 760 Td ($t) Tj 0 -28 Td /F1 11 Tf (Documento ficticio para la demostracion.) Tj ET";
    $objs = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        '<< /Length '.strlen($s)." >>\nstream\n$s\nendstream", '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
    $out = "%PDF-1.4\n"; $off = [];
    foreach ($objs as $i => $o) { $off[] = strlen($out); $out .= ($i + 1)." 0 obj\n$o\nendobj\n"; }
    $x = strlen($out); $out .= "xref\n0 ".(count($objs) + 1)."\n0000000000 65535 f \n";
    foreach ($off as $o) $out .= sprintf("%010d 00000 n \n", $o);
    return $out."trailer\n<< /Size ".(count($objs) + 1)." /Root 1 0 R >>\nstartxref\n$x\n%%EOF\n";
};
$docs = $ox3
    ? [['Programa del curso.pdf', $pdf('Programa del curso - Operador de Grua Horquilla'), 'Cursos y alumnos', $pids[0], $folders['Contrato y cotización']],
       ['Lista de asistencia.csv', "Alumno,RUT,Asistencia\nAlumno Uno,11.111.111-1,100%\nAlumno Dos,22.222.222-2,94%\n", 'Cursos y alumnos', $pids[0], $folders['Registros']],
       ['Acta de cierre.txt', "Acta de cierre del curso (ficticia).\nAprobaron 11 de 12 participantes.\n", 'Certificados', $pids[0], $folders['Informe final']],
       ['Procedimiento interno.pdf', $pdf('Procedimiento interno de compras'), 'Administración', $pids[2], null]]
    : [['Plan de inspección.pdf', $pdf('Plan de inspeccion ROV - Barcaza Ejemplo'), 'Inspecciones y trabajos ROV', $pids[0], $folders['Contrato y cotización']],
       ['Bitácora de inmersión.csv', "Inmersión,Profundidad (m),Duración (min)\n1,12,45\n2,18,38\n", 'Inspecciones y trabajos ROV', $pids[0], $folders['Registros']],
       ['Informe técnico.pdf', $pdf('Informe tecnico de inspeccion de casco'), 'Informes técnicos', $pids[0], $folders['Informe final']],
       ['Procedimiento interno.pdf', $pdf('Procedimiento de seguridad en faena'), 'Administración', $pids[2], null]];
foreach ($docs as [$name, $body, $cat, $p, $f]) {
    $stored = bin2hex(random_bytes(24)); file_put_contents($up.'/'.$stored, $body); chmod($up.'/'.$stored, 0600);
    query('INSERT INTO files(name,stored,size,category,owner,created,project_id,folder_id) VALUES(?,?,?,?,?,?,?,?)', [$name, $stored, strlen($body), $cat, $uid, $now, $p, $f]);
}

// ---------- Centros de costo ----------
$centers = [];
foreach ([[$ox3 ? 'C-101' : 'P-201', $projects[0][0]], [$ox3 ? 'C-102' : 'P-202', $projects[1][0]], ['ADM', 'Administración general']] as [$c, $n]) {
    query('INSERT INTO cc_centers(codigo,nombre,descripcion,created) VALUES(?,?,?,?)', [$c, $n, 'Centro de demostración', $now]);
    $centers[] = ['id' => (int)$db->lastInsertId(), 'label' => "$c · $n"];
}

// ---------- Órdenes de compra ----------
$prov = ['razon' => 'Proveedora Ejemplo Ltda.', 'rut' => '76.000.000-0', 'giro' => 'Arriendo de equipos', 'direccion' => 'Calle Ficticia 123', 'ciudad' => 'Punta Arenas', 'telefono' => '+56 9 0000 0000', 'email' => 'ventas@proveedora.example'];
ocUpsertSupplier($prov);
$ocItems = $ox3 ? [['desc' => 'Arriendo de grúa horquilla para práctica', 'unidad' => 'día', 'cant' => 3, 'precio' => 180000], ['desc' => 'Elementos de protección personal', 'unidad' => 'kit', 'cant' => 12, 'precio' => 15000]]
    : [['desc' => 'Arriendo de embarcación de apoyo', 'unidad' => 'día', 'cant' => 2, 'precio' => 450000], ['desc' => 'Combustible embarcación', 'unidad' => 'litro', 'cant' => 300, 'precio' => 1200]];
foreach ([['Emitida', -6], ['Recibida', -15], ['Borrador', 0]] as $i => [$estado, $d]) {
    $cfgOc = ocCfg(); $num = ocNextNumero($cfgOc); ocBumpSiguiente($num);
    ocStore(ocNormalize(['numero' => $num, 'fecha' => $day($d), 'estado' => $estado, 'ceco' => $centers[$i % 2]['label'], 'iva' => 19, 'proveedor' => $prov,
        'entrega' => ['lugar' => 'Punta Arenas', 'desde' => $day($d + 2), 'hasta' => $day($d + 5), 'solicitante' => 'Editora Demo'],
        'objeto' => ['titulo' => $i ? 'Insumos · '.$projects[$i % 2][0] : $ocItems[0]['desc'], 'detalle' => 'Orden de compra de demostración'],
        'items' => $i ? [$ocItems[1]] : $ocItems, 'condiciones' => $cfgOc['condiciones'] ?? [], 'firmas' => $cfgOc['firmas'] ?? [], 'obs' => '', 'emisor' => $em]), 0, $uid);
}

// ---------- Cotizaciones ----------
$cli = $ox3 ? ['razon' => 'Puerto Demo S.A.', 'rut' => '96.000.000-1', 'giro' => 'Servicios portuarios', 'direccion' => 'Av. Costanera 1000', 'ciudad' => 'Talcahuano', 'pais' => 'Chile', 'contacto' => 'Jefa de RR.HH.', 'telefono' => '+56 41 000 0000', 'email' => 'rrhh@puerto.example']
    : ['razon' => 'Salmonera Demo SpA', 'rut' => '96.000.000-2', 'giro' => 'Acuicultura', 'direccion' => 'Ruta 9 km 10', 'ciudad' => 'Puerto Natales', 'pais' => 'Chile', 'contacto' => 'Jefe de centro', 'telefono' => '+56 61 000 0000', 'email' => 'operaciones@salmonera.example'];
cotUpsertClient($cli);
foreach ([['Aceptada', -20, $ox3 ? 2900000 : 3600000], ['Enviada', -3, $ox3 ? 1450000 : 2100000], ['Borrador', 0, $ox3 ? 980000 : 1250000]] as $i => [$estado, $d, $precio]) {
    $cfgCot = cotCfg(); $lang = cotLangDefaults($cfgCot, 'es'); $num = cotNextNumero($cfgCot); cotBumpSiguiente($num);
    cotStore(cotNormalize(['numero' => $num, 'fecha' => $day($d), 'validez' => 30, 'estado' => $estado, 'ceco' => $centers[$i % 2]['label'], 'iva' => $ox3 ? 0 : 19, 'descuento' => $i === 1 ? 5 : 0,
        'idioma' => 'es', 'moneda' => 'CLP', 'cliente' => $cli,
        'servicio' => ['modalidad' => 'Presencial', 'lugar' => $cli['ciudad'], 'desde' => $day($d + 10), 'hasta' => $day($d + 12), 'participantes' => $ox3 ? '12 participantes' : 'Equipo ROV de 3 personas'],
        'objeto' => ['titulo' => $projects[$i % 2][0], 'detalle' => 'Cotización de demostración'],
        'items' => [['desc' => $ox3 ? 'Curso con material y certificado' : 'Inspección ROV con informe', 'unidad' => $ox3 ? 'curso' : 'servicio', 'cant' => 1, 'precio' => $precio]],
        'condiciones' => $lang['condiciones'] ?? [], 'firmas' => $lang['firmas'] ?? [], 'obs' => '', 'emisor' => $em]), 0, $uid);
}

// ---------- Ingresos, gastos y rendiciones ----------
$mov = function (string $tipo, int $d, int $monto, string $desc, string $cat, ?int $center, ?int $rend = null, string $doc = 'Factura') use ($day, $uid, $now, $db): void {
    query('INSERT INTO cc_movs(tipo,fecha,monto,iva,descripcion,contraparte,rut,doc_tipo,doc_numero,categoria,medio,referencia,rendicion_id,owner,created,updated) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$tipo, $day($d), $monto, 0, $desc, $tipo === 'ingreso' ? 'Cliente de demostración' : 'Proveedor de demostración', '', $doc, (string)random_int(1000, 9999), $cat, 'Transferencia', '', $rend, $uid, $now, $now]);
    query('INSERT INTO cc_splits(mov_id,center_id,monto,pct) VALUES(?,?,?,?)', [(int)$db->lastInsertId(), $center, $monto, 100]);
};
[$c1, $c2, $ca] = array_column($centers, 'id');
$ing = $ox3 ? 'Venta de cursos' : 'Inspecciones ROV';
$mov('ingreso', -25, $ox3 ? 2900000 : 3600000, 'Pago 50% del servicio', $ing, $c1);
$mov('ingreso', -5, $ox3 ? 1450000 : 1800000, 'Pago saldo del servicio', $ing, $c1);
$mov('ingreso', -12, $ox3 ? 980000 : 1250000, 'Anticipo del segundo servicio', $ing, $c2);
$mov('gasto', -18, 540000, 'Arriendo de equipos', 'Servicios externos', $c1);
$mov('gasto', -14, $ox3 ? 720000 : 950000, $ox3 ? 'Honorarios relator' : 'Honorarios piloto ROV', 'Honorarios', $c1, null, 'Boleta de honorarios');
$mov('gasto', -9, 180000, 'Materiales e insumos', 'Materiales e insumos', $c2);
$mov('gasto', -7, 350000, 'Arriendo de oficina', 'Arriendo', $ca);
$mov('gasto', -2, 95000, 'Servicios básicos', 'Servicios básicos', $ca);

$rend = function (string $titulo, string $estado, int $d, int $fondo, int $center, array $gastos) use ($day, $uid, $editor, $now, $db, $mov): void {
    $done = $estado === 'Aprobada';
    query('INSERT INTO cc_rendiciones(numero,titulo,responsable,fecha,fondo,center_id,obs,estado,owner,created,updated,sent,reviewer,reviewed) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [ccNextRendNumero(), $titulo, 'Editora Demo', $day($d), $fondo, $center, '', $estado, $editor ?: $uid, $now, $now, $estado !== 'Borrador' ? $now : null, $done ? $uid : null, $done ? $now : null]);
    $rid = (int)$db->lastInsertId();
    foreach ($gastos as [$monto, $desc, $cat]) $mov('gasto', $d, $monto, $desc, $cat, $center, $rid, 'Boleta');
};
$rend('Viáticos de terreno', 'Aprobada', -16, 200000, $c1, [[62000, 'Alimentación del equipo', 'Alimentación'], [85000, 'Alojamiento una noche', 'Alojamiento'], [38000, 'Combustible vehículo', 'Combustible']]);
$rend('Gastos de traslado', 'Enviada', -4, 150000, $c2, [[45000, 'Pasajes en bus', 'Transporte y pasajes'], [12000, 'Estacionamiento', 'Peajes y estacionamiento']]);
$rend('Compras menores', 'Borrador', -1, 50000, $ca, [[18000, 'Artículos de oficina', 'Materiales e insumos']]);

// ---------- Presupuestos ----------
$budget = function (string $nombre, string $tipo, string $estado, int $pid, int $center, int $part, float $horas, array $lines) use ($day, $uid, $now, $db): void {
    $b = ['participantes' => $part, 'horas' => $horas]; $aprob = $estado === 'Aprobado';
    $tot = ['ingreso' => 0, 'gasto' => 0]; $rows = [];
    foreach ($lines as $i => [$t, $cat, $desc, $cant, $precio, $base]) {
        $monto = (int)round($cant * $precio * psMult($base, $b)); $tot[$t] += $monto;
        $rows[] = [$t, $cat, $desc, $cant, '', $precio, $base, 0, $monto, $i];
    }
    query('INSERT INTO ps_budgets(numero,nombre,tipo,codigo,cliente,project_id,center_id,desde,hasta,participantes,horas,alerta,estado,obs,aprob_ingreso,aprob_gasto,approved_by,approved,owner,created,updated) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [psNextNumero(), $nombre, $tipo, '', 'Cliente de demostración', $pid, $center, $day(-30), $day(30), $part, $horas, 80, $estado, '', $aprob ? $tot['ingreso'] : null, $aprob ? $tot['gasto'] : null, $aprob ? $uid : null, $aprob ? $now : null, $uid, $now, $now]);
    $bid = (int)$db->lastInsertId();
    foreach ($rows as $r) query('INSERT INTO ps_lines(budget_id,tipo,categoria,descripcion,cantidad,unidad,precio,base,ret,monto,orden) VALUES(?,?,?,?,?,?,?,?,?,?,?)', [$bid, ...$r]);
};
$tipo = $ox3 ? 'Curso' : 'Operación';
$budget($projects[0][0], $tipo, 'Aprobado', $pids[0], $c1, $ox3 ? 12 : 0, $ox3 ? 24 : 0, [
    ['ingreso', $ing, 'Valor del servicio', 1, $ox3 ? 4350000 : 5400000, 'fijo'],
    ['gasto', 'Honorarios', $ox3 ? 'Relator' : 'Piloto ROV', 1, $ox3 ? 700000 : 900000, 'fijo'],
    ['gasto', 'Servicios externos', 'Arriendo de equipos', 1, 500000, 'fijo'],
    ['gasto', 'Alimentación', 'Alimentación', 1, 60000, 'fijo'],
    ['gasto', 'Alojamiento', 'Alojamiento', 1, 80000, 'fijo'],
    ['gasto', 'Combustible', 'Combustible', 1, 30000, 'fijo']]);
$budget($projects[1][0], $tipo, 'Por aprobar', $pids[1], $c2, $ox3 ? 10 : 0, $ox3 ? 16 : 0, [
    ['ingreso', $ing, 'Valor del servicio', 1, $ox3 ? 1450000 : 2100000, 'fijo'],
    ['gasto', 'Materiales e insumos', 'Materiales', 1, 200000, 'fijo'],
    ['gasto', 'Transporte y pasajes', 'Traslados', 1, 60000, 'fijo']]);
$db->exec('COMMIT');

// ---------- Cursos (OX3) y proformas (MRM) ----------
if ($ox3) {
    query('INSERT INTO cu_fixed(nombre,monto,activo,orden) VALUES(?,?,?,?),(?,?,?,?)', ['Arriendo de sala', 400000, 1, 1, 'Coordinación académica', 600000, 1, 2]);
    foreach ([['Operador de Grúa Horquilla', 'Logística', 24, 12, 360000], ['Primeros Auxilios', 'Prevención de riesgos', 16, 10, 145000]] as [$n, $a, $h, $part, $precio])
        cuStore(['nombre' => $n, 'area' => $a, 'modalidad' => 'Presencial', 'dirigido' => 'Trabajadores del cliente', 'objetivo' => 'Curso ficticio para la demostración.', 'horas' => $h, 'part' => $part, 'part_min' => 8, 'part_max' => 15, 'precio' => $precio,
            'modulos' => [['nombre' => 'Teoría', 'teo' => $h / 2, 'pra' => 0, 'contenidos' => 'Normativa y conceptos'], ['nombre' => 'Práctica', 'teo' => 0, 'pra' => $h / 2, 'contenidos' => 'Ejercicios guiados']],
            'costos' => [['desc' => 'Relator', 'cant' => 1, 'unidad' => 'hora', 'precio' => 25000, 'base' => 'hora'], ['desc' => 'Material por alumno', 'cant' => 1, 'unidad' => 'kit', 'precio' => 8000, 'base' => 'part']]], 0, 'Versión inicial de demostración');
} else {
    $cfgPf = pfCfg(); $num = pfNextNumero($cfgPf); pfBumpSiguiente($num); $l = pfLangDefaults($cfgPf, 'en');
    $client = ['razon' => 'Example Shipping Co.', 'rut' => '', 'giro' => 'Shipping', 'direccion' => '1 Harbour Road', 'ciudad' => 'Montevideo', 'pais' => 'Uruguay', 'contacto' => 'Fleet manager', 'telefono' => '', 'email' => 'fleet@shipping.example'];
    pfStore(pfNormalize(['numero' => $num, 'fecha' => $day(-2), 'validez' => 30, 'estado' => 'Emitida', 'idioma' => 'en', 'moneda' => 'USD', 'cliente' => $client,
        'objeto' => ['titulo' => 'ROV hull inspection', 'detalle' => 'Demo proforma invoice'], 'items' => [['desc' => 'ROV inspection day rate', 'unidad' => 'day', 'cant' => 2, 'precio' => 2400]],
        'anticipo' => 50, 'condiciones' => $l['condiciones'], 'firmas' => $l['firmas'], 'pago' => $l['pago'], 'declaracion' => $l['declaracion'], 'emisor' => $em]), 0, $uid);
}
audit('Demostración', 'Datos ficticios cargados para '.empresa()['corto']);
echo '['.empresa()['corto']."] datos de demostración cargados.\n";
