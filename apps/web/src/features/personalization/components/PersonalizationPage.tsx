'use client';

import { useEffect, useState } from 'react';
import Select from '@/app/components/common/Select';
import { useAuth } from '@/hooks/useAuth';
import MessagingSettings from '@/features/settings/components/MessagingSettings';
import BrandEditor from './BrandEditor';
import PortalTextsEditor from './PortalTextsEditor';
import PrivacyEditor from './PrivacyEditor';
import DocumentsEditor from './DocumentsEditor';
import CodesEditor from './CodesEditor';
import VocabularyEditor from './VocabularyEditor';

type SectionId = 'marca' | 'documentos' | 'codigos' | 'vocabulario' | 'portal' | 'privacidad' | 'diseno-correo' | 'mensajes-al-cliente';

const GROUPS: { title: string; sections: { id: SectionId; label: string }[] }[] = [
  { title: 'Tu marca', sections: [{ id: 'marca', label: 'Marca y colores' }, { id: 'documentos', label: 'Documentos' }, { id: 'codigos', label: 'Códigos' }] },
  { title: 'Palabras', sections: [{ id: 'vocabulario', label: 'Vocabulario' }] },
  { title: 'Portal de tus clientes', sections: [{ id: 'portal', label: 'Textos del portal' }, { id: 'privacidad', label: 'Política de privacidad' }] },
  { title: 'Mensajes', sections: [{ id: 'diseno-correo', label: 'Diseño del correo' }, { id: 'mensajes-al-cliente', label: 'Mensajes al cliente' }] },
];

const IDS = GROUPS.flatMap((group) => group.sections.map((s) => s.id));

export default function PersonalizationPage() {
  const { user, can } = useAuth();
  const [current, setCurrent] = useState<SectionId>('marca');
  const canSave = can('settings.edit');
  const company = user?.tenant?.name ?? '';

  useEffect(() => {
    const hash = window.location.hash.replace('#', '') as SectionId;
    if (IDS.includes(hash)) setCurrent(hash);
  }, []);

  const open = (id: SectionId) => {
    setCurrent(id);
    window.history.replaceState(null, '', `#${id}`);
  };

  return (
    <div className="space-y-6 pb-10 font-sans text-[#1F2937]">
      <div>
        <h1 className="text-2xl font-black text-brand-ink tracking-tight">Personalización</h1>
        <p className="text-sm text-slate-500 font-medium">Cómo se ve tu empresa y qué dicen sus mensajes. Cómo funciona se cambia en Configuración.</p>
      </div>

      <div className="lg:hidden">
        <Select
          ariaLabel="Sección"
          clearable={false}
          value={current}
          onChange={(e) => { if (e.target.value) open(e.target.value as SectionId); }}
          options={GROUPS.flatMap((group) => group.sections.map((s) => ({ value: s.id, label: `${group.title} · ${s.label}` })))}
        />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-[15rem_minmax(0,1fr)] gap-6 items-start">
        <nav className="hidden lg:block sticky top-4 bg-white rounded-2xl border border-slate-200/80 shadow-xs p-3 space-y-3" aria-label="Secciones de la personalización" data-personalization-index>
          {GROUPS.map((group) => (
            <div key={group.title}>
              <span className="block px-3 pb-1 text-xs font-bold text-brand-ink">{group.title}</span>
              {group.sections.map((s) => {
                const selected = current === s.id;
                return (
                  <button
                    key={s.id}
                    type="button"
                    data-section-link={s.id}
                    aria-current={selected ? 'page' : undefined}
                    onClick={() => open(s.id)}
                    className={`w-full text-left px-3 py-1.5 rounded-lg text-sm transition-colors ${
                      selected ? 'bg-brand-soft/20 text-brand-ink font-semibold' : 'text-slate-600 hover:bg-slate-50 hover:text-brand-ink'
                    }`}
                  >
                    {s.label}
                  </button>
                );
              })}
            </div>
          ))}
        </nav>

        <div className="min-w-0 space-y-6" data-personalization-section={current}>
          {current === 'marca' && <BrandEditor />}
          {current === 'documentos' && <DocumentsEditor onOpenBrand={() => open('marca')} />}
          {current === 'codigos' && <CodesEditor />}
          {current === 'vocabulario' && <VocabularyEditor />}
          {current === 'portal' && <PortalTextsEditor onOpenPrivacy={() => open('privacidad')} />}
          {current === 'privacidad' && <PrivacyEditor />}
          {current === 'diseno-correo' && <MessagingSettings part="design" canSave={canSave} company={company} onOpenBrand={() => open('marca')} />}
          {current === 'mensajes-al-cliente' && <MessagingSettings part="notices" canSave={canSave} company={company} />}
        </div>
      </div>
    </div>
  );
}
