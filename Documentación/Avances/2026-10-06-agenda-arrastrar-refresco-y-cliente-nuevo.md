# 2026-10-06 — Agenda: arrastrar citas, refresco automático, cliente nuevo desde la cita y búsqueda sin tildes

## Contexto

Cinco ajustes pedidos tras revisar las pantallas de Agenda y Citas:

1. La búsqueda no debe distinguir tildes.
2. Los avisos de horario deben hablar como la empresa, no decir "el profesional".
3. El formulario de cita debe tener un "+" junto al buscador para registrar un cliente sin salir de ahí.
4. La agenda debe actualizarse sola cuando otra persona agenda.
5. Las citas deben poder moverse arrastrándolas.

No hay cambios de esquema.

## 1. Búsqueda sin tildes

"ramirez" encuentra a "Ramírez", y "nunez" a "Nuñez". Tampoco distingue mayúsculas.

| Archivo | Cambio |
|---|---|
| `app/Support/Search.php` | Nuevo. Compara el texto sin tildes ni mayúsculas. No usa extensiones de PostgreSQL, así que funciona también en una base sin resetear. |
| `app/Http/Controllers/Api/CustomerController.php` | La lista de clientes busca así por nombre completo, documento, celular, correo y código. |
| `app/Http/Controllers/Api/AppointmentController.php` | La lista de citas busca así por código, y por nombre, documento y celular del cliente. |

Los símbolos `%` y `_` que alguien escriba se buscan como texto, no como comodines.

## 2. Avisos de horario

Los avisos usan el nombre de quien atiende en lugar de "el profesional". Así sirven igual para una empresa que dice médico, estilista o entrenador, y no hay que acertar el género.

| Antes | Ahora |
|---|---|
| Fuera del horario del profesional (09:00–13:00, 15:00–19:00) | Dr. Carlos Pérez no atiende a esa hora (su horario es 09:00–13:00 y 15:00–19:00) |
| Fuera del horario del profesional (no trabaja ese día en esta sede) | Dr. Carlos Pérez no atiende a esa hora (ese día no atiende en esta sede) |
| El profesional ya tiene una cita en ese intervalo | Dr. Carlos Pérez ya tiene una cita en ese intervalo |
| El profesional no realiza este servicio | Dr. Carlos Pérez no realiza este servicio |
| El profesional no está activo | Dr. Carlos Pérez no está disponible para agendar |
| El cliente ya tiene la cita C-0001... | Carlos Ramírez Soto ya tiene la cita C-0001... |

Archivos: `app/Support/AppointmentScheduler.php`, `AppointmentController.php`.

## 3. Cliente nuevo desde la cita

Junto al buscador de clientes del panel "Nueva cita" hay un botón "+". Abre el mismo formulario de cliente de siempre, encima del panel de la cita. Al guardar, el cliente queda elegido en la cita (y su mascota, si registró una sola). La tecla Escape cierra solo el formulario de cliente.

| Archivo | Cambio |
|---|---|
| `features/appointments/components/AppointmentForm.tsx` | Botón "+" y el formulario de cliente. |
| `features/customers/components/FormCustomer.tsx` | Al crear, entrega el cliente creado a quien abrió el formulario. En la pantalla de Clientes se comporta igual que antes. |

## 4. Refresco automático

La agenda vuelve a pedir sus datos cada 20 segundos, y también al volver a la pestaña o a la ventana. La lista de Citas hace lo mismo cada 30 segundos. Bajo la fecha de la agenda se lee "Se actualiza sola · última vez a las HH:MM", con un botón para actualizar en el momento.

No se actualiza mientras hay un panel abierto, una cita a medio arrastrar o la confirmación de un movimiento, para no interrumpir. Tampoco con la pestaña oculta.

Se eligió consultar cada cierto tiempo y no una conexión en vivo: no necesita nada nuevo en el servidor, y un retraso de hasta 20 segundos es aceptable porque la API ya impide que dos personas tomen el mismo horario. Si más adelante se quiere que sea instantáneo, se puede sumar una conexión en vivo (WebSockets) sin cambiar las pantallas.

Archivos: `features/appointments/components/useAutoRefresh.ts` (nuevo), `AgendaDay.tsx`, `AppointmentsTable.tsx`, `useAppointmentPanels.tsx`.

## 5. Arrastrar citas

En la agenda, una cita pendiente o confirmada se puede arrastrar a otra hora o a la columna de otro profesional.

- Mientras se arrastra, un recuadro punteado muestra dónde caería y a qué hora (ajustada al intervalo de la empresa, 15 minutos por defecto).
- Al soltar, una ventana pide confirmar: "Ahora: 09:00 con Dr. Carlos Pérez. Pasa a: 11:00 con Dra. María Gómez."
- Si el horario no sirve, la ventana muestra el motivo y la cita se queda donde estaba.
- Las citas que ya no se pueden reprogramar (llegó, en atención, atendida, cancelada, no asistió) no se arrastran.
- Soltarla donde estaba no hace nada.

Un movimiento arrastrando queda en el historial de la cita igual que una reprogramación. Para forzar un horario fuera de lo normal se sigue usando "Reprogramar" en el panel de la cita.

Archivo: `features/appointments/components/AgendaDay.tsx`.

## 6. Verificación

- Pruebas de la API contra una base temporal (eliminada al terminar):

| Caso | Resultado |
|---|---|
| Clientes: "ramirez", "RAMÍREZ", "sofia valverde", "diaz", documento, parte del celular, código y parte del correo | Encuentra al cliente |
| Clientes: "zzz", "%" y "a_b" | Sin resultados |
| Clientes: "ramirez" desde otra empresa | Sin resultados |
| Citas: "ramirez", "Ramírez Soto" y "c-0001" | Encuentra la cita |
| Avisos de doble reserva, fuera de horario, día sin atención y cliente con otra cita | Usan el nombre del médico o del cliente |

- En el navegador, contra la base temporal:

| Prueba | Resultado |
|---|---|
| Arrastrar una cita de las 09:00 a las 11:00 en su columna: aparece la confirmación; al aceptar, la cita se mueve | Correcto |
| Arrastrarla a la columna de otra médica a las 10:00: la confirmación nombra a la otra médica; al aceptar, cambia de columna | Correcto |
| Soltarla sobre un horario ocupado: la ventana muestra "Dra. María Gómez ya tiene una cita en ese intervalo" y la cita no se mueve; "No mover" cierra | Correcto (con eventos de arrastre generados por código; ver nota) |
| Crear una cita por fuera de la pantalla: aparece sola en la agenda a los 10 segundos y cambia la hora de "última vez" | Correcto |
| Botón "+": abre "Nuevo Paciente" encima de "Nueva cita"; al guardar, el paciente queda elegido con su código | Correcto |
| Buscar "maria jose nunez" encuentra a "María José Nuñez Peña" | Correcto |
| Con el formulario de paciente abierto, Escape cierra solo ese formulario | Correcto |

- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- Nota sobre el arrastre: la herramienta de prueba solo envía dos posiciones del ratón, así que los arrastres largos o que terminan sobre otra cita no llegaron a soltarse con ella; un ratón real envía posiciones todo el tiempo. Ese caso se probó generando los eventos por código. Además, tras las pruebas se hizo un ajuste para que las capas decorativas de la grilla no reciban el puntero; pasó la verificación de tipos pero no se volvió a probar en pantalla.
- No se probó: el refresco de la lista de Citas, el refresco al volver a la pestaña, el botón "Actualizar ahora", arrastrar en una pantalla táctil, ni el botón "+" en un rubro con mascotas.

## 7. Para aplicar

Nada adicional a las entregas anteriores.
