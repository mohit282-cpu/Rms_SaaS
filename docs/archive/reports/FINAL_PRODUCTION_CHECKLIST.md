# Final Production Deployment Checklist

## Environment & Infrastructure Checklist

- [x] **Secret Isolation**: Verified `.env` and sensitive credential files are excluded from Git via `.gitignore`.
- [x] **Credential Hardening**: Initial admin bootstrap password generated cryptographically or pulled from `APP_ADMIN_PASSWORD` env variable instead of static defaults.
- [x] **Database Least Privilege**: Dedicated database user configured without `DROP DATABASE` or global schema modification privileges in production.
- [x] **Information Disclosure Safeguards**: `api/health.php` sanitized to output generic status without exposing PHP version, file paths, or stack traces.
- [x] **File Upload Protection**: `helpers/Security.php` configured with MIME allowlists (`image/jpeg`, `image/png`, `image/webp`), 2MB limit, and randomized filenames (SVG removed to prevent Stored XSS).

---

## Application & Security Governance Checklist

- [x] **Authentication & RBAC**: Backend permission guards (`Auth::requireAdmin()`, `Auth::requireKitchen()`) enforced across all API endpoints.
- [x] **Order Price Tampering Defense**: `place-order.php` calculates base item and add-on prices strictly from server-side database tables.
- [x] **Order Idempotency**: `Idempotency-Key` header verification and unique database constraints active to prevent duplicate order submissions.
- [x] **Order Lifecycle & State Machine**: `OrderService::transitionStatus()` enforcing atomic status updates (`NEW` $\rightarrow$ `PREPARING` $\rightarrow$ `READY` $\rightarrow$ `COMPLETED`).
- [x] **IDOR & Session Hijacking Safeguards**: `order-success.php` and `api/get-order-status.php` validate order access against active customer session table numbers.
- [x] **Rate Limiting**: `RateLimiter::enforce()` automatically increments counters (`hit()`) and blocks excessive attempts.
- [x] **CSRF Protection**: All state-changing browser submissions enforce `CSRF::requireValidToken()`.
- [x] **Database Concurrency**: Order processing, stock checking, and status updates isolated inside DB transactions with `FOR UPDATE` row locks.

---

## Deployment Sign-off

- **PHP Version Compatibility**: PHP 8.1+ verified via `php -l`.
- **Database Schema**: Standalone SQL migrations verified in `database/migrations/`.
- **Production Status**: **READY FOR DEPLOYMENT**
