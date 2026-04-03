# SAP Creation Documentation

> **Purpose:** This document summarizes all SAP Request Types, their approval workflows, form fields, and data types. It serves as the reference for the future **SAP Requests** module in the GHelpdesk application.

---

## Overview

| # | Request Type | Applicable Entities | Approval Steps | SLA |
|---|---|---|---|---|
| 1 | New Item Request | TGI, GSI, EDI, S7S, H63 | 1–2 steps (varies by entity) | 1–3 days |
| 2 | New Vendor Request | TGI, GSI, EDI, S7S, H63 | 1 step | 1–3 days |
| 3 | Store Code Request | TGI, GSI, EDI, S7S, H63 | 2 steps | 1–3 days |
| 4 | New Customer Request | TGI, GSI, EDI, S7S, H63 | 1 step | 1–3 days |
| 5 | Add Existing Vendor | TGI, GSI, EDI, H63, S7S | None (direct to encoder) | 1–3 days |
| 6 | Add UOM Config to Existing SAP | TGI, GSI, EDI, S7S, H63 | None (direct to encoder) | 1–3 days |
| 7 | Add Existing SAP SKU | TGI, GSI, EDI, H63, S7S | None (direct to encoder) | 1–3 days |
| 8 | New BOM (Bill of Materials) | GSI Only | 2 steps | 1–3 days |

> **Note:** The **Data Officer** (Christian Paul Duria / Arwin Larrazaga) is the **encoder** who processes all requests after approval. They are excluded from the approval matrix.

---

## 1. New Item Request

**Description:** Used to create a new item/SKU in SAP. Applicable to all entities, with different approval chains depending on the entity.

### Approval Matrix

| Entity | Step 1 | Step 2 |
|---|---|---|
| TGI | Pulilan Warehouse (Ailene Estella / Joel Santos) | Finance Manager (Rachelle Ibarra-Pascua) |
| GSI | Pasig Warehouse (Doreen Guevarra / Patrick Acosta) | Finance Manager (Rachelle Ibarra-Pascua) |
| EDI, H63, S7S | Finance Manager (Rachelle Ibarra-Pascua) | — |

### Form Fields

| Field | Required | Type / Format | Options / Notes |
|---|---|---|---|
| Email | Yes | Email | Must be a valid email address |
| Entity | Yes | Multi-select | TGI, GSI, EDI, S7S, H63 |
| Single or Multiple SKU? | Yes | Radio / Toggle | Single, Multiple |
| Item Name | Yes | Text | — |
| Item Type | Yes | Dropdown (single) | Sale Item, Inventory Item, Purchasing Item, Service Item |
| SAP Default Whse Location | Yes | Dropdown (single) | 02-Control, 03-Distribution, Big Whse, Chiller, Small Whse, Freezer, None GSI Item *(GSI only)* |
| GSI Picking Category | Yes | Dropdown (single) | Cakes, Bakery, Gourmet, Chiller, Freezer, Small Whse, Big Whse, None GSI Item *(GSI only)* |
| Storage Location | Yes | Dropdown (single) | Pasig Whse, Pulilan Whse, Dropship, None |
| UOM Configuration | Yes | Text | Format: e.g. `1 Case x 24 Bot x 1 Lit` |
| Currency | Yes | Dropdown (single) | PHP, HK$, JPY, USD, EURO, SGD, Other |
| Purchase Cost | Yes | Numeric | — |
| Sales Tax Group | Yes | Dropdown (single) | OVAT-E (Output VAT - Exempt), OVAT-N (Output VAT - Non Capital Goods), OVAT-S (Output VAT - Services), OVAT-Z (Output VAT - Zero Rated) |
| G/L Account / Item Group | Yes | Dropdown (single) | Food, Fresh, Capex, Service, Non Food, Finished Product |
| Withholding Tax Liable | Yes | Radio | Yes, No |
| SAP Item Manage Type | Yes | Dropdown (single) | None, Serial, Batch/Expiry |

---

## 2. New Vendor Request

**Description:** Used to register a new vendor in SAP. Supports Employee, Local Trade, Intercompany, and Imported vendor types.

### Approval Matrix

| Entity | Step 1 |
|---|---|
| TGI, GSI, EDI, S7S, H63 | Account Payables — Accounting Ops (Priscilla Gustilo) |

### Form Fields — Common

| Field | Required | Type / Format | Options / Notes |
|---|---|---|---|
| Email | Yes | Email | Must be a valid email address |
| Brand | Yes | Multi-select | TGI, GSI, EDI, S7S, H63 |
| Vendor Type | Yes | Dropdown (single) | Employee, Local Trade, Interco, Imported |

### Form Fields — Employee Vendor

| Field | Required | Type / Format | Options / Notes |
|---|---|---|---|
| Employee Name | Yes | Text | — |
| Employee ID | Yes | File Attachment | Upload 1 file |

### Form Fields — Local / Interco / Imported Vendor

| Field | Required | Type / Format | Options / Notes |
|---|---|---|---|
| Vendor Name | Yes | Text | — |
| Contact Person | Yes | Text | — |
| TIN # | Yes | Text | — |
| Telephone Number | Yes | Text | — |
| Phone Number | Yes | Text | — |
| Currency | Yes | Text / Dropdown | — |
| Address | Yes | Text (multiline) | — |
| Payment Terms | Yes | Dropdown (single) | COD, 7 Days, 15 Days, 45 Days, 50DP/50FP, 50DP/FP, 50DP/COD, IMMEDIATE, PDC 7 Days, PDC 15 Days, PDC 30 Days, PDC 45 Days |
| Subject to Withholding Tax | Yes | Dropdown | Yes, No |
| Tax Status | Yes | Dropdown (single) | Liable, Exempt |
| Tax Group | Yes | Dropdown (single) | IVAT-E (Input VAT Exempt), IVAT-N (Input VAT Goods), IVAT-S (Input VAT Services), IVAT-Z (Zero Rated), N-VAT (Non-VAT Goods/Services) |
| COR / SEC | Yes | File Attachment | Upload up to 10 files |
| Blank Invoice | Yes | File Attachment | Upload up to 5 files |
| Blank Receipt | Yes | File Attachment | Upload up to 1 file |

---

## 3. Store Code Request

**Description:** Used to create a new store code (customer code) in SAP for CBTL or Nono's stores.

### Approval Matrix

| Entity | Step 1 | Step 2 |
|---|---|---|
| TGI, GSI, EDI, S7S, H63 | Account Receivables — Accounting Ops (Magnolia Gavarra) | Finance Manager (Rachelle Ibarra-Pascua) |

### Form Fields — Submitted by Requester

| Field | Required | Type / Format | Options / Notes |
|---|---|---|---|
| Email | Yes | Email | — |
| Approved Store Code | Yes | Text | — |
| Store Group | Yes | Dropdown (single) | CBTL, Nono's |
| COR Attachment | Yes | File Attachment | — |

### Form Fields — Filled by 1st Approver (Accounting Receivables)

| Field | Required | Type / Format | Options / Notes |
|---|---|---|---|
| SAP Pricelist Group | Yes | Text (fill in blank) | — |
| Consolidating Business Partner | Yes | Text (fill in blank) | — |
| GL Category | Yes | Dropdown (single) | Equity, Franchisee, Associates |
| Cost Center Only? | Yes | Radio | Yes, No |

---

## 4. New Customer Request

**Description:** Used to register a new customer in SAP.

### Approval Matrix

| Entity | Step 1 |
|---|---|
| TGI, GSI, EDI, S7S, H63 | Account Payables — Accounting Ops (Priscilla Gustilo) |

### Form Fields

| Field | Required | Type / Format | Options / Notes |
|---|---|---|---|
| Email | Yes | Email | Must be a valid email address |
| Brand | Yes | Multi-select | TGI, GSI, EDI, S7S, H63 |
| Customer Name | Yes | Text | — |
| Customer Group | Yes | Dropdown (single) | Imported, Intercompany, Local |
| Currency | Yes | Dropdown (single) | AUD, PHP, HK$, JPY, SGD, USD, EURO, All Currencies, Other |
| TIN # | Yes | Text | — |
| Contact Person | Yes | Text | — |
| Telephone Number | Yes | Numeric | — |
| Cellphone Number | Yes | Numeric | — |
| Email Address | Yes | Email | Must be a valid email address |
| Billing Address | Yes | Text (multiline) | — |
| Subject to Withholding Tax | Yes | Radio | Yes, No |
| Payment Terms | Yes | Dropdown (single) | COD, 7 Days, 15 Days, 45 Days, 50DP/50FP, 50DP/FP, 50DP/COD, IMMEDIATE, PDC 7 Days, PDC 15 Days, PDC 30 Days, PDC 45 Days |
| SAP Tax Group | Yes | Dropdown (single) | OVAT-N (Output VAT - Non Capital Goods), OVAT-S (Output VAT - Services), OVAT-Z (Output VAT - Zero Rated), OVAT-E (Output VAT - Exempt), X-VAT (Non-VAT Goods/Services) |
| COR Attachment | Yes | File Attachment | — |
| Signed Contract | Yes | File Attachment | — |

---

## 5. Add Existing Vendor

**Description:** Used to copy an already-existing vendor from one entity to one or more other entities in SAP. No approval required — submitted directly to the Data Officer for encoding.

### Approval Matrix

> No approval matrix. Ticket is sent **directly to the SAP Data Officer** for encoding.

### Form Fields

| Field | Required | Type / Format | Options / Notes |
|---|---|---|---|
| Email | Yes | Email | Must be a valid email address |
| Vendor Name | Yes | Text | — |
| Copy FROM what Entity? | Yes | Dropdown (single) | TGI, GSI, EDI, H63, S7S |
| Copy TO what Entity? | Yes | Multi-select | TGI, GSI, EDI, H63, S7S |

---

## 6. Add UOM Config to Existing SAP Item

**Description:** Used to add or update a Unit of Measure (UOM) configuration to an existing SAP item. No approval required — submitted directly to the Data Officer for encoding.

### Approval Matrix

> No approval matrix. Ticket is sent **directly to the SAP Data Officer** for encoding.

### Form Fields

| Field | Required | Type / Format | Options / Notes |
|---|---|---|---|
| Email | Yes | Email | — |
| Entity | Yes | Multi-select | TGI, GSI, EDI, S7S, H63 |
| SAP Code | Yes | Text | Existing SAP item code |
| New Packaging Info / UOM Conversion | Yes | Text | e.g. `1 Case x 24 Bot x 1 Lit` |

---

## 7. Add Existing SAP SKU

**Description:** Used to add an already-existing SAP SKU from one entity to one or more other entities. No approval required — submitted directly to the Data Officer for encoding.

### Approval Matrix

> No approval matrix. Ticket is sent **directly to the SAP Data Officer** for encoding.

### Form Fields

| Field | Required | Type / Format | Options / Notes |
|---|---|---|---|
| Email | Yes | Email | — |
| Single or Multiple SKU? | Yes | Radio / Toggle | Single, Multiple |
| SAP Item Name | Yes | Text | — |
| FROM what Entity? | Yes | Dropdown (single) | TGI, GSI, EDI, H63, S7S |
| Which Entity to add to? | Yes | Multi-select | TGI, GSI, EDI, H63, S7S |

---

## 8. New BOM (Bill of Materials)

**Description:** Used to create a new Bill of Materials (BOM) in SAP. Applies to GSI entity only. Requires a structured upload of finished product and raw materials data.

### Approval Matrix

| Entity | Step 1 | Step 2 |
|---|---|---|
| GSI Only | GSI Senior Officer-in-Charge (John Patrick Acosta) | Financial Reporting Manager (Christine Tesorero) |

### Form Fields

| Field | Required | Type / Format | Options / Notes |
|---|---|---|---|
| Email | Yes | Email | — |
| Warehouse Location | Yes | Dropdown (single) | 01, 02, 03, Big Whse, Small Whse, Chiller, Freezer |

### BOM Upload Format

The BOM data should follow this tabular structure for uploading:

| Column | Required | Type | Description |
|---|---|---|---|
| FIN PROD NAME | Yes | Text | Finished product name |
| FIN PROD UOM | Yes | Text | Unit of measure for finished product (e.g. WHOLE, SLICE) |
| SAP RAW MATS SKU | Yes | Text | SAP code of raw material (`NO SAP CODE` if not yet in SAP) |
| SAP RAW MATS NAME | Yes | Text | Name of the raw material |
| UOM | Yes | Text | Unit of measure for raw material (e.g. PC, KG, L) |
| QTY | Yes | Decimal/Numeric | Quantity of raw material used |

**Sample BOM Data:**

| FIN PROD NAME | FIN PROD UOM | SAP RAW MATS SKU | SAP RAW MATS NAME | UOM | QTY |
|---|---|---|---|---|---|
| Berry Shortcake | WHOLE | 260A2A | Egg - Fresh, Medium | PC | 6 |
| Berry Shortcake | WHOLE | NO SAP CODE | Water | L | 0.15 |
| Berry Shortcake | WHOLE | 421A2A | Oil - Canola | L | 0.0654 |
| Berry Shortcake | WHOLE | 277A2A | Flour - All Purpose, 25kg/sack | KG | 0.135 |
| Berry Shortcake | WHOLE | 548A2A | Sugar - White | KG | 0.126 |

---

## Redundancy & Duplicate Analysis

### Fields Shared Across Multiple Request Types

| Shared Field | Request Types |
|---|---|
| `Email` | All 8 request types |
| `Entity / Brand` (TGI, GSI, EDI, S7S, H63) | New Item, New Vendor, Store Code, New Customer, Add Existing Vendor, Add UOM Config, Add Existing SAP SKU |
| `Single or Multiple SKU?` | New Item Request, Add Existing SAP SKU |
| `FROM Entity / TO Entity` copy pattern | Add Existing Vendor, Add Existing SAP SKU |
| `Payment Terms` *(identical option list)* | New Vendor Request, New Customer Request |
| `SLA: 1–3 days` | All 8 request types |

### Tax Group Options — Similar but Distinct

| Request Type | Field Name | Variant |
|---|---|---|
| New Item Request | Sales Tax Group | Output VAT (OVAT-E, OVAT-N, OVAT-S, OVAT-Z) |
| New Vendor Request | Tax Group | Input VAT (IVAT-E, IVAT-N, IVAT-S, IVAT-Z, N-VAT) |
| New Customer Request | SAP Tax Group | Output VAT + X-VAT |

> These are distinct enough to remain separate, but their option sets should be stored in a shared lookup/enum table in the database.

### Request Types with No Approval (Direct to Encoder)

These 3 request types share the same simplified workflow — no approval chain, ticket goes directly to the Data Officer:

- Add Existing Vendor
- Add UOM Config to Existing SAP
- Add Existing SAP SKU

> In the SAP Requests module, these can share a `requires_approval = false` flag.

---

## Future SAP Requests Module — Design Notes

These request types are intended as the foundation for a future **SAP Requests** module in GHelpdesk. Below is the proposed database structure and design guidance.

### Proposed Database Tables

#### `sap_request_types`
Stores the 8 request type definitions.

| Column | Type | Description |
|---|---|---|
| id | bigint (PK) | — |
| name | varchar | Request type name |
| description | text | Short description |
| sla_days | tinyint | SLA in days (default: 3) |
| requires_approval | boolean | Whether approval matrix applies |
| applicable_entities | json | Array of entity codes (e.g. `["TGI","GSI"]`) |
| created_at / updated_at | timestamp | — |

#### `sap_request_fields`
Stores form fields for each request type.

| Column | Type | Description |
|---|---|---|
| id | bigint (PK) | — |
| request_type_id | bigint (FK) | Links to `sap_request_types` |
| field_name | varchar | Display label |
| field_key | varchar | Snake_case key |
| field_type | enum | `text`, `email`, `numeric`, `dropdown`, `multi_select`, `radio`, `file`, `textarea` |
| is_required | boolean | — |
| options | json | For dropdowns/selects: array of option values |
| conditional_entity | varchar (nullable) | Restrict field to a specific entity (e.g. `GSI`) |
| filled_by_approver | boolean | Whether this field is filled by an approver, not the requester |
| sort_order | int | Display order in the form |

#### `sap_approval_matrix`
Defines approval steps per request type and entity.

| Column | Type | Description |
|---|---|---|
| id | bigint (PK) | — |
| request_type_id | bigint (FK) | Links to `sap_request_types` |
| entity | varchar (nullable) | Entity code, or `NULL` for all entities |
| step | tinyint | Approval step order (1, 2, 3…) |
| role_name | varchar | Role/department of the approver |
| responsible_persons | json | Array of `{name, email}` objects |

#### `sap_requests`
Stores submitted requests (runtime data).

| Column | Type | Description |
|---|---|---|
| id | bigint (PK) | — |
| request_type_id | bigint (FK) | — |
| submitted_by | bigint (FK → users) | — |
| entity | varchar | Entity selected by requester |
| status | enum | `pending`, `for_approval`, `approved`, `rejected`, `encoded` |
| form_data | json | Key-value pairs of submitted field values |
| current_approval_step | tinyint | Current step in the approval matrix |
| created_at / updated_at | timestamp | — |

### Key Design Decisions

1. **Dynamic forms** — Form fields are data-driven from `sap_request_fields`, allowing new request types or field changes without code deployments.
2. **Conditional fields** — Fields like *SAP Default Whse Location* and *GSI Picking Category* are GSI-only and should render conditionally based on the selected entity.
3. **Approver-filled fields** — Store Code Request has fields filled by the 1st Approver (not the requester). The `filled_by_approver` flag handles this.
4. **Employee vs. Vendor sub-form** — New Vendor Request has two field sets depending on `Vendor Type`. This can be handled via a `conditional_value` column on `sap_request_fields` or via frontend conditional rendering based on the `Vendor Type` selection.
5. **No-approval shortcut** — The 3 request types with `requires_approval = false` skip the approval flow entirely and go straight to the encoder queue.
