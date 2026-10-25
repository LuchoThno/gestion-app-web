<?php
// Notificaciones: avisos dentro de la plataforma, correos (SMTP de Hostinger o mail() de PHP) y pendientes por usuario.
// Se incluye desde app.php después de security.php. Los correos se encolan y se envían después de responder al navegador.
$db->exec('CREATE TABLE IF NOT EXISTS notifications(id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id), kind TEXT NOT NULL, title TEXT NOT NULL, body TEXT NOT NULL DEFAULT \'\', link TEXT NOT NULL DEFAULT \'\', created TEXT NOT NULL, read_at TEXT);
CREATE INDEX IF NOT EXISTS notifications_user ON notifications(user_id, read_at);
CREATE TABLE IF NOT EXISTS mail_outbox(id INTEGER PRIMARY KEY, to_email TEXT NOT NULL, to_name TEXT NOT NULL DEFAULT \'\', subject TEXT NOT NULL, body TEXT NOT NULL, link TEXT NOT NULL DEFAULT \'\', created TEXT NOT NULL, sent TEXT, attempts INTEGER NOT NULL DEFAULT 0, claimed INTEGER NOT NULL DEFAULT 0, last_error TEXT NOT NULL DEFAULT \'\');
CREATE INDEX IF NOT EXISTS mail_outbox_pending ON mail_outbox(sent, attempts);
CREATE TABLE IF NOT EXISTS sys_state(key TEXT PRIMARY KEY, value TEXT NOT NULL);');
// Columnas nuevas de usuarios: preferencia de correo y verificación en dos pasos.
$userCols=array_column($db->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC),'name');
foreach(['notify_email'=>'INTEGER NOT NULL DEFAULT 1','mfa_on'=>'INTEGER NOT NULL DEFAULT 0','mfa_secret'=>"TEXT NOT NULL DEFAULT ''",'mfa_last'=>'INTEGER NOT NULL DEFAULT 0','mfa_recovery'=>"TEXT NOT NULL DEFAULT ''"] as $c=>$def)
    if(!in_array($c,$userCols,true)) $db->exec("ALTER TABLE users ADD COLUMN $c $def");

function stateGet(string $k, string $default=''): string { $v=query('SELECT value FROM sys_state WHERE key=?',[$k])->fetchColumn(); return $v===false?$default:(string)$v; }
function stateSet(string $k, string $v): void { query('INSERT INTO sys_state(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value',[$k,$v]); }

/** Correo configurado: 'smtp', 'mail' o '' (desactivado: los avisos quedan solo en la plataforma). */
function mailMode(): string {
    global $config;
    if(trim((string)($config['smtp_host']??''))!=='') return 'smtp';
    if(trim((string)($config['mail_from']??''))!=='' && function_exists('mail')) return 'mail';
    return '';
}
function appUrl(array $q=[]): string {
    global $config;
    $base=trim((string)($config['app_url']??'')); if($base==='') $base='https://gestion.ox3.cl/';
    if(!str_ends_with($base,'/')) $base.='/';
    return $base.'index.php'.($q?'?'.http_build_query($q):'');
}
/** Destino de un aviso: solo parámetros enteros conocidos o pestañas válidas (se valida otra vez al abrirlo). */
function notifyLink(array $q): string {
    $ok=[]; foreach($q as $k=>$v) if(in_array($k,['oc','cot','mov','rend','pres','p','f','preview'],true) && is_int($v) && $v>0) $ok[$k]=$v; elseif($k==='tab' && is_string($v) && preg_match('/^[a-z_]{1,20}$/',$v)) $ok[$k]=$v;
    if($ok && empresa()['key']!==EMPRESA_BASE) $ok['emp']=empresa()['key']; // el enlace abre la empresa del aviso
    return $ok?http_build_query($ok):'';
}
/**
 * Crea un aviso para cada usuario activo indicado (excepto quien hizo la acción) y, si corresponde, encola el correo.
 * $email: 'pref' respeta la preferencia del usuario; 'always' para avisos de seguridad; 'never' solo en la plataforma.
 */
function notify(array $userIds, string $kind, string $title, string $body, array $link=[], string $email='pref', bool $skipActor=true): int {
    $actor=(int)($_SESSION['uid']??0); $n=0; $lnk=notifyLink($link); $done=[];
    foreach($userIds as $uid){
        $uid=(int)$uid; if($uid<=0 || isset($done[$uid]) || ($skipActor && $uid===$actor)) continue; $done[$uid]=true;
        $u=query('SELECT id,name,email,notify_email FROM users WHERE id=? AND active=1',[$uid])->fetch(PDO::FETCH_ASSOC); if(!$u) continue;
        query('INSERT INTO notifications(user_id,kind,title,body,link,created) VALUES(?,?,?,?,?,?)',[$uid,$kind,shortText($title,160),shortText($body,600),$lnk,gmdate('c')]);
        if($email==='always' || ($email==='pref' && (int)$u['notify_email']===1)) mailQueue($u['email'],$u['name'],$title,$body,$lnk);
        $n++;
    }
    return $n;
}
function adminIds(): array { return array_map('intval',query("SELECT id FROM users WHERE role='admin' AND active=1")->fetchAll(PDO::FETCH_COLUMN)); }
function userName(?int $id): string { return $id ? (string)query('SELECT name FROM users WHERE id=?',[$id])->fetchColumn() : ''; }
function mailQueue(string $to, string $name, string $subject, string $body, string $link=''): void {
    if(mailMode()==='' || !filter_var($to,FILTER_VALIDATE_EMAIL)) return;
    if(empresa()['key']!==EMPRESA_BASE) $subject='['.empresa()['corto'].'] '.$subject; // avisos de otra empresa: se distinguen en la bandeja
    query('INSERT INTO mail_outbox(to_email,to_name,subject,body,link,created) VALUES(?,?,?,?,?,?)',[$to,$name,shortText($subject,160),$body,$link,gmdate('c')]);
    $GLOBALS['mailPending']=true;
}

// ---------- Envío ----------
function mailHeaderText(string $s): string { return preg_match('/[^\x20-\x7E]/',$s) ? '=?UTF-8?B?'.base64_encode($s).'?=' : $s; }
/** Mensaje MIME (texto + HTML sencillo) listo para SMTP o mail(). Devuelve [cabeceras, cuerpo]. */
function mailBuild(array $m): array {
    global $config;
    $from=trim((string)($config['mail_from']??''))?:trim((string)($config['smtp_user']??''));
    $fromName=trim((string)($config['mail_from_name']??''))?:'OX3 Gestión';
    $url=$m['link']!==''?appUrl([])."?".$m['link']:appUrl();
    $hello='Hola'.($m['to_name']!==''?' '.explode(' ',$m['to_name'])[0]:'').',';
    $foot='Este es un aviso automático de la plataforma de gestión OX3 (gestion.ox3.cl). Puedes desactivar los avisos por correo en Mi cuenta.';
    $text=$hello."\n\n".$m['body']."\n\nAbrir en la plataforma: ".$url."\n\n— \n".$foot."\n";
    $h=fn($s)=>htmlspecialchars($s,ENT_QUOTES,'UTF-8');
    $html='<!doctype html><html lang="es"><body style="margin:0;background:#f4f7fb;font:15px/1.5 Arial,sans-serif;color:#172e47"><div style="max-width:560px;margin:0 auto;padding:24px">'
        .'<div style="font:bold 22px Arial;color:#0f2a45">OX<span style="color:#f28c31">3</span> <span style="font-size:12px;color:#6c7c8d;letter-spacing:2px">GESTIÓN</span></div>'
        .'<div style="background:#fff;border:1px solid #e3e9ef;border-radius:12px;padding:22px;margin-top:14px"><p style="margin:0 0 12px">'.$h($hello).'</p><p style="margin:0 0 6px;font-weight:bold">'.$h($m['subject']).'</p><p style="margin:0 0 18px;white-space:pre-line">'.$h($m['body']).'</p>'
        .'<a href="'.$h($url).'" style="display:inline-block;background:#1669c1;color:#fff;text-decoration:none;font-weight:bold;padding:10px 16px;border-radius:8px">Abrir en la plataforma</a></div>'
        .'<p style="font-size:12px;color:#6c7c8d;margin-top:14px">'.$h($foot).'</p></div></body></html>';
    $b='=_ox3_'.bin2hex(random_bytes(8));
    $domain=substr(strrchr($from,'@')?:'@ox3.cl',1);
    $headers=['From: '.mailHeaderText($fromName).' <'.$from.'>','To: '.($m['to_name']!==''?mailHeaderText($m['to_name']).' ':'').'<'.$m['to_email'].'>','Subject: '.mailHeaderText($m['subject']),
        'Date: '.date('r'),'Message-ID: <'.bin2hex(random_bytes(12)).'@'.$domain.'>','MIME-Version: 1.0','Content-Type: multipart/alternative; boundary="'.$b.'"','Auto-Submitted: auto-generated'];
    $body="--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($text))
        ."--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($html))."--$b--\r\n";
    return [$headers,$body,$from];
}
/** Envía un mensaje por SMTP (SSL en 465 o STARTTLS en 587). Lanza RuntimeException con el detalle si falla. */
function smtpSend(array $m): void {
    global $config;
    [$headers,$body,$from]=mailBuild($m);
    $host=trim((string)$config['smtp_host']); $port=(int)($config['smtp_port']??465); $sec=strtolower((string)($config['smtp_secure']??'ssl'));
    $ctx=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host]]);
    $fp=@stream_socket_client(($sec==='ssl'?'ssl://':'tcp://').$host.':'.$port,$errno,$errstr,15,STREAM_CLIENT_CONNECT,$ctx);
    if(!$fp) throw new RuntimeException("No se pudo conectar a $host:$port ($errstr)");
    stream_set_timeout($fp,20);
    $read=function() use($fp): array { $data=''; while(($l=fgets($fp,1024))!==false){ $data.=$l; if(strlen($l)<4 || $l[3]===' ') break; } return [(int)substr($data,0,3),trim($data)]; };
    $cmd=function(string $c, array $ok) use($fp,$read): string { if($c!=='') fwrite($fp,$c."\r\n"); [$code,$txt]=$read(); if(!in_array($code,$ok,true)) throw new RuntimeException('SMTP: '.(str_starts_with($c,'AUTH')||preg_match('/^[A-Za-z0-9+\/=]+$/',$c)?'autenticación rechazada':$txt)); return $txt; };
    try {
        $cmd('',[220]); $ehlo='EHLO '.(safeCall('gethostname')?:'localhost');
        $caps=$cmd($ehlo,[250]);
        if($sec==='tls'){ $cmd('STARTTLS',[220]); if(!stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT|STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) throw new RuntimeException('SMTP: no se pudo iniciar TLS'); $caps=$cmd($ehlo,[250]); }
        $user=(string)($config['smtp_user']??''); $pass=(string)($config['smtp_pass']??'');
        if($user!==''){ $cmd('AUTH LOGIN',[334]); $cmd(base64_encode($user),[334]); $cmd(base64_encode($pass),[235]); }
        $cmd('MAIL FROM:<'.$from.'>',[250]); $cmd('RCPT TO:<'.$m['to_email'].'>',[250,251]); $cmd('DATA',[354]);
        $data=implode("\r\n",$headers)."\r\n\r\n".$body;
        $data=preg_replace('/^\./m','..',$data); // dot-stuffing
        fwrite($fp,$data."\r\n.\r\n"); [$code,$txt]=$read(); if($code!==250) throw new RuntimeException('SMTP: '.$txt);
        fwrite($fp,"QUIT\r\n");
    } finally { fclose($fp); }
}
function mailSendOne(array $m): void {
    if(mailMode()==='smtp') { smtpSend($m); return; }
    [$headers,$body,$from]=mailBuild($m);
    $hs=array_values(array_filter($headers,fn($h)=>!str_starts_with($h,'To: ')&&!str_starts_with($h,'Subject: ')));
    if(!mail($m['to_email'],mailHeaderText($m['subject']),$body,implode("\r\n",$hs),'-f'.$from)) throw new RuntimeException('mail() de PHP no aceptó el mensaje');
}
/** Envía los correos pendientes (máx. $max). Cada mensaje se reserva para que dos procesos no lo envíen dos veces. */
function mailFlush(int $max=20): int {
    if(mailMode()==='') return 0;
    $sent=0;
    foreach(query('SELECT * FROM mail_outbox WHERE sent IS NULL AND attempts<5 AND claimed<? ORDER BY id LIMIT ?',[time()-300,$max])->fetchAll(PDO::FETCH_ASSOC) as $m){
        if(query('UPDATE mail_outbox SET claimed=?,attempts=attempts+1 WHERE id=? AND sent IS NULL AND claimed<?',[time(),$m['id'],time()-300])->rowCount()!==1) continue;
        try { mailSendOne($m); query('UPDATE mail_outbox SET sent=?,last_error=\'\' WHERE id=?',[gmdate('c'),$m['id']]); $sent++; }
        catch(Throwable $ex){ error_log('Correo #'.$m['id'].': '.$ex->getMessage()); query('UPDATE mail_outbox SET last_error=?,claimed=0 WHERE id=?',[shortText($ex->getMessage(),300),$m['id']]); }
    }
    query("DELETE FROM mail_outbox WHERE sent IS NOT NULL AND sent < ?",[gmdate('c',time()-30*86400)]);
    return $sent;
}

// ---------- Eventos de los flujos ----------
/** Avisos por cambios de estado de rendiciones, OC y cotizaciones. Se llama después del audit() de cada acción. */
function wfEvent(string $type, array $row, string $estado): void {
    global $user;
    $who=$user?$user['name']:'Sistema'; $owner=(int)($row['owner']??0);
    if($type==='rend'){
        $t=ccRendTotals($row); $link=['rend'=>(int)$row['id']]; $num=$row['numero'];
        if($estado==='Enviada') notify(adminIds(),'rend_pend','Rendición '.$num.' por aprobar',$who.' envió la rendición «'.$row['titulo'].'» por '.clp($t['gastos']).' ('.$t['n'].' gasto'.($t['n']===1?'':'s').'). Revísala y apruébala o recházala.',$link);
        elseif($estado==='Aprobada') notify([$owner],'rend_ok','Rendición '.$num.' aprobada',$who.' aprobó tu rendición «'.$row['titulo'].'» por '.clp($t['gastos']).'. '.ccSaldoText($t['saldo']).': '.clp(abs($t['saldo'])).'.'.(($row['revision']??'')!==''?"\nComentario: ".$row['revision']:''),$link);
        elseif($estado==='Rechazada') notify([$owner],'rend_bad','Rendición '.$num.' rechazada',$who.' rechazó tu rendición «'.$row['titulo'].'».'."\nMotivo: ".($row['revision']??'').". Corrígela y vuelve a enviarla.",$link);
        elseif($estado==='Borrador') notify([$owner],'rend_open','Rendición '.$num.' devuelta a borrador',$who.' devolvió tu rendición «'.$row['titulo'].'» a borrador. Puedes modificarla y enviarla otra vez.',$link);
        return;
    }
    $isOc=$type==='oc';
    $r=query($isOc?'SELECT id,numero,owner,prov_razon AS party,obj_titulo AS what FROM oc_orders WHERE id=?':'SELECT id,numero,owner,cli_razon AS party,obj_titulo AS what FROM cot_quotes WHERE id=?',[(int)$row['id']])->fetch(PDO::FETCH_ASSOC);
    if(!$r) return;
    $label=($isOc?'OC ':'Cotización ').$r['numero']; $link=[$isOc?'oc':'cot'=>(int)$r['id']];
    $desc=implode(' · ',array_filter([(string)$r['party'],(string)$r['what']],'strlen'));
    $ids=[(int)$r['owner']];
    if($isOc && $estado==='Recibida') { $ids=array_merge($ids,adminIds()); $title=$label.' recibida: queda por pagar'; }
    elseif(!$isOc && $estado==='Aceptada') { $ids=array_merge($ids,adminIds()); $title=$label.' aceptada por el cliente'; }
    elseif(!$isOc && $estado==='Rechazada') $title=$label.' rechazada por el cliente';
    else $title=$label.': '.$estado;
    notify($ids,$type.'_estado',$title,$who.' cambió la '.$label.($desc!==''?' ('.$desc.')':'').' a «'.$estado.'».',$link);
}

// ---------- Pendientes ----------
/** Lista de pendientes del usuario: [['kind','title','detail','link'=>[...]], ...]. */
function pendingItems(array $u): array {
    $out=[]; $admin=$u['role']==='admin'; $edit=in_array($u['role'],['admin','editor'],true);
    if($admin) foreach(query("SELECT r.*, u.name AS who FROM cc_rendiciones r LEFT JOIN users u ON u.id=r.owner WHERE r.estado='Enviada' ORDER BY r.sent")->fetchAll(PDO::FETCH_ASSOC) as $r){ $t=ccRendTotals($r);
        $out[]=['kind'=>'aprobar','title'=>'Aprobar rendición '.$r['numero'],'detail'=>$r['titulo'].' · '.clp($t['gastos']).' · enviada por '.($r['who']?:$r['responsable']).($r['sent']?' el '.ccDay($r['sent']):''),'link'=>['rend'=>(int)$r['id']],'since'=>$r['sent']]; }
    if($edit){
        foreach(query("SELECT * FROM cc_rendiciones WHERE estado='Rechazada' AND owner=? ORDER BY reviewed",[$u['id']])->fetchAll(PDO::FETCH_ASSOC) as $r)
            $out[]=['kind'=>'corregir','title'=>'Corregir rendición '.$r['numero'],'detail'=>$r['titulo'].' · rechazada'.($r['revision']!==''?': '.$r['revision']:''),'link'=>['rend'=>(int)$r['id']],'since'=>$r['reviewed']];
        foreach(query("SELECT r.* FROM cc_rendiciones r WHERE r.estado='Borrador' AND r.owner=? AND EXISTS(SELECT 1 FROM cc_movs m WHERE m.rendicion_id=r.id) ORDER BY r.updated",[$u['id']])->fetchAll(PDO::FETCH_ASSOC) as $r){ $t=ccRendTotals($r);
            $out[]=['kind'=>'enviar','title'=>'Enviar rendición '.$r['numero'],'detail'=>$r['titulo'].' · '.$t['n'].' gasto'.($t['n']===1?'':'s').' por '.clp($t['gastos']).' en borrador','link'=>['rend'=>(int)$r['id']],'since'=>$r['updated']]; }
    }
    $out=array_merge($out,psPending($u));
    if($admin){
        foreach(query("SELECT id,numero,prov_razon,total,updated FROM oc_orders WHERE estado='Recibida' ORDER BY updated")->fetchAll(PDO::FETCH_ASSOC) as $o)
            $out[]=['kind'=>'pagar','title'=>'Pagar OC '.$o['numero'],'detail'=>$o['prov_razon'].' · '.clp((int)$o['total']).' · recibida, por pagar','link'=>['oc'=>(int)$o['id']],'since'=>$o['updated']];
        $missing=array_filter(query('SELECT id,name,role,mfa_on FROM users WHERE active=1')->fetchAll(PDO::FETCH_ASSOC),fn($x)=>!(int)$x['mfa_on'] && mfaRequired($x));
        if($missing) $out[]=['kind'=>'seguridad','title'=>count($missing).' usuario'.(count($missing)===1?'':'s').' sin verificación en dos pasos','detail'=>implode(', ',array_column($missing,'name')).'. Se les pedirá activarla al ingresar.','link'=>['tab'=>'users'],'since'=>null];
        $b=backupStatus();
        if($b['enabled'] && ($b['last']===null || time()-$b['last']['time']>48*3600)) $out[]=['kind'=>'seguridad','title'=>$b['last']?'El último respaldo tiene más de 2 días':'Aún no hay respaldos','detail'=>'Crea un respaldo cifrado y descárgalo a un lugar seguro.','link'=>['tab'=>'security'],'since'=>null];
        elseif(!$b['enabled']) $out[]=['kind'=>'seguridad','title'=>'Respaldos cifrados sin configurar','detail'=>'Define backup_key en config.php para activarlos.','link'=>['tab'=>'security'],'since'=>null];
        if(stateGet('audit_broken')!=='') $out[]=['kind'=>'seguridad','title'=>'La bitácora presenta alteraciones','detail'=>stateGet('audit_broken'),'link'=>['tab'=>'security'],'since'=>null];
        if(mailMode()!=='' && ($nf=(int)query('SELECT COUNT(*) FROM mail_outbox WHERE sent IS NULL AND attempts>=5')->fetchColumn())>0) $out[]=['kind'=>'seguridad','title'=>$nf.' correo'.($nf===1?'':'s').' sin enviar','detail'=>'Revisa la configuración SMTP en Seguridad.','link'=>['tab'=>'security'],'since'=>null];
    }
    return $out;
}

// ---------- Tareas después de responder ----------
/**
 * Tareas diarias (también las ejecuta cron.php): recordatorio de rendiciones por aprobar con más de un día,
 * verificación de la bitácora y respaldo automático si corresponde.
 */
function dailyTasks(bool $force=false): array {
    global $config; $log=[];
    $today=(new DateTime('now',new DateTimeZone('America/Santiago')))->format('Y-m-d');
    if($force || stateGet('daily_run')!==$today){
        stateSet('daily_run',$today);
        $old=query("SELECT numero FROM cc_rendiciones WHERE estado='Enviada' AND sent < ? ORDER BY sent",[gmdate('c',time()-86400)])->fetchAll(PDO::FETCH_COLUMN);
        if($old){ notify(adminIds(),'rend_pend','Recordatorio: '.count($old).' rendici'.(count($old)===1?'ón espera':'ones esperan').' aprobación','Llevan más de un día por aprobar: '.implode(', ',$old).'.',['tab'=>'pend'],'pref',false); $log[]='Recordatorio enviado ('.count($old).')'; }
        $v=auditVerify();
        $prevBroken=stateGet('audit_broken');
        if(!$v['ok'] && $prevBroken!==$v['problem']){ stateSet('audit_broken',$v['problem']); notify(adminIds(),'seguridad','Alerta: la bitácora fue alterada',$v['problem'].' Revisa Seguridad en la plataforma.',['tab'=>'security'],'always',false); }
        elseif($v['ok'] && $prevBroken!=='') stateSet('audit_broken','');
        $log[]='Bitácora: '.($v['ok']?'íntegra ('.$v['total'].' registros)':$v['problem']);
    }
    $hours=(float)($config['backup_auto_hours']??24);
    if($hours>0 && backupEnabled()){
        $b=backupStatus();
        if($b['last']===null || time()-$b['last']['time']>=$hours*3600){
            try { $r=backupCreate('automático'); $log[]='Respaldo creado: '.$r['file']; }
            catch(Throwable $ex){ $log[]='Respaldo falló: '.$ex->getMessage(); }
        }
    }
    $sent=mailFlush(50); if($sent) $log[]=$sent.' correo(s) enviados';
    return $log;
}
/** Al terminar cada petición web: libera al navegador y envía correos / tareas pendientes sin hacer esperar al usuario. */
function afterResponse(): void {
    global $user;
    $needMail=!empty($GLOBALS['mailPending']) || (mailMode()!=='' && random_int(1,20)===1);
    $today=(new DateTime('now',new DateTimeZone('America/Santiago')))->format('Y-m-d');
    $needDaily=$user && stateGet('daily_run')!==$today;
    $needBackup=$user && backupDue();
    if(!$needMail && !$needDaily && !$needBackup) return;
    if(session_status()===PHP_SESSION_ACTIVE) session_write_close();
    if(function_exists('fastcgi_finish_request')) fastcgi_finish_request(); elseif(function_exists('litespeed_finish_request')) litespeed_finish_request();
    safeCall('ignore_user_abort',true); safeCall('set_time_limit',600);
    try { if($needDaily||$needBackup) dailyTasks(); else mailFlush(20); }
    catch(Throwable $ex){ error_log('Tareas en segundo plano: '.$ex); }
}
