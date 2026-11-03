'use client';

import { useCallback, useEffect, useState } from 'react';
import { Columns3, History } from 'lucide-react';
import Select from '@/app/components/common/Select';
import { ErrorAlert } from '@/app/components/common/Alert';
import { UserLink } from '@/app/components/common/PersonLink';
import { PagedList } from '@/app/components/common/Pager';
import { api } from '@/lib/axios';
import { apiError, dateOf, formatDate, timeOf } from '@/features/appointments/appointments';
import { AUTO_STAGE, STAGE_SOURCE, stageOf, type BoardInfo, type StageChange } from '../board';

interface StageData extends BoardInfo {
  stage_id: string | null;
  manual: boolean | null;
  changes: StageChange[];
}

const smallLabel = 'text-[11px] font-bold text-slate-400 uppercase tracking-wider';

const when = (local: string) => `${formatDate(dateOf(local), { day: 'numeric', month: 'short', year: 'numeric' })}, ${timeOf(local)}`;

export default function CustomerStage({ customerId, refreshKey, onChanged }: { customerId: string; refreshKey?: number; onChanged?: () => void }) {
  const [data, setData] = useState<StageData | null>(null);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  const load = useCallback(() => {
    api.get(`/customers/${customerId}/stage-changes`)
      .then((res) => { setData(res.data); setError(''); })
      .catch(() => setError('No se pudo cargar su etapa.'));
  }, [customerId]);

  useEffect(() => { load(); }, [load, refreshKey]);

  if (!data && !error) return null;

  if (data && !data.ready) {
    return <p className="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">{data.message}</p>;
  }

  const stage = data ? stageOf(data, data.stage_id) : undefined;

  const move = (stageId: string) => {
    setSaving(true);
    api.put(`/customers/${customerId}/stage`, { stage_id: stageId, source: 'detail' })
      .then(() => { load(); onChanged?.(); })
      .catch((err) => setError(apiError(err, 'No se pudo cambiar su etapa.')))
      .finally(() => setSaving(false));
  };

  return (
    <div className="pt-4 border-t border-slate-100 space-y-3" data-card="stage">
      <span className={`flex items-center gap-1.5 ${smallLabel}`}><Columns3 size={13} /> Etapa en el tablero</span>

      {stage && (
        <div className="rounded-xl border border-slate-200 px-3 py-2.5" style={{ borderLeft: `4px solid ${stage.color}` }} data-current-stage={stage.name}>
          <span className="block text-sm font-bold" style={{ color: stage.color }}>{stage.name}</span>
          {stage.description && <span className="block text-xs text-slate-500">{stage.description}</span>}
          <span className="block text-xs text-slate-500 mt-1">
            {data?.changes[0]
              ? <>Desde el {when(data.changes[0].created_at)}{data.changes[0].by ? <>, por <UserLink id={data.changes[0].by.id} className="font-semibold">{data.changes[0].by.name}</UserLink></> : data.changes[0].source === 'auto' ? ', solo según sus citas' : ''}</>
              : 'Nadie la ha cambiado todavía.'}
          </span>
          {data?.manual !== null && data?.manual !== undefined && (
            <span className={`block text-xs mt-1 font-semibold ${data.manual ? 'text-amber-700' : 'text-slate-500'}`} data-stage-mode={data.manual ? 'manual' : 'auto'}>
              {data.manual ? 'Movido a mano: se queda aquí hasta su próxima cita.' : 'Se ubica solo según sus citas.'}
            </span>
          )}
        </div>
      )}

      {data?.can_move && data.manual && (
        <button type="button" data-stage-release disabled={saving} onClick={() => move(AUTO_STAGE)} className="text-xs font-semibold text-brand-ink hover:text-brand-accent underline underline-offset-2 disabled:opacity-50">
          Que se ubique solo desde ahora
        </button>
      )}

      {data?.can_move && data.stages.length > 1 && (
        <Select
          ariaLabel="Mover a otra etapa"
          placeholder={saving ? 'Guardando...' : 'Mover a…'}
          clearable={false}
          disabled={saving}
          value=""
          onChange={(e) => { if (e.target.value) move(String(e.target.value)); }}
          options={data.stages.filter((item) => item.id !== stage?.id).map((item) => ({ value: item.id, label: item.name }))}
        />
      )}

      <ErrorAlert message={error} />

      {data && data.changes.length > 0 && (
        <div className="space-y-1" data-stage-history>
          <span className={`flex items-center gap-1.5 ${smallLabel}`}><History size={13} /> Cambios de etapa</span>
          <div className="-mx-2 flex flex-col">
            <PagedList
              items={data.changes}
              empty=""
              renderRow={(change) => (
                <li key={change.id} className="px-2 py-2 text-xs text-slate-600 border-b border-slate-100 last:border-0">
                  <span className="block font-semibold text-slate-800">{change.from ?? 'Sin etapa'} → {change.to ?? 'Sin etapa'}</span>
                  <span className="block text-slate-500">
                    {when(change.created_at)} · {change.by ? <><UserLink id={change.by.id} className="font-semibold">{change.by.name}</UserLink>, </> : ''}
                    {STAGE_SOURCE[change.source] ?? ''}
                  </span>
                </li>
              )}
            />
          </div>
        </div>
      )}
    </div>
  );
}
