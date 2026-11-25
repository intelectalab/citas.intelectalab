'use client';

import { useEffect, useState } from 'react';
import { AlertTriangle, Megaphone, Wrench } from 'lucide-react';
import { api } from '@/lib/axios';
import Badge from '@/app/components/common/Badge';
import RichTextView from '@/app/components/common/RichTextView';
import { ErrorAlert } from '@/app/components/common/Alert';
import { apiError } from '@/features/appointments/appointments';
import { KIND_TONE, type FeedItem } from '../announcements';
import { AnnouncementLink } from './AnnouncementsBar';

const ICON = { news: Megaphone, maintenance: Wrench, important: AlertTriangle };

const dayOf = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString('es-PE', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'America/Lima' }) : '');
const lastDayOf = (iso: string | null) => (iso ? dayOf(new Date(new Date(iso).getTime() - 60000).toISOString()) : '');

export default function NewsPage() {
  const [items, setItems] = useState<FeedItem[] | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    api.get<{ items: FeedItem[] }>('/announcements', { params: { all: 1 } })
      .then((res) => setItems(res.data.items ?? []))
      .catch((err) => { setItems([]); setError(apiError(err, 'No se pudieron cargar las novedades.')); });
  }, []);

  return (
    <div className="space-y-5 pb-10 font-sans max-w-3xl" data-news-page>
      <div>
        <h1 className="flex items-center gap-2.5 text-2xl font-black text-brand-ink tracking-tight"><Megaphone className="w-6 h-6 text-brand-accent" /> Novedades</h1>
        <p className="text-sm text-slate-500 font-medium">Lo nuevo del sistema, los mantenimientos programados y los avisos importantes. Aquí siguen aunque los hayas cerrado en el Inicio.</p>
      </div>

      <ErrorAlert message={error} />

      {items === null ? (
        <p className="text-sm text-slate-500">Cargando novedades...</p>
      ) : items.length === 0 ? (
        <section className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 text-center" data-news-empty>
          <p className="text-sm font-bold text-brand-ink">Sin novedades por ahora</p>
          <p className="text-sm text-slate-500 mt-1">Aquí verás las funciones nuevas y los avisos del equipo de la plataforma.</p>
        </section>
      ) : (
        <div className="space-y-3">
          {items.map((item) => {
            const Icon = ICON[item.kind] ?? Megaphone;
            return (
              <article key={item.id} className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-5" data-news-item={item.id}>
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <h2 className="flex items-center gap-2 text-base font-black text-brand-ink"><Icon size={17} className="shrink-0 text-brand-accent" /> {item.title}</h2>
                  <Badge tone={KIND_TONE[item.kind]} bold nowrap>{item.kind_label}</Badge>
                </div>
                <RichTextView body={item.body} className="mt-2 text-sm text-slate-700" />
                <p className="mt-2 text-xs text-slate-500">Desde el {dayOf(item.starts_at)}{item.ends_at ? ` · hasta el ${lastDayOf(item.ends_at)}` : ''}</p>
                {item.link_url && <div className="mt-3"><AnnouncementLink item={item} /></div>}
              </article>
            );
          })}
        </div>
      )}
    </div>
  );
}
