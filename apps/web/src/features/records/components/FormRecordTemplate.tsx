'use client';

import { useEffect, useState } from 'react';
import { ClipboardList } from 'lucide-react';
import { api } from '@/lib/axios';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Switch from '@/app/components/common/Switch';
import RecordFieldsEditor from './RecordFieldsEditor';
import type { RecordField, RecordTemplate, TemplateService } from '../records';

interface FormRecordTemplateProps {
  isOpen: boolean;
  onClose: () => void;
  initialData: RecordTemplate | null;
  services: TemplateService[];
  templates: RecordTemplate[];
  onSaved: (template: RecordTemplate) => void;
}

const inputClass =
  'w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800 bg-white';
const labelClass = 'block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5';
const blockTitle = 'text-xs font-bold uppercase tracking-wider text-slate-800';

export default function FormRecordTemplate({ isOpen, onClose, initialData, services, templates, onSaved }: FormRecordTemplateProps) {
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [fields, setFields] = useState<RecordField[]>([]);
  const [consentText, setConsentText] = useState('');
  const [requireConsent, setRequireConsent] = useState(false);
  const [serviceIds, setServiceIds] = useState<string[]>([]);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!isOpen) return;

    setError('');
    setName(initialData?.name ?? '');
    setDescription(initialData?.description ?? '');
    setFields((initialData?.fields ?? []).map((field) => ({ ...field })));
    setConsentText(initialData?.consent_text ?? '');
    setRequireConsent(Boolean(initialData?.require_consent));
    setServiceIds([...(initialData?.service_ids ?? [])]);
  }, [isOpen, initialData]);

  const toggleService = (id: string, on: boolean) =>
    setServiceIds((current) => (on ? [...current, id] : current.filter((serviceId) => serviceId !== id)));

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    const payload = {
      name,
      description: description || null,
      fields,
      consent_text: consentText || null,
      require_consent: requireConsent,
      service_ids: serviceIds,
    };

    try {
      setSaving(true);
      setError('');
      const res = initialData
        ? await api.put(`/record-templates/${initialData.id}`, payload)
        : await api.post('/record-templates', payload);
      onSaved(res.data);
      onClose();
    } catch (err: any) {
      const first = Object.values(err?.response?.data?.errors ?? {})[0] as string[] | undefined;
      setError(first?.[0] || err?.response?.data?.message || 'No se pudo guardar la ficha.');
    } finally {
      setSaving(false);
    }
  };

  const formId = 'record-template-form';

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title={initialData ? `Editar ficha: ${initialData.name}` : 'Nueva ficha'}
      subtitle={initialData ? 'Los cambios valen para las fichas que se llenen desde ahora' : 'Define qué se llena al atender'}
      icon={<ClipboardList size={20} />}
      maxWidth="max-w-2xl"
    >
      <div className="flex flex-col h-full justify-between">
        <form id={formId} onSubmit={handleSubmit} className="space-y-6 overflow-y-auto flex-1 pr-1 pb-4">
          {initialData && initialData.records_count > 0 && (
            <p className="text-xs text-slate-600 bg-slate-100 border border-slate-200/70 rounded-lg px-3 py-2">
              Hay {initialData.records_count} {initialData.records_count === 1 ? 'ficha llenada' : 'fichas llenadas'} con esta plantilla.
              No cambian: cada una conserva los campos con los que se llenó.
            </p>
          )}

          <div className="space-y-4">
            <div>
              <label className={labelClass}>Nombre de la ficha *</label>
              <input
                type="text"
                required
                maxLength={150}
                aria-label="Nombre de la ficha"
                placeholder="Ej. Historia clínica"
                className={inputClass}
                value={name}
                onChange={(e) => setName(e.target.value)}
              />
            </div>
            <div>
              <label className={labelClass}>Descripción</label>
              <input
                type="text"
                maxLength={255}
                placeholder="Para qué se usa"
                className={inputClass}
                value={description}
                onChange={(e) => setDescription(e.target.value)}
              />
            </div>
          </div>

          <div className="space-y-3 pt-5 border-t border-slate-100">
            <div>
              <span className={blockTitle}>Campos ({fields.length})</span>
              <span className="block text-xs text-slate-400">Lo que se llena en cada atención, en este orden.</span>
            </div>
            <RecordFieldsEditor value={fields} onChange={setFields} />
          </div>

          <div className="space-y-3 pt-5 border-t border-slate-100">
            <div>
              <span className={blockTitle}>Consentimiento informado</span>
              <span className="block text-xs text-slate-400">Texto que el cliente acepta. Déjalo vacío si no aplica.</span>
            </div>
            <textarea
              rows={3}
              maxLength={5000}
              aria-label="Texto del consentimiento"
              className={inputClass}
              value={consentText}
              onChange={(e) => setConsentText(e.target.value)}
            />
            <label className="flex items-center justify-between gap-3 text-sm font-semibold text-slate-700">
              Sin aceptarlo no se puede firmar la ficha
              <Switch checked={requireConsent} onChange={setRequireConsent} label="El consentimiento es obligatorio" />
            </label>
          </div>

          <div className="space-y-3 pt-5 border-t border-slate-100">
            <div>
              <span className={blockTitle}>Servicios que usan esta ficha ({serviceIds.length})</span>
              <span className="block text-xs text-slate-400">Al atender uno de estos servicios se llena esta ficha. Un servicio usa una sola.</span>
            </div>

            {services.length === 0 ? (
              <p className="text-sm text-slate-400">Aún no tienes servicios.</p>
            ) : (
              <div className="border border-slate-200 rounded-xl divide-y divide-slate-100" data-services>
                {services.map((service) => {
                  const isOn = serviceIds.includes(service.id);
                  const other = service.template_id && service.template_id !== initialData?.id
                    ? templates.find((t) => t.id === service.template_id)
                    : null;

                  return (
                    <label key={service.id} data-service={service.id} className="flex items-center justify-between gap-3 px-4 py-2.5 cursor-pointer">
                      <span>
                        <span className={`block text-sm font-semibold ${isOn ? 'text-slate-800' : 'text-slate-500'}`}>{service.name}</span>
                        <span className="block text-xs text-slate-400">
                          {other ? (isOn ? `Dejará de usar "${other.name}"` : `Hoy usa "${other.name}"`) : service.category ?? ''}
                        </span>
                      </span>
                      <Switch checked={isOn} onChange={(on) => toggleService(service.id, on)} label={`${service.name} usa esta ficha`} />
                    </label>
                  );
                })}
              </div>
            )}
          </div>

          {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error}</p>}
        </form>

        <div className="pt-4 border-t border-slate-100 flex items-center justify-end gap-3 bg-white sticky bottom-0 py-3 mt-auto">
          <button
            type="button"
            onClick={onClose}
            className="px-4 py-2.5 text-sm font-medium border border-slate-200 rounded-lg text-slate-600 hover:bg-slate-50 transition-colors"
          >
            Cancelar
          </button>
          <button
            type="submit"
            form={formId}
            disabled={saving}
            className="px-5 py-2.5 text-sm font-medium bg-[#022340] text-white rounded-lg hover:bg-[#03315a] shadow-sm transition-colors disabled:opacity-50"
          >
            {saving ? 'Guardando...' : initialData ? 'Guardar cambios' : 'Crear ficha'}
          </button>
        </div>
      </div>
    </FormDrawer>
  );
}
