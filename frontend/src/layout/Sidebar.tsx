import { useEffect, useState } from 'react';
import { NavLink, useLocation } from 'react-router-dom';
import { ChevronRight } from 'lucide-react';
import { cn } from '@/lib/cn';
import { auth } from '@/lib/auth';
import { sections, findActive } from './sections';

const STORAGE_KEY = 'erp.sidebar.expandedSection';

export function Sidebar() {
  const user = auth.getUser();
  const { pathname } = useLocation();
  const initials = user?.name
    ? user.name
        .split(' ')
        .slice(0, 2)
        .map((s) => s[0])
        .join('')
        .toUpperCase()
    : 'US';

  const activeSection = findActive(pathname)?.section.label ?? null;
  const [expanded, setExpanded] = useState<string | null>(() => {
    // Prioridad al render: la sección de la ruta actual; si no, lo último
    // recordado; default 'General'.
    if (activeSection) return activeSection;
    return localStorage.getItem(STORAGE_KEY) ?? 'General';
  });

  // Si el usuario navega a una sección distinta (vía link interno o URL
  // directa), expandirla automáticamente.
  useEffect(() => {
    if (activeSection && activeSection !== expanded) {
      setExpanded(activeSection);
    }
  }, [activeSection]); // eslint-disable-line react-hooks/exhaustive-deps

  const toggle = (label: string) => {
    setExpanded((prev) => {
      const next = prev === label ? null : label;
      if (next) localStorage.setItem(STORAGE_KEY, next);
      return next;
    });
  };

  return (
    <aside className="w-[230px] bg-navy-800 text-[#DCE5F0] flex flex-col shrink-0 min-h-full">
      {/* Brand */}
      <div className="px-[18px] py-5 pb-[18px] border-b border-white/10">
        <div className="flex items-center gap-[10px]">
          <div className="w-9 h-9 rounded-lg bg-gradient-to-br from-azure to-navy-600 flex items-center justify-center text-white font-bold text-base shadow-brand">
            LA
          </div>
          <div>
            <div className="text-[13px] font-semibold text-white leading-tight">
              Logística Argentina
            </div>
            <div className="text-[10px] text-[#7EA3CC] uppercase tracking-[0.5px] mt-[2px]">
              ERP · SRL
            </div>
          </div>
        </div>
      </div>

      {/* Nav sections (acordeón exclusivo) */}
      <nav className="flex-1 overflow-y-auto scrollbar-thin pb-2">
        {sections.map((sec) => {
          const open = expanded === sec.label;
          return (
            <div key={sec.label}>
              <button
                type="button"
                onClick={() => toggle(sec.label)}
                className="w-full flex items-center justify-between px-[18px] pt-[14px] pb-[5px] text-[10px] font-semibold tracking-wider text-[#7EA3CC] uppercase hover:text-white"
              >
                <span>{sec.label}</span>
                <ChevronRight
                  className={cn(
                    'w-3 h-3 transition-transform duration-150',
                    open && 'rotate-90'
                  )}
                  strokeWidth={2}
                />
              </button>
              {open && (
                <div>
                  {sec.items.map((item) => {
                    const Icon = item.icon;
                    return (
                      <NavLink
                        key={item.to}
                        to={item.to}
                        className={({ isActive }) =>
                          cn(
                            'flex items-center gap-[11px] px-[18px] py-[7px] text-[12.5px] cursor-pointer border-l-[3px] border-transparent transition-colors select-none',
                            isActive
                              ? 'bg-gradient-to-r from-azure/15 to-transparent text-white border-l-azure font-medium'
                              : 'text-[#CFDAE7] hover:bg-white/5 hover:text-white'
                          )
                        }
                      >
                        <Icon className="w-[16px] h-[16px] opacity-85 shrink-0" strokeWidth={1.7} />
                        {item.label}
                      </NavLink>
                    );
                  })}
                </div>
              )}
            </div>
          );
        })}
      </nav>

      {/* User footer */}
      <div className="mt-auto border-t border-white/10 px-[18px] py-[14px] text-[11px] text-[#7EA3CC]">
        <div className="flex items-center gap-[10px] py-[6px]">
          <div className="w-8 h-8 rounded-full bg-azure flex items-center justify-center text-white font-semibold text-[12px]">
            {initials}
          </div>
          <div className="leading-tight">
            <div className="font-medium text-white text-[12px]">{user?.name ?? 'Invitado'}</div>
            <div className="text-[10px] text-[#7EA3CC]">{user ? 'Super Admin' : 'Sin sesión'}</div>
          </div>
        </div>
      </div>
    </aside>
  );
}
