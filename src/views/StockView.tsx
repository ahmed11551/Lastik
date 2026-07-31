import React, { useState } from 'react';
import { useApp } from '../context/AppContext';
import {
  Boxes,
  RefreshCw,
  ArrowRightLeft,
  Eye,
  EyeOff,
  Search,
  AlertTriangle,
  CheckCircle2,
  FileCode,
  Building
} from 'lucide-react';

export const StockView: React.FC = () => {
  const {
    stock,
    products,
    warehouses,
    activeUser,
    transferStock,
    import1CStock
  } = useApp();

  const [selectedWarehouseId, setSelectedWarehouseId] = useState<string>('all');
  const [searchTerm, setSearchTerm] = useState('');
  const [showCostPrices, setShowCostPrices] = useState(false);

  // Stock Transfer Modal
  const [transferModalOpen, setTransferModalOpen] = useState(false);
  const [transferProductId, setTransferProductId] = useState('');
  const [sourceWhId, setSourceWhId] = useState('wh-1');
  const [targetWhId, setTargetWhId] = useState('wh-2');
  const [transferQty, setTransferQty] = useState(4);
  const [transferReason, setTransferReason] = useState('');

  // 1C Import Modal
  const [import1CModalOpen, setImport1CModalOpen] = useState(false);
  const [importWarehouseId, setImportWarehouseId] = useState('wh-1');
  const [importResult, setImportResult] = useState<any>(null);

  // Permission check for viewing cost price
  const canViewCost = activeUser?.role === 'superadmin' || activeUser?.role === 'accountant' || activeUser?.role === 'platform_owner';

  const filteredStock = stock.filter((st) => {
    if (selectedWarehouseId !== 'all' && st.warehouseId !== selectedWarehouseId) return false;

    if (searchTerm) {
      const prod = products.find((p) => p.id === st.productId);
      const q = searchTerm.toLowerCase().trim();
      if (!prod) return false;
      const matchName = prod.name.toLowerCase().includes(q);
      const matchSku = prod.sku.toLowerCase().includes(q);
      const matchBrand = prod.brand.toLowerCase().includes(q);
      if (!matchName && !matchSku && !matchBrand) return false;
    }
    return true;
  });

  const handleExecuteTransfer = async () => {
    if (!transferProductId || !sourceWhId || !targetWhId || sourceWhId === targetWhId) {
      alert('Выберите корректные склады источника и назначения');
      return;
    }
    if (!transferReason.trim()) {
      alert('Укажите причину перемещения между складами');
      return;
    }

    await transferStock(transferProductId, sourceWhId, targetWhId, transferQty, transferReason);
    setTransferModalOpen(false);
    setTransferProductId('');
    setTransferReason('');
  };

  const handleExecute1CImport = async () => {
    const res = await import1CStock(importWarehouseId);
    setImportResult(res);
  };

  return (
    <div className="space-y-5">
      {/* Header */}
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 bg-slate-900 p-5 rounded-2xl border border-slate-800">
        <div>
          <h1 className="text-lg font-bold text-white flex items-center gap-2">
            <Boxes className="w-5 h-5 text-orange-400" />
            Складской Учёт & Загрузка Остатков 1С
          </h1>
          <p className="text-xs text-slate-400 mt-0.5">
            Фактический остаток minus активные резервы = Доступный остаток. Журнал перемещений и синхронизация CommerceML2
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {canViewCost && (
            <button
              onClick={() => setShowCostPrices(!showCostPrices)}
              className={`px-3 py-2 rounded-xl text-xs font-semibold border flex items-center gap-1.5 transition-colors cursor-pointer ${
                showCostPrices
                  ? 'bg-amber-500/20 text-amber-300 border-amber-500/40'
                  : 'bg-slate-800 text-slate-300 border-slate-700'
              }`}
            >
              {showCostPrices ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
              <span>{showCostPrices ? 'Скрыть закупочные' : 'Показать закупочные'}</span>
            </button>
          )}

          <button
            onClick={() => setTransferModalOpen(true)}
            className="px-3.5 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 font-bold rounded-xl text-xs flex items-center gap-1.5 transition-all cursor-pointer"
          >
            <ArrowRightLeft className="w-4 h-4 text-blue-400" />
            Перемещение
          </button>

          <button
            onClick={() => setImport1CModalOpen(true)}
            className="px-3.5 py-2 bg-orange-500 hover:bg-orange-400 text-slate-950 font-bold rounded-xl text-xs flex items-center gap-1.5 shadow-md shadow-orange-500/20 transition-all cursor-pointer"
          >
            <RefreshCw className="w-4 h-4" />
            Забор остатков 1С
          </button>
        </div>
      </div>

      {/* Filter Bar */}
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 bg-slate-900 p-3 rounded-2xl border border-slate-800">
        <div className="flex items-center gap-2">
          <Building className="w-4 h-4 text-slate-400 ml-2" />
          <select
            value={selectedWarehouseId}
            onChange={(e) => setSelectedWarehouseId(e.target.value)}
            className="bg-slate-800 border border-slate-700 text-slate-200 text-xs rounded-xl px-3 py-1.5 focus:outline-none cursor-pointer"
          >
            <option value="all">Все склады системы</option>
            {warehouses.map((w) => (
              <option key={w.id} value={w.id}>
                {w.name} ({w.code})
              </option>
            ))}
          </select>
        </div>

        <div className="relative w-full sm:w-64">
          <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
          <input
            type="text"
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
            placeholder="Поиск номенклатуры, SKU, бренда..."
            className="w-full bg-slate-800 border border-slate-700/80 rounded-xl pl-9 pr-3 py-1.5 text-xs text-slate-100 focus:outline-none focus:border-orange-500"
          />
        </div>
      </div>

      {/* Stock Table */}
      <div className="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-sm">
        <div className="overflow-x-auto">
          <table className="w-full text-left text-xs text-slate-300">
            <thead className="bg-slate-950 text-slate-400 uppercase text-[10px] tracking-wider border-b border-slate-800">
              <tr>
                <th className="p-3.5">Товар / Номенклатура / SKU</th>
                <th className="p-3.5">Склад</th>
                <th className="p-3.5">Фактический</th>
                <th className="p-3.5">В Резерве</th>
                <th className="p-3.5">Доступный</th>
                <th className="p-3.5">Розничная Цена</th>
                {showCostPrices && <th className="p-3.5 text-amber-300">Закупочная Цена</th>}
                <th className="p-3.5">Источник / Обновлено</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-800/80">
              {filteredStock.map((st) => {
                const prod = products.find((p) => p.id === st.productId);
                const wh = warehouses.find((w) => w.id === st.warehouseId);
                const hasConflict = st.actualQty < st.reservedQty;

                return (
                  <tr key={st.id} className={`hover:bg-slate-800/50 transition-colors ${hasConflict ? 'bg-red-500/10' : ''}`}>
                    <td className="p-3.5">
                      <div className="font-bold text-slate-100 text-xs">
                        {prod?.name || 'Товар'}
                      </div>
                      <div className="text-[10px] text-slate-400 font-mono mt-0.5">
                        SKU: {prod?.sku} {prod?.externalId ? `• 1С ID: ${prod.externalId}` : ''}
                      </div>
                    </td>

                    <td className="p-3.5 font-medium text-slate-300">
                      {wh?.name || st.warehouseId}
                    </td>

                    <td className="p-3.5 font-bold text-slate-100 text-sm">
                      {st.actualQty} {prod?.unit || 'шт'}
                    </td>

                    <td className="p-3.5 font-bold text-blue-400">
                      {st.reservedQty} {prod?.unit || 'шт'}
                    </td>

                    <td className="p-3.5">
                      <span
                        className={`font-black text-sm px-2 py-0.5 rounded ${
                          st.availableQty > 0
                            ? 'bg-emerald-500/20 text-emerald-400'
                            : 'bg-red-500/20 text-red-400'
                        }`}
                      >
                        {st.availableQty} {prod?.unit || 'шт'}
                      </span>
                    </td>

                    <td className="p-3.5 font-bold text-white">
                      {st.price.toLocaleString('ru-RU')} ₽
                    </td>

                    {showCostPrices && (
                      <td className="p-3.5 font-bold text-amber-300">
                        {prod?.pricePurchase ? `${prod.pricePurchase.toLocaleString('ru-RU')} ₽` : '—'}
                      </td>
                    )}

                    <td className="p-3.5 text-[11px] text-slate-400">
                      <div className="flex items-center gap-1">
                        <span className="font-mono text-slate-300">{st.lastSource === '1c_import' ? ' CommerceML2 1С' : ' Ручной'}</span>
                      </div>
                      <div className="text-[10px] text-slate-500">
                        {new Date(st.lastUpdated).toLocaleDateString('ru-RU')} {new Date(st.lastUpdated).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })}
                      </div>
                    </td>
                  </tr>
                );
              })}

              {filteredStock.length === 0 && (
                <tr>
                  <td colSpan={showCostPrices ? 8 : 7} className="p-8 text-center text-slate-500">
                    Остатки по заданным фильтрам не найдены
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* Stock Transfer Modal */}
      {transferModalOpen && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 border border-slate-800 rounded-2xl max-w-md w-full p-6 shadow-2xl text-slate-100">
            <h3 className="text-lg font-bold text-blue-400 flex items-center gap-2 mb-4">
              <ArrowRightLeft className="w-5 h-5" />
              Межскладское Перемещение
            </h3>

            <div className="space-y-3 text-xs mb-5">
              <div>
                <label className="block text-slate-300 font-semibold mb-1">Товар *</label>
                <select
                  value={transferProductId}
                  onChange={(e) => setTransferProductId(e.target.value)}
                  className="w-full bg-slate-800 border border-slate-700 rounded-xl p-2.5 text-white"
                >
                  <option value="">-- Выберите товар --</option>
                  {products.map((p) => (
                    <option key={p.id} value={p.id}>
                      {p.name} (SKU: {p.sku})
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-slate-300 font-semibold mb-1">Склад-Источник *</label>
                <select
                  value={sourceWhId}
                  onChange={(e) => setSourceWhId(e.target.value)}
                  className="w-full bg-slate-800 border border-slate-700 rounded-xl p-2.5 text-white"
                >
                  {warehouses.map((w) => (
                    <option key={w.id} value={w.id}>
                      {w.name}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-slate-300 font-semibold mb-1">Склад-Получатель *</label>
                <select
                  value={targetWhId}
                  onChange={(e) => setTargetWhId(e.target.value)}
                  className="w-full bg-slate-800 border border-slate-700 rounded-xl p-2.5 text-white"
                >
                  {warehouses.map((w) => (
                    <option key={w.id} value={w.id}>
                      {w.name}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-slate-300 font-semibold mb-1">Количество *</label>
                <input
                  type="number"
                  value={transferQty}
                  onChange={(e) => setTransferQty(Number(e.target.value))}
                  className="w-full bg-slate-800 border border-slate-700 rounded-xl p-2.5 text-white"
                />
              </div>

              <div>
                <label className="block text-slate-300 font-semibold mb-1">Причина перемещения *</label>
                <textarea
                  value={transferReason}
                  onChange={(e) => setTransferReason(e.target.value)}
                  placeholder="Причина перемещения..."
                  className="w-full bg-slate-800 border border-slate-700 rounded-xl p-2.5 text-white h-20"
                />
              </div>
            </div>

            <div className="flex items-center justify-end gap-2.5">
              <button
                onClick={() => setTransferModalOpen(false)}
                className="px-4 py-2 rounded-xl text-xs font-medium text-slate-300 hover:bg-slate-800"
              >
                Отмена
              </button>
              <button
                onClick={handleExecuteTransfer}
                className="px-4 py-2 rounded-xl text-xs font-bold text-slate-950 bg-blue-400 hover:bg-blue-300 transition-colors"
              >
                Выполнить перемещение
              </button>
            </div>
          </div>
        </div>
      )}

      {/* 1C CommerceML Import Modal */}
      {import1CModalOpen && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 border border-slate-800 rounded-2xl max-w-md w-full p-6 shadow-2xl text-slate-100">
            <h3 className="text-lg font-bold text-orange-400 flex items-center gap-2 mb-2">
              <RefreshCw className="w-5 h-5" />
              Загрузка Остатков CommerceML2 (1С)
            </h3>
            <p className="text-xs text-slate-300 mb-4">
              Модуль автоматически забирает выгрузку остатков и цен из 1С. При разнице фактического остатка и активных резервов фиксируется конфликт.
            </p>

            <div className="space-y-3 text-xs mb-5">
              <div>
                <label className="block text-slate-300 font-semibold mb-1">Склад назначения *</label>
                <select
                  value={importWarehouseId}
                  onChange={(e) => setImportWarehouseId(e.target.value)}
                  className="w-full bg-slate-800 border border-slate-700 rounded-xl p-2.5 text-white"
                >
                  {warehouses.map((w) => (
                    <option key={w.id} value={w.id}>
                      {w.name} ({w.code})
                    </option>
                  ))}
                </select>
              </div>
            </div>

            {importResult && (
              <div className="p-3.5 bg-slate-800 border border-slate-700 rounded-xl text-xs mb-4 space-y-1.5">
                <div className="text-emerald-400 font-bold flex items-center gap-1.5">
                  <CheckCircle2 className="w-4 h-4" />
                  Успешно обновлено позиций: {importResult.updatedCount}
                </div>
                {importResult.conflicts && importResult.conflicts.length > 0 ? (
                  <div className="text-amber-300 font-semibold pt-1 border-t border-slate-700">
                    {importResult.conflicts.join('; ')}
                  </div>
                ) : (
                  <div className="text-slate-400 text-[11px]">Конфликтов с резервами LASTIK не обнаружено</div>
                )}
              </div>
            )}

            <div className="flex items-center justify-end gap-2.5">
              <button
                onClick={() => {
                  setImport1CModalOpen(false);
                  setImportResult(null);
                }}
                className="px-4 py-2 rounded-xl text-xs font-medium text-slate-300 hover:bg-slate-800"
              >
                Закрыть
              </button>
              <button
                onClick={handleExecute1CImport}
                className="px-4 py-2 rounded-xl text-xs font-bold text-slate-950 bg-orange-400 hover:bg-orange-300 transition-colors"
              >
                Запустить Забор 1С
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
