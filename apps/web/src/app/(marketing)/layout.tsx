import SiteShell, { baseMetadata } from '@/features/web/components/SiteShell';

export const generateMetadata = baseMetadata;

export default function MarketingLayout({ children }: { children: React.ReactNode }) {
  return <SiteShell>{children}</SiteShell>;
}
