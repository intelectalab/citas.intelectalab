import type { ButtonHTMLAttributes } from 'react';

interface OptionCardProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  selected: boolean;
}

export default function OptionCard({ selected, className = '', ...rest }: OptionCardProps) {
  const state = selected ? 'border-brand bg-brand-soft/15 ring-2 ring-brand/10' : 'border-slate-200 hover:bg-slate-50';

  return <button {...rest} className={className ? `${className} ${state}` : state} />;
}
