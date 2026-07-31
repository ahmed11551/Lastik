import React, { useState } from 'react';
import { useApp } from '../context/AppContext';
import { OrderScenario, Customer, Vehicle, OrderItemSnapshot } from '../types';
import {
  PlusCircle,
  User,
  Car,
  Boxes,
  Wrench,
  Check,
  AlertCircle,
  ShoppingBag,
  Trash2,
  Sparkles
} from 'lucide-react';

interface NewOrderWizardProps {
  onComplete: () => void;
}

export const NewOrderWizard: React.FC<NewOrderWizardProps> = ({ onComplete }) => {
  const {
    customers,
    vehicles,
    products,
    services,
    warehouses,
    stock,
    users,
    createOrder,
    createCustomer,
    createVehicle,
    activeUser
  } = useApp();

  const [scenario, setScenario] = useState<OrderScenario>('with_installation');

  // Customer selection/creation state
  const [selectedCustomerId, setSelectedCustomerId] = useState<string>('');
  const [showAddCustomer, setShowAddCustomer] = useState(false);
  const [newCustName, setNewCustName] = useState('');
  const [newCustPhone, setNewCustPhone] = useState('');
  const [newCustType, setNewCustType] = useState<'physical' | 'legal'>('physical');

  // Vehicle selection/creation state
  const [selectedVehicleId, setSelectedVehicleId] = useState<string>('');
  const [showAddVehicle, setShowAddVehicle] = useState(false);
  const [newVehMake, setNewVehMake] = useState('');
  const [newVehModel, setNewVehModel] = useState('');
  const [newVehPlate, setNewVehPlate] = useState('');

  // Selected Order Items
  const [selectedItems, setSelectedItems] = useState<OrderItemSnapshot[]>([]);

  // Employee Assignments
  const [responsibleSellerId, setResponsibleSellerId] = useState<string>(activeUser?.id || '');
  const [masterExecutorId, setMasterExecutorId] = useState<string>('');

  // Comments
  const [comment, setComment] = useState('');
  const [importantComment, setImportantComment] = useState('');

  const selectedCustomer = customers.find((c) => c.id === selectedCustomerId);
  const selectedVehicle = vehicles.find((v) => v.id === selectedVehicleId);

  const customerVehicles = vehicles.filter((v) => v.customerId === selectedCustomerId);

  // Add Product Item
  const handleAddProduct = (prodId: string, whId: string) => {
    const prod = products.find((p) => p.id === prodId);
    if (!prod) return;

    const st = stock.find((s) => s.productId === prodId && s.warehouseId === whId);
    const avail = st ? st.availableQty : 0;

    if (avail <= 0) {
      alert(`Внимание! Товар "${prod.name}" отсутствует в доступном остатке на выбранном складе.`);
    }

    const newItem: OrderItemSnapshot = {
      id: `item-${Date.now()}-${Math.floor(Math.random() * 1000)}`,
      itemType: 'product',
      productId: prod.id,
      name: prod.name,
      brand: prod.brand,
      model: prod.model,
      sku: prod.sku,
      warehouseId: whId,
      qty: 4,
      price: prod.priceRetail,
      discount: 0,
      totalSum: prod.priceRetail * 4,
      status: 'reserved',
      kpiRulePercent: 3,
      kpiAmount: Math.round(prod.priceRetail * 4 * 0.03),
      addedAt: new Date().toISOString()
    };

    setSelectedItems([...selectedItems, newItem]);
  };

  // Add Service Item
  const handleAddService = (servId: string) => {
    const serv = services.find((s) => s.id === servId);
    if (!serv) return;

    const newItem: OrderItemSnapshot = {
      id: `item-${Date.now()}-${Math.floor(Math.random() * 1000)}`,
      itemType: 'service',
      serviceId: serv.id,
      name: serv.name,
      qty: 1,
      price: serv.price,
      discount: 0,
      totalSum: serv.price,
      status: 'added',
      masterExecutorId: masterExecutorId,
      kpiRulePercent: serv.kpiMasterPercent,
      kpiAmount: Math.round(serv.price * (serv.kpiMasterPercent / 100)),
      addedAt: new Date().toISOString()
    };

    setSelectedItems([...selectedItems, newItem]);
  };

  const handleRemoveItem = (id: string) => {
    setSelectedItems(selectedItems.filter((i) => i.id !== id));
  };

  const totalSum = selectedItems.reduce((acc, i) => acc + i.totalSum, 0);

  // Quick Customer Creation
  const handleCreateNewCustomer = async () => {
    if (!newCustName || !newCustPhone) return;
    const created = await createCustomer({
      name: newCustName,
      phone: newCustPhone,
      type: newCustType
    });
    setSelectedCustomerId(created.id);
    setShowAddCustomer(false);
    setNewCustName('');
    setNewCustPhone('');
  };

  // Quick Vehicle Creation
  const handleCreateNewVehicle = async () => {
    if (!selectedCustomerId || !newVehMake || !newVehModel) return;
    const created = await createVehicle({
      customerId: selectedCustomerId,
      make: newVehMake,
      model: newVehModel,
      licensePlate: newVehPlate
    });
    setSelectedVehicleId(created.id);
    setShowAddVehicle(false);
    setNewVehMake('');
    setNewVehModel('');
    setNewVehPlate('');
  };

  const handleSaveOrder = async () => {
    if (!selectedCustomerId) {
      alert('Пожалуйста, выберите покупателя!');
      return;
    }
    if (selectedItems.length === 0) {
      alert('Заказ должен содержать хотя бы одну позицию!');
      return;
    }

    const seller = users.find((u) => u.id === responsibleSellerId) || activeUser;
    const master = users.find((u) => u.id === masterExecutorId);

    await createOrder({
      scenario,
      customerId: selectedCustomerId,
      customerType: selectedCustomer?.type || 'physical',
      customerName: selectedCustomer?.name || '',
      customerPhone: selectedCustomer?.phone || '',
      vehicleId: selectedVehicleId || undefined,
      vehicleInfo: selectedVehicle ? `${selectedVehicle.make} ${selectedVehicle.model} (${selectedVehicle.licensePlate || 'без номера'})` : undefined,
      status: 'in_progress',
      paymentStatus: 'unpaid',
      items: selectedItems,
      totalAmount: totalSum,
      paidAmount: 0,
      dueAmount: totalSum,
      responsibleSellerId: seller?.id || '',
      responsibleSellerName: seller?.name || '',
      masterExecutorId: master?.id,
      masterExecutorName: master?.name,
      comment,
      importantComment
    });

    onComplete();
  };

  return (
    <div className="max-w-5xl mx-auto space-y-6">
      {/* Title & Scenario Selector */}
      <div className="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-sm">
        <h1 className="text-xl font-bold text-white flex items-center gap-2 mb-2">
          <PlusCircle className="w-6 h-6 text-orange-400" />
          Оформление Нового Заказа Покупателя
        </h1>
        <p className="text-xs text-slate-400 mb-5">
          Выберите бизнес-сценарий работы. Автоматическое резервирование остатков и расчёт KPI выработки.
        </p>

        {/* Scenario Toggle */}
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <button
            type="button"
            onClick={() => setScenario('with_installation')}
            className={`p-4 rounded-xl border text-left transition-all cursor-pointer ${
              scenario === 'with_installation'
                ? 'bg-orange-500/15 border-orange-500 text-white shadow-md'
                : 'bg-slate-800/60 border-slate-700 text-slate-400 hover:bg-slate-800'
            }`}
          >
            <div className="flex items-center justify-between mb-1">
              <span className="font-bold text-sm">Продажа с Установкой</span>
              <Wrench className={`w-5 h-5 ${scenario === 'with_installation' ? 'text-orange-400' : 'text-slate-500'}`} />
            </div>
            <p className="text-xs text-slate-400">
              Покупка шин/дисков + шиномонтаж в сервисной зоне. Привязка к автомобилю и назначение мастера.
            </p>
          </button>

          <button
            type="button"
            onClick={() => setScenario('without_installation')}
            className={`p-4 rounded-xl border text-left transition-all cursor-pointer ${
              scenario === 'without_installation'
                ? 'bg-emerald-500/15 border-emerald-500 text-white shadow-md'
                : 'bg-slate-800/60 border-slate-700 text-slate-400 hover:bg-slate-800'
            }`}
          >
            <div className="flex items-center justify-between mb-1">
              <span className="font-bold text-sm">Продажа без Установки</span>
              <ShoppingBag className={`w-5 h-5 ${scenario === 'without_installation' ? 'text-emerald-400' : 'text-slate-500'}`} />
            </div>
            <p className="text-xs text-slate-400">
              Прямая покупка товара из магазина / склада без сервисных работ. Автомобиль не требуется.
            </p>
          </button>
        </div>
      </div>

      {/* Customer & Vehicle Selection */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        
        {/* Customer Box */}
        <div className="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-sm space-y-4">
          <div className="flex items-center justify-between">
            <h2 className="text-sm font-bold text-white flex items-center gap-2">
              <User className="w-4 h-4 text-orange-400" />
              1. Выбор Покупателя
            </h2>
            <button
              onClick={() => setShowAddCustomer(!showAddCustomer)}
              className="text-xs text-orange-400 hover:underline font-semibold"
            >
              + Новый клиент
            </button>
          </div>

          {!showAddCustomer ? (
            <select
              value={selectedCustomerId}
              onChange={(e) => {
                setSelectedCustomerId(e.target.value);
                setSelectedVehicleId('');
              }}
              className="w-full bg-slate-800 border border-slate-700 text-slate-100 text-xs rounded-xl p-3 focus:outline-none focus:border-orange-500"
            >
              <option value="">-- Выберите покупателя --</option>
              {customers.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name} ({c.phone}) [{c.type === 'physical' ? 'Физлицо' : 'Юрлицо'}]
                </option>
              ))}
            </select>
          ) : (
            <div className="p-3.5 bg-slate-800/80 rounded-xl border border-slate-700 space-y-3 text-xs">
              <div className="font-bold text-slate-200">Быстрое создание клиента</div>
              <input
                type="text"
                placeholder="ФИО или Название организации"
                value={newCustName}
                onChange={(e) => setNewCustName(e.target.value)}
                className="w-full bg-slate-900 border border-slate-700 rounded-lg p-2 text-white"
              />
              <input
                type="text"
                placeholder="Телефон (+7...)"
                value={newCustPhone}
                onChange={(e) => setNewCustPhone(e.target.value)}
                className="w-full bg-slate-900 border border-slate-700 rounded-lg p-2 text-white"
              />
              <div className="flex items-center gap-3">
                <label className="flex items-center gap-1.5 text-slate-300">
                  <input
                    type="radio"
                    name="custType"
                    checked={newCustType === 'physical'}
                    onChange={() => setNewCustType('physical')}
                  />
                  Физлицо
                </label>
                <label className="flex items-center gap-1.5 text-slate-300">
                  <input
                    type="radio"
                    name="custType"
                    checked={newCustType === 'legal'}
                    onChange={() => setNewCustType('legal')}
                  />
                  Юрлицо
                </label>
              </div>
              <button
                type="button"
                onClick={handleCreateNewCustomer}
                className="w-full bg-orange-500 hover:bg-orange-400 text-slate-950 font-bold py-1.5 rounded-lg text-xs"
              >
                Сохранить и выбрать
              </button>
            </div>
          )}

          {selectedCustomer && (
            <div className="p-3 bg-slate-800/50 rounded-xl border border-slate-700/60 text-xs space-y-1">
              <div className="font-bold text-slate-100">{selectedCustomer.name}</div>
              <div className="text-slate-400">Тел: {selectedCustomer.phone}</div>
              {selectedCustomer.inn && (
                <div className="text-slate-400">ИНН: {selectedCustomer.inn}</div>
              )}
            </div>
          )}
        </div>

        {/* Vehicle Box */}
        <div className="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-sm space-y-4">
          <div className="flex items-center justify-between">
            <h2 className="text-sm font-bold text-white flex items-center gap-2">
              <Car className="w-4 h-4 text-orange-400" />
              2. Автомобиль {scenario === 'without_installation' && '(Необязательно)'}
            </h2>
            {selectedCustomerId && (
              <button
                onClick={() => setShowAddVehicle(!showAddVehicle)}
                className="text-xs text-orange-400 hover:underline font-semibold"
              >
                + Добавить авто
              </button>
            )}
          </div>

          {!showAddVehicle ? (
            <select
              value={selectedVehicleId}
              onChange={(e) => setSelectedVehicleId(e.target.value)}
              disabled={!selectedCustomerId}
              className="w-full bg-slate-800 border border-slate-700 text-slate-100 text-xs rounded-xl p-3 focus:outline-none focus:border-orange-500 disabled:opacity-50"
            >
              <option value="">-- Выберите автомобиль --</option>
              {customerVehicles.map((v) => (
                <option key={v.id} value={v.id}>
                  {v.make} {v.model} ({v.licensePlate || 'без номера'})
                </option>
              ))}
            </select>
          ) : (
            <div className="p-3.5 bg-slate-800/80 rounded-xl border border-slate-700 space-y-3 text-xs">
              <div className="font-bold text-slate-200">Добавление автомобиля</div>
              <input
                type="text"
                placeholder="Марка (напр. Toyota)"
                value={newVehMake}
                onChange={(e) => setNewVehMake(e.target.value)}
                className="w-full bg-slate-900 border border-slate-700 rounded-lg p-2 text-white"
              />
              <input
                type="text"
                placeholder="Модель (напр. Camry)"
                value={newVehModel}
                onChange={(e) => setNewVehModel(e.target.value)}
                className="w-full bg-slate-900 border border-slate-700 rounded-lg p-2 text-white"
              />
              <input
                type="text"
                placeholder="Госномер (напр. А777АА77)"
                value={newVehPlate}
                onChange={(e) => setNewVehPlate(e.target.value)}
                className="w-full bg-slate-900 border border-slate-700 rounded-lg p-2 text-white"
              />
              <button
                type="button"
                onClick={handleCreateNewVehicle}
                className="w-full bg-orange-500 hover:bg-orange-400 text-slate-950 font-bold py-1.5 rounded-lg text-xs"
              >
                Привязать авто
              </button>
            </div>
          )}

          {selectedVehicle && (
            <div className="p-3 bg-slate-800/50 rounded-xl border border-slate-700/60 text-xs space-y-1">
              <div className="font-bold text-orange-300">
                {selectedVehicle.make} {selectedVehicle.model} ({selectedVehicle.licensePlate})
              </div>
              <div className="text-slate-400">
                Размер шин: {selectedVehicle.tireSizeFront || 'Не указан'}
              </div>
            </div>
          )}
        </div>
      </div>

      {/* Product & Service Selector */}
      <div className="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-sm space-y-4">
        <h2 className="text-sm font-bold text-white flex items-center gap-2">
          <Boxes className="w-4 h-4 text-orange-400" />
          3. Добавление Товаров со Склада и Сервисных Услуг
        </h2>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {/* Product Picker */}
          <div className="space-y-2">
            <label className="block text-xs font-semibold text-slate-300">Шины / Диски на складе</label>
            <div className="max-h-56 overflow-y-auto space-y-2 pr-1">
              {products.map((prod) => {
                const wh = warehouses[0];
                const st = stock.find((s) => s.productId === prod.id && s.warehouseId === wh?.id);
                const avail = st ? st.availableQty : 0;

                return (
                  <div
                    key={prod.id}
                    className="p-2.5 bg-slate-800/80 hover:bg-slate-800 rounded-xl border border-slate-700/80 flex items-center justify-between text-xs"
                  >
                    <div>
                      <div className="font-bold text-slate-100">{prod.name}</div>
                      <div className="text-[10px] text-slate-400">
                        Цена: <strong className="text-white">{prod.priceRetail.toLocaleString('ru-RU')} ₽</strong> • Склад: {avail} шт дост.
                      </div>
                    </div>
                    <button
                      type="button"
                      onClick={() => handleAddProduct(prod.id, wh?.id || 'wh-1')}
                      className="px-2.5 py-1 bg-orange-500 hover:bg-orange-400 text-slate-950 font-bold rounded-lg text-xs transition-colors shrink-0 cursor-pointer"
                    >
                      + 4 шт
                    </button>
                  </div>
                );
              })}
            </div>
          </div>

          {/* Service Picker */}
          {scenario === 'with_installation' && (
            <div className="space-y-2">
              <label className="block text-xs font-semibold text-slate-300">Услуги Шиномонтажа</label>
              <div className="max-h-56 overflow-y-auto space-y-2 pr-1">
                {services.map((serv) => (
                  <div
                    key={serv.id}
                    className="p-2.5 bg-slate-800/80 hover:bg-slate-800 rounded-xl border border-slate-700/80 flex items-center justify-between text-xs"
                  >
                    <div>
                      <div className="font-bold text-slate-100">{serv.name}</div>
                      <div className="text-[10px] text-slate-400">
                        Цена: <strong className="text-white">{serv.price.toLocaleString('ru-RU')} ₽</strong> • KPI мастера: {serv.kpiMasterPercent}%
                      </div>
                    </div>
                    <button
                      type="button"
                      onClick={() => handleAddService(serv.id)}
                      className="px-2.5 py-1 bg-blue-500 hover:bg-blue-400 text-white font-bold rounded-lg text-xs transition-colors shrink-0 cursor-pointer"
                    >
                      + Добавить
                    </button>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>
      </div>

      {/* Selected Items & Summary */}
      <div className="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-sm space-y-4">
        <h2 className="text-sm font-bold text-white flex items-center gap-2">
          <ShoppingBag className="w-4 h-4 text-orange-400" />
          Состав Заказа ({selectedItems.length} поз.)
        </h2>

        {selectedItems.length > 0 ? (
          <div className="space-y-2">
            {selectedItems.map((item) => (
              <div
                key={item.id}
                className="p-3 bg-slate-800/90 rounded-xl border border-slate-700 flex items-center justify-between text-xs"
              >
                <div>
                  <div className="font-bold text-slate-100">{item.name}</div>
                  <div className="text-[11px] text-slate-400 mt-0.5">
                    {item.qty} × {item.price.toLocaleString('ru-RU')} ₽ = <strong className="text-white">{item.totalSum.toLocaleString('ru-RU')} ₽</strong> (KPI: {item.kpiAmount} ₽)
                  </div>
                </div>

                <button
                  type="button"
                  onClick={() => handleRemoveItem(item.id)}
                  className="p-1.5 text-slate-400 hover:text-red-400 transition-colors"
                >
                  <Trash2 className="w-4 h-4" />
                </button>
              </div>
            ))}

            <div className="p-4 bg-slate-800 rounded-xl flex items-center justify-between text-sm font-bold text-white mt-4 border border-slate-700">
              <span>Итоговая сумма заказа:</span>
              <span className="text-orange-400 text-lg">{totalSum.toLocaleString('ru-RU')} ₽</span>
            </div>
          </div>
        ) : (
          <div className="p-8 text-center text-xs text-slate-500 bg-slate-800/40 rounded-xl border border-dashed border-slate-800">
            Позиции не выбраны. Добавьте товары или услуги из блоков выше.
          </div>
        )}
      </div>

      {/* Assignments & Comments */}
      <div className="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-sm grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
        <div>
          <label className="block font-semibold text-slate-300 mb-1">Ответственный Продавец</label>
          <select
            value={responsibleSellerId}
            onChange={(e) => setResponsibleSellerId(e.target.value)}
            className="w-full bg-slate-800 border border-slate-700 rounded-xl p-2.5 text-white focus:outline-none"
          >
            {users.map((u) => (
              <option key={u.id} value={u.id}>
                {u.name} ({u.roleName})
              </option>
            ))}
          </select>
        </div>

        {scenario === 'with_installation' && (
          <div>
            <label className="block font-semibold text-slate-300 mb-1">Мастер-Шиномонтажник</label>
            <select
              value={masterExecutorId}
              onChange={(e) => setMasterExecutorId(e.target.value)}
              className="w-full bg-slate-800 border border-slate-700 rounded-xl p-2.5 text-white focus:outline-none"
            >
              <option value="">-- Выберите мастера --</option>
              {users
                .filter((u) => u.role === 'master' || u.role === 'superadmin' || u.role === 'admin')
                .map((u) => (
                  <option key={u.id} value={u.id}>
                    {u.name} ({u.roleName})
                  </option>
                ))}
            </select>
          </div>
        )}

        <div className="md:col-span-2">
          <label className="block font-semibold text-slate-300 mb-1">Общий комментарий к заказу</label>
          <input
            type="text"
            value={comment}
            onChange={(e) => setComment(e.target.value)}
            placeholder="Особые пожелания клиента..."
            className="w-full bg-slate-800 border border-slate-700 rounded-xl p-2.5 text-white focus:outline-none"
          />
        </div>

        <div className="md:col-span-2">
          <label className="block font-semibold text-amber-300 mb-1">ВАЖНОЕ ПРИМЕЧАНИЕ (Подсветка для сотрудников)</label>
          <input
            type="text"
            value={importantComment}
            onChange={(e) => setImportantComment(e.target.value)}
            placeholder="Например: Затяжка динамометрическим ключом 110 Нм..."
            className="w-full bg-slate-800 border border-amber-500/50 rounded-xl p-2.5 text-white focus:outline-none"
          />
        </div>
      </div>

      {/* Save Button */}
      <div className="flex justify-end gap-3 pt-2">
        <button
          type="button"
          onClick={onComplete}
          className="px-5 py-2.5 rounded-xl text-xs font-semibold text-slate-300 hover:bg-slate-800"
        >
          Отмена
        </button>
        <button
          type="button"
          onClick={handleSaveOrder}
          disabled={!selectedCustomerId || selectedItems.length === 0}
          className="px-6 py-2.5 rounded-xl text-xs font-bold text-slate-950 bg-gradient-to-r from-orange-500 to-amber-500 hover:from-orange-400 hover:to-amber-400 disabled:opacity-50 transition-all shadow-lg shadow-orange-500/20 cursor-pointer"
        >
          Сохранить и зарезервировать заказ
        </button>
      </div>
    </div>
  );
};
