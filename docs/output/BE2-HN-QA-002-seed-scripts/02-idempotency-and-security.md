# Idempotency and security

The UAT seeder uses `updateOrCreate` for users, import batch, physical code, item instance, and inventory serial. Role attachment checks the existing pivot before inserting. Re-running `wms:seed-uat` keeps one record per stable UAT identifier and does not create duplicate role assignments.

The baseline warehouse seeder now selects its own extension fixtures by explicit `IMP-2026-*`, `PALLET-HN-*`, and `QR-HN-2026-*` prefixes with deterministic ordering. UAT records cannot be selected accidentally by the base fixture path.

Password input is hashed with Laravel `Hash::make`; no plaintext password is stored, emitted by the command, or placed in evidence. The UAT command is explicit and is not added to the default `DatabaseSeeder`, so ordinary seed runs do not create UAT accounts.
