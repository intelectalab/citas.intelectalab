'use client';

import { Search, Bell } from 'lucide-react';
import { useAuth } from '@/hooks/useAuth';

export default function Navbar() {
  const { user } = useAuth();

  const userName = user?.first_name 
  ? `${user.first_name} ${user.last_name || ''}`.trim() 
  : 'Usuario';
  const initial = userName.charAt(0).toUpperCase();

  return (
    <header className="h-16 bg-white border-b border-gray-200 px-6 flex items-center justify-between sticky top-0 z-20">

      <div className="flex items-center gap-2 bg-gray-100 px-3 py-1.5 rounded-full w-96 border border-transparent focus-within:border-[#3F768C] focus-within:bg-white transition-all">
        <Search size={18} className="text-gray-400" />
        <input 
          type="text" 
          placeholder="Buscar o preguntar..." 
          className="bg-transparent border-none outline-none text-sm w-full text-gray-700 placeholder-gray-400"
        />
      </div>

      <div className="flex items-center gap-4">
        <button className="p-2 text-gray-600 hover:bg-gray-100 rounded-full transition-colors relative">
          <Bell size={20} />
          <span className="absolute top-1 right-1 w-2 h-2 bg-[#FF7A00] rounded-full"></span>
        </button>

        <div className="flex items-center gap-3 border-l border-gray-200 pl-4">
          <div className="w-8 h-8 rounded-full bg-[#022340] text-white flex items-center justify-center font-semibold text-sm">
            {initial}
          </div>
          <span className="text-sm font-medium text-gray-700">{userName}</span>
        </div>
      </div>
    </header>
  );
}