'use client';

import { useEffect, useState } from 'react';
import { Wrench, X } from 'lucide-react';
import { usePlatform } from '@/hooks/usePlatform';

const when = (iso: string) =>
  new Date(iso).toLocaleString('es-PE', { weekday: 'long', day: 'numeric', month: 'long', hour: 'numeric', minute: '2-digit' });

export default function MaintenanceBanner() {
  const { platform } = usePlatform();
  const notice = platform.look.maintenance;
  const key = notice ? `maintenance:${notice.message}:${notice.starts_at}:${notice.ends_at}` : '';
  const [hidden, setHidden] = useState(false);
  const [now, setNow] = useState(() => Date.now());

  useEffect(() => {
    try {
      setHidden(Boolean(key) && sessionStorage.getItem('maintenance-hidden') === key);
    } catch {
      setHidden(false);
    }
  }, [key]);

  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 60_000);
    return () => window.clearInterval(timer);
  }, []);

  if (!notice?.message || hidden) return null;
  if (notice.ends_at && new Date(notice.ends_at).getTime() <= now) return null;

  const range = notice.starts_at && notice.ends_at
    ? `Desde el ${when(notice.starts_at)} hasta el ${when(notice.ends_at)}`
    : notice.ends_at ? `Hasta el ${when(notice.ends_at)}` : '';

  return (
    <div className="relative z-50 flex items-start gap-2 px-4 py-2 bg-amber-50 border-b border-amber-200 text-amber-900 text-xs sm:text-sm" role="status" data-maintenance-banner>
      <Wrench size={15} className="mt-0.5 shrink-0" />
      <p className="flex-1">
        {notice.message}
        {range && <span className="block sm:inline sm:ml-1 font-semibold">{range.replace(/\.$/, '')}.</span>}
      </p>
      <button
        type="button"
        aria-label="Ocultar el aviso"
        onClick={() => {
          setHidden(true);
          try { sessionStorage.setItem('maintenance-hidden', key); } catch {}
        }}
        className="p-0.5 rounded hover:bg-amber-100"
      >
        <X size={14} />
      </button>
    </div>
  );
}
