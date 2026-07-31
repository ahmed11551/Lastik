import React from 'react';
import { useApp } from '../context/AppContext';
import {
  LayoutDashboard,
  ShoppingBag,
  PlusCircle,
  Users,
  Car,
  Boxes,
  Receipt,
  TrendingUp,
  CheckSquare,
  History,
  UserCog,
  Building2,
  Blocks,
  Tv,
  ChevronRight,
  Terminal
} from 'lucide-react';

export type ViewTab =
  | 'dashboard'
  | 'orders'
  | 'new_order'
  | 'customers'
  | 'vehicles'
  | 'stock'
  | 'shifts'
  | 'kpi'
  | 'tasks'
  | 'audit'
  | 'users'
  | 'tenants'
  | 'modules'
  | 'tv_display';

interface SidebarProps {
  currentTab: ViewTab;
  onSelectTab: (tab: ViewTab) => void;
  collapsed?: boolean;
}

export const Sidebar: React.FC<SidebarProps> = ({ currentTab, onSelectTab }) => {
  const { activeUser, activeShift, tasks } = useApp();

  const pendingTasksCount = tasks.filter((t) => t.status === 'pending').length;

  const navItems = [
    { id: 'dashboard', label: 'Дашборд', icon: LayoutDashboard, roles: ['platform_owner', 'superadmin', 'admin', 'manager', 'cashier', 'accountant', 'master'] },
    { id: 'orders', label: 'Заказы покупателей', icon: ShoppingBag, roles: ['platform_owner', 'superadmin', 'admin', 'manager', 'cashier', 'accountant', 'master'] },
    { id: 'new_order', label: 'Оформить заказ', icon: PlusCircle, highlight: true, roles: ['superadmin', 'admin', 'manager', 'cashier'] },
    { id: 'customers', label: 'Покупатели & Импорт', icon: Users, roles: ['superadmin', 'admin', 'manager', 'accountant'] },
    { id: 'vehicles', label: 'Автомобили', icon: Car, roles: ['superadmin', 'admin', 'manager', 'master'] },
    { id: 'stock', label: 'Склад & 1С Остатки', icon: Boxes, roles: ['superadmin', 'admin', 'manager', 'accountant', 'master', 'storekeeper'] },
    { id: 'shifts', label: 'Управленческая касса', icon: Receipt, badge: activeShift ? 'Смена открыта' : undefined, roles: ['superadmin', 'admin', 'cashier', 'accountant'] },
    { id: 'kpi', label: 'Выработка & KPI', icon: TrendingUp, roles: ['superadmin', 'admin', 'manager', 'accountant', 'master'] },
    { id: 'tasks', label: 'Задачи', icon: CheckSquare, badgeCount: pendingTasksCount, roles: ['superadmin', 'admin', 'manager', 'master'] },
    { id: 'audit', label: 'Журнал действий', icon: History, roles: ['platform_owner', 'superadmin', 'admin', 'accountant'] },
    { id: 'users', label: 'Пользователи & Устройства', icon: UserCog, roles: ['platform_owner', 'superadmin', 'admin'] },
    { id: 'tenants', label: 'Организации & Точки', icon: Building2, roles: ['platform_owner', 'superadmin'] },
    { id: 'modules', label: 'Модули LASTIK', icon: Blocks, roles: ['platform_owner', 'superadmin', 'admin'] },
    { id: 'tv_display', label: 'TV-Экран очереди', icon: Tv, roles: ['platform_owner', 'superadmin', 'admin', 'manager', 'master'] }
  ];

  return (
    <aside className="w-full md:w-64 bg-black/30 border-r border-white/10 backdrop-blur-md flex flex-col shrink-0 text-slate-300">
      <div className="p-3 space-y-1 overflow-y-auto">
        <div className="text-[10px] font-mono uppercase font-bold tracking-widest text-slate-500 px-3 py-2 flex items-center justify-between">
          <span>СИСТЕМНОЕ МЕНЮ</span>
          <Terminal className="w-3 h-3 text-cyan-500/60" />
        </div>

        {navItems.map((item) => {
          // Check role permission
          if (activeUser && !item.roles.includes(activeUser.role) && activeUser.role !== 'platform_owner') {
            return null;
          }

          const isActive = currentTab === item.id;
          const Icon = item.icon;

          return (
            <button
              key={item.id}
              onClick={() => onSelectTab(item.id as ViewTab)}
              className={`w-full flex items-center justify-between px-3 py-2.5 rounded-xl text-xs transition-all cursor-pointer font-sans ${
                isActive
                  ? 'bg-cyan-500/15 text-cyan-300 border border-cyan-500/40 font-semibold shadow-[0_0_15px_rgba(6,182,212,0.2)]'
                  : item.highlight
                  ? 'bg-gradient-to-r from-cyan-600/15 to-blue-600/15 text-cyan-200 border border-cyan-500/30 hover:bg-cyan-600/25'
                  : 'text-slate-300 border border-transparent hover:bg-white/5 hover:border-white/10 hover:text-white'
              }`}
            >
              <div className="flex items-center gap-2.5 min-w-0">
                <Icon className={`w-4 h-4 shrink-0 transition-colors ${isActive ? 'text-cyan-400' : 'text-slate-400'}`} />
                <span className="truncate">{item.label}</span>
              </div>

              <div className="flex items-center gap-1.5 shrink-0">
                {item.badge && (
                  <span className="text-[9px] font-mono font-bold bg-emerald-500/20 text-emerald-400 border border-emerald-500/40 px-1.5 py-0.5 rounded-full shadow-[0_0_8px_rgba(52,211,153,0.3)]">
                    {item.badge}
                  </span>
                )}
                {item.badgeCount !== undefined && item.badgeCount > 0 ? (
                  <span className="text-[10px] font-mono font-bold bg-cyan-400 text-slate-950 px-1.5 py-0.2 rounded-full shadow-[0_0_8px_rgba(6,182,212,0.5)]">
                    {item.badgeCount}
                  </span>
                ) : null}
                <ChevronRight className={`w-3.5 h-3.5 opacity-40 transition-opacity ${isActive ? 'text-cyan-400 opacity-100' : ''}`} />
              </div>
            </button>
          );
        })}
      </div>

      <div className="mt-auto p-3 border-t border-white/10 text-[11px] text-slate-400 font-mono flex items-center justify-between bg-black/20">
        <span className="flex items-center gap-1.5">
          <span className="w-2 h-2 rounded-full bg-cyan-400 animate-pulse"></span>
          LASTIK ORBITAL
        </span>
        <span className="text-emerald-400 text-[10px] bg-emerald-950/60 border border-emerald-800/50 px-1.5 py-0.5 rounded">
          ONLINE
        </span>
      </div>
    </aside>
  );
};
