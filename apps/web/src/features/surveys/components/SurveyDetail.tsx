'use client';

import { useEffect, useState } from 'react';
import { Eye, EyeOff, Loader2, PhoneCall, Reply } from 'lucide-react';
import { api } from '@/lib/axios';
import { useVocabulary } from '@/hooks/useVocabulary';
import Badge from '@/app/components/common/Badge';
import BackLink from '@/app/components/common/BackLink';
import Button from '@/app/components/common/Button';
import Select from '@/app/components/common/Select';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { CustomerLink, ProfessionalLink } from '@/app/components/common/PersonLink';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { apiError } from '@/features/appointments/appointments';
import { FOLLOW_TONE, scoreTone, shortDate, type Survey } from '../surveys';

const card = 'bg-white rounded-3xl border border-slate-200/80 shadow-xs p-5 sm:p-6 space-y-4';

export default function SurveyDetail({ id }: { id: string }) {
  const vocab = useVocabulary();
  const [item, setItem] = useState<Survey | null>(null);
  const [canEdit, setCanEdit] = useState(false);
  const [followUp, setFollowUp] = useState('contacted');
  const [note, setNote] = useState('');
  const [reply, setReply] = useState('');
  const [busy, setBusy] = useState('');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const fill = (data: { survey: Survey; can_edit: boolean }) => {
    setItem(data.survey);
    setCanEdit(data.can_edit);
    setReply(data.survey.reply ?? '');
  };

  useEffect(() => {
    api.get(`/surveys/${id}`).then((res) => fill(res.data)).catch((err) => setError(apiError(err, 'No encontramos esa encuesta.')));
  }, [id]);

  const run = async (key: string, path: string, body: object, done: string) => {
    try {
      setBusy(key);
      setError('');
      setNotice('');
      const res = await api.put(`/surveys/${id}/${path}`, body);
      fill(res.data);
      setNotice(done);
      if (key === 'recovery') setNote('');
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar.'));
    } finally {
      setBusy('');
    }
  };

  if (!item) {
    return (
      <div className="space-y-4">
        <BackLink href="/surveys">Encuestas y reseñas</BackLink>
        {error ? <ErrorAlert message={error} /> : <Loader2 className="w-6 h-6 text-brand-accent animate-spin" />}
      </div>
    );
  }

  return (
    <div className="space-y-5 pb-10 font-sans" data-survey-detail={item.id}>
      <BackLink href="/surveys">Encuestas y reseñas</BackLink>
      <div className="flex flex-wrap items-center gap-3">
        <h1 className="text-2xl font-black text-brand-ink tracking-tight">Encuesta de {item.customer.name}</h1>
        <Badge tone={scoreTone(item.score)} bold>{item.score === null ? item.status_label : `${item.score} de 10`}</Badge>
        {item.follow_up && <Badge tone={FOLLOW_TONE[item.follow_up]} bold>{item.follow_up_label}</Badge>}
      </div>

      <ErrorAlert message={error} />
      <SuccessAlert message={notice} />

      <div className="grid grid-cols-1 xl:grid-cols-2 gap-5">
        <section className={card}>
          <h2 className="text-sm font-black text-brand-ink">La atención</h2>
          <dl className="rounded-2xl border border-slate-200 divide-y divide-slate-100 text-sm">
            {[
              [vocab.client, <CustomerLink key="c" id={item.customer.id}>{item.customer.name}</CustomerLink>],
              ['Celular', item.customer.phone],
              ['Servicio', item.service?.name],
              [vocab.pro, item.professional ? <ProfessionalLink key="p" id={item.professional.id}>{item.professional.name}</ProfessionalLink> : null],
              ['Sede', item.branch?.name],
              ['Cita', item.appointment.code ? `${item.appointment.code} · ${shortDate(item.appointment.starts_at)}` : null],
              ['Encuesta', item.status_label + (item.skip_reason ? `: ${item.skip_reason}` : '')],
              ['Enviada', item.sent_at ? shortDate(item.sent_at) : `Sale el ${shortDate(item.send_at)}`],
              ['Vence', shortDate(item.expires_at)],
              ['Respondida', item.answered_at ? shortDate(item.answered_at) : null],
              ['Google', item.google_invited ? (item.google_clicked ? 'Se le invitó y abrió la reseña' : 'Se le invitó a reseñar') : null],
            ].filter(([, v]) => v).map(([label, value]) => (
              <div key={String(label)} className="flex flex-col sm:flex-row sm:justify-between gap-1 px-4 py-2.5">
                <dt className="text-xs text-slate-500 shrink-0">{label}</dt>
                <dd className="font-semibold text-slate-800 sm:text-right break-words">{value}</dd>
              </div>
            ))}
          </dl>
          {item.comment && (
            <blockquote className="rounded-2xl bg-slate-50 border border-slate-200 p-4 text-sm text-slate-700 whitespace-pre-line" data-survey-comment>«{item.comment}»</blockquote>
          )}
        </section>

        <div className="space-y-5">
          {item.follow_up && (
            <section className={card} data-survey-recovery>
              <h2 className="flex items-center gap-2 text-sm font-black text-brand-ink"><PhoneCall size={16} className="text-brand-accent" /> Recuperar al {vocab.client.toLowerCase()}</h2>
              {item.follow_up_note && (
                <p className="text-sm text-slate-700 bg-slate-50 rounded-xl px-4 py-3">{item.follow_up_note}<span className="block text-xs text-slate-500 mt-1">{item.follow_up_by ? `${item.follow_up_by} · ` : ''}{shortDate(item.follow_up_at)}</span></p>
              )}
              {canEdit ? (
                <div className="space-y-3">
                  <p className="text-xs text-slate-500">Llámalo o escríbele, pregúntale qué pasó y ofrécele una solución. Luego anota qué se habló: queda pendiente hasta que lo registres.</p>
                  <Select label="Cómo va" value={followUp} clearable={false} options={[{ value: 'contacted', label: 'Ya lo contactamos' }, { value: 'resolved', label: 'Resuelto' }]} onChange={(e) => setFollowUp(String(e.target.value))} />
                  <label className="block">
                    <span className={labelClass}>Qué se habló</span>
                    <textarea rows={3} maxLength={1000} className={inputClass} value={note} onChange={(e) => setNote(e.target.value)} placeholder="Ej. La llamamos, se disculpó la sede y le dimos un 20 % en su próxima cita." />
                  </label>
                  <Button variant="primary" type="button" disabled={busy !== '' || note.trim().length < 5} onClick={() => run('recovery', 'recovery', { follow_up: followUp, note }, 'Seguimiento registrado.')} data-action="save-recovery">
                    {busy === 'recovery' ? 'Guardando...' : 'Registrar el contacto'}
                  </Button>
                </div>
              ) : <p className="text-xs text-slate-500">Registrar el contacto necesita el permiso «Encuestas y reseñas: Gestionar y publicar».</p>}
            </section>
          )}

          {item.score !== null && (
            <section className={card} data-survey-review>
              <h2 className="text-sm font-black text-brand-ink">Reseña en tu página de reservas</h2>
              {!item.comment ? (
                <p className="text-sm text-slate-500">No dejó comentario, así que no hay reseña para mostrar. Su nota sí cuenta en el promedio.</p>
              ) : !item.publish_consent ? (
                <p className="text-sm text-slate-500">No aceptó que su comentario se muestre: no se puede publicar.</p>
              ) : (
                <>
                  <p className="text-sm text-slate-600">{item.published ? 'Se muestra en tu página de reservas con su nombre abreviado.' : item.hidden ? 'La ocultaste: no se muestra.' : 'Aceptó que se muestre. Revisa que no tenga datos de salud antes de publicarla.'} El comentario no se puede editar.</p>
                  {canEdit && (
                    <Button variant={item.published ? 'secondary' : 'primary'} type="button" disabled={busy !== ''} onClick={() => run('publish', 'publish', { published: !item.published }, item.published ? 'Reseña oculta.' : 'Reseña publicada en tu página de reservas.')} data-action="toggle-publish" className="inline-flex items-center gap-1.5">
                      {item.published ? <EyeOff size={15} /> : <Eye size={15} />} {item.published ? 'Ocultar' : 'Publicar'}
                    </Button>
                  )}
                </>
              )}
              {canEdit && item.comment && item.publish_consent && (
                <div className="space-y-2 border-t border-slate-100 pt-4">
                  <label className="block">
                    <span className={labelClass}>Tu respuesta (se ve debajo de la reseña)</span>
                    <textarea rows={2} maxLength={600} className={inputClass} value={reply} onChange={(e) => setReply(e.target.value)} placeholder="Ej. ¡Gracias, Lucía! Te esperamos pronto." />
                  </label>
                  <Button variant="secondary" type="button" disabled={busy !== ''} onClick={() => run('reply', 'reply', { reply }, reply.trim() ? 'Respuesta guardada.' : 'Respuesta quitada.')} data-action="save-reply" className="inline-flex items-center gap-1.5">
                    <Reply size={15} /> {busy === 'reply' ? 'Guardando...' : 'Guardar respuesta'}
                  </Button>
                </div>
              )}
            </section>
          )}
        </div>
      </div>
    </div>
  );
}
