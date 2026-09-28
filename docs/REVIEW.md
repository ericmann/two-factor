# Review: TOTP secrets in the WordPress Secrets API
Round: 2

Branch `build/2026-09-28`, base `8799d03`, head `70d6209`. 22 tasks done (21 plan tasks plus R1-01), 0 blocked, 0 skipped.

## Verdict

APPROVED

Categories 1 to 3 (constraints, boundaries, tests) are clean across the whole branch. R1-01 resolves both round-1 findings, and I found no new findings. One edge case that round 1 recorded as a spec issue (the `secrets` CLI subcommand fatals when TOTP is disabled site-wide) is still open. It is carried forward below for a human decision.

## What I verified myself

- **`foundry_verify`:** all 11 `docs/foundry.json` constraints pass, with no fixture failures and zero hits. `npm run lint` (PHPCS, PHPStan level 5, CSS, JS), `composer lint-compat` and `npm test` are green. Each suite runs 416 tests: single site skips 5 and multisite skips 1. All skips are environment-specific.
- **R1-01 (`b6e6c61`) against its plan entry:**
  - F1 (salt-rotation lockout):
    - The readme FAQ now says that a TOTP-only user whose secret cannot be decrypted is locked out until an administrator resets them.
    - The FAQ also says the fallback is forced only when the Secrets API is missing or the marker names another network. That matches `is_available_for_user()` / `is_enrolled_but_unavailable_for_user()` and `Two_Factor_Core::get_available_providers_for_user()`.
    - The PR description's fail-closed bullet is scoped the same way, and open question 6 was added.
    - `grep "sent to the fallback" readme.txt` returns nothing.
  - F2 (decommissioning order): the readme FAQ, the `secrets()` docblock EXAMPLES and the PR description's Export bullet all give the same order:
    1. Return false from `two_factor_use_secrets_api`.
    2. Run `wp two-factor secrets export`.
    3. Deactivate the Secrets API.

    `export_user_totp_key()` does not check `can_write()`, and `delete_user_secret()` works while writes are filtered off, so this procedure does work.
  - The fix changes no PHP behaviour. The CLI edit is docblock-only, and the confirmation prompt string is unchanged.
  - `readme.txt` still has CRLF line endings.
  - The SPEC 8 open questions are still verbatim.
- **R1-01 tests.** Both new tests exist in `Two_Factor_Totp_Secrets_Tests` and assert the behaviour the corrected docs describe. When I mutated `is_enrolled_but_unavailable_for_user()` to return true for any marker (a forced fallback on decrypt failure), `test_unreadable_secret_with_api_present_keeps_totp_and_does_not_force_fallback` failed, together with the existing matrix test.
- **Reading-only constraints from CLAUDE.md, re-checked on the whole branch:**
  - `git diff --stat 8799d03..HEAD -- includes/` is empty.
  - `is_available_for_user()` and `is_enrolled_but_unavailable_for_user()` read meta only.
  - Every `get_users()` / `WP_User_Query` over TOTP meta passes `'blog_id' => 0`.
  - Only translated "unavailable" text reaches end users.
  - The multi-line `two_factor_secret*` actions carry only the user ID, the slug and a `WP_Error`.
  - The CLI export warning prints the error code, never a value.
- **Boundaries:**
  - Only `class-two-factor-secrets.php` calls the Secrets API.
  - Core has no TOTP-specific logic.
  - The only `.gitignore` change is Foundry's own `.foundry/implement.lock` line.
  - No CI, `.gitattributes` or editor-config changes.
- **Mutation sampling this round** (new mutants, different from round 1's). All were **killed**:
  - `class-two-factor-secrets.php`: collapsing "marker present, secret missing" to `null` (`if ( null === $value )` becomes `if ( false )`). 1 failure.
  - `providers/class-two-factor-totp.php`: `is_enrolled_but_unavailable_for_user()` returns true whenever a marker exists. 2 failures, including the new R1-01 test.
  - `providers/class-two-factor-totp.php`: dropping the per-user `delete_user_secret()` call from `uninstall_user_data()`. 2 failures.
  - `class-two-factor-core.php`: dropping the forced-fallback assignment in the enrolled-but-unavailable branch, which is a fail-open. 2 failures, including `test_login_fails_closed_for_affected_totp_only_user`.
  - `CLI/class-two-factor-cli-command.php`: removing the `can_write()` guard in `secrets migrate`. 2 failures.

## Findings

None.

## Spec issues

1. **Undecryptable secrets with the API present (carried from round 1, now documented).** SPEC 4.3 requires `is_available_for_user()` to be true whenever the marker is present, the API is present and the network matches. So a TOTP-only user whose secret fails to decrypt is locked out pending an admin reset, rather than being forced onto the email fallback that SPEC goal 4 implies. The code fails closed, the docs now say so, and `docs/PR-DESCRIPTION.md` open question 6 asks maintainers whether to force a fallback here.
2. **TOTP disabled site-wide (carried from round 1, still unresolved).** If an administrator removes TOTP in Settings → Two-Factor, `two_factor_filter_enabled_providers` drops it from `two_factor_providers`, so `Two_Factor_Totp` is never loaded. Consequences:
   - `wp two-factor secrets status|migrate|export` fatals on `Two_Factor_Totp::count_users_by_storage()` or `Two_Factor_Totp::get_instance()`. The existing `backup-codes generate` path (`CLI/class-two-factor-cli-command.php:578`) and `status` guard with `class_exists()`; the new subcommand does not.
   - The deletion hooks, admin notice and Site Health test are not registered, so deleting a migrated user orphans that user's secret row.

   A human needs to choose between two fixes:
   - Load the TOTP class for these data-management paths regardless of the site-wide setting. This is the better behaviour for an admin who wants to export before decommissioning.
   - Add a `class_exists()` guard with a clear `WP_CLI::error()`.

   Either way, a test needs a seam, because the class cannot be unloaded in-process. I have not raised this as a fix task: SPEC's own self-registration rule produces the configuration, and the choice of remedy is not mine to make.

## Manual checks still owed (from HANDOFF.md, all NOT VERIFIED (human))

- Phase 0:
  - A fresh clone with `npm install && npm run env start` clones `ericmann/secrets-api` v0.2.1 into the tests env.
  - CI passes on the WP `latest`, `7.0-branch` and `7.1-branch` legs.
  - `gh repo set-default --view` shows the fork.
- Phase 1:
  - A Dummy-only user logs in unchanged on the dev site.
  - Read `class-two-factor-secrets.php` for any path that could echo or log a revealed value.
- Phase 2:
  - Enrol an authenticator app and confirm the `_wp_network_secret_two-factor/totp-<id>` option exists and the `_two_factor_totp_key` meta is empty.
  - Log in with an app code.
  - Deactivate the feature plugin and confirm only other methods are offered.
  - Re-activate with `WP_SECRETS_KEY` changed and confirm the profile shows the reset notice.
  - Delete the user and confirm the option row is removed.
- Phase 3:
  - A red, non-dismissible notice appears for an admin (not an editor) when a migrated user exists and the feature plugin is off.
  - Site Health goes critical, then recommended, then good.
  - On multisite, the notice shows in Network Admin for a super admin.
- Phase 4:
  - `wp two-factor secrets status` works, including with `--format=json`.
  - Run `migrate --dry-run`, then `migrate`.
  - `status <user>` shows `secrets-api`.
  - `export` prompts, and `--yes` completes.
  - `wp help two-factor secrets` shows the OPTIONS block.
- Phase 5:
  - Read `docs/PR-DESCRIPTION.md` and the readme FAQ for tone.
  - Confirm CI is green.
  - Open the fork PR (base `master`, `ericmann/two-factor`) if the pipeline did not.
  - Rerun the Phase 2 and 3 browser checks on the final commit.
- Round 1 (R1-01): read the corrected FAQ text, and check that `wp help two-factor secrets` renders the new multi-line example comment cleanly.

## Notes

- The admin notice (`providers/class-two-factor-totp.php`, `admin_notice_secrets_api_missing()`) and the critical Site Health text still say "run `wp two-factor secrets export` before removing it" without the filter-first step R1-01 added to the FAQ. Both are shown only once the API is already unreachable, when export cannot run anyway (`secrets export` errors without the API). So the advice is moot in context, but it is less complete than the FAQ.
- The FAQ's "If the Secrets API is already gone, run `wp two-factor secrets migrate` after restoring it" is harmless. Restoring the API alone makes migrated users readable again; `migrate` only helps users who were enrolled in plaintext meanwhile.
- The `readme.txt` CLI bullet for `secrets export` ("for example before removing the Secrets API") does not repeat the filter-first step. The FAQ a few sections below does.
- The concurrency rollback window noted in round 1 (a transient read-back error after another request finished migrating) is unchanged and still theoretical.
