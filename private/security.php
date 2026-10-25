<?php
if(!function_exists('safeCall')){ function safeCall(string $fn, mixed ...$args): mixed { return function_exists($fn) ? @$fn(...$args) : null; } } // por si se reemplaza solo este archivo
// Seguridad: clave interna, verificación en dos pasos (TOTP, RFC 6238) y bitácora con encadenamiento de hashes.
// Se incluye desde app.php antes que los módulos. Solo usa funciones nativas de PHP (hash_hmac, random_bytes, sodium).

/**
 * Clave interna de 32 bytes. Se toma de config.php (app_key) o se crea una vez en private/app.key.
 * Cifra los secretos TOTP y firma la bitácora: va incluida en los respaldos cifrados.
 */
function appKey(): string {
    static $k=null; global $config;
    if($k!==null) return $k;
    $c=trim((string)($config['app_key']??''));
    if($c!=='') return $k=hash('sha256',$c,true);
    $file=__DIR__.'/app.key';
    if(!is_file($file)){
        // Creación atómica con fopen('x') (falla si otro proceso ya lo creó). No usa link(), que algunos hostings deshabilitan.
        $fh=@fopen($file,'x');
        if($fh){ fwrite($fh,bin2hex(random_bytes(32))); fflush($fh); fclose($fh); safeCall('chmod',$file,0600); }
        elseif(!is_file($file)) throw new RuntimeException('No se pudo crear private/app.key. Revisa los permisos de escritura de private.');
    }
    for($i=0;$i<20;$i++){ clearstatcache(true,$file); $hex=trim((string)file_get_contents($file)); if($hex!=='') break; usleep(50000); } // otro proceso puede estar escribiéndolo
    if(!preg_match('/^[0-9a-f]{64}$/',$hex)) throw new RuntimeException('private/app.key está dañado. Restáuralo desde un respaldo.');
    return $k=hex2bin($hex);
}
function subKey(string $purpose): string { return hash_hmac('sha256',$purpose,appKey(),true); }
function sealSecret(string $plain): string {
    $n=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($n.sodium_crypto_secretbox($plain,$n,subKey('mfa')));
}
function openSecret(string $box): string {
    $raw=base64_decode($box,true); if($raw===false || strlen($raw)<=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return '';
    $p=sodium_crypto_secretbox_open(substr($raw,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),substr($raw,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),subKey('mfa'));
    return $p===false?'':$p;
}

// ---------- TOTP ----------
function b32encode(string $bin): string {
    $a='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits=''; $out='';
    foreach(str_split($bin) as $c) $bits.=str_pad(decbin(ord($c)),8,'0',STR_PAD_LEFT);
    foreach(str_split($bits,5) as $chunk) $out.=$a[bindec(str_pad($chunk,5,'0'))];
    return $out;
}
function b32decode(string $s): string {
    $a='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits=''; $out='';
    foreach(str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/','',$s)??'')) as $c){ $i=strpos($a,$c); if($i===false) continue; $bits.=str_pad(decbin($i),5,'0',STR_PAD_LEFT); }
    foreach(str_split($bits,8) as $b) if(strlen($b)===8) $out.=chr(bindec($b));
    return $out;
}
function totpCode(string $secret, int $counter): string {
    $h=hash_hmac('sha1',pack('J',$counter),$secret,true); $o=ord($h[19])&0x0F;
    $n=((ord($h[$o])&0x7F)<<24)|(ord($h[$o+1])<<16)|(ord($h[$o+2])<<8)|ord($h[$o+3]);
    return str_pad((string)($n%1000000),6,'0',STR_PAD_LEFT);
}
/** Devuelve el contador de 30 s del código válido (±1 intervalo), o null. Un código ya usado ($last) no se acepta de nuevo. */
function totpMatch(string $secret, string $code, int $last=0): ?int {
    $code=preg_replace('/\D/','',$code)??''; if(strlen($code)!==6) return null;
    $now=intdiv(time(),30);
    foreach([0,-1,1] as $d){ $c=$now+$d; if($c>$last && hash_equals(totpCode($secret,$c),$code)) return $c; }
    return null;
}
function totpUri(string $secretB32, string $email): string {
    $issuer='OX3 Gestión';
    return 'otpauth://totp/'.rawurlencode($issuer.':'.$email).'?secret='.$secretB32.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits=6&period=30';
}
/** 10 códigos de recuperación de un solo uso: [texto para mostrar, hashes para guardar]. */
function recoveryCodes(): array {
    $codes=[]; $hashes=[];
    for($i=0;$i<10;$i++){ $c=strtolower(b32encode(random_bytes(5))); $c=substr($c,0,4).'-'.substr($c,4,4); $codes[]=$c; $hashes[]=hash_hmac('sha256',str_replace('-','',$c),subKey('recovery')); }
    return [$codes,$hashes];
}
/** Consume un código de recuperación del usuario. Devuelve true si era válido. */
function useRecoveryCode(array $u, string $code): bool {
    $code=strtolower(preg_replace('/[^A-Za-z0-9]/','',$code)??''); if(strlen($code)!==8) return false;
    $list=json_decode((string)$u['mfa_recovery'],true); if(!is_array($list)) return false;
    $h=hash_hmac('sha256',$code,subKey('recovery'));
    foreach($list as $i=>$x) if(is_string($x) && hash_equals($x,$h)){ unset($list[$i]); query('UPDATE users SET mfa_recovery=? WHERE id=?',[json_encode(array_values($list)),$u['id']]); return true; }
    return false;
}
/** Roles obligados a usar verificación en dos pasos (config mfa_roles). */
function mfaRequired(array $u): bool { global $config; $r=$config['mfa_roles']??[]; return is_array($r) && in_array($u['role'],$r,true); }

// ---------- Bitácora íntegra ----------
// Cada registro guarda el hash del anterior y un HMAC-SHA256 de su contenido con la clave interna:
// modificar, borrar o intercalar un registro rompe la cadena desde ese punto. private/audit.anchor
// guarda el último eslabón, así que también se detecta si se borran los registros finales.
const AUDIT_GENESIS='OX3-BITACORA';
$auditCols=array_column($db->query('PRAGMA table_info(audit)')->fetchAll(PDO::FETCH_ASSOC),'name');
if(!in_array('ip',$auditCols,true)) $db->exec("ALTER TABLE audit ADD COLUMN ip TEXT NOT NULL DEFAULT ''");
if(!in_array('prev',$auditCols,true)) $db->exec("ALTER TABLE audit ADD COLUMN prev TEXT NOT NULL DEFAULT ''");
if(!in_array('hash',$auditCols,true)) {
    $db->exec("ALTER TABLE audit ADD COLUMN hash TEXT NOT NULL DEFAULT ''");
    // Sella el historial existente (desde ahora queda protegido; antes de esta versión no había garantía).
    $db->exec('BEGIN IMMEDIATE');
    try { $prev=AUDIT_GENESIS; foreach($db->query('SELECT * FROM audit ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $a){ $a['prev']=$prev; $h=auditHash($a); query('UPDATE audit SET prev=?,hash=? WHERE id=?',[$prev,$h,$a['id']]); $prev=$h; $lastId=(int)$a['id']; }
        $db->exec('COMMIT'); if(isset($lastId)) auditAnchor($lastId,$prev); }
    catch(Throwable $ex){ $db->exec('ROLLBACK'); throw $ex; }
}
function auditHash(array $a): string {
    return hash_hmac('sha256',implode("\x1f",[(string)$a['id'],(string)($a['actor']??''),(string)$a['action'],(string)$a['detail'],(string)$a['created'],(string)($a['ip']??''),(string)$a['prev']]),subKey('audit'));
}
/** Último eslabón de la bitácora de la empresa en uso, guardado fuera de su base (private/audit.anchor o private/empresas/<clave>/audit.anchor). */
function auditAnchor(int $id, string $hash): void { @file_put_contents(dataDir().'/audit.anchor',$id.' '.$hash,LOCK_EX); safeCall('chmod',dataDir().'/audit.anchor',0600); }
function audit(string $action, string $detail=''): void {
    global $db;
    $own=true; try { $db->exec('BEGIN IMMEDIATE'); } catch(PDOException) { $own=false; } // ya dentro de una transacción
    try {
        $last=query('SELECT id,hash FROM audit ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $a=['id'=>$last?(int)$last['id']+1:1,'actor'=>$_SESSION['uid']??null,'action'=>$action,'detail'=>$detail,'created'=>gmdate('c'),'ip'=>PHP_SAPI==='cli'?'':(string)($_SERVER['REMOTE_ADDR']??''),'prev'=>$last?(string)$last['hash']:AUDIT_GENESIS];
        $a['hash']=auditHash($a);
        query('INSERT INTO audit(id,actor,action,detail,created,ip,prev,hash) VALUES(?,?,?,?,?,?,?,?)',[$a['id'],$a['actor'],$a['action'],$a['detail'],$a['created'],$a['ip'],$a['prev'],$a['hash']]);
        if($own) $db->exec('COMMIT');
    } catch(Throwable $ex) { if($own) $db->exec('ROLLBACK'); throw $ex; }
    auditAnchor($a['id'],$a['hash']);
}
/** Recorre la bitácora completa. Devuelve ['ok'=>bool,'total'=>n,'first_bad'=>id|null,'problem'=>texto]. */
function auditVerify(): array {
    $prev=AUDIT_GENESIS; $n=0; $lastId=0; $lastHash='';
    $st=query('SELECT * FROM audit ORDER BY id');
    while($a=$st->fetch(PDO::FETCH_ASSOC)){
        $n++;
        if($a['prev']!==$prev) return ['ok'=>false,'total'=>$n,'first_bad'=>(int)$a['id'],'problem'=>'El registro #'.$a['id'].' no enlaza con el anterior: se borró o intercaló un registro antes de él.'];
        if(!hash_equals(auditHash($a),(string)$a['hash'])) return ['ok'=>false,'total'=>$n,'first_bad'=>(int)$a['id'],'problem'=>'El registro #'.$a['id'].' fue modificado después de registrarse.'];
        $prev=$a['hash']; $lastId=(int)$a['id']; $lastHash=$a['hash'];
    }
    $anchor=is_file(dataDir().'/audit.anchor')?explode(' ',trim((string)file_get_contents(dataDir().'/audit.anchor'))):[];
    if(count($anchor)===2){
        [$aid,$ah]=[(int)$anchor[0],$anchor[1]];
        if($aid>$lastId) return ['ok'=>false,'total'=>$n,'first_bad'=>$lastId+1,'problem'=>'Faltan los registros finales: la bitácora llegaba al #'.$aid.' y ahora termina en el #'.$lastId.'.'];
        $h=query('SELECT hash FROM audit WHERE id=?',[$aid])->fetchColumn();
        if($h===false || !hash_equals((string)$h,$ah)) return ['ok'=>false,'total'=>$n,'first_bad'=>$aid,'problem'=>'El registro #'.$aid.' no coincide con el último eslabón guardado fuera de la base.'];
    }
    return ['ok'=>true,'total'=>$n,'first_bad'=>null,'problem'=>'','last_id'=>$lastId,'last_hash'=>$lastHash];
}

/** PHP2Shell (CVE-2026-109618 y CVE-2026-109619, 21-10-2026): compara la versión de PHP del hosting con la primera corregida de su rama.
 * 'ok' es true si la versión ya trae la corrección, false si es anterior y null si la rama no tiene versión oficial corregida (8.1 o anterior). */
function phpPatchStatus(): array {
    $fixed=['8.2'=>'8.2.35','8.3'=>'8.3.36','8.4'=>'8.4.27','8.5'=>'8.5.12'];
    $branch=PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION; $min=$fixed[$branch]??null;
    $ok=$min===null ? (version_compare($branch,'8.5','>') ? true : null) : version_compare(PHP_VERSION,$min,'>=');
    return ['version'=>PHP_VERSION,'branch'=>$branch,'fixed'=>$min,'ok'=>$ok,'progress'=>filter_var(ini_get('session.upload_progress.enabled'),FILTER_VALIDATE_BOOLEAN),'sapi'=>PHP_SAPI];
}
