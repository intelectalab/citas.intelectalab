import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { API, apiToken, data, login, nextLocalDay } from './support';

const fullName = `${data.customer.first_name} ${data.customer.last_name}`;

function agendaCustomer(info: TestInfo) {
  return data.agenda_customers[info.project.name === 'celular' ? 1 : 0];
}

async function showWholeTeam(page: Page) {
  const list = page.getByRole('button', { name: 'Lista', exact: true }).or(page.getByRole('radio', { name: 'Lista', exact: true }));

  if ((page.viewportSize()?.width ?? 1440) < 768) {
    await page.getByRole('radio', { name: 'Lista', exact: true }).click();
  } else if (await list.first().isVisible().catch(() => false)) {
    await list.first().click();
  }
}

async function bookByApi(request: import('@playwright/test').APIRequestContext, offset = 1, customerId = data.customer_id) {
  const token = await apiToken(request, 'owner');
  const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' };
  let date = '';
  let ahead = offset;
  let options: { professional: { id: string }; slots: string[] }[] = [];

  for (let day = offset; day < offset + 10 && !options.length; day++) {
    date = nextLocalDay(day);
    ahead = day;
    const free = await request.get(`${API}/appointments/availability`, { headers, params: { service_id: data.service.id, branch_id: data.branch.id, date } });
    expect(free.ok()).toBeTruthy();
    options = (await free.json()).professionals.filter((p: { slots: string[] }) => p.slots.length);
  }

  expect(options.length).toBeGreaterThan(0);
  const pick = options[options.length - 1];
  const slot = pick.slots[pick.slots.length - 1];
  const created = await request.post(`${API}/appointments`, {
    headers,
    data: { customer_id: customerId, service_id: data.service.id, professional_id: pick.professional.id, branch_id: data.branch.id, starts_at: `${date}T${slot}` },
  });
  expect(created.status(), await created.text()).toBe(201);

  return { slot, date, ahead, id: (await created.json()).id as string };
}

test('1. Entrar al sistema y ver el inicio de la empresa', async ({ page }) => {
  await page.goto('/login');
  await page.getByPlaceholder('usuario@empresa.com').fill(data.owner.email);
  await page.locator('input[type=password]').fill('no-es-la-clave');
  await page.getByRole('button', { name: 'Ingresar al sistema' }).click();
  await expect(page.getByText(/credenciales incorrectas/i)).toBeVisible();
  await expect(page).toHaveURL(/\/login/);

  await login(page, 'owner');
  await expect(page).toHaveURL(/\/home/);
  await expect(page.getByRole('heading', { level: 1, name: new RegExp(data.owner.name) })).toBeVisible();
  await expect(page.locator('main').getByText(data.tenant.name).first()).toBeVisible();
});

test('2. Agendar una cita desde la agenda', async ({ page }, info) => {
  const who = agendaCustomer(info);
  await login(page, 'owner');
  await page.goto('/agenda');
  await page.getByRole('button', { name: 'Día siguiente' }).click();
  await page.getByRole('button', { name: 'Nueva cita' }).click();

  await page.getByRole('textbox', { name: 'Buscar cliente' }).fill(who.name);
  await page.getByRole('button', { name: new RegExp(`^${who.name}`) }).click();
  await page.getByRole('combobox', { name: 'Servicio *' }).click();
  await page.getByRole('option', { name: /^Corte de dama/ }).click();

  const slot = page.locator('form').last().getByRole('button', { name: /^\d{2}:\d{2}$/ }).first();
  await expect(slot).toBeVisible();
  const time = (await slot.textContent())!.trim();
  await slot.click();
  await expect(page.getByRole('button', { name: 'Hora de la cita' })).toHaveText(time);

  const saved = page.waitForResponse((r) => r.url().endsWith('/api/appointments') && r.request().method() === 'POST');
  await page.getByRole('button', { name: 'Agendar cita' }).click();
  expect((await saved).status()).toBe(201);

  await showWholeTeam(page);
  await expect(page.getByRole('button', { name: new RegExp(`^${time} ${who.name} Corte de dama`) }).first()).toBeVisible();
});

test('3. Abrir la caja y cobrar una cita en efectivo', async ({ page, request }, info) => {
  const who = agendaCustomer(info);
  const { slot, ahead } = await bookByApi(request, 2, who.id);

  await login(page, 'owner');
  await page.goto('/cash');
  const card = page.getByRole('article').filter({ hasText: 'Caja principal' });

  await expect(card).toBeVisible();

  if (await card.getByText('Cerrada').isVisible()) {
    await card.getByRole('button', { name: 'Abrir caja' }).click();
    await page.getByRole('textbox', { name: 'Efectivo con que se abre' }).fill('100');
    const opened = page.waitForResponse((r) => /\/api\/cash/.test(r.url()) && r.request().method() === 'POST');
    await page.locator('button[form="open-cash-form"]').click();
    expect((await opened).status()).toBeLessThan(300);
  }
  await expect(card.getByText('Cerrada')).toHaveCount(0);

  await page.goto('/agenda');
  for (let i = 0; i < ahead; i++) {
    await page.getByRole('button', { name: 'Día siguiente' }).click();
  }
  await showWholeTeam(page);
  await page.getByRole('button', { name: new RegExp(`^${slot} ${who.name} Corte de dama`) }).first().click();
  await page.getByRole('button', { name: /^Cobrar.*por cobrar/ }).click();

  await expect(page.getByRole('radio', { name: 'Efectivo' })).toBeChecked();
  await expect(page.getByRole('textbox', { name: 'Monto a cobrar *' })).toHaveValue(Number(data.service.price).toFixed(2));
  const paid = page.waitForResponse((r) => r.request().method() === 'POST' && /\/api\/(sales|appointments)\//.test(r.url()) && r.status() < 300);
  await page.locator('button[data-pay="true"]').click();
  await paid;

  await expect(page.getByRole('button', { name: /^Cobrar.*por cobrar/ })).toHaveCount(0);
  await expect(page.getByText(/pagad|cobrad/i).first()).toBeVisible();
});

test('4. Reserva pública desde la página de la empresa', async ({ page }) => {
  const stamp = Date.now().toString().slice(-7);
  await page.goto(`/reservar/${data.tenant.slug}`);
  await expect(page.getByRole('heading', { level: 1, name: data.tenant.name })).toBeVisible();

  await page.getByRole('button', { name: /^Corte de caballero/ }).click();
  await page.getByRole('button', { name: 'Continuar' }).click();
  await expect(page.getByRole('heading', { name: '¿Con quién?' })).toBeVisible();
  await page.getByRole('button', { name: 'Continuar' }).click();

  await expect(page.getByRole('heading', { name: '¿Cuándo?' })).toBeVisible();
  const days = page.getByRole('button', { name: /^(lun|mar|mié|jue|vie|sáb|dom) \d+/ });
  const slot = page.locator('main').getByRole('button', { name: /^\d{2}:\d{2}$/ }).first();

  for (let i = 1; i < 7; i++) {
    const dd = ((await days.nth(i).textContent()) ?? '').match(/\d+/)![0].padStart(2, '0');
    const loaded = page.waitForResponse((r) => r.url().includes(`/public/booking/${data.tenant.slug}/availability`) && new RegExp(`date=\\d{4}-\\d{2}-${dd}`).test(r.url()));
    await days.nth(i).click();
    await loaded;
    await expect(page.getByText('Buscando horarios...')).toHaveCount(0);
    if (await slot.isVisible()) break;
  }

  await expect(slot).toBeVisible();
  const time = (await slot.textContent())!.trim();
  await slot.click();
  await page.getByRole('button', { name: 'Continuar' }).click();

  await expect(page.getByRole('heading', { name: 'Tus datos' })).toBeVisible();
  await page.getByRole('textbox', { name: 'Nombres' }).fill('Recorrido');
  await page.getByRole('textbox', { name: 'Apellidos' }).fill(`Público ${stamp}`);
  await page.getByRole('combobox', { name: 'Documento' }).click();
  await page.getByRole('option', { name: /DNI/ }).first().click();
  await page.getByRole('textbox', { name: 'Número de documento' }).fill(`7${stamp}`);
  await page.getByRole('textbox', { name: 'Celular' }).fill(`98${stamp}`);
  await page.getByRole('textbox', { name: 'Correo' }).fill(`recorrido.${stamp}@example.com`);
  await page.getByRole('switch', { name: 'Acepto la política de privacidad' }).click();
  await page.getByRole('button', { name: 'Continuar' }).click();

  await expect(page.getByRole('heading', { name: 'Revisa y confirma' })).toBeVisible();
  await expect(page.getByRole('definition').filter({ hasText: time })).toBeVisible();
  const booked = page.waitForResponse((r) => r.url().includes(`/public/booking/${data.tenant.slug}/appointments`));
  await page.getByRole('button', { name: 'Reservar cita' }).click();
  expect((await booked).status()).toBeLessThan(300);
  await expect(page.getByRole('heading', { name: 'Revisa y confirma' })).toHaveCount(0);
  await expect(page.locator('main')).toContainText(time);
});

test('5. Portal del cliente: ver y confirmar su próxima cita', async ({ page, request }) => {
  await bookByApi(request, 1);
  await login(page, 'customer');
  await expect(page).toHaveURL(/\/portal/);
  await expect(page.getByRole('heading', { level: 1, name: `Hola, ${data.customer.first_name}` })).toBeVisible();

  const next = page.getByRole('article').first();
  await expect(next).toBeVisible();
  const confirm = next.getByRole('button', { name: 'Confirmar asistencia' });

  if (await confirm.isVisible()) {
    await confirm.click();
    await expect(next.getByRole('button', { name: 'Confirmar asistencia' })).toHaveCount(0);
  }

  await expect(next.getByText(/Confirmada/)).toBeVisible();
  await page.getByRole('button', { name: /Ver todas/ }).click();
  await expect(page.getByText(/Corte de dama/).first()).toBeVisible();
});

test('6. Consola de la plataforma: ver las empresas', async ({ page }) => {
  await login(page, 'platform');
  await page.goto('/admin/tenants');
  await expect(page.getByRole('heading', { level: 1, name: 'Gestión de Tenants' })).toBeVisible();
  await expect(page.getByRole('button', { name: /Todos los tenants \d+/ })).toBeVisible();
  await page.locator('input[data-table-search]').fill('Bella');
  const row = page.getByRole('row').filter({ hasText: data.tenant.name });
  await expect(row).toBeVisible();
  await expect(page.getByRole('row').filter({ hasText: 'Centro Médico Integral' })).toHaveCount(0);
  await row.getByRole('link', { name: data.tenant.name }).click();
  await expect(page).toHaveURL(new RegExp(`/admin/tenants/${data.tenant.id}`));
  await expect(page.getByText(data.tenant.name).first()).toBeVisible();
});

test('7. Aislamiento: una empresa no puede leer datos de otra por la API', async ({ request }) => {
  const token = await apiToken(request, 'owner');
  const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' };

  const appointment = await request.get(`${API}/appointments/${data.other.appointment_id}`, { headers });
  expect([403, 404]).toContain(appointment.status());

  const customer = await request.get(`${API}/customers/${data.other.customer_id}`, { headers });
  expect([403, 404]).toContain(customer.status());

  const mine = await request.get(`${API}/appointments`, { headers, params: { date: nextLocalDay(0) } });
  expect(mine.ok()).toBeTruthy();
  expect(await mine.text()).not.toContain(data.other.appointment_id);
});

test('8. Documentación pública de la API', async ({ page }) => {
  await page.goto(`${API}/docs`);
  await expect(page.getByRole('heading', { level: 1, name: /API pública/ })).toBeVisible();
  await expect(page.locator('[data-operation="POST /appointments"]')).toBeVisible();
  await expect(page.locator('[data-webhook="appointment.created"]')).toBeVisible();
});
