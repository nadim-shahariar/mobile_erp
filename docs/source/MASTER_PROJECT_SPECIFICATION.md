# MOBILE SHOP ERP — MASTER PROJECT SPECIFICATION

**Version:** 1.0 | **Status:** Implementation-ready functional + technical blueprint | **Date:** 19 November 2026  
**Audience:** DeepSeek coding agent, Laravel developers, QA, product owner.  
**Language:** English (for code generation); UI must support English and Bangla.  
**Product concept:** Single-screen-first mobile handset shop ERP with Excel-like inventory, IMEI lifetime history, POS, accessories, purchases, exchange, repairs, unit transfers, installment/due collection, shop-to-shop wholesale invoicing, staff/cashier permissions, expense and salary tracking, accounting and reporting.

> **IMPORTANT:** This document is based on user-stated requirements and UI concepts from two shared Google Sheets screenshots. The complete Google Sheet, its formulas, row data and exact layout have **not** been independently read. All displayed sample figures, names and IMEIs are illustrative. Product-specific tax/legal/warranty rules, payment providers, printer model, hosting, and multi-tenant requirements remain configurable; do not invent facts.

---

## 0. HOW TO USE THIS WITH DEEPSEEK

1. Supply this entire file and `DEEPSEEK_BUILD_PROMPTS.md` to DeepSeek (or upload both). Treat this specification as the contract.
2. Ask the agent to **first inspect the repository, identify Laravel/PHP/database versions and existing modules**, and produce an implementation plan and migration map. For a new repo, scaffold in a clean project.
3. Build in the phases described in section 18. Work one bounded phase at a time; request full file paths and full file content or unified diffs, migrations, tests, seeders and commands.
4. Do **not** accept a UI-only mockup as completion. Each feature requires persistent database writes, authorization, validations, transaction safety, reports and tests.
5. Do not connect a production database or overwrite existing tables without verified backup and explicit approval.
6. If requirements are ambiguous, implement the documented safe default, expose settings where relevant and clearly state assumptions.

### Definition of the product

A staff member can work largely from one **Control Center**: search an IMEI, create/update an inventory row, sell a phone and multiple accessories, choose cash/bank/due payments, generate an invoice, send a phone to repair, transfer to another unit, receive an exchanged phone, collect installments, log staff food/guest expenses or pay salaries. Management can see stock, sales, income, collected money, debt, costs, profit, staff payouts and audit trails without opening numerous disconnected pages.

### Non-negotiable invariants

- An IMEI identifies **one physical handset across its lifetime**. It can have multiple inventory cycles and multiple sales over time but must not have two simultaneously active ownership/stock states.
- Every stock state transition has an immutable, timestamped actor-attributed event; reversals are compensating events, not history deletion.
- An item cannot be sold twice concurrently. A phone currently with a customer cannot be transferred from shop stock until formally returned/exchanged.
- Every money movement is recorded once in a double-entry journal or reconciled subledger; invoiced revenue is **not** the same as cash collected.
- Purchases of inventory are capitalized as inventory, not counted again as operating expenses. Salary advances are receivables/advances until earned/settled, not extra payroll expense.
- Role and unit scope are enforced server-side, not merely by hiding buttons.
- Finalized invoices and financial transactions must not be hard-deleted. Credit notes, refunds and reversal workflows are required.
- Every multi-table sales/exchange/payment/transfer transaction is atomic and idempotent where applicable.

---

## 1. ACTORS, BRANCHES AND ACCESS

### 1.1 Roles

- **Super Admin / Owner**: all units, configurations, exports, reporting, staff permission changes, accounting adjustments and approvals.
- **Admin / Manager**: configurable access to assigned unit(s); purchase/sales/repair/exchange/staff approvals and reports.
- **Cashier**: assigned unit POS, invoice print, collection, shift open/close, permitted minor expenses and due collection; no cost/profit view unless explicitly granted.
- **Staff**: limited inventory view/entry, repair/transfer initiation and assigned operational actions only.

Use policy/permission checks for every endpoint. Support custom permission grants; avoid hardcoding a single matrix.

### 1.2 Example permission names

`dashboard.view`, `inventory.view`, `inventory.cost.view`, `inventory.create`, `inventory.update`, `inventory.import`, `inventory.adjust`, `stock.transfer.create`, `stock.transfer.approve`, `repair.create`, `repair.receive`, `purchase.create`, `purchase.approve`, `pos.sell`, `pos.discount.override`, `sale.refund`, `sale.cancel`, `invoice.view`, `invoice.print`, `customer.view`, `customer.edit`, `installment.manage`, `payment.collect`, `exchange.create`, `exchange.approve`, `expense.create`, `expense.approve`, `payroll.run`, `staff.view`, `staff.manage`, `accounting.view`, `accounting.adjust`, `reports.sales`, `reports.cost_profit`, `reports.export`, `settings.manage`, `audit.view`.

### 1.3 Unit scoping

- A unit is a physical location/branch (Unit 1, Unit 2); configurable in `business_units`.
- User may be assigned one or more units via pivot table. UI defaults to selected unit, with Owner's All Units option.
- Stock transfers are `requested -> dispatched -> received` (optionally `cancelled/rejected`) to prevent phantom inventory between branches. In transit is distinct from available.
- Only users with both authorization and unit access can receive or sell a unit's inventory.

### 1.4 Staff profile

Employee code, name, mobile, email optional, designation, role(s), assigned units, join/leave date, salary settings, payment method, active/suspended status, secure password, last login, login attempts, activity log and optional emergency/contact information. Store only necessary personal data.

---

## 2. UX — ONE-PAGE CONTROL CENTER

### 2.1 Desktop layout

1. Header: brand/company selector (if enabled), Unit selector, global IMEI/model/customer/invoice search, time filter, current shift, notifications, current user.
2. KPI ribbon: Available Phone Stock, Stock Valuation, Today's Sales, Today's Cash Collection, Today's Bank/MFS Collection, Uncollected Due, Active Installments, Overdue Installments, Under Repair, Monthly Gross Profit, Operating Expenses, Net Profit.
3. **Quick Actions** (open modal/right-side drawer, without leaving main workspace): Add Phone, Bulk Entry, New Sale, Collect Due, Add Expense, Salary/Advance, Repair Send/Receive, Unit Transfer, Exchange, New Purchase, Invoice, Reports.
4. Main large Excel-like grid: configurable columns, grouped filters/tabs, sticky header, resizable columns, keyboard support and Row Actions.
5. Side/bottom widgets: Recent Actions, Overdue Alerts, Staff Payout Summary, Today Cash/Bank/Expenses, pending transfers/repairs.
6. Responsive fallback: horizontally scrollable grid on tablet; handset card view/compact scroll on mobile; sticky Sell button.

**Performance:** virtual scrolling/server pagination for 10k+ records. Do not load every IMEI into browser DOM. Filtering/sorting/search are server-side with indexes.

### 2.2 Inventory grid fields

Suggested default: Row No, Brand, Model, Storage, RAM, Color, IMEI 1, IMEI 2, Battery Health %, Cycle Count, Condition Grade, Variant, Box/Kit, Purchase Supplier, Purchase Price (restricted), Purchase Date, Expected Price, Unit, Current State, Last Activity, Selling Price, Profit (restricted), Sale/History/More.

- Inline cell edits have immediate validation, displayed unsaved/pending/saved states; Tab/Enter moves to next editable field.
- Paste rectangular TSV from Excel. Validate complete batch and show per-cell errors before commit; support partial acceptance only if user explicitly chooses it.
- Rows with duplicates/incomplete required fields cannot be finalized; allow optional Draft status.
- Column visibility, filter presets, fixed/hidden sensitive columns by permission.
- `Sell` only when sellable and physically available in user's authorized unit; otherwise disabled with tooltip/reason.
- Row menu: View, Edit, Sell, History, Transfer, Send to Repair, Receive Repair, Exchange In, Print Barcode, Reserve (optional).
- Color coding is supplementary; status text/icon remain visible for accessibility.

### 2.3 Sale drawer

Prefills IMEI, model, unit, current acquisition cost hidden from unauthorized staff. Add multiple accessories from catalog with quantity, unit price, discount and live stock validation. Customer or shop selection, walking customer creation, price override with policy, split payments (cash/bank/MFS/card/due), optional installment plan and invoice preview. Shows total, paid, due, change and selected payment account. Submit once; returns unique invoice, receipt and updated stock.

### 2.4 Expense drawer

Category shortcut chips: Staff Breakfast/Food, Guest Hospitality, Salary, Salary Advance, Bonus, Transport, Courier, Electricity, Rent, Repair, Other. Select one/multiple staff recipients (with allocations), guest name/contact optional, vendor/paid-to, amount, unit, funding cash/bank account, business reason, date/time, receipt photo optional, approval status. Keep **beneficiary** (staff/guest who consumed) distinct from **payee** (who received cash) and **entered by** (cashier). A shared meal may have one expense with several beneficiaries, not duplicated monetary postings.

### 2.5 Staff Payout and outside summary

Per employee: gross salary earned, salary paid, advance outstanding, reimbursed expenses, food/hospitality paid on their behalf, bonuses, deductions, net payable, money personally received, totals this month/year/all-time. Distinguish 'expense attributed to employee' from 'cash handed to employee'. Admin sees overall staff cost vs total income, cost, collections and net profit.

### 2.6 Requirements for UI quality

Responsive WCAG-aware forms, Bangla font support, fast keyboard workflow, toasts with clear undo/reversal semantics, skeleton loading, confirmation for consequential actions, sticky quick-actions, user-specific saved grid layouts and compact/dense mode. Never use UI-only restrictions for financial or cost data.

---

## 3. CATALOG, PROCUREMENT AND INVENTORY

### 3.1 Catalog vs physical units

`products` is catalog item (e.g., iPhone 13 128GB) with attributes. `serialized_units` represents **each physical handset** with unique normalized IMEI, optional second IMEI, identity/history. Accessories are quantity-tracked `stock_items` per branch; optional serialized accessories where necessary.

**IMEI rules:** store as **string**, not number (preserves leading zero); normalize whitespace. A valid IMEI typically has 15 digits and Luhn validation, but allow documented exceptions for legacy/manual inventory with flagged approval and do not invent IDs. IMEI 1 and IMEI 2 should not collide with another device's primary or secondary IMEI; use an IMEI identity table if enforcing uniqueness across both fields. Devices without valid IMEI must use an internally generated serial and flagged status; not silently bypass duplicates.

### 3.2 Purchase

Supplier/person name, contact, unit, date, documents, items, quantities, serials, total, taxes/fees optional, cost allocation, delivery/receipt status and purchase payment split/due. Purchase record updates inventory and supplier payable as appropriate; purchase cash payments reduce cash/bank, never counted as P&L operating expense at purchase time.

A phone can be bought from an individual or supplier/shop. Every acquisition cycle has source, acquisition price, receiving unit, date, grading and operator. Retain historical costs per cycle.

### 3.3 Accessories

Catalog SKUs and variants (charger, cable, case, glass, adapter, earphones etc.), supplier, purchase cost, retail price, stock by unit, reorder point, stock adjustment with reason, sale together with phone or separately. Prevent negative stock unless explicit authorized back-order workflow.

### 3.4 Stock statuses (separate dimensions)

Prefer normalized ownership/location/sellability dimensions over overloaded status field:
- Custody: `IN_UNIT`, `IN_TRANSIT`, `AT_REPAIR_VENDOR`, `WITH_CUSTOMER`, `RETURN_PENDING_INSPECTION`, `WRITTEN_OFF`.
- Sellability: `AVAILABLE`, `RESERVED`, `QUARANTINED`, `NOT_FOR_SALE`, `SOLD` (derived when with customer).
- Repair lifecycle in `repair_jobs`; financial settlement tracked separately.

A phone received from customer exchange is quarantined until inspected/graded, then posted into an active stock cycle. Explicitly prevent having active stock available before ownership/custody is accepted.

---

## 4. IMEI LIFETIME HISTORY — PRIMARY DIFFERENTIATOR

### 4.1 Mandatory sequence example

Single physical phone, single IMEI (`EXAMPLE-IMEI-001`; placeholder only):

1. Purchased and received in **Unit 2** (Acquisition Cycle #1).
2. Transferred **Unit 2 -> Unit 1**.
3. Transferred **Unit 1 -> Unit 2**.
4. Sent to outside repair vendor due to defect.
5. Returned from repair; actual repair cost and amount paid/due recorded; inspected and available again in Unit 2.
6. Sold to Customer A: sale invoice, price and payment splits recorded; custody moved to Customer A.
7. Customer A later returns this handset as **trade-in/exchange** for another phone; link original sale and new exchange invoice. Record new agreed trade-in value and any financial settlement; Acquisition Cycle #2 (distinct cost basis).
8. Inspection passed and received into **Unit 2**; now available for resale under SAME original IMEI.
9. Subsequent sale uses Cycle #2 valuation, not Cycle #1 cost. Every event remains queryable.

### 4.2 Data model for identity and cycles

- `serialized_units`: persistent id and immutable IMEI identity; no duplicate phone rows for repeat acquisition.
- `inventory_cycles`: id, serialized_unit_id, acquisition source type/id, unit_id, acquired_at, agreed_value, allocated costs, opening cost basis, closed_at, close reason. At most **one active owned cycle** per phone.
- `inventory_events`: append-only event id, serialized_unit_id, cycle_id optional, event type, from/to custody + unit, actor_id, happened_at (business date), created_at (system time), reference_type/id, note, idempotency_key, metadata JSON and reversal_of_event_id optional.
- `stock_transfers`: request, dispatch, receipt with actor/time; serialized items in transfer lines.
- `repair_jobs`: initial issue, vendor, sent/received dates, costs, vendor payable, customer charge (if any), outcome, attachments and serialized unit.
- `sale_items`: links to physical serialized unit AND exact inventory cycle and its cost basis snapshot.
- `trade_ins` / `exchange_transactions`: incoming trade-in serialized unit, value offered, outgoing sale item, linked original customer/old sale when applicable.

### 4.3 Example event stream

| Event | From | To | Reference | Notes |
|---|---|---|---|---|
| PURCHASE_RECEIVED | Supplier | Unit 2 | PUR-001 | Cycle 1 opened |
| TRANSFER_DISPATCHED | Unit 2 | Transit | TR-001 | Authorized by manager |
| TRANSFER_RECEIVED | Transit | Unit 1 | TR-001 | Verified IMEI |
| TRANSFER_DISPATCHED | Unit 1 | Transit | TR-002 | Return |
| TRANSFER_RECEIVED | Transit | Unit 2 | TR-002 | Available |
| REPAIR_SENT | Unit 2 | Vendor | REP-001 | Display issue |
| REPAIR_RECEIVED | Vendor | Unit 2 | REP-001 | Inspection passed |
| SOLD | Unit 2 | Customer A | INV-001 | Cycle 1 closed |
| TRADE_IN_ACCEPTED | Customer A | Inspection | EX-001 | Cycle 2 pending |
| INSPECTION_PASSED | Inspection | Unit 2 | EX-001 | Cycle 2 available |
| SOLD | Unit 2 | Customer B | INV-002 | Cycle 2 closed |

### 4.4 History screen

Search exact IMEI (either slot), show hero card with current unit/status, last owner/custodian (permission-restricted), acquisition cost, repairs count, sale count, transfer count, total repair cost, current cycle. Timeline with from/to, user, date/time, linked invoice/receipt, financial effects, before/after snapshots and export. Display past and present cycles distinctly. Access to past customer personal data permission-controlled.

### 4.5 State machine validation

- `AVAILABLE@UNIT` -> `IN_TRANSIT` -> `AVAILABLE@OTHER_UNIT`.
- `AVAILABLE@UNIT` -> `AT_REPAIR_VENDOR` -> `INSPECTION` -> `AVAILABLE@UNIT` / `QUARANTINED`.
- `AVAILABLE@UNIT` -> `WITH_CUSTOMER` via `SALE_POSTED`.
- `WITH_CUSTOMER` -> `INSPECTION` via posted return/trade-in -> `AVAILABLE@UNIT` when accepted.
- An exchange return does not mutate a posted prior sale: new transaction links to it.
- Invalid transition returns HTTP 409 with helpful detail; do not silently change state.

---

## 5. POS SALES, PAYMENTS AND INVOICES

### 5.1 Sale modes

Retail walk-in, known customer, shop/wholesale buyer, accessories-only, installment sale, partially paid sale, trade-in/exchange sale. Sale items may contain one or more serialized devices and/or multiple accessories.

### 5.2 Price and calculation

For item: `quantity * unit_price - item_discount`; invoice subtotal minus invoice discount plus configured taxes/fees = payable. Payments total cannot exceed payable unless change/refund handled explicitly. `due = payable - applied payments - valid credits`. Validate discount ceiling/approval, serialized stock available and quantity accessory stock sufficient.

Use decimal database types for BDT (e.g., DECIMAL(18,2)), never IEEE float to calculate money. Store integer minor units where more suitable, consistently.

### 5.3 Payment split

Record each tender separately: CASH, BANK_TRANSFER, CARD, BKASH, NAGAD, OTHER, CREDIT/DUE (note: **due is not a payment**, it is outstanding receivable). Each real receipt references account, amount, reference/transaction ID, collected_by, unit, shift and posted timestamp. A sale might be 50,000 with cash 15,000, bank 20,000, MFS 5,000 and receivable 10,000.

### 5.4 B2B shop invoice and due

Customer type `PERSON` or `SHOP`; shops have business name, contact, shop address, credit limit, terms and ledger. Invoice has shop identity, invoice no/date, item IMEIs, price, amounts paid, due, running shop balance on statement. Shop can take multiple phones on credit and settle them partially later. Payment allocation can be auto oldest-first or manually selected invoice; ledger transparently shows allocations.

### 5.5 Invoice

Unique human-readable sequence per series (e.g., `INV-2026-000001`), issuer/shop/unit, customer, products/serials/accessories, tax/discounts if applicable, per tender amounts, due, terms, issued_by, payment history and print copies. A4 and thermal-friendly HTML/PDF, reprint with audit log, optional logo/signature. Never modify a finalized invoice without formal correction workflow. Receipt of due payment has separate collection receipt no.

### 5.6 Refund and return

Authorization, reason, proof/reference, customer refund tender vs store credit, accessories restock policy, serial inspection and original transaction links. Refunds/reversals post counter-ledger entries; no deleting sold items. Returned device must pass inspection before availability.

---

## 6. INSTALLMENTS & PARTIAL PAYMENTS

- A sale may have an upfront down payment, financed balance, agreed monthly schedule, first due date, term count and configurable fees if business permits.
- Build `installment_contracts`, `installment_schedule_items`, `payment_allocations` and `receivables`.
- Generate schedule once when contract posted, store contractual due amounts and dates; changes require versioned reschedule and approval. Calendar-month due handling: if day 31 missing in some months, use **last valid day** (configurable).
- Collections may be partial, early or late; allocate oldest overdue installment first by default, permit policy-controlled allocation. For each schedule line show scheduled, paid, outstanding, due_date, paid_at, days overdue.
- Notifications: due today, upcoming configurable days, overdue 1+, aging buckets 1-7/8-30/31-60/60+ days; in-app persisted notifications at minimum. Optional WhatsApp/SMS requires explicit integration and consent.
- Overdue is computed from outstanding > 0 and due_date < today in business timezone (`Asia/Dhaka` default configurable). Dismissing notification never clears due.
- Receipt posts bank/cash once, allocates to contract and updates customer receivables. Edits are approved reversal/new receipt.
- Prevent double counting: 'monthly installment collection' is cash recovery of past receivable, not new sales revenue.
- Contract screen: customer, IMEI(s), original invoice, schedule, all collected payments, next due, arrears, future installments, reminders and authorized reschedule.

---

## 7. REPAIR SYSTEM

`repair_jobs`: job no, serialized handset IMEI, sent from unit, vendor, issue, sent_at, expected return date, photos, estimated cost, actual cost, paid-to-vendor, vendor due, received_at, condition, outcome, inspected_by, target unit, customer charge if customer-paid repair.

Repair board: sent today, at vendor, ready, completed, overdue vendor, amount payable to vendor and amount receivable from customer. These two directions **must not be confused**.

Transitions: `CREATED -> SENT -> UNDER_REPAIR -> READY -> RECEIVED -> CLOSED`, optional `CANCELLED/UNREPAIRABLE`. Upon receipt, technician and staff sign-off; move to quarantine until inspection, then available or parts/write-off. If phone is being repaired for an external customer rather than owned inventory, track external custody separately and do not add to owned stock.

Repair amount paid to vendor decreases cash and settles payable; whether cost is capitalized into current handset stock value or booked as current operating expense follows configurable accounting treatment and documented policy (avoid double count). Recovery charged to customer is invoice/service revenue or receivable separately.

---

## 8. EXCHANGE / TRADE-IN

Exchange transaction combines **outgoing sale** and **incoming trade-in acquisition** atomically:

- Outgoing new phone serial, selling price and accessories.
- Incoming old phone IMEI, its current customer ownership state, agreed trade-in valuation (customer-accepted old phone price), grade/condition and original invoice link if sold here.
- Difference due: `outgoing sale total + additional charges - incoming accepted trade-in credit - other valid credits`.
- Receive cash/bank/due or refund amount if negative. Negative balances require explicit refund/credit handling.
- Incoming trade-in opens new inventory cycle at accepted acquisition value, potentially with additional refurbishing costs; old sales remain untouched. Quarantine until inspection; on approval available in chosen unit.
- Record who approved valuation and exchange. No automatic duplication of IMEI identity; ensure current ownership is valid and no active shop cycle conflicts.
- Produce exchange memo showing old IMEI and value, new IMEI and price, additional cash, payment split and due.
- For trade-in from a phone not previously in inventory, create its persistent IMEI identity as part of acquisition; same validation applies.

---

## 9. STAFF / CASHIER / SHIFT / PAYROLL

### 9.1 Staff money flows

Expense entry must support category, reason, beneficiary staff(s)/guest(s), actual payee, cash/bank account, unit, creator, approver, receipt and timestamps. Examples:
- Cash handed to Rahim for breakfast for three employees; **one** ৳300 expense attributed across beneficiaries, one cash payout to Rahim.
- Salary paid to Rahim: linked to a payroll obligation/pay period; cash/bank payout and settlement.
- Salary advance ৳3,000: creates an advance asset/employee receivable, not payroll expense; later deduct from net payable.
- Bonus paid: wage expense if earned and not previously accrued; payroll statement shows it.
- Guest hospitality: optional guest name, host staff and reason; attributed to guest event, not staff salary.

### 9.2 Payroll and monthly staff statement

Employee salary, pay period, worked/approved adjustments optional, accrual, advances, reimbursements, bonus, deductions, paid vs outstanding, salary payment receipts. Monthly staff statement must distinguish: salary owed, salary paid, advances still owed, personal cash received, expense consumed/allocated (food), company payroll expense and all attributed expense.

### 9.3 Cashier shifts

Shift opening float, user, unit, register and start; every collected cash sale/due/expense linked to shift; closing expected cash = opening cash + valid cash receipts - cash payouts - cash refunds/transfers. Cashier enters actual count; variance logged and supervisor approved. Bank/MFS collections are **not** cash in drawer. Shift re-opening/corrections auditable.

### 9.4 Approval and thresholds

Configurable limits by permission/unit/category: discounts, refunds, expense amount, exchange valuation, inventory adjustment, stock transfer, salary. Approval request with proposer, reviewer, reason, before/after and approved/rejected timestamp; only approved actions post ledger if approval required.

---

## 10. ACCOUNTING PRINCIPLES (MUST IMPLEMENT CONSISTENTLY)

Implement auditable chart of accounts and journal lines or an equivalently rigorous balanced-ledger layer. Example account classes: Cash in Drawer, Bank, Mobile Financial Services, Trade Receivables, Installment Receivables, Supplier Payables, Staff Advance Receivables, Inventory Handsets, Inventory Accessories, Sales Revenue, Repair Service Revenue, Cost of Goods Sold, Payroll Expense, Staff Food, Guest Hospitality, Rent, Utilities, Transport, Repair Expense, Discounts/Returns, Owner Equity and Adjustments.

### 10.1 Posting examples (conceptual)

| Business event | Debit | Credit |
|---|---|---|
| Purchase phone on cash | Inventory | Cash |
| Purchase phone on credit | Inventory | Supplier Payable |
| Sale on credit | Customer Receivable | Sales Revenue |
| Cost of phone sold | COGS | Inventory |
| Collect customer due | Cash / Bank | Customer Receivable |
| Staff food purchased cash | Staff Food Expense | Cash |
| Salary accrued | Salary Expense | Salary Payable |
| Salary paid | Salary Payable | Cash / Bank |
| Salary advance | Staff Advance Receivable | Cash / Bank |
| Supplier payable settled | Supplier Payable | Cash / Bank |

Trade-in with noncash credit and split tenders needs a **balanced composite posting**; design it carefully with finance tests and documented inventory valuation, not naive `revenue = cash received` logic.

### 10.2 Metrics

- **Sales**: posted invoice net revenue in date range, net of posted returns/credits.
- **Collections**: real money received in date range from all sale and due receipts; split by payment account.
- **Outstanding due**: unpaid invoice/contract receivables as of cutoff.
- **Purchases**: acquired inventory amount; not operating expense.
- **Stock value**: current owned on-hand inventory at chosen consistent cost basis; stock in transit/at vendor shown separately.
- **COGS**: cost basis of items actually sold (with returns adjusted).
- **Gross profit**: net sales revenue - COGS (subject to configured treatment of direct costs).
- **Net operating profit**: gross profit + other operating income - operating expenses, as properly recognized; excludes loan principal movements, cash transfers, salary advances and inventory purchases as expenses.
- **Cash balance**: cash opening + actual receipts - actual disbursements.
- **Profit vs cash flow** must always appear as separate metrics.

Tax/VAT treatment is configurable and must be reviewed against applicable local rules before production; do not hardcode rates or legal assumptions.

---

## 11. REPORTS & ALERTS

Each report must support date range, unit filter, actor filter where meaningful, detail drill-down, authorization and CSV/XLSX/PDF exports where feasible.

| Report | Contents |
|---|---|
| Daily sales & payments | Invoice, staff, customer, phones/accessories, gross/net, discounts, cash/bank/MFS, due |
| Monthly sales | totals, trend, return adjustments, units, product types |
| Inventory summary | available IMEIs, per-unit stock, in transit, repair, quarantine, reserved |
| Stock valuation | cost per active cycle + accessory quantity cost, by unit and age |
| Purchase/supplier | purchased from whom, price, stock received, payable, settlement |
| Customer/shop due | original invoices, applied collections, outstanding, aging, credit limit |
| Installments | contract, schedule, next due, partial paid, days overdue, reminders |
| Repair | phone counts, vendor, sent/received, cost, repair income, receivable/payable |
| Unit transfer | movement history, sender, recipient, pending in transit |
| Exchange | incoming/outgoing serials, accepted value, additional customer payment, new cost basis |
| Expense | daily/monthly by reason/category/payee/beneficiary/staff/unit |
| Staff payment | salary owed/paid, advances, food money attributed, bonus, cash actually received |
| Cashier shift | opening, cash receipts, disbursements, actual vs expected, variance |
| Profit/loss | net revenue, COGS, gross margin, expenses, other income, operating profit |
| IMEI life history | all movements, repairs, owner/custody changes, invoices and acquisition cycles |
| Audit / approvals | role-aware changes, denials, adjustments, reversals, approvers |

Alerts: overdue installments; shop/customer dues; unreceived transfer; repair due back; negative cash variance; low accessories stock; duplicated IMEI attempt; approval pending. Store notifications as records; configure read/unread and deduplication. In-app required; SMS/WhatsApp optional.

---

## 12. DATABASE SCHEMA — REQUIRED ENTITIES

Choose conventional Laravel migrations. ALL transactional tables need numeric internal PK, business unit scope where applicable, created_at/updated_at, actor refs, unique business refs and safe money DECIMAL. For financial records, avoid destructive cascade deletes. Index IMEI, invoice number, unit/status/date and overdue due-date.

### 12.1 Core tables

- `business_units`: id, code(unique), name, address, phone, active, timezone.
- `users`: Laravel auth, active, last_login_at; `staff_profiles`: user_id(unique), code, designation, joined_at, left_at, salary_config.
- Spatie roles/permissions tables + `user_business_units(user_id,business_unit_id)`.
- `customers`: type(PERSON/SHOP), name, business_name, phone, address, credit_limit, notes.
- `suppliers`: type(PERSON/BUSINESS), name, phone, address, notes.
- `products`: product_type(PHONE/ACCESSORY), brand_id, name, model, SKU, specification JSON, default unit and selling prices.
- `product_variants`: product_id, SKU, storage, RAM, color, grade etc. `brands`, `categories` if needed.
- `serialized_units`: variant_id, primary_imei, secondary_imei, normalized keys/identity, current_custody, current_unit_id(nullable), sellability_status, last_event_id, version, battery_health, cycles, box_kit, notes.
- `serialized_identifiers`: serialized_unit_id, identifier_type(IMEI1/IMEI2/OTHER), normalized_value(unique) to block cross-column duplicates.
- `inventory_cycles`: serialized_unit_id, cycle_no, source_type/id, purchase/acquisition_value, allocated_direct_cost, effective_cost_basis, initial_unit_id, acquired_at, closed_at, closing_type; guard one active cycle.
- `inventory_events`: unit/cycle, event_type, from/to unit, custody, reference, actor, business/system timestamps, note, reverses_event_id.
- `accessory_stock_balances`: business_unit_id, variant_id, qty_on_hand, qty_reserved, version; unique(unit,variant).
- `accessory_stock_movements`: variant, unit, qty delta, reason, source doc, cost, actor.
- `purchases`, `purchase_lines`, `purchase_receipts` and `supplier_payments`.
- `stock_transfers`, `stock_transfer_lines` (serialized_unit_id OR accessory variant + qty), dispatch/receive actors/dates.
- `repair_jobs`, `repair_cost_items`, `repair_payments` and `repair_attachments`.

### 12.2 Sales/finance tables

- `sales`: invoice_no(unique), type(RETAIL/SHOP), customer_id, unit_id, subtotal, discounts, taxes(optional), grand_total, status(DRAFT/POSTED/PARTIALLY_REFUNDED/REFUNDED/VOIDED), posted_at, created_by.
- `sale_items`: sale_id, variant_id, serialized_unit_id(nullable), inventory_cycle_id(nullable), qty, unit_price, discount, line_total, cogs_cost_snapshot; uniqueness to prevent serial duplicated in a posted invoice.
- `accounts`: cash/bank/MFS, name, opening balance (as journal), unit ownership scope; `payments`: direction(IN/OUT), account_id, source_type/id, receipt_no, amount, actor, reference, posted_at, reversal_of.
- `payment_allocations`: payment_id, invoice_id / installment schedule line reference, amount; ensure allocation <= posted receipt and target outstanding.
- `installment_contracts`, `installment_schedule_items`, `installment_contract_versions` for approved reschedules.
- `customer_ledger` optional derived view/subledger if posting from journals; never conflict with authoritative journal.
- `exchange_transactions`, `trade_in_items`, `refunds`, `credit_notes`.
- `expense_categories`, `expenses`, `expense_beneficiaries`, `expense_attachments`, `expense_approvals`.
- `payroll_periods`, `payroll_entries`, `staff_advances`, `payroll_payments`.
- `cashier_shifts`, `cash_drawer_movements`, `cash_shift_reconciliations`.
- `chart_of_accounts`, `journal_entries`, `journal_lines` with `sum(debits) == sum(credits)` per posted entry, unique source posting key.
- `approval_requests`, `notifications`, `activity_logs`, `audit_logs`, `settings`, `document_sequences`, `idempotency_keys`.

### 12.3 Critical constraints and indexes

- Unique all normalized IMEI identifiers across both slots.
- Unique `(serialized_unit_id, cycle_no)` and database-protected one-open-cycle rule (via transaction lock plus database constraint/lock scheme compatible with chosen DB).
- Product unit `version` for optimistic concurrency. Use `SELECT ... FOR UPDATE` in posted actions.
- Unique invoice/receipt/repair/transfer numbers; document sequence allocated transactionally.
- Unique payment gateway reference if provided; idempotency keys on critical POST requests.
- Foreign keys and restricted deletion for posted financial/IMEI event data.
- Composite indexes `(unit_id,status)`, `(due_date,remaining_amount)`, `(posted_at,unit_id)`, `(serialized_unit_id,created_at)`.
- All ledger amounts nonnegative at line level, positive when applicable, posted journal must balance, period close enforcement.

---

## 13. APPLICATION ARCHITECTURE & CODE ORGANIZATION

**Preferred stack (if starting new):** Laravel current supported stable version compatible with production hosting and selected PHP version, MySQL 8+ or MariaDB compatible, Blade + Livewire + Alpine.js + Tailwind CSS, queues for alerts/export, Laravel Policies/Gates, Spatie permission (compatible version), PHPUnit/Pest tests. Verify current compatibility from installed local packages; do not assume a version. Optional Redis for caching/queues only if available. Default timezone `Asia/Dhaka` (configurable), currency BDT, localization English/Bangla.

**Suggested modules/services:**

- `Domain/Inventory/`: SerialIdentityService, InventoryCycleService, StockMovementService, InventoryGridQuery.
- `Domain/Purchases/`: ReceivePurchaseService, SupplierSettlementService.
- `Domain/Sales/`: PostSaleService, DiscountAuthorization, InvoiceService, ReturnService.
- `Domain/Installments/`: ScheduleService, AllocateCollectionService, OverdueNotifier.
- `Domain/Repairs/`: SendRepairService, ReceiveRepairService.
- `Domain/Exchanges/`: ExecuteExchangeService.
- `Domain/Expenses/`: RecordExpenseService, ExpenseApprovalService.
- `Domain/Payroll/`: AccrualService, AdvanceService, PayrollPaymentService.
- `Domain/Accounting/`: JournalPostingService, ReversalService, ReportsService.
- `Domain/Staff/`: Permissions, CashierShiftService.

Controllers/Livewire components should call domain services and policies; never place money/inventory posting logic in UI components. Use domain events for notifications; queue noncritical side effects **after commit**. Unique DB posting key to guarantee exactly-once transaction effects under retries.

### 13.1 Proposed route/API examples

| Method | Route | Purpose |
|---|---|---|
| GET | `/dashboard` | Control Center |
| GET | `/api/inventory` | Filtered paginated grid |
| POST | `/api/inventory/quick-entry` | Validated bulk/inline entry |
| GET | `/api/imei/{imei}/timeline` | Lifetime history |
| POST | `/api/sales` | Atomically post POS invoice |
| POST | `/api/sales/{sale}/payments` | Collect against due |
| GET | `/invoices/{sale}/print` | Secure printable invoice |
| POST | `/api/transfers` | Request unit transfer |
| POST | `/api/transfers/{transfer}/dispatch` | Move to in-transit |
| POST | `/api/transfers/{transfer}/receive` | Receive stock |
| POST | `/api/repairs` | Send to repair |
| POST | `/api/repairs/{repair}/receive` | Receive repair |
| POST | `/api/exchanges` | Atomic sale + trade-in |
| POST | `/api/installments/{contract}/collect` | Post collection |
| POST | `/api/expenses` | Quick expense |
| POST | `/api/payroll/advances` | Staff advance |
| GET | `/reports/profit-loss` | Authorized financial report |
| GET | `/reports/staff-expenses` | Staff payment/beneficiary report |
| POST | `/api/shifts/open` | Open cashier shift |
| POST | `/api/shifts/{shift}/close` | Reconcile shift |

Use web routes vs REST API consistently as suited to actual chosen stack; route names above are logical, not mandatory rigid paths. Paginated list endpoints must filter records by unit permission.

### 13.2 Security / reliability

CSRF/session protection, auth rate limiting, hashed credentials, optional 2FA for admins, policies and data scopes, encrypted sensitive settings, safe export authorization, validation of uploads and file types, no secrets in repo, audit logs with IP/device summary, queue retries, scheduled database backups, daily operational checks, recovery drill and soft-delete only where consistent. Protect customer personal information, least-privilege access, and anonymize test/seed data.

---

## 14. KEY WORKFLOWS (ACCEPTANCE CONTRACTS)

### 14.1 Sale phone + accessories + payment split

1. Cashier selects AVAILABLE phone in assigned Unit 1 by IMEI and adds 2 charger accessories.
2. System locks serial and accessory balance, rechecks quantity/custody, validates role and discounts.
3. Total calculated in decimals. Customer pays cash + bank; remainder is customer/shop due (not fake tender).
4. Atomically create posted invoice and item rows, cost snapshots, transfer handset custody to customer, decrement accessories quantity, close inventory cycle, post journal and payment records, append IMEI event.
5. Generate reprintable invoice, surface sale and due in respective reports; cashier shift cash increases only by cash portion.
6. If any step fails, **none** of the business state changes persist.

### 14.2 2-unit transfer round-trip

Unit 2 -> in transit -> Unit 1, then Unit 1 -> in transit -> Unit 2. Each dispatch/receipt creates distinct event with staff and dates; unit stock counts reflect custody, in-transit never sellable.

### 14.3 Repair + exchange IMEI lifetime

Same handset goes Unit 2 -> Unit 1 -> Unit 2 -> Repair Vendor -> Unit 2 -> Sold to Customer A -> Exchange Accepted -> Inspection -> Available Unit 2. Ensure all milestones visible on one IMEI timeline and previous sale unchanged. Cycle #2 cost = agreed trade-in value plus permissible allocated costs; subsequent sale uses Cycle #2 cost.

### 14.4 Partial installment payment

Invoice due 45,000 split into six 7,500 installments; customer pays only 3,000 for first due. First schedule entry remains 4,500 outstanding. Overdue notification appears after due date until remaining is paid; subsequent 4,500 collected once posts one receipt and clears that line. Overall original sale revenue unchanged by collections.

### 14.5 Expense attributed to staff

Cashier gives Rahim 300 to buy breakfast for Rahim, Karim and Sabbir. Record one 300 cash outflow, payee Rahim, category Staff Food, three beneficiaries (allocations may be 100 each), note and cashier. Monthly report: Staff Food expense 300 total; allocation reports show 100 per person; Rahim cash handed 300 but **not** extra salary.

### 14.6 Salary advance

Pay Rahim advance 3,000, register decrease in cash/bank and staff advance receivable. When net salary payable 15,000 is processed, offset 3,000 advance and pay 12,000. P&L salary expense should reflect 15,000 total once, not 18,000.

### 14.7 Shop customer credit

Shop B buys 3 phones for 100,000, pays 25,000 bank, owes 75,000. Shop has invoice with all IMEIs and due. Later pays 20,000 cash; shop outstanding becomes 55,000. Sale revenue stays 100,000; collections are 45,000 total, split by accounts and dates.

---

## 15. VALIDATION, EDGE CASES & RACE CONDITIONS

- Two users click Sell on same IMEI within 100ms: exactly one succeeds; loser gets 409. No duplicate journal/invoice.
- Two users try to receive same transfer twice: second request idempotent or rejected; stock not doubled.
- Primary IMEI in one phone entered as secondary IMEI of another: reject.
- Phone sale with no valid owned active acquisition cycle: reject.
- Exchange of a phone that is still ACTIVE stock at another unit: reject until legally valid ownership/state resolved.
- Cashier cannot change purchase price/profit via API by sending hidden fields.
- Expense without reason, account, amount, recipient/payee as required: validation error.
- Guest expense with blank guest name allowed only if business policy permits anonymized/unnamed guest; category and business reason always required.
- Payment larger than outstanding: explicitly allocate change/store credit or reject; never silently overpay.
- Partial installment payments, reschedules and refunds reconcile to original invoices.
- Inconsistent bank/cash transfer must not appear as revenue/expense.
- Repair vendor payable is not customer receivable.
- Cancel/reverse transfer or invoice preserves chronological history.
- Late period adjustments use controlled accounting policy, not silent historical modification.
- Timezone boundaries on daily reports use configured local timezone, while DB timestamps are stored UTC.
- Negative profit is allowed and visible; do not force every sale to show positive margin.
- Zero cost, missing supplier, unknown battery/cycle information must be explicitly labelled, not misrepresented as factual zeros.
- CSV import: injection-safe exported cells; prevent spreadsheet formula injection in downloaded reports.

---

## 16. QUALITY ASSURANCE & TEST STRATEGY

### 16.1 Automated tests (required)

- Unit tests: IMEI normalizer/identifier uniqueness; transition guards; item totals; installment schedule and partial allocation; salary advance settlement; gross/net profit calculation; cash shift reconciliation.
- Feature tests: admin/cashier/staff permissions; unit scope leakage; sale with phone/accessories/splits/due; B2B shop ledger; purchase and supplier payable; round-trip transfer; repair receive; trade-in resale; expense with multiple staff beneficiaries; payroll; reporting filters.
- DB concurrency tests: concurrent sale, double payment retry, duplicate IMEI, receive transfer twice.
- Ledger invariant tests: every posted journal balanced; AR/AP agree with due; stock quantity not negative; valuation/COGS reconciliation; collections not double counted as revenue.
- Browser/UI tests: Excel-like paste and validation, keyboard tab navigation, filter search, Sell drawer, side panel, print invoice, expense shortcut and responsive view.

### 16.2 Minimum UAT scenarios

**UAT-01** create 10 phones, import from XLSX, verify 10 distinct identifiers and two unit stock counts.  
**UAT-02** transfer phone Unit 2 -> Unit 1 -> Unit 2, verify full timeline.  
**UAT-03** repair sent & received, cost settled by cash and vendor due; no stock during vendor custody.  
**UAT-04** sell same phone, later receive exchange, inspect and resell; one IMEI history, two inventory cycles, two invoices and correct per-cycle margin.  
**UAT-05** sell phone plus accessories with cash/bank/due split and printable invoice.  
**UAT-06** installment: partial pay, overdue alert, later pay, no doubled revenue.  
**UAT-07** wholesale shop takes 2 phones on credit; print shop invoice; collect partial amount.  
**UAT-08** staff breakfast for multiple beneficiaries and salary advance; total expense and salary accounting reconciles.  
**UAT-09** cashier cannot see cost/profit or change permissions by direct API call.  
**UAT-10** daily/monthly sales vs payments vs expense vs profit vs stock value match authoritative posted transactions.  
**UAT-11** simultaneous Sell by two cashiers succeeds only once.  
**UAT-12** cash shift close shows exact expected cash and records shortage/surplus.

### 16.3 Acceptance definition

A feature is **Done** only with migrations, models, service layer, validation, policies, UI, happy/unhappy/concurrency tests, audit entries, ledger integration where applicable and updated documentation. Seed/demo numbers should be visibly labelled Demo and never imply a live connection to the user's Google Sheet.

---

## 17. DATA MIGRATION FROM EXISTING GOOGLE SHEET

Since only screenshots were shown, do **not** claim full sheet schema/formulas known. Build import wizard for .xlsx/.csv with preview and manual mapping. Expected fields from screenshot interpretation include Model, IMEI, Storage, Battery Health, Cycle Count, Color, Variant, Category, Brand, Box/Kit, Buying Party, Buy Price, Receiving Date, Stock Status, Selling Price, Selling Date, Profit/Loss, Unit, Repair and Customer/Accessory information. Verify headers against actual export before mapping.

Import steps: upload -> header mapping -> normalize dates/amount/IMEIs -> detect duplicates -> dry-run -> correction report -> approved commit -> import batch log and rollback-by-compensating-entries (no destructive rollback of subsequently used stock). For historical sold items, import as historical transactions with explicit `legacy_import` markers or separately archived legacy rows; do not fabricate missing invoices/payment journal entries or phantom active stock. Never auto-derive inaccurate financial history from screenshot figures alone.

---

## 18. IMPLEMENTATION ROADMAP & DELIVERABLES

### Phase 0 — Repository and infrastructure

Read repository, inventory existing schema, dependency and auth packages, establish migrations, tests, local `.env.example`, backup strategy, user roles/units/settings, demo seed. Deliver ERD, migration plan and risk list. Do not start by building animated UI.

### Phase 1 — Core inventory + grid

Catalog, accessories, unique IMEI identity, stock cycles/events, purchase entry, per-unit stock balances, Excel-like Livewire grid, bulk entry/import, search/filter, role-guarded costs, IMEI timeline. **Gate:** round-trip transfer/state tests and exact stock counts.

### Phase 2 — POS, invoices and accounting foundation

Customers/shops, tender accounts, journals, POS mixed items, cash-bank-due splits, invoice and due collection, stock/COGS post, cashier shifts. **Gate:** all postings balance; concurrent Sell test passes.

### Phase 3 — Installment, overdue, shop credit

Installment plan builder, payment allocation, partial/advance payments, overdue rules, notifications, shop statements and printable receipts. **Gate:** due balances reconcile to invoices.

### Phase 4 — Repair, transfers, exchange and returns

Vendor repair, cross-unit dispatch/receipt, inspection, exchange and reuse of same IMEI with new cycle, refunds/credit notes. **Gate:** full lifetime UAT and per-cycle valuation tests pass.

### Phase 5 — Expenses, staff, payroll, approvals

Quick expense category/recipient, shared food beneficiary allocation, staff monthly statement, salary advances, payroll, approvals, cash reconciliation. **Gate:** salary and expense reports reconcile to journals.

### Phase 6 — Reports, exports, deployment hardening

Date/unit/actor filters, full reporting and accounting reconciliation, XLSX/CSV/PDF export, DB indexes, backup, accessibility, mobile view, localization, audit review, UAT test suite, deployment guide and admin training. **Gate:** UAT-01 to UAT-12 pass.

**Expected files from developer at every phase:** migrations, models, policies, services, controllers/components, views, factories/seeders, tests, route definitions, installation/migration commands, notes on breaking changes and manual UAT steps. Prefer PR-size deliverables rather than massive one-shot code dumps.

---

## 19. DEFINITION OF SUCCESS

The owner can open a single Control Center and answer within seconds:

- How many phones are in Unit 1 vs Unit 2, in repair, in transit and sold?
- What is full lifetime history of a specific IMEI, including every transfer, repair, sale, return, exchange and resale?
- Who purchased a phone, how much did we pay and how much do we now value it at in its current acquisition cycle?
- How much was sold today vs actually received in cash, bank/MFS or still due?
- Which installments and shops are overdue, and who has paid partially?
- How much money was given to each staff member, for what reason, and what is salary vs advance vs food?
- Which expenses are for guests or staff, who paid them, and which cash/bank account was affected?
- What are month-to-date revenue, COGS, gross profit, expenses, operating profit, stock valuation and cash balance?

**If any number cannot be drilled down to immutable source transactions, the implementation is incomplete.**

---

## 20. OPEN QUESTIONS / CONFIGURABLE DEFAULTS

The user has not yet specified: company/business name for invoice, exact Laravel repository, MySQL vs SQL Server deployment preference, number of shop units, exact unit names, existing database, thermal printer paper width, salary structure, approval thresholds, payment accounts, returns/warranty policy and tax treatment. Implement generic defaults with admin settings; do not block the entire project. Present these questions only when the specific implementation phase requires them.

**Final instruction to coding agent:** Maintain a living `IMPLEMENTATION_STATUS.md` documenting completed files, migrations, tests and outstanding scope. No fake buttons, fake reports, empty action handlers, fabricated live metrics or destructive DB resets. Build operational correctness first, then polish the UI.
