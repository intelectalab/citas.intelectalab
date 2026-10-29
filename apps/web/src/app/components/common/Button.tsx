import type { ButtonHTMLAttributes } from 'react';

const VARIANTS = {
  primary: 'px-5 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover shadow-sm transition-colors disabled:opacity-50',
  classic: 'px-5 py-2.5 text-sm font-medium bg-brand text-white rounded-lg hover:bg-brand-hover shadow-sm transition-colors disabled:opacity-50',
  secondary: 'px-4 py-2.5 text-sm font-semibold text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-50 transition-colors',
};

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant: keyof typeof VARIANTS;
}

export default function Button({ variant, className = '', ...rest }: ButtonProps) {
  return <button {...rest} className={className ? `${VARIANTS[variant]} ${className}` : VARIANTS[variant]} />;
}
