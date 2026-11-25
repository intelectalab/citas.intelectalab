'use client';

import { useState } from 'react';
import Link from 'next/link';
import { Check } from 'lucide-react';
import type { WebBusinessType } from '../web';

export default function RubroTabs({ types, groups }: { types: WebBusinessType[]; groups: { name: string; benefits: string[] }[] }) {
  const usable = groups.filter((g) => types.some((t) => t.group === g.name));
  const [active, setActive] = useState(usable[0]?.name ?? '');

  if (usable.length < 2) return null;

  const group = usable.find((g) => g.name === active) ?? usable[0];
  const shown = types.filter((t) => t.group === group.name);

  return (
    <div className="space-y-5" data-rubro-tabs>
      <div role="tablist" aria-label="Tipos de negocio" className="flex gap-2 overflow-x-auto select-scroll pb-1 justify-start sm:justify-center">
        {usable.map((g) => (
          <button
            key={g.name}
            role="tab"
            type="button"
            aria-selected={g.name === group.name}
            onClick={() => setActive(g.name)}
            className={`shrink-0 px-4 py-2 text-sm font-bold rounded-full border transition-colors cursor-pointer ${
              g.name === group.name ? 'bg-brand border-brand text-white' : 'bg-white border-slate-200 text-slate-600 hover:border-brand-accent'
            }`}
          >
            {g.name}
          </button>
        ))}
      </div>
      <div role="tabpanel" className="grid grid-cols-1 lg:grid-cols-[1fr_18rem] gap-5 items-start">
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          {shown.map((type) => (
            <Link key={type.slug} href={`/rubros/${type.slug}`} className="group bg-slate-50/60 hover:bg-white rounded-2xl border border-slate-200 hover:border-brand-soft p-4 flex gap-3 items-center min-w-0">
              <span className="w-9 h-9 rounded-xl shrink-0 flex items-center justify-center text-white font-black text-sm" style={{ background: type.color ?? undefined }} aria-hidden="true">{type.short_name.slice(0, 1)}</span>
              <span className="min-w-0 font-bold text-brand-ink group-hover:text-brand-accent truncate">{type.name}</span>
            </Link>
          ))}
        </div>
        {group.benefits.length > 0 && (
          <ul className="bg-brand-soft/15 border border-brand-soft/50 rounded-2xl p-4 space-y-2">
            {group.benefits.map((benefit) => (
              <li key={benefit} className="flex items-start gap-2 text-sm text-brand-ink"><Check size={16} className="text-brand-accent shrink-0 mt-0.5" /> {benefit}</li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}
