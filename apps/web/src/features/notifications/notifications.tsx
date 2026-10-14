'use client';

import { useCallback, useEffect, useState } from 'react';
import { BellRing, CalendarCheck, CalendarClock, CalendarOff, CalendarPlus, CalendarX2, DoorOpen, Globe, Hourglass, PowerOff, Unlock, Wallet, Wrench, type LucideIcon } from 'lucide-react';
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
  'appointment.booked': { icon: CalendarPlus, tone: 'bg-[#84D0D9]/25 text-[#022340]' },
  'appointment.moved': { icon: CalendarClock, tone: 'bg-amber-50 text-amber-600' },
  'appointment.cancelled': { icon: CalendarX2, tone: 'bg-rose-50 text-rose-600' },
  'appointment.portal_booked': { icon: Globe, tone: 'bg-[#84D0D9]/25 text-[#022340]' },
  'appointment.portal_moved': { icon: CalendarClock, tone: 'bg-amber-50 text-amber-600' },
  'appointment.portal_cancelled': { icon: CalendarX2, tone: 'bg-rose-50 text-rose-600' },
  'appointment.portal_confirmed': { icon: CalendarCheck, tone: 'bg-emerald-50 text-emerald-600' },
  'block.soon': { icon: CalendarOff, tone: 'bg-violet-50 text-violet-600' },
  'appointment.unconfirmed': { icon: BellRing, tone: 'bg-amber-50 text-amber-600' },
  'appointment.released': { icon: Unlock, tone: 'bg-rose-50 text-rose-600' },
  'waitlist.slot_freed': { icon: Hourglass, tone: 'bg-emerald-50 text-emerald-600' },
  'room.down': { icon: PowerOff, tone: 'bg-rose-50 text-rose-600' },
  'room.observed': { icon: Wrench, tone: 'bg-amber-50 text-amber-600' },
  'room.up': { icon: DoorOpen, tone: 'bg-emerald-50 text-emerald-600' },
  'cash.difference': { icon: Wallet, tone: 'bg-rose-50 text-rose-600' },
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
