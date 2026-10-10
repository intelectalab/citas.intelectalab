'use client';

import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { ArrowLeft, ClipboardList, Loader2, Pencil, Plus, Trash2 } from 'lucide-react';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import RecordPreview from '@/features/business-types/components/RecordPreview';
import FormRecordTemplate from './FormRecordTemplate';
import RecordSheetEditor from './RecordSheetEditor';
import type { RecordTemplate, TemplateService } from '../records';

// Las fichas de la empresa: cada una con sus campos y los servicios que la
// usan. A la derecha, cómo se ve la ficha elegida. Solo el administrador de la
// empresa las cambia; el resto del personal las consulta.
export default function RecordTemplates() {
  const { user } = useAuth();
  const [templates, setTemplates] = useState<RecordTemplate[]>([]);
  const [services, setServices] = useState<TemplateService[]>([]);
  const [canEdit, setCanEdit] = useState(false);
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<RecordTemplate | null>(null);
  const [deleting, setDeleting] = useState<RecordTemplate | null>(null);
  const [isDeleting, setIsDeleting] = useState(false);
  // La hoja de la derecha tiene cambios sin guardar.
  const [sheetDirty, setSheetDirty] = useState(false);

  // Con cambios sin guardar en la hoja no se pasa a otra ficha: se perderían.
  const guard = (action: () => void) => {
    if (sheetDirty) {
      setError('Tienes cambios sin guardar en la ficha. Guárdalos o descártalos antes de continuar.');
      return;
    }
    setError('');
    action();
  };

  const load = useCallback((selectId?: string) => {
    api.get('/record-templates')
      .then((res) => {
        const list: RecordTemplate[] = res.data.templates ?? [];
        setTemplates(list);
        setServices(res.data.services ?? []);
        setCanEdit(Boolean(res.data.can_edit));
        setSelectedId((current) => selectId ?? (list.some((t) => t.id === current) ? current : list[0]?.id ?? null));
        setError('');
      })
      .catch((err) => setError(err?.response?.data?.message || 'No se pudieron cargar las fichas.'))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => { load(); }, [load]);

  const selected = templates.find((t) => t.id === selectedId) ?? null;
  const serviceNames = (template: RecordTemplate) => services.filter((s) => template.service_ids.includes(s.id)).map((s) => s.name);
  const withoutRecord = services.filter((s) => !s.template_id).length;

  const confirmDelete = async () => {
    if (!deleting) return;

    try {
      setIsDeleting(true);
      await api.delete(`/record-templates/${deleting.id}`);
      setDeleting(null);
      load();
    } catch (err: any) {
      setError(err?.response?.data?.message || 'No se pudo eliminar la ficha.');
      setDeleting(null);
    } finally {
      setIsDeleting(false);
    }
  };

  if (loading) {
    return (
      <div className="min-h-[60vh] flex items-center justify-center">
        <Loader2 className="w-8 h-8 text-[#3F768C] animate-spin" />
      </div>
    );
  }

  return (
    <div className="space-y-5 pb-8 font-sans">
      <Link href="/records" className="inline-flex items-center gap-2 text-base font-bold text-[#3F768C] hover:text-[#022340] transition-colors">
        <ArrowLeft size={18} /> Fichas de atención
      </Link>

      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-black text-[#022340] tracking-tight">Plantillas de ficha</h1>
          <p className="text-sm text-slate-500 font-medium">
            Lo que tu equipo llena al atender. {canEdit ? 'Edita la ficha directamente sobre la hoja de la derecha: las fichas ya llenadas no cambian.' : 'Las define el administrador de tu empresa.'}
          </p>
        </div>
        {canEdit && (
          <button
            type="button"
            data-action="new-template"
            onClick={() => guard(() => { setEditing(null); setFormOpen(true); })}
            className="flex items-center gap-2 px-4 py-2 text-sm font-bold bg-[#022340] text-white rounded-lg hover:bg-[#03315a] shadow-sm transition-colors"
          >
            <Plus size={16} className="text-[#84D0D9] stroke-[2.5]" /> Nueva ficha
          </button>
        )}
      </div>

      {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error}</p>}

      {templates.length === 0 ? (
        <div className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-10 text-center space-y-2">
          <ClipboardList className="w-8 h-8 text-slate-300 mx-auto" />
          <p className="text-sm font-semibold text-slate-700">Tu empresa aún no tiene fichas.</p>
          <p className="text-sm text-slate-500">{canEdit ? 'Crea la primera con "Nueva ficha".' : 'Pídele al administrador de tu empresa que cree una.'}</p>
        </div>
      ) : (
        <div className="grid grid-cols-1 xl:grid-cols-5 gap-5 items-start">
          <div className="xl:col-span-2 space-y-3">
            {templates.map((template) => {
              const names = serviceNames(template);
              const isSelected = template.id === selectedId;

              return (
                <div
                  key={template.id}
                  data-template={template.name}
                  onClick={() => { if (template.id !== selectedId) guard(() => setSelectedId(template.id)); }}
                  className={`bg-white rounded-2xl border p-5 space-y-3 cursor-pointer transition-all ${
                    isSelected ? 'border-[#3F768C] ring-2 ring-[#3F768C]/20 shadow-sm' : 'border-slate-200/80 shadow-xs hover:border-slate-300'
                  }`}
                >
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <h2 className="text-base font-extrabold text-[#022340] truncate">{template.name}</h2>
                      {template.description && <p className="text-xs text-slate-500 mt-0.5">{template.description}</p>}
                    </div>
                    {canEdit && (
                      <div className="flex items-center gap-1 shrink-0">
                        <button
                          type="button"
                          title={`Servicios y descripción de ${template.name}`}
                          onClick={(e) => { e.stopPropagation(); guard(() => { setEditing(template); setFormOpen(true); }); }}
                          className="p-1.5 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
                        >
                          <Pencil size={16} />
                        </button>
                        <button
                          type="button"
                          title={`Eliminar ${template.name}`}
                          onClick={(e) => { e.stopPropagation(); guard(() => setDeleting(template)); }}
                          className="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                        >
                          <Trash2 size={16} />
                        </button>
                      </div>
                    )}
                  </div>

                  <div className="grid grid-cols-3 gap-2 text-center">
                    {[
                      [template.fields.length, template.fields.length === 1 ? 'campo' : 'campos'],
                      [template.service_ids.length, template.service_ids.length === 1 ? 'servicio' : 'servicios'],
                      [template.records_count, template.records_count === 1 ? 'ficha llenada' : 'fichas llenadas'],
                    ].map(([value, label]) => (
                      <div key={String(label)} className="bg-slate-50 border border-slate-100 rounded-xl py-2">
                        <span className="block text-lg font-extrabold text-slate-900">{value}</span>
                        <span className="block text-[11px] font-medium text-slate-500">{label}</span>
                      </div>
                    ))}
                  </div>

                  <div className="flex flex-wrap gap-1">
                    {names.length === 0 ? (
                      <span className="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-2 py-1">Ningún servicio la usa todavía</span>
                    ) : (
                      names.map((name) => (
                        <span key={name} className="px-2 py-0.5 text-xs font-medium bg-sky-50 text-sky-700 rounded-md border border-sky-100">{name}</span>
                      ))
                    )}
                  </div>
                </div>
              );
            })}

            {withoutRecord > 0 && (
              <p className="text-xs text-slate-500 px-1">
                {withoutRecord} {withoutRecord === 1 ? 'servicio se atiende' : 'servicios se atienden'} sin ficha.
              </p>
            )}
          </div>

          <div className="xl:col-span-3" data-preview>
            {selected && canEdit && (
              <RecordSheetEditor
                template={selected}
                color={user?.tenant?.color || '#022340'}
                companyName={user?.tenant?.name ?? ''}
                onSaved={(template) => load(template.id)}
                onDirtyChange={setSheetDirty}
              />
            )}
            {selected && !canEdit && (
              <RecordPreview
                record={{ name: selected.name, consent_text: selected.consent_text, require_consent: selected.require_consent, fields: selected.fields }}
                color={user?.tenant?.color || '#022340'}
                businessTypeName={user?.tenant?.name ?? ''}
              />
            )}
          </div>
        </div>
      )}

      <FormRecordTemplate
        isOpen={formOpen}
        onClose={() => setFormOpen(false)}
        initialData={editing}
        services={services}
        templates={templates}
        onSaved={(template) => load(template.id)}
      />

      <AppModal
        isOpen={deleting !== null}
        type="confirm"
        variant="danger"
        title={`¿Eliminar la ficha "${deleting?.name ?? ''}"?`}
        message={deleting && deleting.records_count > 0
          ? `Sus servicios pasarán a atenderse sin ficha. Las ${deleting.records_count} fichas ya llenadas se conservan en la historia de cada cliente.`
          : 'Sus servicios pasarán a atenderse sin ficha.'}
        confirmText="Sí, eliminar"
        cancelText="Cancelar"
        isLoading={isDeleting}
        onConfirm={confirmDelete}
        onClose={() => setDeleting(null)}
      />
    </div>
  );
}
