'use client';

import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import { AlertTriangle, ArrowLeft, CalendarDays, Clock, DoorOpen, Edit2, Globe2, Loader2, MapPin, Pencil, Phone, Plus, Store, Trash2, Users, Wrench } from 'lucide-react';
import { api } from '@/lib/axios';
import { useVocabulary } from '@/hooks/useVocabulary';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Switch from '@/app/components/common/Switch';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { apiError } from '@/features/appointments/appointments';
import FormBranch from './FormBranch';
import BranchHoursPanel from './BranchHoursPanel';
import { ROOM_KINDS, TIMEZONES, WEEK, addressLabel, rangesLabel, todayLabel, type BranchDetailData, type Room } from '../branches';

const card = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs';
const cardTitle = 'flex items-center gap-2 text-sm font-extrabold text-[#022340] uppercase tracking-wider';
const smallLabel = 'text-[11px] font-bold text-slate-400 uppercase tracking-wider';
const inputClass =
  'w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800 bg-white';
const labelClass = 'block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5';

const emptyRoom = { name: '', description: '', kind: 'room' as Room['kind'], active: true };

// Página de una sucursal: sus datos, sus números, su horario de atención,
// quiénes atienden ahí y sus salas y equipos.
export default function BranchDetail() {
  const params = useParams<{ id: string }>();
  const vocab = useVocabulary();
  const [branch, setBranch] = useState<BranchDetailData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [editing, setEditing] = useState(false);
  const [hoursOpen, setHoursOpen] = useState(false);

  const [roomOpen, setRoomOpen] = useState(false);
  const [roomEditing, setRoomEditing] = useState<Room | null>(null);
  const [roomForm, setRoomForm] = useState(emptyRoom);
  const [roomError, setRoomError] = useState('');
  const [roomSaving, setRoomSaving] = useState(false);
  const [roomDeleting, setRoomDeleting] = useState<Room | null>(null);

  const load = useCallback(() => {
    api.get(`/branches/${params.id}`)
      .then((res) => { setBranch(res.data); setError(''); })
      .catch((err) => setError(apiError(err, 'No se pudo cargar la sucursal.')))
      .finally(() => setLoading(false));
  }, [params.id]);

  useEffect(() => { load(); }, [load]);

  const backLink = (
    <Link href="/branches" className="inline-flex items-center gap-2 text-base font-bold text-[#3F768C] hover:text-[#022340] transition-colors">
      <ArrowLeft size={18} /> Sucursales
    </Link>
  );

  if (!branch) {
    return (
      <div className="space-y-5 font-sans">
        {backLink}
        {loading
          ? <p className="flex items-center gap-2 text-sm text-slate-500"><Loader2 size={15} className="animate-spin" /> Cargando sucursal...</p>
          : <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error || 'No se encontró la sucursal.'}</p>}
      </div>
    );
  }

  const canManage = branch.can_manage;
  const zone = TIMEZONES.find((z) => z.value === branch.timezone)?.label ?? branch.timezone;
  const outsideCount = branch.professionals.reduce((sum, p) => sum + p.ranges.filter((r) => r.outside).length, 0);

  const facts: [React.ReactNode, string, string | null][] = [
    [<MapPin key="i" size={14} />, 'Dirección', addressLabel(branch) || null],
    [<MapPin key="i" size={14} />, 'Departamento', branch.department],
    [<Phone key="i" size={14} />, 'Teléfono', branch.phone],
    [<Globe2 key="i" size={14} />, 'Zona horaria', zone],
  ];

  const stats: { id: string; title: string; value: string; note: string; icon: React.ReactNode; color: string }[] = [
    { id: 'today', title: 'Citas de hoy', value: String(branch.appointments_today), note: todayLabel(branch), icon: <CalendarDays size={18} />, color: 'text-sky-600' },
    { id: 'upcoming', title: 'Por atender', value: String(branch.upcoming), note: 'Pendientes y confirmadas', icon: <Clock size={18} />, color: 'text-amber-600' },
    { id: 'team', title: vocab.pros, value: String(branch.professionals.length), note: 'Con horario en esta sede', icon: <Users size={18} />, color: 'text-violet-600' },
    ...(branch.uses_rooms ? [{ id: 'rooms', title: 'Salas y equipos', value: String(branch.rooms.filter((r) => r.status === 'active').length), note: 'Activos', icon: <DoorOpen size={18} />, color: 'text-emerald-600' }] : []),
  ];

  const openRoom = (room: Room | null) => {
    setRoomEditing(room);
    setRoomForm(room ? { name: room.name, description: room.description ?? '', kind: room.kind, active: room.status === 'active' } : emptyRoom);
    setRoomError('');
    setRoomOpen(true);
  };

  const saveRoom = async (e: React.FormEvent) => {
    e.preventDefault();

    const payload = { name: roomForm.name.trim(), description: roomForm.description.trim() || null, kind: roomForm.kind, status: roomForm.active ? 'active' : 'inactive' };

    try {
      setRoomSaving(true);
      setRoomError('');
      if (roomEditing) {
        await api.put(`/rooms/${roomEditing.id}`, payload);
      } else {
        await api.post(`/branches/${branch.id}/rooms`, payload);
      }
      setRoomOpen(false);
      load();
    } catch (err) {
      setRoomError(apiError(err, 'No se pudo guardar.'));
    } finally {
      setRoomSaving(false);
    }
  };

  const deleteRoom = async () => {
    if (!roomDeleting) return;

    try {
      setRoomSaving(true);
      await api.delete(`/rooms/${roomDeleting.id}`);
      load();
    } catch (err) {
      setError(apiError(err, 'No se pudo eliminar.'));
    } finally {
      setRoomSaving(false);
      setRoomDeleting(null);
    }
  };

  return (
    <div className="space-y-5 pb-8 font-sans">
      {backLink}

      {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error}</p>}

      <div className="grid grid-cols-1 xl:grid-cols-3 gap-5 items-start">
        {/* Datos de la sucursal */}
        <section className={`${card} p-6 space-y-5 xl:row-span-2`} data-card="branch">
          <div className="flex items-start justify-between gap-3">
            <div className="flex items-center gap-3 min-w-0">
              <span className="w-12 h-12 rounded-2xl bg-[#84D0D9]/20 text-[#022340] flex items-center justify-center shrink-0"><Store size={22} /></span>
              <div className="min-w-0">
                <h1 className="text-xl font-black text-[#022340] tracking-tight truncate">{branch.name}</h1>
                <span data-status={branch.status} className={`inline-block mt-1 px-2.5 py-0.5 text-xs rounded-full font-medium ${branch.status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}>
                  {branch.status === 'active' ? 'Activa' : 'Inactiva'}
                </span>
              </div>
            </div>
            {canManage && (
              <button type="button" data-action="edit-branch" title="Editar sucursal" onClick={() => setEditing(true)} className="p-2 text-slate-400 hover:text-[#022340] hover:bg-slate-100 rounded-lg transition-colors shrink-0">
                <Pencil size={16} />
              </button>
            )}
          </div>

          <dl className="space-y-3">
            {facts.map(([icon, label, value]) => (
              <div key={label} className="flex items-start gap-3">
                <span className="text-slate-400 mt-0.5">{icon}</span>
                <div className="min-w-0">
                  <dt className={smallLabel}>{label}</dt>
                  <dd className={`text-sm ${value ? 'font-semibold text-slate-800' : 'text-slate-400'}`}>{value || 'Sin registrar'}</dd>
                </div>
              </div>
            ))}
          </dl>
        </section>

        {/* Números */}
        <div className={`xl:col-span-2 grid grid-cols-2 gap-4 ${stats.length > 3 ? 'lg:grid-cols-4' : 'lg:grid-cols-3'}`}>
          {stats.map((stat) => (
            <div key={stat.id} data-stat={stat.id} className="bg-white px-4 py-3 rounded-2xl border border-slate-100 shadow-[0_4px_20px_-4px_rgba(0,0,0,0.05)]">
              <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                  <span className="text-[10px] font-semibold tracking-wider text-slate-400 uppercase">{stat.title}</span>
                  <div className="mt-0.5 text-2xl font-extrabold tracking-tight text-slate-900">{stat.value}</div>
                </div>
                <span className={stat.color}>{stat.icon}</span>
              </div>
              <span className="block text-xs text-slate-500 truncate">{stat.note}</span>
            </div>
          ))}
        </div>

        {/* Horario de atención */}
        <section className={`${card} p-6 space-y-4`} data-card="hours">
          <div className="flex items-center justify-between gap-3">
            <h2 className={cardTitle}><Clock size={16} className="text-[#3F768C]" /> Horario de atención</h2>
            {canManage && (
              <button type="button" data-action="edit-hours" onClick={() => setHoursOpen(true)} className="text-xs font-bold text-[#3F768C] hover:text-[#022340] transition-colors">
                Cambiar
              </button>
            )}
          </div>

          {!branch.has_hours ? (
            <p className="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2.5" data-no-hours>
              Aún no tiene horario definido. La agenda ofrece citas según el horario de cada {vocab.pro.toLowerCase()}, a cualquier hora.
            </p>
          ) : (
            <ul className="divide-y divide-slate-100">
              {WEEK.map((day) => {
                const ranges = branch.hours[day.weekday] ?? [];

                return (
                  <li key={day.weekday} className="flex items-center justify-between gap-3 py-2 text-sm">
                    <span className={ranges.length ? 'font-semibold text-slate-800' : 'text-slate-400'}>{day.label}</span>
                    <span className={ranges.length ? 'text-slate-700' : 'text-slate-400'}>{ranges.length ? rangesLabel(ranges) : 'Cerrada'}</span>
                  </li>
                );
              })}
            </ul>
          )}
        </section>

        {/* Quiénes atienden aquí */}
        <section className={`${card} p-6 space-y-4`} data-card="team">
          <div className="flex items-center justify-between gap-3">
            <h2 className={cardTitle}><Users size={16} className="text-[#3F768C]" /> {vocab.pros} en esta sede</h2>
            <Link href="/professionals" className="text-xs font-bold text-[#3F768C] hover:text-[#022340] transition-colors">Ver horarios</Link>
          </div>

          {outsideCount > 0 && (
            <p className="flex items-start gap-2 text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2" data-outside={outsideCount}>
              <AlertTriangle size={14} className="mt-0.5 shrink-0" />
              Hay horarios fuera del horario de la sede (marcados abajo). En esas horas la agenda no ofrece citas.
            </p>
          )}

          {branch.professionals.length === 0 ? (
            <p className="text-sm text-slate-500">
              Nadie tiene horario en esta sede todavía. Una persona atiende aquí cuando se le define un horario en esta sede, desde {vocab.pros}.
            </p>
          ) : (
            <ul className="space-y-3">
              {branch.professionals.map((p) => (
                <li key={p.id} data-professional={p.id}>
                  <span className={`block text-sm font-semibold ${p.inactive ? 'text-slate-400' : 'text-slate-800'}`}>{p.display_name}{p.inactive ? ' · inactivo' : ''}</span>
                  <div className="flex flex-wrap gap-1.5 mt-1">
                    {WEEK.filter((day) => p.ranges.some((r) => r.weekday === day.weekday)).map((day) => {
                      const ranges = p.ranges.filter((r) => r.weekday === day.weekday);
                      const outside = ranges.some((r) => r.outside);

                      return (
                        <span
                          key={day.weekday}
                          title={ranges.map((r) => `${r.starts} a ${r.ends}`).join(' y ') + (outside ? ' · fuera del horario de la sede' : '')}
                          className={`px-2 py-0.5 text-[11px] font-medium rounded-lg border ${outside ? 'bg-amber-50 text-amber-700 border-amber-200' : 'bg-slate-50 text-slate-600 border-slate-200'}`}
                        >
                          {day.short} {ranges.map((r) => `${r.starts}–${r.ends}`).join(', ')}
                        </span>
                      );
                    })}
                  </div>
                </li>
              ))}
            </ul>
          )}
        </section>

        {/* Salas y equipos */}
        {branch.uses_rooms && (
          <section className={`${card} xl:col-span-3 overflow-hidden`} data-card="rooms">
            <div className="flex items-center justify-between gap-3 px-6 py-4 border-b border-slate-100">
              <h2 className={cardTitle}><DoorOpen size={16} className="text-[#3F768C]" /> Salas y equipos</h2>
              {canManage && (
                <button type="button" data-action="add-room" onClick={() => openRoom(null)} className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold bg-[#022340] text-white rounded-lg hover:bg-[#03315a] transition-colors">
                  <Plus size={14} /> Agregar
                </button>
              )}
            </div>

            {branch.rooms.length === 0 ? (
              <p className="px-6 py-5 text-sm text-slate-500">Esta sede aún no tiene salas ni equipos registrados.</p>
            ) : (
              <ul className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-px bg-slate-100">
                {branch.rooms.map((room) => (
                  <li key={room.id} data-room={room.id} className="flex items-center justify-between gap-3 bg-white px-6 py-3">
                    <div className="flex items-center gap-3 min-w-0">
                      <span className={`p-2 rounded-lg shrink-0 ${room.kind === 'equipment' ? 'bg-violet-50 text-violet-600' : 'bg-sky-50 text-sky-600'}`}>
                        {room.kind === 'equipment' ? <Wrench size={15} /> : <DoorOpen size={15} />}
                      </span>
                      <div className="min-w-0">
                        <span className={`block text-sm font-semibold truncate ${room.status === 'active' ? 'text-slate-800' : 'text-slate-400'}`}>{room.name}</span>
                        <span className="block text-xs text-slate-500 truncate">
                          {ROOM_KINDS[room.kind]}{room.description ? ` · ${room.description}` : ''}{room.status !== 'active' ? ' · inactivo' : ''}
                        </span>
                      </div>
                    </div>
                    {canManage && (
                      <div className="flex items-center gap-1 shrink-0">
                        <button title="Editar" onClick={() => openRoom(room)} className="p-1.5 text-slate-400 hover:text-[#022340] hover:bg-slate-100 rounded-lg transition-colors"><Edit2 size={15} /></button>
                        <button data-delete-room={room.id} title="Eliminar" onClick={() => setRoomDeleting(room)} className="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"><Trash2 size={15} /></button>
                      </div>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </section>
        )}
      </div>

      <FormBranch isOpen={editing} onClose={() => setEditing(false)} initialData={branch} onSaved={load} />
      <BranchHoursPanel isOpen={hoursOpen} onClose={() => setHoursOpen(false)} branch={branch} onSaved={load} />

      <FormDrawer
        isOpen={roomOpen}
        onClose={() => setRoomOpen(false)}
        title={roomEditing ? `Editar: ${roomEditing.name}` : 'Nueva sala o equipo'}
        subtitle={branch.name}
        icon={<DoorOpen size={20} />}
      >
        <form id="room-form" onSubmit={saveRoom} className="space-y-5">
          <div>
            <span className={labelClass}>Tipo</span>
            <div className="flex gap-2">
              {(Object.keys(ROOM_KINDS) as Room['kind'][]).map((kind) => (
                <button
                  key={kind}
                  type="button"
                  data-room-kind={kind}
                  aria-pressed={roomForm.kind === kind}
                  onClick={() => setRoomForm({ ...roomForm, kind })}
                  className={`px-3.5 py-2 text-sm font-medium border rounded-full transition-colors ${
                    roomForm.kind === kind ? 'border-[#022340] bg-[#022340] text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'
                  }`}
                >
                  {ROOM_KINDS[kind]}
                </button>
              ))}
            </div>
          </div>
          <div>
            <label className={labelClass} htmlFor="room-name">Nombre *</label>
            <input id="room-name" type="text" required maxLength={100} placeholder={roomForm.kind === 'equipment' ? 'Ej. Ecógrafo' : 'Ej. Consultorio 101'} className={inputClass} value={roomForm.name} onChange={(e) => setRoomForm({ ...roomForm, name: e.target.value })} />
          </div>
          <div>
            <label className={labelClass} htmlFor="room-description">Descripción</label>
            <input id="room-description" type="text" maxLength={255} className={inputClass} value={roomForm.description} onChange={(e) => setRoomForm({ ...roomForm, description: e.target.value })} />
          </div>
          {roomEditing && (
            <label className="flex items-center justify-between gap-3 px-4 py-3 border border-slate-200 rounded-xl cursor-pointer">
              <span className="text-sm font-semibold text-slate-800">Disponible para usarse</span>
              <Switch checked={roomForm.active} onChange={(active) => setRoomForm({ ...roomForm, active })} label="Disponible para usarse" />
            </label>
          )}

          {roomError && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{roomError}</p>}

          <div className="flex justify-end gap-3 pt-2">
            <button type="button" onClick={() => setRoomOpen(false)} className="px-4 py-2.5 text-sm font-medium border border-slate-200 rounded-lg text-slate-600 hover:bg-slate-50 transition-colors">Cancelar</button>
            <button type="submit" disabled={roomSaving} className="px-5 py-2.5 text-sm font-medium bg-[#022340] text-white rounded-lg hover:bg-[#03315a] shadow-sm transition-colors disabled:opacity-50">
              {roomSaving ? 'Guardando...' : roomEditing ? 'Guardar cambios' : 'Agregar'}
            </button>
          </div>
        </form>
      </FormDrawer>

      <AppModal
        isOpen={Boolean(roomDeleting)}
        variant="danger"
        title="Eliminar sala o equipo"
        message={`Se eliminará "${roomDeleting?.name ?? ''}" de ${branch.name}. Si ya tiene citas registradas, se desactiva en lugar de eliminarse.`}
        confirmText="Sí, eliminar"
        cancelText="No, mantenerlo"
        isLoading={roomSaving}
        onConfirm={deleteRoom}
        onClose={() => setRoomDeleting(null)}
      />
    </div>
  );
}
