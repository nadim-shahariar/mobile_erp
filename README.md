# Mobile Shop ERP

A production-oriented Laravel application for a single or multi-branch mobile handset shop, built against `MASTER_PROJECT_SPECIFICATION.md`.

**Current build: Phase 0 + Phase 1 + Phase 2 core complete, with partial Phase 3–5.** That includes POS, customers/shops, invoices, dues and installment schedules, reminders, exchange/trade-in, temporary shop delivery, quick expenses, salary/advance ledger, and a double-entry ledger. Cashier shifts, returns/refunds, repairs, approval thresholds and the full report/export suite are **not implemented**; the UI does not pretend they are. See [IMPLEMENTATION_STATUS.md](IMPLEMENTATION_STATUS.md).

---

## What works today

- **POS terminal** — table-style sale screen with IMEI handset rows, accessory rows, split cash/bank/card/bKash/Nagad tenders, invoice discount, customer quick-add and printable invoice. Modelled on the vehicle-parts POS, adapted for serialized handsets.
- **Customers & shops** — credit limits, outstanding balances, due collection with oldest-overdue-installment allocation and a separate receipt/journal.
- **Installments & reminders** — `DUE` or monthly `INSTALLMENT` schedules at sale posting, anchored month-end dates, partial payments, overdue reminders and a collection link that targets the exact invoice/branch.
- **Exchange / trade-in** — incoming phone or accessory, condition snapshot, split customer/counter payouts, old IMEI restocking and atomic rollback.
- **Temporary delivery** — send a handset to a shop/person with deposit liability, return/refund it, or confirm its sale and apply the deposit.
- **Expenses & salary** — POS quick expenses with staff/supplier/other recipients, salary months, advances and from-salary payments posted through the ledger.
- **Double-entry accounting** — chart of accounts, per-unit payment accounts, balanced journals for sales, purchases, opening intake and adjustments, unique source keys, ledger-to-stock reconciliation.
- **IMEI lifetime identity** — one physical handset, one persistent identity, full append-only event history, cost-basis cycles, duplicate protection across both IMEI slots.
- **Excel-like inventory grid** — server-side search/filter/sort/pagination, inline condition edits, IMEI correction, cost columns masked by permission, unit scoping.
- **Purchases** — draft entry with per-device IMEIs, accessory quantity lines, computed totals, atomic receipt into stock, immutable after receipt.
- **Accessories** — per-unit balances, weighted-average cost, movement ledger, reservations, reorder points.
- **Unit transfers** — request → dispatch (in transit, not sellable) → receive, with reservations and a full audit trail.
- **Unified stock entry** — choose **Phone** for IMEI fields or **Product** for name/SKU/buying price/stock (optional selling price). Existing accessory SKUs are restocked; new ones create the catalog product.
- **Shop sheet row entry** — model/IMEI/buying party/storage/battery/cycles/colour/box/buy & sell price/receiving date/due reason/adapter & cable bundle, exactly as the existing Excel row. See [docs/SHEET_ROW_MAPPING.md](docs/SHEET_ROW_MAPPING.md).
- **Bulk paste & Excel/CSV import wizard** — validation, duplicate detection, dry run, per-row error report, batch audit.
- **IMEI history screen** — cycles, events, actors, documents, current custody/sellability.
- **Roles, units, staff, settings, audit log** — owner/manager/cashier/staff with server-side permissions and unit scope.
- **Bilingual shell** — English + Bangla navigation/common labels; full translation is a later phase.

Deliberately absent: cashier shifts, returns/refunds/credit notes, repairs, approval thresholds, full report pages/exports, 80 mm thermal printing, and automatic carry-forward of excess salary advances. The A4 invoice and configurable invoice branding are implemented.

---

## Requirements

- PHP **8.2+** with `bcmath`, `mbstring`, `intl`, `gd`, `zip`, `pdo` (sqlite and/or mysql), `fileinfo`, `openssl`
- Composer 2
- Node.js 20+ and npm (for asset build)
- SQLite (default) or MySQL 8+/MariaDB 10.6+
- No Redis required

---

## Installation (local, SQLite)

```bash
composer install
cp .env.example .env
php artisan key:generate

# .env: DB_CONNECTION=sqlite (default), ERP_* values, owner credentials
php artisan migrate --seed

# assets (Tailwind/Alpine)
npm install
npm run build

php artisan serve
# open http://127.0.0.1:8000
```

`migrate --seed` creates permissions, the two placeholder business units and the bootstrap owner from `ERP_OWNER_*`. There is **no public registration**; the owner creates staff accounts in the app.

### Optional demo data (clearly labelled DEMO)

```bash
php artisan db:seed --class=StaffSeeder      # manager/cashier/staff demo accounts
php artisan db:seed --class=DemoDataSeeder   # demo catalogue, suppliers, phones, accessories
```

| Account | Email | Password | Role | Units |
|---|---|---|---|---|
| Owner | `owner@example.com` | `ChangeMe!2026` | owner | U1, U2 |
| Manager | `manager@example.com` | `Demo!Pass2026` | manager | U1, U2 |
| Cashier | `cashier@example.com` | `Demo!Pass2026` | cashier | U1 |
| Staff | `staff@example.com` | `Demo!Pass2026` | staff | U2 |

Change the owner password immediately. Demo rows are not real shop history.

### Production (MySQL 8)

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mobile_shop_erp
DB_USERNAME=...
DB_PASSWORD=...
```

```bash
php artisan migrate --force
php artisan config:cache
php artisan route:cache
npm ci && npm run build
```

Before going live: change owner credentials, set `APP_DEBUG=false`, configure HTTPS, mail, queue worker and nightly backups, then run `php artisan test` against a MySQL test database (the suite itself defaults to SQLite `:memory:`).

---

## First-run walkthrough

1. Sign in as the owner. Open **Settings** and set the real business name, address, phone, timezone and currency symbol.
2. Open **Catalog** and create a phone product + at least one variant (storage/colour). Create suppliers in **Suppliers**.
3. Open **Staff & Access** and create manager/cashier/staff accounts with unit assignments.
4. Add stock either:
   - **Add phone** — one handset with IMEI, condition and cost; or
   - **Purchases → New purchase** — multiple phones + accessories, save draft then receive; or
   - **Excel Import** — map an XLSX/CSV, dry-run, commit.
5. Use **Inventory** to search by IMEI, filter by unit/state, and open **History** for the lifetime timeline.
6. Use **Unit Transfers** to move reserved stock: request, dispatch, then the destination user receives it.
7. The **POS** sells a handset (closes its cycle and appends the SOLD event), adds accessories, splits payment and prints the invoice. A due sale needs a customer; shop credit limits are enforced.
8. **Sales & invoices** lists posted invoices; **Customers** shows outstanding balances and collects dues.
9. **Payment reminders** show overdue installment/credit amounts; clicking Collect opens the customer with that invoice and branch preselected.
10. **Staff salary** sets/finalizes monthly salary, applies advances and posts salary payable/expense; **expenses** can be paid from salary or as normal operating expenses.
11. The **Control Center** now shows posted sales, collections, receivables and gross profit alongside inventory figures.

---

## Testing

```bash
php artisan test                 # 183 tests, 881 assertions (SQLite in-memory)
./vendor/bin/pint --test         # code style
```

Coverage includes money arithmetic, IMEI rules, atomic purchase receipt and rollback, duplicate IMEI protection, transfer round-trips with exact custody/sellability, double-receipt rejection, unit-scope leakage, cost masking, import validation/commit, dashboard metrics and access control.

**Not covered:** MySQL row-lock concurrency, browser UI tests, XLSX fixtures, queue/backup behaviour.

---

## Project documentation

- [IMPLEMENTATION_STATUS.md](IMPLEMENTATION_STATUS.md) — what is built, test evidence, gaps, next slice
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — system architecture, invariants, state machine, folder structure, security
- [docs/SHEET_ROW_MAPPING.md](docs/SHEET_ROW_MAPPING.md) — the shop's Excel columns mapped to app fields, including what is intentionally deferred to POS
- [docs/ERD.md](docs/ERD.md) — implemented schema, ER diagram, indexes, migration order, planned tables
- [docs/PERMISSIONS.md](docs/PERMISSIONS.md) — auth design, permission catalogue, role matrix, policies
- [docs/PHASE_PLAN.md](docs/PHASE_PLAN.md) — phase-by-phase plan and acceptance gates
- [docs/source/MASTER_PROJECT_SPECIFICATION.md](docs/source/MASTER_PROJECT_SPECIFICATION.md) — original specification contract (copied into the repo)
- [docs/source/DEEPSEEK_BUILD_PROMPTS.md](docs/source/DEEPSEEK_BUILD_PROMPTS.md) — original implementation prompts

---

## Design decisions in one minute

- **Services own transactions.** Livewire components never write multi-table financial/inventory state directly.
- **Events, not mutations.** Custody/sellability changes are appended to `inventory_events`; posted records are never deleted.
- **Database-enforced invariants.** Unique normalized IMEI, one open cycle via unique guard, unique document numbers, restricted FKs.
- **Decimal money only.** `DECIMAL(18,2)` + bcmath `Money`; averages at 4 decimals.
- **Unit scope is server-side.** Derived from the pivot on every query; request parameters are never trusted.
- **No invented numbers.** Sales/P&L metrics are absent until journals exist; unknown battery/cycle values render as "Unknown", not zero.

---

## License

Proprietary — the shop owner's project. No open-source license granted.
# mobile_erp
