'use client';

import { useEffect, useState } from 'react';
import { Megaphone, Wrench, AlertTriangle, X } from 'lucide-react';
import { api } from '@/lib/axios';
import RichTextView from '@/app/components/common/RichTextView';
import { useAuth } from '@/hooks/useAuth';
import { KIND_CARD, type FeedItem } from '../announcements';

const ICON = { news: Megaphone, maintenance: Wrench, important: AlertTriangle };

export default function AnnouncementsBar() {
  const { user } = useAuth();
  const [items, setItems] = useState<FeedItem[]>([]);
  const isStaff = Boolean(user?.tenant) && !user?.is_saas_admin && !user?.is_saas_support;

  useEffect(() => {
    if (!isStaff) return;
    api.get<{ items: FeedItem[] }>('/announcements').then((res) => setItems(res.data.items ?? [])).catch(() => setItems([]));
  }, [isStaff]);

  const dismiss = async (id: string) => {
    setItems((list) => list.filter((item) => item.id !== id));
    try {
      await api.post(`/announcements/${id}/dismiss`);
    } catch {}
  };

  if (!items.length) return null;

  return (
    <div className="space-y-2" data-announcements>
      {items.map((item) => {
        const Icon = ICON[item.kind] ?? Megaphone;
        return (
          <section key={item.id} className={`relative rounded-2xl border px-4 py-3 pr-11 ${KIND_CARD[item.kind]}`} data-announcement={item.id}>
            <p className="flex items-center gap-2 text-sm font-bold text-brand-ink">
              <Icon size={16} className="shrink-0 text-brand-accent" />
              <span className="text-xs font-semibold text-slate-500">{item.kind_label}</span>
              <span>{item.title}</span>
            </p>
            <RichTextView body={item.body} className="mt-1 text-sm text-slate-700" />
            <button type="button" aria-label="Cerrar el anuncio" onClick={() => dismiss(item.id)} className="absolute top-2.5 right-2.5 p-1.5 rounded-lg text-slate-400 hover:text-brand-ink hover:bg-white/70 cursor-pointer" data-announcement-close>
              <X size={16} />
            </button>
          </section>
        );
      })}
    </div>
  );
}
