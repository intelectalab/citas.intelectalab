'use client';

import { useCallback, useEffect, useState } from 'react';
import { Coins, EyeOff, Gift, ShieldCheck, ShoppingBag, StickyNote, Tag, Trash2, Wallet } from 'lucide-react';
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
  money: { spent: number; balance: number; purchases: number } | null;
  points: number | null;
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

export default function CustomerProfilePanel({ customerId, refreshKey = 0 }: { customerId: string; refreshKey?: number }) {
  const [profile, setProfile] = useState<Profile | null>(null);
  const [allTags, setAllTags] = useState<CustomerTag[]>([]);
  const [colors, setColors] = useState<string[]>([]);
  const [notes, setNotes] = useState<Note[]>([]);
  const [tab, setTab] = useState<'purchases' | 'notes' | 'consents'>('purchases');
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
        if (!res.data.purchases) setTab((current) => (current === 'purchases' ? 'notes' : current));
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
      { id: 'spent', title: 'Gasto total', value: money(profile.money.spent), note: `${profile.money.purchases} ${profile.money.purchases === 1 ? 'compra' : 'compras'}`, icon: <Wallet size={18} />, color: 'text-emerald-600' },
      { id: 'balance', title: 'Saldo pendiente', value: money(profile.money.balance), note: profile.money.balance > 0 ? 'Tiene pagos por completar' : 'Está al día', icon: <Coins size={18} />, color: profile.money.balance > 0 ? 'text-rose-700' : 'text-slate-500' },
    ] : []),
    ...(profile.points !== null ? [{ id: 'points', title: 'Puntos', value: String(profile.points), note: 'Para canjear en sus compras', icon: <Gift size={18} />, color: 'text-violet-600' }] : []),
  ];

  const tabs = [
    ...(profile.purchases ? [{ id: 'purchases' as const, label: `Compras (${profile.purchases.length})`, icon: <ShoppingBag size={14} /> }] : []),
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
          <div className={`grid grid-cols-1 gap-3 ${metrics.length >= 3 ? 'sm:grid-cols-3' : metrics.length === 2 ? 'sm:grid-cols-2' : ''}`}>
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
    </>
  );
}
