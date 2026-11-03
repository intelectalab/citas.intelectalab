'use client';

import { useCallback, useEffect, useState } from 'react';
import { BellRing, Boxes, CalendarCheck, CalendarClock, CalendarOff, CalendarPlus, CalendarX2, CreditCard, DoorOpen, FileCheck2, FileText, FileX2, Gauge, Globe, Hourglass, ListOrdered, NotebookPen, PowerOff, ShieldAlert, Stethoscope, Unlock, Wallet, Wrench, type LucideIcon } from 'lucide-react';
import { api } from '@/lib/axios';

export interface AppNotification {
  id: string;
  type: string;
  title: string;
  body: string | null;
  link: string | null;
  entity_type: string | null;
  entity_id: string | null;
  read: boolean;
  created_at: string;
  seconds_ago: number;
}

export interface NotificationList {
  unread: number;
  total: number;
  items: AppNotification[];
}

const STYLES: Record<string, { icon: LucideIcon; tone: string }> = {
  'appointment.booked': { icon: CalendarPlus, tone: 'bg-brand-soft/25 text-brand-ink' },
  'appointment.moved': { icon: CalendarClock, tone: 'bg-amber-50 text-amber-600' },
  'appointment.cancelled': { icon: CalendarX2, tone: 'bg-rose-50 text-rose-600' },
  'appointment.portal_booked': { icon: Globe, tone: 'bg-brand-soft/25 text-brand-ink' },
  'appointment.portal_moved': { icon: CalendarClock, tone: 'bg-amber-50 text-amber-600' },
  'appointment.portal_cancelled': { icon: CalendarX2, tone: 'bg-rose-50 text-rose-600' },
  'appointment.portal_confirmed': { icon: CalendarCheck, tone: 'bg-emerald-50 text-emerald-600' },
  'block.soon': { icon: CalendarOff, tone: 'bg-violet-50 text-violet-600' },
  'appointment.unconfirmed': { icon: BellRing, tone: 'bg-amber-50 text-amber-600' },
  'appointment.released': { icon: Unlock, tone: 'bg-rose-50 text-rose-600' },
  'waitlist.slot_freed': { icon: Hourglass, tone: 'bg-emerald-50 text-emerald-600' },
  'walkin.added': { icon: ListOrdered, tone: 'bg-amber-50 text-amber-600' },
  'room.down': { icon: PowerOff, tone: 'bg-rose-50 text-rose-600' },
  'room.observed': { icon: Wrench, tone: 'bg-amber-50 text-amber-600' },
  'room.up': { icon: DoorOpen, tone: 'bg-emerald-50 text-emerald-600' },
  'cash.difference': { icon: Wallet, tone: 'bg-rose-50 text-rose-600' },
  'quote.accepted': { icon: FileCheck2, tone: 'bg-emerald-50 text-emerald-600' },
  'quote.rejected': { icon: FileX2, tone: 'bg-rose-50 text-rose-600' },
  'package.bought': { icon: Boxes, tone: 'bg-brand-soft/25 text-brand-ink' },
  'block.affected': { icon: CalendarOff, tone: 'bg-amber-50 text-amber-600' },
  'recall.due': { icon: Stethoscope, tone: 'bg-sky-50 text-sky-600' },
  'record.draft': { icon: NotebookPen, tone: 'bg-amber-50 text-amber-600' },
  'record.emergency': { icon: ShieldAlert, tone: 'bg-rose-50 text-rose-600' },
  'plan.limit': { icon: Gauge, tone: 'bg-amber-50 text-amber-600' },
  'subscription.ending': { icon: CreditCard, tone: 'bg-rose-50 text-rose-600' },
  'subscription.charged': { icon: CreditCard, tone: 'bg-emerald-50 text-emerald-600' },
  'subscription.card': { icon: CreditCard, tone: 'bg-sky-50 text-sky-600' },
  'subscription.charge_failed': { icon: CreditCard, tone: 'bg-rose-50 text-rose-600' },
  'subscription.grace': { icon: CreditCard, tone: 'bg-amber-50 text-amber-600' },
  'quote.sent': { icon: FileText, tone: 'bg-brand-soft/25 text-brand-ink' },
};

export const notificationStyle = (type: string) => STYLES[type] ?? { icon: CalendarCheck, tone: 'bg-slate-100 text-slate-600' };

export const timeAgo = (seconds: number): string => {
  if (seconds < 60) return 'Ahora mismo';
  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) return `Hace ${minutes} min`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `Hace ${hours} h`;
  const days = Math.floor(hours / 24);
  return days === 1 ? 'Ayer' : `Hace ${days} días`;
};

const CHANGED = 'notifications:changed';

export function useNotifications(limit: number, enabled: boolean) {
  const [data, setData] = useState<NotificationList>({ unread: 0, total: 0, items: [] });
  const [loaded, setLoaded] = useState(false);

  const load = useCallback(() => {
    if (!enabled) return;

    api.get('/notifications', { params: { limit } })
      .then((res) => setData({ unread: res.data?.unread ?? 0, total: res.data?.total ?? 0, items: Array.isArray(res.data?.items) ? res.data.items : [] }))
      .catch(() => undefined)
      .finally(() => setLoaded(true));
  }, [limit, enabled]);

  useEffect(() => { load(); }, [load]);

  useEffect(() => {
    window.addEventListener(CHANGED, load);
    return () => window.removeEventListener(CHANGED, load);
  }, [load]);

  const markRead = useCallback((id: string) => {
    setData((current) => {
      const target = current.items.find((item) => item.id === id);
      if (!target || target.read) return current;
      return { ...current, unread: Math.max(0, current.unread - 1), items: current.items.map((item) => (item.id === id ? { ...item, read: true } : item)) };
    });
    api.post(`/notifications/${id}/read`).catch(() => undefined).finally(() => window.dispatchEvent(new Event(CHANGED)));
  }, []);

  const markAllRead = useCallback(() => {
    setData((current) => ({ ...current, unread: 0, items: current.items.map((item) => ({ ...item, read: true })) }));
    api.post('/notifications/read-all').catch(() => undefined).finally(() => window.dispatchEvent(new Event(CHANGED)));
  }, []);

  return { ...data, loaded, load, markRead, markAllRead };
}
