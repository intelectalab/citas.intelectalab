'use client';

import { useEffect, useState } from 'react';
import { MessageSquareHeart } from 'lucide-react';
import { api } from '@/lib/axios';

interface PendingSurvey {
  url: string;
  service: string | null;
  professional: string | null;
}

export default function PortalSurveys() {
  const [items, setItems] = useState<PendingSurvey[]>([]);

  useEffect(() => {
    api.get<{ surveys: PendingSurvey[] }>('/portal/surveys')
      .then((res) => setItems(res.data.surveys ?? []))
      .catch(() => setItems([]));
  }, []);

  if (items.length === 0) return null;

  return (
    <section className="bg-white rounded-3xl border border-slate-200/80 shadow-xs p-5 space-y-3" data-portal-surveys>
      <h2 className="flex items-center gap-2 text-sm font-bold text-brand-ink"><MessageSquareHeart className="w-4 h-4 text-brand-accent" /> ¿Cómo te fue?</h2>
      <ul className="space-y-2">
        {items.map((item) => (
          <li key={item.url} className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 text-sm">
            <span className="text-slate-600">{[item.service, item.professional ? `con ${item.professional}` : null].filter(Boolean).join(' ') || 'Tu última atención'}</span>
            <a href={item.url} className="inline-flex justify-center px-4 py-2 text-xs font-bold bg-brand text-white rounded-xl hover:bg-brand-hover">Responder la encuesta</a>
          </li>
        ))}
      </ul>
    </section>
  );
}
