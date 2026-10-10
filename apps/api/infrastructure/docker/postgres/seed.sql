-- =====================================================================
-- DATOS ESENCIALES. Es lo que el sistema necesita para funcionar y lo único
-- que va al servidor: catálogos, módulos, planes, rubros con su catálogo
-- inicial y las cuentas de la plataforma. No crea ninguna empresa.
--
-- Las empresas de ejemplo, con sus citas y demás datos de prueba, están en
-- seed_demo.sql, que se carga después de este y solo en desarrollo.
-- =====================================================================
BEGIN;
SET client_encoding = 'UTF8';

INSERT INTO type_documents (id, name, length, minimum, value, status) VALUES
('b1eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'L.E / DNI', 8, 8, NULL, 1),
('b1eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', 'RUC', 11, 11, NULL, 1),
('b1eebc99-9c0b-4ef8-bb6d-6bb9bd380a03', 'CARNET DE EXTRANJERIA', 12, 9, NULL, 1),
('b1eebc99-9c0b-4ef8-bb6d-6bb9bd380a04', 'PASAPORTE', 12, 9, NULL, 1),
('b1eebc99-9c0b-4ef8-bb6d-6bb9bd380a05', 'OTROS', 15, 1, NULL, 1)
ON CONFLICT DO NOTHING;

INSERT INTO permissions (id, name, module, description) VALUES
  ('10000000-0000-0000-0000-000000000001', 'company.setup', 'company', 'Registrar datos de su empresa y sucursales'),
  ('10000000-0000-0000-0000-000000000002', 'catalog.manage', 'services', 'Crear/Editar sus servicios, categorías y precios'),
  ('10000000-0000-0000-0000-000000000003', 'staff.manage', 'professionals', 'Dar de alta a sus profesionales y definir sus horarios'),
  ('10000000-0000-0000-0000-000000000004', 'users.manage', 'security', 'Crear cuentas para su personal operativo'),
  ('10000000-0000-0000-0000-000000000005', 'appointments.manage', 'appointments', 'Crear, mover y cancelar citas'),
  ('10000000-0000-0000-0000-000000000006', 'appointments.attend', 'appointments', 'Marcar atención del servicio'),
  ('10000000-0000-0000-0000-000000000007', 'pos.transact', 'pos', 'Cobrar y aperturar/cerrar caja'),
  ('10000000-0000-0000-0000-000000000008', 'reports.view', 'analytics', 'Visualizar reportes operativos y métricas'),
  ('10000000-0000-0000-0000-000000000009', 'audit.view', 'audit', 'Visualizar logs y eventos de auditoría del tenant'),
  ('10000000-0000-0000-0000-000000000010', 'saas.tenants.manage', 'saas_admin', 'Crear, suspender y administrar inquilinos'),
  ('10000000-0000-0000-0000-000000000011', 'saas.plans.manage', 'saas_admin', 'Gestionar catálogo de planes y precios'),
  ('10000000-0000-0000-0000-000000000012', 'saas.metrics.view', 'saas_admin', 'Ver métricas globales de ingresos y uso de la plataforma'),
  ('10000000-0000-0000-0000-000000000013', 'customers.manage', 'customers', 'Crear, editar y eliminar clientes y sus dependientes'),
  ('10000000-0000-0000-0000-000000000014', 'inventory.manage', 'inventory', 'Gestionar productos, almacenes, stock, transferencias y compras'),
  ('10000000-0000-0000-0000-000000000015', 'loyalty.manage', 'loyalty', 'Gestionar membresías, paquetes, puntos y gift cards'),
  ('10000000-0000-0000-0000-000000000016', 'marketing.manage', 'marketing', 'Gestionar campañas, plantillas, cupones y automatizaciones'),
  ('10000000-0000-0000-0000-000000000017', 'quotes.manage', 'quotes', 'Crear, enviar y aprobar presupuestos'),
  ('10000000-0000-0000-0000-000000000018', 'records.manage', 'records', 'Configurar plantillas de ficha y registrar fichas de atención'),
  ('10000000-0000-0000-0000-000000000019', 'waitlist.manage', 'waitlist', 'Gestionar la lista de espera')
ON CONFLICT (name) DO NOTHING;

INSERT INTO status_modules (id, name, description) VALUES
  ('e1eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'customers', 'Estados para la ficha de clientes'),
  ('e1eebc99-9c0b-4ef8-bb6d-6bb9bd380a03', 'sales', 'Estados de ventas y comprobantes'),
  ('e1eebc99-9c0b-4ef8-bb6d-6bb9bd380a04', 'payments', 'Estados de transacciones y pagos de facturas'),
  ('e1eebc99-9c0b-4ef8-bb6d-6bb9bd380a05', 'professionals', 'Estados para la ficha de profesionales'),
  ('e1eebc99-9c0b-4ef8-bb6d-6bb9bd380a06', 'services', 'Estados para el módulo de servicios')
ON CONFLICT (name) DO NOTHING;

INSERT INTO statuses (id, module_id, name, slug, color, is_default) VALUES
  -- Módulo: payments (e1eebc99-9c0b-4ef8-bb6d-6bb9bd380a04)
  ('f1eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'e1eebc99-9c0b-4ef8-bb6d-6bb9bd380a04', 'Pagado', 'paid', '#28A745', true),
  ('f1eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', 'e1eebc99-9c0b-4ef8-bb6d-6bb9bd380a04', 'Pendiente', 'pending', '#FFC107', false),
  ('f1eebc99-9c0b-4ef8-bb6d-6bb9bd380a03', 'e1eebc99-9c0b-4ef8-bb6d-6bb9bd380a04', 'Anulado', 'void', '#DC3545', false),
  
  -- Módulo: customers (e1eebc99-9c0b-4ef8-bb6d-6bb9bd380a01)
  ('f1eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'e1eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'Activo', 'active', '#28A745', true),
  ('f1eebc99-9c0b-4ef8-bb6d-6bb9bd380a12', 'e1eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'Inactivo', 'inactive', '#6C757D', false),

  -- Módulo: professionals (e1eebc99-9c0b-4ef8-bb6d-6bb9bd380a05)
  ('f1eebc99-9c0b-4ef8-bb6d-6bb9bd380a31', 'e1eebc99-9c0b-4ef8-bb6d-6bb9bd380a05', 'Activo', 'active', '#28A745', true),
  ('f1eebc99-9c0b-4ef8-bb6d-6bb9bd380a32', 'e1eebc99-9c0b-4ef8-bb6d-6bb9bd380a05', 'Inactivo', 'inactive', '#6C757D', false),

  ('f1eebc99-9c0b-4ef8-bb6d-6bb9bd380a41', 'e1eebc99-9c0b-4ef8-bb6d-6bb9bd380a06', 'Activo', 'active', '#28A745', true),
  ('f1eebc99-9c0b-4ef8-bb6d-6bb9bd380a42', 'e1eebc99-9c0b-4ef8-bb6d-6bb9bd380a06', 'Inactivo', 'inactive', '#6C757D', false)

ON CONFLICT DO NOTHING;

-- Catálogo de módulos del producto (los 20 del prototipo). Los base van en todos los planes.
INSERT INTO saas_modules (key, name, is_core, sort_order) VALUES
  ('agenda', 'Agenda y citas', true, 1),
  ('crm', 'Clientes / CRM 360', true, 2),
  ('booking', 'Reserva online y portal', false, 3),
  ('pos', 'Punto de venta y caja', false, 4),
  ('invoicing', 'Facturación electrónica', false, 5),
  ('inventory', 'Inventario', false, 6),
  ('commissions', 'Comisiones', false, 7),
  ('memberships', 'Membresías', false, 8),
  ('packages', 'Paquetes', false, 9),
  ('loyalty', 'Puntos y gift cards', false, 10),
  ('marketing', 'Marketing y WhatsApp', false, 11),
  ('automations', 'Automatizaciones', false, 12),
  ('resources', 'Salas y equipos', false, 13),
  ('classes', 'Clases grupales', false, 14),
  ('multi_branch', 'Multisede', false, 15),
  ('reports_adv', 'Analítica avanzada', false, 16),
  ('api', 'API e integraciones', false, 17),
  ('records', 'Fichas de atención e historia', false, 18),
  ('quotes', 'Presupuestos y planes de tratamiento', false, 19),
  ('walkin', 'Cola de llegada sin cita', false, 20)
ON CONFLICT (key) DO NOTHING;

-- Planes del prototipo: Esencial, Profesional y Empresarial. `modules` agrupa las
-- claves de saas_modules que el plan incluye (los módulos base no se listan).
INSERT INTO saas_plans (
    id, name, slug, code_internal, description, price_monthly, price_yearly, currency, 
    trial_config, subscription_config, modules, limits, consumption, support, features, status, presentation_order
) VALUES
(
    '11111111-1111-1111-1111-111111111111', 'Esencial', 'esencial', 'ESE', 'Agenda, reservas online, caja y fichas para negocios de una sede',
    149.00, 1490.00, 'PEN',
    '{"has_trial": true, "trial_days": 14}'::jsonb,
    '{"auto_renew": true, "default_period": "monthly"}'::jsonb,
    '{"agenda": ["booking", "resources", "walkin"], "sales": ["pos", "invoicing", "quotes"], "loyalty": ["packages"], "operations": ["records"]}'::jsonb,
    '{"users": 5, "branches": 1, "professionals": 5, "appointments_month": 800, "whatsapp_month": 300}'::jsonb,
    '{"storage_gb": 20, "api_requests_monthly": 0}'::jsonb,
    '{"level": "Estándar", "tickets_monthly": 10}'::jsonb,
    '{"multi_tenant": true, "roles_and_permissions": true}'::jsonb,
    'active', 1
),
(
    '22222222-2222-2222-2222-222222222222', 'Profesional', 'profesional', 'PRO', 'Inventario, fidelización, marketing y varias sedes',
    299.00, 2990.00, 'PEN',
    '{"has_trial": true, "trial_days": 14}'::jsonb,
    '{"auto_renew": true, "default_period": "monthly"}'::jsonb,
    '{"agenda": ["booking", "resources", "classes", "walkin"], "sales": ["pos", "invoicing", "quotes", "commissions"], "loyalty": ["packages", "memberships", "loyalty"], "marketing": ["marketing", "automations"], "operations": ["inventory", "records", "multi_branch"], "platform": ["reports_adv"]}'::jsonb,
    '{"users": 20, "branches": 3, "professionals": 20, "appointments_month": 4000, "whatsapp_month": 2000}'::jsonb,
    '{"storage_gb": 100, "api_requests_monthly": 0}'::jsonb,
    '{"level": "Avanzado", "tickets_monthly": 25}'::jsonb,
    '{"multi_tenant": true, "roles_and_permissions": true, "audit": true, "advanced_reports": true, "whatsapp_notifications": true}'::jsonb,
    'active', 2
),
(
    '33333333-3333-3333-3333-333333333333', 'Empresarial', 'empresarial', 'EMP', 'Todo el producto, con API e integraciones',
    599.00, 5990.00, 'PEN',
    '{"has_trial": true, "trial_days": 30}'::jsonb,
    '{"auto_renew": true, "default_period": "monthly"}'::jsonb,
    '{"agenda": ["booking", "resources", "classes", "walkin"], "sales": ["pos", "invoicing", "quotes", "commissions"], "loyalty": ["packages", "memberships", "loyalty"], "marketing": ["marketing", "automations"], "operations": ["inventory", "records", "multi_branch"], "platform": ["reports_adv", "api"]}'::jsonb,
    '{"users": 100, "branches": 20, "professionals": 100, "appointments_month": 30000, "whatsapp_month": 10000}'::jsonb,
    '{"storage_gb": 500, "api_requests_monthly": 500000}'::jsonb,
    '{"level": "Prioritario", "tickets_monthly": -1}'::jsonb,
    '{"multi_tenant": true, "roles_and_permissions": true, "audit": true, "advanced_reports": true, "whatsapp_notifications": true, "mfa": true, "api": true, "custom_domain": true, "priority_support": true}'::jsonb,
    'active', 3
)
ON CONFLICT (slug) DO NOTHING;

-- Rubros de negocio (los 10 del prototipo). El vocabulario define cómo se nombra
-- al cliente y al profesional en la interfaz del tenant.
INSERT INTO business_types (id, slug, name, short_name, description, icon, color, vocabulary, sort_order) VALUES
  ('b7000000-0000-0000-0000-000000000001', 'salon', 'Salón de belleza y spa', 'Salón y spa', 'Peluquería, color, uñas, faciales y masajes.', 'scissors', '#1F7A63', '{"client":"Cliente","clients":"Clientes","pro":"Estilista","pros":"Equipo"}', 1),
  ('b7000000-0000-0000-0000-000000000002', 'barber', 'Barbería', 'Barbería', 'Cortes y barba con atención por orden de llegada.', 'scissors', '#7A4B1F', '{"client":"Cliente","clients":"Clientes","pro":"Barbero","pros":"Barberos"}', 2),
  ('b7000000-0000-0000-0000-000000000003', 'aesthetics', 'Centro de estética / medicina estética', 'Centro de estética', 'Tratamientos faciales y corporales, láser e inyectables.', 'spark', '#C05785', '{"client":"Paciente","clients":"Pacientes","pro":"Especialista","pros":"Especialistas"}', 3),
  ('b7000000-0000-0000-0000-000000000004', 'dental', 'Clínica dental', 'Clínica dental', 'Historia clínica con odontograma, presupuestos y controles.', 'heart', '#2A6FB0', '{"client":"Paciente","clients":"Pacientes","pro":"Odontólogo","pros":"Odontólogos"}', 4),
  ('b7000000-0000-0000-0000-000000000005', 'medical', 'Consultorio médico', 'Consultorio médico', 'Consulta presencial y por videollamada, nota médica y receta.', 'heart', '#2E6E8E', '{"client":"Paciente","clients":"Pacientes","pro":"Médico","pros":"Médicos"}', 5),
  ('b7000000-0000-0000-0000-000000000006', 'physio', 'Fisioterapia y rehabilitación', 'Fisioterapia', 'Evaluación inicial, plan de sesiones y evolución.', 'user', '#5B3FA0', '{"client":"Paciente","clients":"Pacientes","pro":"Fisioterapeuta","pros":"Fisioterapeutas"}', 6),
  ('b7000000-0000-0000-0000-000000000007', 'psychology', 'Psicología y terapias', 'Psicología', 'Sesiones presenciales y en línea con notas confidenciales.', 'message', '#3E7F4F', '{"client":"Paciente","clients":"Pacientes","pro":"Psicólogo","pros":"Psicólogos"}', 7),
  ('b7000000-0000-0000-0000-000000000008', 'fitness', 'Estudio de yoga, pilates o funcional', 'Estudio fitness', 'Clases grupales con cupos y membresías.', 'users', '#3C7DC4', '{"client":"Alumno","clients":"Alumnos","pro":"Instructor","pros":"Instructores"}', 8),
  ('b7000000-0000-0000-0000-000000000009', 'vet', 'Veterinaria y grooming', 'Veterinaria', 'El tutor tiene una o varias mascotas; cada cita es para una mascota.', 'heart', '#C27C0E', '{"client":"Tutor","clients":"Tutores","pro":"Médico veterinario","pros":"Veterinarios","dependent":"Mascota","dependents":"Mascotas"}', 9),
  ('b7000000-0000-0000-0000-000000000010', 'generic', 'Otro rubro (genérico)', 'Genérico', 'Punto de partida neutro; todo se ajusta después.', 'layers', '#3C7DC4', '{"client":"Cliente","clients":"Clientes","pro":"Profesional","pros":"Profesionales"}', 10)
ON CONFLICT (slug) DO NOTHING;

-- Diferencias por rubro (prototipo): módulos que no usa, reglas por defecto y dependientes.
UPDATE business_types AS b SET modules_off = v.modules_off::jsonb, default_rules = v.default_rules::jsonb, dependents = v.dependents::jsonb
FROM (VALUES
  ('salon', '["quotes", "walkin", "classes"]', '{"noshow.fee_percent": 50, "pos.tips_enabled": true, "records.require_on_complete": false}', '{}'),
  ('barber', '["quotes", "classes"]', '{"booking.require_deposit": false, "agenda.checkin_step": true, "walkin.self_checkin": true, "pos.tips_enabled": true}', '{}'),
  ('aesthetics', '["walkin", "classes"]', '{"customers.clinical_fields": true, "records.require_on_complete": true, "booking.deposit_percent": 30, "noshow.fee_percent": 30, "cancel.window_hours": 24}', '{}'),
  ('dental', '["walkin", "classes", "memberships", "loyalty"]', '{"customers.clinical_fields": true, "pos.tips_enabled": false, "loyalty.enabled": false, "cancel.window_hours": 24, "records.require_on_complete": true, "records.lock_hours": 24, "booking.deposit_percent": 50, "reminders.hours_before": 48, "inventory.consume_on_service": false}', '{}'),
  ('medical', '["walkin", "classes", "memberships", "loyalty", "inventory"]', '{"customers.clinical_fields": true, "professionals.license": true, "pos.tips_enabled": false, "loyalty.enabled": false, "records.require_on_complete": true, "records.lock_hours": 24, "cancel.window_hours": 12}', '{}'),
  ('physio', '["walkin", "classes"]', '{"customers.clinical_fields": true, "pos.tips_enabled": false, "records.require_on_complete": true, "cancel.window_hours": 12, "noshow.fee_percent": 50}', '{}'),
  ('psychology', '["walkin", "classes", "inventory", "loyalty", "memberships"]', '{"customers.clinical_fields": true, "pos.tips_enabled": false, "loyalty.enabled": false, "records.private_to_author": true, "records.require_on_complete": false, "cancel.window_hours": 24, "noshow.fee_percent": 100}', '{}'),
  ('fitness', '["walkin", "quotes"]', '{"cancel.window_hours": 6, "cancel.late_fee_percent": 100, "noshow.fee_percent": 100, "pos.tips_enabled": false}', '{}'),
  ('vet', '["walkin", "classes", "memberships"]', '{"customers.clinical_fields": true, "professionals.license": true, "dependents.enabled": true, "dependents.required": true, "pos.tips_enabled": false, "records.require_on_complete": true, "recalls.notify_days": 7}', '{"enabled": true, "kind": "pet", "required": true}'),
  ('generic', '["walkin", "classes"]', '{}', '{}')
) AS v(slug, modules_off, default_rules, dependents)
WHERE b.slug = v.slug;

-- Catálogo inicial por rubro (prototipo): categorías, servicios y plantilla de ficha.
-- Se copia al tenant cuando la empresa se registra (Tenant::installBusinessTemplate).
UPDATE business_types AS b SET template = v.template::jsonb
FROM (VALUES
  ('salon', $tpl${"categories":[{"name":"Cabello","services":[{"code":"CAB-01","name":"Corte de dama","duration_minutes":45,"price":60,"buffer_after_minutes":0,"uses_record":false,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1},{"code":"CAB-02","name":"Brushing","duration_minutes":40,"price":40,"buffer_after_minutes":0,"uses_record":false,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1},{"code":"CAB-03","name":"Coloración completa","duration_minutes":120,"price":200,"buffer_after_minutes":10,"uses_record":true,"requires_deposit":true,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Retoque de raíz","days":42}},{"code":"CAB-04","name":"Mechas / balayage","duration_minutes":180,"price":350,"buffer_after_minutes":10,"uses_record":true,"requires_deposit":true,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Retoque de mechas","days":60}},{"code":"CAB-05","name":"Tratamiento de keratina","duration_minutes":150,"price":300,"buffer_after_minutes":10,"uses_record":true,"requires_deposit":true,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Mantenimiento de keratina","days":90}}]},{"name":"Uñas","services":[{"code":"UNA-01","name":"Manicure clásica","duration_minutes":40,"price":30,"buffer_after_minutes":0,"uses_record":false,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1},{"code":"UNA-02","name":"Manicure semipermanente","duration_minutes":60,"price":50,"buffer_after_minutes":0,"uses_record":false,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1},{"code":"UNA-03","name":"Pedicure spa","duration_minutes":60,"price":55,"buffer_after_minutes":10,"uses_record":false,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1}]},{"name":"Facial y spa","services":[{"code":"FAC-01","name":"Limpieza facial","duration_minutes":60,"price":110,"buffer_after_minutes":10,"uses_record":false,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1},{"code":"MAS-01","name":"Masaje relajante","duration_minutes":60,"price":130,"buffer_after_minutes":10,"uses_record":false,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1}]}],"record_template":{"name":"Ficha técnica de color","consent_text":"Declaro que se me informó del procedimiento y que la prueba de alergia se realizó o la rechacé voluntariamente.","require_consent":false,"fields":[{"key":"hair","label":"Tipo de cabello","type":"select","required":false,"options":["Liso","Ondulado","Rizado","Afro"]},{"key":"base","label":"Altura de tono base","type":"select","required":false,"options":["1–3","4–5","6–7","8–10"]},{"key":"formula","label":"Fórmula aplicada","type":"text","required":true,"placeholder":"Ej.: 7.1 + 7.0 (1:1)"},{"key":"ox","label":"Oxidante","type":"select","required":false,"options":["10 vol","20 vol","30 vol","40 vol"]},{"key":"time","label":"Tiempo de exposición (min)","type":"number","required":false},{"key":"allergy","label":"Prueba de alergia","type":"select","required":false,"options":["Negativa","Positiva","No realizada"]},{"key":"obs","label":"Observaciones","type":"textarea","required":false},{"key":"next","label":"Retoque de raíz","type":"next_control","required":false,"days":42}]}}$tpl$),
  ('barber', $tpl${"categories":[{"name":"Cortes","services":[{"code":"COR-01","name":"Corte clásico","duration_minutes":30,"price":30,"buffer_after_minutes":0,"uses_record":false,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Próximo corte","days":21}},{"code":"COR-02","name":"Corte fade / degradado","duration_minutes":40,"price":35,"buffer_after_minutes":0,"uses_record":false,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Próximo corte","days":21}},{"code":"COR-03","name":"Corte infantil","duration_minutes":25,"price":25,"buffer_after_minutes":0,"uses_record":false,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Próximo corte","days":30}}]},{"name":"Barba","services":[{"code":"BAR-01","name":"Perfilado de barba","duration_minutes":20,"price":20,"buffer_after_minutes":0,"uses_record":false,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1},{"code":"BAR-02","name":"Afeitado clásico con navaja","duration_minutes":30,"price":30,"buffer_after_minutes":5,"uses_record":false,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1},{"code":"BAR-03","name":"Corte + barba","duration_minutes":50,"price":50,"buffer_after_minutes":0,"uses_record":false,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Próximo corte y barba","days":21}}]}],"record_template":{"name":"Preferencias de corte","consent_text":null,"require_consent":false,"fields":[{"key":"style","label":"Estilo","type":"select","required":false,"options":["Clásico","Fade bajo","Fade medio","Fade alto","Tijera","Rapado"]},{"key":"machine","label":"Número de máquina","type":"text","required":false},{"key":"beard","label":"Barba","type":"select","required":false,"options":["Sin barba","Perfilada","Completa","Candado"]},{"key":"obs","label":"Observaciones","type":"textarea","required":false},{"key":"next","label":"Próximo corte","type":"next_control","required":false,"days":21}]}}$tpl$),
  ('aesthetics', $tpl${"categories":[{"name":"Facial","services":[{"code":"FAC-01","name":"Limpieza facial profunda","duration_minutes":60,"price":120,"buffer_after_minutes":10,"uses_record":true,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Limpieza de mantenimiento","days":30}},{"code":"FAC-02","name":"Peeling químico","duration_minutes":45,"price":180,"buffer_after_minutes":10,"uses_record":true,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Siguiente sesión de peeling","days":21}},{"code":"FAC-03","name":"Radiofrecuencia facial","duration_minutes":45,"price":160,"buffer_after_minutes":0,"uses_record":true,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Siguiente sesión","days":15}}]},{"name":"Láser","services":[{"code":"LAS-01","name":"Depilación láser (zona pequeña)","duration_minutes":20,"price":90,"buffer_after_minutes":0,"uses_record":true,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Siguiente sesión de láser","days":45}},{"code":"LAS-02","name":"Depilación láser (zona grande)","duration_minutes":45,"price":220,"buffer_after_minutes":0,"uses_record":true,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Siguiente sesión de láser","days":45}}]},{"name":"Medicina estética","services":[{"code":"MED-01","name":"Evaluación médica estética","duration_minutes":30,"price":80,"buffer_after_minutes":0,"uses_record":false,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1},{"code":"MED-02","name":"Toxina botulínica","duration_minutes":30,"price":900,"buffer_after_minutes":0,"uses_record":true,"requires_deposit":true,"online_booking":false,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Retoque de toxina","days":120}},{"code":"MED-03","name":"Ácido hialurónico","duration_minutes":45,"price":1200,"buffer_after_minutes":0,"uses_record":true,"requires_deposit":true,"online_booking":false,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Control de relleno","days":30}}]}],"record_template":{"name":"Ficha de tratamiento estético","consent_text":"Fui informado(a) del tratamiento, sus riesgos, cuidados posteriores y resultados esperados. Autorizo su realización y el registro fotográfico con fines clínicos.","require_consent":true,"fields":[{"key":"fitz","label":"Fototipo (Fitzpatrick)","type":"select","required":true,"options":["I","II","III","IV","V","VI"]},{"key":"zone","label":"Zona tratada","type":"text","required":true},{"key":"params","label":"Parámetros del equipo / producto","type":"text","required":false,"placeholder":"Ej.: 18 J/cm², 30 ms · o producto y unidades"},{"key":"lot","label":"Lote del producto","type":"text","required":false},{"key":"reaction","label":"Reacción inmediata","type":"select","required":false,"options":["Ninguna","Eritema leve","Edema","Otra"]},{"key":"photos","label":"Fotos antes/después registradas","type":"boolean","required":false},{"key":"obs","label":"Indicaciones post tratamiento","type":"textarea","required":false},{"key":"next","label":"Próxima sesión","type":"next_control","required":false,"days":30}]}}$tpl$),
  ('dental', $tpl${"categories":[{"name":"Odontología general","services":[{"code":"GEN-01","name":"Consulta y diagnóstico","duration_minutes":30,"price":80,"buffer_after_minutes":0,"uses_record":true,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Inicio de tratamiento","days":15}},{"code":"GEN-02","name":"Profilaxis y destartraje","duration_minutes":45,"price":150,"buffer_after_minutes":0,"uses_record":true,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Profilaxis semestral","days":180}},{"code":"GEN-03","name":"Curación con resina","duration_minutes":60,"price":180,"buffer_after_minutes":0,"uses_record":true,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Control de resina","days":180}},{"code":"GEN-04","name":"Extracción simple","duration_minutes":45,"price":150,"buffer_after_minutes":0,"uses_record":true,"requires_deposit":false,"online_booking":false,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Control post extracción","days":7,"service_code":"GEN-01"}},{"code":"GEN-05","name":"Radiografía periapical","duration_minutes":15,"price":40,"buffer_after_minutes":5,"uses_record":false,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1}]},{"name":"Especialidades","services":[{"code":"ORT-01","name":"Control de ortodoncia","duration_minutes":30,"price":120,"buffer_after_minutes":0,"uses_record":true,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Control de ortodoncia","days":30}},{"code":"END-01","name":"Endodoncia unirradicular","duration_minutes":90,"price":650,"buffer_after_minutes":0,"uses_record":true,"requires_deposit":true,"online_booking":false,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Restauración post endodoncia","days":15,"service_code":"GEN-03"}},{"code":"EST-01","name":"Blanqueamiento en consultorio","duration_minutes":90,"price":650,"buffer_after_minutes":0,"uses_record":true,"requires_deposit":true,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Control de blanqueamiento","days":180}}]}],"record_template":{"name":"Historia clínica odontológica","consent_text":"Autorizo el procedimiento indicado. Se me explicaron el diagnóstico, alternativas, riesgos y costo según el presupuesto entregado.","require_consent":true,"fields":[{"key":"reason","label":"Motivo de consulta","type":"textarea","required":true},{"key":"history","label":"Antecedentes y alergias","type":"textarea","required":false},{"key":"bp","label":"Presión arterial","type":"text","required":false,"placeholder":"120/80"},{"key":"teeth","label":"Piezas tratadas","type":"odontogram","required":false},{"key":"dx","label":"Diagnóstico","type":"text","required":true},{"key":"proc","label":"Procedimiento realizado","type":"textarea","required":false},{"key":"rx","label":"Indicaciones / receta","type":"textarea","required":false},{"key":"next","label":"Próximo control","type":"next_control","required":false,"days":180}]}}$tpl$),
  ('medical', $tpl${"categories":[{"name":"Consultas","services":[{"code":"CON-01","name":"Consulta de medicina general","duration_minutes":30,"price":90,"buffer_after_minutes":0,"uses_record":true,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Control","days":30}},{"code":"CON-02","name":"Consulta especializada","duration_minutes":30,"price":150,"buffer_after_minutes":0,"uses_record":true,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Control con especialista","days":30}},{"code":"CON-03","name":"Control","duration_minutes":20,"price":60,"buffer_after_minutes":0,"uses_record":true,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Control","days":60}},{"code":"CON-04","name":"Teleconsulta (videollamada)","duration_minutes":30,"price":80,"buffer_after_minutes":0,"uses_record":true,"requires_deposit":false,"online_booking":true,"is_virtual":true,"kind":"individual","capacity":1,"recall":{"label":"Control","days":30}}]},{"name":"Procedimientos","services":[{"code":"PRO-01","name":"Procedimiento menor","duration_minutes":45,"price":200,"buffer_after_minutes":0,"uses_record":true,"requires_deposit":false,"online_booking":false,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Retiro de puntos","days":10,"service_code":"VET-04"}},{"code":"PRO-02","name":"Certificado médico","duration_minutes":15,"price":50,"buffer_after_minutes":0,"uses_record":false,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1}]}],"record_template":{"name":"Nota médica (SOAP)","consent_text":null,"require_consent":false,"fields":[{"key":"reason","label":"Motivo de consulta","type":"textarea","required":true},{"key":"subj","label":"Subjetivo / anamnesis","type":"textarea","required":false},{"key":"bp","label":"Presión arterial","type":"text","required":false,"placeholder":"120/80"},{"key":"hr","label":"Frecuencia cardiaca (lpm)","type":"number","required":false},{"key":"temp","label":"Temperatura (°C)","type":"number","required":false,"step":0.1},{"key":"weight","label":"Peso (kg)","type":"number","required":false,"step":0.1},{"key":"exam","label":"Examen físico","type":"textarea","required":false},{"key":"dx","label":"Diagnóstico (CIE-10)","type":"text","required":true},{"key":"plan","label":"Plan y tratamiento","type":"textarea","required":false},{"key":"rx","label":"Receta","type":"textarea","required":false},{"key":"next","label":"Próximo control","type":"next_control","required":false,"days":30}]}}$tpl$),
  ('physio', $tpl${"categories":[{"name":"Evaluación","services":[{"code":"EVA-01","name":"Evaluación fisioterapéutica","duration_minutes":45,"price":100,"buffer_after_minutes":0,"uses_record":true,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Inicio de terapia","days":3,"service_code":"TER-01"}}]},{"name":"Terapia","services":[{"code":"TER-01","name":"Sesión de terapia física","duration_minutes":45,"price":80,"buffer_after_minutes":0,"uses_record":true,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Próxima sesión","days":3}},{"code":"TER-02","name":"Terapia manual","duration_minutes":45,"price":90,"buffer_after_minutes":0,"uses_record":true,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Próxima sesión","days":7}},{"code":"TER-03","name":"Electroterapia","duration_minutes":30,"price":60,"buffer_after_minutes":0,"uses_record":false,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1},{"code":"TER-04","name":"Rehabilitación deportiva","duration_minutes":60,"price":110,"buffer_after_minutes":0,"uses_record":true,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Próxima sesión","days":7}}]}],"record_template":{"name":"Evaluación y evolución","consent_text":null,"require_consent":false,"fields":[{"key":"zone","label":"Zona / diagnóstico","type":"text","required":true},{"key":"pain","label":"Dolor (EVA)","type":"scale","required":true},{"key":"rom","label":"Rango de movimiento","type":"text","required":false},{"key":"tech","label":"Técnicas aplicadas","type":"multiselect","required":false,"options":["Terapia manual","Electroterapia","Ultrasonido","Ejercicio terapéutico","Vendaje neuromuscular","Crioterapia"]},{"key":"session","label":"Sesión n.º","type":"number","required":false},{"key":"evol","label":"Evolución","type":"textarea","required":false},{"key":"home","label":"Ejercicios en casa","type":"textarea","required":false},{"key":"next","label":"Próxima sesión","type":"next_control","required":false,"days":7}]}}$tpl$),
  ('psychology', $tpl${"categories":[{"name":"Terapia","services":[{"code":"PSI-01","name":"Primera consulta","duration_minutes":60,"price":150,"buffer_after_minutes":10,"uses_record":true,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Primera sesión de terapia","days":7,"service_code":"PSI-02"}},{"code":"PSI-02","name":"Sesión individual","duration_minutes":50,"price":120,"buffer_after_minutes":10,"uses_record":true,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Próxima sesión","days":7}},{"code":"PSI-03","name":"Terapia de pareja","duration_minutes":75,"price":180,"buffer_after_minutes":15,"uses_record":true,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Próxima sesión","days":14}},{"code":"PSI-04","name":"Sesión en línea","duration_minutes":50,"price":110,"buffer_after_minutes":10,"uses_record":true,"requires_deposit":false,"online_booking":true,"is_virtual":true,"kind":"individual","capacity":1,"recall":{"label":"Próxima sesión","days":7}}]}],"record_template":{"name":"Nota de sesión","consent_text":null,"require_consent":false,"fields":[{"key":"goal","label":"Objetivo de la sesión","type":"text","required":false},{"key":"dev","label":"Desarrollo","type":"textarea","required":true},{"key":"tasks","label":"Tareas acordadas","type":"textarea","required":false},{"key":"risk","label":"Nivel de riesgo","type":"select","required":false,"options":["Bajo","Medio","Alto"]},{"key":"next","label":"Próxima sesión","type":"next_control","required":false,"days":7}]}}$tpl$),
  ('fitness', $tpl${"categories":[{"name":"Clases grupales","services":[{"code":"CLA-01","name":"Yoga","duration_minutes":60,"price":35,"buffer_after_minutes":0,"uses_record":false,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"group","capacity":12},{"code":"CLA-02","name":"Pilates mat","duration_minutes":50,"price":40,"buffer_after_minutes":0,"uses_record":false,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"group","capacity":10},{"code":"CLA-03","name":"Funcional","duration_minutes":45,"price":30,"buffer_after_minutes":0,"uses_record":false,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"group","capacity":15}]},{"name":"Personalizado","services":[{"code":"PER-01","name":"Clase privada","duration_minutes":60,"price":120,"buffer_after_minutes":0,"uses_record":true,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Próxima clase privada","days":7}},{"code":"PER-02","name":"Evaluación física","duration_minutes":30,"price":50,"buffer_after_minutes":0,"uses_record":true,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Reevaluación física","days":60}}]}],"record_template":{"name":"Evaluación física","consent_text":null,"require_consent":false,"fields":[{"key":"goal","label":"Objetivo","type":"select","required":false,"options":["Bajar de peso","Tonificar","Flexibilidad","Rehabilitación","Rendimiento"]},{"key":"injury","label":"Lesiones o restricciones","type":"textarea","required":false},{"key":"weight","label":"Peso (kg)","type":"number","required":false,"step":0.1},{"key":"fat","label":"% de grasa","type":"number","required":false,"step":0.1},{"key":"obs","label":"Observaciones","type":"textarea","required":false},{"key":"next","label":"Reevaluación","type":"next_control","required":false,"days":60}]}}$tpl$),
  ('vet', $tpl${"categories":[{"name":"Consulta y prevención","services":[{"code":"VET-01","name":"Consulta general","duration_minutes":30,"price":70,"buffer_after_minutes":0,"uses_record":true,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Control","days":14}},{"code":"VET-02","name":"Vacunación","duration_minutes":20,"price":60,"buffer_after_minutes":0,"uses_record":true,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Refuerzo de vacuna","days":365}},{"code":"VET-03","name":"Desparasitación","duration_minutes":15,"price":35,"buffer_after_minutes":0,"uses_record":true,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Próxima desparasitación","days":90}},{"code":"VET-04","name":"Control post operatorio","duration_minutes":20,"price":40,"buffer_after_minutes":0,"uses_record":true,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Alta post operatoria","days":10,"service_code":"VET-01"}}]},{"name":"Cirugía","services":[{"code":"CIR-01","name":"Esterilización","duration_minutes":120,"price":450,"buffer_after_minutes":30,"uses_record":true,"requires_deposit":true,"online_booking":false,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Retiro de puntos","days":10,"service_code":"VET-04"}}]},{"name":"Grooming","services":[{"code":"GRO-01","name":"Baño y corte (raza pequeña)","duration_minutes":90,"price":70,"buffer_after_minutes":0,"uses_record":false,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Próximo baño","days":30}},{"code":"GRO-02","name":"Baño medicado","duration_minutes":60,"price":60,"buffer_after_minutes":0,"uses_record":false,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1,"recall":{"label":"Próximo baño medicado","days":15}}]}],"record_template":{"name":"Historia clínica veterinaria","consent_text":null,"require_consent":false,"fields":[{"key":"weight","label":"Peso (kg)","type":"number","required":true,"step":0.1},{"key":"temp","label":"Temperatura (°C)","type":"number","required":false,"step":0.1},{"key":"reason","label":"Motivo de consulta","type":"textarea","required":true},{"key":"exam","label":"Examen clínico","type":"textarea","required":false},{"key":"dx","label":"Diagnóstico","type":"text","required":false},{"key":"tx","label":"Tratamiento","type":"textarea","required":false},{"key":"vaccine","label":"Vacuna aplicada","type":"select","required":false,"options":["Ninguna","Séxtuple canina","Antirrábica","Triple felina","Leucemia felina","Bordetella"]},{"key":"lot","label":"Lote de la vacuna","type":"text","required":false},{"key":"next","label":"Próximo refuerzo o control","type":"next_control","required":false,"days":21}]}}$tpl$),
  ('generic', $tpl${"categories":[{"name":"Servicios","services":[{"code":"SER-01","name":"Servicio estándar","duration_minutes":60,"price":100,"buffer_after_minutes":0,"uses_record":false,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1},{"code":"SER-02","name":"Servicio corto","duration_minutes":30,"price":60,"buffer_after_minutes":0,"uses_record":false,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1},{"code":"SER-03","name":"Evaluación inicial","duration_minutes":30,"price":0,"buffer_after_minutes":0,"uses_record":false,"requires_deposit":false,"online_booking":true,"is_virtual":false,"kind":"individual","capacity":1}]}],"record_template":{"name":"Notas de atención","consent_text":null,"require_consent":false,"fields":[{"key":"notes","label":"Notas","type":"textarea","required":true},{"key":"next","label":"Próximo seguimiento","type":"next_control","required":false,"days":30}]}}$tpl$)
) AS v(slug, template)
WHERE b.slug = v.slug;

INSERT INTO pipelines (id, name) VALUES
('c1eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'Pipeline de Atención de Clientes'),
('c1eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', 'Pipeline de Soporte Clínico'),
('c1eebc99-9c0b-4ef8-bb6d-6bb9bd380a03', 'Pipeline de Procesamiento de Exámenes')
ON CONFLICT (id) DO NOTHING;

-- Solo si aún no hay etapas: así volver a cargar este archivo no las duplica.
INSERT INTO pipeline_stages (id, pipeline_id, name, color, probability, stage_order)
SELECT v.id, v.pipeline_id::uuid, v.name, v.color, v.probability, v.stage_order
FROM (VALUES
(gen_random_uuid(), 'c1eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'Cita programada', '#00A3D9', 20, 1),
(gen_random_uuid(), 'c1eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'Triaje realizado', '#FFC107', 40, 2),
(gen_random_uuid(), 'c1eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'En consulta', '#28A745', 60, 3),
(gen_random_uuid(), 'c1eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'Receta / Tratamiento emitido', '#FD7E14', 80, 4),
(gen_random_uuid(), 'c1eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'Atención finalizada', '#198754', 100, 5),
(gen_random_uuid(), 'c1eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'Inasistencia', '#DC3545', 0, 6),
(gen_random_uuid(), 'c1eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', 'Nuevo', '#007BFF', 0, 1),
(gen_random_uuid(), 'c1eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', 'Esperando al cliente', '#6C757D', 0, 2),
(gen_random_uuid(), 'c1eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', 'Acción médica requerida', '#17A2B8', 0, 3),
(gen_random_uuid(), 'c1eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', 'Cerrado', '#28A745', 0, 4),
(gen_random_uuid(), 'c1eebc99-9c0b-4ef8-bb6d-6bb9bd380a03', 'Muestra recibida', '#007BFF', 0, 1),
(gen_random_uuid(), 'c1eebc99-9c0b-4ef8-bb6d-6bb9bd380a03', 'En análisis', '#FFC107', 0, 2),
(gen_random_uuid(), 'c1eebc99-9c0b-4ef8-bb6d-6bb9bd380a03', 'Resultados listos', '#17A2B8', 0, 3),
(gen_random_uuid(), 'c1eebc99-9c0b-4ef8-bb6d-6bb9bd380a03', 'Entregado al cliente', '#28A745', 0, 4),
(gen_random_uuid(), 'c1eebc99-9c0b-4ef8-bb6d-6bb9bd380a03', 'Cancelado', '#DC3545', 0, 5)
) AS v(id, pipeline_id, name, color, probability, stage_order)
WHERE NOT EXISTS (SELECT 1 FROM pipeline_stages);

-- Unidades de medida globales (tenant_id NULL = disponibles para todos)
INSERT INTO units (id, tenant_id, name, abbreviation) VALUES
  ('60eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', NULL, 'Unidad', 'UND'),
  ('60eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', NULL, 'Caja', 'CJA'),
  ('60eebc99-9c0b-4ef8-bb6d-6bb9bd380a03', NULL, 'Paquete', 'PQT'),
  ('60eebc99-9c0b-4ef8-bb6d-6bb9bd380a04', NULL, 'Mililitro', 'ML'),
  ('60eebc99-9c0b-4ef8-bb6d-6bb9bd380a05', NULL, 'Litro', 'L'),
  ('60eebc99-9c0b-4ef8-bb6d-6bb9bd380a06', NULL, 'Gramo', 'G'),
  ('60eebc99-9c0b-4ef8-bb6d-6bb9bd380a07', NULL, 'Kilogramo', 'KG')
ON CONFLICT DO NOTHING;

-- Roles y cuentas de la plataforma (sin empresa).
INSERT INTO roles (id, tenant_id, name, slug, description, is_system) VALUES
  ('c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a00', NULL, 'Administrador SaaS', 'saas_admin', 'Admin global', true),
  -- Soporte de la plataforma: ve las empresas y entra a su panel para ayudarlas; no cambia planes ni catálogos.
  ('c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', NULL, 'Soporte SaaS', 'saas_support', 'Soporte de la plataforma', true)
ON CONFLICT DO NOTHING;

INSERT INTO role_permissions (role_id, permission_id)
SELECT 'c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a00'::uuid, id FROM permissions WHERE module = 'saas_admin'
ON CONFLICT DO NOTHING;

-- OJO AL DESPLEGAR: estas dos cuentas nacen con la contraseña de prueba. En el
-- servidor hay que cambiarla (o cambiar el correo y la contraseña) antes de abrir el acceso.
INSERT INTO users (id, tenant_id, first_name, last_name, email, password_hash, is_owner, status) VALUES
  ('d0eebc99-9c0b-4ef8-bb6d-6bb9bd380a00', NULL, 'Super', 'Admin SaaS', 'admin@tusaas.com', '$2y$12$asHZEj9ZDLwBioqgkZedseegEV2IcYwqBdkZZpnfEy672NFep.kkq', false, 'active'),
  ('d0eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', NULL, 'Sofía', 'Soporte SaaS', 'soporte@tusaas.com', '$2y$12$asHZEj9ZDLwBioqgkZedseegEV2IcYwqBdkZZpnfEy672NFep.kkq', false, 'active')
ON CONFLICT DO NOTHING;

INSERT INTO user_roles (tenant_id, user_id, role_id) VALUES
  (NULL, 'd0eebc99-9c0b-4ef8-bb6d-6bb9bd380a00', 'c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a00'),
  (NULL, 'd0eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a01')
ON CONFLICT DO NOTHING;

COMMIT;
