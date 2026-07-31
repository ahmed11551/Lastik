export type UserRole =
  | 'platform_owner'
  | 'superadmin'
  | 'admin'
  | 'manager'
  | 'cashier'
  | 'accountant'
  | 'master'
  | 'storekeeper';

export type PermissionAction =
  | 'view'
  | 'create'
  | 'edit'
  | 'delete'
  | 'cancel'
  | 'approve'
  | 'accept_payment'
  | 'edit_payment'
  | 'open_shift'
  | 'close_shift'
  | 'view_cash'
  | 'view_cost_price'
  | 'give_discount'
  | 'change_price'
  | 'sell_over_stock'
  | 'view_audit'
  | 'manage_users'
  | 'manage_roles'
  | 'manage_settings'
  | 'manage_modules'
  | 'override_payment_after_release';

export interface UserPermissionMap {
  [section: string]: PermissionAction[];
}

export interface UserDevice {
  id: string;
  deviceName: string;
  deviceType: 'desktop' | 'mobile' | 'tablet';
  ipAddress: string;
  lastActive: string;
  isCurrent: boolean;
}

export interface User {
  id: string;
  name: string;
  email: string;
  phone: string;
  role: UserRole;
  roleName: string;
  tenantId: string;
  locationIds: string[];
  active: boolean;
  createdAt: string;
  lastLoginAt?: string;
  devices: UserDevice[];
}

export interface PlatformTenant {
  id: string;
  name: string;
  legalName: string;
  inn: string;
  address: string;
  phone: string;
  email: string;
  active: boolean;
  rentalStartDate: string;
  rentalEndDate: string;
  rentalStatus: 'active' | 'warning' | 'expired' | 'blocked';
  userLimit: number;
  adminLimit: number;
  activeModules: string[];
  supportAccessEnabled?: boolean;
  supportAccessReason?: string;
  supportAccessExpiry?: string;
}

export interface TenantLocation {
  id: string;
  tenantId: string;
  name: string;
  address: string;
  type: 'store_service' | 'service_only' | 'store_only' | 'warehouse' | 'paint_shop';
  timezone: string;
  active: boolean;
  phone: string;
}

export interface MoneyRecipient {
  id: string;
  tenantId: string;
  name: string;
  type: 'cashbox' | 'card_fio' | 'account_ip' | 'account_ooo';
  details: string; // Account # or Card FIO or Cashbox location
  active: boolean;
}

export interface RoleDefinition {
  id: string;
  code: UserRole;
  name: string;
  description: string;
  isCustom?: boolean;
  permissions: UserPermissionMap;
}

export type CustomerType = 'physical' | 'legal';

export interface Customer {
  id: string;
  tenantId: string;
  type: CustomerType;
  name: string; // Full Name or Legal Entity Name
  legalName?: string;
  inn?: string;
  kpp?: string;
  contactPerson?: string;
  phone: string;
  additionalPhones?: string[];
  email?: string;
  comment?: string;
  isVip?: boolean;
  discountPercent?: number;
  preferredContactChannel?: 'phone' | 'telegram' | 'whatsapp' | 'max' | 'sms';
  createdAt: string;
  updatedAt: string;
}

export interface Vehicle {
  id: string;
  customerId: string;
  tenantId: string;
  make: string;
  model: string;
  licensePlate: string;
  vin?: string;
  year?: number;
  tireSizeFront?: string;
  tireSizeRear?: string;
  wheelBoltPattern?: string;
  comment?: string;
  createdAt: string;
}

export type ProductSeasonality = 'summer' | 'winter_studded' | 'winter_friction' | 'all_season' | 'none';
export type ProductType = 'tire' | 'wheel' | 'fastener' | 'accessory' | 'fluid';

export interface Product {
  id: string;
  tenantId: string;
  externalId?: string;
  sku: string;
  name: string;
  brand: string;
  model: string;
  type: ProductType;
  seasonality?: ProductSeasonality;
  width?: number;
  profile?: number;
  radius?: number;
  boltPattern?: string;
  priceRetail: number;
  priceWholesale?: number;
  pricePurchase?: number;
  unit: string;
  active: boolean;
  imageUrl?: string;
}

export interface Service {
  id: string;
  tenantId: string;
  name: string;
  category: string;
  price: number;
  durationMinutes: number;
  kpiMasterPercent: number;
  active: boolean;
}

export interface Warehouse {
  id: string;
  tenantId: string;
  locationId: string;
  name: string;
  code: string;
  isDefault: boolean;
}

export interface StockItem {
  id: string;
  warehouseId: string;
  productId: string;
  actualQty: number;
  reservedQty: number;
  availableQty: number; // actualQty - reservedQty
  price: number;
  lastUpdated: string;
  lastSource: '1c_import' | 'manual' | 'transfer' | 'order_release';
}

export type OrderScenario = 'with_installation' | 'without_installation';
export type OrderStatus = 'created' | 'in_progress' | 'ready_for_release' | 'released' | 'completed' | 'cancelled';
export type OrderPaymentStatus = 'unpaid' | 'partially_paid' | 'paid' | 'debt' | 'refunded' | 'adjusted';
export type ItemStatus = 'added' | 'reserved' | 'released' | 'cancelled' | 'returned';

export interface OrderItemSnapshot {
  id: string;
  itemType: 'product' | 'service';
  productId?: string;
  serviceId?: string;
  name: string;
  brand?: string;
  model?: string;
  sku?: string;
  warehouseId?: string;
  qty: number;
  price: number;
  discount: number;
  totalSum: number;
  status: ItemStatus;
  responsibleSellerId?: string;
  masterExecutorId?: string;
  kpiRulePercent: number;
  kpiAmount: number;
  addedAt: string;
  releasedAt?: string;
  releasedByUserId?: string;
}

export interface Order {
  id: string;
  orderNumber: string;
  tenantId: string;
  locationId: string;
  scenario: OrderScenario;
  customerId: string;
  customerType: CustomerType;
  customerName: string;
  customerPhone: string;
  vehicleId?: string;
  vehicleInfo?: string;
  status: OrderStatus;
  paymentStatus: OrderPaymentStatus;
  items: OrderItemSnapshot[];
  totalAmount: number;
  paidAmount: number;
  dueAmount: number;
  responsibleSellerId: string;
  responsibleSellerName: string;
  masterExecutorId?: string;
  masterExecutorName?: string;
  shiftId?: string;
  comment?: string;
  importantComment?: string;
  importantCommentReadBy?: { userId: string; readAt: string }[];
  cancelReason?: string;
  createdAt: string;
  updatedAt: string;
  closedAt?: string;
}

export type PaymentMethod = 'cash' | 'card' | 'transfer' | 'account' | 'mixed';

export interface PaymentRecord {
  id: string;
  orderId: string;
  orderNumber: string;
  tenantId: string;
  locationId: string;
  shiftId: string;
  amount: number;
  method: PaymentMethod;
  recipientId: string;
  recipientName: string;
  operatorUserId: string;
  operatorUserName: string;
  status: 'completed' | 'adjusted' | 'refunded';
  createdAt: string;
  note?: string;
}

export interface CorrectionRecord {
  id: string;
  tenantId: string;
  type: 'payment' | 'order_item' | 'kpi' | 'stock';
  targetId: string;
  relatedOrderId?: string;
  operatorUserId: string;
  operatorUserName: string;
  beforeValue: string;
  afterValue: string;
  reason: string;
  accountantNote?: string;
  createdAt: string;
}

export interface CashShift {
  id: string;
  tenantId: string;
  locationId: string;
  locationName: string;
  cashierUserId: string;
  cashierUserName: string;
  openedAt: string;
  closedAt?: string;
  status: 'open' | 'closed';
  openingBalance: number;
  cashInflow: number;
  cardInflow: number;
  transferInflow: number;
  accountInflow: number;
  totalInflow: number;
  encashmentTotal: number; // Инкассация
  withdrawalTotal: number; // Выемка
  closingCashActual?: number;
  discrepancy?: number;
  notes?: string;
}

export interface KPIRecord {
  id: string;
  tenantId: string;
  employeeId: string;
  employeeName: string;
  employeeRole: UserRole;
  orderId: string;
  orderNumber: string;
  itemType: 'product' | 'service';
  itemName: string;
  saleAmount: number;
  commissionPercent: number;
  kpiEarned: number;
  status: 'pending' | 'approved' | 'adjusted';
  createdAt: string;
}

export interface AuditLogEntry {
  id: string;
  timestamp: string;
  tenantId: string;
  locationId?: string;
  userId: string;
  userName: string;
  userRole: string;
  entity: string; // e.g., 'order', 'payment', 'stock', 'customer', 'shift', 'settings'
  entityId?: string;
  action: string; // e.g., 'create', 'update_status', 'release_item', 'accept_payment', 'close_shift', 'merge_duplicate'
  details: string;
  oldValue?: string;
  newValue?: string;
  reason?: string;
  ipAddress: string;
  deviceInfo: string;
  annotations?: { id: string; userId: string; userName: string; text: string; category: string; createdAt: string }[];
}

export type TaskStatus = 'pending' | 'completed' | 'cancelled';

export interface Task {
  id: string;
  tenantId: string;
  title: string;
  description?: string;
  assignedToUserId?: string;
  assignedToUserName?: string;
  assignedToRole?: UserRole;
  relatedOrderId?: string;
  relatedCustomerId?: string;
  relatedVehicleId?: string;
  creatorUserId: string;
  creatorUserName: string;
  dueDate: string;
  status: 'pending' | 'completed' | 'cancelled';
  cancelReason?: string;
  createdAt: string;
  completedAt?: string;
}

export interface SystemModule {
  id: string;
  code: string;
  name: string;
  category: string;
  description: string;
  iconName: string;
  status: 'active' | 'disabled' | 'available';
  priceMonthly: number;
  installedAt?: string;
  permissionsAdded: string[];
}

export interface SystemSettings {
  paymentMethods: { code: string; name: string; active: boolean }[];
  cancellationReasons: string[];
  deletionReasons: string[];
  returnReasons: string[];
  correctionReasons: string[];
  requireCancellationReason: boolean;
  requireItemDeletionReason: boolean;
  preventOrderCloseIfUnpaid: boolean;
  preventItemDeletionAfterRelease: boolean;
  preventDirectPaymentEditAfterShiftClose: boolean;
  kpiDefaultProductPercent: number;
  kpiDefaultServicePercent: number;
}
