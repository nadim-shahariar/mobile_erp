# Authentication and permission design

---

## 1. Authentication

| Concern | Implementation |
|---|---|
| Login | Laravel session guard, email + password, bcrypt (cost 12) |
| Public registration | **disabled** — `/register` returns 404; staff accounts are created by an owner/manager |
| Password reset | Breeze token flow (requires a configured mailer; default `log` in development) |
| Email verification | Optional Laravel verification routes exist; accounts are created pre-verified by the owner |
| Session | database session driver; CSRF on all web forms; `SESSION_ENCRYPT` configurable |
| Brute force | 5 attempts/minute per `email|ip` plus Breeze throttling; lockout column exists on users for future use |
| Account state | `is_active` + `locked_until` checked at login **and** on every request (`EnsureUserIsActive`) |
| Login audit | `last_login_at`, `last_login_ip`, `failed_login_attempts`, `audit_logs(event=auth.login)` |
| 2FA | not implemented (config flag reserved); recommended before production for owner accounts |
| Password change | `/profile` (Breeze) — current password required; hashed by cast |

A suspended user with an existing session is logged out on the next request and redirected to login with an explanatory error.

---

## 2. Authorization model

Two independent gates are always required for data access:

1. **Permission** (role/permission via spatie) — *what kind of action*.
2. **Unit scope** (`user_business_units` via `UnitContext`) — *which branch*.

A cashier with `inventory.view` but no assignment to Unit 2 can never read Unit 2 stock, even by changing request parameters. Owners hold every permission but still only see assigned units.

### Enforcement points

| Layer | Mechanism |
|---|---|
| Route | `auth`, `active`, `can:permission` / `can:policyAction,Model` middleware |
| Livewire action | `$this->authorize('permission')` or policy call before mutation |
| Query | `InventoryGridQuery`, `InventoryMetrics`, purchase/transfer lists start from `accessibleUnitIds()` |
| Service | Unit access checks and state checks inside transactions (defence in depth) |
| View | `@can` hides links — cosmetic only, never the control |
| Data | cost columns never selected/rendered for users without `inventory.cost.view` |

### Unit scope resolution

```
session erp.active_unit_id
   → validated against user_business_units
   → fallback to pivot default, then first accessible unit
```

`UnitContext::authorize($user, $unitId)` throws `UnitAccessDeniedException` (HTTP 403) for any unit outside the pivot. Lists default to **all accessible units**, never "all units in the system".

---

## 3. Permission catalogue

Implemented permissions are used by Phase 0/1 endpoints. Permissions marked *reserved* are seeded for later phases and currently guard no endpoint; they must not be advertised as working features.

| Permission | Used now | Purpose |
|---|---|---|
| `dashboard.view` | ✓ | control center |
| `inventory.view` | ✓ | grid, timeline |
| `inventory.cost.view` | ✓ | cost basis / valuation / price columns |
| `inventory.create` | ✓ | manual intake |
| `inventory.update` | ✓ | profile + IMEI correction |
| `inventory.import` | ✓ | bulk entry, Excel wizard |
| `inventory.adjust` | ✓ policy | accessory adjustment service (UI pending) |
| `inventory.imei.override` | ✓ | legacy / unverified IMEI flagging |
| `purchase.view` | ✓ | purchase list/detail |
| `purchase.create` | ✓ | draft purchase |
| `purchase.approve` | ✓ | receive/cancel purchase, commit import |
| `supplier.view` | ✓ | supplier list |
| `supplier.manage` | ✓ | create/edit supplier |
| `catalog.view` | ✓ | products + variants |
| `catalog.manage` | ✓ | create product/variant |
| `stock.transfer.view` | ✓ | transfer list/detail |
| `stock.transfer.create` | ✓ | request transfer |
| `stock.transfer.approve` | ✓ | dispatch/receive transfers |
| `repair.create`, `repair.receive` | reserved | Phase 4 |
| `pos.sell` | ✓ | POS sale posting |
| `sale.refund`, `sale.cancel`, `pos.discount.override` | reserved | Phase 4 approval flows |
| `invoice.view`, `invoice.print` | ✓ | sales list + printable invoice |
| `customer.view`, `customer.edit` | ✓ | customer/shop records and POS quick-add |
| `installment.manage` | reserved | Phase 3 |
| `payment.collect` | ✓ | due collection receipts |
| `exchange.create`, `exchange.approve` | reserved | Phase 4 |
| `expense.create`, `expense.approve` | reserved | Phase 5 |
| `payroll.run` | reserved | Phase 5 |
| `staff.view`, `staff.manage` | ✓ | staff screen |
| `accounting.view`, `accounting.adjust` | reserved | Phase 2 |
| `reports.cost_profit` | ✓ | gross-profit card on the control center |
| `reports.sales`, `reports.export` | reserved | Phase 2/6 |
| `settings.manage` | ✓ | settings screen, policy/company overrides |
| `audit.view` | reserved | Phase 6 audit review UI |
| `unit.view` | ✓ policy | business unit read |

The canonical list and role matrix live in `app/Support/Permissions.php`.

---

## 4. Bootstrap roles

| Capability | Owner | Manager | Cashier | Staff |
|---|---|---|---|---|
| Dashboard | ✓ | ✓ | ✓ | ✓ |
| Inventory view | ✓ | ✓ | ✓ | ✓ |
| See cost/profit | ✓ | ✓ | — | — |
| Add phone / import | ✓ | ✓ | — | add only |
| Edit handset profile / IMEI | ✓ | ✓ | — | — |
| Purchases (view/create) | ✓ | ✓ | — | — |
| Receive purchase / commit import | ✓ | ✓ | — | — |
| Suppliers | manage | manage | — | — |
| Catalog | manage | manage | — | — |
| Request transfer | ✓ | ✓ | ✓ | ✓ |
| Dispatch/receive transfer | ✓ | ✓ | — | — |
| Staff administration | ✓ | ✓ | — | — |
| Settings | ✓ | — | — | — |
| Accounting adjustments | ✓ | — | — | — |
| POS sale posting | ✓ | ✓ | ✓ | — |
| Invoice view/print | ✓ | ✓ | ✓ | — |
| Customers & shops | manage | manage | manage | — |
| Collect due | ✓ | ✓ | ✓ | — |
| Expense create (Phase 5) | ✓ | ✓ | ✓ | — |

Notes:

- **Owner** is not a hardcoded bypass for data scope: it has all permissions and usually all units, but only the units assigned to it.
- **Manager** is the operational approver (purchases, transfers, imports) but cannot change global settings or make accounting adjustments.
- **Cashier** is intentionally cost-blind: no `inventory.cost.view`, no purchase/catalog access, no staff/settings screens. They can run the POS, print invoices, create/edit customers in the POS and collect dues; gross profit/cost remains hidden.
- **Staff** can view stock and register intake (which requires entering the acquisition cost of the new phone) and request transfers; they cannot see other handsets' cost columns and cannot approve their own transfer.
- Roles are templates. Any account can be granted/revoked individual permissions through spatie without code changes; the Staff screen manages the role + unit assignment baseline.

### Sensitive permissions

`inventory.cost.view`, `reports.cost_profit`, `accounting.view`, `accounting.adjust`, `settings.manage`, `staff.manage` are flagged as sensitive in `Permissions::SENSITIVE`. Grant them deliberately, not as a convenience.

---

## 5. Owner bootstrap

`AdminUserSeeder` creates the first owner from environment variables:

```
ERP_OWNER_NAME, ERP_OWNER_EMAIL, ERP_OWNER_PASSWORD
```

It warns when the default password is still in use. The owner must change it immediately. The demo staff seeder (opt-in) creates manager/cashier/staff accounts with `Demo!Pass2026`; these are for local walkthroughs only and must never run in production.

---

## 6. Policies and their rules

| Policy | Ability | Rule |
|---|---|---|
| SerializedUnit | viewAny | `inventory.view` + at least one unit |
| | view/timeline | permission + current unit (or last-event unit when not in a unit) |
| | create | `inventory.create` + a unit |
| | update/adjust | permission + unit access |
| | import | `inventory.import` + a unit |
| | delete | always false |
| Purchase | viewAny/view | `purchase.view` + purchase unit |
| | create/update | `purchase.create`; update only while draft |
| | receive/cancel | `purchase.approve` + unit |
| | delete | always false |
| StockTransfer | view | `stock.transfer.view` + source or destination unit |
| | create(source) | `stock.transfer.create` + source access |
| | dispatch | `stock.transfer.approve` + source access |
| | receive | `stock.transfer.approve` + destination access |
| | cancel | source access + (approver or requester) |
| Product/Supplier | view vs manage | `catalog.*` / `supplier.*` |
| ImportBatch | create/commit | `inventory.import`; commit additionally requires `purchase.approve` |
| BusinessUnit | view/update | `unit.view` + access / `settings.manage` |
| Sale | view/print | `invoice.view`/`invoice.print` + invoice unit access |
| Customer | view/create/update | `customer.view` / `customer.edit`; delete always false |

---

## 7. Adding a new staff member (operator runbook)

1. Sign in as owner or manager with `staff.manage`.
2. Open **Staff & Access → New staff account**.
3. Set name, login email, a strong initial password, designation and role.
4. Tick the business units the person may operate in. At least one unit is required for operational screens.
5. Save. The account is active immediately; the person should change the password from **My profile**.
6. To suspend: edit the account and clear **Active**. Active sessions are terminated on their next request.

To grant a one-off permission without changing the role, use `php artisan tinker` or a future permission UI:

```php
$user = App\Models\User::where('email', 'cashier@example.com')->first();
$user->givePermissionTo('inventory.cost.view'); // deliberate, audited by policy
```

Prefer role changes through the Staff screen; direct permission grants are an administrative escape hatch.
