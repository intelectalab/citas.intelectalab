# 2026-10-20 — Manual de uso por roles

## Contexto

Se pidió un manual de uso por roles, con capturas de pantalla, que se actualice con cada cambio del sistema. Se acordó el formato (archivos en `Documentación/Manual de uso`, uno por rol) y el alcance (las tareas del día a día, no cada botón).

## Qué se hizo

| Archivo | Contenido |
|---|---|
| `Manual de uso/README.md` | Índice, roles, iniciar sesión, nombres según el rubro, cómo se trabaja en las pantallas y qué hacer ante cada mensaje de error |
| `01-administrador.md` | Revisar el día, dar acceso al equipo, servicios y configuración |
| `02-gerente-de-sede.md` | Horarios del equipo, ausencias y cierres, tiempos de atención y personal de la sede |
| `03-recepcion.md` | Agenda, agendar, recibir, cobrar una cita, pacientes y lista de espera |
| `04-caja.md` | Abrir caja, punto de venta, comprobante, ventas y cierre de caja |
| `05-profesional.md` | Mis citas, atender, cobrar antes de atender, ficha de atención y ausencias |
| `06-cliente.md` | Portal: próxima cita, reservar, confirmar y paquetes |
| `07-plataforma.md` | Empresas, entrar como soporte, planes y plantillas de rubros |
| `Manual de uso/capturas/` | 38 capturas tomadas con los datos de ejemplo |

Las capturas se tomaron en una base temporal con las cuentas de ejemplo de cada rol, sin tocar tu base `erp_saas`. Lo que el manual dice y no se ve en las capturas se comprobó en el código.

## El manual en un solo Word

Después se aclaró que el manual debía entregarse en **un solo Word**: `Documentación/Manual de uso/Manual de uso.docx` (41 páginas, tamaño A4). Tiene portada con la fecha de actualización, índice con números de página, un capítulo por rol que empieza en página nueva, y las capturas con su título.

Los capítulos por rol y las capturas quedan en tu equipo como base para volver a generar el Word; a GitHub sube solo el Word.

## Qué no se sube a GitHub

Se agregó un `.gitignore` en la raíz del proyecto. A GitHub ya no se suben:

| Qué | Por qué |
|---|---|
| `PROTOTIPO/AGENDA360.html` | Es material de referencia; el sistema no lo usa. Sigue en tu equipo |
| `apps/web/AGENTS.md` y `apps/web/CLAUDE.md` | Los genera Next.js solo al trabajar en local |
| Las fuentes del manual (capítulos, capturas y generador) | En GitHub va solo el Word |
| Archivos de configuración con contraseñas (`.env`), registros, copias de la base (`*.dump`), temporales de Word (`~$…`) y carpetas de editores | No deben publicarse o no sirven fuera de tu equipo |

También se borró `apps/api/database/infraestructure/docker/postgre`, un archivo vacío que no se usaba.

## Cómo se mantiene

Con cada cambio que modifique lo que ve o hace un rol, se corrige su capítulo, se vuelven a tomar las capturas afectadas, se actualiza la fecha y se vuelve a generar el Word.
