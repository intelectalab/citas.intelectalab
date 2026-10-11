'use client';

import { useState, type ReactNode } from 'react';
import { ChevronLeft, ChevronRight } from 'lucide-react';

// Cuántas filas se muestran a la vez en tablas y listas.
export const PAGE_SIZE = 6;

/** Reparte una lista en páginas. Si la lista se acorta, la página se ajusta sola. */
export function usePaged<T>(items: T[], size: number = PAGE_SIZE) {
  const [page, setPage] = useState(1);
  const pages = Math.max(1, Math.ceil(items.length / size));
  const current = Math.min(page, pages);

  return { rows: items.slice((current - 1) * size, current * size), page: current, pages, setPage };
}

interface PagerProps {
  page: number;
  pages: number;
  onChange: (page: number) => void;
  className?: string;
}

const button = 'p-1.5 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 hover:text-[#022340] disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-transparent transition-colors';

/** "Página 1 de 3" con sus flechas. */
export function Pager({ page, pages, onChange, className = '' }: PagerProps) {
  return (
    <div className={`flex items-center justify-end gap-3 ${className}`} data-pager>
      <span className="text-xs font-medium text-slate-500" data-page-info>Página {page} de {pages}</span>
      <div className="flex items-center gap-1.5">
        <button type="button" data-page="prev" aria-label="Página anterior" disabled={page <= 1} onClick={() => onChange(page - 1)} className={button}>
          <ChevronLeft size={15} />
        </button>
        <button type="button" data-page="next" aria-label="Página siguiente" disabled={page >= pages} onClick={() => onChange(page + 1)} className={button}>
          <ChevronRight size={15} />
        </button>
      </div>
    </div>
  );
}

interface PagedListProps<T> {
  items: T[];
  renderRow: (item: T) => ReactNode;
  empty: string;
  size?: number;
}

/**
 * Lista de a seis con su paginación al pie. Dos listas lado a lado miden lo
 * mismo: la paginación queda siempre abajo.
 */
export function PagedList<T>({ items, renderRow, empty, size = PAGE_SIZE }: PagedListProps<T>) {
  const { rows, page, pages, setPage } = usePaged(items, size);

  if (items.length === 0) return <p className="px-5 py-3 text-sm text-slate-400">{empty}</p>;

  return (
    <div className="flex-1 flex flex-col">
      <ul className="flex-1 divide-y divide-slate-100">{rows.map(renderRow)}</ul>
      {pages > 1 && <Pager page={page} pages={pages} onChange={setPage} className="px-5 py-2.5 border-t border-slate-100" />}
    </div>
  );
}
