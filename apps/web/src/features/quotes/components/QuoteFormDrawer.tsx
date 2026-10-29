'use client';

import { useEffect, useMemo, useState } from 'react';
import { Calendar, FileText, Plus, Trash2 } from 'lucide-react';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Button from '@/app/components/common/Button';
import Select from '@/app/components/common/Select';
import Switch from '@/app/components/common/Switch';
import { ErrorAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { cleanAmount, money } from '@/features/sales/sales';
import type { QuoteRow, QuotesBoardData } from '../quotes';

interface Props {
  isOpen: boolean;
  onClose: () => void;
  board: QuotesBoardData;
  editingQuote: QuoteRow | null;
  onSaved: (data: QuotesBoardData, notice: string) => void;
}

interface DraftLine {
  key: string;
  item_type: 'service' | 'product' | 'package' | 'other';
  reference_id: string;
  description: string;
  quantity: string;
  unit_price: string;
  discount: string;
  sessions_total: string;
  zone: string;
  phase: string;
}

interface DraftInstallment {
  key: string;
  due_date: string;
  amount: string;
}

const makeKey = () => `${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;

const addDaysIso = (days: number): string => {
  const d = new Date();
  d.setDate(d.getDate() + days);
  return d.toISOString().slice(0, 10);
};

export default function QuoteFormDrawer({ isOpen, onClose, board, editingQuote, onSaved }: Props) {
  const [customerId, setCustomerId] = useState('');
  const [branchId, setBranchId] = useState('');
  const [professionalId, setProfessionalId] = useState('');
  const [title, setTitle] = useState('');
  const [validUntil, setValidUntil] = useState('');
  const [notes, setNotes] = useState('');
  const [sendNow, setSendNow] = useState(false);

  const [lines, setLines] = useState<DraftLine[]>([]);
  const [useInstallments, setUseInstallments] = useState(false);
  const [installmentsCount, setInstallmentsCount] = useState('2');
  const [installments, setInstallments] = useState<DraftInstallment[]>([]);

  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!isOpen) return;
    setError('');

    if (editingQuote) {
      setCustomerId(editingQuote.customer_id);
      setBranchId(editingQuote.branch_id ?? board.branches[0]?.id ?? '');
      setProfessionalId(editingQuote.professional_id ?? '');
      setTitle(editingQuote.title ?? '');
      setValidUntil(editingQuote.valid_until ?? '');
      setNotes(editingQuote.notes ?? '');
      setSendNow(editingQuote.status === 'sent');
      setLines(
        editingQuote.items.map((item) => ({
          key: makeKey(),
          item_type: item.item_type,
          reference_id: item.reference_id ?? '',
          description: item.description,
          quantity: String(item.quantity),
          unit_price: String(item.unit_price),
          discount: String(item.discount || 0),
          sessions_total: String(item.sessions_total || 0),
          zone: item.zone ?? '',
          phase: item.phase ?? '',
        })),
      );
      if (editingQuote.installments.length > 0) {
        setUseInstallments(true);
        setInstallmentsCount(String(editingQuote.installments.length));
        setInstallments(
          editingQuote.installments.map((inst) => ({
            key: makeKey(),
            due_date: inst.due_date,
            amount: String(inst.amount),
          })),
        );
      } else {
        setUseInstallments(false);
        setInstallmentsCount('2');
        setInstallments([]);
      }
      return;
    }

    const firstService = board.services[0];
    setCustomerId(board.customers[0]?.id ?? '');
    setBranchId(board.branches[0]?.id ?? '');
    setProfessionalId(board.professionals[0]?.id ?? '');
    setTitle('');
    setValidUntil(board.rules.validity_days > 0 ? addDaysIso(board.rules.validity_days) : '');
    setNotes('');
    setSendNow(false);
    setLines([
      {
        key: makeKey(),
        item_type: firstService ? 'service' : 'other',
        reference_id: firstService?.id ?? '',
        description: firstService?.name ?? '',
        quantity: '1',
        unit_price: firstService ? String(firstService.price) : '0',
        discount: '0',
        sessions_total: '1',
        zone: '',
        phase: '',
      },
    ]);
    setUseInstallments(false);
    setInstallmentsCount('2');
    setInstallments([]);
  }, [isOpen, editingQuote, board]);

  const totals = useMemo(() => {
    let gross = 0;
    let discount = 0;
    for (const l of lines) {
      const qty = Math.max(0, Number(l.quantity || 0));
      const price = Math.max(0, Number(l.unit_price || 0));
      const lineGross = qty * price;
      const lineDisc = Math.min(lineGross, Math.max(0, Number(l.discount || 0)));
      gross += lineGross;
      discount += lineDisc;
    }
    const net = Math.max(0, gross - discount);
    const rate = board.tax.rate / 100;
    const tax = board.tax.included ? net - net / (1 + rate) : net * rate;
    const total = board.tax.included ? net : net + tax;
    return {
      subtotal: Number(gross.toFixed(2)),
      discount: Number(discount.toFixed(2)),
      tax: Number(tax.toFixed(2)),
      total: Number(total.toFixed(2)),
    };
  }, [lines, board.tax]);

  const generateInstallments = (count: number, totalAmount: number) => {
    const safeCount = Math.max(1, Math.min(24, count));
    const base = Math.floor((totalAmount / safeCount) * 100) / 100;
    const list: DraftInstallment[] = [];
    let sum = 0;
    for (let i = 0; i < safeCount; i++) {
      const amt = i === safeCount - 1 ? Math.max(0, Number((totalAmount - sum).toFixed(2))) : base;
      sum += amt;
      list.push({
        key: makeKey(),
        due_date: addDaysIso(i * 30),
        amount: amt.toFixed(2),
      });
    }
    setInstallments(list);
  };

  const handleToggleInstallments = (on: boolean) => {
    setUseInstallments(on);
    if (on) {
      generateInstallments(Number(installmentsCount || 2), totals.total);
    } else {
      setInstallments([]);
    }
  };

  const updateLine = (key: string, patch: Partial<DraftLine>) => {
    setLines((curr) => curr.map((l) => (l.key === key ? { ...l, ...patch } : l)));
  };

  const handleCatalogPick = (line: DraftLine, refId: string) => {
    if (line.item_type === 'service') {
      const s = board.services.find((x) => x.id === refId);
      updateLine(line.key, {
        reference_id: refId,
        description: s?.name ?? line.description,
        unit_price: s ? String(s.price) : line.unit_price,
        sessions_total: line.sessions_total || '1',
      });
    } else if (line.item_type === 'product') {
      const p = board.products.find((x) => x.id === refId);
      updateLine(line.key, {
        reference_id: refId,
        description: p?.name ?? line.description,
        unit_price: p ? String(p.price) : line.unit_price,
        sessions_total: '0',
      });
    } else if (line.item_type === 'package') {
      const pkg = board.packages.find((x) => x.id === refId);
      updateLine(line.key, {
        reference_id: refId,
        description: pkg?.name ?? line.description,
        unit_price: pkg ? String(pkg.price) : line.unit_price,
        sessions_total: pkg ? String(pkg.sessions_total) : '1',
      });
    }
  };

  const addLine = () => {
    const firstService = board.services[0];
    setLines((curr) => [
      ...curr,
      {
        key: makeKey(),
        item_type: firstService ? 'service' : 'other',
        reference_id: firstService?.id ?? '',
        description: firstService?.name ?? '',
        quantity: '1',
        unit_price: firstService ? String(firstService.price) : '0',
        discount: '0',
        sessions_total: '1',
        zone: '',
        phase: '',
      },
    ]);
  };

  const removeLine = (key: string) => {
    setLines((curr) => (curr.length > 1 ? curr.filter((l) => l.key !== key) : curr));
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      setSaving(true);
      setError('');

      const payload = {
        customer_id: customerId,
        branch_id: branchId || null,
        professional_id: professionalId || null,
        title: title.trim(),
        valid_until: validUntil || null,
        notes: notes.trim() || null,
        status: sendNow ? 'sent' : 'draft',
        items: lines.map((l) => ({
          item_type: l.item_type,
          reference_id: l.reference_id || null,
          description: l.description.trim(),
          quantity: Number(l.quantity || 1),
          unit_price: Number(l.unit_price || 0),
          discount: Number(l.discount || 0),
          sessions_total: Number(l.sessions_total || 0),
          zone: l.zone.trim() || null,
          phase: l.phase.trim() || null,
        })),
        installments: useInstallments
          ? installments.map((inst) => ({
              due_date: inst.due_date,
              amount: Number(inst.amount || 0),
            }))
          : [],
      };

      const res = editingQuote
        ? await api.put<QuotesBoardData>(`/quotes/${editingQuote.id}`, payload)
        : await api.post<QuotesBoardData>('/quotes', payload);

      onSaved(res.data, editingQuote ? 'Presupuesto actualizado.' : 'Presupuesto creado.');
      onClose();
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar el presupuesto.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title={editingQuote ? `Editar presupuesto ${editingQuote.code}` : 'Nuevo presupuesto / plan de tratamiento'}
      subtitle="Arma las sesiones, servicios, productos y cronograma de pagos para el cliente."
      icon={<FileText size={20} />}
    >
      <form onSubmit={handleSubmit} className="space-y-4">
        <ErrorAlert message={error} />

        <div>
          <label className={labelClass} htmlFor="quote-title">Título del presupuesto o plan *</label>
          <input
            id="quote-title"
            required
            maxLength={200}
            placeholder="Ej. Plan de rehabilitación oral integral / Tratamiento facial 6 sesiones"
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            className={inputClass}
          />
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <Select
            label="Cliente / Paciente *"
            value={customerId}
            onChange={(e) => setCustomerId(String(e.target.value))}
            options={board.customers.map((c) => ({
              value: c.id,
              label: `${c.name}${c.document_number ? ` · ${c.document_number}` : ''}`,
            }))}
          />

          <Select
            label="Profesional tratante"
            value={professionalId}
            onChange={(e) => setProfessionalId(String(e.target.value))}
            options={[
              { value: '', label: 'Sin profesional asignado' },
              ...board.professionals.map((p) => ({ value: p.id, label: p.display_name })),
            ]}
          />
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          {board.branches.length > 1 && (
            <Select
              label="Sede"
              value={branchId}
              onChange={(e) => setBranchId(String(e.target.value))}
              options={board.branches.map((b) => ({ value: b.id, label: b.name }))}
            />
          )}

          <div>
            <label className={labelClass} htmlFor="quote-valid-until">Válido hasta</label>
            <input
              id="quote-valid-until"
              type="date"
              value={validUntil}
              onChange={(e) => setValidUntil(e.target.value)}
              className={inputClass}
            />
          </div>
        </div>

        <div className="space-y-3 pt-2 border-t border-slate-100">
          <div className="flex items-center justify-between">
            <span className="text-xs font-bold uppercase tracking-wider text-slate-500">Líneas del plan de tratamiento</span>
            <button
              type="button"
              onClick={addLine}
              className="inline-flex items-center gap-1 text-xs font-bold text-brand-accent hover:text-brand-ink transition-colors"
            >
              <Plus size={14} /> Agregar línea
            </button>
          </div>

          {lines.map((line, index) => (
            <div key={line.key} className="rounded-xl border border-slate-200 p-3.5 space-y-3 bg-slate-50/40">
              <div className="flex items-center justify-between gap-2">
                <span className="text-xs font-bold text-brand-ink">Línea #{index + 1}</span>
                {lines.length > 1 && (
                  <button
                    type="button"
                    onClick={() => removeLine(line.key)}
                    className="text-xs font-semibold text-rose-600 hover:text-rose-800 inline-flex items-center gap-1"
                  >
                    <Trash2 size={13} /> Quitar
                  </button>
                )}
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                <Select
                  label="Tipo"
                  value={line.item_type}
                  onChange={(e) => {
                    const nextType = String(e.target.value) as DraftLine['item_type'];
                    if (nextType === 'service' && board.services[0]) {
                      const s = board.services[0];
                      updateLine(line.key, { item_type: nextType, reference_id: s.id, description: s.name, unit_price: String(s.price), sessions_total: '1' });
                    } else if (nextType === 'product' && board.products[0]) {
                      const p = board.products[0];
                      updateLine(line.key, { item_type: nextType, reference_id: p.id, description: p.name, unit_price: String(p.price), sessions_total: '0' });
                    } else if (nextType === 'package' && board.packages[0]) {
                      const pkg = board.packages[0];
                      updateLine(line.key, { item_type: nextType, reference_id: pkg.id, description: pkg.name, unit_price: String(pkg.price), sessions_total: String(pkg.sessions_total) });
                    } else {
                      updateLine(line.key, { item_type: 'other', reference_id: '', sessions_total: '1' });
                    }
                  }}
                  options={[
                    { value: 'service', label: 'Servicio' },
                    ...(board.products.length > 0 ? [{ value: 'product', label: 'Producto' }] : []),
                    ...(board.packages.length > 0 ? [{ value: 'package', label: 'Paquete' }] : []),
                    { value: 'other', label: 'Concepto libre' },
                  ]}
                />

                {line.item_type === 'service' && (
                  <Select
                    label="Del catálogo"
                    value={line.reference_id}
                    onChange={(e) => handleCatalogPick(line, String(e.target.value))}
                    options={board.services.map((s) => ({ value: s.id, label: `${s.name} (${money(s.price, board.currency)})` }))}
                  />
                )}
                {line.item_type === 'product' && (
                  <Select
                    label="Del catálogo"
                    value={line.reference_id}
                    onChange={(e) => handleCatalogPick(line, String(e.target.value))}
                    options={board.products.map((p) => ({ value: p.id, label: `${p.name} (${money(p.price, board.currency)})` }))}
                  />
                )}
                {line.item_type === 'package' && (
                  <Select
                    label="Del catálogo"
                    value={line.reference_id}
                    onChange={(e) => handleCatalogPick(line, String(e.target.value))}
                    options={board.packages.map((pkg) => ({ value: pkg.id, label: `${pkg.name} (${money(pkg.price, board.currency)})` }))}
                  />
                )}
              </div>

              <div>
                <label className={labelClass}>Descripción en el presupuesto *</label>
                <input
                  required
                  maxLength={255}
                  value={line.description}
                  onChange={(e) => updateLine(line.key, { description: e.target.value })}
                  className={inputClass}
                />
              </div>

              <div className="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                <div>
                  <label className={labelClass}>Cant. *</label>
                  <input
                    inputMode="numeric"
                    value={line.quantity}
                    onChange={(e) => {
                      const q = e.target.value.replace(/\D/g, '').slice(0, 4);
                      updateLine(line.key, {
                        quantity: q,
                        ...(line.item_type === 'service' ? { sessions_total: q || '1' } : {}),
                      });
                    }}
                    className={inputClass}
                  />
                </div>
                <div>
                  <label className={labelClass}>Precio unit. *</label>
                  <input
                    inputMode="decimal"
                    value={line.unit_price}
                    onChange={(e) => updateLine(line.key, { unit_price: cleanAmount(e.target.value) })}
                    className={inputClass}
                  />
                </div>
                <div>
                  <label className={labelClass}>Descuento</label>
                  <input
                    inputMode="decimal"
                    value={line.discount}
                    onChange={(e) => updateLine(line.key, { discount: cleanAmount(e.target.value) })}
                    className={inputClass}
                  />
                </div>
                <div>
                  <label className={labelClass}>N.º sesiones</label>
                  <input
                    inputMode="numeric"
                    value={line.sessions_total}
                    onChange={(e) => updateLine(line.key, { sessions_total: e.target.value.replace(/\D/g, '').slice(0, 3) })}
                    className={inputClass}
                  />
                </div>
              </div>

              {board.rules.track_clinical_zones && (
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                  <div>
                    <label className={labelClass}>Pieza dental / Zona corporal</label>
                    <input
                      maxLength={120}
                      placeholder="Ej. Pieza 24 / Rostro completo / Hombro der."
                      value={line.zone}
                      onChange={(e) => updateLine(line.key, { zone: e.target.value })}
                      className={inputClass}
                    />
                  </div>
                  <div>
                    <label className={labelClass}>Etapa / Fase</label>
                    <input
                      maxLength={120}
                      placeholder="Ej. Fase 1: Diagnóstico / Fase 2: Rehabilitación"
                      value={line.phase}
                      onChange={(e) => updateLine(line.key, { phase: e.target.value })}
                      className={inputClass}
                    />
                  </div>
                </div>
              )}
            </div>
          ))}
        </div>

        <div className="rounded-xl bg-brand text-white p-4 space-y-1.5 text-sm">
          <div className="flex justify-between text-slate-300 text-xs">
            <span>Subtotal</span>
            <span>{money(totals.subtotal, board.currency)}</span>
          </div>
          {totals.discount > 0 && (
            <div className="flex justify-between text-brand-soft text-xs">
              <span>Descuento</span>
              <span>− {money(totals.discount, board.currency)}</span>
            </div>
          )}
          <div className="flex justify-between text-slate-300 text-xs">
            <span>IGV ({board.tax.rate}% {board.tax.included ? 'incluido' : 'adicional'})</span>
            <span>{money(totals.tax, board.currency)}</span>
          </div>
          <div className="flex justify-between font-black text-base pt-1.5 border-t border-white/15">
            <span>Total del presupuesto</span>
            <span>{money(totals.total, board.currency)}</span>
          </div>
        </div>

        {board.rules.allow_installments && (
          <div className="rounded-xl border border-slate-200 p-3.5 space-y-3">
            <div className="flex items-center justify-between gap-3">
              <div>
                <span className="block text-sm font-bold text-brand-ink">Fraccionar en cuotas de pago</span>
                <span className="block text-xs text-slate-500">Define las fechas y montos de cada cuota acordada con el cliente.</span>
              </div>
              <Switch
                checked={useInstallments}
                onChange={handleToggleInstallments}
                label="Fraccionar en cuotas de pago"
              />
            </div>

            {useInstallments && (
              <div className="space-y-2.5 pt-2 border-t border-slate-100">
                <div className="flex items-center gap-2">
                  <label className="text-xs font-semibold text-slate-700" htmlFor="inst-count">Número de cuotas:</label>
                  <input
                    id="inst-count"
                    inputMode="numeric"
                    value={installmentsCount}
                    onChange={(e) => {
                      const val = e.target.value.replace(/\D/g, '').slice(0, 2);
                      setInstallmentsCount(val);
                      if (Number(val) >= 1) {
                        generateInstallments(Number(val), totals.total);
                      }
                    }}
                    className="w-20 text-xs text-center border border-slate-200 rounded-lg px-2.5 py-1.5 font-bold text-brand-ink"
                  />
                  <button
                    type="button"
                    onClick={() => generateInstallments(Number(installmentsCount || 2), totals.total)}
                    className="text-xs font-bold text-brand-accent hover:underline"
                  >
                    Repartir en partes iguales
                  </button>
                </div>

                <div className="space-y-2">
                  {installments.map((inst, idx) => (
                    <div key={inst.key} className="grid grid-cols-12 gap-2 items-center">
                      <span className="col-span-3 text-xs font-bold text-slate-600 flex items-center gap-1">
                        <Calendar size={12} /> Cuota #{idx + 1}
                      </span>
                      <div className="col-span-5">
                        <input
                          type="date"
                          required
                          value={inst.due_date}
                          onChange={(e) =>
                            setInstallments((curr) =>
                              curr.map((row) => (row.key === inst.key ? { ...row, due_date: e.target.value } : row)),
                            )
                          }
                          className={inputClass}
                        />
                      </div>
                      <div className="col-span-4">
                        <input
                          inputMode="decimal"
                          required
                          value={inst.amount}
                          onChange={(e) =>
                            setInstallments((curr) =>
                              curr.map((row) => (row.key === inst.key ? { ...row, amount: cleanAmount(e.target.value) } : row)),
                            )
                          }
                          className={inputClass}
                        />
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>
        )}

        <div>
          <label className={labelClass} htmlFor="quote-notes">Observaciones / Indicaciones clínicas</label>
          <textarea
            id="quote-notes"
            rows={2}
            maxLength={2000}
            placeholder="Condiciones de pago, recomendaciones o notas para el paciente..."
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            className={inputClass}
          />
        </div>

        {!editingQuote && (
          <div className="flex items-center justify-between gap-3 rounded-xl bg-slate-50 border border-slate-200/80 px-3.5 py-2.5">
            <div>
              <span className="block text-xs font-bold text-slate-800">Marcar como &quot;Enviado al cliente&quot; al guardar</span>
              <span className="block text-[11px] text-slate-500">Si lo dejas apagado, se guardará como borrador.</span>
            </div>
            <Switch checked={sendNow} onChange={setSendNow} label="Marcar como enviado al guardar" />
          </div>
        )}

        <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
          <Button type="button" variant="secondary" onClick={onClose}>
            Cancelar
          </Button>
          <Button type="submit" variant="primary" disabled={saving || !customerId || !title.trim() || lines.length === 0}>
            {saving ? 'Guardando...' : editingQuote ? 'Guardar cambios' : 'Crear presupuesto'}
          </Button>
        </div>
      </form>
    </FormDrawer>
  );
}
