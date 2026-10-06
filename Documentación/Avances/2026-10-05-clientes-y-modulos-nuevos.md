# 2026-10-05 — `patients` pasa a `customers` y tablas para los módulos del prototipo

## Contexto

El prototipo `PROTOTIPO/AGENDA360.html` trabaja con "clientes" para diez rubros, no solo con pacientes, y cubre módulos que el esquema no tenía. En este avance se renombra la entidad y se agregan las tablas de inventario, fidelización, marketing, presupuestos, fichas configurables y lista de espera. No se rehízo la lógica existente: solo se adaptó lo que dependía del nombre anterior.

Los cambios de base de datos están en `init.sql` y `seed.sql` porque el esquema se va a resetear. No hay script de migración para una base con datos.

## 1. Esquema: `apps/api/infrastructure/docker/postgres/init.sql`

### Renombrado

| Antes | Ahora |
|---|---|
| tabla `patients` | tabla `customers` |
| `appointments.patient_id` | `appointments.customer_id` |
| `medical_records.patient_id` | `medical_records.customer_id` |
| `sales.patient_id` | `sales.customer_id` |
| índices `idx_patients_*` | `idx_customers_*` |
| `idx_medical_records_patient` | `idx_medical_records_customer` |

Las columnas de `customers` no cambiaron. Se conservan `blood_type`, `medical_notes` y los contactos de emergencia para no romper el formulario actual.

### Tablas nuevas (45; el esquema pasa de 38 a 83)

Los estados de flujo interno de estas tablas usan `varchar` con `CHECK`. La tabla `statuses` queda para los estados personalizables por tenant.

**Dependientes (1):** `customer_dependents`. Mascotas, hijos o familiares a cargo de un cliente. La referencian presupuestos, fichas, controles y lista de espera.

**Inventario (13):**
- Catálogos: `units` (con `tenant_id` nulo para unidades globales), `product_categories`, `product_brands`, `suppliers`.
- `products`: SKU único por tenant, tipo venta, insumo o ambos, costo, precio y stock mínimo.
- `warehouses` y `stock_balances`: saldo por almacén y producto, con cantidad en tránsito.
- `inventory_movements`: kardex con cantidad con signo y saldo resultante.
- `service_supplies`: insumos que consume un servicio al completarse.
- `stock_transfers` y `stock_transfer_items`: transferencia en dos pasos.
- `purchase_orders` y `purchase_order_items`.

**Fidelización (10):**
- `membership_plans` y `memberships`, con pausa que extiende la vigencia.
- `package_plans`, `package_plan_items`, `customer_packages`, `customer_package_items` y `package_usages`.
- `loyalty_transactions`: movimientos de puntos con signo.
- `gift_cards` y `gift_card_transactions`.

**Marketing (11):**
- `customer_tags` y `customer_tag_assignments`.
- `customer_consents`: consentimiento por canal (Ley 29733).
- `marketing_templates`, `marketing_campaigns` y `marketing_campaign_recipients`.
- `coupons` y `coupon_redemptions`.
- `automation_rules` y `automation_logs`.
- `customer_messages`: bitácora de mensajes por cliente.

**Presupuestos (4):** `quotes`, `quote_items` (con plan de sesiones), `quote_installments` (cuotas) y `quote_status_history`.

**Fichas configurables y seguimiento (5):**
- `record_templates`: los campos de la ficha se definen en JSON.
- `record_template_services`: qué servicios usan cada plantilla.
- `care_records`: ficha llenada, con copia de la plantilla, consentimiento, firma y bloqueo.
- `care_record_addenda`: adendas posteriores al bloqueo.
- `recalls`: controles de seguimiento que nacen de una ficha.
- `medical_records` se mantiene como ficha clínica fija.

**Lista de espera (1):** `waitlist_entries`, con rango de fechas, franja horaria y profesional opcional.

## 2. Datos: `apps/api/infrastructure/docker/postgres/seed.sql`

- Módulo de estados `patients` renombrado a `customers`.
- `INSERT INTO patients` pasa a `customers`, con ids fijos y códigos `CLI-0001` a `CLI-0003`.
- Textos de pipelines: "paciente" pasa a "cliente".
- Siete permisos nuevos: `customers.manage`, `inventory.manage`, `loyalty.manage`, `marketing.manage`, `quotes.manage`, `records.manage` y `waitlist.manage`. Los roles dueño los reciben porque el seed les asigna todos los permisos.
- Siete unidades de medida globales.
- Datos demo para el tenant "Centro Médico Integral": un almacén, dos categorías, tres productos con stock y kardex inicial, insumos por servicio, un plan de membresía, un paquete, tres etiquetas, consentimientos, dos plantillas de mensaje, una automatización inactiva y una plantilla de ficha vinculada a sus dos servicios.

## 3. Backend

| Archivo | Cambio |
|---|---|
| `app/Models/Customer.php` | Reescrito. Antes contenía por error una clase `Contact`. Ahora es el modelo `Customer` con borrado lógico, relaciones y generación de código. |
| `app/Models/Patient.php` | Eliminado. |
| `app/Http/Controllers/Api/CustomerController.php` | Nuevo, a partir de `PatientController`. Conserva el aislamiento por tenant. Las respuestas usan la clave `customer` en lugar de `patient`. |
| `app/Http/Controllers/Api/PatientController.php` | Eliminado. |
| `routes/api.php` | `apiResource('patients')` pasa a `apiResource('customers')`. |
| `app/Http/Controllers/ContactController.php` | Usa el modelo `Customer`. Antes importaba `App\Models\Contact`, que no cargaba. |

**Código correlativo.** El prefijo pasa de `PAC-` a `CLI-`. La generación se movió al modelo (`Customer::nextCode`) y un evento `creating` la aplica si el código llega vacío. Toma el número más alto del tenant, incluidos los eliminados, para no repetir códigos. Antes tomaba el último registro creado y podía repetir un código tras un borrado.

## 4. Frontend

| Antes | Ahora |
|---|---|
| `src/features/patients/components/PatientsTable.tsx` | `src/features/customers/components/CustomersTable.tsx` |
| `src/features/patients/components/FormPatient.tsx` | `src/features/customers/components/FormCustomer.tsx` |
| `src/app/(dashboard)/patients/page.tsx` | `src/app/(dashboard)/customers/page.tsx` |

- El `CustomersTable.tsx` anterior era una tabla con datos de ejemplo que ninguna página usaba. Fue reemplazado.
- Llamadas a la API: `/patients` pasa a `/customers`.
- Textos visibles: "Paciente" pasa a "Cliente".
- `Sidebar.tsx`: el ítem "Pacientes" (`/patients`) pasa a "Clientes" (`/customers`).
- `features/home.tsx`: el acceso rápido "Nuevo Cliente" apuntaba a `/customers/new`, que no existe. Ahora va a `/customers`.

## 5. Verificación

- `init.sql` y `seed.sql` se ejecutaron sin errores en una base temporal dentro del contenedor `erp_postgres`. La base `erp_saas` no se tocó y la temporal se eliminó.
- Prueba de la API contra esa base con usuarios de dos tenants:
  - Cada tenant lista solo sus clientes. El admin SaaS no ve ninguno.
  - Ver, editar o borrar un cliente de otro tenant responde 404.
  - Los códigos son correlativos por tenant y no se repiten tras un borrado.
  - `/api/contacts` lista y crea sobre `customers`.
  - `/api/patients` responde 404.
- `php -l` sin errores en los archivos PHP modificados.
- `tsc --noEmit` sin errores en `src`. Los errores restantes vienen de la caché `.next` y de páginas vacías que ya existían.

## 6. Para aplicar el esquema

`docker-compose.yml` solo monta `init.sql`, y Postgres lo ejecuta únicamente cuando el volumen está vacío. Tras resetear el volumen hay que cargar `seed.sql` a mano.
