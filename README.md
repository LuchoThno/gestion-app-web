# OX3 Gestión

Plataforma web de gestión documental, comercial y contable para **OX3** y **Magallanes ROV Marine (MRM)**. Reúne en un solo lugar los documentos de cada proyecto, las órdenes de compra, las cotizaciones, los gastos y rendiciones, los presupuestos y los indicadores de cada empresa.

Es una aplicación PHP con base de datos SQLite, sin dependencias externas ni compilación. Funciona en un hosting compartido (por ejemplo, Hostinger) y se usa desde el navegador, en computador o celular.

> La documentación técnica detallada (arquitectura, tablas, seguridad, requisitos y casos de uso) está en [TECNICO.md](TECNICO.md).

## Funcionalidades

### Documentos
- Proyectos con carpetas y subcarpetas.
- Carga y descarga de archivos con límites de tamaño y cuota de almacenamiento.
- Vista previa en el navegador de PDF, imágenes y documentos Office (Word, Excel y PowerPoint), convertidos en el propio servidor sin servicios externos.

### Órdenes de compra
- Registro de proveedores y numeración correlativa.
- Estados: Borrador, Emitida, Aceptada, Recibida, Pagada y Anulada.
- Hoja imprimible con el logo y los datos de la empresa emisora.

### Cotizaciones
- Registro de clientes, vigencia y cálculo de totales.
- Estados: Borrador, Enviada, Aceptada, Rechazada y Anulada, con aviso de vencimiento.
- Hoja imprimible lista para enviar al cliente.

### Contabilidad y rendiciones
- Ingresos y gastos con distribución entre centros de costo.
- Comprobantes adjuntos a cada movimiento.
- Rendiciones de gastos con flujo de revisión: Borrador, Enviada, Aprobada o Rechazada con motivo.
- Exportación a CSV.

### Presupuestos
- Planificación por proyecto y centro de costo, con líneas de detalle y retención de honorarios.
- Flujo de aprobación: Borrador, Por aprobar, Aprobado y Cerrado.
- Comparación entre lo presupuestado y lo realmente gastado, con alertas al acercarse o superar el límite.

### Indicadores
- Panel con filtros por período y categoría.
- Comparación entre períodos y gráficos generados en el servidor.

### Varias empresas
- OX3 y MRM comparten usuarios, pero cada empresa tiene su propia base de datos, logo y datos de emisor.
- Cada usuario ve solo las empresas que tiene asignadas; los administradores ven todas.

### Usuarios y seguridad
- Roles de administrador, editor y lector.
- Contraseñas robustas, bloqueo por intentos fallidos y verificación Cloudflare Turnstile opcional en el ingreso.
- Segundo factor de autenticación (TOTP, compatible con Google Authenticator y similares) con códigos de recuperación.
- Sesiones que expiran por inactividad, protección CSRF y cabeceras de seguridad estrictas (CSP).
- Bitácora de auditoría encadenada con hashes para detectar alteraciones.

### Avisos y respaldos
- Notificaciones dentro de la aplicación y por correo (SMTP).
- Respaldos cifrados automáticos con retención configurable y herramienta de restauración por consola.
- Tareas programadas mediante `cron.php`.

## Estructura

```
public_html/   Raíz web: punto de entrada (index.php), estilos y logos
private/       Código de la aplicación, configuración y datos (fuera de la raíz web)
TECNICO.md     Documentación técnica completa
```

## Instalación rápida

1. Subir `public_html/` y `private/` como carpetas hermanas; la raíz web debe apuntar **solo** a `public_html/`.
2. Copiar `private/config.example.php` como `private/config.php` y completar la clave de instalación, la URL, el correo y la frase de respaldo.
3. Verificar PHP 8.1 o superior con las extensiones `pdo_sqlite`, `sqlite3`, `fileinfo`, `zip`, `sodium` y `dom`.
4. Abrir la aplicación en el navegador y crear el administrador inicial.
5. Configurar el cron, probar el correo y hacer una prueba de respaldo y restauración.

Para desarrollo local:

```sh
php -S 127.0.0.1:8080 -t public_html
```

Los pasos completos están en la sección 9 de [TECNICO.md](TECNICO.md).

## Lo que no se publica

`config.php`, `app.key`, las bases `*.sqlite`, `uploads/`, `backups/` y `audit.anchor` están excluidos en `.gitignore`. Este repositorio es público: nunca subir credenciales, claves ni datos reales de clientes.

## Instalar como app

La aplicación puede instalarse como **app web progresiva (PWA)**: con un archivo de manifiesto, íconos y una línea en la cabecera de la página, el navegador ofrece "Instalar" en computador y Android, y "Agregar a inicio" en iPhone. Se abre en su propia ventana, con el ícono de OX3, sin barra del navegador.

- No requiere tiendas de aplicaciones ni instaladores.
- Funciona con la política de seguridad actual, que no permite JavaScript.
- Necesita conexión: los datos viven en el servidor, así que no habrá modo sin conexión.

Un instalador nativo (por ejemplo, con Electron) no es recomendable: tendría que incluir su propio PHP y separaría los datos de cada equipo, o bien ser solo una ventana hacia el mismo sitio, que es lo que la PWA ya resuelve.
