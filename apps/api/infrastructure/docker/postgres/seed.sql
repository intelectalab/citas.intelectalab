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
  ('10000000-0000-0000-0000-000000000012', 'saas.metrics.view', 'saas_admin', 'Ver métricas globales de ingresos y uso de la plataforma')
ON CONFLICT (name) DO NOTHING;

INSERT INTO status_modules (id, name, description) VALUES
  ('e1eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'patients', 'Estados para la ficha de pacientes'),
  ('e1eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', 'appointments', 'Estados del flujo de citas médicas'),
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
  
  -- Módulo: patients (e1eebc99-9c0b-4ef8-bb6d-6bb9bd380a01)
  ('f1eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'e1eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'Activo', 'active', '#28A745', true),
  ('f1eebc99-9c0b-4ef8-bb6d-6bb9bd380a12', 'e1eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'Inactivo', 'inactive', '#6C757D', false),

  -- Módulo: appointments (e1eebc99-9c0b-4ef8-bb6d-6bb9bd380a02)
  ('f1eebc99-9c0b-4ef8-bb6d-6bb9bd380a21', 'e1eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', 'Programada', 'scheduled', '#00A3D9', true),
  ('f1eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'e1eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', 'Completada', 'completed', '#28A745', false),
  
  -- Módulo: professionals (e1eebc99-9c0b-4ef8-bb6d-6bb9bd380a05)
  ('f1eebc99-9c0b-4ef8-bb6d-6bb9bd380a31', 'e1eebc99-9c0b-4ef8-bb6d-6bb9bd380a05', 'Activo', 'active', '#28A745', true),
  ('f1eebc99-9c0b-4ef8-bb6d-6bb9bd380a32', 'e1eebc99-9c0b-4ef8-bb6d-6bb9bd380a05', 'Inactivo', 'inactive', '#6C757D', false),

  ('f1eebc99-9c0b-4ef8-bb6d-6bb9bd380a41', 'e1eebc99-9c0b-4ef8-bb6d-6bb9bd380a06', 'Activo', 'active', '#28A745', true),
  ('f1eebc99-9c0b-4ef8-bb6d-6bb9bd380a42', 'e1eebc99-9c0b-4ef8-bb6d-6bb9bd380a06', 'Inactivo', 'inactive', '#6C757D', false)

ON CONFLICT DO NOTHING;

INSERT INTO saas_plans (
    id, name, slug, code_internal, description, price_monthly, price_yearly, currency, 
    trial_config, subscription_config, modules, limits, consumption, support, features, status, presentation_order
) VALUES
(
    '11111111-1111-1111-1111-111111111111', 'Starter', 'starter', 'STA', 'Para empresas pequeñas y profesionales independientes', 
    99.00, 990.00, 'PEN',
    '{"has_trial": true, "trial_days": 30}'::jsonb,
    '{"auto_renew": true, "default_period": "monthly"}'::jsonb,
    '{"core": ["clients", "services", "agenda"]}'::jsonb,
    '{"users": 3, "branches": 1, "clients": 100, "services": 50}'::jsonb,
    '{"storage_gb": 20, "api_requests_monthly": 10000}'::jsonb,
    '{"level": "Estándar", "tickets_monthly": 10}'::jsonb,
    '{"multi_tenant": true, "roles_and_permissions": true, "audit": true}'::jsonb,
    'active', 1
),
(
    '22222222-2222-2222-2222-222222222222', 'Professional', 'professional', 'PRO', 'Operación avanzada y múltiples sucursales', 
    249.00, 2490.00, 'PEN',
    '{"has_trial": true, "trial_days": 14}'::jsonb,
    '{"auto_renew": true, "default_period": "monthly"}'::jsonb,
    '{"core": ["clients", "services", "agenda"], "finance": ["finances", "payments"]}'::jsonb,
    '{"users": 10, "branches": 3, "clients": 500, "services": 200}'::jsonb,
    '{"storage_gb": 50, "api_requests_monthly": 50000}'::jsonb,
    '{"level": "Avanzado", "tickets_monthly": 25}'::jsonb,
    '{"multi_tenant": true, "roles_and_permissions": true, "audit": true, "pos": true, "advanced_reports": true}'::jsonb,
    'active', 2
),
(
    '33333333-3333-3333-3333-333333333333', 'Enterprise', 'enterprise', 'ENT', 'Solución completa para grandes corporaciones', 
    499.00, 4990.00, 'PEN',
    '{"has_trial": false, "trial_days": 0}'::jsonb,
    '{"auto_renew": true, "default_period": "yearly"}'::jsonb,
    '{"core": ["clients", "services", "agenda"], "finance": ["finances", "payments", "electronic_billing"], "operations": ["personal", "support"]}'::jsonb,
    '{"users": -1, "branches": -1, "clients": -1, "services": -1}'::jsonb,
    '{"storage_gb": 500, "api_requests_monthly": 500000}'::jsonb,
    '{"level": "Prioritario", "tickets_monthly": -1}'::jsonb,
    '{"multi_tenant": true, "roles_and_permissions": true, "audit": true, "mfa": true, "api": true, "custom_domain": true}'::jsonb,
    'active', 3
),
(
    '44444444-4444-4444-4444-444444444444', 'Free Tier', 'free-tier', 'FRE', 'Plan gratuito limitado para pruebas iniciales', 
    0.00, 0.00, 'PEN',
    '{"has_trial": false, "trial_days": 0}'::jsonb,
    '{"auto_renew": false, "default_period": "monthly"}'::jsonb,
    '{"core": ["clients", "agenda"]}'::jsonb,
    '{"users": 1, "branches": 1, "clients": 30, "services": 10}'::jsonb,
    '{"storage_gb": 5, "api_requests_monthly": 1000}'::jsonb,
    '{"level": "Comunitario", "tickets_monthly": 0}'::jsonb,
    '{"multi_tenant": true, "roles_and_permissions": false}'::jsonb,
    'active', 4
),
(
    '55555555-5555-5555-5555-555555555555', 'Growth', 'growth', 'GRO', 'Ideal para negocios en rápida expansión', 
    349.00, 3490.00, 'PEN',
    '{"has_trial": true, "trial_days": 14}'::jsonb,
    '{"auto_renew": true, "default_period": "monthly"}'::jsonb,
    '{"core": ["clients", "services", "agenda"], "finance": ["finances", "payments"], "crm": ["crm"]}'::jsonb,
    '{"users": 25, "branches": 5, "clients": 2000, "services": 500}'::jsonb,
    '{"storage_gb": 150, "api_requests_monthly": 150000}'::jsonb,
    '{"level": "Preferencial", "tickets_monthly": 50}'::jsonb,
    '{"multi_tenant": true, "roles_and_permissions": true, "audit": true, "pos": true, "whatsapp_notifications": true}'::jsonb,
    'active', 5
),
(
    '66666666-6666-6666-6666-666666666666', 'Unlimited Custom', 'unlimited-custom', 'CUS', 'Infraestructura dedicada y soporte a medida', 
    999.00, 9990.00, 'PEN',
    '{"has_trial": false, "trial_days": 0}'::jsonb,
    '{"auto_renew": true, "default_period": "yearly"}'::jsonb,
    '{"core": ["clients", "services", "agenda", "documents"], "finance": ["finances", "electronic_billing", "payments"], "operations": ["personal", "suppliers", "support"]}'::jsonb,
    '{"users": -1, "branches": -1, "clients": -1, "services": -1}'::jsonb,
    '{"storage_gb": 2000, "api_requests_monthly": 5000000}'::jsonb,
    '{"level": "Dedicado 24/7", "tickets_monthly": -1}'::jsonb,
    '{"multi_tenant": true, "roles_and_permissions": true, "audit": true, "mfa": true, "api": true, "custom_domain": true, "priority_support": true}'::jsonb,
    'active', 6
)
ON CONFLICT (slug) DO NOTHING;

INSERT INTO tenants (id, name, slug, timezone, status) VALUES
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'Centro Médico Integral', 'centro-medico-integral', 'America/Lima', 'active'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'Clínica Odontológica DentalPlus', 'clinica-dentalplus', 'America/Lima', 'active'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', 'Consultorio Psicológico Mente Sana', 'mente-sana', 'America/Lima', 'active'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44', 'Centro Fisioterapia Vital', 'fisioterapia-vital', 'America/Lima', 'active'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', 'Laboratorio Clínico BioAnálisis', 'bioanalisis', 'America/Lima', 'active'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66', 'Policlínico San Judas', 'policlinico-sanjudas', 'America/Lima', 'suspended')
ON CONFLICT (slug) DO NOTHING;

INSERT INTO subscriptions (id, tenant_id, plan_id, status, billing_cycle, starts_at, trial_ends_at, external_provider) VALUES
  (gen_random_uuid(), 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', '22222222-2222-2222-2222-222222222222', 'active', 'monthly', now(), now() + interval '14 days', 'stripe'),
  (gen_random_uuid(), 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', '33333333-3333-3333-3333-333333333333', 'active', 'yearly', now(), NULL, 'culqi'),
  (gen_random_uuid(), 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', '11111111-1111-1111-1111-111111111111', 'trialing', 'monthly', now(), now() + interval '30 days', 'stripe'),
  (gen_random_uuid(), 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44', '55555555-5555-5555-5555-555555555555', 'active', 'monthly', now(), NULL, 'culqi'),
  (gen_random_uuid(), 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', '44444444-4444-4444-4444-444444444444', 'active', 'monthly', now(), NULL, 'manual'),
  (gen_random_uuid(), 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66', '66666666-6666-6666-6666-666666666666', 'canceled', 'yearly', now() - interval '1 year', NULL, 'stripe')
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
  ('b1111111-1111-1111-1111-111111111111', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'Luxury Services S.A.C.', 'Barbería & Spa Luxury', '20601234567', '987654321', 'Av. Larco 123', 'Miraflores', 'Lima'),
  ('b2222222-2222-2222-2222-222222222222', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'DentalPlus Health S.A.', 'Clínica Odontológica DentalPlus', '20554433221', '912345678', 'Av. Javier Prado 450', 'San Isidro', 'Lima'),
  ('b3333333-3333-3333-3333-333333333333', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', 'Estudio Legal Mendoza E.I.R.L.', 'Consultoría Legal & Asoc', '20443322119', '998877665', 'Jr. de la Unión 500', 'Cercado de Lima', 'Lima'),
  ('b4444444-4444-4444-4444-444444444444', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44', 'FitLife Corp S.A.C.', 'Gimnasio FitLife Center', '20332211005', '976543210', 'Av. Universitaria 1800', 'San Martín de Porres', 'Lima'),
  ('b5555555-5555-5555-5555-555555555555', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', 'Comercial El Ahorro S.R.L.', 'MiniMarket El Ahorro', '20221100993', '965432109', 'Calle Las Begonias 321', 'Los Olivos', 'Lima'),
  ('b6666666-6666-6666-6666-666666666666', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66', 'WebDev Digital Agency S.A.C.', 'WebDev Marketing', '20110099881', '954321098', 'Av. Arequipa 1100', 'Lince', 'Lima')
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
)
ON CONFLICT DO NOTHING;

INSERT INTO roles (id, tenant_id, name, slug, description, is_system) VALUES
  ('c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'Dueño / Owner', 'owner', 'Admin Luxury', true),
  ('c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'Director Médico', 'owner', 'Admin DentalPlus', true),
  ('c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', 'Socio Principal', 'owner', 'Admin Legal', true),
  ('c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44', 'Gerente FitLife', 'owner', 'Admin Gym', true),
  ('c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', 'Administrador Tienda', 'owner', 'Admin Ahorro', true),
  ('c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a77', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66', 'CEO WebDev', 'owner', 'Admin Marketing', true),
  ('c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a00', NULL, 'Administrador SaaS', 'saas_admin', 'Admin global', true)
ON CONFLICT (tenant_id, slug) DO NOTHING;

INSERT INTO roles (id, tenant_id, name, slug, description, is_system) VALUES
  ('c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a81', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'Médico / Profesional', 'professional', 'Personal asistencial y de atención', true),
  ('c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a23', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'Odontólogo / Profesional', 'professional', 'Personal asistencial DentalPlus', true),
  ('c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a45', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', 'Psicólogo / Profesional', 'professional', 'Personal Mente Sana', true)
ON CONFLICT (tenant_id, slug) DO NOTHING;

INSERT INTO role_permissions (role_id, permission_id)
SELECT 'c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33'::uuid, id FROM permissions
UNION ALL
SELECT 'c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22'::uuid, id FROM permissions
UNION ALL
SELECT 'c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44'::uuid, id FROM permissions
UNION ALL
SELECT 'c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55'::uuid, id FROM permissions
UNION ALL
SELECT 'c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66'::uuid, id FROM permissions
UNION ALL
SELECT 'c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a77'::uuid, id FROM permissions
UNION ALL
SELECT 'c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a00'::uuid, id FROM permissions WHERE module = 'saas_admin'
ON CONFLICT DO NOTHING;

INSERT INTO role_permissions (role_id, permission_id)
SELECT 'c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a81'::uuid, id FROM permissions 
WHERE name IN ('catalog.manage', 'appointments.manage', 'appointments.attend', 'reports.view')
ON CONFLICT DO NOTHING;

INSERT INTO users (id, tenant_id, first_name, last_name, email, password_hash, is_owner, status) VALUES
  ('d0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'Carlos', 'Mendoza', 'admin@luxuryspa.pe', '$2y$12$asHZEj9ZDLwBioqgkZedseegEV2IcYwqBdkZZpnfEy672NFep.kkq', true, 'active'),
  ('d0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'Dr. Roberto', 'Guerra', 'admin@dentalplus.pe', '$2y$12$asHZEj9ZDLwBioqgkZedseegEV2IcYwqBdkZZpnfEy672NFep.kkq', true, 'active'),
  ('d0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', 'María', 'Rojas', 'admin@consultorialegal.pe', '$2y$12$asHZEj9ZDLwBioqgkZedseegEV2IcYwqBdkZZpnfEy672NFep.kkq', true, 'active'),
  ('d0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44', 'Jorge', 'Salas', 'admin@fitlife.pe', '$2y$12$asHZEj9ZDLwBioqgkZedseegEV2IcYwqBdkZZpnfEy672NFep.kkq', true, 'active'),
  ('d0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a55', 'Ana', 'Pérez', 'admin@minimarketelahorro.pe', '$2y$12$asHZEj9ZDLwBioqgkZedseegEV2IcYwqBdkZZpnfEy672NFep.kkq', true, 'active'),
  ('d0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a66', 'Luis', 'Silva', 'admin@webdev.pe', '$2y$12$asHZEj9ZDLwBioqgkZedseegEV2IcYwqBdkZZpnfEy672NFep.kkq', true, 'suspended'),
  ('d0eebc99-9c0b-4ef8-bb6d-6bb9bd380a00', NULL, 'Super', 'Admin SaaS', 'admin@tusaas.com', '$2y$12$asHZEj9ZDLwBioqgkZedseegEV2IcYwqBdkZZpnfEy672NFep.kkq', false, 'active')
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
  (NULL, 'd0eebc99-9c0b-4ef8-bb6d-6bb9bd380a00', 'c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a00')
ON CONFLICT DO NOTHING;

INSERT INTO user_roles (tenant_id, user_id, role_id) VALUES
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'd0eebc99-9c0b-4ef8-bb6d-6bb9bd380a71', 'c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a81'),
  ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'd0eebc99-9c0b-4ef8-bb6d-6bb9bd380a72', 'c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a81')
ON CONFLICT DO NOTHING;

INSERT INTO pipelines (id, name) VALUES
('c1eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'Pipeline de Atención de Pacientes'),
('c1eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', 'Pipeline de Soporte Clínico'),
('c1eebc99-9c0b-4ef8-bb6d-6bb9bd380a03', 'Pipeline de Procesamiento de Exámenes')
ON CONFLICT (id) DO NOTHING;

INSERT INTO pipeline_stages (id, pipeline_id, name, color, probability, stage_order) VALUES
(gen_random_uuid(), 'c1eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'Cita programada', '#00A3D9', 20, 1),
(gen_random_uuid(), 'c1eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'Triaje realizado', '#FFC107', 40, 2),
(gen_random_uuid(), 'c1eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'En consulta', '#28A745', 60, 3),
(gen_random_uuid(), 'c1eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'Receta / Tratamiento emitido', '#FD7E14', 80, 4),
(gen_random_uuid(), 'c1eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'Atención finalizada', '#198754', 100, 5),
(gen_random_uuid(), 'c1eebc99-9c0b-4ef8-bb6d-6bb9bd380a01', 'Inasistencia', '#DC3545', 0, 6),
(gen_random_uuid(), 'c1eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', 'Nuevo', '#007BFF', 0, 1),
(gen_random_uuid(), 'c1eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', 'Esperando al paciente', '#6C757D', 0, 2),
(gen_random_uuid(), 'c1eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', 'Acción médica requerida', '#17A2B8', 0, 3),
(gen_random_uuid(), 'c1eebc99-9c0b-4ef8-bb6d-6bb9bd380a02', 'Cerrado', '#28A745', 0, 4),
(gen_random_uuid(), 'c1eebc99-9c0b-4ef8-bb6d-6bb9bd380a03', 'Muestra recibida', '#007BFF', 0, 1),
(gen_random_uuid(), 'c1eebc99-9c0b-4ef8-bb6d-6bb9bd380a03', 'En análisis', '#FFC107', 0, 2),
(gen_random_uuid(), 'c1eebc99-9c0b-4ef8-bb6d-6bb9bd380a03', 'Resultados listos', '#17A2B8', 0, 3),
(gen_random_uuid(), 'c1eebc99-9c0b-4ef8-bb6d-6bb9bd380a03', 'Entregado al paciente', '#28A745', 0, 4),
(gen_random_uuid(), 'c1eebc99-9c0b-4ef8-bb6d-6bb9bd380a03', 'Cancelado', '#DC3545', 0, 5)
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

INSERT INTO patients (
    id, tenant_id, code, first_name, last_name, birth_date, biological_sex, document_number, email, phone, address, district, department, medical_notes
) VALUES  
(
    gen_random_uuid(), 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'PAC-0001', 'Juliana', 'Díaz', '1992-05-14', 'F', '72819043', 'juliana.diaz@gmail.com', '+51 912 345 678', 'Calle Los Pinos 145', 'Miraflores', 'Lima', 'Alergica a la penicilina'
),
(
    gen_random_uuid(), 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'PAC-0002', 'Carlos', 'Ramírez Soto', '1985-11-20', 'M', '44219873', 'carlos.ramirez@yahoo.es', '+51 987 654 321', 'Av. Benavides 789', 'Miraflores', 'Lima', 'Hipertenso controlado'
),
(
    gen_random_uuid(), 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'PAC-0003', 'Sofía', 'Valverde Mendoza', '1998-03-02', 'F', '75412398', 'sofia.valverde@outlook.com', '+51 976 123 456', 'Av. Arequipa 3400', 'San Isidro', 'Lima', 'Sin antecedentes de importancia'
)
ON CONFLICT DO NOTHING;

COMMIT;
