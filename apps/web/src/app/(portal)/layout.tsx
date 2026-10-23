'use client';

import { AuthProvider } from '@/hooks/useAuth';
import IdleLogout from '../components/layout/IdleLogout';

export default function PortalLayout({ children }: { children: React.ReactNode }) {
  return (
    <AuthProvider>
      <IdleLogout />
      {children}
    </AuthProvider>
  );
}
