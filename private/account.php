<?php
// Acciones de Mi cuenta (verificación en dos pasos, avisos), Pendientes y Seguridad (respaldos, bitácora, correo).
// Se incluye desde app.php; accountPost() corre dentro del try con usuario autenticado.

/** Verifica el código TOTP actual del usuario (o lanza error) y lo marca como usado. */
function mfaCheckCurrent(array $u, string $code): void {
    $ctr=totpMatch(openSecret((string)$u['mfa_secret']),$code,(int)$u['mfa_last']);
    if($ctr===null || query('UPDATE users SET mfa_last=? WHERE id=? AND mfa_last<?',[$ctr,$u['id'],$ctr])->rowCount()!==1) throw new RuntimeException('El código de verificación no es correcto o ya se usó. Escribe el código actual de tu app.');
}
function accountPost(string $action): void {
    global $user, $isAdmin;
    $uid=(int)$user['id'];
    // --- Verificación en dos pasos ---
    if ($action==='mfa_begin' || $action==='mfa_restart') {
        if((int)$user['mfa_on']) throw new RuntimeException('La verificación en dos pasos ya está activa.');
        $_SESSION['mfa_new']=b32encode(random_bytes(20)); go('',['tab'=>'account']);
    }
    if ($action==='mfa_setup_cancel') { unset($_SESSION['mfa_new']); go('',['tab'=>'account']); }
    if ($action==='mfa_enable') {
        $b32=(string)($_SESSION['mfa_new']??''); if($b32==='') throw new RuntimeException('Comienza nuevamente la activación.');
        $ctr=totpMatch(b32decode($b32),post('code'));
        if($ctr===null) throw new RuntimeException('El código no coincide. Revisa que escaneaste el código QR más reciente y que la hora del teléfono sea automática.');
        [$codes,$hashes]=recoveryCodes();
        query('UPDATE users SET mfa_on=1,mfa_secret=?,mfa_last=?,mfa_recovery=? WHERE id=?',[sealSecret(b32decode($b32)),$ctr,json_encode($hashes),$uid]);
        unset($_SESSION['mfa_new']); $_SESSION['mfa_codes']=$codes;
        audit('Activación de verificación en dos pasos');
        notify([$uid],'seguridad','Activaste la verificación en dos pasos','Desde ahora, al ingresar a OX3 Gestión te pediremos el código de 6 dígitos de tu app de autenticación. Guarda tus códigos de recuperación en un lugar seguro.',['tab'=>'account'],'always',false);
        go('Verificación en dos pasos activada. Guarda ahora tus códigos de recuperación.',['tab'=>'account']);
    }
    if ($action==='mfa_codes_ok') { unset($_SESSION['mfa_codes']); go('',['tab'=>'account']); }
    if ($action==='mfa_regen') {
        if(!(int)$user['mfa_on']) throw new RuntimeException('Primero activa la verificación en dos pasos.');
        mfaCheckCurrent($user,post('code'));
        [$codes,$hashes]=recoveryCodes(); query('UPDATE users SET mfa_recovery=? WHERE id=?',[json_encode($hashes),$uid]);
        $_SESSION['mfa_codes']=$codes; audit('Nuevos códigos de recuperación');
        go('Generamos 10 códigos nuevos; los anteriores ya no sirven.',['tab'=>'account']);
    }
    if ($action==='mfa_disable') {
        if(!(int)$user['mfa_on']) go('',['tab'=>'account']);
        if(mfaRequired($user)) throw new RuntimeException('Tu permiso exige la verificación en dos pasos; no se puede desactivar.');
        if(!password_verify(passwordInput(),$user['password'])) throw new RuntimeException('La contraseña no es correcta.');
        mfaCheckCurrent($user,post('code'));
        query("UPDATE users SET mfa_on=0,mfa_secret='',mfa_recovery='' WHERE id=?",[$uid]); audit('Desactivación de verificación en dos pasos');
        notify([$uid],'seguridad','Desactivaste la verificación en dos pasos','Tu cuenta vuelve a pedir solo contraseña. Si no fuiste tú, avisa de inmediato al administrador.',['tab'=>'account'],'always',false);
        go('Verificación en dos pasos desactivada.',['tab'=>'account']);
    }
    if ($action==='mfa_reset' && $isAdmin) {
        $t=query('SELECT * FROM users WHERE id=?',[(int)post('id')])->fetch(PDO::FETCH_ASSOC);
        if(!$t || (int)$t['id']===$uid) throw new RuntimeException('No puedes restablecer tu propia verificación desde aquí.');
        query("UPDATE users SET mfa_on=0,mfa_secret='',mfa_recovery='',mfa_last=0 WHERE id=?",[$t['id']]); audit('Restablecimiento de verificación en dos pasos',$t['email']);
        notify([(int)$t['id']],'seguridad','Se restableció tu verificación en dos pasos',$user['name'].' restableció la verificación en dos pasos de tu cuenta (por ejemplo, por cambio de teléfono).'.(mfaRequired($t)?' En tu próximo ingreso deberás activarla de nuevo escaneando un código QR.':''),['tab'=>'account'],'always');
        go('Verificación en dos pasos restablecida para '.$t['name'].'.',['tab'=>'users']);
    }
    // --- Avisos ---
    if ($action==='notify_pref') {
        query('UPDATE users SET notify_email=? WHERE id=?',[post('notify_email')==='1'?1:0,$uid]); audit('Preferencia de avisos por correo',post('notify_email')==='1'?'Sí':'No');
        go('Preferencia guardada.',['tab'=>'account']);
    }
    if ($action==='notif_read_all') { query('UPDATE notifications SET read_at=? WHERE user_id=? AND read_at IS NULL',[gmdate('c'),$uid]); go('',['tab'=>'pend']); }
    // --- Seguridad (administrador) ---
    if ($action==='backup_now' && $isAdmin) {
        safeCall('set_time_limit',600); $r=backupCreate('manual');
        go('Respaldo creado: '.$r['file'].' ('.$r['docs'].' archivos). Descárgalo y guárdalo fuera del hosting.',['tab'=>'security']);
    }
    if ($action==='backup_verify' && $isAdmin) {
        safeCall('set_time_limit',600); $i=backupVerify(post('file')); audit('Prueba de restauración de respaldo',post('file'));
        go('Restauración de prueba correcta: '.post('file').' se descifró y sus bases pasaron la revisión de integridad ('.$i['empresas'].' empresa'.($i['empresas']===1?'':'s').', '.$i['documentos'].' documentos y comprobantes, '.$i['usuarios'].' usuarios).',['tab'=>'security']);
    }
    if ($action==='mail_test' && $isAdmin) {
        if(mailMode()==='') throw new RuntimeException('El correo no está configurado. Completa smtp_host, smtp_user y smtp_pass en config.php.');
        try { mailSendOne(['to_email'=>$user['email'],'to_name'=>$user['name'],'subject'=>'Prueba de correo de OX3 Gestión','body'=>'Si recibes este mensaje, los avisos por correo funcionan correctamente.','link'=>'tab=security']); }
        catch(Throwable $ex) { throw new RuntimeException('No se pudo enviar el correo de prueba: '.$ex->getMessage()); }
        audit('Correo de prueba',$user['email']); go('Correo de prueba enviado a '.$user['email'].'. Revisa también la carpeta de spam.',['tab'=>'security']);
    }
    if ($action==='mail_retry' && $isAdmin) { query('UPDATE mail_outbox SET attempts=0,claimed=0 WHERE sent IS NULL'); $GLOBALS['mailPending']=true; go('Reintentaremos enviar los correos pendientes.',['tab'=>'security']); }
}
/** GET: abrir un aviso, descargar un respaldo o exportar la bitácora. */
function accountGetRequests(): void {
    global $user, $isAdmin;
    if(isset($_GET['notif'])) {
        if(!$user) go();
        $n=query('SELECT * FROM notifications WHERE id=? AND user_id=?',[(int)$_GET['notif'],$user['id']])->fetch(PDO::FETCH_ASSOC);
        if(!$n) go('',['tab'=>'pend']);
        if($n['read_at']===null) query('UPDATE notifications SET read_at=? WHERE id=?',[gmdate('c'),$n['id']]);
        parse_str($n['link'],$q); $to=[];
        foreach($q as $k=>$v) $to[$k]=$k==='tab'?(string)$v:(int)$v;
        go('',$to?:['tab'=>'pend']);
    }
    if(isset($_GET['backup_dl'])) {
        if(!$isAdmin) stop(403,'Solo el administrador descarga respaldos.');
        $f=is_string($_GET['backup_dl'])?$_GET['backup_dl']:'';
        if(!preg_match('/^ox3-respaldo-\d{8}-\d{6}\.ox3bak$/',$f) || !is_file(backupDir().'/'.$f)) stop(404,'Respaldo no encontrado.');
        audit('Descarga de respaldo',$f); session_write_close();
        header('Content-Type: application/octet-stream'); header('Content-Length: '.filesize(backupDir().'/'.$f)); header('Content-Disposition: attachment; filename="'.$f.'"');
        readfile(backupDir().'/'.$f); exit;
    }
    if(isset($_GET['audit_csv'])) {
        if(!$isAdmin) stop(403,'Solo el administrador exporta la bitácora.');
        audit('Exportación de bitácora'); session_write_close();
        header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="bitacora_'.strtolower(empresa()['corto']).'_'.gmdate('Y-m-d').'.csv"');
        $out=fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF");
        fputcsv($out,['N°','Fecha (UTC)','Usuario','Acción','Detalle','IP','Hash anterior','Hash'],';','"','');
        $st=query('SELECT audit.*, users.email FROM audit LEFT JOIN users ON users.id=audit.actor ORDER BY audit.id');
        while($a=$st->fetch(PDO::FETCH_ASSOC)) fputcsv($out,array_map(fn($v)=>is_string($v)&&preg_match('/^[=+\-@\t\r]/',$v)?"'".$v:$v,[$a['id'],$a['created'],$a['email']??'Sistema',$a['action'],$a['detail'],$a['ip'],$a['prev'],$a['hash']]),';','"','');
        fclose($out); exit;
    }
}
