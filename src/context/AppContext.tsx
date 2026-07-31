import React, { createContext, useContext, useState, useEffect } from 'react';
import {
  User,
  PlatformTenant,
  TenantLocation,
  MoneyRecipient,
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
} from '../types';
import { setLaravelContext } from '../api/laravelClient';
interface AppContextType {
  loading: boolean;
  activeUser: User | null;
  activeTenant: PlatformTenant | null;
  activeLocation: TenantLocation | null;
  activeShift: CashShift | null;
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
  settings: SystemSettings | null;

  // Actions
  switchUser: (userId: string) => Promise<void>;
  switchTenant: (tenantId: string) => void;
  switchLocation: (locationId: string) => void;
  toggleSupportAccess: (enabled: boolean, reason: string) => Promise<void>;
  disconnectDevice: (userId: string, deviceId: string) => Promise<void>;

  createCustomer: (customer: Partial<Customer>) => Promise<Customer>;
  updateCustomer: (id: string, customer: Partial<Customer>) => Promise<Customer>;
  mergeCustomers: (primaryId: string, secondaryId: string, reason: string) => Promise<void>;
  importCustomersExcel: (rows: any[]) => Promise<any>;

  createVehicle: (vehicle: Partial<Vehicle>) => Promise<Vehicle>;

  createOrder: (order: Partial<Order>) => Promise<Order>;
  releaseOrderItem: (orderId: string, itemId: string) => Promise<Order>;
  cancelOrder: (orderId: string, reason: string) => Promise<Order>;
  markImportantCommentRead: (orderId: string) => Promise<void>;

  acceptPayment: (payment: Partial<PaymentRecord>) => Promise<void>;
  correctPayment: (paymentId: string, newAmount: number, newMethod: any, newRecipientId: string, reason: string) => Promise<void>;

  openShift: (openingBalance: number) => Promise<void>;
  closeShift: (shiftId: string, closingCashActual: number, notes?: string) => Promise<void>;
  cashMovement: (shiftId: string, type: 'encashment' | 'withdrawal', amount: number, reason: string) => Promise<void>;

  transferStock: (productId: string, sourceWarehouseId: string, targetWarehouseId: string, qty: number, reason: string) => Promise<void>;
  import1CStock: (warehouseId: string, xmlContent?: string) => Promise<any>;

  annotateAudit: (logId: string, text: string, category: string) => Promise<void>;
  createTask: (task: Partial<Task>) => Promise<void>;
  updateTaskStatus: (taskId: string, status: 'pending' | 'completed' | 'cancelled', cancelReason?: string) => Promise<void>;
  toggleModule: (code: string, status: 'active' | 'disabled') => Promise<void>;
  refreshData: () => Promise<void>;
}

const AppContext = createContext<AppContextType | null>(null);

export const AppProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [loading, setLoading] = useState(true);
  const [tenants, setTenants] = useState<PlatformTenant[]>([]);
  const [locations, setLocations] = useState<TenantLocation[]>([]);
  const [recipients, setRecipients] = useState<MoneyRecipient[]>([]);
  const [users, setUsers] = useState<User[]>([]);
  const [roles, setRoles] = useState<RoleDefinition[]>([]);
  const [customers, setCustomers] = useState<Customer[]>([]);
  const [vehicles, setVehicles] = useState<Vehicle[]>([]);
  const [products, setProducts] = useState<Product[]>([]);
  const [services, setServices] = useState<Service[]>([]);
  const [warehouses, setWarehouses] = useState<Warehouse[]>([]);
  const [stock, setStock] = useState<StockItem[]>([]);
  const [orders, setOrders] = useState<Order[]>([]);
  const [payments, setPayments] = useState<PaymentRecord[]>([]);
  const [corrections, setCorrections] = useState<CorrectionRecord[]>([]);
  const [shifts, setShifts] = useState<CashShift[]>([]);
  const [kpiRecords, setKpiRecords] = useState<KPIRecord[]>([]);
  const [auditLogs, setAuditLogs] = useState<AuditLogEntry[]>([]);
  const [tasks, setTasks] = useState<Task[]>([]);
  const [modules, setModules] = useState<SystemModule[]>([]);
  const [settings, setSettings] = useState<SystemSettings | null>(null);

  const [activeUser, setActiveUser] = useState<User | null>(null);
  const [activeTenant, setActiveTenant] = useState<PlatformTenant | null>(null);
  const [activeLocation, setActiveLocation] = useState<TenantLocation | null>(null);
  const [activeShift, setActiveShift] = useState<CashShift | null>(null);

  const refreshData = async () => {
    try {
      const res = await fetch('/api/bootstrap');
      const data = await res.json();
      setTenants(data.tenants || []);
      setLocations(data.locations || []);
      setRecipients(data.recipients || []);
      setUsers(data.users || []);
      setRoles(data.roles || []);
      setCustomers(data.customers || []);
      setVehicles(data.vehicles || []);
      setProducts(data.products || []);
      setServices(data.services || []);
      setWarehouses(data.warehouses || []);
      setStock(data.stock || []);
      setOrders(data.orders || []);
      setPayments(data.payments || []);
      setCorrections(data.corrections || []);
      setShifts(data.shifts || []);
      setKpiRecords(data.kpiRecords || []);
      setAuditLogs(data.auditLogs || []);
      setTasks(data.tasks || []);
      setModules(data.modules || []);
      setSettings(data.settings || null);

      // Default selected user: superadmin
      if (!activeUser && data.users && data.users.length > 0) {
        const defaultUser = data.users.find((u: User) => u.role === 'superadmin') || data.users[0];
        setActiveUser(defaultUser);
        const tenant = data.tenants.find((t: PlatformTenant) => t.id === defaultUser.tenantId) || data.tenants[0];
        setActiveTenant(tenant);
        const loc = data.locations.find((l: TenantLocation) => defaultUser.locationIds.includes(l.id)) || data.locations[0];
        setActiveLocation(loc);
      }
    } catch (e) {
      console.error('Failed to load bootstrap data', e);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    refreshData();
  }, []);

  // Update active shift whenever active location changes
  useEffect(() => {
    if (activeLocation && shifts) {
      const open = shifts.find((s) => s.locationId === activeLocation.id && s.status === 'open');
      setActiveShift(open || null);
    }
  }, [activeLocation, shifts]);

  // Sync tenant/location into Laravel API client headers (X-Tenant-ID / X-Location-ID).
  useEffect(() => {
    setLaravelContext({
      tenantId: activeTenant?.id ?? null,
      tenantSlug: 'acceptance',
      locationId: activeLocation?.id ?? null,
    });
  }, [activeTenant?.id, activeLocation?.id]);

  const switchUser = async (userId: string) => {
    const res = await fetch('/api/auth/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ userId })
    });
    const data = await res.json();
    if (data.user) {
      setActiveUser(data.user);
      const tenant = tenants.find((t) => t.id === data.user.tenantId);
      if (tenant) setActiveTenant(tenant);
      const loc = locations.find((l) => data.user.locationIds.includes(l.id));
      if (loc) setActiveLocation(loc);
      await refreshData();
    }
  };

  const switchTenant = (tenantId: string) => {
    const t = tenants.find((item) => item.id === tenantId);
    if (t) {
      setActiveTenant(t);
      const loc = locations.find((l) => l.tenantId === t.id);
      if (loc) setActiveLocation(loc);
    }
  };

  const switchLocation = (locationId: string) => {
    const l = locations.find((loc) => loc.id === locationId);
    if (l) setActiveLocation(l);
  };

  const toggleSupportAccess = async (enabled: boolean, reason: string) => {
    if (!activeTenant || !activeUser) return;
    const res = await fetch('/api/tenants/support-access', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        tenantId: activeTenant.id,
        enabled,
        reason,
        userId: activeUser.id,
        userName: activeUser.name
      })
    });
    if (res.ok) await refreshData();
  };

  const disconnectDevice = async (userId: string, deviceId: string) => {
    await fetch('/api/auth/disconnect-device', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ userId, deviceId })
    });
    await refreshData();
  };

  const createCustomer = async (cust: Partial<Customer>) => {
    const res = await fetch('/api/customers', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        customer: { ...cust, tenantId: activeTenant?.id },
        userId: activeUser?.id,
        userName: activeUser?.name,
        userRole: activeUser?.role
      })
    });
    const data = await res.json();
    await refreshData();
    return data;
  };

  const updateCustomer = async (id: string, cust: Partial<Customer>) => {
    const res = await fetch(`/api/customers/${id}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        customer: cust,
        userId: activeUser?.id,
        userName: activeUser?.name,
        userRole: activeUser?.role
      })
    });
    const data = await res.json();
    await refreshData();
    return data;
  };

  const mergeCustomers = async (primaryId: string, secondaryId: string, reason: string) => {
    await fetch('/api/customers/merge', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        primaryId,
        secondaryId,
        reason,
        userId: activeUser?.id,
        userName: activeUser?.name,
        userRole: activeUser?.role
      })
    });
    await refreshData();
  };

  const importCustomersExcel = async (rows: any[]) => {
    const res = await fetch('/api/customers/import-excel', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        rows,
        tenantId: activeTenant?.id,
        userId: activeUser?.id,
        userName: activeUser?.name,
        userRole: activeUser?.role
      })
    });
    const data = await res.json();
    await refreshData();
    return data;
  };

  const createVehicle = async (veh: Partial<Vehicle>) => {
    const res = await fetch('/api/vehicles', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        vehicle: { ...veh, tenantId: activeTenant?.id },
        userId: activeUser?.id,
        userName: activeUser?.name,
        userRole: activeUser?.role
      })
    });
    const data = await res.json();
    await refreshData();
    return data;
  };

  const createOrder = async (ord: Partial<Order>) => {
    const res = await fetch('/api/orders', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        order: {
          ...ord,
          tenantId: activeTenant?.id,
          locationId: activeLocation?.id,
          responsibleSellerId: activeUser?.id,
          responsibleSellerName: activeUser?.name
        },
        userId: activeUser?.id,
        userName: activeUser?.name,
        userRole: activeUser?.role
      })
    });
    const data = await res.json();
    await refreshData();
    return data;
  };

  const releaseOrderItem = async (orderId: string, itemId: string) => {
    const res = await fetch(`/api/orders/${orderId}/release`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        itemId,
        userId: activeUser?.id,
        userName: activeUser?.name,
        userRole: activeUser?.role
      })
    });
    const data = await res.json();
    await refreshData();
    return data;
  };

  const cancelOrder = async (orderId: string, reason: string) => {
    const res = await fetch(`/api/orders/${orderId}/cancel`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        reason,
        userId: activeUser?.id,
        userName: activeUser?.name,
        userRole: activeUser?.role
      })
    });
    const data = await res.json();
    await refreshData();
    return data;
  };

  const markImportantCommentRead = async (orderId: string) => {
    if (!activeUser) return;
    await fetch(`/api/orders/${orderId}/comment-read`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ userId: activeUser.id })
    });
    await refreshData();
  };

  const acceptPayment = async (pay: Partial<PaymentRecord>) => {
    await fetch('/api/payments', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        payment: {
          ...pay,
          tenantId: activeTenant?.id,
          locationId: activeLocation?.id,
          shiftId: activeShift?.id || 'shift-101',
          operatorUserId: activeUser?.id,
          operatorUserName: activeUser?.name
        },
        userId: activeUser?.id,
        userName: activeUser?.name,
        userRole: activeUser?.role
      })
    });
    await refreshData();
  };

  const correctPayment = async (paymentId: string, newAmount: number, newMethod: any, newRecipientId: string, reason: string) => {
    await fetch('/api/payments/correct', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        paymentId,
        newAmount,
        newMethod,
        newRecipientId,
        reason,
        userId: activeUser?.id,
        userName: activeUser?.name,
        userRole: activeUser?.role
      })
    });
    await refreshData();
  };

  const openShift = async (openingBalance: number) => {
    await fetch('/api/shifts/open', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        tenantId: activeTenant?.id,
        locationId: activeLocation?.id,
        locationName: activeLocation?.name,
        openingBalance,
        userId: activeUser?.id,
        userName: activeUser?.name,
        userRole: activeUser?.role
      })
    });
    await refreshData();
  };

  const closeShift = async (shiftId: string, closingCashActual: number, notes?: string) => {
    await fetch('/api/shifts/close', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        shiftId,
        closingCashActual,
        notes,
        userId: activeUser?.id,
        userName: activeUser?.name,
        userRole: activeUser?.role
      })
    });
    await refreshData();
  };

  const cashMovement = async (shiftId: string, type: 'encashment' | 'withdrawal', amount: number, reason: string) => {
    await fetch('/api/shifts/cash-movement', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        shiftId,
        type,
        amount,
        reason,
        userId: activeUser?.id,
        userName: activeUser?.name,
        userRole: activeUser?.role
      })
    });
    await refreshData();
  };

  const transferStock = async (productId: string, sourceWarehouseId: string, targetWarehouseId: string, qty: number, reason: string) => {
    await fetch('/api/stock/transfer', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        tenantId: activeTenant?.id,
        productId,
        sourceWarehouseId,
        targetWarehouseId,
        qty,
        reason,
        userId: activeUser?.id,
        userName: activeUser?.name,
        userRole: activeUser?.role
      })
    });
    await refreshData();
  };

  const import1CStock = async (warehouseId: string, xmlContent?: string) => {
    const res = await fetch('/api/stock/import-1c', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        warehouseId,
        xmlContent,
        tenantId: activeTenant?.id,
        userId: activeUser?.id,
        userName: activeUser?.name,
        userRole: activeUser?.role
      })
    });
    const data = await res.json();
    await refreshData();
    return data;
  };

  const annotateAudit = async (logId: string, text: string, category: string) => {
    await fetch(`/api/audit/${logId}/annotate`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        text,
        category,
        userId: activeUser?.id,
        userName: activeUser?.name
      })
    });
    await refreshData();
  };

  const createTask = async (task: Partial<Task>) => {
    await fetch('/api/tasks', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        task: { ...task, tenantId: activeTenant?.id },
        userId: activeUser?.id,
        userName: activeUser?.name,
        userRole: activeUser?.role
      })
    });
    await refreshData();
  };

  const updateTaskStatus = async (taskId: string, status: 'pending' | 'completed' | 'cancelled', cancelReason?: string) => {
    await fetch(`/api/tasks/${taskId}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        status,
        cancelReason,
        userId: activeUser?.id,
        userName: activeUser?.name,
        userRole: activeUser?.role
      })
    });
    await refreshData();
  };

  const toggleModule = async (code: string, status: 'active' | 'disabled') => {
    await fetch('/api/modules/toggle', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        code,
        status,
        userId: activeUser?.id,
        userName: activeUser?.name,
        userRole: activeUser?.role
      })
    });
    await refreshData();
  };

  return (
    <AppContext.Provider
      value={{
        loading,
        activeUser,
        activeTenant,
        activeLocation,
        activeShift,
        tenants,
        locations,
        recipients,
        users,
        roles,
        customers,
        vehicles,
        products,
        services,
        warehouses,
        stock,
        orders,
        payments,
        corrections,
        shifts,
        kpiRecords,
        auditLogs,
        tasks,
        modules,
        settings,
        switchUser,
        switchTenant,
        switchLocation,
        toggleSupportAccess,
        disconnectDevice,
        createCustomer,
        updateCustomer,
        mergeCustomers,
        importCustomersExcel,
        createVehicle,
        createOrder,
        releaseOrderItem,
        cancelOrder,
        markImportantCommentRead,
        acceptPayment,
        correctPayment,
        openShift,
        closeShift,
        cashMovement,
        transferStock,
        import1CStock,
        annotateAudit,
        createTask,
        updateTaskStatus,
        toggleModule,
        refreshData
      }}
    >
      {children}
    </AppContext.Provider>
  );
};

export const useApp = () => {
  const context = useContext(AppContext);
  if (!context) throw new Error('useApp must be used within AppProvider');
  return context;
};
