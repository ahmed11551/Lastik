import React, { useState } from 'react';
import { useApp } from '../context/AppContext';
import { Shield, User, Smartphone, Power, Check, AlertTriangle } from 'lucide-react';

export const UsersRolesView: React.FC = () => {
  const { users, roles, disconnectDeviceSession } = useApp();

  const [disconnectModalOpen, setDisconnectModalOpen] = useState(false);
  const [selectedUserId, setSelectedUserId] = useState('');
  const [selectedSessionId, setSelectedSessionId] = useState('');
  const [disconnectReason, setDisconnectReason] = useState('');

  const selectedUser = users.find((u) => u.id === selectedUserId);

  const handleExecuteDisconnect = async () => {
    if (!selectedUserId || !selectedSessionId) return;
    if (!disconnectReason.trim()) {
      alert('Укажите причину принудительного отключения устройства');
      return;
    }

    await disconnectDeviceSession(selectedUserId, selectedSessionId, disconnectReason);
    setDisconnectModalOpen(false);
    setSelectedUserId('');
    setSelectedSessionId('');
    setDisconnectReason('');
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 bg-slate-900 p-5 rounded-2xl border border-slate-800">
        <div>
          <h1 className="text-lg font-bold text-white flex items-center gap-2">
            <Shield className="w-5 h-5 text-orange-400" />
            Сотрудники, Роли & Сессии Устройств ({users.length})
          </h1>
          <p className="text-xs text-slate-400 mt-0.5">
            Гранулярная матрица прав, контроль лимита устройств и принудительное отключение сессий
          </p>
        </div>
      </div>

      {/* Users & Devices Grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        {users.map((u) => {
          const userRole = roles.find((r) => r.id === u.role);

          return (
            <div
              key={u.id}
              className="bg-slate-900 border border-slate-800 hover:border-slate-700/80 rounded-2xl p-4 shadow-sm space-y-3 transition-all"
            >
              <div className="flex items-start justify-between">
                <div className="flex items-center gap-2.5">
                  <div className="w-9 h-9 rounded-xl bg-orange-500/10 border border-orange-500/20 text-orange-400 font-bold flex items-center justify-center text-xs">
                    {u.name.substring(0, 2).toUpperCase()}
                  </div>
                  <div>
                    <h3 className="font-bold text-slate-100 text-xs">{u.name}</h3>
                    <span className="text-[10px] text-orange-400 font-semibold">{u.roleName}</span>
                  </div>
                </div>

                <span
                  className={`text-[9px] font-bold px-2 py-0.5 rounded ${
                    u.isActive ? 'bg-emerald-500/20 text-emerald-400' : 'bg-red-500/20 text-red-400'
                  }`}
                >
                  {u.isActive ? 'Активен' : 'Заблокирован'}
                </span>
              </div>

              <div className="text-xs text-slate-400 space-y-1">
                <div>Логин: <span className="font-mono text-slate-200">{u.login}</span></div>
                <div>Телефон: <span className="text-slate-200">{u.phone}</span></div>
                <div>Локация: <span className="text-slate-200">{u.assignedLocationName}</span></div>
              </div>

              {/* Active Device Sessions Block */}
              <div className="pt-3 border-t border-slate-800/80 space-y-2">
                <div className="text-[10px] font-bold uppercase tracking-wider text-slate-400 flex items-center justify-between">
                  <span>Активные Устройства ({u.activeSessions?.length || 0})</span>
                  <Smartphone className="w-3 h-3 text-slate-500" />
                </div>

                {u.activeSessions && u.activeSessions.length > 0 ? (
                  <div className="space-y-1.5">
                    {u.activeSessions.map((sess) => (
                      <div
                        key={sess.id}
                        className="p-2 bg-slate-800/80 rounded-lg border border-slate-700/60 flex items-center justify-between text-[11px]"
                      >
                        <div>
                          <div className="font-bold text-slate-200">{sess.deviceName}</div>
                          <div className="text-[9px] text-slate-500 font-mono">IP: {sess.ip}</div>
                        </div>

                        <button
                          onClick={() => {
                            setSelectedUserId(u.id);
                            setSelectedSessionId(sess.id);
                            setDisconnectModalOpen(true);
                          }}
                          className="px-2 py-1 bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/30 rounded text-[10px] font-bold transition-colors flex items-center gap-1 cursor-pointer"
                        >
                          <Power className="w-3 h-3" />
                          Сбросить
                        </button>
                      </div>
                    ))}
                  </div>
                ) : (
                  <div className="text-[10px] text-slate-500 italic">
                    Активные сессии отсутствуют
                  </div>
                )}
              </div>
            </div>
          );
        })}
      </div>

      {/* Disconnect Session Modal */}
      {disconnectModalOpen && selectedUser && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 border border-slate-800 rounded-2xl max-w-md w-full p-6 shadow-2xl text-slate-100">
            <h3 className="text-lg font-bold text-red-400 flex items-center gap-2 mb-2">
              <Power className="w-5 h-5" />
              Отключение Устройства {selectedUser.name}
            </h3>
            <p className="text-xs text-slate-300 mb-4">
              Принудительный разрыв сессии устройства сотрет токен авторизации. Обязательно укажите причину для журнала аудита.
            </p>

            <div className="space-y-3 text-xs mb-5">
              <div>
                <label className="block text-slate-300 font-semibold mb-1">Причина отключения *</label>
                <textarea
                  value={disconnectReason}
                  onChange={(e) => setDisconnectReason(e.target.value)}
                  placeholder="Превышение лимита устройств / Утеря смартфона..."
                  className="w-full bg-slate-800 border border-slate-700 rounded-xl p-2.5 text-white h-20"
                />
              </div>
            </div>

            <div className="flex items-center justify-end gap-2.5">
              <button
                onClick={() => setDisconnectModalOpen(false)}
                className="px-4 py-2 rounded-xl text-xs font-medium text-slate-300 hover:bg-slate-800"
              >
                Отмена
              </button>
              <button
                onClick={handleExecuteDisconnect}
                className="px-4 py-2 rounded-xl text-xs font-bold text-white bg-red-600 hover:bg-red-500 transition-colors"
              >
                Принудительно разлогинить
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
