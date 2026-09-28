# SPEC: Store TOTP secrets with the WordPress Secrets API

Status: approved for build · Branch: `feature/secrets-api-totp` · Base: `master`
Target: a PR to `ericmann/two-factor` (the author's fork) for review. It is **not** a PR to `WordPress/two-factor`; the author opens that one by hand later.

## 1. Background

Two-Factor keeps each user's TOTP shared secret **in plaintext** in user meta (`_two_factor_totp_key`, `Two_Factor_Totp::SECRET_META_KEY`). Anyone who can read the database, a backup, or a SQL-injection dump can mint valid codes for every TOTP user. An earlier upstream attempt (`upstream/pr-389-recrypt`, PR #389) added homegrown XChaCha20 encryption keyed off `SECURE_AUTH_SALT`. It stalled because salt rotation needs a bespoke "recrypt" process.

The **WordPress Secrets API** removes the need for Two-Factor to own any cryptography:

- Core Trac ticket: #66187, milestone 7.2, accepted, not yet committed.
- Core PR: WordPress/wordpress-develop#13759.
- Feature plugin: `github.com/ericmann/secrets-api`, currently v0.2.1.
- Proposal: make.wordpress.org/core/2026/08/25/proposal-a-secrets-api-for-wordpress-7-2/

The API's own ADR 0003 names Two-Factor integration as the Two-Factor maintainers' call. This change is that integration.

### Secrets API facts this spec relies on (confirmed from feature-plugin source v0.2.1)

- The plugin and core use identical function names. Feature-detect with `function_exists( 'wp_get_network_secret' )` at **call time**, never when the plugin file is included.
- `wp_set_network_secret( $name, $value ): true|WP_Error`
- `wp_get_network_secret( $name ): WP_Secret|null|WP_Error`. The three return states must never be collapsed:
  - `null` means the secret does not exist.
  - `WP_Error` means it exists but cannot be read (for example `secret_key_unavailable` or `secret_decryption_failed`).
  - `WP_Secret` means success.
- `wp_delete_network_secret( $name ): true|WP_Error`
- `WP_Secret::reveal(): string|WP_Error`. The object masks itself when printed or serialized.
- `wp_secrets_provider_is_writable(): bool` and `wp_secrets_provider_label(): string`.
- `wp_secrets_memzero( &$value )`
- Names take the form `namespace/name`. Each segment matches `/^[a-z0-9]([a-z0-9_-]*[a-z0-9])?$/` and the whole name is at most 172 characters.
- There is **no per-user scope**, only site and network. Site-scope secrets are bound to the blog ID, so a site-scope secret written on blog 1 cannot be read on blog 2.
- The API does no capability checks. Namespaces are not access control.
- If `WP_SECRETS_KEY` is undefined, the key is derived from `LOGGED_IN_KEY`/`LOGGED_IN_SALT`. Rotating those salts without `WP_SECRETS_KEY` set makes every secret return `WP_Error`.
- A broken drop-in fails closed: every call returns `WP_Error`.
- There is no user-meta import helper and no per-user lifecycle hooks. The owning plugin handles migration and cleanup.

## 2. Goals

1. When the Secrets API is present, new and existing TOTP secrets are stored encrypted in the Secrets API instead of plaintext user meta. This happens automatically, with a filter to opt out.
2. Existing plaintext secrets are migrated **lazily**, the first time they are read in the login or profile flow. A **WP-CLI bulk command** migrates everyone in one pass.
3. Sites without the Secrets API (WP < 7.2 without the feature plugin) behave exactly as today. Nothing changes and nothing breaks.
4. Failures **fail closed**:
   - An unreadable secret (`WP_Error`) never passes TOTP authentication.
   - It is never mistaken for "not configured."
   - It never silently re-enrolls the user.
   - Other providers (backup codes, email fallback) keep working.
5. If the Secrets API disappears after migration (the feature plugin is deactivated), affected users fall back to their other providers. Administrators get a clear admin notice and a critical Site Health result. A WP-CLI export command moves secrets back to user meta before deactivation.
6. Deleting a user and uninstalling the plugin both clean up the stored secrets.

## 3. Non-goals

- Backup codes and email tokens. They are already stored as one-way hashes and don't need reversible storage.
- A settings-page UI toggle. The opt-out is a filter only.
- A cron-based background migration sweep.
- Owning any cryptography or key management in Two-Factor.
- Bumping `Requires at least` or making the Secrets API a hard dependency.
- Per-user access control on secrets. The Secrets API doesn't offer it, and Two-Factor doesn't need it.
- Changes to files in `includes/`.

## 4. Design

### 4.1 Storage adapter: `Two_Factor_Secrets`

Add a new core file, `class-two-factor-secrets.php`, in the plugin root next to `class-two-factor-compat.php`. `two-factor.php` loads it. The class is a thin static adapter so that providers never call `wp_*_secret` directly. It is written generically (keyed by a provider "slug") so a future provider could reuse it. TOTP is the only consumer in this change.

Required surface (names are normative; parameter lists may gain optional args):

| Method | Returns | Behavior |
|---|---|---|
| `is_api_present()` | `bool` | `function_exists( 'wp_get_network_secret' )`, plus the other functions used. Checked at call time. This is the single seam tests use to simulate absence (see 4.8). |
| `can_write()` | `bool` | `is_api_present()`, the `two_factor_use_secrets_api` filter (default `true`) passes, and `wp_secrets_provider_is_writable()` is true. |
| `get_secret_name( $user_id, $slug )` | `string` | `two-factor/{$slug}-{$user_id}`, for example `two-factor/totp-42`. Must satisfy the Secrets API name rules. |
| `get_user_secret( $user_id, $slug )` | `string\|null\|WP_Error` | Revealed plaintext, `null` if absent, `WP_Error` if unreadable or if the API is absent while the marker says a secret exists. |
| `set_user_secret( $user_id, $slug, $value )` | `true\|WP_Error` | Writes the network secret and records the marker (4.2). |
| `delete_user_secret( $user_id, $slug )` | `true\|WP_Error` | Deletes the network secret if present and removes the marker. A missing secret is success. |

Rules:

- **Always use the network-scope functions** (`wp_*_network_secret`), on both single site and multisite. Two-Factor's user meta applies across the network, so the secret must too. On a single site the network functions store in `wp_options` via `*_site_option`, which works.
- The adapter never logs, echoes, or returns a revealed value anywhere except to its immediate caller. After a revealed value has been used for validation, zero it with `wp_secrets_memzero()` where practical.
- `two_factor_use_secrets_api` (bool, default `true`) controls **writes and migration only**. When it returns `false`, new secrets go to plaintext user meta and nothing is migrated. Users who already have a stored secret are **still read** from the Secrets API as long as it is present, so opting out never locks anyone out. Document this in the filter's docblock.

### 4.2 Location marker (user meta)

Add a user meta key `_two_factor_totp_key_network` (constant `Two_Factor_Totp::SECRET_NETWORK_META_KEY`). It holds the **network ID** (`get_current_network_id()`) whose Secrets API store holds that user's TOTP secret.

- The marker lets `is_available_for_user()` answer cheaply, without decrypting. It also lets downgrade detection find affected users.
- If a read happens on a different network than the marker names (multi-network installs share `wp_users`), the result is `WP_Error` (`two_factor_secret_wrong_network`). It is never `null`.
- If a marker exists but `is_api_present()` is false, the result is `WP_Error` (`two_factor_secrets_api_missing`).

Where the authoritative TOTP secret lives:

1. **Plaintext meta (`_two_factor_totp_key`) non-empty:** plaintext is authoritative. This covers legacy data and data written by an older plugin version after a downgrade followed by an upgrade. If `can_write()` is true, migrate it (4.4).
2. **Else, marker present:** the Secrets API is authoritative.
3. **Else:** no TOTP secret.

### 4.3 `Two_Factor_Totp` changes

Existing public methods keep their signatures and backward-compatible return semantics, because tests and third parties call them.

- **New `get_user_totp_key_state( $user_id ): string|null|WP_Error`.** The tri-state read that follows 4.2's precedence. Every internal caller that must tell "absent" apart from "unreadable" uses this.
- **`get_user_totp_key( $user_id ): string`.** Backward-compatible wrapper that returns `''` for both `null` and `WP_Error`. Update its docblock to point to the new method. It triggers lazy migration like any other read.
- **`set_user_totp_key( $user_id, $key )`:**
  - When `can_write()` is true, it writes via the adapter, then deletes the plaintext meta **only after** verifying (4.4).
  - Otherwise it writes plaintext meta exactly as today, and also deletes any stale marker and secret (best effort) so that step 1 of 4.2 stays unambiguous.
  - It returns a truthy value on success and `false` on failure, as today. A Secrets API write error **must not** silently fall back to plaintext when `can_write()` was true. It returns `false`, and the REST setup endpoint surfaces its existing `db_error` message.
- **`delete_user_totp_key( $user_id )`:** deletes plaintext meta, the last-login meta, the Secrets API secret and the marker. When the API is absent, it removes the marker anyway, so a reset is always possible.
- **`is_available_for_user( $user )`:** true if plaintext meta is non-empty, **or** the marker is present **and** `is_api_present()` **and** the marker matches the current network. It must not decrypt. When it returns false for a migrated user because the API is missing, core's existing provider fallback applies (see 4.6).
- **`validate_code_for_user` / `validate_authentication`:** read via the tri-state. `null` and `WP_Error` both fail validation. No code path treats `WP_Error` as success, as empty-and-allowed, or as a reason to skip 2FA.
- **Authentication page:** when the read is a `WP_Error`, show a translated message that the authenticator secret is currently unavailable and the user should use another method, such as a recovery code. Do not show the `WP_Error` message or code to the user. Expose the error code only through an action (4.7).
- **Profile UI (`user_two_factor_options`):** when the tri-state read is a `WP_Error`, do **not** fall into the "generate a new key / setup" branch silently. Show a notice that the stored secret can't be read, plus the existing "Reset authenticator app" control. Resetting deletes the secret and re-enrollment proceeds normally. `null` keeps today's setup flow.
- **`uninstall_user_meta_keys()`:** add the marker key.
- Add `SECRET_NETWORK_META_KEY` to the AGENTS.md meta table.

### 4.4 Lazy migration

Migration triggers whenever `get_user_totp_key_state()` finds non-empty plaintext meta **and** `can_write()` is true. Steps:

1. `set_user_secret()`. On `WP_Error`, keep the plaintext, return the plaintext value (login must not break), and fire `two_factor_secrets_migration_failed`.
2. Read it back with `get_user_secret()` and compare to the plaintext with `hash_equals()`. On mismatch or error, delete the new secret and marker (best effort), keep the plaintext, fire the failure action, and return the plaintext.
3. On success, delete the plaintext `_two_factor_totp_key` meta, fire `two_factor_secrets_migrated`, and return the value.

Migration must be **idempotent** and safe under concurrent requests. Two simultaneous logins migrating the same value must both succeed, and must never leave a user with neither plaintext nor a readable secret. `is_available_for_user()` never migrates, because it runs on list screens and must stay a cheap read.

### 4.5 User deletion and uninstall

- **Single site:** hook `delete_user` and delete that user's TOTP secret and marker.
- **Multisite:** do **not** delete on `delete_user`. In multisite, `delete_user` fires when a user is removed from one blog, but the account remains network-wide. Delete on `wpmu_delete_user` only. Add tests for both cases.
- Register the hooks in `Two_Factor_Totp`'s constructor, following the provider self-registration pattern in AGENTS.md.
- **Uninstall (`Two_Factor_Core::uninstall()`):** for every user with the marker, delete the Secrets API secret if the API is present, then the meta. A provider may declare additional per-user uninstall work through a new optional static hook on the base class, for example `uninstall_user_data( $user_id )`, which is a no-op by default. Don't add TOTP-specific logic to core. If the API is absent at uninstall, the secrets are orphaned in the store. Document this in the uninstall docblock and the readme FAQ. It is acceptable.

### 4.6 Downgrade / missing-API behavior

"Affected users" means users with the marker while `is_api_present()` is false, or while the marker names another network.

- **Login:** TOTP reports unavailable for those users, so core's existing logic picks another enabled provider or the `two_factor_fallback_provider_for_user` / email fail-safe. The build must **verify with tests** that a user whose only enabled provider is TOTP, and who is affected, is **not** let in without a second factor. This is the existing fail-closed email fallback; add a regression test that proves it.
- **Admin notice:** shown on admin screens to users with `manage_options`, or `manage_network_options` in the network admin on multisite, when at least one affected user exists. The notice is not dismissible and names the WP-CLI export and migrate commands. Keep detection cheap and run it only when the API is absent: `get_users()` with `meta_key`, `number => 1`, `fields => 'ID'` and `count_total => false`, cached in a short-lived transient.
- **Site Health:** add a direct test `two_factor_totp_secret_storage` through the `site_status_tests` filter.

  | Situation | Status | Content |
  |---|---|---|
  | Affected users exist | `critical` | Explains the lockout risk and remedies: re-activate the Secrets API, or run the export command before deactivating. |
  | API present, `can_write()` true, plaintext secrets remain | `recommended` | Suggests `wp two-factor secrets migrate`. |
  | API present and nothing left in plaintext | `good` | Names `wp_secrets_provider_label()`. |
  | API absent and no affected users | `good` | Neutral wording ("TOTP secrets are stored in user meta; the WordPress Secrets API, when available, will be used automatically"). **Do not** nag sites that predate 7.2. |

### 4.7 Hooks (new, documented)

- `apply_filters( 'two_factor_use_secrets_api', bool $use, int $user_id )`. Opts out of writes and migration (4.1). `$user_id` may be 0 for non-user contexts.
- `do_action( 'two_factor_secrets_migrated', int $user_id, string $slug )`
- `do_action( 'two_factor_secrets_migration_failed', int $user_id, string $slug, WP_Error $error )`
- `do_action( 'two_factor_secret_unavailable', int $user_id, string $slug, WP_Error $error )`. Fires when a login or profile read hits `WP_Error`.

No hook ever receives a plaintext secret.

### 4.8 WP-CLI: `wp two-factor secrets <action>`

Follow the existing `backup-codes <action>` subcommand pattern in `CLI/class-two-factor-cli-command.php`, including `resolve_user()` and the confirmation conventions.

| Command | Purpose |
|---|---|
| `wp two-factor secrets status [--format=<format>]` | Whether the API is present, the provider label, whether it is writable, and the effect of the `two_factor_use_secrets_api` filter. Counts of users with plaintext TOTP secrets, users migrated, and affected users. |
| `wp two-factor secrets migrate [--user=<id\|login\|email>] [--batch-size=<n>] [--dry-run]` | Migrates plaintext secrets using the same code path as lazy migration (4.4). Pages through users in batches (default 100) to bound memory, and reports migrated, failed and skipped counts. Errors out clearly if `can_write()` is false. |
| `wp two-factor secrets export [--user=<id\|login\|email>] [--yes]` | Reverse migration for decommissioning the Secrets API. Reads each stored secret, writes plaintext meta, verifies it, then deletes the secret and marker. Requires confirmation without `--yes` and warns that secrets will be stored unencrypted. Users whose secrets are unreadable are reported and left untouched. |

Also extend the existing `wp two-factor status <user>` output with a TOTP storage field (`plaintext`, `secrets-api`, `unavailable` or `none`).

### 4.9 Other constraints

- PHP 7.4 compatible (PHPCompatibilityWP). WPCS / VIP-Go PHPCS clean. PHPStan (the config is level 5) clean.
- PHPStan doesn't know `wp_*_secret` or `WP_Secret`. Add stubs, for example `tests/phpstan/secrets-api-stubs.php` referenced under `scanFiles`, rather than baseline ignores.
- No new runtime dependencies. No composer package for the Secrets API in production.
- Don't modify anything under `includes/`.
- Strings are translatable with the `two-factor` text domain.

## 5. Testing

- **Test environment:** make the Secrets API feature plugin available to the `tests` wp-env environment, for example a `.wp-env.json` `env.tests.mappings` / `plugins` entry pinned to `ericmann/secrets-api#v0.2.1`.
  - Load it from `tests/bootstrap.php` on `muplugins_loaded` before Two-Factor, **only if the file exists**, so the suite still runs without it.
  - Define a deterministic `WP_SECRETS_KEY` (canonical base64 of 32 bytes) in the tests config.
  - Update `.github/workflows/test.yml` only if the wp-env change alone isn't enough.
- **Both code paths in one process:** tests need a way to simulate "API absent" while the functions exist. Use one internal seam on `Two_Factor_Secrets::is_api_present()`, for example a filter prefixed and documented as `@internal` for tests, or an overridable static. It must not be advertised as a public extension point. Tests that need the real API `markTestSkipped()` when the feature plugin isn't loaded.
- **Existing tests** that call `set_user_totp_key` / `get_user_totp_key` must pass unchanged, with and without the API.
- **New tests** (`@group secrets` plus existing groups as appropriate), at minimum:
  - The adapter: name format, all three read states, network-scope usage, and the `can_write()` matrix (absent, filtered off, read-only provider).
  - Set, get and delete round-trips with the API: plaintext meta is empty afterward and the marker is set.
  - Lazy migration: success deletes the plaintext; write failure and read-back mismatch keep the plaintext and fire the failure action; the operation is idempotent.
  - Precedence: plaintext beats marker (4.2).
  - `WP_Error` read: validation fails, the auth page shows the unavailable message, the profile UI shows the reset path and not the setup flow, and `two_factor_secret_unavailable` fires.
  - The filter off: new writes go to plaintext, and existing migrated users are still readable.
  - API absent with a marker: `is_available_for_user()` is false, and the login lockout/fallback regression from 4.6 holds.
  - Wrong network marker (4.2).
  - User deletion: the single-site `delete_user` path; on multisite, `delete_user` keeps the secret and `wpmu_delete_user` removes it.
  - Uninstall removes the secrets and the marker.
  - Site Health result for each state, and admin notice visibility by capability.
  - CLI `secrets status`, `migrate` (dry run, per user, batching) and `export` (confirmation, round trip); the `status` storage field.
  - Multisite suite: a secret set on blog 1 validates while on blog 2.
- Everything runs through `npm test`, both single site and multisite, and lint (`npm run lint`, `npm run lint:phpstan`) must pass.

## 6. Documentation

- `readme.txt`: a FAQ entry, "How are authenticator (TOTP) secrets stored?" It covers the Secrets API, the opt-out filter, the `WP_SECRETS_KEY` recommendation and the salt-rotation warning, the migrate and export CLI commands, and the deactivation warning.
- `readme.md`: mirror the readme.txt content where readme.md carries equivalent sections.
- `CHANGELOG.md`: an `## [Unreleased]` section with a "New Features" entry. Don't invent a PR number; use a placeholder the author will fill in.
- `AGENTS.md`: document `class-two-factor-secrets.php` in Core Files, the new meta key, the hooks, and the CLI subcommand.
- `TESTS.md`: document the new test file(s) and groups, and how the Secrets API plugin is provided to tests.
- Inline docblocks with `@since` using the next version placeholder `0.18.0`.
- `docs/PR-DESCRIPTION.md`: a ready-to-paste upstream PR body covering motivation (plaintext secrets, PR #389's history), design summary, the migration and downgrade story, testing notes, and open questions for maintainers. The author will open the upstream PR by hand.

## 7. Process constraints for the build

- The PR produced by this build targets **`ericmann/two-factor`, base `master`** (gh's default repo is pinned to the fork). **Never** open a PR, issue or comment against `WordPress/two-factor`.
- The root `CLAUDE.md` is a checked-in file whose only content is `@AGENTS.md`. Do **not** replace it. If the pipeline needs to add build guidance, append it inside a clearly delimited `<!-- foundry:begin -->` / `<!-- foundry:end -->` block so it can be stripped before upstreaming.
- Foundry's working documents (`docs/SPEC.md`, `docs/PLAN.md`, `docs/PROGRESS.md`, `docs/foundry.json`, review and summary files) live under `docs/`. The author strips them before upstreaming, so keep product documentation out of those files.

## 8. Open questions for upstream maintainers (to go into the PR description, not resolved here)

1. Should the adapter be public API for third-party providers, or `@internal` for now?
2. Is the `LOGGED_IN_*` salt-rotation hazard acceptable without `WP_SECRETS_KEY`, or should Two-Factor surface a Site Health recommendation to define it?
3. Naming the network-scope secrets `two-factor/totp-{ID}` puts one row per TOTP user in the options table. Is that acceptable on large networks, versus waiting for a user-scoped store in the Secrets API?
