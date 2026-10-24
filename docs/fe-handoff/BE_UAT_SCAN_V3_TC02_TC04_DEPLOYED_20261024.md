# TC02/TC04 DEV/TEST Deployment

Completed 24/10/2026 at 17:32:10 Asia/Ho_Chi_Minh.

Application SHA: `184bd43d95804a0c6f42d47ca64dfa39db40d9ae`.
Operations SHA: `aee2269cdf7b5d4fac0c97d305c1f43394c9ef51`.
[Deployment run40086030765](https://github.com/Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE/actions/runs/40086030765): SUCCESS; CI SKIPPED.

This supersedes the release status in the original source-only handoff. Source
snapshots in this package retain their original not-deployed status as historical
evidence. The current deployment report is authoritative for release status.
**Not full TC02/TC04 UAT PASS.**

## App Integration

Use only the existing INBOUND/OUTBOUND detail GETs, with the approved warehouse UUID:

```http
GET /api/v1/mini-app/inbound-documents/{id}?warehouse_id=<UUID>
GET /api/v1/mini-app/outbound-documents/{id}?warehouse_id=<UUID>
X-P12-Timeline-Contract: p12-timeline-handoff-v1
```

Check `meta.p12.timeline_contract` and `data.p12.timeline.contract`. The source of
`data.p12.timeline.milestones.sent_to_web.occurred_at` is persisted
`mini_app_web_post_handoffs.submitted_at`. Parse its RFC3339 instant once, then
display Vietnamese time. The supplied source/document/warehouse/version evidence
must not be treated as a new event UUID or as proof that Web has posted the document.

Do not attach this header globally, to list/warranty/receipt endpoints or to writes.
Without the header the legacy timeline remains unchanged. This is separate from
P12 aggregate v3. Source failure is an explicit error, not fabricated empty history.

TC02 historical job title is still OPEN: no trusted historical title writer/source
was supplied. Do not substitute current IAM/title or the viewer's role. TC04 CHECK
is a distinct human action and has no approved persisted owner event yet. Historical
actor/description remain null with explicit provenance status when not recorded.
Keep the UI placeholder; do not claim full coverage or invent values.

## Release Verification

- Targeted SQLite and PostgreSQL each37 tests /559 assertions PASS; cached SQLite14
  tests /251 assertions PASS, overlapping the main group. Not a full CI suite.
- Backend, queue and scheduler were recreated and checked for the application SHA.
- Config/route caches and Swagger regenerated; existing GET/HEAD ACL middleware retained.
- 219 applied migrations, only P11 receipt migration pending. No migration/seed/repair/restore.
- P11 OFF, P12/P14 ON, P19 and P14 recovery OFF; no flag or permission changes.
- All114 public base-table row/field fingerprints matched before reopening. Thirteen
  local storage/app manifest entries matched. Remote media objects were not touched
  or enumerated. Counts unchanged:1156 SKU,933 published,974 media rows,2759 IN_STOCK.
- All14 reviewed runtime overlays and both inner/outer Nginx ETag fixes preserved.
- Origin/proxy health200 with exactSHA and unauthenticated guards401. These are not
  authenticated detail or device acceptance.

Raw public HTTP, headers, Request-IDs and local test XML are in the package. The
first deployment attempt stopped before maintenance/switch due to an isolated
Docker mount preparation error; the corrected operation succeeded without changing
application source. No real auth login, START/END, RECORD/POST or NFC mutation.

Authenticated same-document detail GET is still pending the approved existing TEST
owner session and document. No new admin login was performed to replace the active
operator. Capture the exactSHA, scope, Request-ID, timestamp contracts and redacted
response when App adopts the header. Keep full TC02/TC04 and native UAT pending.

See `docs/evidence/DEVTEST_TIMELINE_RELEASE_20261024.json` for exact scope and evidence.
