--
-- PostgreSQL database dump
--

\restrict XEfBlEl6qF6XkmG9HufOzfXCBcMYVNQ1SpsLD6P9jb6rKC83gXJMiKneuGFnO9p

-- Dumped from database version 17.11
-- Dumped by pg_dump version 17.11

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: pgcrypto; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public;


--
-- Name: EXTENSION pgcrypto; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION pgcrypto IS 'cryptographic functions';


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: appointment_status_history; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.appointment_status_history (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    appointment_id uuid NOT NULL,
    from_status character varying(20),
    to_status character varying(20) NOT NULL,
    reason character varying(255),
    actor_user_id uuid,
    actor_customer_id uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: appointments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.appointments (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    code character varying(20) NOT NULL,
    branch_id uuid NOT NULL,
    room_id uuid,
    customer_id uuid NOT NULL,
    customer_dependent_id uuid,
    service_id uuid NOT NULL,
    professional_id uuid NOT NULL,
    starts_at timestamp with time zone NOT NULL,
    ends_at timestamp with time zone NOT NULL,
    status character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    source character varying(30) DEFAULT 'staff'::character varying NOT NULL,
    price numeric(14,2) DEFAULT 0 NOT NULL,
    notes text,
    reschedule_count integer DEFAULT 0 NOT NULL,
    override_reason character varying(255),
    cancel_reason character varying(255),
    cancelled_by character varying(20),
    confirmed_at timestamp with time zone,
    checked_in_at timestamp with time zone,
    started_at timestamp with time zone,
    completed_at timestamp with time zone,
    cancelled_at timestamp with time zone,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    currency character(3) DEFAULT 'PEN'::bpchar NOT NULL,
    customer_package_id uuid,
    CONSTRAINT appointments_check CHECK ((ends_at > starts_at)),
    CONSTRAINT appointments_price_check CHECK ((price >= (0)::numeric)),
    CONSTRAINT appointments_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'confirmed'::character varying, 'checked_in'::character varying, 'in_progress'::character varying, 'completed'::character varying, 'cancelled'::character varying, 'no_show'::character varying])::text[])))
);


--
-- Name: audit_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.audit_events (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid,
    actor_user_id uuid,
    action character varying(100) NOT NULL,
    entity_type character varying(100),
    entity_id uuid,
    ip_address inet,
    user_agent text,
    before_data jsonb,
    after_data jsonb,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: automation_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.automation_logs (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    automation_rule_id uuid NOT NULL,
    customer_id uuid,
    appointment_id uuid,
    result character varying(30) NOT NULL,
    reason character varying(255),
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT automation_logs_result_check CHECK (((result)::text = ANY ((ARRAY['sent'::character varying, 'skipped'::character varying, 'failed'::character varying])::text[])))
);


--
-- Name: automation_rules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.automation_rules (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    name character varying(150) NOT NULL,
    trigger_event character varying(60) NOT NULL,
    conditions jsonb DEFAULT '{}'::jsonb NOT NULL,
    channel character varying(30) NOT NULL,
    marketing_template_id uuid,
    delay_minutes integer DEFAULT 0 NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT automation_rules_channel_check CHECK (((channel)::text = ANY ((ARRAY['whatsapp'::character varying, 'email'::character varying, 'sms'::character varying])::text[]))),
    CONSTRAINT automation_rules_delay_minutes_check CHECK ((delay_minutes >= 0))
);


--
-- Name: availability_blocks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.availability_blocks (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    branch_id uuid,
    professional_id uuid,
    starts_at timestamp with time zone NOT NULL,
    ends_at timestamp with time zone NOT NULL,
    reason character varying(255),
    kind character varying(20) DEFAULT 'other'::character varying NOT NULL,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT availability_blocks_check CHECK ((ends_at > starts_at)),
    CONSTRAINT availability_blocks_kind_check CHECK (((kind)::text = ANY ((ARRAY['vacation'::character varying, 'leave'::character varying, 'closure'::character varying, 'other'::character varying])::text[])))
);


--
-- Name: branch_hours; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.branch_hours (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    branch_id uuid NOT NULL,
    weekday smallint NOT NULL,
    opens_local time without time zone NOT NULL,
    closes_local time without time zone NOT NULL,
    CONSTRAINT branch_hours_check CHECK ((closes_local > opens_local)),
    CONSTRAINT branch_hours_weekday_check CHECK (((weekday >= 0) AND (weekday <= 6)))
);


--
-- Name: branches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.branches (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    company_id uuid NOT NULL,
    name character varying(150) NOT NULL,
    address character varying(255),
    district character varying(100),
    department character varying(100),
    phone character varying(30),
    timezone character varying(64) DEFAULT 'America/Lima'::character varying NOT NULL,
    status character varying(30) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: business_types; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.business_types (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    slug character varying(50) NOT NULL,
    name character varying(150) NOT NULL,
    short_name character varying(80) NOT NULL,
    description character varying(500),
    icon character varying(50),
    color character varying(7) DEFAULT '#3C7DC4'::character varying,
    vocabulary jsonb DEFAULT '{}'::jsonb NOT NULL,
    modules_off jsonb DEFAULT '[]'::jsonb NOT NULL,
    default_rules jsonb DEFAULT '{}'::jsonb NOT NULL,
    dependents jsonb DEFAULT '{}'::jsonb NOT NULL,
    template jsonb DEFAULT '{}'::jsonb NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    status character varying(30) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration integer NOT NULL
);


--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration integer NOT NULL
);


--
-- Name: care_record_addenda; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.care_record_addenda (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    care_record_id uuid NOT NULL,
    author_user_id uuid,
    note text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: care_records; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.care_records (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    branch_id uuid,
    record_template_id uuid,
    template_snapshot jsonb DEFAULT '[]'::jsonb NOT NULL,
    customer_id uuid NOT NULL,
    customer_dependent_id uuid,
    appointment_id uuid,
    professional_id uuid,
    data jsonb DEFAULT '{}'::jsonb NOT NULL,
    consent_accepted boolean DEFAULT false NOT NULL,
    consent_accepted_at timestamp with time zone,
    signature_url character varying(255),
    is_private boolean DEFAULT false NOT NULL,
    status character varying(30) DEFAULT 'draft'::character varying NOT NULL,
    template_name character varying(150),
    consent_text text,
    require_consent boolean DEFAULT false NOT NULL,
    signed_at timestamp with time zone,
    signed_by uuid,
    locked_at timestamp with time zone,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT care_records_status_check CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'signed'::character varying, 'locked'::character varying])::text[])))
);


--
-- Name: cash_movements; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cash_movements (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    cash_session_id uuid NOT NULL,
    type character varying(30) NOT NULL,
    amount numeric(14,2) NOT NULL,
    reference_type character varying(50),
    reference_id uuid,
    description character varying(255),
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT cash_movements_amount_check CHECK ((amount > (0)::numeric)),
    CONSTRAINT cash_movements_type_check CHECK (((type)::text = ANY ((ARRAY['sale'::character varying, 'income'::character varying, 'expense'::character varying, 'withdrawal'::character varying, 'refund'::character varying])::text[])))
);


--
-- Name: cash_registers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cash_registers (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    branch_id uuid NOT NULL,
    name character varying(100) NOT NULL,
    status character varying(20) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: cash_sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cash_sessions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    branch_id uuid NOT NULL,
    cash_register_id uuid NOT NULL,
    status character varying(20) DEFAULT 'open'::character varying NOT NULL,
    opened_by uuid NOT NULL,
    closed_by uuid,
    opening_amount numeric(14,2) DEFAULT 0 NOT NULL,
    expected_amount numeric(14,2),
    closing_amount numeric(14,2),
    difference numeric(14,2),
    closing_notes character varying(500),
    opened_at timestamp with time zone DEFAULT now() NOT NULL,
    closed_at timestamp with time zone,
    currency character(3) DEFAULT 'PEN'::bpchar NOT NULL,
    CONSTRAINT cash_sessions_closing_amount_check CHECK ((closing_amount >= (0)::numeric)),
    CONSTRAINT cash_sessions_opening_amount_check CHECK ((opening_amount >= (0)::numeric)),
    CONSTRAINT cash_sessions_status_check CHECK (((status)::text = ANY ((ARRAY['open'::character varying, 'closed'::character varying])::text[])))
);


--
-- Name: companies; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.companies (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    legal_name character varying(200),
    trade_name character varying(200) NOT NULL,
    tax_id character varying(30),
    phone character varying(30),
    address character varying(255),
    department character varying(100) DEFAULT 'Lima'::character varying,
    district character varying(100),
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: coupon_redemptions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.coupon_redemptions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    coupon_id uuid NOT NULL,
    sale_id uuid,
    customer_id uuid,
    discount_amount numeric(14,2) DEFAULT 0 NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT coupon_redemptions_discount_amount_check CHECK ((discount_amount >= (0)::numeric))
);


--
-- Name: coupons; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.coupons (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    code character varying(40) NOT NULL,
    description character varying(255),
    discount_type character varying(20) NOT NULL,
    discount_value numeric(14,2) NOT NULL,
    min_purchase numeric(14,2) DEFAULT 0 NOT NULL,
    max_uses integer,
    used_count integer DEFAULT 0 NOT NULL,
    starts_at timestamp with time zone,
    ends_at timestamp with time zone,
    status character varying(30) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT coupons_discount_type_check CHECK (((discount_type)::text = ANY ((ARRAY['percent'::character varying, 'amount'::character varying])::text[]))),
    CONSTRAINT coupons_discount_value_check CHECK ((discount_value > (0)::numeric)),
    CONSTRAINT coupons_max_uses_check CHECK ((max_uses > 0)),
    CONSTRAINT coupons_min_purchase_check CHECK ((min_purchase >= (0)::numeric)),
    CONSTRAINT coupons_used_count_check CHECK ((used_count >= 0))
);


--
-- Name: currencies; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.currencies (
    code character(3) NOT NULL,
    name character varying(60) NOT NULL,
    symbol character varying(8) NOT NULL,
    decimals smallint DEFAULT 2 NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    sort_order smallint DEFAULT 0 NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT currencies_decimals_check CHECK (((decimals >= 0) AND (decimals <= 4)))
);


--
-- Name: currency_changes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.currency_changes (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    kind character varying(20) NOT NULL,
    from_currency character(3) NOT NULL,
    to_currency character(3) NOT NULL,
    exchange_rate numeric(18,8),
    starts_on date,
    ends_on date,
    counts jsonb DEFAULT '{}'::jsonb NOT NULL,
    excluded integer DEFAULT 0 NOT NULL,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT currency_changes_kind_check CHECK (((kind)::text = ANY ((ARRAY['setting'::character varying, 'relabel'::character varying, 'convert'::character varying])::text[])))
);


--
-- Name: customer_consents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.customer_consents (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    customer_id uuid NOT NULL,
    channel character varying(30) NOT NULL,
    granted boolean DEFAULT false NOT NULL,
    source character varying(50),
    granted_at timestamp with time zone,
    revoked_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT customer_consents_channel_check CHECK (((channel)::text = ANY ((ARRAY['whatsapp'::character varying, 'email'::character varying, 'sms'::character varying, 'marketing'::character varying, 'data_processing'::character varying])::text[])))
);


--
-- Name: customer_dependents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.customer_dependents (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    customer_id uuid NOT NULL,
    name character varying(150) NOT NULL,
    kind character varying(50),
    species character varying(80),
    breed character varying(80),
    birth_date date,
    biological_sex character varying(20),
    notes text,
    status character varying(30) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    deleted_at timestamp with time zone
);


--
-- Name: customer_messages; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.customer_messages (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    customer_id uuid,
    channel character varying(30) NOT NULL,
    direction character varying(20) DEFAULT 'outbound'::character varying NOT NULL,
    kind character varying(30) DEFAULT 'manual'::character varying NOT NULL,
    subject character varying(200),
    body text NOT NULL,
    status character varying(30) DEFAULT 'queued'::character varying NOT NULL,
    marketing_campaign_id uuid,
    automation_rule_id uuid,
    appointment_id uuid,
    external_reference character varying(190),
    error character varying(255),
    sent_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT customer_messages_channel_check CHECK (((channel)::text = ANY ((ARRAY['whatsapp'::character varying, 'email'::character varying, 'sms'::character varying])::text[]))),
    CONSTRAINT customer_messages_direction_check CHECK (((direction)::text = ANY ((ARRAY['outbound'::character varying, 'inbound'::character varying])::text[]))),
    CONSTRAINT customer_messages_kind_check CHECK (((kind)::text = ANY ((ARRAY['manual'::character varying, 'campaign'::character varying, 'automation'::character varying, 'reminder'::character varying, 'transactional'::character varying])::text[]))),
    CONSTRAINT customer_messages_status_check CHECK (((status)::text = ANY ((ARRAY['queued'::character varying, 'sent'::character varying, 'delivered'::character varying, 'failed'::character varying])::text[])))
);


--
-- Name: customer_package_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.customer_package_events (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    customer_package_id uuid NOT NULL,
    kind character varying(20) NOT NULL,
    detail character varying(500),
    days integer,
    amount numeric(14,2),
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT customer_package_events_kind_check CHECK (((kind)::text = ANY ((ARRAY['sold'::character varying, 'scheduled'::character varying, 'unscheduled'::character varying, 'extended'::character varying, 'cancelled'::character varying, 'refunded'::character varying])::text[])))
);


--
-- Name: customer_package_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.customer_package_items (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    customer_package_id uuid NOT NULL,
    service_id uuid NOT NULL,
    sessions_total integer NOT NULL,
    sessions_used integer DEFAULT 0 NOT NULL,
    unit_price numeric(14,2) DEFAULT 0 NOT NULL,
    "position" smallint DEFAULT 0 NOT NULL,
    CONSTRAINT customer_package_items_check CHECK ((sessions_used <= sessions_total)),
    CONSTRAINT customer_package_items_sessions_total_check CHECK ((sessions_total > 0)),
    CONSTRAINT customer_package_items_sessions_used_check CHECK ((sessions_used >= 0)),
    CONSTRAINT customer_package_items_unit_price_check CHECK ((unit_price >= (0)::numeric))
);


--
-- Name: customer_packages; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.customer_packages (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    code character varying(20) NOT NULL,
    package_plan_id uuid,
    customer_id uuid NOT NULL,
    branch_id uuid,
    sale_id uuid,
    name character varying(150) NOT NULL,
    price numeric(14,2) DEFAULT 0 NOT NULL,
    currency character(3) DEFAULT 'PEN'::bpchar NOT NULL,
    conditions jsonb DEFAULT '{}'::jsonb NOT NULL,
    professional_id uuid,
    status character varying(30) DEFAULT 'active'::character varying NOT NULL,
    purchased_at timestamp with time zone DEFAULT now() NOT NULL,
    expires_at timestamp with time zone NOT NULL,
    cancelled_at timestamp with time zone,
    cancel_reason character varying(255),
    refunded_amount numeric(14,2) DEFAULT 0 NOT NULL,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT customer_packages_price_check CHECK ((price >= (0)::numeric)),
    CONSTRAINT customer_packages_refunded_amount_check CHECK ((refunded_amount >= (0)::numeric)),
    CONSTRAINT customer_packages_status_check CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'consumed'::character varying, 'expired'::character varying, 'cancelled'::character varying])::text[])))
);


--
-- Name: customer_tag_assignments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.customer_tag_assignments (
    tenant_id uuid NOT NULL,
    customer_id uuid NOT NULL,
    customer_tag_id uuid NOT NULL
);


--
-- Name: customer_tags; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.customer_tags (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    name character varying(80) NOT NULL,
    color character varying(7) DEFAULT '#022340'::character varying,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: customers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.customers (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    code character varying(50) NOT NULL,
    first_name character varying(150) NOT NULL,
    last_name character varying(150) NOT NULL,
    birth_date date,
    biological_sex character varying(20),
    blood_type character varying(10),
    type_document_id uuid,
    document_number character varying(30),
    email character varying(250),
    phone character varying(50),
    emergency_contact_name character varying(200),
    emergency_contact_phone character varying(50),
    status_id uuid,
    manager_user_id uuid,
    password_hash character varying(255),
    must_change_password boolean DEFAULT false NOT NULL,
    portal_enabled boolean DEFAULT true NOT NULL,
    booking_strikes_reset_at timestamp with time zone,
    password_changed_at timestamp with time zone,
    last_login_at timestamp with time zone,
    pipeline_stage_id uuid,
    address character varying(255),
    district character varying(100),
    department character varying(100),
    postal_code character varying(20),
    medical_notes text,
    last_activity_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    deleted_at timestamp with time zone
);


--
-- Name: gift_card_transactions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.gift_card_transactions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    gift_card_id uuid NOT NULL,
    type character varying(30) NOT NULL,
    amount numeric(14,2) DEFAULT 0 NOT NULL,
    sale_id uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT gift_card_transactions_type_check CHECK (((type)::text = ANY ((ARRAY['issue'::character varying, 'redeem'::character varying, 'refund'::character varying, 'extend'::character varying, 'cancel'::character varying])::text[])))
);


--
-- Name: gift_cards; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.gift_cards (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    code character varying(40) NOT NULL,
    initial_amount numeric(14,2) NOT NULL,
    balance numeric(14,2) NOT NULL,
    purchaser_customer_id uuid,
    recipient_customer_id uuid,
    recipient_name character varying(150),
    recipient_email character varying(250),
    sale_id uuid,
    status character varying(30) DEFAULT 'active'::character varying NOT NULL,
    expires_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT gift_cards_balance_check CHECK ((balance >= (0)::numeric)),
    CONSTRAINT gift_cards_initial_amount_check CHECK ((initial_amount > (0)::numeric)),
    CONSTRAINT gift_cards_status_check CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'redeemed'::character varying, 'expired'::character varying, 'cancelled'::character varying])::text[])))
);


--
-- Name: holidays; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.holidays (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    branch_id uuid,
    date date NOT NULL,
    name character varying(150) NOT NULL,
    closed boolean DEFAULT true NOT NULL,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: inventory_movements; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.inventory_movements (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    warehouse_id uuid NOT NULL,
    product_id uuid NOT NULL,
    type character varying(30) NOT NULL,
    quantity numeric(14,4) NOT NULL,
    unit_cost numeric(14,2),
    balance_after numeric(14,4),
    reference_type character varying(50),
    reference_id uuid,
    reason character varying(255),
    actor_user_id uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT inventory_movements_quantity_check CHECK ((quantity <> (0)::numeric)),
    CONSTRAINT inventory_movements_type_check CHECK (((type)::text = ANY ((ARRAY['initial'::character varying, 'purchase'::character varying, 'sale'::character varying, 'service_consumption'::character varying, 'adjustment'::character varying, 'transfer_out'::character varying, 'transfer_in'::character varying, 'return'::character varying])::text[]))),
    CONSTRAINT inventory_movements_unit_cost_check CHECK ((unit_cost >= (0)::numeric))
);


--
-- Name: invoice_series; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.invoice_series (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    doc_type character varying(20) NOT NULL,
    serie character varying(4) NOT NULL,
    next_number integer DEFAULT 1 NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT invoice_series_doc_type_check CHECK (((doc_type)::text = ANY ((ARRAY['ticket'::character varying, 'boleta'::character varying, 'factura'::character varying, 'nota_credito'::character varying])::text[]))),
    CONSTRAINT invoice_series_next_number_check CHECK ((next_number > 0))
);


--
-- Name: invoices; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.invoices (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    branch_id uuid,
    sale_id uuid NOT NULL,
    customer_id uuid,
    doc_type character varying(20) NOT NULL,
    serie character varying(4) NOT NULL,
    number integer NOT NULL,
    customer_doc_type character varying(20),
    customer_doc_number character varying(30),
    customer_name character varying(255),
    customer_address character varying(255),
    taxable numeric(14,2) DEFAULT 0 NOT NULL,
    tax numeric(14,2) DEFAULT 0 NOT NULL,
    total numeric(14,2) DEFAULT 0 NOT NULL,
    status character varying(20) DEFAULT 'issued'::character varying NOT NULL,
    provider character varying(40) DEFAULT 'simulated'::character varying NOT NULL,
    simulated boolean DEFAULT true NOT NULL,
    sunat_status character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    sunat_message character varying(255),
    external_id character varying(190),
    reference_invoice_id uuid,
    reason character varying(255),
    issued_by uuid,
    issued_at timestamp with time zone DEFAULT now() NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    currency character(3) DEFAULT 'PEN'::bpchar NOT NULL,
    CONSTRAINT invoices_doc_type_check CHECK (((doc_type)::text = ANY ((ARRAY['ticket'::character varying, 'boleta'::character varying, 'factura'::character varying, 'nota_credito'::character varying])::text[]))),
    CONSTRAINT invoices_status_check CHECK (((status)::text = ANY ((ARRAY['issued'::character varying, 'voided'::character varying])::text[]))),
    CONSTRAINT invoices_sunat_status_check CHECK (((sunat_status)::text = ANY ((ARRAY['pending'::character varying, 'accepted'::character varying, 'rejected'::character varying, 'not_applicable'::character varying])::text[])))
);


--
-- Name: loyalty_transactions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.loyalty_transactions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    customer_id uuid NOT NULL,
    type character varying(30) NOT NULL,
    points integer NOT NULL,
    balance_after integer,
    sale_id uuid,
    reason character varying(255),
    expires_at timestamp with time zone,
    actor_user_id uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT loyalty_transactions_points_check CHECK ((points <> 0)),
    CONSTRAINT loyalty_transactions_type_check CHECK (((type)::text = ANY ((ARRAY['earn'::character varying, 'redeem'::character varying, 'adjust'::character varying, 'expire'::character varying, 'referral'::character varying, 'reversal'::character varying])::text[])))
);


--
-- Name: marketing_campaign_recipients; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.marketing_campaign_recipients (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    marketing_campaign_id uuid NOT NULL,
    customer_id uuid NOT NULL,
    status character varying(30) DEFAULT 'pending'::character varying NOT NULL,
    skip_reason character varying(255),
    sent_at timestamp with time zone,
    message_id uuid,
    CONSTRAINT marketing_campaign_recipients_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'sent'::character varying, 'delivered'::character varying, 'failed'::character varying, 'skipped'::character varying])::text[])))
);


--
-- Name: marketing_campaigns; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.marketing_campaigns (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    name character varying(150) NOT NULL,
    channel character varying(30) NOT NULL,
    marketing_template_id uuid,
    coupon_id uuid,
    subject character varying(200),
    body text,
    segment jsonb DEFAULT '{}'::jsonb NOT NULL,
    status character varying(30) DEFAULT 'draft'::character varying NOT NULL,
    scheduled_at timestamp with time zone,
    sent_at timestamp with time zone,
    stats jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    audience_label character varying(200),
    CONSTRAINT marketing_campaigns_channel_check CHECK (((channel)::text = ANY ((ARRAY['whatsapp'::character varying, 'email'::character varying, 'sms'::character varying])::text[]))),
    CONSTRAINT marketing_campaigns_status_check CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'scheduled'::character varying, 'sending'::character varying, 'sent'::character varying, 'cancelled'::character varying])::text[])))
);


--
-- Name: marketing_templates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.marketing_templates (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    name character varying(150) NOT NULL,
    channel character varying(30) NOT NULL,
    subject character varying(200),
    body text NOT NULL,
    variables jsonb DEFAULT '[]'::jsonb NOT NULL,
    status character varying(30) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT marketing_templates_channel_check CHECK (((channel)::text = ANY ((ARRAY['whatsapp'::character varying, 'email'::character varying, 'sms'::character varying])::text[])))
);


--
-- Name: medical_records; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.medical_records (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    customer_id uuid NOT NULL,
    appointment_id uuid,
    professional_id uuid NOT NULL,
    subjective text,
    objective text,
    assessment text,
    plan text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: membership_plans; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.membership_plans (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    name character varying(150) NOT NULL,
    description text,
    price numeric(14,2) DEFAULT 0 NOT NULL,
    billing_period character varying(20) DEFAULT 'monthly'::character varying NOT NULL,
    discount_percent numeric(5,2) DEFAULT 0 NOT NULL,
    sessions_included integer DEFAULT 0 NOT NULL,
    benefits jsonb DEFAULT '{}'::jsonb NOT NULL,
    status character varying(30) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT membership_plans_billing_period_check CHECK (((billing_period)::text = ANY ((ARRAY['monthly'::character varying, 'quarterly'::character varying, 'semiannual'::character varying, 'yearly'::character varying])::text[]))),
    CONSTRAINT membership_plans_discount_percent_check CHECK (((discount_percent >= (0)::numeric) AND (discount_percent <= (100)::numeric))),
    CONSTRAINT membership_plans_price_check CHECK ((price >= (0)::numeric)),
    CONSTRAINT membership_plans_sessions_included_check CHECK ((sessions_included >= 0))
);


--
-- Name: memberships; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.memberships (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    membership_plan_id uuid NOT NULL,
    customer_id uuid NOT NULL,
    sale_id uuid,
    status character varying(30) DEFAULT 'active'::character varying NOT NULL,
    starts_at date NOT NULL,
    ends_at date NOT NULL,
    paused_at timestamp with time zone,
    paused_days integer DEFAULT 0 NOT NULL,
    auto_renew boolean DEFAULT false NOT NULL,
    cancelled_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT memberships_check CHECK ((ends_at >= starts_at)),
    CONSTRAINT memberships_paused_days_check CHECK ((paused_days >= 0)),
    CONSTRAINT memberships_status_check CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'paused'::character varying, 'grace'::character varying, 'expired'::character varying, 'cancelled'::character varying])::text[])))
);


--
-- Name: message_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.message_settings (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    kind character varying(40) NOT NULL,
    enabled boolean DEFAULT true NOT NULL,
    subject character varying(255),
    body text,
    options jsonb DEFAULT '{}'::jsonb NOT NULL,
    updated_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: messages; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.messages (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    customer_id uuid,
    branch_id uuid,
    channel character varying(20) DEFAULT 'email'::character varying NOT NULL,
    kind character varying(40) NOT NULL,
    status character varying(20) DEFAULT 'queued'::character varying NOT NULL,
    to_address character varying(255),
    to_name character varying(255),
    subject character varying(255),
    body text NOT NULL,
    scheduled_at timestamp with time zone,
    sent_at timestamp with time zone,
    attempts integer DEFAULT 0 NOT NULL,
    error character varying(500),
    provider character varying(40),
    entity_type character varying(40),
    entity_id uuid,
    dedupe_key character varying(190),
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT messages_channel_check CHECK (((channel)::text = ANY ((ARRAY['email'::character varying, 'whatsapp'::character varying])::text[]))),
    CONSTRAINT messages_status_check CHECK (((status)::text = ANY ((ARRAY['scheduled'::character varying, 'queued'::character varying, 'sent'::character varying, 'simulated'::character varying, 'failed'::character varying, 'not_sent'::character varying, 'cancelled'::character varying])::text[])))
);


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: package_plan_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.package_plan_items (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    package_plan_id uuid NOT NULL,
    service_id uuid NOT NULL,
    sessions integer NOT NULL,
    "position" smallint DEFAULT 0 NOT NULL,
    CONSTRAINT package_plan_items_sessions_check CHECK (((sessions >= 1) AND (sessions <= 200)))
);


--
-- Name: package_plans; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.package_plans (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    name character varying(150) NOT NULL,
    description text,
    price numeric(14,2) DEFAULT 0 NOT NULL,
    currency character(3) DEFAULT 'PEN'::bpchar NOT NULL,
    validity_days integer DEFAULT 90 NOT NULL,
    booking_mode character varying(20) DEFAULT 'free'::character varying NOT NULL,
    frequency character varying(20) DEFAULT 'weekly'::character varying NOT NULL,
    custom_days jsonb DEFAULT '[]'::jsonb NOT NULL,
    repeat_weeks smallint DEFAULT 1 NOT NULL,
    professional_mode character varying(20) DEFAULT 'chosen'::character varying NOT NULL,
    late_policy character varying(20) DEFAULT 'lose'::character varying NOT NULL,
    refund_policy character varying(20) DEFAULT 'unused_normal'::character varying NOT NULL,
    terms text,
    status character varying(20) DEFAULT 'active'::character varying NOT NULL,
    deleted_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT package_plans_booking_mode_check CHECK (((booking_mode)::text = ANY ((ARRAY['free'::character varying, 'scheduled'::character varying])::text[]))),
    CONSTRAINT package_plans_frequency_check CHECK (((frequency)::text = ANY ((ARRAY['weekly'::character varying, 'twice_weekly'::character varying, 'biweekly'::character varying, 'monthly'::character varying, 'custom'::character varying])::text[]))),
    CONSTRAINT package_plans_late_policy_check CHECK (((late_policy)::text = ANY ((ARRAY['lose'::character varying, 'reschedule'::character varying])::text[]))),
    CONSTRAINT package_plans_price_check CHECK ((price >= (0)::numeric)),
    CONSTRAINT package_plans_professional_mode_check CHECK (((professional_mode)::text = ANY ((ARRAY['chosen'::character varying, 'any'::character varying])::text[]))),
    CONSTRAINT package_plans_refund_policy_check CHECK (((refund_policy)::text = ANY ((ARRAY['none'::character varying, 'unused_normal'::character varying, 'unused_package'::character varying])::text[]))),
    CONSTRAINT package_plans_repeat_weeks_check CHECK ((repeat_weeks = ANY (ARRAY[1, 2]))),
    CONSTRAINT package_plans_status_check CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'inactive'::character varying])::text[]))),
    CONSTRAINT package_plans_validity_days_check CHECK (((validity_days >= 1) AND (validity_days <= 3650)))
);


--
-- Name: package_usages; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.package_usages (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    customer_package_id uuid NOT NULL,
    service_id uuid NOT NULL,
    appointment_id uuid,
    professional_id uuid,
    sessions integer DEFAULT 1 NOT NULL,
    kind character varying(20) DEFAULT 'attended'::character varying NOT NULL,
    note character varying(255),
    created_by uuid,
    used_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT package_usages_kind_check CHECK (((kind)::text = ANY ((ARRAY['attended'::character varying, 'no_show'::character varying, 'late_cancel'::character varying, 'manual'::character varying])::text[]))),
    CONSTRAINT package_usages_sessions_check CHECK ((sessions > 0))
);


--
-- Name: payment_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.payment_events (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid,
    payment_link_id uuid,
    provider character varying(40) NOT NULL,
    event_id character varying(190) NOT NULL,
    event character varying(60) NOT NULL,
    status character varying(20) NOT NULL,
    message character varying(255),
    payload jsonb,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT payment_events_status_check CHECK (((status)::text = ANY ((ARRAY['processed'::character varying, 'duplicate'::character varying, 'ignored'::character varying, 'refunded'::character varying, 'error'::character varying])::text[])))
);


--
-- Name: payment_links; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.payment_links (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    branch_id uuid,
    token character varying(64) NOT NULL,
    purpose character varying(20) NOT NULL,
    status character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    amount numeric(14,2) NOT NULL,
    currency character varying(3) DEFAULT 'PEN'::character varying NOT NULL,
    description character varying(255) NOT NULL,
    sale_id uuid,
    customer_id uuid,
    service_id uuid,
    professional_id uuid,
    customer_dependent_id uuid,
    starts_at timestamp with time zone,
    ends_at timestamp with time zone,
    notes character varying(1000),
    appointment_id uuid,
    payment_id uuid,
    provider character varying(40) DEFAULT 'simulated'::character varying NOT NULL,
    external_id character varying(190),
    cancel_reason character varying(255),
    expires_at timestamp with time zone NOT NULL,
    paid_at timestamp with time zone,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT payment_links_amount_check CHECK ((amount > (0)::numeric)),
    CONSTRAINT payment_links_purpose_check CHECK (((purpose)::text = ANY ((ARRAY['sale'::character varying, 'deposit'::character varying])::text[]))),
    CONSTRAINT payment_links_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'paid'::character varying, 'expired'::character varying, 'cancelled'::character varying, 'failed'::character varying])::text[])))
);


--
-- Name: payment_qr_codes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.payment_qr_codes (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    method character varying(10) NOT NULL,
    holder character varying(150),
    image text NOT NULL,
    updated_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT payment_qr_codes_method_check CHECK (((method)::text = ANY ((ARRAY['yape'::character varying, 'plin'::character varying])::text[])))
);


--
-- Name: payments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.payments (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    branch_id uuid,
    sale_id uuid,
    appointment_id uuid,
    customer_id uuid,
    method character varying(30) NOT NULL,
    status character varying(20) DEFAULT 'confirmed'::character varying NOT NULL,
    amount numeric(14,2) NOT NULL,
    change_amount numeric(14,2) DEFAULT 0 NOT NULL,
    external_reference character varying(190),
    provider character varying(40),
    payment_url character varying(500),
    expires_at timestamp with time zone,
    confirmed_at timestamp with time zone,
    cash_session_id uuid,
    idempotency_key character varying(190),
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    currency character(3) DEFAULT 'PEN'::bpchar NOT NULL,
    CONSTRAINT payments_amount_check CHECK ((amount <> (0)::numeric)),
    CONSTRAINT payments_change_amount_check CHECK ((change_amount >= (0)::numeric)),
    CONSTRAINT payments_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'confirmed'::character varying, 'failed'::character varying, 'expired'::character varying, 'refunded'::character varying])::text[])))
);


--
-- Name: permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.permissions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    name character varying(100) NOT NULL,
    module character varying(50) NOT NULL,
    description character varying(255),
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: personal_access_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.personal_access_tokens (
    id bigint NOT NULL,
    tokenable_type character varying(255) NOT NULL,
    tokenable_id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    token character varying(64) NOT NULL,
    abilities text,
    last_used_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.personal_access_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.personal_access_tokens_id_seq OWNED BY public.personal_access_tokens.id;


--
-- Name: pipeline_stages; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pipeline_stages (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    pipeline_id uuid NOT NULL,
    name character varying(150) NOT NULL,
    color character varying(7) DEFAULT '#0050AA'::character varying,
    probability integer DEFAULT 0,
    stage_order integer DEFAULT 0 NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: pipelines; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pipelines (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    name character varying(150) NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: platform_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.platform_settings (
    key character varying(60) NOT NULL,
    value jsonb,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: product_brands; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.product_brands (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    name character varying(120) NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: product_categories; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.product_categories (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    name character varying(120) NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: products; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.products (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    category_id uuid,
    brand_id uuid,
    unit_id uuid,
    sku character varying(60) NOT NULL,
    barcode character varying(60),
    name character varying(200) NOT NULL,
    description text,
    kind character varying(20) DEFAULT 'retail'::character varying NOT NULL,
    cost numeric(14,2) DEFAULT 0 NOT NULL,
    price numeric(14,2) DEFAULT 0 NOT NULL,
    min_stock numeric(14,4) DEFAULT 0 NOT NULL,
    track_stock boolean DEFAULT true NOT NULL,
    status character varying(30) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    deleted_at timestamp with time zone,
    CONSTRAINT products_cost_check CHECK ((cost >= (0)::numeric)),
    CONSTRAINT products_kind_check CHECK (((kind)::text = ANY ((ARRAY['retail'::character varying, 'supply'::character varying, 'both'::character varying])::text[]))),
    CONSTRAINT products_min_stock_check CHECK ((min_stock >= (0)::numeric)),
    CONSTRAINT products_price_check CHECK ((price >= (0)::numeric))
);


--
-- Name: professional_schedules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.professional_schedules (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    professional_id uuid NOT NULL,
    branch_id uuid NOT NULL,
    weekday smallint NOT NULL,
    starts_local time without time zone NOT NULL,
    ends_local time without time zone NOT NULL,
    CONSTRAINT professional_schedules_check CHECK ((ends_local > starts_local)),
    CONSTRAINT professional_schedules_weekday_check CHECK (((weekday >= 0) AND (weekday <= 6)))
);


--
-- Name: professional_specialties; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.professional_specialties (
    tenant_id uuid NOT NULL,
    professional_id uuid NOT NULL,
    specialty_id uuid NOT NULL
);


--
-- Name: professionals; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.professionals (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    user_id uuid,
    type_document_id uuid,
    document_number character varying(30),
    display_name character varying(150) NOT NULL,
    license_number character varying(50),
    phone character varying(30),
    signature_url character varying(255),
    bio text,
    color_code character varying(7) DEFAULT '#022340'::character varying,
    status_id uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: purchase_order_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.purchase_order_items (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    purchase_order_id uuid NOT NULL,
    product_id uuid NOT NULL,
    quantity numeric(14,4) NOT NULL,
    received_quantity numeric(14,4) DEFAULT 0 NOT NULL,
    unit_cost numeric(14,2) DEFAULT 0 NOT NULL,
    total numeric(14,2) DEFAULT 0 NOT NULL,
    CONSTRAINT purchase_order_items_quantity_check CHECK ((quantity > (0)::numeric)),
    CONSTRAINT purchase_order_items_received_quantity_check CHECK ((received_quantity >= (0)::numeric)),
    CONSTRAINT purchase_order_items_total_check CHECK ((total >= (0)::numeric)),
    CONSTRAINT purchase_order_items_unit_cost_check CHECK ((unit_cost >= (0)::numeric))
);


--
-- Name: purchase_orders; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.purchase_orders (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    code character varying(50) NOT NULL,
    supplier_id uuid NOT NULL,
    warehouse_id uuid NOT NULL,
    status character varying(30) DEFAULT 'draft'::character varying NOT NULL,
    subtotal numeric(14,2) DEFAULT 0 NOT NULL,
    tax numeric(14,2) DEFAULT 0 NOT NULL,
    total numeric(14,2) DEFAULT 0 NOT NULL,
    expected_at date,
    received_at timestamp with time zone,
    notes text,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT purchase_orders_status_check CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'ordered'::character varying, 'partial'::character varying, 'received'::character varying, 'cancelled'::character varying])::text[]))),
    CONSTRAINT purchase_orders_subtotal_check CHECK ((subtotal >= (0)::numeric)),
    CONSTRAINT purchase_orders_tax_check CHECK ((tax >= (0)::numeric)),
    CONSTRAINT purchase_orders_total_check CHECK ((total >= (0)::numeric))
);


--
-- Name: quote_installments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.quote_installments (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    quote_id uuid NOT NULL,
    number integer NOT NULL,
    due_date date NOT NULL,
    amount numeric(14,2) NOT NULL,
    status character varying(30) DEFAULT 'pending'::character varying NOT NULL,
    payment_id uuid,
    paid_at timestamp with time zone,
    CONSTRAINT quote_installments_amount_check CHECK ((amount > (0)::numeric)),
    CONSTRAINT quote_installments_number_check CHECK ((number > 0)),
    CONSTRAINT quote_installments_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'paid'::character varying, 'overdue'::character varying, 'cancelled'::character varying])::text[])))
);


--
-- Name: quote_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.quote_items (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    quote_id uuid NOT NULL,
    item_type character varying(30) NOT NULL,
    reference_id uuid,
    description character varying(255) NOT NULL,
    quantity numeric(14,4) NOT NULL,
    unit_price numeric(14,2) NOT NULL,
    discount numeric(14,2) DEFAULT 0 NOT NULL,
    total numeric(14,2) NOT NULL,
    sessions_total integer DEFAULT 0 NOT NULL,
    sessions_used integer DEFAULT 0 NOT NULL,
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    CONSTRAINT quote_items_discount_check CHECK ((discount >= (0)::numeric)),
    CONSTRAINT quote_items_item_type_check CHECK (((item_type)::text = ANY ((ARRAY['service'::character varying, 'product'::character varying, 'package'::character varying, 'other'::character varying])::text[]))),
    CONSTRAINT quote_items_quantity_check CHECK ((quantity > (0)::numeric)),
    CONSTRAINT quote_items_sessions_total_check CHECK ((sessions_total >= 0)),
    CONSTRAINT quote_items_sessions_used_check CHECK ((sessions_used >= 0)),
    CONSTRAINT quote_items_total_check CHECK ((total >= (0)::numeric)),
    CONSTRAINT quote_items_unit_price_check CHECK ((unit_price >= (0)::numeric))
);


--
-- Name: quote_status_history; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.quote_status_history (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    quote_id uuid NOT NULL,
    from_status character varying(30),
    to_status character varying(30) NOT NULL,
    actor_user_id uuid,
    note character varying(255),
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: quotes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.quotes (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    branch_id uuid,
    customer_id uuid NOT NULL,
    customer_dependent_id uuid,
    professional_id uuid,
    code character varying(50) NOT NULL,
    title character varying(200),
    status character varying(30) DEFAULT 'draft'::character varying NOT NULL,
    valid_until date,
    subtotal numeric(14,2) DEFAULT 0 NOT NULL,
    discount numeric(14,2) DEFAULT 0 NOT NULL,
    tax numeric(14,2) DEFAULT 0 NOT NULL,
    total numeric(14,2) DEFAULT 0 NOT NULL,
    notes text,
    accepted_at timestamp with time zone,
    accepted_via character varying(20),
    sale_id uuid,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT quotes_accepted_via_check CHECK (((accepted_via)::text = ANY ((ARRAY['staff'::character varying, 'portal'::character varying])::text[]))),
    CONSTRAINT quotes_discount_check CHECK ((discount >= (0)::numeric)),
    CONSTRAINT quotes_status_check CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'sent'::character varying, 'accepted'::character varying, 'rejected'::character varying, 'expired'::character varying, 'cancelled'::character varying])::text[]))),
    CONSTRAINT quotes_subtotal_check CHECK ((subtotal >= (0)::numeric)),
    CONSTRAINT quotes_tax_check CHECK ((tax >= (0)::numeric)),
    CONSTRAINT quotes_total_check CHECK ((total >= (0)::numeric))
);


--
-- Name: recalls; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.recalls (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    customer_id uuid NOT NULL,
    customer_dependent_id uuid,
    care_record_id uuid,
    source_appointment_id uuid,
    service_id uuid,
    professional_id uuid,
    label character varying(150) NOT NULL,
    due_date date NOT NULL,
    status character varying(30) DEFAULT 'pending'::character varying NOT NULL,
    notified_at timestamp with time zone,
    appointment_id uuid,
    notes text,
    closed_reason character varying(255),
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT recalls_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'notified'::character varying, 'booked'::character varying, 'done'::character varying, 'cancelled'::character varying])::text[])))
);


--
-- Name: record_template_services; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.record_template_services (
    tenant_id uuid NOT NULL,
    record_template_id uuid NOT NULL,
    service_id uuid NOT NULL
);


--
-- Name: record_templates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.record_templates (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    name character varying(150) NOT NULL,
    description character varying(255),
    fields jsonb DEFAULT '[]'::jsonb NOT NULL,
    consent_text text,
    require_consent boolean DEFAULT false NOT NULL,
    require_signature boolean DEFAULT false NOT NULL,
    is_default boolean DEFAULT false NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    status character varying(30) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: role_permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.role_permissions (
    role_id uuid NOT NULL,
    permission_id uuid NOT NULL
);


--
-- Name: roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.roles (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid,
    name character varying(100) NOT NULL,
    slug character varying(100) NOT NULL,
    description character varying(255),
    is_system boolean DEFAULT false NOT NULL,
    permissions_seeded_at timestamp with time zone,
    scope character varying(20) DEFAULT 'tenant'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT roles_scope_check CHECK (((scope)::text = ANY ((ARRAY['tenant'::character varying, 'branch'::character varying, 'own'::character varying])::text[])))
);


--
-- Name: rooms; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.rooms (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    branch_id uuid NOT NULL,
    name character varying(100) NOT NULL,
    description character varying(255),
    kind character varying(20) DEFAULT 'room'::character varying NOT NULL,
    parent_id uuid,
    status character varying(30) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT rooms_kind_check CHECK (((kind)::text = ANY ((ARRAY['room'::character varying, 'equipment'::character varying])::text[])))
);


--
-- Name: saas_modules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.saas_modules (
    key character varying(50) NOT NULL,
    name character varying(150) NOT NULL,
    is_core boolean DEFAULT false NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: saas_plans; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.saas_plans (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    name character varying(100) NOT NULL,
    slug character varying(100) NOT NULL,
    code_internal character varying(50),
    description text,
    price_monthly numeric(14,2) DEFAULT 0 NOT NULL,
    price_yearly numeric(14,2) DEFAULT 0 NOT NULL,
    currency character varying(10) DEFAULT 'PEN'::character varying NOT NULL,
    trial_config jsonb DEFAULT '{}'::jsonb NOT NULL,
    subscription_config jsonb DEFAULT '{}'::jsonb NOT NULL,
    modules jsonb DEFAULT '{}'::jsonb NOT NULL,
    limits jsonb DEFAULT '{}'::jsonb NOT NULL,
    consumption jsonb DEFAULT '{}'::jsonb NOT NULL,
    support jsonb DEFAULT '{}'::jsonb NOT NULL,
    features jsonb DEFAULT '{}'::jsonb NOT NULL,
    status character varying(30) DEFAULT 'active'::character varying NOT NULL,
    presentation_order integer DEFAULT 1 NOT NULL,
    is_public_catalog boolean DEFAULT true NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT saas_plans_price_monthly_check CHECK ((price_monthly >= (0)::numeric)),
    CONSTRAINT saas_plans_price_yearly_check CHECK ((price_yearly >= (0)::numeric))
);


--
-- Name: sale_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sale_items (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    sale_id uuid NOT NULL,
    item_type character varying(30) NOT NULL,
    reference_id uuid,
    description character varying(255) NOT NULL,
    quantity numeric(14,4) NOT NULL,
    unit_price numeric(14,2) NOT NULL,
    discount numeric(14,2) DEFAULT 0 NOT NULL,
    total numeric(14,2) NOT NULL,
    professional_id uuid,
    appointment_id uuid,
    "position" integer DEFAULT 0 NOT NULL,
    details jsonb,
    CONSTRAINT sale_items_discount_check CHECK ((discount >= (0)::numeric)),
    CONSTRAINT sale_items_quantity_check CHECK ((quantity > (0)::numeric)),
    CONSTRAINT sale_items_total_check CHECK ((total >= (0)::numeric)),
    CONSTRAINT sale_items_unit_price_check CHECK ((unit_price >= (0)::numeric))
);


--
-- Name: sale_refunds; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sale_refunds (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    sale_id uuid NOT NULL,
    amount numeric(14,2) NOT NULL,
    method character varying(30) NOT NULL,
    reason character varying(255) NOT NULL,
    payment_id uuid,
    invoice_id uuid,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT sale_refunds_amount_check CHECK ((amount > (0)::numeric))
);


--
-- Name: sales; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sales (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    branch_id uuid NOT NULL,
    customer_id uuid,
    appointment_id uuid,
    code character varying(30) NOT NULL,
    status character varying(30) DEFAULT 'pending'::character varying NOT NULL,
    subtotal numeric(14,2) DEFAULT 0 NOT NULL,
    discount numeric(14,2) DEFAULT 0 NOT NULL,
    tax numeric(14,2) DEFAULT 0 NOT NULL,
    tip numeric(14,2) DEFAULT 0 NOT NULL,
    total numeric(14,2) DEFAULT 0 NOT NULL,
    paid numeric(14,2) DEFAULT 0 NOT NULL,
    balance numeric(14,2) DEFAULT 0 NOT NULL,
    tax_rate numeric(5,2) DEFAULT 18 NOT NULL,
    prices_include_tax boolean DEFAULT true NOT NULL,
    notes character varying(500),
    void_reason character varying(255),
    deposit_outcome character varying(20),
    created_by uuid,
    paid_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    currency character(3) DEFAULT 'PEN'::bpchar NOT NULL,
    converted_from character(3),
    exchange_rate numeric(18,8),
    CONSTRAINT sales_deposit_outcome_check CHECK (((deposit_outcome)::text = ANY ((ARRAY['refund_due'::character varying, 'retained'::character varying, 'refunded'::character varying])::text[]))),
    CONSTRAINT sales_discount_check CHECK ((discount >= (0)::numeric)),
    CONSTRAINT sales_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'partial'::character varying, 'paid'::character varying, 'void'::character varying, 'partially_refunded'::character varying, 'refunded'::character varying])::text[]))),
    CONSTRAINT sales_subtotal_check CHECK ((subtotal >= (0)::numeric)),
    CONSTRAINT sales_tax_check CHECK ((tax >= (0)::numeric)),
    CONSTRAINT sales_tip_check CHECK ((tip >= (0)::numeric)),
    CONSTRAINT sales_total_check CHECK ((total >= (0)::numeric))
);


--
-- Name: service_categories; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.service_categories (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    name character varying(120) NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: service_professionals; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.service_professionals (
    tenant_id uuid NOT NULL,
    service_id uuid NOT NULL,
    professional_id uuid NOT NULL
);


--
-- Name: service_supplies; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.service_supplies (
    tenant_id uuid NOT NULL,
    service_id uuid NOT NULL,
    product_id uuid NOT NULL,
    quantity numeric(14,4) NOT NULL,
    CONSTRAINT service_supplies_quantity_check CHECK ((quantity > (0)::numeric))
);


--
-- Name: services; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.services (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    category_id uuid,
    name character varying(150) NOT NULL,
    duration_minutes integer NOT NULL,
    buffer_before_minutes integer DEFAULT 0 NOT NULL,
    buffer_after_minutes integer DEFAULT 0 NOT NULL,
    price numeric(14,2) DEFAULT 0 NOT NULL,
    code character varying(30),
    requires_deposit boolean DEFAULT false NOT NULL,
    deposit_type character varying(10) DEFAULT 'percent'::character varying NOT NULL,
    deposit_value numeric(14,2) DEFAULT 30 NOT NULL,
    online_booking boolean DEFAULT true NOT NULL,
    is_virtual boolean DEFAULT false NOT NULL,
    kind character varying(20) DEFAULT 'individual'::character varying NOT NULL,
    capacity integer DEFAULT 1 NOT NULL,
    recall_label character varying(150),
    recall_days integer,
    recall_service_id uuid,
    status_id uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT services_buffer_after_minutes_check CHECK ((buffer_after_minutes >= 0)),
    CONSTRAINT services_buffer_before_minutes_check CHECK ((buffer_before_minutes >= 0)),
    CONSTRAINT services_capacity_check CHECK ((capacity >= 1)),
    CONSTRAINT services_check CHECK ((((kind)::text = 'group'::text) OR (capacity = 1))),
    CONSTRAINT services_check1 CHECK (((recall_label IS NULL) = (recall_days IS NULL))),
    CONSTRAINT services_deposit_type_check CHECK (((deposit_type)::text = ANY ((ARRAY['percent'::character varying, 'amount'::character varying])::text[]))),
    CONSTRAINT services_deposit_value_check CHECK ((deposit_value >= (0)::numeric)),
    CONSTRAINT services_duration_minutes_check CHECK ((duration_minutes > 0)),
    CONSTRAINT services_kind_check CHECK (((kind)::text = ANY ((ARRAY['individual'::character varying, 'group'::character varying])::text[]))),
    CONSTRAINT services_price_check CHECK ((price >= (0)::numeric)),
    CONSTRAINT services_recall_days_check CHECK ((recall_days > 0))
);


--
-- Name: sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id uuid,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


--
-- Name: specialties; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.specialties (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    name character varying(150) NOT NULL,
    description character varying(255),
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: status_modules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.status_modules (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    name character varying(100) NOT NULL,
    description character varying(255),
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: statuses; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.statuses (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid,
    module_id uuid NOT NULL,
    name character varying(100) NOT NULL,
    slug character varying(100) NOT NULL,
    color character varying(7) DEFAULT '#022340'::character varying,
    is_default boolean DEFAULT false NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: stock_balances; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.stock_balances (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    warehouse_id uuid NOT NULL,
    product_id uuid NOT NULL,
    quantity numeric(14,4) DEFAULT 0 NOT NULL,
    in_transit numeric(14,4) DEFAULT 0 NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT stock_balances_in_transit_check CHECK ((in_transit >= (0)::numeric))
);


--
-- Name: stock_transfer_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.stock_transfer_items (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    stock_transfer_id uuid NOT NULL,
    product_id uuid NOT NULL,
    quantity numeric(14,4) NOT NULL,
    received_quantity numeric(14,4) DEFAULT 0 NOT NULL,
    CONSTRAINT stock_transfer_items_quantity_check CHECK ((quantity > (0)::numeric)),
    CONSTRAINT stock_transfer_items_received_quantity_check CHECK ((received_quantity >= (0)::numeric))
);


--
-- Name: stock_transfers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.stock_transfers (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    code character varying(50) NOT NULL,
    from_warehouse_id uuid NOT NULL,
    to_warehouse_id uuid NOT NULL,
    status character varying(30) DEFAULT 'draft'::character varying NOT NULL,
    notes text,
    created_by uuid,
    received_by uuid,
    sent_at timestamp with time zone,
    received_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT stock_transfers_check CHECK ((from_warehouse_id <> to_warehouse_id)),
    CONSTRAINT stock_transfers_status_check CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'in_transit'::character varying, 'received'::character varying, 'cancelled'::character varying])::text[])))
);


--
-- Name: subscription_invoices; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subscription_invoices (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    subscription_id uuid NOT NULL,
    tenant_id uuid NOT NULL,
    status_id uuid NOT NULL,
    amount numeric(12,2) NOT NULL,
    currency character varying(3) DEFAULT 'PEN'::character varying NOT NULL,
    billing_reason character varying(50),
    invoice_date timestamp with time zone DEFAULT now() NOT NULL,
    due_date timestamp with time zone,
    paid_at timestamp with time zone,
    external_invoice_id character varying(255),
    period_start timestamp with time zone,
    period_end timestamp with time zone,
    notes character varying(255),
    hosted_invoice_url text,
    pdf_url text,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now()
);


--
-- Name: subscriptions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subscriptions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    plan_id uuid NOT NULL,
    status character varying(30) DEFAULT 'trialing'::character varying NOT NULL,
    billing_cycle character varying(20) DEFAULT 'monthly'::character varying NOT NULL,
    starts_at timestamp with time zone NOT NULL,
    ends_at timestamp with time zone,
    trial_ends_at timestamp with time zone,
    canceled_at timestamp with time zone,
    external_provider character varying(50),
    external_subscription_id character varying(190),
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: suppliers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.suppliers (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    type_document_id uuid,
    document_number character varying(30),
    name character varying(200) NOT NULL,
    contact_name character varying(150),
    email character varying(250),
    phone character varying(50),
    address character varying(255),
    status character varying(30) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: tenant_contacts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tenant_contacts (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    user_id uuid,
    first_name character varying(250) NOT NULL,
    last_name character varying(250) NOT NULL,
    email character varying(250) NOT NULL,
    phone character varying(50),
    "position" character varying(150),
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: tenant_integrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tenant_integrations (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    kind character varying(30) NOT NULL,
    provider character varying(40) DEFAULT 'simulated'::character varying NOT NULL,
    mode character varying(20) DEFAULT 'simulated'::character varying NOT NULL,
    credentials text,
    settings jsonb DEFAULT '{}'::jsonb NOT NULL,
    status character varying(20) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT tenant_integrations_kind_check CHECK (((kind)::text = ANY ((ARRAY['payments'::character varying, 'invoicing'::character varying, 'messaging'::character varying, 'email'::character varying, 'whatsapp'::character varying])::text[]))),
    CONSTRAINT tenant_integrations_mode_check CHECK (((mode)::text = ANY ((ARRAY['simulated'::character varying, 'sandbox'::character varying, 'production'::character varying])::text[])))
);


--
-- Name: tenant_sequences; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tenant_sequences (
    tenant_id uuid NOT NULL,
    key character varying(60) NOT NULL,
    next_value bigint DEFAULT 1 NOT NULL
);


--
-- Name: tenant_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tenant_settings (
    tenant_id uuid NOT NULL,
    vocabulary jsonb DEFAULT '{}'::jsonb NOT NULL,
    modules_off jsonb DEFAULT '[]'::jsonb NOT NULL,
    rules jsonb DEFAULT '{}'::jsonb NOT NULL,
    brand_color character varying(7),
    logo_url character varying(255),
    allow_customer_signup boolean DEFAULT true NOT NULL,
    appointment_statuses jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: tenants; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tenants (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    name character varying(150) NOT NULL,
    slug character varying(100) NOT NULL,
    code character(8) NOT NULL,
    business_type_id uuid,
    timezone character varying(64) DEFAULT 'America/Lima'::character varying NOT NULL,
    status character varying(30) DEFAULT 'active'::character varying NOT NULL,
    suspension_reason character varying(255),
    modules_off jsonb DEFAULT '[]'::jsonb NOT NULL,
    business_profile jsonb,
    business_profile_synced_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    deleted_at timestamp with time zone,
    CONSTRAINT tenants_code_check CHECK ((code ~ '^[0-9]{8}$'::text))
);


--
-- Name: type_documents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.type_documents (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    name character varying(250) NOT NULL,
    length integer,
    minimum integer,
    value character varying(5),
    status smallint NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: units; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.units (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid,
    name character varying(80) NOT NULL,
    abbreviation character varying(15) NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: user_notifications; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_notifications (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    user_id uuid NOT NULL,
    type character varying(60) NOT NULL,
    title character varying(190) NOT NULL,
    body character varying(500),
    link character varying(255),
    entity_type character varying(50),
    entity_id uuid,
    dedupe_key character varying(190),
    read_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: user_permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_permissions (
    tenant_id uuid NOT NULL,
    user_id uuid NOT NULL,
    permission_id uuid NOT NULL,
    granted boolean NOT NULL,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: user_roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_roles (
    tenant_id uuid,
    user_id uuid NOT NULL,
    role_id uuid NOT NULL
);


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid,
    first_name character varying(100) NOT NULL,
    last_name character varying(100) NOT NULL,
    email character varying(190) NOT NULL,
    password_hash character varying(255) NOT NULL,
    phone character varying(30),
    avatar_url character varying(255),
    is_owner boolean DEFAULT false NOT NULL,
    status character varying(30) DEFAULT 'active'::character varying NOT NULL,
    email_verified_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    branch_id uuid
);


--
-- Name: waitlist_entries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.waitlist_entries (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    branch_id uuid NOT NULL,
    customer_id uuid NOT NULL,
    customer_dependent_id uuid,
    service_id uuid NOT NULL,
    professional_id uuid,
    date_from date NOT NULL,
    date_to date,
    time_from time without time zone,
    time_to time without time zone,
    notes text,
    status character varying(30) DEFAULT 'waiting'::character varying NOT NULL,
    priority integer DEFAULT 0 NOT NULL,
    notified_at timestamp with time zone,
    expires_at timestamp with time zone,
    appointment_id uuid,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT waitlist_entries_check CHECK (((date_to IS NULL) OR (date_to >= date_from))),
    CONSTRAINT waitlist_entries_check1 CHECK (((time_to IS NULL) OR (time_from IS NULL) OR (time_to > time_from))),
    CONSTRAINT waitlist_entries_status_check CHECK (((status)::text = ANY ((ARRAY['waiting'::character varying, 'notified'::character varying, 'booked'::character varying, 'expired'::character varying, 'cancelled'::character varying])::text[])))
);


--
-- Name: warehouses; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.warehouses (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    branch_id uuid,
    name character varying(150) NOT NULL,
    is_default boolean DEFAULT false NOT NULL,
    status character varying(30) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: personal_access_tokens id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens ALTER COLUMN id SET DEFAULT nextval('public.personal_access_tokens_id_seq'::regclass);


--
-- Name: appointment_status_history appointment_status_history_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.appointment_status_history
    ADD CONSTRAINT appointment_status_history_pkey PRIMARY KEY (id);


--
-- Name: appointments appointments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.appointments
    ADD CONSTRAINT appointments_pkey PRIMARY KEY (id);


--
-- Name: appointments appointments_tenant_id_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.appointments
    ADD CONSTRAINT appointments_tenant_id_code_key UNIQUE (tenant_id, code);


--
-- Name: audit_events audit_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_events
    ADD CONSTRAINT audit_events_pkey PRIMARY KEY (id);


--
-- Name: automation_logs automation_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.automation_logs
    ADD CONSTRAINT automation_logs_pkey PRIMARY KEY (id);


--
-- Name: automation_rules automation_rules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.automation_rules
    ADD CONSTRAINT automation_rules_pkey PRIMARY KEY (id);


--
-- Name: availability_blocks availability_blocks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.availability_blocks
    ADD CONSTRAINT availability_blocks_pkey PRIMARY KEY (id);


--
-- Name: branch_hours branch_hours_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.branch_hours
    ADD CONSTRAINT branch_hours_pkey PRIMARY KEY (id);


--
-- Name: branches branches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.branches
    ADD CONSTRAINT branches_pkey PRIMARY KEY (id);


--
-- Name: branches branches_tenant_id_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.branches
    ADD CONSTRAINT branches_tenant_id_name_key UNIQUE (tenant_id, name);


--
-- Name: business_types business_types_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.business_types
    ADD CONSTRAINT business_types_pkey PRIMARY KEY (id);


--
-- Name: business_types business_types_slug_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.business_types
    ADD CONSTRAINT business_types_slug_key UNIQUE (slug);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: care_record_addenda care_record_addenda_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.care_record_addenda
    ADD CONSTRAINT care_record_addenda_pkey PRIMARY KEY (id);


--
-- Name: care_records care_records_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.care_records
    ADD CONSTRAINT care_records_pkey PRIMARY KEY (id);


--
-- Name: cash_movements cash_movements_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cash_movements
    ADD CONSTRAINT cash_movements_pkey PRIMARY KEY (id);


--
-- Name: cash_registers cash_registers_branch_id_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cash_registers
    ADD CONSTRAINT cash_registers_branch_id_name_key UNIQUE (branch_id, name);


--
-- Name: cash_registers cash_registers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cash_registers
    ADD CONSTRAINT cash_registers_pkey PRIMARY KEY (id);


--
-- Name: cash_sessions cash_sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cash_sessions
    ADD CONSTRAINT cash_sessions_pkey PRIMARY KEY (id);


--
-- Name: companies companies_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.companies
    ADD CONSTRAINT companies_pkey PRIMARY KEY (id);


--
-- Name: companies companies_tenant_id_trade_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.companies
    ADD CONSTRAINT companies_tenant_id_trade_name_key UNIQUE (tenant_id, trade_name);


--
-- Name: coupon_redemptions coupon_redemptions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.coupon_redemptions
    ADD CONSTRAINT coupon_redemptions_pkey PRIMARY KEY (id);


--
-- Name: coupons coupons_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.coupons
    ADD CONSTRAINT coupons_pkey PRIMARY KEY (id);


--
-- Name: coupons coupons_tenant_id_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.coupons
    ADD CONSTRAINT coupons_tenant_id_code_key UNIQUE (tenant_id, code);


--
-- Name: currencies currencies_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.currencies
    ADD CONSTRAINT currencies_pkey PRIMARY KEY (code);


--
-- Name: currency_changes currency_changes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.currency_changes
    ADD CONSTRAINT currency_changes_pkey PRIMARY KEY (id);


--
-- Name: customer_consents customer_consents_customer_id_channel_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_consents
    ADD CONSTRAINT customer_consents_customer_id_channel_key UNIQUE (customer_id, channel);


--
-- Name: customer_consents customer_consents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_consents
    ADD CONSTRAINT customer_consents_pkey PRIMARY KEY (id);


--
-- Name: customer_dependents customer_dependents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_dependents
    ADD CONSTRAINT customer_dependents_pkey PRIMARY KEY (id);


--
-- Name: customer_messages customer_messages_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_messages
    ADD CONSTRAINT customer_messages_pkey PRIMARY KEY (id);


--
-- Name: customer_package_events customer_package_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_package_events
    ADD CONSTRAINT customer_package_events_pkey PRIMARY KEY (id);


--
-- Name: customer_package_items customer_package_items_customer_package_id_service_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_package_items
    ADD CONSTRAINT customer_package_items_customer_package_id_service_id_key UNIQUE (customer_package_id, service_id);


--
-- Name: customer_package_items customer_package_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_package_items
    ADD CONSTRAINT customer_package_items_pkey PRIMARY KEY (id);


--
-- Name: customer_packages customer_packages_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_packages
    ADD CONSTRAINT customer_packages_pkey PRIMARY KEY (id);


--
-- Name: customer_packages customer_packages_tenant_id_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_packages
    ADD CONSTRAINT customer_packages_tenant_id_code_key UNIQUE (tenant_id, code);


--
-- Name: customer_tag_assignments customer_tag_assignments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_tag_assignments
    ADD CONSTRAINT customer_tag_assignments_pkey PRIMARY KEY (customer_id, customer_tag_id);


--
-- Name: customer_tags customer_tags_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_tags
    ADD CONSTRAINT customer_tags_pkey PRIMARY KEY (id);


--
-- Name: customer_tags customer_tags_tenant_id_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_tags
    ADD CONSTRAINT customer_tags_tenant_id_name_key UNIQUE (tenant_id, name);


--
-- Name: customers customers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customers
    ADD CONSTRAINT customers_pkey PRIMARY KEY (id);


--
-- Name: gift_card_transactions gift_card_transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gift_card_transactions
    ADD CONSTRAINT gift_card_transactions_pkey PRIMARY KEY (id);


--
-- Name: gift_cards gift_cards_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gift_cards
    ADD CONSTRAINT gift_cards_pkey PRIMARY KEY (id);


--
-- Name: gift_cards gift_cards_tenant_id_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gift_cards
    ADD CONSTRAINT gift_cards_tenant_id_code_key UNIQUE (tenant_id, code);


--
-- Name: holidays holidays_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.holidays
    ADD CONSTRAINT holidays_pkey PRIMARY KEY (id);


--
-- Name: inventory_movements inventory_movements_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_movements
    ADD CONSTRAINT inventory_movements_pkey PRIMARY KEY (id);


--
-- Name: invoice_series invoice_series_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoice_series
    ADD CONSTRAINT invoice_series_pkey PRIMARY KEY (id);


--
-- Name: invoice_series invoice_series_tenant_id_doc_type_serie_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoice_series
    ADD CONSTRAINT invoice_series_tenant_id_doc_type_serie_key UNIQUE (tenant_id, doc_type, serie);


--
-- Name: invoices invoices_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_pkey PRIMARY KEY (id);


--
-- Name: invoices invoices_tenant_id_doc_type_serie_number_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_tenant_id_doc_type_serie_number_key UNIQUE (tenant_id, doc_type, serie, number);


--
-- Name: loyalty_transactions loyalty_transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loyalty_transactions
    ADD CONSTRAINT loyalty_transactions_pkey PRIMARY KEY (id);


--
-- Name: marketing_campaign_recipients marketing_campaign_recipients_marketing_campaign_id_custome_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.marketing_campaign_recipients
    ADD CONSTRAINT marketing_campaign_recipients_marketing_campaign_id_custome_key UNIQUE (marketing_campaign_id, customer_id);


--
-- Name: marketing_campaign_recipients marketing_campaign_recipients_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.marketing_campaign_recipients
    ADD CONSTRAINT marketing_campaign_recipients_pkey PRIMARY KEY (id);


--
-- Name: marketing_campaigns marketing_campaigns_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.marketing_campaigns
    ADD CONSTRAINT marketing_campaigns_pkey PRIMARY KEY (id);


--
-- Name: marketing_templates marketing_templates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.marketing_templates
    ADD CONSTRAINT marketing_templates_pkey PRIMARY KEY (id);


--
-- Name: marketing_templates marketing_templates_tenant_id_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.marketing_templates
    ADD CONSTRAINT marketing_templates_tenant_id_name_key UNIQUE (tenant_id, name);


--
-- Name: medical_records medical_records_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.medical_records
    ADD CONSTRAINT medical_records_pkey PRIMARY KEY (id);


--
-- Name: membership_plans membership_plans_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.membership_plans
    ADD CONSTRAINT membership_plans_pkey PRIMARY KEY (id);


--
-- Name: membership_plans membership_plans_tenant_id_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.membership_plans
    ADD CONSTRAINT membership_plans_tenant_id_name_key UNIQUE (tenant_id, name);


--
-- Name: memberships memberships_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.memberships
    ADD CONSTRAINT memberships_pkey PRIMARY KEY (id);


--
-- Name: message_settings message_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.message_settings
    ADD CONSTRAINT message_settings_pkey PRIMARY KEY (id);


--
-- Name: message_settings message_settings_tenant_id_kind_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.message_settings
    ADD CONSTRAINT message_settings_tenant_id_kind_key UNIQUE (tenant_id, kind);


--
-- Name: messages messages_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.messages
    ADD CONSTRAINT messages_pkey PRIMARY KEY (id);


--
-- Name: messages messages_tenant_id_dedupe_key_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.messages
    ADD CONSTRAINT messages_tenant_id_dedupe_key_key UNIQUE (tenant_id, dedupe_key);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: package_plan_items package_plan_items_package_plan_id_service_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.package_plan_items
    ADD CONSTRAINT package_plan_items_package_plan_id_service_id_key UNIQUE (package_plan_id, service_id);


--
-- Name: package_plan_items package_plan_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.package_plan_items
    ADD CONSTRAINT package_plan_items_pkey PRIMARY KEY (id);


--
-- Name: package_plans package_plans_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.package_plans
    ADD CONSTRAINT package_plans_pkey PRIMARY KEY (id);


--
-- Name: package_usages package_usages_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.package_usages
    ADD CONSTRAINT package_usages_pkey PRIMARY KEY (id);


--
-- Name: payment_events payment_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_events
    ADD CONSTRAINT payment_events_pkey PRIMARY KEY (id);


--
-- Name: payment_events payment_events_provider_event_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_events
    ADD CONSTRAINT payment_events_provider_event_id_key UNIQUE (provider, event_id);


--
-- Name: payment_links payment_links_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_links
    ADD CONSTRAINT payment_links_pkey PRIMARY KEY (id);


--
-- Name: payment_links payment_links_token_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_links
    ADD CONSTRAINT payment_links_token_key UNIQUE (token);


--
-- Name: payment_qr_codes payment_qr_codes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_qr_codes
    ADD CONSTRAINT payment_qr_codes_pkey PRIMARY KEY (id);


--
-- Name: payment_qr_codes payment_qr_codes_tenant_id_method_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_qr_codes
    ADD CONSTRAINT payment_qr_codes_tenant_id_method_key UNIQUE (tenant_id, method);


--
-- Name: payments payments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payments
    ADD CONSTRAINT payments_pkey PRIMARY KEY (id);


--
-- Name: payments payments_tenant_id_idempotency_key_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payments
    ADD CONSTRAINT payments_tenant_id_idempotency_key_key UNIQUE (tenant_id, idempotency_key);


--
-- Name: permissions permissions_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permissions
    ADD CONSTRAINT permissions_name_key UNIQUE (name);


--
-- Name: permissions permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permissions
    ADD CONSTRAINT permissions_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_token_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_token_key UNIQUE (token);


--
-- Name: pipeline_stages pipeline_stages_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pipeline_stages
    ADD CONSTRAINT pipeline_stages_pkey PRIMARY KEY (id);


--
-- Name: pipelines pipelines_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pipelines
    ADD CONSTRAINT pipelines_pkey PRIMARY KEY (id);


--
-- Name: platform_settings platform_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.platform_settings
    ADD CONSTRAINT platform_settings_pkey PRIMARY KEY (key);


--
-- Name: product_brands product_brands_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_brands
    ADD CONSTRAINT product_brands_pkey PRIMARY KEY (id);


--
-- Name: product_brands product_brands_tenant_id_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_brands
    ADD CONSTRAINT product_brands_tenant_id_name_key UNIQUE (tenant_id, name);


--
-- Name: product_categories product_categories_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_categories
    ADD CONSTRAINT product_categories_pkey PRIMARY KEY (id);


--
-- Name: product_categories product_categories_tenant_id_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_categories
    ADD CONSTRAINT product_categories_tenant_id_name_key UNIQUE (tenant_id, name);


--
-- Name: products products_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_pkey PRIMARY KEY (id);


--
-- Name: products products_tenant_id_sku_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_tenant_id_sku_key UNIQUE (tenant_id, sku);


--
-- Name: professional_schedules professional_schedules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.professional_schedules
    ADD CONSTRAINT professional_schedules_pkey PRIMARY KEY (id);


--
-- Name: professional_specialties professional_specialties_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.professional_specialties
    ADD CONSTRAINT professional_specialties_pkey PRIMARY KEY (professional_id, specialty_id);


--
-- Name: professionals professionals_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.professionals
    ADD CONSTRAINT professionals_pkey PRIMARY KEY (id);


--
-- Name: purchase_order_items purchase_order_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_order_items
    ADD CONSTRAINT purchase_order_items_pkey PRIMARY KEY (id);


--
-- Name: purchase_orders purchase_orders_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_pkey PRIMARY KEY (id);


--
-- Name: purchase_orders purchase_orders_tenant_id_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_tenant_id_code_key UNIQUE (tenant_id, code);


--
-- Name: quote_installments quote_installments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quote_installments
    ADD CONSTRAINT quote_installments_pkey PRIMARY KEY (id);


--
-- Name: quote_installments quote_installments_quote_id_number_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quote_installments
    ADD CONSTRAINT quote_installments_quote_id_number_key UNIQUE (quote_id, number);


--
-- Name: quote_items quote_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quote_items
    ADD CONSTRAINT quote_items_pkey PRIMARY KEY (id);


--
-- Name: quote_status_history quote_status_history_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quote_status_history
    ADD CONSTRAINT quote_status_history_pkey PRIMARY KEY (id);


--
-- Name: quotes quotes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quotes
    ADD CONSTRAINT quotes_pkey PRIMARY KEY (id);


--
-- Name: quotes quotes_tenant_id_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quotes
    ADD CONSTRAINT quotes_tenant_id_code_key UNIQUE (tenant_id, code);


--
-- Name: recalls recalls_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recalls
    ADD CONSTRAINT recalls_pkey PRIMARY KEY (id);


--
-- Name: record_template_services record_template_services_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.record_template_services
    ADD CONSTRAINT record_template_services_pkey PRIMARY KEY (record_template_id, service_id);


--
-- Name: record_templates record_templates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.record_templates
    ADD CONSTRAINT record_templates_pkey PRIMARY KEY (id);


--
-- Name: record_templates record_templates_tenant_id_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.record_templates
    ADD CONSTRAINT record_templates_tenant_id_name_key UNIQUE (tenant_id, name);


--
-- Name: role_permissions role_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_permissions
    ADD CONSTRAINT role_permissions_pkey PRIMARY KEY (role_id, permission_id);


--
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (id);


--
-- Name: roles roles_tenant_id_slug_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_tenant_id_slug_key UNIQUE (tenant_id, slug);


--
-- Name: rooms rooms_branch_id_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rooms
    ADD CONSTRAINT rooms_branch_id_name_key UNIQUE (branch_id, name);


--
-- Name: rooms rooms_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rooms
    ADD CONSTRAINT rooms_pkey PRIMARY KEY (id);


--
-- Name: saas_modules saas_modules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.saas_modules
    ADD CONSTRAINT saas_modules_pkey PRIMARY KEY (key);


--
-- Name: saas_plans saas_plans_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.saas_plans
    ADD CONSTRAINT saas_plans_pkey PRIMARY KEY (id);


--
-- Name: saas_plans saas_plans_slug_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.saas_plans
    ADD CONSTRAINT saas_plans_slug_key UNIQUE (slug);


--
-- Name: sale_items sale_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sale_items
    ADD CONSTRAINT sale_items_pkey PRIMARY KEY (id);


--
-- Name: sale_refunds sale_refunds_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sale_refunds
    ADD CONSTRAINT sale_refunds_pkey PRIMARY KEY (id);


--
-- Name: sales sales_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales
    ADD CONSTRAINT sales_pkey PRIMARY KEY (id);


--
-- Name: sales sales_tenant_id_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales
    ADD CONSTRAINT sales_tenant_id_code_key UNIQUE (tenant_id, code);


--
-- Name: service_categories service_categories_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.service_categories
    ADD CONSTRAINT service_categories_pkey PRIMARY KEY (id);


--
-- Name: service_categories service_categories_tenant_id_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.service_categories
    ADD CONSTRAINT service_categories_tenant_id_name_key UNIQUE (tenant_id, name);


--
-- Name: service_professionals service_professionals_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.service_professionals
    ADD CONSTRAINT service_professionals_pkey PRIMARY KEY (service_id, professional_id);


--
-- Name: service_supplies service_supplies_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.service_supplies
    ADD CONSTRAINT service_supplies_pkey PRIMARY KEY (service_id, product_id);


--
-- Name: services services_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.services
    ADD CONSTRAINT services_pkey PRIMARY KEY (id);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: specialties specialties_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.specialties
    ADD CONSTRAINT specialties_pkey PRIMARY KEY (id);


--
-- Name: specialties specialties_tenant_id_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.specialties
    ADD CONSTRAINT specialties_tenant_id_name_key UNIQUE (tenant_id, name);


--
-- Name: status_modules status_modules_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.status_modules
    ADD CONSTRAINT status_modules_name_key UNIQUE (name);


--
-- Name: status_modules status_modules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.status_modules
    ADD CONSTRAINT status_modules_pkey PRIMARY KEY (id);


--
-- Name: statuses statuses_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.statuses
    ADD CONSTRAINT statuses_pkey PRIMARY KEY (id);


--
-- Name: statuses statuses_tenant_id_module_id_slug_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.statuses
    ADD CONSTRAINT statuses_tenant_id_module_id_slug_key UNIQUE (tenant_id, module_id, slug);


--
-- Name: stock_balances stock_balances_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_balances
    ADD CONSTRAINT stock_balances_pkey PRIMARY KEY (id);


--
-- Name: stock_balances stock_balances_warehouse_id_product_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_balances
    ADD CONSTRAINT stock_balances_warehouse_id_product_id_key UNIQUE (warehouse_id, product_id);


--
-- Name: stock_transfer_items stock_transfer_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_transfer_items
    ADD CONSTRAINT stock_transfer_items_pkey PRIMARY KEY (id);


--
-- Name: stock_transfers stock_transfers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_transfers
    ADD CONSTRAINT stock_transfers_pkey PRIMARY KEY (id);


--
-- Name: stock_transfers stock_transfers_tenant_id_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_transfers
    ADD CONSTRAINT stock_transfers_tenant_id_code_key UNIQUE (tenant_id, code);


--
-- Name: subscription_invoices subscription_invoices_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_invoices
    ADD CONSTRAINT subscription_invoices_pkey PRIMARY KEY (id);


--
-- Name: subscriptions subscriptions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscriptions
    ADD CONSTRAINT subscriptions_pkey PRIMARY KEY (id);


--
-- Name: suppliers suppliers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.suppliers
    ADD CONSTRAINT suppliers_pkey PRIMARY KEY (id);


--
-- Name: tenant_contacts tenant_contacts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_contacts
    ADD CONSTRAINT tenant_contacts_pkey PRIMARY KEY (id);


--
-- Name: tenant_integrations tenant_integrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_integrations
    ADD CONSTRAINT tenant_integrations_pkey PRIMARY KEY (id);


--
-- Name: tenant_integrations tenant_integrations_tenant_id_kind_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_integrations
    ADD CONSTRAINT tenant_integrations_tenant_id_kind_key UNIQUE (tenant_id, kind);


--
-- Name: tenant_sequences tenant_sequences_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_sequences
    ADD CONSTRAINT tenant_sequences_pkey PRIMARY KEY (tenant_id, key);


--
-- Name: tenant_settings tenant_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_settings
    ADD CONSTRAINT tenant_settings_pkey PRIMARY KEY (tenant_id);


--
-- Name: tenants tenants_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenants
    ADD CONSTRAINT tenants_code_key UNIQUE (code);


--
-- Name: tenants tenants_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenants
    ADD CONSTRAINT tenants_pkey PRIMARY KEY (id);


--
-- Name: tenants tenants_slug_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenants
    ADD CONSTRAINT tenants_slug_key UNIQUE (slug);


--
-- Name: type_documents type_documents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.type_documents
    ADD CONSTRAINT type_documents_pkey PRIMARY KEY (id);


--
-- Name: units units_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.units
    ADD CONSTRAINT units_pkey PRIMARY KEY (id);


--
-- Name: user_notifications user_notifications_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_notifications
    ADD CONSTRAINT user_notifications_pkey PRIMARY KEY (id);


--
-- Name: user_notifications user_notifications_user_id_dedupe_key_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_notifications
    ADD CONSTRAINT user_notifications_user_id_dedupe_key_key UNIQUE (user_id, dedupe_key);


--
-- Name: user_permissions user_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_permissions
    ADD CONSTRAINT user_permissions_pkey PRIMARY KEY (user_id, permission_id);


--
-- Name: user_roles user_roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_roles
    ADD CONSTRAINT user_roles_pkey PRIMARY KEY (user_id, role_id);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: waitlist_entries waitlist_entries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.waitlist_entries
    ADD CONSTRAINT waitlist_entries_pkey PRIMARY KEY (id);


--
-- Name: warehouses warehouses_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.warehouses
    ADD CONSTRAINT warehouses_pkey PRIMARY KEY (id);


--
-- Name: warehouses warehouses_tenant_id_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.warehouses
    ADD CONSTRAINT warehouses_tenant_id_name_key UNIQUE (tenant_id, name);


--
-- Name: idx_appointments_currency; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_appointments_currency ON public.appointments USING btree (tenant_id, currency);


--
-- Name: idx_appointments_package; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_appointments_package ON public.appointments USING btree (customer_package_id) WHERE (customer_package_id IS NOT NULL);


--
-- Name: idx_appt_customer; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_appt_customer ON public.appointments USING btree (tenant_id, customer_id, starts_at);


--
-- Name: idx_appt_history; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_appt_history ON public.appointment_status_history USING btree (appointment_id, created_at);


--
-- Name: idx_appt_prof_time; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_appt_prof_time ON public.appointments USING btree (tenant_id, professional_id, starts_at, ends_at);


--
-- Name: idx_appt_tenant_branch_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_appt_tenant_branch_date ON public.appointments USING btree (tenant_id, branch_id, starts_at);


--
-- Name: idx_appt_tenant_start; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_appt_tenant_start ON public.appointments USING btree (tenant_id, starts_at);


--
-- Name: idx_audit_tenant_time; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_audit_tenant_time ON public.audit_events USING btree (tenant_id, created_at);


--
-- Name: idx_automation_logs_rule; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_automation_logs_rule ON public.automation_logs USING btree (tenant_id, automation_rule_id, created_at);


--
-- Name: idx_automation_rules_tenant_event; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_automation_rules_tenant_event ON public.automation_rules USING btree (tenant_id, trigger_event);


--
-- Name: idx_blocks_tenant_time; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_blocks_tenant_time ON public.availability_blocks USING btree (tenant_id, starts_at, ends_at);


--
-- Name: idx_branch_hours_branch; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_branch_hours_branch ON public.branch_hours USING btree (branch_id, weekday);


--
-- Name: idx_branches_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_branches_tenant ON public.branches USING btree (tenant_id);


--
-- Name: idx_campaign_recipients_campaign; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_campaign_recipients_campaign ON public.marketing_campaign_recipients USING btree (marketing_campaign_id, status);


--
-- Name: idx_care_record_addenda_record; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_care_record_addenda_record ON public.care_record_addenda USING btree (tenant_id, care_record_id);


--
-- Name: idx_care_records_appointment; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_care_records_appointment ON public.care_records USING btree (appointment_id) WHERE (appointment_id IS NOT NULL);


--
-- Name: idx_care_records_customer; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_care_records_customer ON public.care_records USING btree (tenant_id, customer_id, created_at);


--
-- Name: idx_cash_movements_session; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cash_movements_session ON public.cash_movements USING btree (tenant_id, cash_session_id);


--
-- Name: idx_cash_sessions_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cash_sessions_tenant ON public.cash_sessions USING btree (tenant_id, opened_at);


--
-- Name: idx_companies_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_companies_tenant ON public.companies USING btree (tenant_id);


--
-- Name: idx_coupon_redemptions_coupon; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_coupon_redemptions_coupon ON public.coupon_redemptions USING btree (tenant_id, coupon_id);


--
-- Name: idx_currency_changes_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_currency_changes_tenant ON public.currency_changes USING btree (tenant_id, created_at DESC);


--
-- Name: idx_customer_dependents_customer; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_customer_dependents_customer ON public.customer_dependents USING btree (tenant_id, customer_id);


--
-- Name: idx_customer_messages_customer; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_customer_messages_customer ON public.customer_messages USING btree (tenant_id, customer_id, created_at);


--
-- Name: idx_customer_package_events; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_customer_package_events ON public.customer_package_events USING btree (customer_package_id, created_at);


--
-- Name: idx_customer_packages_customer; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_customer_packages_customer ON public.customer_packages USING btree (tenant_id, customer_id);


--
-- Name: idx_customer_packages_sale; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_customer_packages_sale ON public.customer_packages USING btree (sale_id);


--
-- Name: idx_customer_packages_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_customer_packages_status ON public.customer_packages USING btree (tenant_id, status, expires_at);


--
-- Name: idx_customers_login; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_customers_login ON public.customers USING btree (lower((email)::text)) WHERE (password_hash IS NOT NULL);


--
-- Name: idx_customers_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_customers_status ON public.customers USING btree (status_id);


--
-- Name: idx_customers_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_customers_tenant ON public.customers USING btree (tenant_id);


--
-- Name: idx_customers_tenant_document; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_customers_tenant_document ON public.customers USING btree (tenant_id, document_number);


--
-- Name: idx_customers_tenant_email; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_customers_tenant_email ON public.customers USING btree (tenant_id, email);


--
-- Name: idx_customers_tenant_phone; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_customers_tenant_phone ON public.customers USING btree (tenant_id, phone);


--
-- Name: idx_gift_card_transactions_card; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_gift_card_transactions_card ON public.gift_card_transactions USING btree (tenant_id, gift_card_id);


--
-- Name: idx_holidays_tenant_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_holidays_tenant_date ON public.holidays USING btree (tenant_id, date);


--
-- Name: idx_holidays_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_holidays_unique ON public.holidays USING btree (tenant_id, date, COALESCE(branch_id, '00000000-0000-0000-0000-000000000000'::uuid));


--
-- Name: idx_inventory_movements_product; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_inventory_movements_product ON public.inventory_movements USING btree (tenant_id, product_id, created_at);


--
-- Name: idx_inventory_movements_warehouse; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_inventory_movements_warehouse ON public.inventory_movements USING btree (tenant_id, warehouse_id, created_at);


--
-- Name: idx_invoices_sale; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_invoices_sale ON public.invoices USING btree (tenant_id, sale_id);


--
-- Name: idx_loyalty_transactions_customer; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_loyalty_transactions_customer ON public.loyalty_transactions USING btree (tenant_id, customer_id, created_at);


--
-- Name: idx_marketing_campaigns_tenant_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_marketing_campaigns_tenant_status ON public.marketing_campaigns USING btree (tenant_id, status);


--
-- Name: idx_medical_records_customer; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_medical_records_customer ON public.medical_records USING btree (tenant_id, customer_id);


--
-- Name: idx_memberships_customer; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_memberships_customer ON public.memberships USING btree (tenant_id, customer_id);


--
-- Name: idx_memberships_status_end; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_memberships_status_end ON public.memberships USING btree (tenant_id, status, ends_at);


--
-- Name: idx_messages_customer; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_messages_customer ON public.messages USING btree (tenant_id, customer_id);


--
-- Name: idx_messages_due; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_messages_due ON public.messages USING btree (status, scheduled_at) WHERE ((status)::text = ANY ((ARRAY['scheduled'::character varying, 'queued'::character varying])::text[]));


--
-- Name: idx_messages_tenant_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_messages_tenant_created ON public.messages USING btree (tenant_id, created_at DESC);


--
-- Name: idx_package_usages_package; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_package_usages_package ON public.package_usages USING btree (tenant_id, customer_package_id);


--
-- Name: idx_payment_events_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_payment_events_tenant ON public.payment_events USING btree (tenant_id, created_at);


--
-- Name: idx_payment_links_sale; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_payment_links_sale ON public.payment_links USING btree (sale_id);


--
-- Name: idx_payment_links_slot; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_payment_links_slot ON public.payment_links USING btree (professional_id, starts_at) WHERE (((purpose)::text = 'deposit'::text) AND ((status)::text = 'pending'::text));


--
-- Name: idx_payment_links_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_payment_links_tenant ON public.payment_links USING btree (tenant_id, status, created_at);


--
-- Name: idx_payments_sale; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_payments_sale ON public.payments USING btree (tenant_id, sale_id);


--
-- Name: idx_payments_tenant_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_payments_tenant_created ON public.payments USING btree (tenant_id, created_at);


--
-- Name: idx_pipeline_stages_pipeline; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_pipeline_stages_pipeline ON public.pipeline_stages USING btree (pipeline_id);


--
-- Name: idx_products_tenant_barcode; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_products_tenant_barcode ON public.products USING btree (tenant_id, barcode);


--
-- Name: idx_products_tenant_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_products_tenant_status ON public.products USING btree (tenant_id, status);


--
-- Name: idx_prof_schedule; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_prof_schedule ON public.professional_schedules USING btree (tenant_id, professional_id, branch_id, weekday);


--
-- Name: idx_professionals_document; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_professionals_document ON public.professionals USING btree (tenant_id, document_number);


--
-- Name: idx_professionals_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_professionals_tenant ON public.professionals USING btree (tenant_id);


--
-- Name: idx_purchase_order_items_order; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_purchase_order_items_order ON public.purchase_order_items USING btree (tenant_id, purchase_order_id);


--
-- Name: idx_quote_items_quote; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_quote_items_quote ON public.quote_items USING btree (tenant_id, quote_id);


--
-- Name: idx_quote_status_history_quote; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_quote_status_history_quote ON public.quote_status_history USING btree (tenant_id, quote_id);


--
-- Name: idx_quotes_customer; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_quotes_customer ON public.quotes USING btree (tenant_id, customer_id);


--
-- Name: idx_quotes_tenant_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_quotes_tenant_status ON public.quotes USING btree (tenant_id, status);


--
-- Name: idx_recalls_customer; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_recalls_customer ON public.recalls USING btree (tenant_id, customer_id);


--
-- Name: idx_recalls_tenant_due; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_recalls_tenant_due ON public.recalls USING btree (tenant_id, status, due_date);


--
-- Name: idx_roles_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_roles_tenant ON public.roles USING btree (tenant_id);


--
-- Name: idx_rooms_branch; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rooms_branch ON public.rooms USING btree (branch_id);


--
-- Name: idx_sale_items_appointment; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_sale_items_appointment ON public.sale_items USING btree (appointment_id) WHERE (appointment_id IS NOT NULL);


--
-- Name: idx_sale_items_sale; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_sale_items_sale ON public.sale_items USING btree (tenant_id, sale_id);


--
-- Name: idx_sale_refunds_sale; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_sale_refunds_sale ON public.sale_refunds USING btree (tenant_id, sale_id);


--
-- Name: idx_sales_appointment; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_sales_appointment ON public.sales USING btree (tenant_id, appointment_id);


--
-- Name: idx_sales_currency; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_sales_currency ON public.sales USING btree (tenant_id, currency);


--
-- Name: idx_sales_tenant_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_sales_tenant_created ON public.sales USING btree (tenant_id, created_at);


--
-- Name: idx_services_tenant_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_services_tenant_status ON public.services USING btree (tenant_id, status_id);


--
-- Name: idx_specialties_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_specialties_tenant ON public.specialties USING btree (tenant_id);


--
-- Name: idx_statuses_tenant_module; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_statuses_tenant_module ON public.statuses USING btree (tenant_id, module_id);


--
-- Name: idx_stock_balances_tenant_product; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_stock_balances_tenant_product ON public.stock_balances USING btree (tenant_id, product_id);


--
-- Name: idx_stock_transfer_items_transfer; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_stock_transfer_items_transfer ON public.stock_transfer_items USING btree (tenant_id, stock_transfer_id);


--
-- Name: idx_subscription_invoices_subscription; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_subscription_invoices_subscription ON public.subscription_invoices USING btree (subscription_id);


--
-- Name: idx_subscription_invoices_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_subscription_invoices_tenant ON public.subscription_invoices USING btree (tenant_id);


--
-- Name: idx_subscriptions_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_subscriptions_status ON public.subscriptions USING btree (status);


--
-- Name: idx_subscriptions_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_subscriptions_tenant ON public.subscriptions USING btree (tenant_id);


--
-- Name: idx_suppliers_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_suppliers_tenant ON public.suppliers USING btree (tenant_id);


--
-- Name: idx_tenant_contacts_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_tenant_contacts_tenant ON public.tenant_contacts USING btree (tenant_id);


--
-- Name: idx_tenants_business_type; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_tenants_business_type ON public.tenants USING btree (business_type_id);


--
-- Name: idx_units_global_abbr; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_units_global_abbr ON public.units USING btree (abbreviation) WHERE (tenant_id IS NULL);


--
-- Name: idx_units_tenant_abbr; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_units_tenant_abbr ON public.units USING btree (tenant_id, abbreviation) WHERE (tenant_id IS NOT NULL);


--
-- Name: idx_user_notifications_inbox; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_user_notifications_inbox ON public.user_notifications USING btree (user_id, created_at DESC);


--
-- Name: idx_user_notifications_unread; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_user_notifications_unread ON public.user_notifications USING btree (user_id) WHERE (read_at IS NULL);


--
-- Name: idx_user_permissions_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_user_permissions_tenant ON public.user_permissions USING btree (tenant_id, user_id);


--
-- Name: idx_user_roles_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_user_roles_tenant ON public.user_roles USING btree (tenant_id, user_id);


--
-- Name: idx_users_saas_email; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_users_saas_email ON public.users USING btree (email) WHERE (tenant_id IS NULL);


--
-- Name: idx_users_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_users_tenant ON public.users USING btree (tenant_id);


--
-- Name: idx_users_tenant_email; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_users_tenant_email ON public.users USING btree (tenant_id, email) WHERE (tenant_id IS NOT NULL);


--
-- Name: idx_waitlist_customer; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_waitlist_customer ON public.waitlist_entries USING btree (tenant_id, customer_id);


--
-- Name: idx_waitlist_tenant_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_waitlist_tenant_status ON public.waitlist_entries USING btree (tenant_id, branch_id, status, date_from);


--
-- Name: uq_cash_sessions_open; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_cash_sessions_open ON public.cash_sessions USING btree (cash_register_id) WHERE ((status)::text = 'open'::text);


--
-- Name: uq_package_plans_name; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_package_plans_name ON public.package_plans USING btree (tenant_id, lower((name)::text)) WHERE (deleted_at IS NULL);


--
-- Name: uq_package_usages_appointment; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_package_usages_appointment ON public.package_usages USING btree (appointment_id) WHERE (appointment_id IS NOT NULL);


--
-- Name: appointment_status_history appointment_status_history_actor_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.appointment_status_history
    ADD CONSTRAINT appointment_status_history_actor_customer_id_fkey FOREIGN KEY (actor_customer_id) REFERENCES public.customers(id) ON DELETE SET NULL;


--
-- Name: appointment_status_history appointment_status_history_actor_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.appointment_status_history
    ADD CONSTRAINT appointment_status_history_actor_user_id_fkey FOREIGN KEY (actor_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: appointment_status_history appointment_status_history_appointment_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.appointment_status_history
    ADD CONSTRAINT appointment_status_history_appointment_id_fkey FOREIGN KEY (appointment_id) REFERENCES public.appointments(id) ON DELETE CASCADE;


--
-- Name: appointment_status_history appointment_status_history_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.appointment_status_history
    ADD CONSTRAINT appointment_status_history_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: appointments appointments_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.appointments
    ADD CONSTRAINT appointments_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE CASCADE;


--
-- Name: appointments appointments_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.appointments
    ADD CONSTRAINT appointments_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: appointments appointments_currency_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.appointments
    ADD CONSTRAINT appointments_currency_fkey FOREIGN KEY (currency) REFERENCES public.currencies(code);


--
-- Name: appointments appointments_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.appointments
    ADD CONSTRAINT appointments_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE CASCADE;


--
-- Name: appointments appointments_customer_package_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.appointments
    ADD CONSTRAINT appointments_customer_package_id_fkey FOREIGN KEY (customer_package_id) REFERENCES public.customer_packages(id) ON DELETE SET NULL;


--
-- Name: appointments appointments_professional_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.appointments
    ADD CONSTRAINT appointments_professional_id_fkey FOREIGN KEY (professional_id) REFERENCES public.professionals(id) ON DELETE RESTRICT;


--
-- Name: appointments appointments_room_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.appointments
    ADD CONSTRAINT appointments_room_id_fkey FOREIGN KEY (room_id) REFERENCES public.rooms(id) ON DELETE SET NULL;


--
-- Name: appointments appointments_service_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.appointments
    ADD CONSTRAINT appointments_service_id_fkey FOREIGN KEY (service_id) REFERENCES public.services(id) ON DELETE RESTRICT;


--
-- Name: appointments appointments_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.appointments
    ADD CONSTRAINT appointments_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: audit_events audit_events_actor_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_events
    ADD CONSTRAINT audit_events_actor_user_id_fkey FOREIGN KEY (actor_user_id) REFERENCES public.users(id);


--
-- Name: audit_events audit_events_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_events
    ADD CONSTRAINT audit_events_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: automation_logs automation_logs_appointment_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.automation_logs
    ADD CONSTRAINT automation_logs_appointment_id_fkey FOREIGN KEY (appointment_id) REFERENCES public.appointments(id) ON DELETE SET NULL;


--
-- Name: automation_logs automation_logs_automation_rule_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.automation_logs
    ADD CONSTRAINT automation_logs_automation_rule_id_fkey FOREIGN KEY (automation_rule_id) REFERENCES public.automation_rules(id) ON DELETE CASCADE;


--
-- Name: automation_logs automation_logs_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.automation_logs
    ADD CONSTRAINT automation_logs_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE SET NULL;


--
-- Name: automation_logs automation_logs_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.automation_logs
    ADD CONSTRAINT automation_logs_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: automation_rules automation_rules_marketing_template_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.automation_rules
    ADD CONSTRAINT automation_rules_marketing_template_id_fkey FOREIGN KEY (marketing_template_id) REFERENCES public.marketing_templates(id) ON DELETE SET NULL;


--
-- Name: automation_rules automation_rules_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.automation_rules
    ADD CONSTRAINT automation_rules_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: availability_blocks availability_blocks_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.availability_blocks
    ADD CONSTRAINT availability_blocks_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id);


--
-- Name: availability_blocks availability_blocks_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.availability_blocks
    ADD CONSTRAINT availability_blocks_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: availability_blocks availability_blocks_professional_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.availability_blocks
    ADD CONSTRAINT availability_blocks_professional_id_fkey FOREIGN KEY (professional_id) REFERENCES public.professionals(id);


--
-- Name: availability_blocks availability_blocks_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.availability_blocks
    ADD CONSTRAINT availability_blocks_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: branch_hours branch_hours_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.branch_hours
    ADD CONSTRAINT branch_hours_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE CASCADE;


--
-- Name: branch_hours branch_hours_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.branch_hours
    ADD CONSTRAINT branch_hours_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: branches branches_company_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.branches
    ADD CONSTRAINT branches_company_id_fkey FOREIGN KEY (company_id) REFERENCES public.companies(id);


--
-- Name: branches branches_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.branches
    ADD CONSTRAINT branches_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: care_record_addenda care_record_addenda_author_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.care_record_addenda
    ADD CONSTRAINT care_record_addenda_author_user_id_fkey FOREIGN KEY (author_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: care_record_addenda care_record_addenda_care_record_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.care_record_addenda
    ADD CONSTRAINT care_record_addenda_care_record_id_fkey FOREIGN KEY (care_record_id) REFERENCES public.care_records(id) ON DELETE CASCADE;


--
-- Name: care_record_addenda care_record_addenda_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.care_record_addenda
    ADD CONSTRAINT care_record_addenda_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: care_records care_records_appointment_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.care_records
    ADD CONSTRAINT care_records_appointment_id_fkey FOREIGN KEY (appointment_id) REFERENCES public.appointments(id) ON DELETE SET NULL;


--
-- Name: care_records care_records_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.care_records
    ADD CONSTRAINT care_records_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: care_records care_records_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.care_records
    ADD CONSTRAINT care_records_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: care_records care_records_customer_dependent_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.care_records
    ADD CONSTRAINT care_records_customer_dependent_id_fkey FOREIGN KEY (customer_dependent_id) REFERENCES public.customer_dependents(id) ON DELETE SET NULL;


--
-- Name: care_records care_records_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.care_records
    ADD CONSTRAINT care_records_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE CASCADE;


--
-- Name: care_records care_records_professional_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.care_records
    ADD CONSTRAINT care_records_professional_id_fkey FOREIGN KEY (professional_id) REFERENCES public.professionals(id) ON DELETE SET NULL;


--
-- Name: care_records care_records_record_template_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.care_records
    ADD CONSTRAINT care_records_record_template_id_fkey FOREIGN KEY (record_template_id) REFERENCES public.record_templates(id) ON DELETE SET NULL;


--
-- Name: care_records care_records_signed_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.care_records
    ADD CONSTRAINT care_records_signed_by_fkey FOREIGN KEY (signed_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: care_records care_records_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.care_records
    ADD CONSTRAINT care_records_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: cash_movements cash_movements_cash_session_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cash_movements
    ADD CONSTRAINT cash_movements_cash_session_id_fkey FOREIGN KEY (cash_session_id) REFERENCES public.cash_sessions(id) ON DELETE CASCADE;


--
-- Name: cash_movements cash_movements_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cash_movements
    ADD CONSTRAINT cash_movements_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: cash_movements cash_movements_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cash_movements
    ADD CONSTRAINT cash_movements_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: cash_registers cash_registers_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cash_registers
    ADD CONSTRAINT cash_registers_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE CASCADE;


--
-- Name: cash_registers cash_registers_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cash_registers
    ADD CONSTRAINT cash_registers_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: cash_sessions cash_sessions_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cash_sessions
    ADD CONSTRAINT cash_sessions_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id);


--
-- Name: cash_sessions cash_sessions_cash_register_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cash_sessions
    ADD CONSTRAINT cash_sessions_cash_register_id_fkey FOREIGN KEY (cash_register_id) REFERENCES public.cash_registers(id);


--
-- Name: cash_sessions cash_sessions_closed_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cash_sessions
    ADD CONSTRAINT cash_sessions_closed_by_fkey FOREIGN KEY (closed_by) REFERENCES public.users(id);


--
-- Name: cash_sessions cash_sessions_currency_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cash_sessions
    ADD CONSTRAINT cash_sessions_currency_fkey FOREIGN KEY (currency) REFERENCES public.currencies(code);


--
-- Name: cash_sessions cash_sessions_opened_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cash_sessions
    ADD CONSTRAINT cash_sessions_opened_by_fkey FOREIGN KEY (opened_by) REFERENCES public.users(id);


--
-- Name: cash_sessions cash_sessions_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cash_sessions
    ADD CONSTRAINT cash_sessions_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: companies companies_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.companies
    ADD CONSTRAINT companies_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: coupon_redemptions coupon_redemptions_coupon_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.coupon_redemptions
    ADD CONSTRAINT coupon_redemptions_coupon_id_fkey FOREIGN KEY (coupon_id) REFERENCES public.coupons(id) ON DELETE CASCADE;


--
-- Name: coupon_redemptions coupon_redemptions_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.coupon_redemptions
    ADD CONSTRAINT coupon_redemptions_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE SET NULL;


--
-- Name: coupon_redemptions coupon_redemptions_sale_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.coupon_redemptions
    ADD CONSTRAINT coupon_redemptions_sale_id_fkey FOREIGN KEY (sale_id) REFERENCES public.sales(id) ON DELETE SET NULL;


--
-- Name: coupon_redemptions coupon_redemptions_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.coupon_redemptions
    ADD CONSTRAINT coupon_redemptions_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: coupons coupons_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.coupons
    ADD CONSTRAINT coupons_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: currency_changes currency_changes_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.currency_changes
    ADD CONSTRAINT currency_changes_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: currency_changes currency_changes_from_currency_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.currency_changes
    ADD CONSTRAINT currency_changes_from_currency_fkey FOREIGN KEY (from_currency) REFERENCES public.currencies(code);


--
-- Name: currency_changes currency_changes_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.currency_changes
    ADD CONSTRAINT currency_changes_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: currency_changes currency_changes_to_currency_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.currency_changes
    ADD CONSTRAINT currency_changes_to_currency_fkey FOREIGN KEY (to_currency) REFERENCES public.currencies(code);


--
-- Name: customer_consents customer_consents_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_consents
    ADD CONSTRAINT customer_consents_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE CASCADE;


--
-- Name: customer_consents customer_consents_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_consents
    ADD CONSTRAINT customer_consents_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: customer_dependents customer_dependents_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_dependents
    ADD CONSTRAINT customer_dependents_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE CASCADE;


--
-- Name: customer_dependents customer_dependents_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_dependents
    ADD CONSTRAINT customer_dependents_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: customer_messages customer_messages_appointment_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_messages
    ADD CONSTRAINT customer_messages_appointment_id_fkey FOREIGN KEY (appointment_id) REFERENCES public.appointments(id) ON DELETE SET NULL;


--
-- Name: customer_messages customer_messages_automation_rule_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_messages
    ADD CONSTRAINT customer_messages_automation_rule_id_fkey FOREIGN KEY (automation_rule_id) REFERENCES public.automation_rules(id) ON DELETE SET NULL;


--
-- Name: customer_messages customer_messages_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_messages
    ADD CONSTRAINT customer_messages_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE SET NULL;


--
-- Name: customer_messages customer_messages_marketing_campaign_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_messages
    ADD CONSTRAINT customer_messages_marketing_campaign_id_fkey FOREIGN KEY (marketing_campaign_id) REFERENCES public.marketing_campaigns(id) ON DELETE SET NULL;


--
-- Name: customer_messages customer_messages_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_messages
    ADD CONSTRAINT customer_messages_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: customer_package_events customer_package_events_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_package_events
    ADD CONSTRAINT customer_package_events_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: customer_package_events customer_package_events_customer_package_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_package_events
    ADD CONSTRAINT customer_package_events_customer_package_id_fkey FOREIGN KEY (customer_package_id) REFERENCES public.customer_packages(id) ON DELETE CASCADE;


--
-- Name: customer_package_events customer_package_events_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_package_events
    ADD CONSTRAINT customer_package_events_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: customer_package_items customer_package_items_customer_package_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_package_items
    ADD CONSTRAINT customer_package_items_customer_package_id_fkey FOREIGN KEY (customer_package_id) REFERENCES public.customer_packages(id) ON DELETE CASCADE;


--
-- Name: customer_package_items customer_package_items_service_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_package_items
    ADD CONSTRAINT customer_package_items_service_id_fkey FOREIGN KEY (service_id) REFERENCES public.services(id);


--
-- Name: customer_package_items customer_package_items_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_package_items
    ADD CONSTRAINT customer_package_items_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: customer_packages customer_packages_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_packages
    ADD CONSTRAINT customer_packages_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: customer_packages customer_packages_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_packages
    ADD CONSTRAINT customer_packages_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: customer_packages customer_packages_currency_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_packages
    ADD CONSTRAINT customer_packages_currency_fkey FOREIGN KEY (currency) REFERENCES public.currencies(code);


--
-- Name: customer_packages customer_packages_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_packages
    ADD CONSTRAINT customer_packages_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE CASCADE;


--
-- Name: customer_packages customer_packages_package_plan_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_packages
    ADD CONSTRAINT customer_packages_package_plan_id_fkey FOREIGN KEY (package_plan_id) REFERENCES public.package_plans(id) ON DELETE SET NULL;


--
-- Name: customer_packages customer_packages_professional_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_packages
    ADD CONSTRAINT customer_packages_professional_id_fkey FOREIGN KEY (professional_id) REFERENCES public.professionals(id) ON DELETE SET NULL;


--
-- Name: customer_packages customer_packages_sale_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_packages
    ADD CONSTRAINT customer_packages_sale_id_fkey FOREIGN KEY (sale_id) REFERENCES public.sales(id) ON DELETE SET NULL;


--
-- Name: customer_packages customer_packages_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_packages
    ADD CONSTRAINT customer_packages_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: customer_tag_assignments customer_tag_assignments_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_tag_assignments
    ADD CONSTRAINT customer_tag_assignments_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE CASCADE;


--
-- Name: customer_tag_assignments customer_tag_assignments_customer_tag_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_tag_assignments
    ADD CONSTRAINT customer_tag_assignments_customer_tag_id_fkey FOREIGN KEY (customer_tag_id) REFERENCES public.customer_tags(id) ON DELETE CASCADE;


--
-- Name: customer_tag_assignments customer_tag_assignments_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_tag_assignments
    ADD CONSTRAINT customer_tag_assignments_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: customer_tags customer_tags_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_tags
    ADD CONSTRAINT customer_tags_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: customers customers_manager_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customers
    ADD CONSTRAINT customers_manager_user_id_fkey FOREIGN KEY (manager_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: customers customers_pipeline_stage_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customers
    ADD CONSTRAINT customers_pipeline_stage_id_fkey FOREIGN KEY (pipeline_stage_id) REFERENCES public.pipeline_stages(id) ON DELETE SET NULL;


--
-- Name: customers customers_status_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customers
    ADD CONSTRAINT customers_status_id_fkey FOREIGN KEY (status_id) REFERENCES public.statuses(id);


--
-- Name: customers customers_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customers
    ADD CONSTRAINT customers_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: customers customers_type_document_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customers
    ADD CONSTRAINT customers_type_document_id_fkey FOREIGN KEY (type_document_id) REFERENCES public.type_documents(id) ON DELETE SET NULL;


--
-- Name: appointments fk_appointments_customer_dependent; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.appointments
    ADD CONSTRAINT fk_appointments_customer_dependent FOREIGN KEY (customer_dependent_id) REFERENCES public.customer_dependents(id) ON DELETE SET NULL;


--
-- Name: payment_links fk_payment_links_customer_dependent; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_links
    ADD CONSTRAINT fk_payment_links_customer_dependent FOREIGN KEY (customer_dependent_id) REFERENCES public.customer_dependents(id) ON DELETE SET NULL;


--
-- Name: gift_card_transactions gift_card_transactions_gift_card_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gift_card_transactions
    ADD CONSTRAINT gift_card_transactions_gift_card_id_fkey FOREIGN KEY (gift_card_id) REFERENCES public.gift_cards(id) ON DELETE CASCADE;


--
-- Name: gift_card_transactions gift_card_transactions_sale_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gift_card_transactions
    ADD CONSTRAINT gift_card_transactions_sale_id_fkey FOREIGN KEY (sale_id) REFERENCES public.sales(id) ON DELETE SET NULL;


--
-- Name: gift_card_transactions gift_card_transactions_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gift_card_transactions
    ADD CONSTRAINT gift_card_transactions_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: gift_cards gift_cards_purchaser_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gift_cards
    ADD CONSTRAINT gift_cards_purchaser_customer_id_fkey FOREIGN KEY (purchaser_customer_id) REFERENCES public.customers(id) ON DELETE SET NULL;


--
-- Name: gift_cards gift_cards_recipient_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gift_cards
    ADD CONSTRAINT gift_cards_recipient_customer_id_fkey FOREIGN KEY (recipient_customer_id) REFERENCES public.customers(id) ON DELETE SET NULL;


--
-- Name: gift_cards gift_cards_sale_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gift_cards
    ADD CONSTRAINT gift_cards_sale_id_fkey FOREIGN KEY (sale_id) REFERENCES public.sales(id) ON DELETE SET NULL;


--
-- Name: gift_cards gift_cards_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gift_cards
    ADD CONSTRAINT gift_cards_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: holidays holidays_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.holidays
    ADD CONSTRAINT holidays_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE CASCADE;


--
-- Name: holidays holidays_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.holidays
    ADD CONSTRAINT holidays_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: holidays holidays_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.holidays
    ADD CONSTRAINT holidays_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: inventory_movements inventory_movements_actor_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_movements
    ADD CONSTRAINT inventory_movements_actor_user_id_fkey FOREIGN KEY (actor_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: inventory_movements inventory_movements_product_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_movements
    ADD CONSTRAINT inventory_movements_product_id_fkey FOREIGN KEY (product_id) REFERENCES public.products(id);


--
-- Name: inventory_movements inventory_movements_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_movements
    ADD CONSTRAINT inventory_movements_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: inventory_movements inventory_movements_warehouse_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_movements
    ADD CONSTRAINT inventory_movements_warehouse_id_fkey FOREIGN KEY (warehouse_id) REFERENCES public.warehouses(id);


--
-- Name: invoice_series invoice_series_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoice_series
    ADD CONSTRAINT invoice_series_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: invoices invoices_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id);


--
-- Name: invoices invoices_currency_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_currency_fkey FOREIGN KEY (currency) REFERENCES public.currencies(code);


--
-- Name: invoices invoices_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE SET NULL;


--
-- Name: invoices invoices_issued_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_issued_by_fkey FOREIGN KEY (issued_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: invoices invoices_reference_invoice_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_reference_invoice_id_fkey FOREIGN KEY (reference_invoice_id) REFERENCES public.invoices(id) ON DELETE SET NULL;


--
-- Name: invoices invoices_sale_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_sale_id_fkey FOREIGN KEY (sale_id) REFERENCES public.sales(id);


--
-- Name: invoices invoices_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: loyalty_transactions loyalty_transactions_actor_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loyalty_transactions
    ADD CONSTRAINT loyalty_transactions_actor_user_id_fkey FOREIGN KEY (actor_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: loyalty_transactions loyalty_transactions_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loyalty_transactions
    ADD CONSTRAINT loyalty_transactions_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE CASCADE;


--
-- Name: loyalty_transactions loyalty_transactions_sale_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loyalty_transactions
    ADD CONSTRAINT loyalty_transactions_sale_id_fkey FOREIGN KEY (sale_id) REFERENCES public.sales(id) ON DELETE SET NULL;


--
-- Name: loyalty_transactions loyalty_transactions_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loyalty_transactions
    ADD CONSTRAINT loyalty_transactions_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: marketing_campaign_recipients marketing_campaign_recipients_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.marketing_campaign_recipients
    ADD CONSTRAINT marketing_campaign_recipients_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE CASCADE;


--
-- Name: marketing_campaign_recipients marketing_campaign_recipients_marketing_campaign_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.marketing_campaign_recipients
    ADD CONSTRAINT marketing_campaign_recipients_marketing_campaign_id_fkey FOREIGN KEY (marketing_campaign_id) REFERENCES public.marketing_campaigns(id) ON DELETE CASCADE;


--
-- Name: marketing_campaign_recipients marketing_campaign_recipients_message_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.marketing_campaign_recipients
    ADD CONSTRAINT marketing_campaign_recipients_message_id_fkey FOREIGN KEY (message_id) REFERENCES public.messages(id) ON DELETE SET NULL;


--
-- Name: marketing_campaign_recipients marketing_campaign_recipients_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.marketing_campaign_recipients
    ADD CONSTRAINT marketing_campaign_recipients_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: marketing_campaigns marketing_campaigns_coupon_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.marketing_campaigns
    ADD CONSTRAINT marketing_campaigns_coupon_id_fkey FOREIGN KEY (coupon_id) REFERENCES public.coupons(id) ON DELETE SET NULL;


--
-- Name: marketing_campaigns marketing_campaigns_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.marketing_campaigns
    ADD CONSTRAINT marketing_campaigns_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: marketing_campaigns marketing_campaigns_marketing_template_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.marketing_campaigns
    ADD CONSTRAINT marketing_campaigns_marketing_template_id_fkey FOREIGN KEY (marketing_template_id) REFERENCES public.marketing_templates(id) ON DELETE SET NULL;


--
-- Name: marketing_campaigns marketing_campaigns_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.marketing_campaigns
    ADD CONSTRAINT marketing_campaigns_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: marketing_templates marketing_templates_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.marketing_templates
    ADD CONSTRAINT marketing_templates_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: medical_records medical_records_appointment_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.medical_records
    ADD CONSTRAINT medical_records_appointment_id_fkey FOREIGN KEY (appointment_id) REFERENCES public.appointments(id) ON DELETE SET NULL;


--
-- Name: medical_records medical_records_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.medical_records
    ADD CONSTRAINT medical_records_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE CASCADE;


--
-- Name: medical_records medical_records_professional_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.medical_records
    ADD CONSTRAINT medical_records_professional_id_fkey FOREIGN KEY (professional_id) REFERENCES public.professionals(id) ON DELETE RESTRICT;


--
-- Name: medical_records medical_records_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.medical_records
    ADD CONSTRAINT medical_records_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: membership_plans membership_plans_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.membership_plans
    ADD CONSTRAINT membership_plans_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: memberships memberships_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.memberships
    ADD CONSTRAINT memberships_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE CASCADE;


--
-- Name: memberships memberships_membership_plan_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.memberships
    ADD CONSTRAINT memberships_membership_plan_id_fkey FOREIGN KEY (membership_plan_id) REFERENCES public.membership_plans(id);


--
-- Name: memberships memberships_sale_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.memberships
    ADD CONSTRAINT memberships_sale_id_fkey FOREIGN KEY (sale_id) REFERENCES public.sales(id) ON DELETE SET NULL;


--
-- Name: memberships memberships_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.memberships
    ADD CONSTRAINT memberships_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: message_settings message_settings_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.message_settings
    ADD CONSTRAINT message_settings_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: message_settings message_settings_updated_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.message_settings
    ADD CONSTRAINT message_settings_updated_by_fkey FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: messages messages_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.messages
    ADD CONSTRAINT messages_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: messages messages_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.messages
    ADD CONSTRAINT messages_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: messages messages_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.messages
    ADD CONSTRAINT messages_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE SET NULL;


--
-- Name: messages messages_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.messages
    ADD CONSTRAINT messages_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: package_plan_items package_plan_items_package_plan_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.package_plan_items
    ADD CONSTRAINT package_plan_items_package_plan_id_fkey FOREIGN KEY (package_plan_id) REFERENCES public.package_plans(id) ON DELETE CASCADE;


--
-- Name: package_plan_items package_plan_items_service_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.package_plan_items
    ADD CONSTRAINT package_plan_items_service_id_fkey FOREIGN KEY (service_id) REFERENCES public.services(id);


--
-- Name: package_plan_items package_plan_items_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.package_plan_items
    ADD CONSTRAINT package_plan_items_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: package_plans package_plans_currency_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.package_plans
    ADD CONSTRAINT package_plans_currency_fkey FOREIGN KEY (currency) REFERENCES public.currencies(code);


--
-- Name: package_plans package_plans_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.package_plans
    ADD CONSTRAINT package_plans_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: package_usages package_usages_appointment_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.package_usages
    ADD CONSTRAINT package_usages_appointment_id_fkey FOREIGN KEY (appointment_id) REFERENCES public.appointments(id) ON DELETE SET NULL;


--
-- Name: package_usages package_usages_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.package_usages
    ADD CONSTRAINT package_usages_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: package_usages package_usages_customer_package_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.package_usages
    ADD CONSTRAINT package_usages_customer_package_id_fkey FOREIGN KEY (customer_package_id) REFERENCES public.customer_packages(id) ON DELETE CASCADE;


--
-- Name: package_usages package_usages_professional_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.package_usages
    ADD CONSTRAINT package_usages_professional_id_fkey FOREIGN KEY (professional_id) REFERENCES public.professionals(id) ON DELETE SET NULL;


--
-- Name: package_usages package_usages_service_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.package_usages
    ADD CONSTRAINT package_usages_service_id_fkey FOREIGN KEY (service_id) REFERENCES public.services(id);


--
-- Name: package_usages package_usages_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.package_usages
    ADD CONSTRAINT package_usages_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: payment_events payment_events_payment_link_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_events
    ADD CONSTRAINT payment_events_payment_link_id_fkey FOREIGN KEY (payment_link_id) REFERENCES public.payment_links(id) ON DELETE SET NULL;


--
-- Name: payment_events payment_events_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_events
    ADD CONSTRAINT payment_events_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: payment_links payment_links_appointment_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_links
    ADD CONSTRAINT payment_links_appointment_id_fkey FOREIGN KEY (appointment_id) REFERENCES public.appointments(id) ON DELETE SET NULL;


--
-- Name: payment_links payment_links_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_links
    ADD CONSTRAINT payment_links_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: payment_links payment_links_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_links
    ADD CONSTRAINT payment_links_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: payment_links payment_links_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_links
    ADD CONSTRAINT payment_links_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE SET NULL;


--
-- Name: payment_links payment_links_payment_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_links
    ADD CONSTRAINT payment_links_payment_id_fkey FOREIGN KEY (payment_id) REFERENCES public.payments(id) ON DELETE SET NULL;


--
-- Name: payment_links payment_links_professional_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_links
    ADD CONSTRAINT payment_links_professional_id_fkey FOREIGN KEY (professional_id) REFERENCES public.professionals(id) ON DELETE SET NULL;


--
-- Name: payment_links payment_links_sale_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_links
    ADD CONSTRAINT payment_links_sale_id_fkey FOREIGN KEY (sale_id) REFERENCES public.sales(id) ON DELETE CASCADE;


--
-- Name: payment_links payment_links_service_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_links
    ADD CONSTRAINT payment_links_service_id_fkey FOREIGN KEY (service_id) REFERENCES public.services(id) ON DELETE SET NULL;


--
-- Name: payment_links payment_links_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_links
    ADD CONSTRAINT payment_links_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: payment_qr_codes payment_qr_codes_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_qr_codes
    ADD CONSTRAINT payment_qr_codes_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: payment_qr_codes payment_qr_codes_updated_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_qr_codes
    ADD CONSTRAINT payment_qr_codes_updated_by_fkey FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: payments payments_appointment_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payments
    ADD CONSTRAINT payments_appointment_id_fkey FOREIGN KEY (appointment_id) REFERENCES public.appointments(id) ON DELETE SET NULL;


--
-- Name: payments payments_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payments
    ADD CONSTRAINT payments_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id);


--
-- Name: payments payments_cash_session_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payments
    ADD CONSTRAINT payments_cash_session_id_fkey FOREIGN KEY (cash_session_id) REFERENCES public.cash_sessions(id) ON DELETE SET NULL;


--
-- Name: payments payments_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payments
    ADD CONSTRAINT payments_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: payments payments_currency_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payments
    ADD CONSTRAINT payments_currency_fkey FOREIGN KEY (currency) REFERENCES public.currencies(code);


--
-- Name: payments payments_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payments
    ADD CONSTRAINT payments_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE SET NULL;


--
-- Name: payments payments_sale_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payments
    ADD CONSTRAINT payments_sale_id_fkey FOREIGN KEY (sale_id) REFERENCES public.sales(id);


--
-- Name: payments payments_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payments
    ADD CONSTRAINT payments_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: pipeline_stages pipeline_stages_pipeline_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pipeline_stages
    ADD CONSTRAINT pipeline_stages_pipeline_id_fkey FOREIGN KEY (pipeline_id) REFERENCES public.pipelines(id) ON DELETE CASCADE;


--
-- Name: product_brands product_brands_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_brands
    ADD CONSTRAINT product_brands_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: product_categories product_categories_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_categories
    ADD CONSTRAINT product_categories_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: products products_brand_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_brand_id_fkey FOREIGN KEY (brand_id) REFERENCES public.product_brands(id) ON DELETE SET NULL;


--
-- Name: products products_category_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_category_id_fkey FOREIGN KEY (category_id) REFERENCES public.product_categories(id) ON DELETE SET NULL;


--
-- Name: products products_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: products products_unit_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_unit_id_fkey FOREIGN KEY (unit_id) REFERENCES public.units(id) ON DELETE SET NULL;


--
-- Name: professional_schedules professional_schedules_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.professional_schedules
    ADD CONSTRAINT professional_schedules_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id);


--
-- Name: professional_schedules professional_schedules_professional_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.professional_schedules
    ADD CONSTRAINT professional_schedules_professional_id_fkey FOREIGN KEY (professional_id) REFERENCES public.professionals(id);


--
-- Name: professional_schedules professional_schedules_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.professional_schedules
    ADD CONSTRAINT professional_schedules_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: professional_specialties professional_specialties_professional_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.professional_specialties
    ADD CONSTRAINT professional_specialties_professional_id_fkey FOREIGN KEY (professional_id) REFERENCES public.professionals(id) ON DELETE CASCADE;


--
-- Name: professional_specialties professional_specialties_specialty_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.professional_specialties
    ADD CONSTRAINT professional_specialties_specialty_id_fkey FOREIGN KEY (specialty_id) REFERENCES public.specialties(id) ON DELETE CASCADE;


--
-- Name: professional_specialties professional_specialties_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.professional_specialties
    ADD CONSTRAINT professional_specialties_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: professionals professionals_status_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.professionals
    ADD CONSTRAINT professionals_status_id_fkey FOREIGN KEY (status_id) REFERENCES public.statuses(id);


--
-- Name: professionals professionals_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.professionals
    ADD CONSTRAINT professionals_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: professionals professionals_type_document_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.professionals
    ADD CONSTRAINT professionals_type_document_id_fkey FOREIGN KEY (type_document_id) REFERENCES public.type_documents(id);


--
-- Name: professionals professionals_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.professionals
    ADD CONSTRAINT professionals_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: purchase_order_items purchase_order_items_product_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_order_items
    ADD CONSTRAINT purchase_order_items_product_id_fkey FOREIGN KEY (product_id) REFERENCES public.products(id);


--
-- Name: purchase_order_items purchase_order_items_purchase_order_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_order_items
    ADD CONSTRAINT purchase_order_items_purchase_order_id_fkey FOREIGN KEY (purchase_order_id) REFERENCES public.purchase_orders(id) ON DELETE CASCADE;


--
-- Name: purchase_order_items purchase_order_items_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_order_items
    ADD CONSTRAINT purchase_order_items_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: purchase_orders purchase_orders_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: purchase_orders purchase_orders_supplier_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_supplier_id_fkey FOREIGN KEY (supplier_id) REFERENCES public.suppliers(id);


--
-- Name: purchase_orders purchase_orders_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: purchase_orders purchase_orders_warehouse_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_warehouse_id_fkey FOREIGN KEY (warehouse_id) REFERENCES public.warehouses(id);


--
-- Name: quote_installments quote_installments_payment_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quote_installments
    ADD CONSTRAINT quote_installments_payment_id_fkey FOREIGN KEY (payment_id) REFERENCES public.payments(id) ON DELETE SET NULL;


--
-- Name: quote_installments quote_installments_quote_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quote_installments
    ADD CONSTRAINT quote_installments_quote_id_fkey FOREIGN KEY (quote_id) REFERENCES public.quotes(id) ON DELETE CASCADE;


--
-- Name: quote_installments quote_installments_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quote_installments
    ADD CONSTRAINT quote_installments_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: quote_items quote_items_quote_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quote_items
    ADD CONSTRAINT quote_items_quote_id_fkey FOREIGN KEY (quote_id) REFERENCES public.quotes(id) ON DELETE CASCADE;


--
-- Name: quote_items quote_items_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quote_items
    ADD CONSTRAINT quote_items_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: quote_status_history quote_status_history_actor_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quote_status_history
    ADD CONSTRAINT quote_status_history_actor_user_id_fkey FOREIGN KEY (actor_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: quote_status_history quote_status_history_quote_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quote_status_history
    ADD CONSTRAINT quote_status_history_quote_id_fkey FOREIGN KEY (quote_id) REFERENCES public.quotes(id) ON DELETE CASCADE;


--
-- Name: quote_status_history quote_status_history_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quote_status_history
    ADD CONSTRAINT quote_status_history_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: quotes quotes_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quotes
    ADD CONSTRAINT quotes_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: quotes quotes_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quotes
    ADD CONSTRAINT quotes_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: quotes quotes_customer_dependent_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quotes
    ADD CONSTRAINT quotes_customer_dependent_id_fkey FOREIGN KEY (customer_dependent_id) REFERENCES public.customer_dependents(id) ON DELETE SET NULL;


--
-- Name: quotes quotes_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quotes
    ADD CONSTRAINT quotes_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE CASCADE;


--
-- Name: quotes quotes_professional_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quotes
    ADD CONSTRAINT quotes_professional_id_fkey FOREIGN KEY (professional_id) REFERENCES public.professionals(id) ON DELETE SET NULL;


--
-- Name: quotes quotes_sale_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quotes
    ADD CONSTRAINT quotes_sale_id_fkey FOREIGN KEY (sale_id) REFERENCES public.sales(id) ON DELETE SET NULL;


--
-- Name: quotes quotes_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quotes
    ADD CONSTRAINT quotes_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: recalls recalls_appointment_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recalls
    ADD CONSTRAINT recalls_appointment_id_fkey FOREIGN KEY (appointment_id) REFERENCES public.appointments(id) ON DELETE SET NULL;


--
-- Name: recalls recalls_care_record_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recalls
    ADD CONSTRAINT recalls_care_record_id_fkey FOREIGN KEY (care_record_id) REFERENCES public.care_records(id) ON DELETE SET NULL;


--
-- Name: recalls recalls_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recalls
    ADD CONSTRAINT recalls_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: recalls recalls_customer_dependent_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recalls
    ADD CONSTRAINT recalls_customer_dependent_id_fkey FOREIGN KEY (customer_dependent_id) REFERENCES public.customer_dependents(id) ON DELETE SET NULL;


--
-- Name: recalls recalls_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recalls
    ADD CONSTRAINT recalls_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE CASCADE;


--
-- Name: recalls recalls_professional_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recalls
    ADD CONSTRAINT recalls_professional_id_fkey FOREIGN KEY (professional_id) REFERENCES public.professionals(id) ON DELETE SET NULL;


--
-- Name: recalls recalls_service_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recalls
    ADD CONSTRAINT recalls_service_id_fkey FOREIGN KEY (service_id) REFERENCES public.services(id) ON DELETE SET NULL;


--
-- Name: recalls recalls_source_appointment_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recalls
    ADD CONSTRAINT recalls_source_appointment_id_fkey FOREIGN KEY (source_appointment_id) REFERENCES public.appointments(id) ON DELETE SET NULL;


--
-- Name: recalls recalls_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recalls
    ADD CONSTRAINT recalls_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: record_template_services record_template_services_record_template_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.record_template_services
    ADD CONSTRAINT record_template_services_record_template_id_fkey FOREIGN KEY (record_template_id) REFERENCES public.record_templates(id) ON DELETE CASCADE;


--
-- Name: record_template_services record_template_services_service_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.record_template_services
    ADD CONSTRAINT record_template_services_service_id_fkey FOREIGN KEY (service_id) REFERENCES public.services(id) ON DELETE CASCADE;


--
-- Name: record_template_services record_template_services_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.record_template_services
    ADD CONSTRAINT record_template_services_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: record_templates record_templates_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.record_templates
    ADD CONSTRAINT record_templates_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: role_permissions role_permissions_permission_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_permissions
    ADD CONSTRAINT role_permissions_permission_id_fkey FOREIGN KEY (permission_id) REFERENCES public.permissions(id) ON DELETE CASCADE;


--
-- Name: role_permissions role_permissions_role_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_permissions
    ADD CONSTRAINT role_permissions_role_id_fkey FOREIGN KEY (role_id) REFERENCES public.roles(id) ON DELETE CASCADE;


--
-- Name: roles roles_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: rooms rooms_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rooms
    ADD CONSTRAINT rooms_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE CASCADE;


--
-- Name: rooms rooms_parent_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rooms
    ADD CONSTRAINT rooms_parent_id_fkey FOREIGN KEY (parent_id) REFERENCES public.rooms(id) ON DELETE SET NULL;


--
-- Name: rooms rooms_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rooms
    ADD CONSTRAINT rooms_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: sale_items sale_items_appointment_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sale_items
    ADD CONSTRAINT sale_items_appointment_id_fkey FOREIGN KEY (appointment_id) REFERENCES public.appointments(id) ON DELETE SET NULL;


--
-- Name: sale_items sale_items_professional_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sale_items
    ADD CONSTRAINT sale_items_professional_id_fkey FOREIGN KEY (professional_id) REFERENCES public.professionals(id) ON DELETE SET NULL;


--
-- Name: sale_items sale_items_sale_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sale_items
    ADD CONSTRAINT sale_items_sale_id_fkey FOREIGN KEY (sale_id) REFERENCES public.sales(id) ON DELETE CASCADE;


--
-- Name: sale_items sale_items_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sale_items
    ADD CONSTRAINT sale_items_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: sale_refunds sale_refunds_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sale_refunds
    ADD CONSTRAINT sale_refunds_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: sale_refunds sale_refunds_invoice_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sale_refunds
    ADD CONSTRAINT sale_refunds_invoice_id_fkey FOREIGN KEY (invoice_id) REFERENCES public.invoices(id) ON DELETE SET NULL;


--
-- Name: sale_refunds sale_refunds_payment_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sale_refunds
    ADD CONSTRAINT sale_refunds_payment_id_fkey FOREIGN KEY (payment_id) REFERENCES public.payments(id) ON DELETE SET NULL;


--
-- Name: sale_refunds sale_refunds_sale_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sale_refunds
    ADD CONSTRAINT sale_refunds_sale_id_fkey FOREIGN KEY (sale_id) REFERENCES public.sales(id);


--
-- Name: sale_refunds sale_refunds_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sale_refunds
    ADD CONSTRAINT sale_refunds_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: sales sales_appointment_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales
    ADD CONSTRAINT sales_appointment_id_fkey FOREIGN KEY (appointment_id) REFERENCES public.appointments(id);


--
-- Name: sales sales_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales
    ADD CONSTRAINT sales_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id);


--
-- Name: sales sales_converted_from_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales
    ADD CONSTRAINT sales_converted_from_fkey FOREIGN KEY (converted_from) REFERENCES public.currencies(code);


--
-- Name: sales sales_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales
    ADD CONSTRAINT sales_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: sales sales_currency_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales
    ADD CONSTRAINT sales_currency_fkey FOREIGN KEY (currency) REFERENCES public.currencies(code);


--
-- Name: sales sales_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales
    ADD CONSTRAINT sales_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id);


--
-- Name: sales sales_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales
    ADD CONSTRAINT sales_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: service_categories service_categories_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.service_categories
    ADD CONSTRAINT service_categories_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: service_professionals service_professionals_professional_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.service_professionals
    ADD CONSTRAINT service_professionals_professional_id_fkey FOREIGN KEY (professional_id) REFERENCES public.professionals(id);


--
-- Name: service_professionals service_professionals_service_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.service_professionals
    ADD CONSTRAINT service_professionals_service_id_fkey FOREIGN KEY (service_id) REFERENCES public.services(id);


--
-- Name: service_professionals service_professionals_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.service_professionals
    ADD CONSTRAINT service_professionals_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: service_supplies service_supplies_product_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.service_supplies
    ADD CONSTRAINT service_supplies_product_id_fkey FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: service_supplies service_supplies_service_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.service_supplies
    ADD CONSTRAINT service_supplies_service_id_fkey FOREIGN KEY (service_id) REFERENCES public.services(id) ON DELETE CASCADE;


--
-- Name: service_supplies service_supplies_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.service_supplies
    ADD CONSTRAINT service_supplies_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: services services_category_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.services
    ADD CONSTRAINT services_category_id_fkey FOREIGN KEY (category_id) REFERENCES public.service_categories(id);


--
-- Name: services services_recall_service_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.services
    ADD CONSTRAINT services_recall_service_id_fkey FOREIGN KEY (recall_service_id) REFERENCES public.services(id) ON DELETE SET NULL;


--
-- Name: services services_status_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.services
    ADD CONSTRAINT services_status_id_fkey FOREIGN KEY (status_id) REFERENCES public.statuses(id);


--
-- Name: services services_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.services
    ADD CONSTRAINT services_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id);


--
-- Name: specialties specialties_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.specialties
    ADD CONSTRAINT specialties_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: statuses statuses_module_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.statuses
    ADD CONSTRAINT statuses_module_id_fkey FOREIGN KEY (module_id) REFERENCES public.status_modules(id) ON DELETE CASCADE;


--
-- Name: statuses statuses_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.statuses
    ADD CONSTRAINT statuses_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: stock_balances stock_balances_product_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_balances
    ADD CONSTRAINT stock_balances_product_id_fkey FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: stock_balances stock_balances_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_balances
    ADD CONSTRAINT stock_balances_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: stock_balances stock_balances_warehouse_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_balances
    ADD CONSTRAINT stock_balances_warehouse_id_fkey FOREIGN KEY (warehouse_id) REFERENCES public.warehouses(id) ON DELETE CASCADE;


--
-- Name: stock_transfer_items stock_transfer_items_product_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_transfer_items
    ADD CONSTRAINT stock_transfer_items_product_id_fkey FOREIGN KEY (product_id) REFERENCES public.products(id);


--
-- Name: stock_transfer_items stock_transfer_items_stock_transfer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_transfer_items
    ADD CONSTRAINT stock_transfer_items_stock_transfer_id_fkey FOREIGN KEY (stock_transfer_id) REFERENCES public.stock_transfers(id) ON DELETE CASCADE;


--
-- Name: stock_transfer_items stock_transfer_items_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_transfer_items
    ADD CONSTRAINT stock_transfer_items_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: stock_transfers stock_transfers_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_transfers
    ADD CONSTRAINT stock_transfers_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: stock_transfers stock_transfers_from_warehouse_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_transfers
    ADD CONSTRAINT stock_transfers_from_warehouse_id_fkey FOREIGN KEY (from_warehouse_id) REFERENCES public.warehouses(id);


--
-- Name: stock_transfers stock_transfers_received_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_transfers
    ADD CONSTRAINT stock_transfers_received_by_fkey FOREIGN KEY (received_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: stock_transfers stock_transfers_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_transfers
    ADD CONSTRAINT stock_transfers_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: stock_transfers stock_transfers_to_warehouse_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_transfers
    ADD CONSTRAINT stock_transfers_to_warehouse_id_fkey FOREIGN KEY (to_warehouse_id) REFERENCES public.warehouses(id);


--
-- Name: subscription_invoices subscription_invoices_status_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_invoices
    ADD CONSTRAINT subscription_invoices_status_id_fkey FOREIGN KEY (status_id) REFERENCES public.statuses(id);


--
-- Name: subscription_invoices subscription_invoices_subscription_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_invoices
    ADD CONSTRAINT subscription_invoices_subscription_id_fkey FOREIGN KEY (subscription_id) REFERENCES public.subscriptions(id) ON DELETE CASCADE;


--
-- Name: subscription_invoices subscription_invoices_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_invoices
    ADD CONSTRAINT subscription_invoices_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: subscriptions subscriptions_plan_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscriptions
    ADD CONSTRAINT subscriptions_plan_id_fkey FOREIGN KEY (plan_id) REFERENCES public.saas_plans(id);


--
-- Name: subscriptions subscriptions_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscriptions
    ADD CONSTRAINT subscriptions_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: suppliers suppliers_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.suppliers
    ADD CONSTRAINT suppliers_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: suppliers suppliers_type_document_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.suppliers
    ADD CONSTRAINT suppliers_type_document_id_fkey FOREIGN KEY (type_document_id) REFERENCES public.type_documents(id) ON DELETE SET NULL;


--
-- Name: tenant_contacts tenant_contacts_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_contacts
    ADD CONSTRAINT tenant_contacts_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: tenant_contacts tenant_contacts_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_contacts
    ADD CONSTRAINT tenant_contacts_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: tenant_integrations tenant_integrations_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_integrations
    ADD CONSTRAINT tenant_integrations_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: tenant_sequences tenant_sequences_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_sequences
    ADD CONSTRAINT tenant_sequences_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: tenant_settings tenant_settings_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_settings
    ADD CONSTRAINT tenant_settings_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: tenants tenants_business_type_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenants
    ADD CONSTRAINT tenants_business_type_id_fkey FOREIGN KEY (business_type_id) REFERENCES public.business_types(id) ON DELETE SET NULL;


--
-- Name: units units_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.units
    ADD CONSTRAINT units_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: user_notifications user_notifications_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_notifications
    ADD CONSTRAINT user_notifications_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: user_notifications user_notifications_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_notifications
    ADD CONSTRAINT user_notifications_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: user_permissions user_permissions_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_permissions
    ADD CONSTRAINT user_permissions_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: user_permissions user_permissions_permission_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_permissions
    ADD CONSTRAINT user_permissions_permission_id_fkey FOREIGN KEY (permission_id) REFERENCES public.permissions(id) ON DELETE CASCADE;


--
-- Name: user_permissions user_permissions_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_permissions
    ADD CONSTRAINT user_permissions_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: user_permissions user_permissions_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_permissions
    ADD CONSTRAINT user_permissions_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: user_roles user_roles_role_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_roles
    ADD CONSTRAINT user_roles_role_id_fkey FOREIGN KEY (role_id) REFERENCES public.roles(id) ON DELETE CASCADE;


--
-- Name: user_roles user_roles_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_roles
    ADD CONSTRAINT user_roles_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: user_roles user_roles_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_roles
    ADD CONSTRAINT user_roles_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: users users_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: users users_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: waitlist_entries waitlist_entries_appointment_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.waitlist_entries
    ADD CONSTRAINT waitlist_entries_appointment_id_fkey FOREIGN KEY (appointment_id) REFERENCES public.appointments(id) ON DELETE SET NULL;


--
-- Name: waitlist_entries waitlist_entries_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.waitlist_entries
    ADD CONSTRAINT waitlist_entries_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE CASCADE;


--
-- Name: waitlist_entries waitlist_entries_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.waitlist_entries
    ADD CONSTRAINT waitlist_entries_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: waitlist_entries waitlist_entries_customer_dependent_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.waitlist_entries
    ADD CONSTRAINT waitlist_entries_customer_dependent_id_fkey FOREIGN KEY (customer_dependent_id) REFERENCES public.customer_dependents(id) ON DELETE SET NULL;


--
-- Name: waitlist_entries waitlist_entries_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.waitlist_entries
    ADD CONSTRAINT waitlist_entries_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE CASCADE;


--
-- Name: waitlist_entries waitlist_entries_professional_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.waitlist_entries
    ADD CONSTRAINT waitlist_entries_professional_id_fkey FOREIGN KEY (professional_id) REFERENCES public.professionals(id) ON DELETE SET NULL;


--
-- Name: waitlist_entries waitlist_entries_service_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.waitlist_entries
    ADD CONSTRAINT waitlist_entries_service_id_fkey FOREIGN KEY (service_id) REFERENCES public.services(id) ON DELETE CASCADE;


--
-- Name: waitlist_entries waitlist_entries_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.waitlist_entries
    ADD CONSTRAINT waitlist_entries_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: warehouses warehouses_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.warehouses
    ADD CONSTRAINT warehouses_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: warehouses warehouses_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.warehouses
    ADD CONSTRAINT warehouses_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
--

\unrestrict XEfBlEl6qF6XkmG9HufOzfXCBcMYVNQ1SpsLD6P9jb6rKC83gXJMiKneuGFnO9p

--
-- PostgreSQL database dump
--

\restrict CrzoII60UhYHB2b7O1zW1IUz3u4o9CueRQIsWrn0MSXGGOp54toBgHbtxI3XXcc

-- Dumped from database version 17.11
-- Dumped by pg_dump version 17.11

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.migrations (id, migration, batch) FROM stdin;
1	0001_01_01_000000_esquema_inicial	1
2	0001_01_01_000001_datos_esenciales	1
3	2026_10_21_000000_campanas	1
\.


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.migrations_id_seq', 3, true);


--
-- PostgreSQL database dump complete
--

\unrestrict CrzoII60UhYHB2b7O1zW1IUz3u4o9CueRQIsWrn0MSXGGOp54toBgHbtxI3XXcc

