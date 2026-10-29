'use client';

import { useEffect, useImperativeHandle, useRef, useState, type Ref } from 'react';
import { EditorContent, useEditor, useEditorState } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import { Bold, Italic, Link2, List, ListOrdered, RemoveFormatting, Underline, Unlink } from 'lucide-react';
import { toHtml } from '@/lib/richText';

export interface RichTextHandle {
  insert: (text: string) => void;
  focus: () => void;
}

interface RichTextEditorProps {
  id?: string;
  value: string;
  onChange: (html: string) => void;
  onFocus?: () => void;
  disabled?: boolean;
  minHeight?: number;
  linkVariables?: { token: string; label: string }[];
  ref?: Ref<RichTextHandle>;
}

const TOKEN = /^\[[^\]\s]+\]$/;

const normalizeUrl = (raw: string) => {
  const url = raw.trim();
  if (!url || TOKEN.test(url) || /^(https?:\/\/|mailto:)/i.test(url)) return url;
  if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(url)) return `mailto:${url}`;
  return `https://${url}`;
};

function ToolButton({ active = false, disabled, label, onClick, children }: { active?: boolean; disabled?: boolean; label: string; onClick: () => void; children: React.ReactNode }) {
  return (
    <button
      type="button"
      title={label}
      aria-label={label}
      aria-pressed={active}
      disabled={disabled}
      onMouseDown={(e) => e.preventDefault()}
      onClick={onClick}
      className={`h-8 min-w-8 px-1.5 flex items-center justify-center rounded-md text-sm transition-colors disabled:opacity-40 ${active ? 'bg-brand text-white' : 'text-slate-600 hover:bg-slate-100'}`}
    >
      {children}
    </button>
  );
}

export default function RichTextEditor({ id, value, onChange, onFocus, disabled = false, minHeight = 200, linkVariables = [], ref }: RichTextEditorProps) {
  const emitted = useRef<string | null>(null);
  const [linking, setLinking] = useState(false);
  const [url, setUrl] = useState('');

  const editor = useEditor({
    immediatelyRender: false,
    editable: !disabled,
    extensions: [
      StarterKit.configure({
        heading: { levels: [2, 3] },
        blockquote: false,
        code: false,
        codeBlock: false,
        horizontalRule: false,
        strike: false,
        link: {
          openOnClick: false,
          autolink: true,
          defaultProtocol: 'https',
          HTMLAttributes: { rel: 'noopener', target: null },
          isAllowedUri: (uri, ctx) => TOKEN.test(uri) || ctx.defaultValidate(uri),
        },
      }),
    ],
    content: toHtml(value),
    editorProps: { attributes: { class: 'rich-text focus:outline-none', ...(id ? { id } : {}), 'aria-multiline': 'true', role: 'textbox' } },
    onUpdate: ({ editor: current }) => {
      const html = current.isEmpty ? '' : current.getHTML();
      emitted.current = html;
      onChange(html);
    },
    onFocus: () => onFocus?.(),
  });

  useEffect(() => {
    if (!editor || value === emitted.current) return;
    emitted.current = value;
    editor.commands.setContent(toHtml(value), { emitUpdate: false });
  }, [editor, value]);

  useEffect(() => {
    editor?.setEditable(!disabled);
  }, [editor, disabled]);

  useImperativeHandle(ref, () => ({
    insert: (text: string) => { editor?.chain().focus().insertContent(text).run(); },
    focus: () => { editor?.commands.focus(); },
  }), [editor]);

  const state = useEditorState({
    editor,
    selector: ({ editor: current }) => ({
      bold: current?.isActive('bold') ?? false,
      italic: current?.isActive('italic') ?? false,
      underline: current?.isActive('underline') ?? false,
      title: current?.isActive('heading', { level: 2 }) ?? false,
      subtitle: current?.isActive('heading', { level: 3 }) ?? false,
      bullets: current?.isActive('bulletList') ?? false,
      numbers: current?.isActive('orderedList') ?? false,
      link: current?.isActive('link') ?? false,
      href: (current?.getAttributes('link').href as string | undefined) ?? '',
    }),
  });

  const run = (fn: (chain: ReturnType<NonNullable<typeof editor>['chain']>) => ReturnType<NonNullable<typeof editor>['chain']>) => {
    if (editor) fn(editor.chain().focus()).run();
  };

  const openLink = () => {
    setUrl(state?.href ?? '');
    setLinking(true);
  };

  const applyLink = (target = url) => {
    const href = normalizeUrl(target);
    if (!editor) return;
    if (!href) {
      editor.chain().focus().extendMarkRange('link').unsetLink().run();
    } else if (editor.state.selection.empty && !state?.link) {
      editor.chain().focus().insertContent({ type: 'text', text: TOKEN.test(href) ? 'Abrir enlace' : href, marks: [{ type: 'link', attrs: { href } }] }).run();
    } else {
      editor.chain().focus().extendMarkRange('link').setLink({ href }).run();
    }
    setLinking(false);
  };

  const size = state?.title ? 'title' : state?.subtitle ? 'subtitle' : 'normal';
  const sizes: { key: string; label: string; apply: () => void }[] = [
    { key: 'normal', label: 'Normal', apply: () => run((c) => c.setParagraph()) },
    { key: 'subtitle', label: 'Subtítulo', apply: () => run((c) => c.setHeading({ level: 3 })) },
    { key: 'title', label: 'Título', apply: () => run((c) => c.setHeading({ level: 2 })) },
  ];

  return (
    <div className={`rounded-lg border border-slate-200 bg-white focus-within:ring-2 focus-within:ring-brand/20 focus-within:border-brand ${disabled ? 'opacity-70' : ''}`} data-rich-editor>
      <div className="flex flex-wrap items-center gap-0.5 px-1.5 py-1 border-b border-slate-100" role="toolbar" aria-label="Formato del texto">
        <div className="flex items-center rounded-md border border-slate-200 overflow-hidden mr-1" role="radiogroup" aria-label="Tamaño del texto">
          {sizes.map((s) => (
            <button key={s.key} type="button" role="radio" aria-checked={size === s.key} data-size={s.key} disabled={disabled} onMouseDown={(e) => e.preventDefault()} onClick={s.apply}
              className={`h-7 px-2 text-xs font-semibold transition-colors ${size === s.key ? 'bg-brand text-white' : 'text-slate-600 hover:bg-slate-100'}`}>
              {s.label}
            </button>
          ))}
        </div>
        <ToolButton label="Negrita" active={state?.bold} disabled={disabled} onClick={() => run((c) => c.toggleBold())}><Bold size={15} /></ToolButton>
        <ToolButton label="Cursiva" active={state?.italic} disabled={disabled} onClick={() => run((c) => c.toggleItalic())}><Italic size={15} /></ToolButton>
        <ToolButton label="Subrayado" active={state?.underline} disabled={disabled} onClick={() => run((c) => c.toggleUnderline())}><Underline size={15} /></ToolButton>
        <span className="w-px h-5 bg-slate-200 mx-1" />
        <ToolButton label="Lista con viñetas" active={state?.bullets} disabled={disabled} onClick={() => run((c) => c.toggleBulletList())}><List size={15} /></ToolButton>
        <ToolButton label="Lista numerada" active={state?.numbers} disabled={disabled} onClick={() => run((c) => c.toggleOrderedList())}><ListOrdered size={15} /></ToolButton>
        <span className="w-px h-5 bg-slate-200 mx-1" />
        <ToolButton label="Enlace" active={state?.link || linking} disabled={disabled} onClick={openLink}><Link2 size={15} /></ToolButton>
        {state?.link && <ToolButton label="Quitar enlace" disabled={disabled} onClick={() => run((c) => c.extendMarkRange('link').unsetLink())}><Unlink size={15} /></ToolButton>}
        <ToolButton label="Quitar formato" disabled={disabled} onClick={() => run((c) => c.unsetAllMarks().clearNodes())}><RemoveFormatting size={15} /></ToolButton>
      </div>

      {linking && (
        <div className="px-3 py-2.5 border-b border-slate-100 bg-slate-50/70 space-y-2" data-link-panel>
          <div className="flex flex-wrap items-center gap-2">
            <input
              autoFocus
              aria-label="Dirección del enlace"
              placeholder="https://tuempresa.com/promocion"
              className="flex-1 min-w-48 text-sm border border-slate-200 rounded-lg px-3 py-1.5 focus:outline-none focus:border-brand bg-white"
              value={url}
              onChange={(e) => setUrl(e.target.value)}
              onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); applyLink(); } if (e.key === 'Escape') setLinking(false); }}
            />
            <button type="button" data-apply-link onClick={() => applyLink()} className="px-3 py-1.5 text-xs font-bold bg-brand text-white rounded-lg hover:bg-brand-hover">Aplicar</button>
            <button type="button" onClick={() => setLinking(false)} className="px-3 py-1.5 text-xs font-semibold text-slate-600 border border-slate-200 rounded-lg hover:bg-white">Cancelar</button>
          </div>
          {linkVariables.length > 0 && (
            <div className="flex flex-wrap items-center gap-1.5">
              <span className="text-xs text-slate-500">O que lleve a:</span>
              {linkVariables.map((v) => (
                <button key={v.token} type="button" data-link-variable={v.token} onClick={() => applyLink(v.token)}
                  className="px-2 py-0.5 text-xs font-semibold rounded-md border border-brand-soft bg-brand-soft/15 text-brand-ink hover:bg-brand-soft/30">
                  {v.label}
                </button>
              ))}
            </div>
          )}
          <p className="text-xs text-slate-500">Selecciona primero las palabras que quieres convertir en enlace, por ejemplo &quot;Reserva aquí&quot;.</p>
        </div>
      )}

      <EditorContent editor={editor} className="px-3.5 py-2.5 text-sm text-slate-800 cursor-text" style={{ minHeight }} onClick={() => editor?.commands.focus()} />
    </div>
  );
}
