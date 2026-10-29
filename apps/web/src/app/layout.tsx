import { Plus_Jakarta_Sans, JetBrains_Mono } from 'next/font/google';
import './globals.css';
import { PlatformProvider } from '@/hooks/usePlatform';
import { PlatformStyle } from './components/layout/BrandStyle';
import MaintenanceBanner from './components/layout/MaintenanceBanner';

const plusJakarta = Plus_Jakarta_Sans({
  subsets: ['latin'],
  variable: '--font-sans',
  weight: ['400', '500', '600', '700', '800'],
  display: 'swap',
});

const jetbrainsMono = JetBrains_Mono({
  subsets: ['latin'],
  variable: '--font-mono',
  display: 'swap',
});

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="es" className={`${plusJakarta.variable} ${jetbrainsMono.variable}`}>
      <body className="font-sans bg-[#F2F2F2] text-slate-900 antialiased">
        <PlatformProvider>
          <PlatformStyle />
          <MaintenanceBanner />
          {children}
        </PlatformProvider>
      </body>
    </html>
  );
}