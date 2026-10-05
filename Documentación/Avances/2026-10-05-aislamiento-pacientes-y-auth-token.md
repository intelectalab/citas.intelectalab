# 2026-10-05 — Aislamiento de pacientes por tenant y clave del token de sesión

## Contexto

Revisión inicial del proyecto. Se definió que `apps/api/infrastructure/docker/postgres/` (PostgreSQL) es la **fuente de verdad del esquema de base de datos**. El `init.sql` MySQL en `infrastructure/` de la raíz queda como referencia histórica y no se usa.

## Cambios realizados

### 1. Backend — Pacientes solo visibles por su tenant

**Archivo:** `apps/api/app/Http/Controllers/Api/PatientController.php`

**Problema:** `index` listaba todos los pacientes de la base sin filtrar por `tenant_id`. `show`, `update` y `destroy` usaban route model binding directo (`Patient $patient`), por lo que cualquier usuario autenticado podía leer, editar o borrar un paciente de otro tenant si conocía su id.

**Solución:**
- Se agregó el método privado `tenantQuery(Request)` que devuelve `Patient::where('tenant_id', $request->user()->tenant_id)`.
- Se agregó `findForTenant(Request, string $id)` que busca el paciente dentro del tenant actual con `findOrFail`, respondiendo 404 si no pertenece.
- `index` ahora parte de `tenantQuery` antes de aplicar búsqueda y filtros.
- `show`, `update` y `destroy` reciben el id como `string` en vez del modelo inyectado y pasan por `findForTenant`.
- `store` no cambió: ya asignaba `tenant_id` del usuario autenticado.

**Efecto:** un usuario con `tenant_id` nulo (administrador SaaS) no ve pacientes de ningún tenant. Si en el futuro se requiere una vista global para el admin SaaS, debe agregarse explícitamente.

Verificación: `php -l` sin errores de sintaxis.

### 2. Frontend — Clave del token al expirar la sesión

**Archivo:** `apps/web/src/hooks/useAuth.tsx`

**Problema:** al recibir un 401 en `/user`, el hook ejecutaba `localStorage.removeItem('token')`, pero el token se guarda bajo la clave `auth_token` (en `login` y en el interceptor de `src/lib/axios.ts`). El token expirado nunca se eliminaba y se reenviaba en cada petición.

**Solución:** se cambió la clave a `auth_token`. Ahora las tres rutas (login, logout, expiración) usan la misma clave.

### 3. ESLint y configuración de Next

`apps/web/eslint.config.mjs` y `apps/web/next.config.ts` son los archivos por defecto de la plantilla `create-next-app`. Se revisaron y **no se modificaron**: no hay requisitos actuales que exijan reglas o configuración adicional. Queda anotado para cuando se definan convenciones del equipo.

## Pendientes detectados (no abordados en este avance)

- Otros controladores sin filtro por tenant: `PipelineController`, `PipelineStageController`, `TenantController`, `TypeDocumentController`. Evaluar cuáles deben ser globales y cuáles por tenant.
- Rutas del menú lateral sin página: `/appointments`, `/opportunities`, `/quotes`, `/branches`, `/settings`, `/subscribe`.
- Archivos duplicados en frontend: `ContactsTable1.tsx`, `FormContact1.tsx`.
- Esquema de base de datos en SQL crudo; no hay migraciones Laravel que lo reconstruyan.
- El repositorio no tiene commits todavía.
