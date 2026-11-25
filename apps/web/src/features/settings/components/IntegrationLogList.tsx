'use client';

import Badge from '@/app/components/common/Badge';
import { PagedList } from '@/app/components/common/Pager';
import { momentOf } from '@/features/sales/sales';

export interface IntegrationLogEntry {
  id: string;
  kind: string;
  kind_label: string;
  provider: string;
  action: string;
  action_label: string;
  ok: boolean;
  message: string | null;
  at: string;
}

export const PROVIDER_LABELS: Record<string, string> = {
  culqi: 'Culqi',
  niubiz: 'Niubiz',
  izipay: 'Izipay',
  mercadopago: 'Mercado Pago',
  cloud: 'WhatsApp de Meta',
  '360dialog': '360dialog',
  simulated: 'Simulación',
  anthropic: 'Claude (Anthropic)',
  openai: 'OpenAI',
  turnstile: 'Cloudflare Turnstile',
  resend: 'Resend',
  brevo: 'Brevo',
  ses: 'Amazon SES',
  smtp: 'Servidor de correo',
};

export default function IntegrationLogList({ entries, title = 'Registro de conexiones', showKind = false }: { entries: IntegrationLogEntry[] | undefined; title?: string; showKind?: boolean }) {
  if (!entries) return null;

  return (
    <div className="space-y-2" data-integration-log>
      <span className="block text-[11px] font-bold text-slate-600 uppercase tracking-wider">{title}</span>
      <div className="rounded-xl border border-slate-200 overflow-hidden flex flex-col">
        <PagedList<IntegrationLogEntry>
          items={entries}
          empty="Todavía no hay nada registrado. Aquí aparece cada prueba de conexión y cada error que devuelva el servicio, con palabras sencillas."
          renderRow={(entry) => (
            <li key={entry.id} className="flex items-start gap-3 px-5 py-3" data-log-ok={entry.ok ? 'yes' : 'no'}>
              <Badge tone={entry.ok ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'} bold className="shrink-0">{entry.ok ? 'Bien' : 'Error'}</Badge>
              <span className="flex-1 min-w-0">
                <span className="block text-sm font-semibold text-slate-800">{showKind ? `${entry.kind_label} · ` : ''}{PROVIDER_LABELS[entry.provider] ?? entry.provider} · {entry.action_label}</span>
                <span className="block text-xs text-slate-500 break-words">{entry.message ?? 'Sin detalle.'} · {momentOf(entry.at)}</span>
              </span>
            </li>
          )}
        />
      </div>
    </div>
  );
}
