'use client';

import { useRef, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { Bell, BellOff, CheckCheck } from 'lucide-react';
import { useAuth } from '@/hooks/useAuth';
import Floating from '@/app/components/common/Floating';
import { useAppointmentPanels } from '@/features/appointments/components/useAppointmentPanels';
import { useAutoRefresh } from '@/features/appointments/components/useAutoRefresh';
import NotificationItem from './NotificationItem';
import { useNotifications, type AppNotification } from '../notifications';

// Cuántos avisos muestra la campana; el resto está en la página de notificaciones.
const BELL_LIMIT = 8;

/**
 * La campana de la barra superior: cuántos avisos hay sin leer y los últimos.
 * Un aviso de una cita la abre ahí mismo; los demás llevan a su pantalla.
 */
export default function NotificationsBell() {
  const { user, hasModule } = useAuth();
  const router = useRouter();
  const buttonRef = useRef<HTMLButtonElement>(null);
  const [open, setOpen] = useState(false);

  // Solo el equipo de una empresa tiene avisos.
  const enabled = Boolean(user?.tenant) && user?.account_type !== 'customer';
  const { unread, total, items, loaded, load, markRead, markAllRead } = useNotifications(BELL_LIMIT, enabled);
  const { openDetail, panels, isOpen: panelOpen } = useAppointmentPanels(load);

  // Se actualiza sola para que el número cambie sin recargar.
  useAutoRefresh(load, !enabled || panelOpen, 30000);

  if (!enabled) return null;

  const openItem = (item: AppNotification) => {
    markRead(item.id);
    setOpen(false);

    if (item.entity_type === 'appointment' && item.entity_id && hasModule('agenda')) {
      openDetail(item.entity_id);
    } else if (item.link) {
      router.push(item.link);
    }
  };

  return (
    <>
      <button
        ref={buttonRef}
        type="button"
        data-bell
        aria-haspopup="dialog"
        aria-expanded={open}
        aria-label={unread ? `Notificaciones: ${unread} sin leer` : 'Notificaciones'}
        title="Notificaciones"
        onClick={() => { if (!open) load(); setOpen(!open); }}
        className={`p-2 text-gray-600 hover:bg-gray-100 rounded-full transition-colors relative ${open ? 'bg-gray-100' : ''}`}
      >
        <Bell size={20} />
        {unread > 0 && (
          <span data-bell-count className="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 flex items-center justify-center text-[10px] font-bold text-white bg-[#FF7A00] rounded-full ring-2 ring-white">
            {unread > 99 ? '99+' : unread}
          </span>
        )}
      </button>

      <Floating
        anchorRef={buttonRef}
        open={open}
        onClose={() => setOpen(false)}
        align="right"
        role="dialog"
        className="w-[24rem] max-w-[calc(100vw-1rem)] bg-white border border-slate-200 rounded-2xl shadow-xl overflow-hidden flex flex-col animate-in fade-in"
      >
        <div className="flex items-center justify-between gap-3 px-4 py-3 border-b border-slate-100" data-bell-panel>
          <div>
            <h2 className="text-sm font-extrabold text-[#022340]">Notificaciones</h2>
            <p className="text-xs text-slate-500">{unread ? `${unread} sin leer` : 'Estás al día'}</p>
          </div>
          {unread > 0 && (
            <button type="button" data-action="read-all" onClick={markAllRead} className="inline-flex items-center gap-1.5 text-xs font-bold text-[#3F768C] hover:text-[#022340] transition-colors">
              <CheckCheck size={14} /> Marcar todo como leído
            </button>
          )}
        </div>

        <div className="min-h-0 flex-1 overflow-y-auto select-scroll divide-y divide-slate-100">
          {items.length === 0 ? (
            <div className="px-6 py-10 text-center">
              <BellOff className="w-8 h-8 text-slate-300 mx-auto mb-2" />
              <p className="text-sm text-slate-500">{loaded ? 'Aún no tienes notificaciones.' : 'Cargando…'}</p>
            </div>
          ) : (
            items.map((item) => <NotificationItem key={item.id} item={item} onOpen={openItem} />)
          )}
        </div>

        {total > 0 && (
          <Link href="/notifications" data-action="see-all" onClick={() => setOpen(false)} className="block px-4 py-2.5 text-center text-xs font-bold text-[#3F768C] hover:text-[#022340] hover:bg-slate-50 border-t border-slate-100 transition-colors">
            Ver todas ({total})
          </Link>
        )}
      </Floating>

      {panels}
    </>
  );
}
