import type { MetadataRoute } from 'next';
import { getSite, siteUrl } from '@/features/web/web';

export default async function robots(): Promise<MetadataRoute.Robots> {
  const { site } = await getSite();
  const url = await siteUrl(site);

  return {
    rules: {
      userAgent: '*',
      allow: ['/', '/rubros', '/precios', '/blog', '/legal', '/reclamaciones', '/register', '/reservar'],
      disallow: ['/admin', '/home', '/agenda', '/portal', '/pay', '/plan-pago', '/cita', '/print', '/kiosko', '/promociones', '/login', '/recuperar', '/restablecer', '/settings', '/companies'],
    },
    sitemap: `${url}/sitemap.xml`,
    host: url,
  };
}
