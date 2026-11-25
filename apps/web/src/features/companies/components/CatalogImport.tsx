'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { Download, Loader2, Shapes } from 'lucide-react';
import { api } from '@/lib/axios';
import Select from '@/app/components/common/Select';
import SectionTitle from '@/app/components/common/SectionTitle';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { useAuth } from '@/hooks/useAuth';
import { apiError } from '@/features/appointments/appointments';

interface Preview { new: number; skipped: number; categories: number; name: string }

export default function CatalogImport() {
  const { can } = useAuth();
  const [types, setTypes] = useState<{ value: string; label: string }[]>([]);
  const [typeId, setTypeId] = useState('');
  const [preview, setPreview] = useState<Preview | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  useEffect(() => {
    api.get('/business-types').then((res) => setTypes((res.data ?? []).map((t: { id: string; name: string }) => ({ value: t.id, label: t.name })))).catch(() => setTypes([]));
  }, []);

  useEffect(() => {
    setPreview(null);
    if (!typeId) return;
    api.get<Preview>('/company/catalog-import', { params: { business_type_id: typeId } }).then((res) => setPreview(res.data)).catch((err) => setError(apiError(err, 'No se pudo revisar ese rubro.')));
  }, [typeId]);

  if (!can('services.create')) return null;

  const run = async () => {
    try {
      setBusy(true);
      setError('');
      setNotice('');
      const res = await api.post('/company/catalog-import', { business_type_id: typeId });
      setNotice(res.data.message);
      const fresh = await api.get<Preview>('/company/catalog-import', { params: { business_type_id: typeId } });
      setPreview(fresh.data);
    } catch (err) {
      setError(apiError(err, 'No se pudo importar el catálogo.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <section className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 space-y-4 xl:col-span-3" data-card="catalog-import">
      <SectionTitle><Shapes size={16} className="text-brand-accent" /> Importar el catálogo de un rubro</SectionTitle>
      <p className="text-sm text-slate-600">Suma a tus servicios los de otro rubro, por ejemplo si tu salón empieza a ofrecer uñas o pestañas. Los que ya tienes (mismo nombre o código) no se repiten, y nada de lo tuyo se cambia.</p>
      <div className="flex flex-col sm:flex-row gap-2 sm:items-center">
        <div className="sm:w-80"><Select ariaLabel="Rubro" value={typeId} onChange={(e) => setTypeId(String(e.target.value ?? ''))} options={types} placeholder="Elige el rubro" /></div>
        <button type="button" data-catalog-import disabled={busy || !preview || preview.new === 0} onClick={run} className="inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover disabled:opacity-50">
          {busy ? <Loader2 size={15} className="animate-spin" /> : <Download size={15} />} {preview && preview.new > 0 ? `Importar ${preview.new} ${preview.new === 1 ? 'servicio' : 'servicios'}` : 'Importar'}
        </button>
      </div>
      {preview && (
        <p className="text-xs text-slate-500" data-catalog-preview>
          {preview.new > 0 ? `Se agregan ${preview.new} ${preview.new === 1 ? 'servicio' : 'servicios'} en hasta ${preview.categories} ${preview.categories === 1 ? 'categoría' : 'categorías'}.` : 'Ya tienes todos los servicios de este rubro.'}
          {preview.new > 0 && preview.skipped > 0 ? ` ${preview.skipped} ya los tienes y se saltan.` : ''}
          {preview.new > 0 && <> Después ajusta precios, duraciones y sedes en <Link href="/services" className="font-semibold text-brand-accent hover:text-brand-ink">Servicios</Link>.</>}
        </p>
      )}
      <SuccessAlert message={notice} />
      <ErrorAlert message={error} />
    </section>
  );
}
