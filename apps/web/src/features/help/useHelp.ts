'use client';

import type { LucideIcon } from 'lucide-react';
import { Bell, CircleHelp, FileText, UserRound } from 'lucide-react';
import { useEffect, useState } from 'react';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { useVocabulary } from '@/hooks/useVocabulary';
import { matchesPath, useMenu } from '@/app/components/layout/menu';
import { helpTopics } from './content';
import type { HelpNeeds, HelpPoint, HelpRule, HelpTask, HelpTopic } from './types';

export interface ShapedTopic {
  summary: string;
  points: HelpPoint[];
  tasks: (Omit<HelpTask, 'steps'> & { steps: string[] })[];
  rules: HelpRule[];
  article?: string;
}

export interface HelpArticle {
  id: string;
  slug: string;
  title: string;
  category: string;
  summary: string | null;
  body: string;
  link: string | null;
  replaces_topic: string | null;
}

const textOf = (html: string) => html.replace(/<[^>]+>/g, ' ').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim();

let cachedArticles: { items: HelpArticle[]; hidden: string[] } | null = null;

function useHelpArticles(enabled: boolean) {
  const [data, setData] = useState(cachedArticles);
  useEffect(() => {
    if (!enabled || cachedArticles) return;
    api.get('/help/articles').then((res) => { cachedArticles = { items: res.data.items ?? [], hidden: res.data.hidden ?? [] }; setData(cachedArticles); }).catch(() => undefined);
  }, [enabled]);
  return data ?? { items: [], hidden: [] };
}

export interface HelpScreen {
  name: string;
  href: string;
  icon: LucideIcon;
  topic: ShapedTopic | null;
}

export interface HelpSection {
  id: string;
  title: string;
  screens: HelpScreen[];
}

export const plain = (text: string) => text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

export const screenText = (screen: HelpScreen) => plain([
  screen.name,
  screen.topic?.summary ?? '',
  ...(screen.topic?.points ?? []).flatMap((p) => [p.title, p.text]),
  ...(screen.topic?.tasks ?? []).flatMap((t) => [t.title, ...t.steps]),
  ...(screen.topic?.rules ?? []).flatMap((r) => [r.title, r.text]),
  screen.topic?.article ? textOf(screen.topic.article) : '',
].join(' '));

export function useHelp() {
  const { user, can, hasModule } = useAuth();
  const vocab = useVocabulary();
  const { groups, footer, loading } = useMenu();

  const enabled = !loading && Boolean(user?.help_enabled) && !user?.is_saas_admin && !user?.is_saas_support;
  const topics = helpTopics(vocab);
  const canConfigure = can('settings.view');
  const articles = useHelpArticles(enabled);
  const articleTopic = (article: HelpArticle): ShapedTopic => ({ summary: article.summary || textOf(article.body).slice(0, 220), points: [], tasks: [], rules: [], article: article.body });
  const replacing = new Map(articles.items.filter((a) => a.replaces_topic).map((a) => [a.replaces_topic as string, a]));

  const allows = (needs: HelpNeeds) =>
    (!needs.perm || needs.perm.split('|').some((perm) => can(perm)))
    && (!needs.module || hasModule(needs.module))
    && (!needs.admin || Boolean(user?.is_tenant_admin));

  const shape = (topic: HelpTopic | undefined): ShapedTopic | null => topic ? {
    summary: topic.summary,
    points: (topic.points ?? []).filter(allows),
    tasks: (topic.tasks ?? []).filter(allows).map((task) => ({
      ...task,
      steps: task.steps.filter((step) => typeof step === 'string' || allows(step)).map((step) => (typeof step === 'string' ? step : step.text)),
    })),
    rules: (topic.rules ?? []).filter(allows).map((rule) => (canConfigure ? rule : { ...rule, where: undefined })),
  } : null;

  const toScreen = (item: { name: string; href: string; icon: LucideIcon }): HelpScreen => ({
    name: item.name,
    href: item.href,
    icon: item.icon,
    topic: replacing.has(item.href) ? articleTopic(replacing.get(item.href) as HelpArticle) : articles.hidden.includes(item.href) ? null : shape(topics[item.href]),
  });

  const articleSections: HelpSection[] = Object.entries(articles.items.filter((a) => !a.replaces_topic).reduce<Record<string, HelpArticle[]>>((acc, a) => { (acc[a.category] ??= []).push(a); return acc; }, {}))
    .map(([category, list]) => ({ id: `articles-${category}`, title: category, screens: list.map((a) => ({ name: a.title, href: `${a.link ?? '/help'}#${a.slug}`, icon: FileText, topic: articleTopic(a) })) }));

  const sections: HelpSection[] = enabled ? [
    ...groups.map(({ group, items }) => ({ id: group.id, title: group.groupName ?? 'General', screens: items.map(toScreen) })),
    ...(footer.some((item) => item.href !== '/help')
      ? [{ id: 'footer', title: 'Ajustes de tu empresa', screens: footer.filter((item) => item.href !== '/help').map(toScreen) }]
      : []),
    ...articleSections,
  ] : [];

  const extras: HelpScreen[] = [
    toScreen({ name: 'Notificaciones', href: '/notifications', icon: Bell }),
    toScreen({ name: 'Mi cuenta', href: '/account', icon: UserRound }),
    toScreen({ name: 'Autoayuda', href: '/help', icon: CircleHelp }),
  ];

  const screenFor = (pathname: string): HelpScreen | null =>
    [...sections.flatMap((section) => section.screens), ...extras]
      .filter((screen) => matchesPath(screen.href, pathname))
      .sort((a, b) => b.href.length - a.href.length)[0] ?? null;

  return { enabled, sections, screenFor, isAdmin: Boolean(user?.is_tenant_admin) };
}
