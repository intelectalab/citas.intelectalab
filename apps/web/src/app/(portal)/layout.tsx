'use client';

import { AuthProvider } from '@/hooks/useAuth';
import IdleLogout from '../components/layout/IdleLogout';
import BrandStyle from '../components/layout/BrandStyle';

export default function PortalLayout({ children }: { children: React.ReactNode }) {
  return (
    <AuthProvider>
      <IdleLogout />
      <BrandStyle />
      {children}
    </AuthProvider>
  );
}
