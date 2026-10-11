'use client';

import { CalendarClock, Check, Clock, MapPin, PawPrint, UserRound, X } from 'lucide-react';
import { dateOf, formatDate, statusStyle, timeOf } from '@/features/appointments/appointments';
import type { PortalAppointment } from '../portal';

interface PortalAppointmentCardProps {
  appointment: PortalAppointment;
  // Destacada: la próxima cita, en la portada.
  featured?: boolean;
  busy?: boolean;
  onConfirm?: (appointment: PortalAppointment) => void;
  onReschedule?: (appointment: PortalAppointment) => void;
  onCancel?: (appointment: PortalAppointment) => void;
  // Qué hace la empresa con una cita que no se confirma a tiempo.
  onMiss?: 'notify' | 'notify_release' | 'release';
}

// Una cita del cliente, con lo que puede hacer con ella desde el portal.
export default function PortalAppointmentCard({ appointment, featured = false, busy = false, onConfirm, onReschedule, onCancel, onMiss = 'notify' }: PortalAppointmentCardProps) {
  const date = dateOf(appointment.starts_at);
  const hasActions = appointment.can_confirm || appointment.can_reschedule || appointment.can_cancel;

  return (
    <article
      data-appointment={appointment.code}
      className={`bg-white rounded-3xl border shadow-xs overflow-hidden ${featured ? 'border-[#3F768C]/40' : 'border-slate-200/80'}`}
    >
      <div className="flex items-stretch">
        <div className={`w-20 shrink-0 flex flex-col items-center justify-center py-4 ${featured ? 'bg-[#022340] text-white' : 'bg-slate-50 text-slate-700 border-r border-slate-100'}`}>
          <span className="text-[11px] font-bold uppercase tracking-wider opacity-80">{formatDate(date, { month: 'short' }).replace('.', '')}</span>
          <span className="text-3xl font-black leading-none">{date.slice(8, 10)}</span>
          <span className="text-[11px] font-semibold opacity-80 first-letter:uppercase">{formatDate(date, { weekday: 'short' }).replace('.', '')}</span>
        </div>

        <div className="flex-1 min-w-0 p-4 space-y-2">
          <div className="flex items-start justify-between gap-2">
            <h3 className="text-base font-extrabold text-[#022340] leading-tight">{appointment.service?.name}</h3>
            <span data-status={appointment.status} className={`px-2.5 py-1 text-xs rounded-full font-semibold whitespace-nowrap ${statusStyle(appointment.status).badge}`}>
              {appointment.status_name}
            </span>
          </div>

          <ul className="space-y-1 text-sm text-slate-600">
            <li className="flex items-center gap-2"><Clock size={14} className="text-slate-400 shrink-0" /> {timeOf(appointment.starts_at)} a {timeOf(appointment.ends_at)}</li>
            {appointment.professional && <li className="flex items-center gap-2"><UserRound size={14} className="text-slate-400 shrink-0" /> {appointment.professional.display_name}</li>}
            {appointment.dependent && <li className="flex items-center gap-2"><PawPrint size={14} className="text-slate-400 shrink-0" /> {appointment.dependent.name}</li>}
            {appointment.branch && <li className="flex items-center gap-2"><MapPin size={14} className="text-slate-400 shrink-0" /> {appointment.branch.name}</li>}
          </ul>

          {appointment.can_confirm && appointment.confirm_by && (
            <p data-confirm-by className={`text-xs font-medium ${appointment.confirmation_overdue ? 'text-rose-600' : 'text-amber-700'}`}>
              {appointment.confirmation_overdue
                ? `Aún no confirmas tu asistencia${onMiss === 'notify' ? '. Hazlo cuanto antes.' : ': tu cita puede cancelarse. Confírmala ahora.'}`
                : `Confirma tu asistencia antes del ${formatDate(dateOf(appointment.confirm_by), { weekday: 'long', day: 'numeric', month: 'long' })}, ${timeOf(appointment.confirm_by)}${onMiss === 'notify' ? '.' : ', o tu cita se cancelará.'}`}
            </p>
          )}

          {appointment.status === 'cancelled' && appointment.cancel_reason && (
            <p className="text-xs text-slate-500">Motivo: {appointment.cancel_reason}</p>
          )}
        </div>
      </div>

      {hasActions && (
        <div className="flex flex-wrap gap-2 px-4 py-3 border-t border-slate-100 bg-slate-50/60">
          {appointment.can_confirm && (
            <button
              type="button"
              data-action="confirm"
              disabled={busy}
              onClick={() => onConfirm?.(appointment)}
              className="flex items-center gap-1.5 px-4 py-2 text-sm font-bold bg-[#022340] text-white rounded-xl hover:bg-[#03315a] transition-colors disabled:opacity-50"
            >
              <Check size={15} /> Confirmar asistencia
            </button>
          )}
          {appointment.can_reschedule && (
            <button
              type="button"
              data-action="reschedule"
              disabled={busy}
              onClick={() => onReschedule?.(appointment)}
              className="flex items-center gap-1.5 px-4 py-2 text-sm font-semibold border border-slate-200 bg-white text-slate-700 rounded-xl hover:bg-slate-50 transition-colors disabled:opacity-50"
            >
              <CalendarClock size={15} /> Cambiar fecha
            </button>
          )}
          {appointment.can_cancel && (
            <button
              type="button"
              data-action="cancel"
              disabled={busy}
              onClick={() => onCancel?.(appointment)}
              className="flex items-center gap-1.5 px-4 py-2 text-sm font-semibold border border-rose-200 bg-white text-rose-700 rounded-xl hover:bg-rose-50 transition-colors disabled:opacity-50"
            >
              <X size={15} /> Cancelar
            </button>
          )}
        </div>
      )}
    </article>
  );
}
