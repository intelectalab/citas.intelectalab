'use client';

import Link from 'next/link';
import { Users } from 'lucide-react';

interface AgendaClassBlockProps {
  classId: string;
  reason: string | null;
  top: number;
  height: number;
  compact?: boolean;
}

export default function AgendaClassBlock({ classId, reason, top, height, compact = false }: AgendaClassBlockProps) {
  return (
    <Link
      href={`/classes/${classId}`}
      data-class-block={classId}
      onClick={(event) => event.stopPropagation()}
      title="Clase grupal: abre la clase para ver a los inscritos"
      className={`absolute inset-x-0 z-10 border-y border-l-4 border-sky-300 border-l-sky-500 bg-sky-50 hover:bg-sky-100 overflow-hidden transition-colors ${compact ? 'px-1.5 py-0.5' : 'px-2 py-1'}`}
      style={{ top, height }}
    >
      <span className={`flex items-center gap-1 font-semibold text-sky-800 ${compact ? 'text-[10px]' : 'text-[11px]'}`}>
        <Users size={compact ? 10 : 12} className="shrink-0" />
        <span className="truncate">Clase{reason ? ` · ${reason}` : ''}</span>
      </span>
    </Link>
  );
}
