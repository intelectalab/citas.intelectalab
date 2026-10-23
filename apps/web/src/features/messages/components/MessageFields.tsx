'use client';

import { useRef, useState } from 'react';
import { Eye, EyeOff } from 'lucide-react';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import RichTextEditor, { type RichTextHandle } from '@/app/components/common/RichTextEditor';
import EmailPreview from './EmailPreview';

interface MessageFieldsProps {
  idPrefix: string;
  subject: string;
  onSubject: (value: string) => void;
  body: string;
  onBody: (value: string) => void;
  variables?: { token: string; label: string }[];
  required?: boolean;
  disabled?: boolean;
  subjectPlaceholder?: string;
  hint?: string;
  minHeight?: number;
  previewOpen?: boolean;
}

const LINK_TOKENS = ['[Link]', '[Portal]'];

export default function MessageFields({ idPrefix, subject, onSubject, body, onBody, variables = [], required = true, disabled = false, subjectPlaceholder, hint, minHeight = 200, previewOpen = false }: MessageFieldsProps) {
  const [target, setTarget] = useState<'subject' | 'body'>('body');
  const [preview, setPreview] = useState(previewOpen);
  const subjectRef = useRef<HTMLInputElement>(null);
  const bodyRef = useRef<RichTextHandle>(null);
  const mark = required ? ' *' : '';

  const insert = (token: string) => {
    if (target === 'body') {
      bodyRef.current?.insert(token);
      return;
    }
    const el = subjectRef.current;
    const start = el?.selectionStart ?? subject.length;
    const end = el?.selectionEnd ?? subject.length;
    onSubject(subject.slice(0, start) + token + subject.slice(end));
    requestAnimationFrame(() => { el?.focus(); el?.setSelectionRange(start + token.length, start + token.length); });
  };

  return (
    <>
      <div>
        <label className={labelClass} htmlFor={`${idPrefix}-subject`}>Asunto{mark}</label>
        <input id={`${idPrefix}-subject`} ref={subjectRef} required={required} maxLength={200} placeholder={subjectPlaceholder} className={inputClass} value={subject} onFocus={() => setTarget('subject')} onChange={(e) => onSubject(e.target.value)} disabled={disabled} />
      </div>

      <div>
        <span className={labelClass}>Mensaje{mark}</span>
        <RichTextEditor
          id={`${idPrefix}-body`}
          ref={bodyRef}
          value={body}
          onChange={onBody}
          onFocus={() => setTarget('body')}
          disabled={disabled}
          minHeight={minHeight}
          linkVariables={variables.filter((v) => LINK_TOKENS.includes(v.token))}
        />
        {variables.length > 0 && !disabled && (
          <div className="mt-2" data-variables>
            <span className="block text-xs font-semibold text-slate-600 mb-1.5">Agregar un dato {target === 'subject' ? 'al asunto' : 'al mensaje'}</span>
            <div className="flex flex-wrap gap-1.5">
              {variables.map((v) => (
                <button key={v.token} type="button" data-variable={v.token} onMouseDown={(e) => e.preventDefault()} onClick={() => insert(v.token)}
                  className="px-2.5 py-1 text-xs font-semibold rounded-lg border border-[#84D0D9] bg-[#84D0D9]/15 text-[#022340] hover:bg-[#84D0D9]/30 transition-colors">
                  + {v.label}
                </button>
              ))}
            </div>
          </div>
        )}
        {hint && <p className="text-xs text-slate-500 mt-1.5">{hint}</p>}
      </div>

      <div className="space-y-3">
        <button type="button" data-toggle-preview onClick={() => setPreview(!preview)} className="flex items-center gap-1.5 text-xs font-bold text-[#3F768C] hover:text-[#022340]">
          {preview ? <EyeOff size={14} /> : <Eye size={14} />} {preview ? 'Ocultar cómo le llega' : 'Ver cómo le llega'}
        </button>
        {preview && <EmailPreview subject={subject} body={body} />}
      </div>
    </>
  );
}
