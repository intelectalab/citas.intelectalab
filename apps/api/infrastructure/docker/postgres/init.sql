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

CREATE TABLE tenants (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name varchar(150) NOT NULL,
    slug varchar(100) NOT NULL UNIQUE,
    timezone varchar(64) NOT NULL DEFAULT 'America/Lima',
    status varchar(30) NOT NULL DEFAULT 'active',
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    deleted_at timestamptz
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

-- 4. Tabla unificada de Customers
CREATE TABLE patients (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    code varchar(50) NOT NULL, -- Historia clínica o código de paciente
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
    status_id uuid NULL REFERENCES statuses(id), -- Vinculado a módulo 'patients'
    manager_user_id uuid NULL REFERENCES users(id) ON DELETE SET NULL,
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
CREATE INDEX idx_patients_tenant ON patients(tenant_id);
CREATE INDEX idx_patients_tenant_phone ON patients(tenant_id, phone);
CREATE INDEX idx_patients_tenant_email ON patients(tenant_id, email);
CREATE INDEX idx_patients_tenant_document ON patients(tenant_id, document_number);
CREATE INDEX idx_patients_status ON patients(status_id);

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
    status_id uuid NULL REFERENCES statuses(id),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
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
    CHECK (ends_at > starts_at)
);
CREATE INDEX idx_blocks_tenant_time ON availability_blocks(tenant_id, starts_at, ends_at);

CREATE TABLE appointments (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    branch_id uuid NOT NULL REFERENCES branches(id) ON DELETE CASCADE,
    room_id uuid NULL REFERENCES rooms(id) ON DELETE SET NULL,
    patient_id uuid NOT NULL REFERENCES patients(id) ON DELETE CASCADE,
    service_id uuid NOT NULL REFERENCES services(id) ON DELETE RESTRICT,
    professional_id uuid NOT NULL REFERENCES professionals(id) ON DELETE RESTRICT,
    starts_at timestamptz NOT NULL,
    ends_at timestamptz NOT NULL,
    status_id uuid NULL REFERENCES statuses(id),
    source varchar(30) NOT NULL DEFAULT 'staff',
    notes text,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CHECK (ends_at > starts_at)
);
--
CREATE INDEX idx_appt_tenant_start ON appointments(tenant_id, starts_at);
CREATE INDEX idx_appt_tenant_branch_date ON appointments(tenant_id, branch_id, starts_at);
CREATE INDEX idx_appt_prof_time ON appointments(tenant_id, professional_id, starts_at, ends_at);
CREATE TABLE appointment_status_history (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id),
    appointment_id uuid NOT NULL REFERENCES appointments(id),
    from_status_id uuid REFERENCES statuses(id),
    to_status_id uuid NOT NULL REFERENCES statuses(id),
    actor_user_id uuid REFERENCES users(id),
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE medical_records (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    patient_id uuid NOT NULL REFERENCES patients(id) ON DELETE CASCADE,
    appointment_id uuid NULL REFERENCES appointments(id) ON DELETE SET NULL, -- Vinculado a la cita que originó la atención
    professional_id uuid NOT NULL REFERENCES professionals(id) ON DELETE RESTRICT,
    subjective text NULL, -- Síntomas / Motivo de consulta (Lo que dice el paciente)
    objective text NULL, -- Examen físico / Signos vitales (Lo que observa el médico)
    assessment text NULL, -- Diagnóstico / Impresión diagnóstica (Código CIE-10 opcional)
    plan text NULL, -- Tratamiento, recetas, indicaciones y exámenes auxiliares
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_medical_records_patient ON medical_records(tenant_id, patient_id);

CREATE TABLE sales (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id uuid NOT NULL REFERENCES tenants(id),
    branch_id uuid NOT NULL REFERENCES branches(id),
    patient_id uuid REFERENCES patients(id),
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
    external_invoice_id VARCHAR(255),
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

COMMIT;