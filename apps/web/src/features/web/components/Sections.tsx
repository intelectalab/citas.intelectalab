import Link from 'next/link';
import { ArrowRight, ChevronDown, Clock } from 'lucide-react';
import { shortDate, type SiteFaq, type SitePayload, type WebBusinessType, type WebPost } from '../web';

export function BusinessTypeGrid({ types }: { types: WebBusinessType[] }) {
  return (
    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4" data-business-types>
      {types.map((type, index) => (
        <Link key={type.slug} href={`/rubros/${type.slug}`} data-reveal style={{ ['--d' as string]: `${(index % 3) * 80}ms` }} className="site-lift group bg-slate-50/60 hover:bg-white rounded-2xl border border-slate-200 hover:border-brand-soft p-5 flex gap-4 items-start min-w-0">
          <span className="w-10 h-10 rounded-xl shrink-0 flex items-center justify-center text-white font-black text-sm transition-transform duration-300 group-hover:scale-110 group-hover:-rotate-6" style={{ background: type.color ?? undefined }} aria-hidden="true">
            {type.short_name.slice(0, 1)}
          </span>
          <span className="min-w-0">
            <span className="block font-bold text-brand-ink group-hover:text-brand-accent">{type.name}</span>
            {type.description && <span className="block text-sm text-slate-500 mt-0.5">{type.description}</span>}
          </span>
        </Link>
      ))}
    </div>
  );
}

export function Faq({ items }: { items: SiteFaq[] }) {
  return (
    <div className="space-y-2" data-faq>
      {items.map((item) => (
        <details key={item.q} className="group bg-white rounded-2xl border border-slate-200 px-5 py-4">
          <summary className="flex items-center justify-between gap-3 cursor-pointer list-none font-bold text-brand-ink">
            {item.q}
            <ChevronDown size={18} className="shrink-0 text-slate-400 transition-transform group-open:rotate-180" />
          </summary>
          <p className="mt-2 text-sm text-slate-600 leading-relaxed">{item.a}</p>
        </details>
      ))}
    </div>
  );
}

export function PostCards({ posts }: { posts: WebPost[] }) {
  return (
    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5" data-post-cards>
      {posts.map((post) => (
        <Link key={post.id} href={`/blog/${post.slug}`} className="group bg-white rounded-2xl border border-slate-200 overflow-hidden hover:shadow-md transition-shadow flex flex-col">
          {post.cover_url ? (
            <img src={post.cover_url} alt="" className="w-full aspect-[1200/630] object-cover" loading="lazy" />
          ) : (
            <span className="block w-full aspect-[1200/630] bg-brand-soft/20" aria-hidden="true" />
          )}
          <span className="p-5 flex-1 flex flex-col gap-2">
            {post.business_type_name && <span className="text-[11px] font-bold text-brand-accent">{post.business_type_name}</span>}
            <span className="font-bold text-brand-ink group-hover:text-brand-accent leading-snug">{post.title}</span>
            {post.excerpt && <span className="text-sm text-slate-500 line-clamp-3">{post.excerpt}</span>}
            <span className="mt-auto pt-2 text-xs text-slate-400 flex items-center gap-1.5"><Clock size={12} /> {post.reading_minutes} min · {shortDate(post.published_at)}</span>
          </span>
        </Link>
      ))}
    </div>
  );
}

const WEEK = [
  { day: 'Lun', slots: [1, 1, 1, 0] },
  { day: 'Mar', slots: [1, 1, 0, 1] },
  { day: 'Mié', slots: [1, 0, 1, 1], today: true },
  { day: 'Jue', slots: [0, 1, 0, 0] },
  { day: 'Vie', slots: [1, 0, 0, 1] },
  { day: 'Sáb', slots: [0, 1, 0, 0] },
];

export function SignupBand({ site, rubro }: { site: SitePayload; rubro?: string }) {
  return (
    <div className="w-full bg-brand text-white rounded-3xl p-6 sm:p-8 flex flex-col gap-3" data-signup-band>
      <h2 className="text-2xl sm:text-3xl font-black tracking-tight">{site.texts.cta_title}</h2>
      {site.texts.cta_text && <p className="text-sm text-white/80">{site.texts.cta_text}</p>}
      {site.allow_signup ? (
        <Link href={rubro ? `/register?rubro=${rubro}` : '/register'} data-cta="band" className="group self-start inline-flex items-center gap-2 bg-white text-brand-ink font-bold text-sm px-5 py-3 rounded-xl hover:bg-slate-100">
          Crear mi cuenta gratis <ArrowRight size={16} className="transition-transform group-hover:translate-x-1" />
        </Link>
      ) : (
        <p className="text-sm font-semibold">El registro está cerrado por ahora{site.email ? `: escríbenos a ${site.email}` : ''}.</p>
      )}
      <div className="mt-auto pt-5" aria-hidden="true" data-signup-week>
        <span className="block text-[11px] font-bold text-brand-soft mb-2">Así se llena tu semana</span>
        <div className="grid grid-cols-6 gap-1.5 sm:gap-2">
          {WEEK.map((item) => (
            <div key={item.day} className={`rounded-xl p-1.5 sm:p-2 space-y-1 ${item.today ? 'bg-white/15 ring-1 ring-brand-soft' : 'bg-white/5'}`}>
              <span className={`block text-center text-[10px] font-bold ${item.today ? 'text-white' : 'text-white/60'}`}>{item.day}</span>
              {item.slots.map((filled, index) => (
                <span key={index} className={`block h-2 rounded-full ${filled ? 'bg-brand-soft' : 'bg-white/10'} ${item.today && !filled ? 'site-breathe bg-white/40' : ''}`} />
              ))}
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
