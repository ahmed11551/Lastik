import React from 'react';
import { useApp } from '../context/AppContext';
import { Layers, CheckCircle2, Shield, Radio, Cpu } from 'lucide-react';

export const ModulesView: React.FC = () => {
  const { modules } = useApp();

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 bg-slate-900 p-5 rounded-2xl border border-slate-800">
        <div>
          <h1 className="text-lg font-bold text-white flex items-center gap-2">
            <Layers className="w-5 h-5 text-orange-400" />
            Модули Платформы LASTIK ({modules.length})
          </h1>
          <p className="text-xs text-slate-400 mt-0.5">
            Архитектурный реестр подключенных функциональных подсистем и сервисов
          </p>
        </div>
      </div>

      {/* Modules List */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        {modules.map((mod) => (
          <div
            key={mod.id}
            className="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-sm space-y-3"
          >
            <div className="flex items-start justify-between">
              <div className="flex items-center gap-2.5">
                <div className="w-9 h-9 rounded-xl bg-orange-500/10 border border-orange-500/20 text-orange-400 flex items-center justify-center">
                  <Cpu className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="font-bold text-slate-100 text-xs">{mod.name}</h3>
                  <div className="text-[10px] text-slate-400 font-mono">v{mod.version}</div>
                </div>
              </div>

              <span className="text-[9px] font-bold bg-emerald-500/20 text-emerald-400 px-2 py-0.5 rounded flex items-center gap-1">
                <CheckCircle2 className="w-3 h-3" />
                Активен
              </span>
            </div>

            <p className="text-xs text-slate-300">{mod.description}</p>
          </div>
        ))}
      </div>
    </div>
  );
};
