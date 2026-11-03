'use client';

import OptionCard from '@/app/components/common/OptionCard';
import Button from '@/app/components/common/Button';
import { ErrorAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { useEffect, useState } from 'react';
import { AlertTriangle, Boxes, Plus, Trash2 } from 'lucide-react';
import { api } from '@/lib/axios';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Select from '@/app/components/common/Select';
import Switch from '@/app/components/common/Switch';
import TimeField from '@/app/components/common/TimeField';
import { apiError } from '@/features/appointments/appointments';
import { cleanAmount, money } from '@/features/sales/sales';
import { currencySymbol } from '@/lib/currency';
import { useAuth } from '@/hooks/useAuth';
import QuickService from '@/features/services/components/QuickService';
import {
  FREQUENCY_HINTS, LATE_HINTS, MODE_HINTS, PROFESSIONAL_HINTS, REFUND_HINTS, WEEKDAYS, hour12, sessionsLabel, sessionsPerWeek,
  type BookingMode, type CustomDay, type Frequency, type LatePolicy, type PackageOptions, type PackagePlan, type ProfessionalMode, type RefundPolicy,
} from '../packages';

interface FormPackagePlanProps {
  isOpen: boolean;
  onClose: () => void;
  initialData: PackagePlan | null;
  options: PackageOptions | null;
  onSaved: () => void;
}

interface ServiceOption {
  id: string;
  name: string;
  price: number;
}

interface ServiceRow {
  id: string;
  name: string;
  price: number | string;
  kind?: string;
  status?: { slug?: string } | null;
}

interface Row {
  key: string;
  serviceId: string;
  sessions: string;
}

const sectionClass = 'space-y-3 rounded-2xl border border-slate-200 p-4';
const sectionTitle = 'text-sm font-black text-brand-ink';

let rowSeed = 0;
const newRow = (serviceId = '', sessions = '5'): Row => ({ key: `row-${rowSeed++}`, serviceId, sessions });

const empty = {
  name: '',
  description: '',
  price: '',
  validityDays: '90',
  active: true,
  sellOnline: false,
  bookingMode: 'free' as BookingMode,
  frequency: 'weekly' as Frequency,
  customDays: [] as CustomDay[],
  repeatWeeks: 1,
  professionalMode: 'chosen' as ProfessionalMode,
  latePolicy: 'lose' as LatePolicy,
  refundPolicy: 'unused_normal' as RefundPolicy,
  terms: '',
  customTerms: false,
};

export default function FormPackagePlan({ isOpen, onClose, initialData, options, onSaved }: FormPackagePlanProps) {
  const { user, can } = useAuth();
  const [form, setForm] = useState(empty);
  const [rows, setRows] = useState<Row[]>([newRow()]);
  const [services, setServices] = useState<ServiceOption[]>([]);
  const [quickFor, setQuickFor] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!isOpen) return;

    setError('');
    api.get('/services')
      .then((res) => {
        const list: ServiceRow[] = Array.isArray(res.data) ? res.data : res.data?.data ?? [];
        setServices(list
          .filter((s) => s.status?.slug !== 'inactive' && (s.kind ?? 'individual') === 'individual')
          .map((s) => ({ id: s.id, name: s.name, price: Number(s.price) }))
          .sort((a, b) => a.name.localeCompare(b.name)));
      })
      .catch(() => setServices([]));

    if (initialData) {
      setForm({
        name: initialData.name,
        description: initialData.description ?? '',
        price: String(initialData.price),
        validityDays: String(initialData.validity_days),
        active: initialData.status === 'active',
        sellOnline: Boolean(initialData.sell_online),
        bookingMode: initialData.booking_mode,
        frequency: initialData.frequency,
        customDays: initialData.custom_days ?? [],
        repeatWeeks: initialData.repeat_weeks || 1,
        professionalMode: initialData.professional_mode,
        latePolicy: initialData.late_policy,
        refundPolicy: initialData.refund_policy,
        terms: initialData.terms ?? '',
        customTerms: Boolean(initialData.terms),
      });
      setRows(initialData.items.map((item) => newRow(item.service_id, String(item.sessions))));
    } else {
      setForm(empty);
      setRows([newRow()]);
    }
  }, [isOpen, initialData]);

  const set = (changes: Partial<typeof empty>) => setForm((current) => ({ ...current, ...changes }));
  const changeRow = (key: string, changes: Partial<Row>) => setRows((current) => current.map((row) => (row.key === key ? { ...row, ...changes } : row)));

  const currency = initialData?.currency;
  const chosen = rows
    .map((row) => ({ row, service: services.find((s) => s.id === row.serviceId), sessions: Math.max(0, Number(row.sessions) || 0) }))
    .filter((entry) => entry.service && entry.sessions > 0);
  const totalSessions = chosen.reduce((sum, entry) => sum + entry.sessions, 0);
  const normal = chosen.reduce((sum, entry) => sum + entry.sessions * (entry.service?.price ?? 0), 0);
  const price = Number(form.price || 0);
  const savings = normal > price && price > 0 ? normal - price : 0;
  const scheduled = form.bookingMode === 'scheduled';

  const weeksNeeded = scheduled && totalSessions > 0 ? totalSessions / sessionsPerWeek(form.frequency, form.customDays, form.repeatWeeks) : 0;
  const daysNeeded = Math.ceil(weeksNeeded * 7);
  const tooShort = scheduled && daysNeeded > Number(form.validityDays || 0);
  const cancelHours = Number(user?.tenant?.rules?.['cancel.window_hours'] ?? 24);

  const scheduleText = (() => {
    if (form.frequency !== 'custom') return options?.frequencies.find((f) => f.value === form.frequency)?.label.toLowerCase() ?? '';
    const days = [...form.customDays].sort((a, b) => a.weekday - b.weekday).map((d) => `${WEEKDAYS[d.weekday]}${d.time ? ` ${hour12(d.time)}` : ''}`);
    const last = days.pop();
    return `${days.length ? `${days.join(', ')} y ${last}` : last ?? ''}, ${form.repeatWeeks === 2 ? 'cada 2 semanas' : 'cada semana'}`;
  })();

  const generated = (() => {
    const parts = chosen.map((entry) => `${sessionsLabel(entry.sessions)} de ${entry.service!.name}`);
    const last = parts.pop();
    const what = parts.length ? `${parts.join(', ')} y ${last}` : last ?? 'Las sesiones';
    let text = `${what.charAt(0).toUpperCase()}${what.slice(1)}, válidas ${form.validityDays || '—'} días desde la compra.`;
    if (scheduled) text += ` Las fechas se acuerdan al comprarlo (${scheduleText}).`;
    text += form.latePolicy === 'lose'
      ? ` Si no puedes venir, avisa con ${cancelHours} h de anticipación para reprogramar; si no, la sesión se pierde.`
      : ' Si no puedes venir, la sesión se reprograma.';
    if (form.refundPolicy === 'none') text += ' No se devuelve el dinero.';
    else if (form.refundPolicy === 'unused_package') text += ' Puedes pedir la devolución de lo no usado mientras esté vigente, al precio del paquete.';
    else text += ` Puedes pedir la devolución de lo no usado mientras esté vigente: las sesiones usadas se cobran a su precio normal (${chosen.map((entry) => `${entry.service!.name} a ${money(entry.service!.price, currency)}`).join(', ') || 'su precio de lista'}).`;
    return text;
  })();

  const toggleDay = (weekday: number, on: boolean) => {
    const days = on
      ? [...form.customDays, { weekday, time: form.customDays.find((d) => d.time)?.time ?? null }]
      : form.customDays.filter((d) => d.weekday !== weekday);
    set({ customDays: days.sort((a, b) => a.weekday - b.weekday) });
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    const payload = {
      name: form.name.trim(),
      description: form.description.trim() || null,
      price: Number(form.price || 0),
      validity_days: Number(form.validityDays || 0),
      status: form.active ? 'active' : 'inactive',
      sell_online: form.bookingMode === 'free' && form.sellOnline,
      booking_mode: form.bookingMode,
      frequency: form.frequency,
      custom_days: form.frequency === 'custom' ? form.customDays : [],
      repeat_weeks: form.repeatWeeks,
      professional_mode: form.professionalMode,
      late_policy: form.latePolicy,
      refund_policy: form.refundPolicy,
      terms: form.customTerms && form.terms.trim() && form.terms.trim() !== generated ? form.terms.trim() : null,
      items: rows.filter((row) => row.serviceId).map((row) => ({ service_id: row.serviceId, sessions: Number(row.sessions || 0) })),
    };

    try {
      setSaving(true);
      setError('');
      if (initialData) await api.put(`/package-plans/${initialData.id}`, payload);
      else await api.post('/package-plans', payload);
      onSaved();
      onClose();
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar el paquete.'));
    } finally {
      setSaving(false);
    }
  };

  const formId = 'package-plan-form';
  const used = new Set(rows.map((row) => row.serviceId).filter(Boolean));

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title={initialData ? `Editar: ${initialData.name}` : 'Nuevo paquete'}
      subtitle={initialData ? (initialData.sold ? `Vendido ${initialData.sold} ${initialData.sold === 1 ? 'vez' : 'veces'}: los cambios no tocan lo ya vendido` : 'Aún no se vende') : 'Sesiones que el cliente paga por adelantado, con su precio y sus condiciones'}
      icon={<Boxes size={20} />}
    >
      <div className="flex flex-col h-full justify-between">
        <form id={formId} onSubmit={handleSubmit} className="space-y-5 overflow-y-auto flex-1 pr-1 pb-4" data-package-form>
          <section className={sectionClass}>
            <h3 className={sectionTitle}>Qué incluye</h3>
            <div>
              <label className={labelClass} htmlFor="package-name">Nombre *</label>
              <input id="package-name" required maxLength={150} placeholder="Ej. Rehabilitación de rodilla" className={inputClass} value={form.name} onChange={(e) => set({ name: e.target.value })} />
            </div>
            <div>
              <label className={labelClass} htmlFor="package-description">Descripción</label>
              <input id="package-description" maxLength={500} placeholder="Ej. Dos sesiones por semana con tu terapeuta" className={inputClass} value={form.description} onChange={(e) => set({ description: e.target.value })} />
            </div>

            <div className="space-y-2" data-package-items>
              <span className={labelClass}>Servicios y sesiones *</span>
              {rows.map((row) => (
                <div key={row.key} className="flex items-start gap-2" data-package-row>
                  <div className="flex-1 min-w-0">
                    <Select
                      ariaLabel="Servicio"
                      placeholder="Elige el servicio"
                      value={row.serviceId}
                      clearable={false}
                      onChange={(e) => changeRow(row.key, { serviceId: String(e.target.value) })}
                      options={services.filter((s) => s.id === row.serviceId || !used.has(s.id)).map((s) => ({ value: s.id, label: `${s.name} · ${money(s.price, currency)}` }))}
                      onAdd={can('services.create') ? () => setQuickFor(row.key) : undefined}
                      addLabel="Nuevo servicio"
                    />
                  </div>
                  <div className="w-24 shrink-0">
                    <input aria-label="Sesiones" inputMode="numeric" className={`${inputClass} text-center`} value={row.sessions} onChange={(e) => changeRow(row.key, { sessions: e.target.value.replace(/\D/g, '').slice(0, 3) })} />
                  </div>
                  {rows.length > 1 && (
                    <button type="button" title="Quitar servicio" onClick={() => setRows((current) => current.filter((r) => r.key !== row.key))} className="mt-1 p-2 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors">
                      <Trash2 size={15} />
                    </button>
                  )}
                </div>
              ))}
              {!scheduled && rows.length < 10 && (
                <button type="button" data-add-row onClick={() => setRows((current) => [...current, newRow('', '1')])} className="flex items-center gap-1.5 text-xs font-bold text-brand-accent hover:text-brand-ink transition-colors">
                  <Plus size={14} /> Agregar otro servicio
                </button>
              )}
              {scheduled && <p className="text-xs text-slate-500">Con fechas acordadas, el paquete lleva un solo servicio.</p>}
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className={labelClass} htmlFor="package-price">Precio ({currencySymbol()}) *</label>
                <input id="package-price" required inputMode="decimal" placeholder="450.00" className={inputClass} value={form.price} onChange={(e) => set({ price: cleanAmount(e.target.value) })} />
              </div>
              <div>
                <label className={labelClass} htmlFor="package-days">Vale por (días) *</label>
                <input id="package-days" required inputMode="numeric" className={inputClass} value={form.validityDays} onChange={(e) => set({ validityDays: e.target.value.replace(/\D/g, '').slice(0, 4) })} />
              </div>
            </div>
            {totalSessions > 0 && (
              <p className={`text-xs rounded-lg px-3 py-2 ${savings > 0 ? 'text-emerald-800 bg-emerald-50 border border-emerald-100' : 'text-slate-600 bg-slate-50 border border-slate-200'}`} data-savings>
                {sessionsLabel(totalSessions)} · por separado costarían {money(normal, currency)}.
                {savings > 0 ? ` Ahorro: ${money(savings, currency)} (${Math.round((savings / normal) * 100)} %).` : price > 0 ? ' Con este precio el cliente no ahorra nada.' : ''}
              </p>
            )}
            <p className="text-xs text-slate-500">La vigencia cuenta desde el día de la compra.</p>
          </section>

          <section className={sectionClass}>
            <h3 className={sectionTitle}>Cómo se agenda</h3>
            <div className="grid grid-cols-1 gap-2" role="radiogroup" aria-label="Cómo se agenda">
              {(options?.modes ?? []).map((option) => (
                <OptionCard key={option.value} type="button" role="radio" aria-checked={form.bookingMode === option.value} data-mode={option.value}
                  onClick={() => { set({ bookingMode: option.value as BookingMode }); if (option.value === 'scheduled') setRows((current) => current.slice(0, 1)); }} selected={form.bookingMode === option.value} className="text-left rounded-xl border px-4 py-2.5 transition-colors">
                  <span className="block text-sm font-bold text-brand-ink">{option.label}</span>
                  <span className="block text-xs text-slate-500 mt-0.5">{MODE_HINTS[option.value as BookingMode]}</span>
                </OptionCard>
              ))}
            </div>

            {scheduled && (
              <div className="space-y-3" data-schedule-settings>
                <Select
                  label="Frecuencia"
                  value={form.frequency}
                  clearable={false}
                  onChange={(e) => set({ frequency: String(e.target.value) as Frequency })}
                  options={(options?.frequencies ?? []).map((f) => ({ value: f.value, label: f.label }))}
                />
                <p className="-mt-2 text-xs text-slate-500">{FREQUENCY_HINTS[form.frequency]}. El día y la hora exactos se eligen al venderlo.</p>

                {form.frequency === 'custom' && (
                  <div className="rounded-xl border border-slate-200 divide-y divide-slate-100" data-custom-days>
                    {[1, 2, 3, 4, 5, 6, 0].map((weekday) => {
                      const day = form.customDays.find((d) => d.weekday === weekday);
                      return (
                        <div key={weekday} className="flex items-center gap-3 px-3.5 py-2" data-weekday={weekday}>
                          <Switch checked={Boolean(day)} onChange={(on) => toggleDay(weekday, on)} label={`Sesión los ${WEEKDAYS[weekday]}`} />
                          <span className={`w-24 text-sm capitalize ${day ? 'font-semibold text-slate-800' : 'text-slate-400'}`}>{WEEKDAYS[weekday]}</span>
                          {day && (
                            <div className="w-40">
                              <TimeField size="compact" ariaLabel={`Hora del ${WEEKDAYS[weekday]}`} placeholder="Hora (opcional)" value={day.time ?? ''} clearable
                                onChange={(time) => set({ customDays: form.customDays.map((d) => (d.weekday === weekday ? { ...d, time: time || null } : d)) })} />
                            </div>
                          )}
                        </div>
                      );
                    })}
                    <div className="flex items-center justify-between gap-3 px-3.5 py-2.5">
                      <span className="text-sm text-slate-600">Repetir</span>
                      <div className="flex gap-1.5">
                        {[1, 2].map((weeks) => (
                          <button key={weeks} type="button" data-repeat={weeks} onClick={() => set({ repeatWeeks: weeks })}
                            className={`px-3 py-1.5 text-xs font-semibold rounded-lg border ${form.repeatWeeks === weeks ? 'bg-brand text-white border-brand' : 'border-slate-200 text-slate-600 hover:bg-slate-50'}`}>
                            {weeks === 1 ? 'Cada semana' : 'Cada 2 semanas'}
                          </button>
                        ))}
                      </div>
                    </div>
                  </div>
                )}
                {form.frequency === 'custom' && <p className="-mt-1 text-xs text-slate-500">La hora es opcional: si la dejas vacía, se elige con el cliente al venderlo.</p>}

                <div>
                  <span className={labelClass}>Quién atiende</span>
                  <div className="grid grid-cols-1 gap-2" role="radiogroup" aria-label="Quién atiende">
                    {(options?.professional_modes ?? []).map((option) => (
                      <OptionCard key={option.value} type="button" role="radio" aria-checked={form.professionalMode === option.value} data-pro-mode={option.value}
                        onClick={() => set({ professionalMode: option.value as ProfessionalMode })} selected={form.professionalMode === option.value} className="text-left rounded-xl border px-4 py-2.5 transition-colors">
                        <span className="block text-sm font-bold text-brand-ink">{option.label}</span>
                        <span className="block text-xs text-slate-500 mt-0.5">{PROFESSIONAL_HINTS[option.value as ProfessionalMode]}</span>
                      </OptionCard>
                    ))}
                  </div>
                </div>

                {tooShort && (
                  <p className="flex items-start gap-2 text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2" data-too-short>
                    <AlertTriangle size={14} className="shrink-0 mt-0.5" />
                    {sessionsLabel(totalSessions)} con esta frecuencia necesitan unos {daysNeeded} días y el paquete vale {form.validityDays}. Al venderlo, las fechas que pasen del vencimiento no se podrán agendar.
                  </p>
                )}
              </div>
            )}
          </section>

          <section className={sectionClass}>
            <h3 className={sectionTitle}>Si cancela o no llega</h3>
            <div className="grid grid-cols-1 gap-2" role="radiogroup" aria-label="Si cancela o no llega">
              {(options?.late_policies ?? []).map((option) => (
                <OptionCard key={option.value} type="button" role="radio" aria-checked={form.latePolicy === option.value} data-late={option.value}
                  onClick={() => set({ latePolicy: option.value as LatePolicy })} selected={form.latePolicy === option.value} className="text-left rounded-xl border px-4 py-2.5 transition-colors">
                  <span className="block text-sm font-bold text-brand-ink">{option.label}</span>
                  <span className="block text-xs text-slate-500 mt-0.5">{LATE_HINTS[option.value as LatePolicy]}</span>
                </OptionCard>
              ))}
            </div>
            <p className="text-xs text-slate-500">&quot;A tiempo&quot; es con {cancelHours} h de anticipación, el plazo de cancelación de tu empresa (Configuración → Reglas de reserva).</p>
          </section>

          <section className={sectionClass}>
            <h3 className={sectionTitle}>Si quiere su dinero de vuelta</h3>
            <div className="grid grid-cols-1 gap-2" role="radiogroup" aria-label="Devolución">
              {(options?.refund_policies ?? []).map((option) => (
                <OptionCard key={option.value} type="button" role="radio" aria-checked={form.refundPolicy === option.value} data-refund={option.value}
                  onClick={() => set({ refundPolicy: option.value as RefundPolicy })} selected={form.refundPolicy === option.value} className="text-left rounded-xl border px-4 py-2.5 transition-colors">
                  <span className="block text-sm font-bold text-brand-ink">{option.label}</span>
                  <span className="block text-xs text-slate-500 mt-0.5">{REFUND_HINTS[option.value as RefundPolicy]}</span>
                </OptionCard>
              ))}
            </div>
            <p className="text-xs text-slate-500">Si un paquete no tiene devolución, revisa que tu empresa lo diga claramente al venderlo: la ley de protección al consumidor protege los pagos por adelantado.</p>
          </section>

          <section className={sectionClass} data-terms>
            <div className="flex items-center justify-between gap-3">
              <h3 className={sectionTitle}>Texto que ve el cliente</h3>
              <label className="flex items-center gap-2 text-xs font-semibold text-slate-600">
                Escribirlo a mano
                <Switch checked={form.customTerms} onChange={(on) => set({ customTerms: on, terms: on && !form.terms ? generated : form.terms })} label="Escribir el texto a mano" />
              </label>
            </div>
            {form.customTerms ? (
              <textarea aria-label="Texto para el cliente" rows={5} maxLength={2000} className={inputClass} value={form.terms} onChange={(e) => set({ terms: e.target.value })} />
            ) : (
              <p className="text-sm text-slate-700 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2.5 leading-relaxed" data-terms-text>{generated}</p>
            )}
            <p className="text-xs text-slate-500">Sale en el detalle de la venta y en &quot;Mis paquetes&quot; del portal, para que el cliente sepa qué pasa si falta o se arrepiente.</p>
          </section>

          <div className="flex items-center justify-between gap-3 border border-slate-200 rounded-xl px-4 py-3">
            <div>
              <span className="block text-sm font-semibold text-slate-800">Se vende</span>
              <span className="block text-xs text-slate-500">Apágalo para dejar de ofrecerlo sin borrarlo. Los ya vendidos siguen igual.</span>
            </div>
            <Switch checked={form.active} onChange={(active) => set({ active })} label="Paquete a la venta" />
          </div>

          <div className="flex items-center justify-between gap-3 border border-slate-200 rounded-xl px-4 py-3" data-field="sell-online">
            <div>
              <span className="block text-sm font-semibold text-slate-800">Se vende en línea</span>
              <span className="block text-xs text-slate-500">
                {form.bookingMode === 'free'
                  ? 'El cliente lo ve en su portal y lo compra pagando en línea. El paquete queda a su nombre al pagar.'
                  : 'Solo los paquetes de uso libre: los de fechas acordadas se venden en la sede, porque hay que elegir días y profesional.'}
              </span>
            </div>
            <Switch checked={form.bookingMode === 'free' && form.sellOnline} disabled={form.bookingMode !== 'free'} onChange={(sellOnline) => set({ sellOnline })} label="Se vende en línea" />
          </div>

          <ErrorAlert message={error} />
        </form>

        <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
          <Button type="button" onClick={onClose} variant="secondary">
            Cancelar
          </Button>
          <Button type="submit" form={formId} data-submit disabled={saving} variant="primary">
            {saving ? 'Guardando...' : initialData ? 'Guardar cambios' : 'Crear paquete'}
          </Button>
        </div>
      </div>

      <QuickService
        isOpen={quickFor !== null}
        onClose={() => setQuickFor(null)}
        onCreated={(created) => {
          setServices((current) => [...current, { id: created.id, name: created.name, price: Number(created.price) }].sort((a, b) => a.name.localeCompare(b.name)));
          if (quickFor) changeRow(quickFor, { serviceId: created.id });
          setQuickFor(null);
        }}
      />
    </FormDrawer>
  );
}
