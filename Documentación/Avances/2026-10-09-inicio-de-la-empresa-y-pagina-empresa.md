# 2026-10-09 — Inicio con datos de la empresa y página "Empresa" con su información general

## Contexto

Dos pedidos:

1. "Empresa", en Administración de sede, mostraba una tabla de ejemplo con datos fijos. Debe ser la información general de la propia empresa.
2. El inicio (dashboard) tenía datos de muestra (pedidos, cotizaciones, tickets, reuniones). Debe mostrar lo de la propia empresa, según su rubro, **manteniendo el diseño y los accesos rápidos** que ya tenía.

No hay cambios de esquema. En `seed.sql` solo se corrigieron las razones sociales de las seis empresas originales, que no correspondían a sus nombres.

## 1. Inicio

Se conservó la estructura de la pantalla: encabezado con saludo y dos botones, panel superior con lista y tarjeta lateral, tarjeta oscura grande junto al resumen de tres números, accesos rápidos y dos tarjetas al final. Cambió lo que muestra cada parte.

| Parte | Antes (muestra) | Ahora |
|---|---|---|
| Encabezado | "Buenas noches" fijo; Nuevo pedido, Ver pedidos | Saludo según la hora; **Nueva cita** y **Ver citas** |
| Panel superior | Reuniones de hoy; "Conecta tu calendario" | **Próximas citas de hoy** (hora, cliente o mascota, servicio, quién atiende y fase) y **Ahora mismo**: en espera, en atención y por confirmar. Con varias sedes, las citas de hoy por sede |
| Tarjeta oscura | Pedidos hoy | **Citas de hoy**, comparadas con el mismo día de la semana pasada; atendidas, **agenda ocupada (%)** y valor atendido hoy |
| Resumen | Cotizaciones, nuevos clientes, tickets | **Últimos 30 días**: asistencia (con cuántos no asistieron y cancelaron), clientes nuevos del mes frente al mes pasado, y valor de lo atendido. Abajo, **lo más pedido** |
| Accesos rápidos | Cliente, empresa, producto, ticket | Nueva cita, nuevo cliente, y dos que dependen de la empresa |
| Tarjeta izquierda | Tareas pendientes (ejemplo) | **Por resolver**, con el mismo interruptor: "Pendientes" y "Al día" |
| Tarjeta derecha | Feed de actividades (ejemplo) | **Actividad reciente**: los últimos movimientos de las citas |

### Por qué estas métricas

Para un negocio de citas, lo que decide el día es: cuánta gente viene hoy y quién sigue, quién está esperando, qué citas faltan confirmar, cuánta agenda está vacía, cuánta gente falta a su cita y si entran clientes nuevos. Del prototipo se tomaron citas de hoy, próximas citas, ocupación, inasistencias, clientes nuevos, servicios más pedidos y "requiere tu atención". Quedaron fuera las que dependen de la caja (ingresos de hoy, ticket promedio, ingresos de 14 días), que todavía no existe.

**"Valor de lo atendido" no es lo cobrado**: suma el precio de las citas atendidas. La pantalla lo dice. Cuando exista la caja se reemplaza por lo cobrado.

### Por resolver

| Línea | Qué cuenta | Lleva a |
|---|---|---|
| Citas por confirmar | Pendientes de hoy y de mañana | Citas |
| Citas pasadas sin cerrar | Terminaron hace más de una hora y nadie marcó si se atendieron | Citas |
| Citas dentro de un bloqueo o feriado | Próximas, dentro de una ausencia, cierre o feriado en que la sede cierra | Bloqueos y ausencias |
| Controles vencidos | Clientes que ya debían volver | Controles y seguimiento |
| En lista de espera | Esperan un horario | Lista de espera |
| Fichas sin firmar | Borradores (solo si la empresa tiene fichas) | Fichas de atención |

"Pendientes" muestra las que tienen algo; "Al día", las que están en cero.

### Según el rubro y la empresa

- Usa el vocabulario de la empresa: pacientes, tutores, alumnos; médicos, barberos, instructores.
- En la veterinaria, las citas y la actividad muestran el nombre de la mascota.
- El tercer acceso rápido depende de los módulos: Cola sin cita (barbería), Clases (fitness), Fichas de atención (rubros con ficha) o Lista de espera.
- "Lo más pedido" sale de los servicios de la propia empresa.

### Quién ve qué

| Quién | Qué ve |
|---|---|
| Administrador de la empresa y recepción | Toda la empresa |
| Profesional | Solo sus citas, sus controles y sus fichas; el cuarto acceso es Controles en vez de Bloqueos |
| Plataforma (administrador o soporte SaaS) | El saludo y accesos a Empresas, Planes, Plantillas de rubros y Configuración |

El inicio se actualiza solo cada minuto.

## 2. Empresa (`/companies`)

| Tarjeta | Contenido |
|---|---|
| Empresa | Nombre, rubro, razón social, RUC, teléfono, dirección fiscal, zona horaria y desde cuándo está en la plataforma. El lápiz abre el panel para editar. |
| Uso del plan | Cuatro tarjetas con barra: sucursales activas, profesionales, usuarios del equipo y citas del mes, frente al tope del plan. La barra pasa a ámbar desde el 80 % y a rojo al llegar al tope. |
| Tu plan | Plan, estado, forma de pago y fin de la prueba si aplica. |
| Código de empresa | El código, con botón para copiarlo, y cuántos clientes y servicios tiene. |
| Lo demás de tu empresa | Accesos a Sucursales, Profesionales y Configuración. |

Panel "Editar empresa": nombre de la empresa, razón social, RUC (11 dígitos), teléfono, dirección fiscal, distrito y departamento.

- El nombre de la empresa es el nombre comercial: al cambiarlo cambia en el menú, en el inicio y en lo que ve el cliente.
- La ve todo el personal; la cambia solo el administrador. El rubro, el plan, el código y la zona horaria no se editan aquí.

## 3. Cambios realizados

### Backend

| Archivo | Contenido |
|---|---|
| `app/Http/Controllers/Api/DashboardController.php` | Nuevo. Resumen del inicio. |
| `app/Http/Controllers/Api/CompanyController.php` | Nuevo. Ver y cambiar los datos de la empresa. |
| `app/Models/AppointmentStatusHistory.php` | Relación con su cita. |
| `routes/api.php` | Rutas nuevas. |
| `infrastructure/docker/postgres/seed.sql` | Razones sociales acordes con cada empresa. |

### Rutas

| Ruta | Efecto |
|---|---|
| `GET /api/dashboard` (módulo `agenda`) | Hoy, últimos 30 días, por resolver y actividad. |
| `GET /api/company` | Datos de la empresa, su plan y su uso. |
| `PUT /api/company` | Cambia los datos. Solo el administrador de la empresa. |

### Frontend

| Archivo | Contenido |
|---|---|
| `features/home.tsx` | El inicio, con datos reales. |
| `features/companies/components/CompanyProfile.tsx` | Nuevo. La página Empresa. |
| `features/companies/components/FormCompany.tsx` | Nuevo. Panel para editar. |
| `features/companies/companies.ts` | Nuevo. Tipos. |
| `app/(dashboard)/companies/page.tsx` | Usa la página nueva. |

## 4. Verificación

- `init.sql` y `seed.sql` cargan sin errores en una base temporal. La temporal se eliminó.
- Pruebas de la API contra esa base:

| Caso | Resultado |
|---|---|
| Inicio de Centro Médico: 24 citas hoy en tres sedes, próximas seis, asistencia, clientes nuevos, lo más pedido, por resolver y actividad | Correcto |
| Inicio de la veterinaria: las citas y la actividad traen el nombre de la mascota | Correcto |
| Inicio de la barbería | Correcto |
| Un profesional: solo sus 3 citas de hoy y sus números del mes; sin sedes | Correcto |
| Un cliente del portal; la plataforma | 403; 403 |
| Empresa: datos, plan y uso frente al tope | Correcto |
| Guardar: cambia el nombre en la empresa y en su razón comercial | Correcto |
| Sin nombre y con RUC de 3 dígitos | 422, con ambos mensajes |
| Un profesional la ve sin poder editar; intenta guardar | Correcto; 403 |

- En el navegador, contra la base temporal, con clics reales:

| Prueba | Resultado |
|---|---|
| Inicio de la veterinaria: saludo, próximas citas con mascota y veterinario, "Ahora mismo", citas de hoy frente al viernes pasado, agenda ocupada, últimos 30 días con "Tutores nuevos", lo más pedido | Correcto |
| Accesos rápidos de la veterinaria: Nueva cita, Nuevo Tutor, Fichas de atención, Bloqueos y ausencias | Correcto |
| Interruptor de "Por resolver": "Al día" muestra las cuatro líneas en cero; "Pendientes" vuelve a las dos con pendientes | Correcto |
| Empresa: datos, uso ("1 de 1 · Llegaste al tope", "Veterinarios 4 de 5 · 80%"), plan y código | Correcto |
| Lápiz: el panel dice "Editar empresa: Veterinaria Huellitas"; con RUC de 3 dígitos avisa "El RUC tiene 11 dígitos" | Correcto |
| Corregir RUC, cambiar nombre, teléfono y distrito y guardar: el panel se cierra, la tarjeta y el menú muestran el nombre nuevo, y el inicio también | Correcto |
| Inicio de un profesional: "Tus próximas citas de hoy", sus 3 citas, sin el código de empresa, cuarto acceso "Controles y seguimiento" | Correcto |
| Inicio de DentalPlus: citas de hoy por cada una de sus cinco sedes | Correcto |
| Inicio de la plataforma: saludo y sus cuatro accesos, sin errores | Correcto |

- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- **No se pudo comprobar el botón "Copiar"** del código de empresa: el navegador de prueba no da acceso al portapapeles. Tampoco se probó el tema oscuro ni cómo se ve en un celular. No se pudo capturar la pantalla.

## 5. Para aplicar

No requiere resetear la base. Las razones sociales corregidas se ven al recargar el seed.
