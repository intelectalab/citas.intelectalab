DO $walkin_demo$
DECLARE
  v_tenant uuid := 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a88';
  v_branch uuid := 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380d81';
  v_owner  uuid := 'd0eebc99-9c0b-4ef8-bb6d-6bb9bd380a88';
  v_kevin  uuid := '40eebc99-9c0b-4ef8-bb6d-6bb9bd380b81';
  v_piero  uuid := '40eebc99-9c0b-4ef8-bb6d-6bb9bd380b82';
  v_c1     uuid;
  v_c2     uuid;
  v_c3     uuid;
  v_c4     uuid;
  v_s_fade uuid;
  v_s_barb uuid;
  v_s_comb uuid;
  v_s_clas uuid;
BEGIN
  IF NOT EXISTS (SELECT 1 FROM tenants WHERE id = v_tenant) THEN
    RETURN;
  END IF;

  IF NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'walkin_entries') THEN
    RETURN;
  END IF;

  IF EXISTS (SELECT 1 FROM walkin_entries WHERE tenant_id = v_tenant) THEN
    RETURN;
  END IF;

  SELECT id INTO v_c1 FROM customers WHERE tenant_id = v_tenant AND email = 'bruno.caceres@example.com' LIMIT 1;
  SELECT id INTO v_c2 FROM customers WHERE tenant_id = v_tenant AND email = 'martin.ore@example.com' LIMIT 1;
  SELECT id INTO v_c3 FROM customers WHERE tenant_id = v_tenant AND email = 'joaquin.villena@example.com' LIMIT 1;
  SELECT id INTO v_c4 FROM customers WHERE tenant_id = v_tenant AND email = 'renzo.altamirano@example.com' LIMIT 1;

  SELECT id INTO v_s_fade FROM services WHERE tenant_id = v_tenant AND name ILIKE 'Corte fade%' LIMIT 1;
  SELECT id INTO v_s_barb FROM services WHERE tenant_id = v_tenant AND name ILIKE 'Perfilado de barba%' LIMIT 1;
  SELECT id INTO v_s_comb FROM services WHERE tenant_id = v_tenant AND name ILIKE 'Corte + barba%' LIMIT 1;
  SELECT id INTO v_s_clas FROM services WHERE tenant_id = v_tenant AND name ILIKE 'Diseño y líneas%' LIMIT 1;

  IF v_s_fade IS NULL THEN
    SELECT id INTO v_s_fade FROM services WHERE tenant_id = v_tenant AND kind = 'individual' ORDER BY created_at LIMIT 1;
  END IF;
  IF v_s_barb IS NULL THEN v_s_barb := v_s_fade; END IF;
  IF v_s_comb IS NULL THEN v_s_comb := v_s_fade; END IF;
  IF v_s_clas IS NULL THEN v_s_clas := v_s_fade; END IF;

  IF v_c2 IS NOT NULL AND v_s_comb IS NOT NULL THEN
    INSERT INTO walkin_entries (
      tenant_id, branch_id, customer_id, service_id, professional_id,
      ticket, status, source, notes, waited_minutes, arrived_at, left_at, created_by
    ) VALUES (
      v_tenant, v_branch, v_c2, v_s_comb, NULL,
      'T-001', 'left', 'kiosk', 'Tenía que volver al trabajo.', 25,
      now() - interval '40 minutes', now() - interval '15 minutes', NULL
    );
  END IF;

  IF v_c3 IS NOT NULL AND v_s_fade IS NOT NULL THEN
    INSERT INTO walkin_entries (
      tenant_id, branch_id, customer_id, service_id, professional_id,
      ticket, status, source, notes, arrived_at, created_by
    ) VALUES (
      v_tenant, v_branch, v_c3, v_s_fade, v_piero,
      'T-002', 'waiting', 'kiosk', 'Quiere atenderse con Piero.',
      now() - interval '14 minutes', NULL
    );
  END IF;

  IF v_c4 IS NOT NULL AND v_s_barb IS NOT NULL THEN
    INSERT INTO walkin_entries (
      tenant_id, branch_id, customer_id, service_id, professional_id,
      ticket, status, source, notes, arrived_at, created_by
    ) VALUES (
      v_tenant, v_branch, v_c4, v_s_barb, NULL,
      'T-003', 'waiting', 'staff', NULL,
      now() - interval '6 minutes', v_owner
    );
  END IF;
END
$walkin_demo$;
