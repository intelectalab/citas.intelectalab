import { expect, test, type APIRequestContext, type Page } from '@playwright/test';
import { spawnSync } from 'node:child_process';
import { API, data, nextLocalDay } from './support';

type Person = { id: string; name: string; email: string; pet: { id: string; name: string } | null };
type Journey = {
  tenant: { id: string; slug: string; name: string; code: string };
  owner: { email: string };
  branch: { id: string; name: string };
  service: { id: string; name: string; price: string };
  customers: Person[];
  no_show_appointment?: string | null;
};
type Headers = Record<string, string>;

const journeys = (data as unknown as { journeys: Record<'clinic' | 'dental' | 'vet' | 'barber', Journey | null> }).journeys;

function need(key: 'clinic' | 'dental' | 'vet' | 'barber'): Journey {
  const j = journeys?.[key];
  test.skip(!j || !j.service || !j.customers.length, `La muestra no trae los datos del recorrido «${key}».`);

  return j!;
}

async function tokenFor(request: APIRequestContext, email: string): Promise<Headers> {
  const res = await request.post(`${API}/login`, { data: { email, password: data.password }, headers: { Accept: 'application/json' } });
  expect(res.ok(), await res.text()).toBeTruthy();

  return { Authorization: `Bearer ${(await res.json()).access_token}`, Accept: 'application/json' };
}

async function freeSlot(request: APIRequestContext, headers: Headers, serviceId: string, branchId: string, offset = 1) {
  for (let day = offset; day < offset + 10; day++) {
    const date = nextLocalDay(day);
    const res = await request.get(`${API}/appointments/availability`, { headers, params: { service_id: serviceId, branch_id: branchId, date } });
    expect(res.ok(), await res.text()).toBeTruthy();
    const options = (await res.json()).professionals.filter((p: { slots: string[] }) => p.slots.length);

    if (options.length) {
      const pick = options[options.length - 1];

      return { date, slot: pick.slots[pick.slots.length - 1] as string, professional_id: pick.professional.id as string };
    }
  }

  throw new Error('No hay horarios libres en los próximos diez días.');
}

async function book(request: APIRequestContext, headers: Headers, j: Journey, customerId: string, extra: Record<string, unknown> = {}, offset = 1, serviceId = j.service.id) {
  const free = await freeSlot(request, headers, serviceId, j.branch.id, offset);
  const res = await request.post(`${API}/appointments`, {
    headers,
    data: { customer_id: customerId, service_id: serviceId, professional_id: free.professional_id, branch_id: j.branch.id, starts_at: `${free.date}T${free.slot}`, ...extra },
  });
  expect(res.status(), await res.text()).toBe(201);

  return { ...(await res.json()), date: free.date, slot: free.slot } as { id: string; code: string; status: string; price: string | number; date: string; slot: string };
}

async function changeStatus(request: APIRequestContext, headers: Headers, id: string, body: Record<string, unknown>) {
  return request.post(`${API}/appointments/${id}/status`, { headers, data: body });
}

async function charge(request: APIRequestContext, headers: Headers, id: string, amount: number) {
  const res = await request.post(`${API}/appointments/${id}/charge`, { headers, data: { method: 'card', amount, reference: `OP-${Date.now()}` } });
  expect(res.status(), await res.text()).toBeLessThan(300);
}

async function advance(request: APIRequestContext, headers: Headers, appointment: { id: string; price: string | number }, to: string, expectOk = true) {
  let res = await changeStatus(request, headers, appointment.id, { status: to });

  if (res.status() === 422 && (await res.json()).code === 'PAYMENT_REQUIRED') {
    await charge(request, headers, appointment.id, Number(appointment.price));
    res = await changeStatus(request, headers, appointment.id, { status: to });
  }

  if (expectOk) {
    expect(res.status(), await res.text()).toBe(200);
  }

  return res;
}

async function startCare(request: APIRequestContext, headers: Headers, appointment: { id: string; status: string; price: string | number }) {
  if (appointment.status === 'pending') {
    await advance(request, headers, appointment, 'confirmed');
  }

  await advance(request, headers, appointment, 'in_progress');
}

function fillRecord(fields: { key: string; type: string; options?: string[] }[], controlDays = 30) {
  const values: Record<string, unknown> = {};

  for (const f of fields) {
    switch (f.type) {
      case 'number': values[f.key] = 1; break;
      case 'select': values[f.key] = f.options?.[0]; break;
      case 'multiselect': values[f.key] = f.options?.slice(0, 1) ?? []; break;
      case 'date': values[f.key] = nextLocalDay(0); break;
      case 'boolean': values[f.key] = true; break;
      case 'scale': values[f.key] = 5; break;
      case 'odontogram': values[f.key] = { '11': 'caries' }; break;
      case 'next_control': values[f.key] = { date: nextLocalDay(controlDays) }; break;
      default: values[f.key] = 'Dato escrito en el recorrido automático';
    }
  }

  return values;
}

async function signRecord(request: APIRequestContext, headers: Headers, appointmentId: string, controlDays = 30) {
  const form = await request.get(`${API}/appointments/${appointmentId}/record`, { headers });
  expect(form.ok(), await form.text()).toBeTruthy();
  const body = await form.json();
  expect(body.uses_record).toBeTruthy();

  const saved = await request.put(`${API}/appointments/${appointmentId}/record`, {
    headers,
    data: { data: fillRecord(body.template?.fields ?? [], controlDays), sign: true, consent_accepted: true },
  });
  expect(saved.status(), await saved.text()).toBeLessThan(300);
}

async function finish(request: APIRequestContext, headers: Headers, appointment: { id: string; price: string | number }, controlDays = 30) {
  const res = await advance(request, headers, appointment, 'completed', false);

  if (res.status() === 409 && (await res.json()).code === 'RECORD_REQUIRED') {
    await signRecord(request, headers, appointment.id, controlDays);
    await advance(request, headers, appointment, 'completed');
    return;
  }

  expect(res.status(), await res.text()).toBe(200);
}

async function recallFrom(request: APIRequestContext, headers: Headers, customerId: string, code: string, view = 'open') {
  const res = await request.get(`${API}/recalls`, { headers, params: { customer_id: customerId, view } });
  expect(res.ok(), await res.text()).toBeTruthy();

  return (await res.json()).find((r: { source_code: string | null }) => r.source_code === code) as { id: string; label: string; status: string; due_date: string; service?: { id: string } | null } | undefined;
}

async function loginAs(page: Page, email: string) {
  await page.goto('/login');
  await page.getByPlaceholder('usuario@empresa.com').fill(email);
  await page.locator('input[type=password]').fill(data.password);
  await page.getByRole('button', { name: 'Ingresar al sistema' }).click();
  await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}

async function seeOnCustomer(page: Page, customerId: string, appointmentId: string, text?: RegExp) {
  await page.goto(`/customers/${customerId}`);
  const row = page.locator(`[data-appointment="${appointmentId}"]`).first();
  const card = page.locator('[data-card="appointments"]');
  await expect(card.locator('[data-list]').first()).toBeVisible();

  for (let i = 0; i < 30 && !(await row.isVisible()); i++) {
    const next = card.locator('[data-page="next"]:not([disabled])');
    if (!(await next.count())) break;
    for (const button of await next.all()) await button.click();
  }

  await expect(row).toBeVisible();

  if (text) {
    await expect(page.locator('li').filter({ has: row })).toContainText(text);
  }
}

async function messagesOf(request: APIRequestContext, headers: Headers, customerId: string) {
  const res = await request.get(`${API}/messages`, { headers, params: { customer_id: customerId } });
  expect(res.ok(), await res.text()).toBeTruthy();

  return (await res.json()).messages as { kind: string; subject: string | null }[];
}

test('9. Clínica: cita, ficha firmada obligatoria, control generado y su reserva', async ({ page, request }) => {
  const j = need('clinic');
  const headers = await tokenFor(request, j.owner.email);
  const patient = j.customers[0];

  const cita = await book(request, headers, j, patient.id);
  await startCare(request, headers, cita);

  const blocked = await advance(request, headers, cita, 'completed', false);
  expect(blocked.status()).toBe(409);
  expect((await blocked.json()).code).toBe('RECORD_REQUIRED');

  await signRecord(request, headers, cita.id);
  await advance(request, headers, cita, 'completed');

  const control = await recallFrom(request, headers, patient.id, cita.code);
  expect(control, 'Al terminar la consulta debe quedar un control por agendar').toBeTruthy();
  expect(control!.label).toMatch(/control/i);

  const reserva = await book(request, headers, j, patient.id, { recall_id: control!.id }, 2, control!.service?.id ?? j.service.id);
  const agendado = await recallFrom(request, headers, patient.id, cita.code, 'booked');
  expect(agendado?.status).toBe('booked');

  await loginAs(page, j.owner.email);
  await seeOnCustomer(page, patient.id, cita.id, /atendid/i);
  await seeOnCustomer(page, patient.id, reserva.id);
});

test('10. Dental: presupuesto aceptado, plan de sesiones y sesiones consumidas', async ({ page, request }) => {
  const j = need('dental');
  const headers = await tokenFor(request, j.owner.email);
  const patient = j.customers[0];
  const title = `Ortodoncia recorrido ${Date.now()}`;

  const created = await request.post(`${API}/quotes`, {
    headers,
    data: {
      customer_id: patient.id, branch_id: j.branch.id, title,
      items: [{ item_type: 'service', reference_id: j.service.id, description: j.service.name, quantity: 3, unit_price: Number(j.service.price), sessions_total: 3 }],
    },
  });
  expect(created.status(), await created.text()).toBeLessThan(300);

  const find = async () => {
    const res = await request.get(`${API}/quotes`, { headers });
    expect(res.ok()).toBeTruthy();

    return (await res.json()).quotes.find((q: { title: string }) => q.title === title);
  };

  const quote = await find();
  expect(quote.status).toBe('draft');

  for (const status of ['sent', 'accepted']) {
    const res = await request.post(`${API}/quotes/${quote.id}/status`, { headers, data: { status } });
    expect(res.status(), await res.text()).toBe(200);
  }

  const accepted = await find();
  expect(accepted.status).toBe('accepted');
  expect(accepted.plan?.id, 'Al aceptar debe nacer el plan de sesiones').toBeTruthy();

  const cita = await book(request, headers, j, patient.id);
  const linked = await request.post(`${API}/appointments/${cita.id}/package`, { headers, data: { customer_package_id: accepted.plan.id } });
  expect(linked.status(), await linked.text()).toBeLessThan(300);
  await startCare(request, headers, { ...cita, price: 0 });
  await finish(request, headers, { ...cita, price: 0 });

  const item = accepted.items[0];
  let current = (await find()).items[0];

  while (current.sessions_used < current.sessions_total) {
    const res = await request.post(`${API}/quotes/${quote.id}/items/${item.id}/sessions`, { headers, data: { note: 'Sesión registrada en el recorrido' } });
    expect(res.status(), await res.text()).toBe(200);
    current = (await find()).items[0];
  }

  const extra = await request.post(`${API}/quotes/${quote.id}/items/${item.id}/sessions`, { headers, data: { note: 'Una de más' } });
  expect(extra.status()).toBe(422);
  expect((await find()).plan.status).toBe('consumed');

  await loginAs(page, j.owner.email);
  await page.goto('/quotes');
  await expect(page.locator('main')).toContainText(quote.code);
});

test('11. Veterinaria: tutor con su mascota, cita, ficha y refuerzo de vacuna', async ({ page, request }) => {
  const j = need('vet');
  const tutor = j.customers[0];
  test.skip(!tutor.pet, 'La muestra no trae una mascota para el tutor.');
  const headers = await tokenFor(request, j.owner.email);

  const cita = await book(request, headers, j, tutor.id, { customer_dependent_id: tutor.pet!.id });
  await startCare(request, headers, cita);
  await signRecord(request, headers, cita.id, 365);
  await advance(request, headers, cita, 'completed');

  const refuerzo = await recallFrom(request, headers, tutor.id, cita.code);
  expect(refuerzo, 'Después de vacunar debe quedar el refuerzo por agendar').toBeTruthy();
  expect(refuerzo!.label).toMatch(/refuerzo/i);
  expect(refuerzo!.due_date > nextLocalDay(300)).toBeTruthy();

  await loginAs(page, j.owner.email);
  await seeOnCustomer(page, tutor.id, cita.id, new RegExp(tutor.pet!.name));
});

test('12. Barbería: kiosko, cola, atención y cobro', async ({ page, request }, info) => {
  const j = need('barber');
  const phone = `9${String(Date.now()).slice(-6)}${info.project.name === 'celular' ? '12' : '01'}`;

  const queued = await request.post(`${API}/kiosk/${j.tenant.code}/queue`, {
    headers: { Accept: 'application/json' },
    data: { phone, name: `Kiosko ${info.project.name}`, service_id: j.service.id, branch_id: j.branch.id },
  });
  const queuedBody = await queued.json();
  test.skip(queued.status() === 409 && queuedBody.code === 'WAIT_TOO_LONG', 'A esta hora el local ya no recibe gente sin cita.');
  expect(queued.status(), JSON.stringify(queuedBody)).toBe(201);

  const headers = await tokenFor(request, j.owner.email);
  const board = await request.get(`${API}/walkins`, { headers });
  expect(board.ok()).toBeTruthy();
  const entry = (await board.json()).entries.find((e: { ticket: string }) => e.ticket === queuedBody.ticket);
  expect(entry, `El turno ${queuedBody.ticket} debe aparecer en la cola`).toBeTruthy();

  const options = await (await request.get(`${API}/walkins/${entry.id}/options`, { headers })).json();
  const pro = options.candidates.find((c: { available: boolean }) => c.available) ?? options.candidates[0];
  const served = await request.post(`${API}/walkins/${entry.id}/serve`, {
    headers,
    data: { professional_id: pro.id, ...(pro.available ? {} : { override_reason: 'Recorrido automático fuera de horario' }) },
  });
  expect(served.status(), await served.text()).toBe(200);
  const appointment = (await served.json()).appointment;

  await charge(request, headers, appointment.id, Number(j.service.price));
  await finish(request, headers, { id: appointment.id, price: 0 });

  const history = await request.get(`${API}/walkins`, { headers, params: { view: 'history' } });
  const all = [...(await history.json()).entries, ...(await (await request.get(`${API}/walkins`, { headers })).json()).entries];
  expect(all.find((e: { id: string }) => e.id === entry.id)?.status).toBe('done');

  await page.goto(`/kiosko/${j.tenant.code}`);
  await expect(page.getByText(j.tenant.name).first()).toBeVisible();

  await loginAs(page, j.owner.email);
  await seeOnCustomer(page, entry.customer?.id ?? entry.customer_id, appointment.id, /atendid/i);
});

test('13. Cancelación tardía con penalidad y aviso a la lista de espera', async ({ page, request }) => {
  const j = need('barber');
  test.skip(j.customers.length < 2, 'Faltan clientes en la muestra.');
  const headers = await tokenFor(request, j.owner.email);
  const [quien, espera] = j.customers;

  const cita = await book(request, headers, j, quien.id);
  const anotada = await request.post(`${API}/waitlist`, { headers, data: { customer_id: espera.id, service_id: j.service.id, branch_id: j.branch.id, date_from: cita.date } });
  expect([201, 409, 422]).toContain(anotada.status());
  const antes = (await messagesOf(request, headers, espera.id)).filter((m) => m.kind === 'waitlist_slot').length;

  const cancel = await changeStatus(request, headers, cita.id, { status: 'cancelled', reason: 'Le salió un viaje', by_customer: true });
  expect(cancel.status(), await cancel.text()).toBe(200);
  const body = await cancel.json();

  expect(body.late_cancel).toBe(true);
  expect(Number(body.penalty?.total)).toBeCloseTo(Number(j.service.price) * 0.25, 2);
  expect(body.waitlist_matches).toBeGreaterThan(0);
  expect((await messagesOf(request, headers, espera.id)).filter((m) => m.kind === 'waitlist_slot').length).toBeGreaterThan(antes);

  await loginAs(page, j.owner.email);
  await seeOnCustomer(page, quien.id, cita.id, /cancelad/i);
});

test('14. Inasistencia con penalidad y mensaje automático de recuperación', async ({ page, request }, info) => {
  const j = need('barber');
  test.skip(info.project.name === 'celular', 'La cita pasada de la muestra se usa una sola vez (en escritorio).');
  test.skip(!j.no_show_appointment, 'La muestra no pudo crear la cita pasada.');
  test.skip(!process.env.E2E_API_DIR, 'Hace falta correr con node run.mjs para enviar los mensajes programados.');
  const headers = await tokenFor(request, j.owner.email);
  const quien = j.customers[2];

  const res = await changeStatus(request, headers, j.no_show_appointment!, { status: 'no_show' });
  expect(res.status(), await res.text()).toBe(200);
  expect(Number((await res.json()).penalty?.total)).toBeCloseTo(Number(j.service.price) * 0.5, 2);

  const sent = spawnSync(process.env.E2E_PHP || 'php', ['artisan', 'messages:send'], { cwd: process.env.E2E_API_DIR, env: process.env, encoding: 'utf8' });
  expect(sent.status, `${sent.stdout}\n${sent.stderr}`).toBe(0);

  const automaticos = (await messagesOf(request, headers, quien.id)).filter((m) => m.kind === 'automation');
  expect(automaticos.length).toBeGreaterThan(0);

  await loginAs(page, j.owner.email);
  await seeOnCustomer(page, quien.id, j.no_show_appointment!, /no asisti/i);
});

test('15. Caja: apertura, venta, arqueo con diferencia y aprobación', async ({ page, request }) => {
  const j = need('clinic');
  const headers = await tokenFor(request, j.owner.email);

  const board = await (await request.get(`${API}/cash`, { headers })).json();
  let register = board.registers.find((r: { branch_id: string; status: string }) => r.branch_id === j.branch.id && r.status === 'active');

  if (!register) {
    const made = await request.post(`${API}/cash/registers`, { headers, data: { branch_id: j.branch.id, name: 'Caja del recorrido' } });
    expect(made.status(), await made.text()).toBe(201);
    register = { id: (await made.json()).id, session: null };
  }

  let sessionId = register.session?.id as string | undefined;

  if (!sessionId) {
    const opened = await request.post(`${API}/cash/registers/${register.id}/open`, { headers, data: { opening_amount: 100 } });
    expect(opened.status(), await opened.text()).toBe(201);
    sessionId = (await opened.json()).id;
  }

  const sale = await request.post(`${API}/pos/sales`, { headers, data: { branch_id: j.branch.id, items: [{ type: 'service', id: j.service.id }] } });
  expect(sale.status(), await sale.text()).toBe(201);
  const venta = (await sale.json()).sale;
  const paid = await request.post(`${API}/sales/${venta.id}/pay`, { headers, data: { method: 'cash', amount: venta.total, received: venta.total } });
  expect(paid.status(), await paid.text()).toBe(200);

  const expected = (await (await request.get(`${API}/cash/sessions/${sessionId}`, { headers })).json()).expected_amount as number;
  const sinMotivo = await request.post(`${API}/cash/sessions/${sessionId}/close`, { headers, data: { counted_amount: expected - 5 } });
  expect(sinMotivo.status()).toBe(422);

  const closed = await request.post(`${API}/cash/sessions/${sessionId}/close`, { headers, data: { counted_amount: expected - 5, notes: 'Se dio mal un vuelto' } });
  expect(closed.status(), await closed.text()).toBe(200);
  const cierre = await closed.json();
  expect(Number(cierre.difference)).toBeCloseTo(-5, 2);
  expect(cierre.review_status).toBe('pending');

  const reviewed = await request.post(`${API}/cash/sessions/${sessionId}/review`, { headers, data: { decision: 'approved', notes: 'Se conversó con la cajera' } });
  expect(reviewed.status(), await reviewed.text()).toBe(200);
  expect((await (await request.get(`${API}/cash/sessions/${sessionId}`, { headers })).json()).review_status).toBe('approved');

  await loginAs(page, j.owner.email);
  await page.goto('/cash');
  await expect(page.locator('main')).toContainText(/Aprobado/);
});
