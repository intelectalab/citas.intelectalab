'use client';

import SectionTitle from '@/app/components/common/SectionTitle';
import { PagedList } from '@/app/components/common/Pager';
import { useEffect, useState } from 'react';
import { Download, FileSpreadsheet, FileText } from 'lucide-react';
import { api } from '@/lib/axios';
import { momentOf } from '@/features/sales/sales';

interface DataExport {
  id: string;
  at: string;
  by: string;
  title: string;
  format: string;
  scope: 'filtered' | 'selected';
  rows: number;
  filters: string[];
}

export default function DataExportsLog({ className }: { className: string }) {
  const [rows, setRows] = useState<DataExport[] | null>(null);

  useEffect(() => {
    api.get('/data-exports').then((res) => setRows(res.data.exports)).catch(() => setRows([]));
  }, []);

  return (
    <section className={className} data-card="data-exports">
      <div>
        <SectionTitle><Download className="w-4 h-4 text-[#3F768C]" /> Descargas de datos</SectionTitle>
        <p className="text-xs text-slate-500 mt-1">Cada vez que alguien de tu equipo exporta una lista a Excel o CSV queda anotado aquí: quién, qué lista, cuántas filas y con qué filtros. Solo pueden exportar quienes tienen el permiso &quot;Exportar datos&quot;.</p>
      </div>
      <div className="rounded-2xl border border-slate-200 overflow-hidden">
        <PagedList
          items={rows ?? []}
          empty={rows === null ? 'Cargando…' : 'Nadie exportó datos todavía.'}
          renderRow={(row) => (
            <li key={row.id} className="flex items-start gap-3 px-5 py-3" data-export-row>
              <span className="p-2 rounded-lg bg-[#84D0D9]/20 text-[#022340] shrink-0">{row.format === 'csv' ? <FileText size={15} /> : <FileSpreadsheet size={15} />}</span>
              <span className="flex-1 min-w-0">
                <span className="block text-sm font-semibold text-slate-800">{row.title} · {row.rows} {row.rows === 1 ? 'fila' : 'filas'}{row.scope === 'selected' ? ' seleccionadas' : ''}</span>
                <span className="block text-xs text-slate-500">{row.by} · {momentOf(row.at)} · {row.format === 'csv' ? 'CSV' : 'Excel'}</span>
                {row.filters.length > 0 && <span className="block text-xs text-slate-400 line-clamp-2" title={row.filters.join(' · ')}>{row.filters.join(' · ')}</span>}
              </span>
            </li>
          )}
        />
      </div>
    </section>
  );
}
