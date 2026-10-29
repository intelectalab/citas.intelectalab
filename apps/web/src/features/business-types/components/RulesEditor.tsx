'use client';

import Switch from '@/app/components/common/Switch';
import { RULE_GROUPS, type RuleDefinition, type RuleValue } from '@/lib/rules';

interface RulesEditorProps {
  value: Record<string, RuleValue>;
  onChange: (value: Record<string, RuleValue>) => void;
}

export default function RulesEditor({ value, onChange }: RulesEditorProps) {
  const setRule = (key: string, next: RuleValue | undefined) => {
    const updated = { ...value };
    if (next === undefined) {
      delete updated[key];
    } else {
      updated[key] = next;
    }
    onChange(updated);
  };

  const renderControl = (rule: RuleDefinition) => {
    const current = value[rule.key];

    if (rule.type === 'boolean') {
      return <Switch checked={current === true} onChange={(checked) => setRule(rule.key, checked)} label={rule.label} />;
    }

    return (
      <div className="flex items-stretch shrink-0 border border-slate-200 rounded-lg overflow-hidden bg-white focus-within:ring-2 focus-within:ring-brand/20 focus-within:border-brand">
        <input
          type="number"
          min="0"
          aria-label={rule.label}
          value={current === undefined ? '' : String(current)}
          onChange={(e) => setRule(rule.key, e.target.value === '' ? undefined : Number(e.target.value))}
          className="w-16 text-sm text-right px-2 py-1.5 text-slate-800 outline-none bg-transparent placeholder:text-slate-300"
          placeholder="—"
        />
        <span className="flex items-center px-2 text-xs font-medium text-slate-500 bg-slate-50 border-l border-slate-200 min-w-[3.25rem] justify-center">
          {rule.unit}
        </span>
      </div>
    );
  };

  const activeRules = RULE_GROUPS.flatMap((group) => group.rules).filter((rule) =>
    rule.type === 'boolean' ? value[rule.key] === true : value[rule.key] !== undefined
  );

  return (
    <div className="space-y-5">
      <p className="text-xs text-slate-500">
        Cómo trabaja una empresa de este rubro desde el primer día: adelantos, cancelaciones, fichas, propinas.
        Cada empresa puede cambiarlo después. Un número vacío significa que esa regla no aplica.
      </p>

      <div className="rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-3 space-y-2">
        <span className="block text-[11px] font-bold uppercase tracking-wider text-slate-500">
          {activeRules.length === 0 ? 'Este rubro no tiene reglas activas' : `Activas en este rubro (${activeRules.length})`}
        </span>
        {activeRules.length === 0 ? (
          <span className="block text-xs text-slate-400">Enciende un interruptor o escribe un número en las listas de abajo.</span>
        ) : (
          <div className="flex flex-wrap gap-1.5">
            {activeRules.map((rule) => (
              <span key={rule.key} className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-white border border-emerald-200 text-xs font-medium text-slate-700">
                <span className="w-1.5 h-1.5 rounded-full bg-emerald-500" />
                {rule.label}
                {rule.type === 'number' && <span className="font-bold text-brand-ink">{String(value[rule.key])} {rule.unit}</span>}
              </span>
            ))}
          </div>
        )}
      </div>

      {RULE_GROUPS.map((group) => (
        <div key={group.title} className="space-y-1">
          <span className="block text-[11px] font-bold uppercase tracking-wider text-slate-400 pb-1">{group.title}</span>
          <div className="border border-slate-200 rounded-xl divide-y divide-slate-100 bg-white">
            {group.rules.map((rule) => (
              <div key={rule.key} className="flex items-center justify-between gap-4 px-3.5 py-3">
                <div className="min-w-0">
                  <span className="block text-sm font-medium text-slate-800">{rule.label}</span>
                  <span className="block text-xs text-slate-400">{rule.hint}</span>
                </div>
                {renderControl(rule)}
              </div>
            ))}
          </div>
        </div>
      ))}
    </div>
  );
}
