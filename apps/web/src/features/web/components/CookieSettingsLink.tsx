'use client';

export default function CookieSettingsLink() {
  return (
    <button type="button" data-cookie-settings onClick={() => window.dispatchEvent(new Event('cookie-settings'))} className="text-xs font-semibold text-brand-accent hover:text-brand-ink cursor-pointer">
      (elegir)
    </button>
  );
}
