# Handoff: TOTP secrets in the WordPress Secrets API

- Branch: `build/2026-09-28` (the spec named `build/secrets-api-totp`; `foundry_run_start` created this one and the run stayed on it)
- Base commit: `8799d03` (plan) on `feature/secrets-api-totp`
- Head commit at handoff: `5826028` (plus this file)
- Tasks: 21 done, 0 blocked, 0 skipped
- Final verify: lint, PHPStan (level 5), lint-compat, and both PHPUnit suites green (414 tests each; single site has 5 skips, multisite has 1, all environment-specific).

## Blocked / skipped tasks

None.

## Interpretation choices

- P0-01: stayed on the `build/2026-09-28` branch instead of creating `build/secrets-api-totp`. The `.wp-env.json` and bootstrap changes had already been applied by the operator.
- P1-01: the test classes are named `Two_Factor_Secrets_Tests` (file `tests/class-two-factor-secrets-tests.php`) and, in P2-01, `Two_Factor_Totp_Secrets_Tests` (file `tests/providers/class-two-factor-totp-secrets-tests.php`), not `Tests_Two_Factor_*` as the plan said. PHPCS requires the file name to match the class for classes that don't extend a recognised WP test case, and a file sorting before `tests/class-two-factor-core.php` breaks the core tests (they register `set_auth_cookie` hooks in `set_up_before_class()`, which an earlier test class wipes). Filter parameter is `$enabled` because PHPCS flags `$use`.
- P1-02: `resolve_fallback_provider_for_user()` has an optional fourth by-reference argument that exposes the raw filter value so the existing error data (`fallback_provider`) stays byte-for-byte. The `Two_Factor_Dummy_Unavailable` fixture is loaded through the `two_factor_providers` filter path, not `tests/bootstrap.php`, because `Two_Factor_Dummy` is not loaded at bootstrap time.
- P2-01: `delete_user_totp_key()` now returns `true` only when plaintext, marker and secret are all gone (including when there was nothing to delete).
- P2-02: the unavailable login prompt renders the two prompt actions and the unavailable message but omits the generic "Enter the code" line.
- P2-03: deletion hooks call a new void wrapper `delete_user_secrets_on_user_deletion()` instead of `delete_user_totp_key()`, because PHPStan level 5 rejects bool-returning action callbacks. The constructor test asserts the wrapper.
- P3-02: with the API present and no plaintext users the Site Health result is `good` naming the provider label, even when writes are disabled; the "migration disabled" message is used only when plaintext remains and `can_write()` is false.
- P4-01: `Two_Factor_Secrets::provider_label()` returns `''` whenever `is_api_present()` is false (so the test seam is honoured). Booleans in `secrets status` are the strings `'true'`/`'false'`.
- P5-01: `readme.md` has no equivalent sections and was left unchanged. `readme.txt` uses CRLF line endings, preserved.

## `⚠️ ASSUMPTION` config keys

None introduced. Constants: `SECRET_SLUG = 'totp'`, `AFFECTED_USERS_CACHE_TTL = 300`, `Two_Factor_CLI_Command::SECRETS_DEFAULT_BATCH_SIZE = 100` (none tuned).

## Manual checks (all NOT VERIFIED (human))

- Phase 0: fresh clone with `npm install && npm run env start` clones `ericmann/secrets-api` v0.2.1 into the tests env; CI passes on the WP `latest`, `7.0-branch`, `7.1-branch` legs; `gh repo set-default --view` shows the fork.
- Phase 1: Dummy-only user logs in unchanged on the dev site; read `class-two-factor-secrets.php` for any path that could echo or log a revealed value.
- Phase 2: enrol an authenticator app and confirm the `_wp_network_secret_two-factor/totp-<id>` option exists with empty `_two_factor_totp_key` meta; log in with an app code; deactivate the feature plugin and confirm only other methods are offered, and that the profile shows the reset notice after re-activating with `WP_SECRETS_KEY` changed; deleting the user removes the option row.
- Phase 3: red non-dismissible notice for an admin (not an editor) with a migrated user and the feature plugin off; Site Health goes critical, then recommended, then good; on multisite the notice shows in Network Admin for a super admin.
- Phase 4: `wp two-factor secrets status` (and `--format=json`), `migrate --dry-run` then `migrate`, `status <user>` shows `secrets-api`, `export` prompts and `--yes` completes, `wp help two-factor secrets` shows the OPTIONS block.
- Phase 5: read `docs/PR-DESCRIPTION.md` and the readme FAQ for tone; confirm CI is green; open the fork PR (base `master`, `ericmann/two-factor`) if the pipeline did not; rerun the Phase 2 and 3 browser checks on the final commit.

## For a reviewer who has not seen this code

- `Two_Factor_Secrets` is the only file that may call the Secrets API (enforced by constraints in `docs/foundry.json`). Secrets are network scope only; a user-meta marker `_two_factor_totp_key_network` records the owning network.
- Read precedence: non-empty plaintext meta, then no marker means none, then the Secrets API (tri-state string / null / `WP_Error`).
- Security-critical path: `Two_Factor_Core::get_available_providers_for_user()` now forces the fallback provider (or a `no_available_2fa_methods` error) when the only enrolled providers report `is_enrolled_but_unavailable_for_user()`. The regression test is `test_login_fails_closed_for_affected_totp_only_user` (and its no-fallback variant).
- Tests that need the API skip when it is absent; tests simulate absence through the internal `two_factor_secrets_api_present` filter.
- `docs/PR-DESCRIPTION.md` is the ready-to-paste upstream PR body; nothing was opened against `WordPress/two-factor`.
- Feedback logged: none (no Foundry tooling friction).

## Round 1

- Branch: build/2026-09-28. Tasks: 22 done, 0 blocked, 0 skipped.
- R1-01 (done, b6e6c61): corrected readme FAQ, docs/PR-DESCRIPTION.md and the `secrets` CLI docblock. An undecryptable secret with the Secrets API present locks the user out (no forced fallback); decommissioning requires returning false from `two_factor_use_secrets_api` before `secrets export`. Two behaviour-pinning tests added. No PHP behaviour change.
- Interpretation: added open question 6 to PR-DESCRIPTION.md (forcing a fallback for undecryptable secrets). TESTS.md unchanged.
- Manual check: NOT VERIFIED (human) - read the corrected FAQ text.
