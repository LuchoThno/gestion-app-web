<?php
declare(strict_types=1);
ini_set('display_errors', '0');
umask(0077);
set_exception_handler(function(Throwable $e): void {
    error_log((string)$e); http_response_code(500);
    echo 'No fue posible completar la operación. Revisa el registro de errores del hosting.';
});
function e(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function stop(int $code, string $text): never { http_response_code($code); exit($text); }
$root = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
$path = realpath(__DIR__);
if ($root !== '' && ($path === $root || str_starts_with($path, rtrim($root, '/').'/'))) stop(503, 'Instala la carpeta private fuera de la raíz pública. Consulta INSTALACION.md.');
if (!is_file(__DIR__.'/config.php')) stop(503, 'Configuración pendiente: copia private/config.example.php a private/config.php y define la clave de instalación.');
$config = require __DIR__.'/config.php';
$secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
if ($config['https_only'] && !$secure) { header('Location: https://gestion.ox3.cl/', true, 302); exit; }
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
$cspNonce = base64_encode(random_bytes(16)); // permite el <style> de la vista previa de PowerPoint
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'nonce-$cspNonce'; script-src 'none'; img-src 'self'; frame-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
header('Cache-Control: no-store');
if ($secure) header('Strict-Transport-Security: max-age=31536000');
ini_set('session.use_strict_mode','1');
ini_set('session.use_only_cookies','1');
session_name('OX3GESTION');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Strict']);
session_start();
if (isset($_SESSION['last']) && time()-$_SESSION['last'] > 1800) { $expired=isset($_SESSION['uid']); $_SESSION=[]; if ($expired) $_SESSION['message']='Tu sesión se cerró por inactividad. Ingresa nuevamente.'; }
$_SESSION['last']=time();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
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
function csrf(): string { return '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">'; }
/** Redirige a index.php con parámetros enteros ya validados (p, f, preview, oc, cot, mov, rend, edit, tab). */
function go(string $message='', array $to=[]): never {
    $_SESSION['message']=$message;
    $q=[]; foreach($to as $k=>$v) if(in_array($k,['p','f','preview','oc','cot','mov','rend','edit'],true) && is_int($v) && $v>=0) $q[$k]=$v; elseif($k==='tab' && in_array($v,['account','users','activity','oc','oc_new','oc_cfg','cot','cot_new','cot_cfg','cc','cc_movs','cc_new','cc_rends','cc_rend_new','cc_cfg'],true)) $q[$k]=$v;
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
if (!extension_loaded('pdo_sqlite') || !extension_loaded('fileinfo') || !class_exists('ZipArchive')) stop(503,'Habilita pdo_sqlite, sqlite3, fileinfo y zip en PHP. Consulta INSTALACION.md.');
$dbFile = __DIR__.'/gestion.sqlite';
if (!is_writable(__DIR__) || (is_file($dbFile) && !is_writable($dbFile))) stop(503, 'PHP no puede escribir en la carpeta private (ni en gestion.sqlite). Da permisos de escritura al usuario de PHP: 700/755 para la carpeta y 600/644 para los archivos. Consulta INSTALACION.md.');
try { $db = new PDO('sqlite:'.$dbFile, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); }
catch (PDOException $e) { error_log((string)$e); stop(503, 'No se pudo abrir la base de datos SQLite en la carpeta private. Revisa permisos y la restricción open_basedir del hosting.'); }
$db->exec('PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON;');
$db->exec('CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT UNIQUE NOT NULL, password TEXT NOT NULL, role TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1);
CREATE TABLE IF NOT EXISTS files(id INTEGER PRIMARY KEY, name TEXT NOT NULL, stored TEXT UNIQUE NOT NULL, size INTEGER NOT NULL, category TEXT NOT NULL, owner INTEGER NOT NULL REFERENCES users(id), created TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS attempts(bucket TEXT PRIMARY KEY, count INTEGER NOT NULL, until INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS audit(id INTEGER PRIMARY KEY, actor INTEGER, action TEXT NOT NULL, detail TEXT NOT NULL, created TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS projects(id INTEGER PRIMARY KEY, name TEXT NOT NULL, description TEXT NOT NULL DEFAULT \'\', created TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS folders(id INTEGER PRIMARY KEY, name TEXT NOT NULL, project_id INTEGER, parent_id INTEGER, created TEXT NOT NULL);');
// Migración aditiva: ubicación de cada archivo (los existentes quedan en "General").
$cols=array_column($db->query('PRAGMA table_info(files)')->fetchAll(PDO::FETCH_ASSOC),'name');
if(!in_array('project_id',$cols,true)) $db->exec('ALTER TABLE files ADD COLUMN project_id INTEGER');
if(!in_array('folder_id',$cols,true)) $db->exec('ALTER TABLE files ADD COLUMN folder_id INTEGER');
$db->exec('CREATE INDEX IF NOT EXISTS files_location ON files(project_id, folder_id); CREATE INDEX IF NOT EXISTS folders_parent ON folders(project_id, parent_id);');
function query(string $sql, array $params=[]): PDOStatement { global $db; $s=$db->prepare($sql); $s->execute($params); return $s; }
function audit(string $action, string $detail=''): void { query('INSERT INTO audit(actor,action,detail,created) VALUES(?,?,?,?)',[$_SESSION['uid']??null,$action,$detail,gmdate('c')]); }
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
$user = isset($_SESSION['uid']) ? query('SELECT * FROM users WHERE id=? AND active=1',[$_SESSION['uid']])->fetch(PDO::FETCH_ASSOC) : false;
if ($user && !hash_equals(hash('sha256',$user['password']), (string)($_SESSION['auth_version']??''))) { unset($_SESSION['uid'],$_SESSION['auth_version']); $user=false; }
$installed = (int)query('SELECT COUNT(*) FROM users')->fetchColumn()>0;
$categories=['Administración','Cursos y alumnos','Certificados','Comercial','Operaciones','Otros'];
$canEdit = $user && in_array($user['role'],['admin','editor'],true);
$isAdmin = $user && $user['role']==='admin';
require __DIR__.'/office.php';
require __DIR__.'/oc.php';
require __DIR__.'/cot.php';
require __DIR__.'/contable.php';
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
            $db->exec('COMMIT'); audit('Instalación'); go('Administrador creado. Ya puedes ingresar.');
        }
        if ($installed && $action==='login') {
            $email=strtolower(post('email'));
            // Primero Turnstile: así un bot no consume los intentos ni bloquea la cuenta de nadie.
            if ($turnstileOn && !turnstileValid($turnstileSecret, post('cf-turnstile-response'))) throw new RuntimeException('Completa la verificación de seguridad antes de ingresar.');
            $buckets=[hash('sha256','ip:'.($_SERVER['REMOTE_ADDR']??'')),hash('sha256','email:'.$email)];
            $db->exec('BEGIN IMMEDIATE');
            query('DELETE FROM attempts WHERE until < ?',[time()]);
            foreach($buckets as $b) {
                $n=query('SELECT count FROM attempts WHERE bucket=?',[$b])->fetchColumn();
                if ($n!==false && (int)$n>=5) { $db->exec('COMMIT'); throw new RuntimeException('Demasiados intentos. Intenta nuevamente en 15 minutos.'); }
            }
            foreach($buckets as $b) query('INSERT INTO attempts(bucket,count,until) VALUES(?,1,?) ON CONFLICT(bucket) DO UPDATE SET count=count+1',[$b,time()+900]);
            $db->exec('COMMIT');
            $u=query('SELECT * FROM users WHERE email=? AND active=1',[$email])->fetch(PDO::FETCH_ASSOC);
            $hash=$u['password']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
            if (!password_verify(passwordInput(),$hash)||!$u) throw new RuntimeException('Correo o contraseña incorrectos.');
            session_regenerate_id(true); $_SESSION['uid']=$u['id']; $_SESSION['auth_version']=hash('sha256',$u['password']); $_SESSION['csrf']=bin2hex(random_bytes(32));
            foreach($buckets as $b) query('DELETE FROM attempts WHERE bucket=?',[$b]);
            audit('Ingreso'); go();
        }
        if ($user) {
            if ($action==='logout') { audit('Cierre de sesión'); $_SESSION=[]; session_regenerate_id(true); go(); }
            if ($action==='password') {
                if (!password_verify(is_string($_POST['current_password']??null)?$_POST['current_password']:'',$user['password']) || !validPassword(passwordInput())) throw new RuntimeException('Verifica tu clave actual y usa una nueva de 12 a 72 caracteres.');
                query('UPDATE users SET password=? WHERE id=?',[password_hash(passwordInput(),PASSWORD_DEFAULT),$user['id']]);
                audit('Cambio de contraseña'); $_SESSION=[]; session_regenerate_id(true); go('Contraseña actualizada. Ingresa nuevamente.');
            }
            if ($action==='add_user' && $isAdmin) {
                $email=strtolower(post('email'));
                if (!filter_var($email,FILTER_VALIDATE_EMAIL)||!validPassword(passwordInput())||post('name')==='') throw new RuntimeException('Revisa nombre, correo y contraseña (12 a 72 caracteres).');
                if(query('SELECT id FROM users WHERE email=?',[$email])->fetchColumn()) throw new RuntimeException('Ese correo ya está registrado.');
                $role=in_array(post('role'),['editor','viewer'],true)?post('role'):'viewer';
                query('INSERT INTO users(name,email,password,role) VALUES(?,?,?,?)',[post('name'),$email,password_hash(passwordInput(),PASSWORD_DEFAULT),$role]); audit('Creación de usuario',$email); go('Usuario creado.',['tab'=>'users']);
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
                if(!is_dir(__DIR__.'/uploads')&&!mkdir(__DIR__.'/uploads',0700)) throw new RuntimeException('No se pudo crear el almacenamiento.');
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
                        $stored=bin2hex(random_bytes(24)); $dest=__DIR__.'/uploads/'.$stored;
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
            if (str_starts_with($action,'oc_')) ocPost($action);
            if (str_starts_with($action,'cot_')) cotPost($action);
            if (str_starts_with($action,'cc_')) ccPost($action);
            if ($action==='delete' && $isAdmin) {
                $f=query('SELECT * FROM files WHERE id=?',[(int)post('id')])->fetch(PDO::FETCH_ASSOC);
                if ($f) { $dest=__DIR__.'/uploads/'.$f['stored']; if(is_file($dest)&&!unlink($dest)) throw new RuntimeException('No se pudo eliminar el archivo.'); query('DELETE FROM files WHERE id=?',[$f['id']]); audit('Eliminación',$f['name']);
                    go('Archivo eliminado.',here($f['project_id']!==null?(int)$f['project_id']:null,$f['folder_id']!==null?(int)$f['folder_id']:null)); }
                go('Archivo eliminado.');
            }
        }
    } catch(RuntimeException $ex) { $error=$ex instanceof PDOException ? 'Error de almacenamiento. Revisa el registro del hosting.' : $ex->getMessage(); }
}
ocGetRequests();
cotGetRequests();
ccGetRequests();
if (isset($_GET['download']) || isset($_GET['raw'])) {
    if(!$user) stop(403,'Debes iniciar sesión.');
    $inline=isset($_GET['raw']);
    $f=query('SELECT * FROM files WHERE id=?',[(int)($inline?$_GET['raw']:$_GET['download'])])->fetch(PDO::FETCH_ASSOC);
    if(!$f||!is_file(__DIR__.'/uploads/'.$f['stored'])) stop(404,'Archivo no encontrado.');
    $ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));
    session_write_close();
    // Imágenes incrustadas en Word, Excel o PowerPoint para la vista previa.
    if($inline && isset($_GET['part']) && is_string($_GET['part']) && in_array($ext,['docx','xlsx','pptx'],true)) officeServeMedia(__DIR__.'/uploads/'.$f['stored'],$_GET['part']);
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
    readfile(__DIR__.'/uploads/'.$f['stored']); exit;
}
$message=$_SESSION['message']??''; unset($_SESSION['message']);
$turnstileLogin = !$user && $installed && $turnstileOn;
if ($turnstileLogin) {
    // Solo en la pantalla de ingreso: permitir el script y el recuadro de Cloudflare Turnstile.
    header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src https://challenges.cloudflare.com; img-src 'self'; frame-src https://challenges.cloudflare.com; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
    header('Referrer-Policy: strict-origin');
}
require __DIR__.'/view.php';
