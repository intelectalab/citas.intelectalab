# 2026-10-10 — "Nuevo profesional" bloqueado cuando el plan ya no admite más

## Contexto

Decisión del día: no existe el profesional sin cuenta. Todo profesional tiene su cuenta y ocupa un usuario del plan; solo se crean tantos como permite el plan, sin contar al dueño.

La API ya rechazaba el alta al llegar al tope, pero el botón "Nuevo profesional" dejaba abrir y llenar el formulario, y avisaba recién al guardar. Pedido: que no se pueda entrar al formulario y que el propio botón diga por qué.

## Lo que se encontró

Ninguna empresa de ejemplo supera su plan. Centro Médico Integral tiene 8 profesionales y su plan (Profesional) permite 20: por eso el formulario abría. En la pantalla no se veía cuántos permite el plan, y eso lo hacía parecer un error.

## Cambios

| Qué | Cómo queda |
|---|---|
| Botón "Nuevo profesional" con el tope alcanzado | Sale atenuado, con candado, y no abre el formulario. Al pasar el cursor (o al llegar con el teclado) muestra el motivo: "Tu plan permite hasta 5 barberos y ya tienes 5. Para agregar otro, cambia de plan." |
| Tarjeta nueva en la pantalla de profesionales | Cuántos hay frente a cuántos permite el plan ("Médicos de 20 del plan": 8). Con el tope alcanzado lo dice y cambia de color. |
| Tope que se revisa | El de profesionales y el de cuentas de usuario (sin el dueño); bloquea el primero que se alcance. |
| Editar, horario y eliminar | Sin cambios: siguen disponibles con el tope alcanzado. Al eliminar uno, el botón vuelve a habilitarse. |
| API | Sin cambios: sigue rechazando el alta si alguien la intenta por otra vía. |
| Datos de ejemplo | La Barbería Don Filo tiene un barbero más (Bryan Quispe) y queda en 5 de 5, justo en el tope del plan Esencial, para poder ver el bloqueo. La veterinaria sigue en 4 de 5. |

El bloqueo del botón quedó en la tabla compartida (`primaryActionBlocked`), así que otras pantallas pueden usarlo igual.

## Archivos

| Archivo | Contenido |
|---|---|
| `apps/web/src/app/components/ui/table/DataTable.tsx`, `DataTable.module.css` | Botón principal bloqueado con su motivo. |
| `apps/web/src/features/professionals/components/ProfessionalsTable.tsx` | Lee el uso frente al plan (`GET /company`), bloquea el botón y muestra la tarjeta. |
| `apps/api/infrastructure/docker/postgres/seed_demo.sql` | Un barbero más en la Barbería Don Filo. |

## Verificación

- `init.sql`, `seed.sql` y `seed_demo.sql` cargan sin errores en una base temporal (ya eliminada). Ninguna empresa supera su plan en profesionales, usuarios ni citas del mes; ningún profesional sin cuenta. La barbería queda en 5 de 5 y 215 citas del mes de 800.
- En el navegador, contra la base temporal, con clics reales:

| Prueba | Resultado |
|---|---|
| Barbería (5 de 5): el botón "Nuevo Barbero" está bloqueado | Correcto |
| Clic en el botón bloqueado | No abre el formulario |
| Cursor sobre el botón | El motivo pasa a visible, con el texto del tope |
| Barbería: tarjeta "Barberos de 5 del plan: tope alcanzado" con 5 | Correcto |
| Centro Médico (8 de 20): tarjeta "Médicos de 20 del plan" con 8; el botón abre el formulario | Correcto |

- `tsc --noEmit` sin errores en `src`.
- No se pudo revisar el aspecto del botón bloqueado ni de su globo en una captura (las capturas del navegador de prueba salieron recortadas); se comprobó por sus estilos. Tampoco se probó en pantalla que el botón se habilite tras eliminar un profesional, ni el tema oscuro.

## Para aplicar

El botón y la tarjeta no requieren resetear. Para ver la barbería en su tope, resetear con `init.sql`, `seed.sql` y `seed_demo.sql`.
