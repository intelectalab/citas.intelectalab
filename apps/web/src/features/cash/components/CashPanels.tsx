'use client';

import { useEffect, useState } from 'react';
import { ArrowDownCircle, ArrowUpCircle, Banknote, Calculator, Lock, Unlock, Wallet } from 'lucide-react';
import { api } from '@/lib/axios';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Select from '@/app/components/common/Select';
import Switch from '@/app/components/common/Switch';
import { PagedList } from '@/app/components/common/Pager';
import { apiError } from '@/features/appointments/appointments';
import { cleanAmount, money, momentOf, type CashMovement, type CashRegister, type CashSession } from '@/features/sales/sales';

const inputClass =
  'w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800 bg-white';
const labelClass = 'block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5';
const sectionTitle = 'text-[11px] font-bold text-slate-400 uppercase tracking-wider';
const cancelButton = 'px-4 py-2.5 text-sm font-semibold text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-50 transition-colors';
const primaryButton = 'px-5 py-2.5 text-sm font-bold bg-[#022340] text-white rounded-xl hover:bg-[#03315a] shadow-sm transition-colors disabled:opacity-50';
const errorClass = 'text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2';

interface PanelProps {
  onClose: () => void;
  onSaved: () => void;
}

const Footer = ({ onClose, formId, saving, label, savingLabel }: { onClose: () => void; formId: string; saving: boolean; label: string; savingLabel: string }) => (
  <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
    <button type="button" onClick={onClose} className={cancelButton}>Cancelar</button>
    <button type="submit" form={formId} data-submit disabled={saving} className={primaryButton}>{saving ? savingLabel : label}</button>
  </div>
);

export function OpenCashPanel({ register, onClose, onSaved }: PanelProps & { register: CashRegister | null }) {
  const [amount, setAmount] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => { if (register) { setAmount(''); setError(''); } }, [register]);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!register) return;

    try {
      setSaving(true);
      setError('');
      await api.post(`/cash/registers/${register.id}/open`, { opening_amount: Number(amount || 0) });
      onSaved();
      onClose();
    } catch (err) {
      setError(apiError(err, 'No se pudo abrir la caja.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <FormDrawer isOpen={register !== null} onClose={onClose} title={`Abrir ${register?.name ?? 'caja'}`} subtitle={register?.branch_name ?? undefined} icon={<Unlock size={20} />}>
      <div className="flex flex-col h-full justify-between">
        <form id="open-cash-form" onSubmit={submit} className="space-y-5 flex-1">
          <div>
            <label className={labelClass} htmlFor="open-amount">Efectivo con que se abre</label>
            <input id="open-amount" inputMode="decimal" autoFocus placeholder="0.00" className={inputClass} value={amount} onChange={(e) => setAmount(cleanAmount(e.target.value))} />
            <p className="text-xs text-slate-500 mt-1.5">El sencillo que hay en la caja al empezar el turno. Si está vacía, déjalo en 0.</p>
          </div>
          {error && <p role="alert" className={errorClass}>{error}</p>}
        </form>
        <Footer onClose={onClose} formId="open-cash-form" saving={saving} label="Abrir caja" savingLabel="Abriendo..." />
      </div>
    </FormDrawer>
  );
}

const MOVEMENT_TYPES: { id: 'income' | 'expense' | 'withdrawal'; title: string; hint: string; icon: React.ReactNode }[] = [
  { id: 'income', title: 'Ingreso: se pone dinero en la caja', hint: 'Dinero que entra y no es el pago de un cliente. Por ejemplo, agregar monedas y billetes chicos para poder dar vuelto.', icon: <ArrowDownCircle size={18} /> },
  { id: 'expense', title: 'Gasto: se paga algo con la caja', hint: 'Por ejemplo, un taxi, útiles de limpieza o un pedido a un proveedor.', icon: <ArrowUpCircle size={18} /> },
  { id: 'withdrawal', title: 'Retiro: se saca dinero de la caja', hint: 'Para guardarlo en otro lugar o llevarlo al banco. No es un gasto: el dinero sigue siendo de la empresa.', icon: <Banknote size={18} /> },
];

export function CashMovementPanel({ session, registerName, onClose, onSaved }: PanelProps & { session: CashSession | null; registerName?: string }) {
  const [type, setType] = useState<'income' | 'expense' | 'withdrawal'>('expense');
  const [amount, setAmount] = useState('');
  const [description, setDescription] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => { if (session) { setType('expense'); setAmount(''); setDescription(''); setError(''); } }, [session]);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!session) return;

    try {
      setSaving(true);
      setError('');
      await api.post(`/cash/sessions/${session.id}/movements`, { type, amount: Number(amount || 0), description: description.trim() });
      onSaved();
      onClose();
    } catch (err) {
      setError(apiError(err, 'No se pudo registrar el movimiento.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <FormDrawer isOpen={session !== null} onClose={onClose} title="Movimiento de caja" subtitle={registerName ? `${registerName} · hay ${money(session?.expected_amount, session?.currency)} en efectivo` : undefined} icon={<Wallet size={20} />}>
      <div className="flex flex-col h-full justify-between">
        <form id="cash-movement-form" onSubmit={submit} className="space-y-5 flex-1 overflow-y-auto pr-1 pb-4">
          <div className="space-y-2" role="radiogroup" aria-label="Tipo de movimiento">
            <span className={labelClass}>¿Qué pasó?</span>
            {MOVEMENT_TYPES.map((option) => {
              const chosen = type === option.id;
              return (
                <button
                  key={option.id}
                  type="button"
                  role="radio"
                  aria-checked={chosen}
                  data-movement-type={option.id}
                  onClick={() => setType(option.id)}
                  className={`w-full flex items-start gap-3 text-left rounded-xl border px-4 py-3 transition-colors ${chosen ? 'border-[#022340] bg-[#84D0D9]/15 ring-2 ring-[#022340]/10' : 'border-slate-200 hover:bg-slate-50'}`}
                >
                  <span className={`p-2 rounded-lg shrink-0 ${chosen ? 'bg-[#022340] text-[#84D0D9]' : 'bg-slate-100 text-slate-500'}`}>{option.icon}</span>
                  <span>
                    <span className="block text-sm font-bold text-[#022340]">{option.title}</span>
                    <span className="block text-xs text-slate-500 mt-0.5">{option.hint}</span>
                  </span>
                </button>
              );
            })}
          </div>

          <div>
            <label className={labelClass} htmlFor="movement-amount">Monto *</label>
            <input id="movement-amount" inputMode="decimal" required placeholder="0.00" className={inputClass} value={amount} onChange={(e) => setAmount(cleanAmount(e.target.value))} />
          </div>

          <div>
            <label className={labelClass} htmlFor="movement-description">Motivo *</label>
            <input id="movement-description" required maxLength={255} placeholder="Ej. Taxi para llevar muestras" className={inputClass} value={description} onChange={(e) => setDescription(e.target.value)} />
          </div>

          <p className="text-xs text-slate-500">Los cobros a clientes no se anotan aquí: entran solos a la caja al cobrar. Cada movimiento guarda la fecha, la hora y quién lo registró; se ven en "Ver turno".</p>

          {error && <p role="alert" className={errorClass}>{error}</p>}
        </form>
        <Footer onClose={onClose} formId="cash-movement-form" saving={saving} label="Registrar" savingLabel="Registrando..." />
      </div>
    </FormDrawer>
  );
}

export function CloseCashPanel({ session, registerName, tolerance, onClose, onSaved }: PanelProps & { session: CashSession | null; registerName?: string; tolerance: number }) {
  const [counted, setCounted] = useState('');
  const [notes, setNotes] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => { if (session) { setCounted(''); setNotes(''); setError(''); } }, [session]);

  const expected = session?.expected_amount ?? 0;
  const difference = counted === '' ? null : Math.round((Number(counted) - expected) * 100) / 100;
  const needsNote = difference !== null && Math.abs(difference) > tolerance + 0.001;

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!session) return;

    try {
      setSaving(true);
      setError('');
      await api.post(`/cash/sessions/${session.id}/close`, { counted_amount: Number(counted || 0), notes: notes.trim() || null });
      onSaved();
      onClose();
    } catch (err) {
      setError(apiError(err, 'No se pudo cerrar la caja.'));
    } finally {
      setSaving(false);
    }
  };

  const rows: [string, number, boolean][] = session ? [
    ['Apertura', session.opening_amount, true],
    ['Cobros en efectivo', session.sales, true],
    ['Ingresos', session.income, true],
    ['Gastos', session.expense, false],
    ['Retiros', session.withdrawal, false],
    ['Devoluciones', session.refund, false],
  ] : [];

  return (
    <FormDrawer isOpen={session !== null} onClose={onClose} title={`Cerrar ${registerName ?? 'caja'}`} subtitle="Cuenta el efectivo y compáralo con lo que debía haber" icon={<Lock size={20} />}>
      <div className="flex flex-col h-full justify-between">
        <form id="close-cash-form" onSubmit={submit} className="space-y-5 flex-1 overflow-y-auto pr-1 pb-4">
          <div className="rounded-2xl border border-slate-200 overflow-hidden">
            <div className="px-5 py-3 space-y-1.5 text-sm">
              {rows.filter(([, value], index) => index === 0 || value > 0).map(([label, value, adds]) => (
                <div key={label} className="flex justify-between text-slate-600">
                  <span>{label}</span>
                  <span className="tabular-nums">{adds ? '' : '− '}{money(value, session?.currency)}</span>
                </div>
              ))}
            </div>
            <div className="flex items-center justify-between px-5 py-3 bg-slate-50/80 border-t border-slate-100">
              <span className="text-sm font-bold text-[#022340]">Debe haber en efectivo</span>
              <span className="text-xl font-black text-[#022340] tabular-nums" data-expected>{money(expected, session?.currency)}</span>
            </div>
          </div>

          <div>
            <label className={labelClass} htmlFor="close-counted">Efectivo contado *</label>
            <input id="close-counted" inputMode="decimal" required autoFocus placeholder="0.00" className={inputClass} value={counted} onChange={(e) => setCounted(cleanAmount(e.target.value))} />
            {difference !== null && (
              <p data-difference className={`flex items-center gap-1.5 text-sm font-semibold mt-2 ${difference === 0 ? 'text-emerald-700' : needsNote ? 'text-rose-700' : 'text-amber-700'}`}>
                <Calculator size={15} />
                {difference === 0 ? 'La caja cuadra.' : `${difference < 0 ? 'Faltan' : 'Sobran'} ${money(Math.abs(difference), session?.currency)}${needsNote ? '' : ' (dentro de la tolerancia)'}.`}
              </p>
            )}
          </div>

          <div>
            <label className={labelClass} htmlFor="close-notes">Observaciones{needsNote ? ' *' : ''}</label>
            <textarea id="close-notes" rows={3} maxLength={500} required={needsNote} placeholder={needsNote ? 'Explica a qué se debe la diferencia' : 'Opcional'} className={inputClass} value={notes} onChange={(e) => setNotes(e.target.value)} />
            {needsNote && <p className="text-xs text-slate-500 mt-1.5">Con una diferencia mayor que {money(tolerance, session?.currency)} hay que explicarla, y la administración recibe un aviso.</p>}
          </div>

          {error && <p role="alert" className={errorClass}>{error}</p>}
        </form>
        <Footer onClose={onClose} formId="close-cash-form" saving={saving} label="Cerrar caja" savingLabel="Cerrando..." />
      </div>
    </FormDrawer>
  );
}

export function RegisterPanel({ isOpen, register, branches, onClose, onSaved }: PanelProps & { isOpen: boolean; register: CashRegister | null; branches: { id: string; name: string }[] }) {
  const [branchId, setBranchId] = useState('');
  const [name, setName] = useState('');
  const [active, setActive] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!isOpen) return;
    setError('');
    setBranchId(register?.branch_id ?? (branches.length === 1 ? branches[0].id : ''));
    setName(register?.name ?? '');
    setActive(register ? register.status === 'active' : true);
  }, [isOpen, register, branches]);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!branchId) {
      setError('Elige la sede de la caja.');
      return;
    }

    try {
      setSaving(true);
      setError('');
      if (register) {
        await api.put(`/cash/registers/${register.id}`, { name: name.trim(), status: active ? 'active' : 'inactive' });
      } else {
        await api.post('/cash/registers', { branch_id: branchId, name: name.trim() });
      }
      onSaved();
      onClose();
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar la caja.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <FormDrawer isOpen={isOpen} onClose={onClose} title={register ? `Editar: ${register.name}` : 'Nueva caja'} subtitle={register ? `En ${register.branch_name ?? 'su sede'}` : 'Otro punto de cobro en una sede'} icon={<Wallet size={20} />}>
      <div className="flex flex-col h-full justify-between">
        <form id="register-form" onSubmit={submit} className="space-y-5 flex-1">
          <Select
            label="Sede *"
            placeholder="Elige la sede"
            clearable={false}
            disabled={Boolean(register)}
            value={branchId}
            onChange={(e) => setBranchId(String(e.target.value))}
            options={(register && !branches.some((b) => b.id === register.branch_id) ? [...branches, { id: register.branch_id, name: register.branch_name ?? 'Sede' }] : branches).map((b) => ({ value: b.id, label: b.name }))}
          />

          <div>
            <label className={labelClass} htmlFor="register-name">Nombre *</label>
            <input id="register-name" required maxLength={100} placeholder="Ej. Caja recepción" className={inputClass} value={name} onChange={(e) => setName(e.target.value)} />
          </div>

          {register && (
            <div className="flex items-center justify-between gap-3 border border-slate-200 rounded-xl px-4 py-3">
              <div>
                <span className="block text-sm font-semibold text-slate-800">En uso</span>
                <span className="block text-xs text-slate-500">Apágala si ya no se cobra en ella. Sus turnos anteriores se conservan.</span>
              </div>
              <Switch checked={active} onChange={setActive} label="Caja en uso" />
            </div>
          )}

          {error && <p role="alert" className={errorClass}>{error}</p>}
        </form>
        <Footer onClose={onClose} formId="register-form" saving={saving} label={register ? 'Guardar cambios' : 'Crear caja'} savingLabel="Guardando..." />
      </div>
    </FormDrawer>
  );
}

export function CashSessionPanel({ sessionId, onClose }: { sessionId: string | null; onClose: () => void }) {
  const [session, setSession] = useState<CashSession | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!sessionId) return;
    setSession(null);
    setError('');
    api.get(`/cash/sessions/${sessionId}`)
      .then((res) => setSession(res.data))
      .catch((err) => setError(apiError(err, 'No se pudo cargar el turno.')));
  }, [sessionId]);

  const stat = (label: string, value: string, tone = 'text-slate-800') => (
    <div>
      <dt className={sectionTitle}>{label}</dt>
      <dd className={`text-sm font-semibold ${tone}`}>{value}</dd>
    </div>
  );

  return (
    <FormDrawer
      isOpen={sessionId !== null}
      onClose={onClose}
      title={session ? `Turno de ${session.register_name ?? 'caja'}` : 'Turno de caja'}
      subtitle={session ? `${session.branch_name ?? ''} · ${session.status === 'open' ? 'abierto' : 'cerrado'}` : undefined}
      icon={<Wallet size={20} />}
    >
      {!session ? (
        <p role={error ? 'alert' : undefined} className={`text-sm ${error ? 'text-red-600' : 'text-slate-500'}`}>{error || 'Cargando turno...'}</p>
      ) : (
        <div className="space-y-6 pb-4" data-session={session.status}>
          <dl className="grid grid-cols-2 gap-x-4 gap-y-3">
            {stat('Abrió', `${session.opened_by ?? '—'} · ${momentOf(session.opened_at)}`)}
            {stat('Cerró', session.closed_at ? `${session.closed_by ?? '—'} · ${momentOf(session.closed_at)}` : 'Sigue abierto')}
            {stat('Apertura', money(session.opening_amount, session?.currency))}
            {stat(session.status === 'open' ? 'Debe haber ahora' : 'Debía haber', money(session.expected_amount, session?.currency))}
            {session.status === 'closed' && stat('Contado', money(session.closing_amount, session?.currency))}
            {session.status === 'closed' && stat(
              'Diferencia',
              session.difference === 0 ? 'Cuadró' : `${(session.difference ?? 0) < 0 ? 'Faltaron' : 'Sobraron'} ${money(Math.abs(session.difference ?? 0), session?.currency)}`,
              session.difference === 0 ? 'text-emerald-700' : 'text-rose-700',
            )}
          </dl>

          {session.closing_notes && (
            <p className="text-sm text-slate-600 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2">{session.closing_notes}</p>
          )}

          {session.by_method.length > 0 && (
            <section className="space-y-2">
              <span className={sectionTitle}>Cobrado en la sede durante el turno</span>
              <div className="grid grid-cols-2 gap-2">
                {session.by_method.map((row) => (
                  <div key={row.method} className="rounded-xl border border-slate-200 px-4 py-3">
                    <span className="block text-xs text-slate-500">{row.label} · {row.count} {row.count === 1 ? 'pago' : 'pagos'}</span>
                    <span className="block text-base font-black text-[#022340] tabular-nums">{money(row.total, session?.currency)}</span>
                  </div>
                ))}
              </div>
            </section>
          )}

          <section className="rounded-2xl border border-slate-200 overflow-hidden flex flex-col">
            <div className="px-5 py-3 bg-slate-50/80 border-b border-slate-100">
              <span className={sectionTitle}>Movimientos de efectivo</span>
            </div>
            <PagedList<CashMovement>
              items={session.movements ?? []}
              empty="Aún no hay movimientos en este turno."
              renderRow={(movement) => (
                <li key={movement.id} className="flex items-center gap-3 px-5 py-3">
                  <span className={`p-2 rounded-lg shrink-0 ${movement.sign > 0 ? 'bg-emerald-50 text-emerald-600' : 'bg-rose-50 text-rose-600'}`}>
                    {movement.sign > 0 ? <ArrowDownCircle size={16} /> : <ArrowUpCircle size={16} />}
                  </span>
                  <span className="flex-1 min-w-0">
                    <span className="block text-sm font-semibold text-slate-800 truncate">{movement.type_label}{movement.description ? ` · ${movement.description}` : ''}</span>
                    <span className="block text-xs text-slate-500">{momentOf(movement.at)}{movement.by ? ` · ${movement.by}` : ''}</span>
                  </span>
                  <span className={`text-sm font-bold tabular-nums ${movement.sign > 0 ? 'text-emerald-700' : 'text-rose-700'}`}>
                    {movement.sign > 0 ? '+' : '−'} {money(movement.amount, session?.currency)}
                  </span>
                </li>
              )}
            />
          </section>
        </div>
      )}
    </FormDrawer>
  );
}
