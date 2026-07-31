import React, { useState } from 'react';
import { useApp } from '../context/AppContext';
import { Car, Search, Plus, User, Disc, ShieldCheck } from 'lucide-react';

export const VehiclesView: React.FC = () => {
  const { vehicles, customers, orders, createVehicle } = useApp();
  const [searchTerm, setSearchTerm] = useState('');

  const [addModalOpen, setAddModalOpen] = useState(false);
  const [selectedCustId, setSelectedCustId] = useState('');
  const [make, setMake] = useState('');
  const [model, setModel] = useState('');
  const [plate, setPlate] = useState('');
  const [vin, setVin] = useState('');
  const [frontSize, setFrontSize] = useState('');
  const [rearSize, setRearSize] = useState('');

  const filteredVehicles = vehicles.filter((v) => {
    if (searchTerm) {
      const q = searchTerm.toLowerCase().trim();
      const matchPlate = v.licensePlate?.toLowerCase().includes(q);
      const matchMake = v.make.toLowerCase().includes(q);
      const matchModel = v.model.toLowerCase().includes(q);
      const matchVin = v.vin?.toLowerCase().includes(q);
      if (!matchPlate && !matchMake && !matchModel && !matchVin) return false;
    }
    return true;
  });

  const handleSaveVehicle = async () => {
    if (!selectedCustId || !make || !model) return;
    await createVehicle({
      customerId: selectedCustId,
      make,
      model,
      licensePlate: plate,
      vin,
      tireSizeFront: frontSize,
      tireSizeRear: rearSize
    });
    setAddModalOpen(false);
    setSelectedCustId('');
    setMake('');
    setModel('');
    setPlate('');
    setVin('');
    setFrontSize('');
    setRearSize('');
  };

  return (
    <div className="space-y-5">
      {/* Header */}
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 bg-slate-900 p-5 rounded-2xl border border-slate-800">
        <div>
          <h1 className="text-lg font-bold text-white flex items-center gap-2">
            <Car className="w-5 h-5 text-orange-400" />
            Реестр Автомобилей Покупателей ({filteredVehicles.length})
          </h1>
          <p className="text-xs text-slate-400 mt-0.5">
            Учёт транспортных средств, привязка госномеров, параметров шин и разболтовок
          </p>
        </div>

        <div className="flex items-center gap-3 w-full sm:w-auto">
          <div className="relative w-full sm:w-64">
            <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input
              type="text"
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              placeholder="Поиск по госномеру, марке, VIN..."
              className="w-full bg-slate-800 border border-slate-700/80 rounded-xl pl-9 pr-3 py-1.5 text-xs text-slate-100 focus:outline-none focus:border-orange-500"
            />
          </div>

          <button
            onClick={() => setAddModalOpen(true)}
            className="px-3.5 py-2 bg-orange-500 hover:bg-orange-400 text-slate-950 font-bold rounded-xl text-xs flex items-center gap-1.5 shadow-md shadow-orange-500/20 transition-all cursor-pointer shrink-0"
          >
            <Plus className="w-4 h-4" />
            Добавить авто
          </button>
        </div>
      </div>

      {/* Vehicle Grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        {filteredVehicles.map((veh) => {
          const owner = customers.find((c) => c.id === veh.customerId);
          const vehOrders = orders.filter((o) => o.vehicleId === veh.id);

          return (
            <div
              key={veh.id}
              className="bg-slate-900 border border-slate-800 hover:border-slate-700/80 rounded-2xl p-4 shadow-sm space-y-3 transition-all"
            >
              <div className="flex items-start justify-between">
                <div>
                  <h3 className="font-extrabold text-white text-sm flex items-center gap-2">
                    {veh.make} {veh.model}
                  </h3>
                  <div className="mt-1">
                    <span className="font-mono font-extrabold text-xs bg-slate-950 text-orange-300 px-2 py-0.5 rounded border border-slate-700 tracking-wider inline-block">
                      {veh.licensePlate || 'БЕЗ ГОСНОМЕРА'}
                    </span>
                  </div>
                </div>

                <div className="w-8 h-8 rounded-xl bg-orange-500/10 border border-orange-500/20 flex items-center justify-center text-orange-400">
                  <Car className="w-4 h-4" />
                </div>
              </div>

              <div className="space-y-1 text-xs text-slate-300">
                <div className="flex items-center gap-1.5 text-slate-300">
                  <User className="w-3.5 h-3.5 text-slate-500 shrink-0" />
                  <span>Владелец: <strong className="text-white">{owner?.name || 'Не привязан'}</strong></span>
                </div>
                {veh.vin && (
                  <div className="text-[11px] text-slate-400 font-mono">
                    VIN: {veh.vin}
                  </div>
                )}
                <div className="flex items-center gap-1.5 text-slate-400 text-[11px]">
                  <Disc className="w-3.5 h-3.5 text-slate-500 shrink-0" />
                  <span>Шины: {veh.tireSizeFront || 'Не указан'}</span>
                </div>
              </div>

              <div className="pt-2 border-t border-slate-800/80 flex items-center justify-between text-[11px] text-slate-400">
                <span>Истории обслуживаний: <strong className="text-slate-200">{vehOrders.length}</strong></span>
                <span className="text-orange-400 font-semibold">Готов к записи</span>
              </div>
            </div>
          );
        })}
      </div>

      {/* Add Vehicle Modal */}
      {addModalOpen && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 border border-slate-800 rounded-2xl max-w-md w-full p-6 shadow-2xl text-slate-100">
            <h3 className="text-lg font-bold text-orange-400 mb-4">
              Новый Автомобиль Покупателя
            </h3>

            <div className="space-y-3 text-xs mb-5">
              <div>
                <label className="block text-slate-300 font-semibold mb-1">Покупатель *</label>
                <select
                  value={selectedCustId}
                  onChange={(e) => setSelectedCustId(e.target.value)}
                  className="w-full bg-slate-800 border border-slate-700 rounded-xl p-2.5 text-white"
                >
                  <option value="">-- Выберите владельца --</option>
                  {customers.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name} ({c.phone})
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-slate-300 font-semibold mb-1">Марка *</label>
                <input
                  type="text"
                  placeholder="Toyota, BMW, Audi..."
                  value={make}
                  onChange={(e) => setMake(e.target.value)}
                  className="w-full bg-slate-800 border border-slate-700 rounded-xl p-2.5 text-white"
                />
              </div>

              <div>
                <label className="block text-slate-300 font-semibold mb-1">Модель *</label>
                <input
                  type="text"
                  placeholder="Camry, X5, A6..."
                  value={model}
                  onChange={(e) => setModel(e.target.value)}
                  className="w-full bg-slate-800 border border-slate-700 rounded-xl p-2.5 text-white"
                />
              </div>

              <div>
                <label className="block text-slate-300 font-semibold mb-1">Госномер</label>
                <input
                  type="text"
                  placeholder="А777АА77"
                  value={plate}
                  onChange={(e) => setPlate(e.target.value)}
                  className="w-full bg-slate-800 border border-slate-700 rounded-xl p-2.5 text-white font-mono"
                />
              </div>

              <div>
                <label className="block text-slate-300 font-semibold mb-1">Размерность шин (Передние)</label>
                <input
                  type="text"
                  placeholder="225/55 R17"
                  value={frontSize}
                  onChange={(e) => setFrontSize(e.target.value)}
                  className="w-full bg-slate-800 border border-slate-700 rounded-xl p-2.5 text-white"
                />
              </div>
            </div>

            <div className="flex items-center justify-end gap-2.5">
              <button
                onClick={() => setAddModalOpen(false)}
                className="px-4 py-2 rounded-xl text-xs font-medium text-slate-300 hover:bg-slate-800"
              >
                Отмена
              </button>
              <button
                onClick={handleSaveVehicle}
                className="px-4 py-2 rounded-xl text-xs font-bold text-slate-950 bg-orange-400 hover:bg-orange-300 transition-colors"
              >
                Сохранить
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
