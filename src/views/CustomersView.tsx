import React, { useState } from 'react';
import { useApp } from '../context/AppContext';
import { Customer, CustomerType } from '../types';
import {
  Users,
  Search,
  FileSpreadsheet,
  GitMerge,
  Plus,
  Building2,
  User,
  Phone,
  Mail,
  Car,
  CheckCircle2,
  AlertTriangle
} from 'lucide-react';

export const CustomersView: React.FC = () => {
  const {
    customers,
    vehicles,
    orders,
    createCustomer,
    updateCustomer,
    mergeCustomers,
    importCustomersExcel
  } = useApp();

  const [activeTab, setActiveTab] = useState<'all' | 'physical' | 'legal'>('all');
  const [searchTerm, setSearchTerm] = useState('');

  // Add Customer Modal
  const [addModalOpen, setAddModalOpen] = useState(false);
  const [custType, setCustType] = useState<CustomerType>('physical');
  const [custName, setCustName] = useState('');
  const [custLegalName, setCustLegalName] = useState('');
  const [custInn, setCustInn] = useState('');
  const [custKpp, setCustKpp] = useState('');
  const [custContact, setCustContact] = useState('');
  const [custPhone, setCustPhone] = useState('');
  const [custEmail, setCustEmail] = useState('');
  const [custComment, setCustComment] = useState('');

  // Duplicate Merge Modal
  const [mergeModalOpen, setMergeModalOpen] = useState(false);
  const [primaryCustId, setPrimaryCustId] = useState('');
  const [secondaryCustId, setSecondaryCustId] = useState('');
  const [mergeReason, setMergeReason] = useState('');

  // Excel Import Modal
  const [excelModalOpen, setExcelModalOpen] = useState(false);
  const [excelRawText, setExcelRawText] = useState('');
  const [importResult, setImportResult] = useState<any>(null);

  const filteredCustomers = customers.filter((c) => {
    if (activeTab === 'physical' && c.type !== 'physical') return false;
    if (activeTab === 'legal' && c.type !== 'legal') return false;

    if (searchTerm) {
      const q = searchTerm.toLowerCase().trim();
      const matchName = c.name.toLowerCase().includes(q);
      const matchPhone = c.phone.includes(q);
      const matchInn = c.inn?.includes(q);
      if (!matchName && !matchPhone && !matchInn) return false;
    }
    return true;
  });

  const handleSaveNewCustomer = async () => {
    if (!custName || !custPhone) return;
    await createCustomer({
      type: custType,
      name: custName,
      legalName: custType === 'legal' ? custLegalName : undefined,
      inn: custType === 'legal' ? custInn : undefined,
      kpp: custType === 'legal' ? custKpp : undefined,
      contactPerson: custType === 'legal' ? custContact : undefined,
      phone: custPhone,
      email: custEmail,
      comment: custComment
    });
    setAddModalOpen(false);
    resetForm();
  };

  const resetForm = () => {
    setCustName('');
    setCustLegalName('');
    setCustInn('');
    setCustKpp('');
    setCustContact('');
    setCustPhone('');
    setCustEmail('');
    setCustComment('');
  };

  const handleExecuteMerge = async () => {
    if (!primaryCustId || !secondaryCustId || primaryCustId === secondaryCustId) {
      alert('Выберите два разных покупателя для объединения');
      return;
    }
    if (!mergeReason.trim()) {
      alert('Укажите причину объединения дубликатов');
      return;
    }

    await mergeCustomers(primaryCustId, secondaryCustId, mergeReason);
    setMergeModalOpen(false);
    setPrimaryCustId('');
    setSecondaryCustId('');
    setMergeReason('');
  };

  const handleSimulateExcelImport = async () => {
    // Parse sample CSV/Text table
    const lines = excelRawText.split('\n').filter((l) => l.trim().length > 0);
    const rows = lines.map((line) => {
      const parts = line.split(/[;,\t]/);
      return {
        name: parts[0] || '',
        phone: parts[1] || '',
        type: parts[2] === 'Юрлицо' ? 'legal' : 'physical',
        inn: parts[3] || undefined
      };
    });

    if (rows.length === 0) {
      alert('Введите хотя бы одну строку для импорта');
      return;
    }

    const res = await importCustomersExcel(rows);
    setImportResult(res);
  };

  return (
    <div className="space-y-5">
      {/* Header */}
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 bg-slate-900 p-5 rounded-2xl border border-slate-800">
        <div>
          <h1 className="text-lg font-bold text-white flex items-center gap-2">
            <Users className="w-5 h-5 text-orange-400" />
            База Покупателей (Физлица и Юрлица)
          </h1>
          <p className="text-xs text-slate-400 mt-0.5">
            Управление профилями клиентов, организациями, импортом из Excel и объединением дублей
          </p>
        </div>

        <div className="flex items-center gap-2 w-full sm:w-auto">
          <button
            onClick={() => setMergeModalOpen(true)}
            className="px-3 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-xl text-xs font-semibold border border-slate-700 flex items-center gap-1.5 transition-colors cursor-pointer"
          >
            <GitMerge className="w-4 h-4 text-amber-400" />
            Объединение дублей
          </button>

          <button
            onClick={() => setExcelModalOpen(true)}
            className="px-3 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-xl text-xs font-semibold border border-slate-700 flex items-center gap-1.5 transition-colors cursor-pointer"
          >
            <FileSpreadsheet className="w-4 h-4 text-emerald-400" />
            Импорт Excel
          </button>

          <button
            onClick={() => setAddModalOpen(true)}
            className="px-3.5 py-2 bg-orange-500 hover:bg-orange-400 text-slate-950 font-bold rounded-xl text-xs flex items-center gap-1.5 shadow-md shadow-orange-500/20 transition-all cursor-pointer"
          >
            <Plus className="w-4 h-4" />
            Добавить
          </button>
        </div>
      </div>

      {/* Tabs & Search */}
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 bg-slate-900 p-3 rounded-2xl border border-slate-800">
        <div className="flex items-center gap-1 bg-slate-950 p-1 rounded-xl border border-slate-800">
          <button
            onClick={() => setActiveTab('all')}
            className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-all ${
              activeTab === 'all' ? 'bg-orange-500 text-slate-950 shadow-sm' : 'text-slate-400 hover:text-slate-200'
            }`}
          >
            Все ({customers.length})
          </button>
          <button
            onClick={() => setActiveTab('physical')}
            className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-all ${
              activeTab === 'physical' ? 'bg-orange-500 text-slate-950 shadow-sm' : 'text-slate-400 hover:text-slate-200'
            }`}
          >
            Физлица ({customers.filter((c) => c.type === 'physical').length})
          </button>
          <button
            onClick={() => setActiveTab('legal')}
            className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-all ${
              activeTab === 'legal' ? 'bg-orange-500 text-slate-950 shadow-sm' : 'text-slate-400 hover:text-slate-200'
            }`}
          >
            Юрлица ({customers.filter((c) => c.type === 'legal').length})
          </button>
        </div>

        <div className="relative w-full sm:w-64">
          <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
          <input
            type="text"
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
            placeholder="Поиск по имени, тел, ИНН..."
            className="w-full bg-slate-800 border border-slate-700/80 rounded-xl pl-9 pr-3 py-1.5 text-xs text-slate-100 focus:outline-none focus:border-orange-500"
          />
        </div>
      </div>

      {/* Customer List Grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        {filteredCustomers.map((cust) => {
          const custVehicles = vehicles.filter((v) => v.customerId === cust.id);
          const custOrders = orders.filter((o) => o.customerId === cust.id);

          return (
            <div
              key={cust.id}
              className="bg-slate-900 border border-slate-800 hover:border-slate-700/80 rounded-2xl p-4 shadow-sm space-y-3 transition-all"
            >
              <div className="flex items-start justify-between">
                <div className="flex items-center gap-2">
                  <div
                    className={`w-8 h-8 rounded-xl flex items-center justify-center font-bold text-xs ${
                      cust.type === 'physical'
                        ? 'bg-blue-500/20 text-blue-300 border border-blue-500/30'
                        : 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30'
                    }`}
                  >
                    {cust.type === 'physical' ? <User className="w-4 h-4" /> : <Building2 className="w-4 h-4" />}
                  </div>
                  <div>
                    <h3 className="font-bold text-slate-100 text-xs leading-snug">{cust.name}</h3>
                    <span className="text-[10px] text-slate-400">
                      {cust.type === 'physical' ? 'Физическое лицо' : `ИНН ${cust.inn || 'не указан'}`}
                    </span>
                  </div>
                </div>

                {cust.isVip && (
                  <span className="text-[9px] font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30 px-1.5 py-0.5 rounded">
                    VIP {cust.discountPercent}%
                  </span>
                )}
              </div>

              <div className="space-y-1 text-xs text-slate-300">
                <div className="flex items-center gap-2 text-slate-300">
                  <Phone className="w-3.5 h-3.5 text-slate-500 shrink-0" />
                  <span>{cust.phone}</span>
                </div>
                {cust.email && (
                  <div className="flex items-center gap-2 text-slate-400">
                    <Mail className="w-3.5 h-3.5 text-slate-500 shrink-0" />
                    <span>{cust.email}</span>
                  </div>
                )}
              </div>

              {/* Connected Vehicles */}
              <div className="pt-2 border-t border-slate-800/80 flex items-center justify-between text-[11px] text-slate-400">
                <div className="flex items-center gap-1">
                  <Car className="w-3.5 h-3.5 text-orange-400" />
                  <span>Автомобилей: <strong className="text-slate-200">{custVehicles.length}</strong></span>
                </div>
                <div>
                  Заказов: <strong className="text-slate-200">{custOrders.length}</strong>
                </div>
              </div>
            </div>
          );
        })}
      </div>

      {/* Add Customer Modal */}
      {addModalOpen && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 border border-slate-800 rounded-2xl max-w-md w-full p-6 shadow-2xl text-slate-100">
            <h3 className="text-lg font-bold text-orange-400 mb-4">
              Создание Карточки Покупателя
            </h3>

            <div className="space-y-3 text-xs mb-5">
              <div className="flex items-center gap-3 mb-2">
                <label className="flex items-center gap-1.5 cursor-pointer">
                  <input
                    type="radio"
                    name="modalCustType"
                    checked={custType === 'physical'}
                    onChange={() => setCustType('physical')}
                  />
                  <span>Физическое лицо</span>
                </label>
                <label className="flex items-center gap-1.5 cursor-pointer">
                  <input
                    type="radio"
                    name="modalCustType"
                    checked={custType === 'legal'}
                    onChange={() => setCustType('legal')}
                  />
                  <span>Юридическое лицо</span>
                </label>
              </div>

              <div>
                <label className="block text-slate-300 font-semibold mb-1">
                  {custType === 'physical' ? 'ФИО Покупателя *' : 'Название организации *'}
                </label>
                <input
                  type="text"
                  value={custName}
                  onChange={(e) => setCustName(e.target.value)}
                  placeholder={custType === 'physical' ? 'Иванов Иван Иванович' : 'ООО "Ластик-Транс"'}
                  className="w-full bg-slate-800 border border-slate-700 rounded-xl p-2.5 text-white focus:outline-none"
                />
              </div>

              {custType === 'legal' && (
                <>
                  <div>
                    <label className="block text-slate-300 font-semibold mb-1">ИНН *</label>
                    <input
                      type="text"
                      value={custInn}
                      onChange={(e) => setCustInn(e.target.value)}
                      className="w-full bg-slate-800 border border-slate-700 rounded-xl p-2.5 text-white"
                    />
                  </div>
                  <div>
                    <label className="block text-slate-300 font-semibold mb-1">Контактное лицо</label>
                    <input
                      type="text"
                      value={custContact}
                      onChange={(e) => setCustContact(e.target.value)}
                      className="w-full bg-slate-800 border border-slate-700 rounded-xl p-2.5 text-white"
                    />
                  </div>
                </>
              )}

              <div>
                <label className="block text-slate-300 font-semibold mb-1">Телефон *</label>
                <input
                  type="text"
                  value={custPhone}
                  onChange={(e) => setCustPhone(e.target.value)}
                  placeholder="+7 (911) 000-00-00"
                  className="w-full bg-slate-800 border border-slate-700 rounded-xl p-2.5 text-white focus:outline-none"
                />
              </div>

              <div>
                <label className="block text-slate-300 font-semibold mb-1">Email</label>
                <input
                  type="email"
                  value={custEmail}
                  onChange={(e) => setCustEmail(e.target.value)}
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
                onClick={handleSaveNewCustomer}
                className="px-4 py-2 rounded-xl text-xs font-bold text-slate-950 bg-orange-400 hover:bg-orange-300 transition-colors"
              >
                Сохранить
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Duplicate Merge Modal */}
      {mergeModalOpen && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 border border-slate-800 rounded-2xl max-w-lg w-full p-6 shadow-2xl text-slate-100">
            <h3 className="text-lg font-bold text-amber-400 flex items-center gap-2 mb-2">
              <GitMerge className="w-5 h-5" />
              Объединение Дублей Покупателей
            </h3>
            <p className="text-xs text-slate-300 mb-4">
              Выберите основную карточку и второстепенную. Все заказы и привязанные автомобили будут перенесены в основную карточку.
            </p>

            <div className="space-y-3 text-xs mb-5">
              <div>
                <label className="block text-slate-300 font-semibold mb-1">Основная карточка (Остается в базе) *</label>
                <select
                  value={primaryCustId}
                  onChange={(e) => setPrimaryCustId(e.target.value)}
                  className="w-full bg-slate-800 border border-slate-700 rounded-xl p-2.5 text-white"
                >
                  <option value="">-- Выберите карточку --</option>
                  {customers.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name} ({c.phone})
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-slate-300 font-semibold mb-1">Второстепенная карточка (Будет объединена) *</label>
                <select
                  value={secondaryCustId}
                  onChange={(e) => setSecondaryCustId(e.target.value)}
                  className="w-full bg-slate-800 border border-slate-700 rounded-xl p-2.5 text-white"
                >
                  <option value="">-- Выберите дубликат --</option>
                  {customers.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name} ({c.phone})
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-slate-300 font-semibold mb-1">Причина объединения *</label>
                <textarea
                  value={mergeReason}
                  onChange={(e) => setMergeReason(e.target.value)}
                  placeholder="Причина объединения записей..."
                  className="w-full bg-slate-800 border border-slate-700 rounded-xl p-2.5 text-white h-20"
                />
              </div>
            </div>

            <div className="flex items-center justify-end gap-2.5">
              <button
                onClick={() => setMergeModalOpen(false)}
                className="px-4 py-2 rounded-xl text-xs font-medium text-slate-300 hover:bg-slate-800"
              >
                Отмена
              </button>
              <button
                onClick={handleExecuteMerge}
                className="px-4 py-2 rounded-xl text-xs font-bold text-slate-950 bg-amber-400 hover:bg-amber-300 transition-colors"
              >
                Выполнить объединение
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Excel Import Modal */}
      {excelModalOpen && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 border border-slate-800 rounded-2xl max-w-lg w-full p-6 shadow-2xl text-slate-100">
            <h3 className="text-lg font-bold text-emerald-400 flex items-center gap-2 mb-2">
              <FileSpreadsheet className="w-5 h-5" />
              Импорт Покупателей из Excel / CSV
            </h3>
            <p className="text-xs text-slate-300 mb-4">
              Вставьте строки из таблицы в формате: <br />
              <code className="text-amber-300 font-mono">ФИО / Название; Телефон; Тип (Физлицо/Юрлицо); ИНН</code>
            </p>

            <textarea
              value={excelRawText}
              onChange={(e) => setExcelRawText(e.target.value)}
              placeholder={`Соколов Дмитрий; +7 (911) 222-33-44; Физлицо;\nООО Альфа Спец; +7 (812) 555-00-11; Юрлицо; 7810112233`}
              className="w-full bg-slate-800 border border-slate-700 rounded-xl p-3 text-xs font-mono text-slate-100 h-32 mb-4"
            />

            {importResult && (
              <div className="p-3 bg-slate-800/90 border border-slate-700 rounded-xl text-xs mb-4 space-y-1">
                <div className="text-emerald-400 font-bold">
                  Импортировано: {importResult.imported}, Пропущено дублей: {importResult.skipped}
                </div>
                {importResult.errors && importResult.errors.length > 0 && (
                  <div className="text-[11px] text-amber-300 max-h-20 overflow-y-auto pt-1">
                    {importResult.errors.join('; ')}
                  </div>
                )}
              </div>
            )}

            <div className="flex items-center justify-end gap-2.5">
              <button
                onClick={() => {
                  setExcelModalOpen(false);
                  setImportResult(null);
                  setExcelRawText('');
                }}
                className="px-4 py-2 rounded-xl text-xs font-medium text-slate-300 hover:bg-slate-800"
              >
                Закрыть
              </button>
              <button
                onClick={handleSimulateExcelImport}
                className="px-4 py-2 rounded-xl text-xs font-bold text-slate-950 bg-emerald-400 hover:bg-emerald-300 transition-colors"
              >
                Запустить Импорт
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
