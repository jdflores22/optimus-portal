# Old → new data migration (T7.7 / T7.8)

> Stub — map and validate before production loaders. Do **not** treat as production-ready ETL.

## Table map (high level)

| Symfony / Doctrine area | V2 table(s) | Notes |
|-------------------------|-------------|-------|
| `user` / roles | `users` (TPH: broker, consignee, staff, …) | Remap roles to AppRoles |
| shipping lines | `shipping_lines`, `shipping_line_configurations` | Preserve IDs if possible |
| manifests / NOA / billing | `manifests`, `noas`, `billings` | WorkflowState enum strings |
| payments | `payments`, `edo_payments` | Status enum |
| eDO | `electronic_delivery_orders`, `edo_versions`, … | Single eDO/CRO module |
| terminals / containers | `terminals`, `containers`, `pre_advice_requests` | |
| SAS / transfers / appeals | `form_configurations`, `accreditation_submissions`, … | |
| notifications | `in_app_notifications`, prefs, deliveries | Optional historical |

## Dry-run steps

1. Restore old dump to staging schema `optimus_legacy`.  
2. Create empty `optimus_v2` via EF migrate.  
3. Run loaders (TODO scripts) in order: users → lines → relationships → manifests → payments → eDO → yard → ops.  
4. Execute `validate-counts.sql` against both DBs.  
5. Spot-check 10 manifests end-to-end in UI.

## Validation SQL

See `validate-counts.sql`.
