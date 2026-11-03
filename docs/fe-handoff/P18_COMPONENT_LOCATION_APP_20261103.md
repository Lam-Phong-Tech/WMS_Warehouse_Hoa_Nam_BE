# P18.S04 Backend Read Handoff

The new capability is opt-in on two existing detail GETs. Keep BOX/P06 v1
adapters unchanged. Do not globally reinterpret their location_hint or stock
semantics. Select a new P18.S04 adapter using the exact deployed contract.

## Request

GET `/api/v1/containers/{container_id}?warehouse_id={confirmed UUID}` for BOX.
GET `/api/v1/item-instances/{item_id}?warehouse_id={confirmed UUID}` for ITEM.

Headers:

```http
X-Component-Location-Contract: p18-component-location-read-v1
X-Read-Fields-Contract: explicit-read-fields-v1
```

Use the current authenticated owner. No replacement login, START, SAVE,
RECORD, POST, putaway or move is needed by this read capability. Use an exact
ITEM/BOX ID from the existing resolver; a SKU with multiple objects is not a
location target. Unknown query keys, GET bodies and stale conditional cache
headers are rejected.

## Parse

Verify `meta.contract=explicit-read-fields-v1`,
`meta.read_fields.owner_contract=p18-component-location-read-v1` and
`meta.revision=p18-component-location-read-r1`. Use existing missing-fields
decoder; absent values are omitted, not null/zero/default. `data.identity`
contains the exact physical ID and a real code if available. SKU labels come
from the current exact SKU master and are explicitly not historical snapshots.

Display `current_location` only when supplied with valid persisted provenance.
The actual location kind is TRAY and the label is its real container code,
not a fabricated shelf/bin. The timestamp is the confirmed putaway time; the
response observation timestamp is separate. Keep both versions and snapshot
metadata; never invent version 1, substitute now, or adjust timezone by hand.

Missing location cases include NOT_RECORDED, SOURCE_INCONSISTENT and
REDACTED_PERMISSION. Distinguish them. A 503 source failure is not a valid
no-location result. Hierarchy/capacity are omitted with NOT_CONFIGURED;
current placement may still be shown without them. Contents/availability,
stock issuance and warranty/component eligibility are not granted by this GET.

## Scope And Safety

Keep actor/session/warehouse/caller identity during navigation and Back.
Discard a callback after scope changes. Do not use role display names as
permissions. Location uses actual putaway.view/manage grants; identity/master
requires the documented separate rights. No new grant or seed is performed.

## Evidence And Remaining Acceptance

See `P18_COMPONENT_LOCATION_BE_RETURN_20261103.json` and the versioned JSON
contract for source mappings, test identities and open blockers. Live BOX IDs
are existing data, not generated fixture placement. No positive ITEM placement
dataset was found; keep the missing-data presentation truthful.

Source tests are not device UAT. Confirm the exact release report before
enabling the new adapter. Authenticated browser smoke remains pending if the
Edge URL-verification safety stop has not been resolved. Do not treat a health
response or local UI fixture as that evidence.
