# Two-Factor Secrets API (TOTP) build progress
Branch: build/2026-09-28
Started: 2026-09-28T19:43:32.427Z

## Tasks
- [x] P0-01 Create the build branch and load the Secrets API feature plugin in the tests environment
- [x] P0-02 PHPStan stubs for the Secrets API and PHPUnit exclusion of the stub directory
- [x] P0-03 Push Phase 0 and record the manual environment check
- [x] P1-01 `Two_Factor_Secrets` adapter with tests and the shared secrets test case
- [x] P1-02 Provider base hooks and fail-closed handling of enrolled-but-unavailable providers in core
- [x] P1-03 Push Phase 1 and record the manual check
- [x] P2-01 TOTP tri-state read, Secrets API writes and lazy migration
- [x] P2-02 Fail-closed validation, login prompt, profile UI and the login lockout regression
- [x] P2-03 User deletion hooks and uninstall cleanup of Secrets API entries
- [x] P2-04 Push Phase 2 and record the manual check
- [x] P3-01 Affected-user detection and the administrator notice
- [x] P3-02 Site Health test `two_factor_totp_secret_storage`
- [ ] P3-03 Push Phase 3 and record the manual check
- [ ] P4-01 `wp two-factor status` storage field and `wp two-factor secrets status`
- [ ] P4-02 `wp two-factor secrets migrate`
- [ ] P4-03 `wp two-factor secrets export`
- [ ] P4-04 Push Phase 4 and record the manual check
- [ ] P5-01 readme.txt, readme.md and CHANGELOG.md
- [ ] P5-02 AGENTS.md, TESTS.md and docblock audit
- [ ] P5-03 Upstream PR description
- [ ] P5-04 Push Phase 5 and record the final manual check

## Log
(one entry per task, appended by implement)

### P0-01 — f54ffa6
Config/bootstrap done earlier by operator (e9e18d7); added two acceptance tests in tests/two-factor.php. Both run (not skipped) in single and multisite, 305 tests each. Interpretation: stayed on build/2026-09-28 rather than creating build/secrets-api-totp.

### P0-02 — 51fff02
Added tests/phpstan/secrets-api-stubs.php, scanFiles entry, and tests/phpstan exclude in both phpunit configs. Lint and both suites green (305 tests).

### P0-03 — 9136355
Pushed build/2026-09-28 to origin (ericmann/two-factor). gh default repo is ericmann/two-factor. Empty commit used as task commit. Manual check: NOT VERIFIED (human): (a) fresh clone env start clones ericmann/secrets-api v0.2.1; (b) CI passes on WP latest, 7.0-branch, 7.1-branch legs; (c) gh repo set-default --view shows the fork.

### P1-01 — 07e94fb
Added class-two-factor-secrets.php (Two_Factor_Secrets), required in two-factor.php, wired into phpstan paths and both phpunit coverage whitelists, bootstrap requires tests/class-two-factor-secrets-test-case.php. 18 tests in group secrets, both suites green (323 tests).
Interpretation: test class is Two_Factor_Secrets_Tests in tests/class-two-factor-secrets-tests.php (not Tests_Two_Factor_Secrets): PHPCS requires file name = class name for non-WP test-case parents, and a file sorting before class-two-factor-core.php breaks core tests (core registers set_auth_cookie hooks in set_up_before_class; an earlier test class wipes them). Later test files must sort after class-two-factor-core.php or extend nothing that runs first. Also the internal filter param is $enabled (PHPCS reserved keyword).

### P1-02 — 1d74d90
Base Two_Factor_Provider::is_enrolled_but_unavailable_for_user() (false) and static uninstall_user_data() (no-op); core get_available_providers_for_user extracts resolve_fallback_provider_for_user and adds the enrolled-but-unavailable branch (fallback or no_available_2fa_methods WP_Error with unavailable_providers); uninstall() calls uninstall_user_data before options/meta deletion. Fixture tests/class-two-factor-dummy-unavailable.php. 7 new tests; 330 in both suites.
Interpretation: helper has optional 4th by-ref $filtered param to preserve raw filtered value in error data; fixture is loaded through the two_factor_providers path (not tests/bootstrap.php) because Two_Factor_Dummy is not loaded at bootstrap time. phpcs:ignore on unused $user in base method mirrors pre_process_authentication precedent.

### P1-03 — 9046c96
Pushed to origin. Empty commit used as task commit. Manual check: NOT VERIFIED (human): (a) dev site, WP_DEBUG on, user with only Dummy provider logs in unchanged; (b) read class-two-factor-secrets.php for any path that could echo or log a revealed value.

### P2-01 — 234dc05
Two_Factor_Totp: SECRET_NETWORK_META_KEY/SECRET_SLUG, get_user_totp_key_state, migrate_user_totp_key (verified, idempotent, fires two_factor_secrets_migrated / _migration_failed), Secrets-API-first set/delete, is_available_for_user and is_enrolled_but_unavailable_for_user by marker, get_user_totp_key_storage, marker added to uninstall meta keys. Tests: tests/providers/class-two-factor-totp-secrets-tests.php (27 tests; multisite blog test skipped on single). Suites: 356 tests, single has 1 expected skip, multisite runs it.
Interpretation: class/file named Two_Factor_Totp_Secrets_Tests (PHPCS file naming, as P1-01).
Note: delete_user_totp_key now returns true only when plaintext, marker and secret are gone (also true when nothing existed).

### P2-02 — f346ba4
validate_code_for_user, authentication_page and user_two_factor_options now use get_user_totp_key_state; private report_unavailable fires two_factor_secret_unavailable(user_id,'totp',WP_Error). 11 tests appended to tests/providers/class-two-factor-totp-secrets-tests.php incl. login lockout regression (affected TOTP-only user gets Email fallback / WP_Error, send_auth_cookies still blocked; backup codes retained). 367 tests both suites.
Interpretation: unavailable prompt renders the two prompt actions and the unavailable message but omits the generic 'Enter the code' line; test class named Two_Factor_Totp_Secrets_Tests.

### P2-03 — ec6277d
Deletion hook (wpmu_delete_user on multisite, delete_user otherwise) and Two_Factor_Totp::uninstall_user_data (paged WP_User_Query on the marker meta, offset 0, seen-ID guard); uninstall() docblock notes orphaned secrets. 7 tests (6 in Two_Factor_Totp_Secrets_Tests, 1 in core). 374 tests both suites; skips are the single-site/multisite-only tests.
Interpretation: hooks call new void wrapper delete_user_secrets_on_user_deletion() instead of delete_user_totp_key() directly because PHPStan level 5 rejects bool-returning action callbacks; the constructor test asserts the wrapper.

### P2-04 — 04e6053
Pushed to origin. Empty commit as task commit. Manual check: NOT VERIFIED (human): with feature plugin on dev site (a) enrol app, confirm _wp_network_secret_two-factor/totp-<id> option exists and _two_factor_totp_key meta empty; (b) log in with an app code; (c) deactivate plugin, login offers only other methods; after re-activation with WP_SECRETS_KEY changed profile shows reset notice; (d) delete user, option row gone.

### P3-01 — 3613cd1
Added AFFECTED_USERS_TRANSIENT/AFFECTED_USERS_CACHE_TTL (300), has_affected_users(), clear_affected_users_cache() (called from set/delete/migrate success paths), admin_notice_secrets_api_missing hooked to admin_notices and network_admin_notices (non-dismissible error, contains both wp two-factor secrets export/migrate command strings). 8 tests; 382 tests both suites (skips are env-specific).

### P3-02 — 866b1ca
register_site_health_test, has_plaintext_users(), site_health_secret_storage() with the five status paths in SPEC order. 6 tests; 388 tests both suites.
Interpretation: API present + no plaintext users is 'good' naming the provider label even when writes are disabled; case (4) only when plaintext remains and can_write() is false.
