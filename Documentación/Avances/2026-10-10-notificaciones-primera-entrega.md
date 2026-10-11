# 2026-10-10 — Notificaciones (primera entrega): la campana, sus avisos y su página

## Contexto

La campana de la barra superior era solo un dibujo. Pedido: que el profesional se entere de las citas que le agendan, que el dueño se entere de lo importante y que haya un aviso cuando un profesional tiene una ausencia cerca.

Alcance acordado para esta entrega:

- Al profesional, lo que pasa con **sus** citas.
- Al dueño y a los administradores, **no** cada cita que agenda la recepción: solo lo que hacen los clientes desde el portal y las cancelaciones.
- Ausencias de profesionales, **3 días antes**.
- Todo dentro del sistema: no se envían correos ni WhatsApp.

## 1. Quién recibe qué

| Qué pasó | Profesional de la cita | Dueño y administradores |
|---|---|---|
| Alguien del equipo le agenda una cita | "Te agendaron una cita" | — |
| Alguien del equipo reprograma una cita | "Reprogramaron una cita" | — |
| Alguien del equipo cancela una cita | "Cancelaron una cita" | "Cancelaron una cita" |
| Un cliente reserva desde el portal | "Nueva reserva desde el portal" | Igual |
| Un cliente cambia la fecha desde el portal | "Un cliente cambió su cita" | Igual |
| Un cliente cancela desde el portal | "Un cliente canceló su cita" | Igual |
| Un cliente confirma su cita | "Cita confirmada por el cliente" | — |
| Una ausencia suya empieza en 3 días o menos | "Tienes una ausencia próxima" | "Ausencia próxima en el equipo" |

Reglas:

- **Nadie recibe aviso de lo que hizo él mismo.** Si la dueña cancela una cita, se entera el profesional, no ella. Si un profesional se agenda a sí mismo, nadie recibe nada.
- Confirmar, registrar la llegada, iniciar y dar por atendida una cita no generan avisos.
- Los cierres de sede y las ausencias a más de 3 días no avisan.
- Cada persona recibe el aviso de una misma ausencia **una sola vez**.
- El detalle dice quién, qué servicio y cuándo; al dueño le dice además con qué profesional.
- Un fallo al generar un aviso nunca impide agendar, reprogramar ni cancelar.

## 2. La campana

- Muestra el número de avisos sin leer.
- Al abrirla: los 8 más recientes, con icono según el tipo, título, detalle y hace cuánto. Los no leídos van resaltados y con un punto.
- **Un clic en un aviso de una cita abre la cita ahí mismo**, sin salir de la pantalla en la que estás, y lo marca como leído. Un aviso de ausencia lleva a Bloqueos y ausencias.
- "Marcar todo como leído" y "Ver todas".
- Se actualiza sola cada 30 segundos y al volver a la pestaña.
- La ven el dueño, los administradores y los profesionales. La plataforma y el portal del cliente no tienen campana.

## 3. Página de notificaciones (`/notifications`)

Todas las notificaciones de la persona, de a 6, con dos vistas ("Todas" y "Sin leer") y "Marcar todo como leído". La campana y la página se mantienen iguales entre sí: marcar algo en una se refleja al instante en la otra.

## 4. Cómo se generan las ausencias próximas

Ninguna acción dispara ese aviso (depende de que pase el tiempo), así que se genera cuando alguien de la empresa consulta la campana, que es cada 30 segundos mientras hay alguien con el panel abierto. No hace falta configurar tareas programadas.

## 5. Base de datos

Tabla nueva `user_notifications` en `init.sql`: un aviso por persona, con su tipo, título, detalle, a qué se refiere y cuándo se leyó.

En `seed_demo.sql`, avisos de ejemplo para que la campana no nazca vacía: a cada profesional, sus tres próximas citas ("Te agendaron una cita"); a cada dueño, dos reservas desde el portal y dos cancelaciones. Una de cada grupo ya figura como leída.

## 6. Cambios realizados

| Archivo | Contenido |
|---|---|
| `apps/api/infrastructure/docker/postgres/init.sql` | Tabla `user_notifications`. |
| `apps/api/infrastructure/docker/postgres/seed_demo.sql` | Avisos de ejemplo. |
| `apps/api/app/Models/UserNotification.php` | Nuevo. |
| `apps/api/app/Support/Notifier.php` | Nuevo. Decide quién se entera de qué. |
| `apps/api/app/Http/Controllers/Api/NotificationController.php`, `routes/api.php` | Nuevo. Listar, marcar una y marcar todas. |
| `apps/api/app/Http/Controllers/Api/AppointmentController.php` | Cada paso de una cita (también los del portal) pasa por el aviso. |
| `apps/web/src/features/notifications/` | Nuevo. La campana, el aviso, la página y la carga de datos. |
| `apps/web/src/app/(dashboard)/notifications/page.tsx` | Nuevo. |
| `apps/web/src/app/components/layout/Navbar.tsx` | La campana real en lugar del dibujo. |

Si la tabla no existe (base sin resetear), el sistema sigue funcionando: la campana sale vacía y no se generan avisos.

## 7. Verificación

`init.sql`, `seed.sql` y `seed_demo.sql` cargan sin errores en una base temporal (ya eliminada): 239 avisos de ejemplo.

**Por la API, en la base temporal:**

| Caso | Resultado |
|---|---|
| La dueña agenda una cita para un médico | Él recibe "Te agendaron una cita"; ella, nada |
| El médico se agenda a sí mismo | Nadie recibe |
| La dueña reprograma | Él recibe; ella no |
| La dueña cancela | Él recibe; ella no |
| El médico cancela una suya | La dueña recibe; él no |
| Confirmar, llegada y atender | Sin avisos |
| El cliente reserva, cambia la fecha o cancela desde el portal | Reciben el médico y la dueña |
| El cliente confirma | Solo el médico |
| Ausencia que empieza en 2 días | La dueña recibe "Ausencia próxima en el equipo"; el médico, "Tienes una ausencia próxima" |
| Consultar otra vez | No se repite |
| Ausencia a 6 días y cierre de sede | No avisan |
| Marcar una, filtrar las no leídas, marcar todas | Correcto |
| Marcar un aviso de otra persona | 404 |
| Otra empresa | No ve nada de esta |
| Cancelar una cita por la ruta real de la API | El médico pasa de 3 a 4 avisos; la dueña sigue con los mismos |

Los avisos del portal se probaron llamando a la regla con un cliente como autor, no reservando desde la pantalla del portal.

**En el navegador, con clics reales:**

| Prueba | Resultado |
|---|---|
| Entrar como el médico: la campana marca 3 | Correcto |
| Abrir la campana | Lista con la cancelación recién hecha arriba ("Ahora mismo") y sus citas agendadas |
| Clic en la cancelación | La campana se cierra, se abre la cita cancelada y el número baja a 2 |
| "Ver todas" | Abre la página con 4; "Sin leer" deja 2 |
| "Marcar todo como leído" en la página | Lista de no leídas vacía y la campana queda sin número |
| Entrar como la dueña | La campana trae la ausencia próxima, generada sola, además de sus avisos |
| Clic en la ausencia | Lleva a Bloqueos y ausencias y el número baja |
| "Marcar todo como leído" en la campana | "Estás al día" y sin número |

- Se revisó en capturas la campana abierta y la página.
- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- **No se probó en pantalla:** reservar desde el portal y ver llegar el aviso, la actualización sola a los 30 segundos, la campana como administrador que no es dueño, el tema oscuro y el celular.

## 8. Para aplicar

Hay tabla nueva: resetear `erp_saas` con `init.sql`, `seed.sql` y `seed_demo.sql`, en ese orden.
