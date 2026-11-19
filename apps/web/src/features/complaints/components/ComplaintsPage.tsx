'use client';

import Link from 'next/link';
import { useCallback, useEffect, useState } from 'react';
import { BookOpenText, Check, Copy } from 'lucide-react';
import { api } from '@/lib/axios';
import Badge from '@/app/components/common/Badge';
import { ErrorAlert } from '@/app/components/common/Alert';
import { DataTable, type Column } from '@/app/components/ui/table/DataTable';
import { apiError } from '@/features/appointments/appointments';
import { STATE, deadline, issuerLine, longDate, type Complaint, type Issuer } from '../complaints';

const TABS = [
  { id: 'pending', label: 'Pendientes' },
  { id: 'due', label: 'Por vencer y vencidos' },
  { id: 'answered', label: 'Respondidos' },
];

export default function ComplaintsPage({ platform = false }: { platform?: boolean }) {
  const base = platform ? '/admin/complaints' : '/complaints';
  const [tab, setTab] = useState('pending');
  const [rows, setRows] = useState<Complaint[]>([]);
  const [counts, setCounts] = useState<Record<string, number>>({});
  const [issuer, setIssuer] = useState<Issuer | null>(null);
  const [code, setCode] = useState<string | null>(null);
  const [search, setSearch] = useState('');
  const [error, setError] = useState('');
  const [copied, setCopied] = useState(false);

  const load = useCallback(() => {
    api.get(base, { params: { tab } })
      .then((res) => {
        setRows(res.data.complaints);
        setCounts(res.data.counts ?? {});
        setIssuer(res.data.issuer ?? null);
        setCode(res.data.code ?? null);
        setError('');
      })
      .catch((err) => setError(apiError(err, 'No se pudieron cargar los reclamos.')));
  }, [base, tab]);

  useEffect(() => { load(); }, [load]);

  const publicUrl = typeof window === 'undefined' ? '' : `${window.location.origin}/reclamaciones${code ? `/${code}` : ''}`;
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(publicUrl);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      setCopied(false);
    }
  };

  const term = search.trim().toLowerCase();
  const shown = term ? rows.filter((r) => `${r.code} ${r.consumer.name} ${r.consumer.document_number} ${r.good_description}`.toLowerCase().includes(term)) : rows;
  const incomplete = issuer && (!issuer.legal_name || !issuer.tax_id || !issuer.address);

  const columns: Column<Complaint>[] = [
    { header: 'Código', cell: (r) => <Link href={`${base}/${r.id}`} data-complaint-link={r.code} className="font-bold text-brand-accent hover:text-brand-ink">{r.code}</Link> },
    { header: 'Quién', cell: (r) => <span className="block"><span className="block font-semibold text-slate-800">{r.consumer.name}</span><span className="block text-xs text-slate-500">{r.kind_label} · {r.good_description}</span></span> },
    { header: 'Llegó', cell: (r) => longDate(r.created_on) },
    { header: 'Plazo', cell: (r) => <span className={r.state === 'late' ? 'text-rose-700 font-semibold' : r.state === 'soon' ? 'text-amber-800 font-semibold' : ''}>{deadline(r)}</span> },
    { header: 'Estado', cell: (r) => <Badge tone={STATE[r.state].tone} bold>{STATE[r.state].label}</Badge> },
  ];

  return (
    <div className="space-y-5 pb-10 font-sans" data-complaints>
      <div>
        <h1 className="flex items-center gap-2.5 text-2xl font-black text-brand-ink tracking-tight"><BookOpenText className="w-6 h-6 text-brand-accent" /> Libro de Reclamaciones</h1>
        <p className="text-sm text-slate-500 font-medium">Los reclamos y quejas que llegan por el libro virtual. Hay que responder cada uno en 15 días hábiles (de lunes a viernes, sin feriados). La respuesta le llega por correo a quien reclamó.</p>
      </div>

      <section className="bg-white rounded-3xl border border-slate-200/80 shadow-xs p-5 space-y-3" data-complaints-link>
        <p className="text-sm text-slate-600">{platform ? 'Enlace público del libro de la plataforma. Está al pie de la portada y en la pantalla de ingreso.' : 'Enlace de tu libro. Ponlo en tu web o redes; también aparece en el portal de tus clientes.'}</p>
        <div className="flex flex-col sm:flex-row gap-2">
          <code className="flex-1 min-w-0 truncate text-sm bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 text-slate-700" data-complaints-url>{publicUrl}</code>
          <button type="button" onClick={copy} data-complaints-copy className="inline-flex items-center justify-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-brand-ink border border-slate-200 rounded-xl hover:bg-slate-50">
            {copied ? <Check size={15} /> : <Copy size={15} />} {copied ? 'Copiado' : 'Copiar enlace'}
          </button>
        </div>
        {issuer && <p className="text-xs text-slate-500">Arriba del formulario sale: {issuerLine(issuer) || 'sin datos legales'}.</p>}
        {incomplete && (
          <p className="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3" data-complaints-incomplete>
            Faltan la razón social, el RUC o la dirección. Complétalos en{' '}
            <Link href={platform ? '/admin/configuration' : '/companies'} className="font-semibold text-brand-accent hover:underline">{platform ? 'Configuración' : 'Empresa'}</Link>: la norma pide que salgan en el libro.
          </p>
        )}
      </section>

      <ErrorAlert message={error} />

      <DataTable<Complaint>
        title="Reclamos y quejas"
        tabs={TABS.map((t) => ({ ...t, count: counts[t.id] ?? 0 }))}
        activeTab={tab}
        onTabChange={setTab}
        searchTerm={search}
        onSearchChange={setSearch}
        columns={columns}
        data={shown}
        getItemKey={(r) => r.id}
        entityLabel={shown.length === 1 ? 'reclamo' : 'reclamos'}
      />
    </div>
  );
}
