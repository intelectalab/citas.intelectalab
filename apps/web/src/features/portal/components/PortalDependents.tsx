'use client';

import { useEffect, useState } from 'react';
import { CalendarPlus, Loader2, PawPrint } from 'lucide-react';
import { api } from '@/lib/axios';
import { formatDate } from '@/features/appointments/appointments';

interface DependentControl {
  id: string;
  label: string;
  due_date: string;
  status: string;
  status_label: string;
  overdue: boolean;
}

interface DependentRow {
  id: string;
  name: string;
  species: string | null;
  breed: string | null;
  birth_date: string | null;
  last_visit: { date: string; service: string | null } | null;
  controls: DependentControl[];
}

const ageOf = (birth: string | null) => {
  if (!birth) return null;
  const born = new Date(`${birth}T12:00:00`);
  const now = new Date();
  let months = (now.getFullYear() - born.getFullYear()) * 12 + now.getMonth() - born.getMonth();
  if (now.getDate() < born.getDate()) months -= 1;
  if (months < 0) return null;
  if (months < 12) return months === 1 ? '1 mes' : `${months} meses`;
  const years = Math.floor(months / 12);
  return years === 1 ? '1 año' : `${years} años`;
};

export default function PortalDependents({ canBook, onBook }: { canBook: boolean; onBook: () => void }) {
  const [items, setItems] = useState<DependentRow[] | null>(null);

  useEffect(() => {
    api.get('/portal/dependents')
      .then((res) => setItems(res.data.items ?? []))
      .catch(() => setItems([]));
  }, []);

  if (!items) return <p className="flex items-center gap-2 text-sm text-slate-500"><Loader2 size={14} className="animate-spin" /> Cargando...</p>;
  if (items.length === 0) return <p className="text-sm text-slate-500">Aún no registras ninguno. Podrás hacerlo al reservar.</p>;

  return (
    <div className="grid grid-cols-1 sm:grid-cols-2 gap-2.5" data-portal-dependents>
      {items.map((item) => (
        <article key={item.id} data-dependent-card={item.name} className="rounded-2xl border border-slate-200 p-3.5 space-y-2">
          <div className="flex items-center gap-2.5">
            <span className="w-9 h-9 rounded-xl bg-amber-50 text-amber-700 flex items-center justify-center shrink-0"><PawPrint size={16} /></span>
            <div className="min-w-0">
              <p className="text-sm font-bold text-slate-800 truncate">{item.name}</p>
              <p className="text-xs text-slate-500 truncate">{[item.species, item.breed, ageOf(item.birth_date)].filter(Boolean).join(' · ') || 'Sin más datos'}</p>
            </div>
          </div>
          <p className="text-xs text-slate-500">
            Última atención: {item.last_visit ? `${formatDate(item.last_visit.date.slice(0, 10), { day: 'numeric', month: 'short', year: 'numeric' })}${item.last_visit.service ? ` · ${item.last_visit.service}` : ''}` : 'aún ninguna'}
          </p>
          {item.controls.length > 0 && (
            <ul className="space-y-1" data-dependent-controls>
              {item.controls.map((control) => (
                <li key={control.id} className={`text-xs rounded-lg px-2.5 py-1.5 ${control.overdue ? 'bg-amber-50 text-amber-800' : 'bg-sky-50 text-sky-800'}`}>
                  <span className="font-bold">{control.label}</span>: {formatDate(control.due_date, { day: 'numeric', month: 'long' })}
                  {control.status === 'booked' ? ' · ya está agendado' : control.overdue ? ' · ya toca' : ''}
                </li>
              ))}
            </ul>
          )}
          {canBook && item.controls.some((c) => c.status !== 'booked') && (
            <button type="button" data-action="book-control" onClick={onBook} className="inline-flex items-center gap-1.5 text-xs font-bold text-brand-accent hover:text-brand-ink">
              <CalendarPlus size={14} /> Reservar control
            </button>
          )}
        </article>
      ))}
    </div>
  );
}
