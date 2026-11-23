# DEEPSEEK CODING AGENT — COPY/PASTE PROMPTS

## PROMPT 1 — START THE PROJECT

You are a senior Laravel architect, accounting-aware backend engineer, Livewire UX engineer and test engineer. Read the attached `MASTER_PROJECT_SPECIFICATION.md` completely. Build a production-grade Mobile Shop ERP following that spec, without dropping modules or inventing business logic. Key requirements: Excel-like one-page control center, IMEI lifetime ledger with multiple acquisition cycles, Unit 1/Unit 2 transfers, repair, retail/shop POS with accessories and split payments, installment partial payments and overdue, exchange/trade-in and resale of the SAME IMEI, staff/cashier/admin RBAC, staff/guest food expenses, salary advances and monthly payout statements, double-entry accounting and daily/monthly reports.

FIRST RESPONSE ONLY: inspect the repository and versions if available; provide current architecture assessment, target folder structure, ERD/table plan, migration order, development phase checklist, first-phase files, security/finance risks and explicit assumptions. Do NOT emit hundreds of lines of incomplete code in the first response. If no repository exists, scaffold a new Laravel project with compatibility-aware package choices.

After the plan, implement Phase 0 then Phase 1 one step at a time. For every step output real file paths and actual full working code or patch, migration/test commands and acceptance tests. Never claim something works unless a test was run or give exact manual verification steps.

## PROMPT 2 — NEXT PHASE

Continue the Mobile Shop ERP from `MASTER_PROJECT_SPECIFICATION.md` and existing repository. Before changes inspect actual files, migrations and test results, then implement the next incomplete phase from section 18. Preserve previous schema/data. Implement server-side policies, financial journal entries if affected, IMEI event history, validations, concurrency and tests. Show changed file paths, commands, expected outcomes and remaining tasks. Avoid placeholders and mock functionality.

## PROMPT 3 — CODE REVIEW / BUG FIX

Audit the attached Mobile Shop ERP repository against `MASTER_PROJECT_SPECIFICATION.md`. Find: missing migrations/features, direct unauthorized access, broken unit scoping, duplicate IMEI loopholes, double-selling races, stock quantity mismatches, payment/receivable confusion, unbalanced journal entries, installments counted as revenue twice, salary advances counted as expenses, exchange stock-cycle cost errors, destructive deletes and fake UI actions. Prioritize critical issues. Fix with migrations/services/tests, showing patch by file and commands to run.

## PROMPT 4 — UI IMPLEMENTATION

Implement a dense but accessible Laravel Livewire one-page Control Center matching section 2 of the master specification. Sticky global IMEI search, unit/date filter, financial KPI cards, quick-action drawers, keyboard-friendly spreadsheet-style inventory grid with 10k+ record pagination, per-row Sell/History/Transfer/Repair/Exchange, mixed phone/accessory POS, staff/guest quick expense drawer and responsive layout. All numbers must come from real authorized DB queries; all buttons must work and be permission-checked. Provide component classes, Blade views, state/action flow, validation and browser/feature tests.

## PROMPT 5 — VERIFY COMPLETENESS

Perform final end-to-end verification against sections 14–16 of the master specification. Generate a traceability matrix: requirement -> implemented file(s) -> automated test(s) -> pass/fail -> unresolved issue. Run tests that are available in your environment, show exact commands/output summaries, identify tests not run, verify double-entry balancing and IMEI cycle history, and produce a deployment checklist and `IMPLEMENTATION_STATUS.md`. Do not declare production-ready with failing/unrun critical tests.

## COMPACT CONTEXT RESTORATION PROMPT

Context: We are building the Mobile Shop ERP per `MASTER_PROJECT_SPECIFICATION.md`. The non-negotiables are persistent same-IMEI history across repeat sale/exchange, per-cycle cost, one-page Excel-style inventory + Sell button, accessories, payments/dues/installments, repairs/unit transfers, staff/cashier/admin permissions, quick staff/guest expense, salary advances and reconciled finance. Continue from the latest repository state and `IMPLEMENTATION_STATUS.md`, not from scratch. State which phase is completed, inspect current code, then implement the next testable piece.
