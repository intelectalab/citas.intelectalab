'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { ShieldAlert } from 'lucide-react';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { dateTime } from '../support';

interface Session {
  id: string;
  who: string;
  reason: string;
  started_at: string;
  expires_at: string | null;
}

export default function SupportAccessBanner() {
  const { user } = useAuth();
  const [sessions, setSessions] = useState<Session[]>([]);
  const isStaff = Boolean(user?.tenant) && !user?.is_saas_admin && !user?.is_saas_support && !user?.impersonator;

  useEffect(() => {
    if (!isStaff) return;
    let active = true;
    const load = () => api.get<{ sessions: Session[] }>('/support/access').then((res) => active && setSessions(res.data.sessions ?? [])).catch(() => {});
    load();
    const timer = setInterval(load, 120000);
    return () => { active = false; clearInterval(timer); };
  }, [isStaff]);

  if (!isStaff || !sessions.length) return null;
  const s = sessions[0];

  return (
    <div className="bg-amber-100 text-brand-ink border-b border-amber-200 px-3 sm:px-6 py-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs font-semibold" data-support-access-banner>
      <ShieldAlert size={15} className="shrink-0 text-amber-700" />
      <span className="flex-1 min-w-0">
        Soporte de la plataforma está dentro de tu cuenta ({s.who}) desde el {dateTime(s.started_at)}{s.expires_at ? ` hasta el ${dateTime(s.expires_at)} como máximo` : ''}. Motivo: {s.reason}
      </span>
      <Link href="/audit" className="shrink-0 underline hover:text-brand-accent">Ver en Auditoría</Link>
    </div>
  );
}
