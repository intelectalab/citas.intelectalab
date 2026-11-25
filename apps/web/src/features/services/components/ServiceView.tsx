'use client';

import { Briefcase } from 'lucide-react';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { formatMoney } from '@/lib/currency';
import { useAuth } from '@/hooks/useAuth';
import { useVocabulary } from '@/hooks/useVocabulary';
import { categoryColor, taxNote } from '../services';

export interface ServiceViewData {
  name: string;
  code?: string | null;
  description?: string | null;
  duration_minutes: number;
  buffer_before_minutes: number;
  buffer_after_minutes: number;
  price: number;
  kind?: 'individual' | 'group';
  capacity?: number;
  online_booking?: boolean;
  requires_deposit?: boolean;
  is_virtual?: boolean;
  requires_record?: boolean;
  category?: { name: string; color?: string | null } | null;
  status?: { name: string } | null;
  room_names?: string[];
  professional_names?: string[];
  branch_names?: string[];
}

export default function ServiceView({ service, onClose }: { service: ServiceViewData | null; onClose: () => void }) {
  const { user } = useAuth();
  const vocab = useVocabulary();

  if (!service) return null;

  const rows: [string, React.ReactNode][] = [
    ['Código', service.code || '—'],
    ['Categoría', service.category ? (
      <span className="inline-flex items-center gap-1.5"><span className="w-2.5 h-2.5 rounded-full" style={{ backgroundColor: categoryColor(service.category.color) }} />{service.category.name}</span>
    ) : 'Sin categoría'],
    ['Tipo', service.kind === 'group' ? `Clase grupal · cupo ${service.capacity ?? 1}` : 'Individual'],
    ['Duración', `${service.duration_minutes} min${service.buffer_before_minutes || service.buffer_after_minutes ? ` (+${service.buffer_before_minutes} antes / +${service.buffer_after_minutes} después)` : ''}`],
    ['Precio', `${formatMoney(Number(service.price))} (${taxNote(user?.tenant?.rules)})`],
    ['Requiere', service.room_names?.length ? service.room_names.join(', ') : '—'],
    [vocab.pros, service.professional_names?.length ? service.professional_names.join(', ') : '—'],
    ['Sedes', service.branch_names?.length ? service.branch_names.join(', ') : 'Todas las sedes'],
    ['Canal', [service.online_booking ? 'Se reserva en línea' : 'Solo interno', service.requires_deposit ? 'pide adelanto' : null, service.is_virtual ? 'por videollamada' : null].filter(Boolean).join(' · ')],
    ['Ficha', service.requires_record ? 'Exige la ficha firmada para darla por atendida' : 'No la exige'],
    ['Estado', service.status?.name ?? '—'],
    ['Descripción para el cliente', service.description || '—'],
  ];

  return (
    <FormDrawer isOpen onClose={onClose} title={service.name} subtitle="Solo lectura: tu rol no cambia servicios." icon={<Briefcase size={20} />}>
      <dl className="divide-y divide-slate-100 border border-slate-200 rounded-xl" data-service-view>
        {rows.map(([label, value]) => (
          <div key={label} className="grid grid-cols-1 sm:grid-cols-3 gap-1 px-4 py-3">
            <dt className="text-xs font-semibold uppercase tracking-wider text-slate-500">{label}</dt>
            <dd className="sm:col-span-2 text-sm text-slate-800 whitespace-pre-line break-words">{value}</dd>
          </div>
        ))}
      </dl>
    </FormDrawer>
  );
}
