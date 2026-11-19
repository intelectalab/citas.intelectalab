'use client';

import { useEffect, useState } from 'react';
import { Loader2, Send } from 'lucide-react';
import { api } from '@/lib/axios';
import Badge from '@/app/components/common/Badge';
import BackLink from '@/app/components/common/BackLink';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { inputClass } from '@/app/components/ui/forms/styles';
import { apiError } from '@/features/appointments/appointments';
import { money } from '@/features/sales/sales';
import { STATE, deadline, issuerLine, longDate, type Complaint } from '../complaints';

const card = 'bg-white rounded-3xl border border-slate-200/80 shadow-xs p-5 sm:p-6 space-y-4';

function Rows({ rows }: { rows: [string, string | null | undefined][] }) {
  return (
    <dl className="rounded-2xl border border-slate-200 divide-y divide-slate-100 text-sm">
      {rows.filter(([, v]) => v).map(([label, value]) => (
        <div key={label} className="flex flex-col sm:flex-row sm:justify-between gap-1 px-4 py-2.5">
          <dt className="text-xs text-slate-500 shrink-0">{label}</dt>
          <dd className="font-semibold text-slate-800 sm:text-right whitespace-pre-line break-words">{value}</dd>
        </div>
      ))}
    </dl>
  );
}

export default function ComplaintDetail({ id, platform = false }: { id: string; platform?: boolean }) {
  const base = platform ? '/admin/complaints' : '/complaints';
  const [item, setItem] = useState<Complaint | null>(null);
  const [canAnswer, setCanAnswer] = useState(false);
  const [response, setResponse] = useState('');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    api.get(`${base}/${id}`)
      .then((res) => { setItem(res.data.complaint); setCanAnswer(res.data.can_answer); })
      .catch((err) => setError(apiError(err, 'No encontramos ese reclamo.')));
  }, [base, id]);

  const send = async () => {
    try {
      setBusy(true);
      setError('');
      const res = await api.put(`${base}/${id}`, { response });
      setItem(res.data.complaint);
      setNotice(res.data.message);
    } catch (err) {
      setError(apiError(err, 'No se pudo enviar la respuesta.'));
    } finally {
      setBusy(false);
    }
  };

  if (!item) {
    return (
      <div className="space-y-4">
        <BackLink href={base}>Libro de Reclamaciones</BackLink>
        {error ? <ErrorAlert message={error} /> : <Loader2 className="w-6 h-6 text-brand-accent animate-spin" />}
      </div>
    );
  }

  const c = item.consumer;

  return (
    <div className="space-y-5 pb-10 font-sans" data-complaint-detail={item.code}>
      <BackLink href={base}>Libro de Reclamaciones</BackLink>
      <div className="flex flex-wrap items-center gap-3">
        <h1 className="text-2xl font-black text-brand-ink tracking-tight">{item.kind_label} {item.code}</h1>
        <Badge tone={STATE[item.state].tone} bold>{STATE[item.state].label}</Badge>
      </div>
      <p className={`text-sm font-semibold ${item.state === 'late' ? 'text-rose-700' : item.state === 'soon' ? 'text-amber-800' : 'text-slate-500'}`}>Llegó el {longDate(item.created_on)} · {deadline(item)}</p>

      <div className="grid grid-cols-1 xl:grid-cols-2 gap-5">
        <section className={card}>
          <h2 className="text-sm font-black text-brand-ink">Quién reclama</h2>
          <Rows rows={[
            ['Nombre', c.name],
            ['Documento', `${c.document_type} ${c.document_number}`],
            ['Domicilio', c.address],
            ['Teléfono', c.phone],
            ['Correo', c.email],
            ['Menor de edad', c.is_minor ? 'Sí' : null],
            ['Padre, madre o apoderado', item.guardian ? `${item.guardian.name} (${item.guardian.document_type} ${item.guardian.document_number})` : null],
          ]} />
          <h2 className="text-sm font-black text-brand-ink">Lo que contrató</h2>
          <Rows rows={[
            [item.good_type === 'producto' ? 'Producto' : 'Servicio', item.good_description],
            ['Monto', item.amount !== null ? money(item.amount, item.currency) : 'No lo indicó'],
          ]} />
          <p className="text-xs text-slate-400">Libro de {issuerLine(item.company) || item.company.name}</p>
        </section>

        <section className={card}>
          <h2 className="text-sm font-black text-brand-ink">Qué pasó</h2>
          <Rows rows={[['Detalle', item.detail], ['Pedido', item.request]]} />

          <h2 className="text-sm font-black text-brand-ink">Respuesta</h2>
          <SuccessAlert message={notice} />
          {item.status === 'answered' ? (
            <div className="rounded-2xl border border-emerald-200 bg-emerald-50/50 p-4 space-y-1" data-complaint-response>
              <p className="text-sm text-slate-800 whitespace-pre-line">{item.response}</p>
              <p className="text-xs text-slate-500">Respondido el {longDate(item.responded_at ?? '')} para {c.email}{item.responder ? ` por ${item.responder}` : ''}.</p>
            </div>
          ) : canAnswer ? (
            <div className="space-y-3" data-complaint-answer>
              <textarea aria-label="Respuesta" rows={6} maxLength={5000} value={response} onChange={(e) => setResponse(e.target.value)} className={inputClass} placeholder="Explica qué se hará para solucionarlo o por qué no corresponde. Le llegará por correo tal cual." />
              <p className="text-xs text-slate-500">Una vez enviada, la respuesta no se puede cambiar.</p>
              <ErrorAlert message={error} />
              <button type="button" data-complaint-send disabled={busy || response.trim().length < 10} onClick={send} className="inline-flex items-center gap-1.5 px-5 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover disabled:opacity-50">
                {busy ? <Loader2 size={15} className="animate-spin" /> : <Send size={15} />} Enviar respuesta
              </button>
            </div>
          ) : (
            <p className="text-sm text-slate-500">Aún no tiene respuesta. Responder necesita el permiso "Libro de Reclamaciones: Responder".</p>
          )}
        </section>
      </div>
    </div>
  );
}
