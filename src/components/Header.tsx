import React, { useEffect, useRef, useState } from 'react';
import { useApp } from '../context/AppContext';
import {
  Building2,
  MapPin,
  UserCheck,
  ShieldCheck,
  Search,
  Laptop,
  KeyRound,
  Plus,
  Cpu,
  Radio
} from 'lucide-react';
import { isLaravelApiEnabled, searchLaravel, LaravelApiError, type LaravelSearchResult } from '../api/laravelClient';

interface HeaderProps {
  onSearchChange?: (term: string) => void;
  onOpenNewOrder?: () => void;
}

export const Header: React.FC<HeaderProps> = ({ onSearchChange, onOpenNewOrder }) => {
  const {
    activeUser,
    activeTenant,
    activeLocation,
    activeShift,
    tenants,
    locations,
    users,
    switchUser,
    switchTenant,
    switchLocation,
    toggleSupportAccess
  } = useApp();

  const [searchTerm, setSearchTerm] = useState('');
  const [apiResults, setApiResults] = useState<LaravelSearchResult | null>(null);
  const [apiError, setApiError] = useState<string | null>(null);
  const [supportModalOpen, setSupportModalOpen] = useState(false);
  const [supportReason, setSupportReason] = useState('');
  const searchTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  const handleSearch = (e: React.ChangeEvent<HTMLInputElement>) => {
    const val = e.target.value;
    setSearchTerm(val);
    if (onSearchChange) onSearchChange(val);

    if (!isLaravelApiEnabled()) {
      setApiResults(null);
      setApiError(null);
      return;
    }

    if (searchTimer.current) clearTimeout(searchTimer.current);
    if (val.trim().length < 2) {
      setApiResults(null);
      setApiError(null);
      return;
    }

    searchTimer.current = setTimeout(() => {
      searchLaravel(val)
        .then((data) => {
          setApiResults(data);
          setApiError(null);
        })
        .catch((err: unknown) => {
          setApiResults(null);
          setApiError(err instanceof LaravelApiError ? err.toUserMessage() : 'Ошибка поиска');
        });
    }, 280);
  };

  useEffect(() => () => {
    if (searchTimer.current) clearTimeout(searchTimer.current);
  }, []);

  const handleSupportToggle = async () => {
    if (!activeTenant) return;
    if (activeTenant.supportAccessEnabled) {
      await toggleSupportAccess(false, '');
      setSupportModalOpen(false);
    } else {
      if (!supportReason.trim()) return;
      await toggleSupportAccess(true, supportReason);
      setSupportModalOpen(false);
      setSupportReason('');
    }
  };

  return (
    <header className="sticky top-0 z-30 bg-black/40 backdrop-blur-md border-b border-white/10 text-slate-100 px-4 py-2.5 shadow-[0_4px_20px_rgba(0,0,0,0.5)]">
      <div className="flex flex-col md:flex-row items-center justify-between gap-3 max-w-7xl mx-auto">
        
        {/* Left Brand & Tenant Context */}
        <div className="flex items-center gap-3 w-full md:w-auto justify-between md:justify-start">
          <div className="flex items-center gap-2.5">
            <div className="w-9 h-9 rounded-lg bg-gradient-to-br from-cyan-500 to-blue-600 flex items-center justify-center font-mono font-bold text-white text-lg shadow-[0_0_15px_rgba(6,182,212,0.4)] tracking-tight">
              L
            </div>
            <div>
              <div className="font-mono font-bold tracking-widest text-white text-base leading-none flex items-center gap-2">
                LASTIK
                <span className="text-[10px] bg-cyan-500/10 text-cyan-400 font-mono px-2 py-0.5 rounded border border-cyan-500/30 shadow-[0_0_10px_rgba(6,182,212,0.2)]">
                  v1.0 ERP
                </span>
              </div>
              <div className="text-[11px] text-slate-400 font-mono leading-tight mt-0.5 flex items-center gap-1">
                <Radio className="w-3 h-3 text-cyan-400 animate-pulse" />
                <span>ORBITAL TYRE PLATFORM</span>
              </div>
            </div>
          </div>

          {/* Tenant Selector Dropdown */}
          <div className="flex items-center gap-2 bg-white/5 px-2.5 py-1.5 rounded-lg border border-white/10 hover:border-white/20 transition-all">
            <Building2 className="w-4 h-4 text-cyan-400 shrink-0" />
            <select
              value={activeTenant?.id || ''}
              onChange={(e) => switchTenant(e.target.value)}
              className="bg-transparent text-xs text-slate-200 font-semibold focus:outline-none cursor-pointer max-w-[160px] truncate"
            >
              {tenants.map((t) => (
                <option key={t.id} value={t.id} className="bg-slate-900 text-slate-200">
                  {t.name}
                </option>
              ))}
            </select>
          </div>

          {/* Location Selector Dropdown */}
          <div className="hidden sm:flex items-center gap-2 bg-white/5 px-2.5 py-1.5 rounded-lg border border-white/10 hover:border-white/20 transition-all">
            <MapPin className="w-4 h-4 text-emerald-400 shrink-0" />
            <select
              value={activeLocation?.id || ''}
              onChange={(e) => switchLocation(e.target.value)}
              className="bg-transparent text-xs text-slate-200 font-medium focus:outline-none cursor-pointer max-w-[180px] truncate"
            >
              {locations
                .filter((l) => l.tenantId === activeTenant?.id)
                .map((loc) => (
                  <option key={loc.id} value={loc.id} className="bg-slate-900 text-slate-200">
                    {loc.name}
                  </option>
                ))}
            </select>
          </div>
        </div>

        {/* Global Search Bar */}
        <div className="relative w-full md:w-72">
          <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-cyan-400/70" />
          <input
            type="text"
            value={searchTerm}
            onChange={handleSearch}
            placeholder="Поиск по тел, госномеру, № заказа..."
            className="w-full bg-white/5 border border-white/10 rounded-lg pl-9 pr-3 py-1.5 text-xs text-slate-100 placeholder-slate-400 focus:outline-none focus:border-cyan-400 focus:shadow-[0_0_15px_rgba(6,182,212,0.25)] transition-all font-sans"
          />
          {apiError && (
            <div className="absolute left-0 right-0 top-full mt-1 z-50 rounded-lg border border-rose-500/40 bg-rose-950/95 px-3 py-2 text-xs text-rose-200 shadow-xl">
              {apiError}
            </div>
          )}
          {!apiError && apiResults && (
            <div className="absolute left-0 right-0 top-full mt-1 z-50 max-h-72 overflow-auto rounded-lg border border-white/10 bg-slate-950/95 shadow-xl text-xs">
              {apiResults.orders.map((o) => (
                <div key={`o-${o.id}`} className="px-3 py-2 border-b border-white/5 text-slate-200">
                  Заказ <span className="font-mono text-cyan-300">{o.number}</span>
                  <span className="text-slate-500 ml-2">{o.status}</span>
                </div>
              ))}
              {apiResults.customers.map((c) => (
                <div key={`c-${c.id}`} className="px-3 py-2 border-b border-white/5 text-slate-200">
                  {c.name}
                  <span className="text-slate-500 ml-2">{c.phone}</span>
                </div>
              ))}
              {apiResults.vehicles.map((v) => (
                <div key={`v-${v.id}`} className="px-3 py-2 border-b border-white/5 text-slate-200">
                  <span className="font-mono text-emerald-300">{v.plate}</span>
                  <span className="text-slate-500 ml-2">{[v.brand, v.model].filter(Boolean).join(' ')}</span>
                </div>
              ))}
              {!apiResults.orders.length && !apiResults.customers.length && !apiResults.vehicles.length && (
                <div className="px-3 py-2 text-slate-500">Ничего не найдено</div>
              )}
            </div>
          )}
        </div>

        {/* Right Status Badges & Quick Role Switcher */}
        <div className="flex items-center gap-2.5 w-full md:w-auto justify-end">
          
          {/* New Order Quick Action Button */}
          {onOpenNewOrder && (
            <button
              onClick={onOpenNewOrder}
              className="bg-gradient-to-r from-cyan-600 to-blue-600 hover:from-cyan-500 hover:to-blue-500 text-white px-3.5 py-1.5 rounded-lg text-xs font-bold font-mono tracking-wide flex items-center gap-1.5 shadow-[0_0_15px_rgba(6,182,212,0.3)] transition-all cursor-pointer shrink-0 border border-cyan-400/40"
            >
              <Plus className="w-4 h-4" />
              <span>Создать заказ</span>
            </button>
          )}

          {/* Cash Shift Indicator */}
          <div className="hidden lg:flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-mono border border-white/10 bg-white/5">
            {activeShift ? (
              <span className="flex items-center gap-1.5 text-emerald-400 font-semibold">
                <span className="w-2 h-2 rounded-full bg-emerald-400 shadow-[0_0_8px_rgba(52,211,153,0.8)] animate-pulse" />
                Смена ({activeShift.cashInflow + activeShift.openingBalance} ₽)
              </span>
            ) : (
              <span className="flex items-center gap-1.5 text-slate-400">
                <span className="w-2 h-2 rounded-full bg-slate-500" />
                Смена закрыта
              </span>
            )}
          </div>

          {/* Support Mode Badge for Platform Owner */}
          {activeUser?.role === 'platform_owner' && (
            <button
              onClick={() => setSupportModalOpen(true)}
              className={`px-2.5 py-1 rounded-lg text-xs font-mono font-semibold flex items-center gap-1.5 border transition-all cursor-pointer ${
                activeTenant?.supportAccessEnabled
                  ? 'bg-amber-500/20 text-amber-300 border-amber-500/40 shadow-[0_0_15px_rgba(245,158,11,0.3)] animate-pulse'
                  : 'bg-white/5 text-slate-400 border-white/10 hover:text-slate-200 hover:border-white/20'
              }`}
              title="Режим поддержки владельца платформы"
            >
              <ShieldCheck className="w-3.5 h-3.5 text-amber-400" />
              <span>
                {activeTenant?.supportAccessEnabled ? 'Поддержка вкл.' : 'ТехПоддержка'}
              </span>
            </button>
          )}

          {/* Active Device Counter */}
          <div className="hidden sm:flex items-center gap-1.5 text-[11px] font-mono text-slate-400 bg-white/5 px-2.5 py-1 rounded-lg border border-white/10" title="Максимум 2 устройства на пользователя">
            <Laptop className="w-3.5 h-3.5 text-cyan-400" />
            <span>{activeUser?.devices.length || 1}/2 устр.</span>
          </div>

          {/* User Role Switcher for Testing Context */}
          <div className="flex items-center gap-1.5 bg-white/5 px-2.5 py-1 rounded-lg border border-white/10 hover:border-white/20 transition-all">
            <UserCheck className="w-4 h-4 text-cyan-400 shrink-0" />
            <select
              value={activeUser?.id || ''}
              onChange={(e) => switchUser(e.target.value)}
              className="bg-transparent text-xs text-slate-200 font-semibold focus:outline-none cursor-pointer max-w-[150px] truncate"
            >
              {users.map((u) => (
                <option key={u.id} value={u.id} className="bg-slate-900 text-slate-200">
                  {u.name} ({u.roleName})
                </option>
              ))}
            </select>
          </div>
        </div>
      </div>

      {/* Support Mode Modal */}
      {supportModalOpen && (
        <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-md flex items-center justify-center p-4">
          <div className="bg-[#080d1a] border border-white/15 rounded-2xl max-w-md w-full p-6 shadow-[0_0_40px_rgba(0,0,0,0.8)] text-slate-100 relative overflow-hidden">
            <div className="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-amber-500 to-orange-500"></div>
            
            <div className="flex items-center gap-3 mb-4 text-amber-400 font-mono">
              <KeyRound className="w-6 h-6 animate-pulse" />
              <h3 className="text-base font-bold tracking-wide">
                ТЕХНИЧЕСКИЙ ДОСТУП ПЛАТФОРМЫ
              </h3>
            </div>
            <p className="text-xs text-slate-300 leading-relaxed mb-4">
              По умолчанию Владелец Платформы не видит внутренние коммерческие данные, заказы и покупателей организации.
              Активация режима создаст обязательную аудиторскую запись с указанием причины. Срок действия: 8 часов.
            </p>

            {!activeTenant?.supportAccessEnabled ? (
              <div className="mb-5">
                <label className="block text-xs font-mono font-semibold text-slate-300 mb-1.5">
                  Причина включения технического доступа <span className="text-amber-400">*</span>
                </label>
                <textarea
                  value={supportReason}
                  onChange={(e) => setSupportReason(e.target.value)}
                  placeholder="Например: Заявка от суперадмина #4912 по разбору ошибки кассовой смены..."
                  className="w-full bg-white/5 border border-white/10 rounded-xl p-3 text-xs text-slate-100 focus:outline-none focus:border-amber-400 h-24 font-sans"
                />
              </div>
            ) : (
              <div className="p-3 bg-amber-500/10 border border-amber-500/30 rounded-xl mb-5 text-xs text-amber-200 font-mono">
                Режим технического доступа сейчас <strong className="text-amber-300">АКТИВЕН</strong>.
                Причина: {activeTenant?.supportAccessReason}
              </div>
            )}

            <div className="flex items-center justify-end gap-2.5">
              <button
                onClick={() => setSupportModalOpen(false)}
                className="px-4 py-2 rounded-xl text-xs font-mono text-slate-300 hover:bg-white/5 border border-transparent hover:border-white/10"
              >
                Отмена
              </button>
              <button
                onClick={handleSupportToggle}
                className="px-4 py-2 rounded-xl text-xs font-mono font-bold text-slate-950 bg-amber-400 hover:bg-amber-300 shadow-[0_0_15px_rgba(245,158,11,0.4)] transition-all"
              >
                {activeTenant?.supportAccessEnabled ? 'Деактивировать доступ' : 'Подтвердить и включить'}
              </button>
            </div>
          </div>
        </div>
      )}
    </header>
  );
};
