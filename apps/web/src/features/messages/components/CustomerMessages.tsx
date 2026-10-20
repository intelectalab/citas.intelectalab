'use client';

import Badge from '@/app/components/common/Badge';
import SectionTitle from '@/app/components/common/SectionTitle';
import { useCallback, useEffect, useState } from 'react';
import { Mail, Plus } from 'lucide-react';
import { api } from '@/lib/axios';
import Switch from '@/app/components/common/Switch';
import { PagedList } from '@/app/components/common/Pager';
import { apiError } from '@/features/appointments/appointments';
import { momentOf } from '@/features/sales/sales';
import type { PickedCustomer } from '@/features/customers/components/CustomerPicker';
import ComposePanel from './ComposePanel';
import { MessageDetail } from './MessagesBoard';
import { MESSAGE_BADGES, type Consents, type Message, type MessagesBoard } from '../messages';

const card = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs';

interface CustomerMessagesProps {
  customer: PickedCustomer & { email?: string | null };
  consents: Consents | null;
  canView: boolean;
  canEditConsents: boolean;
}

export default function CustomerMessages({ customer, consents: initial, canView, canEditConsents }: CustomerMessagesProps) {
  const [board, setBoard] = useState<MessagesBoard | null>(null);
  const [consents, setConsents] = useState<Consents | null>(initial);
  const [composing, setComposing] = useState(false);
  const [editing, setEditing] = useState<Message | null>(null);
  const [detail, setDetail] = useState<Message | null>(null);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const load = useCallback(() => {
    if (!canView) return;
    api.get('/messages', { params: { customer_id: customer.id } }).then((res) => setBoard(res.data)).catch(() => setBoard(null));
  }, [canView, customer.id]);

  useEffect(() => { load(); }, [load]);
  useEffect(() => { setConsents(initial); }, [initial]);

  const toggle = (channel: 'email' | 'whatsapp', granted: boolean) => {
    setError('');
    api.put(`/customers/${customer.id}/consents`, { [channel]: granted })
      .then((res) => setConsents(res.data.consents))
      .catch((err) => setError(apiError(err, 'No se pudo guardar.')));
  };

  const messages = board?.messages ?? [];

  return (
    <section className={`${card} overflow-hidden flex flex-col`} data-card="messages">
      <div className="px-6 pt-5 pb-3 flex items-start justify-between gap-3">
        <div>
          <SectionTitle><Mail className="w-4 h-4 text-[#3F768C]" /> Mensajes</SectionTitle>
          <p className="text-xs text-slate-500 font-medium mt-1">{customer.email ? `Correo: ${customer.email}` : 'No tiene correo registrado: agrégalo en su ficha para escribirle.'}</p>
        </div>
        {board?.can.create && (
          <button type="button" data-action="new-message" onClick={() => { setEditing(null); setComposing(true); setNotice(''); }} className="flex items-center gap-1.5 px-3 py-2 text-xs font-bold bg-[#022340] text-white rounded-lg hover:bg-[#03315a] shrink-0">
            <Plus size={14} /> Nuevo mensaje
          </button>
        )}
      </div>

      {consents && (
        <div className="mx-6 mb-3 rounded-xl border border-slate-200 divide-y divide-slate-100" data-consents>
          {([['email', 'Acepta recibir promociones por correo'], ['whatsapp', 'Acepta recibir promociones por WhatsApp']] as ['email' | 'whatsapp', string][]).map(([channel, label]) => (
            <div key={channel} className="flex items-center justify-between gap-3 px-4 py-2.5">
              <div className="min-w-0">
                <span className="block text-sm text-slate-700">{label}</span>
                {consents[channel].at && <span className="block text-[11px] text-slate-400">{consents[channel].granted ? 'Aceptó' : 'Lo retiró'} {momentOf(consents[channel].at!)}{consents[channel].source === 'portal' ? ' desde el portal' : ''}</span>}
              </div>
              <Switch checked={consents[channel].granted} onChange={(on) => toggle(channel, on)} label={label} disabled={!canEditConsents} />
            </div>
          ))}
          <p className="px-4 py-2 text-[11px] text-slate-500">Los avisos de sus citas, pagos y paquetes le llegan igual. Esto solo cuenta para las campañas.</p>
        </div>
      )}

      {error && <p role="alert" className="mx-6 mb-3 text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error}</p>}
      {notice && <p data-notice className="mx-6 mb-3 text-sm text-emerald-700 bg-emerald-50 border border-emerald-100 rounded-lg px-3 py-2">{notice}</p>}

      {canView && (
        <div className="border-t border-slate-100 flex flex-col">
          <PagedList
            items={messages}
            empty="Aún no se le envió ningún mensaje."
            renderRow={(m) => (
              <li key={m.id}>
                <button type="button" data-customer-message={m.id} onClick={() => setDetail(m)} className="w-full flex items-center gap-3 px-6 py-2.5 text-left hover:bg-slate-50 transition-colors">
                  <div className="flex-1 min-w-0">
                    <span className="block text-sm font-semibold text-slate-800 truncate">{m.subject}</span>
                    <span className="block text-xs text-slate-500">{m.kind_label} · {momentOf(m.status === 'scheduled' && m.scheduled_at ? m.scheduled_at : m.sent_at ?? m.created_at)}</span>
                  </div>
                  <Badge tone={MESSAGE_BADGES[m.status]} nowrap>{m.status_label}</Badge>
                </button>
              </li>
            )}
          />
        </div>
      )}

      <MessageDetail message={detail} can={board?.can ?? null} onClose={() => setDetail(null)} onChanged={(m, text) => { setDetail(m); setNotice(text); load(); }} onEdit={(m) => { setDetail(null); setEditing(m); setComposing(true); }} />

      <ComposePanel
        isOpen={composing}
        onClose={() => { setComposing(false); setEditing(null); }}
        customer={editing ? null : customer}
        editing={editing}
        emailState={board?.email.state}
        onSaved={(m, text) => { setNotice(text); load(); }}
      />
    </section>
  );
}
