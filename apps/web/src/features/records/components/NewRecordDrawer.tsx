'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { ClipboardPlus } from 'lucide-react';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Button from '@/app/components/common/Button';
import Select from '@/app/components/common/Select';
import { ErrorAlert } from '@/app/components/common/Alert';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { apiError } from '@/features/appointments/appointments';

interface NewRecordDrawerProps {
  isOpen: boolean;
  customerId: string;
  customerName: string;
  dependents?: { id: string; name: string }[];
  dependentWord?: string;
  onClose: () => void;
  onCreated: (recordId: string) => void;
}

export default function NewRecordDrawer({ isOpen, customerId, customerName, dependents = [], dependentWord = 'Dependiente', onClose, onCreated }: NewRecordDrawerProps) {
  const { can } = useAuth();
  const [templates, setTemplates] = useState<{ id: string; name: string; description: string | null }[] | null>(null);
  const [templateId, setTemplateId] = useState('');
  const [dependentId, setDependentId] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!isOpen) return;
    setError('');
    setDependentId(dependents.length === 1 ? dependents[0].id : '');
    api.get('/record-sheet-templates')
      .then((res) => { setTemplates(res.data.templates ?? []); setTemplateId(res.data.templates?.[0]?.id ?? ''); })
      .catch((err) => { setTemplates([]); setError(apiError(err, 'No se pudieron cargar las plantillas.')); });
  }, [isOpen, dependents]);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      setSaving(true);
      setError('');
      const res = await api.post('/record-sheets', { customer_id: customerId, record_template_id: templateId, customer_dependent_id: dependentId || null });
      onCreated(res.data.id);
      onClose();
    } catch (err) {
      setError(apiError(err, 'No se pudo abrir la ficha.'));
    } finally {
      setSaving(false);
    }
  };

  const chosen = templates?.find((t) => t.id === templateId);

  return (
    <FormDrawer isOpen={isOpen} onClose={onClose} title="Nueva ficha sin cita" subtitle={`Para ${customerName}: una evaluación, un control o una atención que no se agendó.`} icon={<ClipboardPlus size={20} />}>
      <form onSubmit={submit} className="space-y-4" data-new-record>
        <ErrorAlert message={error} />

        {templates !== null && templates.length === 0 ? (
          <p className="text-sm text-slate-600">
            Tu empresa aún no tiene plantillas de ficha activas.
            {can('record_templates.create') && <> Créala en <Link href="/records/templates" className="font-semibold text-brand-accent hover:text-brand-ink">Plantillas de ficha</Link>.</>}
          </p>
        ) : (
          <>
            <Select
              label="Ficha que vas a llenar *"
              value={templateId}
              clearable={false}
              onChange={(e) => setTemplateId(String(e.target.value))}
              options={(templates ?? []).map((t) => ({ value: t.id, label: t.name }))}
            />
            {chosen?.description && <p className="-mt-2 text-xs text-slate-500">{chosen.description}</p>}

            {dependents.length > 0 && (
              <Select
                label={dependentWord}
                value={dependentId}
                onChange={(e) => setDependentId(String(e.target.value))}
                options={[{ value: '', label: `Para ${customerName}` }, ...dependents.map((d) => ({ value: d.id, label: d.name }))]}
              />
            )}

            <p className="text-xs text-slate-500">Se abre como borrador. La llenas, la firmas y queda en su historia con la fecha de hoy, igual que las fichas de las citas.</p>
          </>
        )}

        <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
          <Button type="button" variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button type="submit" variant="primary" disabled={saving || !templateId}>{saving ? 'Abriendo...' : 'Abrir ficha'}</Button>
        </div>
      </form>
    </FormDrawer>
  );
}
