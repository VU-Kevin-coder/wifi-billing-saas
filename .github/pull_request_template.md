## What and why

## Checklist
- [ ] `vendor/bin/phpunit` passes locally, and CI is green (SQLite, MySQL and PostgreSQL)
- [ ] New behaviour has tests; a bug fix has a test that fails without the fix
- [ ] No secrets, keys or real customer data in the diff or the tests
- [ ] Migrations: reviewed for existing data and tested on PostgreSQL; rollback considered
- [ ] **Money or auth paths touched** (payments, wallet, withdrawals, MFA, tenancy): a second reviewer has approved
- [ ] New environment variables are documented in `docs/production-env.example` and `render.yaml`
- [ ] User-facing changes (emails, forced 2FA, new required settings) are communicated to operators
