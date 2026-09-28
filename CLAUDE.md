@AGENTS.md

<!-- foundry:begin -->
# Build guidance: TOTP secrets via the WordPress Secrets API

Everything below is scoped to the build described in `docs/SPEC.md` and `docs/PLAN.md`. Strip this block before upstreaming.

## Principles
- Fail closed. An unreadable secret (`WP_Error`) never validates, is never treated as "not configured", never re-enrols the user, and never drops a user to single-factor login. Other providers keep working.
- Two-Factor owns no cryptography. All storage goes through `Two_Factor_Secrets`; never call `wp_*_secret()` elsewhere.
- Feature-detect at call time (`function_exists( 'wp_get_network_secret' )`), never at include time. Sites without the API behave exactly as today.
- Three read states are sacred: `null` (absent), `WP_Error` (unreadable), string (value). Never collapse them.
- Plaintext user meta (`_two_factor_totp_key`) beats the marker; marker (`_two_factor_totp_key_network`, holds the network ID) beats nothing; else no secret.
- Migration is lazy, verified by read-back with `hash_equals()`, idempotent, and safe under concurrent requests. `is_available_for_user()` never migrates and never decrypts.
- Revealed values go only to the immediate caller; `Two_Factor_Secrets::memzero()` after use. No hook ever receives plaintext.
- `two_factor_use_secrets_api` (default `true`) gates writes and migration only; migrated users are still read while the API is present.
- Every task ships its tests. Existing tests must pass unchanged with and without the API.
- PHP 7.4 syntax, WPCS + VIP-Go clean, PHPStan level 5 clean via stubs (no baseline ignores), `two-factor` text domain, `@since 0.18.0`.

## Commands
- `npm run env start` (once; restart after editing `.wp-env.json`)
- `npm run lint` (PHPCS, PHPStan, CSS, JS) and `composer lint-compat`
- `npm test` (single site then multisite; run sequentially, never concurrently)
- `npm run composer -- test -- --group secrets|totp|cli|core` for a subset
- `npm run env run tests-cli wp plugin list` to confirm `secrets-api` is mapped

## Module map
- `two-factor.php` — loads core, compat, `class-two-factor-secrets.php`, settings; registers CLI and uninstall.
- `class-two-factor-secrets.php` — `Two_Factor_Secrets`, static adapter: `is_api_present`, `is_provider_writable`, `can_write`, `get_secret_name`, `get_marker_meta_key`, `get_user_secret`, `set_user_secret`, `delete_user_secret`, `provider_label`, `memzero`; `@internal` seams `two_factor_secrets_api_present` filter and `$test_overrides`.
- `class-two-factor-core.php` — login orchestration; `get_available_providers_for_user()` forces the fallback provider when every enabled provider is unregistered or enrolled-but-unavailable; `uninstall()` calls each provider's `uninstall_user_data()` before deleting meta.
- `providers/class-two-factor-provider.php` — base class; new `is_enrolled_but_unavailable_for_user()` (default `false`) and static `uninstall_user_data()` (no-op).
- `providers/class-two-factor-totp.php` — `get_user_totp_key_state()`, `get_user_totp_key()`, `set_user_totp_key()`, `delete_user_totp_key()`, `migrate_user_totp_key()`, `export_user_totp_key()`, `get_user_totp_key_storage()`, `count_users_by_storage()`, `has_affected_users()`, `has_plaintext_users()`, admin notice, Site Health test `two_factor_totp_secret_storage`, deletion hooks (`delete_user` single site, `wpmu_delete_user` multisite).
- `CLI/class-two-factor-cli-command.php` — `status` gains `totp_storage`; `secrets status|migrate|export`.
- `includes/` — never modified.
- `tests/class-two-factor-secrets-test-case.php` — `Two_Factor_Secrets_UnitTestCase` (`require_secrets_api()`, `simulate_api_absent()`, override reset). `tests/phpstan/` — stubs, excluded from PHPUnit.

## Constraints
Rules marked (pattern) are enforced by `docs/foundry.json` `constraints`; the rest the reviewer checks by reading.
- (pattern) No `wp_*_secret()` / `wp_secrets_*()` call outside `class-two-factor-secrets.php`.
- (pattern) No site-scope `wp_get_secret`/`wp_set_secret`/`wp_delete_secret`/`wp_list_secrets` anywhere; network scope only.
- (pattern) The `two-factor/{slug}-{id}` prefix appears only in the adapter.
- (pattern) `_two_factor_totp_key` and `_two_factor_totp_key_network` are referenced via constants outside their definitions.
- (pattern) No `error_log`/`var_dump`/`print_r`/`var_export` in the adapter, the TOTP provider or the CLI.
- (pattern) Every `__()`-family call uses the `two-factor` domain.
- (pattern) `Requires at least: 7.0` is not bumped; no composer/npm dependency on `secrets-api`.
- (pattern, single-line only) `two_factor_secret*` hooks never receive `$plaintext|$secret|$key|$value|$readback|$state`.
- (pattern) CLI batch size and the affected-users transient TTL come from constants.
- (reading) No diff under `includes/`: `git diff --stat feature/secrets-api-totp -- includes/` must be empty.
- (reading) `is_available_for_user()` and `is_enrolled_but_unavailable_for_user()` never call `get_user_secret()`, `migrate_user_totp_key()` or `reveal()`.
- (reading) Every `get_users()`/`WP_User_Query` over TOTP meta passes `'blog_id' => 0`.
- (reading) `WP_Error` messages or codes from the Secrets API are never echoed to end users; only the translated "unavailable" text is.
- (reading) No `gh pr`/`gh issue` command targets `WordPress/two-factor`; always `--repo ericmann/two-factor`.
- (reading) Multi-line `do_action( 'two_factor_secret…' )` calls carry no plaintext argument.

## Commit template
```
<ID>: <title>

Goal: <one sentence>
Tests: <test files and names added or changed>
Interpretation: <any reading chosen where SPEC allowed two, or "none">
Measurement: <tuning tasks only; otherwise omit>
Manual check: <what a human must verify, or "none">
```

SPEC.md wins over PLAN.md, which wins over code comments.
<!-- foundry:end -->
