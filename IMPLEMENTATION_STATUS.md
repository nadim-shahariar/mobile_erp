# IMPLEMENTATION STATUS — Mobile Shop ERP

**Last updated:** 20 November 2026
**Repository:** `mobile-shop-erp/`
**Current stage:** Phase 0 complete · Phase 1 complete · Phase 2 core complete · Phase 3 partial (installments/reminders) · Phase 4 partial (exchange/temporary delivery) · Phase 5 partial (expenses/salary)
**Test status:** `php artisan test` → **183 passed, 881 assertions** (SQLite in-memory)

> This file is the single source of truth for progress. Update it at the end of every working session before handing off. Never mark an item done here unless the migration, service, authorization, UI and test exist and were run.

---

## 1. Environment actually used

| Item | Value |
|---|---|
| PHP | 8.2.4 (bcmath, intl, gd, zip, pdo_sqlite, pdo_mysql enabled) |
| Laravel | 12.73.0 |
| Livewire | 3.6 |
| Spatie permission | 6.25 |
| PhpSpreadsheet | 5.11 |
| Node / npm | 26.13 / 11.20 |
| Vite | 7.3.8 (Tailwind 3, Alpine via Breeze) |
| Database (local) | SQLite `database/database.sqlite` |
| Database (tests) | SQLite `:memory:` (`phpunit.xml`) |
| Database (production target) | MySQL 8+ / MariaDB 10.6+ |
| Business timezone | `Asia/Dhaka` (configurable in Settings) |

Installed but unused packages: `laravel/sail` (not used), `laravel/breeze` (dev-only scaffolding). No Redis requirement.

---

## 2. Completed scope

### 2.1 Phase 0 — foundation

- [x] Laravel 12 scaffold, package compatibility verified, `.env.example` documented.
- [x] 27 migrations creating 54 tables (including Laravel auth/cache/job tables and spatie tables).
- [x] Business units with codes/names/timezone; user↔unit pivot; default unit.
- [x] Users with active/locked state, rank-free unit access, locale, last-login metadata.
- [x] Staff profiles (code, designation, join date, salary config fields ready for Phase 5).
- [x] Spatie RBAC: 45 permissions, roles `owner`, `manager`, `cashier`, `staff`; owner Gate bypass; sensitive-permission list.
- [x] Login hardened: no public registration, rate limiting, inactive/locked rejection, session termination, login audit.
- [x] Settings stored in DB with request-time overlay (company, timezone, currency, policy toggles, IMEI Luhn).
- [x] Transactional document numbering (`DocumentNumberService`) + sequence table.
- [x] Audit log (actor, IP, user agent, before/after, reason) for inventory/cycle/settings/purchase/staff/supplier changes.
- [x] Idempotency keys on inventory events, purchases and stock transfers.
- [x] Import batch audit table with headers/mapping/counts/error report.
- [x] Seeders: permissions, units, owner from env; opt-in demo staff and demo catalogue/stock (clearly labelled DEMO).
- [x] ERP shell: sidebar navigation, unit selector, global search, language switch (EN/BN), flash + error feedback, responsive layout.
- [x] Bilingual infrastructure: `lang/bn.json` covers navigation/common labels; full screen translation is a Phase 6 item (see gaps).
- [x] Test harness with scenario builder and deterministic Luhn-valid IMEIs.

### 2.2 Phase 1 — inventory core

- [x] **Catalog:** brands, categories, products (`PHONE`/`ACCESSORY`), variants, create/edit UI, accessory stock display.
- [x] **IMEI identity:** string storage, whitespace/dash normalization, 15-digit + Luhn validation, global uniqueness across both slots, legacy override with permission + reason, internal serial generation with explicit confirmation.
- [x] **Acquisition cycles:** `acquisition_value`, `allocated_direct_cost`, `effective_cost_basis`, source document, initial unit, one-open-cycle database guard, close workflow.
- [x] **Immutable events:** append-only ledger, actor, business time, from/to custody + unit, reference document, idempotency key, reversal pointer, metadata.
- [x] **State machine:** custody map + sellability map; invalid transitions HTTP 409; unit required only for `IN_UNIT`; list/next actions are permission-checked.
- [x] **Manual intake:** real cost basis, unit-scoped, audited, "unknown" battery/cycles represented as null.
- [x] **Unified stock entry:** type switch for **Phone / IMEI** (full serialized fields) or **Product / accessory** (name, SKU, buying price, stock, optional selling price). Existing accessory SKUs receive stock; new SKUs create the product + variant; stock movement and journal are posted.
- [x] **Shop sheet row:** buying party per acquisition cycle, expected selling price, due/status reason, per-line receiving date, adapter/cable name+price; entry form, grid columns, purchase propagation and import mapping. Full mapping in `docs/SHEET_ROW_MAPPING.md`.
- [x] **Purchases:** draft with multiple phone lines (IMEI captured per device) and accessory quantity lines; money recomputed server-side; atomic receive; received purchases immutable; draft cancel.
- [x] **Accessories:** per-unit balances, weighted-average cost (4dp), append-only movements, reservations, negative-stock guard, reorder point.
- [x] **Unit transfers:** request (reserve) → dispatch (in transit, not sellable) → receive (destination, sellable); accessory quantities; cancel before dispatch; double-receive rejection; destination-unit authorization.
- [x] **Excel-like grid:** server-side search (IMEI/model/SKU/brand), unit/custody/sellability/brand/grade filters, whitelisted sort, pagination 10–100, inline profile editing with validation, IMEI correction modal, history/transfer row links, cost masking, honest empty states.
- [x] **Bulk TSV entry:** Excel paste, per-row validation, in-file + DB duplicate detection, creates and receives a real purchase.
- [x] **Excel/CSV import wizard:** upload → mapping → dry run (IMEI, duplicates, model resolution, cost/battery/cycles) → commit (valid rows only is explicit) → batch audit with retained error report.
- [x] **IMEI lifetime screen:** hero state, identifiers, cycles with costs (permission-gated), full event timeline with actor/units/references, cross-unit 403.
- [x] **Control center:** real inventory KPIs only (available/in-transit/repair/quarantined, stock values, today's receipts, low stock, pending transfers, recent events, per-unit availability). "Modules not enabled yet" panel replaces fake sales/P&L.
- [x] **Staff & Access UI:** create/edit account, role, units, active/suspended, designation, password reset by admin.
- [x] **Suppliers UI:** list/create/edit, active flag.
- [x] **Settings UI:** company identity, timezone, currency symbol, IMEI Luhn, negative-stock policy, guest-expense policy, low-stock alerts.

### 2.3 Phase 2 — POS, customers, invoices, accounting core

- [x] **POS terminal** (`/pos`) with a dedicated full-width layout: unit/customer bar, IMEI/model search, accessory search, sale rows with editable price/discount, split cash/bank/card/bKash/Nagad/other tenders, exact-payment shortcuts, invoice-wide discount, live totals and due.
- [x] **Customer/shop records** with type, business name, phone, email, address, credit limit, active flag, outstanding balance and quick-add from the POS.
- [x] **Atomic sale posting**: handset row locks + `IN_UNIT`/`AVAILABLE`/open-cycle re-check (409 on the loser), accessory availability check, per-line cost snapshots, invoice numbering, immutable posted invoice.
- [x] **Stock effects**: `SOLD` IMEI event, custody `WITH_CUSTOMER`, cycle close, accessory `SALE` issue.
- [x] **Sales & invoices UI**: sales list with filters, printable A4 invoice (IMEI, tenders, due, company details), POS recent-invoice strip.
- [x] **Chart of accounts + per-unit payment accounts** and **double-entry journal posting** with balance/source-key invariants; purchase receipts, opening/manual intake and accessory adjustments now journalised too.
- [x] **Due sales** require a registered customer; SHOP credit limit is enforced; PERSON dues allowed with optional limit.
- [x] **Due collection** (`CustomerPaymentService`) with receipt number, oldest-invoice-first allocations, invoice paid/due updates and Dr cash/bank / Cr receivable journal — no new revenue.
- [x] **Dashboard sales KPIs**: today's sales, today's collections (cash/bank/MFS/card), outstanding due, and gross profit/COGS for cost-profit users.
- [x] **Accounting reconciliation test/demo**: ledger inventory balances equal open cycle cost + accessory weighted stock value; demo journals balance.

### 2.4 Phase 3 partial — installment schedules & reminders

- [x] Invoice repayment plans (`DUE` or `INSTALLMENT`) with anchored month-end dates, final-cent handling and a 120-installment cap.
- [x] Immutable `sale_payment_schedules`; partial collections allocate oldest overdue installment across **all** in-scope invoices before falling back to unscheduled credit.
- [x] Payment reminders component/page with branch scoping, overdue amount, invoice/phone and a collection link that targets the reminded invoice and branch.
- [x] Customer collection receipt/allocation journal remains receivable recovery, never revenue.
- ⬜ Aging buckets, persisted notifications, full shop statements, installment reports.

### 2.5 Phase 4 partial — exchange/trade-in & temporary delivery

- [x] POS exchange modal: trade-in phone or accessory, exact IMEI reuse/restocking, condition snapshot, split customer/counter payouts (cash/bank/bKash), over/under-payment rollback.
- [x] IMEI normalization for dashed/spaced trade-ins; secondary-IMEI-only devices supported.
- [x] Temporary handoff to a shop/person with deposit liability, return/refund, or sale confirmation that applies the deposit and creates the invoice.
- [x] Unit transfer handoff from POS with approval-aware dispatch.
- ⬜ Repairs, returns/credit notes, formal refund workflows.

### 2.6 Phase 5 partial — expenses, salary & advances

- [x] POS quick expense with staff/supplier/other recipients, typed-name reuse, branch scope, balanced cash/bank journal, idempotency.
- [x] Salary month configuration/finalization, salary payable, staff advances (never operating expense), from-salary payments and balanced accrual/advance journals.
- [x] Backdated rate changes refresh unfinalized salary snapshots; finalize works without a pre-created month record.
- ⬜ Approval thresholds, guest/staff meal allocation splitting, full payroll run/export, carrying an excess advance into a later month (currently the excess remains an employee receivable and is shown as “Extra advance”).
- ⬜ Full receipts/approval history and payroll reports.

### 2.7 Invoice branding & POS convenience

- [x] Editable invoice title/tagline/terms/signature labels/footer/accent colour and a logo upload served through `/branding/logo`; protected preview page.
- [x] Invoice shows sold device condition snapshot, exchange/trade-in, deposit credit, installment schedule and settlement details.
- [x] POS recent transactions, stock handoff, payment reminders and quick expense panels.
- [x] Cash-memo/invoice identity remains stable after the handset profile is edited later.

### 2.8 Route map delivered

| Route | Component | Permission |
|---|---|---|
| `/dashboard` | `Dashboard` | auth + active |
| `/pos` | `PosTerminal` | `pos.sell` + unit access |
| `/sales` | `SalesIndex` | `invoice.view` + unit |
| `/invoices/{sale}` | `InvoiceController` | `invoice.view` + invoice unit |
| `/customers` | `CustomerIndex` | `customer.view` (mutations `customer.edit`, collections `payment.collect`) |
| `/inventory` | `InventoryGrid` | `inventory.view` + unit |
| `/inventory/new` | `PhoneIntake` | `inventory.create` |
| `/inventory/bulk-entry` | `BulkEntry` | `inventory.import` |
| `/imei-history/{imei?}` | `ImeiHistory` | `inventory.view` + unit |
| `/purchases`, `/purchases/create`, `/purchases/{id}` | purchase components | `purchase.view/create/approve` |
| `/transfers`, `/transfers/create`, `/transfers/{id}` | transfer components | `stock.transfer.view/create/approve` |
| `/catalog` | `ProductIndex` | `catalog.view/manage` |
| `/suppliers` | `SupplierIndex` | `supplier.view/manage` |
| `/imports/phones` | `PhoneImport` | `inventory.import` + `purchase.approve` to commit |
| `/staff` | `StaffIndex` | `staff.view/manage` |
| `/settings` | `SettingsPage` | `settings.manage` |
| `/invoice-preview` | `InvoiceController@preview` | `settings.manage` |
| `/staff-salary` | `SalaryIndex` | `payroll.run` |
| `/payment-reminders` | `PaymentReminders` | `invoice.view` + unit |
| `/unit/switch`, `/locale/switch` | controllers | auth |
| `/login`, `/profile` | Breeze | guest / auth |

---

## 3. Evidence — commands and results

Run from the repository root:

```bash
php artisan migrate:fresh --seed
# 2026_01_... all migrations DONE, Permission/BusinessUnit/AdminUser seeders DONE

php artisan test
# Tests: 183 passed (881 assertions), Duration ~21s

npm install --cache /tmp/mserp-npm-cache
npm run build
# vite build: app CSS/JS bundles build successfully

php artisan db:seed --class=StaffSeeder       # optional demo staff
php artisan db:seed --class=DemoDataSeeder    # optional demo catalogue + stock
```

Verified test coverage by area:

| Area | Tests |
|---|---|
| Money (bcmath rounding, no floats) | `tests/Unit/MoneyTest.php` |
| IMEI normalization/Luhn/pair rules | `tests/Unit/ImeiNormalizerTest.php` |
| Manual intake identity/cycle/event, duplicates, legacy, internal serial, unit scope, cashier 403 | `tests/Feature/Inventory/PhoneIntakeTest.php` |
| Purchase totals, atomic receipt, rollback, double receive, weighted average, negative stock | `tests/Feature/Inventory/PurchaseReceiptTest.php` |
| State machine custody/sellability, 409, idempotency, one-open-cycle, identity edit lock | `tests/Feature/Inventory/StockStateMachineTest.php` |
| Transfer round trip, exact custody/sellability, accessory quantities, double receive, cancellation, wrong unit | `tests/Feature/Transfers/StockTransferTest.php` |
| Product-stock entry: new accessory, existing SKU restock, phone-SKU rejection, stock validation, balanced opening journal | `tests/Feature/Inventory/PhoneIntakeTest.php` |
| POS sale totals, tenders, IMEI event/cycle close, COGS, balanced journal, duplicate sale 409, credit limit, invoice permissions, POS Livewire flow | `tests/Feature/Sales/PosSaleTest.php` |
| Partial due collection, allocations, receipt, ledger balance, no new revenue, over-collection rejection | `tests/Feature/Sales/CustomerCollectionTest.php` |
| Purchase receipt journal (inventory/cash/payable) | `tests/Feature/Inventory/PurchaseReceiptTest.php` |
| Grid unit scoping, search, counts, cost masking, no-unit 403 | `tests/Feature/Inventory/InventoryGridQueryTest.php` |
| IMEI timeline + cross-unit protection + not-found honesty | `tests/Feature/Inventory/ImeiHistoryTest.php` |
| Import analyzer + CSV end-to-end commit + invalid-row blocking | `tests/Feature/Imports/PhoneImportTest.php` |
| Inventory metrics from posted rows, no cross-unit leak, dashboard render | `tests/Feature/DashboardMetricsTest.php` |
| Inactive login/session, RBAC screens, last login | `tests/Feature/Auth/AccessControlTest.php` |
| Breeze auth/profile retained | `tests/Feature/Auth/*`, `ProfileTest` |
| Registration disabled | `tests/Feature/Auth/RegistrationTest.php` |
| Exchange/trade-in, split payouts, rollback, walk-in, secondary/dashed IMEI | `tests/Feature/Exchanges/PosExchangeTest.php` |
| Temporary delivery send/return/sale, deposits, branch numbering, handoff reset | `tests/Feature/Deliveries/ExternalDeliveryTest.php` |
| Expense recipients, permissions, journals, cross-branch numbering | `tests/Feature/Expenses/PosExpenseTest.php` |
| Salary accrual/advances, backdated rates, finalize without record | `tests/Feature/Payroll/SalaryTest.php` |
| Installment schedules, cross-invoice oldest allocation, reminders | `tests/Feature/Sales/PaymentScheduleTest.php` |
| Invoice branding/logo/preview, sold-condition snapshot | `tests/Feature/Settings/InvoiceBrandingTest.php` |
| Global vs unit document sequences | `tests/Feature/DocumentNumberServiceTest.php` |

**Authenticated HTTP smoke test (20 Nov 2026):** fresh isolated SQLite database migrated/seeded from zero; owner routes `/dashboard`, `/pos`, `/sales`, `/customers`, `/inventory`, `/inventory/new`, `/inventory/bulk-entry`, `/purchases`, `/purchases/create`, `/catalog`, `/suppliers`, `/imports/phones`, `/staff`, `/settings`, `/profile`, `/payment-reminders`, `/staff-salary`, `/invoices/1` all returned HTTP 200. Role checks: cashier 200 on POS/sales/customers/inventory and 403 on purchases/catalog/staff/settings; staff 200 on inventory/intake and 403 on POS/sales/purchases. Demo ledger debits = credits (490,500.00) with zero unbalanced entries; ledger handset/accessory balances equal stock value.

**Not run in this environment:**

- MySQL/MariaDB integration tests (row-lock concurrency, two simultaneous sales, two simultaneous transfer receipts). The code uses `lockForUpdate` and unique guards; SQLite cannot prove parallel behaviour.
- Browser/Dusk tests (Excel paste keyboard flow, POS keyboard flow, responsive grid, drawer UX).
- XLSX (as opposed to CSV) import end-to-end. The same PhpSpreadsheet reader handles both; a fixture-based XLSX test is pending.
- Queue workers, scheduler, backups, mail delivery, thermal (80 mm) printing.

---

## 4. Demo data and credentials

`DatabaseSeeder` creates only bootstrap data. Demo data is opt-in.

| Account | Email | Password | Role | Units |
|---|---|---|---|---|
| Owner | `owner@example.com` | `ChangeMe!2026` | owner | U1, U2 |
| Manager | `manager@example.com` | `Demo!Pass2026` | manager | U1, U2 |
| Cashier | `cashier@example.com` | `Demo!Pass2026` | cashier | U1 |
| Staff | `staff@example.com` | `Demo!Pass2026` | staff | U2 |

**Change the owner password immediately.** Demo catalogue/stock is labelled DEMO; it is not the shop's real Google Sheet data and no attempt was made to reconstruct that data.

---

## 5. Known gaps / deliberately unimplemented

These are not bugs hidden behind fake buttons — the UI does not offer them.

1. **Cashier shifts** (open float, drawer movements, blind close, variance) — the remaining Phase 2 gate. Sales link to user/unit but not a shift.
2. **Returns, refunds, credit notes and repairs** — exchange/trade-in is implemented, but formal returns/refunds and repair jobs are not.
3. **Full report pages/exports/PDF/80 mm receipt** — A4 invoice prints; no report/export screens or thermal receipt.
4. **Approval thresholds** for discounts/refunds/exchanges/expenses; salary and exchange actions are permission-gated but not threshold-approved.
5. **Excess salary advance carry-forward** — extra advances remain an employee receivable and are labelled “Extra advance”; they are not automatically deducted from a later month. No repayment-entry screen yet.
6. **Full Bangla translation** — navigation/common labels only; operational screens remain English.
7. **Grid refinements:** saved layouts, Tab/Enter traversal, virtual scrolling, colum visibility.
8. **Accessory adjustment UI** — service and journal posting exist; no screen yet.
9. **Manual reserve/not-for-sale UI** — reservation currently happens through transfers.
10. **2FA, audit review UI, scheduled backups, queue-based notifications, SMS/WhatsApp** — not implemented.
11. **Cost allocation UI** for allocated direct costs (schema/service exist, UI always sends 0).
12. **MySQL concurrency job** — row-lock races are designed but only SQLite sequential tests run.
13. **Inline grid price edits** are limited to expected selling price/status note; acquisition cost stays controlled by receipt/cycle flows.
14. **Sheet columns `DFGH`, `Column 23`, `Column 31`** remain unmapped because their meaning is unknown; `Variant` maps to catalog variant/SKU.
15. **Sold/customer columns** from the sheet are not imported as stock; sold rows become POS invoices, and the importer rejects rows containing a selling date/customer name.
16. **Duplicate staff identity** — a typed expense staff member can later get a login account, creating two payroll rows for one person. Not automatically merged.
17. **Salary-linked expenses** live in the expenses table; future expense reports must exclude rows with `salary_month_id` or advances will look like operating expenses.

---

## 6. Risks and mitigations

| Risk | Mitigation / status |
|---|---|
| SQLite hides row-lock races | Services use `lockForUpdate` + unique guards + idempotency; MySQL CI concurrency tests required before production |
| Cost leakage to cashiers | Permission checks at policy, query presenter and view; no cost in serialized Livewire payload for unauthorised users (tested) |
| Duplicate IMEI across slots | Global unique `serialized_identifiers.normalized_value` (tested) |
| Two open cycles | Unique guard index + row lock + service error (tested) |
| Double stock receipt | Status check under lock + idempotency (tested) |
| Double selling one IMEI | Handset row lock + state/cycle re-check inside the sale transaction (sequential 409 tested; MySQL parallel test pending) |
| Double-posted journal / doubled revenue | Unique `source_key` + idempotency on sale/collection/purchase (tested) |
| Accounting vs stock mismatch | Opening intake, purchases, adjustments, sales and collections all journalised; demo ledger reconciles to stock value |
| Credit leak / uncollectable due | Due requires a registered customer; SHOP credit limit enforced (tested) |
| Fake financial reporting | No P&L/sales UI until journals exist; dashboard explicitly labels missing modules |
| Demo data mistaken for production | Separate opt-in seeder, DEMO notes, default owner warning |
| Timezone day-boundary bugs | `BusinessClock` converts business day ↔ UTC; used for "today" metrics |
| Spreadsheet formula injection | Export layer not built yet; must sanitize `=`, `+`, `-`, `@` cells before enabling CSV export (Phase 6 gate) |
| Import volume | 2000-row cap per batch; server-side processing; queued imports planned for larger files |

---

## 7. Next work

1. **Cashier shift open/close** and drawer reconciliation (remaining Phase 2 gate).
2. Returns/refunds/credit notes and repair jobs (Phase 4); reject/restock inspections, counter-ledger entries.
3. Full reports: sales/payments, receivables aging, stock valuation, gross profit, salary/expense reports; CSV/XLSX export with formula-injection protection and 80 mm receipt.
4. Aging buckets, persisted due/overdue notifications, shop statements and customer ledger drill-down.
5. Carry excess salary advances to later net payable, add repayment entries, and add approval thresholds.
6. MySQL concurrency job: two simultaneous POS sales of one IMEI, double collection retry, double receipt.
7. Full Bangla translation and saved grid layouts.

Track progress by appending to section 8, not by editing historical claims.

---

## 8. Change log

| Date | Change |
|---|---|
| 2026-11-19 | Phase 0 + Phase 1 implemented; docs (`ARCHITECTURE`, `ERD`, `PERMISSIONS`, `PHASE_PLAN`) added; demo seed verified. |
| 2026-11-19 | Added shop-sheet row support: buying party, expected selling price, due/status reason, per-line receiving date, adapter/cable bundle; grid columns/edits, purchase propagation, import mapping (`docs/SHEET_ROW_MAPPING.md`). |
| 2026-11-19 | Added unified Add stock entry with Phone/Product switch; product entry creates/restocks accessories by SKU with buying price and stock, optional selling price, and an opening-balance journal. 114 tests / 428 assertions. |
| 2026-11-19 | Phase 2 core: POS terminal, customers/shops, invoices + print, split tenders, due + collections, chart of accounts, balanced journals for sales/purchases/intake/adjustments/collections, dashboard sales metrics, demo sale. Ledger reconciles to stock. |
| 2026-11-20 | Overnight feature pass: installment schedules/reminders, exchange/trade-in with split payouts, temporary shop/person delivery, POS quick expenses, salary/advance ledger, invoice branding/preview. 183 tests / 881 assertions. |
| 2026-11-20 | QA fixes: money parser accepts `5.`/`.5`/`+5`; global-vs-unit document numbers and sequence scope concurrency; collection allocates oldest overdue schedule across invoices; salary snapshots refresh after backdated rates and can finalize without a record; expense render permission; delivery shop classification/deposit permissions/stale-handoff reset; exchange IMEI normalization/secondary-only; delivery/schedule date guards. |
