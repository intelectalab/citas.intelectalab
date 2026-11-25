'use client';

import { useEffect, useMemo, useState } from 'react';
import { CalendarClock, Check, Loader2, Star } from 'lucide-react';
import { api } from '@/lib/axios';
import { previewStyle, resolveColors } from '@/lib/brand';
import { formatDate } from '@/features/appointments/appointments';
import { portalError } from '@/features/portal/portal';

interface PublicSurvey {
  state: 'open' | 'answered' | 'expired';
  company: { name: string; slug: string; primary: string | null; accent: string | null; logo: string | null };
  service: string | null;
  professional: string | null;
  date: string;
  score: number | null;
  google: boolean;
  detractor: boolean;
  can_publish: boolean;
}

export default function SurveyAnswerPage({ token }: { token: string }) {
  const [data, setData] = useState<PublicSurvey | null>(null);
  const [score, setScore] = useState<number | null>(null);
  const [comment, setComment] = useState('');
  const [consent, setConsent] = useState(false);
  const [sending, setSending] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    api.get<PublicSurvey>(`/public/surveys/${token}`)
      .then((res) => { setData(res.data); document.title = `¿Cómo te fue en ${res.data.company.name}?`; })
      .catch((err) => setError(portalError(err, 'No se pudo abrir la encuesta.')));
  }, [token]);

  const style = useMemo(() => (data ? previewStyle(resolveColors({ primary: data.company.primary, accent: data.company.accent }), false) : undefined), [data]);

  const send = async () => {
    if (score === null) return;
    try {
      setSending(true);
      setError('');
      const res = await api.post<PublicSurvey>(`/public/surveys/${token}`, { score, comment: comment.trim() || null, publish_consent: consent });
      setData(res.data);
    } catch (err: any) {
      if (err?.response?.status === 409 && err.response.data?.state) setData(err.response.data);
      else setError(portalError(err, 'No se pudo enviar tu respuesta.'));
    } finally {
      setSending(false);
    }
  };

  const openGoogle = async () => {
    try {
      const res = await api.post<{ url: string }>(`/public/surveys/${token}/google`);
      window.location.href = res.data.url;
    } catch (err) {
      setError(portalError(err, 'No se pudo abrir Google.'));
    }
  };

  if (!data) {
    return (
      <main className="min-h-screen bg-[#F2F2F2] flex items-center justify-center px-4">
        {error ? <p role="alert" className="max-w-md text-center text-sm text-slate-600 bg-white border border-slate-200 rounded-2xl px-6 py-5">{error}</p> : <Loader2 size={22} className="animate-spin text-slate-400" />}
      </main>
    );
  }

  return (
    <main className="theme-scope min-h-screen bg-[#F2F2F2] text-slate-800 px-4 py-8" style={style} data-survey-page={data.state}>
      <div className="max-w-lg mx-auto bg-white rounded-3xl border border-slate-200/80 shadow-xs p-6 sm:p-8 space-y-5 text-center">
        {data.company.logo ? (
          <img src={data.company.logo} alt={data.company.name} className="w-16 h-16 mx-auto rounded-2xl object-contain border border-slate-100" />
        ) : (
          <span className="w-16 h-16 mx-auto rounded-2xl bg-brand text-white flex items-center justify-center"><CalendarClock size={26} /></span>
        )}

        {data.state === 'expired' && (
          <div className="space-y-2">
            <h1 className="text-xl font-black text-brand-ink">Esta encuesta ya venció</h1>
            <p className="text-sm text-slate-600">Gracias por tu interés. Si quieres contarnos algo, escríbenos directamente.</p>
          </div>
        )}

        {data.state === 'answered' && (
          <div className="space-y-4" data-survey-thanks>
            <span className="w-12 h-12 mx-auto rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center"><Check size={24} /></span>
            <h1 className="text-xl font-black text-brand-ink">¡Gracias por tu respuesta!</h1>
            {data.google ? (
              <>
                <p className="text-sm text-slate-600">¿Nos ayudas con una reseña en Google? Toma menos de un minuto y ayuda a otras personas a encontrarnos.</p>
                <button type="button" onClick={openGoogle} data-survey-google className="w-full inline-flex items-center justify-center gap-2 px-5 py-3 text-sm font-bold bg-brand text-white rounded-2xl hover:bg-brand-hover cursor-pointer">
                  <Star size={16} /> Dejar mi reseña en Google
                </button>
              </>
            ) : (
              <p className="text-sm text-slate-600">{data.detractor ? 'Sentimos que no haya sido como esperabas. Tu opinión ya llegó al equipo y te contactaremos.' : 'Tu opinión ya llegó al equipo.'}</p>
            )}
          </div>
        )}

        {data.state === 'open' && (
          <div className="space-y-5">
            <div className="space-y-1">
              <h1 className="text-xl font-black text-brand-ink">¿Cómo te fue en {data.company.name}?</h1>
              <p className="text-xs text-slate-500 first-letter:uppercase">{[data.service, data.professional ? `con ${data.professional}` : null].filter(Boolean).join(' ')} · {formatDate(data.date)}</p>
            </div>
            <p className="text-sm text-slate-700">Del 0 al 10, ¿qué tan probable es que nos recomiendes a un amigo o familiar?</p>
            <div className="grid grid-cols-6 sm:grid-cols-11 gap-1.5" role="radiogroup" aria-label="Nota del 0 al 10">
              {Array.from({ length: 11 }, (_, n) => (
                <button key={n} type="button" role="radio" aria-checked={score === n} data-score={n} onClick={() => setScore(n)}
                  className={`h-11 rounded-xl border text-sm font-bold cursor-pointer transition-colors ${score === n ? 'bg-brand text-white border-brand' : 'bg-white text-slate-700 border-slate-200 hover:border-brand-accent'}`}>
                  {n}
                </button>
              ))}
            </div>
            <div className="flex justify-between text-[11px] text-slate-500"><span>Nada probable</span><span>Muy probable</span></div>
            <textarea rows={3} maxLength={1000} value={comment} onChange={(e) => setComment(e.target.value)} aria-label="Comentario" placeholder="¿Algo que quieras contarnos? (opcional)"
              className="w-full text-sm border border-slate-200 rounded-xl px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-brand/20 focus:border-brand bg-white text-left" />
            {data.can_publish && comment.trim() !== '' && (
              <button type="button" role="checkbox" aria-checked={consent} onClick={() => setConsent(!consent)} data-survey-consent className="w-full flex items-start gap-3 text-left text-xs text-slate-600 cursor-pointer">
                <span className={`mt-0.5 w-4 h-4 shrink-0 rounded border flex items-center justify-center ${consent ? 'bg-brand border-brand text-white' : 'border-slate-300 bg-white'}`}>{consent && <Check size={12} />}</span>
                Acepto que mi comentario se muestre en la página de reservas con mi nombre abreviado (por ejemplo, «Lucía P.»).
              </button>
            )}
            {error && <p role="alert" className="text-sm text-rose-700">{error}</p>}
            <button type="button" disabled={score === null || sending} onClick={send} data-survey-send className="w-full px-5 py-3 text-sm font-bold bg-brand text-white rounded-2xl hover:bg-brand-hover disabled:opacity-50 cursor-pointer">
              {sending ? 'Enviando...' : 'Enviar'}
            </button>
          </div>
        )}
      </div>
    </main>
  );
}
