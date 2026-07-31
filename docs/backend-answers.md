# LASTIK Backend — ответы по 6 Check-in задачам

Документ построен по системному промпту `backend.md` и `backend-checkin.md`. Все фрагменты ориентированы на Laravel 13, PHP 8.3, PostgreSQL, single-database multi-tenancy.

---

## Задача 1 — Миграции FIRST STAGE

### Соглашения
- Везде формат денег: `decimal(12,2)`.
- JSONB только там, где явно указано в доменных контрактах.
- Каждая tenant-scoped сущность содержит `tenant_id` и индекс по `(tenant_id, ...)`.
- RLS применяется через политики PostgreSQL; миграции содержат `tenant_id`.

### Миграции

```sql
-- tenants
create table tenants (
    id          bigserial primary key,
    slug        varchar(100) not null unique,
    name        varchar(255) not null,
    timezone     varchar(100) default 'UTC',
    is_active   boolean default true,
    created_at  timestamptz not null default now(),
    updated_at  timestamptz not null default now()
);

create index tenants_slug_idx on tenants(slug);


-- locations
create table locations (
    id          bigserial primary key,
    tenant_id   bigint not null references tenants(id) on delete cascade,
    name        varchar(255) not null,
    timezone     varchar(100) default 'UTC',
    is_active   boolean default true,
    created_at  timestamptz not null default now(),
    updated_at  timestamptz not null default now()
);

create index locations_tenant_id_idx on locations(tenant_id);


-- roles
create table roles (
    id          bigserial primary key,
    tenant_id   bigint not null references tenants(id) on delete cascade,
    slug        varchar(100) not null,
    name        varchar(255) not null,
    permissions jsonb not null default '{}'::jsonb,
    created_at  timestamptz not null default now(),
    updated_at  timestamptz not null default now()
);

create unique index roles_tenant_slug_idx on roles(tenant_id, slug);


-- permissions
create table permissions (
    id          bigserial primary key,
    tenant_id   bigint not null references tenants(id) on delete cascade,
    slug        varchar(100) not null,
    section     varchar(100) not null,
    action      varchar(100) not null,
    created_at  timestamptz not null default now()
);

create unique index permissions_tenant_slug_idx on permissions(tenant_id, slug);


-- users
create table users (
    id                bigserial primary key,
    tenant_id         bigint not null references tenants(id) on delete cascade,
    location_id       bigint references locations(id) on delete set null,
    role_id           bigint not null references roles(id),
    name              varchar(255) not null,
    email             varchar(255) not null,
    email_verified_at timestamptz,
    password_hash     varchar(255) not null,
    two_factor_secret varchar(255),
    devices_limit     int not null default 2,
    last_login_at     timestamptz,
    is_active         boolean default true,
    created_at        timestamptz not null default now(),
    updated_at        timestamptz not null default now()
);

create index users_tenant_id_idx on users(tenant_id);
create unique index users_tenant_email_idx on users(tenant_id, email);


-- customers
create table customers (
    id          bigserial primary key,
    tenant_id   bigint not null references tenants(id) on delete cascade,
    type        varchar(20) not null check (type in ('individual','legal')),
    phone       varchar(50) not null,
    email       varchar(255),
    inn         varchar(20),
    kpp         varchar(20),
    legal_name  varchar(255),
    created_at  timestamptz not null default now(),
    updated_at  timestamptz not null default now()
);

create index customers_tenant_id_idx on customers(tenant_id);


-- vehicles
create table vehicles (
    id          bigserial primary key,
    tenant_id   bigint not null references tenants(id) on delete cascade,
    customer_id bigint not null references customers(id) on delete cascade,
    plate       varchar(20) not null,
    vin         varchar(32),
    brand       varchar(100),
    model       varchar(100),
    created_at  timestamptz not null default now(),
    updated_at  timestamptz not null default now()
);

create index vehicles_tenant_id_idx on vehicles(tenant_id);
create unique index vehicles_tenant_plate_idx on vehicles(tenant_id, plate);


-- products_services
create table products_services (
    id          bigserial primary key,
    tenant_id   bigint not null references tenants(id) on delete cascade,
    type        varchar(20) not null check (type in ('product','service')),
    article     varchar(100) not null,
    external_id varchar(100),
    name        varchar(255) not null,
    is_active   boolean default true,
    created_at  timestamptz not null default now(),
    updated_at  timestamptz not null default now()
);

create index products_services_tenant_id_idx on products_services(tenant_id);
create unique index products_services_tenant_article_idx on products_services(tenant_id, article);


-- warehouses
create table warehouses (
    id          bigserial primary key,
    tenant_id   bigint not null references tenants(id) on delete cascade,
    location_id bigint not null references locations(id) on delete cascade,
    name        varchar(255) not null,
    created_at  timestamptz not null default now(),
    updated_at  timestamptz not null default now()
);

create index warehouses_tenant_id_idx on warehouses(tenant_id);


-- stocks
create table stocks (
    id          bigserial primary key,
    tenant_id   bigint not null references tenants(id) on delete cascade,
    warehouse_id bigint not null references warehouses(id) on delete cascade,
    product_id  bigint not null references products_services(id) on delete cascade,
    actual      numeric(12,2) not null default 0,
    reserved    numeric(12,2) not null default 0,
    available   numeric(12,2) not null default 0,
    created_at  timestamptz not null default now(),
    updated_at  timestamptz not null default now()
);

create unique index stocks_warehouse_product_idx on stocks(warehouse_id, product_id);
create index stocks_tenant_id_idx on stocks(tenant_id);


-- prices
create table prices (
    id          bigserial primary key,
    product_id  bigint not null references products_services(id) on delete cascade,
    tenant_id   bigint not null references tenants(id) on delete cascade,
    type        varchar(50) not null,
    amount      decimal(12,2) not null,
    created_at  timestamptz not null default now(),
    updated_at  timestamptz not null default now()
);

create unique index prices_tenant_product_type_idx on prices(tenant_id, product_id, type);


-- orders
create table orders (
    id                bigserial primary key,
    tenant_id         bigint not null references tenants(id) on delete cascade,
    location_id       bigint not null references locations(id) on delete cascade,
    customer_id       bigint not null references customers(id) on delete cascade,
    vehicle_id        bigint not null references vehicles(id) on delete cascade,
    scenario          varchar(100) not null,
    number            varchar(100) not null,
    status            varchar(100) not null,
    payment_status    varchar(100) not null,
    shift_id          bigint,
    assigned_seller_id bigint references users(id),
    master_id         bigint references users(id),
    total             decimal(12,2) not null default 0,
    created_by        bigint not null references users(id),
    locked_at         timestamptz,
    created_at        timestamptz not null default now(),
    updated_at        timestamptz not null default now()
);

create index orders_tenant_id_idx on orders(tenant_id);
create unique index orders_tenant_number_idx on orders(tenant_id, number);


-- order_items
create table order_items (
    id             bigserial primary key,
    order_id       bigint not null references orders(id) on delete cascade,
    type           varchar(50) not null,
    product_id     bigint references products_services(id) on delete set null,
    snapshot       jsonb not null,
    qty            numeric(12,3) not null,
    price          decimal(12,2) not null,
    discount       decimal(12,2) not null default 0,
    kpi_percent    numeric(6,3),
    kpi_amount     decimal(12,2) not null default 0,
    created_at    timestamptz not null default now()
);

create index order_items_order_id_idx on order_items(order_id);


-- reservations
create table reservations (
    id         bigserial primary key,
    tenant_id  bigint not null references tenants(id) on delete cascade,
    order_item_id bigint not null references order_items(id) on delete cascade,
    stock_id   bigint not null references stocks(id) on delete cascade,
    qty        numeric(12,3) not null,
    status     varchar(50) not null default 'active' check (status in ('active','released','used','cancelled','conflict')),
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now()
);

create index reservations_tenant_id_idx on reservations(tenant_id);
create index reservations_order_item_id_idx on reservations(order_item_id);


-- stock_conflicts
create table stock_conflicts (
    id           bigserial primary key,
    tenant_id    bigint not null references tenants(id) on delete cascade,
    stock_id     bigint not null references stocks(id) on delete cascade,
    import_job_id bigint,
    message      text not null,
    payload      jsonb not null default '{}'::jsonb,
    resolved     boolean default false,
    created_at   timestamptz not null default now()
);

create index stock_conflicts_tenant_id_idx on stock_conflicts(tenant_id);


-- payments
create table payments (
    id          bigserial primary key,
    tenant_id   bigint not null references tenants(id) on delete cascade,
    order_id    bigint not null references orders(id) on delete cascade,
    shift_id    bigint references cash_shifts(id),
    method      varchar(100) not null,
    type        varchar(50) not null,
    amount      decimal(12,2) not null,
    status      varchar(50) not null,
    payee_id    bigint references users(id),
    created_by  bigint not null references users(id),
    created_at  timestamptz not null default now()
);

create index payments_tenant_id_idx on payments(tenant_id);


-- payment_corrections
create table payment_corrections (
    id             bigserial primary key,
    tenant_id      bigint not null references tenants(id) on delete cascade,
    payment_id     bigint not null references payments(id) on delete cascade,
    old_amount     decimal(12,2) not null,
    new_amount     decimal(12,2) not null,
    reason         varchar(255) not null,
    created_by    bigint not null references users(id),
    created_at    timestamptz not null default now()
);

create index payment_corrections_tenant_id_idx on payment_corrections(tenant_id);


-- issuances
create table issuances (
    id          bigserial primary key,
    tenant_id   bigint not null references tenants(id) on delete cascade,
    order_id    bigint not null references orders(id) on delete cascade,
    item_id     bigint not null references order_items(id) on delete cascade,
    qty         numeric(12,3) not null,
    issued_by   bigint not null references users(id),
    issued_at   timestamptz not null default now()
);

create index issuances_tenant_id_idx on issuances(tenant_id);


-- cash_shifts
create table cash_shifts (
    id          bigserial primary key,
    tenant_id   bigint not null references tenants(id) on delete cascade,
    location_id bigint not null references locations(id) on delete cascade,
    user_id     bigint not null references users(id),
    opened_at   timestamptz not null default now(),
    closed_at   timestamptz,
    totals      jsonb not null default '{}'::jsonb,
    created_at  timestamptz not null default now(),
    updated_at  timestamptz not null default now()
);

create index cash_shifts_tenant_id_idx on cash_shifts(tenant_id);


-- cash_movements
create table cash_movements (
    id          bigserial primary key,
    tenant_id   bigint not null references tenants(id) on delete cascade,
    shift_id    bigint not null references cash_shifts(id) on delete cascade,
    type        varchar(50) not null check (type in ('inkasso','withdrawal','adjustment')),
    amount      decimal(12,2) not null,
    payee_id    bigint references users(id),
    reason      varchar(255),
    created_by  bigint not null references users(id),
    created_at  timestamptz not null default now()
);

create index cash_movements_tenant_id_idx on cash_movements(tenant_id);


-- kpi_rules
create table kpi_rules (
    id            bigserial primary key,
    tenant_id     bigint not null references tenants(id) on delete cascade,
    applies_to    varchar(50) not null check (applies_to in ('order','item')),
    role_id       bigint references roles(id),
    percent       numeric(6,3) not null,
    amount        decimal(12,2) not null default 0,
    is_active     boolean default true,
    valid_from    timestamptz,
    valid_to      timestamptz,
    created_at    timestamptz not null default now(),
    updated_at    timestamptz not null default now()
);

create index kpi_rules_tenant_id_idx on kpi_rules(tenant_id);


-- earnings
create table earnings (
    id            bigserial primary key,
    tenant_id     bigint not null references tenants(id) on delete cascade,
    order_id      bigint not null references orders(id) on delete cascade,
    user_id       bigint not null references users(id),
    amount        decimal(12,2) not null,
    rule_snapshot jsonb not null,
    source        varchar(50) not null check (source in ('order','item')),
    created_at    timestamptz not null default now()
);

create index earnings_tenant_id_idx on earnings(tenant_id);


-- audit_logs
create table audit_logs (
    id            bigserial primary key,
    tenant_id     bigint not null references tenants(id) on delete cascade,
    user_id       bigint,
    action        varchar(100) not null,
    object_type   varchar(100) not null,
    object_id     bigint,
    old           jsonb,
    new           jsonb,
    metadata      jsonb not null default '{}'::jsonb,
    ip            varchar(45),
    user_agent    varchar(255),
    reason        varchar(255),
    created_at    timestamptz not null default now()
);

create index audit_logs_tenant_id_idx on audit_logs(tenant_id);
create index audit_logs_object_idx on audit_logs(object_type, object_id);


-- import_jobs
create table import_jobs (
    id            bigserial primary key,
    tenant_id     bigint not null references tenants(id) on delete cascade,
    source        varchar(100) not null check (source in ('commerceml2','excel_customers')),
    status        varchar(100) not null,
    summary       jsonb not null default '{}'::jsonb,
    created_at    timestamptz not null default now(),
    updated_at    timestamptz not null default now()
);

create index import_jobs_tenant_id_idx on import_jobs(tenant_id);


-- modules
create table modules (
    id           bigserial primary key,
    tenant_id    bigint not null references tenants(id) on delete cascade,
    slug         varchar(100) not null,
    status       varchar(100) not null default 'available' check (status in ('available','active','disabled','blocked')),
    enabled_at   timestamptz,
    disabled_at  timestamptz,
    settings     jsonb not null default '{}'::jsonb,
    created_at   timestamptz not null default now(),
    updated_at   timestamptz not null default now()
);

create unique index modules_tenant_slug_idx on modules(tenant_id, slug);


-- settings
create table settings (
    id         bigserial primary key,
    tenant_id  bigint not null references tenants(id) on delete cascade,
    group      varchar(100) not null,
    key        varchar(100) not null,
    value      jsonb not null default '{}'::jsonb,
    scope      varchar(50) not null default 'global' check (scope in ('global','location','role','user')),
    ref_id     bigint,
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now()
);

create unique index settings_tenant_scope_key_ref_idx on settings(tenant_id, scope, key, ref_id);
create index settings_tenant_id_idx on settings(tenant_id);
```

### RLS

```sql
alter table locations enable row level security;
create policy tenant_isolation_locations on locations
    using (tenant_id = current_setting('app.current_tenant_id')::bigint);

alter table users enable row level security;
create policy tenant_isolation_users on users
    using (tenant_id = current_setting('app.current_tenant_id')::bigint);

alter table roles enable row level security;
create policy tenant_isolation_roles on roles
    using (tenant_id = current_setting('app.current_tenant_id')::bigint);

alter table permissions enable row level security;
create policy tenant_isolation_permissions on permissions
    using (tenant_id = current_setting('app.current_tenant_id')::bigint);

alter table customers enable row level security;
create policy tenant_isolation_customers on customers
    using (tenant_id = current_setting('app.current_tenant_id')::bigint);

alter table vehicles enable row level security;
create policy tenant_isolation_vehicles on vehicles
    using (tenant_id = current_setting('app.current_tenant_id')::bigint);

alter table products_services enable row level security;
create policy tenant_isolation_products_services on products_services
    using (tenant_id = current_setting('app.current_tenant_id')::bigint);

alter table warehouses enable row level security;
create policy tenant_isolation_warehouses on warehouses
    using (tenant_id = current_setting('app.current_tenant_id')::bigint);

alter table stocks enable row level security;
create policy tenant_isolation_stocks on stocks
    using (tenant_id = current_setting('app.current_tenant_id')::bigint);

alter table prices enable row level security;
create policy tenant_isolation_prices on prices
    using (tenant_id = current_setting('app.current_tenant_id')::bigint);

alter table orders enable row level security;
create policy tenant_isolation_orders on orders
    using (tenant_id = current_setting('app.current_tenant_id')::bigint);

alter table order_items enable row level security;
create policy tenant_isolation_order_items on order_items
    using (
        exists (
            select 1 from orders o
            where o.id = order_items.order_id
              and o.tenant_id = current_setting('app.current_tenant_id')::bigint
        )
    );

alter table reservations enable row level security;
create policy tenant_isolation_reservations on reservations
    using (tenant_id = current_setting('app.current_tenant_id')::bigint);

alter table payments enable row level security;
create policy tenant_isolation_payments on payments
    using (tenant_id = current_setting('app.current_tenant_id')::bigint);

alter table payment_corrections enable row level security;
create policy tenant_isolation_payment_corrections on payment_corrections
    using (tenant_id = current_setting('app.current_tenant_id')::bigint);

alter table issuances enable row level security;
create policy tenant_isolation_issuances on issuances
    using (tenant_id = current_setting('app.current_tenant_id')::bigint);

alter table cash_shifts enable row level security;
create policy tenant_isolation_cash_shifts on cash_shifts
    using (tenant_id = current_setting('app.current_tenant_id')::bigint);

alter table cash_movements enable row level security;
create policy tenant_isolation_cash_movements on cash_movements
    using (tenant_id = current_setting('app.current_tenant_id')::bigint);

alter table kpi_rules enable row level security;
create policy tenant_isolation_kpi_rules on kpi_rules
    using (tenant_id = current_setting('app.current_tenant_id')::bigint);

alter table earnings enable row level security;
create policy tenant_isolation_earnings on earnings
    using (tenant_id = current_setting('app.current_tenant_id')::bigint);

alter table audit_logs enable row level security;
create policy tenant_isolation_audit_logs on audit_logs
    using (tenant_id = current_setting('app.current_tenant_id')::bigint);

alter table import_jobs enable row level security;
create policy tenant_isolation_import_jobs on import_jobs
    using (tenant_id = current_setting('app.current_tenant_id')::bigint);

alter table modules enable row level security;
create policy tenant_isolation_modules on modules
    using (tenant_id = current_setting('app.current_tenant_id')::bigint);

alter table settings enable row level security;
create policy tenant_isolation_settings on settings
    using (tenant_id = current_setting('app.current_tenant_id')::bigint);

alter table stock_conflicts enable row level security;
create policy tenant_isolation_stock_conflicts on stock_conflicts
    using (tenant_id = current_setting('app.current_tenant_id')::bigint);
```

---

## Задача 2 — Stock + StockReservationService

### Model `Stock`

```php
class Stock extends Model
{
    use HasFactory;

    protected $table = 'stocks';

    protected $fillable = [
        'tenant_id',
        'warehouse_id',
        'product_id',
        'actual',
        'reserved',
        'available',
    ];

    protected $casts = [
        'actual'    => 'decimal:2',
        'reserved'  => 'decimal:2',
        'available' => 'decimal:2',
    ];

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function product(): BelongsTo { return $this->belongsTo(ProductService::class, 'product_id'); }

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $builder->where('stocks.tenant_id', tenant_id());
        });
    }
}
```

### Service `StockReservationService`

```php
class StockReservationService
{
    public function __construct(private StockRepository $stocks) {}

    public function reserve(Stock $stock, int $qty): Reservation
    {
        return DB::transaction(function () use ($stock, $qty) {
            $fresh = $stock->lockForUpdate()->firstOrFail();

            if ($fresh->available - $qty < 0) {
                throw new OutOfStockException('Available cannot be negative');
            }

            $fresh->actual   = $fresh->actual; // unchanged
            $fresh->reserved = round($fresh->reserved + $qty, 2);
            $fresh->available = round($fresh->actual - $fresh->reserved, 2);
            $fresh->save();

            AuditLog::write($fresh->tenant_id, auth()->id(), 'reserve', Stock::class, $fresh->id, [
                'available' => [
                    'old' => $stock->available,
                    'new' => $fresh->available,
                ],
            ]);

            return Reservation::create([
                'tenant_id'     => $fresh->tenant_id,
                'order_item_id' => request('order_item_id'),
                'stock_id'      => $fresh->id,
                'qty'           => $qty,
                'status'        => Reservation::STATUS_ACTIVE,
            ]);
        });
    }

    public function release(Stock $stock, int $qty): Reservation
    {
        return DB::transaction(function () use ($stock, $qty) {
            $fresh = $stock->lockForUpdate()->firstOrFail();

            if ($fresh->reserved - $qty < 0) {
                throw new InvalidReservationException('Reserved cannot be negative');
            }

            $fresh->reserved  = round($fresh->reserved - $qty, 2);
            $fresh->available = round($fresh->actual - $fresh->reserved, 2);
            $fresh->save();

            AuditLog::write($fresh->tenant_id, auth()->id(), 'release', Stock::class, $fresh->id, [
                'available' => [
                    'old' => $stock->available,
                    'new' => $fresh->available,
                ],
            ]);

            return Reservation::create([
                'tenant_id'     => $fresh->tenant_id,
                'order_item_id' => request('order_item_id'),
                'stock_id'      => $fresh->id,
                'qty'           => -$qty,
                'status'        => Reservation::STATUS_RELEASED,
            ]);
        });
    }
}
```

---

## Задача 3 — Импорт CommerceML2

### Миграция `import_jobs` уже есть. Добавляем?

- Нужен уникальный индекс по `(tenant_id, source, status, id)` для идемпотентности, но в Задаче 1 создан достаточный базовый набор. Для FIRST STAGE достаточно таблицы `import_jobs` + `stock_conflicts`.
- Дополнительный трудовой ресурс: batch-файлы прогресса. Для FIRST STAGE ограничимся уже созданной таблицей.

### Сервис импорта

```php
class CommerceML2ImportService
{
    public function import(string $filePath): ImportJob
    {
        $job = ImportJob::create([
            'tenant_id' => tenant_id(),
            'source'    => 'commerceml2',
            'status'    => 'processing',
        ]);

        $errors = [];
        $summary = [
            'processed' => 0,
            'updated'   => 0,
            'created'   => 0,
            'skipped'   => 0,
            'conflicts' => 0,
        ];

        $rows = CmlParser::parseRemains($filePath);

        DB::transaction(function () use ($rows, $job, &$errors, &$summary) {
            $seenExternalIds = collect();

            foreach ($rows as $row) {
                $product = ProductService::where('tenant_id', $job->tenant_id)
                    ->where('external_id', $row['external_id'])
                    ->first();

                if (! $product) {
                    $errors[] = ['external_id' => $row['external_id'], 'message' => 'product not found'];
                    $summary['skipped']++;
                    continue;
                }

                foreach ($row['warehouses'] as $whRow) {
                    $warehouse = Warehouse::where('tenant_id', $job->tenant_id)
                        ->where('name', $whRow['warehouse'])
                        ->first();

                    if (! $warehouse) {
                        $errors[] = ['warehouse' => $whRow['warehouse'], 'message' => 'warehouse not found'];
                        $summary['skipped']++;
                        continue;
                    }

                    $stock = Stock::where('tenant_id', $job->tenant_id)
                        ->where('warehouse_id', $warehouse->id)
                        ->where('product_id', $product->id)
                        ->lockForUpdate()
                        ->first();

                    if ($stock) {
                        $before = $stock->actual;

                        if ($stock->reserved > 0 && $whRow['qty'] < $before) {
                            $this->recordConflict($stock, $job, 'cannot decrease actual below reserved');
                            $summary['conflicts']++;
                            continue;
                        }

                        $stock->actual = $whRow['qty'];
                        $stock->available = round($stock->actual - $stock->reserved, 2);
                        $stock->save();
                        $summary['updated']++;
                    } else {
                        $stock = Stock::create([
                            'tenant_id'   => $job->tenant_id,
                            'warehouse_id' => $warehouse->id,
                            'product_id'  => $product->id,
                            'actual'      => $whRow['qty'],
                            'reserved'    => 0,
                            'available'   => $whRow['qty'],
                        ]);
                        $summary['created']++;
                    }

                    $summary['processed']++;

                    AuditLog::write($job->tenant_id, auth()->id(), 'commerceml2.import.update', Stock::class, $stock->id, [
                        'stock_id' => $stock->id,
                        'actual'   => [
                            'old' => $before ?? null,
                            'new' => $stock->actual,
                        ],
                    ], []);
                }
            }

            $job->update([
                'status'  => 'finished',
                'summary' => array_merge($summary, ['errors' => $errors]),
            ]);
        }, 5);

        return $job;
    }

    private function recordConflict(Stock $stock, ImportJob $job, string $message): void
    {
        StockConflict::create([
            'tenant_id'    => $stock->tenant_id,
            'stock_id'     => $stock->id,
            'import_job_id'=> $job->id,
            'message'      => $message,
            'payload'      => ['stock' => $stock->only(['actual','reserved','available'])],
            'resolved'     => false,
        ]);
    }
}
```

---

## Задача 4 — OrderStateMachine

### Контракт переходов

```
CREATED -> IN_PROGRESS -> READY -> ISSUED -> CLOSED
                   \-> CANCELLED
```

### Сервис

```php
class OrderStateMachine
{
    private const ALLOWED = [
        Order::STATUS_CREATED     => ['in_progress', 'cancelled'],
        'in_progress'             => ['ready', 'cancelled'],
        'ready'                   => ['issued'],
        'issued'                  => ['closed'],
        'closed'                  => [],
        'cancelled'               => [],
    ];

    public function __construct(private readonly array $settings) {}

    public function transition(Order $order, string $to, CancelReason $reason = null): void
    {
        $from = $order->status;

        if (! in_array($to, self::ALLOWED[$from] ?? [], true)) {
            throw new InvalidOrderTransitionException("Transition from {$from} to {$to} is forbidden");
        }

        if ($to === Order::STATUS_CANCELLED && blank($reason)) {
            throw new InvalidCancelReasonException('Cancel reason is required');
        }

        DB::transaction(function () use ($order, $from, $to, $reason) {
            if ($to === Order::STATUS_CLOSED) {
                if ($order->payment_status !== 'paid') {
                    $mustPay = (bool) ($this->settings['require_payment_before_close'] ?? true);
                    if ($mustPay) {
                        throw new OrderLockedException('Order cannot be closed without paid payment by settings');
                    }
                }
            }

            if (in_array($from, [Order::STATUS_READY, Order::STATUS_ISSUED, Order::STATUS_CLOSED], true)) {
                if ($order->order_items()->exists()) {
                    throw new OrderPositionRemovalException('Positions cannot be removed after payment/issuance');
                }
            }

            $old = $order->only(['status', 'payment_status', 'locked_at']);
            $order->status = $to;

            if ($to === Order::STATUS_CLOSED) {
                $order->locked_at = now();
            }

            $order->save();

            AuditLog::write($order->tenant_id, auth()->id(), 'order.transition', Order::class, $order->id, [
                'old' => $old + ['reason' => optional($reason)?->toArray()],
                'new' => ['status' => $to, 'locked_at' => $order->locked_at],
            ]);
        });
    }

    public function removeItem(OrderItem $item): void
    {
        $order = $item->order;

        if (in_array($order->status, [Order::STATUS_READY, Order::STATUS_ISSUED, Order::STATUS_CLOSED], true)) {
            throw new OrderPositionRemovalException('Items cannot be removed after payment/issuance/close');
        }

        DB::transaction(function () use ($item) {
            $item->reservations()->where('status', Reservation::STATUS_ACTIVE)->each(function (Reservation $r) {
                app(StockReservationService::class)->release($r->stock, $r->qty);
                $r->update(['status' => Reservation::STATUS_RELEASED]);
            });

            $snapshot = $item->snapshot;

            AuditLog::write($item->order->tenant_id, auth()->id(), 'order.item.remove', OrderItem::class, $item->id, [
                'snapshot' => $snapshot,
            ], []);

            $item->delete();
        });
    }
}
```

---

## Задача 5 — Middleware + защищённый API

### Middleware `EnsureTenant`

```php
class EnsureTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();
        $tenant = Tenant::where('slug', $host)->firstOrFail();
        tenant($tenant);
        DB::statement('SET app.current_tenant_id = ?', [$tenant->id]);

        return $next($request);
    }
}
```

### Middleware `EnsurePermission`

```php
class EnsurePermission
{
    public function __construct(private PermissionRegistry $permissions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $permission = $request->route('permission');

        abort_if(
            ! auth()->user()?->hasPermissionTo($permission),
            Response::HTTP_FORBIDDEN,
            'Forbidden'
        );

        return $next($request);
    }
}
```

### API Routes

```php
Route::middleware([
   EnsureTenant::class,
'api.auth:sanctum',
'permission:orders.read',
])
    ->prefix('api/v1')
    ->group(function () {
        Route::post('orders.index', [OrderController::class, 'index']);
        Route::post('orders.show/{id}', [OrderController::class, 'show']);
    });

Route::middleware(['api.auth:sanctum', 'permission:payments.correct'])
    ->post('payments/{payment}/correct', [PaymentController::class, 'correct']);

Route::middleware(['api.auth:sanctum', 'permission:shifts.close'])
    ->post('shifts/{shift}/close', [CashShiftController::class, 'close']);

Route::middleware(['api.auth:sanctum', 'permission:stock.transfer'])
    ->post('stock/transfer', [StockController::class, 'transfer']);
```

### Пример контроллера `orders.show`

```php
class OrderController extends Controller
{
    public function show(Order $order): JsonResponse
    {
        $order->load([
            'orderItems:id,order_id,product_id,type,qty,price,discount,snapshot',
            'payments:id,order_id,method,amount,status,payee_id',
            'customer:id,phone,type,legal_name',
            'vehicle:id,plate,brand,model',
        ]);

        return response()->json([
            'order' => $order,
            'items' => $order->orderItems,
            'payments' => $order->payments->map(fn ($p) => $p->only([
                'id','method','amount','status','payee_id','created_at'
            ])),
        ]);
    }
}
```

---

## Задача 6 — Модульная система Laravel 13

### Registry

```php
class ModuleRegistry
{
    public function isActive(Tenant $tenant, string $slug): bool
    {
        return $tenant->modules()
            ->where('slug', $slug)
            ->where('status', Module::STATUS_ACTIVE)
            ->exists();
    }

    public function toggle(Tenant $tenant, string $slug, bool $active): void
    {
        $module = Module::firstOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => $slug],
            ['status' => Module::STATUS_AVAILABLE, 'settings' => []]
        );

        if ($active) {
            $module->update([
                'status'     => Module::STATUS_ACTIVE,
                'enabled_at' => now(),
                'disabled_at' => null,
            ]);
        } else {
            $module->update([
                'status'     => Module::STATUS_DISABLED,
                'disabled_at' => now(),
            ]);
        }
    }
}
```

### Routes модуля

```php
class ModuleRoutesProvider
{
    public function map(Router $router): void
    {
        if ($this->registry->isActive(tenant(), 'orders')) {
            require base_path('modules/orders/routes/api.php');
        }

        if ($this->registry->isActive(tenant(), 'payments')) {
            require base_path('modules/payments/routes/api.php');
        }
    }
}
```

### Menu item

```php
class ModuleMenuProvider
{
    public function items(): Collection
    {
        return Module::where('tenant_id', tenant_id())
            ->where('status', Module::STATUS_ACTIVE)
            ->get()
            ->map(fn ($m) => [
                'slug'     => $m->slug,
                'label'    => data_get($m->settings, 'menu.label', ucfirst($m->slug)),
                'icon'     => data_get($m->settings, 'menu.icon'),
                'route'    => data_get($m->settings, 'menu.route'),
                'position' => (int) data_get($m->settings, 'menu.position', 99),
                'roles'    => data_get($m->settings, 'menu.roles', []),
            ]);
    }
}
```

### Settings модуля

```php
class ModuleSettingsService
{
    public function set(string $module, string $key, mixed $value, Tenant $tenant): void
    {
        Setting::updateOrCreate([
            'tenant_id' => $tenant->id,
            'group'     => 'module.' . $module,
            'key'       => $key,
            'scope'     => Setting::SCOPE_GLOBAL,
        ], [
            'ref_id' => null,
            'value'  => is_array($value) ? $value : ['value' => $value],
        ]);
    }
}
```

### Journal integration

```php
class ModuleJournalWatcher
{
    public function boot(): void
    {
        foreach (config('modules.journal_events', []) as $event => $moduleSlug) {
            Event::listen($event, function (AuditPayload $payload) use ($moduleSlug) {
                if (! tenant()->modules()->where('slug', $moduleSlug)->where('status', Module::STATUS_ACTIVE)->exists()) {
                    return;
                }

                AuditLog::write(tenant_id(), auth()->id(), $payload->action, $payload->object_type, $payload->object_id, $payload->old, $payload->new, $payload->metadata);
            });
        }
    }
}
```

### Пример модуля `orders`

```
modules/
├── orders/
│   ├── ModuleServiceProvider.php
│   ├── routes/
│   │   ├── api.php
│   │   └── web.php
│   ├── Http/
│   │   ├── Controllers/
│   │   └── Middleware/
│   ├── Services/
│   │   └── OrderStateMachine.php
│   ├── database/
│   │   └── migrations/
│   │       └── 0001_create_orders_tables.php
│   ├── resources/
│   │   └── views/menu/orders.blade.php
│   └── config/module.php
```

`modules/orders/config/module.php`

```php
return [
    'slug' => 'orders',
    'label' => 'Заказы',
    'permissions' => [
        'orders.read',
        'orders.write',
        'orders.cancel',
    ],
    'menu' => [
        'label' => 'Заказы',
        'route' => 'orders.index',
        'icon' => 'clipboard',
        'position' => 10,
        'roles' => ['admin','seller'],
    ],
];
```

---

## Acceptance mapping 49.1–49.8

### 49.1 Tenant isolation
- Ответственность backend:
  - `EnsureTenant` middleware.
  - Global Query Scope по `tenant_id`.
  - RLS-политики PostgreSQL с `current_setting('app.current_tenant_id')`.
- Ошибка для пользователя: `406 Not Acceptable / 403 Forbidden` при несоответствии tenant.
- Без Postman: base URL — `<slug>.lastik.app` или заголовок `X-Tenant-Slug`.

### 49.2 Device limit
- Backend: политика на поле `devices_limit` в таблице `users`.
- При входе с нового устройства сверяем COOKIE/токен.
- Ошибка: `429 Too Many Requests` с message `Device limit exceeded`.

### 49.3 Prices — reserves — stock
- Endpoints:
  - `orders.store` — внутри `DB::transaction`, Reserve происходит через `StockReservationService::reserve`.
- Контроль:
  - перед созданием `order` сумма `available` проверяется с `lockForUpdate()`.
  - отрицательный `available` запрещён исключением `OutOfStockException`.
- Ошибка пользователя: `409 Conflict` с `available_less_than_qty`.

### 49.4 Reservations and releases
- Endpoint: `reservations.release` или внутренний release в OrderStateMachine при отмене заказа.
- Идемпотентность: статус `active` проверяется перед переходом в `released`.
- Конфликты в отдельной таблице `stock_conflicts`.

### 49.5 Payments + corrections + mixed payment
- Создание платежа: endpoint `payments.store`.
- Corrections: `payments/{payment}/correct` с сохранением `old_amount/new_amount/reason`.
- Mixed payment: несколько записей в `payments` по одному `order_id`.
- После закрытия смены запрет на правку платежа без корректировки.

### 49.6 Shifts and reports
- Endpoint: `shifts.close` с DB-транзакцией и подсчётом `totals`.
- Правки: только через `PaymentCorrection` или `CashMovement`.
- Ошибка: `409 Conflict` при закрытии смены без сверки.

### 49.7 KPI snapshots
- Начисление происходит в `KpiRuleSnapshotService` при закрытии:
  - `order.paid` -> `Earning::create(['source' => 'order', ...])`.
  - `order_item.shipped` -> `Earning::create(['source' => 'item', ...])`.
- Snapshot правила в поле `rule_snapshot` JSONB, чтобы income не зависел от обновления `kpi_rules`.
- AuditLog на каждое начисление.

### 49.8 Audit log completeness
- Append-only: запрет `audit_logs` ON DELETE/UPDATE.
- Правки идут через `AuditLog::write(...)` из сервисов.
- Политика RLS напоминает контур:
  - `user_id` обязателен, кроме system-операций.
- Невидимые для клиента поля API:
  - `password_hash`, `two_factor_secret` исключаются из `UserResource`.

---

## Итоговый список критичных артефактов

1. Миграции всех FIRST STAGE сущностей + `stock_conflicts`.
2. RLS политики по tenant_id.
3. Модель `Stock` + `StockReservationService` с `lockForUpdate()` и AuditLog.
4. `CommerceML2ImportService` с обработкой конфликтов.
5. `OrderStateMachine` с запрещёнными переходами и `order_items` snapshot.
6. Middleware `EnsureTenant`, `EnsurePermission`.
7. API ресурсы без секретов.
8. Сервисный слой модульной системы с enable/disable без потери данных.
