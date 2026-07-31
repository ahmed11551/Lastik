import fs from 'fs';
import path from 'path';
import {
  PlatformTenant,
  TenantLocation,
  MoneyRecipient,
  User,
  RoleDefinition,
  Customer,
  Vehicle,
  Product,
  Service,
  Warehouse,
  StockItem,
  Order,
  PaymentRecord,
  CorrectionRecord,
  CashShift,
  KPIRecord,
  AuditLogEntry,
  Task,
  SystemModule,
  SystemSettings
} from '../src/types.js';

const DATA_DIR = path.join(process.cwd(), 'data');
const DB_FILE = path.join(DATA_DIR, 'db.json');

export interface DatabaseSchema {
  tenants: PlatformTenant[];
  locations: TenantLocation[];
  recipients: MoneyRecipient[];
  users: User[];
  roles: RoleDefinition[];
  customers: Customer[];
  vehicles: Vehicle[];
  products: Product[];
  services: Service[];
  warehouses: Warehouse[];
  stock: StockItem[];
  orders: Order[];
  payments: PaymentRecord[];
  corrections: CorrectionRecord[];
  shifts: CashShift[];
  kpiRecords: KPIRecord[];
  auditLogs: AuditLogEntry[];
  tasks: Task[];
  modules: SystemModule[];
  settings: SystemSettings;
}

const initialSeed: DatabaseSchema = {
  tenants: [
    {
      id: 'tenant-1',
      name: 'LASTIK Северо-Запад',
      legalName: 'ООО "Ластик Северо-Запад Групп"',
      inn: '7810987654',
      address: 'г. Санкт-Петербург, Московский пр., д. 140',
      phone: '+7 (812) 555-01-99',
      email: 'spb@lastik-tire.ru',
      active: true,
      rentalStartDate: '2026-01-01',
      rentalEndDate: '2027-01-01',
      rentalStatus: 'active',
      userLimit: 15,
      adminLimit: 3,
      activeModules: ['mod-storage', 'mod-tv', 'mod-1c'],
      supportAccessEnabled: false
    },
    {
      id: 'tenant-2',
      name: 'Шинный Центр "Гранд"',
      legalName: 'ИП Иванов Сергей Васильевич',
      inn: '7723456789',
      address: 'г. Москва, Дмитровское шоссе, д. 85',
      phone: '+7 (495) 777-12-34',
      email: 'grand@tire-center.ru',
      active: true,
      rentalStartDate: '2026-03-01',
      rentalEndDate: '2026-11-01',
      rentalStatus: 'active',
      userLimit: 8,
      adminLimit: 2,
      activeModules: ['mod-tv'],
      supportAccessEnabled: false
    }
  ],
  locations: [
    {
      id: 'loc-1',
      tenantId: 'tenant-1',
      name: 'Шинный Центр - Центральный (Магазин + Сервис)',
      address: 'г. Санкт-Петербург, ул. Салова, д. 52',
      type: 'store_service',
      timezone: 'Europe/Moscow',
      active: true,
      phone: '+7 (812) 555-01-01'
    },
    {
      id: 'loc-2',
      tenantId: 'tenant-1',
      name: 'Сервис-Пост Южный',
      address: 'г. Санкт-Петербург, Кубинская ул., д. 78',
      type: 'service_only',
      timezone: 'Europe/Moscow',
      active: true,
      phone: '+7 (812) 555-01-02'
    },
    {
      id: 'loc-3',
      tenantId: 'tenant-1',
      name: 'Центральный Склад Приморский',
      address: 'г. Санкт-Петербург, ул. Оптиков, д. 4',
      type: 'warehouse',
      timezone: 'Europe/Moscow',
      active: true,
      phone: '+7 (812) 555-01-03'
    }
  ],
  recipients: [
    {
      id: 'rec-1',
      tenantId: 'tenant-1',
      name: 'Основная Касса Наличные (Центральный)',
      type: 'cashbox',
      details: 'Касса магазина ул. Салова 52',
      active: true
    },
    {
      id: 'rec-2',
      tenantId: 'tenant-1',
      name: 'Карта Сбер (ИП Иванов С.В.)',
      type: 'card_fio',
      details: 'Карта Сбербанк *4412 (Иванов С.В.)',
      active: true
    },
    {
      id: 'rec-3',
      tenantId: 'tenant-1',
      name: 'Расчётный счет ООО "Ластик Групп" (Альфа-Банк)',
      type: 'account_ooo',
      details: 'р/с 40702810901234567890 в АО "Альфа-Банк", БИК 044525593',
      active: true
    },
    {
      id: 'rec-4',
      tenantId: 'tenant-1',
      name: 'Расчётный счет ИП Иванов (Т-Банк)',
      type: 'account_ip',
      details: 'р/с 40802810800001234567 в АО "Т-Банк", БИК 044525974',
      active: true
    }
  ],
  users: [
    {
      id: 'usr-owner',
      name: 'Александр Громов (Владелец Platform)',
      email: 'owner@lastik.ru',
      phone: '+7 (999) 000-00-01',
      role: 'platform_owner',
      roleName: 'Владелец Платформы LASTIK',
      tenantId: 'tenant-1',
      locationIds: ['loc-1', 'loc-2', 'loc-3'],
      active: true,
      createdAt: '2026-01-01T00:00:00Z',
      lastLoginAt: new Date().toISOString(),
      devices: [
        {
          id: 'dev-1',
          deviceName: 'MacBook Pro 16 (Chrome)',
          deviceType: 'desktop',
          ipAddress: '192.168.1.10',
          lastActive: new Date().toISOString(),
          isCurrent: true
        }
      ]
    },
    {
      id: 'usr-superadmin',
      name: 'Алексей Сергеев (Суперадмин)',
      email: 'admin@lastik.ru',
      phone: '+7 (911) 123-45-67',
      role: 'superadmin',
      roleName: 'Суперадмин Организации',
      tenantId: 'tenant-1',
      locationIds: ['loc-1', 'loc-2', 'loc-3'],
      active: true,
      createdAt: '2026-01-05T10:00:00Z',
      lastLoginAt: new Date().toISOString(),
      devices: [
        {
          id: 'dev-2',
          deviceName: 'Dell XPS 15 (Edge)',
          deviceType: 'desktop',
          ipAddress: '192.168.1.15',
          lastActive: new Date().toISOString(),
          isCurrent: true
        }
      ]
    },
    {
      id: 'usr-manager',
      name: 'Дмитрий Волков (Продавец-менеджер)',
      email: 'volkov@lastik.ru',
      phone: '+7 (921) 987-65-43',
      role: 'manager',
      roleName: 'Продавец / Менеджер',
      tenantId: 'tenant-1',
      locationIds: ['loc-1'],
      active: true,
      createdAt: '2026-01-10T11:00:00Z',
      lastLoginAt: new Date().toISOString(),
      devices: [
        {
          id: 'dev-3',
          deviceName: 'iPad Air 5 (Safari)',
          deviceType: 'tablet',
          ipAddress: '192.168.1.22',
          lastActive: new Date().toISOString(),
          isCurrent: true
        }
      ]
    },
    {
      id: 'usr-cashier',
      name: 'Елена Кузнецова (Кассир)',
      email: 'cashier@lastik.ru',
      phone: '+7 (911) 555-44-33',
      role: 'cashier',
      roleName: 'Старший Кассир',
      tenantId: 'tenant-1',
      locationIds: ['loc-1', 'loc-2'],
      active: true,
      createdAt: '2026-01-12T09:00:00Z',
      lastLoginAt: new Date().toISOString(),
      devices: [
        {
          id: 'dev-4',
          deviceName: 'POS Workstation Terminal',
          deviceType: 'desktop',
          ipAddress: '192.168.1.100',
          lastActive: new Date().toISOString(),
          isCurrent: true
        }
      ]
    },
    {
      id: 'usr-master',
      name: 'Михаил Мастеровой (Старший Шиномонтажник)',
      email: 'master@lastik.ru',
      phone: '+7 (931) 333-22-11',
      role: 'master',
      roleName: 'Мастер-шиномонтажник',
      tenantId: 'tenant-1',
      locationIds: ['loc-1', 'loc-2'],
      active: true,
      createdAt: '2026-01-15T08:30:00Z',
      lastLoginAt: new Date().toISOString(),
      devices: [
        {
          id: 'dev-5',
          deviceName: 'Samsung Galaxy Tab (Android)',
          deviceType: 'mobile',
          ipAddress: '192.168.1.45',
          lastActive: new Date().toISOString(),
          isCurrent: true
        }
      ]
    },
    {
      id: 'usr-accountant',
      name: 'Ольга Николаева (Главный Бухгалтер)',
      email: 'buh@lastik.ru',
      phone: '+7 (812) 555-01-90',
      role: 'accountant',
      roleName: 'Бухгалтер-аудитор',
      tenantId: 'tenant-1',
      locationIds: ['loc-1', 'loc-2', 'loc-3'],
      active: true,
      createdAt: '2026-01-08T12:00:00Z',
      lastLoginAt: new Date().toISOString(),
      devices: [
        {
          id: 'dev-6',
          deviceName: 'Lenovo ThinkPad',
          deviceType: 'desktop',
          ipAddress: '192.168.1.50',
          lastActive: new Date().toISOString(),
          isCurrent: true
        }
      ]
    }
  ],
  roles: [
    {
      id: 'role-owner',
      code: 'platform_owner',
      name: 'Владелец Платформы',
      description: 'Техническое и коммерческое управление всей платформой и арендаторами',
      permissions: {
        all: ['view', 'create', 'edit', 'delete', 'cancel', 'approve', 'manage_users', 'manage_settings', 'manage_modules']
      }
    },
    {
      id: 'role-superadmin',
      code: 'superadmin',
      name: 'Суперадмин Организации',
      description: 'Полный доступ ко всем настройкам, точкам, отчётам, ролям и кассе организации',
      permissions: {
        orders: ['view', 'create', 'edit', 'delete', 'cancel', 'approve', 'give_discount', 'change_price', 'sell_over_stock'],
        payments: ['view', 'accept_payment', 'edit_payment', 'override_payment_after_release'],
        shifts: ['view', 'open_shift', 'close_shift', 'view_cash'],
        stock: ['view', 'create', 'edit', 'delete', 'view_cost_price'],
        users: ['view', 'manage_users', 'manage_roles'],
        audit: ['view_audit'],
        settings: ['manage_settings', 'manage_modules']
      }
    },
    {
      id: 'role-manager',
      code: 'manager',
      name: 'Продавец / Менеджер',
      description: 'Оформление заказов, резервирование, выдача товаров, работа с покупателями',
      permissions: {
        orders: ['view', 'create', 'edit', 'cancel', 'give_discount'],
        stock: ['view'],
        customers: ['view', 'create', 'edit'],
        payments: ['view', 'accept_payment']
      }
    },
    {
      id: 'role-cashier',
      code: 'cashier',
      name: 'Кассир',
      description: 'Прием оплат, смешанные расчеты, управление сменой, инкассация, выемка',
      permissions: {
        orders: ['view'],
        payments: ['view', 'accept_payment'],
        shifts: ['view', 'open_shift', 'close_shift', 'view_cash']
      }
    },
    {
      id: 'role-master',
      code: 'master',
      name: 'Мастер / Исполнитель',
      description: 'Выполнение шиномонтажных работ, подтверждение готовности, просмотр личной выработки',
      permissions: {
        orders: ['view', 'edit'],
        stock: ['view']
      }
    },
    {
      id: 'role-accountant',
      code: 'accountant',
      name: 'Бухгалтер',
      description: 'Контроль кассовых смен, корректировки оплат, просмотр закупочных цен, аудит',
      permissions: {
        orders: ['view'],
        payments: ['view', 'edit_payment'],
        shifts: ['view', 'view_cash'],
        stock: ['view', 'view_cost_price'],
        audit: ['view_audit']
      }
    }
  ],
  customers: [
    {
      id: 'cust-1',
      tenantId: 'tenant-1',
      type: 'physical',
      name: 'Иванов Алексей Петрович',
      phone: '+7 (921) 111-22-33',
      email: 'ivanov.a@mail.ru',
      comment: 'Постоянный клиент, предпочитает шины Michelin',
      isVip: true,
      discountPercent: 5,
      preferredContactChannel: 'telegram',
      createdAt: '2026-02-01T10:00:00Z',
      updatedAt: '2026-02-01T10:00:00Z'
    },
    {
      id: 'cust-2',
      tenantId: 'tenant-1',
      type: 'physical',
      name: 'Петров Сергей Николаевич',
      phone: '+7 (911) 444-55-66',
      email: 'petrov.s@gmail.com',
      comment: 'Нужен чек по СБП',
      discountPercent: 0,
      preferredContactChannel: 'whatsapp',
      createdAt: '2026-02-10T14:20:00Z',
      updatedAt: '2026-02-10T14:20:00Z'
    },
    {
      id: 'cust-3',
      tenantId: 'tenant-1',
      type: 'legal',
      name: 'ООО "Логистика-Транс"',
      legalName: 'Общество с ограниченной ответственностью "Логистика-Транс"',
      inn: '7701234567',
      kpp: '770101001',
      contactPerson: 'Смирнов Константин (Начальник автопарка)',
      phone: '+7 (812) 320-10-20',
      email: 'fleet@logistics-trans.ru',
      comment: 'Договор №12/2026 от 15.01.2026, оплата по безналичному расчету',
      discountPercent: 10,
      preferredContactChannel: 'phone',
      createdAt: '2026-01-20T09:00:00Z',
      updatedAt: '2026-01-20T09:00:00Z'
    },
    {
      id: 'cust-4',
      tenantId: 'tenant-1',
      type: 'legal',
      name: 'ИП "Смирнов А.В."',
      legalName: 'Индивидуальный Предприниматель Смирнов Андрей Викторович',
      inn: '781298765432',
      contactPerson: 'Смирнов Андрей',
      phone: '+7 (905) 777-88-99',
      email: 'smirnov.ip@yandex.ru',
      comment: 'Закупка дисков оптом',
      discountPercent: 7,
      preferredContactChannel: 'telegram',
      createdAt: '2026-02-15T11:30:00Z',
      updatedAt: '2026-02-15T11:30:00Z'
    }
  ],
  vehicles: [
    {
      id: 'veh-1',
      customerId: 'cust-1',
      tenantId: 'tenant-1',
      make: 'Toyota',
      model: 'Camry',
      licensePlate: 'А777АА77',
      vin: 'XTA21102030405060',
      year: 2021,
      tireSizeFront: '225/55 R17',
      tireSizeRear: '225/55 R17',
      wheelBoltPattern: '5x114.3',
      comment: 'Шины 17 радиус, литые диски',
      createdAt: '2026-02-01T10:05:00Z'
    },
    {
      id: 'veh-2',
      customerId: 'cust-1',
      tenantId: 'tenant-1',
      make: 'BMW',
      model: 'X5',
      licensePlate: 'В123ВВ178',
      vin: 'WBAKS810203040506',
      year: 2023,
      tireSizeFront: '275/40 R20',
      tireSizeRear: '315/35 R20',
      wheelBoltPattern: '5x112',
      comment: 'Разноширокий комплект',
      createdAt: '2026-02-05T12:00:00Z'
    },
    {
      id: 'veh-3',
      customerId: 'cust-2',
      tenantId: 'tenant-1',
      make: 'Volkswagen',
      model: 'Tiguan',
      licensePlate: 'Е456КХ99',
      vin: 'XW8ZZZ5NZKG001122',
      year: 2020,
      tireSizeFront: '215/65 R17',
      tireSizeRear: '215/65 R17',
      comment: 'Шипованная резина',
      createdAt: '2026-02-10T14:25:00Z'
    },
    {
      id: 'veh-4',
      customerId: 'cust-3',
      tenantId: 'tenant-1',
      make: 'ГАЗель',
      model: 'NEXT',
      licensePlate: 'О987ОO178',
      vin: 'X96A21R2301020304',
      year: 2022,
      tireSizeFront: '185/75 R16C',
      tireSizeRear: '185/75 R16C',
      comment: 'Коммерческий транспорт, усиленный корд',
      createdAt: '2026-01-20T09:15:00Z'
    }
  ],
  products: [
    {
      id: 'prod-1',
      tenantId: 'tenant-1',
      externalId: '1C-PROD-001',
      sku: 'MIC-PS5-2255517',
      name: 'Michelin Pilot Sport 5 225/55 R17 101Y XL',
      brand: 'Michelin',
      model: 'Pilot Sport 5',
      type: 'tire',
      seasonality: 'summer',
      width: 225,
      profile: 55,
      radius: 17,
      priceRetail: 14500,
      priceWholesale: 12800,
      pricePurchase: 10500,
      unit: 'шт',
      active: true,
      imageUrl: 'https://images.unsplash.com/photo-1578844251758-2f71da64c96f?w=300&auto=format&fit=crop'
    },
    {
      id: 'prod-2',
      tenantId: 'tenant-1',
      externalId: '1C-PROD-002',
      sku: 'NOK-H10-2156517',
      name: 'Nokian Hakkapeliitta 10 SUV 215/65 R17 103T XL (шип)',
      brand: 'Nokian',
      model: 'Hakkapeliitta 10 SUV',
      type: 'tire',
      seasonality: 'winter_studded',
      width: 215,
      profile: 65,
      radius: 17,
      priceRetail: 13200,
      priceWholesale: 11500,
      pricePurchase: 9200,
      unit: 'шт',
      active: true,
      imageUrl: 'https://images.unsplash.com/photo-1580273916550-e323be2ae537?w=300&auto=format&fit=crop'
    },
    {
      id: 'prod-3',
      tenantId: 'tenant-1',
      externalId: '1C-PROD-003',
      sku: 'PIR-IZ2-2754020',
      name: 'Pirelli Ice Zero 2 275/40 R20 106T XL',
      brand: 'Pirelli',
      model: 'Ice Zero 2',
      type: 'tire',
      seasonality: 'winter_studded',
      width: 275,
      profile: 40,
      radius: 20,
      priceRetail: 22800,
      priceWholesale: 20100,
      pricePurchase: 16800,
      unit: 'шт',
      active: true,
      imageUrl: 'https://images.unsplash.com/photo-1578844251758-2f71da64c96f?w=300&auto=format&fit=crop'
    },
    {
      id: 'prod-4',
      tenantId: 'tenant-1',
      externalId: '1C-PROD-004',
      sku: 'BBS-CIR-1875',
      name: 'Диск литой BBS CI-R 7.5x18 5x114.3 ET45 d67.1 Satin Black',
      brand: 'BBS',
      model: 'CI-R',
      type: 'wheel',
      width: 18,
      radius: 18,
      boltPattern: '5x114.3',
      priceRetail: 31000,
      priceWholesale: 27500,
      pricePurchase: 22000,
      unit: 'шт',
      active: true,
      imageUrl: 'https://images.unsplash.com/photo-1611821064430-0d40291d0f0b?w=300&auto=format&fit=crop'
    },
    {
      id: 'prod-5',
      tenantId: 'tenant-1',
      externalId: '1C-PROD-005',
      sku: 'FAST-M12-15',
      name: 'Болт колесный M12x1.5x28 конус цинк',
      brand: 'Vector',
      model: 'M12x1.5',
      type: 'fastener',
      priceRetail: 150,
      priceWholesale: 100,
      pricePurchase: 65,
      unit: 'шт',
      active: true
    },
    {
      id: 'prod-6',
      tenantId: 'tenant-1',
      externalId: '1C-PROD-006',
      sku: 'COR-1857516',
      name: 'Cordiant Business CW 2 185/75 R16C 104/102Q (шип)',
      brand: 'Cordiant',
      model: 'Business CW 2',
      type: 'tire',
      seasonality: 'winter_studded',
      width: 185,
      profile: 75,
      radius: 16,
      priceRetail: 6800,
      priceWholesale: 5900,
      pricePurchase: 4800,
      unit: 'шт',
      active: true
    }
  ],
  services: [
    {
      id: 'serv-1',
      tenantId: 'tenant-1',
      name: 'Комплексный шиномонтаж 4х колес (R16 - R18)',
      category: 'Шиномонтаж',
      price: 3200,
      durationMinutes: 45,
      kpiMasterPercent: 30,
      active: true
    },
    {
      id: 'serv-2',
      tenantId: 'tenant-1',
      name: 'Комплексный шиномонтаж 4х колес (R19 - R22 XL)',
      category: 'Шиномонтаж',
      price: 4800,
      durationMinutes: 60,
      kpiMasterPercent: 35,
      active: true
    },
    {
      id: 'serv-3',
      tenantId: 'tenant-1',
      name: 'Балансировка 1 колеса',
      category: 'Балансировка',
      price: 450,
      durationMinutes: 15,
      kpiMasterPercent: 25,
      active: true
    },
    {
      id: 'serv-4',
      tenantId: 'tenant-1',
      name: 'Ремонт прокола грибком / жгутом',
      category: 'Ремонт',
      price: 850,
      durationMinutes: 20,
      kpiMasterPercent: 40,
      active: true
    },
    {
      id: 'serv-5',
      tenantId: 'tenant-1',
      name: 'Выпрямление литого диска R17-R19',
      category: 'Ремонт дисков',
      price: 2500,
      durationMinutes: 40,
      kpiMasterPercent: 35,
      active: true
    },
    {
      id: 'serv-6',
      tenantId: 'tenant-1',
      name: 'Сезонное хранение комплекта колес (6 месяцев)',
      category: 'Хранение',
      price: 4000,
      durationMinutes: 15,
      kpiMasterPercent: 15,
      active: true
    }
  ],
  warehouses: [
    {
      id: 'wh-1',
      tenantId: 'tenant-1',
      locationId: 'loc-1',
      name: 'Склад при магазине ул. Салова',
      code: 'WH-SALOVA',
      isDefault: true
    },
    {
      id: 'wh-2',
      tenantId: 'tenant-1',
      locationId: 'loc-3',
      name: 'Главный Склад Приморский',
      code: 'WH-PRIMORSKY',
      isDefault: false
    }
  ],
  stock: [
    {
      id: 'st-1',
      warehouseId: 'wh-1',
      productId: 'prod-1',
      actualQty: 12,
      reservedQty: 4,
      availableQty: 8,
      price: 14500,
      lastUpdated: new Date().toISOString(),
      lastSource: '1c_import'
    },
    {
      id: 'st-2',
      warehouseId: 'wh-1',
      productId: 'prod-2',
      actualQty: 16,
      reservedQty: 0,
      availableQty: 16,
      price: 13200,
      lastUpdated: new Date().toISOString(),
      lastSource: '1c_import'
    },
    {
      id: 'st-3',
      warehouseId: 'wh-1',
      productId: 'prod-3',
      actualQty: 8,
      reservedQty: 4,
      availableQty: 4,
      price: 22800,
      lastUpdated: new Date().toISOString(),
      lastSource: '1c_import'
    },
    {
      id: 'st-4',
      warehouseId: 'wh-1',
      productId: 'prod-4',
      actualQty: 8,
      reservedQty: 0,
      availableQty: 8,
      price: 31000,
      lastUpdated: new Date().toISOString(),
      lastSource: '1c_import'
    },
    {
      id: 'st-5',
      warehouseId: 'wh-1',
      productId: 'prod-5',
      actualQty: 200,
      reservedQty: 20,
      availableQty: 180,
      price: 150,
      lastUpdated: new Date().toISOString(),
      lastSource: 'manual'
    },
    {
      id: 'st-6',
      warehouseId: 'wh-2',
      productId: 'prod-1',
      actualQty: 24,
      reservedQty: 0,
      availableQty: 24,
      price: 14500,
      lastUpdated: new Date().toISOString(),
      lastSource: '1c_import'
    },
    {
      id: 'st-7',
      warehouseId: 'wh-1',
      productId: 'prod-6',
      actualQty: 12,
      reservedQty: 0,
      availableQty: 12,
      price: 6800,
      lastUpdated: new Date().toISOString(),
      lastSource: '1c_import'
    }
  ],
  orders: [
    {
      id: 'ord-1001',
      orderNumber: 'ЛСТ-2026-001',
      tenantId: 'tenant-1',
      locationId: 'loc-1',
      scenario: 'with_installation',
      customerId: 'cust-1',
      customerType: 'physical',
      customerName: 'Иванов Алексей Петрович',
      customerPhone: '+7 (921) 111-22-33',
      vehicleId: 'veh-1',
      vehicleInfo: 'Toyota Camry (А777АА77)',
      status: 'in_progress',
      paymentStatus: 'paid',
      items: [
        {
          id: 'item-1',
          itemType: 'product',
          productId: 'prod-1',
          name: 'Michelin Pilot Sport 5 225/55 R17 101Y XL',
          brand: 'Michelin',
          model: 'Pilot Sport 5',
          sku: 'MIC-PS5-2255517',
          warehouseId: 'wh-1',
          qty: 4,
          price: 14500,
          discount: 0,
          totalSum: 58000,
          status: 'reserved',
          responsibleSellerId: 'usr-manager',
          kpiRulePercent: 3,
          kpiAmount: 1740,
          addedAt: '2026-07-30T10:00:00Z'
        },
        {
          id: 'item-2',
          itemType: 'service',
          serviceId: 'serv-1',
          name: 'Комплексный шиномонтаж 4х колес (R16 - R18)',
          qty: 1,
          price: 3200,
          discount: 200,
          totalSum: 3000,
          status: 'added',
          masterExecutorId: 'usr-master',
          kpiRulePercent: 30,
          kpiAmount: 900,
          addedAt: '2026-07-30T10:05:00Z'
        }
      ],
      totalAmount: 61000,
      paidAmount: 61000,
      dueAmount: 0,
      responsibleSellerId: 'usr-manager',
      responsibleSellerName: 'Дмитрий Волков',
      masterExecutorId: 'usr-master',
      masterExecutorName: 'Михаил Мастеровой',
      shiftId: 'shift-101',
      comment: 'Клиент просит аккуратно с датчиками давления',
      importantComment: 'ВАЖНО: Затяжка динамометрическим ключом 110 Нм',
      importantCommentReadBy: [
        { userId: 'usr-master', readAt: '2026-07-30T10:15:00Z' }
      ],
      createdAt: '2026-07-30T10:00:00Z',
      updatedAt: '2026-07-30T10:20:00Z'
    },
    {
      id: 'ord-1002',
      orderNumber: 'ЛСТ-2026-002',
      tenantId: 'tenant-1',
      locationId: 'loc-1',
      scenario: 'without_installation',
      customerId: 'cust-2',
      customerType: 'physical',
      customerName: 'Петров Сергей Николаевич',
      customerPhone: '+7 (911) 444-55-66',
      vehicleId: 'veh-3',
      vehicleInfo: 'Volkswagen Tiguan (Е456КХ99)',
      status: 'ready_for_release',
      paymentStatus: 'paid',
      items: [
        {
          id: 'item-3',
          itemType: 'product',
          productId: 'prod-3',
          name: 'Pirelli Ice Zero 2 275/40 R20 106T XL',
          brand: 'Pirelli',
          model: 'Ice Zero 2',
          sku: 'PIR-IZ2-2754020',
          warehouseId: 'wh-1',
          qty: 4,
          price: 22800,
          discount: 0,
          totalSum: 91200,
          status: 'reserved',
          responsibleSellerId: 'usr-manager',
          kpiRulePercent: 3,
          kpiAmount: 2736,
          addedAt: '2026-07-30T11:00:00Z'
        }
      ],
      totalAmount: 91200,
      paidAmount: 91200,
      dueAmount: 0,
      responsibleSellerId: 'usr-manager',
      responsibleSellerName: 'Дмитрий Волков',
      shiftId: 'shift-101',
      comment: 'Самовывоз из магазина ул. Салова',
      createdAt: '2026-07-30T11:00:00Z',
      updatedAt: '2026-07-30T11:10:00Z'
    }
  ],
  payments: [
    {
      id: 'pay-1',
      orderId: 'ord-1001',
      orderNumber: 'ЛСТ-2026-001',
      tenantId: 'tenant-1',
      locationId: 'loc-1',
      shiftId: 'shift-101',
      amount: 61000,
      method: 'mixed',
      recipientId: 'rec-1',
      recipientName: 'Основная Касса Наличные (Центральный)',
      operatorUserId: 'usr-cashier',
      operatorUserName: 'Елена Кузнецова',
      status: 'completed',
      createdAt: '2026-07-30T10:20:00Z',
      note: '30 000 руб наличными, 31 000 руб переводом на карту'
    },
    {
      id: 'pay-2',
      orderId: 'ord-1002',
      orderNumber: 'ЛСТ-2026-002',
      tenantId: 'tenant-1',
      locationId: 'loc-1',
      shiftId: 'shift-101',
      amount: 91200,
      method: 'card',
      recipientId: 'rec-2',
      recipientName: 'Карта Сбер (ИП Иванов С.В.)',
      operatorUserId: 'usr-cashier',
      operatorUserName: 'Елена Кузнецова',
      status: 'completed',
      createdAt: '2026-07-30T11:10:00Z',
      note: 'Терминал Сбербанк №0928371'
    }
  ],
  corrections: [],
  shifts: [
    {
      id: 'shift-101',
      tenantId: 'tenant-1',
      locationId: 'loc-1',
      locationName: 'Шинный Центр - Центральный',
      cashierUserId: 'usr-cashier',
      cashierUserName: 'Елена Кузнецова',
      openedAt: '2026-07-30T08:00:00Z',
      status: 'open',
      openingBalance: 15000,
      cashInflow: 30000,
      cardInflow: 91200,
      transferInflow: 31000,
      accountInflow: 0,
      totalInflow: 152200,
      encashmentTotal: 0,
      withdrawalTotal: 0,
      notes: 'Смена открыта штатно'
    }
  ],
  kpiRecords: [
    {
      id: 'kpi-1',
      tenantId: 'tenant-1',
      employeeId: 'usr-manager',
      employeeName: 'Дмитрий Волков',
      employeeRole: 'manager',
      orderId: 'ord-1001',
      orderNumber: 'ЛСТ-2026-001',
      itemType: 'product',
      itemName: 'Michelin Pilot Sport 5',
      saleAmount: 58000,
      commissionPercent: 3,
      kpiEarned: 1740,
      status: 'approved',
      createdAt: '2026-07-30T10:20:00Z'
    },
    {
      id: 'kpi-2',
      tenantId: 'tenant-1',
      employeeId: 'usr-master',
      employeeName: 'Михаил Мастеровой',
      employeeRole: 'master',
      orderId: 'ord-1001',
      orderNumber: 'ЛСТ-2026-001',
      itemType: 'service',
      itemName: 'Комплексный шиномонтаж',
      saleAmount: 3000,
      commissionPercent: 30,
      kpiEarned: 900,
      status: 'approved',
      createdAt: '2026-07-30T10:20:00Z'
    },
    {
      id: 'kpi-3',
      tenantId: 'tenant-1',
      employeeId: 'usr-manager',
      employeeName: 'Дмитрий Волков',
      employeeRole: 'manager',
      orderId: 'ord-1002',
      orderNumber: 'ЛСТ-2026-002',
      itemType: 'product',
      itemName: 'Pirelli Ice Zero 2',
      saleAmount: 91200,
      commissionPercent: 3,
      kpiEarned: 2736,
      status: 'approved',
      createdAt: '2026-07-30T11:10:00Z'
    }
  ],
  auditLogs: [
    {
      id: 'log-1',
      timestamp: '2026-07-30T08:00:00Z',
      tenantId: 'tenant-1',
      locationId: 'loc-1',
      userId: 'usr-cashier',
      userName: 'Елена Кузнецова',
      userRole: 'cashier',
      entity: 'shift',
      entityId: 'shift-101',
      action: 'open_shift',
      details: 'Открытие кассовой смены #101 с остатком 15 000 руб',
      ipAddress: '192.168.1.100',
      deviceInfo: 'POS Workstation Terminal'
    },
    {
      id: 'log-2',
      timestamp: '2026-07-30T10:00:00Z',
      tenantId: 'tenant-1',
      locationId: 'loc-1',
      userId: 'usr-manager',
      userName: 'Дмитрий Волков',
      userRole: 'manager',
      entity: 'order',
      entityId: 'ord-1001',
      action: 'create_order',
      details: 'Создан заказ ЛСТ-2026-001 (Продажа с установкой) для Иванов А.П.',
      newValue: 'Сумма: 61 000 руб, Статус: in_progress',
      ipAddress: '192.168.1.22',
      deviceInfo: 'iPad Air 5'
    },
    {
      id: 'log-3',
      timestamp: '2026-07-30T10:20:00Z',
      tenantId: 'tenant-1',
      locationId: 'loc-1',
      userId: 'usr-cashier',
      userName: 'Елена Кузнецова',
      userRole: 'cashier',
      entity: 'payment',
      entityId: 'pay-1',
      action: 'accept_payment',
      details: 'Принята смешанная оплата 61 000 руб по заказу ЛСТ-2026-001',
      newValue: 'Оплачено 61000 (Касса + Карты)',
      ipAddress: '192.168.1.100',
      deviceInfo: 'POS Workstation Terminal'
    }
  ],
  tasks: [
    {
      id: 'task-1',
      tenantId: 'tenant-1',
      title: 'Подготовить заказ ЛСТ-2026-002 к выдаче клиенту',
      description: 'Проверить комплектность 4 шт Pirelli Ice Zero 2 и напечатать накладную',
      assignedToUserId: 'usr-manager',
      assignedToUserName: 'Дмитрий Волков',
      assignedToRole: 'manager',
      relatedOrderId: 'ord-1002',
      relatedCustomerId: 'cust-2',
      creatorUserId: 'usr-superadmin',
      creatorUserName: 'Алексей Сергеев',
      dueDate: '2026-07-30T16:00:00Z',
      status: 'pending',
      createdAt: '2026-07-30T11:15:00Z'
    },
    {
      id: 'task-2',
      tenantId: 'tenant-1',
      title: 'Сделать сверку остатков 1С с главным складом',
      description: 'Сверить номенклатуру Michelin R17',
      assignedToUserId: 'usr-superadmin',
      assignedToUserName: 'Алексей Сергеев',
      assignedToRole: 'superadmin',
      creatorUserId: 'usr-owner',
      creatorUserName: 'Александр Громов',
      dueDate: '2026-07-31T18:00:00Z',
      status: 'pending',
      createdAt: '2026-07-30T09:00:00Z'
    }
  ],
  modules: [
    {
      id: 'mod-storage',
      code: 'tire_storage',
      name: 'Модуль Сезонного Хранения Шин',
      category: 'Услуги и Склад',
      description: 'Учёт договоров хранения, актов приема-передачи, штрихкодирование стеллажей и ячеек',
      iconName: 'Archive',
      status: 'active',
      priceMonthly: 1500,
      installedAt: '2026-01-10T00:00:00Z',
      permissionsAdded: ['storage_view', 'storage_edit', 'storage_contracts']
    },
    {
      id: 'mod-tv',
      code: 'tv_display',
      name: 'TV-Экран Электронной Очереди',
      category: 'Интерфейсы',
      description: 'Экранная версия очереди сервисных работ в зонах ожидания клиентов',
      iconName: 'Tv',
      status: 'active',
      priceMonthly: 800,
      installedAt: '2026-01-15T00:00:00Z',
      permissionsAdded: ['tv_view']
    },
    {
      id: 'mod-1c',
      code: 'commerce_ml',
      name: 'Загрузка остатков 1С CommerceML2',
      category: 'Интеграция',
      description: 'Забор остатков и цен из выгрузок 1С с фиксацией конфликтов резервов',
      iconName: 'RefreshCw',
      status: 'active',
      priceMonthly: 2000,
      installedAt: '2026-01-01T00:00:00Z',
      permissionsAdded: ['1c_import']
    },
    {
      id: 'mod-chilli',
      code: 'chilli_ai',
      name: 'ИИ-Помощник Chilli Service',
      category: 'ИИ и Подсказки',
      description: 'ИИ-подсказки допродаж, скрипты менеджеров, анализ подозрительных операций',
      iconName: 'Sparkles',
      status: 'available',
      priceMonthly: 3500,
      permissionsAdded: ['ai_prompts']
    },
    {
      id: 'mod-audio',
      code: 'background_audio',
      name: 'Фоновое Аудио и Реклама',
      category: 'Мультимедиа',
      description: 'Управление фоновым звуковым сопровождением и сервисными объявлениями в сервисе',
      iconName: 'Music',
      status: 'available',
      priceMonthly: 1000,
      permissionsAdded: ['audio_manage']
    }
  ],
  settings: {
    paymentMethods: [
      { code: 'cash', name: 'Наличные', active: true },
      { code: 'card', name: 'Банковская карта / СБП', active: true },
      { code: 'transfer', name: 'Перевод на карту ФИО', active: true },
      { code: 'account', name: 'Безналичный расчет (ООО / ИП)', active: true },
      { code: 'mixed', name: 'Смешанная оплата', active: true }
    ],
    cancellationReasons: [
      'Ошибка оформления заказа',
      'Отказ покупателя по цене',
      'Отсутствие нужного размера шины',
      'Клиент передумал / перенёс запись',
      'Дублирующий заказ'
    ],
    deletionReasons: [
      'Ошибка в количестве или размере',
      'Замена товара по согласованию с клиентом',
      'Позиция ошибочно добавлена менеджером',
      'Переход в другую услугу'
    ],
    returnReasons: [
      'Заводской брак / дефект шины',
      'Не подошел размер диска/шины',
      'Возврат по заявлению покупателя в течение 14 дней',
      'Ошибочная отгрузка со склада'
    ],
    correctionReasons: [
      'Ошибка выборки кассира при вводе суммы',
      'Пересортица между наличными и безналом',
      'Корректировка скидки по указанию бухгалтера',
      'Возврат части средств по рекламации'
    ],
    requireCancellationReason: true,
    requireItemDeletionReason: true,
    preventOrderCloseIfUnpaid: true,
    preventItemDeletionAfterRelease: true,
    preventDirectPaymentEditAfterShiftClose: true,
    kpiDefaultProductPercent: 3,
    kpiDefaultServicePercent: 30
  }
};

class Store {
  private db: DatabaseSchema;

  constructor() {
    if (!fs.existsSync(DATA_DIR)) {
      fs.mkdirSync(DATA_DIR, { recursive: true });
    }
    if (fs.existsSync(DB_FILE)) {
      try {
        const raw = fs.readFileSync(DB_FILE, 'utf-8');
        this.db = JSON.parse(raw);
      } catch (e) {
        console.error('Failed to parse db.json, using seed', e);
        this.db = initialSeed;
        this.save();
      }
    } else {
      this.db = initialSeed;
      this.save();
    }
  }

  public save() {
    try {
      fs.writeFileSync(DB_FILE, JSON.stringify(this.db, null, 2), 'utf-8');
    } catch (e) {
      console.error('Failed to write db.json', e);
    }
  }

  public get(): DatabaseSchema {
    return this.db;
  }

  // Audit logging helper
  public logAudit(entry: Omit<AuditLogEntry, 'id' | 'timestamp'>): AuditLogEntry {
    const fullLog: AuditLogEntry = {
      ...entry,
      id: `log-${Date.now()}-${Math.floor(Math.random() * 1000)}`,
      timestamp: new Date().toISOString()
    };
    this.db.auditLogs.unshift(fullLog);
    this.save();
    return fullLog;
  }

  // Stock availability helper
  public updateStockAvailable(stockItemId: string) {
    const item = this.db.stock.find((s) => s.id === stockItemId);
    if (item) {
      item.availableQty = Math.max(0, item.actualQty - item.reservedQty);
      item.lastUpdated = new Date().toISOString();
    }
  }

  // Recalculate stock reservations across active orders
  public recalculateReservations() {
    // Reset all reservedQty
    for (const st of this.db.stock) {
      st.reservedQty = 0;
    }
    // Sum active reservations
    for (const order of this.db.orders) {
      if (order.status !== 'cancelled' && order.status !== 'completed') {
        for (const item of order.items) {
          if (item.itemType === 'product' && item.productId && item.status === 'reserved' && item.warehouseId) {
            const st = this.db.stock.find((s) => s.warehouseId === item.warehouseId && s.productId === item.productId);
            if (st) {
              st.reservedQty += item.qty;
            }
          }
        }
      }
    }
    // Update availableQty
    for (const st of this.db.stock) {
      st.availableQty = Math.max(0, st.actualQty - st.reservedQty);
    }
    this.save();
  }
}

export const store = new Store();
