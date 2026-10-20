import type { ButtonHTMLAttributes } from 'react';

interface OptionCardProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  selected: boolean;
}

export default function OptionCard({ selected, className = '', ...rest }: OptionCardProps) {
  const state = selected ? 'border-[#022340] bg-[#84D0D9]/15 ring-2 ring-[#022340]/10' : 'border-slate-200 hover:bg-slate-50';

  return <button {...rest} className={className ? `${className} ${state}` : state} />;
}
