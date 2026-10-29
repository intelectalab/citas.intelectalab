'use client';

import { useRef, useState } from 'react';
import { Info } from 'lucide-react';
import Floating from './Floating';

interface InfoTipProps {
  text: string;
  label: string;
  title?: string;
}

export default function InfoTip({ text, label, title }: InfoTipProps) {
  const [open, setOpen] = useState(false);
  const buttonRef = useRef<HTMLButtonElement>(null);

  return (
    <>
      <button
        ref={buttonRef}
        type="button"
        aria-label={label}
        aria-expanded={open}
        data-info-tip
        onClick={(e) => { e.preventDefault(); e.stopPropagation(); setOpen((value) => !value); }}
        className={`inline-flex items-center justify-center w-5 h-5 rounded-full shrink-0 transition-colors ${open ? 'text-brand-ink bg-brand-soft/30' : 'text-slate-400 hover:text-brand-ink hover:bg-slate-100'}`}
      >
        <Info size={14} />
      </button>
      <Floating
        anchorRef={buttonRef}
        open={open}
        onClose={() => setOpen(false)}
        role="tooltip"
        className="w-64 bg-white border border-slate-200 rounded-xl shadow-xl px-3.5 py-3 animate-in fade-in duration-150"
      >
        <div onClick={(e) => e.stopPropagation()} data-info-text>
          {title && <span className="block text-xs font-bold text-brand-ink mb-1">{title}</span>}
          <p className="text-xs text-slate-600 leading-relaxed">{text}</p>
        </div>
      </Floating>
    </>
  );
}
