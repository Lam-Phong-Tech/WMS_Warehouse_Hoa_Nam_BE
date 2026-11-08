# P05: Authorized SAVE Must Receive PX

Root cause: canonical P03 already accepts an effective ACTIVE SUPER_ADMIN
assignment, while the numbered SAVE adapter ignored it. The same context could
therefore return permissions.save=true and numbering.allowed=false.

The adapter now recognizes that existing canonical authority. Ordinary users
still need actual ACTIVE scoped outbound.scan plus manual_create/import; read
or scan alone never permits manual creation. No grants or Post permissions change.
All ownership, warehouse, version, session, shift and idempotency guards remain.
P05 context now intersects its read/save/cancel/record flags with this same
current-warehouse policy; another warehouse's create grant cannot advertise
creation here. Honor numbering.allowed=false rather than falling back to a
broader permissions flag. Merely opening P05 is not create authorization.

Wire contract/revision remain p05-save-numbering-v1 / p05-save-numbering-r1 so
the existing App parser continues to work. Additive capability fields:

```json
{
  "authorization_policy": "CANONICAL_SUPER_ADMIN_OR_ACTIVE_SCOPED_GRANTS",
  "authorization_policy_revision": "p05-numbering-authorization-r2"
}
```

Current authorization contract: docs/contracts/p05-save-numbering-authorization-r2.json.
It supersedes only the old contract's authorization wording; all r1 transport,
number format, receipt, source and stock semantics remain unchanged.

For a genuinely new intended SAVE, retain original draft/scan identity and use
X-Outbound-Numbering-Contract, Idempotency-Key, strong If-Match and tracked shift
headers. Official PX is data.document.document_no. Never generate it in App.
An old successful unnumbered receipt stays unnumbered on replay/GET. Resolve
UNKNOWN first; only a separate explicit new SAVE with current known version can
assign a number. Do not automatically resend an unknown operation or allocate a
replacement draft. Existing numbered drafts retain the same PX.

No migration, seed, backfill, live grant update, stock posting or NFC/push action
is required. Exact source/runtime SHA and actual test/live evidence are supplied
separately; policy readback is not an authenticated live SAVE or device UAT.
