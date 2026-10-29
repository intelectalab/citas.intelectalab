'use client';

import { useState } from 'react';
import { Edit2, Plus, Trash2, Truck } from 'lucide-react';
import Badge from '@/app/components/common/Badge';
import Button from '@/app/components/common/Button';
import { PagedList } from '@/app/components/common/Pager';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { inputClass } from '@/app/components/ui/forms/styles';
import { money } from '@/features/sales/sales';
import type { Supplier } from '../inventory';

interface SuppliersPanelProps {
  isOpen: boolean;
  onClose: () => void;
  suppliers: Supplier[];
  currency: string;
  canCreate: boolean;
  canEdit: boolean;
  canDelete: boolean;
  onNew: () => void;
  onEdit: (supplier: Supplier) => void;
  onDelete: (supplier: Supplier) => void;
}

const iconButton = 'p-1.5 text-slate-400 rounded-lg transition-colors';

export default function SuppliersPanel({
  isOpen,
  onClose,
  suppliers,
  currency,
  canCreate,
  canEdit,
  canDelete,
  onNew,
  onEdit,
  onDelete,
}: SuppliersPanelProps) {
  const [search, setSearch] = useState('');
  const term = search.trim().toLowerCase();
  const rows = suppliers.filter((s) =>
    !term || `${s.name} ${s.document_number ?? ''} ${s.contact_name ?? ''} ${s.phone ?? ''} ${s.email ?? ''}`.toLowerCase().includes(term),
  );

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title="Proveedores"
      subtitle="A quién le compras los productos e insumos."
      icon={<Truck size={20} />}
    >
      <div className="space-y-4">
        <div className="flex items-center gap-2">
          <input
            aria-label="Buscar proveedor"
            placeholder="Buscar por nombre, RUC o contacto"
            className={inputClass}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
          {canCreate && (
            <Button type="button" variant="primary" onClick={onNew}>
              <Plus size={15} /> Nuevo
            </Button>
          )}
        </div>

        <div className="rounded-xl border border-slate-200 bg-white">
          <PagedList
            items={rows}
            empty={suppliers.length === 0 ? 'Aún no hay proveedores. Registra el primero con "Nuevo".' : 'Ningún proveedor coincide con la búsqueda.'}
            renderRow={(s) => (
              <li key={s.id} data-supplier={s.name} className="flex items-center justify-between gap-3 px-4 py-3">
                <div className="min-w-0">
                  <div className="flex items-center gap-2">
                    {canEdit ? (
                      <button type="button" onClick={() => onEdit(s)} className="text-sm font-bold text-brand-ink hover:text-brand-accent text-left truncate">
                        {s.name}
                      </button>
                    ) : (
                      <span className="text-sm font-bold text-brand-ink truncate">{s.name}</span>
                    )}
                    {s.status !== 'active' && <Badge tone="bg-slate-100 text-slate-500">Inactivo</Badge>}
                  </div>
                  <span className="block text-xs text-slate-500 truncate">
                    {[s.document_number, s.contact_name, s.phone, s.email].filter(Boolean).join(' · ') || 'Sin datos de contacto'}
                  </span>
                  <span className="block text-xs text-slate-400 tabular-nums">
                    {s.orders_count} {s.orders_count === 1 ? 'orden' : 'órdenes'} · {money(s.total_bought, currency)} comprado
                  </span>
                </div>
                <div className="flex items-center gap-1 shrink-0">
                  {canEdit && (
                    <button type="button" title={`Editar ${s.name}`} onClick={() => onEdit(s)} className={`${iconButton} hover:text-brand-ink hover:bg-slate-100`}>
                      <Edit2 size={16} />
                    </button>
                  )}
                  {canDelete && (
                    <button type="button" title={`Eliminar ${s.name}`} onClick={() => onDelete(s)} className={`${iconButton} hover:text-red-600 hover:bg-red-50`}>
                      <Trash2 size={16} />
                    </button>
                  )}
                </div>
              </li>
            )}
          />
        </div>
      </div>
    </FormDrawer>
  );
}
