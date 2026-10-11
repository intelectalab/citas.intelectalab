'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { Bell, BellOff, CheckCheck } from 'lucide-react';
import { useAuth } from '@/hooks/useAuth';
import { PagedList } from '@/app/components/common/Pager';
import { useAppointmentPanels } from '@/features/appointments/components/useAppointmentPanels';
import { useAutoRefresh } from '@/features/appointments/components/useAutoRefresh';
import NotificationItem from './NotificationItem';
import { useNotifications, type AppNotification } from '../notifications';

const VIEWS: { id: 'all' | 'unread'; label: string }[] = [
  { id: 'all', label: 'Todas' },
  { id: 'unread', label: 'Sin leer' },
];

// Todas las notificaciones de quien tiene la sesión abierta, de a seis.
export default function NotificationsPage() {
  const { user, hasModule } = useAuth();
  const router = useRouter();
  const [view, setView] = useState<'all' | 'unread'>('all');

  const enabled = Boolean(user?.tenant);
  const { unread, total, items, loaded, load, markRead, markAllRead } = useNotifications(300, enabled);
  const { openDetail, panels, isOpen: panelOpen } = useAppointmentPanels(load);

  useAutoRefresh(load, !enabled || panelOpen, 30000);

  const openItem = (item: AppNotification) => {
    markRead(item.id);

    if (item.entity_type === 'appointment' && item.entity_id && hasModule('agenda')) {
      openDetail(item.entity_id);
    } else if (item.link) {
      router.push(item.link);
    }
  };

  const shown = view === 'unread' ? items.filter((item) => !item.read) : items;

  return (
    <div className="space-y-5 pb-8 font-sans">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="flex items-center gap-2.5 text-2xl font-black text-[#022340] tracking-tight"><Bell className="w-6 h-6 text-[#3F768C]" /> Notificaciones</h1>
          <p className="text-sm text-slate-500 mt-1">
            Lo que pasó con tus citas y con el equipo. Un clic abre la cita o te lleva a su pantalla.
          </p>
        </div>
        {unread > 0 && (
          <button type="button" data-action="read-all" onClick={markAllRead} className="inline-flex items-center gap-2 px-4 py-2 text-sm font-bold text-white bg-[#022340] hover:bg-[#03315a] rounded-xl transition-colors">
            <CheckCheck size={16} /> Marcar todo como leído
          </button>
        )}
      </div>

      <section className="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden" data-card="notifications">
        <div className="flex items-center gap-2 px-6 py-3 border-b border-slate-100">
          {VIEWS.map((option) => (
            <button
              key={option.id}
              type="button"
              data-view={option.id}
              aria-pressed={view === option.id}
              onClick={() => setView(option.id)}
              className={`px-3.5 py-1.5 text-sm font-semibold rounded-xl transition-colors ${view === option.id ? 'bg-[#022340] text-white' : 'text-slate-600 hover:bg-slate-100'}`}
            >
              {option.label}
              <span className={`ml-2 text-xs ${view === option.id ? 'text-[#84D0D9]' : 'text-slate-400'}`}>{option.id === 'all' ? total : unread}</span>
            </button>
          ))}
        </div>

        {shown.length === 0 ? (
          <div className="px-6 py-14 text-center">
            <BellOff className="w-9 h-9 text-slate-300 mx-auto mb-2" />
            <p className="text-sm text-slate-500">
              {!loaded ? 'Cargando…' : view === 'unread' ? 'No tienes notificaciones sin leer.' : 'Aún no tienes notificaciones.'}
            </p>
          </div>
        ) : (
          <div className="flex flex-col">
            <PagedList key={view} items={shown} empty="" renderRow={(item) => <li key={item.id}><NotificationItem item={item} onOpen={openItem} roomy /></li>} />
          </div>
        )}
      </section>

      {panels}
    </div>
  );
}
