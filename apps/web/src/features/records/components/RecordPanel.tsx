'use client';

import Badge from '@/app/components/common/Badge';
import { inputClass } from '@/app/components/ui/forms/styles';
import { useEffect, useState } from 'react';
import Link from 'next/link';
import { ChevronDown, ClipboardList, Download, EyeOff, Lock, PenLine, RefreshCw, ShieldAlert } from 'lucide-react';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { useVocabulary } from '@/hooks/useVocabulary';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { formatDateTime } from '@/features/appointments/appointments';
import RecordFieldInput from './RecordFieldInput';
import { RECORD_STATUS, isEmptyValue, type AppointmentRecord, type CareRecord, type RecordData, type RecordField, type RecordValue } from '../records';

interface RecordPanelProps {
  appointmentId: string | null;
  onClose: () => void;
  onChanged?: () => void;
}

const sectionTitle = 'text-[11px] font-bold text-slate-400 uppercase tracking-wider';

function ReadOnlyFields({ fields, data }: { fields: RecordField[]; data: RecordData }) {
  const filled = fields.filter((field) => !isEmptyValue(data[field.key]));

  if (filled.length === 0) return <p className="text-sm text-slate-400">Sin datos.</p>;

  return (
    <div className="space-y-3">
      {filled.map((field) => (
        <div key={field.key} className="space-y-1">
          <span className="block text-xs font-semibold text-slate-600">{field.label}</span>
          <RecordFieldInput field={field} value={data[field.key]} onChange={() => {}} disabled />
        </div>
      ))}
    </div>
  );
}

export default function RecordPanel({ appointmentId, onClose, onChanged }: RecordPanelProps) {
  const { user, can } = useAuth();
  const vocab = useVocabulary();
  const [context, setContext] = useState<AppointmentRecord | null>(null);
  const [data, setData] = useState<RecordData>({});
  const [consent, setConsent] = useState(false);
  const [loading, setLoading] = useState(false);
  const [busy, setBusy] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [message, setMessage] = useState<{ type: 'ok' | 'error'; text: string } | null>(null);
  const [note, setNote] = useState('');
  const [openPrevious, setOpenPrevious] = useState<string | null>(null);
  const [accessReason, setAccessReason] = useState('');
  const [confirmUpdate, setConfirmUpdate] = useState(false);

  const applyRecord = (record: CareRecord | null) => {
    setData({ ...(record?.data ?? {}) });
    setConsent(Boolean(record?.consent_accepted));
  };

  useEffect(() => {
    if (!appointmentId) return;

    setContext(null);
    setErrors({});
    setMessage(null);
    setNote('');
    setAccessReason('');
    setOpenPrevious(null);
    setConfirmUpdate(false);
    setLoading(true);

    api.get(`/appointments/${appointmentId}/record`)
      .then((res) => { setContext(res.data); applyRecord(res.data.record); })
      .catch((err) => setMessage({ type: 'error', text: err?.response?.data?.message || 'No se pudo cargar la ficha.' }))
      .finally(() => setLoading(false));
  }, [appointmentId]);

  const record = context?.record ?? null;
  const fields: RecordField[] = record?.fields ?? context?.template?.fields ?? [];
  const consentText = record ? record.consent_text : context?.template?.consent_text;
  const requireConsent = Boolean(record ? record.require_consent : context?.template?.require_consent);
  const editable = Boolean(context?.uses_record) && (record ? record.can_edit : true) && !record?.hidden && can('records.edit');
  const isSigned = record?.status === 'signed';

  const setValue = (key: string, value: RecordValue) => {
    setData((current) => ({ ...current, [key]: value }));
    setErrors((current) => { const { [key]: _removed, ...rest } = current; return rest; });
    setMessage(null);
  };

  const save = async (sign: boolean) => {
    if (!context) return;

    try {
      setBusy(sign ? 'sign' : 'save');
      setErrors({});
      setMessage(null);
      const res = await api.put(`/appointments/${context.appointment.id}/record`, { data, consent_accepted: consent, sign });
      setContext({ ...context, record: res.data, uses_record: true, template_update: sign ? null : context.template_update });
      applyRecord(res.data);
      setConfirmUpdate(false);
      setMessage({ type: 'ok', text: sign ? 'Ficha firmada.' : isSigned ? 'Cambios guardados.' : 'Borrador guardado.' });
      onChanged?.();
    } catch (err: any) {
      const found: Record<string, string> = {};
      Object.entries(err?.response?.data?.errors ?? {}).forEach(([key, value]) => { found[key] = (value as string[])[0]; });
      setErrors(found);
      const count = Object.keys(found).length;
      setMessage({
        type: 'error',
        text: count > 1 ? `Faltan ${count} datos para firmar. Están marcados abajo.` : Object.values(found)[0] || err?.response?.data?.message || 'No se pudo guardar la ficha.',
      });
    } finally {
      setBusy('');
    }
  };

  const update = context?.template_update ?? null;
  const updateAdds = update ? update.fields.filter((field) => !fields.some((old) => old.key === field.key)) : [];
  const updateLoses = update
    ? fields.filter((old) => !isEmptyValue(data[old.key]) && !update.fields.some((field) => field.key === old.key && field.type === old.type))
    : [];

  const updateTemplate = async () => {
    if (!context) return;

    try {
      setBusy('update');
      setErrors({});
      setMessage(null);
      const res = await api.post(`/appointments/${context.appointment.id}/record/refresh-template`, { data });
      setContext(res.data);
      applyRecord(res.data.record);
      setConfirmUpdate(false);
      setMessage({ type: 'ok', text: 'Ficha actualizada a la plantilla actual.' });
      onChanged?.();
    } catch (err: any) {
      const first = Object.values(err?.response?.data?.errors ?? {})[0] as string[] | undefined;
      setMessage({ type: 'error', text: first?.[0] || err?.response?.data?.message || 'No se pudo actualizar la ficha.' });
    } finally {
      setBusy('');
    }
  };

  const addNote = async () => {
    if (!context || !record) return;

    try {
      setBusy('note');
      setMessage(null);
      const res = await api.post(`/care-records/${record.id}/addenda`, { note });
      setContext({ ...context, record: res.data });
      setNote('');
      setMessage({ type: 'ok', text: 'Nota agregada.' });
      onChanged?.();
    } catch (err: any) {
      const first = Object.values(err?.response?.data?.errors ?? {})[0] as string[] | undefined;
      setMessage({ type: 'error', text: first?.[0] || err?.response?.data?.message || 'No se pudo agregar la nota.' });
    } finally {
      setBusy('');
    }
  };

  const requestAccess = async () => {
    if (!context || !record) return;

    try {
      setBusy('access');
      setMessage(null);
      const res = await api.post(`/care-records/${record.id}/emergency-access`, { reason: accessReason });
      setContext({ ...context, record: res.data });
      applyRecord(res.data);
      setAccessReason('');
    } catch (err: any) {
      const first = Object.values(err?.response?.data?.errors ?? {})[0] as string[] | undefined;
      setMessage({ type: 'error', text: first?.[0] || err?.response?.data?.message || 'No se pudo abrir la ficha.' });
    } finally {
      setBusy('');
    }
  };

  const download = async () => {
    if (!record) return;

    try {
      setBusy('download');
      setMessage(null);
      const res = await api.get(`/care-records/${record.id}/pdf`, { responseType: 'blob' });
      const url = URL.createObjectURL(res.data);
      const link = document.createElement('a');
      link.href = url;
      link.download = `Ficha ${record.appointment?.code ?? ''} ${record.customer?.name ?? ''}`.trim() + '.pdf';
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
    } catch {
      setMessage({ type: 'error', text: 'No se pudo descargar la ficha.' });
    } finally {
      setBusy('');
    }
  };

  const status = record ? RECORD_STATUS[record.status] : null;
  const readingByEmergency = Boolean(record?.reading_by_emergency);

  return (
    <FormDrawer
      isOpen={appointmentId !== null}
      onClose={onClose}
      title={record?.template_name ?? context?.template?.name ?? 'Ficha de atención'}
      subtitle={context ? [context.appointment.dependent ?? context.appointment.customer, context.appointment.service, formatDateTime(context.appointment.starts_at)].filter(Boolean).join(' · ') : undefined}
      icon={<ClipboardList size={20} />}
      maxWidth="max-w-2xl"
    >
      {loading || !context ? (
        <p className="text-sm text-slate-500">{message?.text ?? 'Cargando ficha...'}</p>
      ) : !context.uses_record ? (
        <div className="space-y-2">
          <p className="text-sm font-semibold text-slate-700">Este servicio no se atiende con ficha.</p>
          <p className="text-sm text-slate-500">
            {can('record_templates.edit') ? (
              <>Puedes asignarle una en <Link href="/records/templates" className="font-semibold text-[#3F768C] hover:text-[#022340]">Plantillas de ficha</Link>.</>
            ) : 'El administrador de tu empresa decide qué servicios llevan ficha.'}
          </p>
        </div>
      ) : (
        <div className="flex flex-col h-full justify-between">
          <div className="space-y-6 overflow-y-auto flex-1 pr-1 pb-4">
            <div className="flex flex-wrap items-center gap-2" data-record-state={record?.status ?? 'new'}>
              <Badge tone={status?.badge ?? 'bg-slate-100 text-slate-600'} bold>
                {status?.label ?? 'Sin llenar'}
              </Badge>
              {record?.is_private && (
                <span className="flex items-center gap-1 px-2.5 py-1 text-xs rounded-full font-semibold bg-violet-50 text-violet-600">
                  <EyeOff size={12} /> Privada
                </span>
              )}
              <span className="text-xs text-slate-500">
                {!record && 'Llénala durante la atención. Puedes guardarla como borrador y firmarla al terminar.'}
                {record?.status === 'draft' && `Borrador de ${record.author ?? 'alguien del equipo'}.`}
                {record?.status === 'signed' && `Firmada el ${formatDateTime(record.signed_at!)}. Se puede corregir hasta el ${formatDateTime(record.locked_at!)}.`}
                {record?.status === 'locked' && `Firmada el ${formatDateTime(record.signed_at!)}. Ya no se edita: solo admite notas.`}
              </span>
            </div>

            {!record && context.private_by_default && (
              <p className="flex items-start gap-2 text-xs text-violet-600 bg-violet-50 border border-violet-100 rounded-lg px-3 py-2">
                <EyeOff size={14} className="shrink-0 mt-0.5" /> En tu empresa las fichas son privadas: solo las ve quien las escribe.
              </p>
            )}

            {record && !record.hidden && (record.emergency_accesses?.length ?? 0) > 0 && (
              <section className="p-3 bg-amber-50 border border-amber-200 rounded-xl space-y-1.5" data-emergency-log>
                <span className="flex items-center gap-1.5 text-xs font-bold text-amber-700 uppercase tracking-wider">
                  <ShieldAlert size={14} /> Abierta por acceso de emergencia
                </span>
                {record.emergency_accesses!.map((access, index) => (
                  <p key={index} className="text-xs text-amber-800">
                    <span className="font-semibold">{access.by ?? 'Administrador'}</span> · {formatDateTime(access.at)} · {access.reason}
                  </p>
                ))}
                {readingByEmergency && (
                  <p className="text-xs text-amber-700">La estás leyendo con acceso de emergencia: dura 30 minutos y no permite cambiarla.</p>
                )}
              </section>
            )}

            {update && editable && (
              <section className="p-3 bg-sky-50 border border-sky-200 rounded-xl space-y-2" data-template-update>
                <div className="flex items-start justify-between gap-3">
                  <p className="text-xs text-sky-800">
                    <span className="block font-bold uppercase tracking-wider text-sky-700">La plantilla de este servicio cambió</span>
                    Este borrador conserva los campos con que se empezó. Puedes pasarlo a la plantilla actual
                    {update.name !== record?.template_name ? ` («${update.name}»)` : ''}.
                  </p>
                  {!confirmUpdate && (
                    <button
                      type="button"
                      data-action="update-template"
                      disabled={busy !== ''}
                      onClick={() => setConfirmUpdate(true)}
                      className="flex items-center gap-1.5 shrink-0 px-3 py-2 text-xs font-semibold bg-white border border-sky-200 rounded-lg text-sky-800 hover:bg-sky-100 transition-colors disabled:opacity-50"
                    >
                      <RefreshCw size={13} /> Actualizar ficha
                    </button>
                  )}
                </div>

                {confirmUpdate && (
                  <div className="p-3 bg-white border border-sky-200 rounded-lg space-y-2" data-update-confirm>
                    <p className="text-xs text-slate-600">Lo escrito en los campos que siguen existiendo se conserva.</p>
                    {updateAdds.length > 0 && (
                      <p className="text-xs text-slate-700" data-update-adds>
                        <span className="font-semibold">Campos nuevos:</span> {updateAdds.map((field) => field.label).join(', ')}.
                      </p>
                    )}
                    {updateLoses.length > 0 ? (
                      <p className="text-xs text-red-700" data-update-loses>
                        <span className="font-semibold">Se pierde lo escrito en:</span> {updateLoses.map((field) => field.label).join(', ')}.
                      </p>
                    ) : (
                      <p className="text-xs text-emerald-700" data-update-loses="none">No se pierde nada de lo escrito.</p>
                    )}
                    {update.consent_text !== (record?.consent_text ?? null) && consent && (
                      <p className="text-xs text-slate-700">El consentimiento cambió: hay que volver a aceptarlo.</p>
                    )}
                    <div className="flex justify-end gap-2 pt-1">
                      <button
                        type="button"
                        data-action="update-cancel"
                        disabled={busy !== ''}
                        onClick={() => setConfirmUpdate(false)}
                        className="px-3 py-2 text-xs font-medium border border-slate-200 rounded-lg text-slate-600 hover:bg-slate-50 transition-colors disabled:opacity-50"
                      >
                        No, mantenerla
                      </button>
                      <button
                        type="button"
                        data-action="update-confirm"
                        disabled={busy !== ''}
                        onClick={updateTemplate}
                        className="px-3 py-2 text-xs font-medium bg-[#022340] text-white rounded-lg hover:bg-[#03315a] transition-colors disabled:opacity-50"
                      >
                        {busy === 'update' ? 'Actualizando...' : 'Sí, actualizar'}
                      </button>
                    </div>
                  </div>
                )}
              </section>
            )}

            {record?.hidden ? (
              <div className="p-6 space-y-4 bg-slate-50 border border-slate-200 rounded-xl" data-private>
                <div className="text-center space-y-2">
                  <Lock className="w-7 h-7 text-slate-300 mx-auto" />
                  <p className="text-sm font-semibold text-slate-700">Esta ficha es privada.</p>
                  <p className="text-sm text-slate-500">Solo la ve {record.author ?? 'quien la escribió'}.</p>
                </div>

                {record.can_request_access && (
                  <div className="p-4 bg-white border border-amber-200 rounded-xl space-y-2">
                    <span className="flex items-center gap-1.5 text-xs font-bold text-amber-700 uppercase tracking-wider">
                      <ShieldAlert size={14} /> Acceso de emergencia
                    </span>
                    <p className="text-xs text-slate-600">
                      Como administrador puedes abrirla si hay una razón que lo justifique. Queda registrado, y {record.author ?? 'su autor'} verá
                      quién la abrió, cuándo y por qué. Podrás leerla durante 30 minutos, sin cambiarla.
                    </p>
                    <textarea
                      rows={2}
                      maxLength={500}
                      aria-label="Motivo del acceso de emergencia"
                      placeholder="Ej. Reclamo del cliente sobre su tratamiento"
                      className={inputClass}
                      value={accessReason}
                      onChange={(e) => { setAccessReason(e.target.value); setMessage(null); }}
                    />
                    <div className="flex justify-end">
                      <button
                        type="button"
                        data-action="emergency-access"
                        disabled={busy !== '' || accessReason.trim().length < 10}
                        onClick={requestAccess}
                        className="px-4 py-2 text-sm font-medium bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition-colors disabled:opacity-50"
                      >
                        {busy === 'access' ? 'Abriendo...' : 'Abrir la ficha'}
                      </button>
                    </div>
                  </div>
                )}
              </div>
            ) : (
              <>
                <section className="space-y-4" data-record-fields>
                  {fields.length === 0 && <p className="text-sm text-slate-400">Esta ficha no tiene campos.</p>}

                  {fields.map((field, index) => (
                    <div key={field.key} className="space-y-1.5" data-field={field.key}>
                      <span className="flex items-baseline gap-1.5 text-xs font-semibold text-slate-700">
                        <span className="text-[10px] font-mono text-slate-300">{String(index + 1).padStart(2, '0')}</span>
                        {field.label}
                        {field.required && <span className="text-red-500" title="Obligatorio">*</span>}
                      </span>
                      <RecordFieldInput field={field} value={data[field.key]} onChange={(value) => setValue(field.key, value)} disabled={!editable} />
                      {errors[field.key] && <p role="alert" className="text-xs font-medium text-red-600">{errors[field.key]}</p>}
                    </div>
                  ))}
                </section>

                {consentText && (
                  <section className={`rounded-xl border p-4 space-y-3 ${errors.consent ? 'border-red-200 bg-red-50' : 'border-slate-200 bg-slate-50'}`}>
                    <div className="flex items-center justify-between gap-2">
                      <span className={sectionTitle}>Consentimiento informado</span>
                      {requireConsent && <span className="px-2 py-0.5 rounded-md text-[10px] font-semibold bg-rose-50 text-rose-700">Obligatorio</span>}
                    </div>
                    <p className="text-sm text-slate-600 leading-relaxed">{consentText}</p>
                    <label className={`flex items-center gap-2 text-sm font-semibold text-slate-700 ${editable ? 'cursor-pointer' : ''}`}>
                      <input
                        type="checkbox"
                        checked={consent}
                        disabled={!editable}
                        onChange={(e) => { setConsent(e.target.checked); setErrors((current) => { const { consent: _removed, ...rest } = current; return rest; }); }}
                        className="rounded border-slate-300 text-[#022340] focus:ring-[#022340]"
                      />
                      {context.appointment.customer ?? `El ${vocab.client.toLowerCase()}`} acepta
                    </label>
                    {errors.consent && <p role="alert" className="text-xs font-medium text-red-600">{errors.consent}</p>}
                  </section>
                )}

                {record && (record.addenda?.length ?? 0) > 0 && (
                  <section className="space-y-2" data-addenda>
                    <span className={sectionTitle}>Notas agregadas</span>
                    <ol className="space-y-2">
                      {record.addenda!.map((item, index) => (
                        <li key={index} className="p-3 bg-slate-50 border border-slate-200 rounded-lg">
                          <span className="block text-sm text-slate-800 whitespace-pre-line">{item.note}</span>
                          <span className="block text-xs text-slate-500 mt-1">{formatDateTime(item.at)}{item.by ? ` · ${item.by}` : ''}</span>
                        </li>
                      ))}
                    </ol>
                  </section>
                )}

                {record?.can_add_note && (
                  <section className="space-y-2">
                    <label className={sectionTitle}>Agregar una nota</label>
                    <textarea
                      rows={2}
                      maxLength={5000}
                      aria-label="Nota para agregar a la ficha"
                      placeholder="La ficha no cambia: la nota queda al final, con tu nombre y la fecha."
                      className={inputClass}
                      value={note}
                      onChange={(e) => setNote(e.target.value)}
                    />
                    {note.trim() && (
                      <div className="flex justify-end">
                        <button
                          type="button"
                          data-action="add-note"
                          disabled={busy !== ''}
                          onClick={addNote}
                          className="px-4 py-2 text-sm font-medium border border-slate-200 rounded-lg text-slate-700 hover:bg-slate-50 transition-colors disabled:opacity-50"
                        >
                          {busy === 'note' ? 'Agregando...' : 'Agregar nota'}
                        </button>
                      </div>
                    )}
                  </section>
                )}
              </>
            )}

            {context.previous.length > 0 && (
              <section className="space-y-2 pt-5 border-t border-slate-100" data-previous>
                <span className={sectionTitle}>Atenciones anteriores ({context.previous.length})</span>
                {context.previous.map((item) => {
                  const isOpen = openPrevious === item.id;
                  return (
                    <div key={item.id} className="border border-slate-200 rounded-lg bg-white">
                      <button
                        type="button"
                        onClick={() => setOpenPrevious(isOpen ? null : item.id)}
                        className="w-full flex items-center justify-between gap-3 px-3 py-2.5 text-left hover:bg-slate-50 rounded-lg transition-colors"
                      >
                        <div>
                          <span className="block text-sm font-semibold text-slate-800">
                            {item.signed_at ? formatDateTime(item.signed_at) : formatDateTime(item.created_at)} · {item.appointment?.service ?? item.template_name}
                          </span>
                          <span className="block text-xs text-slate-500">{item.professional ?? item.author}</span>
                        </div>
                        {item.hidden
                          ? <span className="flex items-center gap-1 text-xs text-slate-400"><EyeOff size={13} /> Privada</span>
                          : <ChevronDown size={15} className={`text-slate-400 transition-transform duration-200 ${isOpen ? 'rotate-180' : ''}`} />}
                      </button>
                      {isOpen && !item.hidden && (
                        <div className="px-3 pb-3 pt-2 border-t border-slate-100 space-y-3">
                          <ReadOnlyFields fields={item.fields ?? []} data={item.data ?? {}} />
                          {(item.addenda ?? []).map((addendum, index) => (
                            <p key={index} className="text-xs text-slate-600 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2">
                              <span className="font-semibold">Nota:</span> {addendum.note}
                            </p>
                          ))}
                        </div>
                      )}
                    </div>
                  );
                })}
              </section>
            )}
          </div>

          <div className="pt-4 border-t border-slate-100 flex items-center justify-between gap-3 bg-white sticky bottom-0 py-3 mt-auto">
            <span role={message?.type === 'error' ? 'alert' : undefined} data-record-message className={`text-sm font-medium ${message?.type === 'ok' ? 'text-emerald-600' : 'text-red-600'}`}>
              {message?.text}
            </span>
            <div className="flex items-center gap-3 shrink-0">
              <button
                type="button"
                onClick={onClose}
                className="px-4 py-2.5 text-sm font-medium border border-slate-200 rounded-lg text-slate-600 hover:bg-slate-50 transition-colors"
              >
                Cerrar
              </button>
              {record && !record.hidden && (
                <button
                  type="button"
                  data-action="download"
                  title="Descargar la ficha en PDF"
                  disabled={busy !== ''}
                  onClick={download}
                  className="flex items-center gap-2 px-4 py-2.5 text-sm font-medium border border-slate-200 rounded-lg text-slate-700 hover:bg-slate-50 transition-colors disabled:opacity-50"
                >
                  <Download size={15} /> {busy === 'download' ? 'Preparando...' : 'Descargar'}
                </button>
              )}
              {editable && !isSigned && (
                <button
                  type="button"
                  data-action="save-draft"
                  disabled={busy !== ''}
                  onClick={() => save(false)}
                  className="px-4 py-2.5 text-sm font-medium border border-slate-200 rounded-lg text-slate-700 hover:bg-slate-50 transition-colors disabled:opacity-50"
                >
                  {busy === 'save' ? 'Guardando...' : 'Guardar borrador'}
                </button>
              )}
              {editable && (
                <button
                  type="button"
                  data-action={isSigned ? 'save-changes' : 'sign'}
                  disabled={busy !== ''}
                  onClick={() => save(!isSigned)}
                  className="flex items-center gap-2 px-5 py-2.5 text-sm font-medium bg-[#022340] text-white rounded-lg hover:bg-[#03315a] shadow-sm transition-colors disabled:opacity-50"
                >
                  {!isSigned && <PenLine size={15} />}
                  {busy === 'sign' || (isSigned && busy === 'save') ? 'Guardando...' : isSigned ? 'Guardar cambios' : 'Firmar ficha'}
                </button>
              )}
            </div>
          </div>
        </div>
      )}
    </FormDrawer>
  );
}
