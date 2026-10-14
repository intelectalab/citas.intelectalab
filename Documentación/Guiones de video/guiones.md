# Guiones de video por módulo

16 videos de 2 a 3 minutos cada uno, unos 44 minutos en total. Cada guion trae:

- **Cuenta:** con qué cuenta entrar.
- **Antes de grabar:** qué dejar preparado.
- **Guion por tramos de tiempo:**
  - **Pantalla:** qué hacer en la pantalla.
  - **Voz:** qué decir. Está escrita para leerse en voz alta con un tono claro y profesional; unas 300 palabras llenan 2 minutos y medio con pausas.
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
- **Voz:** "Este sistema está pensado para negocios que trabajan con citas: clínicas, consultorios dentales, veterinarias, salones de belleza y barberías, entre otros. El personal y los clientes ingresan por la misma pantalla, solo con su correo y su contraseña."

**0:20–0:50**
- **Pantalla:** entrar como la dueña de Centro Médico Integral. Se abre el Inicio.
- **Voz:** "Ingresamos como administradora de un centro médico. La primera pantalla es el Inicio. En la parte superior están las dos acciones más frecuentes del día: 'Nueva cita' y 'Ver citas'."

**0:50–1:30**
- **Pantalla:** recorrer el Inicio: "Próximas citas de hoy", "Ahora mismo", la tarjeta oscura "Citas de hoy", "Últimos 30 días" y "lo más pedido".
- **Voz:** "El Inicio resume la jornada. Muestra las próximas citas del día y qué pacientes están esperando o en atención en este momento. Indica cuántas citas hay hoy, las compara con el mismo día de la semana anterior y muestra qué tan ocupada está la agenda. Más abajo aparecen los resultados de los últimos 30 días: la asistencia, los clientes nuevos y los servicios más solicitados."

**1:30–1:55**
- **Pantalla:** bajar a "Por resolver", mover el interruptor "Pendientes" / "Al día" y hacer clic en una línea.
- **Voz:** "La sección 'Por resolver' reúne lo que requiere atención: citas sin confirmar, citas pasadas que no se cerraron, fichas sin firmar y controles vencidos. Al hacer clic en cada punto, el sistema lleva directamente a la pantalla donde se resuelve."

**1:55–2:15**
- **Pantalla:** menú de perfil → "Cambiar de cuenta" → entrar a DentalPlus. Señalar el menú.
- **Voz:** "El sistema adapta sus términos a cada tipo de negocio. En un centro médico se habla de 'Pacientes' y 'Médicos'; en una clínica dental, de 'Odontólogos'; y en una veterinaria, de 'Tutores' y 'Mascotas'."

**2:15–2:30**
- **Pantalla:** cerrar sesión → entrar como Juliana → aparece "¿A qué empresa deseas ingresar?" → elegir Centro Médico Integral.
- **Voz:** "Si un cliente se atiende en dos negocios que usan el sistema, al ingresar elige a cuál desea entrar. En este caso, la paciente accede a su portal, que se presenta en un video aparte."

**No mostrar:** el valor que muestra el Inicio es el precio de las citas atendidas, no el dinero cobrado. No decir "ingresos".

---

## Video 2 · Plataforma: las empresas (2:45)

**Cuenta:** `admin@tusaas.com`.
**Antes de grabar:** ensaya "Entrar al panel" y la suspensión; no se han probado en pantalla.

**0:00–0:20**
- **Pantalla:** Inicio de la plataforma, con sus cuatro accesos.
- **Voz:** "En este video se muestra la consola de la plataforma, que es la vista del proveedor del sistema. Desde aquí se administran todas las empresas que contratan el servicio."

**0:20–0:45**
- **Pantalla:** tabla de empresas. Usar el filtro "Fecha de alta" y "Ordenar por" plan.
- **Voz:** "La tabla lista todas las empresas con su tipo de negocio, su plan y su estado. Se pueden filtrar por fecha de alta y ordenar, por ejemplo, por plan."

**0:45–1:25**
- **Pantalla:** "Nuevo Tenant". Elegir un rubro (aparece con qué empieza: servicios y ficha), el plan, "empezar en prueba", los datos, la sede principal y el administrador. Crear. Aparece "¡Empresa creada!".
- **Voz:** "Para registrar una empresa nueva se completa un solo formulario. Al elegir el tipo de negocio, el sistema indica con qué contenido empezará: por ejemplo, una barbería inicia con seis servicios y su ficha de preferencias de corte. Luego se elige el plan, si empieza en periodo de prueba, su primera sede y la persona que la administrará. Al crearla, el sistema muestra una contraseña temporal una sola vez, para entregarla al cliente."

**1:25–2:10**
- **Pantalla:** abrir una empresa: tarjetas Plan, Uso (los anillos) y Módulos. Apagar un módulo.
- **Voz:** "La página de cada empresa muestra su plan, su fecha de vencimiento y el uso que hace de él: usuarios, sedes, profesionales y citas del mes. Los indicadores cambian a ámbar cuando se acerca al límite y a rojo cuando lo alcanza. Más abajo están sus módulos. Desde aquí se puede desactivar un módulo; para agregar uno que el plan no incluye, es necesario cambiar de plan."

**2:10–2:45**
- **Pantalla:** registrar un pago (aparece "Cobros (1)"). Suspender y reactivar.
- **Voz:** "También se registran los pagos de la empresa y, si es necesario, se la puede suspender. Al hacerlo, se cierran de inmediato las sesiones de su personal y de sus clientes, y reactivarla toma un solo paso. El equipo de soporte puede ingresar al panel de una empresa para ayudarla; mientras lo hace, una franja amarilla indica que se trata de una sesión de soporte."

**No mostrar:** la bitácora (no tiene pantalla). Tampoco cambiar de plan, cambiar de rubro ni mover el vencimiento sin ensayarlos antes.

---

## Video 3 · Plataforma: planes, rubros y configuración (2:45)

**Cuenta:** `admin@tusaas.com`.

**0:00–0:30**
- **Pantalla:** Planes SaaS.
- **Voz:** "El sistema se ofrece en tres planes. El plan Esencial está pensado para un negocio con una sede y hasta cinco usuarios. El Profesional admite hasta tres sedes y veinte usuarios. El Empresarial está orientado a cadenas con varias sedes. También es posible crear planes a medida, que no aparecen en el registro público."

**0:30–1:20**
- **Pantalla:** Plantillas de rubros: tarjetas, columna "Módulos" con su globo. Abrir el rubro dental.
- **Voz:** "Cada tipo de negocio tiene una plantilla. En ella se define qué módulos usa, cómo se nombran las cosas, qué servicios trae al empezar, qué ficha de atención utiliza y cuáles son sus reglas, por ejemplo, con cuánta anticipación se puede reservar o cancelar. Así, un salón de belleza no necesita historia clínica, mientras que una clínica sí."

**1:20–2:00**
- **Pantalla:** "Editar rubro" → cambiar un módulo → guardar. Abrir el panel de empresas: "cambios sin aplicar", en verde lo que gana y en rojo lo que pierde → "Aplicar".
- **Voz:** "Cada empresa conserva su propia copia de la plantilla. Si se modifica la plantilla dental, las clínicas que ya usan el sistema no cambian automáticamente. El sistema muestra qué ganaría y qué perdería cada una, y los cambios se aplican solo al pulsar 'Aplicar'. Lo que cada empresa haya personalizado se mantiene."

**2:00–2:35**
- **Pantalla:** Configuración: elegir el color "Celeste" y guardar (cambia sin recargar). Cerrar el registro de empresas.
- **Voz:** "En la configuración de la plataforma se define la identidad del sistema: el nombre del producto, el color principal y el correo de soporte. También se establece cuánto dura una sesión de soporte y si cualquier negocio puede registrarse por su cuenta o solo mediante un alta realizada por la plataforma."

**2:35–2:45**
- **Pantalla:** menú de perfil → "Tema" oscuro → volver a claro.
- **Voz:** "Además, cada usuario puede elegir trabajar en modo claro u oscuro."

**No mostrar:** la pantalla Contactos (casi no tiene funciones). Vuelve a abrir el registro al terminar.

---

## Video 4 · Agenda (3:00)

**Cuenta:** `admin@luxuryspa.pe`.
**Antes de grabar:** busca un día con huecos libres y una cita pendiente que se pueda arrastrar.

**0:00–0:25**
- **Pantalla:** Agenda en "Hoy".
- **Voz:** "La agenda es la pantalla central del sistema. Cada columna corresponde a un profesional, con su horario debajo del nombre. Las zonas blancas indican cuándo atiende, las grises cuándo no, y la línea roja marca la hora actual. Cada cita se muestra con el color de su estado."

**0:25–0:45**
- **Pantalla:** cambiar de sede, de día e "Ir a una fecha". Clic en una tarjeta de arriba (por ejemplo, "confirmadas").
- **Voz:** "En la parte superior se elige la sede y el día. Las tarjetas resumen las citas de la jornada y permiten mostrar, por ejemplo, solo las pendientes o solo las confirmadas. La agenda se actualiza de forma automática."

**0:45–1:40**
- **Pantalla:** clic en un espacio libre → "Nueva cita" con el médico y la hora puestos. Usar el "+" del cliente para crear un paciente, elegir el servicio (aparecen los horarios libres) y guardar.
- **Voz:** "Para agendar, se hace clic en un espacio libre y el formulario se abre con el profesional y la hora ya seleccionados. Si el paciente es nuevo, se registra con el botón '+' sin salir del formulario, y queda elegido de inmediato. Al seleccionar el servicio, el sistema muestra solo los horarios que están realmente disponibles."

**1:40–2:15**
- **Pantalla:** arrastrar una cita a otra hora o a otro médico → confirmar. Intentar soltarla sobre un horario ocupado: aparece el motivo.
- **Voz:** "Para mover una cita, basta con arrastrarla a otro horario o a otro profesional, y el sistema pide confirmar el cambio. Si el nuevo horario está ocupado, no permite moverla y explica el motivo. De esta forma, nunca se cruzan dos citas de un mismo profesional."

**2:15–2:50**
- **Pantalla:** en "Nueva cita", escribir una hora fuera de horario. Aparece el aviso → "Agendar de todos modos" con su motivo.
- **Voz:** "Existen casos excepcionales, como un médico que acepta atender fuera de su horario. Para ello se usa la opción 'Agendar de todos modos', que exige indicar un motivo y queda registrada. Esta opción está disponible solo para la administración y el gerente de sede. Las vacaciones, los feriados y las sedes cerradas no se pueden pasar por alto."

**2:50–3:00**
- **Pantalla:** un día sin horarios → "Anotar en lista de espera".
- **Voz:** "Cuando no hay horarios disponibles, el paciente puede quedar anotado en la lista de espera, que se explica en otro video."

**No mostrar:** arrastrar en pantalla táctil (no se probó).

---

## Video 5 · Citas, fases y tiempos de atención (2:45)

**Cuenta:** `admin@bellaaura.pe` (ahí no hay que cobrar antes de atender, así que las fases avanzan sin interrupciones). Si prefieres Centro Médico, cobra la cita antes, como en el video 11.
**Antes de grabar:** una cita confirmada de hoy, cerca de la hora actual.

**0:00–0:30**
- **Pantalla:** Citas: pestañas "Hoy", "Próximos 7 días"… Buscar "ramirez" sin tilde. Abrir "Filtro" → "Fase: Pendiente".
- **Voz:** "La pantalla Citas reúne todas las citas agendadas. Se pueden buscar por nombre, incluso sin tildes, filtrar por estado, profesional o fecha, y ordenar según se necesite."

**0:30–1:20**
- **Pantalla:** abrir una cita. Señalar el camino de fases. Pulsar "Registrar llegada" y luego "Iniciar atención".
- **Voz:** "Cada cita sigue un recorrido: pendiente, confirmada, llegó, en atención y atendida. También puede terminar como cancelada o como no asistió. Los botones disponibles cambian según la etapa en que se encuentre. Cuando el paciente llega, se registra su llegada; cuando pasa al consultorio, se inicia la atención."

**1:20–1:50**
- **Pantalla:** volver a la agenda: el cronómetro corre en el bloque de la cita. En el panel, la sección "Tiempos".
- **Voz:** "A partir de ese momento se activa un cronómetro, visible también en la agenda. Si la atención supera el tiempo asignado, el cronómetro cambia a rojo. Así, la recepción sabe si la atención va retrasada."

**1:50–2:15**
- **Pantalla:** "Dar por atendida". Leer "La atención duró… min menos de lo asignado". Mostrar el "Historial".
- **Voz:** "Al finalizar, el sistema indica cuánto duró realmente la atención y, si terminó antes, libera el tiempo restante. Cada paso queda registrado en el historial, con la fecha y la persona que lo realizó. Para cancelar una cita siempre se debe indicar un motivo."

**2:15–2:45**
- **Pantalla:** Tiempos de atención, periodo de 30 días: tarjetas y tablas por profesional, servicio y cliente.
- **Voz:** "Con esta información se genera el reporte de tiempos de atención. Muestra cuánto dura en realidad cada servicio, cuánto esperan los pacientes y el desempeño de cada profesional. Esto permite ajustar las duraciones de los servicios y organizar mejor la agenda."

**No mostrar:** la marca "Sin confirmar a tiempo" en la agenda (solo aparece en la tabla de Citas y en el panel).

---

## Video 6 · Lista de espera y controles (2:45)

**Cuenta:** `admin@luxuryspa.pe`.

**0:00–0:20**
- **Pantalla:** Lista de espera, pestaña "En espera".
- **Voz:** "Cuando un paciente solicita una cita y no hay horarios disponibles, se le puede registrar en la lista de espera para atenderlo apenas se libere un espacio."

**0:20–0:55**
- **Pantalla:** "Anotar en la lista": cliente, servicio, sede, franja "Mañana" e interruptor "Prioritario". Guardar. Señalar la columna "Puesto" y las tarjetas.
- **Voz:** "Al registrarlo se indica el servicio, la sede y el horario de su preferencia, por ejemplo, por la mañana. También se puede señalar si espera a un profesional en particular. Los casos prioritarios se ubican primero y el resto se ordena por llegada."

**0:55–1:30**
- **Pantalla:** "Buscar horario" → elegir una hora → se abre "Nueva cita" ya llena → guardar. La persona pasa a "Con cita".
- **Voz:** "La opción 'Buscar horario' revisa las próximas tres semanas y muestra los espacios que se ajustan a lo que necesita el paciente. Al elegir uno, la cita se completa de forma automática y el paciente pasa a 'Con cita'. Si luego la cancela, vuelve a la lista y conserva su lugar."

**1:30–1:45**
- **Pantalla:** cancelar otra cita: aparece "Este horario le sirve a N personas de la lista de espera".
- **Voz:** "Además, cuando se cancela una cita, el sistema avisa si ese horario le sirve a alguien de la lista de espera."

**1:45–2:45**
- **Pantalla:** Controles y seguimiento: tarjetas "Vencidos" y "Hoy y próximos 7 días". Buscar "presion". "Agendar" en uno vencido. Mostrar "Nuevo control" con sus atajos.
- **Voz:** "La pantalla de controles y seguimiento sirve para las atenciones que requieren una nueva visita, como un control de presión al mes o una limpieza dental cada seis meses. Los controles se generan automáticamente al firmar la ficha o al terminar un servicio que lo incluye, y también se pueden crear manualmente. Aquí se ve cuáles están vencidos y cuáles corresponden a esta semana. Después de contactar al paciente, se marca como avisado y, con 'Agendar', la cita se completa automáticamente. De esta manera, se asegura el seguimiento de cada paciente."

**No mostrar:** el sistema no envía mensajes al paciente. Las llamadas y los avisos se hacen por fuera y aquí se marcan.

---

## Video 7 · Bloqueos, feriados y horarios del equipo (2:45)

**Cuenta:** `admin@luxuryspa.pe`.

**0:00–0:45**
- **Pantalla:** Horarios del equipo: pestañas por sede, línea de tiempo por persona. Lápiz → "Horario de…" → "Agregar tramo" → guardar. Provocar "El martes tiene tramos que se cruzan".
- **Voz:** "La disponibilidad parte del horario de cada profesional, definido por sede. Esta pantalla lo muestra como una línea de tiempo, con el horario de la sede como referencia. Se edita por día y por tramos, y el sistema impide que dos tramos se superpongan, incluso entre sedes distintas."

**0:45–1:40**
- **Pantalla:** Bloqueos y ausencias. Abrir las vacaciones de la Dra. Gómez: "N por resolver" → la lista de citas.
- **Voz:** "Las vacaciones, los permisos médicos o el cierre temporal de una sede se registran en esta pantalla, siempre con un motivo. Mientras se completa el registro, el sistema indica cuántas citas ya agendadas quedan dentro de ese periodo. Esas citas no se cancelan automáticamente: quedan marcadas como 'por resolver', para reprogramarlas o avisar al paciente a tiempo."

**1:40–2:00**
- **Pantalla:** "Nuevo bloqueo" de tipo "Bloqueo de horario".
- **Voz:** "Existe también el 'bloqueo de horario', pensado para situaciones como una reunión interna. A diferencia de las vacaciones, este bloqueo se puede omitir ante una urgencia, indicando el motivo."

**2:00–2:45**
- **Pantalla:** pestaña Feriados: la franja para cargar los feriados nacionales y "Agregar feriado" con "La sede cierra todo el día". Luego la agenda del 8 de diciembre: "No atiende · Inmaculada Concepción".
- **Voz:** "En la pestaña de feriados se pueden cargar en un solo paso los feriados nacionales del año. Si la sede cierra, la agenda lo muestra y no permite reservar en esa fecha. Si el negocio decide atender, basta con indicarlo al registrar el feriado."

---

## Video 8 · Clientes (2:30)

**Cuenta:** `admin@luxuryspa.pe`.
**Antes de grabar:** llena y firma una ficha de un paciente (video 9) y anota quién tiene una nota de inasistencias en línea.

**0:00–0:30**
- **Pantalla:** Pacientes: ordenar por Apellido, filtro "Entre dos fechas", pasar de página.
- **Voz:** "Esta pantalla reúne a todos los pacientes del negocio. Como en el resto de tablas del sistema, se puede buscar, filtrar por fecha, ordenar y avanzar por páginas. Cada negocio ve únicamente a sus propios clientes; ni otras empresas ni la plataforma tienen acceso a ellos."

**0:30–1:15**
- **Pantalla:** clic en un nombre → página del paciente: datos, tarjetas, "Historia de atenciones" y citas. Clic en la tarjeta "Atenciones".
- **Voz:** "Cada paciente tiene su propia página. En ella se encuentran sus datos, el número de atenciones, sus próximas citas, sus inasistencias, el tiempo promedio de atención y su historia de atenciones. Las tarjetas también funcionan como filtro: al seleccionar 'Atenciones', se muestran solo las citas atendidas."

**1:15–1:35**
- **Pantalla:** abrir el lápiz: "Tipo de sangre", "Antecedentes".
- **Voz:** "En los negocios de salud se registran datos clínicos, como el tipo de sangre y los antecedentes. En un salón de belleza o una barbería, estos campos no aparecen."

**1:35–2:05**
- **Pantalla:** bloque "Acceso al portal": apagar y encender el interruptor. "Olvidó su contraseña" → pasa a "Aún no ingresa".
- **Voz:** "Desde aquí también se gestiona el acceso del paciente al portal. El paciente ingresa con su correo y, la primera vez, con su número de documento como contraseña; después crea una propia. Si la olvida, se restablece desde esta misma sección."

**2:05–2:30**
- **Pantalla:** un paciente con "1 inasistencia en línea" bajo el nombre; pasar el cursor.
- **Voz:** "Si un paciente reserva por internet y no asiste, el sistema lo registra. Cada negocio decide si solo se deja una nota o si, después de varias inasistencias, se le restringe la reserva en línea por un tiempo. Aun así, el personal puede seguir agendándole citas."

**No mostrar:** no pulses "Perdonar" en cámara; solo se probó por detrás.

---

## Video 9 · Fichas de atención (3:00)

**Cuenta:** `admin@luxuryspa.pe`. Para mostrar el odontograma, `admin@dentalplus.pe`.
**Antes de grabar:** una cita en atención sin ficha; si vas a mostrar el acceso de emergencia, la empresa con ficha privada y una ficha de otro autor.

**0:00–0:50**
- **Pantalla:** Fichas de atención → "Plantillas de ficha". Renombrar un campo, ponerlo "Obligatorio", "Agregar campo" de escala → "Guardar ficha".
- **Voz:** "Cada servicio puede tener una ficha de atención, que es el registro que completa el profesional. El negocio diseña su ficha como una hoja: nombra los campos, indica cuáles son obligatorios y elige el tipo de cada uno, como texto, número, sí o no, una escala del cero al diez, un odontograma o la fecha del próximo control. Los cambios en la plantilla no modifican las fichas ya completadas."

**0:50–1:10**
- **Pantalla:** abrir la cita en atención → "Dar por atendida" → aviso de ficha firmada.
- **Voz:** "En un centro médico, una consulta no puede cerrarse sin su ficha firmada. Si se intenta, el sistema lo advierte."

**1:10–1:55**
- **Pantalla:** "Ficha de atención · Pendiente". Llenar a medias → firmar → "Faltan 2 datos para firmar". Completar (escala, odontograma si es dental), aceptar el consentimiento y firmar.
- **Voz:** "La ficha se puede guardar como borrador y completar más tarde. Si se intenta firmar con datos obligatorios vacíos, el sistema señala exactamente cuáles faltan. Una vez completa, se acepta el consentimiento y se firma. En la parte inferior se muestran las atenciones anteriores del paciente como referencia."

**1:55–2:20**
- **Pantalla:** ficha "Firmada" con su plazo. Agregar una nota. "Descargar" el PDF.
- **Voz:** "Después de firmada, la ficha puede corregirse durante 24 horas. Pasado ese plazo queda cerrada y solo admite notas adicionales, como corresponde a una historia clínica. También se puede descargar en PDF."

**2:20–3:00**
- **Pantalla:** como dueña, abrir una ficha privada de otro profesional: "Esta ficha es privada" → escribir el motivo → "Abrir la ficha".
- **Voz:** "Algunos negocios, como los de psicología, necesitan fichas privadas que solo puede leer su autor. Ante una emergencia, la administración puede abrirlas indicando un motivo. El acceso es de solo lectura, dura treinta minutos y queda registrado quién la abrió, cuándo y por qué. El profesional autor también recibe el aviso."

**No mostrar:** no hay firma digital del paciente; el PDF trae líneas para firmar a mano. Revisa cómo se ve el PDF antes de mostrarlo.

---

## Video 10 · Equipo y catálogo (3:00)

**Cuenta:** `admin@luxuryspa.pe`; para el tope del plan, `admin@donfilo.pe`.

**0:00–0:50**
- **Pantalla:** Servicios: ordenar por precio. "Nuevo servicio" con "Nueva categoría". Abrir "Reserva y seguimiento".
- **Voz:** "El catálogo de servicios define lo que ofrece el negocio. Cada servicio tiene precio, duración y categoría. Aquí también se indica si puede reservarse por internet y si genera un control de seguimiento; por ejemplo, una extracción puede generar un control a los siete días. Al registrarse, cada negocio recibe los servicios habituales de su rubro."

**0:50–1:35**
- **Pantalla:** Médicos: tarjeta "Médicos de 20 del plan: 8". Clic en un nombre → su página: tarjetas, horario de la semana con hoy resaltado, ausencias.
- **Voz:** "En la sección del equipo, cada profesional tiene su propia página, con sus servicios, su horario semanal, sus ausencias, sus citas del día y su tiempo promedio de atención. Cada profesional tiene una cuenta propia y solo ve la información que le corresponde."

**1:35–2:00**
- **Pantalla:** entrar como Don Filo → Barberos: botón con candado y su globo.
- **Voz:** "La tarjeta superior muestra siempre cuántos profesionales hay registrados frente al límite del plan. Esta barbería ya tiene cinco de cinco, por lo que el botón para agregar otro aparece bloqueado y explica que es necesario cambiar de plan."

**2:00–3:00**
- **Pantalla:** volver a Centro Médico → Salas y equipos: "Ecógrafo · En Consultorio 201". Ir a Configuración → "Salas y equipos" → "Dejar la sala fuera de uso". Volver, editar el Ecógrafo y apagar "En uso": el consultorio pasa a "Fuera de uso". Encenderlo de nuevo.
- **Voz:** "Por último, las salas y los equipos. Se registra cada consultorio y, dentro de él, los equipos que contiene, como este ecógrafo. Si un equipo deja de funcionar, cada negocio decide qué ocurre: que no cambie nada, que se registre como observación o que la sala completa quede fuera de uso mientras se repara. Cuando el equipo vuelve a estar disponible, la sala se habilita automáticamente y la administración recibe un aviso."

**No mostrar:** las salas todavía no se eligen al agendar; por ahora son un inventario. Tampoco hay servicios distintos por sede.

---

## Video 11 · Caja y cobrar una cita (3:00)

**Cuenta:** `admin@luxuryspa.pe`.
**Antes de grabar:** todas las cajas cerradas y una cita de Miraflores confirmada, con precio y sin cobrar.

**0:00–0:30**
- **Pantalla:** Caja: una tarjeta por sede, "0 de 3 cajas abiertas". "Abrir caja" en Miraflores con S/ 100.
- **Voz:** "Cada sede cuenta con su propia caja, que el sistema crea automáticamente. Al inicio del día se abre la caja indicando el efectivo inicial, por ejemplo, cien soles."

**0:30–0:50**
- **Pantalla:** abrir la cita confirmada → "Iniciar atención" → "Esta cita aún no está pagada. Cóbrala antes de iniciar la atención."
- **Voz:** "Como ocurre en la mayoría de clínicas, el pago se realiza antes de la atención. Si se intenta iniciar una atención sin cobrar, el sistema lo advierte. Cada negocio puede desactivar esta regla; en salones y barberías ya viene desactivada."

**0:50–1:45**
- **Pantalla:** tarjeta "Cobrar" → panel: IGV y total. Efectivo, "Recibido" 100: muestra el vuelto. Comprobante Boleta → Cobrar. Aparece la boleta B001 con la nota de simulación.
- **Voz:** "Al seleccionar 'Cobrar', se muestra el detalle con el IGV incluido. Si el paciente paga en efectivo, el sistema calcula el vuelto; si paga con tarjeta, Yape o Plin, se registra el número de operación. Luego se elige boleta o factura y la venta queda pagada con su comprobante. Por ahora, el comprobante es una simulación y aún no se envía a SUNAT, lo cual se indica claramente en pantalla."

**1:45–2:00**
- **Pantalla:** "Iniciar atención" ahora sí funciona.
- **Voz:** "Con el pago registrado, la atención ya puede iniciarse."

**2:00–2:30**
- **Pantalla:** Caja → "Movimiento": retiro de S/ 500 → "En la caja no hay tanto efectivo." Luego un gasto de S/ 15. "Ver turno".
- **Voz:** "Durante el día también se registran otros movimientos, como un gasto pagado con la caja o un retiro de efectivo. El sistema no permite retirar más dinero del disponible. En 'Ver turno' se revisa lo cobrado por cada medio de pago."

**2:30–3:00**
- **Pantalla:** "Cerrar caja": escribir lo contado → "La caja cuadra." o "Faltan S/ 5.00" con observación. Tabla "Turnos cerrados".
- **Voz:** "Al cierre del día se cuenta el efectivo y se registra el monto. El sistema compara esa cifra con la que debería haber e indica si la caja cuadra. Si existe una diferencia, es obligatorio explicarla. Todos los cierres quedan registrados, con quién abrió, quién cerró y el resultado."

**No mostrar:** no hay punto de venta sin cita ni carrito, ni pago por internet. No prometer SUNAT.

---

## Video 12 · Ventas, pagos, devoluciones y cupones (3:00)

**Cuenta:** `admin@luxuryspa.pe`.
**Antes de grabar:** ventas ya cobradas (video 11) y una cita confirmada sin cobrar para usar el cupón.

**0:00–0:30**
- **Pantalla:** Ventas: pestañas y tarjetas "Vendido hoy", "Por cobrar", "Devuelto".
- **Voz:** "Cada cobro genera una venta. En esta pantalla se consultan todas: las pagadas, las que tienen devoluciones y las anuladas. En la parte superior se muestra lo vendido y lo devuelto en el día."

**0:30–1:15**
- **Pantalla:** abrir una venta pagada → "Devolver" 15, motivo, Efectivo → "Devolución de S/ 15.00 registrada" → "Devuelta en parte" y la nota de crédito BC01.
- **Voz:** "Para devolver dinero, se abre la venta y se selecciona 'Devolver'. La devolución puede ser total o parcial, y siempre requiere un motivo. Si se hace en efectivo, el monto sale de la caja abierta. Además, se genera la nota de crédito correspondiente, también en modo de simulación. Una venta solo se anula si aún no se ha cobrado; si ya se cobró, corresponde una devolución. Ambas acciones están reservadas para la administración y el gerente de sede."

**1:15–1:35**
- **Pantalla:** Pagos → pestaña "Devoluciones": el −S/ 15.00 en rojo. Tarjeta "Neto de hoy".
- **Voz:** "En la pantalla Pagos se registra cada movimiento de dinero, tanto cobros como devoluciones, con su medio de pago y la persona que lo registró. También se muestra el total neto del día."

**1:35–2:15**
- **Pantalla:** Cupones: los tres de muestra y la pestaña "Ya no valen". "Nuevo cupón": escribir "promo 15!" (queda "PROMO15"), 15 %, 1 uso.
- **Voz:** "El sistema también permite crear cupones de descuento. Para cada cupón se define el código, si descuenta un porcentaje o un monto fijo, la compra mínima, la fecha de vencimiento y la cantidad de usos permitidos."

**2:15–3:00**
- **Pantalla:** cobrar una cita → cupón "aniversario" → "Ese cupón ya venció." → PROMO15 → "− S/ 6.75" → cobrar. Volver a Cupones: "1 de 1 · Agotado".
- **Voz:** "Al momento del cobro se ingresa el cupón del paciente. Si está vencido, el sistema lo indica. Si es válido, aplica el descuento, recalcula el total y el comprobante se emite por el monto final. Como este cupón tenía un solo uso, ahora figura como agotado y no puede volver a usarse."

**No mostrar:** no hay descuento general, propinas ni puntos.

---

## Video 13 · Usuarios y roles (3:00)

**Cuentas:** `admin@luxuryspa.pe`, `karina.lozano@luxuryspa.pe` (Caja) y `gustavo.paredes@luxuryspa.pe` (Gerente de San Borja).

**0:00–0:35**
- **Pantalla:** Usuarios y roles → pestaña Usuarios: tarjeta de cuentas frente al plan. Pestaña Roles: los cinco roles y "22 de 63".
- **Voz:** "No todas las personas de un negocio necesitan acceder a lo mismo. Por ejemplo, la recepción no requiere la configuración y la caja no debería eliminar pacientes. Por eso, cada persona tiene un rol. El sistema incluye cinco roles predefinidos: Administrador, Gerente de sede, Recepción, Caja y Profesional. Además, cada negocio puede crear los suyos."

**0:35–1:20**
- **Pantalla:** abrir a Rosa (Recepción): rejilla. Tocar el nombre de una pantalla para leer qué permite. Apagar "Crear" en Agenda (marca "Quitado") y encender "Eliminar" en Pacientes (marca "Agregado").
- **Voz:** "Cada rol es un conjunto de permisos: ver, crear, editar y eliminar, pantalla por pantalla, con una explicación sencilla de lo que permite cada uno. Si una persona necesita algo distinto, se puede ajustar solo para ella, sin afectar al resto del rol. En este caso, a Rosa se le quita el permiso de agendar y se le otorga el de eliminar pacientes. El sistema marca las diferencias respecto de su rol."

**1:20–1:35**
- **Pantalla:** columna "Especial": "Forzar horario", "Perdonar faltas", "Acceso de emergencia".
- **Voz:** "También existen permisos especiales, como agendar fuera de horario o abrir una ficha privada en caso de emergencia."

**1:35–2:10**
- **Pantalla:** entrar como Karina: menú corto, Pacientes sin "Nuevo", en la cita solo "Cobrar". Escribir `/users` en la barra: "no tiene acceso".
- **Voz:** "Así ve el sistema Karina, del área de caja. Su menú incluye solo las pantallas que utiliza. Puede consultar a los pacientes, pero no registrarlos, y en una cita solo tiene la opción de cobrar. Si intenta ingresar a otra pantalla escribiendo su dirección, el sistema le indica que su rol no tiene acceso."

**2:10–3:00**
- **Pantalla:** entrar como Gustavo: aviso "Ves al personal de tu sede…". "Nuevo usuario" con la sede ya puesta. Caja y Agenda muestran solo San Borja.
- **Voz:** "Por su parte, Gustavo es gerente de la sede San Borja y solo ve la información de su sede: agenda, caja, ventas y personal. Puede registrar a nuevas personas para su sede sin depender de la administración general, pero no puede crear administradores ni consultar otras sedes. Los pacientes, en cambio, son de toda la empresa, ya que pueden atenderse en cualquier sede."

**No mostrar:** a Rosa navegando la entrega 2 ni el panel de una venta sin permiso de cobrar; no se probaron en pantalla.

---

## Video 14 · Tu empresa: sucursales, empresa y configuración (3:00)

**Cuentas:** `admin@luxuryspa.pe`; para crear una sede, `admin@dentalplus.pe`.

**0:00–0:30**
- **Pantalla:** Empresa: tarjetas de datos, uso del plan y código de empresa.
- **Voz:** "La sección 'Tu empresa' reúne la información del negocio. Aquí se consultan sus datos, el plan contratado y su nivel de uso: sedes, profesionales y usuarios. También se muestra el código de empresa de ocho dígitos, que los clientes utilizan para registrarse."

**0:30–1:20**
- **Pantalla:** Sucursales: tarjetas ("3 de 3", "Abren hoy"…). Abrir San Borja: horario, quiénes atienden aquí, salas. Reloj → cerrar el lunes a las 17:00 → avisa qué horarios quedan fuera.
- **Voz:** "Cada sucursal tiene su propia página, con su horario, los profesionales que atienden en ella y sus salas. Si se modifica el horario de la sede, el sistema indica qué turnos del equipo quedan fuera. Las sedes no se eliminan, sino que se desactivan, y solo es posible hacerlo si no tienen citas pendientes."

**1:20–1:35**
- **Pantalla:** "Nueva sucursal" en Centro Médico explica el tope del plan.
- **Voz:** "Este centro ya utiliza las tres sedes que permite su plan. Para abrir una nueva, sería necesario cambiar a un plan superior."

**1:35–3:00**
- **Pantalla:** Configuración, sección por sección:
  - Módulos: apagar uno, guardar y ver que desaparece del menú.
  - Vocabulario y Fases de la cita.
  - Reglas de reserva: "Avisar y luego liberar".
  - Ventas y caja: "Cobrar antes de atender".
  - "Si reserva en línea y no asiste".
- **Voz:** "En Configuración, cada negocio adapta el sistema a su forma de trabajo. Puede desactivar los módulos que no utiliza y cambiar los nombres de las etapas, por ejemplo, que 'Llegó' se muestre como 'En sala'. También define con cuántas horas de anticipación debe confirmarse una cita y qué ocurre si no se confirma: solo avisar o liberar el horario para otro paciente. Además, decide si se cobra antes de atender, si los precios incluyen IGV y qué hacer cuando un paciente reserva por internet y no asiste. Cada opción viene configurada con el valor más habitual y puede modificarse según las necesidades del negocio."

**No mostrar:** sin ensayo, no apagues y vuelvas a encender módulos que tengan datos.

---

## Video 15 · Portal del cliente (3:00)

**Cuentas:** un paciente que nunca entró (su documento es la contraseña inicial) y `juliana.diaz@gmail.com`.
**Antes de grabar:** decide qué paciente usar para el primer ingreso y ten su documento a mano.

**0:00–0:30**
- **Pantalla:** entrar con el paciente nuevo: solo aparece "Crea tu contraseña". Crearla.
- **Voz:** "Los clientes cuentan con su propio espacio: el portal. La primera vez ingresan con su correo y su número de documento, y el sistema les pide crear una contraseña personal."

**0:30–1:30**
- **Pantalla:** "Reservar": servicio → sede → "Cualquiera disponible" → elegir un domingo ("No hay horarios libres", ir al próximo día con horarios) → hora de la mañana → "Revisa y confirma" con "Llega 15 minutos antes…" → reservar.
- **Voz:** "La reserva se realiza paso a paso: el servicio, la sede, el profesional o cualquiera disponible, y la fecha. Solo se muestran los horarios realmente libres, organizados en mañana, tarde y noche. Si un día no tiene disponibilidad, el sistema lleva al siguiente día con horarios. Al final se muestra un resumen, con el recordatorio de llegar con anticipación para realizar el pago."

**1:30–2:15**
- **Pantalla:** entrar como Juliana → Mis citas: "Confirma tu asistencia antes del…" → "Confirmar asistencia". "Cambiar fecha". "Filtrar" → Estado.
- **Voz:** "En 'Mis citas', el paciente consulta sus próximas citas y su historial. Desde aquí puede confirmar su asistencia, cambiar la fecha o cancelar, dentro del plazo que defina el negocio. Si no confirma a tiempo, el negocio puede liberar ese horario."

**2:15–2:45**
- **Pantalla:** en "¿Cuándo?", "¿Ningún horario te sirve? Anótate en la lista de espera" → "Por la mañana", "Las próximas 2 semanas". En Mis citas: "En lista de espera" con horas en verde.
- **Voz:** "Si no encuentra un horario adecuado, el paciente puede anotarse en la lista de espera e indicar cuándo le resulta conveniente. Cuando se libera un horario que le sirve, lo ve en esta misma pantalla y puede reservarlo directamente."

**2:45–3:00**
- **Pantalla:** "Mis datos".
- **Voz:** "En 'Mis datos' puede actualizar su número de celular o su contraseña. El paciente no tiene acceso a notas internas ni a fichas: solo ve su propia información."

**No mostrar:** no hay pago por internet ni mensajes por correo o WhatsApp. No grabes en celular sin probarlo antes.

---

## Video 16 · Notificaciones (2:00)

**Cuentas:** un profesional de Centro Médico (por ejemplo `carlos.perez@luxuryspa.pe`) y `admin@luxuryspa.pe`.

**0:00–0:40**
- **Pantalla:** entrar como el profesional: la campana con su número → abrirla → clic en "Te agendaron una cita": se abre la cita y el número baja.
- **Voz:** "La campana de notificaciones informa al equipo sobre los cambios importantes. El profesional recibe un aviso cuando se le agenda, reprograma o cancela una cita, cuando un paciente confirma desde el portal o cuando se acerca una ausencia suya. Al hacer clic en el aviso, se abre la cita correspondiente."

**0:40–1:20**
- **Pantalla:** entrar como la dueña → campana → aviso de ausencia → lleva a Bloqueos y ausencias.
- **Voz:** "La administración recibe otro tipo de avisos: cancelaciones, acciones de los pacientes en el portal, horarios liberados que le sirven a alguien de la lista de espera o salas que quedaron fuera de uso. Cuando hay varias citas sin confirmar, se envía un único aviso diario para no saturar la campana."

**1:20–2:00**
- **Pantalla:** "Ver todas" → "Sin leer" → "Marcar todo como leído" → "Estás al día".
- **Voz:** "En esta pantalla se consulta el historial completo de avisos. Además, nadie recibe notificaciones de las acciones que realizó personalmente: si una persona agenda una cita, el aviso le llega al profesional, no a quien la registró."

**No mostrar:** los avisos son dentro del sistema; no hay correo, WhatsApp ni notificaciones al celular.

---

## Consejos para grabar

- Graba cada video en tomas cortas, una por tramo de tiempo. Es más fácil repetir 30 segundos que 3 minutos.
- Mueve el mouse despacio y espera un segundo después de cada clic antes de hablar de lo que apareció.
- Si algo falla en cámara, anótalo: probablemente es un pendiente real del sistema.
- Ensaya primero los tramos que este documento marca como no probados en pantalla.
