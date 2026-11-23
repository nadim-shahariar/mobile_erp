# Phase-by-phase implementation plan

This plan follows section 18 of the master specification. Each phase must satisfy its **gate** before the next one starts. A feature is only "done" when migrations, models, services, validation, policies, UI, tests, audit entries and ledger integration (where applicable) all exist.

Legend: ✅ implemented and verified · 🚧 in progress · ⬜ not started

---

## Phase 0 — Foundation ✅

**Goal:** a secure, testable Laravel application with units, roles, settings, numbering and audit — no feature UI before the invariants exist.

| Deliverable | Status | Evidence |
|---|---|---|
| Laravel 12 + PHP 8.2 compatibility audit | ✅ | `composer.json`, `php artisan --version` |
| `.env.example` with every required key | ✅ | DB, ERP timezone/currency/company/units/owner/IMEI |
| Business units (U1/U2 placeholders) + pivot + default unit | ✅ | `business_units`, `user_business_units`, `UnitContext` |
| Users + staff profiles, active/locked state, login audit | ✅ | `users`, `staff_profiles`, `EnsureUserIsActive` |
| Spatie roles/permissions, 4 bootstrap roles, 45 permissions | ✅ | `PermissionSeeder`, `Permissions` |
| Auth hardening: no registration, throttling, session invalidation | ✅ | `routes/auth.php`, `LoginRequest`, tests |
| Database settings overlay + company/timezone/currency/policy | ✅ | `settings`, `SettingsPage`, `SetLocale` middleware |
| Transactional document numbering | ✅ | `document_sequences`, `DocumentNumberService` |
| Audit log with actor/IP/before-after | ✅ | `audit_logs`, `AuditLogger` |
| Idempotency storage | ✅ | `idempotency_keys`, keys on events/purchases/transfers |
| Import batch audit table | ✅ | `import_batches` |
| Bootstrap seed (permissions, units, owner from env) | ✅ | `DatabaseSeeder` + `AdminUserSeeder` |
| Opt-in demo staff/catalogue/stock, clearly labelled DEMO | ✅ | `StaffSeeder`, `DemoDataSeeder` |
| Test harness + scenario builder | ✅ | `tests/Concerns/BuildsErpScenario.php` |
| ERD, migration plan, risk list | ✅ | `docs/ERD.md`, `docs/ARCHITECTURE.md` |

**Gate:** `php artisan migrate:fresh --seed` succeeds; login/logout/unit switch work; 183 tests green.

---

## Phase 1 — Core inventory + Excel grid ✅

**Goal:** the operational heart — unique IMEI identity, acquisition cycles, immutable events, purchases, accessories, per-unit stock, transfers, grid, bulk/import, timeline.

### Completed slices

1. **Catalog** — brands, categories, products (PHONE/ACCESSORY), variants; create/edit UI.
2. **Serial identity** — normalization, Luhn validation, global cross-slot uniqueness, legacy-flag override, internal serials.
3. **Cycles and events** — one-open-cycle DB guard, acquisition value + allocated cost + effective basis, append-only event ledger, state machine with 409 on invalid transitions.
4. **Manual intake** — real cost basis, unit-scoped, audited.
5. **Purchases** — draft entry with per-device IMEI capture, accessory quantity lines, computed totals, atomic receive, immutable once received, cancel drafts.
6. **Accessories** — per-unit balances, weighted-average cost, append-only movements, reservation support, negative-stock guard.
7. **Transfers** — request (reserve) → dispatch (in transit) → receive (destination), accessory quantities, cancel-before-dispatch, double-receive rejection.
8. **Excel-like grid** — server-side filter/search/sort/pagination, unit scoping, inline profile edits with validation, IMEI correction modal, row actions, cost masking, empty states.
9. **Bulk TSV entry** — validate every row, duplicate detection (file + DB), books a real purchase.
10. **Shop sheet row fields** — buying party, expected selling price, due/status reason, receiving date per line, adapter/cable bundle with prices; reflected in grid, history and import mapping.
11. **Excel/CSV import wizard** — upload, header mapping (including the shop's exact headers), dry run, per-row errors, valid-only opt-in, batch log, atomic commit.
12. **IMEI lifetime screen** — timeline, cycles, current state, cost (permission-gated), identifier list, cross-unit protection.
13. **Control center** — real inventory KPIs only; explicit "not enabled" panel instead of fake sales/P&L.
14. **Staff access + settings** — role/unit assignment, suspension, company/policy settings.

**Gate evidence:**
- `tests/Feature/Transfers/StockTransferTest.php` — U2→U1→U2 round trip, exact custody/sellability, 6 events, unchanged cycle, no double receive.
- `tests/Feature/Inventory/PurchaseReceiptTest.php` — atomic receipt, rollback on duplicate IMEI, weighted average.
- `tests/Feature/Inventory/PhoneIntakeTest.php` — identity, cycle, event, duplicate/invalid/legacy/internal-serial rules, unit scope.
- `tests/Feature/Inventory/InventoryGridQueryTest.php` — no cross-unit leakage, cost masking.
- `tests/Feature/Imports/PhoneImportTest.php` — analyzer, the shop's sheet headers, end-to-end CSV commit.
- `tests/Feature/Inventory/InventoryGridQueryTest.php` — sheet columns, inline price/status edits, adapter/cable bundle.
- 183 tests / 881 assertions passing on SQLite.

### Phase 1 items deliberately deferred (tracked in status)

- Column visibility/saved grid layouts, keyboard Tab/Enter cell traversal, virtual scrolling beyond server pagination.
- Accessory adjustment UI (service + permission exist).
- Reserved/not-for-sale manual actions UI (transfer reservation is used).
- MySQL concurrency race tests (design uses row locks; SQLite cannot exercise them).
- Thermal/A4 printing: A4 invoice implemented; 80 mm thermal receipt is still pending.

---

## Phase 2 — POS, invoices, accounting foundation ✅ (core)

**Goal:** sell handsets + accessories with real tender splits, due invoices, COGS and balanced double-entry journals.

**Implemented in this build:** POS terminal (IMEI/accessory rows, split tenders, customer quick-add), immutable invoices, printable A4 invoice, sales list, customers/shops with credit limits, due collection with oldest-first allocations, chart of accounts + per-unit cash/bank/MFS/card accounts, balanced journals for sales/purchases/opening intake/adjustments/collections, dashboard sales & profit metrics. **Still pending in Phase 2:** cashier shift open/close and drawer reconciliation, returns/refunds/credit notes (moved with Phase 4), formal report pages.

### Work packages

1. **Customers/shops** — `customers` (PERSON/SHOP), credit limit, terms, contact, ledger statement shell. Policies + unit scope. Walk-in quick create.
2. **Accounts** — cash/bank/MFS/card accounts per unit with opening balances as journal entries, not raw numbers.
3. **Chart of accounts + journal core** —
   - `chart_of_accounts`, `journal_entries`, `journal_lines`.
   - `JournalPostingService` accepts balanced line arrays, validates sum(debit)=sum(credit), non-negative lines, posting period open, unique source posting key.
   - `ReversalService` posts a mirror entry linked by `reversal_of`; never deletes.
   - Account classes: Cash in Drawer, Bank, MFS, Trade Receivables, Installment Receivables, Supplier Payable, Staff Advance, Inventory Handsets, Inventory Accessories, Sales Revenue, Repair Revenue, COGS, Payroll, Staff Food, Guest Hospitality, Rent, Utilities, Transport, Repair Expense, Discounts/Returns, Owner Equity.
4. **POS sale posting** —
   - `sales`, `sale_items` (serialized unit + cycle + COGS snapshot), invoice numbering `INV-YYYY-NNNNNN`.
   - Locks the handset row, re-checks `IN_UNIT` + `AVAILABLE` + open cycle inside the transaction, closes the cycle (`SOLD`), posts `SOLD` event, decrements accessory stock, posts balanced journal (AR/revenue and COGS/inventory), creates tender payment rows (cash/bank/MFS/card; due is a receivable, never a tender).
   - Compensations for change/refund must not mutate the original posting.
   - Idempotency key on the sale request; loser of a concurrent race gets 409.
5. **Invoices and collections** — printable A4/thermal HTML, immutable posted invoice, due collection receipt with allocation (`payment_allocations`), invoice status transitions, reprint audit.
6. **Returns/refunds foundation** — credit note/reversal scaffolding and stock inspection state (full exchange in Phase 4).
7. **Cashier shifts** — open float, link cash receipts/payouts, expected vs counted, variance + supervisor approval; bank/MFS excluded from drawer.
8. **Unit-scoped reports** — daily sales & payments, outstanding due, stock/COGS reconciliation.

**Gate status:**
- ✅ every posted journal balances (automated sale/purchase/collection tests; demo ledger debit = credit);
- ✅ split tenders + due reconcile (`paid + due = grand total`);
- ✅ invoice/COGS/stock/cycle/event all reconcile in tests;
- ✅ collections do not create revenue (automated);
- ✅ cashier cannot see cost/profit (existing cost masking tests + POS UI);
- ⬜ MySQL concurrency test for two simultaneous sales of one IMEI (SQLite logic test covers the sequential second-sale rejection; row locks are designed for MySQL).

---

## Phase 3 — Installments, overdue, shop credit 🚧 (partial)

1. `installment_contracts`, schedule generation once at posting, `payment_allocations` to schedule lines (oldest overdue first, policy-controlled).
2. Partial payments: line remains partially outstanding; collection is cash recovery, never new revenue.
3. Calendar-month due dates with last-valid-day policy; immutable schedule once posted.
4. Reschedule workflow: versioned contract + approval, no silent edits.
5. Overdue computation in business timezone; aging buckets 1–7/8–30/31–60/60+; persisted notifications with dedupe and read state.
6. Shop statements with running balance, credit limit breach warnings, manual or oldest-first allocation, printable receipts.
7. Reporting: installment schedule, arrears, next due, collection vs revenue reconciliation.

**Implemented:** immutable DUE/INSTALLMENT schedules, oldest-overdue allocation across invoices, partial payments, branch-scoped reminders and collection links, invoice schedule display. **Still pending:** aging buckets, persisted notification read state, reschedule approvals, full shop statements.
**Gate:** outstanding balances reconcile to invoices; partial payment leaves exactly the residual; overdue reminder persists until paid; no double-counted revenue.

---

## Phase 4 — Repairs, exchange, returns 🚧 (partial)

1. `repair_jobs` + cost items + vendor payments; transitions `CREATED → SENT → UNDER_REPAIR → READY → RECEIVED → CLOSED` (+CANCELLED/UNREPAIRABLE); vendor payable vs customer receivable kept separate; inspection/quarantine before resale; configurable capitalise-vs-expense treatment (no double count).
2. Exchange transaction: outgoing sale + incoming trade-in atomically; incoming handset quarantined, inspected, new cycle opened at accepted value; difference settlement/refund handled explicitly; original sale immutable; exchange memo.
3. Return/refund: authorized, reason, tender vs store credit, inspection before restock, counter-ledger entries, credit notes.
4. Full IMEI lifetime UAT: purchase → transfers → repair → sale → trade-in → inspection → resale with two cycles and correct per-cycle margin.

**Implemented:** exchange/trade-in for phones and accessories with split payouts, temporary shop/person delivery with deposit/return/sale, IMEI normalization and secondary-only support.
**Still pending:** repairs and formal returns/credit notes.
**Gate:** UAT-04 passes; per-cycle valuation tests; invalid ownership/exchange cases rejected; no history rewrite.

---

## Phase 5 — Expenses, staff, payroll, approvals 🚧 (partial)

1. Expense categories and quick expense: beneficiary(ies) vs payee vs entered-by, allocations, funding account, reason, receipt, approval, unit scope.
2. Shared staff meal = one cash outflow, multiple beneficiary allocations; no duplicate monetary postings.
3. Staff monthly statement: salary owed/paid, advances outstanding, bonus, deductions, food/hospitality attributed, cash personally received, reimbursed expenses.
4. Salary accrual, advance as receivable, payroll payment settles payable; P&L salary expense recognised once.
5. Approval thresholds for discounts/refunds/expenses/exchange valuations/adjustments; approval requests with before/after.
6. Cash reconciliation across shifts; expense/payroll reports reconcile to journals.

**Implemented:** POS quick expenses with staff/supplier/other recipients, salary months, rates, advances/from-salary payments, accrual and payable journals, finalize without a pre-created record, backdated rate refresh.
**Still pending:** approval thresholds, shared meal allocations, excess-advance carry-forward, payroll exports.
**Gate:** salary + expense journals reconcile; advance does not inflate payroll expense.

---

## Phase 6 — Reports, exports, hardening ⬜

1. Full report set with date/unit/actor filters and drill-down: daily/monthly sales, inventory, valuation, purchases, customer/shop due aging, installments, repairs, transfers, exchange, expenses, staff payment, cashier shift, P&L, IMEI history, audit/approvals.
2. CSV/XLSX export with spreadsheet-injection protection; PDF invoices/reports; export authorization.
3. Performance: indexes verified on 10k+ handsets, server pagination everywhere, query budgets, optional Redis cache for reference data.
4. Localization completion (Bangla strings for all operational screens), accessibility pass, responsive/mobile grid card view.
5. Deployment: `.env` hardening, `config:cache`, queue worker + scheduler, nightly encrypted backup + restore drill, health checks, error monitoring.
6. UAT-01 → UAT-12 executed and recorded; traceability matrix requirement → file → test → pass/fail.
7. Admin training notes and deployment guide.

**Gate:** all UAT scenarios pass; no critical test unrun; backup restore proven; audit review complete.

---

## UAT traceability

| UAT | Scenario | Phase | Current status |
|---|---|---|---|
| UAT-01 | Create 10 phones via import, two unit counts correct | 1 | Covered in tests for import + grid; full 10-row manual run pending |
| UAT-02 | Transfer U2→U1→U2 with full timeline | 1 | ✅ automated |
| UAT-03 | Repair sent/received with vendor due | 4 | Not started |
| UAT-04 | Sell → exchange → inspect → resell, two cycles | 4 | Model supports it; documents pending |
| UAT-05 | Phone + accessories + split payment + invoice | 2 | Not started |
| UAT-06 | Installment partial payment/overdue/no double revenue | 3 | Not started |
| UAT-07 | Wholesale shop credit + partial collection | 2/3 | Not started |
| UAT-08 | Staff breakfast + salary advance reconciliation | 5 | Not started |
| UAT-09 | Cashier cannot see cost or escalate via API | 1 | ✅ automated partial (cost masking, settings 403) |
| UAT-10 | Sales vs payments vs expenses vs profit vs stock reconcile | 2/6 | Not started |
| UAT-11 | Simultaneous sell succeeds once | 2 | Not started (MySQL test required) |
| UAT-12 | Shift close expected vs counted | 2 | Not started |

---

## Recommended next slice (Phase 3)

1. Cashier shifts (open float, link cash receipts, blind close, variance) — the remaining Phase 2 gate.
2. Installment contracts and schedule generation at sale posting (down payment, financed balance, term, first due date).
3. Partial collection allocation to schedule lines (oldest overdue first) with receipts; collections remain receivable recovery, never new revenue.
4. Overdue computation in the business timezone, aging buckets, persisted notifications with dedupe.
5. Shop statements with running balance and credit-limit visibility.
6. Report pages: sales/payments, receivables aging, stock valuation, gross profit; CSV/XLSX export.
7. MySQL integration test job for concurrent sale/lock behaviour.
