'use client';

import { useLayoutEffect, useRef, useState } from 'react';
import { Check, Minus } from 'lucide-react';
import { MODULE_GROUPS, OPTIONAL_MODULE_KEYS } from '@/lib/modules';

interface ModulesBubbleProps {
  modulesOff: string[];
}

const BUBBLE_WIDTH = 460;
const GAP = 10;
const MARGIN = 12;

export default function ModulesBubble({ modulesOff }: ModulesBubbleProps) {
  const anchorRef = useRef<HTMLButtonElement>(null);
  const bubbleRef = useRef<HTMLDivElement>(null);
  const [isOpen, setIsOpen] = useState(false);
  const [position, setPosition] = useState<{ left: number; top: number; arrow: number; above: boolean } | null>(null);

  const total = OPTIONAL_MODULE_KEYS.length;
  const enabled = total - modulesOff.filter((key) => OPTIONAL_MODULE_KEYS.includes(key)).length;

  useLayoutEffect(() => {
    if (!isOpen) {
      setPosition(null);
      return;
    }

    const rect = anchorRef.current?.getBoundingClientRect();
    const height = bubbleRef.current?.offsetHeight ?? 0;
    if (!rect) return;

    const center = rect.left + rect.width / 2;
    const left = Math.min(Math.max(MARGIN, center - BUBBLE_WIDTH / 2), window.innerWidth - BUBBLE_WIDTH - MARGIN);

    const spaceBelow = window.innerHeight - rect.bottom - GAP - MARGIN;
    const spaceAbove = rect.top - GAP - MARGIN;
    const above = height > spaceBelow && spaceAbove > spaceBelow;
    const top = above
      ? Math.max(MARGIN, rect.top - GAP - height)
      : Math.min(rect.bottom + GAP, Math.max(MARGIN, window.innerHeight - MARGIN - height));

    setPosition({ left, top, arrow: center - left, above });
  }, [isOpen]);

  return (
    <>
      <button
        ref={anchorRef}
        type="button"
        onMouseEnter={() => setIsOpen(true)}
        onMouseLeave={() => setIsOpen(false)}
        onFocus={() => setIsOpen(true)}
        onBlur={() => setIsOpen(false)}
        className="inline-flex items-baseline gap-1 px-2.5 py-1 rounded-lg bg-emerald-50 border border-emerald-100 text-emerald-700 hover:bg-emerald-100 transition-colors cursor-default whitespace-nowrap"
      >
        <span className="text-sm font-bold">{enabled}</span>
        <span className="text-[11px] font-medium text-emerald-600/80">de {total}</span>
      </button>

      {isOpen && (
        <div
          ref={bubbleRef}
          role="tooltip"
          className="fixed z-[60] pointer-events-none"
          style={{ left: position?.left ?? 0, top: position?.top ?? 0, width: BUBBLE_WIDTH, visibility: position ? 'visible' : 'hidden' }}
        >
          <div className="relative bg-white border border-slate-200 rounded-2xl shadow-xl p-5 space-y-4">
            {position && (
              <span
                className={`absolute w-3 h-3 bg-white border-slate-200 rotate-45 ${position.above ? '-bottom-1.5 border-r border-b' : '-top-1.5 border-l border-t'}`}
                style={{ left: position.arrow - 6 }}
              />
            )}

            <div className="flex items-center justify-between gap-3">
              <span className="text-sm font-bold text-[#022340]">{enabled} módulos encendidos</span>
              {total - enabled > 0 && (
                <span className="text-xs font-medium text-slate-400">{total - enabled} apagados</span>
              )}
            </div>

            <div className="grid grid-cols-2 gap-x-6 gap-y-4">
              {MODULE_GROUPS.map((group) => (
                <div key={group.key} className="space-y-2">
                  <span className="block text-[10px] font-bold uppercase tracking-wider text-slate-400">{group.title}</span>
                  <ul className="space-y-2">
                    {group.items.map((item) => {
                      const isOn = !modulesOff.includes(item.key);
                      return (
                        <li key={item.key} className={`flex items-start gap-2 text-xs leading-snug ${isOn ? 'text-slate-700' : 'text-slate-400 line-through decoration-slate-300'}`}>
                          {isOn
                            ? <Check size={13} className="text-emerald-500 shrink-0 mt-0.5" />
                            : <Minus size={13} className="text-slate-300 shrink-0 mt-0.5" />}
                          <span>{item.label}</span>
                        </li>
                      );
                    })}
                  </ul>
                </div>
              ))}
            </div>
          </div>
        </div>
      )}
    </>
  );
}
