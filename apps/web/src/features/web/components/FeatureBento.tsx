import { BellRing, CalendarCheck, Check, CheckCheck, ClipboardList, Globe, Link2, Package, Receipt } from 'lucide-react';

function Head({ icon: Icon, title, text }: { icon: typeof Globe; title: string; text: string }) {
  return (
    <div>
      <span className="inline-flex p-2.5 rounded-xl bg-brand-soft/20 text-brand-accent transition-colors group-hover:bg-brand group-hover:text-brand-soft"><Icon className="w-5 h-5" /></span>
      <h3 className="mt-3 font-bold text-brand-ink">{title}</h3>
      <p className="mt-1 text-sm text-slate-500">{text}</p>
    </div>
  );
}

const card = 'site-lift group bg-white rounded-3xl border border-slate-200 hover:border-brand-soft p-5 sm:p-6 flex flex-col gap-5 overflow-hidden';

const SLOTS = [
  ['9:00', 1], ['9:30', 1], ['10:00', 0], ['10:30', 1], ['11:00', 0], ['11:30', 1],
  ['12:00', 1], ['15:00', 0], ['15:30', 1], ['16:00', 0], ['16:30', 1], ['17:00', 0],
] as const;

export default function FeatureBento() {
  return (
    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4" data-features>
      <div data-reveal className={`${card} sm:col-span-2 lg:flex-row lg:items-center`}>
        <div className="lg:w-[42%] shrink-0">
          <Head icon={CalendarCheck} title="Agenda y reservas en línea" text="Tus clientes reservan solos a cualquier hora, y solo ven los horarios que de verdad están libres. Sin cruces." />
        </div>
        <div className="flex-1 rounded-2xl bg-slate-50 border border-slate-100 p-3" aria-hidden="true">
          <div className="flex items-center justify-between text-[11px] font-bold text-slate-500 mb-2">
            <span>Mañana, con Carla</span>
            <span className="text-brand-accent">5 horarios libres</span>
          </div>
          <div className="grid grid-cols-4 sm:grid-cols-6 gap-1.5">
            {SLOTS.map(([time, taken], index) => (
              <span
                key={time}
                className={`text-center text-[11px] font-bold rounded-lg py-1.5 tabular-nums ${taken ? 'bg-slate-200/70 text-slate-400 line-through decoration-slate-300' : index === 4 ? 'bg-brand text-white ring-2 ring-brand-soft site-breathe' : 'bg-white border border-brand-soft/60 text-brand-ink'}`}
              >
                {time}
              </span>
            ))}
          </div>
        </div>
      </div>

      <div data-reveal style={{ ['--d' as string]: '90ms' }} className={card}>
        <Head icon={BellRing} title="Recordatorios automáticos" text="Avisos por correo y WhatsApp para que falten menos." />
        <div className="mt-auto space-y-1.5" aria-hidden="true">
          <div className="max-w-[90%] rounded-2xl rounded-tl-md bg-slate-100 px-3 py-2 text-xs text-slate-600">Hola Lucía, te esperamos mañana a las 11:00 am.</div>
          <div className="ml-auto w-fit rounded-2xl rounded-tr-md bg-brand-soft/30 px-3 py-2 text-xs font-semibold text-brand-ink flex items-center gap-1.5">Confirmo, gracias <CheckCheck className="w-3.5 h-3.5 text-brand-accent" /></div>
        </div>
      </div>

      <div data-reveal className={card}>
        <Head icon={Receipt} title="Caja y comprobantes" text="Cobra, emite boletas y facturas y cierra la caja del día." />
        <div className="mt-auto rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-3 text-[11px] text-slate-500 space-y-1 tabular-nums" aria-hidden="true">
          <div className="flex justify-between font-bold text-brand-ink"><span>Boleta B001-0245</span><span className="text-emerald-700 inline-flex items-center gap-1"><Check className="w-3 h-3" /> Emitida</span></div>
          <div className="flex justify-between"><span>Corte y peinado</span><span>S/ 45.00</span></div>
          <div className="flex justify-between"><span>Tratamiento capilar</span><span>S/ 35.00</span></div>
          <div className="flex justify-between border-t border-slate-200 pt-1 font-bold text-brand-ink"><span>Total</span><span>S/ 80.00</span></div>
        </div>
      </div>

      <div data-reveal style={{ ['--d' as string]: '90ms' }} className={card}>
        <Head icon={ClipboardList} title="Fichas y antecedentes" text="La historia de cada cliente o paciente, a mano y segura." />
        <div className="mt-auto rounded-2xl bg-slate-50 border border-slate-100 p-3 flex items-start gap-3" aria-hidden="true">
          <span className="w-9 h-9 rounded-full bg-brand-accent text-white text-xs font-black flex items-center justify-center shrink-0">AR</span>
          <span className="min-w-0 space-y-1.5">
            <span className="block text-xs font-bold text-brand-ink">Ana Rojas · 6 visitas</span>
            <span className="flex flex-wrap gap-1">
              <span className="text-[10px] font-bold rounded-full px-2 py-0.5 bg-rose-50 text-rose-700">Alergia al látex</span>
              <span className="text-[10px] font-bold rounded-full px-2 py-0.5 bg-sky-50 text-sky-700">Prefiere tarde</span>
            </span>
          </span>
        </div>
      </div>

      <div data-reveal style={{ ['--d' as string]: '180ms' }} className={card}>
        <Head icon={Package} title="Paquetes y membresías" text="Vende sesiones por adelantado y planes mensuales." />
        <div className="mt-auto rounded-2xl bg-slate-50 border border-slate-100 p-3 space-y-2" aria-hidden="true">
          <div className="flex justify-between text-xs"><span className="font-bold text-brand-ink">Paquete 6 masajes</span><span className="font-bold text-brand-accent tabular-nums">3 de 6</span></div>
          <div className="grid grid-cols-6 gap-1">
            {Array.from({ length: 6 }, (_, index) => (
              <span key={index} className={`h-2 rounded-full ${index < 3 ? 'bg-brand-accent site-step-line' : 'bg-slate-200'}`} style={index < 3 ? { animationDelay: `${300 + index * 150}ms` } : undefined} />
            ))}
          </div>
        </div>
      </div>

      <div data-reveal className={`${card} sm:col-span-2 lg:col-span-3 lg:flex-row lg:items-center lg:justify-between`}>
        <div className="lg:max-w-md">
          <Head icon={Globe} title="Tu página de reservas" text="Con tus servicios, horarios y redes, lista para compartir en tu Instagram, tu WhatsApp o tu Google." />
        </div>
        <div className="flex flex-col sm:flex-row sm:items-center gap-2" aria-hidden="true">
          <span className="inline-flex items-center gap-2 rounded-xl bg-slate-50 border border-slate-200 px-3 py-2.5 text-xs font-semibold text-slate-600 min-w-0">
            <Link2 className="w-4 h-4 text-brand-accent shrink-0" /> <span className="truncate">…/reservar/<span className="text-brand-ink font-bold">tu-negocio</span></span>
          </span>
          <span className="inline-flex items-center justify-center gap-1.5 rounded-xl bg-brand text-white px-4 py-2.5 text-xs font-bold">Reservar ahora</span>
        </div>
      </div>
    </div>
  );
}
