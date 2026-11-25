'use client';

import { useEffect, useState } from 'react';
import { Sparkles } from 'lucide-react';
import Badge from '@/app/components/common/Badge';
import { api } from '@/lib/axios';
import { money } from '@/features/sales/sales';

interface Summary {
  segments: { key: string; label: string }[];
  loyalty: { points: number; value: number; can_redeem: boolean; tier: { key: string; label: string } } | null;
  balance: number;
  currency: string;
}

const SEGMENT_TONES: Record<string, string> = {
  vip: 'bg-brand-soft/30 text-brand-ink',
  frequent: 'bg-sky-50 text-sky-700',
  new: 'bg-emerald-50 text-emerald-700',
  at_risk: 'bg-amber-50 text-amber-800',
  inactive: 'bg-slate-100 text-slate-600',
  birthday_month: 'bg-violet-50 text-violet-700',
};

export default function CustomerSummaryChip({ customerId }: { customerId: string | null | undefined }) {
  const [summary, setSummary] = useState<Summary | null>(null);

  useEffect(() => {
    if (!customerId) {
      setSummary(null);
      return;
    }
    let alive = true;
    api.get<Summary>(`/pos/customers/${customerId}/summary`)
      .then((res) => { if (alive) setSummary(res.data); })
      .catch(() => { if (alive) setSummary(null); });
    return () => { alive = false; };
  }, [customerId]);

  if (!customerId || !summary) return null;

  const segments = summary.segments.filter((s) => s.key !== 'no_visits');
  if (!segments.length && !summary.loyalty && summary.balance <= 0) return null;

  return (
    <div className="-mt-2 flex flex-wrap items-center gap-1.5 text-xs" data-customer-summary>
      {segments.map((s) => (
        <Badge key={s.key} tone={SEGMENT_TONES[s.key] ?? 'bg-slate-100 text-slate-600'} bold>{s.label}</Badge>
      ))}
      {summary.loyalty && (
        <span className="inline-flex items-center gap-1 text-brand-ink" data-customer-points>
          <Sparkles size={13} className="text-brand-accent" />
          <strong>{summary.loyalty.points} pts</strong>
          <span className="text-slate-500">({money(summary.loyalty.value, summary.currency)}{summary.loyalty.can_redeem ? ', puede canjear' : ''}) · {summary.loyalty.tier.label}</span>
        </span>
      )}
      {summary.balance > 0 && (
        <span className="text-amber-800">Debe {money(summary.balance, summary.currency)}</span>
      )}
    </div>
  );
}
