'use client';

import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { Award, CalendarPlus, Clock3, Coins, EyeOff, FileText, Gift, Heart, History, Receipt, ShieldCheck, ShoppingBag, StickyNote, Tag, Trash2, Wallet } from 'lucide-react';
import Badge from '@/app/components/common/Badge';
import Button from '@/app/components/common/Button';
import Select from '@/app/components/common/Select';
import SectionTitle from '@/app/components/common/SectionTitle';
import { ErrorAlert } from '@/app/components/common/Alert';
import { PagedList } from '@/app/components/common/Pager';
import { api } from '@/lib/axios';
import { formatMoney } from '@/lib/currency';
import { apiError, dateOf, formatDate, timeOf } from '@/features/appointments/appointments';
import { SALE_BADGES } from '@/features/sales/sales';
import SaleDetailPanel from '@/features/sales/components/SaleDetailPanel';
import TagChip, { ColorPicker, type CustomerTag } from './TagChip';
import PointsAdjustDrawer from '@/features/loyalty/components/PointsAdjustDrawer';
import type { LoyaltyRules } from '@/features/loyalty/loyalty';
import { QUOTE_STATUS_BADGES } from '@/features/quotes/quotes';
import { STATUS_BADGES as MEMBERSHIP_BADGES } from '@/features/memberships/memberships';
import { RECORD_STATUS, type CareRecord } from '@/features/records/records';
import type { Appointment } from '@/features/appointments/appointments';

export interface PendingRecall {
  id: string;
  label: string;
  due_date: string;
  overdue: boolean;
  status: string;
  service_id: string | null;
  service: string | null;
  dependent_id: string | null;
  dependent: string | null;
}

interface TimelineItem {
  id: string;
  at: string;
  tone: string;
  title: string;
  detail: string;
  open?: () => void;
  href?: string;
}

interface Purchase {
  id: string;
  code: string;
  status: keyof typeof SALE_BADGES;
  status_label: string;
  total: number;
  balance: number;
  branch: string | null;
  date: string;
  items: string[];
}

interface Note {
  id: string;
  body: string;
  is_private: boolean;
  author: string;
  mine: boolean;
  created_at: string;
}

interface ConsentEvent {
  id: string;
  channel: string;
  granted: boolean;
  source: string | null;
  by: string;
  ip: string | null;
  at: string;
}

interface Profile {
  currency: { code: string };
  money: { spent: number; balance: number; purchases: number; per_month: number; months: number; avg_ticket: number } | null;
  points: number | null;
  points_value: number | null;
  credit?: { balance: number; items: { code: string; balance: number; initial: number; sale_code: string | null; created_at: string }[] } | null;
  preferences: { favorite_service: { name: string; times: number } | null; usual_professional: { name: string; times: number } | null; preferred_professional: string | null; home_branch: string | null; district: string | null; birthday: string | null; origin: string | null };
  recalls: PendingRecall[] | null;
  memberships: { id: string; code: string; name: string; status: string; status_label: string; price: number; period_label: string; starts_at: string; ends_at: string; days_left: number; benefits_text: string; uses: number; grace_until: string | null }[] | null;
  quotes: { id: string; code: string; title: string | null; status: keyof typeof QUOTE_STATUS_BADGES; status_label: string; total: number; balance: number; dependent: string | null; valid_until: string | null; created_at: string }[] | null;
  loyalty: {
    points_enabled: boolean;
    gift_cards_enabled: boolean;
    balance: number;
    value: number;
    rules: LoyaltyRules;
    movements: { id: string; type: string; type_label: string; points: number; balance_after: number; reason: string | null; sale_code: string | null; created_at: string }[];
    gift_cards: { id: string; code: string; initial_amount: number; balance: number; status: string; status_label: string; recipient_name: string | null; bought: boolean; expires_at: string | null }[];
    can_adjust: boolean;
  } | null;
  messages: { id: string; channel: string; kind: string; status: string; status_label: string; summary: string; created_at: string }[] | null;
  segments: { key: string; label: string; hint: string | null }[];
  tags: CustomerTag[];
  tags_ready: boolean;
  purchases: Purchase[] | null;
  notes_ready: boolean;
  consent_history: ConsentEvent[];
  consent_history_ready: boolean;
  fields_ready: boolean;
  can: { private_notes: boolean; write_notes: boolean; tags: boolean };
}

const card = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs';
const smallLabel = 'text-[11px] font-bold text-slate-400 uppercase tracking-wider';
const CHANNELS: Record<string, string> = { email: 'Correo', whatsapp: 'WhatsApp', sms: 'SMS', marketing: 'Promociones', data_processing: 'Uso de sus datos' };
const when = (local: string) => `${formatDate(dateOf(local), { day: 'numeric', month: 'short', year: 'numeric' })}, ${timeOf(local)}`;

interface CustomerProfilePanelProps {
  customerId: string;
  customerName?: string;
  refreshKey?: number;
  appointments?: Appointment[];
  records?: CareRecord[];
  onOpenAppointment?: (id: string) => void;
  onOpenRecord?: (record: CareRecord) => void;
  onBookRecall?: (recall: PendingRecall) => void;
  onNewQuote?: () => void;
}

const CHANNEL_NAMES: Record<string, string> = { email: 'Correo', whatsapp: 'WhatsApp', call: 'Llamada' };
const monthDay = (md: string) => formatDate(`2000-${md}`, { day: 'numeric', month: 'long' });

export default function CustomerProfilePanel({ customerId, customerName = '', refreshKey = 0, appointments = [], records = [], onOpenAppointment, onOpenRecord, onBookRecall, onNewQuote }: CustomerProfilePanelProps) {
  const [adjusting, setAdjusting] = useState(false);
  const [profile, setProfile] = useState<Profile | null>(null);
  const [allTags, setAllTags] = useState<CustomerTag[]>([]);
  const [colors, setColors] = useState<string[]>([]);
  const [notes, setNotes] = useState<Note[]>([]);
  const [tab, setTab] = useState<'timeline' | 'purchases' | 'quotes' | 'plans' | 'loyalty' | 'notes' | 'consents'>('timeline');
  const [error, setError] = useState('');
  const [creating, setCreating] = useState(false);
  const [newName, setNewName] = useState('');
  const [newColor, setNewColor] = useState('');
  const [body, setBody] = useState('');
  const [isPrivate, setIsPrivate] = useState(false);
  const [busy, setBusy] = useState(false);
  const [saleId, setSaleId] = useState<string | null>(null);

  const load = useCallback(() => {
    api.get(`/customers/${customerId}/profile`)
      .then((res) => {
        setProfile(res.data);
        if (res.data.notes_ready) {
          api.get(`/customers/${customerId}/notes`).then((r) => setNotes(r.data.notes ?? [])).catch(() => setNotes([]));
        }
      })
      .catch((err) => setError(apiError(err, 'No se pudo cargar el resumen.')));
  }, [customerId]);

  useEffect(() => { load(); }, [load, refreshKey]);

  useEffect(() => {
    api.get('/customer-tags')
      .then((res) => { setAllTags(res.data.tags ?? []); setColors(res.data.colors ?? []); setNewColor(res.data.colors?.[0] ?? ''); })
      .catch(() => setAllTags([]));
  }, []);

  if (!profile) {
    return error ? <ErrorAlert message={error} /> : null;
  }

  const money = (value: number) => formatMoney(value, profile.currency.code);

  const saveTags = (ids: string[]) => {
    setError('');
    api.put(`/customers/${customerId}/tags`, { tag_ids: ids })
      .then((res) => setProfile((current) => (current ? { ...current, tags: res.data.tags } : current)))
      .catch((err) => setError(apiError(err, 'No se pudieron guardar las etiquetas.')));
  };

  const createTag = () => {
    setBusy(true);
    setError('');
    api.post('/customer-tags', { name: newName, color: newColor })
      .then((res) => {
        setAllTags(res.data.tags ?? []);
        setCreating(false);
        setNewName('');
        saveTags([...profile.tags.map((t) => t.id), res.data.tag.id]);
      })
      .catch((err) => setError(apiError(err, 'No se pudo crear la etiqueta.')))
      .finally(() => setBusy(false));
  };

  const addNote = () => {
    setBusy(true);
    setError('');
    api.post(`/customers/${customerId}/notes`, { body, is_private: isPrivate })
      .then((res) => { setNotes(res.data.notes ?? []); setBody(''); setIsPrivate(false); })
      .catch((err) => setError(apiError(err, 'No se pudo guardar la nota.')))
      .finally(() => setBusy(false));
  };

  const removeNote = (id: string) => {
    setError('');
    api.delete(`/customers/${customerId}/notes/${id}`)
      .then((res) => setNotes(res.data.notes ?? []))
      .catch((err) => setError(apiError(err, 'No se pudo borrar la nota.')));
  };

  const metrics = [
    ...(profile.money ? [
      { id: 'spent', title: 'Gasto total', value: money(profile.money.spent), note: `${money(profile.money.per_month)} al mes · ${profile.money.purchases} ${profile.money.purchases === 1 ? 'compra' : 'compras'}`, icon: <Wallet size={18} />, color: 'text-emerald-600' },
      { id: 'ticket', title: 'Ticket promedio', value: money(profile.money.avg_ticket), note: 'Lo que paga en cada compra', icon: <Receipt size={18} />, color: 'text-sky-600' },
      { id: 'balance', title: 'Saldo pendiente', value: money(profile.money.balance), note: profile.money.balance > 0 ? 'Tiene pagos por completar' : 'Está al día', icon: <Coins size={18} />, color: profile.money.balance > 0 ? 'text-rose-700' : 'text-slate-500' },
    ] : []),
    ...(profile.points !== null ? [{ id: 'points', title: 'Puntos', value: String(profile.points), note: profile.points_value !== null ? `Valen ${money(profile.points_value)} para canjear` : 'Para canjear en sus compras', icon: <Gift size={18} />, color: 'text-violet-600' }] : []),
    ...(profile.credit && profile.credit.balance > 0 ? [{ id: 'credit', title: 'Saldo a favor', value: money(profile.credit.balance), note: 'De adelantos de citas que canceló a tiempo. Se usa al cobrar', icon: <Wallet size={18} />, color: 'text-emerald-600' }] : []),
  ];

  const timeline: TimelineItem[] = [
    ...appointments.map((a) => ({ id: `a-${a.id}`, at: a.starts_at, tone: a.status === 'completed' ? 'bg-emerald-500' : ['cancelled', 'no_show'].includes(a.status) ? 'bg-rose-500' : 'bg-sky-500', title: `Cita ${a.code} · ${a.service?.name ?? ''}`, detail: [a.professional?.display_name, a.status_name].filter(Boolean).join(' · '), open: onOpenAppointment ? () => onOpenAppointment(a.id) : undefined })),
    ...records.map((rec) => ({ id: `r-${rec.id}`, at: rec.appointment?.starts_at ?? rec.created_at, tone: 'bg-violet-500', title: `Ficha: ${rec.template_name ?? 'Atención'}${rec.dependent ? ` · ${rec.dependent.name}` : ''}`, detail: [rec.professional ?? rec.author, RECORD_STATUS[rec.status]?.label].filter(Boolean).join(' · '), open: onOpenRecord ? () => onOpenRecord(rec) : undefined })),
    ...(profile.purchases ?? []).map((p) => ({ id: `s-${p.id}`, at: p.date, tone: 'bg-brand-accent', title: `Venta ${p.code} · ${money(p.total)}`, detail: [p.items.slice(0, 2).join(', '), p.status_label].filter(Boolean).join(' · '), open: () => setSaleId(p.id) })),
    ...(profile.quotes ?? []).map((q) => ({ id: `q-${q.id}`, at: q.created_at, tone: q.status === 'accepted' ? 'bg-emerald-500' : 'bg-amber-500', title: `Presupuesto ${q.code} · ${money(q.total)}`, detail: [q.title, q.status_label].filter(Boolean).join(' · '), href: `/quotes?quote=${q.id}` })),
    ...notes.map((note) => ({ id: `n-${note.id}`, at: note.created_at, tone: 'bg-amber-400', title: `Nota de ${note.author}${note.is_private ? ' (privada)' : ''}`, detail: note.body.length > 90 ? `${note.body.slice(0, 90)}…` : note.body, open: () => setTab('notes') })),
    ...(profile.messages ?? []).map((m) => ({ id: `m-${m.id}`, at: m.created_at, tone: m.status === 'failed' ? 'bg-rose-500' : 'bg-slate-400', title: `${CHANNEL_NAMES[m.channel] ?? m.channel}: ${m.summary}`, detail: m.status_label })),
    ...(profile.loyalty?.movements ?? []).map((x) => ({ id: `l-${x.id}`, at: x.created_at, tone: x.points > 0 ? 'bg-emerald-500' : 'bg-amber-500', title: `${x.points > 0 ? '+' : ''}${x.points} puntos`, detail: [x.type_label, x.reason, x.sale_code].filter(Boolean).join(' · '), open: () => setTab('loyalty') })),
  ].filter((item) => item.at).sort((a, b) => (a.at < b.at ? 1 : -1));

  const activeMemberships = (profile.memberships ?? []).filter((m) => ['active', 'grace', 'paused'].includes(m.status));

  const tabs = [
    { id: 'timeline' as const, label: 'Línea de tiempo', icon: <History size={14} /> },
    ...(profile.purchases ? [{ id: 'purchases' as const, label: `Compras (${profile.purchases.length})`, icon: <ShoppingBag size={14} /> }] : []),
    ...(profile.quotes ? [{ id: 'quotes' as const, label: `Presupuestos (${profile.quotes.length})`, icon: <FileText size={14} /> }] : []),
    ...(profile.memberships ? [{ id: 'plans' as const, label: `Membresías (${profile.memberships.length})`, icon: <Award size={14} /> }] : []),
    ...(profile.loyalty ? [{ id: 'loyalty' as const, label: 'Puntos y gift cards', icon: <Gift size={14} /> }] : []),
    { id: 'notes' as const, label: `Notas (${notes.length})`, icon: <StickyNote size={14} /> },
    { id: 'consents' as const, label: 'Consentimientos', icon: <ShieldCheck size={14} /> },
  ];

  const available = allTags.filter((t) => !profile.tags.some((mine) => mine.id === t.id));

  return (
    <>
      <section className={`${card} p-5 space-y-4`} data-card="profile-360">
        {!profile.fields_ready && (
          <p role="alert" className="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">Falta actualizar la base de datos para guardar sede habitual, profesional preferido y alerta: ejecuta php artisan migrate.</p>
        )}

        {metrics.length > 0 && (
          <div className={`grid grid-cols-1 gap-3 ${metrics.length >= 4 ? 'sm:grid-cols-2 lg:grid-cols-4' : metrics.length === 3 ? 'sm:grid-cols-3' : metrics.length === 2 ? 'sm:grid-cols-2' : ''}`}>
            {metrics.map((m) => (
              <div key={m.id} data-metric={m.id} className="px-4 py-3 rounded-xl border border-slate-100 bg-slate-50/50 flex items-start justify-between gap-2">
                <span className="min-w-0">
                  <span className="block text-[10px] font-semibold tracking-wider text-slate-400 uppercase">{m.title}</span>
                  <span className="block mt-0.5 text-xl font-extrabold tracking-tight text-slate-900">{m.value}</span>
                  <span className="block text-xs text-slate-500 mt-0.5">{m.note}</span>
                </span>
                <span className={`p-2 rounded-xl bg-white border border-slate-100 shrink-0 ${m.color}`}>{m.icon}</span>
              </div>
            ))}
          </div>
        )}

        <div className="space-y-2" data-segments>
          <span className={smallLabel}>Grupos de campañas en los que está</span>
          {profile.segments.length === 0 ? (
            <p className="text-sm text-slate-400">Por ahora no está en ninguno.</p>
          ) : (
            <div className="flex flex-wrap gap-1.5">
              {profile.segments.map((s) => (
                <span key={s.key} data-segment={s.key} title={s.hint ?? undefined} className="px-2.5 py-1 text-xs font-semibold rounded-lg bg-brand-soft/20 text-brand-ink border border-brand-soft/40">{s.label}</span>
              ))}
            </div>
          )}
        </div>

        <div className="space-y-2" data-tags>
          <span className={`flex items-center gap-1.5 ${smallLabel}`}><Tag size={12} /> Etiquetas</span>
          {!profile.tags_ready ? (
            <p role="alert" className="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">Falta actualizar la base de datos para usar etiquetas: ejecuta php artisan migrate.</p>
          ) : (
            <div className="flex flex-wrap items-center gap-1.5">
              {profile.tags.length === 0 && !profile.can.tags && <span className="text-sm text-slate-400">Sin etiquetas.</span>}
              {profile.tags.map((t) => (
                <TagChip key={t.id} tag={t} onRemove={profile.can.tags ? () => saveTags(profile.tags.filter((x) => x.id !== t.id).map((x) => x.id)) : undefined} />
              ))}
              {profile.can.tags && (
                <div className="w-48" data-add-tag>
                  <Select
                    variant="chip"
                    ariaLabel="Agregar etiqueta"
                    placeholder="Agregar etiqueta…"
                    clearable={false}
                    value=""
                    onChange={(e) => { if (e.target.value) saveTags([...profile.tags.map((t) => t.id), String(e.target.value)]); }}
                    options={available.map((t) => ({ value: t.id, label: t.name }))}
                    onAdd={() => setCreating(true)}
                    addLabel="Nueva etiqueta"
                  />
                </div>
              )}
            </div>
          )}
          {creating && (
            <div className="rounded-xl border border-slate-200 p-3 space-y-3" data-new-tag>
              <input
                type="text"
                maxLength={40}
                value={newName}
                onChange={(e) => setNewName(e.target.value)}
                placeholder="Nombre de la etiqueta, ej. Prefiere WhatsApp"
                className="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 text-slate-800 focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"
              />
              <ColorPicker colors={colors} value={newColor} onChange={setNewColor} />
              <div className="flex justify-end gap-2">
                <Button variant="secondary" type="button" onClick={() => setCreating(false)}>Cancelar</Button>
                <Button variant="classic" type="button" onClick={createTag} disabled={busy || !newName.trim()}>Crear y agregar</Button>
              </div>
            </div>
          )}
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 pt-4 border-t border-slate-100">
          <div className="space-y-2" data-preferences>
            <span className={`flex items-center gap-1.5 ${smallLabel}`}><Heart size={12} /> Preferencias</span>
            <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
              {([
                ['Servicio favorito', profile.preferences.favorite_service ? `${profile.preferences.favorite_service.name} (${profile.preferences.favorite_service.times})` : null],
                ['Lo atiende más', profile.preferences.usual_professional ? `${profile.preferences.usual_professional.name} (${profile.preferences.usual_professional.times})` : null],
                ['Prefiere', profile.preferences.preferred_professional],
                ['Sede habitual', profile.preferences.home_branch],
                ['Distrito', profile.preferences.district],
                ['Cumpleaños', profile.preferences.birthday ? monthDay(profile.preferences.birthday) : null],
                ['Origen', profile.preferences.origin],
              ] as [string, string | null][]).map(([label, value]) => (
                <div key={label} className="min-w-0">
                  <dt className="text-[11px] font-semibold text-slate-400">{label}</dt>
                  <dd className="font-semibold text-slate-800 truncate" title={value ?? undefined}>{value ?? '—'}</dd>
                </div>
              ))}
            </dl>
          </div>

          <div className="space-y-4">
            {profile.recalls && (
              <div className="space-y-2" data-pending-recalls>
                <span className={`flex items-center gap-1.5 ${smallLabel}`}><Clock3 size={12} /> Controles pendientes</span>
                {profile.recalls.length === 0 ? (
                  <p className="text-sm text-slate-400">No tiene controles por venir.</p>
                ) : (
                  <ul className="space-y-1.5">
                    {profile.recalls.slice(0, 4).map((recall) => (
                      <li key={recall.id} data-recall={recall.id} className="flex items-center justify-between gap-2 text-sm">
                        <span className="min-w-0">
                          <span className="block font-semibold text-slate-800 truncate">{recall.label}{recall.dependent ? ` · ${recall.dependent}` : ''}</span>
                          <span className={`block text-xs ${recall.overdue ? 'text-rose-700 font-semibold' : 'text-slate-500'}`}>{recall.overdue ? 'Venció el ' : 'Para el '}{formatDate(recall.due_date, { day: 'numeric', month: 'short', year: 'numeric' })}</span>
                        </span>
                        {onBookRecall && (
                          <button type="button" data-action="book-recall" onClick={() => onBookRecall(recall)} className="shrink-0 inline-flex items-center gap-1 px-2 py-1 text-xs font-bold text-brand-ink bg-white border border-slate-200 rounded-lg hover:bg-slate-50">
                            <CalendarPlus size={12} className="text-brand-accent" /> Agendar
                          </button>
                        )}
                      </li>
                    ))}
                  </ul>
                )}
                {profile.recalls.length > 0 && <Link href="/recalls" className="text-xs font-bold text-brand-accent hover:text-brand-ink">Ver todos los controles</Link>}
              </div>
            )}

            {activeMemberships.length > 0 && (
              <div className="space-y-2" data-active-memberships>
                <span className={`flex items-center gap-1.5 ${smallLabel}`}><Award size={12} /> Membresías vigentes</span>
                {activeMemberships.map((m) => (
                  <p key={m.id} className="text-sm text-slate-700"><span className="font-semibold">{m.name}</span> · hasta el {formatDate(m.ends_at, { day: 'numeric', month: 'short', year: 'numeric' })} <Badge tone={MEMBERSHIP_BADGES[m.status as keyof typeof MEMBERSHIP_BADGES] ?? ''} nowrap>{m.status_label}</Badge></p>
                ))}
              </div>
            )}
          </div>
        </div>

        <ErrorAlert message={error} />
      </section>

      <section className={`${card} overflow-hidden`} data-card="profile-tabs">
        <div className="flex gap-1 px-4 pt-4 border-b border-slate-100 overflow-x-auto select-scroll" role="tablist">
          {tabs.map((t) => (
            <button
              key={t.id}
              type="button"
              role="tab"
              data-tab={t.id}
              aria-selected={tab === t.id}
              onClick={() => setTab(t.id)}
              className={`flex items-center gap-1.5 px-3 py-2 text-sm font-semibold whitespace-nowrap border-b-2 -mb-px transition-colors ${tab === t.id ? 'border-brand-accent text-brand-ink' : 'border-transparent text-slate-500 hover:text-brand-ink'}`}
            >
              {t.icon} {t.label}
            </button>
          ))}
        </div>

        {tab === 'timeline' && (
          <div className="flex flex-col" data-list="timeline">
            <p className="px-5 pt-3 text-xs text-slate-500">Todo lo que pasó, de lo más reciente a lo más antiguo. Haz clic en una línea para verla.</p>
            <PagedList
              items={timeline}
              empty="Aún no hay nada que mostrar."
              renderRow={(item) => {
                const body = (
                  <>
                    <span className={`mt-1.5 w-2.5 h-2.5 rounded-full shrink-0 ${item.tone}`} />
                    <span className="flex-1 min-w-0">
                      <span className="block text-sm font-semibold text-slate-800 truncate">{item.title}</span>
                      {item.detail && <span className="block text-xs text-slate-500 truncate">{item.detail}</span>}
                    </span>
                    <span className="shrink-0 text-xs text-slate-500 text-right">{when(item.at)}</span>
                  </>
                );
                const rowClass = 'w-full flex items-start gap-3 px-5 py-3 text-left transition-colors';
                return (
                  <li key={item.id} data-timeline-item={item.id}>
                    {item.href ? <Link href={item.href} className={`${rowClass} hover:bg-slate-50`}>{body}</Link>
                      : item.open ? <button type="button" onClick={item.open} className={`${rowClass} hover:bg-slate-50`}>{body}</button>
                      : <div className={rowClass}>{body}</div>}
                  </li>
                );
              }}
            />
          </div>
        )}

        {tab === 'quotes' && profile.quotes && (
          <div className="flex flex-col" data-list="quotes">
            {onNewQuote && (
              <div className="flex justify-end px-5 pt-3">
                <Button variant="classic" type="button" onClick={onNewQuote}>Nuevo presupuesto</Button>
              </div>
            )}
            <PagedList
              items={profile.quotes}
              empty="Aún no tiene presupuestos."
              renderRow={(q) => (
                <li key={q.id}>
                  <Link href={`/quotes?quote=${q.id}`} data-customer-quote={q.id} className="w-full flex items-center gap-3 px-5 py-3 text-left hover:bg-slate-50 transition-colors">
                    <div className="flex-1 min-w-0">
                      <span className="block text-sm font-semibold text-brand-ink truncate"><span className="font-mono">{q.code}</span> · {q.title || 'Plan de tratamiento'}</span>
                      <span className="block text-xs text-slate-500 truncate">{[formatDate(dateOf(q.created_at), { day: 'numeric', month: 'short', year: 'numeric' }), q.dependent].filter(Boolean).join(' · ')}</span>
                    </div>
                    <div className="text-right shrink-0">
                      <span className="block text-sm font-bold text-slate-800">{money(q.total)}</span>
                      {q.balance > 0 && <span className="block text-[11px] font-semibold text-rose-700">Debe {money(q.balance)}</span>}
                    </div>
                    <Badge tone={QUOTE_STATUS_BADGES[q.status] ?? ''} nowrap className="hidden sm:inline-flex">{q.status_label}</Badge>
                  </Link>
                </li>
              )}
            />
          </div>
        )}

        {tab === 'plans' && profile.memberships && (
          <div className="flex flex-col" data-list="memberships">
            <PagedList
              items={profile.memberships}
              empty="No tiene membresías."
              renderRow={(m) => (
                <li key={m.id}>
                  <Link href={`/memberships/${m.id}`} className="w-full flex items-center gap-3 px-5 py-3 text-left hover:bg-slate-50 transition-colors">
                    <div className="flex-1 min-w-0">
                      <span className="block text-sm font-semibold text-slate-800 truncate">{m.name} <span className="font-mono text-xs text-slate-400">{m.code}</span></span>
                      <span className="block text-xs text-slate-500 truncate">{formatDate(m.starts_at, { day: 'numeric', month: 'short', year: 'numeric' })} al {formatDate(m.ends_at, { day: 'numeric', month: 'short', year: 'numeric' })} · {m.uses} {m.uses === 1 ? 'uso' : 'usos'}{m.benefits_text ? ` · ${m.benefits_text}` : ''}</span>
                    </div>
                    <span className="text-sm font-bold text-slate-800 shrink-0">{money(m.price)}</span>
                    <Badge tone={MEMBERSHIP_BADGES[m.status as keyof typeof MEMBERSHIP_BADGES] ?? ''} nowrap>{m.status_label}</Badge>
                  </Link>
                </li>
              )}
            />
          </div>
        )}

        {tab === 'loyalty' && profile.loyalty && (
          <div className="grid grid-cols-1 lg:grid-cols-2 lg:divide-x divide-slate-100" data-list="loyalty">
            <div className="flex flex-col">
              <div className="flex items-center justify-between gap-2 px-5 pt-3">
                <span className={smallLabel}>Puntos: {profile.loyalty.balance} (valen {money(profile.loyalty.value)})</span>
                {profile.loyalty.can_adjust && <Button variant="classic" type="button" data-action="adjust-points" onClick={() => setAdjusting(true)}>Ajustar</Button>}
              </div>
              {!profile.loyalty.points_enabled ? (
                <p className="px-5 py-3 text-sm text-slate-400">Tu empresa no usa puntos.</p>
              ) : (
                <PagedList
                  items={profile.loyalty.movements}
                  empty="Aún no tiene movimientos de puntos."
                  renderRow={(x) => (
                    <li key={x.id} className="flex items-center gap-3 px-5 py-2.5">
                      <div className="flex-1 min-w-0">
                        <span className="block text-sm font-semibold text-slate-800 truncate">{x.type_label}{x.sale_code ? ` · ${x.sale_code}` : ''}</span>
                        <span className="block text-xs text-slate-500 truncate">{[x.reason, when(x.created_at)].filter(Boolean).join(' · ')}</span>
                      </div>
                      <span className={`text-sm font-bold shrink-0 ${x.points < 0 ? 'text-rose-700' : 'text-emerald-700'}`}>{x.points > 0 ? '+' : ''}{x.points}</span>
                    </li>
                  )}
                />
              )}
            </div>
            <div className="flex flex-col">
              <span className={`block px-5 pt-3 ${smallLabel}`}>Gift cards</span>
              {!profile.loyalty.gift_cards_enabled ? (
                <p className="px-5 py-3 text-sm text-slate-400">Tu empresa no vende gift cards.</p>
              ) : (
                <PagedList
                  items={profile.loyalty.gift_cards}
                  empty="No tiene gift cards."
                  renderRow={(g) => (
                    <li key={g.id} className="flex items-center gap-3 px-5 py-2.5">
                      <div className="flex-1 min-w-0">
                        <span className="block text-sm font-semibold text-slate-800 font-mono">{g.code}</span>
                        <span className="block text-xs text-slate-500 truncate">{g.bought ? `La compró para ${g.recipient_name ?? 'otra persona'}` : 'Es para esta persona'}{g.expires_at ? ` · vence el ${formatDate(g.expires_at, { day: 'numeric', month: 'short', year: 'numeric' })}` : ''}</span>
                      </div>
                      <span className="text-right shrink-0">
                        <span className="block text-sm font-bold text-slate-800">{money(g.balance)}</span>
                        <span className="block text-[11px] text-slate-500">de {money(g.initial_amount)}</span>
                      </span>
                      <Badge tone={g.status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'} nowrap>{g.status_label}</Badge>
                    </li>
                  )}
                />
              )}
            </div>
          </div>
        )}

        {tab === 'purchases' && profile.purchases && (
          <div className="flex flex-col" data-list="purchases">
            <PagedList
              items={profile.purchases}
              empty="Aún no tiene compras."
              renderRow={(p) => (
                <li key={p.id}>
                  <button type="button" data-purchase={p.id} onClick={() => setSaleId(p.id)} className="w-full flex items-center gap-3 px-5 py-3 text-left hover:bg-slate-50 transition-colors">
                    <div className="w-28 shrink-0">
                      <span className="block text-sm font-semibold text-slate-800 first-letter:uppercase">{formatDate(dateOf(p.date), { weekday: 'short', day: '2-digit', month: 'short' })}</span>
                      <span className="block text-xs text-slate-500">{dateOf(p.date).slice(0, 4)} · {timeOf(p.date)}</span>
                    </div>
                    <div className="flex-1 min-w-0">
                      <span className="block text-sm font-semibold text-brand-ink truncate hover:text-brand-accent">{p.code}</span>
                      <span className="block text-xs text-slate-500 truncate">{[p.items.slice(0, 2).join(', ') + (p.items.length > 2 ? ` y ${p.items.length - 2} más` : ''), p.branch].filter(Boolean).join(' · ')}</span>
                    </div>
                    <div className="text-right shrink-0">
                      <span className="block text-sm font-bold text-slate-800">{money(p.total)}</span>
                      {p.balance > 0 && <span className="block text-[11px] font-semibold text-rose-700">Debe {money(p.balance)}</span>}
                    </div>
                    <Badge tone={SALE_BADGES[p.status] ?? ''} nowrap className="hidden sm:inline-flex">{p.status_label}</Badge>
                  </button>
                </li>
              )}
            />
          </div>
        )}

        {tab === 'notes' && (
          <div className="flex flex-col" data-list="notes">
            {!profile.notes_ready ? (
              <p role="alert" className="m-5 text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">Falta actualizar la base de datos para usar notas: ejecuta php artisan migrate.</p>
            ) : (
              <>
                {profile.can.write_notes && (
                  <div className="px-5 pt-4 pb-3 space-y-2 border-b border-slate-100" data-note-form>
                    <textarea
                      rows={2}
                      maxLength={4000}
                      value={body}
                      onChange={(e) => setBody(e.target.value)}
                      placeholder="Escribe una nota para el equipo…"
                      className="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 text-slate-800 focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"
                    />
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      {profile.can.private_notes ? (
                        <label className="inline-flex items-center gap-2 text-xs font-semibold text-slate-600 cursor-pointer select-none">
                          <button type="button" role="switch" aria-checked={isPrivate} data-action="toggle-private" onClick={() => setIsPrivate((v) => !v)} className={`relative w-9 h-5 rounded-full transition-colors ${isPrivate ? 'bg-violet-600' : 'bg-slate-300'}`}>
                            <span className={`absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-white transition-transform ${isPrivate ? 'translate-x-4' : ''}`} />
                          </button>
                          Privada: solo la ve quien tiene permiso de notas privadas
                        </label>
                      ) : <span />}
                      <Button variant="classic" type="button" onClick={addNote} disabled={busy || !body.trim()}>Guardar nota</Button>
                    </div>
                  </div>
                )}
                <PagedList
                  items={notes}
                  empty="Aún no hay notas."
                  renderRow={(n) => (
                    <li key={n.id} data-note={n.id} className="flex items-start gap-3 px-5 py-3">
                      <div className="flex-1 min-w-0">
                        <p className="text-sm text-slate-800 whitespace-pre-line break-words">{n.body}</p>
                        <span className="flex items-center gap-1.5 text-xs text-slate-500 mt-1">
                          {n.is_private && <span className="inline-flex items-center gap-1 text-violet-700 font-semibold"><EyeOff size={12} /> Privada ·</span>}
                          {n.author} · {when(n.created_at)}
                        </span>
                      </div>
                      {n.mine && (
                        <button type="button" data-action="delete-note" title="Borrar nota" onClick={() => removeNote(n.id)} className="p-1.5 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition-colors">
                          <Trash2 size={14} />
                        </button>
                      )}
                    </li>
                  )}
                />
              </>
            )}
          </div>
        )}

        {tab === 'consents' && (
          <div className="flex flex-col" data-list="consents">
            <p className="px-5 pt-3 text-xs text-slate-500">Cada vez que acepta o retira un permiso queda aquí: cuándo, quién lo cambió y desde qué dirección de internet.</p>
            {!profile.consent_history_ready ? (
              <p role="alert" className="m-5 text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">Falta actualizar la base de datos para guardar el historial: ejecuta php artisan migrate.</p>
            ) : (
              <PagedList
                items={profile.consent_history}
                empty="Todavía no hay cambios registrados."
                renderRow={(e) => (
                  <li key={e.id} data-consent-event={e.id} className="flex items-center gap-3 px-5 py-3">
                    <div className="flex-1 min-w-0">
                      <span className="block text-sm font-semibold text-slate-800">{CHANNELS[e.channel] ?? e.channel}: {e.granted ? 'aceptó' : 'lo retiró'}</span>
                      <span className="block text-xs text-slate-500 truncate">{e.by} · {when(e.at)}{e.ip ? ` · IP ${e.ip}` : ''}</span>
                    </div>
                    <Badge tone={e.granted ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'} nowrap>{e.granted ? 'Aceptado' : 'Retirado'}</Badge>
                  </li>
                )}
              />
            )}
          </div>
        )}
      </section>

      <SaleDetailPanel saleId={saleId} onClose={() => setSaleId(null)} onChanged={load} />

      {profile.loyalty && (
        <PointsAdjustDrawer
          isOpen={adjusting}
          onClose={() => setAdjusting(false)}
          customers={[{ id: customerId, name: customerName, document_number: null, email: null, phone: null, points: profile.loyalty.balance, earned: 0, redeemed: 0, movements_count: profile.loyalty.movements.length, redeemable_value: profile.loyalty.value, can_redeem: profile.loyalty.balance > 0, last_movement_at: null }]}
          initialType="adjust"
          rules={profile.loyalty.rules}
          currency={profile.currency.code}
          onSaved={() => { setAdjusting(false); load(); }}
        />
      )}
    </>
  );
}
