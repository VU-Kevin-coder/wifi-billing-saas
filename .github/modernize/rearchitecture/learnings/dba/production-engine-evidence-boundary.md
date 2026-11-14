# Production Engine Evidence Boundary

Repository defaults and local Compose files do not prove which database engine is deployed in production; PHPUnit's forced SQLite `:memory:` configuration is the safe automated target when external DB use is prohibited.

## What Happened

For wifi-billing-saas task t9, `.env.example`, Docker Compose, and README documented MySQL 8.0, while `config/database.php` also permitted SQLite, MariaDB, PostgreSQL, and SQL Server. The actual production database was explicitly unknown, so no connection or migration was attempted. `phpunit.xml` force-selects in-memory SQLite; `phpunit.ci-db.xml` relies on environment values and may target an external database.

## Takeaway

Report documented/local engine separately from confirmed production engine. When production configuration is unknown, keep engine-specific conclusions pending and run automated tests only through the test configuration that force-isolates its database.

## History
- 2026-11-13 (wifi-billing-saas/t9): initial
