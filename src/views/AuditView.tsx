import React, { useState } from 'react';
import { useApp } from '../context/AppContext';
import {
  ShieldCheck,
  Search,
  Filter,
  Terminal,
  User,
  MessageSquare,
  Download,
  CheckCircle2,
  Tag,
  AlertTriangle,
  Info,
  Clock,
  ShieldAlert,
  Plus
} from 'lucide-react';

export const AuditView: React.FC = () => {
  const { auditLogs, annotateAudit } = useApp();
  const [searchTerm, setSearchTerm] = useState('');
  const [selectedActionCategory, setSelectedActionCategory] = useState<string>('all');
  const [selectedLog, setSelectedLog] = useState<any>(null);

  // Annotation state
  const [annotationText, setAnnotationText] = useState('');
  const [annotationCategory, setAnnotationCategory] = useState('audit_note');
  const [isAnnotating, setIsAnnotating] = useState(false);

  const actionCategories = [
    { id: 'all', label: 'Все записи' },
    { id: 'ORDER', label: 'Заказы' },
    { id: 'PAYMENT', label: 'Касса & Оплаты' },
    { id: 'STOCK', label: 'Склад & 1С' },
    { id: 'SUPPORT', label: 'ТехПоддержка' },
    { id: 'CUSTOMER', label: 'Покупатели' },
    { id: 'USER', label: 'Пользователи' }
  ];

  const filteredLogs = auditLogs.filter((log) => {
    if (selectedActionCategory !== 'all') {
      if (!log.action.includes(selectedActionCategory)) return false;
    }

    if (searchTerm) {
      const q = searchTerm.toLowerCase().trim();
      const matchAction = log.action.toLowerCase().includes(q);
      const matchEntity = log.entityType.toLowerCase().includes(q);
      const matchUser = log.userName.toLowerCase().includes(q);
      const matchReason = log.reason?.toLowerCase().includes(q);
      const matchDetails = log.details?.toLowerCase().includes(q);
      if (!matchAction && !matchEntity && !matchUser && !matchReason && !matchDetails) return false;
    }
    return true;
  });

  const handleAddAnnotation = async () => {
    if (!selectedLog || !annotationText.trim()) return;
    try {
      setIsAnnotating(true);
      await annotateAudit(selectedLog.id, annotationText.trim(), annotationCategory);
      setAnnotationText('');
      // update selected log locally if needed
      const updatedLog = {
        ...selectedLog,
        annotations: [
          ...(selectedLog.annotations || []),
          {
            id: 'ann_' + Date.now(),
            userId: 'current',
            userName: 'Оператор Аудита',
            text: annotationText.trim(),
            category: annotationCategory,
            createdAt: new Date().toISOString()
          }
        ]
      };
      setSelectedLog(updatedLog);
    } catch (e) {
      console.error('Error adding audit annotation:', e);
    } finally {
      setIsAnnotating(false);
    }
  };

  const handleExportCSV = () => {
    const headers = ['ID', 'Timestamp', 'Action', 'EntityType', 'EntityId', 'UserName', 'UserRole', 'Details', 'Reason', 'IP'];
    const rows = filteredLogs.map((l) => [
      l.id,
      new Date(l.timestamp).toISOString(),
      l.action,
      l.entityType,
      l.entityId,
      `"${l.userName}"`,
      l.userRole,
      `"${(l.details || '').replace(/"/g, '""')}"`,
      `"${(l.reason || '').replace(/"/g, '""')}"`,
      l.ipAddress
    ]);

    const csvContent = 'data:text/csv;charset=utf-8,\uFEFF' + [headers.join(','), ...rows.map((r) => r.join(','))].join('\n');
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute('download', `audit_export_${new Date().toISOString().slice(0, 10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  };

  return (
    <div className="space-y-6">
      {/* Top Banner */}
      <div className="bg-white/5 border border-white/10 rounded-2xl p-6 text-slate-100 backdrop-blur-md shadow-[0_0_30px_rgba(0,0,0,0.5)] flex flex-col md:flex-row items-start md:items-center justify-between gap-4 relative overflow-hidden">
        <div className="absolute top-0 right-0 w-64 h-64 bg-emerald-500/10 rounded-full blur-[80px] pointer-events-none"></div>

        <div className="relative z-10">
          <div className="flex items-center gap-2 text-xs font-mono font-bold text-emerald-400 uppercase tracking-widest mb-1.5">
            <ShieldCheck className="w-4 h-4 text-emerald-400 animate-pulse" />
            НЕИЗМЕНЯЕМЫЙ АУДИТ БЕЗОПАСНОСТИ // AUDIT LOG
          </div>
          <h1 className="text-xl font-mono font-bold text-white tracking-wide flex items-center gap-3">
            Журнал операций и протоколирование изменений
            <span className="text-xs font-mono bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 px-2.5 py-0.5 rounded-full">
              Записей: {filteredLogs.length}
            </span>
          </h1>
          <p className="text-xs text-slate-400 font-sans mt-1">
            Сквозное логирование смен цен, отмен заказов, ручных коррекций кассы, смены ролей и режима техподдержки
          </p>
        </div>

        <div className="flex items-center gap-3 relative z-10">
          <button
            onClick={handleExportCSV}
            className="bg-white/5 hover:bg-white/10 text-slate-200 border border-white/10 hover:border-white/20 px-3.5 py-2 rounded-xl text-xs font-mono font-semibold flex items-center gap-2 transition-all cursor-pointer"
          >
            <Download className="w-4 h-4 text-cyan-400" />
            <span>Экспорт в CSV</span>
          </button>
        </div>
      </div>

      {/* Filter Category Tabs & Search */}
      <div className="bg-white/5 border border-white/10 rounded-2xl p-4 backdrop-blur-md shadow-[0_0_20px_rgba(0,0,0,0.4)] flex flex-col md:flex-row items-stretch md:items-center justify-between gap-4">
        
        {/* Category Filter Pills */}
        <div className="flex items-center gap-1.5 overflow-x-auto pb-1 md:pb-0">
          {actionCategories.map((cat) => (
            <button
              key={cat.id}
              onClick={() => setSelectedActionCategory(cat.id)}
              className={`px-3 py-1.5 rounded-xl text-xs font-mono transition-all cursor-pointer whitespace-nowrap ${
                selectedActionCategory === cat.id
                  ? 'bg-cyan-500/20 text-cyan-300 border border-cyan-500/40 font-bold shadow-[0_0_12px_rgba(6,182,212,0.3)]'
                  : 'bg-white/5 text-slate-400 hover:text-slate-200 hover:bg-white/10 border border-transparent'
              }`}
            >
              {cat.label}
            </button>
          ))}
        </div>

        {/* Search Input */}
        <div className="relative w-full md:w-80">
          <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-cyan-400/70" />
          <input
            type="text"
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
            placeholder="Поиск по действию, ID, оператору, причине..."
            className="w-full bg-black/40 border border-white/10 rounded-xl pl-9 pr-3 py-1.5 text-xs text-slate-100 placeholder-slate-400 focus:outline-none focus:border-cyan-400 focus:shadow-[0_0_15px_rgba(6,182,212,0.25)] transition-all font-sans"
          />
        </div>
      </div>

      {/* Audit Table */}
      <div className="bg-white/5 border border-white/10 rounded-2xl overflow-hidden backdrop-blur-md shadow-[0_0_20px_rgba(0,0,0,0.4)]">
        <div className="overflow-x-auto">
          <table className="w-full text-left text-xs text-slate-300">
            <thead className="bg-black/60 text-slate-400 uppercase text-[10px] font-mono tracking-wider border-b border-white/10">
              <tr>
                <th className="p-3.5">Дата / Время</th>
                <th className="p-3.5">Действие</th>
                <th className="p-3.5">Сущность / ID</th>
                <th className="p-3.5">Оператор & Роль</th>
                <th className="p-3.5">Детали / Обязательная причина</th>
                <th className="p-3.5 text-right">Инспекция Diff</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-white/5 font-mono text-[11px]">
              {filteredLogs.map((log) => {
                const isWarningAction = log.action.includes('CANCEL') || log.action.includes('DISCONNECT') || log.action.includes('DELETE');
                const isNoticeAction = log.action.includes('CORRECT') || log.action.includes('MERGE') || log.action.includes('SUPPORT');

                return (
                  <tr key={log.id} className="hover:bg-white/5 transition-colors">
                    <td className="p-3.5 text-slate-400">
                      <div className="text-slate-200 font-semibold">{new Date(log.timestamp).toLocaleDateString('ru-RU')}</div>
                      <div className="text-[10px] text-cyan-400/80">{new Date(log.timestamp).toLocaleTimeString('ru-RU')}</div>
                    </td>

                    <td className="p-3.5">
                      <span
                        className={`inline-block px-2.5 py-1 rounded-md text-[10px] font-bold font-mono border ${
                          isWarningAction
                            ? 'bg-red-500/15 text-red-300 border-red-500/30 shadow-[0_0_10px_rgba(239,68,68,0.2)]'
                            : isNoticeAction
                            ? 'bg-amber-500/15 text-amber-300 border-amber-500/30 shadow-[0_0_10px_rgba(245,158,11,0.2)]'
                            : 'bg-cyan-500/15 text-cyan-300 border-cyan-500/30 shadow-[0_0_10px_rgba(6,182,212,0.2)]'
                        }`}
                      >
                        {log.action}
                      </span>
                    </td>

                    <td className="p-3.5 text-slate-200">
                      <span className="text-slate-400 font-sans">{log.entityType}:</span>
                      <div className="text-cyan-400 text-[10px] font-bold">{log.entityId}</div>
                    </td>

                    <td className="p-3.5 text-slate-200 font-sans">
                      <div className="font-semibold text-white">{log.userName}</div>
                      <div className="text-[10px] text-slate-400 font-mono">{log.userRole}</div>
                    </td>

                    <td className="p-3.5 text-slate-300 font-sans max-w-sm">
                      <div className="text-slate-200 leading-tight mb-0.5">{log.details}</div>
                      {log.reason ? (
                        <div className="text-amber-300 text-[11px] font-mono flex items-center gap-1 bg-amber-500/10 px-2 py-0.5 rounded border border-amber-500/20 w-fit mt-1">
                          <AlertTriangle className="w-3 h-3 text-amber-400 shrink-0" />
                          <span>Причина: {log.reason}</span>
                        </div>
                      ) : (
                        <div className="text-slate-500 text-[10px] font-mono italic">Причина не требовалась</div>
                      )}
                      {log.annotations && log.annotations.length > 0 && (
                        <div className="mt-1.5 flex items-center gap-1 text-[10px] text-cyan-300 font-mono">
                          <MessageSquare className="w-3 h-3 text-cyan-400" />
                          <span>Заметок аудитора: {log.annotations.length}</span>
                        </div>
                      )}
                    </td>

                    <td className="p-3.5 text-right font-sans">
                      <button
                        onClick={() => {
                          setSelectedLog(log);
                          setAnnotationText('');
                        }}
                        className="px-3 py-1.5 bg-cyan-500/10 hover:bg-cyan-500/20 text-cyan-300 border border-cyan-500/30 rounded-lg text-[10px] font-mono font-bold transition-all inline-flex items-center gap-1.5 cursor-pointer shadow-[0_0_10px_rgba(6,182,212,0.15)]"
                      >
                        <Terminal className="w-3.5 h-3.5 text-cyan-400" />
                        JSON Diff & Заметки
                      </button>
                    </td>
                  </tr>
                );
              })}
              {filteredLogs.length === 0 && (
                <tr>
                  <td colSpan={6} className="p-8 text-center text-slate-500 font-mono text-xs">
                    <ShieldAlert className="w-8 h-8 mx-auto mb-2 text-slate-600" />
                    Записей аудита по текущим критериям не найдено
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* JSON Diff Drawer Modal */}
      {selectedLog && (
        <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-md flex justify-end">
          <div className="bg-[#080d1a] border-l border-white/15 w-full max-w-xl h-full p-6 overflow-y-auto text-slate-100 flex flex-col justify-between shadow-[0_0_50px_rgba(0,0,0,0.9)] relative">
            
            <div className="space-y-5">
              <div className="flex items-center justify-between pb-4 border-b border-white/10">
                <div>
                  <div className="text-[10px] font-mono font-bold text-cyan-400 uppercase tracking-widest mb-1">
                    ИНСПЕКЦИЯ СОСТОЯНИЯ // AUDIT DIFF INSPECTOR
                  </div>
                  <h2 className="text-base font-bold text-white font-mono">
                    {selectedLog.action}
                  </h2>
                  <p className="text-xs text-slate-400 font-mono">
                    ID: {selectedLog.id} • {new Date(selectedLog.timestamp).toLocaleString('ru-RU')}
                  </p>
                </div>
                <button
                  onClick={() => setSelectedLog(null)}
                  className="p-2 hover:bg-white/10 rounded-xl text-slate-400 hover:text-slate-200 transition-colors"
                >
                  ✕
                </button>
              </div>

              {/* Operator metadata card */}
              <div className="p-3.5 bg-white/5 border border-white/10 rounded-xl text-xs space-y-1 font-mono">
                <div className="flex justify-between">
                  <span className="text-slate-400">Оператор:</span>
                  <span className="text-slate-100 font-bold">{selectedLog.userName} ({selectedLog.userRole})</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-slate-400">Сущность:</span>
                  <span className="text-cyan-300">{selectedLog.entityType} ({selectedLog.entityId})</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-slate-400">IP Адрес:</span>
                  <span className="text-slate-300">{selectedLog.ipAddress}</span>
                </div>
                {selectedLog.reason && (
                  <div className="pt-2 border-t border-white/10 text-amber-300 font-sans">
                    <strong>Причина:</strong> {selectedLog.reason}
                  </div>
                )}
              </div>

              {/* State JSON comparison */}
              <div className="space-y-4 text-xs font-mono">
                <div>
                  <span className="text-slate-400 text-[10px] uppercase font-bold tracking-wider block mb-1.5 flex items-center gap-1">
                    <span className="w-2 h-2 rounded-full bg-red-400"></span>
                    Предшествующее состояние (Before State):
                  </span>
                  <pre className="p-3.5 bg-black/60 rounded-xl border border-red-500/20 text-red-300/90 overflow-x-auto text-[11px] leading-relaxed shadow-inner">
                    {JSON.stringify(selectedLog.beforeState || { info: 'Начальное состояние отсутствует' }, null, 2)}
                  </pre>
                </div>

                <div>
                  <span className="text-slate-400 text-[10px] uppercase font-bold tracking-wider block mb-1.5 flex items-center gap-1">
                    <span className="w-2 h-2 rounded-full bg-emerald-400"></span>
                    Новое состояние (After State):
                  </span>
                  <pre className="p-3.5 bg-black/60 rounded-xl border border-emerald-500/20 text-emerald-300/90 overflow-x-auto text-[11px] leading-relaxed shadow-inner">
                    {JSON.stringify(selectedLog.afterState || { info: 'Обновленное состояние отсутствует' }, null, 2)}
                  </pre>
                </div>
              </div>

              {/* Annotations List */}
              <div className="space-y-3 pt-3 border-t border-white/10">
                <h3 className="text-xs font-mono font-bold text-slate-300 uppercase tracking-wider flex items-center gap-1.5">
                  <MessageSquare className="w-4 h-4 text-cyan-400" />
                  Заметки независимого аудитора
                </h3>

                {selectedLog.annotations && selectedLog.annotations.length > 0 ? (
                  <div className="space-y-2">
                    {selectedLog.annotations.map((ann: any) => (
                      <div key={ann.id} className="p-3 bg-white/5 border border-white/10 rounded-xl text-xs space-y-1">
                        <div className="flex items-center justify-between text-[10px] font-mono text-cyan-400">
                          <span>{ann.userName} ({ann.category})</span>
                          <span>{new Date(ann.createdAt).toLocaleString('ru-RU')}</span>
                        </div>
                        <p className="text-slate-200">{ann.text}</p>
                      </div>
                    ))}
                  </div>
                ) : (
                  <div className="text-[11px] text-slate-500 italic font-mono">Заметок пока нет</div>
                )}

                {/* Add Annotation Form */}
                <div className="pt-2 space-y-2">
                  <div className="flex gap-2">
                    <select
                      value={annotationCategory}
                      onChange={(e) => setAnnotationCategory(e.target.value)}
                      className="bg-black/40 border border-white/10 rounded-xl px-2 py-1 text-xs text-slate-300 font-mono focus:outline-none focus:border-cyan-400"
                    >
                      <option value="audit_note">Заметка безопасности</option>
                      <option value="legal">Юридический риск</option>
                      <option value="discrepancy">Расхождение расчетов</option>
                    </select>
                  </div>
                  <div className="flex items-center gap-2">
                    <input
                      type="text"
                      value={annotationText}
                      onChange={(e) => setAnnotationText(e.target.value)}
                      placeholder="Добавить примечание аудитора..."
                      className="flex-1 bg-black/40 border border-white/10 rounded-xl px-3 py-1.5 text-xs text-slate-100 placeholder-slate-500 focus:outline-none focus:border-cyan-400 font-sans"
                    />
                    <button
                      onClick={handleAddAnnotation}
                      disabled={isAnnotating || !annotationText.trim()}
                      className="px-3 py-1.5 bg-cyan-600 hover:bg-cyan-500 disabled:opacity-50 text-white text-xs font-mono font-bold rounded-xl flex items-center gap-1 cursor-pointer shrink-0 transition-all shadow-[0_0_10px_rgba(6,182,212,0.3)]"
                    >
                      <Plus className="w-3.5 h-3.5" />
                      Добавить
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <div className="pt-4 border-t border-white/10 flex justify-end mt-4">
              <button
                onClick={() => setSelectedLog(null)}
                className="px-4 py-2 bg-white/5 hover:bg-white/10 text-slate-200 font-mono font-bold rounded-xl text-xs border border-white/10 transition-colors cursor-pointer"
              >
                Закрыть
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
