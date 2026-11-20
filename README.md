# Plataforma web multiempresa para la gestión documental, comercial y financiera de pequeñas empresas de servicios

**Caso: OX3 Capacitaciones y Magallanes ROV Marine** · Proyecto de título, Ingeniería en Computación e Informática, Universidad Gabriela Mistral · Luis Elías Vargas Vergara

Plataforma web con la que dos pequeñas empresas de servicios que comparten gerencia y personal administrativo, **OX3 Capacitaciones** (organismo técnico de capacitación) y **Magallanes ROV Marine** (inspección e ingeniería submarina con ROV), gestionan cada curso, proyecto u operación desde un solo lugar: documentos, cotizaciones, órdenes de compra, ingresos, gastos, rendiciones y presupuestos, con los datos de cada empresa separados y trazabilidad segura.

Este repositorio contiene el código de la plataforma (en producción como **OX3 Gestión**, `gestion.ox3.cl`), organizado según el producto mínimo viable (MVP) y los incrementos definidos en la Solemne I.

- **Tecnología:** PHP 8.3 y SQLite, sin dependencias externas, sin compilación y sin JavaScript en las páginas internas.
- **Despliegue:** hosting compartido (Hostinger).
- **Uso:** desde el navegador, en computador o celular, o instalada como app (ver [Instalar como app](#instalar-como-app)).

> La documentación técnica (arquitectura, tablas, seguridad y casos de uso) está en [TECNICO.md](TECNICO.md).

## Problema

> OX3 y MRM no cuentan con un mecanismo común y seguro para registrar, vincular y consultar la información documental, comercial y financiera de cada curso, proyecto u operación, con los datos de cada empresa separados. Como consecuencia, la información queda dispersa e incompleta, las aprobaciones no dejan trazabilidad y el resultado de cada servicio solo se conoce después de su cierre, cuando ya no es posible corregir desvíos.

| Dimensión | Descripción | Respuesta de la plataforma |
| --- | --- | --- |
| D1. Dispersión | La información de un mismo servicio está repartida en soportes que no se comunican. | Registro único por servicio: documentos, cotizaciones, compras y gastos ligados al proyecto o centro de costo. |
| D2. Trazabilidad y seguridad | No queda constancia de quién registró, aprobó o consultó cada dato. | Roles, segundo factor, bitácora encadenada y respaldos cifrados. |
| D3. Separación entre empresas | Al compartir personas y equipos, la información de OX3 y MRM puede mezclarse. | Una base de datos por empresa y acceso solo a las empresas autorizadas. |
| D4. Oportunidad | El control económico del servicio ocurre al cierre y no durante la ejecución. | Presupuestos con control de ejecución, semáforo y alertas. |

## Arquitectura multiempresa

Monolito modular en PHP con un punto de entrada único (`public_html/index.php`) y el código y los datos fuera de la raíz web (`private/`).

- **Usuarios comunes, datos separados:** la base principal guarda los usuarios, los intentos de ingreso y las asignaciones a empresas. Cada empresa tiene su propia base SQLite, carpeta de documentos, correlativos, configuración y bitácora.
- **Selección de empresa:** al ingresar, cada usuario ve solo las empresas que tiene autorizadas; los administradores ven todas. El sistema abre únicamente la base de la empresa elegida y adjunta la principal para consultar los usuarios.
- **Contexto protegido:** cada formulario lleva la empresa en que se abrió; si el usuario cambió de empresa en otra pestaña, la operación se rechaza en lugar de guardarse en la empresa equivocada.
- **Identidad por empresa:** logos, datos de emisor y numeración de cotizaciones, órdenes de compra y proformas cambian según la empresa activa.
- **Extensible:** agregar una empresa consiste en declararla en `empresas()` (`private/app.php`); su base se crea con el mismo esquema al primer uso.

## Hipótesis de valor

> Si cada gasto se registra una sola vez, con su comprobante, ligado al centro de costo del servicio y aprobado formalmente, y si el presupuesto aprobado se compara automáticamente con esos gastos y con las compras comprometidas, la gerencia podrá detectar los desvíos de un curso u operación mientras está en ejecución, y no al cierre.

La hipótesis se valida si los presupuestos aprobados muestran su ejecución durante el servicio, los indicadores de calidad de datos alcanzan sus metas y los usuarios califican la plataforma con un SUS de 68 o más.

## MVP e incrementos

El MVP reúne los requerimientos de prioridad **Debe**: lo mínimo para cerrar el ciclo presupuesto → compra → gasto → rendición → control, con seguridad y separación entre empresas desde el primer día.

| Incremento | Contenido | Requerimientos |
| --- | --- | --- |
| **MVP** (sprints 1 y 2) | Ingreso con segundo factor, selección de empresa y roles; centros de costo; ingresos y gastos con comprobante y reparto; rendiciones con aprobación; órdenes de compra; presupuesto con control de ejecución y alertas; respaldos cifrados. | RF-03 a RF-06, RF-08 a RF-10, RF-12, RF-13 |
| **Incremento 2** (sprint 3) | Gestión documental por proyecto, cotizaciones, dashboard, pendientes y avisos por correo, bitácora verificada. | RF-01, RF-02, RF-07, RF-11 |
| **Incremento 3** (sprint 3) | Diseño y evaluación de cursos con cotización desde el curso; importación desde Excel; exportación CSV. | RF-14 a RF-16 |
| Fuera del alcance | Permisos por carpeta, versionado de documentos, integración bancaria y con el SII. | — |

## Requerimientos y código

| ID | Requerimiento | Prioridad | Incremento | Archivos |
| --- | --- | --- | --- | --- |
| RF-01 | Gestionar proyectos, carpetas y documentos con categorías, búsqueda y vista previa | Debería | 2 | `app.php`, `view.php`, `office.php` |
| RF-02 | Emitir cotizaciones con correlativo por empresa, estado, vencimiento e impresión | Debería | 2 | `cot.php`, `cot_view.php`, `intl.php` |
| RF-03 | Emitir órdenes de compra con correlativo por empresa, estados e impresión | Debe | MVP | `oc.php`, `oc_view.php` |
| RF-04 | Registrar ingresos y gastos con comprobante y reparto entre centros de costo | Debe | MVP | `contable.php`, `contable_view.php` |
| RF-05 | Rendir fondos con flujo de envío, aprobación o rechazo con motivo | Debe | MVP | `contable.php`, `contable_view.php` |
| RF-06 | Elaborar presupuestos por curso u operación, aprobarlos y controlar su ejecución con alertas | Debe | MVP | `presupuesto.php`, `presupuesto_view.php` |
| RF-07 | Consultar indicadores, pendientes por rol y recibir avisos por correo | Debería | 2 | `dash.php`, `dash_view.php`, `notify.php`, `cron.php` |
| RF-08 | Elegir empresa al ingresar y operar solo en las autorizadas | Debe | MVP | `app.php` (`empresas()`, `userEmpresas()`) |
| RF-09 | Administrar usuarios, roles y acceso a cada empresa | Debe | MVP | `app.php`, `view.php` |
| RF-10 | Ingresar con contraseña y verificación en dos pasos | Debe | MVP | `app.php`, `security.php`, `account.php`, `qr.php` |
| RF-11 | Registrar las acciones en una bitácora y verificar su integridad | Debería | 2 | `security.php`, `cron.php` |
| RF-12 | Crear, verificar y restaurar respaldos cifrados | Debe | MVP | `backup.php`, `respaldo.php` |
| RF-13 | Administrar centros de costo sin perder su historial | Debe | MVP | `contable.php` |
| RF-14 | Exportar listados a CSV | Podría | 3 | `oc.php`, `cot.php`, `contable.php` |
| RF-15 | Diseñar y evaluar económicamente cursos y cotizarlos desde la evaluación | Debería | 3 | `cursos.php`, `cursos_view.php` |
| RF-16 | Cargar cursos desde una planilla Excel con revisión de errores | Podría | 3 | `cursos_xlsx.php` |

Todos los archivos están en `private/`. El código incluye además **Proformas** (`proforma.php`), un módulo de MRM para servicios ROV en español o inglés y en CLP, USD o EUR, agregado después de la Solemne I.

## Funcionalidades

### Multiempresa
- OX3 y MRM comparten usuarios, pero cada empresa tiene su propia base de datos, documentos, correlativos, configuración y bitácora.
- Cada usuario elige empresa al ingresar y solo ve las que tiene autorizadas; los documentos impresos usan el logo y los datos de emisor de la empresa activa.

### Seguridad e identidad
- Roles de administrador, editor y lector.
- Verificación en dos pasos (TOTP) con códigos de recuperación, obligatoria para los roles que se configuren.
- Bloqueo tras cinco intentos fallidos por correo e IP, con aviso al titular, y verificación anti-bots Cloudflare Turnstile opcional.
- Sesiones `Secure`, `HttpOnly` y `SameSite=Strict`, expiración por inactividad, tokens CSRF, consultas preparadas y CSP estricta sin JavaScript.
- Bitácora de auditoría encadenada con hashes y respaldos cifrados con libsodium, con herramienta de restauración.

### Contabilidad de gestión
- Ingresos y gastos con comprobantes y reparto entre centros de costo.
- Rendiciones con flujo Borrador → Enviada → Aprobada o Rechazada con motivo.
- Resumen de resultados por período.

### Presupuestos
- Presupuesto por curso, proyecto u operación, con partidas, aprobación y versiones.
- Control de ejecución con semáforo, compromisos de órdenes de compra, retención de honorarios y punto de equilibrio.

### Comercial y compras
- Cotizaciones y órdenes de compra con correlativo por empresa, estados, hoja imprimible y exportación CSV.

### Gestión documental
- Proyectos con carpetas, categorías y búsqueda.
- Vista previa en el servidor de PDF, imágenes y documentos Word, Excel y PowerPoint, sin enviar archivos a terceros.

### Dashboard y avisos
- Ingresos frente a costos, distribución del gasto y comparación entre períodos.
- Pendientes por rol y avisos por correo electrónico.

### Cursos (solo OX3)
- Diseño y evaluación económica de cursos con escenarios y prorrateo de costos fijos.
- Cotización generada desde el curso e importación masiva desde Excel.

## Instalar como app

La plataforma es una **app web progresiva (PWA)**: incluye un manifiesto (`public_html/manifest.webmanifest`) e íconos, así que el navegador permite instalarla.

- **Computador (Chrome o Edge):** botón "Instalar" en la barra de direcciones.
- **Android (Chrome):** menú ⋮ → "Instalar aplicación".
- **iPhone (Safari):** Compartir → "Agregar a inicio".

Se abre en su propia ventana, con el ícono de OX3 y sin la barra del navegador. No usa JavaScript ni service worker, por lo que mantiene intacta la CSP estricta. Requiere conexión, porque los datos viven en el servidor. Responde a la exclusión de una aplicación móvil nativa (Tabla 2.3) sin desarrollar una aparte.

## Estructura

```
public_html/   Raíz web: index.php, manifiesto PWA, estilos, logos e íconos
private/       Código de la aplicación, configuración y datos (fuera de la raíz web)
TECNICO.md     Documentación técnica
```

## Instalación

1. Subir `public_html/` y `private/` como carpetas hermanas; la raíz web debe apuntar **solo** a `public_html/`.
2. Copiar `private/config.example.php` como `private/config.php` y definir `setup_key` (32 caracteres o más), `app_url`, el correo SMTP y `backup_key`.
3. Verificar PHP 8.1 o superior (recomendado 8.3) con `pdo_sqlite`, `sqlite3`, `fileinfo`, `zip`, `sodium` y `dom`.
4. Abrir la plataforma, crear el administrador inicial con la clave de instalación y activar el segundo factor.
5. Programar `php private/cron.php` y hacer una prueba de respaldo y restauración.

### Desarrollo local

```sh
cp private/config.example.php private/config.php   # https_only => false y una setup_key
php -S 127.0.0.1:8080 -t public_html
```

El servidor integrado de PHP es solo para desarrollo.

## Lo que no se publica

`config.php`, `app.key`, las bases `*.sqlite`, `private/empresas/`, `uploads/`, `backups/` y `audit.anchor` están excluidos en `.gitignore`. Este repositorio es público: nunca subir credenciales, claves ni datos reales.

## Contexto académico

- **Metodología:** Design Science Research (Peffers et al., 2007) con sprints de Scrum.
- **Evaluación:** pruebas funcionales y de migración, revisión OWASP Top 10, indicadores de calidad de datos y cuestionario SUS.
- **Hitos:** Solemne I (20-11-2026), Entrega II con la plataforma en producción y este repositorio (23-12-2026), entrega final (13-01-2027).
