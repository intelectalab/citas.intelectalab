'use client';

import { useState } from 'react';
import { Check, Download, FileText, Loader2 } from 'lucide-react';
import Badge from '@/app/components/common/Badge';
import SectionTitle from '@/app/components/common/SectionTitle';
import { PagedList } from '@/app/components/common/Pager';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { api } from '@/lib/axios';
import { money } from '@/features/sales/sales';
import { QUOTE_STATUS_BADGES } from '@/features/quotes/quotes';
import { portalError } from '../portal';

export interface PortalQuote {
  id: string;
  code: string;
  title: string;
  status: 'sent' | 'accepted' | 'rejected' | 'expired';
  status_label: string;
  valid_until: string | null;
  created_at: string;
  accepted_at: string | null;
  accepted_via: string | null;
  branch: string | null;
  professional: string | null;
  notes: string | null;
  subtotal: number;
  discount: number;
  total: number;
  currency: string;
  items: { description: string; quantity: number; total: number; sessions: number }[];
  installments: { number: number; due_date: string; amount: number; status: string; status_label: string }[];
  paid: number | null;
  balance: number | null;
  plan: { code: string; status: string; status_label: string; expires_at: string; sessions_total: number; sessions_used: number } | null;
  rejection: string | null;
  can_answer: boolean;
}

export interface PortalQuotesData {
  enabled: boolean;
  reasons: string[];
  quotes: PortalQuote[];
}

const day = (value: string) => new Date(value.length === 10 ? `${value}T12:00:00` : value).toLocaleDateString('es-PE', { day: 'numeric', month: 'short', year: 'numeric' });

export default function PortalQuotes({ data, onChanged, onBook }: { data: PortalQuotesData; onChanged: (next: PortalQuotesData, notice: string) => void; onBook?: () => void }) {
  const [accepting, setAccepting] = useState<PortalQuote | null>(null);
  const [rejectingId, setRejectingId] = useState<string | null>(null);
  const [reason, setReason] = useState('');
  const [other, setOther] = useState('');
  const [busy, setBusy] = useState(false);
  const [downloading, setDownloading] = useState<string | null>(null);
  const [error, setError] = useState('');

  const pending = data.quotes.filter((q) => q.can_answer).length;

  const accept = async () => {
    if (!accepting) return;
    try {
      setBusy(true);
      setError('');
      const res = await api.post<PortalQuotesData>(`/portal/quotes/${accepting.id}/accept`);
      onChanged(res.data, '¡Gracias! Aceptaste el presupuesto. Ya puedes reservar tus sesiones.');
      setAccepting(null);
    } catch (err) {
      setError(portalError(err, 'No se pudo aceptar el presupuesto.'));
      setAccepting(null);
    } finally {
      setBusy(false);
    }
  };

  const reject = async (quote: PortalQuote) => {
    const text = reason === 'Otro' ? other.trim() : reason;
    if (!text) {
      setError('Cuéntanos el motivo.');
      return;
    }
    try {
      setBusy(true);
      setError('');
      const res = await api.post<PortalQuotesData>(`/portal/quotes/${quote.id}/reject`, { reason: text });
      onChanged(res.data, 'Registramos tu respuesta. Gracias por contarnos.');
      setRejectingId(null);
      setReason('');
      setOther('');
    } catch (err) {
      setError(portalError(err, 'No se pudo enviar tu respuesta.'));
    } finally {
      setBusy(false);
    }
  };

  const downloadPdf = async (quote: PortalQuote) => {
    try {
      setDownloading(quote.id);
      setError('');
      const res = await api.get(`/portal/quotes/${quote.id}/pdf`, { responseType: 'blob' });
      const url = URL.createObjectURL(res.data);
      const link = document.createElement('a');
      link.href = url;
      link.download = `Presupuesto ${quote.code}.pdf`;
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
    } catch {
      setError('No se pudo descargar el presupuesto.');
    } finally {
      setDownloading(null);
    }
  };

  return (
    <section className="bg-white rounded-3xl border border-slate-200/80 shadow-xs overflow-hidden" data-card="portal-quotes">
      <div className="px-5 pt-5 pb-3">
        <SectionTitle><FileText size={16} className="text-brand-accent" /> Mis presupuestos</SectionTitle>
        <p className="text-xs text-slate-500 mt-1">
          {pending
            ? `Tienes ${pending === 1 ? '1 presupuesto' : `${pending} presupuestos`} por responder.`
            : 'Aquí verás los presupuestos que te enviemos y el avance de tus planes.'}
        </p>
        {error && <p role="alert" className="mt-3 text-sm text-red-600 bg-red-50 border border-red-100 rounded-xl px-4 py-3">{error}</p>}
      </div>
      <div className="border-t border-slate-100">
        <PagedList
          items={data.quotes}
          empty="Aún no tienes presupuestos."
          renderRow={(q) => (
            <li key={q.id} className="px-5 py-4 space-y-3" data-portal-quote={q.code}>
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <span className="block text-sm font-bold text-slate-800">{q.title}</span>
                  <span className="block text-xs text-slate-500">
                    {q.code} · {money(q.total, q.currency)}
                    {q.status === 'sent' && q.valid_until ? ` · válido hasta el ${day(q.valid_until)}` : ''}
                    {q.status === 'accepted' && q.accepted_at ? ` · aceptado el ${day(q.accepted_at)}` : ''}
                  </span>
                  {(q.professional || q.branch) && <span className="block text-xs text-slate-400">{[q.professional, q.branch].filter(Boolean).join(' · ')}</span>}
                </div>
                <Badge tone={QUOTE_STATUS_BADGES[q.status]} bold nowrap>{q.status_label}</Badge>
              </div>

              <ul className="rounded-xl border border-slate-100 divide-y divide-slate-100 text-xs">
                {q.items.map((item, index) => (
                  <li key={index} className="flex items-center justify-between gap-3 px-3 py-2">
                    <span className="text-slate-700">
                      {item.quantity > 1 ? `${item.quantity} × ` : ''}{item.description}
                      {item.sessions > 0 && <span className="text-slate-400"> · {item.sessions === 1 ? '1 sesión' : `${item.sessions} sesiones`}</span>}
                    </span>
                    <span className="font-semibold text-slate-800 tabular-nums whitespace-nowrap">{money(item.total, q.currency)}</span>
                  </li>
                ))}
                <li className="px-3 py-2">
                  <span className="flex items-center justify-between gap-3 font-bold text-brand-ink"><span>Total</span><span className="tabular-nums whitespace-nowrap">{money(q.total, q.currency)}</span></span>
                  {q.discount > 0 && <span className="block text-right text-[11px] text-emerald-700 font-semibold">Ya incluye {money(q.discount, q.currency)} de descuento</span>}
                </li>
              </ul>

              {q.notes && <p className="text-xs text-slate-600 bg-slate-50 border border-slate-100 rounded-lg px-3 py-2 whitespace-pre-line">{q.notes}</p>}

              {q.installments.length > 0 && q.status !== 'rejected' && (
                <div className="text-xs text-slate-600" data-portal-quote-installments>
                  <span className="font-semibold text-slate-700">Se paga en {q.installments.length} cuotas:</span>{' '}
                  {q.installments.map((i) => `${money(i.amount, q.currency)} el ${day(i.due_date)}${q.status === 'accepted' ? ` (${i.status_label.toLowerCase()})` : ''}`).join(' · ')}
                </div>
              )}

              {q.status === 'accepted' && (
                <div className="text-xs rounded-xl bg-brand-soft/15 border border-brand-soft/50 px-3 py-2 space-y-0.5" data-portal-quote-plan>
                  {q.plan ? (
                    <span className="block text-brand-ink font-semibold">
                      Plan {q.plan.code}: {q.plan.sessions_used} de {q.plan.sessions_total} sesiones usadas · {q.plan.status === 'active' ? `vigente hasta el ${day(q.plan.expires_at)}` : q.plan.status_label.toLowerCase()}
                    </span>
                  ) : (
                    <span className="block text-brand-ink font-semibold">Te avisaremos cuando tu plan de sesiones esté listo.</span>
                  )}
                  {q.paid !== null && (
                    <span className="block text-slate-600">Pagado {money(q.paid, q.currency)}{q.balance ? ` · te falta ${money(q.balance, q.currency)}` : ' · pagado por completo'}</span>
                  )}
                  {q.plan?.status === 'active' && q.plan.sessions_used < q.plan.sessions_total && onBook && (
                    <button type="button" onClick={onBook} className="text-xs font-bold text-brand-accent underline underline-offset-2">Reservar una sesión</button>
                  )}
                </div>
              )}

              {q.status === 'rejected' && q.rejection && <p className="text-xs text-slate-500">{q.rejection}</p>}
              {q.status === 'expired' && <p className="text-xs text-slate-500">Este presupuesto venció. Si te sigue interesando, pídenos uno nuevo.</p>}

              {rejectingId === q.id ? (
                <div className="rounded-xl border border-slate-200 p-3 space-y-2.5" data-portal-reject>
                  <span className="block text-xs font-bold text-slate-700">¿Por qué no te interesa?</span>
                  <div className="flex flex-wrap gap-1.5">
                    {[...data.reasons, 'Otro'].map((option) => (
                      <button
                        key={option}
                        type="button"
                        data-reason={option}
                        onClick={() => { setReason(option); setError(''); }}
                        className={`px-3 py-1.5 text-xs font-semibold rounded-lg border transition-colors ${reason === option ? 'border-brand bg-brand-soft/20 text-brand-ink' : 'border-slate-200 text-slate-600 hover:border-slate-300'}`}
                      >
                        {option}
                      </button>
                    ))}
                  </div>
                  {reason === 'Otro' && (
                    <input
                      type="text"
                      aria-label="Cuéntanos el motivo"
                      maxLength={200}
                      value={other}
                      onChange={(e) => setOther(e.target.value)}
                      placeholder="Cuéntanos el motivo"
                      className="w-full text-sm border border-slate-200 rounded-xl px-3.5 py-2 focus:outline-none focus:ring-2 focus:ring-brand/20 focus:border-brand"
                    />
                  )}
                  <div className="flex justify-end gap-2">
                    <button type="button" onClick={() => { setRejectingId(null); setReason(''); setError(''); }} className="px-3 py-2 text-xs font-semibold text-slate-600 hover:text-slate-800">Volver</button>
                    <button type="button" data-action="send-reject" disabled={busy} onClick={() => reject(q)} className="px-4 py-2 text-xs font-bold bg-brand text-white rounded-xl hover:bg-brand-hover disabled:opacity-50">Enviar respuesta</button>
                  </div>
                </div>
              ) : (
                <div className="flex flex-wrap items-center gap-2">
                  <button type="button" data-action="quote-pdf" disabled={downloading === q.id} onClick={() => downloadPdf(q)} className="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-slate-600 border border-slate-200 rounded-xl hover:border-slate-300 disabled:opacity-50">
                    {downloading === q.id ? <Loader2 size={13} className="animate-spin" /> : <Download size={13} />} PDF
                  </button>
                  {q.can_answer && (
                    <>
                      <button type="button" data-action="reject-quote" onClick={() => { setRejectingId(q.id); setReason(''); setError(''); }} className="ml-auto px-3 py-2 text-xs font-semibold text-slate-600 hover:text-red-600 transition-colors">No me interesa</button>
                      <button type="button" data-action="accept-quote" onClick={() => setAccepting(q)} className="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-bold bg-brand text-white rounded-xl hover:bg-brand-hover">
                        <Check size={14} /> Aceptar presupuesto
                      </button>
                    </>
                  )}
                </div>
              )}
              {q.status === 'sent' && !q.can_answer && !data.enabled && (
                <p className="text-xs text-slate-500">Para aceptarlo, comunícate con nosotros.</p>
              )}
            </li>
          )}
        />
      </div>

      <AppModal
        isOpen={accepting !== null}
        type="confirm"
        title="¿Aceptar el presupuesto?"
        message={accepting ? `${accepting.title} por ${money(accepting.total, accepting.currency)}. Al aceptarlo se crea tu plan de sesiones${accepting.installments.length ? ' y puedes pagarlo en las cuotas indicadas' : ''}. Agenda cada sesión desde "Reservar".` : ''}
        confirmText="Sí, aceptar"
        cancelText="Todavía no"
        isLoading={busy}
        onConfirm={accept}
        onClose={() => setAccepting(null)}
      />
    </section>
  );
}
