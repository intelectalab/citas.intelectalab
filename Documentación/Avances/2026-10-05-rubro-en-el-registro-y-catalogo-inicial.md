# 2026-10-05 — Rubro en el registro de empresa y catálogo inicial por rubro

## Contexto

Este avance cubre tres pasos del plan de rubros:

- **Paso 2. Alinear los planes con el prototipo.** Los tres planes y sus claves de módulo ya se habían adoptado en el avance de módulos por plan y rubro. Aquí se verificó contra el prototipo y se corrigió lo que aún no los usaba: la pantalla de registro y los días de prueba.
- **Paso 4. Pedir el rubro al registrar la empresa.**
- **Paso 5. Aplicar la plantilla del rubro al crear el tenant.**

Los cambios de base están en `init.sql` y `seed.sql`. No hay migraciones.

## 1. Planes (paso 2)

Los planes del seed coinciden con el prototipo en precio mensual y anual, días de prueba, módulos y límites. No se cambiaron.

| Cambio | Detalle |
|---|---|
| Días de prueba | El registro daba 14 días a todos. Ahora usa `trial_config.trial_days` del plan: Esencial y Profesional 14, Empresarial 30. |
| Tarjetas de plan en `/register` | Mostraban el mismo texto fijo para los tres planes. Ahora muestran la descripción, los días de prueba, los límites y los módulos reales de cada plan. |
| `GET /api/plans` | Ordena por `presentation_order`: Esencial, Profesional, Empresarial. |
| Validación de `plan_id` | El registro solo acepta planes con estado `active`. |

## 2. Rubro en el registro (paso 4)

El formulario de registro de empresa pide el rubro en su primer paso. Es obligatorio en el formulario y lista los 10 rubros de `GET /api/business-types`. Al elegir uno se muestra su descripción.

En la API, `business_type_id` sigue siendo opcional: si no llega, la empresa queda en `generic`. Solo se aceptan rubros con estado `active`.

## 3. Catálogo inicial por rubro (paso 5)

### Esquema: `init.sql`

| Cambio | Detalle |
|---|---|
| `business_types.template` | jsonb. Catálogo que se copia al tenant cuando se registra. |
| Comentario de `record_templates.fields` | Se documentan el tipo `multiselect` y las claves `placeholder`, `days` y `step`. |

Forma de `template`:

```
{
  "categories": [
    { "name": "...", "services": [
      { "name": "...", "duration_minutes": 30, "price": 80, "buffer_after_minutes": 0, "uses_record": true }
    ] }
  ],
  "record_template": { "name": "...", "consent_text": "...", "require_consent": false, "fields": [ ... ] }
}
```

### Datos: `seed.sql`

Los valores salen del prototipo (`vertical_templates`).

| Rubro | Categorías | Servicios | Ficha | Campos | Consentimiento obligatorio |
|---|---|---|---|---|---|
| salon | 3 | 10 | Ficha técnica de color | 8 | no |
| barber | 2 | 6 | Preferencias de corte | 5 | no |
| aesthetics | 3 | 8 | Ficha de tratamiento estético | 8 | sí |
| dental | 2 | 8 | Historia clínica odontológica | 8 | sí |
| medical | 2 | 6 | Nota médica (SOAP) | 11 | no |
| physio | 2 | 5 | Evaluación y evolución | 8 | no |
| psychology | 1 | 4 | Nota de sesión | 5 | no |
| fitness | 2 | 5 | Evaluación física | 6 | no |
| vet | 3 | 7 | Historia clínica veterinaria | 9 | no |
| generic | 1 | 3 | Notas de atención | 2 | no |

Los tipos de campo del prototipo se llevaron a los del esquema: `recall` → `next_control`, `check` → `boolean`, `teeth` → `odontogram`, `multi` → `multiselect`.

### Backend

| Archivo | Cambio |
|---|---|
| `app/Models/BusinessType.php` | Columna `template`. Está oculta en las respuestas: `GET /api/business-types` no la envía. |
| `app/Models/Tenant.php` | `installBusinessTemplate()` copia al tenant las categorías, los servicios y la plantilla de ficha de su rubro. |
| `app/Http/Controllers/Auth/RegisterTenantController.php` | Llama a `installBusinessTemplate()` dentro de la transacción del registro. La respuesta agrega `installed` con los conteos. |
| `app/Http/Controllers/Api/ServiceController.php` | Faltaba importar `Status`. Crear un servicio sin `status_id` fallaba. |

Qué hace `installBusinessTemplate()`:

- Crea las categorías y sus servicios con el estado "Activo" del módulo `services`.
- Crea la plantilla de ficha como plantilla por defecto del tenant.
- Enlaza la plantilla con los servicios que el rubro atiende con ficha (`record_template_services`).
- Si el tenant ya tiene categorías o servicios, no hace nada. Así no duplica.

El registro también dejó de fallar cuando no llegan `phone`, `address` o `district`, que son opcionales.

### Frontend

| Archivo | Cambio |
|---|---|
| `src/features/subscriptions/components/RegisterModal.tsx` | Selector "Rubro del negocio" y envío de `business_type_id`. |
| `src/app/(auth)/register/page.tsx` | Tarjetas de plan con datos reales del plan. |

## 4. Verificación

- `init.sql` y `seed.sql` cargan sin errores en una base temporal del contenedor `erp_postgres`. La temporal se eliminó.
- Pruebas de la API contra esa base:
  - Registro de una empresa por cada uno de los 10 rubros: cada una recibe las categorías, servicios y ficha de la tabla anterior. Ningún servicio queda sin estado.
  - Días de prueba: 14 con Esencial y 30 con Empresarial.
  - El dueño de la veterinaria nueva ve sus 7 servicios en `GET /api/services` y su vocabulario es Tutores / Veterinarios / Mascotas.
  - Volver a instalar la plantilla en ese tenant no crea nada.
  - Registro sin rubro: queda en `generic` con su catálogo.
  - Rubro inexistente: 422 y no se crea el tenant.
  - `GET /api/business-types` no expone `template`.
  - `POST /api/services` sin `status_id`: 201.
- `php -l` sin errores en los archivos PHP modificados.
- `tsc --noEmit` sin errores en `src`.
- En el navegador, `/register` muestra los tres planes con sus datos y el formulario lista los 10 rubros. No se envió un registro desde el navegador: la base `erp_saas` todavía no tiene la columna `template`.

## 5. Para aplicar

Resetear el esquema con `init.sql` y cargar `seed.sql` a mano. Luego registrar una empresa desde `/register` eligiendo un rubro: al entrar, "Servicios" ya muestra el catálogo de ese rubro.

Con la base sin resetear el registro sigue funcionando y guarda el rubro, pero no copia el catálogo.
