BEGIN;

-- 1. Configuración inicial
SET client_encoding = 'UTF8';
CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- 2. Tablas base y auxiliares independientes
CREATE TABLE type_documents (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name varchar(250) NOT NULL,
    length int NULL,
    minimum int NULL,
    value varchar(5) NULL,
    status smallint NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

-- Rubros de negocio (catálogo global del SaaS). Definen la presentación y el
-- vocabulario por defecto del tenant: cliente/paciente/alumno, profesional, etc.
CREATE TABLE business_types (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    slug varchar(50) NOT NULL UNIQUE, -- salon, barber, dental, medical, vet, generic...
    name varchar(150) NOT NULL,
    short_name varchar(80) NOT NULL,
    description varchar(500),
    icon varchar(50),
    color varchar(7) DEFAULT '#3C7DC4',
    vocabulary jsonb NOT NULL DEFAULT '{}'::jsonb, -- {client, clients, pro, pros, dependent, dependents}
    modules_off jsonb NOT NULL DEFAULT '[]'::jsonb, -- Módulos que el rubro no usa aunque el plan los incluya
    default_rules jsonb NOT NULL DEFAULT '{}'::jsonb, -- Reglas de operación por defecto ("cancel.window_hours": 24)
    dependents jsonb NOT NULL DEFAULT '{}'::jsonb, -- {enabled, kind, required}: mascotas, hijos, etc.
    -- Catálogo inicial que se copia al tenant al registrarse:
    -- {categories: [{name, services: [{code, name, duration_minutes, price, buffer_after_minutes, uses_record,
    --   requires_deposit, online_booking, is_virtual, kind, capacity, recall: {label, days, service_code}}]}],
    --  record_template: {name, consent_text, require_consent, fields}}
    template jsonb NOT NULL DEFAULT '{}'::jsonb,
    sort_order int NOT NULL DEFAULT 0,
    status varchar(30) NOT NULL DEFAULT 'active',
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE tenants (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name varchar(150) NOT NULL,
    slug varchar(100) NOT NULL UNIQUE,
    -- Código de empresa de 8 dígitos que el tenant entrega a sus clientes para que
    -- se registren. Identifica al tenant sin exponer su id ni su slug.
    code char(8) NOT NULL UNIQUE CHECK (code ~ '^[0-9]{8}$'),
    business_type_id uuid NULL REFERENCES business_types(id) ON DELETE SET NULL,
    timezone varchar(64) NOT NULL DEFAULT 'America/Lima',
    status varchar(30) NOT NULL DEFAULT 'active', -- active, suspended, inactive: solo 'active' puede iniciar sesión
    suspension_reason varchar(255) NULL, -- Motivo que registra la plataforma al suspender
    -- Módulos que la plataforma le apaga a esta empresa aunque su plan los incluya.
    -- La empresa no puede volver a encenderlos (los suyos van en tenant_settings.modules_off).
    modules_off jsonb NOT NULL DEFAULT '[]'::jsonb,
    -- Copia de lo que el rubro le dio a la empresa: {vocabulary, modules_off, default_rules, dependents}.
    -- Se toma al registrarse o al cambiar de rubro. Editar el rubro no la toca: los cambios
    -- solo llegan cuando la plataforma los aplica a la empresa de forma explícita.
    business_profile jsonb NULL,
    business_profile_synced_at timestamptz NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    deleted_at timestamptz
);

CREATE INDEX idx_tenants_business_type ON tenants(business_type_id);

-- Configuración propia de cada tenant (una fila por tenant). La configuración
-- del SaaS vive en saas_plans y subscriptions.
CREATE TABLE tenant_settings (
    tenant_id uuid PRIMARY KEY REFERENCES tenants(id) ON DELETE CASCADE,
    vocabulary jsonb NOT NULL DEFAULT '{}'::jsonb, -- Sobrescribe claves del vocabulario del rubro
    modules_off jsonb NOT NULL DEFAULT '[]'::jsonb, -- Módulos que la empresa apaga además de los del rubro
    rules jsonb NOT NULL DEFAULT '{}'::jsonb, -- Sobrescribe reglas por defecto del rubro
    brand_color varchar(7) NULL,
    logo_url varchar(255) NULL,
    allow_customer_signup boolean NOT NULL DEFAULT true, -- Registro de clientes con el código de empresa
    appointment_statuses jsonb NOT NULL DEFAULT '{}'::jsonb, -- Nombre propio de las fases de la cita: {"checked_in": "En sala"}
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE status_modules (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name varchar(100) NOT NULL UNIQUE, -- ej: appointments, sales, customers, services, payments, cash_sessions
    description varchar(255),
    created_at timestamptz NOT NULL DEFAULT now()
);

-- Estados dinámicos
CREATE TABLE statuses (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NULL REFERENCES tenants(id) ON DELETE CASCADE, -- NULL = Global del SaaS, con valor = Personalizado del Tenant
    module_id uuid NOT NULL REFERENCES status_modules(id) ON DELETE CASCADE,
    name varchar(100) NOT NULL,
    slug varchar(100) NOT NULL,
    color varchar(7) DEFAULT '#022340',
    is_default boolean NOT NULL DEFAULT false,
    sort_order int NOT NULL DEFAULT 0,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, module_id, slug)
);
CREATE INDEX idx_statuses_tenant_module ON statuses(tenant_id, module_id);

CREATE TABLE companies (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id),
    legal_name varchar(200),
    trade_name varchar(200) NOT NULL,
    tax_id varchar(30),
    phone varchar(30),
    address varchar(255),
    department varchar(100) DEFAULT 'Lima',
    district varchar(100),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, trade_name)
);
CREATE INDEX idx_companies_tenant ON companies(tenant_id);

CREATE TABLE branches (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id),
    company_id uuid NOT NULL REFERENCES companies(id),
    name varchar(150) NOT NULL,
    timezone varchar(64) NOT NULL DEFAULT 'America/Lima',
    status varchar(30) NOT NULL DEFAULT 'active',
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, name)
);
CREATE INDEX idx_branches_tenant ON branches(tenant_id);

CREATE TABLE users (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NULL REFERENCES tenants(id) ON DELETE CASCADE,
    first_name varchar(100) NOT NULL,
    last_name varchar(100) NOT NULL,
    email varchar(190) NOT NULL,
    password_hash varchar(255) NOT NULL,
    phone varchar(30),
    avatar_url varchar(255),
    is_owner boolean NOT NULL DEFAULT false,
    status varchar(30) NOT NULL DEFAULT 'active',
    email_verified_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_users_tenant ON users(tenant_id);
CREATE UNIQUE INDEX idx_users_tenant_email ON users (tenant_id, email) WHERE tenant_id IS NOT NULL;
CREATE UNIQUE INDEX idx_users_saas_email ON users (email) WHERE tenant_id IS NULL;

-- 3. Pipelines y Etapas (Se crean antes de customers para evitar errores de referencia)
CREATE TABLE pipelines (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name varchar(150) NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE pipeline_stages (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    pipeline_id uuid NOT NULL REFERENCES pipelines(id) ON DELETE CASCADE,
    name varchar(150) NOT NULL,
    color varchar(7) DEFAULT '#0050AA',
    probability int DEFAULT 0,
    stage_order int NOT NULL DEFAULT 0,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_pipeline_stages_pipeline ON pipeline_stages(pipeline_id);

-- 4. Tabla unificada de Customers (clientes; antes "patients")
CREATE TABLE customers (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    code varchar(50) NOT NULL, -- Código correlativo por tenant (CLI-0001)
    first_name varchar(150) NOT NULL,
    last_name varchar(150) NOT NULL,
    birth_date date NULL,
    biological_sex varchar(20) NULL, -- M, F, Otro
    blood_type varchar(10) NULL, -- O+, A-, etc.
    type_document_id uuid NULL REFERENCES type_documents(id) ON DELETE SET NULL,
    document_number varchar(30) NULL,
    email varchar(250) NULL,
    phone varchar(50) NULL,
    emergency_contact_name varchar(200) NULL,
    emergency_contact_phone varchar(50) NULL,
    status_id uuid NULL REFERENCES statuses(id), -- Vinculado a módulo 'customers'
    manager_user_id uuid NULL REFERENCES users(id) ON DELETE SET NULL,
    -- Acceso al portal: el cliente ingresa con su correo. La contraseña inicial es su
    -- número de documento y debe cambiarla en su primer ingreso. Sin correo o sin
    -- contraseña no hay acceso. Los clientes no tienen fila en users.
    password_hash varchar(255) NULL,
    must_change_password boolean NOT NULL DEFAULT false,
    portal_enabled boolean NOT NULL DEFAULT true,
    password_changed_at timestamptz NULL,
    last_login_at timestamptz NULL,
    pipeline_stage_id uuid NULL REFERENCES pipeline_stages(id) ON DELETE SET NULL,
    address varchar(255) NULL,
    district varchar(100) NULL,
    department varchar(100) NULL,
    postal_code varchar(20) NULL,
    medical_notes text, -- Alergias o antecedentes médicos relevantes
    last_activity_at timestamptz NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    deleted_at timestamptz
);
CREATE INDEX idx_customers_tenant ON customers(tenant_id);
CREATE INDEX idx_customers_tenant_phone ON customers(tenant_id, phone);
CREATE INDEX idx_customers_tenant_email ON customers(tenant_id, email);
CREATE INDEX idx_customers_tenant_document ON customers(tenant_id, document_number);
CREATE INDEX idx_customers_status ON customers(status_id);
CREATE INDEX idx_customers_login ON customers(lower(email)) WHERE password_hash IS NOT NULL;

-- 5. Resto de tablas operativas
CREATE TABLE tenant_contacts (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    user_id uuid NULL REFERENCES users(id) ON DELETE SET NULL,
    first_name varchar(250) NOT NULL,
    last_name varchar(250) NOT NULL,
    email varchar(250) NOT NULL,
    phone varchar(50) NULL,
    position varchar(150) NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_tenant_contacts_tenant ON tenant_contacts(tenant_id);

CREATE TABLE service_categories (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id),
    name varchar(120) NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, name)
);

CREATE TABLE services (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id),
    category_id uuid REFERENCES service_categories(id),
    name varchar(150) NOT NULL,
    duration_minutes integer NOT NULL CHECK (duration_minutes > 0),
    buffer_before_minutes integer NOT NULL DEFAULT 0 CHECK (buffer_before_minutes >= 0),
    buffer_after_minutes integer NOT NULL DEFAULT 0 CHECK (buffer_after_minutes >= 0),
    price numeric(14,2) NOT NULL DEFAULT 0 CHECK (price >= 0),
    code varchar(30) NULL, -- Código interno del servicio (CAB-01)
    requires_deposit boolean NOT NULL DEFAULT false, -- Exige adelanto para confirmar la reserva
    online_booking boolean NOT NULL DEFAULT true, -- Se puede reservar desde el portal y la reserva pública
    is_virtual boolean NOT NULL DEFAULT false, -- Se atiende por videollamada
    kind varchar(20) NOT NULL DEFAULT 'individual' CHECK (kind IN ('individual', 'group')), -- group = clase con cupos
    capacity integer NOT NULL DEFAULT 1 CHECK (capacity >= 1), -- Cupo por sesión; mayor a 1 solo en clases
    -- Control de seguimiento que se sugiere al completar la atención
    recall_label varchar(150) NULL,
    recall_days integer NULL CHECK (recall_days > 0),
    recall_service_id uuid NULL REFERENCES services(id) ON DELETE SET NULL, -- Servicio del control; NULL = el mismo
    status_id uuid NULL REFERENCES statuses(id),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CHECK (kind = 'group' OR capacity = 1),
    CHECK ((recall_label IS NULL) = (recall_days IS NULL))
);
CREATE INDEX idx_services_tenant_status ON services(tenant_id, status_id);

CREATE TABLE specialties (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    name varchar(150) NOT NULL,
    description varchar(255),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, name)
);
CREATE INDEX idx_specialties_tenant ON specialties(tenant_id);

CREATE TABLE professionals (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    user_id uuid REFERENCES users(id) ON DELETE SET NULL,
    type_document_id uuid NULL REFERENCES type_documents(id),
    document_number varchar(30) NULL,
    display_name varchar(150) NOT NULL,
    license_number varchar(50) NULL, -- Número de colegiatura (CMP, COP, etc.)
    phone varchar(30) NULL,
    signature_url varchar(255) NULL,
    bio text NULL,
    color_code varchar(7) DEFAULT '#022340',
    status_id uuid NULL REFERENCES statuses(id), -- Vinculado a módulo 'professionals'
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_professionals_tenant ON professionals(tenant_id);
CREATE INDEX idx_professionals_document ON professionals(tenant_id, document_number);

CREATE TABLE professional_specialties (
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    professional_id uuid NOT NULL REFERENCES professionals(id) ON DELETE CASCADE,
    specialty_id uuid NOT NULL REFERENCES specialties(id) ON DELETE CASCADE,
    PRIMARY KEY (professional_id, specialty_id)
);

CREATE TABLE service_professionals (
    tenant_id uuid NOT NULL REFERENCES tenants(id),
    service_id uuid NOT NULL REFERENCES services(id),
    professional_id uuid NOT NULL REFERENCES professionals(id),
    PRIMARY KEY (service_id, professional_id)
);

CREATE TABLE professional_schedules (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id),
    professional_id uuid NOT NULL REFERENCES professionals(id),
    branch_id uuid NOT NULL REFERENCES branches(id),
    weekday smallint NOT NULL CHECK (weekday BETWEEN 0 AND 6),
    starts_local time NOT NULL,
    ends_local time NOT NULL,
    CHECK (ends_local > starts_local)
);
CREATE INDEX idx_prof_schedule ON professional_schedules(tenant_id, professional_id, branch_id, weekday);
CREATE TABLE rooms (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    branch_id uuid NOT NULL REFERENCES branches(id) ON DELETE CASCADE,
    name varchar(100) NOT NULL, -- Ej: Consultorio 101, Tópico 2
    description varchar(255),
    status varchar(30) NOT NULL DEFAULT 'active',
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (branch_id, name)
);
CREATE INDEX idx_rooms_branch ON rooms(branch_id);

CREATE TABLE availability_blocks (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id),
    branch_id uuid REFERENCES branches(id),
    professional_id uuid REFERENCES professionals(id),
    starts_at timestamptz NOT NULL,
    ends_at timestamptz NOT NULL,
    reason varchar(255),
    -- vacation, leave, holiday y closure no se pueden forzar; other (reunión, capacitación) sí, con motivo
    kind varchar(20) NOT NULL DEFAULT 'other' CHECK (kind IN ('vacation', 'leave', 'holiday', 'closure', 'other')),
    created_by uuid NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    CHECK (ends_at > starts_at)
);
CREATE INDEX idx_blocks_tenant_time ON availability_blocks(tenant_id, starts_at, ends_at);

CREATE TABLE appointments (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    code varchar(20) NOT NULL, -- Correlativo por empresa (C-0001)
    branch_id uuid NOT NULL REFERENCES branches(id) ON DELETE CASCADE,
    room_id uuid NULL REFERENCES rooms(id) ON DELETE SET NULL,
    customer_id uuid NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
    -- Mascota u otro dependiente atendido; obligatorio en los rubros que lo exigen (lo valida la API)
    customer_dependent_id uuid NULL,
    service_id uuid NOT NULL REFERENCES services(id) ON DELETE RESTRICT,
    professional_id uuid NOT NULL REFERENCES professionals(id) ON DELETE RESTRICT,
    starts_at timestamptz NOT NULL,
    ends_at timestamptz NOT NULL,
    -- Fase de la cita. Son fijas: el flujo y los permisos dependen de ellas.
    -- Cada empresa solo cambia el nombre con que las ve (tenant_settings.appointment_statuses).
    status varchar(20) NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'confirmed', 'checked_in', 'in_progress', 'completed', 'cancelled', 'no_show')),
    source varchar(30) NOT NULL DEFAULT 'staff',
    price numeric(14,2) NOT NULL DEFAULT 0 CHECK (price >= 0), -- Precio del servicio al reservar
    notes text,
    reschedule_count integer NOT NULL DEFAULT 0,
    override_reason varchar(255) NULL, -- Motivo cuando se agendó fuera del horario (sobre-agenda)
    cancel_reason varchar(255) NULL,
    cancelled_by varchar(20) NULL, -- staff, customer
    confirmed_at timestamptz NULL,
    checked_in_at timestamptz NULL,
    started_at timestamptz NULL,
    completed_at timestamptz NULL,
    cancelled_at timestamptz NULL,
    created_by uuid NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CHECK (ends_at > starts_at),
    UNIQUE (tenant_id, code)
);
--
CREATE INDEX idx_appt_tenant_start ON appointments(tenant_id, starts_at);
CREATE INDEX idx_appt_tenant_branch_date ON appointments(tenant_id, branch_id, starts_at);
CREATE INDEX idx_appt_prof_time ON appointments(tenant_id, professional_id, starts_at, ends_at);
CREATE INDEX idx_appt_customer ON appointments(tenant_id, customer_id, starts_at);

-- Cada cambio de fase y cada reprogramación (en ese caso la fase no cambia).
CREATE TABLE appointment_status_history (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    appointment_id uuid NOT NULL REFERENCES appointments(id) ON DELETE CASCADE,
    from_status varchar(20) NULL,
    to_status varchar(20) NOT NULL,
    reason varchar(255) NULL,
    actor_user_id uuid REFERENCES users(id) ON DELETE SET NULL,
    -- Cuando el paso lo dio el propio cliente desde el portal.
    actor_customer_id uuid NULL REFERENCES customers(id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_appt_history ON appointment_status_history(appointment_id, created_at);

CREATE TABLE medical_records (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    customer_id uuid NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
    appointment_id uuid NULL REFERENCES appointments(id) ON DELETE SET NULL, -- Vinculado a la cita que originó la atención
    professional_id uuid NOT NULL REFERENCES professionals(id) ON DELETE RESTRICT,
    subjective text NULL, -- Síntomas / Motivo de consulta (Lo que dice el cliente)
    objective text NULL, -- Examen físico / Signos vitales (Lo que observa el médico)
    assessment text NULL, -- Diagnóstico / Impresión diagnóstica (Código CIE-10 opcional)
    plan text NULL, -- Tratamiento, recetas, indicaciones y exámenes auxiliares
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_medical_records_customer ON medical_records(tenant_id, customer_id);

CREATE TABLE sales (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id),
    branch_id uuid NOT NULL REFERENCES branches(id),
    customer_id uuid REFERENCES customers(id),
    appointment_id uuid REFERENCES appointments(id),
    status_id uuid NULL REFERENCES statuses(id), -- Vinculado a módulo 'sales' (ej: abierto, pagado, anulado)
    subtotal numeric(14,2) NOT NULL DEFAULT 0 CHECK (subtotal >= 0),
    tax numeric(14,2) NOT NULL DEFAULT 0 CHECK (tax >= 0),
    discount numeric(14,2) NOT NULL DEFAULT 0 CHECK (discount >= 0),
    total numeric(14,2) NOT NULL DEFAULT 0 CHECK (total >= 0),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE sale_items (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id),
    sale_id uuid NOT NULL REFERENCES sales(id),
    item_type varchar(30) NOT NULL,
    reference_id uuid,
    description varchar(255) NOT NULL,
    quantity numeric(14,4) NOT NULL CHECK (quantity > 0),
    unit_price numeric(14,2) NOT NULL CHECK (unit_price >= 0),
    total numeric(14,2) NOT NULL CHECK (total >= 0)
);
CREATE INDEX idx_sale_items_sale ON sale_items(tenant_id, sale_id);

CREATE TABLE payments (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id),
    sale_id uuid NOT NULL REFERENCES sales(id),
    method varchar(30) NOT NULL,
    status_id uuid NULL REFERENCES statuses(id), -- Vinculado a módulo 'payments' (ej: pendiente, aprobado, rechazado)
    amount numeric(14,2) NOT NULL CHECK (amount > 0),
    external_reference varchar(190),
    idempotency_key varchar(190),
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, idempotency_key)
);

CREATE TABLE cash_sessions (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id),
    branch_id uuid NOT NULL REFERENCES branches(id),
    opened_by uuid NOT NULL REFERENCES users(id),
    closed_by uuid REFERENCES users(id),
    opening_amount numeric(14,2) NOT NULL DEFAULT 0 CHECK (opening_amount >= 0),
    closing_amount numeric(14,2),
    status_id uuid NULL REFERENCES statuses(id), -- Vinculado a módulo 'cash_sessions' (ej: abierto, cerrado, arqueado)
    opened_at timestamptz NOT NULL DEFAULT now(),
    closed_at timestamptz
);

CREATE TABLE cash_movements (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id),
    cash_session_id uuid NOT NULL REFERENCES cash_sessions(id),
    type varchar(30) NOT NULL,
    amount numeric(14,2) NOT NULL CHECK (amount > 0),
    reference_type varchar(50),
    reference_id uuid,
    description varchar(255),
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE audit_events (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid REFERENCES tenants(id),
    actor_user_id uuid REFERENCES users(id),
    action varchar(100) NOT NULL,
    entity_type varchar(100),
    entity_id uuid,
    ip_address inet,
    user_agent text,
    before_data jsonb,
    after_data jsonb,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_audit_tenant_time ON audit_events(tenant_id, created_at);

-- Catálogo de módulos del producto. Los módulos base (is_core) están en todos
-- los planes; el resto se activa por plan y se puede apagar por rubro o empresa.
CREATE TABLE saas_modules (
    key varchar(50) PRIMARY KEY, -- agenda, crm, booking, pos, inventory...
    name varchar(150) NOT NULL,
    is_core boolean NOT NULL DEFAULT false,
    sort_order int NOT NULL DEFAULT 0,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

-- Configuración de la plataforma (del SaaS, no de una empresa): marca, apariencia,
-- registro de empresas y soporte. Una fila por clave; lo que falta usa su valor por defecto.
CREATE TABLE platform_settings (
    key varchar(60) PRIMARY KEY, -- product_name, accent_color, allow_tenant_signup...
    value jsonb NULL, -- NULL = dato opcional vacío (p. ej. sin correo de soporte)
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE saas_plans (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name varchar(100) NOT NULL,
    slug varchar(100) NOT NULL UNIQUE,
    code_internal varchar(50),
    description text,
    price_monthly numeric(14,2) NOT NULL DEFAULT 0 CHECK (price_monthly >= 0),
    price_yearly numeric(14,2) NOT NULL DEFAULT 0 CHECK (price_yearly >= 0),
    currency varchar(10) NOT NULL DEFAULT 'PEN',
    
    -- Bloques agrupados en JSONB
    trial_config jsonb NOT NULL DEFAULT '{}',
    subscription_config jsonb NOT NULL DEFAULT '{}',
    modules jsonb NOT NULL DEFAULT '{}',
    limits jsonb NOT NULL DEFAULT '{}',
    consumption jsonb NOT NULL DEFAULT '{}',
    support jsonb NOT NULL DEFAULT '{}',
    features jsonb NOT NULL DEFAULT '{}',

    status varchar(30) NOT NULL DEFAULT 'active',
    presentation_order integer NOT NULL DEFAULT 1,
    is_public_catalog boolean NOT NULL DEFAULT true,
    
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE subscriptions (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    plan_id uuid NOT NULL REFERENCES saas_plans(id),
    status varchar(30) NOT NULL DEFAULT 'trialing',
    billing_cycle varchar(20) NOT NULL DEFAULT 'monthly',
    starts_at timestamptz NOT NULL,
    ends_at timestamptz,
    trial_ends_at timestamptz,
    canceled_at timestamptz,
    external_provider varchar(50),
    external_subscription_id varchar(190),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_subscriptions_tenant ON subscriptions(tenant_id);
CREATE INDEX idx_subscriptions_status ON subscriptions(status);

CREATE TABLE IF NOT EXISTS subscription_invoices (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    subscription_id UUID NOT NULL REFERENCES subscriptions(id) ON DELETE CASCADE,
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    status_id UUID NOT NULL REFERENCES statuses(id), -- Vinculado al módulo de estados (ej. pagado, pendiente, anulado)
    amount NUMERIC(12, 2) NOT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'PEN',
    billing_reason VARCHAR(50), -- Ej: 'subscription_create', 'subscription_cycle', 'manual'
    invoice_date TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now(),
    due_date TIMESTAMP WITH TIME ZONE,
    paid_at TIMESTAMP WITH TIME ZONE,
    external_invoice_id VARCHAR(255), -- Referencia del pago: n.º de operación o id de la pasarela
    period_start TIMESTAMP WITH TIME ZONE, -- Periodo de servicio que cubre el cobro
    period_end TIMESTAMP WITH TIME ZONE,
    notes VARCHAR(255),
    hosted_invoice_url TEXT,
    pdf_url TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT now(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT now()
);

CREATE INDEX idx_subscription_invoices_tenant ON subscription_invoices(tenant_id);
CREATE INDEX idx_subscription_invoices_subscription ON subscription_invoices(subscription_id);

CREATE TABLE permissions (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name varchar(100) NOT NULL UNIQUE,
    module varchar(50) NOT NULL,
    description varchar(255),
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE roles (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NULL REFERENCES tenants(id) ON DELETE CASCADE,
    name varchar(100) NOT NULL,
    slug varchar(100) NOT NULL,
    description varchar(255),
    is_system boolean NOT NULL DEFAULT false,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, slug)
);
CREATE INDEX idx_roles_tenant ON roles(tenant_id);

CREATE TABLE role_permissions (
    role_id uuid NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    permission_id uuid NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
    PRIMARY KEY (role_id, permission_id)
);

CREATE TABLE user_roles (
    tenant_id uuid NULL REFERENCES tenants(id) ON DELETE CASCADE,
    user_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role_id uuid NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    PRIMARY KEY (user_id, role_id)
);
CREATE INDEX idx_user_roles_tenant ON user_roles(tenant_id, user_id);

CREATE TABLE IF NOT EXISTS personal_access_tokens (
    id bigserial PRIMARY KEY,
    tokenable_type varchar(255) NOT NULL,
    tokenable_id varchar(255) NOT NULL,
    name varchar(255) NOT NULL,
    token varchar(64) NOT NULL UNIQUE,
    abilities text,
    last_used_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);

CREATE TABLE IF NOT EXISTS sessions (
    id VARCHAR(255) PRIMARY KEY,
    user_id UUID NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    payload TEXT NOT NULL,
    last_activity INT NOT NULL
);

-- Caché de Laravel (CACHE_STORE=database). La usa el límite de intentos
-- (throttle) del login y del registro de clientes.
CREATE TABLE IF NOT EXISTS cache (
    key VARCHAR(255) PRIMARY KEY,
    value TEXT NOT NULL,
    expiration INT NOT NULL
);

CREATE TABLE IF NOT EXISTS cache_locks (
    key VARCHAR(255) PRIMARY KEY,
    owner VARCHAR(255) NOT NULL,
    expiration INT NOT NULL
);

-- =====================================================================
-- 6. MÓDULOS AMPLIADOS (referencia: prototipo AGENDA360)
--    Dependientes · Inventario · Fidelización · Marketing · Presupuestos
--    · Fichas configurables y seguimiento · Lista de espera
--
--    Convención: los estados de flujo interno (borrador, enviado, etc.)
--    usan varchar + CHECK porque son máquinas de estado del sistema.
--    La tabla "statuses" queda para estados personalizables por tenant.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 6.1 Dependientes del cliente (mascotas, hijos, familiares a cargo)
-- ---------------------------------------------------------------------
CREATE TABLE customer_dependents (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    customer_id uuid NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
    name varchar(150) NOT NULL,
    kind varchar(50) NULL, -- mascota, hijo, familiar
    species varchar(80) NULL, -- Perro, Gato (rubro veterinario)
    breed varchar(80) NULL,
    birth_date date NULL,
    biological_sex varchar(20) NULL,
    notes text,
    status varchar(30) NOT NULL DEFAULT 'active',
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    deleted_at timestamptz
);
CREATE INDEX idx_customer_dependents_customer ON customer_dependents(tenant_id, customer_id);

ALTER TABLE appointments
    ADD CONSTRAINT fk_appointments_customer_dependent
    FOREIGN KEY (customer_dependent_id) REFERENCES customer_dependents(id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------
-- 6.2 Inventario
-- ---------------------------------------------------------------------
CREATE TABLE units (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NULL REFERENCES tenants(id) ON DELETE CASCADE, -- NULL = unidad global del SaaS
    name varchar(80) NOT NULL,
    abbreviation varchar(15) NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX idx_units_tenant_abbr ON units(tenant_id, abbreviation) WHERE tenant_id IS NOT NULL;
CREATE UNIQUE INDEX idx_units_global_abbr ON units(abbreviation) WHERE tenant_id IS NULL;

CREATE TABLE product_categories (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    name varchar(120) NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, name)
);

CREATE TABLE product_brands (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    name varchar(120) NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, name)
);

CREATE TABLE suppliers (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    type_document_id uuid NULL REFERENCES type_documents(id) ON DELETE SET NULL,
    document_number varchar(30) NULL,
    name varchar(200) NOT NULL,
    contact_name varchar(150) NULL,
    email varchar(250) NULL,
    phone varchar(50) NULL,
    address varchar(255) NULL,
    status varchar(30) NOT NULL DEFAULT 'active',
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_suppliers_tenant ON suppliers(tenant_id);

CREATE TABLE products (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    category_id uuid NULL REFERENCES product_categories(id) ON DELETE SET NULL,
    brand_id uuid NULL REFERENCES product_brands(id) ON DELETE SET NULL,
    unit_id uuid NULL REFERENCES units(id) ON DELETE SET NULL,
    sku varchar(60) NOT NULL,
    barcode varchar(60) NULL,
    name varchar(200) NOT NULL,
    description text,
    kind varchar(20) NOT NULL DEFAULT 'retail' CHECK (kind IN ('retail', 'supply', 'both')), -- venta, insumo interno o ambos
    cost numeric(14,2) NOT NULL DEFAULT 0 CHECK (cost >= 0),
    price numeric(14,2) NOT NULL DEFAULT 0 CHECK (price >= 0),
    min_stock numeric(14,4) NOT NULL DEFAULT 0 CHECK (min_stock >= 0),
    track_stock boolean NOT NULL DEFAULT true,
    status varchar(30) NOT NULL DEFAULT 'active',
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    deleted_at timestamptz,
    UNIQUE (tenant_id, sku)
);
CREATE INDEX idx_products_tenant_status ON products(tenant_id, status);
CREATE INDEX idx_products_tenant_barcode ON products(tenant_id, barcode);

CREATE TABLE warehouses (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    branch_id uuid NULL REFERENCES branches(id) ON DELETE SET NULL,
    name varchar(150) NOT NULL,
    is_default boolean NOT NULL DEFAULT false,
    status varchar(30) NOT NULL DEFAULT 'active',
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, name)
);

-- Saldo actual por almacén y producto
CREATE TABLE stock_balances (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    warehouse_id uuid NOT NULL REFERENCES warehouses(id) ON DELETE CASCADE,
    product_id uuid NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    quantity numeric(14,4) NOT NULL DEFAULT 0,
    in_transit numeric(14,4) NOT NULL DEFAULT 0 CHECK (in_transit >= 0), -- enviado por transferencia y aún no recibido
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (warehouse_id, product_id)
);
CREATE INDEX idx_stock_balances_tenant_product ON stock_balances(tenant_id, product_id);

-- Kardex: cada entrada o salida. quantity lleva signo (+ entrada, - salida)
CREATE TABLE inventory_movements (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    warehouse_id uuid NOT NULL REFERENCES warehouses(id),
    product_id uuid NOT NULL REFERENCES products(id),
    type varchar(30) NOT NULL CHECK (type IN ('initial', 'purchase', 'sale', 'service_consumption', 'adjustment', 'transfer_out', 'transfer_in', 'return')),
    quantity numeric(14,4) NOT NULL CHECK (quantity <> 0),
    unit_cost numeric(14,2) NULL CHECK (unit_cost >= 0),
    balance_after numeric(14,4) NULL,
    reference_type varchar(50) NULL, -- sale, appointment, purchase_order, stock_transfer
    reference_id uuid NULL,
    reason varchar(255) NULL,
    actor_user_id uuid NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_inventory_movements_product ON inventory_movements(tenant_id, product_id, created_at);
CREATE INDEX idx_inventory_movements_warehouse ON inventory_movements(tenant_id, warehouse_id, created_at);

-- Receta de insumos: qué productos consume un servicio al completarse
CREATE TABLE service_supplies (
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    service_id uuid NOT NULL REFERENCES services(id) ON DELETE CASCADE,
    product_id uuid NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    quantity numeric(14,4) NOT NULL CHECK (quantity > 0),
    PRIMARY KEY (service_id, product_id)
);

CREATE TABLE stock_transfers (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    code varchar(50) NOT NULL,
    from_warehouse_id uuid NOT NULL REFERENCES warehouses(id),
    to_warehouse_id uuid NOT NULL REFERENCES warehouses(id),
    status varchar(30) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'in_transit', 'received', 'cancelled')),
    notes text,
    created_by uuid NULL REFERENCES users(id) ON DELETE SET NULL,
    received_by uuid NULL REFERENCES users(id) ON DELETE SET NULL,
    sent_at timestamptz NULL,
    received_at timestamptz NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, code),
    CHECK (from_warehouse_id <> to_warehouse_id)
);

CREATE TABLE stock_transfer_items (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    stock_transfer_id uuid NOT NULL REFERENCES stock_transfers(id) ON DELETE CASCADE,
    product_id uuid NOT NULL REFERENCES products(id),
    quantity numeric(14,4) NOT NULL CHECK (quantity > 0),
    received_quantity numeric(14,4) NOT NULL DEFAULT 0 CHECK (received_quantity >= 0)
);
CREATE INDEX idx_stock_transfer_items_transfer ON stock_transfer_items(tenant_id, stock_transfer_id);

CREATE TABLE purchase_orders (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    code varchar(50) NOT NULL,
    supplier_id uuid NOT NULL REFERENCES suppliers(id),
    warehouse_id uuid NOT NULL REFERENCES warehouses(id),
    status varchar(30) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'ordered', 'partial', 'received', 'cancelled')),
    subtotal numeric(14,2) NOT NULL DEFAULT 0 CHECK (subtotal >= 0),
    tax numeric(14,2) NOT NULL DEFAULT 0 CHECK (tax >= 0),
    total numeric(14,2) NOT NULL DEFAULT 0 CHECK (total >= 0),
    expected_at date NULL,
    received_at timestamptz NULL,
    notes text,
    created_by uuid NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, code)
);

CREATE TABLE purchase_order_items (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    purchase_order_id uuid NOT NULL REFERENCES purchase_orders(id) ON DELETE CASCADE,
    product_id uuid NOT NULL REFERENCES products(id),
    quantity numeric(14,4) NOT NULL CHECK (quantity > 0),
    received_quantity numeric(14,4) NOT NULL DEFAULT 0 CHECK (received_quantity >= 0),
    unit_cost numeric(14,2) NOT NULL DEFAULT 0 CHECK (unit_cost >= 0),
    total numeric(14,2) NOT NULL DEFAULT 0 CHECK (total >= 0)
);
CREATE INDEX idx_purchase_order_items_order ON purchase_order_items(tenant_id, purchase_order_id);

-- ---------------------------------------------------------------------
-- 6.3 Fidelización: membresías, paquetes, puntos y gift cards
-- ---------------------------------------------------------------------
CREATE TABLE membership_plans (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    name varchar(150) NOT NULL,
    description text,
    price numeric(14,2) NOT NULL DEFAULT 0 CHECK (price >= 0),
    billing_period varchar(20) NOT NULL DEFAULT 'monthly' CHECK (billing_period IN ('monthly', 'quarterly', 'semiannual', 'yearly')),
    discount_percent numeric(5,2) NOT NULL DEFAULT 0 CHECK (discount_percent BETWEEN 0 AND 100),
    sessions_included integer NOT NULL DEFAULT 0 CHECK (sessions_included >= 0),
    benefits jsonb NOT NULL DEFAULT '{}', -- servicios incluidos, descuentos por categoría, etc.
    status varchar(30) NOT NULL DEFAULT 'active',
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, name)
);

CREATE TABLE memberships (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    membership_plan_id uuid NOT NULL REFERENCES membership_plans(id),
    customer_id uuid NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
    sale_id uuid NULL REFERENCES sales(id) ON DELETE SET NULL,
    status varchar(30) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'paused', 'grace', 'expired', 'cancelled')),
    starts_at date NOT NULL,
    ends_at date NOT NULL,
    paused_at timestamptz NULL,
    paused_days integer NOT NULL DEFAULT 0 CHECK (paused_days >= 0), -- la pausa extiende la vigencia
    auto_renew boolean NOT NULL DEFAULT false,
    cancelled_at timestamptz NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CHECK (ends_at >= starts_at)
);
CREATE INDEX idx_memberships_customer ON memberships(tenant_id, customer_id);
CREATE INDEX idx_memberships_status_end ON memberships(tenant_id, status, ends_at);

CREATE TABLE package_plans (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    name varchar(150) NOT NULL,
    description text,
    price numeric(14,2) NOT NULL DEFAULT 0 CHECK (price >= 0),
    validity_days integer NULL CHECK (validity_days > 0), -- NULL = sin vencimiento
    status varchar(30) NOT NULL DEFAULT 'active',
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, name)
);

CREATE TABLE package_plan_items (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    package_plan_id uuid NOT NULL REFERENCES package_plans(id) ON DELETE CASCADE,
    service_id uuid NOT NULL REFERENCES services(id),
    sessions integer NOT NULL CHECK (sessions > 0),
    UNIQUE (package_plan_id, service_id)
);

-- Paquete comprado por un cliente
CREATE TABLE customer_packages (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    package_plan_id uuid NOT NULL REFERENCES package_plans(id),
    customer_id uuid NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
    sale_id uuid NULL REFERENCES sales(id) ON DELETE SET NULL,
    status varchar(30) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'consumed', 'expired', 'cancelled')),
    purchased_at timestamptz NOT NULL DEFAULT now(),
    expires_at timestamptz NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_customer_packages_customer ON customer_packages(tenant_id, customer_id);

-- Saldo de sesiones por servicio dentro del paquete comprado
CREATE TABLE customer_package_items (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    customer_package_id uuid NOT NULL REFERENCES customer_packages(id) ON DELETE CASCADE,
    service_id uuid NOT NULL REFERENCES services(id),
    sessions_total integer NOT NULL CHECK (sessions_total > 0),
    sessions_used integer NOT NULL DEFAULT 0 CHECK (sessions_used >= 0),
    UNIQUE (customer_package_id, service_id)
);

CREATE TABLE package_usages (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    customer_package_id uuid NOT NULL REFERENCES customer_packages(id) ON DELETE CASCADE,
    service_id uuid NOT NULL REFERENCES services(id),
    appointment_id uuid NULL REFERENCES appointments(id) ON DELETE SET NULL,
    professional_id uuid NULL REFERENCES professionals(id) ON DELETE SET NULL,
    sessions integer NOT NULL DEFAULT 1 CHECK (sessions > 0),
    created_by uuid NULL REFERENCES users(id) ON DELETE SET NULL,
    used_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_package_usages_package ON package_usages(tenant_id, customer_package_id);

-- Movimientos de puntos. points lleva signo (+ acumula, - canjea o vence)
CREATE TABLE loyalty_transactions (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    customer_id uuid NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
    type varchar(30) NOT NULL CHECK (type IN ('earn', 'redeem', 'adjust', 'expire', 'referral', 'reversal')),
    points integer NOT NULL CHECK (points <> 0),
    balance_after integer NULL,
    sale_id uuid NULL REFERENCES sales(id) ON DELETE SET NULL,
    reason varchar(255) NULL,
    expires_at timestamptz NULL,
    actor_user_id uuid NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_loyalty_transactions_customer ON loyalty_transactions(tenant_id, customer_id, created_at);

CREATE TABLE gift_cards (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    code varchar(40) NOT NULL,
    initial_amount numeric(14,2) NOT NULL CHECK (initial_amount > 0),
    balance numeric(14,2) NOT NULL CHECK (balance >= 0),
    purchaser_customer_id uuid NULL REFERENCES customers(id) ON DELETE SET NULL,
    recipient_customer_id uuid NULL REFERENCES customers(id) ON DELETE SET NULL,
    recipient_name varchar(150) NULL,
    recipient_email varchar(250) NULL,
    sale_id uuid NULL REFERENCES sales(id) ON DELETE SET NULL,
    status varchar(30) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'redeemed', 'expired', 'cancelled')),
    expires_at timestamptz NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, code)
);

CREATE TABLE gift_card_transactions (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    gift_card_id uuid NOT NULL REFERENCES gift_cards(id) ON DELETE CASCADE,
    type varchar(30) NOT NULL CHECK (type IN ('issue', 'redeem', 'refund', 'extend', 'cancel')),
    amount numeric(14,2) NOT NULL DEFAULT 0,
    sale_id uuid NULL REFERENCES sales(id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_gift_card_transactions_card ON gift_card_transactions(tenant_id, gift_card_id);

-- ---------------------------------------------------------------------
-- 6.4 Marketing: etiquetas, consentimientos, plantillas, campañas,
--     cupones, automatizaciones y bitácora de mensajes
-- ---------------------------------------------------------------------
CREATE TABLE customer_tags (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    name varchar(80) NOT NULL,
    color varchar(7) DEFAULT '#022340',
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, name)
);

CREATE TABLE customer_tag_assignments (
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    customer_id uuid NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
    customer_tag_id uuid NOT NULL REFERENCES customer_tags(id) ON DELETE CASCADE,
    PRIMARY KEY (customer_id, customer_tag_id)
);

-- Consentimiento por canal (Ley 29733 de protección de datos personales)
CREATE TABLE customer_consents (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    customer_id uuid NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
    channel varchar(30) NOT NULL CHECK (channel IN ('whatsapp', 'email', 'sms', 'marketing', 'data_processing')),
    granted boolean NOT NULL DEFAULT false,
    source varchar(50) NULL, -- staff, booking, portal
    granted_at timestamptz NULL,
    revoked_at timestamptz NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (customer_id, channel)
);

CREATE TABLE marketing_templates (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    name varchar(150) NOT NULL,
    channel varchar(30) NOT NULL CHECK (channel IN ('whatsapp', 'email', 'sms')),
    subject varchar(200) NULL,
    body text NOT NULL,
    variables jsonb NOT NULL DEFAULT '[]', -- ej: ["first_name", "appointment_date"]
    status varchar(30) NOT NULL DEFAULT 'active',
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, name)
);

CREATE TABLE coupons (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    code varchar(40) NOT NULL,
    description varchar(255) NULL,
    discount_type varchar(20) NOT NULL CHECK (discount_type IN ('percent', 'amount')),
    discount_value numeric(14,2) NOT NULL CHECK (discount_value > 0),
    min_purchase numeric(14,2) NOT NULL DEFAULT 0 CHECK (min_purchase >= 0),
    max_uses integer NULL CHECK (max_uses > 0), -- NULL = ilimitado
    used_count integer NOT NULL DEFAULT 0 CHECK (used_count >= 0),
    starts_at timestamptz NULL,
    ends_at timestamptz NULL,
    status varchar(30) NOT NULL DEFAULT 'active',
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, code)
);

CREATE TABLE coupon_redemptions (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    coupon_id uuid NOT NULL REFERENCES coupons(id) ON DELETE CASCADE,
    sale_id uuid NULL REFERENCES sales(id) ON DELETE SET NULL,
    customer_id uuid NULL REFERENCES customers(id) ON DELETE SET NULL,
    discount_amount numeric(14,2) NOT NULL DEFAULT 0 CHECK (discount_amount >= 0),
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_coupon_redemptions_coupon ON coupon_redemptions(tenant_id, coupon_id);

CREATE TABLE marketing_campaigns (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    name varchar(150) NOT NULL,
    channel varchar(30) NOT NULL CHECK (channel IN ('whatsapp', 'email', 'sms')),
    marketing_template_id uuid NULL REFERENCES marketing_templates(id) ON DELETE SET NULL,
    coupon_id uuid NULL REFERENCES coupons(id) ON DELETE SET NULL,
    subject varchar(200) NULL,
    body text NULL,
    segment jsonb NOT NULL DEFAULT '{}', -- ej: {"type": "tag", "tag_id": "..."} o {"type": "inactive", "days": 90}
    status varchar(30) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'scheduled', 'sending', 'sent', 'cancelled')),
    scheduled_at timestamptz NULL,
    sent_at timestamptz NULL,
    stats jsonb NOT NULL DEFAULT '{}',
    created_by uuid NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_marketing_campaigns_tenant_status ON marketing_campaigns(tenant_id, status);

CREATE TABLE marketing_campaign_recipients (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    marketing_campaign_id uuid NOT NULL REFERENCES marketing_campaigns(id) ON DELETE CASCADE,
    customer_id uuid NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
    status varchar(30) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'sent', 'delivered', 'failed', 'skipped')),
    skip_reason varchar(255) NULL, -- ej: sin consentimiento, sin teléfono
    sent_at timestamptz NULL,
    UNIQUE (marketing_campaign_id, customer_id)
);

CREATE TABLE automation_rules (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    name varchar(150) NOT NULL,
    trigger_event varchar(60) NOT NULL, -- ej: appointment.completed, appointment.no_show, customer.inactive, customer.birthday
    conditions jsonb NOT NULL DEFAULT '{}',
    channel varchar(30) NOT NULL CHECK (channel IN ('whatsapp', 'email', 'sms')),
    marketing_template_id uuid NULL REFERENCES marketing_templates(id) ON DELETE SET NULL,
    delay_minutes integer NOT NULL DEFAULT 0 CHECK (delay_minutes >= 0),
    is_active boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_automation_rules_tenant_event ON automation_rules(tenant_id, trigger_event);

CREATE TABLE automation_logs (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    automation_rule_id uuid NOT NULL REFERENCES automation_rules(id) ON DELETE CASCADE,
    customer_id uuid NULL REFERENCES customers(id) ON DELETE SET NULL,
    appointment_id uuid NULL REFERENCES appointments(id) ON DELETE SET NULL,
    result varchar(30) NOT NULL CHECK (result IN ('sent', 'skipped', 'failed')),
    reason varchar(255) NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_automation_logs_rule ON automation_logs(tenant_id, automation_rule_id, created_at);

-- Bitácora de mensajes enviados o recibidos por cliente
CREATE TABLE customer_messages (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    customer_id uuid NULL REFERENCES customers(id) ON DELETE SET NULL,
    channel varchar(30) NOT NULL CHECK (channel IN ('whatsapp', 'email', 'sms')),
    direction varchar(20) NOT NULL DEFAULT 'outbound' CHECK (direction IN ('outbound', 'inbound')),
    kind varchar(30) NOT NULL DEFAULT 'manual' CHECK (kind IN ('manual', 'campaign', 'automation', 'reminder', 'transactional')),
    subject varchar(200) NULL,
    body text NOT NULL,
    status varchar(30) NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'sent', 'delivered', 'failed')),
    marketing_campaign_id uuid NULL REFERENCES marketing_campaigns(id) ON DELETE SET NULL,
    automation_rule_id uuid NULL REFERENCES automation_rules(id) ON DELETE SET NULL,
    appointment_id uuid NULL REFERENCES appointments(id) ON DELETE SET NULL,
    external_reference varchar(190) NULL,
    error varchar(255) NULL,
    sent_at timestamptz NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_customer_messages_customer ON customer_messages(tenant_id, customer_id, created_at);

-- ---------------------------------------------------------------------
-- 6.5 Presupuestos y planes de tratamiento
-- ---------------------------------------------------------------------
CREATE TABLE quotes (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    branch_id uuid NULL REFERENCES branches(id) ON DELETE SET NULL,
    customer_id uuid NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
    customer_dependent_id uuid NULL REFERENCES customer_dependents(id) ON DELETE SET NULL,
    professional_id uuid NULL REFERENCES professionals(id) ON DELETE SET NULL,
    code varchar(50) NOT NULL,
    title varchar(200) NULL,
    status varchar(30) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'sent', 'accepted', 'rejected', 'expired', 'cancelled')),
    valid_until date NULL,
    subtotal numeric(14,2) NOT NULL DEFAULT 0 CHECK (subtotal >= 0),
    discount numeric(14,2) NOT NULL DEFAULT 0 CHECK (discount >= 0),
    tax numeric(14,2) NOT NULL DEFAULT 0 CHECK (tax >= 0),
    total numeric(14,2) NOT NULL DEFAULT 0 CHECK (total >= 0),
    notes text,
    accepted_at timestamptz NULL,
    accepted_via varchar(20) NULL CHECK (accepted_via IN ('staff', 'portal')),
    sale_id uuid NULL REFERENCES sales(id) ON DELETE SET NULL, -- venta o cuenta por cobrar generada al aceptar
    created_by uuid NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, code)
);
CREATE INDEX idx_quotes_customer ON quotes(tenant_id, customer_id);
CREATE INDEX idx_quotes_tenant_status ON quotes(tenant_id, status);

CREATE TABLE quote_items (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    quote_id uuid NOT NULL REFERENCES quotes(id) ON DELETE CASCADE,
    item_type varchar(30) NOT NULL CHECK (item_type IN ('service', 'product', 'package', 'other')),
    reference_id uuid NULL, -- id del servicio, producto o paquete según item_type
    description varchar(255) NOT NULL,
    quantity numeric(14,4) NOT NULL CHECK (quantity > 0),
    unit_price numeric(14,2) NOT NULL CHECK (unit_price >= 0),
    discount numeric(14,2) NOT NULL DEFAULT 0 CHECK (discount >= 0),
    total numeric(14,2) NOT NULL CHECK (total >= 0),
    sessions_total integer NOT NULL DEFAULT 0 CHECK (sessions_total >= 0), -- plan de sesiones al aceptar
    sessions_used integer NOT NULL DEFAULT 0 CHECK (sessions_used >= 0),
    metadata jsonb NOT NULL DEFAULT '{}', -- ej: pieza dental en odontograma
    sort_order integer NOT NULL DEFAULT 0
);
CREATE INDEX idx_quote_items_quote ON quote_items(tenant_id, quote_id);

-- Cuotas de pago del presupuesto aceptado
CREATE TABLE quote_installments (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    quote_id uuid NOT NULL REFERENCES quotes(id) ON DELETE CASCADE,
    number integer NOT NULL CHECK (number > 0),
    due_date date NOT NULL,
    amount numeric(14,2) NOT NULL CHECK (amount > 0),
    status varchar(30) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'paid', 'overdue', 'cancelled')),
    payment_id uuid NULL REFERENCES payments(id) ON DELETE SET NULL,
    paid_at timestamptz NULL,
    UNIQUE (quote_id, number)
);

CREATE TABLE quote_status_history (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    quote_id uuid NOT NULL REFERENCES quotes(id) ON DELETE CASCADE,
    from_status varchar(30) NULL,
    to_status varchar(30) NOT NULL,
    actor_user_id uuid NULL REFERENCES users(id) ON DELETE SET NULL,
    note varchar(255) NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_quote_status_history_quote ON quote_status_history(tenant_id, quote_id);

-- ---------------------------------------------------------------------
-- 6.6 Fichas de atención configurables y controles de seguimiento
--     (medical_records se mantiene como ficha clínica SOAP fija)
-- ---------------------------------------------------------------------
CREATE TABLE record_templates (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    name varchar(150) NOT NULL,
    description varchar(255) NULL,
    -- Definición de campos: [{"key","label","type","required","options","placeholder","days","step"}]
    -- type: text, textarea, number, select, multiselect, date, boolean, scale, odontogram, next_control
    fields jsonb NOT NULL DEFAULT '[]',
    consent_text text NULL,
    require_consent boolean NOT NULL DEFAULT false,
    require_signature boolean NOT NULL DEFAULT false,
    is_default boolean NOT NULL DEFAULT false,
    version integer NOT NULL DEFAULT 1,
    status varchar(30) NOT NULL DEFAULT 'active',
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, name)
);

-- Servicios que usan cada plantilla de ficha
CREATE TABLE record_template_services (
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    record_template_id uuid NOT NULL REFERENCES record_templates(id) ON DELETE CASCADE,
    service_id uuid NOT NULL REFERENCES services(id) ON DELETE CASCADE,
    PRIMARY KEY (record_template_id, service_id)
);

CREATE TABLE care_records (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    branch_id uuid NULL REFERENCES branches(id) ON DELETE SET NULL,
    record_template_id uuid NULL REFERENCES record_templates(id) ON DELETE SET NULL,
    template_snapshot jsonb NOT NULL DEFAULT '[]', -- copia de los campos al momento de llenar la ficha
    customer_id uuid NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
    customer_dependent_id uuid NULL REFERENCES customer_dependents(id) ON DELETE SET NULL,
    appointment_id uuid NULL REFERENCES appointments(id) ON DELETE SET NULL,
    professional_id uuid NULL REFERENCES professionals(id) ON DELETE SET NULL,
    data jsonb NOT NULL DEFAULT '{}', -- valores capturados por clave de campo
    consent_accepted boolean NOT NULL DEFAULT false,
    consent_accepted_at timestamptz NULL,
    signature_url varchar(255) NULL,
    is_private boolean NOT NULL DEFAULT false, -- visible solo para su autor
    status varchar(30) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'signed', 'locked')),
    template_name varchar(150) NULL, -- nombre de la plantilla al llenar la ficha
    consent_text text NULL, -- texto de consentimiento que se aceptó
    signed_at timestamptz NULL,
    signed_by uuid NULL REFERENCES users(id) ON DELETE SET NULL,
    locked_at timestamptz NULL, -- desde ese momento solo se admiten notas (adendas)
    created_by uuid NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_care_records_customer ON care_records(tenant_id, customer_id, created_at);
-- Una sola ficha por cita.
CREATE UNIQUE INDEX idx_care_records_appointment ON care_records(appointment_id) WHERE appointment_id IS NOT NULL;

CREATE TABLE care_record_addenda (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    care_record_id uuid NOT NULL REFERENCES care_records(id) ON DELETE CASCADE,
    author_user_id uuid NULL REFERENCES users(id) ON DELETE SET NULL,
    note text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_care_record_addenda_record ON care_record_addenda(tenant_id, care_record_id);

-- Controles y seguimiento (vacuna, profilaxis, retoque, próxima sesión). Nacen de la
-- ficha de una atención, del servicio atendido (services.recall_*) o los registra el personal.
CREATE TABLE recalls (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    customer_id uuid NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
    customer_dependent_id uuid NULL REFERENCES customer_dependents(id) ON DELETE SET NULL,
    care_record_id uuid NULL REFERENCES care_records(id) ON DELETE SET NULL,
    source_appointment_id uuid NULL REFERENCES appointments(id) ON DELETE SET NULL, -- cita de la atención que lo originó
    service_id uuid NULL REFERENCES services(id) ON DELETE SET NULL,
    professional_id uuid NULL REFERENCES professionals(id) ON DELETE SET NULL,
    label varchar(150) NOT NULL,
    due_date date NOT NULL,
    status varchar(30) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'notified', 'booked', 'done', 'cancelled')),
    notified_at timestamptz NULL,
    appointment_id uuid NULL REFERENCES appointments(id) ON DELETE SET NULL, -- cita en la que se convirtió
    notes text NULL,
    closed_reason varchar(255) NULL, -- por qué se descartó o se dio por realizado sin cita
    created_by uuid NULL REFERENCES users(id) ON DELETE SET NULL, -- solo los registrados a mano
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_recalls_tenant_due ON recalls(tenant_id, status, due_date);
CREATE INDEX idx_recalls_customer ON recalls(tenant_id, customer_id);

-- ---------------------------------------------------------------------
-- 6.7 Lista de espera
-- ---------------------------------------------------------------------
CREATE TABLE waitlist_entries (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    branch_id uuid NOT NULL REFERENCES branches(id) ON DELETE CASCADE,
    customer_id uuid NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
    customer_dependent_id uuid NULL REFERENCES customer_dependents(id) ON DELETE SET NULL,
    service_id uuid NOT NULL REFERENCES services(id) ON DELETE CASCADE,
    professional_id uuid NULL REFERENCES professionals(id) ON DELETE SET NULL, -- NULL = cualquier profesional
    date_from date NOT NULL,
    date_to date NULL,
    time_from time NULL, -- franja horaria preferida
    time_to time NULL,
    notes text,
    status varchar(30) NOT NULL DEFAULT 'waiting' CHECK (status IN ('waiting', 'notified', 'booked', 'expired', 'cancelled')),
    priority integer NOT NULL DEFAULT 0,
    notified_at timestamptz NULL,
    expires_at timestamptz NULL,
    appointment_id uuid NULL REFERENCES appointments(id) ON DELETE SET NULL, -- cita obtenida desde la lista
    created_by uuid NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CHECK (date_to IS NULL OR date_to >= date_from),
    CHECK (time_to IS NULL OR time_from IS NULL OR time_to > time_from)
);
CREATE INDEX idx_waitlist_tenant_status ON waitlist_entries(tenant_id, branch_id, status, date_from);
CREATE INDEX idx_waitlist_customer ON waitlist_entries(tenant_id, customer_id);

COMMIT;
