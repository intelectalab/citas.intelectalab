const HTML_TAG = /<(p|br|strong|b|em|i|u|h[1-6]|ul|ol|li|a|div)\b[^>]*>/i;

const escape = (text: string) => text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

export const isHtml = (text: string | null | undefined) => HTML_TAG.test(text ?? '');

export const textToHtml = (text: string) => {
  const clean = text.replace(/\r\n?/g, '\n').trim();
  if (!clean) return '';
  return clean.split('\n\n').map((part) => part.replace(/^\n+|\n+$/g, '')).map((part) => `<p>${escape(part).replace(/\n/g, '<br>')}</p>`).join('');
};

export const toHtml = (body: string | null | undefined) => (isHtml(body) ? (body ?? '') : textToHtml(body ?? ''));

export const htmlToText = (body: string | null | undefined) => {
  if (!isHtml(body)) return body ?? '';
  const text = (body ?? '')
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<li[^>]*>/gi, '• ')
    .replace(/<\/(p|h[1-6]|li|ul|ol|div)>/gi, '\n')
    .replace(/<[^>]+>/g, '')
    .replace(/&nbsp;/g, ' ')
    .replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#0?39;/g, "'").replace(/&amp;/g, '&');
  return text.replace(/\n{3,}/g, '\n\n').trim();
};
