import React, { useState } from 'react';
import { useApp } from '../context/AppContext';
import { Task, TaskStatus } from '../types';
import { CheckSquare, Clock, UserCheck, AlertCircle, Ban, Plus } from 'lucide-react';

export const TasksView: React.FC = () => {
  const { tasks, updateTaskStatus, users } = useApp();
  const [filterStatus, setFilterStatus] = useState<string>('all');

  const [cancelModalOpen, setCancelModalOpen] = useState(false);
  const [selectedTaskId, setSelectedTaskId] = useState('');
  const [cancelReason, setCancelReason] = useState('');

  const filteredTasks = tasks.filter((t) => {
    if (filterStatus !== 'all' && t.status !== filterStatus) return false;
    return true;
  });

  const handleExecuteCancelTask = async () => {
    if (!selectedTaskId || !cancelReason.trim()) return;
    await updateTaskStatus(selectedTaskId, 'cancelled', cancelReason);
    setCancelModalOpen(false);
    setSelectedTaskId('');
    setCancelReason('');
  };

  return (
    <div className="space-y-5">
      {/* Header */}
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 bg-slate-900 p-5 rounded-2xl border border-slate-800">
        <div>
          <h1 className="text-lg font-bold text-white flex items-center gap-2">
            <CheckSquare className="w-5 h-5 text-orange-400" />
            Задачи и Поручения по Заказам ({filteredTasks.length})
          </h1>
          <p className="text-xs text-slate-400 mt-0.5">
            Операционный контроль сборки комплектов шин, подачи на посты и клиентских заметок
          </p>
        </div>

        <select
          value={filterStatus}
          onChange={(e) => setFilterStatus(e.target.value)}
          className="bg-slate-800 border border-slate-700 text-slate-200 text-xs rounded-xl px-3 py-2 focus:outline-none"
        >
          <option value="all">Все статусы задач</option>
          <option value="pending">Ожидает выполнения</option>
          <option value="in_progress">В работе</option>
          <option value="completed">Завершено</option>
          <option value="cancelled">Отменено</option>
        </select>
      </div>

      {/* Task Cards Grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        {filteredTasks.map((t) => (
          <div
            key={t.id}
            className="bg-slate-900 border border-slate-800 rounded-2xl p-4 shadow-sm space-y-3 flex flex-col justify-between"
          >
            <div>
              <div className="flex items-start justify-between gap-2 mb-2">
                <h3 className="font-bold text-slate-100 text-xs">{t.title}</h3>
                <span
                  className={`text-[9px] font-bold px-2 py-0.5 rounded shrink-0 ${
                    t.priority === 'urgent'
                      ? 'bg-red-500/20 text-red-400 border border-red-500/30'
                      : 'bg-blue-500/20 text-blue-300'
                  }`}
                >
                  {t.priority === 'urgent' ? 'СРОЧНО' : 'Обычный'}
                </span>
              </div>

              <p className="text-xs text-slate-300 mb-3">{t.description}</p>

              <div className="text-[11px] text-slate-400 space-y-0.5">
                <div>Заказ: <span className="font-mono text-orange-300">{t.orderNumber}</span></div>
                <div>Исполнитель: <span className="text-slate-200">{t.assigneeUserName}</span></div>
              </div>
            </div>

            <div className="pt-3 border-t border-slate-800 flex items-center justify-between gap-2">
              <span
                className={`text-[10px] font-bold px-2 py-0.5 rounded ${
                  t.status === 'completed'
                    ? 'bg-emerald-500/20 text-emerald-400'
                    : t.status === 'in_progress'
                    ? 'bg-amber-500/20 text-amber-300'
                    : t.status === 'cancelled'
                    ? 'bg-red-500/20 text-red-400'
                    : 'bg-slate-800 text-slate-300'
                }`}
              >
                {t.status === 'completed'
                  ? 'Выполнено'
                  : t.status === 'in_progress'
                  ? 'В процессе'
                  : t.status === 'cancelled'
                  ? 'Отменено'
                  : 'Ожидает'}
              </span>

              {t.status !== 'completed' && t.status !== 'cancelled' && (
                <div className="flex items-center gap-1.5">
                  <button
                    onClick={() => updateTaskStatus(t.id, 'completed')}
                    className="px-2.5 py-1 bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-[10px] rounded-lg transition-colors cursor-pointer"
                  >
                    Завершить
                  </button>
                  <button
                    onClick={() => {
                      setSelectedTaskId(t.id);
                      setCancelModalOpen(true);
                    }}
                    className="p-1 text-slate-400 hover:text-red-400 transition-colors cursor-pointer"
                  >
                    <Ban className="w-4 h-4" />
                  </button>
                </div>
              )}
            </div>
          </div>
        ))}
      </div>

      {/* Task Cancel Modal */}
      {cancelModalOpen && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 border border-slate-800 rounded-2xl max-w-md w-full p-6 shadow-2xl text-slate-100">
            <h3 className="text-lg font-bold text-red-400 flex items-center gap-2 mb-2">
              <Ban className="w-5 h-5" />
              Отмена Поручения
            </h3>

            <div className="space-y-3 text-xs mb-5">
              <div>
                <label className="block text-slate-300 font-semibold mb-1">Причина отмены задачи *</label>
                <textarea
                  value={cancelReason}
                  onChange={(e) => setCancelReason(e.target.value)}
                  placeholder="Причина аннулирования..."
                  className="w-full bg-slate-800 border border-slate-700 rounded-xl p-2.5 text-white h-20"
                />
              </div>
            </div>

            <div className="flex items-center justify-end gap-2.5">
              <button
                onClick={() => setCancelModalOpen(false)}
                className="px-4 py-2 rounded-xl text-xs font-medium text-slate-300 hover:bg-slate-800"
              >
                Отмена
              </button>
              <button
                onClick={handleExecuteCancelTask}
                className="px-4 py-2 rounded-xl text-xs font-bold text-white bg-red-600 hover:bg-red-500 transition-colors"
              >
                Подтвердить отмену
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
