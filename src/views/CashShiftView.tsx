import React, { useState } from 'react';
import { useApp } from '../context/AppContext';
import {
  Receipt,
  Play,
  Square,
  ArrowUpRight,
  ArrowDownRight,
  DollarSign,
  CreditCard,
  Building,
  Edit3,
  History,
  AlertTriangle
} from 'lucide-react';

export const CashShiftView: React.FC = () => {
  const {
    activeShift,
    shifts,
    payments,
    recipients,
    corrections,
    openShift,
    closeShift,
    cashMovement,
    correctPayment
  } = useApp();

  // Open Shift State
  const [openShiftModal, setOpenShiftModal] = useState(false);
  const [openingBalanceInput, setOpeningBalanceInput] = useState<number>(15000);

  // Close Shift State
  const [closeShiftModal, setCloseShiftModal] = useState(false);
  const [closingCashActual, setClosingCashActual] = useState<number>(0);
  const [shiftNotes, setShiftNotes] = useState('');

  // Cash Movement State
  const [cashMoveModal, setCashMoveModal] = useState(false);
  const [moveType, setMoveType] = useState<'encashment' | 'withdrawal'>('encashment');
  const [moveAmount, setMoveAmount] = useState<number>(5000);
  const [moveReason, setMoveReason] = useState('');

  // Payment Correction State
  const [correctModalOpen, setCorrectModalOpen] = useState(false);
  const [selectedPaymentId, setSelectedPaymentId] = useState<string>('');
  const [newAmountInput, setNewAmountInput] = useState<number>(0);
  const [newRecipientId, setNewRecipientId] = useState<string>('');
  const [correctionReasonInput, setCorrectionReasonInput] = useState<string>('');

  const selectedPayment = payments.find((p) => p.id === selectedPaymentId);

  const handleOpenShift = async () => {
    await openShift(openingBalanceInput);
    setOpenShiftModal(false);
  };

  const handleCloseShift = async () => {
    if (!activeShift) return;
    await closeShift(activeShift.id, closingCashActual, shiftNotes);
    setCloseShiftModal(false);
  };

  const handleExecuteCashMove = async () => {
    if (!activeShift) return;
    if (!moveReason.trim()) {
      alert('Укажите причину изъятия/инкассации денег');
      return;
    }
    await cashMovement(activeShift.id, moveType, moveAmount, moveReason);
    setCashMoveModal(false);
    setMoveReason('');
  };

  const handleExecuteCorrection = async () => {
    if (!selectedPayment) return;
    if (!correctionReasonInput.trim()) {
      alert('Причина корректировки обязательна');
      return;
    }

    await correctPayment(
      selectedPayment.id,
      newAmountInput,
      selectedPayment.method,
      newRecipientId,
      correctionReasonInput
    );
    setCorrectModalOpen(false);
    setSelectedPaymentId('');
    setCorrectionReasonInput('');
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 bg-slate-900 p-5 rounded-2xl border border-slate-800">
        <div>
          <h1 className="text-lg font-bold text-white flex items-center gap-2">
            <Receipt className="w-5 h-5 text-orange-400" />
            Управленческая Касса & Кассовые Смены
          </h1>
          <p className="text-xs text-slate-400 mt-0.5">
            Фиксация оплат, инкассаций, получателей денег (ООО, ИП, Карта ФИО) и кассовых смен
          </p>
        </div>

        <div className="flex items-center gap-2">
          {!activeShift ? (
            <button
              onClick={() => setOpenShiftModal(true)}
              className="px-4 py-2.5 bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-bold rounded-xl text-xs flex items-center gap-2 shadow-lg shadow-emerald-500/20 transition-all cursor-pointer"
            >
              <Play className="w-4 h-4" />
              Открыть Смену
            </button>
          ) : (
            <>
              <button
                onClick={() => setCashMoveModal(true)}
                className="px-3.5 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 font-bold rounded-xl text-xs flex items-center gap-1.5 transition-all cursor-pointer"
              >
                <ArrowDownRight className="w-4 h-4 text-amber-400" />
                Инкассация / Выемка
              </button>

              <button
                onClick={() => {
                  const expected = activeShift.openingBalance + activeShift.cashInflow - activeShift.encashmentTotal - activeShift.withdrawalTotal;
                  setClosingCashActual(expected);
                  setCloseShiftModal(true);
                }}
                className="px-4 py-2.5 bg-red-600 hover:bg-red-500 text-white font-bold rounded-xl text-xs flex items-center gap-2 shadow-lg shadow-red-600/20 transition-all cursor-pointer"
              >
                <Square className="w-4 h-4" />
                Закрыть Смену
              </button>
            </>
          )}
        </div>
      </div>

      {/* Active Shift Summary Banner */}
      {activeShift ? (
        <div className="bg-slate-900 border border-emerald-500/30 rounded-2xl p-5 shadow-sm space-y-4">
          <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 pb-3 border-b border-slate-800">
            <div className="flex items-center gap-2">
              <span className="w-3 h-3 rounded-full bg-emerald-500 animate-ping" />
              <h2 className="text-sm font-bold text-white">
                Активная Смена #{activeShift.id.slice(-4)} ({activeShift.locationName})
              </h2>
            </div>
            <div className="text-xs text-slate-400">
              Кассир: <strong className="text-slate-200">{activeShift.cashierUserName}</strong> • Открыта: {new Date(activeShift.openedAt).toLocaleTimeString('ru-RU')}
            </div>
          </div>

          <div className="grid grid-cols-2 sm:grid-cols-5 gap-3 text-xs">
            <div className="p-3 bg-slate-800/80 rounded-xl border border-slate-700/60">
              <span className="text-slate-400 block text-[10px]">Начальный остаток</span>
              <strong className="text-white text-base">{activeShift.openingBalance.toLocaleString('ru-RU')} ₽</strong>
            </div>

            <div className="p-3 bg-slate-800/80 rounded-xl border border-slate-700/60">
              <span className="text-slate-400 block text-[10px]">Наличные в кассу</span>
              <strong className="text-emerald-400 text-base">{activeShift.cashInflow.toLocaleString('ru-RU')} ₽</strong>
            </div>

            <div className="p-3 bg-slate-800/80 rounded-xl border border-slate-700/60">
              <span className="text-slate-400 block text-[10px]">Безнал / Карты / СБП</span>
              <strong className="text-blue-400 text-base">{activeShift.cardInflow.toLocaleString('ru-RU')} ₽</strong>
            </div>

            <div className="p-3 bg-slate-800/80 rounded-xl border border-slate-700/60">
              <span className="text-slate-400 block text-[10px]">Переводы ФИО</span>
              <strong className="text-amber-400 text-base">{activeShift.transferInflow.toLocaleString('ru-RU')} ₽</strong>
            </div>

            <div className="p-3 bg-slate-800/80 rounded-xl border border-slate-700/60">
              <span className="text-slate-400 block text-[10px]">Инкассация / Выемки</span>
              <strong className="text-red-400 text-base">{(activeShift.encashmentTotal + activeShift.withdrawalTotal).toLocaleString('ru-RU')} ₽</strong>
            </div>
          </div>
        </div>
      ) : (
        <div className="p-8 bg-slate-900 border border-slate-800 rounded-2xl text-center text-xs text-slate-400 space-y-2">
          <AlertTriangle className="w-8 h-8 text-amber-500/80 mx-auto" />
          <div className="font-bold text-slate-200">В текущей локации кассовая смена закрыта</div>
          <p>Откройте смену для проведения ручных оплат и печати отчёта</p>
        </div>
      )}

      {/* Money Recipients Catalog */}
      <div className="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-sm space-y-3">
        <h2 className="text-sm font-bold text-white flex items-center gap-2">
          <Building className="w-4 h-4 text-orange-400" />
          Справочник Получателей Денег (ООО, ИП, Карта ФИО, Касса)
        </h2>

        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
          {recipients.map((rec) => (
            <div
              key={rec.id}
              className="p-3 bg-slate-800/70 rounded-xl border border-slate-700/80 text-xs space-y-1"
            >
              <div className="font-bold text-slate-100">{rec.name}</div>
              <div className="text-[11px] text-slate-400">{rec.details}</div>
              <span className="inline-block text-[9px] font-bold bg-slate-700 text-slate-300 px-1.5 py-0.2 rounded mt-1">
                {rec.type === 'cashbox' ? 'Касса' : rec.type === 'card_fio' ? 'Карта ФИО' : 'Р/счет'}
              </span>
            </div>
          ))}
        </div>
      </div>

      {/* Payments History & Correction Trigger */}
      <div className="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-sm">
        <div className="p-4 border-b border-slate-800 flex items-center justify-between">
          <h2 className="text-sm font-bold text-white flex items-center gap-2">
            <DollarSign className="w-4 h-4 text-emerald-400" />
            Реестр Оплат и Корректировок Смены
          </h2>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full text-left text-xs text-slate-300">
            <thead className="bg-slate-950 text-slate-400 uppercase text-[10px] tracking-wider border-b border-slate-800">
              <tr>
                <th className="p-3.5">Дата / № Заказа</th>
                <th className="p-3.5">Форма Оплаты</th>
                <th className="p-3.5">Получатель Денег</th>
                <th className="p-3.5">Сумма</th>
                <th className="p-3.5">Оператор</th>
                <th className="p-3.5">Статус</th>
                <th className="p-3.5 text-right">Действие</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-800/80">
              {payments.map((p) => (
                <tr key={p.id} className="hover:bg-slate-800/50 transition-colors">
                  <td className="p-3.5">
                    <div className="font-mono font-bold text-orange-300">{p.orderNumber}</div>
                    <div className="text-[10px] text-slate-500">
                      {new Date(p.createdAt).toLocaleTimeString('ru-RU')}
                    </div>
                  </td>

                  <td className="p-3.5">
                    <span className="font-semibold text-slate-200">
                      {p.method === 'cash' ? 'Наличные' : p.method === 'card' ? 'Карта/СБП' : p.method === 'transfer' ? 'Перевод ФИО' : 'Безнал ООО'}
                    </span>
                  </td>

                  <td className="p-3.5 font-medium text-slate-300">
                    {p.recipientName}
                  </td>

                  <td className="p-3.5 font-bold text-white text-sm">
                    {p.amount.toLocaleString('ru-RU')} ₽
                  </td>

                  <td className="p-3.5 text-slate-400">
                    {p.operatorUserName}
                  </td>

                  <td className="p-3.5">
                    <span
                      className={`text-[10px] font-bold px-2 py-0.5 rounded ${
                        p.status === 'completed'
                          ? 'bg-emerald-500/20 text-emerald-400'
                          : 'bg-amber-500/20 text-amber-300'
                      }`}
                    >
                      {p.status === 'completed' ? 'Проведено' : 'Скорректировано'}
                    </span>
                  </td>

                  <td className="p-3.5 text-right">
                    <button
                      onClick={() => {
                        setSelectedPaymentId(p.id);
                        setNewAmountInput(p.amount);
                        setNewRecipientId(p.recipientId);
                        setCorrectModalOpen(true);
                      }}
                      className="px-2.5 py-1 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-lg text-[11px] font-semibold border border-slate-700 transition-colors inline-flex items-center gap-1 cursor-pointer"
                    >
                      <Edit3 className="w-3.5 h-3.5 text-amber-400" />
                      Корректировка
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      {/* Open Shift Modal */}
      {openShiftModal && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 border border-slate-800 rounded-2xl max-w-md w-full p-6 shadow-2xl text-slate-100">
            <h3 className="text-lg font-bold text-emerald-400 flex items-center gap-2 mb-4">
              <Play className="w-5 h-5" />
              Открытие Кассовой Смены
            </h3>

            <div className="space-y-3 text-xs mb-5">
              <div>
                <label className="block text-slate-300 font-semibold mb-1">Начальный остаток наличных в кассе (размен) ₽</label>
                <input
                  type="number"
                  value={openingBalanceInput}
                  onChange={(e) => setOpeningBalanceInput(Number(e.target.value))}
                  className="w-full bg-slate-800 border border-slate-700 rounded-xl p-3 text-sm font-bold text-white focus:outline-none focus:border-emerald-500"
                />
              </div>
            </div>

            <div className="flex items-center justify-end gap-2.5">
              <button
                onClick={() => setOpenShiftModal(false)}
                className="px-4 py-2 rounded-xl text-xs font-medium text-slate-300 hover:bg-slate-800"
              >
                Отмена
              </button>
              <button
                onClick={handleOpenShift}
                className="px-4 py-2 rounded-xl text-xs font-bold text-slate-950 bg-emerald-400 hover:bg-emerald-300 transition-colors"
              >
                Открыть Смену
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Close Shift Modal */}
      {closeShiftModal && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 border border-slate-800 rounded-2xl max-w-md w-full p-6 shadow-2xl text-slate-100">
            <h3 className="text-lg font-bold text-red-400 flex items-center gap-2 mb-4">
              <Square className="w-5 h-5" />
              Закрытие Кассовой Смены
            </h3>

            <div className="space-y-3 text-xs mb-5">
              <div>
                <label className="block text-slate-300 font-semibold mb-1">Фактическая сумма наличных в кассе (Пересчет) ₽</label>
                <input
                  type="number"
                  value={closingCashActual}
                  onChange={(e) => setClosingCashActual(Number(e.target.value))}
                  className="w-full bg-slate-800 border border-slate-700 rounded-xl p-3 text-sm font-bold text-white focus:outline-none focus:border-red-500"
                />
              </div>

              <div>
                <label className="block text-slate-300 font-semibold mb-1">Замечания к смене / Расхождения</label>
                <textarea
                  value={shiftNotes}
                  onChange={(e) => setShiftNotes(e.target.value)}
                  placeholder="Замечания кассира..."
                  className="w-full bg-slate-800 border border-slate-700 rounded-xl p-2.5 text-white h-20"
                />
              </div>
            </div>

            <div className="flex items-center justify-end gap-2.5">
              <button
                onClick={() => setCloseShiftModal(false)}
                className="px-4 py-2 rounded-xl text-xs font-medium text-slate-300 hover:bg-slate-800"
              >
                Отмена
              </button>
              <button
                onClick={handleCloseShift}
                className="px-4 py-2 rounded-xl text-xs font-bold text-white bg-red-600 hover:bg-red-500 transition-colors"
              >
                Сформировать Итог и Закрыть
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Cash Movement Modal */}
      {cashMoveModal && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 border border-slate-800 rounded-2xl max-w-md w-full p-6 shadow-2xl text-slate-100">
            <h3 className="text-lg font-bold text-amber-400 flex items-center gap-2 mb-4">
              <ArrowDownRight className="w-5 h-5" />
              Инкассация / Выемка Средств из Кассы
            </h3>

            <div className="space-y-3 text-xs mb-5">
              <div className="flex items-center gap-3">
                <label className="flex items-center gap-1.5 cursor-pointer">
                  <input
                    type="radio"
                    name="moveType"
                    checked={moveType === 'encashment'}
                    onChange={() => setMoveType('encashment')}
                  />
                  <span>Инкассация (Сдача инкассаторам)</span>
                </label>
                <label className="flex items-center gap-1.5 cursor-pointer">
                  <input
                    type="radio"
                    name="moveType"
                    checked={moveType === 'withdrawal'}
                    onChange={() => setMoveType('withdrawal')}
                  />
                  <span>Служебная Выемка</span>
                </label>
              </div>

              <div>
                <label className="block text-slate-300 font-semibold mb-1">Сумма (₽)</label>
                <input
                  type="number"
                  value={moveAmount}
                  onChange={(e) => setMoveAmount(Number(e.target.value))}
                  className="w-full bg-slate-800 border border-slate-700 rounded-xl p-2.5 text-white"
                />
              </div>

              <div>
                <label className="block text-slate-300 font-semibold mb-1">Основание / Причина *</label>
                <textarea
                  value={moveReason}
                  onChange={(e) => setMoveReason(e.target.value)}
                  placeholder="Укажите основание выемки..."
                  className="w-full bg-slate-800 border border-slate-700 rounded-xl p-2.5 text-white h-20"
                />
              </div>
            </div>

            <div className="flex items-center justify-end gap-2.5">
              <button
                onClick={() => setCashMoveModal(false)}
                className="px-4 py-2 rounded-xl text-xs font-medium text-slate-300 hover:bg-slate-800"
              >
                Отмена
              </button>
              <button
                onClick={handleExecuteCashMove}
                className="px-4 py-2 rounded-xl text-xs font-bold text-slate-950 bg-amber-400 hover:bg-amber-300 transition-colors"
              >
                Провести
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Payment Correction Modal */}
      {correctModalOpen && selectedPayment && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 border border-slate-800 rounded-2xl max-w-md w-full p-6 shadow-2xl text-slate-100">
            <h3 className="text-lg font-bold text-amber-400 flex items-center gap-2 mb-2">
              <Edit3 className="w-5 h-5" />
              Корректировка Оплаты №{selectedPayment.orderNumber}
            </h3>
            <p className="text-xs text-slate-300 mb-4">
              В соответствии с техническими правилами системы изменение проводимой оплаты сохраняет прежние значения и создает обязательную аудиторскую запись.
            </p>

            <div className="space-y-3 text-xs mb-5">
              <div>
                <label className="block text-slate-300 font-semibold mb-1">Новая сумма оплаты (₽)</label>
                <input
                  type="number"
                  value={newAmountInput}
                  onChange={(e) => setNewAmountInput(Number(e.target.value))}
                  className="w-full bg-slate-800 border border-slate-700 rounded-xl p-2.5 text-white"
                />
              </div>

              <div>
                <label className="block text-slate-300 font-semibold mb-1">Получатель денег</label>
                <select
                  value={newRecipientId}
                  onChange={(e) => setNewRecipientId(e.target.value)}
                  className="w-full bg-slate-800 border border-slate-700 rounded-xl p-2.5 text-white"
                >
                  {recipients.map((r) => (
                    <option key={r.id} value={r.id}>
                      {r.name}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-slate-300 font-semibold mb-1">Причина корректировки *</label>
                <textarea
                  value={correctionReasonInput}
                  onChange={(e) => setCorrectionReasonInput(e.target.value)}
                  placeholder="Обязательное указание причины..."
                  className="w-full bg-slate-800 border border-slate-700 rounded-xl p-2.5 text-white h-20"
                />
              </div>
            </div>

            <div className="flex items-center justify-end gap-2.5">
              <button
                onClick={() => setCorrectModalOpen(false)}
                className="px-4 py-2 rounded-xl text-xs font-medium text-slate-300 hover:bg-slate-800"
              >
                Отмена
              </button>
              <button
                onClick={handleExecuteCorrection}
                className="px-4 py-2 rounded-xl text-xs font-bold text-slate-950 bg-amber-400 hover:bg-amber-300 transition-colors"
              >
                Сохранить корректировку
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
