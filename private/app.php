<?php
declare(strict_types=1);
ini_set('display_errors', '0');
umask(0077);
set_exception_handler(function(Throwable $e): void {
    error_log((string)$e); http_response_code(500);
    echo 'No fue posible completar la operación. Revisa el registro de errores del hosting.';
});
/** Llama a una función de PHP solo si el hosting no la deshabilitó (disable_functions): en PHP 8 llamar a una deshabilitada es un error fatal, aun con @. */
function safeCall(string $fn, mixed ...$args): mixed { return function_exists($fn) ? @$fn(...$args) : null; }
function e(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function stop(int $code, string $text): never { http_response_code($code); exit($text); }
$root = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
$path = realpath(__DIR__);
if (PHP_SAPI !== 'cli' && $root !== '' && ($path === $root || str_starts_with($path, rtrim($root, '/').'/'))) stop(503, 'Instala la carpeta private fuera de la raíz pública. Consulta INSTALACION.md.');
if (!is_file(__DIR__.'/config.php')) stop(503, 'Configuración pendiente: copia private/config.example.php a private/config.php y define la clave de instalación.');
$config = require __DIR__.'/config.php';
$cli = PHP_SAPI==='cli' && defined('OX3_CLI'); // cron.php: solo base de datos y tareas, sin sesión ni pantallas
$secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
if (!$cli && $config['https_only'] && !$secure) { header('Location: https://gestion.ox3.cl/', true, 302); exit; }
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
$cspNonce = base64_encode(random_bytes(16)); // permite el <style> de la vista previa de PowerPoint
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'nonce-$cspNonce'; script-src 'none'; img-src 'self'; frame-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
header('Cache-Control: no-store');
if ($secure) header('Strict-Transport-Security: max-age=31536000');
if ($cli) $_SESSION=[]; else {
ini_set('session.use_strict_mode','1');
ini_set('session.use_only_cookies','1');
session_name('OX3GESTION');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Strict']);
session_start();
if (isset($_SESSION['last']) && time()-$_SESSION['last'] > 1800) { $expired=isset($_SESSION['uid']); $_SESSION=[]; if ($expired) $_SESSION['message']='Tu sesión se cerró por inactividad. Ingresa nuevamente.'; }
$_SESSION['last']=time();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}
/** Cloudflare Turnstile: activo solo si ambas claves están definidas en config.php. */
$turnstileSite = trim((string)($config['turnstile_site_key'] ?? ''));
$turnstileSecret = trim((string)($config['turnstile_secret_key'] ?? ''));
$turnstileOn = $turnstileSite !== '' && $turnstileSecret !== '';
function turnstileValid(string $secret, string $token): bool {
    if ($token === '' || strlen($token) > 2048) return false;
    $body = http_build_query(['secret'=>$secret, 'response'=>$token, 'remoteip'=>$_SERVER['REMOTE_ADDR'] ?? '']);
    $url = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    if (function_exists('curl_init')) {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$body, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded']]);
        $raw = curl_exec($c); curl_close($c);
    } else {
        $raw = @file_get_contents($url, false, stream_context_create(['http'=>['method'=>'POST', 'header'=>"Content-Type: application/x-www-form-urlencoded\r\n", 'content'=>$body, 'timeout'=>10]]));
    }
    if (!is_string($raw)) { error_log('Turnstile: sin respuesta de Cloudflare'); throw new RuntimeException('No fue posible validar la verificación de seguridad. Intenta nuevamente en unos segundos.'); }
    $r = json_decode($raw, true);
    if (!is_array($r)) return false;
    if (empty($r['success']) && !empty($r['error-codes'])) error_log('Turnstile: '.implode(',', (array)$r['error-codes']));
    return !empty($r['success']) && (!isset($r['action']) || $r['action'] === '' || $r['action'] === 'login');
}
/** Token del formulario y la empresa en que se abrió (si se cambió de empresa en otra pestaña, el envío se rechaza). */
function csrf(): string { global $empresaSet, $empresaKey; return '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">'.($empresaSet?'<input type="hidden" name="emp" value="'.e($empresaKey).'">':''); }
const APP_TABS=['account','users','activity','oc','oc_new','oc_cfg','cot','cot_new','cot_cfg','cc','cc_movs','cc_new','cc_rends','cc_rend_new','cc_cfg','pend','security','dash','ps','ps_new','cu','cu_new','cu_cfg','cu_import','pf','pf_new','pf_cfg'];
/** Redirige a index.php con parámetros enteros ya validados (p, f, preview, oc, cot, pf, mov, rend, pres, curso, ver, edit, tab, vista). */
function go(string $message='', array $to=[]): never {
    $_SESSION['message']=$message;
    $q=[]; foreach($to as $k=>$v) if(in_array($k,['p','f','preview','oc','cot','pf','mov','rend','pres','edit','curso','ver'],true) && is_int($v) && $v>=0) $q[$k]=$v; elseif($k==='tab' && in_array($v,APP_TABS,true)) $q[$k]=$v; elseif($k==='vista' && is_string($v) && preg_match('/^[a-z]{3,12}$/',$v)) $q[$k]=$v;
    header('Location: index.php'.($q?'?'.http_build_query($q):''),true,303); exit;
}
function post(string $k): string { return is_string($_POST[$k]??null) ? trim($_POST[$k]) : ''; }
function passwordInput(): string { return is_string($_POST['password']??null) ? $_POST['password'] : ''; }
function validPassword(string $p): bool { return strlen($p)>=12 && strlen($p)<=72; }
function cleanName(string $n, int $max=80): string {
    $n=trim(preg_replace('/[\x00-\x1F\x7F]/u','',$n)??'');
    if($n===''||preg_match('/^.{1,'.$max.'}$/us',$n)!==1) throw new RuntimeException('Escribe un nombre de 1 a '.$max.' caracteres.');
    return $n;
}
function shortText(string $t, int $max=300): string { $t=preg_replace('/[\x00-\x1F\x7F]/u',' ',$t)??''; preg_match('/^.{0,'.$max.'}/us',$t,$m); return trim($m[0]??''); }
if (!extension_loaded('pdo_sqlite') || !extension_loaded('fileinfo') || !class_exists('ZipArchive') || !function_exists('sodium_crypto_secretbox')) stop(503,'Habilita pdo_sqlite, sqlite3, fileinfo, zip y sodium en PHP. Consulta INSTALACION.md.');
/**
 * Empresas de la plataforma. Cada una tiene su propia base, documentos, bitácora y logo; los usuarios son comunes.
 * 'dir' vacío = la carpeta private (OX3, los datos de siempre). 'emisor' son los datos iniciales para OC y cotizaciones
 * (después se editan en la configuración de cada módulo). 'cursos': muestra el módulo Cursos (MRM hace operaciones con ROV, no capacitación).
 * 'proformas': muestra el módulo Proformas y el atajo de cotización en inglés (acuerdos internacionales de MRM).
 */
const EMPRESA_BASE='ox3';
function empresas(): array {
    return [
        'ox3'=>['nombre'=>'OX3 Capacitaciones','corto'=>'OX3','lema'=>'Capacitaciones Chile','logo'=>'assets/logo-ox3.png','web'=>'www.ox3.cl','dir'=>'','cursos'=>true,'proformas'=>false,
            'emisor'=>['razon'=>'Capacitaciones OX3 Chile SpA','rut'=>'77.927.027-0','giro'=>'Capacitación','direccion'=>'Mejicana 518, Of. 1, Punta Arenas','email'=>'contacto@ox3.cl','web'=>'www.ox3.cl']],
        'mrm'=>['nombre'=>'Magallanes ROV Marine','corto'=>'MRM','lema'=>'Marine Robotics & Subsea Engineering','logo'=>'assets/logo-mrm.png','web'=>'www.magallanesrovmarine.cl','dir'=>'empresas/mrm','cursos'=>false,'proformas'=>true,
            'emisor'=>['razon'=>'Magallanes ROV Marine','rut'=>'','giro'=>'Robótica marina e ingeniería submarina','direccion'=>'Punta Arenas','email'=>'contacto@magallanesrovmarine.cl','web'=>'www.magallanesrovmarine.cl']],
    ];
}
/** Empresa en uso (con su clave en 'key'). */
function empresa(): array { global $empresaKey; $k=$empresaKey??EMPRESA_BASE; return empresas()[$k]+['key'=>$k]; }
/** Texto según el giro de la empresa en uso: capacitación (OX3) o inspecciones y trabajos con ROV (MRM, sin cursos). */
function txEmp(string $capacitacion, string $rov): string { return empresa()['cursos'] ? $capacitacion : $rov; }
/** Carpeta de datos de una empresa: gestion.sqlite, uploads y audit.anchor. */
function dataDir(?string $k=null): string { global $empresaKey; $d=empresas()[$k??$empresaKey??EMPRESA_BASE]['dir']; return __DIR__.($d!==''?'/'.$d:''); }
/** En la base de una empresa adicional, la tabla users está en la base principal (adjunta como "core"):
 * SQLite no admite claves foráneas entre bases, así que esas referencias se omiten al crear sus tablas. */
final class GestionPDO extends PDO {
    public bool $sharedUsers=false;
    public function exec(string $statement): int|false {
        if($this->sharedUsers) $statement=preg_replace('/\s+REFERENCES\s+users\s*\(\s*id\s*\)/i','',$statement)??$statement;
        return parent::exec($statement);
    }
}
function openDb(string $file): GestionPDO {
    try { $p=new GestionPDO('sqlite:'.$file, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); }
    catch (PDOException $e) { error_log((string)$e); stop(503, 'No se pudo abrir la base de datos SQLite en la carpeta private. Revisa permisos y la restricción open_basedir del hosting.'); }
    $p->exec('PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON;');
    return $p;
}
$dbFile = __DIR__.'/gestion.sqlite';
if (!is_writable(__DIR__) || (is_file($dbFile) && !is_writable($dbFile))) stop(503, 'PHP no puede escribir en la carpeta private (ni en gestion.sqlite). Da permisos de escritura al usuario de PHP: 700/755 para la carpeta y 600/644 para los archivos. Consulta INSTALACION.md.');
$db = openDb($dbFile);
function query(string $sql, array $params=[]): PDOStatement { global $db; $s=$db->prepare($sql); $s->execute($params); return $s; }
// Base principal: usuarios, intentos de ingreso y acceso de cada usuario a las empresas (comunes a todas).
$db->exec('CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT UNIQUE NOT NULL, password TEXT NOT NULL, role TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1);
CREATE TABLE IF NOT EXISTS attempts(bucket TEXT PRIMARY KEY, count INTEGER NOT NULL, until INTEGER NOT NULL);');
// Migración aditiva: al crear la tabla de accesos, todos los usuarios existentes quedan con acceso a OX3 (los administradores entran a todas).
$newAccess = $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='user_companies'")->fetchColumn()==0;
$db->exec('CREATE TABLE IF NOT EXISTS user_companies(user_id INTEGER NOT NULL REFERENCES users(id), empresa TEXT NOT NULL, PRIMARY KEY(user_id, empresa))');
if ($newAccess) query('INSERT OR IGNORE INTO user_companies(user_id,empresa) SELECT id,? FROM users',[EMPRESA_BASE]);
/** Empresas a las que entra un usuario: el administrador a todas; los demás, las asignadas en Usuarios. */
function userEmpresas(array $u): array {
    if(($u['role']??'')==='admin') return array_keys(empresas());
    $mine=query('SELECT empresa FROM user_companies WHERE user_id=?',[(int)$u['id']])->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_filter(array_keys(empresas()),fn($k)=>in_array($k,$mine,true)));
}
// Empresa de esta petición: ?emp= (enlaces de los avisos) o la elegida en la sesión; si el usuario tiene una sola, esa.
$empresaKey = EMPRESA_BASE; $empresaSet = false;
if ($cli) { if (defined('OX3_EMPRESA') && isset(empresas()[OX3_EMPRESA])) $empresaKey = OX3_EMPRESA; }
elseif (isset($_SESSION['uid'])) {
    $u0 = query('SELECT id,role FROM users WHERE id=? AND active=1',[$_SESSION['uid']])->fetch(PDO::FETCH_ASSOC);
    $allowed = $u0 ? userEmpresas($u0) : [];
    $want = '';
    foreach ([$_GET['emp'] ?? null, $_SESSION['empresa'] ?? null] as $c) if (is_string($c) && in_array($c,$allowed,true)) { $want=$c; break; }
    if ($want==='' && count($allowed)===1) $want = $allowed[0];
    if ($want!=='') { $_SESSION['empresa']=$want; $empresaKey=$want; $empresaSet=true; } else unset($_SESSION['empresa']);
}
if ($empresaKey !== EMPRESA_BASE) {
    // Empresa adicional: su propia base en private/empresas/<clave>/, con la principal adjunta para leer los usuarios.
    $dir = dataDir();
    if (!is_dir($dir) && !mkdir($dir,0700,true)) stop(503, 'PHP no pudo crear la carpeta private/'.empresa()['dir'].'. Revisa los permisos de escritura de private.');
    $cdb = $dir.'/gestion.sqlite';
    if (!is_writable($dir) || (is_file($cdb) && !is_writable($cdb))) stop(503, 'PHP no puede escribir en private/'.empresa()['dir'].'. Consulta INSTALACION.md.');
    $db = openDb($cdb); $db->sharedUsers = true;
    query('ATTACH DATABASE ? AS core',[$dbFile]);
}
// Datos propios de cada empresa.
$db->exec('CREATE TABLE IF NOT EXISTS files(id INTEGER PRIMARY KEY, name TEXT NOT NULL, stored TEXT UNIQUE NOT NULL, size INTEGER NOT NULL, category TEXT NOT NULL, owner INTEGER NOT NULL REFERENCES users(id), created TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS audit(id INTEGER PRIMARY KEY, actor INTEGER, action TEXT NOT NULL, detail TEXT NOT NULL, created TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS projects(id INTEGER PRIMARY KEY, name TEXT NOT NULL, description TEXT NOT NULL DEFAULT \'\', created TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS folders(id INTEGER PRIMARY KEY, name TEXT NOT NULL, project_id INTEGER, parent_id INTEGER, created TEXT NOT NULL);');
// Migración aditiva: ubicación de cada archivo (los existentes quedan en "General").
$cols=array_column($db->query('PRAGMA table_info(files)')->fetchAll(PDO::FETCH_ASSOC),'name');
if(!in_array('project_id',$cols,true)) $db->exec('ALTER TABLE files ADD COLUMN project_id INTEGER');
if(!in_array('folder_id',$cols,true)) $db->exec('ALTER TABLE files ADD COLUMN folder_id INTEGER');
$db->exec('CREATE INDEX IF NOT EXISTS files_location ON files(project_id, folder_id); CREATE INDEX IF NOT EXISTS folders_parent ON folders(project_id, parent_id);');
function project(int $id): array|false { return $id>0 ? query('SELECT * FROM projects WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC) : false; }
function folder(int $id): array|false { return $id>0 ? query('SELECT * FROM folders WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC) : false; }
/** Carpetas desde la raíz hasta $id. */
function folderTrail(int $id): array {
    $trail=[]; for($i=0;$id>0&&$i<30;$i++){ $f=folder($id); if(!$f) break; array_unshift($trail,$f); $id=(int)$f['parent_id']; }
    return $trail;
}
/** Valida una ubicación: devuelve [project_id|null, folder_id|null]. */
function location(int $p, int $f): array {
    if($f>0){ $fo=folder($f); if(!$fo) throw new RuntimeException('La carpeta no existe.'); return [$fo['project_id']!==null?(int)$fo['project_id']:null,(int)$fo['id']]; }
    if($p>0){ if(!project($p)) throw new RuntimeException('El proyecto no existe.'); return [$p,null]; }
    return [null,null];
}
function here(?int $p, ?int $f): array { return $f ? ['f'=>$f] : ($p ? ['p'=>$p] : ['p'=>0]); }
/** Tipo de vista previa según extensión (el contenido se validó al subir). */
function previewKind(string $name): string {
    $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
    return match($ext){ 'pdf'=>'pdf', 'jpg','jpeg','png'=>'image', 'txt'=>'text', 'csv'=>'csv', 'docx'=>'docx', 'xlsx'=>'xlsx', 'pptx'=>'pptx', default=>'none' };
}
require __DIR__.'/security.php';
require __DIR__.'/notify.php';
require __DIR__.'/backup.php';
require __DIR__.'/qr.php';
$user = isset($_SESSION['uid']) ? query('SELECT * FROM users WHERE id=? AND active=1',[$_SESSION['uid']])->fetch(PDO::FETCH_ASSOC) : false;
if ($user && !hash_equals(hash('sha256',$user['password']), (string)($_SESSION['auth_version']??''))) { unset($_SESSION['uid'],$_SESSION['auth_version']); $user=false; }
$installed = (int)query('SELECT COUNT(*) FROM users')->fetchColumn()>0;
$categories=empresa()['cursos'] ? ['Administración','Cursos y alumnos','Certificados','Comercial','Operaciones','Otros']
    : ['Administración','Inspecciones y trabajos ROV','Informes técnicos','Certificados','Comercial','Operaciones','Otros'];
// Si en MRM ya se subieron documentos con una categoría de la lista anterior, se mantiene para poder filtrarlos.
foreach(query('SELECT DISTINCT category FROM files')->fetchAll(PDO::FETCH_COLUMN) as $fc) if(is_string($fc) && $fc!=='' && !in_array($fc,$categories,true)) array_splice($categories,-1,0,[$fc]);
$canEdit = $user && in_array($user['role'],['admin','editor'],true);
$isAdmin = $user && $user['role']==='admin';
require __DIR__.'/office.php';
require __DIR__.'/account.php';
require __DIR__.'/oc.php';
require __DIR__.'/intl.php';
require __DIR__.'/cot.php';
require __DIR__.'/proforma.php';
require __DIR__.'/contable.php';
require __DIR__.'/dash.php';
require __DIR__.'/presupuesto.php';
require __DIR__.'/cursos.php';
if ($cli) return;
register_shutdown_function('afterResponse');
// Roles obligados a la verificación en dos pasos: sin activarla solo pueden enrolarse o salir.
$mfaForce = $user && !(int)$user['mfa_on'] && mfaRequired($user);
if ($mfaForce && empty($_SESSION['mfa_new'])) $_SESSION['mfa_new']=b32encode(random_bytes(20));
/** Completa el ingreso (después de la contraseña y, si corresponde, del segundo paso). */
function finishLogin(array $u, string $how='Ingreso'): never {
    session_regenerate_id(true);
    $_SESSION=['uid'=>$u['id'],'auth_version'=>hash('sha256',$u['password']),'csrf'=>bin2hex(random_bytes(32)),'last'=>time()];
    audit($how); go();
}
/** Bloqueo por intentos (5 en 15 minutos) para los buckets dados. Devuelve el conteo mayor después de sumar uno. */
function countAttempt(array $buckets): int {
    global $db; $max=0;
    $db->exec('BEGIN IMMEDIATE');
    query('DELETE FROM attempts WHERE until < ?',[time()]);
    foreach($buckets as $b) { $n=query('SELECT count FROM attempts WHERE bucket=?',[$b])->fetchColumn(); if ($n!==false && (int)$n>=5) { $db->exec('COMMIT'); throw new RuntimeException('Demasiados intentos. Intenta nuevamente en 15 minutos.'); } }
    foreach($buckets as $b) { query('INSERT INTO attempts(bucket,count,until) VALUES(?,1,?) ON CONFLICT(bucket) DO UPDATE SET count=count+1',[$b,time()+900]); $max=max($max,(int)query('SELECT count FROM attempts WHERE bucket=?',[$b])->fetchColumn()); }
    $db->exec('COMMIT');
    return $max;
}
$error='';
$action=post('action');
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!hash_equals($_SESSION['csrf'],post('csrf'))) {
        // Formulario de ingreso abierto demasiado tiempo: volver a mostrarlo en vez de un error en blanco.
        if (!$user) go($_SESSION['message'] ?? 'La página estuvo abierta mucho tiempo. Ingresa nuevamente.');
        stop(403,'Sesión inválida. Recarga la página.');
    }
    try {
        if (!$installed && $action==='setup') {
            if (strlen($config['setup_key'])<32 || !hash_equals($config['setup_key'],post('setup_key'))) throw new RuntimeException('Clave de instalación inválida.');
            $email=strtolower(post('email')); $pw=passwordInput();
            if (!filter_var($email,FILTER_VALIDATE_EMAIL)||!validPassword($pw)||post('name')==='') throw new RuntimeException('Completa el nombre, un correo válido y una contraseña de 12 a 72 caracteres.');
            $db->exec('BEGIN IMMEDIATE');
            if ((int)query('SELECT COUNT(*) FROM users')->fetchColumn()>0) { $db->exec('ROLLBACK'); stop(409,'Ya instalado.'); }
            query('INSERT INTO users(name,email,password,role) VALUES(?,?,?,?)',[post('name'),$email,password_hash($pw,PASSWORD_DEFAULT),'admin']);
            query('INSERT OR IGNORE INTO user_companies(user_id,empresa) VALUES(?,?)',[(int)$db->lastInsertId(),EMPRESA_BASE]);
            $db->exec('COMMIT'); audit('Instalación'); go('Administrador creado. Ya puedes ingresar.');
        }
        if ($installed && $action==='login') {
            $email=strtolower(post('email'));
            // Primero Turnstile: así un bot no consume los intentos ni bloquea la cuenta de nadie.
            if ($turnstileOn && !turnstileValid($turnstileSecret, post('cf-turnstile-response'))) throw new RuntimeException('Completa la verificación de seguridad antes de ingresar.');
            $buckets=[hash('sha256','ip:'.($_SERVER['REMOTE_ADDR']??'')),hash('sha256','email:'.$email)];
            countAttempt($buckets);
            $u=query('SELECT * FROM users WHERE email=? AND active=1',[$email])->fetch(PDO::FETCH_ASSOC);
            $hash=$u['password']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
            if (!password_verify(passwordInput(),$hash)||!$u) {
                // Al quinto intento fallido sobre una cuenta existente, avisa a su dueño.
                if ($u && (int)query('SELECT count FROM attempts WHERE bucket=?',[$buckets[1]])->fetchColumn()===5) { audit('Bloqueo por intentos fallidos',$email); notify([(int)$u['id']],'seguridad','Bloqueamos el ingreso a tu cuenta por 15 minutos','Hubo 5 intentos fallidos de ingreso con tu correo (IP '.($_SERVER['REMOTE_ADDR']??'desconocida').'). Si no fuiste tú, avisa al administrador y cambia tu contraseña.',[],'always',false); }
                throw new RuntimeException('Correo o contraseña incorrectos.');
            }
            foreach($buckets as $b) query('DELETE FROM attempts WHERE bucket=?',[$b]);
            if ((int)$u['mfa_on']===1) {
                // Segundo paso: la sesión aún no tiene usuario; solo recuerda a quién se le pide el código, por 5 minutos.
                session_regenerate_id(true);
                $_SESSION=['csrf'=>bin2hex(random_bytes(32)),'last'=>time(),'mfa_uid'=>(int)$u['id'],'mfa_until'=>time()+300,'mfa_ver'=>hash('sha256',$u['password'])];
                go();
            }
            finishLogin($u);
        }
        if ($installed && !$user && $action==='mfa_cancel') { $_SESSION=['csrf'=>bin2hex(random_bytes(32))]; go(); }
        if ($installed && !$user && $action==='mfa_login') {
            $uid=(int)($_SESSION['mfa_uid']??0);
            if (!$uid || (int)($_SESSION['mfa_until']??0)<time()) { unset($_SESSION['mfa_uid'],$_SESSION['mfa_until'],$_SESSION['mfa_ver']); throw new RuntimeException('Pasaron más de 5 minutos. Ingresa nuevamente tu correo y contraseña.'); }
            $u=query('SELECT * FROM users WHERE id=? AND active=1',[$uid])->fetch(PDO::FETCH_ASSOC);
            if (!$u || !(int)$u['mfa_on'] || !hash_equals(hash('sha256',$u['password']),(string)($_SESSION['mfa_ver']??''))) { $_SESSION=['csrf'=>bin2hex(random_bytes(32))]; throw new RuntimeException('Ingresa nuevamente.'); }
            $bucket=hash('sha256','mfa:'.$uid);
            try { countAttempt([$bucket]); } catch(RuntimeException $ex) { $_SESSION=['csrf'=>bin2hex(random_bytes(32))]; throw $ex; }
            $code=post('code'); $how='Ingreso con verificación en dos pasos';
            $ctr=totpMatch(openSecret($u['mfa_secret']),$code,(int)$u['mfa_last']);
            if ($ctr!==null && query('UPDATE users SET mfa_last=? WHERE id=? AND mfa_last<?',[$ctr,$uid,$ctr])->rowCount()===1) {}
            elseif (useRecoveryCode($u,$code)) {
                $left=count(json_decode((string)query('SELECT mfa_recovery FROM users WHERE id=?',[$uid])->fetchColumn(),true)?:[]); $how='Ingreso con código de recuperación';
                notify([$uid],'seguridad','Usaste un código de recuperación','Ingresaste con un código de recuperación. Te quedan '.$left.'. Si no fuiste tú, avisa al administrador. Puedes generar códigos nuevos en Mi cuenta.',['tab'=>'account'],'always',false);
            }
            else throw new RuntimeException('Código incorrecto o ya usado. Revisa la hora de tu teléfono y escribe el código actual de 6 dígitos.');
            query('DELETE FROM attempts WHERE bucket=?',[$bucket]);
            finishLogin($u,$how);
        }
        if ($user) {
            if ($mfaForce && !in_array($action,['logout','mfa_enable','mfa_restart'],true)) throw new RuntimeException('Activa la verificación en dos pasos para continuar.');
            if ($action==='logout') { audit('Cierre de sesión'); $_SESSION=[]; session_regenerate_id(true); go(); }
            if ($action==='company_select' && !$mfaForce) {
                $k=post('empresa'); if(!in_array($k,userEmpresas($user),true)) throw new RuntimeException('No tienes acceso a esa empresa. Pídelo al administrador.');
                $_SESSION['empresa']=$k;
                go(!empty($_SESSION['mfa_codes'])?'Guarda ahora tus códigos de recuperación.':'', !empty($_SESSION['mfa_codes'])?['tab'=>'account']:[]); // códigos recién generados: mostrarlos primero
            }
            if (!$mfaForce && !$empresaSet) throw new RuntimeException('Elige una empresa para continuar.');
            // Formulario abierto en otra empresa (se cambió de empresa en otra pestaña): no aplicar el cambio aquí.
            if ($empresaSet && post('emp')!=='' && post('emp')!==$empresaKey) go('No se guardó: ese formulario era de '.(empresas()[post('emp')]['nombre']??'otra empresa').' y ahora estás en '.empresa()['nombre'].'. Revisa los datos y vuelve a intentarlo.');
            if ($action==='password') {
                if (!password_verify(is_string($_POST['current_password']??null)?$_POST['current_password']:'',$user['password']) || !validPassword(passwordInput())) throw new RuntimeException('Verifica tu clave actual y usa una nueva de 12 a 72 caracteres.');
                query('UPDATE users SET password=? WHERE id=?',[password_hash(passwordInput(),PASSWORD_DEFAULT),$user['id']]);
                audit('Cambio de contraseña'); notify([(int)$user['id']],'seguridad','Se cambió la contraseña de tu cuenta','La contraseña de tu cuenta en OX3 Gestión se cambió. Si no fuiste tú, avisa de inmediato al administrador.',[],'always',false);
                $_SESSION=[]; session_regenerate_id(true); go('Contraseña actualizada. Ingresa nuevamente.');
            }
            if ($action==='add_user' && $isAdmin) {
                $email=strtolower(post('email'));
                if (!filter_var($email,FILTER_VALIDATE_EMAIL)||!validPassword(passwordInput())||post('name')==='') throw new RuntimeException('Revisa nombre, correo y contraseña (12 a 72 caracteres).');
                if(query('SELECT id FROM users WHERE email=?',[$email])->fetchColumn()) throw new RuntimeException('Ese correo ya está registrado.');
                $role=in_array(post('role'),['editor','viewer'],true)?post('role'):'viewer';
                $emps=array_values(array_intersect(array_keys(empresas()),is_array($_POST['empresas']??null)?$_POST['empresas']:[]));
                if(!$emps) throw new RuntimeException('Marca al menos una empresa a la que tendrá acceso.');
                query('INSERT INTO users(name,email,password,role) VALUES(?,?,?,?)',[post('name'),$email,password_hash(passwordInput(),PASSWORD_DEFAULT),$role]);
                $nu=(int)$db->lastInsertId(); foreach($emps as $k) query('INSERT OR IGNORE INTO user_companies(user_id,empresa) VALUES(?,?)',[$nu,$k]);
                audit('Creación de usuario',$email.' · '.implode(', ',array_map(fn($k)=>empresas()[$k]['corto'],$emps))); $req=mfaRequired(['role'=>$role]);
                notify([$nu],'bienvenida','Tu cuenta en OX3 Gestión está lista',$user['name'].' creó tu cuenta con el correo '.$email.' y permiso de '.($role==='editor'?'editor':'lector').'. Ingresa con la contraseña inicial que te entregó (no la enviamos por correo) y cámbiala en Mi cuenta.'.($req?' Al ingresar te pediremos activar la verificación en dos pasos con una app como Google Authenticator o Microsoft Authenticator.':''),[],'always');
                go('Usuario creado.'.(mailMode()!==''?' Le enviamos un correo de bienvenida (sin la contraseña).':''),['tab'=>'users']);
            }
            if ($action==='user_company' && $isAdmin) {
                $t=query('SELECT id,name,role FROM users WHERE id=?',[(int)post('id')])->fetch(PDO::FETCH_ASSOC); $k=post('empresa');
                if(!$t || !isset(empresas()[$k])) throw new RuntimeException('Usuario o empresa no válidos.');
                if($t['role']==='admin') throw new RuntimeException('El administrador entra a todas las empresas.');
                $has=(bool)query('SELECT 1 FROM user_companies WHERE user_id=? AND empresa=?',[$t['id'],$k])->fetchColumn();
                if($has) query('DELETE FROM user_companies WHERE user_id=? AND empresa=?',[$t['id'],$k]); else query('INSERT INTO user_companies(user_id,empresa) VALUES(?,?)',[$t['id'],$k]);
                audit($has?'Retiro de acceso a empresa':'Acceso a empresa',$t['name'].' · '.empresas()[$k]['nombre']);
                go(($has?'Se quitó a ':'Se dio a ').$t['name'].' acceso a '.empresas()[$k]['nombre'].'.',['tab'=>'users']);
            }
            if ($action==='toggle_user' && $isAdmin) {
                query('UPDATE users SET active=1-active WHERE id=? AND role<>?',[(int)post('id'),'admin']); audit('Cambio de acceso',post('id')); go('Acceso actualizado.',['tab'=>'users']);
            }
            // --- Proyectos y carpetas ---
            if ($action==='add_project' && $canEdit) {
                $name=cleanName(post('name')); $desc=shortText(post('description'));
                query('INSERT INTO projects(name,description,created) VALUES(?,?,?)',[$name,$desc,gmdate('c')]);
                $id=(int)$db->lastInsertId(); audit('Creación de proyecto',$name); go('Proyecto creado.',['p'=>$id]);
            }
            if ($action==='edit_project' && $canEdit) {
                $pr=project((int)post('id')); if(!$pr) throw new RuntimeException('El proyecto no existe.');
                $name=cleanName(post('name')); $desc=shortText(post('description'));
                query('UPDATE projects SET name=?,description=? WHERE id=?',[$name,$desc,$pr['id']]); audit('Edición de proyecto',$name); go('Proyecto actualizado.',['p'=>(int)$pr['id']]);
            }
            if ($action==='delete_project' && $isAdmin) {
                $pr=project((int)post('id')); if(!$pr) go();
                if(query('SELECT (SELECT COUNT(*) FROM files WHERE project_id=?)+(SELECT COUNT(*) FROM folders WHERE project_id=?)',[$pr['id'],$pr['id']])->fetchColumn()>0) throw new RuntimeException('Solo se puede eliminar un proyecto vacío. Mueve o elimina primero sus carpetas y documentos.');
                if(query('SELECT COUNT(*) FROM ps_budgets WHERE project_id=?',[$pr['id']])->fetchColumn()>0) throw new RuntimeException('Este proyecto tiene presupuestos asociados. Quítale el proyecto a esos presupuestos o elimínalos antes.');
                query('DELETE FROM projects WHERE id=?',[$pr['id']]); audit('Eliminación de proyecto',$pr['name']); go('Proyecto eliminado.');
            }
            if ($action==='add_folder' && $canEdit) {
                [$p,$f]=location((int)post('p'),(int)post('f')); $name=cleanName(post('name'));
                if(query('SELECT COUNT(*) FROM folders WHERE name=? COLLATE NOCASE AND project_id IS ? AND parent_id IS ?',[$name,$p,$f])->fetchColumn()>0) throw new RuntimeException('Ya existe una carpeta con ese nombre aquí.');
                if($f && count(folderTrail($f))>=10) throw new RuntimeException('Se permiten hasta 10 niveles de carpetas.');
                query('INSERT INTO folders(name,project_id,parent_id,created) VALUES(?,?,?,?)',[$name,$p,$f,gmdate('c')]);
                audit('Creación de carpeta',$name); go('Carpeta creada.',here($p,$f));
            }
            if ($action==='rename_folder' && $canEdit) {
                $fo=folder((int)post('id')); if(!$fo) throw new RuntimeException('La carpeta no existe.');
                $name=cleanName(post('name'));
                query('UPDATE folders SET name=? WHERE id=?',[$name,$fo['id']]); audit('Cambio de nombre de carpeta',$fo['name'].' → '.$name); go('Carpeta renombrada.',['f'=>(int)$fo['id']]);
            }
            if ($action==='delete_folder' && $isAdmin) {
                $fo=folder((int)post('id')); if(!$fo) go();
                if(query('SELECT (SELECT COUNT(*) FROM files WHERE folder_id=?)+(SELECT COUNT(*) FROM folders WHERE parent_id=?)',[$fo['id'],$fo['id']])->fetchColumn()>0) throw new RuntimeException('Solo se puede eliminar una carpeta vacía.');
                query('DELETE FROM folders WHERE id=?',[$fo['id']]); audit('Eliminación de carpeta',$fo['name']);
                go('Carpeta eliminada.',here($fo['project_id']!==null?(int)$fo['project_id']:null,$fo['parent_id']!==null?(int)$fo['parent_id']:null));
            }
            if ($action==='edit_file' && $canEdit) {
                $fi=query('SELECT * FROM files WHERE id=?',[(int)post('id')])->fetch(PDO::FETCH_ASSOC); if(!$fi) throw new RuntimeException('Documento no encontrado.');
                $dest=post('dest'); $p=0; $f=0;
                if(preg_match('/^f:(\d+)$/',$dest,$m)) $f=(int)$m[1]; elseif(preg_match('/^p:(\d+)$/',$dest,$m)) $p=(int)$m[1];
                [$p,$f]=location($p,$f);
                $name=cleanName(post('name'),180);
                if(strtolower(pathinfo($name,PATHINFO_EXTENSION))!==strtolower(pathinfo($fi['name'],PATHINFO_EXTENSION))) $name.='.'.strtolower(pathinfo($fi['name'],PATHINFO_EXTENSION));
                $category=in_array(post('category'),$categories,true)?post('category'):$fi['category'];
                query('UPDATE files SET name=?,category=?,project_id=?,folder_id=? WHERE id=?',[$name,$category,$p,$f,$fi['id']]);
                audit('Edición de documento',$name); go('Documento actualizado.',['preview'=>(int)$fi['id']]);
            }
            if ($action==='upload' && $canEdit) {
                [$p,$f]=location((int)post('p'),(int)post('f'));
                $up=$_FILES['file']??null;
                if(!$up || !is_array($up['name'])) throw new RuntimeException('Selecciona al menos un archivo.');
                $count=count($up['name']); if($count>20) throw new RuntimeException('Sube como máximo 20 archivos a la vez.');
                $category=in_array(post('category'),$categories,true)?post('category'):'Otros';
                $types=['pdf'=>['application/pdf'],'jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],'png'=>['image/png'],'txt'=>['text/plain'],'csv'=>['text/plain','text/csv','application/csv'],'docx'=>['application/zip','application/vnd.openxmlformats-officedocument.wordprocessingml.document'],'xlsx'=>['application/zip','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],'pptx'=>['application/zip','application/vnd.openxmlformats-officedocument.presentationml.presentation']];
                if(!is_dir(dataDir().'/uploads')&&!mkdir(dataDir().'/uploads',0700,true)) throw new RuntimeException('No se pudo crear el almacenamiento.');
                $ok=0; $failed=[];
                for($i=0;$i<$count;$i++){
                    $label=is_string($up['name'][$i]??null)?basename(str_replace('\\','/',$up['name'][$i])):'archivo';
                    try {
                        if(!is_int($up['error'][$i]??null) || $up['error'][$i]!==UPLOAD_ERR_OK) throw new RuntimeException('la carga falló (revisa el límite de tamaño)');
                        $tmp=$up['tmp_name'][$i]; $size=filesize($tmp);
                        if ($size===false || $size<1 || $size>$config['max_upload_mb']*1048576) throw new RuntimeException('vacío o superior al límite');
                        $name=preg_replace('/[\x00-\x1F\x7F]/u','',$label)??'archivo';
                        if(strlen($name)>180) throw new RuntimeException('nombre demasiado largo');
                        $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
                        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
                        if (!isset($types[$ext]) || !in_array($mime,$types[$ext],true)) throw new RuntimeException('formato no permitido');
                        if(in_array($ext,['docx','xlsx','pptx'],true)) {
                            $zip=new ZipArchive(); $part=['docx'=>'word/document.xml','xlsx'=>'xl/workbook.xml','pptx'=>'ppt/presentation.xml'][$ext];
                            if($zip->open($tmp)!==true) throw new RuntimeException('documento Office inválido');
                            $valid=$zip->locateName('[Content_Types].xml')!==false && $zip->locateName($part)!==false;
                            for($j=0;$j<$zip->numFiles;$j++) if(str_contains(strtolower((string)$zip->getNameIndex($j)), 'vbaproject')) $valid=false;
                            $zip->close(); if(!$valid) throw new RuntimeException('Office inválido o con macros');
                        }
                        $stored=bin2hex(random_bytes(24)); $dest=dataDir().'/uploads/'.$stored;
                        $db->exec('BEGIN IMMEDIATE');
                        try {
                            $total=(int)query('SELECT (SELECT COALESCE(SUM(size),0) FROM files)+(SELECT COALESCE(SUM(size),0) FROM cc_files)')->fetchColumn();
                            if($total+$size>$config['quota_mb']*1048576) throw new RuntimeException('cuota de almacenamiento completa');
                            if(!move_uploaded_file($tmp,$dest)) throw new RuntimeException('no se pudo guardar');
                            query('INSERT INTO files(name,stored,size,category,owner,created,project_id,folder_id) VALUES(?,?,?,?,?,?,?,?)',[$name,$stored,$size,$category,$user['id'],gmdate('c'),$p,$f]);
                            $db->exec('COMMIT');
                        } catch(Throwable $ex) { $db->exec('ROLLBACK'); if(is_file($dest)) unlink($dest); throw $ex; }
                        audit('Carga',$name); $ok++;
                    } catch(RuntimeException $ex) { $failed[]=$label.': '.($ex instanceof PDOException?'error de almacenamiento':$ex->getMessage()); }
                }
                if($failed && !$ok) throw new RuntimeException('No se cargó ningún archivo. '.implode(' · ',$failed));
                go(($ok===1?'1 archivo cargado.':$ok.' archivos cargados.').($failed?' No se cargaron: '.implode(' · ',$failed):''),here($p,$f));
            }
            accountPost($action);
            if (str_starts_with($action,'oc_')) ocPost($action);
            if (str_starts_with($action,'cot_')) cotPost($action);
            if (str_starts_with($action,'pf_') && pfOn()) pfPost($action);
            if (str_starts_with($action,'cc_')) ccPost($action);
            if (str_starts_with($action,'ps_')) psPost($action);
            if (str_starts_with($action,'cu_') && cuOn()) cuPost($action);
            if ($action==='delete' && $isAdmin) {
                $f=query('SELECT * FROM files WHERE id=?',[(int)post('id')])->fetch(PDO::FETCH_ASSOC);
                if ($f) { $dest=dataDir().'/uploads/'.$f['stored']; if(is_file($dest)&&!unlink($dest)) throw new RuntimeException('No se pudo eliminar el archivo.'); query('DELETE FROM files WHERE id=?',[$f['id']]); audit('Eliminación',$f['name']);
                    go('Archivo eliminado.',here($f['project_id']!==null?(int)$f['project_id']:null,$f['folder_id']!==null?(int)$f['folder_id']:null)); }
                go('Archivo eliminado.');
            }
        }
    } catch(RuntimeException $ex) { $error=$ex instanceof PDOException ? 'Error de almacenamiento. Revisa el registro del hosting.' : $ex->getMessage(); }
}
if ($mfaForce && array_diff(array_keys($_GET),['tab'])) go();
// Sin empresa elegida solo se muestra la pantalla de selección (ninguna descarga ni impresión).
$showPicker = $user && !$mfaForce && (!$empresaSet || isset($_GET['empresas']));
if (!$showPicker) {
ocGetRequests();
cotGetRequests();
pfGetRequests();
ccGetRequests();
psGetRequests();
cuGetRequests();
accountGetRequests();
}
if (!$showPicker && (isset($_GET['download']) || isset($_GET['raw']))) {
    if(!$user) stop(403,'Debes iniciar sesión.');
    $inline=isset($_GET['raw']);
    $f=query('SELECT * FROM files WHERE id=?',[(int)($inline?$_GET['raw']:$_GET['download'])])->fetch(PDO::FETCH_ASSOC);
    if(!$f||!is_file(dataDir().'/uploads/'.$f['stored'])) stop(404,'Archivo no encontrado.');
    $ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));
    session_write_close();
    // Imágenes incrustadas en Word, Excel o PowerPoint para la vista previa.
    if($inline && isset($_GET['part']) && is_string($_GET['part']) && in_array($ext,['docx','xlsx','pptx'],true)) officeServeMedia(dataDir().'/uploads/'.$f['stored'],$_GET['part']);
    if($inline) {
        // Solo PDF e imágenes se muestran en el navegador; el resto se descarga.
        $mime=['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png'][$ext]??null;
        if(!$mime) stop(415,'Este formato no se puede visualizar. Descárgalo.');
        header('Content-Type: '.$mime); header('Content-Length: '.$f['size']);
        header('X-Frame-Options: SAMEORIGIN');
        // El visor de PDF del navegador no funciona con una CSP restrictiva; las imágenes sí la llevan.
        header($ext==='pdf' ? "Content-Security-Policy: frame-ancestors 'self'" : "Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'self'");
        header('Cache-Control: private, max-age=600');
        header("Content-Disposition: inline; filename=\"documento.$ext\"; filename*=UTF-8''".rawurlencode($f['name']));
    } else {
        audit('Descarga',$f['name']);
        header('Content-Type: application/octet-stream'); header('Content-Length: '.$f['size']);
        header("Content-Disposition: attachment; filename=\"documento.$ext\"; filename*=UTF-8''".rawurlencode($f['name']));
    }
    readfile(dataDir().'/uploads/'.$f['stored']); exit;
}
$message=$_SESSION['message']??''; unset($_SESSION['message']);
$turnstileLogin = !$user && $installed && $turnstileOn;
if ($turnstileLogin) {
    // Solo en la pantalla de ingreso: permitir el script y el recuadro de Cloudflare Turnstile.
    header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src https://challenges.cloudflare.com; img-src 'self'; frame-src https://challenges.cloudflare.com; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
    header('Referrer-Policy: strict-origin');
}
require __DIR__.'/view.php';
