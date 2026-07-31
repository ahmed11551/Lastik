import React, { useState } from 'react';
import { useApp } from '../context/AppContext';
import { Order, OrderStatus } from '../types';
import {
  ShoppingBag,
  Search,
  Filter,
  CheckCircle,
  XCircle,
  AlertTriangle,
  Clock,
  PackageCheck,
  CreditCard,
  Ban,
  MessageSquare,
  Eye,
  Check,
  UserCheck
} from 'lucide-react';

interface OrdersViewProps {
  searchTerm?: string;
  onOpenNewOrder?: () => void;
}

export const OrdersView: React.FC<OrdersViewProps> = ({ searchTerm = '' }) => {
  const {
    orders,
    releaseOrderItem,
    cancelOrder,
    acceptPayment,
    markImportantCommentRead,
    activeUser,
    recipients
  } = useApp();

  const [filterScenario, setFilterScenario] = useState<string>('all');
  const [filterStatus, setFilterStatus] = useState<string>('all');
  const [filterPayment, setFilterPayment] = useState<string>('all');
  const [localSearch, setLocalSearch] = useState<string>(searchTerm);

  const [selectedOrder, setSelectedOrder] = useState<Order | null>(null);

  // Cancellation Modal
  const [cancelModalOpen, setCancelModalOpen] = useState(false);
  const [cancelReason, setCancelReason] = useState('');

  // Payment Modal
  const [paymentModalOpen, setPaymentModalOpen] = useState(false);
  const [paymentAmount, setPaymentAmount] = useState<number>(0);
  const [paymentMethod, setPaymentMethod] = useState<any>('cash');
  const [paymentRecipientId, setPaymentRecipientId] = useState<string>('');

  const query = localSearch || searchTerm;

  const filteredOrders = orders.filter((o) => {
    if (filterScenario !== 'all' && o.scenario !== filterScenario) return false;
    if (filterStatus !== 'all' && o.status !== filterStatus) return false;
    if (filterPayment !== 'all' && o.paymentStatus !== filterPayment) return false;

    if (query) {
      const q = query.toLowerCase().trim();
      const matchNum = o.orderNumber.toLowerCase().includes(q);
      const matchCust = o.customerName.toLowerCase().includes(q);
      const matchPhone = o.customerPhone.includes(q);
      const matchVeh = o.vehicleInfo?.toLowerCase().includes(q);
      if (!matchNum && !matchCust && !matchPhone && !matchVeh) return false;
    }
    return true;
  });

  const handleOpenOrderDrawer = (ord: Order) => {
    setSelectedOrder(ord);
    if (ord.importantComment && activeUser) {
      markImportantCommentRead(ord.id);
    }
  };

  const handleReleaseItem = async (itemId: string) => {
    if (!selectedOrder) return;
    const updated = await releaseOrderItem(selectedOrder.id, itemId);
    setSelectedOrder(updated);
  };

  const handleExecuteCancel = async () => {
    if (!selectedOrder) return;
    if (!cancelReason.trim()) return;
    const updated = await cancelOrder(selectedOrder.id, cancelReason);
    setSelectedOrder(updated);
    setCancelModalOpen(false);
    setCancelReason('');
  };

  const handleExecutePayment = async () => {
    if (!selectedOrder) return;
    const recipient = recipients.find((r) => r.id === paymentRecipientId) || recipients[0];
    await acceptPayment({
      orderId: selectedOrder.id,
      orderNumber: selectedOrder.orderNumber,
      amount: paymentAmount || selectedOrder.dueAmount,
      method: paymentMethod,
      recipientId: recipient?.id || 'rec-1',
      recipientName: recipient?.name || 'Основная касса'
    });
    setPaymentModalOpen(false);
    // Refresh selected order
    const updated = orders.find((o) => o.id === selectedOrder.id);
    if (updated) setSelectedOrder(updated);
  };

  return (
    <div className="space-y-5">
      {/* View Title */}
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 bg-slate-900 p-5 rounded-2xl border border-slate-800">
        <div>
          <h1 className="text-lg font-bold text-white flex items-center gap-2">
            <ShoppingBag className="w-5 h-5 text-orange-400" />
            Заказы покупателей ({filteredOrders.length})
          </h1>
          <p className="text-xs text-slate-400 mt-0.5">
            Операционный реестр продаж, резервов на складе и выдачи товаров
          </p>
        </div>

        {/* Filters bar */}
        <div className="flex flex-wrap items-center gap-2 w-full sm:w-auto">
          <select
            value={filterScenario}
            onChange={(e) => setFilterScenario(e.target.value)}
            className="bg-slate-800 border border-slate-700 text-slate-200 text-xs rounded-xl px-3 py-1.5 focus:outline-none"
          >
            <option value="all">Все сценарии</option>
            <option value="with_installation">Продажа с установкой</option>
            <option value="without_installation">Продажа без установки</option>
          </select>

          <select
            value={filterStatus}
            onChange={(e) => setFilterStatus(e.target.value)}
            className="bg-slate-800 border border-slate-700 text-slate-200 text-xs rounded-xl px-3 py-1.5 focus:outline-none"
          >
            <option value="all">Все статусы заказа</option>
            <option value="created">Создан</option>
            <option value="in_progress">В работе</option>
            <option value="ready_for_release">Готов к выдаче</option>
            <option value="released">Выдан</option>
            <option value="completed">Закрыт</option>
            <option value="cancelled">Отменён</option>
          </select>

          <select
            value={filterPayment}
            onChange={(e) => setFilterPayment(e.target.value)}
            className="bg-slate-800 border border-slate-700 text-slate-200 text-xs rounded-xl px-3 py-1.5 focus:outline-none"
          >
            <option value="all">Все оплаты</option>
            <option value="paid">Оплачено</option>
            <option value="partially_paid">Частично</option>
            <option value="unpaid">Не оплачено</option>
          </select>
        </div>
      </div>

      {/* Orders Table */}
      <div className="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-sm">
        <div className="overflow-x-auto">
          <table className="w-full text-left text-xs text-slate-300">
            <thead className="bg-slate-950 text-slate-400 uppercase text-[10px] tracking-wider border-b border-slate-800">
              <tr>
                <th className="p-3.5">№ Заказа / Дата</th>
                <th className="p-3.5">Сценарий</th>
                <th className="p-3.5">Покупатель / Автомобиль</th>
                <th className="p-3.5">Позиции</th>
                <th className="p-3.5">Ответственные</th>
                <th className="p-3.5">Сумма / Оплата</th>
                <th className="p-3.5">Статус</th>
                <th className="p-3.5 text-right">Действие</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-800/80">
              {filteredOrders.map((ord) => (
                <tr
                  key={ord.id}
                  onClick={() => handleOpenOrderDrawer(ord)}
                  className="hover:bg-slate-800/50 transition-colors cursor-pointer"
                >
                  <td className="p-3.5">
                    <div className="font-mono font-bold text-orange-300 text-sm">
                      {ord.orderNumber}
                    </div>
                    <div className="text-[11px] text-slate-500">
                      {new Date(ord.createdAt).toLocaleDateString('ru-RU')} {new Date(ord.createdAt).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })}
                    </div>
                  </td>

                  <td className="p-3.5">
                    <span
                      className={`inline-block px-2 py-0.5 rounded text-[10px] font-bold ${
                        ord.scenario === 'with_installation'
                          ? 'bg-blue-500/15 text-blue-300 border border-blue-500/30'
                          : 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/30'
                      }`}
                    >
                      {ord.scenario === 'with_installation' ? 'С установкой' : 'Без установки'}
                    </span>
                  </td>

                  <td className="p-3.5">
                    <div className="font-bold text-slate-100">{ord.customerName}</div>
                    <div className="text-[11px] text-slate-400">{ord.customerPhone}</div>
                    {ord.vehicleInfo && (
                      <div className="text-[11px] text-orange-400 font-medium mt-0.5">
                        {ord.vehicleInfo}
                      </div>
                    )}
                  </td>

                  <td className="p-3.5 max-w-xs">
                    <div className="text-slate-200 line-clamp-1 font-medium">
                      {ord.items.map((i) => i.name).join(', ')}
                    </div>
                    <div className="text-[10px] text-slate-500">
                      Всего позиций: {ord.items.length}
                    </div>
                  </td>

                  <td className="p-3.5 text-[11px]">
                    <div>Продавец: <span className="text-slate-200 font-medium">{ord.responsibleSellerName}</span></div>
                    {ord.masterExecutorName && (
                      <div>Мастер: <span className="text-slate-200 font-medium">{ord.masterExecutorName}</span></div>
                    )}
                  </td>

                  <td className="p-3.5">
                    <div className="font-bold text-white text-sm">
                      {ord.totalAmount.toLocaleString('ru-RU')} ₽
                    </div>
                    <span
                      className={`text-[10px] font-bold px-1.5 py-0.2 rounded ${
                        ord.paymentStatus === 'paid'
                          ? 'bg-emerald-500/20 text-emerald-400'
                          : ord.paymentStatus === 'partially_paid'
                          ? 'bg-amber-500/20 text-amber-300'
                          : 'bg-red-500/20 text-red-300'
                      }`}
                    >
                      {ord.paymentStatus === 'paid'
                        ? 'Оплачено'
                        : ord.paymentStatus === 'partially_paid'
                        ? `Оплачено ${ord.paidAmount} ₽`
                        : 'Не оплачено'}
                    </span>
                  </td>

                  <td className="p-3.5">
                    <span
                      className={`inline-block px-2 py-0.5 rounded text-[10px] font-bold ${
                        ord.status === 'in_progress'
                          ? 'bg-amber-500/20 text-amber-300'
                          : ord.status === 'ready_for_release'
                          ? 'bg-blue-500/20 text-blue-300'
                          : ord.status === 'completed' || ord.status === 'released'
                          ? 'bg-emerald-500/20 text-emerald-400'
                          : ord.status === 'cancelled'
                          ? 'bg-red-500/20 text-red-400'
                          : 'bg-slate-800 text-slate-300'
                      }`}
                    >
                      {ord.status === 'in_progress'
                        ? 'В работе'
                        : ord.status === 'ready_for_release'
                        ? 'Готов к выдаче'
                        : ord.status === 'released'
                        ? 'Выдан'
                        : ord.status === 'completed'
                        ? 'Закрыт'
                        : ord.status === 'cancelled'
                        ? 'Отменён'
                        : 'Создан'}
                    </span>
                  </td>

                  <td className="p-3.5 text-right">
                    <button
                      onClick={(e) => {
                        e.stopPropagation();
                        handleOpenOrderDrawer(ord);
                      }}
                      className="p-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-lg transition-colors inline-flex items-center gap-1 text-xs font-semibold"
                    >
                      <Eye className="w-3.5 h-3.5" />
                      Детали
                    </button>
                  </td>
                </tr>
              ))}

              {filteredOrders.length === 0 && (
                <tr>
                  <td colSpan={8} className="p-8 text-center text-slate-500">
                    Заказы по заданным фильтрам не найдены
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* Order Detail Drawer Modal */}
      {selectedOrder && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex justify-end">
          <div className="bg-slate-900 border-l border-slate-800 w-full max-w-2xl h-full p-6 overflow-y-auto text-slate-100 flex flex-col justify-between shadow-2xl">
            <div>
              {/* Header */}
              <div className="flex items-center justify-between pb-4 border-b border-slate-800 mb-4">
                <div>
                  <div className="flex items-center gap-2">
                    <h2 className="text-lg font-extrabold text-orange-400 font-mono">
                      {selectedOrder.orderNumber}
                    </h2>
                    <span
                      className={`text-[10px] font-bold px-2 py-0.5 rounded ${
                        selectedOrder.scenario === 'with_installation'
                          ? 'bg-blue-500/20 text-blue-300'
                          : 'bg-emerald-500/20 text-emerald-300'
                      }`}
                    >
                      {selectedOrder.scenario === 'with_installation' ? 'Продажа с установкой' : 'Продажа без установки'}
                    </span>
                  </div>
                  <div className="text-xs text-slate-400 mt-0.5">
                    Создан: {new Date(selectedOrder.createdAt).toLocaleString('ru-RU')}
                  </div>
                </div>

                <button
                  onClick={() => setSelectedOrder(null)}
                  className="p-2 hover:bg-slate-800 rounded-xl text-slate-400 hover:text-slate-200"
                >
                  ✕
                </button>
              </div>

              {/* Important Comment Alert */}
              {selectedOrder.importantComment && (
                <div className="p-3.5 bg-amber-500/10 border border-amber-500/30 rounded-xl mb-4 flex items-start gap-3">
                  <AlertTriangle className="w-5 h-5 text-amber-400 shrink-0 mt-0.5" />
                  <div>
                    <div className="text-xs font-bold text-amber-300">ВАЖНОЕ ПРИМЕЧАНИЕ К ЗАКАЗУ:</div>
                    <div className="text-xs text-amber-200/90 mt-0.5">
                      {selectedOrder.importantComment}
                    </div>
                  </div>
                </div>
              )}

              {/* Customer & Vehicle Info */}
              <div className="grid grid-cols-2 gap-3 p-3.5 bg-slate-800/60 rounded-xl border border-slate-700/60 mb-5 text-xs">
                <div>
                  <span className="text-slate-400 block text-[10px] uppercase font-bold">Покупатель</span>
                  <span className="font-bold text-slate-100">{selectedOrder.customerName}</span>
                  <div className="text-slate-400">{selectedOrder.customerPhone}</div>
                </div>
                <div>
                  <span className="text-slate-400 block text-[10px] uppercase font-bold">Автомобиль</span>
                  <span className="font-bold text-orange-300">{selectedOrder.vehicleInfo || 'Без привязки авто'}</span>
                </div>
                <div>
                  <span className="text-slate-400 block text-[10px] uppercase font-bold">Продавец</span>
                  <span className="text-slate-200">{selectedOrder.responsibleSellerName}</span>
                </div>
                <div>
                  <span className="text-slate-400 block text-[10px] uppercase font-bold">Мастер-исполнитель</span>
                  <span className="text-slate-200">{selectedOrder.masterExecutorName || 'Не назначен'}</span>
                </div>
              </div>

              {/* Items List (Snapshot View) */}
              <div className="mb-5">
                <h3 className="text-xs font-bold text-slate-300 uppercase tracking-wider mb-2">
                  Позиции заказа (Фиксация snapshot цен и резерва)
                </h3>
                <div className="space-y-2">
                  {selectedOrder.items.map((item) => (
                    <div
                      key={item.id}
                      className="p-3 bg-slate-800/80 rounded-xl border border-slate-700/80 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 text-xs"
                    >
                      <div>
                        <div className="font-bold text-slate-100">{item.name}</div>
                        <div className="text-[11px] text-slate-400 mt-0.5">
                          {item.qty} {item.itemType === 'product' ? 'шт' : 'услуга'} × {item.price.toLocaleString('ru-RU')} ₽ = <strong className="text-white">{item.totalSum.toLocaleString('ru-RU')} ₽</strong>
                        </div>
                        <div className="text-[10px] text-slate-500 mt-0.5">
                          KPI выработка: {item.kpiRulePercent}% ({item.kpiAmount} ₽)
                        </div>
                      </div>

                      <div className="flex items-center gap-2 self-end sm:self-center">
                        <span
                          className={`text-[10px] font-bold px-2 py-0.5 rounded ${
                            item.status === 'released'
                              ? 'bg-emerald-500/20 text-emerald-400'
                              : item.status === 'reserved'
                              ? 'bg-blue-500/20 text-blue-300'
                              : 'bg-slate-700 text-slate-300'
                          }`}
                        >
                          {item.status === 'released'
                            ? 'Выдан'
                            : item.status === 'reserved'
                            ? 'Зарезервирован'
                            : 'Добавлен'}
                        </span>

                        {item.status !== 'released' && item.itemType === 'product' && (
                          <button
                            onClick={() => handleReleaseItem(item.id)}
                            className="px-2.5 py-1 bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-[11px] rounded-lg transition-colors flex items-center gap-1 cursor-pointer"
                          >
                            <PackageCheck className="w-3.5 h-3.5" />
                            Выдать товар
                          </button>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              </div>

              {/* Payment Status Block */}
              <div className="p-4 bg-slate-800/80 border border-slate-700 rounded-xl mb-5">
                <div className="flex items-center justify-between mb-2">
                  <span className="text-xs font-bold text-slate-300">Статус оплаты:</span>
                  <span className="text-sm font-black text-white">
                    {selectedOrder.totalAmount.toLocaleString('ru-RU')} ₽
                  </span>
                </div>
                <div className="text-xs text-slate-400 flex items-center justify-between">
                  <span>Оплачено: <strong className="text-emerald-400">{selectedOrder.paidAmount.toLocaleString('ru-RU')} ₽</strong></span>
                  <span>Остаток: <strong className="text-orange-400">{selectedOrder.dueAmount.toLocaleString('ru-RU')} ₽</strong></span>
                </div>
              </div>
            </div>

            {/* Bottom Actions */}
            <div className="pt-4 border-t border-slate-800 flex items-center justify-between gap-3">
              {selectedOrder.status !== 'cancelled' && (
                <button
                  onClick={() => setCancelModalOpen(true)}
                  className="px-3 py-2 bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/30 rounded-xl text-xs font-bold transition-colors flex items-center gap-1.5 cursor-pointer"
                >
                  <Ban className="w-4 h-4" />
                  Отменить заказ
                </button>
              )}

              {selectedOrder.dueAmount > 0 && selectedOrder.status !== 'cancelled' && (
                <button
                  onClick={() => {
                    setPaymentAmount(selectedOrder.dueAmount);
                    setPaymentModalOpen(true);
                  }}
                  className="px-4 py-2 bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-bold text-xs rounded-xl transition-colors flex items-center gap-1.5 cursor-pointer"
                >
                  <CreditCard className="w-4 h-4" />
                  Принять оплату ({selectedOrder.dueAmount.toLocaleString('ru-RU')} ₽)
                </button>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Cancel Order Modal */}
      {cancelModalOpen && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 border border-slate-800 rounded-2xl max-w-md w-full p-6 shadow-2xl text-slate-100">
            <h3 className="text-lg font-bold text-red-400 flex items-center gap-2 mb-2">
              <Ban className="w-5 h-5" />
              Отмена заказа {selectedOrder?.orderNumber}
            </h3>
            <p className="text-xs text-slate-300 mb-4">
              В соответствии с техническим регламентом отмена заказа требует обязательного указания причины. Активные резервы на складе будут автоматически сняты.
            </p>

            <label className="block text-xs font-semibold text-slate-300 mb-1.5">
              Причина отмены <span className="text-red-400">*</span>
            </label>
            <textarea
              value={cancelReason}
              onChange={(e) => setCancelReason(e.target.value)}
              placeholder="Укажите причину отмены..."
              className="w-full bg-slate-800 border border-slate-700 rounded-xl p-3 text-xs text-slate-100 focus:outline-none focus:border-red-500 h-24 mb-5"
            />

            <div className="flex items-center justify-end gap-2.5">
              <button
                onClick={() => setCancelModalOpen(false)}
                className="px-4 py-2 rounded-xl text-xs font-medium text-slate-300 hover:bg-slate-800"
              >
                Отмена
              </button>
              <button
                onClick={handleExecuteCancel}
                disabled={!cancelReason.trim()}
                className="px-4 py-2 rounded-xl text-xs font-bold text-white bg-red-600 hover:bg-red-500 disabled:opacity-50 transition-colors"
              >
                Подтвердить отмену
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Accept Payment Modal */}
      {paymentModalOpen && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 border border-slate-800 rounded-2xl max-w-md w-full p-6 shadow-2xl text-slate-100">
            <h3 className="text-lg font-bold text-emerald-400 flex items-center gap-2 mb-4">
              <CreditCard className="w-5 h-5" />
              Прием оплаты по заказу {selectedOrder?.orderNumber}
            </h3>

            <div className="space-y-4 mb-5 text-xs">
              <div>
                <label className="block text-slate-300 font-semibold mb-1">Сумма платежа (₽)</label>
                <input
                  type="number"
                  value={paymentAmount}
                  onChange={(e) => setPaymentAmount(Number(e.target.value))}
                  className="w-full bg-slate-800 border border-slate-700 rounded-xl p-2.5 text-sm font-bold text-white focus:outline-none focus:border-emerald-500"
                />
              </div>

              <div>
                <label className="block text-slate-300 font-semibold mb-1">Форма оплаты</label>
                <select
                  value={paymentMethod}
                  onChange={(e) => setPaymentMethod(e.target.value)}
                  className="w-full bg-slate-800 border border-slate-700 rounded-xl p-2.5 text-xs text-white focus:outline-none"
                >
                  <option value="cash">Наличные в кассу</option>
                  <option value="card">Банковская карта (Эквайринг / СБП)</option>
                  <option value="transfer">Перевод на карту ФИО</option>
                  <option value="account">Безналичный счет (ООО / ИП)</option>
                  <option value="mixed">Смешанная оплата</option>
                </select>
              </div>

              <div>
                <label className="block text-slate-300 font-semibold mb-1">Получатель денег</label>
                <select
                  value={paymentRecipientId}
                  onChange={(e) => setPaymentRecipientId(e.target.value)}
                  className="w-full bg-slate-800 border border-slate-700 rounded-xl p-2.5 text-xs text-white focus:outline-none"
                >
                  {recipients.map((r) => (
                    <option key={r.id} value={r.id}>
                      {r.name} ({r.details})
                    </option>
                  ))}
                </select>
              </div>
            </div>

            <div className="flex items-center justify-end gap-2.5">
              <button
                onClick={() => setPaymentModalOpen(false)}
                className="px-4 py-2 rounded-xl text-xs font-medium text-slate-300 hover:bg-slate-800"
              >
                Отмена
              </button>
              <button
                onClick={handleExecutePayment}
                className="px-4 py-2 rounded-xl text-xs font-bold text-slate-950 bg-emerald-400 hover:bg-emerald-300 transition-colors"
              >
                Провести оплату
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
