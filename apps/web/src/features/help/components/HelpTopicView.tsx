'use client';

import { Lightbulb, ListChecks, MousePointerClick, SlidersHorizontal } from 'lucide-react';
import type { HelpScreen } from '../useHelp';

const blockTitle = 'flex items-center gap-2 text-sm font-bold text-brand-ink';

export default function HelpTopicView({ screen, wide = false }: { screen: HelpScreen; wide?: boolean }) {
  const topic = screen.topic;

  if (!topic) {
    return (
      <div className="p-4 rounded-2xl bg-slate-50 border border-slate-200 text-sm text-slate-600" data-help-empty>
        Esta pantalla todavía no tiene su guía escrita.
      </div>
    );
  }

  return (
    <div className="space-y-6" data-help-topic={screen.href}>
      <div className="p-4 rounded-2xl bg-brand-soft/15 border border-brand-soft/40">
        <p className={blockTitle}><Lightbulb size={16} className="text-brand-accent" /> ¿Para qué sirve {screen.name}?</p>
        <p className="mt-1.5 text-sm text-slate-700 leading-relaxed">{topic.summary}</p>
      </div>

      {topic.points.length > 0 && (
        <section className="space-y-3" data-help-block="points">
          <h3 className={blockTitle}><MousePointerClick size={16} className="text-brand-accent" /> Qué puedes hacer aquí</h3>
          <ul className={`grid gap-3 ${wide ? '2xl:grid-cols-2' : ''}`}>
            {topic.points.map((point) => (
              <li key={point.title} className="p-3.5 rounded-xl bg-white border border-slate-200">
                <span className="flex items-start gap-2 text-sm font-semibold text-slate-800">
                  <span className="mt-1.5 w-1.5 h-1.5 rounded-full bg-brand-accent shrink-0" />
                  {point.title}
                </span>
                <span className="block mt-1 pl-3.5 text-sm text-slate-600 leading-relaxed">{point.text}</span>
              </li>
            ))}
          </ul>
        </section>
      )}

      {topic.tasks.length > 0 && (
        <section className="space-y-3" data-help-block="tasks">
          <h3 className={blockTitle}><ListChecks size={16} className="text-brand-accent" /> Cómo se hace</h3>
          <div className={`grid gap-3 ${wide ? '2xl:grid-cols-2' : ''}`}>
            {topic.tasks.map((task) => (
              <div key={task.title} className="p-3.5 rounded-xl bg-white border border-slate-200">
                <p className="text-sm font-semibold text-slate-800">{task.title}</p>
                <ol className="mt-2 space-y-1.5">
                  {task.steps.map((step, index) => (
                    <li key={index} className="flex items-start gap-2.5 text-sm text-slate-600 leading-relaxed">
                      <span className="mt-0.5 w-5 h-5 rounded-full bg-brand-ink text-white text-[11px] font-bold flex items-center justify-center shrink-0">{index + 1}</span>
                      <span>{step}</span>
                    </li>
                  ))}
                </ol>
              </div>
            ))}
          </div>
        </section>
      )}

      {topic.rules.length > 0 && (
        <section className="space-y-3" data-help-block="rules">
          <h3 className={blockTitle}><SlidersHorizontal size={16} className="text-brand-accent" /> Reglas de tu empresa que cambian esta pantalla</h3>
          <ul className="space-y-2">
            {topic.rules.map((rule) => (
              <li key={rule.title} className="p-3.5 rounded-xl bg-slate-50 border border-slate-200">
                <span className="block text-sm font-semibold text-slate-800">{rule.title}</span>
                <span className="block mt-1 text-sm text-slate-600 leading-relaxed">{rule.text}</span>
                {rule.where && <span className="block mt-1.5 text-xs font-medium text-brand-accent">Se cambia en {rule.where}.</span>}
              </li>
            ))}
          </ul>
        </section>
      )}
    </div>
  );
}
