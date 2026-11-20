<?php
return [
    'setup_key' => '', // Define una clave aleatoria de al menos 32 caracteres antes de instalar.
    'https_only' => true, // Mantener true en Hostinger. false solo en localhost.
    'max_upload_mb' => 20,
    'quota_mb' => 1024,
    // Cloudflare Turnstile (verificación "no soy un robot" en el ingreso).
    // Deja ambas vacías para ingresar sin verificación.
    'turnstile_site_key' => '',   // Clave de sitio (pública)
    'turnstile_secret_key' => '', // Clave secreta: no la compartas

    // Dirección de la plataforma, para los enlaces de los correos.
    'app_url' => 'https://gestion.ox3.cl/',
    // Correo de avisos (SMTP de Hostinger). Con smtp_host vacío no se envían correos:
    // los avisos quedan solo dentro de la plataforma (Pendientes y campana).
    'smtp_host' => '',          // smtp.hostinger.com
    'smtp_port' => 465,         // 465 con 'ssl' o 587 con 'tls'
    'smtp_secure' => 'ssl',
    'smtp_user' => '',          // casilla completa, ej. notificaciones@ox3.cl
    'smtp_pass' => '',          // contraseña de esa casilla: no la compartas
    'mail_from' => '',          // remitente; vacío = smtp_user
    'mail_from_name' => 'OX3 Gestión',

    // Verificación en dos pasos (TOTP): roles obligados a activarla. [] = opcional para todos.
    'mfa_roles' => ['admin', 'editor'],

    // Respaldos cifrados. backup_key: frase de al menos 20 caracteres, guárdala también FUERA del hosting
    // (sin ella no se puede abrir ningún respaldo). Vacía = respaldos desactivados.
    'backup_key' => '',
    'backup_keep' => 7,          // cuántos respaldos conservar en el servidor
    'backup_auto_hours' => 24,   // cada cuántas horas crear uno automático (0 = solo manual)
];
