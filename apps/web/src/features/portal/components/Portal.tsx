'use client';

import { useCallback, useEffect, useState } from 'react';
import { BellRing, Building2, CalendarDays, CalendarPlus, Home, KeyRound, Loader2, LogOut, Mail, PawPrint, Phone, UserRound } from 'lucide-react';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { useVocabulary } from '@/hooks/useVocabulary';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import BookingFlow from './BookingFlow';
import PortalAppointmentCard from './PortalAppointmentCard';
import PortalAppointments from './PortalAppointments';
import PortalWaitlist from './PortalWaitlist';
import PortalPassword from './PortalPassword';
import { portalError, type PortalAppointment, type PortalCatalog, type PortalOverview, type PortalWaitlistEntry } from '../portal';

type Tab = 'home' | 'book' | 'appointments' | 'profile';

// Portal del cliente: su próxima cita, reservar en línea y sus citas.
export default function Portal() {
  const { user, loading: authLoading, logout } = useAuth();
  const vocab = useVocabulary();
  const isStaff = !!user && user.account_type !== 'customer';

  const [overview, setOverview] = useState<PortalOverview | null>(null);
  const [catalog, setCatalog] = useState<PortalCatalog | null>(null);
  const [waiting, setWaiting] = useState<PortalWaitlistEntry[]>([]);
  const [tab, setTab] = useState<Tab>('home');
  const [rescheduling, setRescheduling] = useState<PortalAppointment | null>(null);
  const [cancelling, setCancelling] = useState<PortalAppointment | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [phone, setPhone] = useState('');
  const [editingPhone, setEditingPhone] = useState(false);
  const [editingPassword, setEditingPassword] = useState(false);

  // El portal es solo para cuentas de cliente; el personal vuelve a su panel.
  useEffect(() => {
    if (isStaff) window.location.replace('/home');
  }, [isStaff]);

  const load = useCallback(() => {
    api.get('/portal/overview')
      .then((res) => { setOverview(res.data); setPhone(res.data.customer.phone ?? ''); setError(''); })
      .catch((err) => setError(portalError(err, 'No se pudo cargar tu información.')));
  }, []);

  useEffect(() => {
    if (!user || isStaff) return;
    load();
  }, [user, isStaff, load]);

  // Lo que espera, con los horarios que ya le sirven.
  const loadWaiting = useCallback(() => {
    api.get('/portal/waitlist')
      .then((res) => setWaiting(Array.isArray(res.data) ? res.data : []))
      .catch(() => setWaiting([]));
  }, []);

  // Primer ingreso con su número de documento: antes de nada, crea su contraseña.
  const mustChangePassword = Boolean(overview?.customer.must_change_password);

  // El catálogo solo existe si la empresa ofrece reserva en línea.
  useEffect(() => {
    if (!overview?.policies.booking_enabled || catalog || mustChangePassword) return;

    api.get('/portal/catalog')
      .then((res) => setCatalog(res.data))
      .catch(() => setCatalog(null));
    loadWaiting();
  }, [overview?.policies.booking_enabled, catalog, mustChangePassword, loadWaiting]);

  if (authLoading || !user || isStaff || (!overview && !error)) {
    return (
      <div className="min-h-screen flex flex-col items-center justify-center gap-3">
        <Loader2 className="w-8 h-8 text-[#3F768C] animate-spin" />
        <p className="text-sm font-medium text-slate-500">Cargando tu portal...</p>
      </div>
    );
  }

  const tenant = user.tenant;
  const policies = overview?.policies;
  const appointments = overview?.appointments ?? [];
  // Vienen de la más nueva a la más antigua: las próximas se muestran al revés.
  const upcoming = appointments.filter((a) => a.is_upcoming).reverse();
  const past = appointments.filter((a) => !a.is_upcoming);
  const next = upcoming[0] ?? null;
  const canBook = Boolean(policies?.booking_enabled);

  const act = async (run: () => Promise<unknown>, done: string) => {
    try {
      setBusy(true);
      setError('');
      setNotice('');
      await run();
      setNotice(done);
      load();
    } catch (err) {
      setError(portalError(err, 'No se pudo completar. Inténtalo otra vez.'));
    } finally {
      setBusy(false);
    }
  };

  const confirm = (appointment: PortalAppointment) =>
    act(() => api.post(`/portal/appointments/${appointment.id}/confirm`), 'Gracias: tu asistencia quedó confirmada.');

  const cancel = async () => {
    if (!cancelling) return;
    const appointment = cancelling;
    await act(() => api.post(`/portal/appointments/${appointment.id}/cancel`), 'Tu cita fue cancelada.');
    setCancelling(null);
  };

  const startReschedule = (appointment: PortalAppointment) => {
    setRescheduling(appointment);
    setNotice('');
    setError('');
    setTab('book');
  };

  const openTab = (target: Tab) => {
    setRescheduling(null);
    setNotice('');
    setError('');
    setTab(target);
  };

  const savePhone = () => act(() => api.put('/portal/profile', { phone }), 'Actualizamos tu celular.').then(() => setEditingPhone(false));

  const cardActions = { busy, onConfirm: confirm, onReschedule: startReschedule, onCancel: setCancelling, onMiss: policies?.confirm_on_miss };

  const tabs: { id: Tab; label: string; icon: React.ReactNode }[] = [
    { id: 'home', label: 'Inicio', icon: <Home size={16} /> },
    ...(canBook ? [{ id: 'book' as Tab, label: 'Reservar', icon: <CalendarPlus size={16} /> }] : []),
    { id: 'appointments', label: 'Mis citas', icon: <CalendarDays size={16} /> },
    { id: 'profile', label: 'Mis datos', icon: <UserRound size={16} /> },
  ];

  return (
    <div className="min-h-screen bg-slate-100/70 font-sans text-[#1F2937]">
      <header className="bg-white border-b border-slate-200 sticky top-0 z-30">
        {/* La empresa en una esquina y cerrar sesión en la otra; el menú sigue alineado con el contenido. */}
        <div className="px-4 sm:px-6 lg:px-8 py-3 flex items-center justify-between gap-4" data-portal-bar>
          <div className="flex items-center gap-3 min-w-0">
            <span className="w-10 h-10 rounded-2xl flex items-center justify-center text-white shrink-0" style={{ backgroundColor: tenant?.color ?? '#022340' }}>
              <Building2 className="w-5 h-5" />
            </span>
            <div className="min-w-0">
              <p className="text-base font-black text-[#022340] tracking-tight truncate">{tenant?.name}</p>
              <p className="text-[11px] text-slate-500 font-medium truncate">Portal de {vocab.client.toLowerCase()}</p>
            </div>
          </div>
          <button type="button" onClick={logout} className="inline-flex items-center gap-2 text-xs font-bold text-[#3F768C] hover:text-[#022340] transition-colors cursor-pointer shrink-0">
            <LogOut className="w-4 h-4" /> Cerrar sesión
          </button>
        </div>

        <nav className="max-w-3xl mx-auto px-4 sm:px-6 flex gap-1 overflow-x-auto" aria-label="Secciones del portal">
          {!mustChangePassword && tabs.map((item) => (
            <button
              key={item.id}
              type="button"
              data-tab={item.id}
              aria-current={tab === item.id ? 'page' : undefined}
              onClick={() => openTab(item.id)}
              className={`flex items-center gap-2 px-4 py-2.5 text-sm font-bold border-b-2 whitespace-nowrap transition-colors ${
                tab === item.id ? 'border-[#022340] text-[#022340]' : 'border-transparent text-slate-500 hover:text-slate-800'
              }`}
            >
              {item.icon} {item.label}
            </button>
          ))}
        </nav>
      </header>

      <main className="max-w-3xl mx-auto px-4 sm:px-6 py-6 space-y-5">
        {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-xl px-4 py-3">{error}</p>}
        {notice && <p data-notice className="text-sm text-emerald-700 bg-emerald-50 border border-emerald-100 rounded-xl px-4 py-3">{notice}</p>}

        {overview && mustChangePassword && (
          <PortalPassword forced onDone={() => { setNotice('Tu contraseña quedó guardada. Ya puedes usar tu portal.'); load(); }} />
        )}

        {overview && !mustChangePassword && tab === 'home' && (
          <>
            <div className="space-y-1">
              <h1 className="text-2xl sm:text-3xl font-black text-[#022340] tracking-tight">Hola, {overview.customer.first_name}</h1>
              <p className="text-sm text-slate-500 font-medium">
                {next ? 'Esta es tu próxima cita.' : 'No tienes citas próximas.'}
              </p>
            </div>

            {next ? (
              <PortalAppointmentCard appointment={next} featured {...cardActions} />
            ) : (
              <section className="bg-white rounded-3xl border border-slate-200/80 shadow-xs p-6 flex items-start gap-4">
                <span className="p-2.5 bg-[#84D0D9]/20 text-[#022340] rounded-xl shrink-0"><CalendarDays className="w-5 h-5" /></span>
                <div className="space-y-1">
                  <h2 className="text-sm font-bold text-[#022340]">{canBook ? 'Reserva cuando quieras' : 'Reserva de citas'}</h2>
                  <p className="text-xs text-slate-500 font-medium leading-relaxed">
                    {canBook
                      ? 'Elige el servicio, el día y la hora que te acomoden.'
                      : `Para agendar una cita, comunícate con ${tenant?.name}. Aquí verás las que tengas.`}
                  </p>
                </div>
              </section>
            )}

            {waiting.some((entry) => entry.days.length > 0) && (
              <button
                type="button"
                data-action="see-waiting"
                onClick={() => openTab('appointments')}
                className="w-full flex items-center gap-3 px-5 py-4 text-left bg-emerald-50 border border-emerald-200 rounded-2xl hover:border-emerald-400 transition-colors"
              >
                <span className="p-2 bg-white text-emerald-600 rounded-xl shrink-0"><BellRing size={18} /></span>
                <span>
                  <span className="block text-sm font-bold text-emerald-800">Se liberó un horario que estabas esperando</span>
                  <span className="block text-xs text-emerald-700">Míralo en "Mis citas" y resérvalo antes de que lo tome otra persona.</span>
                </span>
              </button>
            )}

            {canBook && (
              <button
                type="button"
                data-action="book"
                onClick={() => openTab('book')}
                className="w-full flex items-center justify-center gap-2 px-5 py-3.5 text-sm font-bold bg-[#022340] text-white rounded-2xl hover:bg-[#03315a] shadow-sm transition-colors"
              >
                <CalendarPlus size={18} /> Reservar una cita
              </button>
            )}

            {upcoming.length > 1 && (
              <button type="button" onClick={() => openTab('appointments')} className="text-sm font-bold text-[#3F768C] hover:text-[#022340] transition-colors">
                Tienes {upcoming.length - 1} {upcoming.length - 1 === 1 ? 'cita más' : 'citas más'} · Ver todas
              </button>
            )}

          </>
        )}

        {overview && !mustChangePassword && tab === 'book' && (
          !catalog ? (
            <p className="flex items-center gap-2 text-sm text-slate-500"><Loader2 size={15} className="animate-spin" /> Cargando servicios...</p>
          ) : (
            <BookingFlow
              key={rescheduling?.id ?? 'new'}
              overview={overview}
              catalog={catalog}
              reschedule={rescheduling}
              onDone={() => { load(); openTab('appointments'); }}
              onExit={() => openTab(rescheduling ? 'appointments' : 'home')}
              onWaitlisted={() => { loadWaiting(); openTab('appointments'); setNotice('Te anotamos en la lista de espera. Cuando se libere un horario que te sirva, lo verás aquí.'); }}
              onDependentAdded={(dependent) => setOverview({ ...overview, dependents: { ...overview.dependents, items: [...overview.dependents.items, dependent] } })}
            />
          )
        )}

        {overview && !mustChangePassword && tab === 'appointments' && (
          <>
            <PortalWaitlist
              entries={waiting}
              proWord={vocab.pro}
              onChanged={(text) => { loadWaiting(); load(); setError(''); setNotice(text); }}
            />
            <PortalAppointments upcoming={upcoming} past={past} policies={policies} proWord={vocab.pro} actions={cardActions} />
          </>
        )}

        {overview && !mustChangePassword && tab === 'profile' && (
          <>
            <section className="bg-white rounded-3xl border border-slate-200/80 shadow-xs p-5 space-y-3" data-card="profile">
              <h2 className="text-sm font-extrabold text-[#022340] uppercase tracking-wider">Tus datos</h2>
              <p className="text-xs text-slate-500 -mt-1.5">Para corregir tu nombre, tu correo o tu documento, comunícate con {tenant?.name}.</p>
              <dl className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                <div>
                  <dt className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Nombre</dt>
                  <dd className="font-semibold text-slate-800">{overview.customer.first_name} {overview.customer.last_name}</dd>
                </div>
                <div>
                  <dt className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Código</dt>
                  <dd className="font-semibold text-slate-800 font-mono">{overview.customer.code}</dd>
                </div>
                <div className="sm:col-span-2">
                  <dt className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Correo con el que ingresas</dt>
                  <dd className="flex items-center gap-1.5 font-semibold text-slate-800 break-all"><Mail size={14} className="text-slate-400 shrink-0" /> {overview.customer.email || 'Sin registrar'}</dd>
                </div>
                <div className="sm:col-span-2">
                  <dt className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Celular</dt>
                  {editingPhone ? (
                    <dd className="flex items-center gap-2 mt-1">
                      <input
                        type="tel"
                        aria-label="Tu celular"
                        maxLength={30}
                        value={phone}
                        onChange={(e) => setPhone(e.target.value)}
                        className="flex-1 text-sm border border-slate-200 rounded-xl px-3.5 py-2 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340]"
                      />
                      <button type="button" data-action="save-phone" disabled={busy} onClick={savePhone} className="px-4 py-2 text-sm font-bold bg-[#022340] text-white rounded-xl hover:bg-[#03315a] disabled:opacity-50">
                        Guardar
                      </button>
                    </dd>
                  ) : (
                    <dd className="flex items-center gap-3 font-semibold text-slate-800">
                      <span className="flex items-center gap-1.5"><Phone size={14} className="text-slate-400" /> {overview.customer.phone || 'Sin registrar'}</span>
                      <button type="button" data-action="edit-phone" onClick={() => setEditingPhone(true)} className="text-xs font-bold text-[#3F768C] hover:text-[#022340]">
                        Cambiar
                      </button>
                    </dd>
                  )}
                  <p className="text-xs text-slate-500 mt-1">Lo usamos para avisarte de tus citas.</p>
                </div>
                <div className="sm:col-span-2" data-row="password">
                  <dt className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Contraseña</dt>
                  {editingPassword ? (
                    <dd className="mt-1">
                      <PortalPassword
                        onDone={() => { setEditingPassword(false); setError(''); setNotice('Tu contraseña quedó guardada.'); }}
                        onCancel={() => setEditingPassword(false)}
                      />
                    </dd>
                  ) : (
                    <dd className="flex items-center gap-3 font-semibold text-slate-800">
                      <span className="flex items-center gap-1.5"><KeyRound size={14} className="text-slate-400" /> ••••••••</span>
                      <button type="button" data-action="edit-password" onClick={() => setEditingPassword(true)} className="text-xs font-bold text-[#3F768C] hover:text-[#022340]">
                        Cambiar
                      </button>
                    </dd>
                  )}
                </div>
              </dl>

              {overview.dependents.enabled && (
                <div className="pt-3 border-t border-slate-100 space-y-2">
                  <span className="flex items-center gap-1.5 text-[11px] font-bold text-slate-400 uppercase tracking-wider"><PawPrint size={13} /> {vocab.dependents}</span>
                  {overview.dependents.items.length === 0 ? (
                    <p className="text-sm text-slate-500">Aún no registras ninguno. Podrás hacerlo al reservar.</p>
                  ) : (
                    <div className="flex flex-wrap gap-1.5">
                      {overview.dependents.items.map((item) => (
                        <span key={item.id} className="px-2.5 py-1 text-xs font-medium bg-amber-50 text-amber-700 rounded-lg border border-amber-100">
                          {item.name}{item.species ? ` · ${item.species}` : ''}
                        </span>
                      ))}
                    </div>
                  )}
                </div>
              )}
            </section>
          </>
        )}
      </main>

      <AppModal
        isOpen={cancelling !== null}
        type="confirm"
        variant="danger"
        title="¿Cancelar tu cita?"
        message={cancelling ? `${cancelling.service?.name} · ${cancelling.starts_at.slice(8, 10)}/${cancelling.starts_at.slice(5, 7)} a las ${cancelling.starts_at.slice(11, 16)}. El horario quedará libre para otra persona.` : ''}
        confirmText="Sí, cancelar"
        cancelText="No, mantenerla"
        isLoading={busy}
        onConfirm={cancel}
        onClose={() => setCancelling(null)}
      />
    </div>
  );
}
