'use client';

import { useEffect, useState, type ReactNode } from 'react';
import { Ban, CalendarX2, Loader2, RotateCcw, Trash2, Undo2 } from 'lucide-react';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { ErrorAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { apiError } from '@/features/appointments/appointments';

interface ReasonDrawerProps {
  isOpen: boolean;
  onClose: () => void;
  title: string;
  subtitle: string;
  icon: ReactNode;
  intro: ReactNode;
  reasonLabel: string;
  placeholder: string;
  minReason?: number;
  confirmName?: string;
  actionLabel: string;
  actionIcon: ReactNode;
  danger?: boolean;
  dataAttr: string;
  onSubmit: (values: { reason: string; confirm_name: string }) => Promise<void>;
  fallbackError: string;
}

function ReasonDrawer({ isOpen, onClose, title, subtitle, icon, intro, reasonLabel, placeholder, minReason = 10, confirmName, actionLabel, actionIcon, danger, dataAttr, onSubmit, fallbackError }: ReasonDrawerProps) {
  const [reason, setReason] = useState('');
  const [name, setName] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (isOpen) {
      setReason('');
      setName('');
      setError('');
      setBusy(false);
    }
  }, [isOpen]);

  const plain = (value: string) => value.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase().replace(/\s+/g, ' ').trim();
  const nameOk = !confirmName || plain(name) === plain(confirmName);
  const ready = reason.trim().length >= minReason && nameOk;

  const submit = async () => {
    try {
      setBusy(true);
      setError('');
      await onSubmit({ reason: reason.trim(), confirm_name: name.trim() });
      onClose();
    } catch (err) {
      setError(apiError(err, fallbackError));
      setBusy(false);
    }
  };

  return (
    <FormDrawer isOpen={isOpen} onClose={onClose} title={title} subtitle={subtitle} icon={icon}>
      <div className="space-y-4" {...{ [`data-${dataAttr}`]: true }}>
        <div className="text-sm text-slate-600 space-y-2">{intro}</div>
        <div>
          <label className={labelClass} htmlFor={`${dataAttr}-reason`}>{reasonLabel}</label>
          <textarea id={`${dataAttr}-reason`} rows={4} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} className={inputClass} placeholder={placeholder} />
          <p className="text-xs text-slate-500 mt-1">Al menos {minReason} letras. Queda en la bitácora de la plataforma.</p>
        </div>
        {confirmName && (
          <div>
            <label className={labelClass} htmlFor={`${dataAttr}-name`}>Para confirmar, escribe el nombre de la empresa</label>
            <input id={`${dataAttr}-name`} type="text" value={name} onChange={(e) => setName(e.target.value)} className={inputClass} placeholder={confirmName} autoComplete="off" />
            <p className="text-xs text-slate-500 mt-1">Puedes escribirlo sin tildes y en mayúsculas o minúsculas.</p>
          </div>
        )}
        <ErrorAlert message={error} />
        <button
          type="button"
          data-reason-go
          disabled={busy || !ready}
          onClick={submit}
          className={`w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-bold text-white rounded-xl disabled:opacity-50 cursor-pointer ${danger ? 'bg-red-600 hover:bg-red-700' : 'bg-brand-accent hover:bg-brand-ink'}`}
        >
          {busy ? <Loader2 size={16} className="animate-spin" /> : actionIcon} {actionLabel}
        </button>
      </div>
    </FormDrawer>
  );
}

interface TenantRef { id: string; name: string }

export function DeleteTenantDrawer({ isOpen, onClose, tenant, days = 90, onSubmit }: { isOpen: boolean; onClose: () => void; tenant: TenantRef; days?: number; onSubmit: (values: { reason: string; confirm_name: string }) => Promise<void> }) {
  return (
    <ReasonDrawer
      isOpen={isOpen}
      onClose={onClose}
      title={`Borrar ${tenant.name}`}
      subtitle={`Se borra a los ${days} días`}
      icon={<Trash2 size={18} />}
      intro={(
        <>
          <p>La empresa queda desactivada desde ahora: nadie de su equipo ni sus clientes pueden entrar y su plan se cancela.</p>
          <p>Sus datos se guardan {days} días. Pasado ese plazo se borran los datos personales de sus clientes y de su equipo; quedan solo los montos y comprobantes que la ley obliga a guardar.</p>
          <p>Mientras no se cumpla el plazo puedes anular el borrado desde su página.</p>
        </>
      )}
      reasonLabel="Motivo del borrado"
      placeholder="Ej. La dueña pidió por correo cerrar su cuenta"
      confirmName={tenant.name}
      actionLabel="Borrar empresa"
      actionIcon={<Trash2 size={16} />}
      danger
      dataAttr="delete-tenant"
      onSubmit={onSubmit}
      fallbackError="No se pudo programar el borrado."
    />
  );
}

export function CancelDeletionDrawer({ isOpen, onClose, tenant, onSubmit }: { isOpen: boolean; onClose: () => void; tenant: TenantRef; onSubmit: (values: { reason: string }) => Promise<void> }) {
  return (
    <ReasonDrawer
      isOpen={isOpen}
      onClose={onClose}
      title={`Anular el borrado de ${tenant.name}`}
      subtitle="Sus datos ya no se borran"
      icon={<Undo2 size={18} />}
      intro={<p>La empresa sigue desactivada y con el plan cancelado. Después puedes reactivarla y registrar su pago.</p>}
      reasonLabel="Motivo"
      placeholder="Ej. La dueña llamó y quiere seguir"
      actionLabel="Anular el borrado"
      actionIcon={<Undo2 size={16} />}
      dataAttr="cancel-deletion"
      onSubmit={onSubmit}
      fallbackError="No se pudo anular el borrado."
    />
  );
}

export function ReactivatePlanDrawer({ isOpen, onClose, tenant, planName, onSubmit }: { isOpen: boolean; onClose: () => void; tenant: TenantRef; planName: string; onSubmit: (values: { reason: string }) => Promise<void> }) {
  return (
    <ReasonDrawer
      isOpen={isOpen}
      onClose={onClose}
      title="Reactivar sin cobrar"
      subtitle={`${tenant.name} · plan ${planName}`}
      icon={<RotateCcw size={18} />}
      intro={(
        <>
          <p>Su suscripción no está vigente (venció o se canceló). Si cambias el plan así, queda activa un periodo completo <span className="font-semibold">sin registrar ningún cobro</span>.</p>
          <p>Si ya pagó, mejor usa «Registrar pago»: deja el comprobante y suma el periodo.</p>
        </>
      )}
      reasonLabel="Por qué la reactivas sin cobrar"
      placeholder="Ej. Pagó por transferencia y el abono llega mañana"
      actionLabel={`Reactivar con el plan ${planName}`}
      actionIcon={<RotateCcw size={16} />}
      dataAttr="reactivate-plan"
      onSubmit={onSubmit}
      fallbackError="No se pudo cambiar el plan."
    />
  );
}

export function CancelSubscriptionDrawer({ isOpen, onClose, tenant, onSubmit }: { isOpen: boolean; onClose: () => void; tenant: TenantRef; onSubmit: (values: { reason: string }) => Promise<void> }) {
  return (
    <ReasonDrawer
      isOpen={isOpen}
      onClose={onClose}
      title={`Cancelar la suscripción de ${tenant.name}`}
      subtitle="Queda en solo lectura desde ahora"
      icon={<Ban size={18} />}
      intro={<p>Se deja de cobrar el plan y la empresa queda sin módulos de inmediato. Sus datos se conservan y puede volver a pagar cuando quiera.</p>}
      reasonLabel="Motivo de la cancelación"
      placeholder="Ej. La dueña pidió cerrar el negocio por teléfono"
      actionLabel="Cancelar la suscripción"
      actionIcon={<Ban size={16} />}
      danger
      dataAttr="cancel-subscription"
      onSubmit={onSubmit}
      fallbackError="No se pudo cancelar la suscripción."
    />
  );
}

export function MarkPastDueDrawer({ isOpen, onClose, tenant, onSubmit }: { isOpen: boolean; onClose: () => void; tenant: TenantRef; onSubmit: (values: { reason: string }) => Promise<void> }) {
  return (
    <ReasonDrawer
      isOpen={isOpen}
      onClose={onClose}
      title={`Marcar pago vencido de ${tenant.name}`}
      subtitle="Empiezan los días de gracia"
      icon={<CalendarX2 size={18} />}
      intro={<p>Úsalo cuando un pago que se dio por hecho no llegó (por ejemplo, una transferencia que se devolvió). El plan vence hoy, la empresa entra en sus días de gracia y le avisamos para que pague. Si no paga, queda en solo lectura como cualquier plan vencido.</p>}
      reasonLabel="Por qué lo marcas vencido"
      placeholder="Ej. El banco devolvió la transferencia del 20 de noviembre"
      actionLabel="Marcar como vencido"
      actionIcon={<CalendarX2 size={16} />}
      danger
      dataAttr="mark-past-due"
      onSubmit={onSubmit}
      fallbackError="No se pudo marcar el pago como vencido."
    />
  );
}
