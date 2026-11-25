<?php

namespace App\Support;

class WebRubros
{
    public const GROUPS = [
        'Belleza y cuidado personal' => ['salon', 'barber', 'nails', 'aesthetics', 'tattoo'],
        'Salud y bienestar'          => ['dental', 'physio', 'podiatry', 'psychology', 'kids_therapy', 'nutrition', 'medical', 'fitness'],
        'Mascotas'                   => ['vet', 'grooming'],
    ];

    public const GROUP_BENEFITS = [
        'Belleza y cuidado personal' => ['Reservas desde Instagram y WhatsApp', 'Comisiones que se calculan solas', 'Paquetes y gift cards'],
        'Salud y bienestar'          => ['Fichas de atención privadas', 'Controles que se avisan solos', 'Atención en línea con su enlace'],
        'Mascotas'                   => ['Ficha por mascota', 'Vacunas y baños recordados al tutor', 'Varias mascotas por tutor'],
    ];

    private const PLAN = ['plan_ind' => 'individual', 'plan_es' => 'esencial', 'plan_pro' => 'profesional'];

    private const CONTENT = [
        'salon' => [
            'h1' => 'Tu salón con la agenda llena y cada estilista en su lugar', 'sub' => 'Tus clientas reservan solas a cualquier hora, WhatsApp les recuerda la cita y al cobrar se calculan las comisiones y sale la boleta.',
            'pains' => [['Clientas que reservan por DM y se pierden en el chat', 'Reservan en tu enlace con estilista, servicio y hora'], ['Citas que no llegan y sillones vacíos', 'Recordatorio por WhatsApp con botón para confirmar'], ['Comisiones calculadas a mano a fin de mes', 'La comisión se calcula sola en cada cobro']],
            'feats' => [['Agenda por estilista y cabina', 'Cada servicio reserva a la persona y el sillón o la cabina que necesita.'], ['Recordatorios por WhatsApp', 'Confirmación, recordatorio y aviso del próximo retoque de color.'], ['Comisiones automáticas', 'Por servicio o por producto, con el reporte listo para pagar.'], ['Venta de productos', 'Shampoo, tratamientos y kits con su stock y su boleta.'], ['Ficha técnica de color', 'Fórmula, oxidante y tiempo de cada clienta, a mano en la próxima visita.'], ['Paquetes y gift cards', 'Vende 5 manicures o un día de spa para regalar.']],
            'plan' => 'plan_es',
            'faq' => [['¿Cada estilista ve solo su agenda?', 'Sí. Cada persona entra con su usuario y ve sus citas; la recepción y la dueña ven todo.'], ['¿Puedo cobrar un adelanto para coloración?', 'Sí. Marcas los servicios que piden adelanto y se cobra al reservar con Yape o tarjeta.'], ['¿Se descuentan los insumos del stock?', 'Sí, puedes asociar insumos a cada servicio y se descuentan al terminar la atención.'], ['¿Sirve si también hago uñas y spa?', 'Sí. La plantilla trae cabello, uñas, facial y masajes; agregas o quitas lo que quieras.']],
        ],
        'barber' => [
            'h1' => 'Tu barbería llena, sin fila y sin cuaderno', 'sub' => 'Citas y clientes sin cita en el mismo tablero, recordatorio «te toca tu corte» y la caja cuadrada al cierre con la comisión de cada barbero.',
            'pains' => [['Sábados con cola y gente que se va', 'Cola sin cita con tiempo de espera y aviso por WhatsApp'], ['Clientes que vuelven cuando se acuerdan', 'Recordatorio automático a los 21 días de su corte'], ['La caja no cuadra y las comisiones en una libreta', 'Cierre de caja y comisión por barbero en un clic']],
            'feats' => [['Cola sin cita', 'El cliente se anota en la tableta y ve su turno; le avisas cuando le toca.'], ['Reservas desde Instagram', 'Tu enlace en la biografía: elige barbero, corte y hora.'], ['Te toca tu corte', 'Aviso automático a los 21 días con el enlace para volver a reservar.'], ['Comisión por barbero', 'Cada cobro suma a la comisión del barbero que atendió.'], ['Membresía de cortes', 'Corte ilimitado al mes con cobro recurrente.'], ['Caja que cuadra', 'Efectivo, Yape y Plin separados, con cierre por denominaciones.']],
            'plan' => 'plan_es',
            'faq' => [['¿Puedo mezclar citas y clientes sin cita?', 'Sí. El tablero muestra la agenda y la cola juntas, y asigna al siguiente barbero libre.'], ['¿Necesito una tableta?', 'No es obligatorio. El autorregistro funciona en cualquier tableta o celular con el enlace del kiosko.'], ['¿Cómo pago las comisiones?', 'El reporte de comisiones suma lo de cada barbero por fecha y lo puedes exportar.'], ['¿Puedo vender ceras y aceites?', 'Sí, con su stock, su precio y su boleta en el mismo cobro.']],
        ],
        'nails' => [
            'h1' => 'Tus clientas vuelven a su retoque sin que tengas que escribirles', 'sub' => 'Reserva en línea con adelanto para extensiones, ficha con técnica y curvatura, y aviso automático del relleno o el retoque.',
            'pains' => [['Clientas que no llegan a una cita de 2 horas', 'Adelanto con Yape al reservar las extensiones'], ['Olvidas qué curvatura o color usaste', 'Ficha con técnica, tamaño y código de color'], ['Escribir una por una para el retoque', 'Aviso automático a los 21 días con enlace para reservar']],
            'feats' => [['Adelanto en servicios largos', 'Extensiones y acrílicas piden adelanto; si no llega, lo conservas.'], ['Ficha de técnica', 'Forma, largo, curvatura, color y sensibilidad a adhesivos.'], ['Retoques recordados', 'Relleno de acrílicas y retoque de pestañas avisados solos.'], ['Agenda por mesa y camilla', 'Mesa de uñas y camilla de pestañas sin cruces.'], ['Club de uñas', 'Membresía mensual con dos manicures incluidas.'], ['Reseñas de tus clientas', 'Encuesta después de cada cita e invitación a dejar reseña en Google.']],
            'plan' => 'plan_ind',
            'faq' => [['Trabajo sola, ¿qué plan me conviene?', 'El plan Individual: una profesional, reservas en línea, boleta y recordatorios.'], ['¿Puedo cobrar adelanto solo en extensiones?', 'Sí. Eliges qué servicios piden adelanto y de cuánto.'], ['¿Se guarda la curvatura de cada clienta?', 'Sí, en su ficha, junto con la técnica, el color y las alergias.'], ['¿Puedo vender gift cards?', 'Sí, con código y saldo que se descuenta al usarla.']],
        ],
        'aesthetics' => [
            'h1' => 'Paquetes de sesiones, consentimiento y fotos en un solo lugar', 'sub' => 'Vende paquetes de láser o faciales, firma el consentimiento en la tableta y sigue cada tratamiento con su ficha y sus parámetros.',
            'pains' => [['Paquetes de 6 sesiones anotados en papel', 'Saldo de sesiones que se descuenta en cada visita'], ['Consentimientos firmados que nadie encuentra', 'Consentimiento obligatorio dentro de la ficha'], ['Pacientes que abandonan el tratamiento', 'Aviso de la siguiente sesión con enlace para reservar']],
            'feats' => [['Paquetes de sesiones', 'Láser x6 o faciales x4 con vencimiento y saldo a la vista.'], ['Consentimiento firmado', 'Sin consentimiento no se cierra la atención de un tratamiento.'], ['Ficha con parámetros', 'Fototipo, zona, energía, lote del producto y fotos de seguimiento.'], ['Adelanto en inyectables', 'Toxina y rellenos con adelanto y reserva solo por recepción.'], ['Siguiente sesión', 'Recordatorio a los 45 días para la sesión de láser.'], ['Reportes por tratamiento', 'Ingresos, paquetes vendidos y sesiones pendientes.']],
            'plan' => 'plan_pro',
            'faq' => [['¿El consentimiento queda guardado?', 'Sí, firmado y con fecha dentro de la ficha de la paciente.'], ['¿Puedo subir fotos de antes y después?', 'Sí, en la ficha de cada atención.'], ['¿Cómo funcionan los paquetes?', 'Vendes el paquete, se cobra una vez y cada sesión se descuenta del saldo.'], ['¿Sirve para medicina estética?', 'Sí, con servicios que solo se reservan por recepción y con adelanto.']],
        ],
        'tattoo' => [
            'h1' => 'Cotiza, cobra el adelanto y tatúa sin plantones', 'sub' => 'Cita de diseño, adelanto obligatorio para sesiones largas, consentimiento firmado y el retoque recordado a los 30 días.',
            'pains' => [['Clientes que reservan 4 horas y no llegan', 'Adelanto obligatorio que se conserva si no asiste'], ['Consentimientos y edades sin control', 'Consentimiento en la ficha con control de mayoría de edad'], ['Cotizaciones perdidas en el chat', 'Cita de diseño y presupuesto que se convierte en sesión']],
            'feats' => [['Adelanto obligatorio', 'La reserva se confirma solo con el adelanto pagado.'], ['Consentimiento firmado', 'Salud, alergias y autorización del menor en la ficha.'], ['Cotización de diseño', 'Presupuesto por tamaño y zona, aprobado por el cliente.'], ['Agenda por artista', 'Cada artista con sus horarios, su camilla y su estilo.'], ['Retoque recordado', 'Aviso a los 30 días para el retoque sin costo.'], ['Boleta del saldo', 'Cobras el saldo al terminar y la boleta llega por WhatsApp.']],
            'plan' => 'plan_es',
            'faq' => [['¿Puedo pedir adelanto solo en tatuajes grandes?', 'Sí. Defines el adelanto por servicio y las sesiones largas solo se reservan con él.'], ['¿Cómo controlo a los menores de edad?', 'La ficha pide la autorización firmada del padre, madre o tutor.'], ['¿Se guarda el lote de tintas y agujas?', 'Sí, en la ficha de cada atención.'], ['¿Sirve para piercing?', 'Sí, la plantilla trae perforaciones y cambio de joya.']],
        ],
        'dental' => [
            'h1' => 'Odontograma, presupuestos y controles sin papeles', 'sub' => 'Historia odontológica con odontograma, presupuestos que se convierten en plan de tratamiento con cuotas y aviso del control semestral.',
            'pains' => [['Presupuestos que el paciente nunca aprueba', 'Presupuesto por WhatsApp con aprobación en línea'], ['Pacientes que no vuelven a su profilaxis', 'Control semestral avisado solo'], ['Historias clínicas en fólderes', 'Odontograma y evolución en la ficha digital']],
            'feats' => [['Odontograma', 'Piezas, hallazgos y tratamientos en la historia del paciente.'], ['Presupuestos y planes', 'El presupuesto aprobado se vuelve plan con citas y cuotas.'], ['Controles automáticos', 'Profilaxis semestral y controles de ortodoncia avisados.'], ['Consentimiento informado', 'Firmado antes del procedimiento y guardado en la historia.'], ['Agenda por consultorio', 'Odontólogos, sillones y equipos de rayos X sin cruces.'], ['Boleta y factura SUNAT', 'A personas y a empresas, con el pago en cuotas registrado.']],
            'plan' => 'plan_pro',
            'faq' => [['¿Tiene odontograma?', 'Sí, dentro de la historia odontológica de cada paciente.'], ['¿Puedo cobrar un tratamiento en cuotas?', 'Sí. El plan de tratamiento registra las cuotas y lo pagado.'], ['¿Cada odontólogo ve solo a sus pacientes?', 'Puedes configurarlo por rol; la recepción ve la agenda completa.'], ['¿Emite factura a empresas?', 'Sí, boleta o factura electrónica SUNAT en el mismo cobro.']],
        ],
        'physio' => [
            'h1' => 'Planes de sesiones que el paciente sí termina', 'sub' => 'Evaluación inicial, plan de 10 sesiones que se vende como paquete, evolución con escala de dolor y control de asistencia.',
            'pains' => [['Pacientes que dejan la terapia a la mitad', 'Recordatorio de cada sesión y aviso si falta'], ['Sesiones pagadas anotadas en un cuaderno', 'Paquete con saldo que se descuenta solo'], ['La evolución no queda registrada', 'Escala de dolor y técnicas en cada sesión']],
            'feats' => [['Evaluación inicial', 'Zona, diagnóstico, rango de movimiento y dolor de partida.'], ['Planes de sesiones', 'Paquete de 10 sesiones con saldo y vencimiento.'], ['Evolución del dolor', 'La escala de cada sesión muestra el avance al paciente.'], ['Agenda por camilla', 'Fisioterapeutas, camillas y gimnasio sin cruces.'], ['Asistencia', 'Recordatorios y aviso al paciente que faltó.'], ['Boleta por sesión o paquete', 'Cobro con Yape, tarjeta o efectivo y boleta SUNAT.']],
            'plan' => 'plan_es',
            'faq' => [['¿Puedo vender las sesiones como paquete?', 'Sí. El paquete se paga una vez y cada sesión descuenta del saldo.'], ['¿Se registra la escala de dolor?', 'Sí, en la evolución de cada sesión.'], ['¿Sirve para rehabilitación deportiva?', 'Sí, la plantilla la incluye y puedes agregar tus servicios.'], ['¿Puedo cobrar penalidad si no asiste?', 'Sí, con la regla de inasistencia que definas.']],
        ],
        'podiatry' => [
            'h1' => 'Podología ordenada: fichas, controles y pacientes que vuelven', 'sub' => 'Ficha del pie con alerta para pacientes con diabetes, controles de uña encarnada y hongos avisados por WhatsApp, y plantillas a medida con adelanto.',
            'pains' => [['Pacientes que no vuelven a la curación', 'Control a los 7 días agendado y recordado'], ['No sabes quién tiene diabetes al atender', 'Alerta visible en la ficha y en la agenda'], ['Quiropodias que se olvidan por meses', 'Aviso automático cada 30 días']],
            'feats' => [['Ficha podológica', 'Motivo, hallazgos, tratamiento e indicaciones en cada visita.'], ['Alerta de diabetes', 'El paciente con diabetes se marca y se ve antes de atender.'], ['Controles de curación', 'Uña encarnada y hongos con su siguiente control recordado.'], ['Plantillas con adelanto', 'Toma de molde con adelanto y entrega agendada.'], ['Paquetes de quiropodia', '4 quiropodias con descuento y saldo a la vista.'], ['Boleta SUNAT', 'Cobro con Yape o tarjeta y boleta por WhatsApp.']],
            'plan' => 'plan_ind',
            'faq' => [['Atiendo sola, ¿qué plan me conviene?', 'El Individual: una profesional, fichas, recordatorios y boleta.'], ['¿Puedo marcar a los pacientes con diabetes?', 'Sí, en su ficha; se ve en la agenda antes de la cita.'], ['¿Se agenda el control después del tratamiento?', 'Sí, el sistema propone la fecha y avisa al paciente.'], ['¿Sirve para atención a domicilio?', 'Sí, puedes crear servicios a domicilio con su duración.']],
        ],
        'psychology' => [
            'h1' => 'Tu consulta en orden y tus notas solo para ti', 'sub' => 'Sesiones presenciales y en línea, notas de sesión confidenciales que solo ve quien las escribe y cobro anticipado sin incomodar a nadie.',
            'pains' => [['Pacientes que cancelan una hora antes', 'Cobro anticipado y política de cancelación clara'], ['Notas en cuadernos o en el celular', 'Notas cifradas que solo tú puedes ver'], ['Enviar enlaces de videollamada a mano', 'Enlace de la sesión en línea en la confirmación']],
            'feats' => [['Notas privadas', 'Solo quien escribe la nota puede leerla, ni la recepción.'], ['Sesiones en línea', 'El enlace llega en la confirmación y en el recordatorio.'], ['Cobro anticipado', 'El paciente paga al reservar con Yape o tarjeta.'], ['Frecuencia semanal', 'Citas repetidas cada semana a la misma hora.'], ['Paquetes de sesiones', '4 sesiones con descuento y saldo a la vista.'], ['Recibo o boleta', 'Comprobante electrónico enviado por correo o WhatsApp.']],
            'plan' => 'plan_ind',
            'faq' => [['¿Alguien más puede leer mis notas?', 'No. Las notas privadas solo las ve quien las escribió.'], ['¿Puedo atender en línea?', 'Sí, la cita lleva el enlace de videollamada.'], ['¿Puedo cobrar antes de la sesión?', 'Sí, con Yape o tarjeta al reservar.'], ['¿Sirve para un centro con varios psicólogos?', 'Sí, cada uno con su agenda y sus notas privadas.']],
        ],
        'kids_therapy' => [
            'h1' => 'Terapias infantiles organizadas para el equipo y para las familias', 'sub' => 'La familia reserva para su hijo o hija, cada niño tiene su ficha y su evolución, y los padres reciben las indicaciones para casa.',
            'pains' => [['Horarios que cambian cada semana por WhatsApp', 'La familia reprograma sola desde su enlace'], ['La evolución de cada niño en hojas sueltas', 'Ficha por niño con avance en cada sesión'], ['Padres que no saben qué practicar en casa', 'Indicaciones enviadas después de la sesión']],
            'feats' => [['Ficha por niño', 'Una familia, uno o varios hijos, cada uno con su historia.'], ['Evolución por sesión', 'Objetivo, actividades y avance de 0 a 10.'], ['Indicaciones a padres', 'Lo que hay que practicar en casa, enviado a la familia.'], ['Sesiones semanales', 'Mismo día y hora cada semana, con recordatorio.'], ['Planes de terapia', '8 sesiones de lenguaje u ocupacional con saldo.'], ['Agenda por sala', 'Terapeutas, salas y sala sensorial sin cruces.']],
            'plan' => 'plan_es',
            'faq' => [['¿Una familia puede tener varios hijos?', 'Sí. El apoderado registra a cada hijo y reserva para cada uno.'], ['¿Quién ve la evolución?', 'El equipo terapéutico; a la familia le llegan las indicaciones que decidas enviar.'], ['¿Sirve para lenguaje, ocupacional y aprendizaje?', 'Sí, la plantilla trae las tres terapias y la evaluación inicial.'], ['¿Puedo vender planes de sesiones?', 'Sí, como paquetes con saldo y vencimiento.']],
        ],
        'nutrition' => [
            'h1' => 'Controles nutricionales que tus pacientes no se saltan', 'sub' => 'Primera consulta y controles con peso, medidas e IMC, consulta en línea y planes de varios meses con el siguiente control recordado.',
            'pains' => [['Pacientes que abandonan después de la primera consulta', 'Control cada 15 días agendado y recordado'], ['Medidas anotadas en hojas o en Excel', 'Peso, IMC y medidas en la ficha de cada control'], ['Cobrar plan por plan', 'Planes de 3 meses vendidos como paquete']],
            'feats' => [['Control nutricional', 'Objetivo, peso, talla, IMC, grasa y cintura en cada visita.'], ['Evolución del paciente', 'Peso y medidas de control en control.'], ['Consulta en línea', 'Enlace de videollamada en la confirmación.'], ['Planes por meses', 'Plan de 3 meses con 6 controles y saldo.'], ['Próximo control', 'Recordatorio a los 15 días con enlace para reservar.'], ['Boleta SUNAT', 'Cobro con Yape o tarjeta y comprobante por WhatsApp.']],
            'plan' => 'plan_ind',
            'faq' => [['¿Se calcula el IMC?', 'Registras peso y talla en la ficha y el IMC queda en cada control.'], ['¿Puedo atender en línea?', 'Sí, con enlace de videollamada en la cita.'], ['¿Puedo vender planes de varios meses?', 'Sí, como paquetes de controles con saldo.'], ['¿Sirve para nutrición deportiva?', 'Sí, la plantilla la incluye.']],
        ],
        'vet' => [
            'h1' => 'Cada mascota con su historia y sus vacunas al día', 'sub' => 'El tutor reserva para su mascota, la historia clínica es por mascota y las vacunas y desparasitaciones se avisan solas.',
            'pains' => [['Vacunas de refuerzo que se olvidan', 'Aviso automático al tutor antes de la fecha'], ['Historias en fichas de cartón', 'Historia clínica digital por mascota'], ['Tutores que llaman para preguntar horarios', 'Reserva en línea eligiendo a su mascota']],
            'feats' => [['Ficha por mascota', 'Especie, raza, peso, alergias y alertas de cada mascota.'], ['Vacunas con aviso', 'Refuerzos y desparasitaciones recordados al tutor.'], ['Historia clínica', 'Motivo, examen, diagnóstico, tratamiento y lote de vacuna.'], ['Cirugías con adelanto', 'Esterilizaciones con adelanto y reserva por recepción.'], ['Farmacia y alimentos', 'Venta con stock y boleta en el mismo cobro.'], ['Consultorio, quirófano y grooming', 'Cada espacio con su agenda sin cruces.']],
            'plan' => 'plan_es',
            'faq' => [['¿Un tutor puede tener varias mascotas?', 'Sí, y reserva eligiendo a cuál lleva.'], ['¿Se avisa la próxima vacuna?', 'Sí, por WhatsApp unos días antes, con enlace para reservar.'], ['¿También sirve para el baño y grooming?', 'Sí; si solo haces grooming, mira la plantilla de peluquería canina.'], ['¿Puedo vender productos?', 'Sí, con stock y boleta.']],
        ],
        'grooming' => [
            'h1' => 'Tu peluquería canina con la agenda llena cada mes', 'sub' => 'El tutor reserva para su mascota, el precio va por tamaño, la ficha guarda temperamento y corte, y el próximo baño se avisa solo.',
            'pains' => [['Tutores que llaman para preguntar precio y turno', 'Reserva en línea con precio por tamaño'], ['No recuerdas si el perro muerde o qué corte lleva', 'Ficha con temperamento, corte y productos'], ['Clientes que vuelven cuando quieren', 'Aviso del próximo baño a los 30 días']],
            'feats' => [['Baño y corte por tamaño', 'Servicios por raza pequeña o grande con su duración.'], ['Ficha por mascota', 'Temperamento, corte, piel y pelaje en cada visita.'], ['Próximo baño', 'Recordatorio automático con enlace para reservar.'], ['Club de baño', 'Membresía mensual con dos baños incluidos.'], ['Paquetes de baños', '4 baños con descuento y saldo.'], ['Aviso de recojo', 'Mensaje al tutor cuando su mascota está lista.']],
            'plan' => 'plan_ind',
            'faq' => [['¿El precio cambia por tamaño?', 'Sí, cada servicio tiene su versión por raza pequeña o grande.'], ['¿Se guarda el corte de cada perro?', 'Sí, en su ficha junto con el temperamento.'], ['¿Puedo vender una membresía de baños?', 'Sí, con cobro mensual y baños incluidos.'], ['¿Sirve si además tengo veterinaria?', 'Sí; la plantilla de veterinaria también incluye grooming.']],
        ],
    ];

    public static function groupOf(string $slug): ?string
    {
        foreach (self::GROUPS as $group => $slugs) {
            if (in_array($slug, $slugs, true)) {
                return $group;
            }
        }

        return null;
    }

    public static function content(string $slug): ?array
    {
        $row = self::CONTENT[$slug] ?? null;

        if (!$row) {
            return null;
        }

        return [
            'h1'    => $row['h1'],
            'sub'   => $row['sub'],
            'pains' => array_map(fn ($p) => ['before' => $p[0], 'after' => $p[1]], $row['pains']),
            'feats' => array_map(fn ($f) => ['title' => $f[0], 'text' => $f[1]], $row['feats']),
            'plan'  => self::PLAN[$row['plan']] ?? null,
            'faq'   => array_map(fn ($q) => ['q' => $q[0], 'a' => $q[1]], $row['faq']),
        ];
    }
}
