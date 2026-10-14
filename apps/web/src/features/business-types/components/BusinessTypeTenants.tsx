'use client';

import { useEffect, useState } from 'react';
import { ArrowRight, Building2, Check, RefreshCw, ShieldCheck } from 'lucide-react';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { api } from '@/lib/axios';
import { MODULE_LABELS } from '@/lib/modules';

interface TypeTenant {
  id: string;
  name: string;
  status: string;
  synced_at: string | null;
  outdated: string[];
  modules_gained: string[];
  modules_lost: string[];
}

interface BusinessTypeTenantsProps {
  businessTypeId: string;
  businessTypeName: string;
  refreshKey: number;
  onApplied: () => void;
}

const PART_LABELS: Record<string, string> = {
  vocabulary: 'Vocabulario',
  modules_off: 'Módulos',
  default_rules: 'Reglas',
  dependents: 'Dependientes',
};

export default function BusinessTypeTenants({ businessTypeId, businessTypeName, refreshKey, onApplied }: BusinessTypeTenantsProps) {
  const [tenants, setTenants] = useState<TypeTenant[]>([]);
  const [selected, setSelected] = useState<string[]>([]);
  const [confirming, setConfirming] = useState(false);
  const [applying, setApplying] = useState(false);
  const [notice, setNotice] = useState<{ type: 'ok' | 'error'; text: string } | null>(null);

  const load = () => {
    api.get(`/admin/business-types/${businessTypeId}/tenants`)
      .then((res) => {
        const rows: TypeTenant[] = res.data ?? [];
        setTenants(rows);
        setSelected(rows.filter((row) => row.outdated.length > 0).map((row) => row.id));
      })
      .catch(() => setNotice({ type: 'error', text: 'No se pudo cargar las empresas de este rubro.' }));
  };

  useEffect(() => {
    load();
  }, [businessTypeId, refreshKey]);

  const outdated = tenants.filter((tenant) => tenant.outdated.length > 0);

  const apply = async () => {
    try {
      setApplying(true);
      const res = await api.post(`/admin/business-types/${businessTypeId}/apply`, { tenant_ids: selected });
      setNotice({ type: 'ok', text: res.data.message });
      setConfirming(false);
      load();
      onApplied();
    } catch (err: any) {
      const errors = err.response?.data?.errors;
      const first = errors ? (Object.values(errors)[0] as string[])[0] : null;
      setNotice({ type: 'error', text: first || err.response?.data?.message || 'No se pudo aplicar el rubro.' });
      setConfirming(false);
    } finally {
      setApplying(false);
    }
  };

  if (tenants.length === 0) return null;

  return (
    <section className="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
      <div className={`px-6 py-4 flex flex-wrap items-center justify-between gap-4 ${outdated.length > 0 ? 'bg-amber-50 border-b border-amber-200' : 'bg-emerald-50/60 border-b border-emerald-100'}`}>
        <div className="flex items-start gap-3 min-w-0">
          <span className={`p-2 rounded-xl shrink-0 ${outdated.length > 0 ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'}`}>
            {outdated.length > 0 ? <RefreshCw size={18} /> : <ShieldCheck size={18} />}
          </span>
          <div className="min-w-0">
            <h2 className="text-sm font-extrabold text-[#022340]">
              {outdated.length > 0
                ? `${outdated.length} de ${tenants.length} ${tenants.length === 1 ? 'empresa tiene' : 'empresas tienen'} cambios del rubro sin aplicar`
                : `${tenants.length === 1 ? 'La empresa de este rubro está' : `Las ${tenants.length} empresas de este rubro están`} al día`}
            </h2>
            <p className="text-xs text-slate-600">
              Editar el rubro no cambia a las empresas que ya lo usan: cada una conserva lo que recibió. Los cambios llegan solo cuando los aplicas aquí.
              El catálogo inicial nunca se vuelve a copiar.
            </p>
          </div>
        </div>
        {outdated.length > 0 && (
          <button
            type="button"
            disabled={selected.length === 0}
            onClick={() => setConfirming(true)}
            className="px-4 py-2.5 text-sm font-bold bg-[#022340] text-white rounded-xl hover:bg-[#03315a] shadow-sm transition-colors disabled:opacity-50 flex items-center gap-2 cursor-pointer shrink-0"
          >
            Aplicar a {selected.length} {selected.length === 1 ? 'empresa' : 'empresas'} <ArrowRight size={15} />
          </button>
        )}
      </div>

      {notice && (
        <div className={`mx-6 mt-4 p-3 rounded-xl border text-xs font-medium ${notice.type === 'ok' ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-red-50 border-red-200 text-red-600'}`}>
          {notice.text}
        </div>
      )}

      <ul className="divide-y divide-slate-100">
        {tenants.map((tenant) => {
          const isOutdated = tenant.outdated.length > 0;
          const isSelected = selected.includes(tenant.id);

          return (
            <li key={tenant.id} className="px-6 py-3.5 flex flex-wrap items-center gap-x-4 gap-y-2">
              {isOutdated ? (
                <input
                  type="checkbox"
                  checked={isSelected}
                  onChange={() => setSelected((prev) => (isSelected ? prev.filter((id) => id !== tenant.id) : [...prev, tenant.id]))}
                  aria-label={`Aplicar el rubro a ${tenant.name}`}
                  className="rounded border-slate-300 text-[#022340] focus:ring-[#022340]"
                />
              ) : (
                <Check size={16} className="text-emerald-500" />
              )}

              <div className="flex items-center gap-2.5 min-w-[14rem] flex-1">
                <span className="p-1.5 rounded-lg bg-slate-100 text-slate-500"><Building2 size={14} /></span>
                <div className="min-w-0">
                  <span className="block text-sm font-semibold text-slate-800 truncate">{tenant.name}</span>
                  <span className="block text-[11px] text-slate-400">
                    {tenant.synced_at ? `Recibió el rubro el ${new Date(tenant.synced_at).toLocaleDateString()}` : 'Sin copia propia: lee el rubro directamente'}
                    {tenant.status !== 'active' ? ' · suspendida' : ''}
                  </span>
                </div>
              </div>

              {isOutdated ? (
                <div className="flex flex-wrap items-center gap-1.5">
                  {tenant.outdated.map((part) => (
                    <span key={part} className="px-2 py-0.5 rounded-md bg-amber-50 border border-amber-200 text-[11px] font-semibold text-amber-700">
                      {PART_LABELS[part] ?? part}
                    </span>
                  ))}
                  {tenant.modules_gained.map((key) => (
                    <span key={`g-${key}`} className="px-2 py-0.5 rounded-md bg-emerald-50 border border-emerald-200 text-[11px] font-medium text-emerald-700" title="Lo recibiría si su plan lo incluye">
                      + {MODULE_LABELS[key] ?? key}
                    </span>
                  ))}
                  {tenant.modules_lost.map((key) => (
                    <span key={`l-${key}`} className="px-2 py-0.5 rounded-md bg-rose-50 border border-rose-200 text-[11px] font-medium text-rose-600" title="Dejaría de tenerlo">
                      − {MODULE_LABELS[key] ?? key}
                    </span>
                  ))}
                </div>
              ) : (
                <span className="text-xs font-medium text-emerald-600">Al día</span>
              )}
            </li>
          );
        })}
      </ul>

      <AppModal
        isOpen={confirming}
        type="confirm"
        title={`¿Aplicar "${businessTypeName}" a ${selected.length} ${selected.length === 1 ? 'empresa' : 'empresas'}?`}
        message={
          <span className="block space-y-2 text-sm text-slate-600">
            <span className="block">
              Recibirán el vocabulario, los módulos, las reglas y los dependientes del rubro tal como están ahora.
            </span>
            {tenants.some((tenant) => selected.includes(tenant.id) && tenant.modules_lost.length > 0) && (
              <span className="block p-2.5 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-xs font-medium">
                Algunas dejarán de tener módulos que hoy usan. Revisa la lista antes de continuar.
              </span>
            )}
            <span className="block text-xs text-slate-500">
              Lo que cada empresa cambió por su cuenta (su vocabulario propio, los módulos que apagó) se conserva. Queda registrado en la bitácora.
            </span>
          </span>
        }
        confirmText="Sí, aplicar"
        cancelText="Cancelar"
        isLoading={applying}
        maxWidth="max-w-lg"
        onConfirm={apply}
        onClose={() => setConfirming(false)}
      />
    </section>
  );
}
