import { api } from './axios';

const sent = new Set<string>();
let budget = 10;

export function reportError(input: unknown, extra: { file?: string; line?: number; kind?: string } = {}) {
  if (typeof window === 'undefined' || budget <= 0) return;
  const error = input instanceof Error ? input : null;
  const message = (error?.message || (typeof input === 'string' ? input : '') || 'Error sin mensaje').slice(0, 2000);
  if (/ResizeObserver loop|Loading chunk|Script error\.?$/i.test(message)) return;
  const key = `${message}|${extra.file ?? ''}|${extra.line ?? ''}`;
  if (sent.has(key)) return;
  sent.add(key);
  budget -= 1;
  api.post('/client-errors', {
    message,
    kind: extra.kind ?? error?.name ?? 'Error',
    file: extra.file ?? null,
    line: extra.line ?? null,
    url: window.location.pathname + window.location.search,
    stack: error?.stack?.slice(0, 5000) ?? null,
  }).catch(() => undefined);
}
