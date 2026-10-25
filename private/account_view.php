<?php
// Pantallas Mi cuenta, Pendientes y Seguridad. Se incluye desde view.php.
$roleName=['admin'=>'Administrador','editor'=>'Editor','viewer'=>'Lector'];
$kindLabel=['aprobar'=>'Por aprobar','corregir'=>'Por corregir','enviar'=>'Por enviar','pagar'=>'Por pagar','seguridad'=>'Seguridad','sobregiro'=>'Sobregiro','alerta'=>'Presupuesto','cerrar'=>'Por cerrar'];
$mailOn=mailMode()!=='';
if($mode==='account'):?>
<div class="page-head"><div><span class="eyebrow muted">MI CUENTA</span><h1><?=e($user['name'])?></h1><p class="subtitle"><?=e($user['email'])?> · <?=e($roleName[$user['role']])?></p></div></div>
<?php if(!empty($_SESSION['mfa_codes'])):?>
<section class="card narrow codes-card"><h2><?=ic('key')?>Tus códigos de recuperación</h2>
<p>Sirven para ingresar si pierdes el teléfono. Cada código funciona <b>una sola vez</b>. Guárdalos ahora en un lugar seguro (gestor de contraseñas o impresos): no se volverán a mostrar.</p>
<ul class="codes"><?php foreach($_SESSION['mfa_codes'] as $c):?><li><code><?=e($c)?></code></li><?php endforeach?></ul>
<form method="post"><?=csrf()?><button class="primary" name="action" value="mfa_codes_ok"><?=ic('check')?>Ya los guardé</button></form></section>
<?php endif?>
<div class="acc-grid">
<section class="card"><h2><?=ic('shield')?>Verificación en dos pasos</h2>
<?php if((int)$user['mfa_on']): $left=count(json_decode((string)$user['mfa_recovery'],true)?:[]); ?>
  <p><span class="status on">Activa</span> Al ingresar te pedimos tu contraseña y el código de 6 dígitos de tu app de autenticación.</p>
  <p class="hint">Te quedan <b><?=$left?></b> código<?=$left===1?'':'s'?> de recuperación.<?=$left<=3?' Genera nuevos antes de que se agoten.':''?></p>
  <details class="pop-inline"><summary class="btn ghost small"><?=ic('key')?>Generar códigos de recuperación nuevos</summary><form method="post" class="stack"><?=csrf()?><label>Código actual de tu app<input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required></label><button class="primary" name="action" value="mfa_regen">Generar</button></form></details>
  <?php if(!mfaRequired($user)):?><details class="pop-inline"><summary class="btn ghost small">Desactivar</summary><form method="post" class="stack danger-zone"><?=csrf()?><label>Contraseña<input type="password" name="password" required autocomplete="current-password"></label><label>Código actual de tu app<input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required></label><button class="danger" name="action" value="mfa_disable">Desactivar verificación</button></form></details>
  <?php else:?><p class="hint">Tu permiso exige esta verificación. Si cambias de teléfono, pide al administrador que la restablezca y vuelve a activarla.</p><?php endif?>
<?php else:?>
  <p>Agrega una segunda protección: además de tu contraseña, al ingresar te pediremos un código que cambia cada 30 segundos en tu teléfono.</p>
  <?php mfaSetup(false) ?>
<?php endif?>
</section>
<section class="card"><h2><?=ic('mail')?>Avisos por correo</h2>
<form method="post" class="stack"><?=csrf()?><input type="hidden" name="notify_email" value="0">
<label class="check-row"><input type="checkbox" name="notify_email" value="1"<?=(int)$user['notify_email']?' checked':''?>> Enviarme por correo los avisos de pendientes (rendiciones por aprobar, aprobadas o rechazadas, OC y cotizaciones)</label>
<button class="primary" name="action" value="notify_pref">Guardar</button></form>
<p class="hint">Los avisos de seguridad (cambio de contraseña, verificación en dos pasos, bloqueos) siempre se envían. Todos los avisos quedan también en <a href="<?=e(url(['tab'=>'pend']))?>">Pendientes</a>.<?=$mailOn?'':' El envío de correos aún no está configurado en la plataforma.'?></p></section>
<section class="card"><h2><?=ic('key')?>Cambiar contraseña</h2><form method="post" class="stack"><?=csrf()?><label>Contraseña actual<input type="password" name="current_password" required autocomplete="current-password"></label><label>Nueva contraseña <small>(12 a 72 caracteres)</small><input type="password" name="password" minlength="12" maxlength="72" required autocomplete="new-password"></label><button class="primary" name="action" value="password">Actualizar contraseña</button></form><p class="hint">Al cambiarla se cerrará tu sesión y deberás ingresar nuevamente.</p></section>
</div>

<?php elseif($mode==='pend'):
$notifs=query('SELECT * FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 60',[$user['id']])->fetchAll(PDO::FETCH_ASSOC); ?>
<div class="page-head"><div><span class="eyebrow muted">TU TRABAJO</span><h1>Pendientes</h1><p class="subtitle">Lo que espera una acción tuya y los avisos de la plataforma.<?=$mailOn&&(int)$user['notify_email']?' También te los enviamos por correo.':''?></p></div></div>
<section class="block"><div class="block-head"><h2>Por hacer <small><?=count($pending)?></small></h2></div>
<?php if($pending):?><ul class="pend-list big"><?php foreach($pending as $it):?><li class="pk-<?=e($it['kind'])?>"><a href="<?=e(url($it['link']))?>"><span class="pk-tag"><?=e($kindLabel[$it['kind']]??'')?></span><b><?=e($it['title'])?></b><small><?=e($it['detail'])?></small></a></li><?php endforeach?></ul>
<?php else:?><div class="empty-state"><?=ic('check')?><b>Todo al día</b><span>No tienes rendiciones, órdenes ni tareas esperando por ti.</span></div><?php endif?></section>
<section class="block"><div class="block-head"><h2>Avisos <small><?=$unread?$unread.' sin leer':''?></small></h2><?php if($unread):?><form method="post"><?=csrf()?><button class="ghost small" name="action" value="notif_read_all"><?=ic('check')?>Marcar todo como leído</button></form><?php endif?></div>
<?php if($notifs):?><ul class="notif-list"><?php foreach($notifs as $n):?><li class="<?=$n['read_at']===null?'unread':''?>"><a href="<?=e(url(['notif'=>(int)$n['id']]))?>"><span class="n-ic n-<?=e(explode('_',$n['kind'])[0])?>"><?=ic($n['kind']==='seguridad'?'shield':(str_starts_with($n['kind'],'rend')?'receipt':(str_starts_with($n['kind'],'oc')?'cart':(str_starts_with($n['kind'],'cot')?'quote':(str_starts_with($n['kind'],'ps')?'target':'bell')))))?></span><span class="n-txt"><b><?=e($n['title'])?></b><small><?=e($n['body'])?></small></span><time><?=e(fdate($n['created'],true))?></time></a></li><?php endforeach?></ul>
<?php else:?><div class="empty-state"><?=ic('bell')?><b>Sin avisos</b><span>Aquí verás cuando te aprueben o rechacen una rendición, o haya algo por aprobar.</span></div><?php endif?></section>

<?php elseif($mode==='security'):
$av=auditVerify(); $bs=backupStatus(); $st=$bs['state'];
$mq=query('SELECT SUM(sent IS NOT NULL AND sent>=?) AS ok, SUM(sent IS NULL AND attempts<5) AS pend, SUM(sent IS NULL AND attempts>=5) AS bad FROM mail_outbox',[gmdate('c',time()-7*86400)])->fetch(PDO::FETCH_ASSOC);
$lastErr=(string)query("SELECT last_error FROM mail_outbox WHERE last_error<>'' ORDER BY id DESC LIMIT 1")->fetchColumn();
$users=query('SELECT id,name,role,mfa_on FROM users WHERE active=1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC); $mfaN=count(array_filter($users,fn($u)=>(int)$u['mfa_on'])); ?>
<div class="page-head"><div><span class="eyebrow muted">ADMINISTRACIÓN</span><h1>Seguridad</h1><p class="subtitle">Integridad de la bitácora, respaldos cifrados, verificación en dos pasos y envío de correos.</p></div></div>
<div class="stat-grid">
  <div class="stat"><?=ic('activity')?><strong class="<?=$av['ok']?'cc-in':'cc-out'?>"><?=$av['ok']?'Íntegra':'Alterada'?></strong><span>Bitácora · <?=number_format($av['total'],0,',','.')?> registros</span></div>
  <div class="stat"><?=ic('database')?><strong><?=$bs['last']?e(fdate(gmdate('c',$bs['last']['time']),true)):'—'?></strong><span><?=$bs['enabled']?'Último respaldo cifrado':'Respaldos sin configurar'?></span></div>
  <div class="stat"><?=ic('shield')?><strong><?=$mfaN?> de <?=count($users)?></strong><span>Usuarios con dos pasos</span></div>
  <div class="stat"><?=ic('mail')?><strong><?=$mailOn?(int)$mq['ok']:'—'?></strong><span><?=$mailOn?'Correos enviados en 7 días'.((int)$mq['bad']?' · '.(int)$mq['bad'].' fallidos':''):'Correo sin configurar'?></span></div>
</div>

<?php $php=phpPatchStatus();?>
<section class="card"><h2><?=ic('shield')?>Servidor PHP</h2>
<?php if($php['ok']===true):?><div class="alert" role="status"><b>Actualizado.</b> El hosting usa PHP <?=e($php['version'])?>, que ya incluye la corrección de PHP2Shell (CVE-2026-109618 y CVE-2026-109619).</div>
<?php elseif($php['ok']===false):?><div class="alert error" role="alert"><b>PHP sin la corrección de PHP2Shell.</b> El hosting usa PHP <?=e($php['version'])?> y la rama <?=e($php['branch'])?> está corregida desde la <?=e($php['fixed'])?>. Pide a Hostinger que actualice PHP o confirme por escrito que su versión <?=e($php['version'])?> ya incluye el parche (algunos proveedores lo aplican sin cambiar el número). Mientras tanto, mantén activo el proxy de Cloudflare.</div>
<?php else:?><div class="alert error" role="alert"><b>Rama de PHP sin soporte.</b> PHP <?=e($php['version'])?> ya no recibe versiones oficiales con la corrección de PHP2Shell. En hPanel → Avanzado → Configuración de PHP elige 8.3 o superior.</div><?php endif?>
<p class="hint">Barra de progreso de cargas de PHP (<code>session.upload_progress</code>): <b><?=$php['progress']?'activa':'desactivada'?></b>. OX3 Gestión no la usa; desactivarla reduce la exposición mientras PHP no esté actualizado. Modo de PHP: <?=e($php['sapi'])?>.</p></section>

<section class="card"><h2><?=ic('activity')?>Bitácora de auditoría</h2>
<?php if($av['ok']):?><div class="alert" role="status"><b>Íntegra.</b> Se verificaron los <?=number_format($av['total'],0,',','.')?> registros: ninguno fue modificado, borrado ni intercalado.<?php if(!empty($av['last_hash'])):?> Último eslabón: <code class="hash">#<?=(int)$av['last_id']?> · <?=e(substr($av['last_hash'],0,16))?>…</code><?php endif?></div>
<?php else:?><div class="alert error" role="alert"><b>Se detectó una alteración.</b> <?=e($av['problem'])?> Restaura la base desde un respaldo anterior a ese registro o investiga quién tuvo acceso al servidor.</div><?php endif?>
<p class="hint">Cada acción queda firmada (HMAC-SHA256) y encadenada con la anterior. La verificación se repite cada día y, si falla, se avisa por correo a los administradores. Los registros anteriores a esta versión se sellaron al instalarla.</p>
<div class="btn-row"><a class="btn ghost" href="<?=e(url(['audit_csv'=>1]))?>"><?=ic('download')?>Exportar bitácora completa (CSV)</a><a class="btn ghost" href="<?=e(url(['tab'=>'activity']))?>"><?=ic('list')?>Ver actividad reciente</a></div></section>

<section class="card"><h2><?=ic('database')?>Respaldos cifrados</h2>
<?php if(!$bs['enabled']):?><div class="alert error" role="alert">Los respaldos están desactivados. En <code>config.php</code> define <code>backup_key</code> con una frase de al menos 20 caracteres y guárdala también fuera del hosting: sin ella no se puede abrir ningún respaldo.<?=function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')?'':' Además, habilita la extensión sodium de PHP.'?></div>
<?php else:?>
<p>Cada respaldo incluye <b>todas las empresas</b> (<?=e(implode(' y ',array_column(empresas(),'nombre')))?>): sus bases de datos, documentos y comprobantes, y las claves internas. Se cifran con XChaCha20-Poly1305 y una clave derivada de <code>backup_key</code> (Argon2id). <?php $h=(float)($config['backup_auto_hours']??24); echo $h>0?'Se crea uno automático cada '.e(rtrim(rtrim(number_format($h,1,',',''),'0'),',')).' horas':'El respaldo automático está desactivado'?> y se conservan los últimos <?=max(1,(int)($config['backup_keep']??7))?>.</p>
<?php if(!empty($st['error']) && (int)($st['error_time']??0)>(int)($st['ok_time']??0)):?><div class="alert error" role="alert">El último intento falló (<?=e(fdate(gmdate('c',(int)$st['error_time']),true))?>): <?=e($st['error'])?></div><?php endif?>
<form method="post" class="btn-row"><?=csrf()?><button class="primary" name="action" value="backup_now"><?=ic('database')?>Crear respaldo ahora</button></form>
<?php if($bs['list']):?><div class="table-wrap"><table><thead><tr><th>Respaldo</th><th>Fecha</th><th>Tamaño</th><th class="right">Acciones</th></tr></thead><tbody>
<?php foreach($bs['list'] as $b):?><tr><td><b><?=e($b['file'])?></b></td><td class="nowrap"><?=e(fdate(gmdate('c',$b['time']),true))?></td><td><?=size($b['size'])?></td><td class="right nowrap"><a class="btn ghost small" href="<?=e(url(['backup_dl'=>$b['file']]))?>"><?=ic('download')?>Descargar</a> <form method="post" class="inline-form"><?=csrf()?><input type="hidden" name="file" value="<?=e($b['file'])?>"><button class="ghost small" name="action" value="backup_verify" title="Descifra el respaldo en un temporal y revisa la base y los archivos, sin tocar los datos en uso"><?=ic('check')?>Probar restauración</button></form></td></tr><?php endforeach?>
</tbody></table></div><?php else:?><p class="hint">Aún no hay respaldos.</p><?php endif?>
<p class="hint">Descarga periódicamente un respaldo y guárdalo fuera del hosting (por ejemplo, en un disco o nube de la empresa). Para restaurarlo sigue la sección <b>Respaldos</b> de INSTALACION.md.</p>
<?php endif?></section>

<section class="card"><h2><?=ic('mail')?>Correo</h2>
<?php if(!$mailOn):?><p>El envío de correos no está configurado: los avisos se ven solo en <b>Pendientes</b> y en la campana. Para activarlo, crea una casilla (por ejemplo notificaciones@ox3.cl) en hPanel → Correos y completa <code>smtp_host</code>, <code>smtp_user</code> y <code>smtp_pass</code> en <code>config.php</code>.</p>
<?php else:?><p>Se envía con <b><?=mailMode()==='smtp'?'SMTP '.e($config['smtp_host']).':'.(int)($config['smtp_port']??465):'mail() de PHP'?></b> desde <b><?=e(trim((string)($config['mail_from']??''))?:(string)($config['smtp_user']??''))?></b>. Pendientes de envío: <?=(int)$mq['pend']?> · fallidos: <?=(int)$mq['bad']?>.</p>
<?php if($lastErr!==''):?><p class="hint">Último error: <?=e($lastErr)?></p><?php endif?>
<form method="post" class="btn-row"><?=csrf()?><button class="primary" name="action" value="mail_test"><?=ic('mail')?>Enviarme un correo de prueba</button><?php if((int)$mq['bad']):?><button class="ghost" name="action" value="mail_retry">Reintentar fallidos</button><?php endif?></form><?php endif?></section>

<section class="card"><h2><?=ic('shield')?>Verificación en dos pasos</h2>
<p><?php $roles=$config['mfa_roles']??[]; echo is_array($roles)&&$roles?'Obligatoria para: '.e(implode(', ',array_map(fn($r)=>strtolower($roleName[$r]??$r).'es',$roles))).'. Al ingresar sin tenerla activa, se les pide activarla antes de continuar.':'Opcional para todos (config <code>mfa_roles</code> vacío). Cada usuario puede activarla en Mi cuenta.'?></p>
<ul class="mfa-users"><?php foreach($users as $u):?><li><span><?=e($u['name'])?> <small><?=e($roleName[$u['role']]??$u['role'])?></small></span><?=$u['mfa_on']?'<span class="status on">Activa</span>':'<span class="status off">'.(mfaRequired($u)?'Pendiente':'No activa').'</span>'?></li><?php endforeach?></ul>
<p class="hint">Si alguien pierde su teléfono y sus códigos de recuperación, restablécela en <a href="<?=e(url(['tab'=>'users']))?>">Usuarios</a>.</p></section>
<?php endif?>
