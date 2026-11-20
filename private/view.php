<?php
function ic(string $n): string {
    static $p=[
        'home'=>'<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/>',
        'folder'=>'<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'project'=>'<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 13h18"/>',
        'inbox'=>'<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5h13L22 12v6a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-6z"/>',
        'upload'=>'<path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
        'download'=>'<path d="M12 4v12M7 11l5 5 5-5"/><path d="M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
        'eye'=>'<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'search'=>'<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
        'plus'=>'<path d="M12 5v14M5 12h14"/>',
        'users'=>'<circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0M16 4a4 4 0 0 1 0 8M22 21a7 7 0 0 0-4-6.3"/>',
        'activity'=>'<path d="M22 12h-4l-3 8L9 4l-3 8H2"/>',
        'user'=>'<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'logout'=>'<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        'edit'=>'<path d="M4 20h4L19 9l-4-4L4 16z"/>',
        'trash'=>'<path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/>',
        'external'=>'<path d="M14 4h6v6M20 4l-9 9"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
        'back'=>'<path d="M15 18l-6-6 6-6"/>',
        'chevron'=>'<path d="M9 6l6 6-6 6"/>',
        'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'cart'=>'<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.7 12.4a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.5L21 8H6"/>',
        'print'=>'<path d="M6 9V3h12v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M7 14h10v7H7z"/>',
        'copy'=>'<rect x="8" y="8" width="13" height="13" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/>',
        'settings'=>'<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'quote'=>'<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M8 13h8M8 17h5"/>',
        'more'=>'<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>',
        'chart'=>'<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'list'=>'<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'receipt'=>'<path d="M5 3h14v18l-3-2-2 2-2-2-2 2-2-2-3 2z"/><path d="M9 8h6M9 12h6M9 16h3"/>',
        'bell'=>'<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/>',
        'shield'=>'<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'check'=>'<path d="M20 6L9 17l-5-5"/>',
        'key'=>'<circle cx="7.5" cy="15.5" r="4.5"/><path d="M10.7 12.3L21 2M16 7l3 3M18.5 4.5l2 2"/>',
        'database'=>'<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.7 4 3 9 3s9-1.3 9-3V5M3 12c0 1.7 4 3 9 3s9-1.3 9-3"/>',
        'trend'=>'<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 6-7"/>',
        'mail'=>'<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 7l-10 6L2 7"/>',
        'target'=>'<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
        'info'=>'<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/>',
        'globe'=>'<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18a14 14 0 0 1 0-18"/>',
        'switch'=>'<path d="M7 4L3 8l4 4"/><path d="M3 8h14"/><path d="M17 20l4-4-4-4"/><path d="M21 16H7"/>',
    ];
    return '<svg class="ic" viewBox="0 0 24 24" aria-hidden="true">'.($p[$n]??'').'</svg>';
}
function size(int|string $b): string { $b=(int)$b; return $b>=1048576 ? number_format($b/1048576,1,',','.').' MB' : number_format(max(1,(int)ceil($b/1024)),0,',','.').' KB'; }
function fdate(string $iso, bool $time=false): string {
    try { $d=new DateTime($iso); $d->setTimezone(new DateTimeZone('America/Santiago')); return $d->format($time?'d-m-Y H:i':'d-m-Y'); } catch(Exception) { return substr($iso,0,10); }
}
function ftype(string $name): array {
    $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
    return match($ext){ 'pdf'=>['PDF','t-pdf'], 'docx'=>['DOC','t-doc'], 'xlsx','csv'=>[$ext==='csv'?'CSV':'XLS','t-xls'], 'pptx'=>['PPT','t-ppt'], 'jpg','jpeg','png'=>['IMG','t-img'], default=>['TXT','t-txt'] };
}
function tone(int $id): string { return 'tone'.($id%6); }
function url(array $q): string { return 'index.php'.($q?'?'.http_build_query($q):''); }
/** Opciones de ubicación (General, proyectos y carpetas) para mover documentos. */
function locationOptions(): array {
    $folders=query('SELECT id,name,project_id,parent_id FROM folders ORDER BY name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
    $kids=[]; foreach($folders as $fo) $kids[($fo['project_id']??0).':'.($fo['parent_id']??0)][]=$fo;
    $walk=function(int $pid,int $parent,int $depth) use(&$walk,$kids): array { $out=[]; foreach($kids[$pid.':'.$parent]??[] as $fo){ $out[]=['f:'.$fo['id'],str_repeat('   ',$depth).'└ '.$fo['name']]; $out=array_merge($out,$walk($pid,(int)$fo['id'],$depth+1)); } return $out; };
    $opts=[['g','General (sin proyecto)']]; $opts=array_merge($opts,$walk(0,0,1));
    foreach(query('SELECT id,name FROM projects ORDER BY name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC) as $pr){ $opts[]=['p:'.$pr['id'],$pr['name']]; $opts=array_merge($opts,$walk((int)$pr['id'],0,1)); }
    return $opts;
}
/** Activación de la verificación en dos pasos: código QR, clave manual y confirmación con el primer código. */
function mfaSetup(bool $forced): void { global $user; $b32=(string)($_SESSION['mfa_new']??''); ?>
<?php if($b32===''):?><form method="post"><?=csrf()?><button class="primary" name="action" value="mfa_begin"><?=ic('shield')?>Activar verificación en dos pasos</button></form>
<?php else:?>
<ol class="mfa-steps">
  <li>Instala en tu teléfono una app de autenticación: <b>Google Authenticator</b>, <b>Microsoft Authenticator</b> u otra compatible.</li>
  <li>En la app, agrega una cuenta y escanea este código:<div class="mfa-qr"><?=qrSvg(totpUri($b32,$user['email']),196)?></div>
    <details class="mfa-manual"><summary>¿No puedes escanear? Escribe la clave</summary><code><?=e(trim(chunk_split($b32,4,' ')))?></code><small>Tipo: basada en tiempo · 6 dígitos · 30 segundos</small></details></li>
  <li>Escribe el código de 6 dígitos que muestra la app:
    <form method="post" class="mfa-confirm"><?=csrf()?><label><span class="sr">Código de verificación</span><input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required placeholder="123 456"<?=$forced?' autofocus':''?>></label><button class="primary" name="action" value="mfa_enable">Activar</button></form></li>
</ol>
<form method="post" class="mfa-links"><?=csrf()?><button class="link-btn" name="action" value="mfa_restart">Generar otro código QR</button><?php if(!$forced):?> · <button class="link-btn" name="action" value="mfa_setup_cancel">Cancelar</button><?php endif?></form>
<?php endif?>
<?php }
function fileRows(array $files, bool $showLocation=false): void { global $isAdmin; ?>
<div class="table-wrap"><table class="files"><thead><tr><th>Documento</th><?php if($showLocation):?><th>Ubicación</th><?php endif?><th class="hide-sm">Categoría</th><th class="hide-sm">Subido por</th><th class="hide-sm">Fecha</th><th class="right">Acciones</th></tr></thead><tbody>
<?php foreach($files as $f): [$tl,$tc]=ftype($f['name']); ?><tr>
<td><a class="doc" href="<?=e(url(['preview'=>(int)$f['id']]))?>"><span class="ftype <?=$tc?>"><?=$tl?></span><span><b><?=e($f['name'])?></b><small><?=size($f['size'])?></small></span></a></td>
<?php if($showLocation):?><td class="loc"><?=ic($f['project_name']?'project':'inbox')?><?=e($f['project_name']??'General')?><?php if($f['folder_name']):?><small><?=ic('folder')?><?=e($f['folder_name'])?></small><?php endif?></td><?php endif?>
<td class="hide-sm"><span class="badge"><?=e($f['category'])?></span></td><td class="hide-sm"><?=e($f['author'])?></td><td class="hide-sm nowrap"><?=e(fdate($f['created']))?></td>
<td class="right nowrap"><a class="icon-btn" href="<?=e(url(['preview'=>(int)$f['id']]))?>" title="Ver"><?=ic('eye')?><span class="sr">Ver</span></a><a class="icon-btn" href="<?=e(url(['download'=>(int)$f['id']]))?>" title="Descargar"><?=ic('download')?><span class="sr">Descargar</span></a></td>
</tr><?php endforeach?>
</tbody></table></div>
<?php }
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=$user&&!$showPicker&&!$mfaForce?e(empresa()['corto']):'OX3'?> | Gestión documental</title><link rel="stylesheet" href="assets/style.css?v=17"><link rel="manifest" href="manifest.webmanifest"><meta name="theme-color" content="#0f2a45"><link rel="icon" href="assets/icon-192.png" sizes="192x192"><link rel="apple-touch-icon" href="assets/apple-touch-icon.png"><meta name="apple-mobile-web-app-title" content="OX3 Gestión"><?php if($turnstileLogin): ?><script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script><?php endif ?></head>
<body>
<?php if(!$user || $mfaForce): $mfaStep=!$user && $installed && !empty($_SESSION['mfa_uid']) && (int)($_SESSION['mfa_until']??0)>=time(); ?>
<main class="login"><section class="brand-panel"><a class="logo" href="index.php" aria-label="OX3 inicio">OX<span>3</span><small>CAPACITACIONES CHILE</small></a><div class="pitch"><span class="eyebrow">PLATAFORMA DE GESTIÓN</span><h1>Tu documentación.<br>En un solo lugar.</h1><p>Organiza por proyectos y carpetas los archivos<br>que dan respaldo a cada operación de OX3.</p><div class="tags"><span>Marítima</span><span>Portuaria</span><span>Minera</span></div></div><footer>CAPACITACIONES OX3 CHILE SPA <span>gestion.ox3.cl</span></footer></section>
<section class="login-form"><div class="form-wrap"><span class="eyebrow muted">GESTIÓN DOCUMENTAL / OX3</span>
<?php if($mfaForce): ?>
<div class="icon-lock"><?=ic('shield')?></div><h2>Protege tu cuenta</h2><p class="subtitle">Tu permiso de <?=e(['admin'=>'administrador','editor'=>'editor','viewer'=>'lector'][$user['role']])?> requiere verificación en dos pasos. Actívala una vez y luego, al ingresar, te pediremos tu contraseña y un código de tu teléfono.</p>
<?php if($error): ?><div class="alert error" role="alert"><?=e($error)?></div><?php endif ?><?php if($message): ?><div class="alert" role="status"><?=e($message)?></div><?php endif ?>
<?php mfaSetup(true) ?>
<form method="post" class="mfa-links"><?=csrf()?><button class="link-btn" name="action" value="logout">Salir</button></form>
</div><small class="copyright">© <?=date('Y')?> OX3 · Gestión documental</small></section></main>
<?php elseif($mfaStep): ?>
<div class="icon-lock"><?=ic('shield')?></div><h2>Verificación en dos pasos</h2><p class="subtitle">Abre tu app de autenticación y escribe el código de 6 dígitos de OX3 Gestión.</p>
<?php if($error): ?><div class="alert error" role="alert"><?=e($error)?></div><?php endif ?>
<form method="post"><?=csrf()?><input type="hidden" name="action" value="mfa_login">
<label>Código de verificación<input name="code" inputmode="numeric" autocomplete="one-time-code" required maxlength="12" placeholder="123 456" autofocus></label>
<button class="primary full">Verificar e ingresar →</button></form>
<p class="help">¿Perdiste el teléfono? Escribe uno de tus códigos de recuperación (formato abcd-efgh) en el mismo campo, o pide al administrador que restablezca tu verificación.</p>
<form method="post" class="mfa-links"><?=csrf()?><button class="link-btn" name="action" value="mfa_cancel">← Volver e ingresar con otra cuenta</button></form>
</div><small class="copyright">© <?=date('Y')?> OX3 · Gestión documental</small></section></main>
<?php else: ?>
<div class="icon-lock"><?=ic('user')?></div><h2><?= $installed?'Bienvenido de nuevo':'Configura tu acceso' ?></h2><p class="subtitle"><?= $installed?'Ingresa con tu cuenta para continuar.':'Crea el administrador de la plataforma.' ?></p>
<?php if($error): ?><div class="alert error" role="alert"><?=e($error)?></div><?php endif ?>
<?php if($message): ?><div class="alert" role="status"><?=e($message)?></div><?php endif ?>
<form method="post"><?=csrf()?><input type="hidden" name="action" value="<?=$installed?'login':'setup'?>">
<?php if(!$installed): ?><label>Clave de instalación<input type="password" name="setup_key" required autocomplete="off"></label><label>Nombre del administrador<input name="name" maxlength="100" required autocomplete="name"></label><?php endif ?>
<label>Correo electrónico<input type="email" name="email" placeholder="nombre@ox3.cl" required maxlength="254" autocomplete="username" value="<?=e($_SERVER['REQUEST_METHOD']==='POST'?post('email'):'')?>"<?=($error!=='' && post('email')!=='')?'':' autofocus'?>></label>
<label>Contraseña<input type="password" name="password" placeholder="Tu contraseña" required <?=!$installed?'minlength="12" maxlength="72"':''?> autocomplete="<?=$installed?'current-password':'new-password'?>"<?=($error!=='' && post('email')!=='')?' autofocus':''?>></label>
<?php if($turnstileLogin): ?><div class="turnstile"><div class="cf-turnstile" data-sitekey="<?=e($turnstileSite)?>" data-action="login" data-language="es" data-theme="light" data-size="flexible"></div><noscript><p class="help">Para ingresar, habilita JavaScript: la verificación de seguridad de Cloudflare lo necesita.</p></noscript></div><?php endif ?>
<button class="primary full"><?=$installed?'Ingresar a la plataforma →':'Crear administrador →'?></button></form>
<p class="help">Acceso exclusivo para personal autorizado.<br>Si necesitas acceso, contacta al administrador de OX3.</p><div class="secure-label">● &nbsp; Documentación interna · Acceso restringido<?=$turnstileLogin?' · Protegido por Cloudflare':''?></div></div><small class="copyright">© <?=date('Y')?> OX3 · Gestión documental</small></section></main>
<?php endif ?>
<?php elseif($showPicker): $mine=userEmpresas($user); ?>
<main class="picker"><div class="picker-wrap">
<header class="picker-head"><a class="logo" href="index.php" aria-label="OX3 Gestión">OX<span>3</span><small>PLATAFORMA DE GESTIÓN</small></a>
<form method="post"><?=csrf()?><button class="ghost small" name="action" value="logout"><?=ic('logout')?>Salir</button></form></header>
<span class="eyebrow">HOLA, <?=e(mb_strtoupper(explode(' ',$user['name'])[0]))?></span><h1>¿A qué empresa quieres entrar?</h1>
<p class="picker-sub">Cada empresa tiene sus propios documentos, cotizaciones, órdenes de compra, contabilidad y presupuestos. Puedes cambiar de empresa cuando quieras desde el menú.</p>
<?php if($error): ?><div class="alert error" role="alert"><?=e($error)?></div><?php endif ?><?php if($message): ?><div class="alert" role="status"><?=e($message)?></div><?php endif ?>
<form method="post" class="picker-grid"><?=csrf()?><input type="hidden" name="action" value="company_select">
<?php foreach(empresas() as $k=>$em): $ok=in_array($k,$mine,true); $cur=$empresaSet&&$k===$empresaKey; ?>
<button class="co-card<?=$cur?' current':''?>" name="empresa" value="<?=e($k)?>"<?=$ok?'':' disabled'?>>
<span class="co-card-logo"><img src="<?=e($em['logo'])?>" alt=""></span>
<span class="co-card-body"><strong><?=e($em['nombre'])?></strong><small><?=e($em['lema'])?> · <?=e($em['web'])?></small></span>
<span class="co-card-go"><?=$ok?($cur?'Seguir en esta empresa →':'Entrar al portal →'):'Sin acceso: pídelo al administrador'?></span>
</button>
<?php endforeach?>
</form>
<?php if(!$mine):?><p class="picker-sub">Tu cuenta aún no tiene acceso a ninguna empresa. Pide al administrador que te lo asigne en Usuarios.</p><?php endif?>
<small class="picker-foot">© <?=date('Y')?> OX3 · Gestión documental · gestion.ox3.cl</small>
</div></main>
<?php else:
$g=fn(string $k): string => is_string($_GET[$k]??null) ? trim($_GET[$k]) : '';
$search=$g('q'); $category=in_array($g('category'),$categories,true)?$g('category'):'';
$tab=in_array($g('tab'),APP_TABS,true)?$g('tab'):'';
if(in_array($tab,['users','activity','oc_cfg','cot_cfg','pf_cfg','cc_cfg','cu_cfg','security'],true)&&!$isAdmin) $tab='';
if($tab==='cc_new'&&!$canEdit) $tab='cc_movs';
if($tab==='cc_rend_new'&&!$canEdit) $tab='cc_rends';
if($tab==='oc_new'&&!$canEdit) $tab='oc';
if($tab==='cot_new'&&!$canEdit) $tab='cot';
if($tab==='pf_new'&&!$canEdit) $tab='pf';
if(str_starts_with($tab,'pf')&&!pfOn()) $tab='';
if($tab==='ps_new'&&!$canEdit) $tab='ps';
if(($tab==='cu_new'||$tab==='cu_import')&&!$canEdit) $tab='cu';
if(str_starts_with($tab,'cu')&&!cuOn()) $tab='';
$ocId=(int)$g('oc'); $cotId=(int)$g('cot'); $pfId=pfOn()?(int)$g('pf'):0; $movId=(int)$g('mov'); $rendId=(int)$g('rend'); $psId=(int)$g('pres'); $cuId=cuOn()?(int)$g('curso'):0;
$previewId=(int)$g('preview'); $curF=(int)$g('f'); $curP=isset($_GET['p'])?(int)$g('p'):-1;
$sort=in_array($g('sort'),['name','size'],true)?$g('sort'):'recent';
$order=['recent'=>'files.id DESC','name'=>'files.name COLLATE NOCASE','size'=>'files.size DESC'][$sort];
$select='SELECT files.*,users.name AS author,projects.name AS project_name,folders.name AS folder_name FROM files JOIN users ON users.id=files.owner LEFT JOIN projects ON projects.id=files.project_id LEFT JOIN folders ON folders.id=files.folder_id';
$stats=query('SELECT COUNT(*) AS total, COALESCE(SUM(size),0)+(SELECT COALESCE(SUM(size),0) FROM cc_files) AS bytes FROM files')->fetch(PDO::FETCH_ASSOC);
$projects=query('SELECT projects.*,(SELECT COUNT(*) FROM files WHERE project_id=projects.id) AS nfiles,(SELECT COUNT(*) FROM folders WHERE project_id=projects.id) AS nfolders FROM projects ORDER BY name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
$generalCount=(int)query('SELECT COUNT(*) FROM files WHERE project_id IS NULL')->fetchColumn();
// Ubicación actual
$preview=$previewId?query($select.' WHERE files.id=?',[$previewId])->fetch(PDO::FETCH_ASSOC):false;
$curFolder=false; $curProject=false; $trail=[];
if($preview){ $curF=(int)$preview['folder_id']; $curP=$preview['project_id']===null?0:(int)$preview['project_id']; }
if($curF>0){ $curFolder=folder($curF); if($curFolder){ $trail=folderTrail($curF); $curP=$curFolder['project_id']===null?0:(int)$curFolder['project_id']; } else $curF=0; }
if($curP>0){ $curProject=project($curP); if(!$curProject) $curP=-1; }
$mode=$previewId?'preview':($ocId?'oc_doc':($cotId?'cot_doc':($pfId?'pf_doc':($movId?'cc_mov_doc':($rendId?'cc_rend_doc':($psId?'ps_doc':($cuId?'cu_doc':($tab?:(($search!==''||$category!=='')?'search':(($curP>=0||$curF>0)?'folder':'home'))))))))));
$active=fn(bool $c): string => $c?' class="active" aria-current="page"':'';
$quota=(int)$config['quota_mb']*1048576;
$pending=pendingItems($user); $unread=(int)query('SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL',[$user['id']])->fetchColumn();
$nPendWork=count(array_filter($pending,fn($p)=>$p['kind']!=='seguridad'));
?>
<div class="app">
<aside class="side">
  <?php $em=empresa(); $multi=count(userEmpresas($user))>1; ?>
  <a class="co-brand" href="index.php" title="Inicio de <?=e($em['nombre'])?>"><span class="co-logo"><img src="<?=e($em['logo'])?>" alt="<?=e($em['nombre'])?>"></span><span class="co-name"><?=e($em['nombre'])?><small>GESTIÓN DOCUMENTAL</small></span></a>
  <?php if($multi):?><a class="co-switch" href="<?=e(url(['empresas'=>1]))?>"><?=ic('switch')?>Cambiar de empresa</a><?php endif?>
  <nav class="nav">
    <a href="index.php"<?=$active($mode==='home')?>><?=ic('home')?>Inicio</a>
    <a href="<?=e(url(['tab'=>'pend']))?>"<?=$active($mode==='pend')?>><?=ic('bell')?>Pendientes<?php if($pending):?><span class="count cc-count" title="Pendientes"><?=count($pending)?></span><?php endif?></a>
    <a href="<?=e(url(['tab'=>'dash']))?>"<?=$active($mode==='dash')?>><?=ic('trend')?>Dashboard</a>
    <a href="<?=e(url(['p'=>0]))?>"<?=$active($mode!=='home'&&$mode!=='search'&&!$tab&&$curP===0)?>><?=ic('inbox')?>General<span class="count"><?=$generalCount?></span></a>
  </nav>
  <div class="side-title"><span>Proyectos</span><?php if($canEdit):?><details class="pop pop-side"><summary class="mini-btn" title="Nuevo proyecto"><?=ic('plus')?><span class="sr">Nuevo proyecto</span></summary><form method="post" class="pop-body"><?=csrf()?><strong>Nuevo proyecto</strong><label>Nombre<input name="name" maxlength="80" required placeholder="<?=e(txEmp('Ej: Curso OMI 2026','Ej: Inspección ROV Terminal Mardones'))?>"></label><label>Descripción <small>(opcional)</small><input name="description" maxlength="300"></label><button class="primary" name="action" value="add_project">Crear proyecto</button></form></details><?php endif?></div>
  <nav class="nav projects-nav">
    <?php foreach($projects as $pr):?><a href="<?=e(url(['p'=>(int)$pr['id']]))?>"<?=$active(!$tab&&$mode!=='search'&&$mode!=='home'&&$curP===(int)$pr['id'])?>><span class="dot <?=tone((int)$pr['id'])?>"></span><?=e($pr['name'])?><span class="count"><?=(int)$pr['nfiles']?></span></a><?php endforeach?>
    <?php if(!$projects):?><p class="side-empty">Aún no hay proyectos.</p><?php endif?>
  </nav>
  <div class="side-title"><span>Ventas</span></div>
  <nav class="nav">
    <?php if(cuOn()):?><a href="<?=e(url(['tab'=>'cu']))?>"<?=$active(str_starts_with($mode,'cu'))?>><?=ic('list')?>Cursos<span class="count"><?=(int)query("SELECT COUNT(*) FROM cu_courses WHERE estado<>'Archivado'")->fetchColumn()?></span></a><?php endif?>
    <a href="<?=e(url(['tab'=>'cot']))?>"<?=$active(str_starts_with($mode,'cot'))?>><?=ic('quote')?>Cotizaciones<span class="count"><?=(int)query('SELECT COUNT(*) FROM cot_quotes')->fetchColumn()?></span></a>
    <?php if(pfOn()):?><a href="<?=e(url(['tab'=>'pf']))?>"<?=$active(str_starts_with($mode,'pf'))?>><?=ic('receipt')?>Proformas<span class="count"><?=(int)query('SELECT COUNT(*) FROM pf_invoices')->fetchColumn()?></span></a><?php endif?>
  </nav>
  <div class="side-title"><span>Compras</span></div>
  <nav class="nav">
    <a href="<?=e(url(['tab'=>'oc']))?>"<?=$active(str_starts_with($mode,'oc'))?>><?=ic('cart')?>Órdenes de compra<span class="count"><?=(int)query('SELECT COUNT(*) FROM oc_orders')->fetchColumn()?></span></a>
  </nav>
  <div class="side-title"><span>Contabilidad</span><?php if($isAdmin):?><a class="mini-btn" href="<?=e(url(['tab'=>'cc_cfg']))?>" title="Configuración contable"><?=ic('settings')?><span class="sr">Configuración contable</span></a><?php endif?></div>
  <nav class="nav">
    <a href="<?=e(url(['tab'=>'cc']))?>"<?=$active($mode==='cc'||$mode==='cc_cfg')?>><?=ic('chart')?>Resumen</a>
    <a href="<?=e(url(['tab'=>'cc_movs']))?>"<?=$active($mode==='cc_movs'||$mode==='cc_new'||$mode==='cc_mov_doc')?>><?=ic('list')?>Ingresos y gastos</a>
    <a href="<?=e(url(['tab'=>'cc_rends']))?>"<?=$active(str_starts_with($mode,'cc_rend'))?>><?=ic('receipt')?>Rendiciones<?php $nPend=(int)query("SELECT COUNT(*) FROM cc_rendiciones WHERE estado='Enviada'")->fetchColumn(); if($nPend):?><span class="count cc-count" title="Por aprobar"><?=$nPend?></span><?php endif?></a>
    <a href="<?=e(url(['tab'=>'ps']))?>"<?=$active(str_starts_with($mode,'ps'))?>><?=ic('target')?>Presupuestos<?php $nPs=(int)query("SELECT COUNT(*) FROM ps_budgets WHERE estado='Aprobado' AND alert_level>0")->fetchColumn(); if($nPs):?><span class="count cc-count" title="Con alerta o sobregiro"><?=$nPs?></span><?php endif?></a>
  </nav>
  <div class="side-title"><span>Cuenta</span></div>
  <nav class="nav">
    <a href="<?=e(url(['tab'=>'account']))?>"<?=$active($tab==='account')?>><?=ic('user')?>Mi cuenta</a>
    <?php if($isAdmin):?><a href="<?=e(url(['tab'=>'users']))?>"<?=$active($tab==='users')?>><?=ic('users')?>Usuarios</a><a href="<?=e(url(['tab'=>'activity']))?>"<?=$active($tab==='activity')?>><?=ic('activity')?>Actividad</a><a href="<?=e(url(['tab'=>'security']))?>"<?=$active($tab==='security')?>><?=ic('shield')?>Seguridad</a><?php endif?>
  </nav>
  <div class="storage"><span>Almacenamiento</span><progress max="<?=$quota?>" value="<?=min((int)$stats['bytes'],$quota)?>"></progress><small><?=size($stats['bytes'])?> de <?=e($config['quota_mb'])?> MB · <?=(int)$stats['total']?> archivos</small></div>
</aside>
<div class="main">
<header class="top">
  <form method="get" class="topsearch" role="search"><?=ic('search')?><input name="q" value="<?=e($search)?>" placeholder="Buscar documentos por nombre…" aria-label="Buscar documentos"><?php if($category):?><input type="hidden" name="category" value="<?=e($category)?>"><?php endif?></form>
  <div class="account"><?php if($multi):?><a class="co-pill" href="<?=e(url(['empresas'=>1]))?>" title="Estás en <?=e($em['nombre'])?>. Cambiar de empresa"><img src="<?=e($em['logo'])?>" alt=""><span><?=e($em['corto'])?></span><?=ic('switch')?></a><?php endif?><a class="bell<?=$unread?' on':''?>" href="<?=e(url(['tab'=>'pend']))?>" title="<?=$unread?$unread.' aviso'.($unread===1?'':'s').' sin leer':'Pendientes y avisos'?>"><?=ic('bell')?><?php if($unread):?><span class="bell-n"><?=$unread>99?'99+':$unread?></span><?php endif?><span class="sr">Pendientes y avisos</span></a><span class="avatar"><?=e(strtoupper(substr($user['name'],0,1)))?></span><span class="who"><?=e($user['name'])?><small><?=e(['admin'=>'Administrador','editor'=>'Editor','viewer'=>'Lector'][$user['role']])?></small></span><form method="post"><?=csrf()?><button class="ghost small" name="action" value="logout" title="Salir"><?=ic('logout')?><span class="hide-sm">Salir</span></button></form></div>
</header>
<main class="content">
<?php if($error): ?><div class="alert error" role="alert"><?=e($error)?></div><?php endif ?><?php if($message): ?><div class="alert" role="status"><?=e($message)?></div><?php endif ?>

<?php if($mode==='home'): $recent=query($select.' ORDER BY files.id DESC LIMIT 8')->fetchAll(PDO::FETCH_ASSOC); $nfolders=(int)query('SELECT COUNT(*) FROM folders')->fetchColumn(); ?>
<div class="page-head"><div><span class="eyebrow muted">ESPACIO DE TRABAJO</span><h1>Hola, <?=e(explode(' ',$user['name'])[0])?></h1><p class="subtitle">Organiza los documentos de <?=e(empresa()['nombre'])?> por proyectos y carpetas.</p></div></div>
<?php if($pending):?><section class="pend-home"><div class="block-head"><h2><?=ic('bell')?>Tienes <?=count($pending)?> pendiente<?=count($pending)===1?'':'s'?></h2><a class="btn ghost small" href="<?=e(url(['tab'=>'pend']))?>">Ver todos</a></div>
<ul class="pend-list"><?php foreach(array_slice($pending,0,4) as $it):?><li class="pk-<?=e($it['kind'])?>"><a href="<?=e(url($it['link']))?>"><b><?=e($it['title'])?></b><small><?=e($it['detail'])?></small></a></li><?php endforeach?></ul></section><?php endif?>
<div class="stat-grid">
  <div class="stat"><?=ic('inbox')?><strong><?=(int)$stats['total']?></strong><span>Documentos</span></div>
  <div class="stat"><?=ic('project')?><strong><?=count($projects)?></strong><span>Proyectos</span></div>
  <div class="stat"><?=ic('folder')?><strong><?=$nfolders?></strong><span>Carpetas</span></div>
  <div class="stat"><?=ic('upload')?><strong><?=size($stats['bytes'])?></strong><span>de <?=e($config['quota_mb'])?> MB usados</span></div>
</div>
<section class="block"><div class="block-head"><h2>Proyectos</h2></div>
<div class="card-grid">
  <a class="tile" href="<?=e(url(['p'=>0]))?>"><span class="tile-icon tone-gray"><?=ic('inbox')?></span><b>General</b><small>Documentos sin proyecto asignado</small><span class="tile-meta"><?=$generalCount?> documentos</span></a>
  <?php foreach($projects as $pr):?><a class="tile" href="<?=e(url(['p'=>(int)$pr['id']]))?>"><span class="tile-icon <?=tone((int)$pr['id'])?>"><?=ic('project')?></span><b><?=e($pr['name'])?></b><small><?=e($pr['description']?:'Sin descripción')?></small><span class="tile-meta"><?=(int)$pr['nfiles']?> documentos · <?=(int)$pr['nfolders']?> carpetas</span></a><?php endforeach?>
  <?php if($canEdit):?><details class="tile tile-new"><summary><span class="tile-icon tone-line"><?=ic('plus')?></span><b>Nuevo proyecto</b><small>Agrupa carpetas y documentos de un cliente, <?=txEmp('curso','inspección')?> u operación.</small></summary><form method="post" class="stack"><?=csrf()?><label>Nombre<input name="name" maxlength="80" required></label><label>Descripción <small>(opcional)</small><input name="description" maxlength="300"></label><button class="primary" name="action" value="add_project">Crear proyecto</button></form></details><?php endif?>
</div></section>
<section class="block"><div class="block-head"><h2>Subidos recientemente</h2></div>
<?php if($recent): fileRows($recent,true); else:?><div class="empty-state"><?=ic('upload')?><b>Todavía no hay documentos</b><span>Entra a un proyecto o a General para subir el primero.</span></div><?php endif?>
</section>

<?php elseif($mode==='search'): $sql=$select.' WHERE files.name LIKE ?'; $params=['%'.$search.'%']; if($category){$sql.=' AND files.category=?';$params[]=$category;} $files=query($sql.' ORDER BY '.$order.' LIMIT 200',$params)->fetchAll(PDO::FETCH_ASSOC); ?>
<div class="page-head"><div><span class="eyebrow muted">BÚSQUEDA</span><h1><?=$search!==''?'Resultados para “'.e($search).'”':'Documentos por categoría'?></h1><p class="subtitle"><?=count($files)?> documento<?=count($files)===1?'':'s'?> en todos los proyectos<?=count($files)>=200?' (se muestran los primeros 200)':''?>.</p></div></div>
<form method="get" class="chips"><input type="hidden" name="q" value="<?=e($search)?>"><button name="category" value="" class="chip<?=$category===''?' on':''?>">Todas</button><?php foreach($categories as $c):?><button name="category" value="<?=e($c)?>" class="chip<?=$category===$c?' on':''?>"><?=e($c)?></button><?php endforeach?></form>
<section class="block"><?php if($files): fileRows($files,true); else:?><div class="empty-state"><?=ic('search')?><b>Sin resultados</b><span>Prueba con otra palabra o categoría.</span></div><?php endif?></section>

<?php elseif($mode==='folder'):
$pid=$curP>0?$curP:null; $fid=$curF>0?$curF:null;
$subs=query('SELECT folders.*,(SELECT COUNT(*) FROM files WHERE folder_id=folders.id) AS nfiles,(SELECT COUNT(*) FROM folders c WHERE c.parent_id=folders.id) AS nsub FROM folders WHERE project_id IS ? AND parent_id IS ? ORDER BY name COLLATE NOCASE',[$pid,$fid])->fetchAll(PDO::FETCH_ASSOC);
$files=query($select.' WHERE files.project_id IS ? AND files.folder_id IS ? ORDER BY '.$order.' LIMIT 500',[$pid,$fid])->fetchAll(PDO::FETCH_ASSOC);
$title=$curFolder?$curFolder['name']:($curProject?$curProject['name']:'General');
$here=$fid?['f'=>$fid]:['p'=>$pid??0];
?>
<nav class="crumbs" aria-label="Ruta"><a href="index.php">Inicio</a><?=ic('chevron')?><?php if($curFolder||$curProject):?><a href="<?=e(url(['p'=>$pid??0]))?>"><?=e($curProject?$curProject['name']:'General')?></a><?php else:?><span>General</span><?php endif?><?php foreach($trail as $i=>$t):?><?=ic('chevron')?><?php if($i<count($trail)-1):?><a href="<?=e(url(['f'=>(int)$t['id']]))?>"><?=e($t['name'])?></a><?php else:?><span><?=e($t['name'])?></span><?php endif?><?php endforeach?></nav>
<div class="page-head">
  <div class="title-row"><span class="tile-icon big <?=$curFolder?'tone-folder':($curProject?tone((int)$curProject['id']):'tone-gray')?>"><?=ic($curFolder?'folder':($curProject?'project':'inbox'))?></span><div><h1><?=e($title)?></h1><p class="subtitle"><?php if($curFolder):?>Carpeta<?=$curProject?' de '.e($curProject['name']):' en General'?><?php elseif($curProject):?><?=e($curProject['description']?:'Proyecto')?><?php else:?>Documentos y carpetas sin proyecto asignado.<?php endif?></p></div></div>
  <div class="head-actions">
  <?php if($canEdit):?>
    <details class="pop"><summary class="btn ghost"><?=ic('folder')?>Nueva carpeta</summary><form method="post" class="pop-body"><?=csrf()?><input type="hidden" name="p" value="<?=(int)$pid?>"><input type="hidden" name="f" value="<?=(int)$fid?>"><strong>Nueva carpeta en “<?=e($title)?>”</strong><label>Nombre<input name="name" maxlength="80" required placeholder="<?=e(txEmp('Ej: Certificados alumnos','Ej: Videos de inspección'))?>"></label><button class="primary" name="action" value="add_folder">Crear carpeta</button></form></details>
    <?php if($curFolder||$curProject):?><details class="pop"><summary class="btn ghost" title="Más opciones"><?=ic('more')?><span class="sr">Más opciones</span></summary><div class="pop-body">
      <?php if($curFolder):?><form method="post" class="stack"><?=csrf()?><input type="hidden" name="id" value="<?=(int)$curFolder['id']?>"><strong>Renombrar carpeta</strong><label>Nombre<input name="name" maxlength="80" required value="<?=e($curFolder['name'])?>"></label><button class="primary" name="action" value="rename_folder">Guardar</button></form>
      <?php else:?><form method="post" class="stack"><?=csrf()?><input type="hidden" name="id" value="<?=(int)$curProject['id']?>"><strong>Editar proyecto</strong><label>Nombre<input name="name" maxlength="80" required value="<?=e($curProject['name'])?>"></label><label>Descripción<input name="description" maxlength="300" value="<?=e($curProject['description'])?>"></label><button class="primary" name="action" value="edit_project">Guardar</button></form><?php endif?>
      <?php if($isAdmin):?><form method="post" class="stack danger-zone"><?=csrf()?><input type="hidden" name="id" value="<?=(int)($curFolder?$curFolder['id']:$curProject['id'])?>"><p>Solo se puede eliminar si está vacío.</p><button class="danger" name="action" value="<?=$curFolder?'delete_folder':'delete_project'?>"><?=ic('trash')?>Eliminar <?=$curFolder?'carpeta':'proyecto'?></button></form><?php endif?>
    </div></details><?php endif?>
  <?php endif?>
  </div>
</div>
<?php if($curProject && !$curFolder) psProjectStrip((int)$curProject['id']); ?>
<?php if($canEdit):?><form method="post" enctype="multipart/form-data" class="dropzone"><?=csrf()?><input type="hidden" name="action" value="upload"><input type="hidden" name="p" value="<?=(int)$pid?>"><input type="hidden" name="f" value="<?=(int)$fid?>">
  <span class="dz-icon"><?=ic('upload')?></span>
  <label class="dz-file">Subir documentos a “<?=e($title)?>”<input type="file" name="file[]" multiple accept=".pdf,.docx,.xlsx,.pptx,.jpg,.jpeg,.png,.txt,.csv" required></label>
  <label class="dz-cat">Categoría<select name="category"><?php foreach($categories as $c):?><option><?=e($c)?></option><?php endforeach?></select></label>
  <button class="primary"><?=ic('upload')?>Subir</button>
  <small class="hint">Puedes elegir varios archivos. PDF, Word, Excel, PowerPoint, JPG, PNG, TXT y CSV · Máx. <?=e($config['max_upload_mb'])?> MB por archivo · Office sin macros.</small>
</form><?php endif?>
<?php if($subs):?><section class="block"><div class="block-head"><h2>Carpetas</h2></div><div class="card-grid folders">
<?php foreach($subs as $s):?><a class="tile folder-tile" href="<?=e(url(['f'=>(int)$s['id']]))?>"><span class="tile-icon tone-folder"><?=ic('folder')?></span><span><b><?=e($s['name'])?></b><small><?=(int)$s['nfiles']?> documentos<?=$s['nsub']?' · '.(int)$s['nsub'].' carpetas':''?></small></span></a><?php endforeach?>
</div></section><?php endif?>
<section class="block"><div class="block-head"><h2>Documentos <small><?=count($files)?></small></h2><?php if($files):?><div class="seg" role="group" aria-label="Ordenar"><?php foreach(['recent'=>'Recientes','name'=>'Nombre','size'=>'Tamaño'] as $k=>$lbl):?><a href="<?=e(url($here+['sort'=>$k]))?>"<?=$sort===$k?' class="on"':''?>><?=$lbl?></a><?php endforeach?></div><?php endif?></div>
<?php if($files): fileRows($files); elseif(!$subs):?><div class="empty-state"><?=ic('folder')?><b>Esta ubicación está vacía</b><span><?=$canEdit?'Sube documentos o crea una carpeta para empezar.':'Aún no hay documentos aquí.'?></span></div><?php else:?><p class="hint">No hay documentos sueltos aquí; revisa las carpetas.</p><?php endif?>
</section>

<?php elseif($mode==='preview'):
if(!$preview):?><div class="empty-state"><?=ic('search')?><b>Documento no encontrado</b><span><a href="index.php">Volver al inicio</a></span></div><?php else:
$kind=previewKind($preview['name']); [$tl,$tc]=ftype($preview['name']); audit('Vista previa',$preview['name']);
$back=$curF>0?url(['f'=>$curF]):url(['p'=>max($curP,0)]);
?>
<nav class="crumbs" aria-label="Ruta"><a href="index.php">Inicio</a><?=ic('chevron')?><a href="<?=e(url(['p'=>max($curP,0)]))?>"><?=e($curProject?$curProject['name']:'General')?></a><?php foreach($trail as $t):?><?=ic('chevron')?><a href="<?=e(url(['f'=>(int)$t['id']]))?>"><?=e($t['name'])?></a><?php endforeach?><?=ic('chevron')?><span><?=e($preview['name'])?></span></nav>
<div class="page-head">
  <div class="title-row"><a class="icon-btn" href="<?=e($back)?>" title="Volver"><?=ic('back')?><span class="sr">Volver</span></a><span class="ftype big <?=$tc?>"><?=$tl?></span><div><h1 class="doc-title"><?=e($preview['name'])?></h1><p class="subtitle"><?=size($preview['size'])?> · <?=e($preview['category'])?> · Subido por <?=e($preview['author'])?> el <?=e(fdate($preview['created'],true))?></p></div></div>
  <div class="head-actions">
    <a class="btn primary" href="<?=e(url(['download'=>(int)$preview['id']]))?>"><?=ic('download')?>Descargar</a>
    <?php if($kind==='pdf'||$kind==='image'):?><a class="btn ghost" href="<?=e(url(['raw'=>(int)$preview['id']]))?>" target="_blank" rel="noopener"><?=ic('external')?>Abrir en pestaña</a><?php endif?>
    <?php if($canEdit):?><details class="pop"><summary class="btn ghost"><?=ic('edit')?>Editar</summary><div class="pop-body"><form method="post" class="stack"><?=csrf()?><input type="hidden" name="id" value="<?=(int)$preview['id']?>"><strong>Editar o mover documento</strong>
      <label>Nombre<input name="name" maxlength="180" required value="<?=e($preview['name'])?>"></label>
      <label>Categoría<select name="category"><?php foreach($categories as $c):?><option<?=$preview['category']===$c?' selected':''?>><?=e($c)?></option><?php endforeach?></select></label>
      <label>Ubicación<select name="dest"><?php $cur=$preview['folder_id']?'f:'.$preview['folder_id']:($preview['project_id']?'p:'.$preview['project_id']:'g'); foreach(locationOptions() as [$v,$l]):?><option value="<?=e($v)?>"<?=$v===$cur?' selected':''?>><?=e($l)?></option><?php endforeach?></select></label>
      <button class="primary" name="action" value="edit_file">Guardar cambios</button></form>
      <?php if($isAdmin):?><form method="post" class="stack danger-zone"><?=csrf()?><input type="hidden" name="id" value="<?=(int)$preview['id']?>"><p>La eliminación es definitiva.</p><button class="danger" name="action" value="delete"><?=ic('trash')?>Eliminar documento</button></form><?php endif?>
    </div></details><?php endif?>
  </div>
</div>
<section class="viewer viewer-<?=$kind?>">
<?php if($kind==='pdf'):?><iframe src="<?=e(url(['raw'=>(int)$preview['id']]))?>" title="Vista previa de <?=e($preview['name'])?>"></iframe>
<?php elseif($kind==='image'):?><img src="<?=e(url(['raw'=>(int)$preview['id']]))?>" alt="<?=e($preview['name'])?>">
<?php elseif($kind==='text'||$kind==='csv'):
  $fh=fopen(dataDir().'/uploads/'.$preview['stored'],'rb'); $raw=$fh?(string)fread($fh,200000):''; if($fh) fclose($fh);
  if(!preg_match('//u',$raw) && function_exists('mb_convert_encoding')) $raw=mb_convert_encoding($raw,'UTF-8','Windows-1252');
  $cut=$preview['size']>200000;
  if($kind==='csv'): $lines=preg_split('/\r\n|\n|\r/',$raw)?:[]; if($cut) array_pop($lines); $first=$lines[0]??''; $sep=substr_count($first,';')>substr_count($first,',')?';':','; ?>
  <div class="table-wrap csv"><table><tbody><?php foreach(array_slice($lines,0,300) as $i=>$ln): if($ln==='') continue; ?><tr><?php foreach(str_getcsv($ln,$sep,'"','') as $cell):?><<?=$i===0?'th':'td'?>><?=e($cell)?></<?=$i===0?'th':'td'?>><?php endforeach?></tr><?php endforeach?></tbody></table></div>
  <?php if(count($lines)>300||$cut):?><p class="hint">Vista previa parcial. Descarga el archivo para verlo completo.</p><?php endif?>
  <?php else:?><pre><?=e($raw)?></pre><?php if($cut):?><p class="hint">Vista previa parcial. Descarga el archivo para verlo completo.</p><?php endif?><?php endif?>
<?php elseif(in_array($kind,['docx','xlsx','pptx'],true)):
  $officeHtml=''; $officeErr=''; $officePath=dataDir().'/uploads/'.$preview['stored'];
  try {
      if(!officeAvailable()) throw new RuntimeException('Habilita la extensión dom de PHP para ver documentos de Office.');
      if($kind==='docx') $officeHtml='<div class="ofc-scroll">'.officeDocx($officePath,(int)$preview['id']).'</div>';
      elseif($kind==='pptx') {
          [$nSlides,$cur,$slideHtml]=officePptx($officePath,(int)$preview['id'],(int)$g('slide')-1);
          $nav='';
          if($nSlides>0){
              $link=fn(int $i,string $label,string $cls='')=>'<a class="'.$cls.'" href="'.e(url(['preview'=>(int)$preview['id'],'slide'=>$i+1])).'">'.$label.'</a>';
              $prev=$cur>0?$link($cur-1,ic('back').'<span>Anterior</span>','btn ghost small'):'<span class="btn ghost small off">'.ic('back').'<span>Anterior</span></span>';
              $next=$cur<$nSlides-1?$link($cur+1,'<span>Siguiente</span>'.ic('chevron'),'btn primary small'):'<span class="btn ghost small off"><span>Siguiente</span>'.ic('chevron').'</span>';
              $pages=''; if($nSlides>1&&$nSlides<=60) for($i=0;$i<$nSlides;$i++) $pages.=$link($i,(string)($i+1),$i===$cur?'on':'');
              $nav='<nav class="ofc-slidenav" aria-label="Diapositivas">'.$prev.'<span class="ofc-count">Diapositiva <b>'.($cur+1).'</b> de '.$nSlides.'</span>'.$next.'</nav>'.($pages?'<nav class="ofc-pages" aria-label="Ir a diapositiva">'.$pages.'</nav>':'');
          }
          $officeHtml='<div class="ofc-stage">'.$slideHtml.'</div>'.$nav;
      }
      else {
          [$sheets,$sheetNo,$sheetHtml]=officeXlsx($officePath,(int)$g('sheet'));
          $tabs=''; if(count($sheets)>1){ foreach($sheets as $i=>$sh) $tabs.='<a href="'.e(url(['preview'=>(int)$preview['id'],'sheet'=>$i])).'"'.($i===$sheetNo?' class="on" aria-current="page"':'').'>'.e($sh['name']).'</a>'; $tabs='<nav class="ofc-tabs" aria-label="Hojas">'.$tabs.'</nav>'; }
          $officeHtml=$tabs.$sheetHtml;
      }
  } catch(Throwable $ex) { error_log((string)$ex); $officeErr=$ex instanceof RuntimeException?$ex->getMessage():'No se pudo generar la vista previa.'; }
  if($officeErr):?><div class="empty-state"><span class="ftype big <?=$tc?>"><?=$tl?></span><b>Vista previa no disponible</b><span><?=e($officeErr)?></span><a class="btn primary" href="<?=e(url(['download'=>(int)$preview['id']]))?>"><?=ic('download')?>Descargar</a></div>
  <?php else:?><p class="ofc-note">Vista previa aproximada: algunos formatos, fuentes o gráficos pueden verse distintos. Para el original, descarga el archivo.</p><?=$officeHtml?><?=officeStyleTag($cspNonce)?><?php endif?>
<?php else:?><div class="empty-state"><span class="ftype big <?=$tc?>"><?=$tl?></span><b>Vista previa no disponible</b><span>Este formato se abre descargándolo.</span><a class="btn primary" href="<?=e(url(['download'=>(int)$preview['id']]))?>"><?=ic('download')?>Descargar</a></div><?php endif?>
</section>
<?php endif?>

<?php elseif($mode==='dash'): require __DIR__.'/dash_view.php';?>

<?php elseif(str_starts_with($mode,'oc')): require __DIR__.'/oc_view.php';?>

<?php elseif(str_starts_with($mode,'cot')): require __DIR__.'/cot_view.php';?>
<?php elseif(str_starts_with($mode,'pf')): require __DIR__.'/proforma_view.php';?>

<?php elseif(str_starts_with($mode,'cc')): require __DIR__.'/contable_view.php';?>

<?php elseif(str_starts_with($mode,'ps')): require __DIR__.'/presupuesto_view.php';?>

<?php elseif(str_starts_with($mode,'cu')): require __DIR__.'/cursos_view.php';?>

<?php elseif(in_array($mode,['account','pend','security'],true)): require __DIR__.'/account_view.php';?>

<?php elseif($mode==='users'):?>
<div class="page-head"><div><span class="eyebrow muted">ADMINISTRACIÓN</span><h1>Usuarios</h1><p class="subtitle">Los usuarios son los mismos en todas las empresas. Cada usuario activo ve y descarga los documentos de las empresas a las que tiene acceso. Los editores también pueden subir, crear proyectos y carpetas, y mover documentos; solo el administrador elimina.</p></div></div>
<section class="card"><h2>Nuevo usuario</h2><form method="post" class="row-form"><?=csrf()?><label>Nombre<input name="name" required maxlength="100"></label><label>Correo<input type="email" name="email" required maxlength="254"></label><label>Contraseña inicial<input type="password" name="password" required minlength="12" maxlength="72" autocomplete="new-password"></label><label>Permiso<select name="role"><option value="editor">Editor</option><option value="viewer">Lector</option></select></label><fieldset class="emp-checks"><legend>Empresas</legend><?php foreach(empresas() as $k=>$em):?><label class="check"><input type="checkbox" name="empresas[]" value="<?=e($k)?>"<?=$k===$empresaKey?' checked':''?>><?=e($em['corto'])?></label><?php endforeach?></fieldset><button name="action" value="add_user" class="primary">Crear usuario</button></form><p class="hint">Los usuarios son comunes a todas las empresas; cada uno entra solo a las empresas marcadas. El administrador entra a todas.</p></section>
<section class="card"><div class="table-wrap"><table><thead><tr><th>Usuario</th><th>Permiso</th><th>Empresas</th><th>Dos pasos</th><th>Estado</th><th class="right">Acción</th></tr></thead><tbody><?php foreach(query('SELECT id,name,email,role,active,mfa_on FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $u):?><tr><td><b><?=e($u['name'])?></b><small><?=e($u['email'])?></small></td><td><?=e(['admin'=>'Administrador','editor'=>'Editor','viewer'=>'Lector'][$u['role']]??$u['role'])?></td>
<td class="nowrap"><?php if($u['role']==='admin'):?><span class="muted">Todas</span><?php else: $ue=userEmpresas($u); foreach(empresas() as $k=>$em): $has=in_array($k,$ue,true);?><form method="post" class="inline-form"><?=csrf()?><input type="hidden" name="id" value="<?=(int)$u['id']?>"><input type="hidden" name="empresa" value="<?=e($k)?>"><button class="emp-chip<?=$has?' on':''?>" name="action" value="user_company" title="<?=$has?'Quitar acceso a ':'Dar acceso a '?><?=e($em['nombre'])?>"><?=$has?'✓ ':''?><?=e($em['corto'])?></button></form><?php endforeach; endif?></td>
<td><?php if($u['mfa_on']):?><span class="status on">Activa</span><?php if((int)$u['id']!==(int)$user['id']):?><form method="post" class="inline-form"><?=csrf()?><input type="hidden" name="id" value="<?=(int)$u['id']?>"><button class="link-btn" name="action" value="mfa_reset" title="Para un usuario que perdió su teléfono: deberá activarla otra vez">Restablecer</button></form><?php endif?><?php else:?><span class="status off"><?=mfaRequired($u)?'Pendiente (obligatoria)':'No activa'?></span><?php endif?></td><td><span class="status <?=$u['active']?'on':'off'?>"><?=$u['active']?'Activo':'Desactivado'?></span></td><td class="right"><?php if($u['role']!=='admin'):?><form method="post"><?=csrf()?><input type="hidden" name="id" value="<?=(int)$u['id']?>"><button class="ghost small" name="action" value="toggle_user"><?=$u['active']?'Desactivar':'Activar'?></button></form><?php endif?></td></tr><?php endforeach?></tbody></table></div></section>

<?php elseif($mode==='activity'):?>
<div class="page-head"><div><span class="eyebrow muted">ADMINISTRACIÓN</span><h1>Actividad reciente</h1><p class="subtitle">Últimas 50 acciones (hora de Chile). La bitácora completa, su verificación de integridad y la exportación están en <a href="<?=e(url(['tab'=>'security']))?>">Seguridad</a>.</p></div></div>
<section class="card"><div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Detalle</th><th class="hide-sm">IP</th></tr></thead><tbody><?php foreach(query('SELECT audit.*, users.email FROM audit LEFT JOIN users ON users.id=audit.actor ORDER BY audit.id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC) as $a):?><tr><td class="nowrap"><?=e(fdate($a['created'],true))?></td><td><?=e($a['email']??'Sistema')?></td><td><?=e($a['action'])?></td><td><?=e($a['detail'])?></td><td class="hide-sm muted"><?=e($a['ip'])?></td></tr><?php endforeach?></tbody></table></div></section>
<?php endif?>
<footer class="page-footer"><?=e(mb_strtoupper(ocCfg()['emisor']['razon']??empresa()['nombre']))?> · Plataforma interna</footer>
</main></div></div>
<?php endif?></body></html>
