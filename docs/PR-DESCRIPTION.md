# Store TOTP secrets with the WordPress Secrets API

## Motivation

Two-Factor keeps every user's TOTP shared secret in plaintext user meta (`_two_factor_totp_key`, `Two_Factor_Totp::SECRET_META_KEY`). Anyone who can read the database, a backup, or a SQL injection dump can mint valid codes for every TOTP user.

An earlier attempt (PR #389, "recrypt") added homegrown XChaCha20 encryption keyed off `SECURE_AUTH_SALT`. It stalled because rotating the salt needed a bespoke re-encryption process, and it made Two-Factor responsible for cryptography and key management.

The WordPress Secrets API removes that burden. It is tracked in Trac #66187 (milestone 7.2), core PR WordPress/wordpress-develop#13759, and is available today as a feature plugin (`ericmann/secrets-api`, v0.2.1). This change uses it when present and changes nothing when it is not.

## Design summary

- **Adapter.** `Two_Factor_Secrets` (`class-two-factor-secrets.php`) is a static class and the only code that calls the Secrets API. Everything else goes through it. Presence is detected at call time, never when files load.
- **Network scope only.** Secrets are named `two-factor/totp-{user_id}` and always stored with the network functions, on single site and multisite alike. The API has no per-user scope, and site-scope secrets are bound to the blog ID, which would break a user moving between sites.
- **Location marker.** User meta `_two_factor_totp_key_network` (`Two_Factor_Totp::SECRET_NETWORK_META_KEY`) holds the network ID that owns the secret. It is what tells "not migrated" apart from "migrated but unreadable".
- **Precedence.** A non-empty plaintext meta value wins and is migrated lazily. Otherwise no marker means no key. Otherwise the secret is read from the Secrets API.
- **Tri-state reads.** `get_user_totp_key_state()` returns a string, `null` (no key) or `WP_Error` (a key exists but cannot be read). `get_user_totp_key()` keeps its signature and returns `''` for both non-string states.
- **Verified writes.** Every write and migration is read back and compared with `hash_equals()`. The plaintext copy is deleted only after that succeeds. A Secrets API failure never falls back to writing plaintext.
- **Fail closed.**
  - An unreadable secret never authenticates and is never treated as "not configured" or re-enrolled silently.
  - The login prompt explains that the authenticator app is unavailable and offers no code field.
  - When the Secrets API is missing, or the marker names another network, a user whose only enrolled method is the unavailable TOTP is forced onto the fallback provider (email by default, filterable), or locked out with a `no_available_2fa_methods` error if no valid fallback exists. They are never let in with one factor.
  - When the Secrets API is present but the secret cannot be decrypted (salt rotation without `WP_SECRETS_KEY`, or a missing secret row), TOTP stays available and is not replaced by the fallback. The user cannot authenticate and is locked out until an administrator resets them.
  - Users with backup codes keep using them.
- **Hooks.**
  - `two_factor_use_secrets_api` filter: opt out of writes and migration. Already-migrated users are still read from the Secrets API while it is present.
  - `two_factor_secrets_migrated`, `two_factor_secrets_migration_failed` and `two_factor_secret_unavailable` actions. No hook ever receives a plaintext secret.
  - `two_factor_secrets_api_present` is an internal test seam that can only force "absent".
- **Provider base class.** Two new generic hooks: `is_enrolled_but_unavailable_for_user()` and the static `uninstall_user_data()`. Core stays free of TOTP logic.

## Migration and downgrade story

- **Lazy migration.** The first time a plaintext secret is read in the login or profile flow it is moved into the Secrets API, verified, and the plaintext is removed.
- **Bulk.** `wp two-factor secrets migrate [--user=<user>] [--batch-size=<n>] [--dry-run]` uses the same code path in bounded batches.
- **Status.** `wp two-factor secrets status` and a `totp_storage` field on `wp two-factor status <user>` (`plaintext`, `secrets-api`, `unavailable`, `none`).
- **If the Secrets API goes away.** Affected users cannot use their authenticator app. Administrators see a non-dismissible notice (Dashboard and Network Admin, `manage_options` / `manage_network_options` only) and a critical Site Health result, `two_factor_totp_secret_storage`. Detection is a cheap single-row query cached in a site transient.
- **Export.** `wp two-factor secrets export [--user=<user>] [--batch-size=<n>] [--yes]` moves secrets back into user meta (with confirmation) before the API is removed. While the API is active and `two_factor_use_secrets_api` allows writes, lazy migration moves exported secrets back on the next read. To decommission: return false from `two_factor_use_secrets_api`, run export, then deactivate the Secrets API.
- **Cleanup.** Deleting a user removes their secret and marker (`wpmu_delete_user` on multisite, so removal from a single site does not delete a network secret). Uninstall removes every secret while the API is available. Secrets left in the store when the API is absent at uninstall are orphaned; there is nothing to reach them with.

## Compatibility

- No `Requires at least` bump, and no new package dependency. The Secrets API is optional.
- Without the Secrets API (WP earlier than 7.2 and no feature plugin) behaviour is identical to today: secrets stay in user meta and nothing is migrated.
- The opt-out is the `two_factor_use_secrets_api` filter only. There is no settings UI.
- Existing public method signatures on `Two_Factor_Totp` are unchanged.
- `includes/` is untouched.

## Testing notes

- The feature plugin is loaded into the wp-env tests environment through `env.tests.plugins` in `.wp-env.json` (`ericmann/secrets-api#v0.2.1`, with `"."` repeated because an env-level `plugins` key replaces the root one). CI needs no change because wp-env clones the source itself.
- `WP_SECRETS_KEY` is set to a fixed, test-only value so encryption is deterministic. `tests/bootstrap.php` loads `secrets-api.php` if present (override with `TWO_FACTOR_SECRETS_API_FILE`); when it is absent the suite still runs and API-dependent tests skip.
- Tests run in both suites (single site and multisite). Multisite-only cases cover reading a secret from a second blog, deletion semantics, and Network Admin notice capabilities.
- Group filter: `npm run composer -- test -- --group secrets`. Shared helpers live in `Two_Factor_Secrets_UnitTestCase`.
- PHPStan (level 5) uses stubs in `tests/phpstan/`, excluded from PHPUnit because they would redeclare the real functions.

## Open questions for maintainers

1. Should the adapter be public API for third-party providers, or `@internal` for now?
2. Is the `LOGGED_IN_*` salt-rotation hazard acceptable without `WP_SECRETS_KEY`, or should Two-Factor surface a Site Health recommendation to define it?
3. Naming the network-scope secrets `two-factor/totp-{ID}` puts one row per TOTP user in the options table. Is that acceptable on large networks, versus waiting for a user-scoped store in the Secrets API?
4. `Two_Factor_Provider::is_enrolled_but_unavailable_for_user()` is a generic core hook that lets any provider say "enrolled, but currently unusable", so core forces the fallback instead of failing open. Is that the right name and shape for a core-level concept?
5. `Two_Factor_Provider::uninstall_user_data()` is a bulk, static hook called once during uninstall for data held outside user meta. Would maintainers prefer a different name or a per-user shape?
6. Should a secret that is unreadable while the Secrets API is present also force the fallback provider, as a missing API does? Today the user is locked out until an administrator resets them.
