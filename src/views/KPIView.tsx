import React, { useState } from 'react';
import { useApp } from '../context/AppContext';
import { TrendingUp, UserCheck, Wrench, Award, CheckCircle } from 'lucide-react';

export const KPIView: React.FC = () => {
  const { kpiRecords, users } = useApp();
  const [selectedRole, setSelectedRole] = useState<string>('all');

  const filteredRecords = kpiRecords.filter((k) => {
    if (selectedRole !== 'all' && k.employeeRole !== selectedRole) return false;
    return true;
  });

  const totalEarned = filteredRecords.reduce((sum, r) => sum + r.kpiEarned, 0);

  // Group by employee
  const employeeTotals: { [empId: string]: { name: string; role: string; sum: number; count: number } } = {};
  filteredRecords.forEach((r) => {
    if (!employeeTotals[r.employeeId]) {
      employeeTotals[r.employeeId] = {
        name: r.employeeName,
        role: r.employeeRole,
        sum: 0,
        count: 0
      };
    }
    employeeTotals[r.employeeId].sum += r.kpiEarned;
    employeeTotals[r.employeeId].count += 1;
  });

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 bg-slate-900 p-5 rounded-2xl border border-slate-800">
        <div>
          <h1 className="text-lg font-bold text-white flex items-center gap-2">
            <TrendingUp className="w-5 h-5 text-orange-400" />
            Минимальная Выработка & Расчёт KPI Начислений
          </h1>
          <p className="text-xs text-slate-400 mt-0.5">
            Процент с продаж товаров менеджерам и мастерский процент с шиномонтажных услуг
          </p>
        </div>

        <div className="flex items-center gap-2">
          <select
            value={selectedRole}
            onChange={(e) => setSelectedRole(e.target.value)}
            className="bg-slate-800 border border-slate-700 text-slate-200 text-xs rounded-xl px-3 py-2 focus:outline-none"
          >
            <option value="all">Все роли сотрудников</option>
            <option value="manager">Продавцы-Менеджеры (% с товара)</option>
            <option value="master">Мастера-Шиномонтажники (% с услуги)</option>
          </select>
        </div>
      </div>

      {/* Summary Cards */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div className="p-5 bg-slate-900 border border-slate-800 rounded-2xl">
          <div className="text-xs text-slate-400 font-semibold mb-1">Общая сумма выработки за смену</div>
          <div className="text-2xl font-extrabold text-emerald-400">
            {totalEarned.toLocaleString('ru-RU')} ₽
          </div>
        </div>

        <div className="p-5 bg-slate-900 border border-slate-800 rounded-2xl">
          <div className="text-xs text-slate-400 font-semibold mb-1">Количество начислений</div>
          <div className="text-2xl font-extrabold text-white">
            {filteredRecords.length}
          </div>
        </div>

        <div className="p-5 bg-slate-900 border border-slate-800 rounded-2xl">
          <div className="text-xs text-slate-400 font-semibold mb-1">Активных сотрудников</div>
          <div className="text-2xl font-extrabold text-orange-400">
            {Object.keys(employeeTotals).length}
          </div>
        </div>
      </div>

      {/* Employee Totals Leaderboard */}
      <div className="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-sm space-y-4">
        <h2 className="text-sm font-bold text-white flex items-center gap-2">
          <Award className="w-4 h-4 text-orange-400" />
          Сводка по сотрудникам
        </h2>

        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
          {Object.entries(employeeTotals).map(([empId, data]) => (
            <div
              key={empId}
              className="p-4 bg-slate-800/80 rounded-xl border border-slate-700/80 flex items-center justify-between"
            >
              <div>
                <div className="font-bold text-slate-100 text-xs">{data.name}</div>
                <div className="text-[10px] text-slate-400 mt-0.5">
                  {data.role === 'manager' ? 'Продавец-Менеджер' : 'Мастер-Шиномонтажник'} • {data.count} операций
                </div>
              </div>
              <div className="text-right">
                <div className="font-black text-emerald-400 text-sm">
                  {data.sum.toLocaleString('ru-RU')} ₽
                </div>
              </div>
            </div>
          ))}
        </div>
      </div>

      {/* Detailed Records Table */}
      <div className="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-sm">
        <div className="overflow-x-auto">
          <table className="w-full text-left text-xs text-slate-300">
            <thead className="bg-slate-950 text-slate-400 uppercase text-[10px] tracking-wider border-b border-slate-800">
              <tr>
                <th className="p-3.5">Дата / Заказ</th>
                <th className="p-3.5">Сотрудник / Роль</th>
                <th className="p-3.5">Номенклатура / Позиция</th>
                <th className="p-3.5">Продажа</th>
                <th className="p-3.5">Правило %</th>
                <th className="p-3.5">Выработка</th>
                <th className="p-3.5">Статус</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-800/80">
              {filteredRecords.map((r) => (
                <tr key={r.id} className="hover:bg-slate-800/50 transition-colors">
                  <td className="p-3.5">
                    <div className="font-mono font-bold text-orange-300">{r.orderNumber}</div>
                    <div className="text-[10px] text-slate-500">
                      {new Date(r.createdAt).toLocaleTimeString('ru-RU')}
                    </div>
                  </td>

                  <td className="p-3.5">
                    <div className="font-bold text-slate-100">{r.employeeName}</div>
                    <div className="text-[10px] text-slate-400">
                      {r.employeeRole === 'manager' ? 'Продавец' : 'Мастер'}
                    </div>
                  </td>

                  <td className="p-3.5 text-slate-200 font-medium max-w-xs">
                    {r.itemName}
                  </td>

                  <td className="p-3.5 font-bold text-white">
                    {r.saleAmount.toLocaleString('ru-RU')} ₽
                  </td>

                  <td className="p-3.5 font-mono font-bold text-blue-400">
                    {r.commissionPercent}%
                  </td>

                  <td className="p-3.5 font-black text-emerald-400 text-sm">
                    {r.kpiEarned.toLocaleString('ru-RU')} ₽
                  </td>

                  <td className="p-3.5">
                    <span className="text-[10px] font-bold bg-emerald-500/20 text-emerald-400 px-2 py-0.5 rounded">
                      Подтверждено
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
};
