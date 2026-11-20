# Política de seguridad

OX3 Gestión maneja información documental, comercial y financiera de empresas reales. Agradecemos cualquier reporte responsable de vulnerabilidades.

## Cómo reportar

- **No abras un issue público** ni publiques detalles de la vulnerabilidad.
- Usa el reporte privado de GitHub: pestaña **Security** → **Report a vulnerability**.
- Incluye la versión o commit afectado, los pasos para reproducir el problema y su impacto esperado.

Se acusará recibo del reporte y se informará el avance hasta su corrección.

## Alcance

| Dentro del alcance | Fuera del alcance |
| --- | --- |
| Código de este repositorio (`public_html/`, `private/`) | Pruebas de denegación de servicio o carga |
| Autenticación, segundo factor, sesiones, CSRF y CSP | Ingeniería social o phishing al personal |
| Separación de datos entre empresas | Acceso a datos reales de producción |
| Respaldos cifrados y bitácora encadenada | Servicios de terceros (hosting, correo, Cloudflare) |

No realices pruebas sobre la instancia en producción sin autorización previa por escrito.

## Buenas prácticas del repositorio

Credenciales, claves, bases `*.sqlite`, documentos y respaldos están excluidos en `.gitignore`. Si detectas que se publicó por error algún secreto o dato real, repórtalo por la misma vía privada.
