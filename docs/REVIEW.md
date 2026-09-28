# Review: TOTP secrets in the WordPress Secrets API
Round: 1

Branch `build/2026-09-28`, base `8799d03`, head `9dac25f`. 21 tasks done, 0 blocked, 0 skipped.

## Verdict

CHANGES REQUESTED

Categories 1 to 3 (constraints, boundaries, tests) are clean across the branch. There are two category-5 findings. Both are in the site-owner documentation that ships with the plugin, and both are about lockout and decommissioning. The first states a security behaviour the code does not have. They are grouped into one fix task.

## What I verified myself

- `foundry_verify`: all 11 `docs/foundry.json` constraints pass with no fixture failures and zero hits. `npm run lint` (PHPCS plus PHPStan level 5), `composer lint-compat` and `npm test` are green: 414 tests per suite, with 5 skips on single site and 1 on multisite, all environment-specific.
- Existing tests with the API **absent**, per SPEC 5 ("with and without the API"). I ran both suites inside `tests-cli` with `TWO_FACTOR_SECRETS_API_FILE=/nonexistent`. Single site: 414 tests OK, 61 skipped. Multisite: 414 tests OK, 60 skipped. The skips are the tests that need the API.
- Reading-only constraints from CLAUDE.md:
  - `git diff --stat 8799d03..HEAD -- includes/` is empty.
  - `is_available_for_user()` and `is_enrolled_but_unavailable_for_user()` read meta only. Neither calls `get_user_secret()`, `migrate_user_totp_key()` or `reveal()`.
  - Every `get_users()` / `WP_User_Query` over TOTP meta passes `'blog_id' => 0`: `has_affected_users`, `has_plaintext_users`, `count_users_by_storage`, `uninstall_user_data`, CLI migrate and CLI export.
  - The login prompt and profile UI print only the translated "unavailable" text, never an error message or code.
  - The multi-line `do_action( 'two_factor_secret…' )` calls pass only `$user_id`, the slug and a `WP_Error`.
- Boundaries:
  - Only `class-two-factor-secrets.php` calls the Secrets API.
  - Core has no TOTP-specific logic. It uses the generic `is_enrolled_but_unavailable_for_user()` and `uninstall_user_data()` hooks.
  - The one `.gitignore` change (`.foundry/implement.lock`) was made by Foundry's own `chore: start implementation run` commit, not by the implementer.
  - The `.wp-env.json` and bootstrap edits come from the operator commit `e9e18d7` and match P0-01.
- Mutation sampling. Every mutation was **killed**:
  - `class-two-factor-core.php`: disabling the enrolled-but-unavailable fallback branch (4 failures, including the login lockout regression tests).
  - `class-two-factor-core.php`: dropping the `uninstall_user_data` call (2 failures).
  - `class-two-factor-secrets.php`: removing the wrong-network check (2 failures).
  - `providers/class-two-factor-totp.php`: dropping `hash_equals` from migration read-back (1 failure).
  - `providers/class-two-factor-totp.php`: falling back to plaintext on a Secrets API write error (1 failure).
  - `providers/class-two-factor-totp.php`: registering `delete_user` instead of `wpmu_delete_user` on multisite (3 multisite failures).
  - `providers/class-two-factor-totp.php`: using `manage_options` for the network-admin notice (1 multisite failure).
  - `CLI/class-two-factor-cli-command.php`: migrate paging offset (2 failures).
  - `CLI/class-two-factor-cli-command.php`: export paging offset (1 failure).

## Findings

### F1: category 5 (spec drift). The readme FAQ misstates what happens to TOTP-only users after salt rotation. Task P5-01, with the same claim in the P5-03 PR description.

- **Where:**
  - `readme.txt`, FAQ "How are authenticator (TOTP) secrets stored?", second paragraph: "Affected users can't use their authenticator app until they reset it, and if it was their only method they are sent to the fallback method instead of being let in with a single factor."
  - `docs/PR-DESCRIPTION.md`, "Fail closed" bullets: "A user whose only enrolled method is the unavailable TOTP is forced onto the fallback provider", which follows directly from "An unreadable secret never authenticates".
- **What is wrong:** rotating `LOGGED_IN_KEY`/`LOGGED_IN_SALT` without `WP_SECRETS_KEY` leaves the Secrets API present and the marker on the current network, and every read returns `WP_Error( 'secret_decryption_failed' )`. In that state:
  - `Two_Factor_Totp::is_available_for_user()` returns **true**, correctly per SPEC 4.3: marker present, API present, same network, no decrypt.
  - `is_enrolled_but_unavailable_for_user()` returns **false**.
  - So `Two_Factor_Core::get_available_providers_for_user()` returns exactly `Two_Factor_Totp`, and no fallback is forced. A TOTP-only user gets the "currently unavailable" prompt with no code field and no alternative method.

  The user is locked out until an administrator resets them (for example with `wp two-factor disable <user> --yes`). They are not sent to the email fallback. The code fails closed, which is correct. The documentation promises a recovery path that does not exist.
- **What would break:** an administrator who trusts the FAQ may rotate salts without `WP_SECRETS_KEY`, expecting TOTP-only users to receive email codes. In fact every TOTP-only user is locked out.
- **Minimal fix:**
  - Correct the readme FAQ sentence: TOTP-only users are locked out until an administrator resets their authenticator app, users with backup codes can still use them, and this is why `WP_SECRETS_KEY` is recommended.
  - Scope the PR-description bullet to the cases that do force the fallback: the Secrets API is missing, or the marker names another network. State that an unreadable secret with the API present fails closed without a forced fallback.
  - Add a behaviour-pinning test so the documented behaviour cannot drift again.

### F2: category 5 (spec drift / incomplete procedure). "Export before removing the Secrets API" is undone by lazy migration. Task P4-03, and P5-01 for the FAQ.

- **Where:**
  - `readme.txt` FAQ, third paragraph: "Run `wp two-factor secrets export` before removing it…".
  - `CLI/class-two-factor-cli-command.php` `secrets()` docblock example "Move all secrets back into user meta before removing the Secrets API".
  - `docs/PR-DESCRIPTION.md` "Export" bullet.
- **What is wrong:** `export_user_totp_key()` writes plaintext meta and removes the marker. While the Secrets API stays active and `two_factor_use_secrets_api` returns true, the next login or profile view of that user runs `get_user_totp_key_state()`. That call sees non-empty plaintext with `can_write()` true and migrates the secret straight back (SPEC 4.4). Anyone who logs in between `export` and deactivation is re-migrated. After deactivation they are an affected user again, which is exactly what export was meant to prevent. SPEC 4.8 describes export as "reverse migration for decommissioning the Secrets API". The documented procedure does not achieve that on a live site.
- **What would break:** on a busy site, users who log in during the window lose their authenticator app when the Secrets API is removed. The admin notice and the critical Site Health result then fire, contradicting the admin's belief that export succeeded.
- **Minimal fix:** document the working order in the readme FAQ, the `secrets` command docblock and the PR description:
  1. Return `false` from `two_factor_use_secrets_api`, which stops migration while reads still work (SPEC 4.1).
  2. Run `wp two-factor secrets export`.
  3. Deactivate the Secrets API.

  Add a test pinning both halves: an exported user is re-migrated on the next read when the filter allows writes, and stays in plaintext when the filter returns `false`.

## Spec issues

1. **SPEC goal 4 vs SPEC 4.3 for undecryptable secrets.** Goal 4 says "other providers (backup codes, email fallback) keep working". SPEC 4.3, however, requires `is_available_for_user()` to be true whenever the marker is present, the API is present and the network matches, without decrypting. The consequence is that a TOTP-only user whose secret fails to decrypt (salt rotation without `WP_SECRETS_KEY`, a broken drop-in on the same network, or a missing row: `two_factor_secret_missing`) has no forced email fallback and is locked out pending an admin reset. The implementation follows SPEC and fails closed, which is safe. Whether maintainers want a forced fallback here is worth adding to the PR's open questions. It would need a decrypt on the login path only, not on list screens.
2. **Provider self-registration versus the site-wide provider setting.** SPEC 4.5 requires the deletion hooks in `Two_Factor_Totp`'s constructor. When an administrator removes TOTP in Settings → Two-Factor, `two_factor_filter_enabled_providers` drops it from `two_factor_providers`, so the class is never loaded or instantiated. As a result:
   - Deleting a migrated user leaves their ciphertext row orphaned. Uninstall still cleans it up, because `uninstall()` merges the default providers.
   - The admin notice and Site Health test are not registered.
   - `wp two-factor secrets <action>` fatals on `Two_Factor_Totp::count_users_by_storage()` or `Two_Factor_Totp::get_instance()` ("class not found"). The existing `status` command guards with `class_exists()`; the new subcommand does not.

   This is an edge configuration and SPEC's own hook-placement rule produces it, so I have not raised it as a finding. It is worth a decision: either load the TOTP class for these paths regardless of the site-wide setting, or have the `secrets` subcommand exit with a clear `WP_CLI::error()`.

## Manual checks still owed (from HANDOFF.md, all NOT VERIFIED (human))

- Phase 0:
  - A fresh clone with `npm install && npm run env start` clones `ericmann/secrets-api` v0.2.1 into the tests env.
  - CI passes on the WP `latest`, `7.0-branch` and `7.1-branch` legs.
  - `gh repo set-default --view` shows the fork.
- Phase 1:
  - A Dummy-only user logs in unchanged on the dev site.
  - Read `class-two-factor-secrets.php` for any path that could echo or log a revealed value. I read it during this review and found none; a human check is still owed.
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

## Notes

- Concurrency (SPEC 4.4): two logins migrating the same plaintext both succeed, because they write the same value and both read-backs match. There is one theoretical window. If request A's read-back returns a transient `WP_Error` just after request B completed a successful migration and deleted the plaintext, A's rollback (`delete_user_secret()`) removes B's good secret and the marker, leaving neither copy. This needs a transient read failure on an otherwise healthy store, so I am noting it rather than raising a task. A rollback that re-checks whether the plaintext still exists before deleting would close it.
- `delete_user_totp_key()` now returns `true` when there was nothing to delete, where it used to return `false` (listed in HANDOFF). No caller in the plugin uses the return value, and existing tests pass unchanged.
- CLI progress lines (`Would migrate user %d`, `User %d: %s`, the export warning) are not translated, while the errors and success messages are. That matches how some existing CLI output is handled, but it is inconsistent within the new subcommand.
- The admin notice says the Secrets API "is no longer available on this site". That is also shown for the wrong-network case on multi-network installs, where the wording is slightly inaccurate.
- The test classes were renamed to `Two_Factor_Secrets_Tests` and `Two_Factor_Totp_Secrets_Tests`, and the reason in HANDOFF (PHPCS file naming, and ordering relative to the core tests' `set_up_before_class`) holds up. TESTS.md documents the real names.
