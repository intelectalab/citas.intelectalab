'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { BookOpen, CircleHelp } from 'lucide-react';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { useHelp } from '../useHelp';
import HelpTopicView from './HelpTopicView';

export default function HelpButton() {
  const pathname = usePathname();
  const { enabled, screenFor } = useHelp();
  const [open, setOpen] = useState(false);

  useEffect(() => {
    setOpen(false);
  }, [pathname]);

  if (!enabled) return null;

  const screen = screenFor(pathname);

  return (
    <>
      <button
        type="button"
        data-help-button
        aria-haspopup="dialog"
        aria-expanded={open}
        aria-label="Ayuda de esta pantalla"
        title="Ayuda de esta pantalla"
        onClick={() => setOpen(true)}
        className={`p-2 text-gray-600 hover:bg-gray-100 rounded-full transition-colors ${open ? 'bg-gray-100' : ''}`}
      >
        <CircleHelp size={20} />
      </button>

      <FormDrawer
        isOpen={open}
        onClose={() => setOpen(false)}
        title={screen ? `Ayuda: ${screen.name}` : 'Ayuda'}
        subtitle="Lo que puedes hacer en esta pantalla"
        icon={<CircleHelp size={18} />}
      >
        <div className="space-y-6" data-help-panel>
          {screen ? (
            <HelpTopicView screen={screen} />
          ) : (
            <p className="text-sm text-slate-600">Esta pantalla no tiene una guía propia. En Autoayuda encuentras la de todas las pantallas de tu menú.</p>
          )}
          {pathname !== '/help' && (
            <Link
              href={screen ? `/help?screen=${encodeURIComponent(screen.href)}` : '/help'}
              data-help-all
              className="flex items-center justify-center gap-2 w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm font-semibold text-brand-ink hover:bg-slate-50 transition-colors"
            >
              <BookOpen size={16} /> Ver toda la autoayuda
            </Link>
          )}
        </div>
      </FormDrawer>
    </>
  );
}
