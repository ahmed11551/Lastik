# Архив дублирующих миграций

Папка `migrations.disabled/` **не подхватывается** Laravel Migrator
(он читает только `database/migrations/`).

Файлы здесь — устаревшие черновики Sprint 1 с более богатой схемой.
Активная схема живёт в `database/migrations/` +
`2026_08_01_000034_align_acceptance_schema_gaps.php`.

**Безопасно удалить** эту папку в проде — на `migrate` / `migrate:fresh`
это не влияет. Перед удалением сверьте уникальные поля
(например `article` у products) с активными миграциями.
