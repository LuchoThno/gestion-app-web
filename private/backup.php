<?php
if(!function_exists('safeCall')){ function safeCall(string $fn, mixed ...$args): mixed { return function_exists($fn) ? @$fn(...$args) : null; } } // respaldo.php lo usa sin app.php
// Respaldos cifrados: bases SQLite de todas las empresas (copia consistente), documentos, comprobantes, app.key, audit.anchor y config.php
// en un ZIP cifrado con XChaCha20-Poly1305 (libsodium, secretstream) y una clave derivada con Argon2id de backup_key.
// Este archivo solo define funciones: respaldo.php lo usa para descifrar o restaurar en cualquier computador con PHP.

const BAK_MAGIC="OX3BAK1\n";
const BAK_CHUNK=1048576;

/** Cifra $src en $dst con la frase $pass. */
function bakEncryptFile(string $src, string $dst, string $pass): void {
    $in=fopen($src,'rb'); $out=fopen($dst,'wb'); if(!$in||!$out) throw new RuntimeException('No se pudo abrir el archivo temporal del respaldo.');
    try {
        $salt=random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES); $ops=SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE; $mem=SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE;
        $key=sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,$pass,$salt,$ops,$mem,SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        [$state,$header]=sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        fwrite($out,BAK_MAGIC.$salt.pack('NN',$ops,intdiv($mem,1024)).$header);
        $size=filesize($src); $done=0;
        do {
            $chunk=(string)fread($in,BAK_CHUNK); $done+=strlen($chunk); $final=$done>=$size || feof($in);
            $c=sodium_crypto_secretstream_xchacha20poly1305_push($state,$chunk,'',$final?SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL:SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
            fwrite($out,pack('N',strlen($c)).$c);
        } while(!$final);
        sodium_memzero($key);
    } finally { fclose($in); fclose($out); }
}
/** Descifra $src en $dst. Falla si la frase es incorrecta o si el archivo está dañado o incompleto. */
function bakDecryptFile(string $src, string $dst, string $pass): void {
    $in=fopen($src,'rb'); if(!$in) throw new RuntimeException('No se pudo abrir el respaldo.');
    $out=null;
    try {
        $hlen=strlen(BAK_MAGIC)+SODIUM_CRYPTO_PWHASH_SALTBYTES+8+SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;
        $h=fread($in,$hlen); if(!is_string($h) || strlen($h)!==$hlen || !str_starts_with($h,BAK_MAGIC)) throw new RuntimeException('El archivo no es un respaldo de OX3 Gestión.');
        $p=strlen(BAK_MAGIC); $salt=substr($h,$p,SODIUM_CRYPTO_PWHASH_SALTBYTES); $p+=SODIUM_CRYPTO_PWHASH_SALTBYTES;
        ['o'=>$ops,'m'=>$memKb]=unpack('No/Nm',substr($h,$p,8)); $p+=8; $header=substr($h,$p);
        if($ops<1||$ops>20||$memKb<8||$memKb>1048576) throw new RuntimeException('Encabezado de respaldo inválido.');
        $key=sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,$pass,$salt,$ops,$memKb*1024,SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        $state=sodium_crypto_secretstream_xchacha20poly1305_init_pull($header,$key); sodium_memzero($key);
        $out=fopen($dst,'wb'); if(!$out) throw new RuntimeException('No se pudo crear el archivo de salida.');
        $final=false; $first=true;
        while(!$final){
            $l=fread($in,4); if(!is_string($l) || strlen($l)!==4) throw new RuntimeException('El respaldo está incompleto (fue cortado).');
            $n=unpack('N',$l)[1]; if($n<17 || $n>BAK_CHUNK+17) throw new RuntimeException('El respaldo está dañado.');
            $c=''; while(strlen($c)<$n && !feof($in)) $c.=(string)fread($in,$n-strlen($c));
            $r=sodium_crypto_secretstream_xchacha20poly1305_pull($state,$c);
            if($r===false) throw new RuntimeException($first?'La clave de respaldo (backup_key) no corresponde a este archivo, o está dañado.':'El respaldo está dañado (falló la verificación de integridad).');
            [$plain,$tag]=$r; fwrite($out,$plain); $first=false;
            $final=$tag===SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
        }
        if(fread($in,1)!=='') throw new RuntimeException('El respaldo tiene datos extra después del final.');
    } catch(Throwable $ex) { if($out){ fclose($out); $out=null; @unlink($dst); } throw $ex; }
    finally { fclose($in); if($out) fclose($out); }
}
/** Revisa un ZIP de respaldo ya descifrado: estructura, integridad de SQLite y presencia de todos los archivos. */
function bakCheckZip(string $zipFile, string $tmpDir): array {
    $z=new ZipArchive(); if($z->open($zipFile)!==true) throw new RuntimeException('El contenido del respaldo no es un ZIP válido.');
    try {
        $man=json_decode((string)$z->getFromName('manifest.json'),true);
        if(!is_array($man) || $z->locateName('gestion.sqlite')===false) throw new RuntimeException('Al respaldo le falta la base de datos o el manifiesto.');
        // Base principal (OX3 y usuarios) y la de cada empresa adicional (empresas/<clave>/gestion.sqlite), cada una con sus uploads.
        $bases=[]; for($i=0;$i<$z->numFiles;$i++){ $n=(string)$z->getNameIndex($i); if(preg_match('#^(empresas/[a-z0-9]+/)?gestion\.sqlite$#',$n,$mm)) $bases[$n]=$mm[1]??''; }
        $docs=0; $missing=0; $users=0;
        foreach($bases as $name=>$prefix){
            $db=$tmpDir.'/check-'.bin2hex(random_bytes(6)).'.sqlite';
            if(file_put_contents($db,$z->getStream($name) ?: '')===false) throw new RuntimeException('No se pudo extraer la base para revisarla.');
            try {
                $p=new PDO('sqlite:'.$db,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
                $ic=(string)$p->query('PRAGMA integrity_check')->fetchColumn();
                if($ic!=='ok') throw new RuntimeException('La base '.$name.' del respaldo no pasó la revisión de integridad: '.$ic);
                $tables=$p->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
                $stored=$p->query('SELECT stored FROM files'.(in_array('cc_files',$tables,true)?' UNION ALL SELECT stored FROM cc_files':''))->fetchAll(PDO::FETCH_COLUMN);
                foreach($stored as $s) if($z->locateName($prefix.'uploads/'.$s)===false) $missing++;
                $docs+=count($stored);
                if(in_array('users',$tables,true)) $users+=(int)$p->query('SELECT COUNT(*) FROM users')->fetchColumn();
                $p=null;
            } finally { @unlink($db); }
        }
        for($i=0;$i<$z->numFiles;$i++){ $st=$z->statIndex($i); if($st && preg_match('#^(empresas/[a-z0-9]+/)?uploads/#',$st['name']) && $z->getStream($st['name'])===false) throw new RuntimeException('No se pudo leer '.$st['name']); }
        if($missing) throw new RuntimeException("Faltan $missing archivo(s) de documentos o comprobantes en el respaldo.");
        return ['created'=>$man['created']??'','documentos'=>$docs,'usuarios'=>$users,'empresas'=>count($bases),'entradas'=>$z->numFiles];
    } finally { $z->close(); }
}

// ---------- Parte de la aplicación ----------
function backupDir(): string { return __DIR__.'/backups'; }
function backupEnabled(): bool { global $config; return function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push') && strlen((string)($config['backup_key']??''))>=20; }
/** Respaldos existentes, del más reciente al más antiguo. */
function backupList(): array {
    $out=[]; foreach(glob(backupDir().'/ox3-respaldo-*.ox3bak')?:[] as $f) $out[]=['file'=>basename($f),'size'=>(int)filesize($f),'time'=>(int)filemtime($f)];
    usort($out,fn($a,$b)=>$b['time']<=>$a['time']); return $out;
}
function backupStatus(): array {
    $l=backupList(); $st=json_decode((string)@file_get_contents(backupDir().'/estado.json'),true);
    return ['enabled'=>backupEnabled(),'last'=>$l[0]??null,'list'=>$l,'state'=>is_array($st)?$st:[]];
}
/** Hay que hacer un respaldo automático (según backup_auto_hours). */
function backupDue(): bool {
    global $config; $h=(float)($config['backup_auto_hours']??24);
    if($h<=0 || !backupEnabled()) return false;
    $l=backupList(); $st=backupStatus()['state'];
    if(isset($st['error_time']) && time()-(int)$st['error_time']<3600) return false; // tras un error, reintenta en una hora
    return !$l || time()-$l[0]['time']>=$h*3600;
}
/** Crea un respaldo cifrado. Devuelve ['file','size','docs']. */
function backupCreate(string $kind='manual'): array {
    global $db, $config;
    if(!backupEnabled()) throw new RuntimeException('Define backup_key (al menos 20 caracteres) en config.php y verifica que PHP tenga sodium.');
    $dir=backupDir(); if(!is_dir($dir) && !mkdir($dir,0700)) throw new RuntimeException('No se pudo crear la carpeta private/backups.');
    $lock=fopen($dir.'/.lock','c'); if(!$lock || !flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('Ya hay un respaldo en curso. Intenta en unos minutos.');
    $tag=gmdate('Ymd-His'); $zip="$dir/tmp-$tag.zip"; $final="$dir/ox3-respaldo-".(new DateTime('now',new DateTimeZone('America/Santiago')))->format('Ymd-His').'.ox3bak';
    // Todas las empresas que ya tienen base: la principal (OX3, con los usuarios) y las adicionales en private/empresas/<clave>/.
    $parts=[]; foreach(empresas() as $k=>$em){ $d=dataDir($k); if(is_file($d.'/gestion.sqlite')) $parts[$k]=['dir'=>$d,'zip'=>$em['dir']!==''?$em['dir'].'/':'']; }
    $need=0; foreach($parts as $pt){ $need+=(int)filesize($pt['dir'].'/gestion.sqlite')*3; foreach(glob($pt['dir'].'/uploads/*')?:[] as $f) $need+=(int)filesize($f)*2; }
    $free=@disk_free_space($dir); $snaps=[];
    try {
        if($free!==false && $free<$need) throw new RuntimeException('No hay espacio suficiente en el hosting para crear el respaldo.');
        $z=new ZipArchive(); if($z->open($zip,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) throw new RuntimeException('No se pudo crear el ZIP del respaldo.');
        $docs=0;
        foreach($parts as $k=>$pt){
            // Copia consistente de cada base sin detener la aplicación.
            $snap=$snaps[]="$dir/tmp-$tag-$k.sqlite";
            $p=new PDO('sqlite:'.$pt['dir'].'/gestion.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); $p->exec('PRAGMA busy_timeout=5000');
            try { $p->prepare('VACUUM INTO ?')->execute([$snap]); }
            catch(PDOException) { $p->exec('BEGIN IMMEDIATE'); try { if(!copy($pt['dir'].'/gestion.sqlite',$snap)) throw new RuntimeException('No se pudo copiar la base.'); } finally { $p->exec('COMMIT'); } }
            $p=null;
            $z->addFile($snap,$pt['zip'].'gestion.sqlite');
            if(is_file($pt['dir'].'/audit.anchor')) $z->addFile($pt['dir'].'/audit.anchor',$pt['zip'].'audit.anchor');
            foreach(glob($pt['dir'].'/uploads/*')?:[] as $f){ if(!is_file($f)) continue; $n=$pt['zip'].'uploads/'.basename($f); $z->addFile($f,$n); $z->setCompressionName($n,ZipArchive::CM_STORE); $docs++; }
        }
        foreach(['app.key','config.php'] as $f) if(is_file(__DIR__.'/'.$f)) $z->addFile(__DIR__.'/'.$f,$f);
        $z->addFromString('manifest.json',json_encode(['app'=>'OX3 Gestión','created'=>gmdate('c'),'kind'=>$kind,'documentos'=>$docs,'empresas'=>array_keys($parts),'php'=>PHP_VERSION],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
        $z->addFromString('LEEME.txt',"Respaldo de OX3 Gestión (todas las empresas).\nPara restaurar: copia estos archivos dentro de la carpeta private (gestion.sqlite, app.key, audit.anchor, config.php, la carpeta uploads y la carpeta empresas).\nInstrucciones completas en INSTALACION.md, sección Respaldos.\n");
        if(!$z->close()) throw new RuntimeException('No se pudo escribir el ZIP del respaldo.');
        foreach($snaps as $f) @unlink($f);
        bakEncryptFile($zip,$final.'.part',(string)$config['backup_key']); @unlink($zip);
        if(!rename($final.'.part',$final)) throw new RuntimeException('No se pudo guardar el respaldo.');
        safeCall('chmod',$final,0600);
        // Conserva solo los últimos backup_keep.
        $keep=max(1,(int)($config['backup_keep']??7)); foreach(array_slice(backupList(),$keep) as $old) @unlink($dir.'/'.$old['file']);
        $size=(int)filesize($final);
        file_put_contents($dir.'/estado.json',json_encode(['ok_time'=>time(),'file'=>basename($final),'kind'=>$kind]));
        audit('Respaldo cifrado',basename($final).' · '.$kind.' · '.$docs.' archivos');
        return ['file'=>basename($final),'size'=>$size,'docs'=>$docs];
    } catch(Throwable $ex) {
        foreach([...$snaps,$zip,$final.'.part'] as $f) if(is_file($f)) @unlink($f);
        $msg=$ex instanceof RuntimeException?$ex->getMessage():'Error inesperado: '.$ex->getMessage();
        @file_put_contents($dir.'/estado.json',json_encode(['error_time'=>time(),'error'=>$msg]+(backupStatus()['state']??[])));
        error_log('Respaldo: '.$ex);
        try { notify(adminIds(),'seguridad','El respaldo '.$kind.' falló',$msg,['tab'=>'security'],'always',false); } catch(Throwable) {}
        throw new RuntimeException($msg);
    } finally { flock($lock,LOCK_UN); fclose($lock); }
}
/** Prueba de restauración: descifra un respaldo en un temporal y revisa su contenido, sin tocar los datos en uso. */
function backupVerify(string $file): array {
    global $config;
    if(!preg_match('/^ox3-respaldo-\d{8}-\d{6}\.ox3bak$/',$file) || !is_file(backupDir().'/'.$file)) throw new RuntimeException('Respaldo no encontrado.');
    $tmp=backupDir().'/verif-'.bin2hex(random_bytes(6)).'.zip';
    try { bakDecryptFile(backupDir().'/'.$file,$tmp,(string)$config['backup_key']); return bakCheckZip($tmp,backupDir()); }
    finally { @unlink($tmp); }
}
