# Shop sheet row → app field mapping

This maps the shop's existing Excel row to the ERP, so the same information can be entered once without losing meaning.

Source row (as supplied):

```
DFGH | Stock | Model | IMEI | Buying Party | Storage | Battery Health | Cycle Count |
Colour | Variant | Category | Brand Name | Box/Kit | Buy Price | Selling Price |
Total profit/Loss | Receiving Date | Selling date | In Stock | Due for reason |
Unit 1 | In Repair | Column 23 | customer name | phone | address |
adapter | adapter price | cables name | cables price | Column 31
```

---

## 1. Acquisition-time columns (entered now)

These are captured in **Add phone**, **New purchase** (per phone line) or the **Excel import**. `stored in` names the authoritative table.

| Sheet column | App field | Stored in | Notes |
|---|---|---|---|
| `Stock` | Derived stock state | `serialized_units.current_custody` + `sellability_status` | Not free text. Shown as In stock / In transit / At repair / With customer / Quarantined / Written off. |
| `Model` | Product + variant | `products.name`, `product_variants` | Model is resolved to a catalog variant. |
| `IMEI` | IMEI 1 (and optional IMEI 2) | `serialized_units.primary_imei`, `serialized_identifiers` | Stored as a string; unique across both slots; 15 digits + Luhn. |
| `Buying Party` | Buying party | `inventory_cycles.buying_party_name` (fallback `purchases.supplier_id`) | Free text for an individual seller; registered supplier on purchase documents. |
| `Storage` | Variant storage | `product_variants.storage` | Catalog attribute, used to match imports. |
| `Battery Health` | Battery health % | `serialized_units.battery_health` | Nullable; unknown renders “Unknown”, not 0. |
| `Cycle Count` | Cycle count | `serialized_units.cycle_count` | Nullable; unknown renders “Unknown”. |
| `Colour` | Variant colour | `product_variants.color` | Catalog attribute, used to match imports. |
| `Variant` | Product variant / SKU | `product_variants.sku` + attributes | If your “Variant” is a free-text label (e.g. dual-SIM/eSIM), tell us so it can be modelled separately instead of guessed. |
| `Category` | Category | `products.category_id` | Catalog reference. |
| `Brand Name` | Brand | `products.brand_id` | Catalog reference. |
| `Box/Kit` | Box / kit | `serialized_units.box_kit` | Free text, e.g. “Box + cable”. |
| `Buy Price` | Acquisition cost | `inventory_cycles.acquisition_value` (from `purchase_lines.unit_cost`) | Decimal BDT; becomes the handset cost basis for that cycle. |
| `Selling Price` | Expected selling price | `serialized_units.expected_selling_price` | Per-handset asking price; prefilled from the catalog default when adding a phone. |
| `Receiving Date` | Acquired date | `inventory_cycles.acquired_at` (from `purchase_lines.received_at` or purchase date) | Business timezone is `Asia/Dhaka`; timestamps stored UTC. |
| `Due for reason` | Due / status reason | `serialized_units.status_note` (line: `purchase_lines.status_note`) | Free text shown under the state badge. |
| `Unit 1` | Business unit | `serialized_units.current_unit_id`, `business_units` | Unit selector controls this; access is permissioned. |
| `adapter` | Included adapter | `serialized_unit_accessories` (`accessory_type = ADAPTER`) | Name + price travel with the handset row. |
| `adapter price` | Adapter price | `serialized_unit_accessories.price` | Recorded as entered; does **not** change the handset cost basis. |
| `cables name` | Included cable | `serialized_unit_accessories` (`accessory_type = CABLE`) | Name + price travel with the handset row. |
| `cables price` | Cable price | `serialized_unit_accessories.price` | Recorded as entered; does **not** change the handset cost basis. |

## 2. Sold / customer columns (populated by POS, not by intake)

These cannot be truthfully filled at stock-entry time. They are intentionally **not** fabricated or imported into active stock.

| Sheet column | App destination | Status |
|---|---|---|
| `Selling date` | `sales.posted_at` (Phase 2) | Not implemented until POS |
| `Total profit/Loss` | Realised margin = sale line total − cost snapshot (Phase 2) | Today the grid shows **Projected margin** (expected selling price − current cost basis) to cost-authorised users, clearly labelled “projected”. |
| `In Stock` | Derived from custody/sellability | Already shown as the state badge |
| `In Repair` | Derived from custody `AT_REPAIR_VENDOR` | Custody state exists; repair job documents are Phase 4 |
| `customer name` | `customers.name` / sale customer (Phase 2) | Not captured at intake |
| `phone` | `customers.phone` (Phase 2) | Not captured at intake |
| `address` | `customers.address` (Phase 2) | Not captured at intake |

Importing historical sold rows as live stock would create phantom inventory and fake revenue; the import wizard therefore imports **inbound** handsets only. Historical sold rows need the explicit legacy-archive workflow described in the specification (Phase 6) — not silent conversion.

## 3. Columns that need clarification

| Sheet column | Current handling |
|---|---|
| `DFGH` | Not mapped. Is it a row/shop code or a sample value? If it is a stable external reference, it can be added as an `external_ref` field. |
| `Column 23` | Empty/unnamed; ignored. |
| `Column 31` | Empty/unnamed; ignored. |

If `DFGH`, `Column 23` or `Column 31` carry real business meaning (e.g. shop code, checking flag, purchase memo), send one example row and they will be modelled explicitly rather than guessed.

## 4. Entry workflow

Single handset:

1. **Inventory → Add stock → Phone / IMEI**
2. Choose unit and model; enter IMEI, buying party, storage/colour (from the selected variant), battery, cycles, box/kit, buy price, selling price, receiving date, due reason.
3. Enter adapter/cable name and price under **Included with handset**.
4. Save. The app creates the persistent IMEI identity, the open acquisition cycle, the receipt event and the accessory rows in one transaction.
5. The grid shows Buying party, Selling price, Received, Adapter/cable and Projected margin (cost permission required).

Single product/accessory:

1. **Inventory → Add stock → Product / accessory**
2. Enter product name, SKU, buying price and stock quantity; optional selling price is needed before POS can sell it.
3. Save. An existing accessory SKU is restocked; a new SKU creates the product + variant. The opening stock movement is journalised against opening equity — use **Purchases** for supplier purchases with cash/payable.

Multiple handsets:

- **Purchases → New purchase**: one line per phone; the document buying party applies to lines unless a line overrides it. Receive to book stock.
- **Inventory → Bulk entry**: paste IMEI rows for one model.
- **Excel Import**: map the sheet headers (Model, Brand, Storage, Colour, IMEI, Buying Party, Buy Price, Selling Price, Battery Health, Cycle Count, Box/Kit, Receiving Date, Adapter/Cable, Due for reason) and commit. Sold/customer columns are ignored by design.

## 5. Why Bundle prices are separate

The sheet records adapter/cable prices next to the phone price. The ERP stores them as handset accessories with their own price, but keeps the phone's cost basis driven only by Buy Price (plus any explicitly allocated direct cost). This avoids double-counting the same money as both handset cost and accessory revenue. When POS is enabled, the sale screen can decide whether these extras are included or charged separately, explicitly.
