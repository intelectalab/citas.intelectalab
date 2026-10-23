BEGIN;
SET client_encoding = 'UTF8';

DO $check$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM tenants WHERE id = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11') THEN
    RAISE EXCEPTION 'Tu base no tiene los datos de muestra base. Cárgalos primero con: php artisan db:seed';
  END IF;
END
$check$;

INSERT INTO customer_consents (tenant_id, customer_id, channel, granted, source, granted_at)
SELECT c.tenant_id, c.id, 'email', true, 'staff', now()
FROM customers c
WHERE c.tenant_id = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11'
  AND c.email LIKE '%@example.com'
  AND right(c.code, 1)::int % 4 <> 0
ON CONFLICT (customer_id, channel) DO NOTHING;

DO $campaigns$
DECLARE
  t uuid := 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';
  owner uuid := 'd0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';
  company text;
  site text := rtrim(coalesce(nullif(current_setting('app.frontend_url', true), ''), 'http://localhost:3000'), '/');
  portal text := site || '/login';
  control uuid;
  coupon uuid;
  camp record;
  cust record;
  rid uuid;
  mid uuid;
  sent timestamptz;
  n_all int;
  n_ok int;
  n_skip int;
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'marketing_campaign_recipients' AND column_name = 'message_id') THEN
    RAISE EXCEPTION 'Falta actualizar la base: ejecuta primero php artisan migrate.';
  END IF;

  IF EXISTS (SELECT 1 FROM marketing_campaigns WHERE id = '6aeebc99-9c0b-4ef8-bb6d-6bb9bd380a01') THEN
    RETURN;
  END IF;

  SELECT coalesce(trade_name, legal_name) INTO company FROM companies WHERE tenant_id = t ORDER BY created_at LIMIT 1;
  company := coalesce(company, (SELECT name FROM tenants WHERE id = t));
  SELECT id INTO control FROM services WHERE tenant_id = t AND name = 'Control' LIMIT 1;
  SELECT id INTO coupon FROM coupons WHERE tenant_id = t AND code = 'VUELVE20' LIMIT 1;

  UPDATE appointments SET created_at = date_trunc('day', now()) - interval '13 days' + interval '15 hours'
  WHERE tenant_id = t AND created_at >= date_trunc('day', now()) - interval '12 days' AND created_at <= now();

  FOR camp IN
    SELECT * FROM (VALUES
      ('6aeebc99-9c0b-4ef8-bb6d-6bb9bd380a01'::uuid, 'Chequeo preventivo de octubre', 'all', NULL::uuid, 'Todos los clientes', NULL::uuid, 12,
       '[Nombre], es momento de tu chequeo anual',
       E'Hola [Nombre]:\n\nCuidarte a tiempo es la mejor forma de prevenir. Este mes tenemos horarios disponibles para tu chequeo preventivo anual.\n\nReserva tu cita en nuestro portal: [Portal]\n\n[Empresa]'),
      ('6aeebc99-9c0b-4ef8-bb6d-6bb9bd380a02'::uuid, 'Vuelve a tu control', 'service', control, 'Que se hicieron un servicio: Control', coupon, 5,
       '[Nombre], te extrañamos',
       E'Hola [Nombre]:\n\nHace tiempo que no te vemos y ya te toca tu control. Reserva en nuestro portal: [Portal]\n\nCon el cupón [Cupón] tienes S/ 20 de descuento.\n\n[Empresa]')
    ) AS v(id, name, grp, service_id, label, coupon_id, days_ago, subject, body)
  LOOP
    sent := date_trunc('day', now()) - make_interval(days => camp.days_ago) + interval '15 hours';

    INSERT INTO marketing_campaigns (id, tenant_id, name, channel, coupon_id, subject, body, segment, audience_label, status, scheduled_at, sent_at, stats, created_by, created_at, updated_at)
    VALUES (camp.id, t, camp.name, 'email', camp.coupon_id, camp.subject, camp.body,
            CASE WHEN camp.grp = 'service' THEN jsonb_build_object('group', 'service', 'service_id', camp.service_id) ELSE jsonb_build_object('group', camp.grp) END,
            camp.label, 'sent', sent, sent, '{}'::jsonb, owner, sent - interval '1 hour', sent)
    ON CONFLICT (id) DO NOTHING;

    n_all := 0; n_ok := 0; n_skip := 0;

    FOR cust IN
      SELECT c.id, c.first_name, c.last_name, c.email, coalesce(cc.granted, false) AS granted
      FROM customers c
      LEFT JOIN customer_consents cc ON cc.customer_id = c.id AND cc.channel = 'email'
      WHERE c.tenant_id = t AND c.deleted_at IS NULL
        AND (camp.grp = 'all' OR EXISTS (SELECT 1 FROM appointments a WHERE a.customer_id = c.id AND a.status = 'completed' AND a.service_id = camp.service_id))
      ORDER BY c.code
    LOOP
      n_all := n_all + 1;
      rid := gen_random_uuid();

      IF cust.granted AND cust.email IS NOT NULL THEN
        n_ok := n_ok + 1;
        mid := gen_random_uuid();
        INSERT INTO messages (id, tenant_id, customer_id, channel, kind, status, to_address, to_name, subject, body, sent_at, attempts, provider, entity_type, entity_id, dedupe_key, created_by, created_at, updated_at)
        VALUES (mid, t, cust.id, 'email', 'campaign', 'simulated', cust.email, cust.first_name || ' ' || cust.last_name,
                replace(camp.subject, '[Nombre]', cust.first_name),
                replace(replace(replace(replace(camp.body, '[Nombre]', cust.first_name), '[Empresa]', company), '[Portal]', portal), '[Cupón]', 'VUELVE20')
                  || E'\n\n—\nSi no quieres recibir más promociones de ' || company || ', entra aquí: ' || site || '/promociones/baja/' || rid,
                sent + make_interval(mins => n_ok), 1, 'simulated', 'campaign', camp.id, 'campaign:' || camp.id || ':' || cust.id, owner, sent, sent)
        ON CONFLICT DO NOTHING;
        INSERT INTO marketing_campaign_recipients (id, tenant_id, marketing_campaign_id, customer_id, status, message_id)
        VALUES (rid, t, camp.id, cust.id, 'pending', mid) ON CONFLICT DO NOTHING;
      ELSE
        n_skip := n_skip + 1;
        INSERT INTO marketing_campaign_recipients (id, tenant_id, marketing_campaign_id, customer_id, status, skip_reason)
        VALUES (rid, t, camp.id, cust.id, 'skipped', CASE WHEN cust.email IS NULL THEN 'No tiene un correo registrado.' ELSE 'No aceptó recibir promociones por correo.' END)
        ON CONFLICT DO NOTHING;
      END IF;
    END LOOP;

    UPDATE marketing_campaigns
    SET stats = jsonb_build_object('audience', n_all, 'eligible', n_ok, 'no_consent', n_skip, 'no_email', 0)
    WHERE id = camp.id;

    UPDATE appointments a
    SET created_at = sent + interval '1 day' + make_interval(hours => (x.rn * 5)::int)
    FROM (
      SELECT d.id, row_number() OVER (ORDER BY d.customer_id) AS rn
      FROM (
        SELECT DISTINCT ON (a2.customer_id) a2.id, a2.customer_id
        FROM appointments a2
        JOIN marketing_campaign_recipients r ON r.customer_id = a2.customer_id AND r.marketing_campaign_id = camp.id AND r.status = 'pending'
        WHERE a2.tenant_id = t AND a2.starts_at > now() AND a2.status IN ('pending', 'confirmed')
        ORDER BY a2.customer_id, a2.starts_at
      ) d
    ) x
    WHERE a.id = x.id AND x.rn <= CASE WHEN camp.grp = 'all' THEN 4 ELSE 2 END;
  END LOOP;

  INSERT INTO marketing_campaigns (id, tenant_id, name, channel, subject, body, segment, audience_label, status, scheduled_at, stats, created_by, created_at, updated_at)
  VALUES ('6aeebc99-9c0b-4ef8-bb6d-6bb9bd380a03', t, 'Te extrañamos: clientes inactivos', 'email',
          '[Nombre], te extrañamos',
          E'Hola [Nombre]:\n\nHace tiempo que no te vemos. Cuando quieras, reserva tu próxima cita en nuestro portal: [Portal]\n\n[Empresa]',
          jsonb_build_object('group', 'inactive'), 'Inactivos', 'scheduled',
          date_trunc('day', now()) + interval '3 days' + interval '14 hours', '{}'::jsonb, owner, now(), now())
  ON CONFLICT (id) DO NOTHING;
END
$campaigns$;

COMMIT;
