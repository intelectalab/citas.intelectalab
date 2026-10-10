# 2026-10-09 — Cada profesional de ejemplo con su cuenta, y topes del plan al registrar profesionales

## Contexto

En las empresas de ejemplo había profesionales que se veían en pantalla pero no tenían cuenta para entrar. Pedido: que los datos de muestra sean coherentes con el plan de cada empresa (o se le sube el plan, o no puede tener más de lo que su plan permite), sin necesidad de que todas tengan varias sucursales.

## Lo que se encontró

1. **Los equipos de ejemplo sí caben en sus planes.** No hizo falta cambiar ningún plan ni quitar profesionales, sedes o citas.
2. **Lo que faltaba eran las cuentas.** En el sistema, registrar a un profesional siempre le crea su cuenta; el seed los insertaba sin ella. Solo los dos de Centro Médico podían entrar.
3. **La API no respetaba el tope de profesionales ni el de usuarios del plan.** Una empresa podía registrar más de los que su plan permite.
4. **Una empresa nueva no tenía el rol de profesional.** El alta de una empresa crea solo el rol de dueño. El primer profesional que registraba quedaba sin rol y, por eso, veía las citas de todos en lugar de solo las suyas.

## Cómo queda cada empresa frente a su plan

| Empresa | Plan | Sedes activas | Profesionales | Usuarios | Citas del mes |
|---|---|---|---|---|---|
| Centro Médico Integral | Profesional | 3 de 3 | 8 de 20 | 8 de 20 | 296 de 4000 |
| Clínica Odontológica DentalPlus | Empresarial | 5 de 20 | 12 de 100 | 12 de 100 | 452 de 30 000 |
| Consultorio Psicológico Mente Sana | Profesional (prueba) | 2 de 3 | 5 de 20 | 5 de 20 | 188 de 4000 |
| Centro Fisioterapia Vital | Profesional | 3 de 3 | 7 de 20 | 7 de 20 | 267 de 4000 |
| Laboratorio Clínico BioAnálisis | Profesional | 2 de 3 | 5 de 20 | 5 de 20 | 201 de 4000 |
| Policlínico San Judas | Empresarial (cancelado) | 3 de 20 | 7 de 100 | 7 de 100 | 280 de 30 000 |
| Salón Bella Aura | Profesional | 1 de 3 | 4 de 20 | 4 de 20 | 130 de 4000 |
| Barbería Don Filo | Esencial | 1 de 1 | 4 de 5 | 4 de 5 | 149 de 800 |
| Centro de Estética Lumina | Profesional (prueba) | 1 de 3 | 4 de 20 | 4 de 20 | 150 de 4000 |
| Estudio Prana Yoga y Pilates | Profesional | 1 de 3 | 4 de 20 | 4 de 20 | 148 de 4000 |
| Veterinaria Huellitas | Esencial | 1 de 1 | 4 de 5 | 4 de 5 | 140 de 800 |

**El dueño no cuenta como usuario del plan** (aclaración posterior del mismo día): el tope es de cuentas además de la suya. Usuarios = una cuenta por profesional. La barbería y la veterinaria tienen 4 de 5: admiten un profesional más y con él llegan al tope de profesionales y de usuarios del plan Esencial.

## Cambios

### Datos de muestra (`seed_demo.sql`)

- Todas las empresas tienen el rol de profesional.
- **Cada profesional tiene su cuenta**, con el rol de profesional. El correo es `nombre.apellido@<empresa>.pe` (por ejemplo `jhonatan.rios@barberiadonfilo.pe`) y la contraseña es la de prueba, como las demás cuentas.
- No cambian sedes, planes, servicios, clientes ni citas.

### Topes del plan al registrar un profesional

Cada profesional nuevo ocupa un lugar de profesional y una cuenta de usuario del plan. Si la empresa ya llegó a cualquiera de los dos topes, no se registra y el formulario lo explica: "Tu plan permite hasta 5 cuentas de usuario y ya llegaste a ese número. Para agregar otro, cambia de plan."

El formulario de profesional antes no mostraba ningún error al fallar (solo lo escribía en la consola del navegador). Ahora muestra el motivo, sea el tope del plan o un dato mal llenado.

### Rol de profesional en empresas nuevas

Al registrar un profesional, si la empresa no tiene el rol de profesional se crea en ese momento. Así su cuenta ve solo sus citas.

## Archivos

| Archivo | Contenido |
|---|---|
| `apps/api/infrastructure/docker/postgres/seed_demo.sql` | Rol de profesional en todas las empresas y una cuenta por profesional. |
| `apps/api/app/Http/Controllers/Api/ProfessionalController.php` | Tope de profesionales y de usuarios del plan; crea el rol de profesional si falta. |
| `apps/web/src/features/professionals/components/FormProfessional.tsx` | Muestra el motivo cuando no se puede guardar. |

## Verificación

- `init.sql`, `seed.sql` y `seed_demo.sql` cargan sin errores en una base temporal. La temporal se eliminó.
- En la base: ninguna empresa supera su plan en sedes, profesionales, usuarios ni citas del mes; ningún profesional sin cuenta ni sin rol; ningún correo repetido.
- Pruebas de la API:

| Caso | Resultado |
|---|---|
| Barbería (5 de 5 usuarios) registra un profesional | 422 con el mensaje; sigue con 4 |
| Salón (5 de 20) registra un profesional | Se crea, con su rol |
| Salón con el tope de profesionales alcanzado | 422 con el mensaje de profesionales |
| Empresa recién registrada (solo tenía el rol de dueño) registra su primer profesional | Se crea, y con él el rol |
| Ese profesional abre su inicio | Ve solo lo suyo |
| La misma empresa, plan Esencial, sigue registrando | Acepta hasta 5 usuarios y rechaza el siguiente |
| Un profesional del seed entra | Su inicio y su lista de citas muestran solo las suyas |
| Ese profesional intenta cambiar los datos de la empresa | 403 |

- En el navegador, contra la base temporal, con clics reales (Barbería Don Filo):

| Prueba | Resultado |
|---|---|
| Empresa: "Barberos 4 de 5" y "Usuarios del equipo 5 de 5 · Llegaste al tope de tu plan" | Correcto |
| Barberos: los cuatro aparecen con su correo | Correcto |
| "Nuevo Barbero", llenar y "Crear Barbero": el panel sigue abierto y muestra el mensaje del tope; la lista sigue con cuatro | Correcto |

- `php -l` sin errores. `tsc --noEmit` sin errores en `src`.
- No se probó en pantalla: entrar como profesional de cada rubro ni el aviso del tope de profesionales (el de usuarios sí).

## Para aplicar

Resetear con `init.sql`, `seed.sql` y `seed_demo.sql`.

## Corrección del mismo día: el dueño no cuenta

El tope de usuarios del plan es **además del dueño**. Se cambió el conteo (`Tenant::usage()`), que usan el tope al registrar profesionales, la página Empresa ("Usuarios, sin contar al dueño") y la página de la empresa en la consola de la plataforma. El mensaje dice ahora "cuentas de usuario además de la del dueño".

Las pruebas de la API y de pantalla de arriba se hicieron cuando el dueño contaba (barbería en 5 de 5). Con el cambio se repitió por la API: la barbería (4 de 5) registra un quinto profesional y el siguiente se rechaza por el tope.
