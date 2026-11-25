'use client';

import { useEffect, useState } from 'react';
import { Cookie, Star } from 'lucide-react';
import { loadTrackers, readConsent, saveConsent, type Tracking } from '../tracking';

export interface PublicReviews {
  count: number;
  average: number | null;
  items: { id: string; name: string; stars: number; comment: string | null; reply: string | null; service: string | null; date: string }[];
}

const stars = (n: number) => Array.from({ length: 5 }, (_, i) => (
  <Star key={i} size={13} className={i < n ? 'text-amber-400 fill-amber-400' : 'text-slate-300'} />
));

export function ReviewsSection({ reviews, company, narrow = false }: { reviews: PublicReviews | null | undefined; company: string; narrow?: boolean }) {
  if (!reviews || reviews.items.length === 0) return null;

  return (
    <section className="bg-white rounded-3xl border border-slate-200/80 p-5 sm:p-6 space-y-3" data-reviews>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-sm font-black text-brand-ink">Lo que dicen de {company}</h2>
        {reviews.average !== null && (
          <span className="inline-flex items-center gap-1 text-xs text-slate-600" data-reviews-average>
            <span className="inline-flex">{stars(Math.round(reviews.average))}</span>
            <b className="text-slate-800">{reviews.average.toFixed(1)}</b> / 5 · {reviews.count} {reviews.count === 1 ? 'opinión' : 'opiniones'}
          </span>
        )}
      </div>
      <div className={`grid grid-cols-1 gap-3 ${narrow || reviews.items.length === 1 ? '' : 'sm:grid-cols-2'}`}>
        {reviews.items.map((item) => (
          <figure key={item.id} className="rounded-2xl bg-slate-50 border border-slate-100 p-4 space-y-1.5 text-sm">
            <span className="inline-flex">{stars(item.stars)}</span>
            {item.comment && <blockquote className="text-slate-700">«{item.comment}»</blockquote>}
            <figcaption className="text-xs text-slate-500">{item.name}{item.service ? ` · ${item.service}` : ''}</figcaption>
            {item.reply && <p className="text-xs text-slate-600 border-l-2 border-brand-accent pl-2">Respuesta: {item.reply}</p>}
          </figure>
        ))}
      </div>
    </section>
  );
}

export function TrackingConsent({ tracking, onChoose }: { tracking: Tracking | null | undefined; onChoose?: (value: 'all' | 'necessary') => void }) {
  const [show, setShow] = useState(false);
  const has = Boolean(tracking?.ga4_id || tracking?.meta_pixel_id);

  useEffect(() => {
    if (!has || !tracking) return;
    const stored = readConsent();
    if (stored === 'all') loadTrackers(tracking);
    else if (!stored) setShow(true);
  }, [has, tracking]);

  if (!show || !tracking) return null;

  const choose = (value: 'all' | 'necessary') => {
    saveConsent(value);
    if (value === 'all') loadTrackers(tracking);
    setShow(false);
    onChoose?.(value);
  };

  return (
    <div role="dialog" aria-label="Aviso de cookies" data-cookie-banner className="fixed inset-x-0 bottom-0 z-50 p-3 sm:p-4 pointer-events-none">
      <div className="pointer-events-auto max-w-2xl mx-auto bg-white border border-slate-200 shadow-2xl rounded-2xl p-4 flex flex-col sm:flex-row sm:items-center gap-3">
        <Cookie size={20} className="text-brand-accent shrink-0 hidden sm:block" />
        <p className="text-xs sm:text-sm text-slate-600 flex-1">Usamos cookies para medir cuántas personas reservan desde cada red. Puedes aceptarlas o quedarte solo con las necesarias.</p>
        <div className="flex gap-2 shrink-0">
          <button type="button" data-cookie-necessary onClick={() => choose('necessary')} className="flex-1 sm:flex-none px-4 py-2 text-xs sm:text-sm font-semibold text-slate-700 border border-slate-200 rounded-xl hover:bg-slate-50 cursor-pointer">Solo las necesarias</button>
          <button type="button" data-cookie-accept onClick={() => choose('all')} className="flex-1 sm:flex-none px-4 py-2 text-xs sm:text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover cursor-pointer">Aceptar</button>
        </div>
      </div>
    </div>
  );
}
