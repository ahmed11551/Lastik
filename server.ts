import express from 'express';
import path from 'path';
import { createServer as createViteServer } from 'vite';
import { store } from './server/store.js';
import {
  Customer,
  Vehicle,
  Product,
  Service,
  Order,
  PaymentRecord,
  CorrectionRecord,
  CashShift,
  Task,
  StockItem
} from './src/types.js';

async function startServer() {
  const app = express();
  const PORT = 3000;
  const laravelProxy = process.env.VITE_LARAVEL_PROXY || 'http://127.0.0.1:8010';

  app.use(express.json({ limit: '10mb' }));

  // Bridge: forward /api/v1/* to Laravel (credentials + tenant/location headers preserved)
  app.use('/api/v1', async (req, res) => {
    try {
      const target = new URL(req.originalUrl, laravelProxy);
      const headers = new Headers();
      for (const [key, value] of Object.entries(req.headers)) {
        if (value == null) continue;
        const lower = key.toLowerCase();
        if (lower === 'host' || lower === 'content-length' || lower === 'connection') continue;
        headers.set(key, Array.isArray(value) ? value.join(',') : String(value));
      }
      const init: RequestInit = {
        method: req.method,
        headers,
        redirect: 'manual',
      };
      if (req.method !== 'GET' && req.method !== 'HEAD' && req.body !== undefined) {
        init.body = JSON.stringify(req.body);
        if (!headers.has('Content-Type')) headers.set('Content-Type', 'application/json');
      }
      const upstream = await fetch(target, init);
      res.status(upstream.status);
      upstream.headers.forEach((v, k) => {
        if (['transfer-encoding', 'content-encoding', 'content-length'].includes(k.toLowerCase())) return;
        res.setHeader(k, v);
      });
      const buf = Buffer.from(await upstream.arrayBuffer());
      res.send(buf);
    } catch (err) {
      console.error('Laravel proxy error', err);
      res.status(502).json({ message: 'Laravel API unavailable', error: String(err) });
    }
  });

  // Helper middleware to log API calls
  app.use('/api', (req, res, next) => {
    next();
  });

  // Health
  app.get('/api/health', (req, res) => {
    res.json({ status: 'ok', timestamp: new Date().toISOString() });
  });

  // Bootstrap data for client
  app.get('/api/bootstrap', (req, res) => {
    const db = store.get();
    res.json({
      tenants: db.tenants,
      locations: db.locations,
      recipients: db.recipients,
      users: db.users,
      roles: db.roles,
      customers: db.customers,
      vehicles: db.vehicles,
      products: db.products,
      services: db.services,
      warehouses: db.warehouses,
      stock: db.stock,
      orders: db.orders,
      payments: db.payments,
      corrections: db.corrections,
      shifts: db.shifts,
      kpiRecords: db.kpiRecords,
      auditLogs: db.auditLogs,
      tasks: db.tasks,
      modules: db.modules,
      settings: db.settings
    });
  });

  // Authentication & Devices
  app.post('/api/auth/login', (req, res) => {
    const { userId } = req.body;
    const db = store.get();
    const user = db.users.find((u) => u.id === userId);
    if (!user) {
      return res.status(404).json({ error: 'Пользователь не найден' });
    }
    user.lastLoginAt = new Date().toISOString();
    store.save();

    store.logAudit({
      tenantId: user.tenantId,
      userId: user.id,
      userName: user.name,
      userRole: user.role,
      entity: 'user',
      entityId: user.id,
      action: 'login',
      details: `Вход пользователя ${user.name} (${user.roleName})`,
      ipAddress: req.ip || '127.0.0.1',
      deviceInfo: req.headers['user-agent'] || 'Browser'
    });

    res.json({ user });
  });

  app.post('/api/auth/disconnect-device', (req, res) => {
    const { userId, deviceId } = req.body;
    const db = store.get();
    const user = db.users.find((u) => u.id === userId);
    if (!user) return res.status(404).json({ error: 'Пользователь не найден' });

    user.devices = user.devices.filter((d) => d.id !== deviceId);
    store.save();

    store.logAudit({
      tenantId: user.tenantId,
      userId: user.id,
      userName: user.name,
      userRole: user.role,
      entity: 'device',
      entityId: deviceId,
      action: 'disconnect_device',
      details: `Отключено устройство ID ${deviceId} для пользователя ${user.name}`,
      ipAddress: req.ip || '127.0.0.1',
      deviceInfo: req.headers['user-agent'] || 'Browser'
    });

    res.json({ success: true, devices: user.devices });
  });

  // Support Access Toggle for Platform Owner
  app.post('/api/tenants/support-access', (req, res) => {
    const { tenantId, enabled, reason, userId, userName } = req.body;
    const db = store.get();
    const tenant = db.tenants.find((t) => t.id === tenantId);
    if (!tenant) return res.status(404).json({ error: 'Организация не найдена' });

    tenant.supportAccessEnabled = enabled;
    tenant.supportAccessReason = enabled ? reason : undefined;
    tenant.supportAccessExpiry = enabled ? new Date(Date.now() + 8 * 3600 * 1000).toISOString() : undefined;
    store.save();

    store.logAudit({
      tenantId,
      userId,
      userName,
      userRole: 'platform_owner',
      entity: 'tenant',
      entityId: tenantId,
      action: 'support_access_toggle',
      details: enabled
        ? `Включен режим технической поддержки арендатора "${tenant.name}". Причина: ${reason}`
        : `Выключен режим технической поддержки арендатора "${tenant.name}"`,
      reason,
      ipAddress: req.ip || '127.0.0.1',
      deviceInfo: req.headers['user-agent'] || 'Browser'
    });

    res.json({ tenant });
  });

  // Customers
  app.get('/api/customers', (req, res) => {
    res.json(store.get().customers);
  });

  app.post('/api/customers', (req, res) => {
    const { customer, userId, userName, userRole } = req.body;
    const db = store.get();

    const newCustomer: Customer = {
      ...customer,
      id: `cust-${Date.now()}`,
      createdAt: new Date().toISOString(),
      updatedAt: new Date().toISOString()
    };

    db.customers.unshift(newCustomer);
    store.save();

    store.logAudit({
      tenantId: newCustomer.tenantId,
      userId,
      userName,
      userRole,
      entity: 'customer',
      entityId: newCustomer.id,
      action: 'create_customer',
      details: `Создан покупатель "${newCustomer.name}" (${newCustomer.type === 'physical' ? 'Физлицо' : 'Юрлицо'}, Тел: ${newCustomer.phone})`,
      ipAddress: req.ip || '127.0.0.1',
      deviceInfo: req.headers['user-agent'] || 'Browser'
    });

    res.json(newCustomer);
  });

  app.put('/api/customers/:id', (req, res) => {
    const { id } = req.params;
    const { customer, userId, userName, userRole } = req.body;
    const db = store.get();
    const index = db.customers.findIndex((c) => c.id === id);
    if (index === -1) return res.status(404).json({ error: 'Покупатель не найден' });

    const oldVal = JSON.stringify(db.customers[index]);
    db.customers[index] = {
      ...db.customers[index],
      ...customer,
      updatedAt: new Date().toISOString()
    };
    store.save();

    store.logAudit({
      tenantId: db.customers[index].tenantId,
      userId,
      userName,
      userRole,
      entity: 'customer',
      entityId: id,
      action: 'update_customer',
      details: `Обновлена карточка покупателя "${db.customers[index].name}"`,
      oldValue: oldVal,
      newValue: JSON.stringify(db.customers[index]),
      ipAddress: req.ip || '127.0.0.1',
      deviceInfo: req.headers['user-agent'] || 'Browser'
    });

    res.json(db.customers[index]);
  });

  // Duplicate Merge
  app.post('/api/customers/merge', (req, res) => {
    const { primaryId, secondaryId, reason, userId, userName, userRole } = req.body;
    const db = store.get();
    const primary = db.customers.find((c) => c.id === primaryId);
    const secondary = db.customers.find((c) => c.id === secondaryId);

    if (!primary || !secondary) {
      return res.status(400).json({ error: 'Выбранные записи дублей не найдены' });
    }

    // Reassign orders and vehicles from secondary to primary
    db.orders.forEach((o) => {
      if (o.customerId === secondaryId) {
        o.customerId = primaryId;
        o.customerName = primary.name;
      }
    });

    db.vehicles.forEach((v) => {
      if (v.customerId === secondaryId) {
        v.customerId = primaryId;
      }
    });

    // Remove secondary customer
    db.customers = db.customers.filter((c) => c.id !== secondaryId);
    store.save();

    store.logAudit({
      tenantId: primary.tenantId,
      userId,
      userName,
      userRole,
      entity: 'customer',
      entityId: primaryId,
      action: 'merge_duplicate',
      details: `Объединены карточки дубликатов: "${secondary.name}" (ID: ${secondaryId}) перенесен в "${primary.name}" (ID: ${primaryId})`,
      reason,
      ipAddress: req.ip || '127.0.0.1',
      deviceInfo: req.headers['user-agent'] || 'Browser'
    });

    res.json({ success: true, primaryCustomer: primary });
  });

  // Excel Customer Import Simulator Endpoint
  app.post('/api/customers/import-excel', (req, res) => {
    const { rows, tenantId, userId, userName, userRole } = req.body;
    const db = store.get();
    let imported = 0;
    let skipped = 0;
    const errors: string[] = [];

    if (!Array.isArray(rows)) {
      return res.status(400).json({ error: 'Неверный формат данных импорта' });
    }

    rows.forEach((row: any, idx: number) => {
      const name = row.name || row['ФИО'] || row['Название организации'];
      const phone = row.phone || row['Телефон'] || row['Тел'];
      const type = row.type || (row['ИНН'] ? 'legal' : 'physical');
      const inn = row.inn || row['ИНН'];

      if (!name || !phone) {
        errors.push(`Строка ${idx + 1}: пропущено имя или телефон`);
        skipped++;
        return;
      }

      // Check duplicate phone or INN
      const exists = db.customers.some(
        (c) => c.tenantId === tenantId && (c.phone.replace(/\D/g, '') === String(phone).replace(/\D/g, '') || (inn && c.inn === String(inn)))
      );

      if (exists) {
        errors.push(`Строка ${idx + 1}: клиент "${name}" (тел ${phone}) уже существует в базе`);
        skipped++;
        return;
      }

      const newCust: Customer = {
        id: `cust-imp-${Date.now()}-${idx}`,
        tenantId,
        type: type === 'legal' ? 'legal' : 'physical',
        name: String(name).trim(),
        phone: String(phone).trim(),
        inn: inn ? String(inn).trim() : undefined,
        email: row.email || row['Email'] || undefined,
        comment: `Импортировано из Excel (${new Date().toLocaleDateString('ru-RU')})`,
        createdAt: new Date().toISOString(),
        updatedAt: new Date().toISOString()
      };

      db.customers.unshift(newCust);
      imported++;
    });

    store.save();

    store.logAudit({
      tenantId,
      userId,
      userName,
      userRole,
      entity: 'customer',
      action: 'import_excel',
      details: `Импорт клиентов из Excel: успешно импортировано ${imported}, пропущено ${skipped}`,
      newValue: `Ошибки: ${errors.join('; ')}`,
      ipAddress: req.ip || '127.0.0.1',
      deviceInfo: req.headers['user-agent'] || 'Browser'
    });

    res.json({ imported, skipped, errors, customers: db.customers });
  });

  // Vehicles
  app.get('/api/vehicles', (req, res) => {
    res.json(store.get().vehicles);
  });

  app.post('/api/vehicles', (req, res) => {
    const { vehicle, userId, userName, userRole } = req.body;
    const db = store.get();

    const newVehicle: Vehicle = {
      ...vehicle,
      id: `veh-${Date.now()}`,
      createdAt: new Date().toISOString()
    };

    db.vehicles.unshift(newVehicle);
    store.save();

    store.logAudit({
      tenantId: newVehicle.tenantId,
      userId,
      userName,
      userRole,
      entity: 'vehicle',
      entityId: newVehicle.id,
      action: 'create_vehicle',
      details: `Добавлен автомобиль ${newVehicle.make} ${newVehicle.model} (Госномер: ${newVehicle.licensePlate || 'без номера'})`,
      ipAddress: req.ip || '127.0.0.1',
      deviceInfo: req.headers['user-agent'] || 'Browser'
    });

    res.json(newVehicle);
  });

  // Stock Transfer
  app.post('/api/stock/transfer', (req, res) => {
    const { tenantId, productId, sourceWarehouseId, targetWarehouseId, qty, reason, userId, userName, userRole } = req.body;
    const db = store.get();

    if (sourceWarehouseId === targetWarehouseId) {
      return res.status(400).json({ error: 'Склад-источник и склад-получатель совпадают' });
    }

    const sourceStock = db.stock.find((s) => s.warehouseId === sourceWarehouseId && s.productId === productId);
    if (!sourceStock || sourceStock.availableQty < qty) {
      return res.status(400).json({ error: 'Недостаточно доступного остатка на складе-источнике' });
    }

    // Deduct source
    sourceStock.actualQty -= qty;
    sourceStock.availableQty = sourceStock.actualQty - sourceStock.reservedQty;
    sourceStock.lastUpdated = new Date().toISOString();
    sourceStock.lastSource = 'transfer';

    // Add target
    let targetStock = db.stock.find((s) => s.warehouseId === targetWarehouseId && s.productId === productId);
    if (!targetStock) {
      targetStock = {
        id: `st-${Date.now()}`,
        warehouseId: targetWarehouseId,
        productId,
        actualQty: qty,
        reservedQty: 0,
        availableQty: qty,
        price: sourceStock.price,
        lastUpdated: new Date().toISOString(),
        lastSource: 'transfer'
      };
      db.stock.push(targetStock);
    } else {
      targetStock.actualQty += qty;
      targetStock.availableQty = targetStock.actualQty - targetStock.reservedQty;
      targetStock.lastUpdated = new Date().toISOString();
      targetStock.lastSource = 'transfer';
    }

    const product = db.products.find((p) => p.id === productId);
    store.save();

    store.logAudit({
      tenantId,
      userId,
      userName,
      userRole,
      entity: 'stock',
      entityId: productId,
      action: 'transfer_stock',
      details: `Перемещение товара "${product?.name || productId}" (${qty} шт) со склада ${sourceWarehouseId} на склад ${targetWarehouseId}`,
      reason,
      ipAddress: req.ip || '127.0.0.1',
      deviceInfo: req.headers['user-agent'] || 'Browser'
    });

    res.json({ success: true, stock: db.stock });
  });

  // 1C CommerceML XML Stock Import Simulator
  app.post('/api/stock/import-1c', (req, res) => {
    const { xmlContent, tenantId, warehouseId, userId, userName, userRole } = req.body;
    const db = store.get();

    // Parse simple CommerceML2 simulated items or XML structure
    let updatedCount = 0;
    const conflicts: string[] = [];

    // Simulate batch stock update from 1C export
    db.stock.forEach((st) => {
      if (st.warehouseId === warehouseId) {
        // Simulate minor stock refresh
        st.lastSource = '1c_import';
        st.lastUpdated = new Date().toISOString();
        updatedCount++;

        // Check conflicts
        if (st.actualQty < st.reservedQty) {
          const prod = db.products.find((p) => p.id === st.productId);
          conflicts.push(`Конфликт: по номенклатуре "${prod?.name}" фактический остаток 1С (${st.actualQty}) меньше активного резерва (${st.reservedQty})`);
        }
      }
    });

    store.save();

    store.logAudit({
      tenantId,
      userId,
      userName,
      userRole,
      entity: 'stock',
      action: '1c_import',
      details: `Загрузка остатков CommerceML2 из 1С для склада ${warehouseId}. Обновлено позиций: ${updatedCount}`,
      newValue: conflicts.length > 0 ? conflicts.join('; ') : 'Конфликтов не обнаружено',
      ipAddress: req.ip || '127.0.0.1',
      deviceInfo: req.headers['user-agent'] || 'Browser'
    });

    res.json({ updatedCount, conflicts, stock: db.stock });
  });

  // Orders: Create & Manage
  app.get('/api/orders', (req, res) => {
    res.json(store.get().orders);
  });

  app.post('/api/orders', (req, res) => {
    const { order, userId, userName, userRole } = req.body;
    const db = store.get();

    const orderNum = `ЛСТ-2026-${String(db.orders.length + 1).padStart(3, '0')}`;

    const newOrder: Order = {
      ...order,
      id: `ord-${Date.now()}`,
      orderNumber: orderNum,
      createdAt: new Date().toISOString(),
      updatedAt: new Date().toISOString()
    };

    db.orders.unshift(newOrder);
    store.recalculateReservations();

    store.logAudit({
      tenantId: newOrder.tenantId,
      locationId: newOrder.locationId,
      userId,
      userName,
      userRole,
      entity: 'order',
      entityId: newOrder.id,
      action: 'create_order',
      details: `Создан заказ ${newOrder.orderNumber} (${newOrder.scenario === 'with_installation' ? 'Продажа с установкой' : 'Продажа без установки'}) для ${newOrder.customerName}`,
      newValue: `Сумма: ${newOrder.totalAmount} руб`,
      ipAddress: req.ip || '127.0.0.1',
      deviceInfo: req.headers['user-agent'] || 'Browser'
    });

    res.json(newOrder);
  });

  // Order Item Release (Выдача товара)
  app.post('/api/orders/:id/release', (req, res) => {
    const { id } = req.params;
    const { itemId, userId, userName, userRole } = req.body;
    const db = store.get();

    const order = db.orders.find((o) => o.id === id);
    if (!order) return res.status(404).json({ error: 'Заказ не найден' });

    const item = order.items.find((i) => i.id === itemId);
    if (!item) return res.status(404).json({ error: 'Позиция заказа не найдена' });

    item.status = 'released';
    item.releasedAt = new Date().toISOString();
    item.releasedByUserId = userId;

    // Deduct actual stock upon release
    if (item.itemType === 'product' && item.productId && item.warehouseId) {
      const stockItem = db.stock.find((s) => s.warehouseId === item.warehouseId && s.productId === item.productId);
      if (stockItem) {
        stockItem.actualQty = Math.max(0, stockItem.actualQty - item.qty);
      }
    }

    order.updatedAt = new Date().toISOString();

    // Check if all items released
    const allReleased = order.items.every((i) => i.status === 'released' || i.itemType === 'service');
    if (allReleased && order.status === 'ready_for_release') {
      order.status = 'released';
    }

    store.recalculateReservations();

    store.logAudit({
      tenantId: order.tenantId,
      locationId: order.locationId,
      userId,
      userName,
      userRole,
      entity: 'order',
      entityId: order.id,
      action: 'release_item',
      details: `Выдача товара "${item.name}" (${item.qty} шт) по заказу ${order.orderNumber} выполнена сотрудником ${userName}`,
      ipAddress: req.ip || '127.0.0.1',
      deviceInfo: req.headers['user-agent'] || 'Browser'
    });

    res.json(order);
  });

  // Order Cancel
  app.post('/api/orders/:id/cancel', (req, res) => {
    const { id } = req.params;
    const { reason, userId, userName, userRole } = req.body;
    const db = store.get();

    const order = db.orders.find((o) => o.id === id);
    if (!order) return res.status(404).json({ error: 'Заказ не найден' });

    if (!reason) {
      return res.status(400).json({ error: 'Причина отмены обязательна' });
    }

    order.status = 'cancelled';
    order.cancelReason = reason;
    order.items.forEach((i) => (i.status = 'cancelled'));
    order.updatedAt = new Date().toISOString();

    store.recalculateReservations();

    store.logAudit({
      tenantId: order.tenantId,
      locationId: order.locationId,
      userId,
      userName,
      userRole,
      entity: 'order',
      entityId: order.id,
      action: 'cancel_order',
      details: `Отмена заказа ${order.orderNumber}. Причина: ${reason}`,
      reason,
      ipAddress: req.ip || '127.0.0.1',
      deviceInfo: req.headers['user-agent'] || 'Browser'
    });

    res.json(order);
  });

  // Mark Important Comment as Read
  app.post('/api/orders/:id/comment-read', (req, res) => {
    const { id } = req.params;
    const { userId } = req.body;
    const db = store.get();

    const order = db.orders.find((o) => o.id === id);
    if (!order) return res.status(404).json({ error: 'Заказ не найден' });

    if (!order.importantCommentReadBy) {
      order.importantCommentReadBy = [];
    }

    if (!order.importantCommentReadBy.some((r) => r.userId === userId)) {
      order.importantCommentReadBy.push({
        userId,
        readAt: new Date().toISOString()
      });
      store.save();
    }

    res.json({ success: true, importantCommentReadBy: order.importantCommentReadBy });
  });

  // Payments
  app.post('/api/payments', (req, res) => {
    const { payment, userId, userName, userRole } = req.body;
    const db = store.get();

    const newPayment: PaymentRecord = {
      ...payment,
      id: `pay-${Date.now()}`,
      status: 'completed',
      createdAt: new Date().toISOString()
    };

    db.payments.unshift(newPayment);

    // Update order payment status
    const order = db.orders.find((o) => o.id === newPayment.orderId);
    if (order) {
      order.paidAmount += newPayment.amount;
      order.dueAmount = Math.max(0, order.totalAmount - order.paidAmount);
      order.paymentStatus = order.dueAmount === 0 ? 'paid' : 'partially_paid';
      order.updatedAt = new Date().toISOString();

      // Create KPI records for order items if fully paid
      if (order.paymentStatus === 'paid') {
        order.items.forEach((item) => {
          const empId = item.itemType === 'product' ? item.responsibleSellerId || order.responsibleSellerId : item.masterExecutorId || order.masterExecutorId;
          const empName = empId === order.responsibleSellerId ? order.responsibleSellerName : order.masterExecutorName || userName;

          if (empId) {
            db.kpiRecords.unshift({
              id: `kpi-${Date.now()}-${Math.floor(Math.random() * 1000)}`,
              tenantId: order.tenantId,
              employeeId: empId,
              employeeName: empName,
              employeeRole: item.itemType === 'product' ? 'manager' : 'master',
              orderId: order.id,
              orderNumber: order.orderNumber,
              itemType: item.itemType,
              itemName: item.name,
              saleAmount: item.totalSum,
              commissionPercent: item.kpiRulePercent,
              kpiEarned: item.kpiAmount,
              status: 'approved',
              createdAt: new Date().toISOString()
            });
          }
        });
      }
    }

    // Update cash shift totals
    const shift = db.shifts.find((s) => s.id === newPayment.shiftId);
    if (shift && shift.status === 'open') {
      if (newPayment.method === 'cash') shift.cashInflow += newPayment.amount;
      else if (newPayment.method === 'card') shift.cardInflow += newPayment.amount;
      else if (newPayment.method === 'transfer') shift.transferInflow += newPayment.amount;
      else if (newPayment.method === 'account') shift.accountInflow += newPayment.amount;
      else if (newPayment.method === 'mixed') shift.cashInflow += newPayment.amount; // mixed
      shift.totalInflow += newPayment.amount;
    }

    store.save();

    store.logAudit({
      tenantId: newPayment.tenantId,
      locationId: newPayment.locationId,
      userId,
      userName,
      userRole,
      entity: 'payment',
      entityId: newPayment.id,
      action: 'accept_payment',
      details: `Принята оплата ${newPayment.amount} руб (Способ: ${newPayment.method}, Получатель: ${newPayment.recipientName}) по заказу ${newPayment.orderNumber}`,
      newValue: `Сумма: ${newPayment.amount} руб`,
      ipAddress: req.ip || '127.0.0.1',
      deviceInfo: req.headers['user-agent'] || 'Browser'
    });

    res.json({ payment: newPayment, order });
  });

  // Payment Correction
  app.post('/api/payments/correct', (req, res) => {
    const { paymentId, newAmount, newMethod, newRecipientId, reason, userId, userName, userRole } = req.body;
    const db = store.get();

    const payment = db.payments.find((p) => p.id === paymentId);
    if (!payment) return res.status(404).json({ error: 'Оплата не найдена' });

    if (!reason) return res.status(400).json({ error: 'Укажите причину корректировки' });

    const beforeVal = `Сумма: ${payment.amount}, Способ: ${payment.method}, Получатель: ${payment.recipientName}`;

    if (newAmount !== undefined) payment.amount = newAmount;
    if (newMethod !== undefined) payment.method = newMethod;
    if (newRecipientId) {
      const rec = db.recipients.find((r) => r.id === newRecipientId);
      if (rec) {
        payment.recipientId = rec.id;
        payment.recipientName = rec.name;
      }
    }
    payment.status = 'adjusted';

    const afterVal = `Сумма: ${payment.amount}, Способ: ${payment.method}, Получатель: ${payment.recipientName}`;

    // Add Correction Audit Record
    const correction: CorrectionRecord = {
      id: `corr-${Date.now()}`,
      tenantId: payment.tenantId,
      type: 'payment',
      targetId: paymentId,
      relatedOrderId: payment.orderId,
      operatorUserId: userId,
      operatorUserName: userName,
      beforeValue: beforeVal,
      afterValue: afterVal,
      reason,
      createdAt: new Date().toISOString()
    };

    db.corrections.unshift(correction);
    store.save();

    store.logAudit({
      tenantId: payment.tenantId,
      locationId: payment.locationId,
      userId,
      userName,
      userRole,
      entity: 'payment',
      entityId: paymentId,
      action: 'correct_payment',
      details: `Корректировка оплаты по заказу ${payment.orderNumber}. Было: ${beforeVal}. Стало: ${afterVal}`,
      oldValue: beforeVal,
      newValue: afterVal,
      reason,
      ipAddress: req.ip || '127.0.0.1',
      deviceInfo: req.headers['user-agent'] || 'Browser'
    });

    res.json({ payment, correction });
  });

  // Cash Shifts
  app.post('/api/shifts/open', (req, res) => {
    const { tenantId, locationId, locationName, openingBalance, userId, userName, userRole } = req.body;
    const db = store.get();

    const activeShift = db.shifts.find((s) => s.locationId === locationId && s.status === 'open');
    if (activeShift) {
      return res.status(400).json({ error: 'В данной точке уже есть открытая смена' });
    }

    const newShift: CashShift = {
      id: `shift-${Date.now()}`,
      tenantId,
      locationId,
      locationName,
      cashierUserId: userId,
      cashierUserName: userName,
      openedAt: new Date().toISOString(),
      status: 'open',
      openingBalance: openingBalance || 0,
      cashInflow: 0,
      cardInflow: 0,
      transferInflow: 0,
      accountInflow: 0,
      totalInflow: 0,
      encashmentTotal: 0,
      withdrawalTotal: 0
    };

    db.shifts.unshift(newShift);
    store.save();

    store.logAudit({
      tenantId,
      locationId,
      userId,
      userName,
      userRole,
      entity: 'shift',
      entityId: newShift.id,
      action: 'open_shift',
      details: `Открыта кассовая смена #${newShift.id} в точке "${locationName}". Начальный остаток: ${openingBalance} руб`,
      ipAddress: req.ip || '127.0.0.1',
      deviceInfo: req.headers['user-agent'] || 'Browser'
    });

    res.json(newShift);
  });

  app.post('/api/shifts/close', (req, res) => {
    const { shiftId, closingCashActual, notes, userId, userName, userRole } = req.body;
    const db = store.get();

    const shift = db.shifts.find((s) => s.id === shiftId);
    if (!shift) return res.status(404).json({ error: 'Смена не найдена' });

    shift.status = 'closed';
    shift.closedAt = new Date().toISOString();
    shift.closingCashActual = closingCashActual;
    const expectedCash = shift.openingBalance + shift.cashInflow - shift.encashmentTotal - shift.withdrawalTotal;
    shift.discrepancy = closingCashActual - expectedCash;
    shift.notes = notes;

    store.save();

    store.logAudit({
      tenantId: shift.tenantId,
      locationId: shift.locationId,
      userId,
      userName,
      userRole,
      entity: 'shift',
      entityId: shiftId,
      action: 'close_shift',
      details: `Закрыта кассовая смена #${shiftId}. Итог выручки: ${shift.totalInflow} руб. Фактическая касса: ${closingCashActual} руб. Расхождение: ${shift.discrepancy} руб`,
      oldValue: `Открыта: ${shift.openedAt}`,
      newValue: `Закрыта: ${shift.closedAt}`,
      ipAddress: req.ip || '127.0.0.1',
      deviceInfo: req.headers['user-agent'] || 'Browser'
    });

    res.json(shift);
  });

  app.post('/api/shifts/cash-movement', (req, res) => {
    const { shiftId, type, amount, reason, userId, userName, userRole } = req.body;
    const db = store.get();

    const shift = db.shifts.find((s) => s.id === shiftId);
    if (!shift || shift.status !== 'open') return res.status(400).json({ error: 'Смена закрыта или не найдена' });

    if (type === 'encashment') shift.encashmentTotal += amount;
    else if (type === 'withdrawal') shift.withdrawalTotal += amount;

    store.save();

    store.logAudit({
      tenantId: shift.tenantId,
      locationId: shift.locationId,
      userId,
      userName,
      userRole,
      entity: 'shift',
      entityId: shiftId,
      action: type === 'encashment' ? 'encashment' : 'withdrawal',
      details: `${type === 'encashment' ? 'Инкассация' : 'Выемка денег'} на сумму ${amount} руб. Причина: ${reason}`,
      reason,
      ipAddress: req.ip || '127.0.0.1',
      deviceInfo: req.headers['user-agent'] || 'Browser'
    });

    res.json(shift);
  });

  // Audit Annotations
  app.post('/api/audit/:id/annotate', (req, res) => {
    const { id } = req.params;
    const { text, category, userId, userName } = req.body;
    const db = store.get();

    const log = db.auditLogs.find((l) => l.id === id);
    if (!log) return res.status(404).json({ error: 'Запись аудита не найдена' });

    if (!log.annotations) log.annotations = [];
    const annotation = {
      id: `ann-${Date.now()}`,
      userId,
      userName,
      text,
      category: category || 'approved',
      createdAt: new Date().toISOString()
    };
    log.annotations.push(annotation);
    store.save();

    res.json({ success: true, annotation });
  });

  // Tasks
  app.post('/api/tasks', (req, res) => {
    const { task, userId, userName, userRole } = req.body;
    const db = store.get();

    const newTask: Task = {
      ...task,
      id: `task-${Date.now()}`,
      status: 'pending',
      creatorUserId: userId,
      creatorUserName: userName,
      createdAt: new Date().toISOString()
    };

    db.tasks.unshift(newTask);
    store.save();

    store.logAudit({
      tenantId: newTask.tenantId,
      userId,
      userName,
      userRole,
      entity: 'task',
      entityId: newTask.id,
      action: 'create_task',
      details: `Создана задача "${newTask.title}" (Назначена: ${newTask.assignedToUserName || 'Отдел'})`,
      ipAddress: req.ip || '127.0.0.1',
      deviceInfo: req.headers['user-agent'] || 'Browser'
    });

    res.json(newTask);
  });

  app.put('/api/tasks/:id', (req, res) => {
    const { id } = req.params;
    const { status, cancelReason, userId, userName, userRole } = req.body;
    const db = store.get();

    const task = db.tasks.find((t) => t.id === id);
    if (!task) return res.status(404).json({ error: 'Задача не найдена' });

    task.status = status;
    if (cancelReason) task.cancelReason = cancelReason;
    if (status === 'completed') task.completedAt = new Date().toISOString();

    store.save();

    store.logAudit({
      tenantId: task.tenantId,
      userId,
      userName,
      userRole,
      entity: 'task',
      entityId: id,
      action: `update_task_${status}`,
      details: `Задача "${task.title}" переведена в статус "${status}"`,
      reason: cancelReason,
      ipAddress: req.ip || '127.0.0.1',
      deviceInfo: req.headers['user-agent'] || 'Browser'
    });

    res.json(task);
  });

  // Module Toggle
  app.post('/api/modules/toggle', (req, res) => {
    const { code, status, userId, userName, userRole } = req.body;
    const db = store.get();

    const mod = db.modules.find((m) => m.code === code);
    if (!mod) return res.status(404).json({ error: 'Модуль не найден' });

    mod.status = status;
    if (status === 'active') mod.installedAt = new Date().toISOString();

    store.save();

    store.logAudit({
      tenantId: 'tenant-1',
      userId,
      userName,
      userRole,
      entity: 'module',
      entityId: mod.id,
      action: status === 'active' ? 'enable_module' : 'disable_module',
      details: `${status === 'active' ? 'Включен' : 'Отключен'} модуль "${mod.name}"`,
      ipAddress: req.ip || '127.0.0.1',
      deviceInfo: req.headers['user-agent'] || 'Browser'
    });

    res.json(mod);
  });

  // Public TV Queue Display
  app.get('/api/tv-queue', (req, res) => {
    const db = store.get();
    const activeOrders = db.orders
      .filter((o) => o.status !== 'completed' && o.status !== 'cancelled')
      .map((o) => ({
        id: o.id,
        orderNumber: o.orderNumber,
        vehicleInfo: o.vehicleInfo || 'Автo',
        scenario: o.scenario,
        status: o.status,
        masterExecutorName: o.masterExecutorName,
        createdAt: o.createdAt
      }));
    res.json(activeOrders);
  });

  // Vite Middleware for development
  if (process.env.NODE_ENV !== 'production') {
    const vite = await createViteServer({
      server: { middlewareMode: true },
      appType: 'spa'
    });
    app.use(vite.middlewares);
  } else {
    const distPath = path.join(process.cwd(), 'dist');
    app.use(express.static(distPath));
    app.get('*', (req, res) => {
      res.sendFile(path.join(distPath, 'index.html'));
    });
  }

  app.listen(PORT, '0.0.0.0', () => {
    console.log(`AUTOMETRIA API (legacy Express) on http://0.0.0.0:${PORT}`);
    console.log('UI: run `npm run dev` → http://127.0.0.1:5178 (Vue Autometria DS)');
  });
}

startServer();
