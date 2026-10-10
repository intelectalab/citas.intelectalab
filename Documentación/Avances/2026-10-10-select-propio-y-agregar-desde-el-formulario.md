# 2026-10-10 — Todas las listas con el Select propio, y "+" para crear lo que falta sin salir del formulario

## Contexto

Dos pedidos:

1. Varias listas desplegables usaban el `<select>` genérico del navegador en lugar del componente `Select` del proyecto.
2. Agregar en los formularios el "+" que ya tenía el cliente en "Nueva cita", para no tener que salir del formulario a crear algo que todavía no existe.

Sin cambios de base ni de API.

## 1. Listas con el Select propio

Había 34 listas genéricas en 14 archivos. Se cambiaron **30**; quedan 4 (ver abajo).

Para poder usarlo en todos los casos, el componente `Select` ganó opciones nuevas. **Su comportamiento por defecto no cambió**: los formularios que ya lo usaban se ven y funcionan igual.

| Opción nueva | Para qué |
|---|---|
| `clearable={false}` | Listas donde siempre hay algo elegido (Sí/No, estado, moneda): no muestra la opción vacía. |
| `disabled` | Lista bloqueada. |
| `variant="chip"` | Versión pequeña, sin caja, para ir dentro de una fila o un filtro. |
| `onAdd` y `addLabel` | Muestra el botón "+" junto a la lista. |
| `ariaLabel` | Nombre de la lista cuando su etiqueta va aparte. |

### Dónde se cambió

| Pantalla | Listas |
|---|---|
| Servicios (formulario) | Categoría, estado, modalidad y servicio del control |
| Clientes (formulario) | Sexo, tipo de sangre, tipo de documento y etapa |
| Mascotas o dependientes del cliente | Sexo |
| Agenda | Sede |
| Todas las tablas | Los filtros que aparecen con el botón "Filtro" |
| Plantillas de ficha | Tipo de campo, en la hoja editable y en el panel lateral |
| Planes (plataforma) | Estado, catálogo, moneda, prueba, pago al inicio, renovación y periodo |
| Empresas (plataforma) | Rubro, plan, ciclo de pago y estado, en el formulario y en la página de la empresa |
| Rubros (plataforma) | Estado, tipo de dependiente, servicio del control y tipo de campo |

### Lo que quedó con la lista genérica

| Dónde | Por qué |
|---|---|
| Registro público de empresa (`RegisterModal`), 2 listas | Ese formulario tiene su propio estilo (campos grises, más pequeños); el `Select` se vería distinto al resto de sus campos. Además ahí la lista obligatoria la valida el navegador. |
| `FormContact1.tsx`, 2 listas | Es un archivo duplicado que ninguna página usa. |

## 2. "+" para crear sin salir del formulario

| Formulario | "+" nuevo | Qué hace |
|---|---|---|
| Nueva cita | Mascota (o dependiente) | Abre un recuadro en el mismo formulario: nombre y especie. Al agregar, queda elegida. |
| Nueva cita | Servicio | Abre el panel de Servicios encima. Al guardar, el servicio queda elegido y se cargan sus horarios. |
| Lista de espera | Cliente | El buscador de clientes compartido ahora tiene "+": abre el panel de cliente y lo deja elegido. |
| Lista de espera | Mascota y servicio | Igual que en la cita. |
| Nuevo control | Cliente, mascota y servicio | Igual. |
| Profesional | Especialidad | Un campo "Nueva especialidad" con "+": la crea y la deja marcada. Antes las especialidades no se podían crear desde ninguna pantalla. |

- El "+" de servicio solo lo ve el administrador de la empresa; los demás "+" los ve todo el personal.
- Si el cliente no tiene mascotas, la lista lo dice ("Aún no tiene mascotas: agrégalo con +") en lugar de mandar a otra pantalla.
- Ya existían y no cambiaron: el "+" de cliente en Nueva cita y "Nueva categoría" en el formulario de servicio.

## 3. Cambios realizados

| Archivo | Contenido |
|---|---|
| `app/components/common/Select.tsx` | Opciones nuevas. |
| `app/globals.css` | Barra de desplazamiento discreta en las listas. |
| `features/services/components/QuickService.tsx` | Nuevo. Servicio nuevo desde otro formulario. |
| `features/customers/components/QuickDependent.tsx` | Nuevo. Mascota o dependiente nuevo desde otro formulario. |
| `features/customers/components/CustomerPicker.tsx` | "+" de cliente. |
| `features/appointments/components/AppointmentForm.tsx`, `features/waitlist/components/FormWaitlist.tsx`, `features/recalls/components/FormRecall.tsx` | Los "+" de cada formulario. |
| `features/professionals/components/FormProfessional.tsx` | Especialidad nueva. |
| `features/services/components/FormService.tsx` | Listas con el Select; devuelve el servicio que guardó. |
| `features/customers/components/FormCustomer.tsx`, `CustomerDependents.tsx`, `features/appointments/components/AgendaDay.tsx`, `app/components/ui/table/DataTable.tsx`, `features/records/components/RecordFieldsEditor.tsx`, `RecordSheetEditor.tsx`, `features/plans/components/FormSaaSPlan.tsx`, `features/tenants/components/FormTenant.tsx`, `TenantDetail.tsx`, `features/business-types/components/FormBusinessType.tsx`, `TemplateEditor.tsx` | Listas con el Select. |

## 4. Verificación

En el navegador, contra una base temporal (ya eliminada), con clics reales:

| Prueba | Resultado |
|---|---|
| Veterinaria, Nueva cita: elegir tutor; la lista de mascota y la de servicio tienen su "+" | Correcto |
| "+" de mascota: escribir nombre y especie y agregar | El recuadro se cierra y la mascota queda elegida |
| "+" de servicio: se abre el panel de servicio encima de la cita; abrir Categoría, elegir una, llenar y crear | El panel se cierra, el servicio queda elegido en la cita y aparecen sus horarios libres |
| Lista de espera: el buscador tiene "+" y abre "Nuevo Tutor" encima | Correcto |
| Nuevo control: "+" de cliente y de servicio; al elegir cliente aparece el de mascota | Correcto |
| Profesional: escribir una especialidad y "+" | Aparece en la lista, marcada, y el campo se limpia |
| Agenda de Centro Médico: abrir Sede, elegir San Borja | La agenda pasa a los médicos de esa sede |
| Formulario de cliente: cuatro listas con el Select; cambiar Sexo | Correcto |
| Citas: botón Filtro, abrir "Fase", elegir "Pendiente" | La tabla pasa de 132 a 65 filas |
| Plantillas de ficha: abrir el tipo del último campo, elegir "Fecha" | El campo cambia y la hoja queda con cambios sin guardar; el menú no se recorta |
| En esas pantallas no queda ninguna lista genérica | Correcto |

- Se revisó en una captura el menú del tipo de campo abierto sobre la hoja.
- `tsc --noEmit` sin errores en `src`.
- **No se probaron en pantalla las listas de la consola de la plataforma** (planes, empresas y rubros): se cambiaron con el mismo componente y pasan la verificación de tipos, pero falta abrirlas y guardar. Tampoco: guardar un cliente o un servicio tras cambiar sus listas (se probó el alta de servicio desde la cita), el tema oscuro, y los "+" como profesional.

## 5. Para aplicar

No requiere resetear la base. Basta recargar la página.
