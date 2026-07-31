import React, { useState, useEffect } from 'react';
import { useApp } from '../context/AppContext';
import { Tv, Clock, CheckCircle2, Wrench, AlertCircle, Car } from 'lucide-react';
import { fetchTvBoard, isLaravelApiEnabled, LaravelApiError } from '../api/laravelClient';

type BoardCard = {
  id: string | number;
  plate?: string | null;
  vehicle?: string | null;
  number?: string | null;
  customerName?: string;
  vehicleInfo?: string;
  masterExecutorName?: string;
  orderNumber?: string;
};

export const TVDisplayView: React.FC = () => {
  const { orders, activeLocation } = useApp();
  const [time, setTime] = useState(new Date());
  const [apiQueue, setApiQueue] = useState<BoardCard[] | null>(null);
  const [apiInProgress, setApiInProgress] = useState<BoardCard[] | null>(null);
  const [apiReady, setApiReady] = useState<BoardCard[] | null>(null);
  const [apiError, setApiError] = useState<string | null>(null);

  useEffect(() => {
    const timer = setInterval(() => setTime(new Date()), 1000);
    return () => clearInterval(timer);
  }, []);

  useEffect(() => {
    if (!isLaravelApiEnabled()) return;
    let cancelled = false;
    const load = () => {
      fetchTvBoard(activeLocation?.id)
        .then((board) => {
          if (cancelled || !board) return;
          const map = (rows: Array<Record<string, unknown>>): BoardCard[] =>
            rows.map((r) => ({
              id: r.id as number,
              plate: (r.plate as string) ?? null,
              vehicle: (r.vehicle as string) ?? null,
              number: (r.number as string) ?? null,
              vehicleInfo: r.plate ? `${r.vehicle || ''} (${r.plate})` : String(r.vehicle || r.number || ''),
              orderNumber: String(r.number ?? r.id),
            }));
          setApiQueue(map(board.columns.queue));
          setApiInProgress(map(board.columns.in_progress));
          setApiReady(map(board.columns.ready));
          setApiError(null);
        })
        .catch((err: unknown) => {
          if (cancelled) return;
          setApiError(err instanceof LaravelApiError ? err.toUserMessage() : 'Не удалось загрузить табло');
        });
    };
    load();
    const poll = setInterval(load, 15000);
    return () => {
      cancelled = true;
      clearInterval(poll);
    };
  }, [activeLocation?.id]);

  const mockInProgress = orders.filter((o) => o.status === 'in_progress' && o.scenario === 'with_installation');
  const mockReady = orders.filter((o) => o.status === 'ready_for_release' || o.status === 'released' || o.status === 'ready');
  const mockQueue = orders.filter((o) => o.status === 'created' && o.scenario === 'with_installation');

  const inProgressOrders: BoardCard[] = apiInProgress ?? mockInProgress;
  const readyOrders: BoardCard[] = apiReady ?? mockReady;
  const queueOrders: BoardCard[] = apiQueue ?? mockQueue;

  return (
    <div className="bg-slate-950 text-white min-h-[85vh] p-6 rounded-3xl border-2 border-slate-800 space-y-6 shadow-2xl">
      {/* Top Banner */}
      <div className="flex items-center justify-between pb-6 border-b border-slate-800">
        <div className="flex items-center gap-4">
          <div className="w-12 h-12 rounded-2xl bg-gradient-to-br from-orange-500 to-amber-500 flex items-center justify-center text-slate-950 font-black text-xl shadow-lg shadow-orange-500/20">
            L
          </div>
          <div>
            <h1 className="text-2xl font-black tracking-tight text-white uppercase flex items-center gap-2">
              ТАБЛО ОБСЛУЖИВАНИЯ • LASTIK
            </h1>
            <p className="text-sm font-bold text-orange-400 mt-0.5">
              {activeLocation?.name || 'Шинный Центр'} • Сервисный цех
            </p>
            {apiError && (
              <p className="text-xs font-semibold text-rose-400 mt-1">{apiError}</p>
            )}
          </div>
        </div>

        <div className="text-right font-mono">
          <div className="text-3xl font-black text-slate-100 tracking-wider">
            {time.toLocaleTimeString('ru-RU')}
          </div>
          <div className="text-xs text-slate-400 uppercase font-semibold mt-0.5">
            {time.toLocaleDateString('ru-RU', { weekday: 'long', day: 'numeric', month: 'long' })}
          </div>
        </div>
      </div>

      {/* Main Grid for TV */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        {/* Column 1: IN PROGRESS */}
        <div className="bg-slate-900/90 border-2 border-amber-500/50 rounded-2xl p-5 space-y-4 shadow-xl">
          <div className="flex items-center justify-between pb-3 border-b border-amber-500/30">
            <h2 className="text-lg font-black text-amber-400 flex items-center gap-2 tracking-wide uppercase">
              <Wrench className="w-6 h-6 text-amber-400 animate-spin" />
              В работе на по постах ({inProgressOrders.length})
            </h2>
          </div>

          <div className="space-y-3">
            {inProgressOrders.map((ord) => (
              <div
                key={ord.id}
                className="p-4 bg-slate-800/90 rounded-xl border border-amber-500/40 flex items-center justify-between shadow-md"
              >
                <div>
                  <div className="font-mono font-black text-2xl text-orange-300">
                    {ord.vehicleInfo?.split('(')[1]?.replace(')', '') || ord.vehicleInfo || 'АВТО'}
                  </div>
                  <div className="text-sm font-bold text-slate-200 mt-1">
                    {ord.vehicleInfo?.split('(')[0] || ord.customerName}
                  </div>
                  <div className="text-xs text-slate-400 mt-0.5">
                    Мастер: <strong className="text-amber-300">{ord.masterExecutorName || 'Назначен'}</strong>
                  </div>
                </div>

                <div className="text-right">
                  <span className="inline-block px-3 py-1 bg-amber-500/20 text-amber-300 font-extrabold text-xs rounded-lg border border-amber-500/40 animate-pulse">
                    ПОСТ #{String(ord.orderNumber ?? ord.number ?? '').slice(-1) || '—'}
                  </span>
                </div>
              </div>
            ))}

            {inProgressOrders.length === 0 && (
              <div className="p-8 text-center text-sm text-slate-500 font-bold">
                Посты свободны
              </div>
            )}
          </div>
        </div>

        {/* Column 2: READY FOR RELEASE */}
        <div className="bg-slate-900/90 border-2 border-emerald-500/50 rounded-2xl p-5 space-y-4 shadow-xl">
          <div className="flex items-center justify-between pb-3 border-b border-emerald-500/30">
            <h2 className="text-lg font-black text-emerald-400 flex items-center gap-2 tracking-wide uppercase">
              <CheckCircle2 className="w-6 h-6 text-emerald-400" />
              Готов к выдаче ({readyOrders.length})
            </h2>
          </div>

          <div className="space-y-3">
            {readyOrders.map((ord) => (
              <div
                key={ord.id}
                className="p-4 bg-slate-800/90 rounded-xl border border-emerald-500/40 flex items-center justify-between shadow-md"
              >
                <div>
                  <div className="font-mono font-black text-2xl text-emerald-300">
                    {ord.vehicleInfo?.split('(')[1]?.replace(')', '') || ord.vehicleInfo || 'АВТО'}
                  </div>
                  <div className="text-sm font-bold text-slate-200 mt-1">
                    {ord.vehicleInfo?.split('(')[0] || ord.customerName}
                  </div>
                </div>

                <div className="text-right">
                  <span className="inline-block px-3 py-1 bg-emerald-500/20 text-emerald-300 font-extrabold text-xs rounded-lg border border-emerald-500/40">
                    ГОТОВ
                  </span>
                </div>
              </div>
            ))}

            {readyOrders.length === 0 && (
              <div className="p-8 text-center text-sm text-slate-500 font-bold">
                Готовых автомобилей нет
              </div>
            )}
          </div>
        </div>

        {/* Column 3: WAITING QUEUE */}
        <div className="bg-slate-900/90 border-2 border-slate-800 rounded-2xl p-5 space-y-4 shadow-xl">
          <div className="flex items-center justify-between pb-3 border-b border-slate-800">
            <h2 className="text-lg font-black text-slate-300 flex items-center gap-2 tracking-wide uppercase">
              <Clock className="w-6 h-6 text-slate-400" />
              В очереди ({queueOrders.length})
            </h2>
          </div>

          <div className="space-y-3">
            {queueOrders.map((ord) => (
              <div
                key={ord.id}
                className="p-4 bg-slate-800/60 rounded-xl border border-slate-700/80 flex items-center justify-between"
              >
                <div>
                  <div className="font-mono font-black text-xl text-slate-200">
                    {ord.vehicleInfo?.split('(')[1]?.replace(')', '') || ord.vehicleInfo || 'АВТО'}
                  </div>
                  <div className="text-xs font-semibold text-slate-400 mt-0.5">
                    {ord.customerName}
                  </div>
                </div>

                <div className="text-right">
                  <span className="inline-block px-2.5 py-1 bg-slate-800 text-slate-400 font-bold text-xs rounded-lg">
                    Ожидание
                  </span>
                </div>
              </div>
            ))}

            {queueOrders.length === 0 && (
              <div className="p-8 text-center text-sm text-slate-500 font-bold">
                Очередь пуста
              </div>
            )}
          </div>
        </div>

      </div>
    </div>
  );
};
