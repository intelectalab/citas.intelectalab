'use client';

import { useState, useEffect } from 'react';
import Sidebar from '../components/layout/Sidebar';
import Navbar from '../components/layout/Navbar';
import { AuthProvider } from '@/hooks/useAuth'; // Ajusta la ruta si useAuth está en otro directorio

export default function DashboardLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  const [isCollapsed, setIsCollapsed] = useState(false);
  const [mounted, setMounted] = useState(false);

  useEffect(() => {
    setMounted(true);
  }, []);

  return (
    <AuthProvider>
      <div className={`flex h-screen overflow-hidden ${mounted ? 'bg-[#0B192C]' : 'bg-[#0B192C]'}`}>
        <Sidebar isCollapsed={isCollapsed} setIsCollapsed={setIsCollapsed} />

        <div className="flex-1 flex flex-col min-w-0 bg-[#F2F2F2] rounded-tl-3xl overflow-hidden shadow-inner">
          <Navbar />
          <main className="flex-1 overflow-y-auto p-6">
            {children}
          </main>
        </div>
      </div>
    </AuthProvider>
  );
}