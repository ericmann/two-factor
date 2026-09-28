# Build summary: TOTP secrets in the WordPress Secrets API

**Merge line:** `build/2026-09-28`, base `8799d03` (on `feature/secrets-api-totp`) → head `fe547f2`, 55 commits (plus this summary). 22 tasks done (21 plan + 1 review fix), 0 blocked, 0 skipped. 2 review rounds; final verdict **APPROVED**. Not merged. The upstream-facing PR targets `master` on `ericmann/two-factor` and must be retargeted by hand (see Spec issues).

## What was built

**Phase 0: test environment (P0-01 to P0-03).** The wp-env tests environment now loads the `ericmann/secrets-api` v0.2.1 feature plugin with a fixed test-only `WP_SECRETS_KEY`. PHPStan gets stubs for the Secrets API functions, and the stub directory is excluded from PHPUnit.

**Phase 1: storage adapter and provider base hooks (P1-01 to P1-03).** `Two_Factor_Secrets` is the only class that talks to the Secrets API. It stores secrets at network scope, and a user-meta marker (`_two_factor_totp_key_network`) records which network owns each secret. Reads are tri-state: a string, `null` for none, or a `WP_Error`. The provider base class gains `is_enrolled_but_unavailable_for_user()` and a bulk static `uninstall_user_data()`. Core now fails closed. If a user's only enrolled providers are enrolled but unavailable, core forces the fallback provider, or returns a `no_available_2fa_methods` error when there is no fallback. Before this change, the user would have logged in with only a password.

**Phase 2: TOTP integration (P2-01 to P2-04).** TOTP keys are written to the Secrets API when it can write. Existing plaintext keys migrate lazily on read. An unreadable secret never authenticates: the login prompt shows an "unavailable" message instead of a code field, and the profile offers a reset. Deleting a user removes their secret row, and so does plugin uninstall.

**Phase 3: downgrade detection (P3-01 to P3-03).** Administrators see a red, non-dismissible notice when migrated users exist but the Secrets API is missing (or, on multisite, when markers name another network). The affected-user lookup is cached for 300 s. A new Site Health test, `two_factor_totp_secret_storage`, reports critical, recommended or good.

**Phase 4: WP-CLI (P4-01 to P4-04).** `wp two-factor status` now shows a storage field. A new `wp two-factor secrets` command adds three subcommands:
- `status`: storage counts, with `--format` support.
- `migrate`: moves plaintext keys into the Secrets API, with `--dry-run` and batching.
- `export`: moves secrets back into plaintext meta for decommissioning. It asks for confirmation unless `--yes` is given.

**Phase 5: documentation (P5-01 to P5-04).** Updated readme.txt (FAQ, hooks, CLI) and CHANGELOG.md. AGENTS.md now says PHPStan level 5, and TESTS.md was updated. `docs/PR-DESCRIPTION.md` holds the upstream PR body, with SPEC 8's open questions copied verbatim plus open question 6.

**Review fix (R1-01).** Corrected two claims in the readme FAQ, the `secrets` CLI docblock and the PR description:
- An undecryptable secret while the API is present locks a TOTP-only user out until an admin resets them. It does not force the fallback provider.
- Decommissioning must return false from `two_factor_use_secrets_api` *before* running `export`. Otherwise lazy migration moves exported users straight back into the Secrets API.

Two tests now pin both behaviours. No PHP behaviour changed.

## Decisions that shaped it

- **Plan:** The phases are derived, because SPEC defines none: scaffold → adapter/base hooks → TOTP → downgrade detection → CLI → docs.
- **P1-02:** SPEC 4.6 assumed core already falls back for a registered-but-unavailable provider. It does not, so a generic base-class hook `is_enrolled_but_unavailable_for_user()` (default `false`) was added and core applies `two_factor_fallback_provider_for_user`. No TOTP logic lives in core.
- **P1-02:** SPEC 4.5's per-user `uninstall_user_data( $user_id )` became a bulk static `uninstall_user_data()` with no argument. The provider pages through its own users, and core calls it before deleting user meta.
- **P1-01:** The "API absent" test seam is an `@internal` filter, `two_factor_secrets_api_present`. It can only force `false`.
- **P1-01:** The seam for write failure and read-back mismatch is an `@internal` static, `Two_Factor_Secrets::$test_overrides` (keys `set`, `get`, `delete`, `writable`). It is needed because the Secrets API caches its provider and store per process. The shared test case clears it in `tear_down()`.
- **P1-01:** When the marker is present, the API is present and the network matches, but the API returns `null`, the adapter returns `WP_Error( 'two_factor_secret_missing' )` rather than `null`. The profile reset clears the stale marker.
- **P2-01:** `set_user_totp_key()` with an empty key calls `delete_user_totp_key()` instead, so an empty secret is never written.
- **P2-01:** `delete_user_totp_key()` returns `true` only when plaintext, marker and secret are all gone, including when there was nothing to delete.
- **P2-02:** The "unavailable" login prompt omits the generic "Enter the code" line.
- **P2-03:** The deletion hooks call a void wrapper, `delete_user_secrets_on_user_deletion()`, because PHPStan level 5 rejects bool-returning action callbacks.
- **P3-01:** When the API is present, affected-user detection runs only on multisite, and only looks for markers that name a different network. The result is cached in a site transient for 300 s.
- **P3-02:** When the API is present, `can_write()` is false and plaintext remains, Site Health reports `good` with "migration disabled" wording. This row is not in SPEC's table. With the API present and no plaintext users, the result is `good` and names the provider label, even when writes are disabled.
- **P4-01:** `Two_Factor_Secrets::provider_label()` returns `''` whenever the API is absent. Booleans in `secrets status` are the strings `'true'`/`'false'`.
- **P4-02:** `migrate` pages by ID. The offset advances only by the number of users in a batch that were not migrated, because migrated users drop out of the query. With `--dry-run` it advances by the full batch. `export` pages the same way.
- **P4-03:** If `export` verifies the plaintext but then fails to delete the Secrets API row, it still returns `true` and still removes the marker. The orphaned ciphertext row is accepted.
- **P5-01:** readme.md has no FAQ, hooks or CLI sections, so it was left unchanged. readme.txt keeps its CRLF line endings.
- **P5-03:** SPEC 8's open questions are not resolved in this build. They go verbatim into `docs/PR-DESCRIPTION.md`.
- **P0-01 / plan:** The run used branch `build/2026-09-28`, not SPEC's `build/secrets-api-totp`. The base is `feature/secrets-api-totp`, not `master`, and the author retargets by hand.
- **P1-01:** The test classes are `Two_Factor_Secrets_Tests` and `Two_Factor_Totp_Secrets_Tests`, not `Tests_Two_Factor_*`. PHPCS requires the file name to match the class, and the file sort order must not break the core tests' `set_up_before_class()` hooks. The filter parameter is named `$enabled` because PHPCS flags `$use`.
- **P1-02:** `resolve_fallback_provider_for_user()` takes an optional fourth by-reference argument, which keeps the existing `fallback_provider` error data byte-identical. The `Two_Factor_Dummy_Unavailable` fixture loads through the `two_factor_providers` filter.
- **R1-01:** Added open question 6 to the PR description: should an undecryptable secret force the fallback provider?

## Assumptions still in play

No `⚠️ ASSUMPTION` config keys were introduced. These constants were chosen without tuning and remain guesses:
- `Two_Factor_Totp::SECRET_SLUG = 'totp'`
- `Two_Factor_Totp::AFFECTED_USERS_CACHE_TTL = 300`: the notice and Site Health can lag by up to 5 minutes.
- `Two_Factor_CLI_Command::SECRETS_DEFAULT_BATCH_SIZE = 100`

## Spec issues (edits to make to SPEC.md)

1. **SPEC 4.6 relies on a fallback that does not exist** for registered-but-unavailable providers. Resolved in P1-02 with the new hook and core branch. SPEC should describe this mechanism.
2. **SPEC 4.5 `uninstall_user_data( $user_id )`** cannot be driven by core without TOTP knowledge. Change the SPEC to the bulk static form.
3. **Marker present but secret absent** is unspecified. Specify `WP_Error( 'two_factor_secret_missing' )`.
4. **The Site Health table** is missing the row "API present, cannot write, plaintext remains". Add it as `good` with migration-disabled wording.
5. **The "read-only provider" case in the `can_write()` matrix** has no Secrets API seam. Document the `$test_overrides['writable']` seam.
6. **PHPStan level:** SPEC 4.9 says 5, AGENTS.md said 2. AGENTS.md is now corrected to 5.
7. **Branch base:** SPEC 3 names `master`, but the build was cut from `feature/secrets-api-totp`. Align the SPEC or retarget by hand.
8. **readme.md mirror (SPEC 6):** readme.md has no matching sections. Drop the requirement.
9. **The test-only `WP_SECRETS_KEY` is public in `.wp-env.json`.** This is deliberate per SPEC 5. Note it explicitly.
10. **SPEC goal 4 conflicts with SPEC 4.3 for undecryptable secrets** (review rounds 1 and 2). SPEC 4.3 makes TOTP available whenever the marker, the API and the network all match, so a TOTP-only user whose secret fails to decrypt is locked out pending an admin reset instead of getting the email fallback. The code fails closed and the docs now say so. Decide whether to force a fallback (PR open question 6) and make the two SPEC sections consistent.
11. **TOTP disabled site-wide** (review rounds 1 and 2, **unresolved**). Removing TOTP in Settings → Two-Factor means `Two_Factor_Totp` is never loaded. As a result:
    - `wp two-factor secrets status|migrate|export` **fatals** (class not found). There is no `class_exists()` guard, unlike `status` and `backup-codes generate`.
    - The deletion hooks, admin notice and Site Health test are not registered.
    - Deleting a migrated user orphans that user's secret row.

    Choose between two fixes: load the TOTP class for these data-management paths regardless of the setting, or add a `class_exists()` guard with a clear `WP_CLI::error()`. Either one needs a test seam.

## Manual checks owed (all NOT VERIFIED, human)

- **Phase 0:**
  - A fresh clone with `npm install && npm run env start` clones `ericmann/secrets-api` v0.2.1 into the tests env.
  - CI is green on the WP `latest`, `7.0-branch` and `7.1-branch` legs.
  - `gh repo set-default --view` shows the fork.
- **Phase 1:**
  - A Dummy-only user logs in unchanged on the dev site.
  - Read `class-two-factor-secrets.php` for any path that could echo or log a revealed value.
- **Phase 2:**
  - Enrol an authenticator app. The `_wp_network_secret_two-factor/totp-<id>` option should exist and the `_two_factor_totp_key` meta should be empty.
  - Log in with an app code.
  - Deactivate the feature plugin. Only the other methods should be offered.
  - Re-activate with `WP_SECRETS_KEY` changed. The profile should show the reset notice.
  - Delete the user. The option row should be removed.
- **Phase 3:**
  - With a migrated user and the feature plugin off, an admin (not an editor) sees a red, non-dismissible notice.
  - Site Health moves from critical to recommended to good.
  - On multisite, a super admin sees the notice in Network Admin.
- **Phase 4:**
  - `wp two-factor secrets status` works, including with `--format=json`.
  - Run `migrate --dry-run`, then `migrate`.
  - `status <user>` shows `secrets-api`.
  - `export` prompts for confirmation, and `--yes` completes it.
  - `wp help two-factor secrets` shows the OPTIONS block.
- **Phase 5:**
  - Read `docs/PR-DESCRIPTION.md` and the readme FAQ for tone.
  - Confirm CI is green.
  - Open the fork PR (base `master`, `ericmann/two-factor`).
  - Rerun the Phase 2 and 3 browser checks on the final commit.
- **R1-01:**
  - Read the corrected FAQ text.
  - Check that `wp help two-factor secrets` renders the multi-line example comment cleanly.

## Review history

- **Round 1:** CHANGES REQUESTED. 2 findings:
  - F1: the salt-rotation lockout was misdocumented.
  - F2: the export-before-decommission procedure was undone by lazy migration.

  Both led to 1 fix task (R1-01). 2 spec issues were raised. Not non-converging.
- **Round 2:** APPROVED. 0 findings, 0 fix tasks. No finding recurred: R1-01 does not reappear, and both round-1 findings are resolved. Mutation sampling killed all 5 mutants. **This was a notes-only approval.** `## Notes` flagged four things that were not queued as work:
  - The admin notice and the critical Site Health text still say "run export before removing" without the filter-first step. This is moot in context, because export cannot run once the API is gone.
  - The FAQ line "run `migrate` after restoring" is harmless but unnecessary.
  - The readme.txt CLI bullet for `secrets export` omits the filter-first step, which the FAQ does include.
  - A theoretical concurrency rollback window during migration read-back is unchanged.

## Pipeline friction

- controller / halt: The run halted because P0-01 was blocked at operator level:
  1. The permission classifier twice denied (as "Untrusted Code Integration") the commands that add the third-party GitHub plugin `ericmann/secrets-api#v0.2.1` to `.wp-env.json` and start wp-env.
  2. Verify commands could not start: `node_modules` was missing (npm-run-all, wp-env and phpcs not found; exit 127), so nothing could be tested.

  The operator needed to allow this dependency (or add a Bash permission rule) and run `npm install` and `composer install`. No source changes were made.
