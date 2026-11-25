'use client';

import { useEffect, useState } from 'react';
import { PlayCircle, X } from 'lucide-react';
import { secondsOf, videoEmbed } from '../web';

const withStart = (src: string, seconds: number, autoplay: boolean) => {
  const url = new URL(src);
  if (seconds > 0) {
    if (src.includes('vimeo')) url.hash = `t=${seconds}s`;
    else url.searchParams.set('start', String(seconds));
  }
  if (autoplay) url.searchParams.set('autoplay', '1');
  return url.toString();
};

export function VideoChapters({ video }: { video: { url: string; title: string | null; chapters: { time: string; label: string }[] } }) {
  const embed = videoEmbed(video.url);
  const [start, setStart] = useState<{ at: number; play: boolean }>({ at: 0, play: false });

  if (!embed) {
    return (
      <a href={video.url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-2 bg-brand text-white font-bold px-5 py-3 rounded-xl text-sm">
        <PlayCircle size={18} /> Ver el video
      </a>
    );
  }

  return (
    <div className="grid grid-cols-1 lg:grid-cols-[1fr_16rem] gap-4 items-start" data-video>
      <div className="aspect-video w-full rounded-2xl overflow-hidden bg-slate-900 border border-slate-200 shadow-lg">
        <iframe
          key={`${start.at}-${start.play}`}
          src={withStart(embed, start.at, start.play)}
          title={video.title || 'Video'}
          className="w-full h-full"
          loading="lazy"
          allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
          allowFullScreen
        />
      </div>
      {video.chapters.length > 0 && (
        <ol className="bg-white rounded-2xl border border-slate-200 divide-y divide-slate-100 overflow-hidden" aria-label="Capítulos del video" data-video-chapters>
          {video.chapters.map((chapter) => (
            <li key={`${chapter.time}-${chapter.label}`}>
              <button type="button" onClick={() => setStart({ at: secondsOf(chapter.time), play: true })} className="w-full flex items-center gap-3 px-4 py-3 text-left text-sm hover:bg-slate-50 cursor-pointer">
                <span className="font-mono text-xs font-bold text-brand-accent tabular-nums w-10 shrink-0">{chapter.time}</span>
                <span className="font-semibold text-slate-700">{chapter.label}</span>
              </button>
            </li>
          ))}
        </ol>
      )}
    </div>
  );
}

export function HeroVideoButton({ url }: { url: string }) {
  const [open, setOpen] = useState(false);
  const embed = videoEmbed(url);

  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false); };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open]);

  if (!embed) {
    return (
      <a href={url} target="_blank" rel="noopener noreferrer" data-hero-video className="inline-flex items-center gap-2 text-brand-ink font-bold py-3 px-4 rounded-xl text-sm hover:bg-white/70">
        <PlayCircle size={18} className="text-brand-accent" /> Ver video
      </a>
    );
  }

  return (
    <>
      <button type="button" onClick={() => setOpen(true)} data-hero-video className="inline-flex items-center gap-2 text-brand-ink font-bold py-3 px-4 rounded-xl text-sm hover:bg-white/70 cursor-pointer">
        <PlayCircle size={18} className="text-brand-accent" /> Ver video
      </button>
      {open && (
        <div className="fixed inset-0 z-[70] bg-slate-900/80 flex items-center justify-center p-4" onClick={() => setOpen(false)}>
          <div role="dialog" aria-modal="true" aria-label="Video" className="relative w-full max-w-4xl" onClick={(e) => e.stopPropagation()}>
            <button type="button" onClick={() => setOpen(false)} aria-label="Cerrar video" className="absolute -top-10 right-0 p-2 text-white hover:text-brand-soft cursor-pointer"><X size={22} /></button>
            <div className="aspect-video w-full rounded-2xl overflow-hidden bg-black">
              <iframe src={withStart(embed, 0, true)} title="Video" className="w-full h-full" allow="autoplay; encrypted-media; picture-in-picture" allowFullScreen />
            </div>
          </div>
        </div>
      )}
    </>
  );
}
