import { expect, type APIRequestContext, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';


export type Who = 'owner' | 'reception' | 'platform' | 'customer';

type Data = {
  password: string;
  tenant: { id: string; slug: string; name: string };
  owner: { email: string; name: string };
  reception: { email: string } | null;
  platform: { email: string };
  customer: { email: string; first_name: string; last_name: string };
  service: { id: string; name: string; price: string };
  branch: { id: string; name: string };
  customer_id: string;
  agenda_customers: { id: string; name: string }[];
  other: { slug: string; appointment_id: string; customer_id: string };
};

export const API = process.env.E2E_API || 'http://localhost:8001/api';

export const data: Data = JSON.parse(readFileSync(new URL('../.cache/data.json', import.meta.url), 'utf8'));

export function emailOf(who: Who): string {
  if (who === 'customer') return data.customer.email;
  if (who === 'reception') return data.reception!.email;
  return data[who].email;
}

export async function login(page: Page, who: Who): Promise<void> {
  await page.goto('/login');
  await page.getByPlaceholder('usuario@empresa.com').fill(emailOf(who));
  await page.locator('input[type=password]').fill(data.password);
  await page.getByRole('button', { name: 'Ingresar al sistema' }).click();
  await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}

export async function apiToken(request: APIRequestContext, who: Who): Promise<string> {
  const response = await request.post(`${API}/login`, { data: { email: emailOf(who), password: data.password }, headers: { Accept: 'application/json' } });
  expect(response.ok()).toBeTruthy();

  return (await response.json()).access_token;
}

export function nextLocalDay(offset = 1): string {
  const now = new Date(new Date().toLocaleString('en-US', { timeZone: 'America/Lima' }));
  now.setDate(now.getDate() + offset);

  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}

export async function openMenuIfMobile(page: Page): Promise<void> {
  const toggle = page.getByRole('button', { name: /abrir menú|menú/i }).first();

  if ((page.viewportSize()?.width ?? 1440) < 768 && (await toggle.isVisible().catch(() => false))) {
    await toggle.click();
  }
}
