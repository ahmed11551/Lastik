import React from 'react';
import { useApp } from '../context/AppContext';
import {
  ShoppingBag,
  TrendingUp,
  Receipt,
  Boxes,
  CheckCircle2,
  Clock,
  ArrowUpRight,
  ShieldAlert,
  Car,
  Activity,
  Zap,
  Radio
} from 'lucide-react';

interface DashboardViewProps {
  onOpenNewOrder?: () => void;
  onNavigateView: (tab: any) => void;
}

export const DashboardView: React.FC<DashboardViewProps> = ({ onOpenNewOrder, onNavigateView }) => {
  const {
    orders,
    stock,
    tasks,
    auditLogs,
    activeShift,
    activeLocation,
    kpiRecords
  } = useApp();

  const activeOrdersCount = orders.filter((o) => o.status !== 'completed' && o.status !== 'cancelled').length;
  const todayRevenue = orders
    .filter((o) => o.status !== 'cancelled' && o.paymentStatus === 'paid')
    .reduce((sum, o) => sum + o.paidAmount, 0);

  const totalReservedStock = stock.reduce((sum, s) => sum + s.reservedQty, 0);
  const pendingTasks = tasks.filter((t) => t.status === 'pending');

  const totalKpiEarned = kpiRecords.reduce((sum, k) => sum + k.kpiEarned, 0);

  return (
    <div className="space-y-6">
      {/* Top Banner & Context Telemetry */}
      <div className="bg-white/5 border border-white/10 rounded-2xl p-6 text-slate-100 backdrop-blur-md shadow-[0_0_30px_rgba(0,0,0,0.5)] flex flex-col md:flex-row items-start md:items-center justify-between gap-4 relative overflow-hidden">
        <div className="absolute top-0 right-0 w-64 h-64 bg-cyan-500/10 rounded-full blur-[80px] pointer-events-none"></div>
        
        <div className="relative z-10">
          <div className="flex items-center gap-2 text-xs font-mono font-bold text-cyan-400 uppercase tracking-widest mb-1.5">
            <span className="w-2 h-2 rounded-full bg-cyan-400 shadow-[0_0_8px_rgba(6,182,212,0.8)] animate-pulse" />
            ОПЕРАЦИОННЫЙ ЦЕНТР // COMMAND DECK
          </div>
          <h1 className="text-xl font-mono font-bold text-white tracking-wide">
            Сводка по локации: <span className="text-cyan-300">{activeLocation?.name || 'Все точки'}</span>
          </h1>
          <p className="text-xs text-slate-400 font-sans mt-1">
            Ядро LASTIK v1.0 • Синхронизировано с кассовой сменой и 1С-складом
          </p>
        </div>

        <div className="flex items-center gap-3 relative z-10">
          <button
            onClick={() => onNavigateView('new_order')}
            className="bg-gradient-to-r from-cyan-600 to-blue-600 hover:from-cyan-500 hover:to-blue-500 text-white px-4 py-2.5 rounded-xl font-mono font-bold text-xs shadow-[0_0_20px_rgba(6,182,212,0.3)] border border-cyan-400/40 transition-all flex items-center gap-2 cursor-pointer"
          >
            <Zap className="w-4 h-4 text-cyan-200" />
            <span>+ НОВЫЙ ЗАКАЗ ПОКУПАТЕЛЯ</span>
          </button>
        </div>
      </div>

      {/* Metric Cards Grid */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        
        {/* Card 1: Today Sales */}
        <div className="bg-white/5 border border-white/10 rounded-2xl p-5 backdrop-blur-md shadow-[0_0_15px_rgba(0,0,0,0.3)] hover:border-cyan-500/40 hover:shadow-[0_0_20px_rgba(6,182,212,0.15)] transition-all">
          <div className="flex items-center justify-between mb-3">
            <span className="text-xs font-mono uppercase tracking-widest text-slate-400">Выручка за день</span>
            <div className="w-8 h-8 rounded-xl bg-emerald-500/10 border border-emerald-500/30 flex items-center justify-center text-emerald-400 shadow-[0_0_10px_rgba(52,211,153,0.3)]">
              <TrendingUp className="w-4 h-4" />
            </div>
          </div>
          <div className="text-2xl font-mono font-bold text-white tracking-tight">
            {todayRevenue.toLocaleString('ru-RU')} <span className="text-emerald-400 text-lg">₽</span>
          </div>
          <div className="text-[11px] text-emerald-400 mt-2 font-mono flex items-center gap-1">
            <ArrowUpRight className="w-3.5 h-3.5" />
            По оплаченным заказам
          </div>
        </div>

        {/* Card 2: Active Orders */}
        <div className="bg-white/5 border border-white/10 rounded-2xl p-5 backdrop-blur-md shadow-[0_0_15px_rgba(0,0,0,0.3)] hover:border-cyan-500/40 hover:shadow-[0_0_20px_rgba(6,182,212,0.15)] transition-all">
          <div className="flex items-center justify-between mb-3">
            <span className="text-xs font-mono uppercase tracking-widest text-slate-400">Активные Заказы</span>
            <div className="w-8 h-8 rounded-xl bg-cyan-500/10 border border-cyan-500/30 flex items-center justify-center text-cyan-400 shadow-[0_0_10px_rgba(6,182,212,0.3)]">
              <ShoppingBag className="w-4 h-4" />
            </div>
          </div>
          <div className="text-2xl font-mono font-bold text-white tracking-tight">
            {activeOrdersCount}
          </div>
          <div className="text-[11px] text-cyan-400 mt-2 font-mono">
            В работе и на шиномонтаже
          </div>
        </div>

        {/* Card 3: Stock Reservation */}
        <div className="bg-white/5 border border-white/10 rounded-2xl p-5 backdrop-blur-md shadow-[0_0_15px_rgba(0,0,0,0.3)] hover:border-cyan-500/40 hover:shadow-[0_0_20px_rgba(6,182,212,0.15)] transition-all">
          <div className="flex items-center justify-between mb-3">
            <span className="text-xs font-mono uppercase tracking-widest text-slate-400">Товар в Резерве</span>
            <div className="w-8 h-8 rounded-xl bg-indigo-500/10 border border-indigo-500/30 flex items-center justify-center text-indigo-400 shadow-[0_0_10px_rgba(99,102,241,0.3)]">
              <Boxes className="w-4 h-4" />
            </div>
          </div>
          <div className="text-2xl font-mono font-bold text-white tracking-tight">
            {totalReservedStock} <span className="text-sm font-normal text-slate-400">шт</span>
          </div>
          <div className="text-[11px] text-indigo-300 mt-2 font-mono">
            Закреплено за заказами
          </div>
        </div>

        {/* Card 4: Shift Cash Inflow */}
        <div className="bg-white/5 border border-white/10 rounded-2xl p-5 backdrop-blur-md shadow-[0_0_15px_rgba(0,0,0,0.3)] hover:border-cyan-500/40 hover:shadow-[0_0_20px_rgba(6,182,212,0.15)] transition-all">
          <div className="flex items-center justify-between mb-3">
            <span className="text-xs font-mono uppercase tracking-widest text-slate-400">Текущая Касса</span>
            <div className="w-8 h-8 rounded-xl bg-amber-500/10 border border-amber-500/30 flex items-center justify-center text-amber-400 shadow-[0_0_10px_rgba(245,158,11,0.3)]">
              <Receipt className="w-4 h-4" />
            </div>
          </div>
          <div className="text-2xl font-mono font-bold text-white tracking-tight">
            {(activeShift?.totalInflow || 0).toLocaleString('ru-RU')} <span className="text-amber-400 text-lg">₽</span>
          </div>
          <div className="text-[11px] text-amber-400 mt-2 font-mono">
            {activeShift ? `Смена #${activeShift.id.slice(-4)}` : 'Смена закрыта'}
          </div>
        </div>
      </div>

      {/* Operational Widgets Row */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        {/* Widget 1: Active Orders Queue */}
        <div className="lg:col-span-2 bg-white/5 border border-white/10 rounded-2xl p-5 backdrop-blur-md shadow-[0_0_20px_rgba(0,0,0,0.4)]">
          <div className="flex items-center justify-between mb-4">
            <div className="flex items-center gap-2">
              <Car className="w-5 h-5 text-cyan-400" />
              <h2 className="text-sm font-mono font-bold text-white tracking-wide uppercase">
                Текущие заказы на шиномонтаж и самовывоз
              </h2>
            </div>
            <button
              onClick={() => onNavigateView('orders')}
              className="text-xs text-cyan-400 hover:text-cyan-300 font-mono font-semibold"
            >
              Все заказы ({orders.length}) →
            </button>
          </div>

          <div className="space-y-3">
            {orders.slice(0, 4).map((order) => (
              <div
                key={order.id}
                onClick={() => onNavigateView('orders')}
                className="bg-black/40 hover:bg-white/10 border border-white/10 hover:border-cyan-500/30 rounded-xl p-3.5 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 transition-all cursor-pointer"
              >
                <div>
                  <div className="flex items-center gap-2 mb-1">
                    <span className="font-mono font-bold text-sm text-cyan-300">
                      {order.orderNumber}
                    </span>
                    <span
                      className={`text-[10px] font-mono font-bold px-2 py-0.5 rounded-full ${
                        order.scenario === 'with_installation'
                          ? 'bg-cyan-500/20 text-cyan-300 border border-cyan-500/40 shadow-[0_0_8px_rgba(6,182,212,0.3)]'
                          : 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/40'
                      }`}
                    >
                      {order.scenario === 'with_installation' ? 'С УСТАНОВКОЙ' : 'БЕЗ УСТАНОВКИ'}
                    </span>
                  </div>
                  <div className="text-xs text-slate-200 font-medium">
                    {order.customerName} {order.vehicleInfo ? `• ${order.vehicleInfo}` : ''}
                  </div>
                  <div className="text-[11px] text-slate-400 font-mono mt-0.5">
                    Позиций: {order.items.length} • Исполнитель: {order.masterExecutorName || order.responsibleSellerName}
                  </div>
                </div>

                <div className="flex items-center gap-3 self-end sm:self-center">
                  <div className="text-right font-mono">
                    <div className="text-sm font-bold text-white">
                      {order.totalAmount.toLocaleString('ru-RU')} ₽
                    </div>
                    <span
                      className={`text-[10px] font-bold px-2 py-0.5 rounded ${
                        order.paymentStatus === 'paid'
                          ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30'
                          : order.paymentStatus === 'partially_paid'
                          ? 'bg-amber-500/20 text-amber-300 border border-amber-500/30'
                          : 'bg-red-500/20 text-red-300 border border-red-500/30'
                      }`}
                    >
                      {order.paymentStatus === 'paid'
                        ? 'Оплачено'
                        : order.paymentStatus === 'partially_paid'
                        ? 'Частично'
                        : 'Не оплачено'}
                    </span>
                  </div>
                </div>
              </div>
            ))}
          </div>
        </div>

        {/* Widget 2: Tasks & Action Items */}
        <div className="bg-white/5 border border-white/10 rounded-2xl p-5 backdrop-blur-md shadow-[0_0_20px_rgba(0,0,0,0.4)] flex flex-col justify-between">
          <div>
            <div className="flex items-center justify-between mb-4">
              <div className="flex items-center gap-2">
                <Clock className="w-5 h-5 text-amber-400" />
                <h2 className="text-sm font-mono font-bold text-white tracking-wide uppercase">
                  Задачи ({pendingTasks.length})
                </h2>
              </div>
              <button
                onClick={() => onNavigateView('tasks')}
                className="text-xs text-cyan-400 hover:text-cyan-300 font-mono font-semibold"
              >
                Перейти →
              </button>
            </div>

            <div className="space-y-3">
              {pendingTasks.slice(0, 3).map((task) => (
                <div
                  key={task.id}
                  className="p-3 bg-black/40 border border-white/10 rounded-xl space-y-1.5"
                >
                  <div className="text-xs font-bold text-slate-100">{task.title}</div>
                  {task.description && (
                    <div className="text-[11px] text-slate-300 leading-snug">{task.description}</div>
                  )}
                  <div className="flex items-center justify-between text-[10px] font-mono text-slate-400 pt-1 border-t border-white/10">
                    <span>Кому: {task.assignedToUserName || 'Отдел'}</span>
                    <span>Срок: {new Date(task.dueDate).toLocaleDateString('ru-RU')}</span>
                  </div>
                </div>
              ))}
              {pendingTasks.length === 0 && (
                <div className="p-6 text-center text-xs font-mono text-slate-500">
                  <CheckCircle2 className="w-8 h-8 mx-auto mb-2 text-emerald-400/60" />
                  Все срочные поручения выполнены
                </div>
              )}
            </div>
          </div>

          <div className="mt-4 p-3 bg-cyan-500/10 border border-cyan-500/30 rounded-xl text-xs text-slate-300 flex items-center justify-between">
            <div>
              <div className="text-[10px] font-mono text-cyan-300 uppercase">KPI за смену</div>
              <div className="font-mono font-bold text-emerald-400 text-sm">
                {totalKpiEarned.toLocaleString('ru-RU')} ₽
              </div>
            </div>
            <button
              onClick={() => onNavigateView('kpi')}
              className="text-[11px] font-mono bg-cyan-500/20 hover:bg-cyan-500/30 text-cyan-300 font-bold px-3 py-1 rounded-lg border border-cyan-500/40 transition-colors"
            >
              Отчёт KPI
            </button>
          </div>
        </div>
      </div>

      {/* Widget 3: Audit Trail Recent Operations */}
      <div className="bg-white/5 border border-white/10 rounded-2xl p-5 backdrop-blur-md shadow-[0_0_20px_rgba(0,0,0,0.4)]">
        <div className="flex items-center justify-between mb-4">
          <div className="flex items-center gap-2">
            <ShieldAlert className="w-5 h-5 text-emerald-400" />
            <h2 className="text-sm font-mono font-bold text-white tracking-wide uppercase">
              Журнал аудита LASTIK (Последние операции)
            </h2>
          </div>
          <button
            onClick={() => onNavigateView('audit')}
            className="text-xs text-cyan-400 hover:text-cyan-300 font-mono font-semibold"
          >
            Полный Журнал Аудита →
          </button>
        </div>

        <div className="divide-y divide-white/10">
          {auditLogs.slice(0, 4).map((log) => (
            <div key={log.id} className="py-2.5 flex items-start justify-between gap-4 text-xs font-sans">
              <div>
                <div className="font-semibold text-slate-200">
                  {log.details}
                </div>
                <div className="text-[11px] text-slate-400 flex items-center gap-2 mt-0.5 font-mono">
                  <span className="font-medium text-slate-300">{log.userName} ({log.userRole})</span>
                  <span>•</span>
                  <span>{new Date(log.timestamp).toLocaleTimeString('ru-RU')}</span>
                  <span>•</span>
                  <span className="text-slate-500">{log.ipAddress}</span>
                </div>
              </div>

              <span className="text-[10px] font-mono font-bold bg-white/10 text-cyan-300 px-2.5 py-1 rounded-md border border-white/10 shrink-0">
                {log.action}
              </span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
};
