import { toHtml } from '@/lib/richText';

export default function RichTextView({ body, className = '', ...rest }: { body: string | null | undefined; className?: string } & Record<`data-${string}`, string | boolean | undefined>) {
  const html = toHtml(body).replace(/<a href="\[([^"\]]+)\]">/g, '<a title="Lleva a: $1">').replace(/<a href=/g, '<a target="_blank" rel="noopener noreferrer" href=');
  return <div className={`rich-text ${className}`} dangerouslySetInnerHTML={{ __html: html }} {...rest} />;
}
