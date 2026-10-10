# 2026-10-09 — El seed se separa en dos: lo esencial y los datos de muestra

## Contexto

`seed.sql` mezclaba lo que el sistema necesita para funcionar con las empresas de ejemplo, incluido el SQL que fabrica sus citas, su equipo y sus clientes. Al pasar al servidor habría que recortarlo a mano, con riesgo de borrar de más.

Se separó en dos archivos. **No cambió ningún dato**: los dos juntos dejan lo mismo que el archivo único.

## Cómo queda

| Archivo | Qué trae | Dónde se carga |
|---|---|---|
| `init.sql` | El esquema. Sin cambios. | Siempre |
| `seed.sql` | **Lo esencial**: tipos de documento, permisos, estados, módulos del producto, los tres planes, los diez rubros con sus reglas y su catálogo inicial, embudos, unidades de medida, y los roles y las dos cuentas de la plataforma. No crea ninguna empresa. | Siempre, también en el servidor |
| `seed_demo.sql` | **Datos de muestra**: las once empresas de ejemplo con sus sedes, dueños, suscripciones, servicios, equipo, clientes, mascotas, bloqueos, feriados, citas, y el inventario, paquetes y marketing de ejemplo. | Solo en desarrollo, después de `seed.sql` |

Orden para resetear la base de desarrollo: `init.sql`, `seed.sql`, `seed_demo.sql`.

Para el servidor: `init.sql` y `seed.sql`. Quitar lo de muestra es no cargar el tercer archivo.

### Sobre la "lógica" del seed

Lo que parece lógica (el generador de citas, la copia del catálogo del rubro, las listas de equipo y clientes) está todo en `seed_demo.sql` y solo fabrica datos de prueba. El sistema no depende de eso: una empresa que se registra recibe su catálogo, su ficha y sus feriados desde la API.

### Al desplegar

Las dos cuentas de la plataforma (`admin@tusaas.com` y `soporte@tusaas.com`) están en `seed.sql` con la contraseña de prueba. **En el servidor hay que cambiarla antes de abrir el acceso.** El archivo lo advierte en un comentario.

## Cambios realizados

| Archivo | Contenido |
|---|---|
| `apps/api/infrastructure/docker/postgres/seed.sql` | Queda solo con lo esencial. Los roles, permisos y cuentas de la plataforma, los embudos y las unidades, que estaban en las mismas sentencias que los datos de las empresas de ejemplo, pasaron a sentencias propias. |
| `apps/api/infrastructure/docker/postgres/seed_demo.sql` | Nuevo. Todo lo demás, sin cambios de contenido. |

## Verificación

En bases temporales (ya eliminadas), sin tocar `erp_saas`:

| Prueba | Resultado |
|---|---|
| Archivo único anterior frente a `seed.sql` + `seed_demo.sql`: número de filas de las 53 tablas con datos | Iguales |
| Las 4553 citas, una por una (empresa, código, fase, horas, precio, cliente, servicio, profesional y sede) | 4544 idénticas. Las 9 restantes son la cita que cada empresa tiene a propósito en su próximo feriado: se asigna a un profesional cualquiera y ya variaba entre dos cargas del mismo archivo |
| Solo `init.sql` + `seed.sql`: ninguna empresa, las dos cuentas de la plataforma y 14 tablas de catálogo con datos | Correcto |
| Con solo lo esencial, la plataforma entra y ve empresas (0), planes (3), rubros (10), configuración y módulos (20) | Correcto |
| Con solo lo esencial, se registra una veterinaria nueva: recibe 3 categorías, 7 servicios, su ficha, 28 feriados, su sede y su prueba | Correcto |
| Su dueña abre inicio, empresa, agenda, servicios, profesionales, clientes, sucursales, feriados, bloqueos, controles, lista de espera, fichas y configuración | Todas responden bien |
| Su inicio sin datos: ceros, sin errores | Correcto |
| Registra su primer cliente con mascota | Correcto |

No se probó en pantalla una base con solo lo esencial; se probó por la API.

## Para aplicar

Resetear con `init.sql`, luego `seed.sql`, luego `seed_demo.sql`. Los avances anteriores que dicen "cargar `seed.sql`" se refieren ahora a los dos archivos.

## Ajuste del 2026-10-10: cargar un archivo dos veces o en mal orden

Al resetear, se cargó `seed.sql` dos veces (la segunda con el nombre de `seed_demo.sql`) y falló con un error de clave duplicada en `roles`, difícil de interpretar.

| Caso | Antes | Ahora |
|---|---|---|
| Cargar `seed.sql` dos veces | Error de clave duplicada en `roles`; y las etapas de los embudos podían duplicarse | No hace nada la segunda vez |
| Cargar `seed_demo.sql` sin haber cargado `seed.sql` | Error de clave foránea | Se detiene con: "Faltan los datos esenciales: carga primero seed.sql y luego seed_demo.sql." |
| Cargar `seed_demo.sql` dos veces | Error "more than one row returned by a subquery" | Se detiene con: "Los datos de muestra ya están cargados. Para recargarlos, vuelve a crear la base con init.sql y seed.sql." |

En los tres casos la base queda como estaba: cada archivo corre en una sola transacción. Probado en una base temporal, ya eliminada.
