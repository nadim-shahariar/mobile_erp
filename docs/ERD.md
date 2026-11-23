# Database ERD and migration structure

**Status:** tables marked ✅ are migrated and used by Phase 0/1 code. Tables marked 🧭 are designed but **not yet migrated**; they are listed so later phases do not redesign the core.

---

## 1. Implemented entity relationship diagram

```mermaid
erDiagram
    business_units ||--o{ user_business_units : assigns
    users ||--o{ user_business_units : accesses
    users ||--o| staff_profiles : has
    users ||--o{ audit_logs : acts
    users ||--o{ inventory_events : records
    users ||--o{ purchases : creates
    users ||--o{ stock_transfers : requests

    brands ||--o{ products : owns
    categories ||--o{ products : classifies
    products ||--o{ product_variants : defines

    product_variants ||--o{ serialized_units : physical
    serialized_units ||--o{ serialized_identifiers : identity
    serialized_units ||--o{ serialized_unit_accessories : bundles
    serialized_units ||--o{ inventory_cycles : cost_basis
    serialized_units ||--o{ inventory_events : lifetime
    inventory_cycles ||--o{ inventory_events : during

    suppliers ||--o{ purchases : supplies
    business_units ||--o{ purchases : receives
    purchases ||--o{ purchase_lines : contains
    purchases ||--o| journal_entries : posts
    product_variants ||--o{ purchase_lines : ordered_as
    purchase_lines ||--o{ inventory_cycles : "source (morph)"

    business_units ||--o{ accessory_stock_balances : holds
    product_variants ||--o{ accessory_stock_balances : balances
    product_variants ||--o{ accessory_stock_movements : moves_quantity
    business_units ||--o{ accessory_stock_movements : at

    business_units ||--o{ stock_transfers : from
    business_units ||--o{ stock_transfers : to
    stock_transfers ||--o{ stock_transfer_lines : contains
    serialized_units ||--o{ stock_transfer_lines : moves
    product_variants ||--o{ stock_transfer_lines : accessory_qty

    business_units ||--o{ import_batches : imports_into
    users ||--o{ import_batches : uploads

    customers ||--o{ sales : buys
    business_units ||--o{ sales : sells_from
    users ||--o{ sales : creates
    sales ||--o{ sale_items : contains
    sales ||--o{ sale_payments : tendered_by
    sales ||--o{ payment_allocations : settled_by
    customers ||--o{ customer_payments : pays
    customer_payments ||--o{ payment_allocations : allocates

    accounts ||--o{ journal_lines : posts_to
    journal_entries ||--o{ journal_lines : contains
    sales ||--o| journal_entries : posts
    customer_payments ||--o| journal_entries : posts

    business_units {
        bigint id PK
        string code UK
        string name
        string timezone
        boolean is_active
        smallint sort_order
    }

    users {
        bigint id PK
        string name
        string email UK
        string phone
        string password
        boolean is_active
        string locale
        timestamp last_login_at
        smallint failed_login_attempts
        timestamp locked_until
    }

    user_business_units {
        bigint id PK
        bigint user_id FK
        bigint business_unit_id FK
        boolean is_default
    }

    staff_profiles {
        bigint id PK
        bigint user_id FK,UK
        string code UK
        string designation
        date joined_at
        date left_at
        decimal monthly_salary
        json salary_config
    }

    settings {
        bigint id PK
        string key UK
        json value
        string group
        boolean is_encrypted
    }

    document_sequences {
        bigint id PK
        string key
        bigint business_unit_id FK
        string period
        string prefix
        smallint padding
        bigint next_number
    }

    audit_logs {
        bigint id PK
        bigint user_id FK
        string event
        string auditable_type
        bigint auditable_id
        json old_values
        json new_values
        string ip_address
    }

    idempotency_keys {
        bigint id PK
        string key UK
        string action
        json response_body
    }

    import_batches {
        bigint id PK
        string batch_no UK
        bigint business_unit_id FK
        bigint user_id FK
        string original_filename
        string stored_path
        string status
        json headers
        json mapping
        int total_rows
        int valid_rows
        int invalid_rows
        int committed_rows
        json error_report
        timestamp committed_at
    }

    products {
        bigint id PK
        string product_type
        bigint brand_id FK
        bigint category_id FK
        string name
        string model
        string sku UK
        json specification
        boolean is_active
    }

    product_variants {
        bigint id PK
        bigint product_id FK
        string sku UK
        string storage
        string ram
        string color
        string condition_grade
        json attributes
        decimal default_purchase_price
        decimal default_selling_price
        int reorder_point
    }

    suppliers {
        bigint id PK
        string type
        string name
        string phone
        string address
        boolean is_active
    }

    purchases {
        bigint id PK
        string document_no UK
        bigint supplier_id FK
        string buying_party_name
        bigint business_unit_id FK
        date purchase_date
        string status
        decimal subtotal
        decimal discount_total
        decimal other_charges
        decimal total
        decimal paid_amount
        decimal due_amount
        string payment_method
        timestamp received_at
        bigint received_by FK
        string idempotency_key UK
    }

    purchase_lines {
        bigint id PK
        bigint purchase_id FK
        bigint product_id FK
        bigint product_variant_id FK
        string line_type
        string imei_1
        string imei_2
        string buying_party_name
        decimal expected_selling_price
        string status_note
        date received_at
        json serial_payload
        int quantity
        decimal unit_cost
        decimal line_total
    }

    serialized_units {
        bigint id PK
        bigint product_variant_id FK
        string primary_imei
        string secondary_imei
        string imei_status
        string imei_flag_reason
        tinyint battery_health
        int cycle_count
        string condition_grade
        string box_kit
        string current_custody
        bigint current_unit_id FK
        string sellability_status
        decimal expected_selling_price
        string status_note
        bigint last_event_id
        bigint version
    }

    serialized_unit_accessories {
        bigint id PK
        bigint serialized_unit_id FK
        bigint inventory_cycle_id FK
        string accessory_type
        string name
        decimal price
        string note
    }

    serialized_identifiers {
        bigint id PK
        bigint serialized_unit_id FK
        string identifier_type
        string raw_value
        string normalized_value UK
    }

    inventory_cycles {
        bigint id PK
        bigint serialized_unit_id FK
        int cycle_no
        string source_type
        string source_ref_type
        bigint source_ref_id
        string buying_party_name
        decimal acquisition_value
        decimal allocated_direct_cost
        decimal effective_cost_basis
        bigint initial_unit_id FK
        timestamp acquired_at
        timestamp closed_at
        string closing_type
        tinyint active_cycle_guard "1 open / NULL closed"
    }

    inventory_events {
        bigint id PK
        bigint serialized_unit_id FK
        bigint inventory_cycle_id FK
        string event_type
        string from_custody
        string to_custody
        bigint from_unit_id FK
        bigint to_unit_id FK
        bigint actor_id FK
        timestamp happened_at
        string reference_type
        bigint reference_id
        string idempotency_key UK
        bigint reversal_of_event_id FK
        json metadata
    }

    accessory_stock_balances {
        bigint id PK
        bigint business_unit_id FK
        bigint product_variant_id FK
        int qty_on_hand
        int qty_reserved
        decimal average_cost
        bigint version
    }

    accessory_stock_movements {
        bigint id PK
        bigint business_unit_id FK
        bigint product_variant_id FK
        int quantity_delta
        string reason
        decimal unit_cost
        int balance_after
        string source_type
        bigint source_id
        bigint actor_id FK
    }

    stock_transfers {
        bigint id PK
        string transfer_no UK
        bigint from_unit_id FK
        bigint to_unit_id FK
        string status
        bigint requested_by FK
        timestamp requested_at
        bigint dispatched_by FK
        timestamp dispatched_at
        bigint received_by FK
        timestamp received_at
        string idempotency_key UK
    }

    stock_transfer_lines {
        bigint id PK
        bigint stock_transfer_id FK
        string line_type
        bigint serialized_unit_id FK
        bigint product_variant_id FK
        int quantity
        decimal unit_cost
    }

    customers {
        bigint id PK
        string type
        string name
        string business_name
        string phone
        decimal credit_limit
        boolean is_active
    }

    accounts {
        bigint id PK
        string code UK
        string name
        string class
        string type
        bigint business_unit_id FK
        boolean is_payment_account
    }

    journal_entries {
        bigint id PK
        string entry_no UK
        date entry_date
        string source_type
        bigint source_id
        string source_key UK
        string memo
        timestamp posted_at
        bigint created_by FK
        bigint reversal_of_id FK
    }

    journal_lines {
        bigint id PK
        bigint journal_entry_id FK
        bigint account_id FK
        decimal debit
        decimal credit
        string memo
    }

    sales {
        bigint id PK
        string invoice_no UK
        bigint business_unit_id FK
        bigint customer_id FK
        string type
        string status
        decimal subtotal
        decimal line_discount_total
        decimal invoice_discount_total
        decimal discount_total
        decimal tax_total
        decimal grand_total
        decimal paid_total
        decimal due_total
        timestamp posted_at
        bigint created_by FK
        bigint journal_entry_id FK
        string idempotency_key UK
    }

    sale_items {
        bigint id PK
        bigint sale_id FK
        string line_type
        bigint product_variant_id FK
        bigint serialized_unit_id FK
        bigint inventory_cycle_id FK
        string identifier
        int quantity
        decimal unit_price
        decimal discount
        decimal line_total
        decimal unit_cost
        decimal cost_total
    }

    sale_payments {
        bigint id PK
        bigint sale_id FK
        bigint account_id FK
        string method
        decimal amount
        string reference
        timestamp received_at
    }

    customer_payments {
        bigint id PK
        string receipt_no UK
        bigint customer_id FK
        bigint business_unit_id FK
        bigint account_id FK
        decimal amount
        timestamp received_at
        bigint journal_entry_id FK
    }

    payment_allocations {
        bigint id PK
        bigint customer_payment_id FK
        bigint sale_id FK
        decimal amount
    }
```

---

## 2. Table-by-table notes

### 2.1 Identity and access

| Table | Purpose | Critical constraints |
|---|---|---|
| `business_units` | Physical branches (Unit 1/Unit 2 placeholders) | `code` unique |
| `users` | Login identity, active flag, locale, login metadata | `email` unique, bcrypt password |
| `user_business_units` | Unit authorisation pivot | unique `(user_id, business_unit_id)` |
| `staff_profiles` | Employee code, designation, join/leave, salary config | `user_id` unique, `code` unique |
| spatie tables | roles, permissions, model/pivot mappings | standard package schema, teams disabled |
| `settings` | DB-backed company/policy overrides | `key` unique |
| `audit_logs` | Actor-attributed before/after changes | indexed by auditable + event |
| `document_sequences` | Transactional document numbering | unique `(key, unit, period)` |
| `idempotency_keys` | Durable request/response reuse for critical POSTs | `key` unique |
| `import_batches` | Import audit + error report | `batch_no` unique |

### 2.2 Catalog

`products.product_type` is `PHONE` or `ACCESSORY`. Handsets attach to `product_variants` (storage/RAM/colour/grade), accessories also attach to variants so sales/transfers can refer to one consistent SKU. Variant prices are *defaults*; real cost lives on purchase lines/cycles.

### 2.3 Serialized handset core

- **`serialized_units`**: persistent identity + current derived state. `current_custody` and `sellability_status` are denormalised fast-read columns, always written through `StockStateMachine`. `last_event_id`/`version` support cheap timeline entry and optimistic concurrency. Battery/cycle/grade are nullable: unknown is represented as unknown, not zero.
- **`serialized_identifiers`**: the actual uniqueness authority. `normalized_value` is globally unique, so cross-slot duplication is impossible. `identifier_type` is `IMEI1`, `IMEI2` or `SERIAL`.
- **`inventory_cycles`**: one cost basis per ownership period. `source_type` is the acquisition type; `source_ref_type/source_ref_id` is a polymorphic link to the source document (`PurchaseLine` today; sale/trade-in later). Two unique indexes:
  - `UNIQUE(serialized_unit_id, cycle_no)`
  - `UNIQUE(serialized_unit_id, active_cycle_guard)` with guard `1` open / `NULL` closed
- **`serialized_unit_accessories`**: adapter/cable bundle per handset with recorded name and price. Prices are informational bundle values and do not silently alter the acquisition cost basis; the row can be edited from the grid with an audit entry.
- **`inventory_events`**: append-only. No `updated_at` column. Fields capture from/to custody and unit, actor, business timestamp (`happened_at`, stored UTC), reference document, free-text note, idempotency key, reversal pointer and JSON metadata (cycle id, cost, from/to sellability).

### 2.4 Purchasing

`purchases` stores the document, unit, supplier, totals, paid/due and receipt actor/time. `purchase_lines` stores one row per physical handset (qty 1, IMEI in `imei_1`/`imei_2`, extra condition in `serial_payload`) or one quantity row per accessory. Serial data is captured on the draft so "save draft, receive later" works; it becomes a real identity only on receipt.

### 2.5 Accessories

`accessory_stock_balances` is the current quantity + weighted average cost per `(unit, variant)`, with a version for optimistic locking. `accessory_stock_movements` is the immutable movement log (`PURCHASE_RECEIPT`, `SALE`, `TRANSFER_OUT/IN`, `ADJUSTMENT`, `OPENING`, `WRITE_OFF`). On-hand is reconstructable from movements.

### 2.6 Sheet-row fields

`inventory_cycles.buying_party_name` records who the handset was bought from (free text when there is no supplier record). `serialized_units.expected_selling_price` and `status_note` mirror the sheet's Selling Price and Due/reason columns. Purchase lines carry the same fields plus a per-line `received_at`, which becomes the cycle acquisition date when the purchase is received. These are nullable because the shop sheet itself often has blanks; blanks are shown as "Not set/Unknown", never as zero.

### 2.7 Sales and receivables

`sales` is the immutable invoice header; `sale_items` carry a per-line cost snapshot and (for phones) the exact `inventory_cycle_id` that was closed. `sale_payments` stores each real tender against a payment account; due is **not** a payment row — it is the difference carried in `due_total` and posted to Trade Receivables. `customer_payments` + `payment_allocations` record later due collection and allocation to specific invoices. A unique `(sale_id, serialized_unit_id)` index prevents the same IMEI appearing twice on one invoice.

### 2.8 Double-entry ledger

`accounts` is the chart of accounts plus per-unit payment accounts (`CASH-U1`, `BANK-U1`, `BKASH-U1`, …). `journal_entries` has a unique `source_key` so a retried sale/purchase/collection cannot double-post; `journal_lines` carry exactly one of debit/credit. Every service posting checks debits = credits and non-negative lines. `purchases.journal_entry_id` and `sales.journal_entry_id` link documents to their ledger entry.

### 2.9 Installments, exchange, delivery, expense and payroll tables (implemented)

| Table | Purpose / key fields |
|---|---|
| `sale_payment_schedules` | sale_id, type DUE/INSTALLMENT, sequence, due_date, amount, paid_amount; unique `(sale_id, sequence)` |
| `exchanges` | document_no unique, idempotency_key unique, unit, nullable customer (walk-in), outgoing sale_id, incoming serialized_unit_id / product_variant_id, condition_snapshot, buying_price, settlement direction/amount/method/payments |
| `external_deliveries` | reference_no unique, unit, phone, customer, recipient_type, agreed_price, deposit, status OUT/SOLD/RETURNED, active_guard (one open delivery per phone), sale_id |
| `expenses` | document_no unique, unit, recipient type/staff/supplier/other, amount, reason, payment method, salary_month_id (from-salary), journal_entry_id, idempotency |
| `staff_members` | lightweight non-login employee recipient per branch (typed staff names), normalized_name unique per unit |
| `staff_salary_rates` | unit, recipient_key `user:{id}`/`member:{id}`, effective_month, monthly_salary |
| `salary_months` | unit, recipient_key, salary_month, salary_amount, finalized_at/by, journal_entry_id |
| `document_sequences.scope_key` | `GLOBAL` or `UNIT:{id}` with unique `(key, scope_key, period)`; prevents duplicate numbers under concurrent first allocation |

Accounting links: sales carry `trade_in_credit`, `deposit_credit`, `emi_rate`, `emi_charge`; sale items carry a condition snapshot in `details`; expenses/salary/exchange/delivery flows post through `journal_entries`/`journal_lines`.

### 2.10 Transfers

`stock_transfers` is the header with actor/timestamps for each lifecycle step; `stock_transfer_lines` holds either a serialized unit id or an accessory variant + quantity. The same handset cannot sit in two open transfers (service check under transaction).

---

## 3. Migration order and commands

```bash
php artisan migrate            # apply
php artisan migrate:fresh --seed
php artisan migrate:status
```

| # | Migration | Tables |
|---|---|---|
| 1 | `0001_01_01_000000_create_users_table` | users, password_reset_tokens, sessions |
| 2 | `0001_01_01_000001_create_cache_table` | cache, cache_locks |
| 3 | `0001_01_01_000002_create_jobs_table` | jobs, job_batches, failed_jobs |
| 4 | `2026_01_01_000001_create_organisation_tables` | business_units, user_business_units, staff_profiles, settings, document_sequences, audit_logs, idempotency_keys, import_batches |
| 5 | `2026_01_01_000002_create_permission_tables` | permissions, roles, model_has_*, role_has_permissions |
| 6 | `2026_01_02_000010_create_catalog_tables` | brands, categories, products, product_variants |
| 7 | `2026_01_02_000020_create_supplier_and_purchase_tables` | suppliers, purchases, purchase_lines |
| 8 | `2026_01_02_000030_create_serialized_unit_tables` | serialized_units, serialized_identifiers |
| 9 | `2026_01_02_000040_create_inventory_cycle_and_event_tables` | inventory_cycles, inventory_events |
| 10 | `2026_01_02_000050_create_accessory_stock_tables` | accessory_stock_balances, accessory_stock_movements |
| 11 | `2026_01_02_000060_create_stock_transfer_tables` | stock_transfers, stock_transfer_lines |
| 12 | `2026_01_03_000010_add_sheet_row_fields` | sheet-row columns + serialized_unit_accessories |
| 13 | `2026_01_04_000010_create_customer_and_accounting_tables` | customers, accounts, journal_entries, journal_lines, purchases.journal_entry_id |
| 14 | `2026_01_04_000020_create_sales_tables` | sales, sale_items, sale_payments, customer_payments, payment_allocations |
| 15 | `2026_11_20_000001_add_intake_details_to_serialized_units` | supplier_id, inventory_category |
| 16 | `2026_11_20_000002_add_country_to_product_variants` | country |
| 17 | `2026_11_20_000003_create_expenses_table` | expenses |
| 18 | `2026_11_20_000004_create_exchanges_table` | exchanges + sales.trade_in_credit |
| 19 | `2026_11_20_000005_create_staff_members_for_expense_recipients` | staff_members |
| 20 | `2026_11_20_000006_create_sale_payment_schedules` | sale_payment_schedules |
| 21 | `2026_11_20_000007_create_staff_salary_tables` | staff_salary_rates, salary_months |
| 22 | `2026_11_20_000008_add_supplier_and_emi_fields` | supplier refs / EMI columns |
| 23 | `2026_11_20_000009_create_external_deliveries` | external_deliveries + sales.deposit_credit |
| 24 | `2026_11_20_000010_add_exchange_split_payments` | exchanges.settlement_payments |
| 25 | `2026_11_20_000011_allow_walk_in_exchanges` | exchanges.customer_id nullable |
| 26 | `2026_11_20_000012_add_sale_item_details` | sale_items.details snapshot |
| 27 | `2026_11_20_000013_add_scope_key_to_document_sequences` | document_sequences.scope_key + unique scope |

Future phases append migrations only; Phase 0/1 tables are not rewritten.

---

## 4. Index and constraint inventory (implemented)

| Table | Index / constraint | Reason |
|---|---|---|
| serialized_units | `primary_imei`, `secondary_imei` | exact/like IMEI search |
| serialized_units | `(current_unit_id, sellability_status)` | unit grid + availability counts |
| serialized_units | `(current_custody, sellability_status)` | in-transit/repair widgets |
| serialized_units | `(product_variant_id, sellability_status)` | model-level stock |
| serialized_identifiers | `normalized_value` unique | duplicate prevention |
| serialized_identifiers | `(serialized_unit_id, identifier_type)` unique | one IMEI1/IMEI2 per handset |
| inventory_cycles | `(serialized_unit_id, cycle_no)` unique | cycle ordering |
| inventory_cycles | `(serialized_unit_id, active_cycle_guard)` unique | one open cycle |
| inventory_cycles | `(initial_unit_id, closed_at)` | unit valuation |
| inventory_events | `(serialized_unit_id, id)`, `(event_type, happened_at)` | timeline + activity feed |
| inventory_events | `(reference_type, reference_id)` | document drill-down |
| purchase_lines | `(product_variant_id, line_type)` | variant history |
| purchases | `(business_unit_id, purchase_date)`, `(supplier_id, status)` | list/report filters |
| accessory_stock_balances | `(business_unit_id, product_variant_id)` unique | one balance per unit/SKU |
| accessory_stock_movements | `(product_variant_id, business_unit_id, created_at)` | movement history |
| stock_transfers | `(status, from_unit_id)`, `(status, to_unit_id)` | open-transfer boards |

Deletion policy: no `ON DELETE CASCADE` from any master/financial table. Purchase lines cascade only from their still-draft purchase; cancelling is a status change. Handsets, cycles, events and movements are `RESTRICT`.

---

## 5. Planned entities for later phases (NOT migrated)

### Phase 2 — POS, invoices, accounting (implemented core; remaining items marked)

| Table | Status |
|---|---|
| `customers` | ✅ implemented |
| `accounts` | ✅ implemented (chart + per-unit payment accounts) |
| `sales`, `sale_items` | ✅ implemented with cost snapshots |
| `sale_payments` | ✅ implemented |
| `journal_entries`, `journal_lines` | ✅ implemented with balance/source-key invariants |
| `customer_payments`, `payment_allocations` | ✅ implemented for due collection |
| `cashier_shifts`, `cash_drawer_movements`, `cash_shift_reconciliations` | ⬜ planned |
| `refunds`, `credit_notes`, return lines | ⬜ planned (Phase 4 with returns/exchange) |

### Phase 3 — Installments / receivables

| Table | Key fields |
|---|---|
| `installment_contracts` | sale_id, customer_id, down_payment, financed_amount, term, first_due_date, status |
| `installment_schedule_items` | contract_id, seq, due_date, scheduled_amount, paid_amount, status |
| `installment_contract_versions` | versioned reschedules with approval |
| `receivables` | customer/shop ledger view or materialised subledger fed by journals |
| `notifications` | DB notifications for due/overdue with dedupe keys |

### Phase 4 — Repairs, exchanges, returns

| Table | Key fields |
|---|---|
| `repair_jobs` | job_no, serialized_unit_id, unit, vendor, issue, sent/expected/received, estimated/actual cost, paid/due, outcome, inspected_by |
| `repair_cost_items` | job_id, description, amount, capitalized flag |
| `repair_payments` | job_id, vendor, amount, account, paid_at |
| `repair_attachments` | job_id, path, type |
| `exchange_transactions` | exchange_no, outgoing_sale_id, incoming_serialized_unit_id, trade_in_value, difference_due, approved_by |
| `trade_in_items` | exchange_id, serial, grade, accepted_value, inspected_at |
| `refund_lines` | refund_id, sale_item_id, qty, amount, restock flag |

### Phase 5 — Expenses, payroll, approvals

| Table | Key fields |
|---|---|
| `expense_categories` | name, code, cash/bank account default, requires_approval |
| `expenses` | document_no, category, unit, payee, beneficiary_type, amount, account, reason, date, created_by, approved_by, status |
| `expense_beneficiaries` | expense_id, staff_id/guest_name, allocation_amount |
| `expense_attachments`, `expense_approvals` | evidence and approval trail |
| `payroll_periods`, `payroll_entries` | staff, gross, deductions, advances offset, net, status |
| `staff_advances` | staff, amount, date, settled_amount, receivable account |
| `payroll_payments` | entry_id, account, amount, paid_at |
| `approval_requests` | proposer, reviewer, action, before/after, status, reason, timestamps |

### Phase 6 — Reporting support

Materialised/read-model tables or views for aging buckets, daily sales/payments, stock valuation by age, staff payout statements, exports metadata and audit review. Reports themselves will query authoritative posted tables, not duplicate totals.

---

## 6. Data-integrity checklist for future migrations

1. Every new posted document gets a unique business number from `document_sequences`.
2. Every journal entry must satisfy `SUM(debits) = SUM(credits)`; enforce in a service and a DB check/trigger where the engine supports it.
3. Every payment allocation must be ≤ posted receipt and ≤ outstanding target.
4. Serialized sale items must be unique per posted invoice and must reference an open cycle at posting time.
5. No `CASCADE` delete on financial history; use status transitions and compensating entries.
6. Add composite indexes alongside the query that needs them; keep list endpoints server-paginated.
7. Money remains `DECIMAL(18,2)` (unit costs may use 4 decimals); never floats.
