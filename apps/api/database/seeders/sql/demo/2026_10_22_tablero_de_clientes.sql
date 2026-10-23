BEGIN;
SET client_encoding = 'UTF8';

DO $check$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM tenants WHERE id = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11') THEN
    RAISE EXCEPTION 'Tu base no tiene los datos de muestra base. Cárgalos primero con: php artisan db:seed';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'pipelines' AND column_name = 'tenant_id') THEN
    RAISE EXCEPTION 'Falta la migración del tablero de clientes. Ejecuta primero: php artisan migrate';
  END IF;
END
$check$;

DO $board$
DECLARE
  t record;
  board uuid;
  owner uuid;
  c record;
  target text;
  stage_id uuid;
  stage_name text;
BEGIN
  FOR t IN
    SELECT DISTINCT cu.tenant_id AS id FROM customers cu WHERE cu.email LIKE '%@example.com' AND cu.deleted_at IS NULL
  LOOP
    SELECT id INTO board FROM pipelines WHERE tenant_id = t.id;

    IF board IS NULL THEN
      board := gen_random_uuid();
      INSERT INTO pipelines (id, tenant_id, name) VALUES (board, t.id, 'Clientes');
      INSERT INTO pipeline_stages (pipeline_id, name, description, color, system_key, probability, stage_order) VALUES
        (board, 'Nuevo contacto', 'Registrado, aún sin cita', '#64748B', 'lead', 0, 1),
        (board, 'Contactado', 'Se le escribió o llamó', '#0EA5E9', 'contacted', 0, 2),
        (board, 'Primera cita agendada', 'Ya reservó, aún no viene', '#6366F1', 'booked', 0, 3),
        (board, 'Activo', 'Ya fue atendido y vuelve', '#10B981', 'active', 0, 4),
        (board, 'Fidelizado', 'Viene seguido o gasta más', '#A855F7', 'loyal', 0, 5),
        (board, 'Por recuperar', 'Hace tiempo que no viene y no tiene cita', '#F59E0B', 'recover', 0, 6),
        (board, 'Perdido', 'No desea volver', '#EF4444', 'lost', 0, 7);
    END IF;

    SELECT id INTO owner FROM users WHERE tenant_id = t.id ORDER BY is_owner DESC, created_at LIMIT 1;

    FOR c IN
      WITH base AS (
        SELECT cu.id, abs(hashtext(cu.id::text)) AS h,
          (SELECT count(*) FROM appointments a WHERE a.customer_id = cu.id AND a.status = 'completed') AS visits
        FROM customers cu
        WHERE cu.tenant_id = t.id AND cu.email LIKE '%@example.com' AND cu.deleted_at IS NULL AND cu.pipeline_stage_id IS NULL
          AND NOT EXISTS (SELECT 1 FROM customer_stage_changes h WHERE h.customer_id = cu.id)
      )
      SELECT id, h, row_number() OVER (ORDER BY visits, h) AS low, row_number() OVER (ORDER BY visits DESC, h) AS high FROM base
    LOOP
      target := CASE
        WHEN c.low <= 2 THEN 'lead'
        WHEN c.low <= 4 THEN 'contacted'
        WHEN c.low <= 6 THEN 'booked'
        WHEN c.high <= 3 THEN 'loyal'
        WHEN c.low = 7 THEN 'lost'
        WHEN c.h % 3 = 0 THEN 'recover'
        ELSE 'active'
      END;

      IF target = 'lead' THEN
        CONTINUE;
      END IF;

      SELECT s.id, s.name INTO stage_id, stage_name FROM pipeline_stages s WHERE s.pipeline_id = board AND s.system_key = target;

      IF stage_id IS NULL THEN
        CONTINUE;
      END IF;

      UPDATE customers SET pipeline_stage_id = stage_id WHERE id = c.id;
      INSERT INTO customer_stage_changes (tenant_id, customer_id, from_stage_id, from_name, to_stage_id, to_name, source, changed_by, created_at)
      SELECT t.id, c.id, s.id, s.name, stage_id, stage_name, 'board', owner, now() - ((c.h % 20 + 1) * interval '1 day')
      FROM pipeline_stages s WHERE s.pipeline_id = board AND s.system_key = 'lead';
    END LOOP;
  END LOOP;
END
$board$;

COMMIT;
