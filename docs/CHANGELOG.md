# RMS SaaS — Release Changelog

## [1.0.0-PROD] — Production Release

### Added
- **Floor & Table Operations**: Added `BillingService::calculateTableBill` for multi-order table bill aggregation. Added `transfer_table` and `merge_bills` endpoints and POS UI quick action buttons.
- **Register Shift & Cash Float System**: Integrated shift opening cash float tracking, cash movements, sales breakdowns, expected cash calculation, variance reconciliation, and immutable shift locking.
- **HR & Payroll Module**: Integrated employee management, shift templates, shift assignments, clock in/out attendance, overtime calculation, server-side payroll calculation engine, payslip generation, and salary history.
- **Customer QR Checkout**: Built secure 2D SVG QR code generation, table session validation, self-service dining ordering, and KDS queue integration.
- **Tenant Deletion Purge Service**: Built `TenantDeletionService` to purge all 35+ tenant-owned tables in dependency order with zero orphaned records.
- **Production Documentation**: Added `DEPLOYMENT.md`, `SECURITY.md`, `DATABASE.md`, `API.md`, and `CHANGELOG.md`.

### Security & Hardening
- Enforced production error display suppression (`display_errors = 0`) when `APP_ENV=production`.
- Hardened `.htaccess` rules in document root, `uploads/`, and `storage/` with Apache 2.4 `Require all denied` access controls.
- Enforced CLI-only execution guard on `database/migrate.php`.
- Centralized RBAC permissions (`AuthorizationService`, `PermissionService`).
- Scrubbed secrets and hardcoded local path dependencies across all files.

### Testing
- Automated 35-step end-to-end integration test suite (`e2e_restaurant_os_test.php`).
- Automated 96-point regression test matrix passing 100% cleanly across security, HR, shift, QR, and core operations.
