'use client';

import { notificationStyle, timeAgo, type AppNotification } from '../notifications';

interface NotificationItemProps {
  item: AppNotification;
  onOpen: (item: AppNotification) => void;
  roomy?: boolean;
}

export default function NotificationItem({ item, onOpen, roomy = false }: NotificationItemProps) {
  const { icon: Icon, tone } = notificationStyle(item.type);

  return (
    <button
      type="button"
      data-notification={item.id}
      data-type={item.type}
      data-read={item.read}
      onClick={() => onOpen(item)}
      className={`w-full flex items-start gap-3 text-left transition-colors hover:bg-slate-50 ${roomy ? 'px-6 py-3.5' : 'px-4 py-3'} ${item.read ? '' : 'bg-brand-soft/10'}`}
    >
      <span className={`w-9 h-9 rounded-xl flex items-center justify-center shrink-0 ${tone}`}><Icon size={17} /></span>
      <span className="flex-1 min-w-0">
        <span className={`block text-sm text-brand-ink ${item.read ? 'font-medium' : 'font-bold'}`}>{item.title}</span>
        {item.body && <span className={`block text-xs text-slate-600 mt-0.5 ${roomy ? '' : 'line-clamp-2'}`}>{item.body}</span>}
        <span className="block text-[11px] text-slate-400 mt-1">{timeAgo(item.seconds_ago)}</span>
      </span>
      {!item.read && <span className="w-2 h-2 rounded-full bg-brand-accent mt-1.5 shrink-0" aria-label="Sin leer" />}
    </button>
  );
}
