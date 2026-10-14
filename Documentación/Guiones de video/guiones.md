# Guiones de video por módulo

16 videos de 2 a 3 minutos cada uno, unos 40 minutos en total. Cada guion trae:

- **Cuenta:** con qué cuenta entrar.
- **Antes de grabar:** qué dejar preparado.
- **Guion por tramos de tiempo:**
  - **Pantalla:** qué hacer en la pantalla.
  - **Voz:** qué decir. Está escrita para leerse en voz alta; unas 300 palabras llenan 2 minutos y medio con pausas.
- **No mostrar:** lo que no conviene prometer, porque es simulación o porque aún no existe.

Todas las cuentas usan la contraseña que la pantalla de ingreso trae precargada. Donde la voz dice "el sistema", puedes poner el nombre del producto.

---

## Orden sugerido

| N.º | Video | Cuenta principal | Dura |
|---|---|---|---|
| 1 | Ingreso e Inicio | Centro Médico, DentalPlus, Juliana | 2:30 |
| 2 | Plataforma: las empresas | admin@tusaas.com | 2:45 |
| 3 | Plataforma: planes, rubros y configuración | admin@tusaas.com | 2:45 |
| 4 | Agenda | Centro Médico | 3:00 |
| 5 | Citas, fases y tiempos de atención | Bella Aura o Centro Médico | 2:45 |
| 6 | Lista de espera y controles | Centro Médico | 2:45 |
| 7 | Bloqueos, feriados y horarios del equipo | Centro Médico | 2:45 |
| 8 | Clientes | Centro Médico | 2:30 |
| 9 | Fichas de atención | Centro Médico o DentalPlus | 3:00 |
| 10 | Equipo y catálogo | Centro Médico y Don Filo | 3:00 |
| 11 | Caja y cobrar una cita | Centro Médico | 3:00 |
| 12 | Ventas, pagos, devoluciones y cupones | Centro Médico | 3:00 |
| 13 | Usuarios y roles | Dueño, Karina y Gustavo | 3:00 |
| 14 | Tu empresa: sucursales, empresa y configuración | Centro Médico y DentalPlus | 3:00 |
| 15 | Portal del cliente | Juliana y un cliente nuevo | 3:00 |
| 16 | Notificaciones | Un profesional y la dueña | 2:00 |

---

## Preparación general (una sola vez, antes de grabar todo)

1. **Resetear la base `erp_saas`** con `init.sql`, `seed.sql` y `seed_demo.sql`, en ese orden.
   - Sin esto, Caja, Ventas, Pagos, Cupones, "Cobrar" y Usuarios y roles muestran "Falta actualizar la base de datos…".
   - La muestra crea las citas al azar en cada carga, así que los números cambian. **Resetea una vez y no vuelvas a resetear entre videos.**
2. **Abrir Caja una vez** como dueña de Centro Médico, para que nazcan las tres cajas.
3. **Ensayar los videos 11 y 12 antes de grabarlos.** La muestra no trae ventas, pagos ni turnos de caja, así que necesitas:
   - 2 o 3 citas cobradas;
   - una devolución parcial;
   - un turno cerrado con diferencia.
4. **Llenar y firmar una ficha** antes de los videos 8 y 9. La muestra no trae fichas llenadas.
5. Pantalla en tema claro, ventana grande y zoom del navegador al 100 %. Cierra pestañas y avisos que no sean del sistema.

---

## Video 1 · Ingreso e Inicio (2:30)

**Cuenta:** `admin@luxuryspa.pe` (Centro Médico Integral), luego `admin@dentalplus.pe`, y al final `juliana.diaz@gmail.com`.
**Antes de grabar:** sesión cerrada, en la pantalla de ingreso.

**0:00–0:20**
- **Pantalla:** pantalla de ingreso.
- **Voz:** "Este es el sistema para negocios que trabajan con citas: clínicas, consultorios dentales, veterinarias, salones, barberías y más. Todos entran por la misma puerta: el personal y también sus clientes. Solo se pide correo y contraseña."

**0:20–0:50**
- **Pantalla:** entrar como la dueña de Centro Médico Integral. Se abre el Inicio.
- **Voz:** "Entro como dueña de un centro médico. Lo primero que veo es el Inicio: un saludo, y arriba dos botones para lo que más se hace en el día, 'Nueva cita' y 'Ver citas'."

**0:50–1:30**
- **Pantalla:** recorrer el Inicio: "Próximas citas de hoy", "Ahora mismo", la tarjeta oscura "Citas de hoy", "Últimos 30 días" y "lo más pedido".
- **Voz:** "Aquí está el día de un vistazo. Quién viene ahora y quién ya está esperando o en atención. Cuántas citas hay hoy, comparado con el mismo día de la semana pasada, y qué tan llena está la agenda. Más abajo, cómo nos fue en los últimos 30 días: asistencia, clientes nuevos y los servicios que más se piden."

**1:30–1:55**
- **Pantalla:** bajar a "Por resolver", mover el interruptor "Pendientes" / "Al día" y hacer clic en una línea.
- **Voz:** "Y esta es mi parte favorita: 'Por resolver'. El sistema me junta lo que se quedó pendiente: citas sin confirmar, citas pasadas que nadie cerró, fichas sin firmar, controles vencidos. Un clic y voy directo a resolverlo."

**1:55–2:15**
- **Pantalla:** menú de perfil → "Cambiar de cuenta" → entrar a DentalPlus. Señalar el menú.
- **Voz:** "Cada negocio habla su propio idioma. En el centro médico decimos 'Pacientes' y 'Médicos'; en esta clínica dental, 'Odontólogos'. En una veterinaria serían 'Tutores' y 'Mascotas'. El sistema se adapta solo según el tipo de negocio."

**2:15–2:30**
- **Pantalla:** cerrar sesión → entrar como Juliana → aparece "¿A qué empresa deseas ingresar?" → elegir Centro Médico Integral.
- **Voz:** "Y si un cliente se atiende en dos negocios que usan el sistema, al entrar elige a cuál. Juliana entra a su portal de paciente, que veremos en su propio video."

**No mostrar:** el valor que muestra el Inicio es el precio de las citas atendidas, no el dinero cobrado. No decir "ingresos".

---

## Video 2 · Plataforma: las empresas (2:45)

**Cuenta:** `admin@tusaas.com`.
**Antes de grabar:** ensaya "Entrar al panel" y la suspensión; no se han probado en pantalla.

**0:00–0:20**
- **Pantalla:** Inicio de la plataforma, con sus cuatro accesos.
- **Voz:** "Este video es del lado del dueño del sistema: la consola de la plataforma. Desde aquí se administran todos los negocios que contratan el servicio."

**0:20–0:45**
- **Pantalla:** tabla de empresas. Usar el filtro "Fecha de alta" y "Ordenar por" plan.
- **Voz:** "Aquí están todas las empresas, con su tipo de negocio, su plan y su estado. Puedo filtrar por fecha de alta u ordenar por plan, por ejemplo."

**0:45–1:25**
- **Pantalla:** "Nuevo Tenant". Elegir un rubro (aparece con qué empieza: servicios y ficha), el plan, "empezar en prueba", los datos, la sede principal y el administrador. Crear. Aparece "¡Empresa creada!".
- **Voz:** "Para dar de alta un negocio nuevo lleno un solo formulario. Al elegir el tipo de negocio, el sistema me dice con qué va a empezar: por ejemplo, una barbería nace con seis servicios y su ficha de preferencias de corte. Elijo el plan, si empieza en prueba, su primera sede y quién la administra. Al crear, me muestra la contraseña temporal una sola vez, para entregársela al cliente."

**1:25–2:10**
- **Pantalla:** abrir una empresa: tarjetas Plan, Uso (los anillos) y Módulos. Apagar un módulo.
- **Voz:** "En la página de cada empresa veo su plan y cuándo vence, y cuánto está usando: usuarios, sedes, profesionales y citas del mes. Los anillos se ponen ámbar al acercarse al límite y rojos al llegar. Abajo, sus módulos. Desde aquí puedo apagarle uno; para darle uno que su plan no trae, se le cambia de plan."

**2:10–2:45**
- **Pantalla:** registrar un pago (aparece "Cobros (1)"). Suspender y reactivar.
- **Voz:** "También registro sus pagos y, si deja de pagar, la suspendo: al instante se cierran las sesiones de su personal y de sus clientes. Reactivarla es un clic. Y si soy del equipo de soporte, puedo entrar a su panel para ayudarla, siempre con una franja amarilla que me recuerda que estoy ahí de visita."

**No mostrar:** la bitácora (no tiene pantalla). Tampoco cambiar de plan, cambiar de rubro ni mover el vencimiento sin ensayarlos antes.

---

## Video 3 · Plataforma: planes, rubros y configuración (2:45)

**Cuenta:** `admin@tusaas.com`.

**0:00–0:30**
- **Pantalla:** Planes SaaS.
- **Voz:** "El sistema se vende en tres planes. Esencial, para un negocio con una sede y hasta cinco usuarios. Profesional, hasta tres sedes y veinte usuarios. Y Empresarial, para cadenas. También se pueden armar planes a medida, que no salen en el registro público."

**0:30–1:20**
- **Pantalla:** Plantillas de rubros: tarjetas, columna "Módulos" con su globo. Abrir el rubro dental.
- **Voz:** "Cada tipo de negocio tiene su plantilla: qué módulos usa, cómo se llaman las cosas, qué servicios trae al empezar, qué ficha de atención usa y sus reglas: con cuánta anticipación se reserva, cuándo se puede cancelar y más. Un salón no necesita historia clínica; una clínica sí. Eso se decide aquí."

**1:20–2:00**
- **Pantalla:** "Editar rubro" → cambiar un módulo → guardar. Abrir el panel de empresas: "cambios sin aplicar", en verde lo que gana y en rojo lo que pierde → "Aplicar".
- **Voz:** "Algo importante: cada empresa guarda su propia copia. Si hoy mejoro la plantilla dental, las clínicas que ya trabajan no cambian de golpe. El sistema me muestra qué ganaría y qué perdería cada una, y recién cuando pulso 'Aplicar' se actualizan. Lo que la empresa personalizó no se toca."

**2:00–2:35**
- **Pantalla:** Configuración: elegir el color "Celeste" y guardar (cambia sin recargar). Cerrar el registro de empresas.
- **Voz:** "En Configuración defino la cara del sistema: el nombre del producto, el color, el correo de soporte, cuánto dura una visita de soporte y si cualquiera puede registrar su empresa o solo la doy de alta yo."

**2:35–2:45**
- **Pantalla:** menú de perfil → "Tema" oscuro → volver a claro.
- **Voz:** "Y cada persona elige si trabaja en modo claro u oscuro."

**No mostrar:** la pantalla Contactos (casi no tiene funciones). Vuelve a abrir el registro al terminar.

---

## Video 4 · Agenda (3:00)

**Cuenta:** `admin@luxuryspa.pe`.
**Antes de grabar:** busca un día con huecos libres y una cita pendiente que se pueda arrastrar.

**0:00–0:25**
- **Pantalla:** Agenda en "Hoy".
- **Voz:** "La agenda es el corazón del sistema. Cada columna es un profesional, con su horario debajo del nombre. Lo blanco es cuando atiende, lo gris cuando no, y la línea roja marca la hora actual. Cada cita tiene el color de su estado."

**0:25–0:45**
- **Pantalla:** cambiar de sede, de día e "Ir a una fecha". Clic en una tarjeta de arriba (por ejemplo, "confirmadas").
- **Voz:** "Arriba elijo la sede y el día. Estas tarjetas cuentan las citas del día y, con un clic, me dejan ver solo las pendientes o solo las confirmadas. La agenda se actualiza sola."

**0:45–1:40**
- **Pantalla:** clic en un espacio libre → "Nueva cita" con el médico y la hora puestos. Usar el "+" del cliente para crear un paciente, elegir el servicio (aparecen los horarios libres) y guardar.
- **Voz:** "Para agendar, hago clic en el espacio libre: el profesional y la hora ya vienen puestos. ¿El paciente es nuevo? Con este '+' lo registro sin salir de aquí, y queda elegido. Al escoger el servicio, el sistema me muestra solo los horarios que de verdad están libres."

**1:40–2:15**
- **Pantalla:** arrastrar una cita a otra hora o a otro médico → confirmar. Intentar soltarla sobre un horario ocupado: aparece el motivo.
- **Voz:** "¿Hay que mover una cita? La arrastro. El sistema me confirma de dónde a dónde va. Y si intento ponerla donde ya hay alguien, no me deja y me explica por qué. Aquí nunca se cruzan dos citas del mismo profesional."

**2:15–2:50**
- **Pantalla:** en "Nueva cita", escribir una hora fuera de horario. Aparece el aviso → "Agendar de todos modos" con su motivo.
- **Voz:** "Hay casos especiales, como el doctor que acepta quedarse un rato más. Para eso existe 'Agendar de todos modos', siempre con un motivo que queda registrado. Solo lo pueden hacer la administración y el gerente. Lo que nunca se fuerza son las vacaciones, un feriado o una sede cerrada."

**2:50–3:00**
- **Pantalla:** un día sin horarios → "Anotar en lista de espera".
- **Voz:** "Y si no hay espacio, el paciente queda en la lista de espera. Eso lo vemos en otro video."

**No mostrar:** arrastrar en pantalla táctil (no se probó).

---

## Video 5 · Citas, fases y tiempos de atención (2:45)

**Cuenta:** `admin@bellaaura.pe` (ahí no hay que cobrar antes de atender, así que las fases avanzan sin interrupciones). Si prefieres Centro Médico, cobra la cita antes, como en el video 11.
**Antes de grabar:** una cita confirmada de hoy, cerca de la hora actual.

**0:00–0:30**
- **Pantalla:** Citas: pestañas "Hoy", "Próximos 7 días"… Buscar "ramirez" sin tilde. Abrir "Filtro" → "Fase: Pendiente".
- **Voz:** "La pantalla Citas es la lista de todo lo agendado. La busco por nombre, aunque no ponga tildes, la filtro por estado, profesional o fecha, y la ordeno como necesite."

**0:30–1:20**
- **Pantalla:** abrir una cita. Señalar el camino de fases. Pulsar "Registrar llegada" y luego "Iniciar atención".
- **Voz:** "Cada cita recorre un camino: pendiente, confirmada, llegó, en atención y atendida. O termina como cancelada o no asistió. Los botones cambian según dónde esté. La paciente llegó: lo marco. Pasa al consultorio: inicio la atención."

**1:20–1:50**
- **Pantalla:** volver a la agenda: el cronómetro corre en el bloque de la cita. En el panel, la sección "Tiempos".
- **Voz:** "Desde ese momento corre un cronómetro, también en la agenda. Si se pasa del tiempo asignado se pone en rojo. Así la recepción sabe, sin preguntar, si el doctor va atrasado."

**1:50–2:15**
- **Pantalla:** "Dar por atendida". Leer "La atención duró… min menos de lo asignado". Mostrar el "Historial".
- **Voz:** "Al terminar, el sistema me dice cuánto duró de verdad y, si terminó antes, libera el resto del horario. Todo queda en el historial: qué se hizo, quién y cuándo. Cancelar siempre pide un motivo."

**2:15–2:45**
- **Pantalla:** Tiempos de atención, periodo de 30 días: tarjetas y tablas por profesional, servicio y cliente.
- **Voz:** "Con todo eso se arma este reporte: cuánto dura de verdad cada servicio, cuánto esperan los pacientes y quién es más ágil. Sirve para ajustar las duraciones y no sobrecargar la agenda."

**No mostrar:** la marca "Sin confirmar a tiempo" en la agenda (solo aparece en la tabla de Citas y en el panel).

---

## Video 6 · Lista de espera y controles (2:45)

**Cuenta:** `admin@luxuryspa.pe`.

**0:00–0:20**
- **Pantalla:** Lista de espera, pestaña "En espera".
- **Voz:** "¿Qué pasa cuando un paciente quiere una cita y no hay espacio? En vez de perderlo, lo anotamos en la lista de espera."

**0:20–0:55**
- **Pantalla:** "Anotar en la lista": cliente, servicio, sede, franja "Mañana" e interruptor "Prioritario". Guardar. Señalar la columna "Puesto" y las tarjetas.
- **Voz:** "Anoto qué necesita, en qué sede, si prefiere mañana o tarde, e incluso si espera a un doctor en particular. Los prioritarios van primero; los demás, por orden de llegada."

**0:55–1:30**
- **Pantalla:** "Buscar horario" → elegir una hora → se abre "Nueva cita" ya llena → guardar. La persona pasa a "Con cita".
- **Voz:** "Con 'Buscar horario', el sistema revisa las próximas tres semanas y me trae los espacios que le sirven a esa persona. Elijo uno, la cita se llena sola, y el paciente pasa a 'Con cita'. Si luego cancela, vuelve a la lista sin perder su puesto."

**1:30–1:45**
- **Pantalla:** cancelar otra cita: aparece "Este horario le sirve a N personas de la lista de espera".
- **Voz:** "Y cuando alguien cancela, el sistema me avisa si ese horario le sirve a alguien de la lista."

**1:45–2:45**
- **Pantalla:** Controles y seguimiento: tarjetas "Vencidos" y "Hoy y próximos 7 días". Buscar "presion". "Agendar" en uno vencido. Mostrar "Nuevo control" con sus atajos.
- **Voz:** "La otra cara es el seguimiento. Hay atenciones que piden volver: un control de presión en un mes, una limpieza en seis meses. El control nace solo, al firmar la ficha o al terminar un servicio que lo tiene, y también se puede crear a mano. Aquí veo cuáles ya vencieron y cuáles vienen esta semana. Llamo, marco que lo avisé y, con 'Agendar', la cita se llena sola. Así ningún paciente se olvida de volver."

**No mostrar:** el sistema no envía mensajes al paciente. Las llamadas y los avisos se hacen por fuera y aquí se marcan.

---

## Video 7 · Bloqueos, feriados y horarios del equipo (2:45)

**Cuenta:** `admin@luxuryspa.pe`.

**0:00–0:45**
- **Pantalla:** Horarios del equipo: pestañas por sede, línea de tiempo por persona. Lápiz → "Horario de…" → "Agregar tramo" → guardar. Provocar "El martes tiene tramos que se cruzan".
- **Voz:** "Todo empieza por el horario de cada profesional, sede por sede. Aquí lo veo como una línea de tiempo, con el horario de la sede de fondo. Lo edito por día y por tramos, y el sistema no deja que dos tramos se crucen, ni siquiera entre sedes distintas."

**0:45–1:40**
- **Pantalla:** Bloqueos y ausencias. Abrir las vacaciones de la Dra. Gómez: "N por resolver" → la lista de citas.
- **Voz:** "Cuando alguien sale de vacaciones, tiene un permiso médico o la sede cierra por mantenimiento, se registra aquí, siempre con un motivo. Mientras lo lleno, el sistema me avisa cuántas citas ya quedan dentro. Ojo: no las cancela solo. Me las deja como 'por resolver' para que las reprograme o avise al paciente. Nadie se entera tarde."

**1:40–2:00**
- **Pantalla:** "Nuevo bloqueo" de tipo "Bloqueo de horario".
- **Voz:** "Hay un tipo más suave, el 'bloqueo de horario', para una reunión interna, por ejemplo. Ese sí se puede saltar por una urgencia, con motivo."

**2:00–2:45**
- **Pantalla:** pestaña Feriados: la franja para cargar los feriados nacionales y "Agregar feriado" con "La sede cierra todo el día". Luego la agenda del 8 de diciembre: "No atiende · Inmaculada Concepción".
- **Voz:** "Y los feriados: con un clic cargo los nacionales del año. Si la sede cierra, la agenda lo muestra y nadie puede reservar ese día. Si el negocio decide atender igual, lo marco y listo."

---

## Video 8 · Clientes (2:30)

**Cuenta:** `admin@luxuryspa.pe`.
**Antes de grabar:** llena y firma una ficha de un paciente (video 9) y anota quién tiene una nota de inasistencias en línea.

**0:00–0:30**
- **Pantalla:** Pacientes: ordenar por Apellido, filtro "Entre dos fechas", pasar de página.
- **Voz:** "Aquí están todos los pacientes del negocio. Como en todas las tablas del sistema, puedo buscar, filtrar por fecha, ordenar y pasar de página. Cada negocio ve solo a sus clientes: ni otra empresa ni nosotros, como plataforma, podemos verlos."

**0:30–1:15**
- **Pantalla:** clic en un nombre → página del paciente: datos, tarjetas, "Historia de atenciones" y citas. Clic en la tarjeta "Atenciones".
- **Voz:** "Cada paciente tiene su página: sus datos, cuántas veces se atendió, sus citas próximas, sus faltas, cuánto tarda en promedio su atención y su historia de atenciones. Las tarjetas también filtran: toco 'Atenciones' y veo solo las citas atendidas."

**1:15–1:35**
- **Pantalla:** abrir el lápiz: "Tipo de sangre", "Antecedentes".
- **Voz:** "En los negocios de salud se guardan datos clínicos como el tipo de sangre y los antecedentes. En un salón o una barbería esos campos ni aparecen."

**1:35–2:05**
- **Pantalla:** bloque "Acceso al portal": apagar y encender el interruptor. "Olvidó su contraseña" → pasa a "Aún no ingresa".
- **Voz:** "Desde aquí también manejo su acceso al portal. Entra con su correo y, la primera vez, con su documento como contraseña; luego crea la suya. Si la olvida, la devuelvo al documento con un clic."

**2:05–2:30**
- **Pantalla:** un paciente con "1 inasistencia en línea" bajo el nombre; pasar el cursor.
- **Voz:** "Y si un paciente reserva por internet y no viene, queda anotado. El negocio decide si solo deja una nota o si, tras varias faltas, ya no puede reservar en línea por un tiempo. Igual el personal le puede agendar por teléfono."

**No mostrar:** no pulses "Perdonar" en cámara; solo se probó por detrás.

---

## Video 9 · Fichas de atención (3:00)

**Cuenta:** `admin@luxuryspa.pe`. Para mostrar el odontograma, `admin@dentalplus.pe`.
**Antes de grabar:** una cita en atención sin ficha; si vas a mostrar el acceso de emergencia, la empresa con ficha privada y una ficha de otro autor.

**0:00–0:50**
- **Pantalla:** Fichas de atención → "Plantillas de ficha". Renombrar un campo, ponerlo "Obligatorio", "Agregar campo" de escala → "Guardar ficha".
- **Voz:** "Cada servicio puede tener su ficha de atención: la historia que llena el profesional. El negocio arma su ficha como una hoja: nombra los campos, decide cuáles son obligatorios y elige el tipo: texto, número, sí o no, una escala del cero al diez, un odontograma o el próximo control. Si cambio la plantilla, las fichas ya llenadas no se tocan."

**0:50–1:10**
- **Pantalla:** abrir la cita en atención → "Dar por atendida" → aviso de ficha firmada.
- **Voz:** "En un centro médico no se puede cerrar una consulta sin su ficha firmada. Si lo intento, el sistema me lo recuerda."

**1:10–1:55**
- **Pantalla:** "Ficha de atención · Pendiente". Llenar a medias → firmar → "Faltan 2 datos para firmar". Completar (escala, odontograma si es dental), aceptar el consentimiento y firmar.
- **Voz:** "Abro la ficha. Puedo guardarla como borrador y seguir luego. Si intento firmar y falta algo obligatorio, me marca exactamente qué. La completo, acepto el consentimiento y firmo. Abajo veo además sus atenciones anteriores, para no empezar de cero."

**1:55–2:20**
- **Pantalla:** ficha "Firmada" con su plazo. Agregar una nota. "Descargar" el PDF.
- **Voz:** "Una vez firmada, hay 24 horas para corregir. Después queda cerrada y solo se le agregan notas, como en una historia clínica de verdad. Y se descarga en PDF."

**2:20–3:00**
- **Pantalla:** como dueña, abrir una ficha privada de otro profesional: "Esta ficha es privada" → escribir el motivo → "Abrir la ficha".
- **Voz:** "Algunos negocios, como los de psicología, necesitan fichas privadas que solo lee su autor. ¿Y si hay una emergencia? La administración puede abrirla, pero tiene que escribir el motivo. Se abre solo para leer, por media hora, y queda registrado quién la abrió, cuándo y por qué. El profesional lo ve."

**No mostrar:** no hay firma digital del paciente; el PDF trae líneas para firmar a mano. Revisa cómo se ve el PDF antes de mostrarlo.

---

## Video 10 · Equipo y catálogo (3:00)

**Cuenta:** `admin@luxuryspa.pe`; para el tope del plan, `admin@donfilo.pe`.

**0:00–0:50**
- **Pantalla:** Servicios: ordenar por precio. "Nuevo servicio" con "Nueva categoría". Abrir "Reserva y seguimiento".
- **Voz:** "Empecemos por lo que el negocio ofrece. Cada servicio tiene su precio, su duración y su categoría. Aquí también decido si se puede reservar por internet y si deja un control de seguimiento: por ejemplo, una extracción deja un control a los siete días. Al registrarse, cada negocio ya trae los servicios típicos de su rubro."

**0:50–1:35**
- **Pantalla:** Médicos: tarjeta "Médicos de 20 del plan: 8". Clic en un nombre → su página: tarjetas, horario de la semana con hoy resaltado, ausencias.
- **Voz:** "Luego, el equipo. Cada profesional tiene su página: sus servicios, su horario de la semana, sus ausencias, sus citas de hoy y cuánto tarda en promedio. Cada profesional tiene su propia cuenta y solo ve lo suyo."

**1:35–2:00**
- **Pantalla:** entrar como Don Filo → Barberos: botón con candado y su globo.
- **Voz:** "La tarjeta de arriba siempre muestra cuántos llevo frente al límite del plan. Esta barbería tiene cinco de cinco, así que el botón se bloquea y explica por qué: para agregar otro, hay que subir de plan."

**2:00–3:00**
- **Pantalla:** volver a Centro Médico → Salas y equipos: "Ecógrafo · En Consultorio 201". Ir a Configuración → "Salas y equipos" → "Dejar la sala fuera de uso". Volver, editar el Ecógrafo y apagar "En uso": el consultorio pasa a "Fuera de uso". Encenderlo de nuevo.
- **Voz:** "Por último, las salas y los equipos. Registro cada consultorio y dentro de él sus equipos, como este ecógrafo. ¿Qué pasa si el equipo se malogra? Cada negocio decide: que no pase nada, que se anote como observación, o que la sala completa quede fuera de uso mientras se repara. Cuando el equipo vuelve, la sala vuelve sola. Y la campana avisa a la administración."

**No mostrar:** las salas todavía no se eligen al agendar; por ahora son un inventario. Tampoco hay servicios distintos por sede.

---

## Video 11 · Caja y cobrar una cita (3:00)

**Cuenta:** `admin@luxuryspa.pe`.
**Antes de grabar:** todas las cajas cerradas y una cita de Miraflores confirmada, con precio y sin cobrar.

**0:00–0:30**
- **Pantalla:** Caja: una tarjeta por sede, "0 de 3 cajas abiertas". "Abrir caja" en Miraflores con S/ 100.
- **Voz:** "Cada sede tiene su caja, y se crea sola. Al empezar el día la abro y digo con cuánto sencillo empiezo, por ejemplo, cien soles."

**0:30–0:50**
- **Pantalla:** abrir la cita confirmada → "Iniciar atención" → "Esta cita aún no está pagada. Cóbrala antes de iniciar la atención."
- **Voz:** "Como en la mayoría de clínicas, aquí se paga antes de pasar al consultorio. Si intento iniciar la atención sin cobrar, el sistema me lo recuerda. El negocio puede apagar esta regla; en salones y barberías ya viene apagada."

**0:50–1:45**
- **Pantalla:** tarjeta "Cobrar" → panel: IGV y total. Efectivo, "Recibido" 100: muestra el vuelto. Comprobante Boleta → Cobrar. Aparece la boleta B001 con la nota de simulación.
- **Voz:** "Toco 'Cobrar'. Veo el detalle con su IGV. El paciente paga en efectivo con cien soles y el sistema me calcula el vuelto. Si paga con tarjeta, Yape o Plin, anoto el número de operación. Elijo boleta o factura y listo: la venta queda pagada y sale su comprobante. Por ahora es una simulación: todavía no se envía a SUNAT, y la pantalla lo dice claramente."

**1:45–2:00**
- **Pantalla:** "Iniciar atención" ahora sí funciona.
- **Voz:** "Ahora sí, la atención puede empezar."

**2:00–2:30**
- **Pantalla:** Caja → "Movimiento": retiro de S/ 500 → "En la caja no hay tanto efectivo." Luego un gasto de S/ 15. "Ver turno".
- **Voz:** "Durante el día puedo anotar otros movimientos: un gasto pagado con la caja o un retiro. Si intento sacar más de lo que hay, no me deja. En 'Ver turno' veo lo cobrado por cada medio de pago."

**2:30–3:00**
- **Pantalla:** "Cerrar caja": escribir lo contado → "La caja cuadra." o "Faltan S/ 5.00" con observación. Tabla "Turnos cerrados".
- **Voz:** "Al final del día cuento el dinero y lo escribo. El sistema ya sabe cuánto debería haber y me dice si cuadra. Si falta algo, tengo que explicarlo. Cada cierre queda guardado aquí, con quién abrió, quién cerró y el resultado."

**No mostrar:** no hay punto de venta sin cita ni carrito, ni pago por internet. No prometer SUNAT.

---

## Video 12 · Ventas, pagos, devoluciones y cupones (3:00)

**Cuenta:** `admin@luxuryspa.pe`.
**Antes de grabar:** ventas ya cobradas (video 11) y una cita confirmada sin cobrar para usar el cupón.

**0:00–0:30**
- **Pantalla:** Ventas: pestañas y tarjetas "Vendido hoy", "Por cobrar", "Devuelto".
- **Voz:** "Cada cobro crea una venta. Aquí las veo todas: las pagadas, las que tienen alguna devolución y las anuladas. Arriba, cuánto se vendió hoy y cuánto se devolvió."

**0:30–1:15**
- **Pantalla:** abrir una venta pagada → "Devolver" 15, motivo, Efectivo → "Devolución de S/ 15.00 registrada" → "Devuelta en parte" y la nota de crédito BC01.
- **Voz:** "Si hay que devolver dinero, abro la venta y pulso 'Devolver'. Puede ser todo o una parte, siempre con motivo. Si se devuelve en efectivo, sale de la caja abierta. Se genera su nota de crédito, también simulada. ¿Y anular? Solo se anula una venta en la que aún no entró dinero; si ya se cobró, se devuelve. Estas dos acciones son solo para la administración y el gerente."

**1:15–1:35**
- **Pantalla:** Pagos → pestaña "Devoluciones": el −S/ 15.00 en rojo. Tarjeta "Neto de hoy".
- **Voz:** "En Pagos veo cada movimiento de dinero: cobros y devoluciones, con su medio de pago y quién lo registró. El neto del día, a la vista."

**1:35–2:15**
- **Pantalla:** Cupones: los tres de muestra y la pestaña "Ya no valen". "Nuevo cupón": escribir "promo 15!" (queda "PROMO15"), 15 %, 1 uso.
- **Voz:** "Y los cupones de descuento. Creo uno: el código, si descuenta un porcentaje o un monto fijo, una compra mínima, hasta cuándo vale y cuántas veces se puede usar."

**2:15–3:00**
- **Pantalla:** cobrar una cita → cupón "aniversario" → "Ese cupón ya venció." → PROMO15 → "− S/ 6.75" → cobrar. Volver a Cupones: "1 de 1 · Agotado".
- **Voz:** "Al cobrar, el paciente me da su cupón. Si ya venció, el sistema me lo dice. Si vale, descuenta y recalcula el total, y el comprobante sale por el monto final. Como este tenía un solo uso, ahora aparece agotado. Si alguien intenta usarlo otra vez, no pasa."

**No mostrar:** no hay descuento general, propinas ni puntos.

---

## Video 13 · Usuarios y roles (3:00)

**Cuentas:** `admin@luxuryspa.pe`, `karina.lozano@luxuryspa.pe` (Caja) y `gustavo.paredes@luxuryspa.pe` (Gerente de San Borja).

**0:00–0:35**
- **Pantalla:** Usuarios y roles → pestaña Usuarios: tarjeta de cuentas frente al plan. Pestaña Roles: los cinco roles y "22 de 63".
- **Voz:** "No todos en un negocio deben ver lo mismo. La recepcionista no necesita la configuración y la cajera no tiene por qué borrar pacientes. Por eso cada persona tiene un rol. Vienen cinco listos: Administrador, Gerente de sede, Recepción, Caja y Profesional. Y el negocio puede crear los suyos."

**0:35–1:20**
- **Pantalla:** abrir a Rosa (Recepción): rejilla. Tocar el nombre de una pantalla para leer qué permite. Apagar "Crear" en Agenda (marca "Quitado") y encender "Eliminar" en Pacientes (marca "Agregado").
- **Voz:** "Cada rol es una lista de permisos: ver, crear, editar y eliminar, pantalla por pantalla, explicados en palabras simples. Y si una persona necesita algo distinto, se lo cambio solo a ella sin tocar al resto: a Rosa le quito agendar y le doy eliminar pacientes. El sistema marca lo que es distinto a su rol."

**1:20–1:35**
- **Pantalla:** columna "Especial": "Forzar horario", "Perdonar faltas", "Acceso de emergencia".
- **Voz:** "Hay permisos especiales, como agendar fuera de horario o abrir una ficha privada en una emergencia."

**1:35–2:10**
- **Pantalla:** entrar como Karina: menú corto, Pacientes sin "Nuevo", en la cita solo "Cobrar". Escribir `/users` en la barra: "no tiene acceso".
- **Voz:** "Así lo ve Karina, de caja: su menú solo trae lo que usa. Ve a los pacientes, pero no los crea; en una cita solo puede cobrar. Y si intenta entrar escribiendo la dirección de otra pantalla, el sistema le dice que su rol no la incluye."

**2:10–3:00**
- **Pantalla:** entrar como Gustavo: aviso "Ves al personal de tu sede…". "Nuevo usuario" con la sede ya puesta. Caja y Agenda muestran solo San Borja.
- **Voz:** "Y Gustavo, gerente de San Borja. Él ve solo su sede: su agenda, su caja, sus ventas y su personal. Puede contratar a una recepcionista para su sede sin molestar al dueño, pero no puede crear administradores ni ver otras sedes. Los pacientes sí son de toda la empresa, porque un paciente puede atenderse en cualquier sede."

**No mostrar:** a Rosa navegando la entrega 2 ni el panel de una venta sin permiso de cobrar; no se probaron en pantalla.

---

## Video 14 · Tu empresa: sucursales, empresa y configuración (3:00)

**Cuentas:** `admin@luxuryspa.pe`; para crear una sede, `admin@dentalplus.pe`.

**0:00–0:30**
- **Pantalla:** Empresa: tarjetas de datos, uso del plan y código de empresa.
- **Voz:** "En 'Tu empresa' está lo del negocio mismo. Aquí veo sus datos, mi plan y cuánto de él estoy usando: sedes, profesionales y usuarios. Y este código de ocho dígitos es el que mis clientes usan para registrarse."

**0:30–1:20**
- **Pantalla:** Sucursales: tarjetas ("3 de 3", "Abren hoy"…). Abrir San Borja: horario, quiénes atienden aquí, salas. Reloj → cerrar el lunes a las 17:00 → avisa qué horarios quedan fuera.
- **Voz:** "Cada sucursal tiene su página: su horario, quiénes atienden ahí y sus salas. Si cambio el horario de la sede, el sistema me avisa qué turnos del equipo quedan fuera. Una sede no se borra: se desactiva, y solo si no tiene citas pendientes."

**1:20–1:35**
- **Pantalla:** "Nueva sucursal" en Centro Médico explica el tope del plan.
- **Voz:** "Este centro ya tiene las tres sedes de su plan; para abrir otra, tendría que subir de plan."

**1:35–3:00**
- **Pantalla:** Configuración, sección por sección:
  - Módulos: apagar uno, guardar y ver que desaparece del menú.
  - Vocabulario y Fases de la cita.
  - Reglas de reserva: "Avisar y luego liberar".
  - Ventas y caja: "Cobrar antes de atender".
  - "Si reserva en línea y no asiste".
- **Voz:** "Y Configuración: aquí cada negocio ajusta el sistema a su forma de trabajar. Puede apagar los módulos que no usa. Puede renombrar las cosas, por ejemplo que 'Llegó' diga 'En sala'. Decide con cuántas horas debe confirmarse una cita y qué pasa si no se confirma: solo avisar, o liberar el espacio para otro paciente. Decide si se cobra antes de atender, si los precios incluyen IGV y qué hacer con quien reserva por internet y no viene. Todo viene con el valor más común, y el negocio lo cambia si lo necesita."

**No mostrar:** sin ensayo, no apagues y vuelvas a encender módulos que tengan datos.

---

## Video 15 · Portal del cliente (3:00)

**Cuentas:** un paciente que nunca entró (su documento es la contraseña inicial) y `juliana.diaz@gmail.com`.
**Antes de grabar:** decide qué paciente usar para el primer ingreso y ten su documento a mano.

**0:00–0:30**
- **Pantalla:** entrar con el paciente nuevo: solo aparece "Crea tu contraseña". Crearla.
- **Voz:** "Los clientes también tienen su espacio: el portal. La primera vez entran con su correo y su número de documento, y el sistema les pide crear su propia contraseña."

**0:30–1:30**
- **Pantalla:** "Reservar": servicio → sede → "Cualquiera disponible" → elegir un domingo ("No hay horarios libres", ir al próximo día con horarios) → hora de la mañana → "Revisa y confirma" con "Llega 15 minutos antes…" → reservar.
- **Voz:** "Reservar es paso a paso: qué servicio, en qué sede, con quién (o con cualquiera disponible) y cuándo. Solo aparecen los horarios realmente libres, separados en mañana, tarde y noche. Si el día está lleno, el sistema le lleva al próximo con espacio. Al final ve un resumen, con el recordatorio de llegar antes para pagar."

**1:30–2:15**
- **Pantalla:** entrar como Juliana → Mis citas: "Confirma tu asistencia antes del…" → "Confirmar asistencia". "Cambiar fecha". "Filtrar" → Estado.
- **Voz:** "En 'Mis citas', el paciente ve sus próximas citas y las anteriores. Confirma su asistencia con un clic, cambia la fecha o cancela, hasta cierto número de horas antes, según lo que decida el negocio. Si no confirma a tiempo, el negocio puede liberar ese espacio."

**2:15–2:45**
- **Pantalla:** en "¿Cuándo?", "¿Ningún horario te sirve? Anótate en la lista de espera" → "Por la mañana", "Las próximas 2 semanas". En Mis citas: "En lista de espera" con horas en verde.
- **Voz:** "¿No encuentra horario? Se anota solo en la lista de espera, diciendo cuándo le acomoda. Cuando se libera algo que le sirve, lo ve aquí mismo y lo toma con un clic."

**2:45–3:00**
- **Pantalla:** "Mis datos".
- **Voz:** "Y en 'Mis datos' actualiza su celular o su contraseña. El paciente nunca ve notas internas ni fichas: solo lo suyo."

**No mostrar:** no hay pago por internet ni mensajes por correo o WhatsApp. No grabes en celular sin probarlo antes.

---

## Video 16 · Notificaciones (2:00)

**Cuentas:** un profesional de Centro Médico (por ejemplo `carlos.perez@luxuryspa.pe`) y `admin@luxuryspa.pe`.

**0:00–0:40**
- **Pantalla:** entrar como el profesional: la campana con su número → abrirla → clic en "Te agendaron una cita": se abre la cita y el número baja.
- **Voz:** "La campana avisa al equipo de lo importante, sin que tenga que estar revisando. Al doctor le llega cuando le agendan, le mueven o le cancelan una cita, cuando un paciente confirma desde el portal o cuando tiene vacaciones cerca. Un clic en el aviso y abre la cita."

**0:40–1:20**
- **Pantalla:** entrar como la dueña → campana → aviso de ausencia → lleva a Bloqueos y ausencias.
- **Voz:** "La administración recibe otro tipo de avisos: cancelaciones, lo que hacen los pacientes desde el portal, un horario liberado que le sirve a alguien de la lista de espera o una sala que quedó fuera de uso. Si hay varias citas sin confirmar, llega un solo aviso por día, para no llenar la campana."

**1:20–2:00**
- **Pantalla:** "Ver todas" → "Sin leer" → "Marcar todo como leído" → "Estás al día".
- **Voz:** "Aquí está el historial completo. Y una regla simple: nadie recibe aviso de lo que hizo él mismo. Si yo agendo la cita, no me avisa a mí; le avisa al doctor."

**No mostrar:** los avisos son dentro del sistema; no hay correo, WhatsApp ni notificaciones al celular.

---

## Consejos para grabar

- Graba cada video en tomas cortas, una por tramo de tiempo. Es más fácil repetir 30 segundos que 3 minutos.
- Mueve el mouse despacio y espera un segundo después de cada clic antes de hablar de lo que apareció.
- Si algo falla en cámara, anótalo: probablemente es un pendiente real del sistema.
- Ensaya primero los tramos que este documento marca como no probados en pantalla.
