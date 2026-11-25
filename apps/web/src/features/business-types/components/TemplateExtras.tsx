'use client';

import { DoorOpen, Package, Plus, Repeat, Trash2 } from 'lucide-react';
import Select from '@/app/components/common/Select';
import type { BusinessTemplate, TemplateBundle, TemplateRoom } from './TemplateEditor';

const inputClass = 'w-full text-sm border border-slate-200 rounded-lg px-3 py-2 text-slate-800 focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none bg-white';
const labelClass = 'block text-[11px] font-semibold uppercase tracking-wider text-slate-600 mb-1';
const addLink = 'inline-flex items-center gap-1 text-xs font-bold text-sky-600 hover:text-sky-700 transition-colors';
const removeButton = 'p-1.5 text-slate-400 rounded-lg hover:text-rose-600 hover:bg-rose-50 transition-colors';

export const roomTypeOf = (room: TemplateRoom) => room.type?.trim() || room.name.trim();

function Bundles({ title, hint, icon, rows, codes, membership, onChange }: { title: string; hint: string; icon: React.ReactNode; rows: TemplateBundle[]; codes: { value: string; label: string }[]; membership?: boolean; onChange: (rows: TemplateBundle[]) => void }) {
  const update = (index: number, patch: Partial<TemplateBundle>) => onChange(rows.map((row, i) => (i === index ? { ...row, ...patch } : row)));

  return (
    <div className="space-y-2" data-template-bundles={membership ? 'memberships' : 'packages'}>
      <div>
        <span className="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-slate-800">{icon} {title}</span>
        <span className="block text-xs text-slate-400">{hint}</span>
      </div>
      {rows.map((row, index) => (
        <div key={index} className="rounded-xl border border-slate-200 p-3 space-y-2">
          <div className="grid grid-cols-1 sm:grid-cols-[1fr_7rem_7rem_auto] gap-2 items-end">
            <div>
              <label className={labelClass}>Nombre</label>
              <input maxLength={150} value={row.name} onChange={(e) => update(index, { name: e.target.value })} className={inputClass} placeholder={membership ? 'Ej. Club mensual' : 'Ej. Pack 5 sesiones'} />
            </div>
            <div>
              <label className={labelClass}>{membership ? 'Precio al mes' : 'Precio'}</label>
              <input type="number" min="0" step="0.01" value={row.price} onChange={(e) => update(index, { price: Number(e.target.value) })} className={inputClass} />
            </div>
            <div>
              <label className={labelClass}>{membership ? 'Descuento %' : 'Vence en días'}</label>
              {membership
                ? <input type="number" min="0" max="100" value={row.discount_percent ?? 0} onChange={(e) => update(index, { discount_percent: Number(e.target.value) })} className={inputClass} />
                : <input type="number" min="1" max="3650" value={row.validity_days ?? 90} onChange={(e) => update(index, { validity_days: Number(e.target.value) })} className={inputClass} />}
            </div>
            <button type="button" aria-label={`Quitar ${row.name || title}`} onClick={() => onChange(rows.filter((_, i) => i !== index))} className={removeButton}><Trash2 size={15} /></button>
          </div>
          <div className="space-y-1.5">
            <span className={labelClass}>Servicios incluidos</span>
            {row.items.map((item, itemIndex) => (
              <div key={itemIndex} className="grid grid-cols-[1fr_6rem_auto] gap-2 items-center">
                <Select ariaLabel="Servicio" clearable={false} value={item.code} onChange={(e) => update(index, { items: row.items.map((it, i) => (i === itemIndex ? { ...it, code: String(e.target.value) } : it)) })} options={codes} placeholder="Elige el servicio" />
                <input type="number" min="1" max={membership ? 99 : 200} aria-label="Sesiones" value={item.sessions} onChange={(e) => update(index, { items: row.items.map((it, i) => (i === itemIndex ? { ...it, sessions: Number(e.target.value) } : it)) })} className={inputClass} />
                <button type="button" aria-label="Quitar servicio" onClick={() => update(index, { items: row.items.filter((_, i) => i !== itemIndex) })} className={removeButton}><Trash2 size={14} /></button>
              </div>
            ))}
            <button type="button" className={addLink} disabled={codes.length === 0} onClick={() => update(index, { items: [...row.items, { code: codes[0]?.value ?? '', sessions: 1 }] })}><Plus size={13} /> Agregar servicio</button>
          </div>
        </div>
      ))}
      <button type="button" className={addLink} disabled={codes.length === 0} title={codes.length === 0 ? 'Primero pon código a los servicios' : undefined} onClick={() => onChange([...rows, membership ? { name: '', price: 0, discount_percent: 0, items: [] } : { name: '', price: 0, validity_days: 90, items: [{ code: codes[0]?.value ?? '', sessions: 1 }] }])}>
        <Plus size={14} /> {membership ? 'Agregar membresía' : 'Agregar paquete'}
      </button>
    </div>
  );
}

export default function TemplateExtras({ value, onChange }: { value: BusinessTemplate; onChange: (value: BusinessTemplate) => void }) {
  const rooms = value.rooms ?? [];
  const codes = value.categories.flatMap((category) => category.services).filter((service) => service.code?.trim()).map((service) => ({ value: String(service.code).trim(), label: `${service.name} (${String(service.code).trim()})` }));
  const setRooms = (next: TemplateRoom[]) => onChange({ ...value, rooms: next });

  return (
    <div className="space-y-5 pt-5 border-t border-slate-200" data-template-extras>
      <div className="space-y-2" data-template-rooms>
        <div>
          <span className="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-slate-800"><DoorOpen size={14} className="text-brand-accent" /> Salas y equipos</span>
          <span className="block text-xs text-slate-400">Se crean en la primera sede de cada empresa nueva. Las salas del mismo tipo sirven para los mismos servicios (márcalo en cada servicio).</span>
        </div>
        {rooms.map((room, index) => (
          <div key={index} className="grid grid-cols-1 sm:grid-cols-[1fr_10rem_9rem_auto] gap-2 items-end">
            <div>
              <label className={labelClass}>Nombre</label>
              <input maxLength={100} value={room.name} onChange={(e) => setRooms(rooms.map((r, i) => (i === index ? { ...r, name: e.target.value } : r)))} className={inputClass} placeholder="Ej. Cabina 1" />
            </div>
            <div>
              <label className={labelClass}>Tipo</label>
              <input maxLength={60} value={room.type ?? ''} onChange={(e) => setRooms(rooms.map((r, i) => (i === index ? { ...r, type: e.target.value } : r)))} className={inputClass} placeholder="Ej. cabina" />
            </div>
            <div>
              <label className={labelClass}>Es</label>
              <Select ariaLabel="Sala o equipo" clearable={false} value={room.kind ?? 'room'} onChange={(e) => setRooms(rooms.map((r, i) => (i === index ? { ...r, kind: String(e.target.value) as TemplateRoom['kind'] } : r)))} options={[{ value: 'room', label: 'Sala' }, { value: 'equipment', label: 'Equipo' }]} />
            </div>
            <button type="button" aria-label={`Quitar ${room.name || 'sala'}`} onClick={() => setRooms(rooms.filter((_, i) => i !== index))} className={removeButton}><Trash2 size={15} /></button>
          </div>
        ))}
        <button type="button" className={addLink} onClick={() => setRooms([...rooms, { name: '', type: '', kind: 'room' }])}><Plus size={14} /> Agregar sala o equipo</button>
      </div>

      <Bundles title="Paquetes" hint="Paquetes de sesiones que la empresa nueva ya tiene listos para vender. Usa los códigos de los servicios." icon={<Package size={14} className="text-brand-accent" />} rows={value.packages ?? []} codes={codes} onChange={(packages) => onChange({ ...value, packages })} />
      <Bundles title="Membresías" hint="Planes mensuales con descuento y sesiones incluidas." icon={<Repeat size={14} className="text-brand-accent" />} rows={value.memberships ?? []} codes={codes} membership onChange={(memberships) => onChange({ ...value, memberships })} />
    </div>
  );
}
