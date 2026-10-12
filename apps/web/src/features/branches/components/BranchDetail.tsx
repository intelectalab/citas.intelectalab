'use client';

import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import { AlertTriangle, ArrowLeft, CalendarDays, Clock, DoorOpen, Edit2, Globe2, Loader2, MapPin, Pencil, Phone, Plus, Store, Trash2, Users, Wrench } from 'lucide-react';
import { api } from '@/lib/axios';
import { useVocabulary } from '@/hooks/useVocabulary';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { apiError } from '@/features/appointments/appointments';
import FormBranch from './FormBranch';
import BranchHoursPanel from './BranchHoursPanel';
import FormRoom from '@/features/rooms/components/FormRoom';
import { ROOM_KINDS, TIMEZONES, WEEK, addressLabel, rangesLabel, todayLabel, type BranchDetailData, type Room } from '../branches';
import { ProfessionalLink } from '@/app/components/common/PersonLink';

const card = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs';
const cardTitle = 'flex items-center gap-2 text-sm font-extrabold text-[#022340] uppercase tracking-wider';
const smallLabel = 'text-[11px] font-bold text-slate-400 uppercase tracking-wider';

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
    ...(branch.uses_rooms ? [{ id: 'rooms', title: 'Salas y equipos', value: String(branch.rooms.filter((r) => (r.effective_status ?? r.status) === 'active').length), note: 'En uso', icon: <DoorOpen size={18} />, color: 'text-emerald-600' }] : []),
  ];

  const statusOf = (room: Room) => room.effective_status ?? room.status;

  const openRoom = (room: Room | null) => {
    setRoomEditing(room);
    setRoomOpen(true);
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
                  <ProfessionalLink id={p.id} className={`block text-sm font-semibold ${p.inactive ? 'text-slate-400' : 'text-slate-800'}`}>{p.display_name}{p.inactive ? ' · inactivo' : ''}</ProfessionalLink>
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
                        <span className={`block text-sm font-semibold truncate ${statusOf(room) === 'active' ? 'text-slate-800' : 'text-slate-400'}`}>{room.name}</span>
                        <span className="block text-xs text-slate-500 truncate">
                          {ROOM_KINDS[room.kind]}{room.parent_name ? ` · en ${room.parent_name}` : ''}{room.description ? ` · ${room.description}` : ''}{statusOf(room) !== 'active' ? ' · fuera de uso' : ''}
                        </span>
                        {(room.down_equipment?.length ?? 0) > 0 && (
                          <span data-equipment-note className={`block text-[11px] font-semibold truncate ${room.auto_down ? 'text-rose-600' : 'text-amber-700'}`}>
                            {room.auto_down ? 'Por' : 'Observación:'} {room.down_equipment!.join(', ')} fuera de uso
                          </span>
                        )}
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

      <FormRoom
        isOpen={roomOpen}
        onClose={() => setRoomOpen(false)}
        initialData={roomEditing}
        branches={[{ id: branch.id, name: branch.name }]}
        rooms={branch.links_equipment ? branch.rooms.filter((r) => r.kind === 'room') : []}
        defaultBranchId={branch.id}
        onSaved={load}
      />

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
