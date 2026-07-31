import React, { useState } from 'react';
import { useApp } from '../context/AppContext';
import { Building, MapPin, ShieldAlert, LifeBuoy, Check, Plus } from 'lucide-react';

export const TenantsLocationsView: React.FC = () => {
  const { tenant, locations, enableSupportAccess, isSupportAccessActive } = useApp();
  const [supportModalOpen, setSupportModalOpen] = useState(false);
  const [supportHours, setSupportHours] = useState(2);
  const [supportReason, setSupportReason] = useState('');

  const handleActivateSupport = async () => {
    if (!supportReason.trim()) {
      alert('Укажите причину предоставления доступа техподдержке');
      return;
    }
    await enableSupportAccess(supportHours, supportReason);
    setSupportModalOpen(false);
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 bg-slate-900 p-5 rounded-2xl border border-slate-800">
        <div>
          <h1 className="text-lg font-bold text-white flex items-center gap-2">
            <Building className="w-5 h-5 text-orange-400" />
            Организация & Шинные Центры ({tenant?.name})
          </h1>
          <p className="text-xs text-slate-400 mt-0.5">
            Управление филиалами, сервисными постами, складами и режимом техподдержки
          </p>
        </div>

        <button
          onClick={() => setSupportModalOpen(true)}
          className={`px-4 py-2.5 font-bold rounded-xl text-xs flex items-center gap-2 transition-all cursor-pointer ${
            isSupportAccessActive
              ? 'bg-amber-500 text-slate-950 shadow-lg shadow-amber-500/20'
              : 'bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700'
          }`}
        >
          <LifeBuoy className="w-4 h-4" />
          <span>{isSupportAccessActive ? 'Техподдержка Активна' : 'Режим Техподдержки'}</span>
        </button>
      </div>

      {/* Support Active Banner */}
      {isSupportAccessActive && (
        <div className="p-4 bg-amber-500/15 border border-amber-500/40 rounded-2xl flex items-center gap-3">
          <ShieldAlert className="w-6 h-6 text-amber-400 shrink-0" />
          <div className="text-xs text-amber-200">
            <div className="font-bold text-amber-300">ВНИМАНИЕ: Включение режима доступа Техподдержки Платформы</div>
            <div>Сотрудники поддержки имеют временный доступ для диагностики. Все действия протоколируются.</div>
          </div>
        </div>
      )}

      {/* Locations Cards */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        {locations.map((loc) => (
          <div
            key={loc.id}
            className="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-sm space-y-3"
          >
            <div className="flex items-start justify-between">
              <div>
                <h3 className="font-bold text-slate-100 text-sm flex items-center gap-2">
                  <MapPin className="w-4 h-4 text-orange-400" />
                  {loc.name}
                </h3>
                <div className="text-xs text-slate-400 mt-0.5">{loc.address}</div>
              </div>

              <span className="text-[10px] font-bold bg-emerald-500/20 text-emerald-400 px-2 py-0.5 rounded">
                Активен
              </span>
            </div>

            <div className="grid grid-cols-2 gap-2 text-xs pt-2 border-t border-slate-800 text-slate-300">
              <div>
                <span className="text-slate-400 block text-[10px]">Телефон:</span>
                <strong className="text-slate-200">{loc.phone}</strong>
              </div>

              <div>
                <span className="text-slate-400 block text-[10px]">Код склада:</span>
                <strong className="text-orange-300 font-mono">{loc.code}</strong>
              </div>
            </div>
          </div>
        ))}
      </div>

      {/* Support Access Modal */}
      {supportModalOpen && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 border border-slate-800 rounded-2xl max-w-md w-full p-6 shadow-2xl text-slate-100">
            <h3 className="text-lg font-bold text-amber-400 flex items-center gap-2 mb-2">
              <LifeBuoy className="w-5 h-5" />
              Предоставление Доступа Техподдержке
            </h3>
            <p className="text-xs text-slate-300 mb-4">
              Предоставляет инженерам Платформы LASTIK временный диагностический доступ. Все манипуляции записываются в Audit Log.
            </p>

            <div className="space-y-3 text-xs mb-5">
              <div>
                <label className="block text-slate-300 font-semibold mb-1">Срок доступа (часов)</label>
                <select
                  value={supportHours}
                  onChange={(e) => setSupportHours(Number(e.target.value))}
                  className="w-full bg-slate-800 border border-slate-700 rounded-xl p-2.5 text-white"
                >
                  <option value={1}>1 час</option>
                  <option value={2}>2 часа</option>
                  <option value={4}>4 часа</option>
                  <option value={12}>12 часов</option>
                </select>
              </div>

              <div>
                <label className="block text-slate-300 font-semibold mb-1">Причина запроса / Описание проблемы *</label>
                <textarea
                  value={supportReason}
                  onChange={(e) => setSupportReason(e.target.value)}
                  placeholder="Опишите технический тикет..."
                  className="w-full bg-slate-800 border border-slate-700 rounded-xl p-2.5 text-white h-20"
                />
              </div>
            </div>

            <div className="flex items-center justify-end gap-2.5">
              <button
                onClick={() => setSupportModalOpen(false)}
                className="px-4 py-2 rounded-xl text-xs font-medium text-slate-300 hover:bg-slate-800"
              >
                Отмена
              </button>
              <button
                onClick={handleActivateSupport}
                className="px-4 py-2 rounded-xl text-xs font-bold text-slate-950 bg-amber-400 hover:bg-amber-300 transition-colors"
              >
                Включить режим поддержки
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
