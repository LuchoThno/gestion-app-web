<?php
// Herramienta de línea de comandos para respaldos cifrados de OX3 Gestión.
// Funciona en cualquier computador con PHP 8.1+ (extensiones sodium y zip); no necesita la aplicación instalada.
//
//   php respaldo.php descifrar ox3-respaldo-AAAAMMDD-HHMMSS.ox3bak salida.zip
//   php respaldo.php verificar ox3-respaldo-AAAAMMDD-HHMMSS.ox3bak
//   php respaldo.php restaurar ox3-respaldo-AAAAMMDD-HHMMSS.ox3bak carpeta-destino
//
// Pide la clave de respaldo (backup_key de config.php) por teclado, o la toma de la variable OX3_BACKUP_KEY.
declare(strict_types=1);
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
umask(0077);
require __DIR__.'/backup.php';
$fail=function(string $m): never { fwrite(STDERR,"Error: $m\n"); exit(1); };
if(!function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_pull')) $fail('esta instalación de PHP no tiene la extensión sodium.');
if(!class_exists('ZipArchive')) $fail('esta instalación de PHP no tiene la extensión zip.');
[$cmd,$src,$dst]=[$argv[1]??'',$argv[2]??'',$argv[3]??''];
if(!in_array($cmd,['descifrar','verificar','restaurar'],true) || $src==='' || ($cmd!=='verificar' && $dst==='')) {
    fwrite(STDERR,"Uso:\n  php respaldo.php descifrar <respaldo.ox3bak> <salida.zip>\n  php respaldo.php verificar <respaldo.ox3bak>\n  php respaldo.php restaurar <respaldo.ox3bak> <carpeta-destino>\n"); exit(2);
}
if(!is_file($src)) $fail("no existe $src");
$pass=getenv('OX3_BACKUP_KEY');
if($pass===false || $pass==='') {
    fwrite(STDOUT,'Clave de respaldo (backup_key): ');
    if(DIRECTORY_SEPARATOR==='/' && function_exists('shell_exec')) { @shell_exec('stty -echo'); $pass=trim((string)fgets(STDIN)); @shell_exec('stty echo'); fwrite(STDOUT,"\n"); }
    else $pass=trim((string)fgets(STDIN));
}
try {
    if($cmd==='descifrar') { if(file_exists($dst)) $fail("$dst ya existe; elige otro nombre."); bakDecryptFile($src,$dst,$pass); echo "Listo: $dst (ZIP con la base, documentos y claves).\n"; exit(0); }
    $tmp=sys_get_temp_dir().'/ox3-'.bin2hex(random_bytes(6)).'.zip';
    try {
        bakDecryptFile($src,$tmp,$pass);
        $info=bakCheckZip($tmp,sys_get_temp_dir());
        echo "Respaldo íntegro: creado {$info['created']} · {$info['empresas']} empresa(s) · {$info['documentos']} documentos y comprobantes · {$info['usuarios']} usuarios.\n";
        if($cmd==='restaurar') {
            if(is_dir($dst) && count(scandir($dst))>2) $fail("la carpeta $dst no está vacía. Usa una carpeta nueva.");
            if(!is_dir($dst) && !mkdir($dst,0700,true)) $fail("no se pudo crear $dst");
            $z=new ZipArchive(); $z->open($tmp);
            for($i=0;$i<$z->numFiles;$i++){ $n=(string)$z->getNameIndex($i); if(str_contains($n,'..') || str_starts_with($n,'/')) $fail("nombre no permitido en el respaldo: $n"); }
            if(!$z->extractTo($dst)) $fail('no se pudo extraer el respaldo.');
            $z->close(); @unlink($dst.'/manifest.json');
            echo "Restaurado en $dst. Copia su contenido dentro de la carpeta private del hosting (ver INSTALACION.md).\n";
        }
    } finally { @unlink($tmp); }
} catch(Throwable $ex) { $fail($ex->getMessage()); }
