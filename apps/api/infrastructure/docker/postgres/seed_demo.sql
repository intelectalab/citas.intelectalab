-- =====================================================================
-- DATOS DE MUESTRA. Solo para desarrollo y pruebas: NO se carga en el servidor.
-- Crea once empresas de ejemplo (al menos una por rubro) con sus sedes, equipo,
-- servicios, clientes y citas. Se carga después de seed.sql, que trae lo esencial.
--
-- Lo que aquí parece lógica (el generador de citas, la copia del catálogo del
-- rubro, las listas de equipo y clientes) solo fabrica datos de prueba. El
-- sistema no depende de nada de este archivo: una empresa real recibe su
-- catálogo, su ficha y sus feriados desde la API al registrarse.
-- =====================================================================
BEGIN;
SET client_encoding = 'UTF8';

-- Antes de empezar: este archivo va después de seed.sql y se carga una sola vez.
DO $check$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM business_types) THEN
    RAISE EXCEPTION 'Faltan los datos esenciales: carga primero seed.sql y luego seed_demo.sql.';
  END IF;

  IF EXISTS (SELECT 1 FROM tenants WHERE id = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11') THEN
    RAISE EXCEPTION 'Los datos de muestra ya están cargados. Para recargarlos, vuelve a crear la base con init.sql y seed.sql.';
  END IF;
END
$check$;

INSERT INTO tenants (id, name, slug, code, business_type_id, timezone, status) VALUES
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'Centro Médico Integral', 'centro-medico-integral', '48271639', 'b7000000-0000-0000-0000-000000000005', 'America/Lima', 'active'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'Clínica Odontológica DentalPlus', 'clinica-dentalplus', '73920518', 'b7000000-0000-0000-0000-000000000004', 'America/Lima', 'active'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', 'Consultorio Psicológico Mente Sana', 'mente-sana', '15846027', 'b7000000-0000-0000-0000-000000000007', 'America/Lima', 'active'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44', 'Centro Fisioterapia Vital', 'fisioterapia-vital', '60394872', 'b7000000-0000-0000-0000-000000000006', 'America/Lima', 'active'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', 'Laboratorio Clínico BioAnálisis', 'bioanalisis', '92716345', 'b7000000-0000-0000-0000-000000000010', 'America/Lima', 'active'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66', 'Policlínico San Judas', 'policlinico-sanjudas', '37058194', 'b7000000-0000-0000-0000-000000000005', 'America/Lima', 'suspended'),
  -- Una empresa de una sola sede por cada rubro restante.
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a77', 'Salón Bella Aura', 'bella-aura', '26481905', 'b7000000-0000-0000-0000-000000000001', 'America/Lima', 'active'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a88', 'Barbería Don Filo', 'barberia-don-filo', '58130276', 'b7000000-0000-0000-0000-000000000002', 'America/Lima', 'active'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a99', 'Centro de Estética Lumina', 'estetica-lumina', '81549302', 'b7000000-0000-0000-0000-000000000003', 'America/Lima', 'active'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380aaa', 'Estudio Prana Yoga y Pilates', 'estudio-prana', '34672150', 'b7000000-0000-0000-0000-000000000008', 'America/Lima', 'active'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380abb', 'Veterinaria Huellitas', 'veterinaria-huellitas', '69205784', 'b7000000-0000-0000-0000-000000000009', 'America/Lima', 'active')
ON CONFLICT (slug) DO NOTHING;

INSERT INTO tenant_settings (tenant_id)
SELECT id FROM tenants
ON CONFLICT (tenant_id) DO NOTHING;

-- Cada empresa guarda su copia de lo que le da su rubro (igual que al registrarse).
UPDATE tenants AS t SET
    business_profile = jsonb_build_object(
        'vocabulary', b.vocabulary,
        'modules_off', b.modules_off,
        'default_rules', b.default_rules,
        'dependents', b.dependents
    ),
    business_profile_synced_at = now()
FROM business_types AS b
WHERE b.id = t.business_type_id AND t.business_profile IS NULL;

INSERT INTO subscriptions (id, tenant_id, plan_id, status, billing_cycle, starts_at, trial_ends_at, external_provider) VALUES
  (gen_random_uuid(), 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', '22222222-2222-2222-2222-222222222222', 'active', 'monthly', now(), now() + interval '14 days', 'stripe'),
  (gen_random_uuid(), 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', '33333333-3333-3333-3333-333333333333', 'active', 'yearly', now(), NULL, 'culqi'),
  (gen_random_uuid(), 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', '22222222-2222-2222-2222-222222222222', 'trialing', 'monthly', now(), now() + interval '30 days', 'stripe'),
  (gen_random_uuid(), 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44', '22222222-2222-2222-2222-222222222222', 'active', 'monthly', now(), NULL, 'culqi'),
  (gen_random_uuid(), 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', '22222222-2222-2222-2222-222222222222', 'active', 'monthly', now(), NULL, 'manual'),
  (gen_random_uuid(), 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66', '33333333-3333-3333-3333-333333333333', 'canceled', 'yearly', now() - interval '1 year', NULL, 'stripe'),
  -- Barbería y veterinaria en el plan Esencial (una sede); las otras tres en el Profesional,
  -- que es el que trae inventario, puntos, membresías y clases.
  (gen_random_uuid(), 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a77', '22222222-2222-2222-2222-222222222222', 'active', 'monthly', now(), NULL, 'culqi'),
  (gen_random_uuid(), 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a88', '11111111-1111-1111-1111-111111111111', 'active', 'monthly', now(), NULL, 'manual'),
  (gen_random_uuid(), 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a99', '22222222-2222-2222-2222-222222222222', 'trialing', 'monthly', now(), now() + interval '14 days', 'manual'),
  (gen_random_uuid(), 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380aaa', '22222222-2222-2222-2222-222222222222', 'active', 'monthly', now(), NULL, 'culqi'),
  (gen_random_uuid(), 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380abb', '11111111-1111-1111-1111-111111111111', 'active', 'monthly', now(), NULL, 'manual')
ON CONFLICT DO NOTHING;

INSERT INTO subscription_invoices (
    id, subscription_id, tenant_id, status_id, amount, currency, billing_reason, invoice_date, due_date, paid_at, external_invoice_id
) VALUES 
(
    gen_random_uuid(),
    (SELECT id FROM subscriptions WHERE tenant_id = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22'), -- Clínica DentalPlus (Enterprise: Anual 4990 PEN - Sin trial, pagado)
    'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22',
    'f1eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', -- Status: Pagado
    4990.00,
    'PEN',
    'subscription_create',
    now(),
    now() + interval '30 days',
    now(),
    'in_1_culqi_enterprise_sample'
),
(
    gen_random_uuid(),
    (SELECT id FROM subscriptions WHERE tenant_id = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44'), -- Centro Fisioterapia Vital (Growth: Mensual 349 PEN)
    'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44',
    'f1eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', -- Status: Pagado
    349.00,
    'PEN',
    'subscription_create',
    now(),
    now() + interval '15 days',
    now(),
    'in_2_culqi_growth_sample'
)
ON CONFLICT DO NOTHING;

INSERT INTO companies (id, tenant_id, legal_name, trade_name, tax_id, phone, address, district, department) VALUES
  ('b1111111-1111-1111-1111-111111111111', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'Centro Médico Integral S.A.C.', 'Centro Médico Integral', '20601234567', '987654321', 'Av. Larco 123', 'Miraflores', 'Lima'),
  ('b2222222-2222-2222-2222-222222222222', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'DentalPlus Salud S.A.', 'Clínica Odontológica DentalPlus', '20554433221', '912345678', 'Av. Javier Prado 450', 'San Isidro', 'Lima'),
  ('b3333333-3333-3333-3333-333333333333', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', 'Mente Sana Psicólogos E.I.R.L.', 'Consultorio Psicológico Mente Sana', '20443322119', '998877665', 'Jr. de la Unión 500', 'Cercado de Lima', 'Lima'),
  ('b4444444-4444-4444-4444-444444444444', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44', 'Fisioterapia Vital S.A.C.', 'Centro Fisioterapia Vital', '20332211005', '976543210', 'Av. Universitaria 1800', 'San Martín de Porres', 'Lima'),
  ('b5555555-5555-5555-5555-555555555555', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', 'BioAnálisis Laboratorios S.R.L.', 'Laboratorio Clínico BioAnálisis', '20221100993', '965432109', 'Calle Las Begonias 321', 'Los Olivos', 'Lima'),
  ('b6666666-6666-6666-6666-666666666666', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66', 'Policlínico San Judas S.A.C.', 'Policlínico San Judas', '20110099881', '954321098', 'Av. Arequipa 1100', 'Lince', 'Lima'),
  ('b7777777-7777-7777-7777-777777777777', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a77', 'Bella Aura S.A.C.', 'Salón Bella Aura', '20605512347', '947100771', 'Av. Grau 320', 'Barranco', 'Lima'),
  ('b8888888-8888-8888-8888-888888888888', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a88', 'Don Filo E.I.R.L.', 'Barbería Don Filo', '20604478912', '947100881', 'Jr. Castilla 640', 'Magdalena del Mar', 'Lima'),
  ('b9999999-9999-9999-9999-999999999999', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a99', 'Lumina Estética S.A.C.', 'Centro de Estética Lumina', '20603365478', '947100991', 'Calle Las Camelias 780', 'San Isidro', 'Lima'),
  ('baaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380aaa', 'Prana Estudio S.A.C.', 'Estudio Prana Yoga y Pilates', '20602254163', '947100101', 'Calle Berlín 455', 'Miraflores', 'Lima'),
  ('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380abb', 'Huellitas Veterinaria S.R.L.', 'Veterinaria Huellitas', '20601143829', '947100111', 'Av. Salaverry 2020', 'Jesús María', 'Lima')
ON CONFLICT (tenant_id, trade_name) DO NOTHING;

INSERT INTO branches (id, tenant_id, company_id, name, timezone, status) VALUES
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'b1111111-1111-1111-1111-111111111111', 'Sede Central - Miraflores', 'America/Lima', 'active'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'b2222222-2222-2222-2222-222222222222', 'Sede Principal - San Isidro', 'America/Lima', 'active'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', 'b3333333-3333-3333-3333-333333333333', 'Oficina Lima Centro', 'America/Lima', 'active'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44', 'b4444444-4444-4444-4444-444444444444', 'Sede SMP - Universitaria', 'America/Lima', 'active'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', 'b5555555-5555-5555-5555-555555555555', 'Tienda Los Olivos', 'America/Lima', 'active'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a77', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66', 'b6666666-6666-6666-6666-666666666666', 'Sede Principal Lince', 'America/Lima', 'inactive')
ON CONFLICT (tenant_id, name) DO NOTHING;

INSERT INTO rooms (id, tenant_id, branch_id, name, description) VALUES
  ('11eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'Consultorio 101', 'Consultorio de Medicina General'),
  ('11eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'Consultorio 102', 'Consultorio de Pediatría')
ON CONFLICT DO NOTHING;

INSERT INTO tenant_contacts (id, tenant_id, first_name, last_name, email, phone, position) VALUES 
(
    gen_random_uuid(), 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'Carlos', 'Mendoza Ruiz', 'carlos.mendoza@tenantadmin.pe', '+51 999888777', 'Gerente General'
),
(
    gen_random_uuid(), 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'Roberto', 'Guerra Silva', 'roberto.guerra@dentalplus.pe', '+51 912345678', 'Director Médico'
),
(
    gen_random_uuid(), 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', 'María', 'Rojas Viteri', 'maria.rojas@consultoria.pe', '+51 998877665', 'Socia Fundadora'
),
(
    gen_random_uuid(), 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44', 'Jorge', 'Salas Paredes', 'jorge.salas@fitlife.pe', '+51 976543210', 'Gerente de Operaciones'
),
(
    gen_random_uuid(), 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', 'Ana', 'Pérez Gómez', 'ana.perez@elahorro.pe', '+51 965432109', 'Administradora'
),
(
    gen_random_uuid(), 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66', 'Luis', 'Silva Morales', 'luis.silva@webdev.pe', '+51 954321098', 'CEO & Fundador'
),
(gen_random_uuid(), 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a77', 'Fabiola', 'Arana Quiroz', 'fabiola.arana@bellaaura.pe', '+51 947100771', 'Dueña'),
(gen_random_uuid(), 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a88', 'Kevin', 'Arroyo Paz', 'kevin.arroyo@donfilo.pe', '+51 947100881', 'Dueño'),
(gen_random_uuid(), 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a99', 'Ximena', 'Barrios Luna', 'ximena.barrios@lumina.pe', '+51 947100991', 'Directora médica'),
(gen_random_uuid(), 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380aaa', 'Alejandra', 'Wong Ríos', 'alejandra.wong@prana.pe', '+51 947100101', 'Fundadora'),
(gen_random_uuid(), 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380abb', 'Fernando', 'Aliaga Soto', 'fernando.aliaga@huellitas.pe', '+51 947100111', 'Director'
)
ON CONFLICT DO NOTHING;

INSERT INTO roles (id, tenant_id, name, slug, description, is_system) VALUES
  ('c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'Dueño / Owner', 'owner', 'Admin Luxury', true),
  ('c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'Director Médico', 'owner', 'Admin DentalPlus', true),
  ('c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', 'Socio Principal', 'owner', 'Admin Legal', true),
  ('c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44', 'Gerente FitLife', 'owner', 'Admin Gym', true),
  ('c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', 'Administrador Tienda', 'owner', 'Admin Ahorro', true),
  ('c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a77', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66', 'CEO WebDev', 'owner', 'Admin Marketing', true),
  ('c0eebc99-9c0b-4ef8-bb6d-6bb9bd380b77', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a77', 'Dueña', 'owner', 'Admin Bella Aura', true),
  ('c0eebc99-9c0b-4ef8-bb6d-6bb9bd380b88', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a88', 'Dueño', 'owner', 'Admin Don Filo', true),
  ('c0eebc99-9c0b-4ef8-bb6d-6bb9bd380b99', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a99', 'Directora', 'owner', 'Admin Lumina', true),
  ('c0eebc99-9c0b-4ef8-bb6d-6bb9bd380baa', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380aaa', 'Fundadora', 'owner', 'Admin Prana', true),
  ('c0eebc99-9c0b-4ef8-bb6d-6bb9bd380bbb', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380abb', 'Director', 'owner', 'Admin Huellitas', true)
ON CONFLICT (tenant_id, slug) DO NOTHING;

INSERT INTO roles (id, tenant_id, name, slug, description, is_system) VALUES
  ('c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a81', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'Médico / Profesional', 'professional', 'Personal asistencial y de atención', true),
  ('c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a23', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'Odontólogo / Profesional', 'professional', 'Personal asistencial DentalPlus', true),
  ('c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a45', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', 'Psicólogo / Profesional', 'professional', 'Personal Mente Sana', true)
ON CONFLICT (tenant_id, slug) DO NOTHING;

-- Los permisos de cada rol no se escriben aquí: la API le da a cada rol de fábrica
-- sus permisos iniciales (y su nombre: Administrador, Profesional...) la primera vez
-- que hace falta. Ver App\Support\Access::ensureRoles.

INSERT INTO users (id, tenant_id, first_name, last_name, email, password_hash, is_owner, status) VALUES
  ('d0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'Carlos', 'Mendoza', 'admin@luxuryspa.pe', '$2y$12$asHZEj9ZDLwBioqgkZedseegEV2IcYwqBdkZZpnfEy672NFep.kkq', true, 'active'),
  ('d0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'Dr. Roberto', 'Guerra', 'admin@dentalplus.pe', '$2y$12$asHZEj9ZDLwBioqgkZedseegEV2IcYwqBdkZZpnfEy672NFep.kkq', true, 'active'),
  ('d0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', 'María', 'Rojas', 'admin@consultorialegal.pe', '$2y$12$asHZEj9ZDLwBioqgkZedseegEV2IcYwqBdkZZpnfEy672NFep.kkq', true, 'active'),
  ('d0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44', 'Jorge', 'Salas', 'admin@fitlife.pe', '$2y$12$asHZEj9ZDLwBioqgkZedseegEV2IcYwqBdkZZpnfEy672NFep.kkq', true, 'active'),
  ('d0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', 'Ana', 'Pérez', 'admin@minimarketelahorro.pe', '$2y$12$asHZEj9ZDLwBioqgkZedseegEV2IcYwqBdkZZpnfEy672NFep.kkq', true, 'active'),
  ('d0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66', 'Luis', 'Silva', 'admin@webdev.pe', '$2y$12$asHZEj9ZDLwBioqgkZedseegEV2IcYwqBdkZZpnfEy672NFep.kkq', true, 'suspended'),
  ('d0eebc99-9c0b-4ef8-bb6d-6bb9bd380a77', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a77', 'Fabiola', 'Arana', 'admin@bellaaura.pe', '$2y$12$asHZEj9ZDLwBioqgkZedseegEV2IcYwqBdkZZpnfEy672NFep.kkq', true, 'active'),
  ('d0eebc99-9c0b-4ef8-bb6d-6bb9bd380a88', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a88', 'Kevin', 'Arroyo', 'admin@donfilo.pe', '$2y$12$asHZEj9ZDLwBioqgkZedseegEV2IcYwqBdkZZpnfEy672NFep.kkq', true, 'active'),
  ('d0eebc99-9c0b-4ef8-bb6d-6bb9bd380a99', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a99', 'Ximena', 'Barrios', 'admin@lumina.pe', '$2y$12$asHZEj9ZDLwBioqgkZedseegEV2IcYwqBdkZZpnfEy672NFep.kkq', true, 'active'),
  ('d0eebc99-9c0b-4ef8-bb6d-6bb9bd380aaa', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380aaa', 'Alejandra', 'Wong', 'admin@prana.pe', '$2y$12$asHZEj9ZDLwBioqgkZedseegEV2IcYwqBdkZZpnfEy672NFep.kkq', true, 'active'),
  ('d0eebc99-9c0b-4ef8-bb6d-6bb9bd380abb', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380abb', 'Fernando', 'Aliaga', 'admin@huellitas.pe', '$2y$12$asHZEj9ZDLwBioqgkZedseegEV2IcYwqBdkZZpnfEy672NFep.kkq', true, 'active')
ON CONFLICT DO NOTHING;

-- 1. Crear los usuarios del sistema para los doctores
INSERT INTO users (id, tenant_id, first_name, last_name, email, password_hash, is_owner, status) VALUES
  ('d0eebc99-9c0b-4ef8-bb6d-6bb9bd380a71', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'Carlos', 'Pérez', 'carlos.perez@luxuryspa.pe', '$2y$12$asHZEj9ZDLwBioqgkZedseegEV2IcYwqBdkZZpnfEy672NFep.kkq', false, 'active'),
  ('d0eebc99-9c0b-4ef8-bb6d-6bb9bd380a72', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'María', 'Gómez', 'maria.gomez@luxuryspa.pe', '$2y$12$asHZEj9ZDLwBioqgkZedseegEV2IcYwqBdkZZpnfEy672NFep.kkq', false, 'active')
ON CONFLICT DO NOTHING;

INSERT INTO user_roles (tenant_id, user_id, role_id) VALUES
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'd0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'd0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', 'd0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', 'c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44', 'd0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44', 'c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', 'd0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', 'c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66', 'd0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66', 'c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a77'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a77', 'd0eebc99-9c0b-4ef8-bb6d-6bb9bd380a77', 'c0eebc99-9c0b-4ef8-bb6d-6bb9bd380b77'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a88', 'd0eebc99-9c0b-4ef8-bb6d-6bb9bd380a88', 'c0eebc99-9c0b-4ef8-bb6d-6bb9bd380b88'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a99', 'd0eebc99-9c0b-4ef8-bb6d-6bb9bd380a99', 'c0eebc99-9c0b-4ef8-bb6d-6bb9bd380b99'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380aaa', 'd0eebc99-9c0b-4ef8-bb6d-6bb9bd380aaa', 'c0eebc99-9c0b-4ef8-bb6d-6bb9bd380baa'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380abb', 'd0eebc99-9c0b-4ef8-bb6d-6bb9bd380abb', 'c0eebc99-9c0b-4ef8-bb6d-6bb9bd380bbb')
ON CONFLICT DO NOTHING;

INSERT INTO user_roles (tenant_id, user_id, role_id) VALUES
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'd0eebc99-9c0b-4ef8-bb6d-6bb9bd380a71', 'c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a81'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'd0eebc99-9c0b-4ef8-bb6d-6bb9bd380a72', 'c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a81')
ON CONFLICT DO NOTHING;

INSERT INTO specialties (id, tenant_id, name, description) VALUES
  ('10eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'Medicina General', 'Atención integral primaria'),
  ('10eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'Pediatría', 'Atención especializada infantil')
ON CONFLICT DO NOTHING;

INSERT INTO service_categories (id, tenant_id, name) VALUES
  ('20ee9999-9c0b-4ef8-bb6d-6bb9bd380a01', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'Consultas Médicas')
ON CONFLICT DO NOTHING;

INSERT INTO services (id, tenant_id, category_id, name, duration_minutes, buffer_before_minutes, buffer_after_minutes, price) VALUES
  ('30eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', '20ee9999-9c0b-4ef8-bb6d-6bb9bd380a01', 'Consulta en Medicina General', 30, 5, 5, 50.00),
  ('30eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', '20ee9999-9c0b-4ef8-bb6d-6bb9bd380a01', 'Control Pediátrico', 30, 5, 10, 60.00)
ON CONFLICT DO NOTHING;

INSERT INTO professionals (id, tenant_id, user_id, type_document_id, document_number, display_name, license_number, phone) VALUES
  (
    '40eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 
    'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 
    'd0eebc99-9c0b-4ef8-bb6d-6bb9bd380a71',
    (SELECT id FROM type_documents WHERE value = 'DNI' LIMIT 1),
    '45879632',
    'Dr. Carlos Pérez', 
    'CMP-12345',
    '+51999888777'
  ),
  (
    '40eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', 
    'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 
    'd0eebc99-9c0b-4ef8-bb6d-6bb9bd380a72',
    (SELECT id FROM type_documents WHERE value = 'DNI' LIMIT 1),
    '12345678',
    'Dra. María Gómez', 
    'CMP-67890',
    '+51988777666'
  )
ON CONFLICT DO NOTHING;

INSERT INTO professional_specialties (tenant_id, professional_id, specialty_id) VALUES
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', '40eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', '10eebc99-9c0b-4ef8-bb6d-6bb9bd380a01'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', '40eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', '10eebc99-9c0b-4ef8-bb6d-6bb9bd380a02')
ON CONFLICT DO NOTHING;

-- Horario semanal de ejemplo (0 = domingo ... 6 = sábado). Dr. Pérez: lunes a
-- viernes en dos tramos. Dra. Gómez: lunes, miércoles y viernes, y sábado por la mañana.
INSERT INTO professional_schedules (tenant_id, professional_id, branch_id, weekday, starts_local, ends_local)
SELECT 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', '40eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', d, t.starts_local, t.ends_local
FROM generate_series(1, 5) AS d, (VALUES ('09:00'::time, '13:00'::time), ('15:00'::time, '19:00'::time)) AS t(starts_local, ends_local);

INSERT INTO professional_schedules (tenant_id, professional_id, branch_id, weekday, starts_local, ends_local) VALUES
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', '40eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 1, '08:00', '14:00'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', '40eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 3, '08:00', '14:00'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', '40eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 5, '08:00', '14:00'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', '40eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 6, '09:00', '13:00');

-- Bloqueos de ejemplo, con fechas relativas para que sigan vigentes tras un
-- reseteo: vacaciones de la Dra. Gómez, una capacitación del Dr. Pérez el miércoles
-- de la semana siguiente (se puede forzar). Los feriados van aparte, en holidays.
INSERT INTO availability_blocks (tenant_id, branch_id, professional_id, starts_at, ends_at, reason, kind) VALUES
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', NULL, '40eebc99-9c0b-4ef8-bb6d-6bb9bd380a02',
   (current_date + 14)::timestamp AT TIME ZONE 'America/Lima', (current_date + 21)::timestamp AT TIME ZONE 'America/Lima', 'Vacaciones', 'vacation'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', NULL, '40eebc99-9c0b-4ef8-bb6d-6bb9bd380a01',
   (date_trunc('week', current_date)::date + 9 + time '15:00') AT TIME ZONE 'America/Lima', (date_trunc('week', current_date)::date + 9 + time '17:00') AT TIME ZONE 'America/Lima', 'Capacitación interna', 'other');

INSERT INTO customers (
    id, tenant_id, code, first_name, last_name, birth_date, biological_sex, document_number, email, phone, address, district, department, medical_notes
) VALUES
(
    '50eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'CLI-0001', 'Juliana', 'Díaz', '1992-05-14', 'F', '72819043', 'juliana.diaz@gmail.com', '+51 912 345 678', 'Calle Los Pinos 145', 'Miraflores', 'Lima', 'Alergica a la penicilina'
),
(
    '50eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'CLI-0002', 'Carlos', 'Ramírez Soto', '1985-11-20', 'M', '44219873', 'carlos.ramirez@yahoo.es', '+51 987 654 321', 'Av. Benavides 789', 'Miraflores', 'Lima', 'Hipertenso controlado'
),
(
    '50eebc99-9c0b-4ef8-bb6d-6bb9bd380a03', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'CLI-0003', 'Sofía', 'Valverde Mendoza', '1998-03-02', 'F', '75412398', 'sofia.valverde@outlook.com', '+51 976 123 456', 'Av. Arequipa 3400', 'San Isidro', 'Lima', 'Sin antecedentes de importancia'
)
ON CONFLICT DO NOTHING;

-- Lista de espera de ejemplo: una persona prioritaria que solo puede por la mañana y
-- otra que espera a un médico en particular.
INSERT INTO waitlist_entries (tenant_id, branch_id, customer_id, service_id, professional_id, date_from, date_to, time_from, time_to, notes, priority, created_at) VALUES
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', '50eebc99-9c0b-4ef8-bb6d-6bb9bd380a03', '30eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', NULL, current_date, current_date + 10, '08:00', '12:00', 'Solo puede antes del mediodía.', 1, now() - interval '2 days'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', '50eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', '30eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', '40eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', current_date, NULL, NULL, NULL, NULL, 0, now() - interval '5 days');

-- Controles de ejemplo, con fechas relativas: uno vencido, uno para esta semana y uno más adelante.
INSERT INTO recalls (tenant_id, customer_id, service_id, professional_id, label, due_date, status, notes) VALUES
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', '50eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', '30eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', '40eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'Control de presión arterial', current_date - 6, 'pending', 'Traer sus últimos resultados de laboratorio.'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', '50eebc99-9c0b-4ef8-bb6d-6bb9bd380a03', '30eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', '40eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', 'Chequeo anual', current_date + 4, 'pending', NULL),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', '50eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', '30eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', '40eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'Revisión de tratamiento', current_date + 40, 'pending', NULL);

-- Acceso al portal. Los clientes ingresan con su correo; no tienen fila en users.
-- Juliana Díaz existe en dos empresas con la misma contraseña (la de las cuentas
-- de prueba) para probar el selector de empresa al iniciar sesión.
INSERT INTO customers (id, tenant_id, code, first_name, last_name, email, phone) VALUES
  ('50eebc99-9c0b-4ef8-bb6d-6bb9bd380b01', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'CLI-0001', 'Juliana', 'Díaz', 'juliana.diaz@gmail.com', '+51 912 345 678')
ON CONFLICT DO NOTHING;

UPDATE customers SET password_hash = '$2y$12$asHZEj9ZDLwBioqgkZedseegEV2IcYwqBdkZZpnfEy672NFep.kkq', must_change_password = false
WHERE id IN ('50eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', '50eebc99-9c0b-4ef8-bb6d-6bb9bd380b01');

-- El resto de clientes con correo y documento entra con su número de documento
-- como contraseña inicial y debe cambiarla al ingresar, igual que los que registra el personal.
UPDATE customers
SET password_hash = replace(crypt(document_number, gen_salt('bf', 10)), '$2a$', '$2y$'), must_change_password = true
WHERE password_hash IS NULL AND email IS NOT NULL AND document_number IS NOT NULL;

-- =====================================================================
-- MÓDULOS AMPLIADOS: inventario, fidelización, marketing y fichas
-- =====================================================================

-- Datos demo para el tenant "Centro Médico Integral"
INSERT INTO product_categories (id, tenant_id, name) VALUES
  ('61eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'Insumos de atención'),
  ('61eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'Venta al público')
ON CONFLICT DO NOTHING;

INSERT INTO warehouses (id, tenant_id, branch_id, name, is_default) VALUES
  ('62eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'Almacén Principal', true)
ON CONFLICT DO NOTHING;

INSERT INTO products (id, tenant_id, category_id, unit_id, sku, name, kind, cost, price, min_stock) VALUES
  ('63eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', '61eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', '60eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'INS-0001', 'Guantes de nitrilo (par)', 'supply', 0.60, 0.00, 100),
  ('63eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', '61eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', '60eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'INS-0002', 'Baja lenguas descartable', 'supply', 0.10, 0.00, 200),
  ('63eebc99-9c0b-4ef8-bb6d-6bb9bd380a03', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', '61eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', '60eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'VTA-0001', 'Protector solar FPS 50', 'retail', 35.00, 59.90, 5)
ON CONFLICT DO NOTHING;

INSERT INTO stock_balances (tenant_id, warehouse_id, product_id, quantity) VALUES
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', '62eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', '63eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 500),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', '62eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', '63eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', 1000),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', '62eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', '63eebc99-9c0b-4ef8-bb6d-6bb9bd380a03', 24)
ON CONFLICT DO NOTHING;

INSERT INTO inventory_movements (tenant_id, warehouse_id, product_id, type, quantity, unit_cost, balance_after, reason) VALUES
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', '62eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', '63eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'initial', 500, 0.60, 500, 'Carga inicial de inventario'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', '62eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', '63eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', 'initial', 1000, 0.10, 1000, 'Carga inicial de inventario'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', '62eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', '63eebc99-9c0b-4ef8-bb6d-6bb9bd380a03', 'initial', 24, 35.00, 24, 'Carga inicial de inventario');

-- Insumos que consume cada servicio al completarse la atención
INSERT INTO service_supplies (tenant_id, service_id, product_id, quantity) VALUES
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', '30eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', '63eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', '30eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', '63eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', '30eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', '63eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 1)
ON CONFLICT DO NOTHING;

INSERT INTO membership_plans (id, tenant_id, name, description, price, billing_period, discount_percent, sessions_included) VALUES
  ('64eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'Plan Salud Familiar', 'Descuento en consultas y una consulta general incluida al mes', 89.00, 'monthly', 10, 1)
ON CONFLICT DO NOTHING;

INSERT INTO package_plans (id, tenant_id, name, description, price, validity_days) VALUES
  ('65eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'Paquete 5 controles pediátricos', 'Cinco controles con precio preferencial', 250.00, 180)
ON CONFLICT DO NOTHING;

INSERT INTO package_plan_items (tenant_id, package_plan_id, service_id, sessions) VALUES
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', '65eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', '30eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', 5)
ON CONFLICT DO NOTHING;

INSERT INTO customer_tags (id, tenant_id, name, color) VALUES
  ('66eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'VIP', '#7A3FF2'),
  ('66eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'Nuevo', '#0E9F6E'),
  ('66eebc99-9c0b-4ef8-bb6d-6bb9bd380a03', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'En riesgo', '#C27803')
ON CONFLICT DO NOTHING;

INSERT INTO customer_tag_assignments (tenant_id, customer_id, customer_tag_id) VALUES
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', '50eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', '66eebc99-9c0b-4ef8-bb6d-6bb9bd380a01'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', '50eebc99-9c0b-4ef8-bb6d-6bb9bd380a03', '66eebc99-9c0b-4ef8-bb6d-6bb9bd380a02')
ON CONFLICT DO NOTHING;

INSERT INTO customer_consents (tenant_id, customer_id, channel, granted, source, granted_at) VALUES
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', '50eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'whatsapp', true, 'staff', now()),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', '50eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'marketing', true, 'staff', now()),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', '50eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', 'whatsapp', true, 'staff', now())
ON CONFLICT DO NOTHING;

INSERT INTO marketing_templates (id, tenant_id, name, channel, body, variables) VALUES
  ('67eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'Recordatorio de cita', 'whatsapp', 'Hola {{first_name}}, te recordamos tu cita del {{appointment_date}} a las {{appointment_time}}. Responde 1 para confirmar.', '["first_name", "appointment_date", "appointment_time"]'::jsonb),
  ('67eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'Te extrañamos', 'whatsapp', 'Hola {{first_name}}, hace tiempo que no nos visitas. Reserva tu próxima cita cuando gustes.', '["first_name"]'::jsonb)
ON CONFLICT DO NOTHING;

INSERT INTO automation_rules (id, tenant_id, name, trigger_event, conditions, channel, marketing_template_id, delay_minutes, is_active) VALUES
  ('68eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'Recuperación de clientes inactivos', 'customer.inactive', '{"days": 90}'::jsonb, 'whatsapp', '67eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', 0, false)
ON CONFLICT DO NOTHING;

INSERT INTO record_templates (id, tenant_id, name, description, fields, consent_text, require_consent, is_default) VALUES
  (
    '69eebc99-9c0b-4ef8-bb6d-6bb9bd380a01',
    'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11',
    'Ficha de consulta general',
    'Ficha base para consultas de medicina general y pediatría',
    '[
      {"key": "reason", "label": "Motivo de consulta", "type": "textarea", "required": true},
      {"key": "history", "label": "Antecedentes", "type": "textarea", "required": false},
      {"key": "blood_pressure", "label": "Presión arterial", "type": "text", "required": false},
      {"key": "weight_kg", "label": "Peso (kg)", "type": "number", "required": false},
      {"key": "diagnosis", "label": "Diagnóstico", "type": "textarea", "required": true},
      {"key": "treatment", "label": "Tratamiento e indicaciones", "type": "textarea", "required": false},
      {"key": "next_control", "label": "Próximo control", "type": "next_control", "required": false}
    ]'::jsonb,
    'Declaro que recibí información sobre el procedimiento y autorizo la atención.',
    false,
    true
  )
ON CONFLICT DO NOTHING;

INSERT INTO record_template_services (tenant_id, record_template_id, service_id) VALUES
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', '69eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', '30eebc99-9c0b-4ef8-bb6d-6bb9bd380a01'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', '69eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', '30eebc99-9c0b-4ef8-bb6d-6bb9bd380a02')
ON CONFLICT DO NOTHING;

-- =====================================================================
-- DATOS DE DEMOSTRACIÓN: sucursales, feriados, catálogo mínimo y citas en todas las empresas
-- =====================================================================
-- Todo va con fechas relativas a hoy, para que tras cada reseteo haya historia
-- reciente y citas próximas que convivan con los bloqueos, ausencias y feriados.

-- Feriados nacionales de este año y del siguiente, para todas las sedes de cada
-- empresa. Es lo mismo que recibe una empresa nueva al crearse.
INSERT INTO holidays (tenant_id, date, name)
SELECT t.id, make_date(y.year, v.month, v.day), v.name
FROM tenants t
CROSS JOIN generate_series(extract(year FROM current_date)::int, extract(year FROM current_date)::int + 1) AS y(year)
CROSS JOIN (VALUES
  (1, 1, 'Año Nuevo'), (5, 1, 'Día del Trabajo'), (6, 7, 'Batalla de Arica y Día de la Bandera'),
  (6, 29, 'San Pedro y San Pablo'), (7, 23, 'Día de la Fuerza Aérea'), (7, 28, 'Fiestas Patrias'),
  (7, 29, 'Fiestas Patrias'), (8, 6, 'Batalla de Junín'), (8, 30, 'Santa Rosa de Lima'),
  (10, 8, 'Combate de Angamos'), (11, 1, 'Todos los Santos'), (12, 8, 'Inmaculada Concepción'),
  (12, 9, 'Batalla de Ayacucho'), (12, 25, 'Navidad')
) AS v(month, day, name)
ON CONFLICT DO NOTHING;

-- Un feriado propio de una sola sede, que no cierra: solo se recuerda en la agenda.
INSERT INTO holidays (tenant_id, branch_id, date, name, closed) VALUES
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', current_date + 12, 'Aniversario de la sede', false)
ON CONFLICT DO NOTHING;

-- Sucursales: cada empresa tiene entre dos y cinco, para ver cómo se reparte la
-- agenda por sede. Las primeras ya existían; aquí reciben su dirección.
UPDATE branches AS b SET address = v.address, district = v.district, department = 'Lima', phone = v.phone
FROM (VALUES
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'Av. Larco 123', 'Miraflores', '(01) 445 1100'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', 'Av. Javier Prado Oeste 450', 'San Isidro', '(01) 221 4500'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44', 'Jr. de la Unión 500', 'Cercado de Lima', '(01) 427 8800'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', 'Av. Universitaria 1800', 'San Martín de Porres', '(01) 531 2200'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66', 'Calle Las Begonias 321', 'Los Olivos', '(01) 522 9090'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a77', 'Av. Arequipa 1100', 'Lince', '(01) 265 3300')
) AS v(id, address, district, phone)
WHERE b.id = v.id::uuid;

INSERT INTO branches (id, tenant_id, company_id, name, address, district, department, phone, timezone, status)
SELECT v.id::uuid, v.tenant_id::uuid, (SELECT c.id FROM companies c WHERE c.tenant_id = v.tenant_id::uuid ORDER BY c.created_at LIMIT 1),
       v.name, v.address, v.district, 'Lima', v.phone, 'America/Lima', 'active'
FROM (VALUES
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c11', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'Sede San Borja', 'Av. Aviación 2850', 'San Borja', '(01) 475 6600'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c12', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'Sede Surco', 'Av. Caminos del Inca 1570', 'Santiago de Surco', '(01) 344 7700'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c21', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'Sede Miraflores', 'Av. Benavides 620', 'Miraflores', '(01) 241 1010'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c22', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'Sede La Molina', 'Av. La Molina 1167', 'La Molina', '(01) 349 2020'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c23', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'Sede Los Olivos', 'Av. Carlos Izaguirre 813', 'Los Olivos', '(01) 523 3030'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c24', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'Sede Callao', 'Av. Sáenz Peña 345', 'Callao', '(01) 429 4040'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c31', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', 'Consultorio San Miguel', 'Av. La Marina 2355', 'San Miguel', '(01) 578 5050'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c41', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44', 'Sede Independencia', 'Av. Túpac Amaru 1420', 'Independencia', '(01) 526 6060'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c42', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44', 'Sede Comas', 'Av. Universitaria 6850', 'Comas', '(01) 537 7070'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c51', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', 'Punto de toma Comas', 'Av. Belaúnde 990', 'Comas', '(01) 541 8080'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c61', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66', 'Sede Jesús María', 'Av. Brasil 1250', 'Jesús María', '(01) 330 1212'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c62', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66', 'Sede Breña', 'Av. Venezuela 980', 'Breña', '(01) 424 1313'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c63', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66', 'Sede Pueblo Libre', 'Av. Bolívar 740', 'Pueblo Libre', '(01) 461 1414'),
  -- Las empresas de los demás rubros tienen una sola sede.
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380d71', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a77', 'Local Barranco', 'Av. Grau 320', 'Barranco', '(01) 247 5151'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380d81', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a88', 'Local Magdalena', 'Jr. Castilla 640', 'Magdalena del Mar', '(01) 263 6262'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380d91', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a99', 'Sede San Isidro', 'Calle Las Camelias 780', 'San Isidro', '(01) 422 7373'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380da1', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380aaa', 'Estudio Miraflores', 'Calle Berlín 455', 'Miraflores', '(01) 446 8484'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380db1', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380abb', 'Sede Jesús María', 'Av. Salaverry 2020', 'Jesús María', '(01) 471 9595')
) AS v(id, tenant_id, name, address, district, phone)
ON CONFLICT DO NOTHING;

-- Horario de atención: lunes a viernes de 08:00 a 20:00 y sábados por la mañana.
-- Las sedes más pequeñas cierran a las 18:00 y, salvo una, no abren los sábados.
INSERT INTO branch_hours (tenant_id, branch_id, weekday, opens_local, closes_local)
SELECT b.tenant_id, b.id, d, '08:00'::time,
       CASE WHEN d = 6 THEN '14:00'::time WHEN b.id::text ~ 'c(12|23|24|42|63)$' THEN '18:00'::time ELSE '20:00'::time END
FROM branches b
CROSS JOIN generate_series(1, 6) AS d
WHERE NOT (d = 6 AND b.id::text ~ 'c(12|23|24|42)$')
  AND NOT EXISTS (SELECT 1 FROM branch_hours h WHERE h.branch_id = b.id);

-- Salas y equipos en algunas sedes nuevas (las de Centro Médico ya tenían sus consultorios).
INSERT INTO rooms (tenant_id, branch_id, name, description, kind)
SELECT b.tenant_id, b.id, v.name, v.description, v.kind
FROM (VALUES
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c11', 'Consultorio 201', 'Medicina general', 'room'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c11', 'Ecógrafo', 'Equipo compartido entre consultorios', 'equipment'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', 'Sillón 1', 'Unidad dental', 'room'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', 'Sillón 2', 'Unidad dental', 'room'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', 'Equipo de rayos X', 'Radiografía periapical', 'equipment'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', 'Gimnasio terapéutico', 'Sala de ejercicios', 'room'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', 'Camilla 1', 'Terapia manual', 'room'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380d71', 'Cabina de spa', 'Faciales y masajes', 'room'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380d81', 'Sillón 1', 'Puesto de corte', 'room'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380d81', 'Sillón 2', 'Puesto de corte', 'room'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380d91', 'Cabina 1', 'Tratamientos faciales', 'room'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380d91', 'Equipo láser', 'Depilación láser', 'equipment'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380da1', 'Sala principal', 'Yoga, pilates y funcional', 'room'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380db1', 'Consultorio 1', 'Consulta y vacunación', 'room'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380db1', 'Sala de grooming', 'Baño y corte', 'room')
) AS v(branch_id, name, description, kind)
JOIN branches b ON b.id = v.branch_id::uuid
ON CONFLICT DO NOTHING;

-- Algunos equipos están instalados en una sala de su misma sede.
UPDATE rooms e SET parent_id = r.id
FROM rooms r
WHERE e.kind = 'equipment' AND r.kind = 'room' AND r.branch_id = e.branch_id
  AND (e.name, r.name) IN (('Ecógrafo', 'Consultorio 201'), ('Equipo de rayos X', 'Sillón 1'), ('Equipo láser', 'Cabina 1'));

-- El laboratorio está en el rubro genérico, cuyo catálogo es neutro: sus servicios
-- se escriben aquí. Las demás empresas reciben más abajo el catálogo de su rubro.
INSERT INTO service_categories (id, tenant_id, name) VALUES
  ('20ee9999-9c0b-4ef8-bb6d-6bb9bd380a55', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', 'Análisis')
ON CONFLICT DO NOTHING;

INSERT INTO services (tenant_id, category_id, name, duration_minutes, buffer_before_minutes, buffer_after_minutes, price)
SELECT v.tenant_id::uuid, v.category_id::uuid, v.name, v.minutes, 0, 5, v.price
FROM (VALUES
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', '20ee9999-9c0b-4ef8-bb6d-6bb9bd380a55', 'Toma de muestra', 15, 25.00),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', '20ee9999-9c0b-4ef8-bb6d-6bb9bd380a55', 'Entrega y lectura de resultados', 20, 30.00)
) AS v(tenant_id, category_id, name, minutes, price)
WHERE NOT EXISTS (SELECT 1 FROM services s WHERE s.tenant_id = v.tenant_id::uuid AND s.name = v.name);

-- Todas las empresas reciben el catálogo completo de su rubro (categorías, servicios
-- y plantilla de ficha), igual que una empresa que se registra
-- (Tenant::installBusinessTemplate). El rubro genérico queda fuera: su catálogo es neutro.
INSERT INTO service_categories (tenant_id, name)
SELECT t.id, c.item->>'name'
FROM tenants t
JOIN business_types b ON b.id = t.business_type_id
CROSS JOIN LATERAL jsonb_array_elements(b.template->'categories') AS c(item)
WHERE b.slug <> 'generic'
ON CONFLICT (tenant_id, name) DO NOTHING;

INSERT INTO services (
    tenant_id, category_id, name, code, duration_minutes, buffer_after_minutes, price, requires_deposit,
    online_booking, is_virtual, kind, capacity, recall_label, recall_days, status_id
)
SELECT t.id, sc.id, s.item->>'name', s.item->>'code', (s.item->>'duration_minutes')::int,
       coalesce((s.item->>'buffer_after_minutes')::int, 0), coalesce((s.item->>'price')::numeric, 0),
       coalesce((s.item->>'requires_deposit')::boolean, false), coalesce((s.item->>'online_booking')::boolean, true),
       coalesce((s.item->>'is_virtual')::boolean, false), coalesce(s.item->>'kind', 'individual'),
       coalesce((s.item->>'capacity')::int, 1), s.item->'recall'->>'label', (s.item->'recall'->>'days')::int,
       'f1eebc99-9c0b-4ef8-bb6d-6bb9bd380a41'
FROM tenants t
JOIN business_types b ON b.id = t.business_type_id
CROSS JOIN LATERAL jsonb_array_elements(b.template->'categories') AS c(item)
CROSS JOIN LATERAL jsonb_array_elements(c.item->'services') AS s(item)
JOIN service_categories sc ON sc.tenant_id = t.id AND sc.name = c.item->>'name'
WHERE b.slug <> 'generic'
  AND NOT EXISTS (SELECT 1 FROM services x WHERE x.tenant_id = t.id AND (x.code = s.item->>'code' OR x.name = s.item->>'name'))
  -- Centro Médico ya tiene su propia consulta de medicina general.
  AND NOT (t.id = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11' AND s.item->>'code' = 'CON-01');

-- Controles que se agendan con otro servicio (p. ej. esterilización → control post operatorio).
UPDATE services AS s SET recall_service_id = r.id
FROM tenants t
JOIN business_types b ON b.id = t.business_type_id
CROSS JOIN LATERAL jsonb_array_elements(b.template->'categories') AS c(item)
CROSS JOIN LATERAL jsonb_array_elements(c.item->'services') AS j(item)
JOIN services r ON r.tenant_id = t.id AND r.code = j.item->'recall'->>'service_code'
WHERE b.slug <> 'generic'
  AND s.tenant_id = t.id AND s.code = j.item->>'code' AND s.recall_service_id IS NULL;

INSERT INTO record_templates (tenant_id, name, fields, consent_text, require_consent, is_default)
SELECT t.id, b.template->'record_template'->>'name', b.template->'record_template'->'fields',
       b.template->'record_template'->>'consent_text',
       coalesce((b.template->'record_template'->>'require_consent')::boolean, false),
       NOT EXISTS (SELECT 1 FROM record_templates x WHERE x.tenant_id = t.id AND x.is_default)
FROM tenants t
JOIN business_types b ON b.id = t.business_type_id
WHERE b.slug <> 'generic'
  AND b.template->'record_template' IS NOT NULL
ON CONFLICT (tenant_id, name) DO NOTHING;

-- Servicios que el rubro atiende con ficha.
INSERT INTO record_template_services (tenant_id, record_template_id, service_id)
SELECT t.id, rt.id, sv.id
FROM tenants t
JOIN business_types b ON b.id = t.business_type_id
JOIN record_templates rt ON rt.tenant_id = t.id AND rt.name = b.template->'record_template'->>'name'
CROSS JOIN LATERAL jsonb_array_elements(b.template->'categories') AS c(item)
CROSS JOIN LATERAL jsonb_array_elements(c.item->'services') AS s(item)
JOIN services sv ON sv.tenant_id = t.id AND sv.code = s.item->>'code'
WHERE b.slug <> 'generic'
  AND coalesce((s.item->>'uses_record')::boolean, false)
ON CONFLICT DO NOTHING;

-- Servicios propios de cada empresa, además de los de su rubro.
CREATE TEMP TABLE demo_services (tenant_id uuid, category text, name text, minutes int, price numeric, kind text, capacity int) ON COMMIT DROP;
INSERT INTO demo_services VALUES
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'Procedimientos', 'Electrocardiograma', 20, 80.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'Procedimientos', 'Curación de heridas', 20, 45.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'Procedimientos', 'Inyectable y control de signos', 15, 20.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'Chequeos', 'Chequeo preventivo anual', 45, 180.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'Odontología general', 'Fluorización', 20, 50.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'Odontología general', 'Sellantes (por pieza)', 20, 60.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'Odontopediatría', 'Primera visita del niño', 30, 70.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'Especialidades', 'Corona de porcelana', 60, 900.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', 'Terapia', 'Terapia familiar', 80, 200.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', 'Terapia', 'Orientación vocacional', 60, 130.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', 'Evaluaciones', 'Evaluación psicológica', 90, 250.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', 'Evaluaciones', 'Entrega de informe psicológico', 30, 120.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44', 'Evaluación', 'Evaluación postural', 40, 90.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44', 'Terapia', 'Masaje descontracturante', 30, 60.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44', 'Terapia', 'Punción seca', 30, 70.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44', 'Terapia', 'Drenaje linfático', 50, 95.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', 'Análisis', 'Hemograma completo', 15, 35.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', 'Análisis', 'Perfil lipídico', 15, 45.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', 'Análisis', 'Glucosa en ayunas', 10, 15.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', 'Análisis', 'Examen completo de orina', 10, 20.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', 'Análisis', 'Prueba de embarazo en sangre', 15, 40.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', 'A domicilio', 'Toma de muestra a domicilio', 30, 50.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66', 'Procedimientos', 'Nebulización', 20, 35.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66', 'Procedimientos', 'Ecografía abdominal', 30, 120.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66', 'Enfermería', 'Control de enfermería', 20, 30.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66', 'Enfermería', 'Control de presión y glucosa', 15, 15.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a77', 'Cabello', 'Corte de caballero', 30, 40.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a77', 'Cabello', 'Peinado para evento', 60, 90.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a77', 'Uñas', 'Uñas acrílicas', 90, 120.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a77', 'Facial y spa', 'Depilación con cera (piernas)', 40, 60.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a88', 'Cortes', 'Diseño y líneas', 15, 15.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a88', 'Barba', 'Tinte de barba', 30, 35.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a88', 'Cuidado facial', 'Limpieza facial express', 30, 40.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a88', 'Cuidado facial', 'Mascarilla negra', 20, 25.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a99', 'Facial', 'Hidratación profunda', 50, 150.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a99', 'Facial', 'Microdermoabrasión', 45, 170.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a99', 'Corporal', 'Masaje reductor', 50, 110.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a99', 'Corporal', 'Cavitación', 40, 130.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380aaa', 'Clases grupales', 'Stretching', 45, 30.00, 'group', 12),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380aaa', 'Personalizado', 'Clase privada en pareja', 60, 180.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380aaa', 'Personalizado', 'Plan de entrenamiento', 45, 90.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380aaa', 'Personalizado', 'Asesoría nutricional', 40, 100.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380abb', 'Consulta y prevención', 'Consulta de urgencia', 30, 120.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380abb', 'Consulta y prevención', 'Colocación de microchip', 15, 80.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380abb', 'Cirugía', 'Profilaxis dental', 60, 250.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380abb', 'Grooming', 'Baño y corte (raza grande)', 120, 110.00, 'individual', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380abb', 'Grooming', 'Corte de uñas', 15, 20.00, 'individual', 1);

INSERT INTO service_categories (tenant_id, name)
SELECT DISTINCT v.tenant_id, v.category FROM demo_services v
ON CONFLICT (tenant_id, name) DO NOTHING;

INSERT INTO services (tenant_id, category_id, name, duration_minutes, price, kind, capacity, status_id)
SELECT v.tenant_id, sc.id, v.name, v.minutes, v.price, v.kind, v.capacity, 'f1eebc99-9c0b-4ef8-bb6d-6bb9bd380a41'
FROM demo_services v
JOIN service_categories sc ON sc.tenant_id = v.tenant_id AND sc.name = v.category
WHERE NOT EXISTS (SELECT 1 FROM services s WHERE s.tenant_id = v.tenant_id AND s.name = v.name);

-- Un profesional atiende en una sede cuando tiene horario ahí (professional_schedules).
-- En el seed cada uno atiende en una sola sede:
--  · el primero de cada empresa, de lunes a viernes en dos tramos;
--  · el segundo, de lunes a sábado por la mañana;
--  · el resto del equipo de cada sede va más abajo, en demo_staff.
INSERT INTO professionals (id, tenant_id, display_name, phone) VALUES
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b21', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'Dra. Lucía Fernández', '+51 955 100 221'),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b22', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'Dr. Andrés Salas', '+51 955 100 222'),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b31', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', 'Ps. Valeria Rojas', '+51 955 100 331'),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b32', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', 'Ps. Diego Montoya', '+51 955 100 332'),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b41', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44', 'Lic. Renato Castillo', '+51 955 100 441'),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b42', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44', 'Lic. Paola Vega', '+51 955 100 442'),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b51', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', 'Tec. Miriam Soto', '+51 955 100 551'),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b52', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', 'Tec. Jorge Huamán', '+51 955 100 552'),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b61', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66', 'Dra. Carmen Ruiz', '+51 955 100 661'),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b62', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66', 'Dr. Luis Tapia', '+51 955 100 662'),
  -- Los de las sedes adicionales.
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b13', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'Dr. Jorge Lazo', '+51 955 100 113'),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b23', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'Dra. Mariela Quispe', '+51 955 100 223'),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b24', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'Dr. Hugo Cárdenas', '+51 955 100 224'),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b25', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'Dra. Silvia Ponce', '+51 955 100 225'),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b43', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44', 'Lic. Bruno Alarcón', '+51 955 100 443'),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b63', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66', 'Dra. Teresa Bravo', '+51 955 100 663'),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b64', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66', 'Dr. Mario Peralta', '+51 955 100 664'),
  -- Los de las empresas de una sola sede.
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b71', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a77', 'Karla Mendívil', '+51 955 100 771'),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b72', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a77', 'Brenda Solano', '+51 955 100 772'),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b81', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a88', 'Kevin Arroyo', '+51 955 100 881'),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b82', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a88', 'Piero Zavala', '+51 955 100 882'),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b91', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a99', 'Dra. Ximena Barrios', '+51 955 100 991'),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b92', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a99', 'Lic. Carolina Ugarte', '+51 955 100 992'),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380ba1', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380aaa', 'Alejandra Wong', '+51 955 100 101'),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380ba2', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380aaa', 'Sebastián Llerena', '+51 955 100 102'),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380bb1', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380abb', 'Dr. Fernando Aliaga', '+51 955 100 111'),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380bb2', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380abb', 'Dra. Rocío Meza', '+51 955 100 112')
ON CONFLICT DO NOTHING;

-- Lunes a viernes en una sola sede. Los dos tramos (mañana y tarde) son del primero
-- de cada empresa; los de las sedes adicionales atienden de corrido.
INSERT INTO professional_schedules (tenant_id, professional_id, branch_id, weekday, starts_local, ends_local)
SELECT p.tenant_id, p.id, v.branch_id::uuid, d, t.starts_local, t.ends_local
FROM (VALUES
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b21', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', true),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b31', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44', true),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b41', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', true),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b51', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66', true),
  -- La sede principal de San Judas está inactiva en el seed: atiende en Jesús María.
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b61', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c61', true),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b71', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380d71', true),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b81', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380d81', true),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b91', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380d91', true),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380ba1', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380da1', true),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380bb1', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380db1', true),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b13', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c12', false),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b23', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c22', false),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b24', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c23', false),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b25', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c24', false),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b43', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c42', false),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b64', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c63', false)
) AS v(professional_id, branch_id, split)
JOIN professionals p ON p.id = v.professional_id::uuid
CROSS JOIN generate_series(1, 5) AS d
CROSS JOIN LATERAL (
  SELECT * FROM (VALUES ('09:00'::time, '13:00'::time, true), ('15:00'::time, '19:00'::time, true), ('09:00'::time, '17:00'::time, false)) AS r(starts_local, ends_local, split)
  WHERE r.split = v.split
) AS t
WHERE NOT EXISTS (SELECT 1 FROM professional_schedules s WHERE s.professional_id = p.id);

-- El segundo de cada empresa: lunes a sábado de 08:00 a 14:00, en su sede.
INSERT INTO professional_schedules (tenant_id, professional_id, branch_id, weekday, starts_local, ends_local)
SELECT p.tenant_id, p.id, v.branch_id::uuid, d, '08:00'::time, '14:00'::time
FROM (VALUES
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b22', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33'),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b32', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44'),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b42', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55'),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b52', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66'),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b62', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c61'),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b63', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c62'),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b72', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380d71'),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b82', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380d81'),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380b92', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380d91'),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380ba2', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380da1'),
  ('40eebc99-9c0b-4ef8-bb6d-6bb9bd380bb2', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380db1')
) AS v(professional_id, branch_id)
JOIN professionals p ON p.id = v.professional_id::uuid
CROSS JOIN generate_series(1, 6) AS d
WHERE NOT EXISTS (SELECT 1 FROM professional_schedules s WHERE s.professional_id = p.id);

-- El resto del equipo: cada sede activa queda con dos a cuatro personas, cada una en
-- una sola sede. El turno se recorta al horario de la sede (branch_hours): quien
-- atiende por la mañana trabaja el sábado solo donde la sede abre ese día.
--   split = 09:00–13:00 y 15:00–18:00 · day = 09:00–17:00
--   morning = 08:00–14:00 · afternoon = 12:00–18:00
CREATE TEMP TABLE demo_staff (branch_id uuid, name text, shift text) ON COMMIT DROP;
INSERT INTO demo_staff VALUES
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'Dra. Patricia Núñez', 'afternoon'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c11', 'Dr. Álvaro Benavides', 'split'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c11', 'Dra. Fátima Cornejo', 'morning'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c11', 'Dr. Ricardo Tello', 'afternoon'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c12', 'Dra. Noelia Huertas', 'morning'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', 'Dr. Gonzalo Rivas', 'afternoon'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c21', 'Dra. Antonella Vera', 'split'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c21', 'Dr. Mauricio León', 'morning'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c21', 'Dra. Katia Robles', 'afternoon'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c22', 'Dr. Emilio Chang', 'morning'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c23', 'Dra. Yolanda Pizarro', 'morning'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c24', 'Dr. Raúl Mejía', 'morning'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44', 'Ps. Lucía Arévalo', 'afternoon'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c31', 'Ps. Marcela Ibarra', 'split'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c31', 'Ps. Julián Castro', 'morning'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', 'Lic. Daniela Flores', 'afternoon'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c41', 'Lic. Óscar Villanueva', 'split'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c41', 'Lic. Ana Lucía Paz', 'morning'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c42', 'Lic. Miguel Requena', 'morning'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66', 'Tec. Rosa Calderón', 'afternoon'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c51', 'Tec. Alberto Nima', 'split'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c51', 'Tec. Vanessa Ccori', 'morning'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c61', 'Dr. Ernesto Vidal', 'afternoon'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c62', 'Dra. Liliana Poma', 'split'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380c63', 'Dr. Javier Otero', 'morning'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380d71', 'Pamela Rázuri', 'afternoon'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380d71', 'Nicole Cabrera', 'day'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380d81', 'Jhonatan Ríos', 'afternoon'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380d81', 'Luis Ángel Soto', 'day'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380d81', 'Bryan Quispe', 'morning'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380d91', 'Lic. Melissa Tovar', 'afternoon'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380d91', 'Dra. Paola Guzmán', 'day'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380da1', 'Camila Echevarría', 'afternoon'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380da1', 'Rodrigo Salas', 'day'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380db1', 'Dra. Andrea Linares', 'afternoon'),
  ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380db1', 'Dr. César Palacios', 'day');

INSERT INTO professionals (tenant_id, display_name, phone)
SELECT b.tenant_id, v.name, '+51 956 200 ' || lpad((row_number() OVER (ORDER BY b.tenant_id, v.name))::text, 3, '0')
FROM demo_staff v
JOIN branches b ON b.id = v.branch_id
WHERE NOT EXISTS (SELECT 1 FROM professionals p WHERE p.tenant_id = b.tenant_id AND p.display_name = v.name);

INSERT INTO professional_schedules (tenant_id, professional_id, branch_id, weekday, starts_local, ends_local)
SELECT p.tenant_id, p.id, b.id, h.weekday, r.starts_local, r.ends_local
FROM demo_staff v
JOIN branches b ON b.id = v.branch_id
JOIN professionals p ON p.tenant_id = b.tenant_id AND p.display_name = v.name
JOIN (VALUES
  ('split', '09:00'::time, '13:00'::time), ('split', '15:00'::time, '18:00'::time), ('day', '09:00'::time, '17:00'::time),
  ('morning', '08:00'::time, '14:00'::time), ('afternoon', '12:00'::time, '18:00'::time)
) AS r(shift, starts_local, ends_local) ON r.shift = v.shift
JOIN branch_hours h ON h.branch_id = b.id AND h.opens_local <= r.starts_local AND h.closes_local >= r.ends_local
WHERE NOT EXISTS (SELECT 1 FROM professional_schedules s WHERE s.professional_id = p.id);

-- Cada profesional tiene su cuenta para entrar al sistema, igual que cuando la empresa
-- lo registra desde la pantalla. Así el equipo de cada empresa de ejemplo cabe en su plan:
-- usuarios (dueño + profesionales) y profesionales dentro del tope.
INSERT INTO roles (tenant_id, name, slug, description, is_system)
SELECT t.id, 'Profesional', 'professional', 'Atiende sus propias citas', true
FROM tenants t
ON CONFLICT (tenant_id, slug) DO NOTHING;

-- Nombre sin el título (Dr., Dra., Ps., Lic., Tec.) y correo con el dominio de su empresa.
CREATE TEMP TABLE demo_accounts ON COMMIT DROP AS
SELECT p.id AS professional_id, p.tenant_id, gen_random_uuid() AS user_id,
       split_part(n.name, ' ', 1) AS first_name,
       coalesce(nullif(substr(n.name, length(split_part(n.name, ' ', 1)) + 2), ''), split_part(n.name, ' ', 1)) AS last_name,
       translate(lower(replace(n.name, ' ', '.')), 'áéíóúñ', 'aeioun') || '@' || replace(t.slug, '-', '') || '.pe' AS email
FROM professionals p
JOIN tenants t ON t.id = p.tenant_id
CROSS JOIN LATERAL (SELECT regexp_replace(p.display_name, '^(Dra?|Ps|Lic|Tec)\.\s+', '') AS name) AS n
WHERE p.user_id IS NULL;

INSERT INTO users (id, tenant_id, first_name, last_name, email, password_hash, is_owner, status)
SELECT a.user_id, a.tenant_id, a.first_name, a.last_name, a.email, '$2y$12$asHZEj9ZDLwBioqgkZedseegEV2IcYwqBdkZZpnfEy672NFep.kkq', false, 'active'
FROM demo_accounts a;

INSERT INTO user_roles (tenant_id, user_id, role_id)
SELECT a.tenant_id, a.user_id, r.id
FROM demo_accounts a
JOIN roles r ON r.tenant_id = a.tenant_id AND r.slug = 'professional';

UPDATE professionals AS p SET user_id = a.user_id
FROM demo_accounts a
WHERE a.professional_id = p.id;

-- Personal que no atiende citas: recepción, caja y gerencia de sede. Los roles nacen
-- aquí sin permisos; la API se los da la primera vez (App\Support\Access::ensureRoles).
-- Solo en empresas con lugar en su plan: Don Filo y Huellitas quedan como estaban.
INSERT INTO roles (tenant_id, name, slug, description, is_system)
SELECT t.id, v.name, v.slug, v.description, true
FROM tenants t
CROSS JOIN (VALUES
  ('Gerente de sede', 'manager', 'Lleva el día a día de una sede: agenda, equipo, ventas y caja.'),
  ('Recepción', 'reception', 'Agenda, recibe y cobra a quien llega. No elimina ni devuelve dinero.'),
  ('Caja', 'cashier', 'Cobra y lleva la caja. Ve la agenda y los clientes sin cambiarlos.')
) AS v(name, slug, description)
ON CONFLICT (tenant_id, slug) DO NOTHING;

-- branch_n: la sede de la persona, contando desde 0 por orden alfabético.
CREATE TEMP TABLE demo_office ON COMMIT DROP AS
SELECT gen_random_uuid() AS user_id, v.tenant_id::uuid AS tenant_id, v.slug, v.first_name, v.last_name, v.email, v.phone,
       (SELECT b.id FROM branches b WHERE b.tenant_id = v.tenant_id::uuid ORDER BY b.name LIMIT 1 OFFSET v.branch_n) AS branch_id
FROM (VALUES
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'reception', 'Rosa', 'Medina Paz', 'rosa.medina@luxuryspa.pe', '+51 957 300 101', 0),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'cashier', 'Karina', 'Lozano Vera', 'karina.lozano@luxuryspa.pe', '+51 957 300 102', 0),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'manager', 'Gustavo', 'Paredes Ruiz', 'gustavo.paredes@luxuryspa.pe', '+51 957 300 103', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'reception', 'Milagros', 'Chávez Soto', 'milagros.chavez@dentalplus.pe', '+51 957 300 201', 0),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'manager', 'Enrique', 'Tapia León', 'enrique.tapia@dentalplus.pe', '+51 957 300 202', 1),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a77', 'reception', 'Fiorella', 'Campos Díaz', 'fiorella.campos@bellaaura.pe', '+51 957 300 301', 0)
) AS v(tenant_id, slug, first_name, last_name, email, phone, branch_n);

INSERT INTO users (id, tenant_id, branch_id, first_name, last_name, email, phone, password_hash, is_owner, status)
SELECT o.user_id, o.tenant_id, o.branch_id, o.first_name, o.last_name, o.email, o.phone, '$2y$12$asHZEj9ZDLwBioqgkZedseegEV2IcYwqBdkZZpnfEy672NFep.kkq', false, 'active'
FROM demo_office o;

INSERT INTO user_roles (tenant_id, user_id, role_id)
SELECT o.tenant_id, o.user_id, r.id
FROM demo_office o
JOIN roles r ON r.tenant_id = o.tenant_id AND r.slug = o.slug;

INSERT INTO customers (tenant_id, code, first_name, last_name, document_number, email, phone)
SELECT v.tenant_id::uuid,
       'CLI-' || lpad((v.n + (SELECT count(*) FROM customers c WHERE c.tenant_id = v.tenant_id::uuid))::text, 4, '0'),
       v.first_name, v.last_name, v.document_number, v.email, v.phone
FROM (VALUES
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 1, 'Marco', 'Aguilar Rivas', '41526378', 'marco.aguilar@example.com', '+51 944 210 001'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 2, 'Daniela', 'Paredes León', '70451236', 'daniela.paredes@example.com', '+51 944 210 002'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 3, 'Héctor', 'Zúñiga Mora', '09874512', 'hector.zuniga@example.com', '+51 944 210 003'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', 1, 'Camila', 'Torres Vidal', '72103456', 'camila.torres@example.com', '+51 944 330 001'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', 2, 'Rodrigo', 'Benites Cano', '45780123', 'rodrigo.benites@example.com', '+51 944 330 002'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', 3, 'Natalia', 'Quiroz Peña', '73340987', 'natalia.quiroz@example.com', '+51 944 330 003'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', 4, 'Esteban', 'Luna Farfán', '40127865', 'esteban.luna@example.com', '+51 944 330 004'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44', 1, 'Gloria', 'Mendoza Ríos', '08451276', 'gloria.mendoza@example.com', '+51 944 440 001'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44', 2, 'Iván', 'Carrasco Solís', '43218790', 'ivan.carrasco@example.com', '+51 944 440 002'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44', 3, 'Patricia', 'Núñez Arce', '10345678', 'patricia.nunez@example.com', '+51 944 440 003'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44', 4, 'Sergio', 'Ibáñez Cruz', '46901234', 'sergio.ibanez@example.com', '+51 944 440 004'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', 1, 'Elena', 'Vargas Pinto', '07563412', 'elena.vargas@example.com', '+51 944 550 001'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', 2, 'Raúl', 'Espinoza Gil', '42876543', 'raul.espinoza@example.com', '+51 944 550 002'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', 3, 'Mónica', 'Salazar Rey', '71234098', 'monica.salazar@example.com', '+51 944 550 003'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', 4, 'Julio', 'Campos Díaz', '09123487', 'julio.campos@example.com', '+51 944 550 004'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66', 1, 'Rosa', 'Chávez Milla', '06785432', 'rosa.chavez@example.com', '+51 944 660 001'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66', 2, 'Alberto', 'Gamarra Ruiz', '41098765', 'alberto.gamarra@example.com', '+51 944 660 002'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66', 3, 'Fiorella', 'Ramos Tello', '74561230', 'fiorella.ramos@example.com', '+51 944 660 003'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66', 4, 'Óscar', 'Medina Lazo', '10876543', 'oscar.medina@example.com', '+51 944 660 004'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a77', 1, 'Andrea', 'Lozano Vera', '72650431', 'andrea.lozano@example.com', '+51 944 770 001'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a77', 2, 'Mariana', 'Cueto Ríos', '45239876', 'mariana.cueto@example.com', '+51 944 770 002'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a77', 3, 'Lucero', 'Palomino Díaz', '70918234', 'lucero.palomino@example.com', '+51 944 770 003'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a77', 4, 'Gabriela', 'Rentería Soto', '41872365', 'gabriela.renteria@example.com', '+51 944 770 004'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a88', 1, 'Bruno', 'Cáceres Lira', '47562310', 'bruno.caceres@example.com', '+51 944 880 001'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a88', 2, 'Martín', 'Oré Santos', '73021984', 'martin.ore@example.com', '+51 944 880 002'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a88', 3, 'Joaquín', 'Villena Paz', '44783015', 'joaquin.villena@example.com', '+51 944 880 003'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a88', 4, 'Renzo', 'Altamirano Gil', '76340912', 'renzo.altamirano@example.com', '+51 944 880 004'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a99', 1, 'Verónica', 'Sáenz Mori', '40236781', 'veronica.saenz@example.com', '+51 944 990 001'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a99', 2, 'Pamela', 'Ochoa Ruiz', '72894510', 'pamela.ochoa@example.com', '+51 944 990 002'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a99', 3, 'Claudia', 'Miranda Tapia', '43105927', 'claudia.miranda@example.com', '+51 944 990 003'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a99', 4, 'Jimena', 'Falcón Rey', '70456123', 'jimena.falcon@example.com', '+51 944 990 004'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380aaa', 1, 'Valentina', 'Cruz Alva', '74120983', 'valentina.cruz@example.com', '+51 944 100 001'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380aaa', 2, 'Diego', 'Prado Neyra', '46873201', 'diego.prado@example.com', '+51 944 100 002'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380aaa', 3, 'Lorena', 'Bustamante Gil', '42390168', 'lorena.bustamante@example.com', '+51 944 100 003'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380aaa', 4, 'Tomás', 'Riquelme Sosa', '75612049', 'tomas.riquelme@example.com', '+51 944 100 004'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380abb', 1, 'Milagros', 'Tejada Luna', '45120678', 'milagros.tejada@example.com', '+51 944 110 001'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380abb', 2, 'Gustavo', 'Peña Rojas', '41763052', 'gustavo.pena@example.com', '+51 944 110 002'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380abb', 3, 'Karen', 'Villalobos Chu', '73459018', 'karen.villalobos@example.com', '+51 944 110 003'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380abb', 4, 'Rafael', 'Montes Vela', '09482136', 'rafael.montes@example.com', '+51 944 110 004')
) AS v(tenant_id, n, first_name, last_name, document_number, email, phone)
WHERE NOT EXISTS (SELECT 1 FROM customers c WHERE c.tenant_id = v.tenant_id::uuid AND c.email = v.email);

-- Catorce clientes más por empresa, armados con listas de nombres y apellidos.
INSERT INTO customers (tenant_id, code, first_name, last_name, biological_sex, document_number, email, phone, district, department)
SELECT t.id,
       'CLI-' || lpad((n + (SELECT count(*) FROM customers c WHERE c.tenant_id = t.id))::text, 4, '0'),
       f.first_name, l1.last_name || ' ' || l2.last_name, f.sex,
       (40000000 + t.tn * 1000000 + n * 38917)::text,
       translate(lower(f.first_name || '.' || l1.last_name), 'áéíóúñ ', 'aeioun.') || '.' || t.tn || n || '@example.com',
       '+51 945 ' || lpad(t.tn::text, 3, '0') || ' ' || lpad(n::text, 3, '0'),
       (ARRAY['Miraflores', 'San Isidro', 'Surco', 'Jesús María', 'Lince', 'San Miguel', 'Los Olivos', 'Barranco', 'San Borja', 'Pueblo Libre'])[1 + (n + t.tn) % 10],
       'Lima'
FROM (SELECT id, (row_number() OVER (ORDER BY id))::int AS tn FROM tenants) t
CROSS JOIN generate_series(1, 14) AS n
JOIN (VALUES
  (0, 'Adriana', 'F'), (1, 'Felipe', 'M'), (2, 'Isabel', 'F'), (3, 'Mateo', 'M'), (4, 'Carolina', 'F'), (5, 'Santiago', 'M'),
  (6, 'Ximena', 'F'), (7, 'Nicolás', 'M'), (8, 'Rebeca', 'F'), (9, 'Emilio', 'M'), (10, 'Paula', 'F'), (11, 'Hernán', 'M'),
  (12, 'Milena', 'F'), (13, 'Gonzalo', 'M'), (14, 'Tatiana', 'F'), (15, 'Ignacio', 'M'), (16, 'Noemí', 'F')
) AS f(i, first_name, sex) ON f.i = (n + t.tn * 3) % 17
JOIN (VALUES
  (0, 'Flores'), (1, 'Quispe'), (2, 'Rojas'), (3, 'Huamán'), (4, 'Castillo'), (5, 'Vargas'), (6, 'Chávez'), (7, 'Mamani'),
  (8, 'Gutiérrez'), (9, 'Reyes'), (10, 'Córdova'), (11, 'Salinas'), (12, 'Navarro'), (13, 'Delgado'), (14, 'Aguirre'),
  (15, 'Cabrera'), (16, 'Herrera'), (17, 'Paredes'), (18, 'Yupanqui')
) AS l1(i, last_name) ON l1.i = (n * 3 + t.tn * 5) % 19
JOIN (VALUES
  (0, 'Ramos'), (1, 'Torres'), (2, 'Díaz'), (3, 'Morales'), (4, 'Sánchez'), (5, 'Campos'), (6, 'Vega'), (7, 'Rivera'),
  (8, 'Ortiz'), (9, 'Medina'), (10, 'Silva'), (11, 'Cruz'), (12, 'Romero')
) AS l2(i, last_name) ON l2.i = (n * 7 + t.tn) % 13
WHERE NOT EXISTS (SELECT 1 FROM customers c WHERE c.tenant_id = t.id AND c.document_number = (40000000 + t.tn * 1000000 + n * 38917)::text);

-- Veterinaria: cada tutor tiene una o dos mascotas, y cada cita es para una de ellas.
INSERT INTO customer_dependents (tenant_id, customer_id, name, kind, species, breed, birth_date, biological_sex)
SELECT c.tenant_id, c.id, v.name, 'pet', v.species, v.breed, v.birth_date::date, v.sex
FROM (VALUES
  ('milagros.tejada@example.com', 'Rocky', 'Perro', 'Labrador', '2020-03-12', 'M'),
  ('milagros.tejada@example.com', 'Mishi', 'Gato', 'Mestizo', '2022-08-05', 'F'),
  ('gustavo.pena@example.com', 'Toby', 'Perro', 'Schnauzer', '2019-11-20', 'M'),
  ('karen.villalobos@example.com', 'Luna', 'Gato', 'Siamés', '2021-06-30', 'F'),
  ('karen.villalobos@example.com', 'Coco', 'Perro', 'Shih Tzu', '2023-01-15', 'M'),
  ('rafael.montes@example.com', 'Max', 'Perro', 'Mestizo', '2018-09-02', 'M')
) AS v(email, name, species, breed, birth_date, sex)
JOIN customers c ON c.tenant_id = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380abb' AND c.email = v.email
WHERE NOT EXISTS (SELECT 1 FROM customer_dependents d WHERE d.customer_id = c.id AND d.name = v.name);

-- Los demás tutores de la veterinaria, con una mascota cada uno.
INSERT INTO customer_dependents (tenant_id, customer_id, name, kind, species, breed, biological_sex)
SELECT c.tenant_id, c.id, v.name, 'pet', v.species, v.breed, v.sex
FROM (
  SELECT c.id, c.tenant_id, (row_number() OVER (ORDER BY c.code))::int % 14 AS i
  FROM customers c
  WHERE c.tenant_id = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380abb' AND NOT EXISTS (SELECT 1 FROM customer_dependents d WHERE d.customer_id = c.id)
) c
JOIN (VALUES
  (0, 'Bruno', 'Perro', 'Beagle', 'M'), (1, 'Nala', 'Gato', 'Mestizo', 'F'), (2, 'Simba', 'Gato', 'Persa', 'M'),
  (3, 'Kira', 'Perro', 'Pastor alemán', 'F'), (4, 'Thor', 'Perro', 'Bulldog francés', 'M'), (5, 'Lola', 'Perro', 'Poodle', 'F'),
  (6, 'Oreo', 'Gato', 'Mestizo', 'M'), (7, 'Canela', 'Perro', 'Cocker', 'F'), (8, 'Zeus', 'Perro', 'Rottweiler', 'M'),
  (9, 'Pelusa', 'Conejo', 'Cabeza de león', 'F'), (10, 'Jack', 'Perro', 'Jack Russell', 'M'), (11, 'Mora', 'Gato', 'Angora', 'F'),
  (12, 'Duque', 'Perro', 'Golden retriever', 'M'), (13, 'Chispa', 'Perro', 'Chihuahua', 'F')
) AS v(i, name, species, breed, sex) ON v.i = c.i;

-- Igual que los que registra el personal: entran a su portal con su número de documento.
UPDATE customers
SET password_hash = replace(crypt(document_number, gen_salt('bf', 10)), '$2a$', '$2y$'), must_change_password = true
WHERE password_hash IS NULL AND email IS NOT NULL AND document_number IS NOT NULL;

-- Una ausencia próxima en cada una de esas empresas, para ver citas dentro de un bloqueo.
INSERT INTO availability_blocks (tenant_id, professional_id, starts_at, ends_at, reason, kind)
SELECT p.tenant_id, p.id,
       (current_date + 6)::timestamp AT TIME ZONE 'America/Lima', (current_date + 8)::timestamp AT TIME ZONE 'America/Lima',
       'Descanso médico', 'leave'
FROM professionals p
WHERE p.id::text LIKE '40eebc99-9c0b-4ef8-bb6d-6bb9bd380b_2'
  AND NOT EXISTS (SELECT 1 FROM availability_blocks b WHERE b.professional_id = p.id);

-- Citas de las últimas tres semanas (atendidas, canceladas y ausencias, con sus
-- tiempos reales) y de las próximas cuatro (pendientes y confirmadas), para cada
-- profesional según su horario, en la sede donde atiende cada día. No se agenda en feriados, pero sí dentro de las
-- ausencias y vacaciones registradas arriba: así se ven las "citas por resolver".
-- La última de cada empresa cae a propósito en su próximo feriado. Si el cliente
-- tiene mascotas (veterinaria), cada cita es para una de ellas. Solo se agendan
-- servicios individuales: las clases grupales del estudio fitness no son citas.
DO $demo$
DECLARE
  tz constant text := 'America/Lima';
  today date := (now() AT TIME ZONE tz)::date;
  t record;
  p record;
  customers_ids uuid[];
  services_ids uuid[];
  durations int[];
  prices numeric[];
  branch uuid;
  seq int;
  pro_index int;
  day_off int;
  d date;
  first_start time;
  first_end time;
  k int;
  i int;
  duration int;
  starts timestamptz;
  ends timestamptz;
  status text;
  r double precision;
  appointment uuid;
  started timestamptz;
  holiday date;
  customer uuid;
  candidate uuid;
  dependent uuid;
  j int;
BEGIN
  PERFORM setseed(0.42);

  FOR t IN SELECT id FROM tenants ORDER BY created_at, id LOOP
    CONTINUE WHEN EXISTS (SELECT 1 FROM appointments a WHERE a.tenant_id = t.id);

    SELECT array_agg(c.id ORDER BY c.code) INTO customers_ids FROM customers c WHERE c.tenant_id = t.id AND c.deleted_at IS NULL;
    SELECT array_agg(s.id ORDER BY s.name), array_agg(s.duration_minutes ORDER BY s.name), array_agg(s.price ORDER BY s.name)
      INTO services_ids, durations, prices
      FROM services s WHERE s.tenant_id = t.id AND s.kind = 'individual';
    CONTINUE WHEN customers_ids IS NULL OR services_ids IS NULL;

    seq := 0;
    pro_index := 0;

    FOR p IN SELECT pr.id FROM professionals pr WHERE pr.tenant_id = t.id ORDER BY pr.display_name LOOP
      pro_index := pro_index + 1;

      FOR day_off IN -21..28 LOOP
        d := today + day_off;

        -- La sede donde atiende ese día de la semana, y desde qué hora.
        first_start := NULL;
        SELECT s.branch_id, s.starts_local, s.ends_local INTO branch, first_start, first_end
          FROM professional_schedules s
          WHERE s.professional_id = p.id AND s.weekday = extract(dow FROM d)::int
          ORDER BY s.starts_local LIMIT 1;

        CONTINUE WHEN first_start IS NULL;
        CONTINUE WHEN EXISTS (SELECT 1 FROM holidays h WHERE h.tenant_id = t.id AND h.date = d AND h.closed AND (h.branch_id IS NULL OR h.branch_id = branch));

        FOR k IN 0..2 LOOP
          -- Más allá de una semana la agenda está menos llena: una cita, día por medio.
          CONTINUE WHEN day_off > 7 AND (k > 0 OR (day_off + pro_index) % 2 = 0);

          i := 1 + (day_off + 21 + k + pro_index) % array_length(services_ids, 1);
          duration := durations[i];
          starts := (d + first_start + (k * interval '90 minutes')) AT TIME ZONE tz;
          ends := starts + make_interval(mins => duration);

          -- Los servicios largos (color, cirugía) no deben salirse del tramo ni pisar la cita anterior.
          CONTINUE WHEN ends > (d + first_end) AT TIME ZONE tz;
          CONTINUE WHEN EXISTS (SELECT 1 FROM appointments a WHERE a.professional_id = p.id AND a.starts_at < ends AND a.ends_at > starts);

          r := random();

          status := CASE
            WHEN ends < now() - interval '2 hours' THEN CASE WHEN r < 0.80 THEN 'completed' WHEN r < 0.92 THEN 'cancelled' ELSE 'no_show' END
            WHEN starts < now() THEN 'confirmed'
            WHEN day_off <= 3 THEN CASE WHEN r < 0.70 THEN 'confirmed' ELSE 'pending' END
            ELSE CASE WHEN r < 0.40 THEN 'confirmed' ELSE 'pending' END
          END;

          -- Un cliente no puede estar en dos citas a la vez: se toma el primero que esté libre.
          customer := NULL;
          FOR j IN 0..array_length(customers_ids, 1) - 1 LOOP
            candidate := customers_ids[1 + (day_off + 21 + k * 2 + pro_index + j) % array_length(customers_ids, 1)];
            IF NOT EXISTS (SELECT 1 FROM appointments a WHERE a.customer_id = candidate AND a.starts_at < ends AND a.ends_at > starts) THEN
              customer := candidate;
              EXIT;
            END IF;
          END LOOP;

          CONTINUE WHEN customer IS NULL;

          seq := seq + 1;
          started := starts + make_interval(mins => floor(random() * 8)::int);

          -- La mascota atendida, alternando entre las del cliente; NULL si no tiene.
          dependent := NULL;
          SELECT cd.id INTO dependent FROM customer_dependents cd
            WHERE cd.customer_id = customer AND cd.deleted_at IS NULL
            ORDER BY CASE WHEN seq % 2 = 0 THEN cd.name END, cd.name DESC LIMIT 1;

          INSERT INTO appointments (
            tenant_id, code, branch_id, customer_id, customer_dependent_id, service_id, professional_id, starts_at, ends_at, status, source, price,
            confirmed_at, checked_in_at, started_at, completed_at, cancelled_at, cancel_reason, cancelled_by, created_at, updated_at
          ) VALUES (
            t.id, 'C-' || lpad(seq::text, 4, '0'), branch,
            customer, dependent,
            services_ids[i], p.id, starts, ends, status,
            CASE WHEN random() < 0.25 THEN 'online' ELSE 'staff' END, prices[i],
            CASE WHEN status IN ('confirmed', 'completed', 'no_show') THEN starts - interval '1 day' END,
            CASE WHEN status = 'completed' THEN starts - make_interval(mins => 2 + floor(random() * 10)::int) END,
            CASE WHEN status = 'completed' THEN started END,
            CASE WHEN status = 'completed' THEN started + make_interval(mins => greatest(5, round(duration * (0.8 + random() * 0.45))::int)) END,
            CASE WHEN status = 'cancelled' THEN starts - interval '1 day' END,
            CASE WHEN status = 'cancelled' THEN 'Avisó que no podía asistir' END,
            CASE WHEN status = 'cancelled' THEN 'staff' END,
            starts - interval '5 days', LEAST(now(), starts)
          ) RETURNING id INTO appointment;

          INSERT INTO appointment_status_history (tenant_id, appointment_id, from_status, to_status, reason, created_at)
          VALUES (t.id, appointment, NULL, 'pending', NULL, starts - interval '5 days');

          IF status <> 'pending' THEN
            INSERT INTO appointment_status_history (tenant_id, appointment_id, from_status, to_status, reason, created_at)
            VALUES (t.id, appointment, 'pending', status,
                    CASE WHEN status = 'cancelled' THEN 'Avisó que no podía asistir' END,
                    LEAST(now(), CASE WHEN status = 'completed' THEN ends ELSE starts - interval '1 day' END));
          END IF;
        END LOOP;
      END LOOP;
    END LOOP;

    -- Una cita agendada en el próximo feriado en que alguien atiende ese día de la
    -- semana: aparece como "por resolver" en la pestaña de feriados.
    SELECT min(h.date) INTO holiday
      FROM holidays h
      WHERE h.tenant_id = t.id AND h.closed AND h.date > today + 1
        AND EXISTS (SELECT 1 FROM professional_schedules s WHERE s.tenant_id = t.id AND s.weekday = extract(dow FROM h.date)::int);

    IF holiday IS NOT NULL THEN
      SELECT s.professional_id, s.branch_id, s.starts_local INTO p.id, branch, first_start
        FROM professional_schedules s
        WHERE s.tenant_id = t.id AND s.weekday = extract(dow FROM holiday)::int
        ORDER BY s.professional_id, s.starts_local LIMIT 1;

      seq := seq + 1;
      starts := (holiday + first_start) AT TIME ZONE tz;

      dependent := NULL;
      SELECT cd.id INTO dependent FROM customer_dependents cd
        WHERE cd.customer_id = customers_ids[1] AND cd.deleted_at IS NULL ORDER BY cd.name LIMIT 1;

      INSERT INTO appointments (tenant_id, code, branch_id, customer_id, customer_dependent_id, service_id, professional_id, starts_at, ends_at, status, source, price, confirmed_at, notes, created_at)
      VALUES (t.id, 'C-' || lpad(seq::text, 4, '0'), branch, customers_ids[1], dependent, services_ids[1], p.id, starts,
              starts + make_interval(mins => durations[1]), 'confirmed', 'staff', prices[1], now() - interval '3 days',
              'Se agendó antes de registrar el feriado.', now() - interval '6 days')
      RETURNING id INTO appointment;

      INSERT INTO appointment_status_history (tenant_id, appointment_id, from_status, to_status, created_at)
      VALUES (t.id, appointment, NULL, 'confirmed', now() - interval '6 days');
    END IF;
  END LOOP;
END
$demo$;

-- ============================================================================
--  Avisos de la campana, de ejemplo
--  · Cada profesional: sus tres próximas citas, como si se las hubiera agendado la recepción.
--  · El dueño: dos reservas "desde el portal" y dos cancelaciones recientes.
--  Los avisos de ausencias próximas no van aquí: el sistema los genera solo al consultar.
-- ============================================================================
INSERT INTO user_notifications (tenant_id, user_id, type, title, body, link, entity_type, entity_id, read_at, created_at)
SELECT x.tenant_id, x.user_id, 'appointment.booked', 'Te agendaron una cita',
       x.customer || ' · ' || x.service || ' · ' || to_char(x.starts_at AT TIME ZONE 'America/Lima', 'DD/MM, HH24:MI'),
       '/agenda', 'appointment', x.id,
       CASE WHEN x.n = 3 THEN now() - interval '1 hour' END,
       now() - make_interval(hours => x.n::int * 5)
FROM (
  SELECT a.id, a.tenant_id, p.user_id, a.starts_at, s.name AS service,
         c.first_name || ' ' || c.last_name AS customer,
         row_number() OVER (PARTITION BY a.professional_id ORDER BY a.starts_at) AS n
  FROM appointments a
  JOIN professionals p ON p.id = a.professional_id AND p.user_id IS NOT NULL
  JOIN customers c ON c.id = a.customer_id
  JOIN services s ON s.id = a.service_id
  WHERE a.starts_at > now() AND a.status IN ('pending', 'confirmed')
) x
WHERE x.n <= 3;

INSERT INTO user_notifications (tenant_id, user_id, type, title, body, link, entity_type, entity_id, read_at, created_at)
SELECT x.tenant_id, u.id,
       CASE WHEN x.cancelled THEN 'appointment.cancelled' ELSE 'appointment.portal_booked' END,
       CASE WHEN x.cancelled THEN 'Cancelaron una cita' ELSE 'Nueva reserva desde el portal' END,
       x.customer || ' · ' || x.service || ' · ' || to_char(x.starts_at AT TIME ZONE 'America/Lima', 'DD/MM, HH24:MI') || ' · ' || x.professional,
       '/agenda', 'appointment', x.id,
       CASE WHEN x.n = 2 THEN now() - interval '2 hours' END,
       now() - make_interval(hours => x.n::int * 7 + CASE WHEN x.cancelled THEN 3 ELSE 0 END)
FROM (
  SELECT a.id, a.tenant_id, a.starts_at, s.name AS service, p.display_name AS professional,
         c.first_name || ' ' || c.last_name AS customer,
         a.status = 'cancelled' AS cancelled,
         row_number() OVER (PARTITION BY a.tenant_id, a.status = 'cancelled' ORDER BY CASE WHEN a.status = 'cancelled' THEN -extract(epoch FROM a.starts_at) ELSE extract(epoch FROM a.starts_at) END) AS n
  FROM appointments a
  JOIN professionals p ON p.id = a.professional_id
  JOIN customers c ON c.id = a.customer_id
  JOIN services s ON s.id = a.service_id
  WHERE (a.status = 'cancelled' AND a.starts_at < now() + interval '7 days')
     OR (a.status = 'pending' AND a.starts_at > now() + interval '1 day')
) x
JOIN users u ON u.tenant_id = x.tenant_id AND u.is_owner
WHERE x.n <= 2;

-- Algunas inasistencias pasan a ser de citas reservadas en línea, para que la ficha
-- de esos clientes muestre la nota (y el veto, si la empresa lo activa).
UPDATE appointments SET source = 'online'
WHERE status = 'no_show' AND abs(hashtext(customer_id::text)) % 3 = 0;

-- Cupones de ejemplo en cada empresa: uno vigente en porcentaje, uno vigente en
-- monto con compra mínima y usos limitados, y uno ya vencido.
INSERT INTO coupons (tenant_id, code, description, discount_type, discount_value, min_purchase, max_uses, starts_at, ends_at, status)
SELECT t.id, c.code, c.description, c.discount_type, c.discount_value, c.min_purchase, c.max_uses,
       now() + make_interval(days => c.from_days), now() + make_interval(days => c.to_days), 'active'
FROM tenants t
CROSS JOIN (VALUES
  ('BIENVENIDA10', 'Bienvenida: 10 % en la primera visita', 'percent', 10, 0, NULL::int, -20, 60),
  ('VUELVE20', 'Reactivación: S/ 20 para quien no viene hace tiempo', 'amount', 20, 80, 50, -10, 30),
  ('ANIVERSARIO', 'Campaña de aniversario: 15 %', 'percent', 15, 0, 100, -60, -5)
) AS c(code, description, discount_type, discount_value, min_purchase, max_uses, from_days, to_days);

COMMIT;
