# 2026-10-09 — Una empresa de ejemplo por cada rubro y el salón sin Clases

## Contexto

Dos pedidos:

1. Que el seed tenga al menos una empresa por cada rubro. Faltaban cinco: salón, barbería, estética, fitness y veterinaria. Cada una con una sola sucursal y con servicios, profesionales, clientes y citas como las demás.
2. Quitar Clases del rubro de belleza (salón).

> Después de este avance el seed creció (más servicios, más equipo y más clientes por empresa): ver `2026-10-09-mas-servicios-y-equipo-y-actualizar-ficha.md`. Las cantidades de equipo, clientes y citas de este documento son las de ese momento.

Solo cambia `seed.sql`. No hay cambios de esquema ni de código. **Requiere volver a cargar el seed** (resetear con `init.sql` y `seed.sql`).

## 1. Empresas nuevas

| Empresa | Rubro | Plan | Sede | Dueño (correo) |
|---|---|---|---|---|
| Salón Bella Aura | Salón de belleza y spa | Profesional | Local Barranco | `admin@bellaaura.pe` |
| Barbería Don Filo | Barbería | Esencial | Local Magdalena | `admin@donfilo.pe` |
| Centro de Estética Lumina | Centro de estética | Profesional (en prueba) | Sede San Isidro | `admin@lumina.pe` |
| Estudio Prana Yoga y Pilates | Estudio fitness | Profesional | Estudio Miraflores | `admin@prana.pe` |
| Veterinaria Huellitas | Veterinaria | Esencial | Sede Jesús María | `admin@huellitas.pe` |

Todas entran con la misma contraseña de prueba que las demás cuentas del seed.

Lo que trae cada una:

| Qué | Detalle |
|---|---|
| Catálogo | El **catálogo completo de su rubro**: categorías, servicios (con código, adelanto, reserva en línea y control de seguimiento) y su plantilla de ficha enlazada a los servicios que la usan. Es lo mismo que recibe una empresa al registrarse. |
| Equipo | Dos personas: una de lunes a viernes en dos tramos (09:00–13:00 y 15:00–19:00) y otra de lunes a sábado de 08:00 a 14:00. |
| Sede | Dirección, teléfono, horario de atención (lunes a viernes de 08:00 a 20:00, sábados hasta las 14:00) y una o dos salas o equipos. |
| Clientes | Cuatro, con acceso al portal por su número de documento. |
| Ausencia | Un "Descanso médico" de dos días la semana siguiente para la segunda persona, con citas dentro. |
| Feriados | Los nacionales de este año y del siguiente. |
| Citas | Entre 116 y 149: tres semanas atrás y cuatro adelante, con una en el próximo feriado. |

Por qué esos planes: barbería y veterinaria van en el Esencial (una sede), así vuelve a haber empresas en ese plan; la barbería conserva ahí la Cola sin cita. Salón, estética y fitness van en el Profesional, que es el que trae inventario, puntos, membresías y clases.

### Particularidades

- **Veterinaria.** Los cuatro tutores tienen seis mascotas en total y **todas las citas son para una mascota**. La agenda las muestra por el nombre de la mascota.
- **Fitness.** El catálogo trae tres clases grupales (Yoga, Pilates mat, Funcional). Las citas de demostración usan solo los servicios individuales (Clase privada y Evaluación física): las clases grupales no son citas y esperan a su módulo.
- **Salón y veterinaria tienen servicios largos** (coloración de 2 a 3 horas, esterilización de 2 horas). El generador de citas ahora descarta una cita si se sale del tramo del profesional o si pisa la anterior. Las seis empresas que ya existían conservan exactamente las mismas citas.

## 2. Clases fuera del salón

`classes` se agregó a los módulos que el rubro "Salón de belleza y spa" no usa. El salón ya no ve Clases en el menú ni en la lista de módulos de su configuración.

Solo se cambió en el salón. Estética ya lo tenía apagado. **La barbería lo conserva** (con plan Profesional o superior).

Una empresa de salón creada antes de este cambio lo recibe al recargar el seed o cuando la plataforma le aplique el rubro.

## 3. Cambios realizados

| Archivo | Contenido |
|---|---|
| `apps/api/infrastructure/docker/postgres/seed.sql` | Cinco empresas con su configuración, suscripción, razón social, contacto, rol y usuario dueño, sede, salas, catálogo del rubro, equipo con horario, clientes, mascotas y ausencia. El generador de citas asigna la mascota y respeta el tramo. `classes` apagado en el salón. |

## 4. Verificación

- `init.sql` y `seed.sql` cargan sin errores en una base temporal. La temporal se eliminó.
- En la base: 11 empresas, al menos una por cada uno de los 10 rubros; ninguna cita cruzada (ni de un profesional ni de un cliente); ninguna cita fuera del horario de su profesional; ningún horario fuera del horario de su sede; ninguna cita con una clase grupal; ninguna mascota en una cita de otro tutor; las 133 citas de la veterinaria tienen mascota; todos los clientes tienen acceso al portal.
- API, con el dueño de cada empresa nueva: contexto, agenda del día, citas, servicios, profesionales, clientes, sucursales, bloqueos, feriados, controles, lista de espera, plantillas de ficha y configuración responden bien. Los horarios libres descuentan las citas del seed. El detalle de una cita de la veterinaria trae su mascota.

| Empresa | Clases | Cola sin cita |
|---|---|---|
| Salón Bella Aura | No | No |
| Barbería Don Filo | No (plan Esencial) | Sí |
| Centro de Estética Lumina | No | No |
| Estudio Prana | Sí | No |
| Veterinaria Huellitas | No | No |

- En el navegador, contra la base temporal, con clics reales:

| Prueba | Resultado |
|---|---|
| Veterinaria: el menú dice Tutores y Veterinarios | Correcto |
| Veterinaria: la agenda de hoy muestra seis citas con el nombre de la mascota | Correcto |
| Veterinaria: "Nueva cita" — elegir tutora, aparece "Mascota", elegir Mishi, elegir Vacunación, tomar las 16:00 de los horarios libres y agendar | La cita aparece en la agenda y el contador pasa de 6 a 7 |
| Veterinaria: Tutores lista las mascotas de cada uno; Servicios muestra los siete del rubro; Bloqueos muestra el descanso con citas por resolver | Correcto |
| Salón: el menú no tiene Clases; la configuración no la lista entre sus módulos; agenda con seis citas; diez servicios | Correcto |
| Fitness: el menú tiene Clases, Alumnos e Instructores; la agenda solo tiene clases privadas y evaluaciones | Correcto |
| Barbería: el menú tiene Cola sin cita y Barberos; Sucursales muestra "1 de 1" y "Nueva sucursal" explica que el plan incluye una sola | Correcto |

- No se probó en pantalla: el Centro de Estética (sus datos se revisaron por la API) ni el portal de un cliente de estas empresas.

## 5. Para aplicar

Resetear el esquema con `init.sql` y cargar `seed.sql`.
