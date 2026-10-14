'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import {
  ArrowLeft, Building2, CalendarClock, Check, ClipboardList, Clock, FileText, FolderOpen, Globe,
  HandCoins, Layers, Loader2, Minus, PawPrint, Pencil, Puzzle, SlidersHorizontal, Type, Users, Video
} from 'lucide-react';
import { api } from '@/lib/axios';
import { CORE_MODULE_LABELS, MODULE_GROUPS, OPTIONAL_MODULE_KEYS } from '@/lib/modules';
import { RULE_GROUPS } from '@/lib/rules';
import FormBusinessType, { type BusinessType } from './FormBusinessType';
import BusinessTypeTenants from './BusinessTypeTenants';
import RecordPreview from './RecordPreview';
import { parseTemplate, type TemplateService } from './TemplateEditor';

const VOCABULARY: { key: string; label: string; fallback: string }[] = [
  { key: 'client', label: 'Cliente', fallback: 'Cliente' },
  { key: 'clients', label: 'Clientes', fallback: 'Clientes' },
  { key: 'pro', label: 'Profesional', fallback: 'Profesional' },
  { key: 'pros', label: 'Profesionales', fallback: 'Profesionales' },
];

const DEPENDENT_KINDS: Record<string, string> = { pet: 'Mascotas', child: 'Hijos o familiares' };

const cardClass = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 space-y-4';

function SectionTitle({ icon, title, aside }: { icon: React.ReactNode; title: string; aside?: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-3">
      <h2 className="flex items-center gap-2 text-sm font-extrabold text-[#022340] uppercase tracking-wider">
        <span className="text-[#3F768C]">{icon}</span> {title}
      </h2>
      {aside}
    </div>
  );
}

function ServiceFlags({ service }: { service: TemplateService }) {
  const flags = [
    service.online_booking !== false && { icon: <Globe size={12} />, label: 'Reserva en línea' },
    service.requires_deposit && { icon: <HandCoins size={12} />, label: 'Exige adelanto' },
    service.is_virtual && { icon: <Video size={12} />, label: 'Videollamada' },
    service.uses_record && { icon: <FileText size={12} />, label: 'Con ficha' },
    service.kind === 'group' && { icon: <Users size={12} />, label: `Clase · ${service.capacity} cupos` },
  ].filter(Boolean) as { icon: React.ReactNode; label: string }[];

  return (
    <div className="flex flex-wrap gap-1">
      {flags.map((flag) => (
        <span key={flag.label} title={flag.label} className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md bg-slate-100 text-slate-500 text-[10px] font-medium">
          {flag.icon} {flag.label}
        </span>
      ))}
    </div>
  );
}

export default function BusinessTypeDetail() {
  const params = useParams<{ id: string }>();
  const [type, setType] = useState<BusinessType | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [version, setVersion] = useState(0);

  const load = () => {
    api.get(`/admin/business-types/${params.id}`)
      .then((res) => { setType(res.data); setError(null); })
      .catch((err) => setError(err.response?.status === 404 ? 'Este rubro no existe.' : 'No se pudo cargar el rubro.'))
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    load();
  }, [params.id]);

  if (loading) {
    return (
      <div className="min-h-[60vh] flex items-center justify-center">
        <Loader2 className="w-8 h-8 text-[#3F768C] animate-spin" />
      </div>
    );
  }

  if (!type) {
    return (
      <div className="max-w-2xl mx-auto pt-10 text-center space-y-3">
        <p className="text-sm text-slate-500 font-medium">{error}</p>
        <Link href="/admin/business-types" className="inline-block text-xs font-bold text-[#3F768C] hover:text-[#022340]">Volver a Plantillas de rubros</Link>
      </div>
    );
  }

  const color = type.color || '#3C7DC4';
  const modulesOff = type.modules_off ?? [];
  const enabledModules = OPTIONAL_MODULE_KEYS.length - modulesOff.filter((key) => OPTIONAL_MODULE_KEYS.includes(key)).length;
  const template = parseTemplate(type.template);
  const totalServices = template.categories.reduce((total, category) => total + category.services.length, 0);
  const rules = type.default_rules ?? {};
  const definedRules = RULE_GROUPS
    .map((group) => ({ ...group, rules: group.rules.filter((rule) => rules[rule.key] !== undefined) }))
    .filter((group) => group.rules.length > 0);
  const dependents = type.dependents?.enabled ? type.dependents : null;

  return (
    <div className="space-y-5 pb-10 font-sans text-[#1F2937]">
      <nav className="flex items-center gap-2 text-sm">
        <Link
          href="/admin/business-types"
          className="inline-flex items-center gap-2 pl-2.5 pr-3.5 py-2 rounded-xl bg-white border border-slate-200 font-bold text-[#022340] hover:bg-slate-50 hover:border-slate-300 shadow-xs transition-colors"
        >
          <ArrowLeft size={16} /> Plantillas de rubros
        </Link>
        <span className="text-slate-300">/</span>
        <span className="font-semibold text-slate-500 truncate">{type.name}</span>
      </nav>

      <div className="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
        <div className="h-1.5" style={{ backgroundColor: color }} />
        <div className="p-6 flex flex-wrap items-start justify-between gap-4">
          <div className="flex items-start gap-4 min-w-0">
            <span className="p-3.5 rounded-2xl shrink-0" style={{ backgroundColor: `${color}1A`, color }}>
              <Layers className="w-7 h-7" />
            </span>
            <div className="min-w-0 space-y-1">
              <div className="flex flex-wrap items-center gap-2">
                <h1 className="text-2xl font-black text-[#022340] tracking-tight">{type.name}</h1>
                <span className={`px-2 py-0.5 text-xs rounded-full font-medium ${type.status === 'active' ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-500'}`}>
                  {type.status === 'active' ? 'Activo' : 'Inactivo'}
                </span>
              </div>
              <p className="text-xs text-slate-400 font-mono">slug: {type.slug} · nombre corto: {type.short_name}</p>
              {type.description && <p className="text-sm text-slate-600 max-w-2xl pt-1">{type.description}</p>}
            </div>
          </div>
          <button
            type="button"
            onClick={() => setShowForm(true)}
            className="px-4 py-2.5 text-sm font-bold bg-[#022340] text-white rounded-xl hover:bg-[#03315a] shadow-sm transition-colors flex items-center gap-2 cursor-pointer"
          >
            <Pencil size={15} /> Editar rubro
          </button>
        </div>

        <div className="grid grid-cols-2 md:grid-cols-4 divide-x divide-slate-100 border-t border-slate-100 bg-slate-50/60">
          {[
            { icon: <Building2 size={15} />, value: type.tenants_count ?? 0, label: (type.tenants_count ?? 0) === 1 ? 'empresa lo usa' : 'empresas lo usan' },
            { icon: <Puzzle size={15} />, value: `${enabledModules} de ${OPTIONAL_MODULE_KEYS.length}`, label: 'módulos encendidos' },
            { icon: <FolderOpen size={15} />, value: totalServices, label: `servicios en ${template.categories.length} categorías` },
            { icon: <ClipboardList size={15} />, value: template.record_template ? template.record_template.fields.length : '—', label: template.record_template ? 'campos en la ficha' : 'sin ficha de atención' },
          ].map((stat) => (
            <div key={stat.label} className="px-6 py-4">
              <span className="flex items-center gap-2 text-xl font-black text-[#022340]">
                <span className="text-[#3F768C]">{stat.icon}</span> {stat.value}
              </span>
              <span className="block text-xs text-slate-500 font-medium">{stat.label}</span>
            </div>
          ))}
        </div>
      </div>

      <BusinessTypeTenants
        businessTypeId={type.id}
        businessTypeName={type.name}
        refreshKey={version}
        onApplied={load}
      />

      <div className="grid grid-cols-1 xl:grid-cols-5 gap-6 items-start">
        <div className="xl:col-span-3 space-y-6">
          <section className={cardClass}>
            <SectionTitle icon={<Type size={16} />} title="Vocabulario" />
            <p className="text-xs text-slate-500">Así se nombra a las personas en el panel de una empresa de este rubro.</p>
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
              {VOCABULARY.map((word) => (
                <div key={word.key} className="rounded-xl border border-slate-200 px-3.5 py-3">
                  <span className="block text-[10px] font-bold uppercase tracking-wider text-slate-400">{word.label}</span>
                  <span className="block text-sm font-bold text-slate-800">{type.vocabulary?.[word.key] || word.fallback}</span>
                </div>
              ))}
            </div>
            <div className={`flex items-center gap-3 rounded-xl border px-3.5 py-3 ${dependents ? 'border-amber-200 bg-amber-50/60' : 'border-slate-200 bg-slate-50'}`}>
              <PawPrint size={18} className={dependents ? 'text-amber-600' : 'text-slate-300'} />
              {dependents ? (
                <p className="text-xs text-slate-700">
                  El cliente tiene <span className="font-bold">{(type.vocabulary?.dependents || DEPENDENT_KINDS[dependents.kind ?? ''] || 'dependientes').toLowerCase()}</span>.
                  {dependents.required ? ' Cada cita es para uno de ellos.' : ' Son opcionales en la cita.'}
                </p>
              ) : (
                <p className="text-xs text-slate-500">Este rubro no usa dependientes (mascotas, hijos).</p>
              )}
            </div>
          </section>

          <section className={cardClass}>
            <SectionTitle
              icon={<Puzzle size={16} />}
              title="Módulos"
              aside={<span className="px-2.5 py-1 rounded-lg bg-emerald-50 border border-emerald-100 text-xs font-bold text-emerald-700">{enabledModules} de {OPTIONAL_MODULE_KEYS.length} encendidos</span>}
            />
            <p className="text-xs text-slate-500">
              Una empresa recibe los módulos encendidos que su plan incluya. {Object.values(CORE_MODULE_LABELS).join(' y ')} siempre están.
            </p>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-5">
              {MODULE_GROUPS.map((group) => (
                <div key={group.key} className="space-y-2">
                  <span className="block text-[11px] font-bold uppercase tracking-wider text-slate-400">{group.title}</span>
                  <ul className="space-y-1.5">
                    {group.items.map((item) => {
                      const isOn = !modulesOff.includes(item.key);
                      return (
                        <li key={item.key} className={`flex items-center gap-2 text-sm ${isOn ? 'text-slate-700' : 'text-slate-400 line-through decoration-slate-300'}`}>
                          {isOn ? <Check size={15} className="text-emerald-500 shrink-0" /> : <Minus size={15} className="text-slate-300 shrink-0" />}
                          {item.label}
                        </li>
                      );
                    })}
                  </ul>
                </div>
              ))}
            </div>
          </section>

          <section className={cardClass}>
            <SectionTitle icon={<SlidersHorizontal size={16} />} title="Reglas por defecto" />
            {definedRules.length === 0 ? (
              <p className="text-xs text-slate-500">Este rubro no define reglas: sus empresas empiezan con el comportamiento estándar.</p>
            ) : (
              <div className="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-5">
                {definedRules.map((group) => (
                  <div key={group.title} className="space-y-2">
                    <span className="block text-[11px] font-bold uppercase tracking-wider text-slate-400">{group.title}</span>
                    <ul className="space-y-2">
                      {group.rules.map((rule) => {
                        const current = rules[rule.key];
                        return (
                          <li key={rule.key} className="flex items-center justify-between gap-3 text-sm">
                            <span className="text-slate-700">{rule.label}</span>
                            {rule.type === 'boolean' ? (
                              <span className={`px-2 py-0.5 rounded-md text-xs font-bold shrink-0 ${current === true ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}>
                                {current === true ? 'Sí' : 'No'}
                              </span>
                            ) : (
                              <span className="px-2 py-0.5 rounded-md bg-[#022340]/5 text-xs font-bold text-[#022340] shrink-0">{String(current)} {rule.unit}</span>
                            )}
                          </li>
                        );
                      })}
                    </ul>
                  </div>
                ))}
              </div>
            )}
          </section>

          <section className={cardClass}>
            <SectionTitle
              icon={<FolderOpen size={16} />}
              title="Catálogo inicial"
              aside={<span className="text-xs font-medium text-slate-400">{template.categories.length} categorías · {totalServices} servicios</span>}
            />
            <p className="text-xs text-slate-500">Lo que recibe una empresa al registrarse con este rubro. Después lo ajusta a su manera.</p>

            {template.categories.length === 0 && (
              <p className="text-xs text-slate-400 italic">Sin catálogo: la empresa empieza sin servicios.</p>
            )}

            {template.categories.map((category) => (
              <div key={category.name} className="rounded-xl border border-slate-200 overflow-hidden">
                <div className="flex items-center justify-between px-4 py-2.5 bg-slate-50/85 border-b border-slate-200">
                  <span className="text-sm font-bold text-slate-800">{category.name}</span>
                  <span className="text-[11px] font-medium text-slate-400">{category.services.length} servicios</span>
                </div>
                <ul className="divide-y divide-slate-100">
                  {category.services.map((service) => (
                    <li key={service.code || service.name} className="px-4 py-3 flex items-start justify-between gap-4">
                      <div className="min-w-0 space-y-1.5">
                        <div className="flex items-baseline gap-2">
                          <span className="text-sm font-semibold text-slate-800">{service.name}</span>
                          {service.code && <span className="text-[11px] font-mono text-slate-400">{service.code}</span>}
                        </div>
                        <ServiceFlags service={service} />
                        {service.recall?.label && (
                          <span className="flex items-center gap-1.5 text-[11px] text-amber-700">
                            <CalendarClock size={12} /> Control: {service.recall.label} en {service.recall.days} días
                          </span>
                        )}
                      </div>
                      <div className="text-right shrink-0">
                        <span className="block text-sm font-bold text-slate-800">S/ {Number(service.price || 0).toFixed(2)}</span>
                        <span className="flex items-center justify-end gap-1 text-[11px] text-slate-400">
                          <Clock size={11} /> {service.duration_minutes} min
                          {service.buffer_after_minutes ? ` + ${service.buffer_after_minutes}` : ''}
                        </span>
                      </div>
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </section>
        </div>

        <div className="xl:col-span-2 space-y-3">
          {template.record_template ? (
            <RecordPreview record={template.record_template} color={color} businessTypeName={type.name} />
          ) : (
            <div className="bg-white rounded-2xl border border-dashed border-slate-300 p-8 text-center space-y-2">
              <ClipboardList size={28} className="mx-auto text-slate-300" />
              <p className="text-sm font-semibold text-slate-600">Sin ficha de atención</p>
              <p className="text-xs text-slate-400">Las empresas de este rubro empiezan sin una ficha que llenar al atender.</p>
            </div>
          )}
        </div>
      </div>

      <FormBusinessType
        isOpen={showForm}
        onClose={() => setShowForm(false)}
        initialData={type}
        onSubmitSuccess={() => { load(); setVersion((v) => v + 1); }}
      />
    </div>
  );
}
