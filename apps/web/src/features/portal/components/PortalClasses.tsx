'use client';

import { useMemo, useState } from 'react';
import { CalendarCheck, Hourglass, MapPin, User, Users } from 'lucide-react';
import { api } from '@/lib/axios';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { PagedList } from '@/app/components/common/Pager';
import Select from '@/app/components/common/Select';
import { portalError } from '../portal';

export interface PortalClass {
  id: string;
  title: string;
  status: string;
  state: string;
  state_label: string;
  date: string;
  time: string;
  end_time: string;
  starts_at: string;
  capacity: number;
  taken: number;
  free: number;
  waitlist: number;
  professional: { id: string; display_name: string } | null;
  branch: { id: string; name: string } | null;
  room: { id: string; name: string } | null;
  service: { id: string; name: string; price?: number };
  my_status: string | null;
  my_status_label: string | null;
  can_leave: boolean;
}

export interface PortalClassesData {
  classes: PortalClass[];
  mine: PortalClass[];
  leave_hours: number;
  waitlist: boolean;
  blocked: string | null;
}

interface PortalClassesProps {
  data: PortalClassesData;
  proWord: string;
  onChanged: (message: string) => void;
  onError: (message: string) => void;
}

const dayLabel = (date: string) => {
  const [y, m, d] = date.split('-').map(Number);
  return new Date(y, m - 1, d).toLocaleDateString('es-PE', { weekday: 'long', day: 'numeric', month: 'long' });
};

export default function PortalClasses({ data, proWord, onChanged, onError }: PortalClassesProps) {
  const [busy, setBusy] = useState('');
  const [leaving, setLeaving] = useState<PortalClass | null>(null);
  const [kind, setKind] = useState('');

  const kinds = useMemo(() => [...new Set(data.classes.map((c) => c.title))].sort(), [data.classes]);
  const upcoming = data.classes.filter((c) => !kind || c.title === kind);
  const mine = data.mine.filter((c) => c.status === 'scheduled' && c.state !== 'done' && c.my_status && ['enrolled', 'waitlist'].includes(c.my_status));

  const enroll = async (c: PortalClass) => {
    try {
      setBusy(c.id);
      const res = await api.post(`/portal/classes/${c.id}/enroll`);
      onChanged(res.data?.message ?? 'Listo.');
    } catch (err) {
      onError(portalError(err, 'No se pudo inscribirte en la clase.'));
    } finally {
      setBusy('');
    }
  };

  const leave = async () => {
    if (!leaving) return;
    try {
      setBusy(leaving.id);
      const res = await api.post(`/portal/classes/${leaving.id}/leave`);
      onChanged(res.data?.message ?? 'Listo.');
    } catch (err) {
      onError(portalError(err, 'No se pudo salir de la clase.'));
    } finally {
      setBusy('');
      setLeaving(null);
    }
  };

  const row = (c: PortalClass, own: boolean) => (
    <li key={c.id} className="flex flex-col sm:flex-row sm:items-center gap-3 px-5 py-3.5" data-portal-class={c.id}>
      <div className="flex-1 min-w-0 space-y-0.5">
        <span className="block text-sm font-bold text-brand-ink">{c.title}</span>
        <span className="block text-xs text-slate-600 first-letter:uppercase">{dayLabel(c.date)} · {c.time}–{c.end_time}</span>
        <span className="flex flex-wrap gap-x-3 text-xs text-slate-500">
          {c.professional && <span className="inline-flex items-center gap-1"><User size={12} /> {c.professional.display_name}</span>}
          {(c.room || c.branch) && <span className="inline-flex items-center gap-1"><MapPin size={12} /> {[c.room?.name, c.branch?.name].filter(Boolean).join(' · ')}</span>}
          <span className="inline-flex items-center gap-1"><Users size={12} /> {c.free > 0 ? `${c.free} ${c.free === 1 ? 'cupo libre' : 'cupos libres'}` : 'Completa'}</span>
        </span>
      </div>
      <div className="flex items-center gap-2 shrink-0">
        {c.my_status && ['enrolled', 'waitlist'].includes(c.my_status) ? (
          <>
            <span className={`text-xs font-bold px-2.5 py-1 rounded-full ${c.my_status === 'waitlist' ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700'}`}>{c.my_status === 'waitlist' ? 'En espera' : 'Inscrito'}</span>
            {c.can_leave
              ? <button type="button" data-portal-class-leave disabled={busy !== ''} onClick={() => setLeaving(c)} className="px-3 py-2 text-sm font-semibold text-slate-600 hover:text-red-600 transition-colors">Salir</button>
              : own && <span className="text-xs text-slate-400">Para salir, escríbenos</span>}
          </>
        ) : (
          <button
            type="button"
            data-portal-class-enroll
            disabled={busy !== '' || Boolean(data.blocked) || (c.free === 0 && !data.waitlist)}
            onClick={() => enroll(c)}
            className="px-4 py-2 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover transition-colors disabled:opacity-50"
          >
            {busy === c.id ? 'Guardando...' : c.free > 0 ? 'Inscribirme' : data.waitlist ? 'Anotarme en espera' : 'Completa'}
          </button>
        )}
      </div>
    </li>
  );

  return (
    <div className="space-y-5" data-portal-classes>
      {data.blocked && <p className="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3">{data.blocked}</p>}

      {mine.length > 0 && (
        <section className="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
          <div className="px-5 pt-5 pb-2">
            <h2 className="flex items-center gap-2 text-base font-black text-brand-ink"><CalendarCheck size={18} className="text-brand-accent" /> Mis clases</h2>
            <p className="text-xs text-slate-500 mt-1">Puedes salir de una clase hasta {data.leave_hours} {data.leave_hours === 1 ? 'hora' : 'horas'} antes. Si estás en espera y se libera un cupo, entras y te avisamos.</p>
          </div>
          <PagedList items={mine} empty="" renderRow={(c) => row(c, true)} />
        </section>
      )}

      <section className="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
        <div className="px-5 pt-5 pb-3 flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
          <div>
            <h2 className="flex items-center gap-2 text-base font-black text-brand-ink"><Hourglass size={18} className="text-brand-accent" /> Próximas clases</h2>
            <p className="text-xs text-slate-500 mt-1">Las de las próximas tres semanas. Cada clase tiene su {proWord.toLowerCase()} y un número de cupos.</p>
          </div>
          {kinds.length > 1 && (
            <div className="sm:w-56">
              <Select ariaLabel="Filtrar por clase" placeholder="Todas las clases" value={kind} onChange={(e) => setKind(String(e.target.value))} options={kinds.map((k) => ({ value: k, label: k }))} />
            </div>
          )}
        </div>
        <PagedList items={upcoming} empty="No hay clases con inscripción en línea en las próximas semanas." renderRow={(c) => row(c, false)} />
      </section>

      <AppModal
        isOpen={leaving !== null}
        type="confirm"
        variant="danger"
        title={`¿Salir de ${leaving?.title ?? ''}?`}
        message={leaving?.my_status === 'waitlist' ? 'Dejas de esperar cupo en esta clase.' : 'Tu cupo queda libre para otra persona.'}
        confirmText="Sí, salir"
        cancelText="Volver"
        isLoading={busy !== ''}
        onConfirm={leave}
        onClose={() => setLeaving(null)}
      />
    </div>
  );
}
