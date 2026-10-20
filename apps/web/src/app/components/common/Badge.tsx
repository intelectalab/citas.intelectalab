import type { HTMLAttributes } from 'react';

interface BadgeProps extends HTMLAttributes<HTMLSpanElement> {
  tone: string;
  bold?: boolean;
  nowrap?: boolean;
}

export default function Badge({ tone, bold = false, nowrap = false, className = '', ...rest }: BadgeProps) {
  const classes = ['px-2.5 py-1 text-xs rounded-full', bold ? 'font-semibold' : 'font-medium', nowrap ? 'whitespace-nowrap' : '', className, tone]
    .filter(Boolean)
    .join(' ');

  return <span {...rest} className={classes} />;
}
