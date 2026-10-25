<?php
// Tareas programadas de OX3 Gestión. En hPanel → Avanzado → Cron Jobs, ejecuta una vez por hora:
//   /usr/bin/php /home/USUARIO/domains/ox3.cl/ox3-private/cron.php
// Envía correos pendientes, el recordatorio diario de rendiciones por aprobar, verifica la bitácora (de cada empresa)
// y crea el respaldo cifrado automático cuando corresponde. Sin cron, las mismas tareas se ejecutan
// al usar la plataforma (después de responder a la página).
//   php cron.php respaldo   → fuerza un respaldo ahora
//   php cron.php verificar  → verifica la bitácora y el último respaldo
declare(strict_types=1);
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
define('OX3_CLI',true);
require __DIR__.'/app.php';
$cmd=$argv[1]??'';
$out=function(string $m): void { echo '['.date('Y-m-d H:i:s').'] '.$m."\n"; };
/** Deja la base de la empresa $k como base en uso (las tareas usan las mismas funciones que la plataforma). */
$useEmpresa=function(string $k) use($dbFile): bool {
    global $db, $empresaKey;
    if($k===EMPRESA_BASE){ $empresaKey=$k; $db=openDb($dbFile); return true; }
    $f=dataDir($k).'/gestion.sqlite'; if(!is_file($f)) return false; // aún nadie entra a esa empresa
    $empresaKey=$k; $db=openDb($f); $db->sharedUsers=true; query('ATTACH DATABASE ? AS core',[$dbFile]); return true;
};
$fail=false;
// El respaldo es uno solo y abarca todas las empresas; lo demás (avisos, bitácora, correos) se hace en cada una.
if($cmd==='respaldo') {
    try { $r=backupCreate('manual (cron)'); $out('Respaldo creado: '.$r['file'].' ('.$r['docs'].' archivos)'); } catch(Throwable $ex) { $out('Error: '.$ex->getMessage()); exit(1); }
    exit(0);
}
foreach(empresas() as $k=>$em) {
    try {
        if(!$useEmpresa($k)) { $out('['.$em['corto'].'] Sin datos todavía.'); continue; }
        if($cmd==='verificar') { $v=auditVerify(); $out('['.$em['corto'].'] Bitácora: '.($v['ok']?'íntegra, '.$v['total'].' registros':$v['problem'])); }
        else foreach(dailyTasks() ?: ['Sin tareas pendientes.'] as $m) $out('['.$em['corto'].'] '.$m);
    } catch(Throwable $ex) { $out('['.$em['corto'].'] Error: '.$ex->getMessage()); $fail=true; }
}
if($cmd==='verificar') {
    try { $l=backupList(); if($l){ $i=backupVerify($l[0]['file']); $out('Último respaldo '.$l[0]['file'].': íntegro, '.$i['empresas'].' empresa(s), '.$i['documentos'].' documentos'); } else $out('Sin respaldos.'); }
    catch(Throwable $ex) { $out('Error: '.$ex->getMessage()); $fail=true; }
}
exit($fail?1:0);
