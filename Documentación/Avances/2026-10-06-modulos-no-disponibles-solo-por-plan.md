# 2026-10-06 — Configuración de la empresa: "No disponibles" solo lista lo que falta por el plan

## Contexto

En `/settings`, la lista "No disponibles para tu empresa" mostraba también los módulos que el rubro de la empresa no usa, con el texto "No aplica a tu rubro". En un consultorio médico salían Inventario, Membresías, Puntos y gift cards, Clases grupales y Cola de llegada. La empresa no puede hacer nada con esa información: no los obtiene cambiando de plan.

Pedido: mostrar solo lo que no está disponible por el plan.

No hay cambios de esquema.

## Cambios realizados

| Archivo | Problema | Solución |
|---|---|---|
| `apps/web/src/features/settings/components/TenantSettings.tsx` | La lista incluía los módulos que apaga el rubro. | Esos módulos ya no se listan. Se retiró el texto "No aplica a tu rubro". |
| `apps/web/src/features/settings/components/TenantSettings.tsx` | Si la lista quedaba vacía, se veía una línea divisoria sin nada debajo. | La línea y el bloque solo aparecen cuando hay algo que listar. |
| `apps/api/app/Http/Controllers/Api/TenantSettingController.php` | Un módulo que el rubro no usa y que además no está en el plan se marcaba como "plan", y habría seguido en la lista como "No incluido en tu plan". | El motivo "rubro" se evalúa antes que "plan". |

Siguen mostrándose, cuando ocurren, "Desactivado por la plataforma" y "Requiere una suscripción activa".

La página de la empresa en la consola (`/admin/tenants/{id}`) no cambia: el administrador SaaS sigue viendo el motivo de cada módulo, incluido el rubro.

## Verificación

- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- En el navegador, contra una base temporal (eliminada al terminar), con el dueño de Centro Médico Integral: la lista muestra solo "API e integraciones — No incluido en tu plan". Los doce interruptores siguen igual.
- No se probó un módulo que esté a la vez fuera del plan y apagado por el rubro, ni la lista vacía (empresa con plan Empresarial).
