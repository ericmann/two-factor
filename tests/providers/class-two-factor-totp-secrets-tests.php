<?php
/**
 * Test the TOTP provider's Secrets API storage.
 *
 * @package Two_Factor
 */

/**
 * Class Two_Factor_Totp_Secrets_Tests
 *
 * @package Two_Factor
 * @group providers
 * @group totp
 * @group secrets
 */
class Two_Factor_Totp_Secrets_Tests extends Two_Factor_Secrets_UnitTestCase {

	/**
	 * Provider under test.
	 *
	 * @var Two_Factor_Totp
	 */
	private $provider;

	/**
	 * Recorded action calls.
	 *
	 * @var array
	 */
	private $calls = array();

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();

		$this->provider = Two_Factor_Totp::get_instance();
		$this->calls    = array();

		$record = function ( $name ) {
			return function () use ( $name ) {
				$this->calls[] = array_merge( array( $name ), func_get_args() );
			};
		};

		add_action( 'two_factor_secrets_migrated', $record( 'migrated' ), 10, 2 );
		add_action( 'two_factor_secrets_migration_failed', $record( 'failed' ), 10, 3 );
	}

	/**
	 * Create a user.
	 *
	 * @return int
	 */
	private function user() {
		return self::factory()->user->create();
	}

	/**
	 * Count recorded calls of a kind.
	 *
	 * @param string $name Action alias.
	 * @return int
	 */
	private function count_calls( $name ) {
		return count(
			array_filter(
				$this->calls,
				function ( $call ) use ( $name ) {
					return $call[0] === $name;
				}
			)
		);
	}

	/**
	 * Read the marker meta.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private function marker( $user_id ) {
		return (string) get_user_meta( $user_id, Two_Factor_Totp::SECRET_NETWORK_META_KEY, true );
	}

	/**
	 * Read the plaintext meta.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private function plaintext( $user_id ) {
		return (string) get_user_meta( $user_id, Two_Factor_Totp::SECRET_META_KEY, true );
	}

	/**
	 * Marker constant matches the adapter.
	 */
	public function test_marker_key_matches_adapter() {
		$this->assertSame( Two_Factor_Secrets::get_marker_meta_key( 'totp' ), Two_Factor_Totp::SECRET_NETWORK_META_KEY );
	}

	/**
	 * Keys are stored in the Secrets API.
	 */
	public function test_set_key_with_api_stores_in_secrets_api() {
		$this->require_secrets_api();
		$user_id = $this->user();

		$this->assertTrue( $this->provider->set_user_totp_key( $user_id, 'ABCDEFGH' ) );
		$this->assertSame( '', $this->plaintext( $user_id ) );
		$this->assertSame( (string) get_current_network_id(), $this->marker( $user_id ) );
		$this->assertSame( 'ABCDEFGH', $this->provider->get_user_totp_key( $user_id ) );
		$this->assertNotNull( wp_get_network_secret( "two-factor/totp-{$user_id}" ) );
	}

	/**
	 * Keys fall back to plaintext without the API.
	 */
	public function test_set_key_without_api_stores_plaintext() {
		$this->simulate_api_absent();
		$user_id = $this->user();

		$this->provider->set_user_totp_key( $user_id, 'ABCDEFGH' );

		$this->assertSame( 'ABCDEFGH', $this->plaintext( $user_id ) );
		$this->assertSame( '', $this->marker( $user_id ) );
		$this->assertSame( 'ABCDEFGH', $this->provider->get_user_totp_key( $user_id ) );
	}

	/**
	 * A failed Secrets API write never falls back to plaintext.
	 */
	public function test_set_key_returns_false_when_secrets_write_fails() {
		$this->require_secrets_api();
		$user_id                                   = $this->user();
		Two_Factor_Secrets::$test_overrides['set'] = function () {
			return new WP_Error( 'write_failed' );
		};

		$this->assertFalse( $this->provider->set_user_totp_key( $user_id, 'ABCDEFGH' ) );
		$this->assertSame( '', $this->plaintext( $user_id ) );
		$this->assertSame( '', $this->marker( $user_id ) );
	}

	/**
	 * A read-back mismatch fails the write and cleans up.
	 */
	public function test_set_key_returns_false_when_readback_mismatches() {
		$this->require_secrets_api();
		$user_id                                   = $this->user();
		Two_Factor_Secrets::$test_overrides['get'] = function () {
			return 'SOMETHINGELSE';
		};

		$this->assertFalse( $this->provider->set_user_totp_key( $user_id, 'ABCDEFGH' ) );
		$this->assertNull( wp_get_network_secret( "two-factor/totp-{$user_id}" ) );
		$this->assertSame( '', $this->marker( $user_id ) );
	}

	/**
	 * Setting an empty key deletes.
	 */
	public function test_set_empty_key_deletes() {
		$user_id = $this->user();
		$this->provider->set_user_totp_key( $user_id, 'ABCDEFGH' );

		$this->provider->set_user_totp_key( $user_id, '' );

		$this->assertSame( '', $this->provider->get_user_totp_key( $user_id ) );
		$this->assertSame( '', $this->marker( $user_id ) );
		$this->assertSame( '', $this->plaintext( $user_id ) );
	}

	/**
	 * Delete clears everything.
	 */
	public function test_delete_key_removes_secret_marker_and_plaintext() {
		$this->require_secrets_api();
		$user_id = $this->user();
		$this->provider->set_user_totp_key( $user_id, 'ABCDEFGH' );
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_META_KEY, 'LEFTOVER' );

		$this->assertTrue( $this->provider->delete_user_totp_key( $user_id ) );
		$this->assertSame( '', $this->plaintext( $user_id ) );
		$this->assertSame( '', $this->marker( $user_id ) );
		$this->assertNull( wp_get_network_secret( "two-factor/totp-{$user_id}" ) );
	}

	/**
	 * Delete clears the marker without the API.
	 */
	public function test_delete_key_removes_marker_when_api_absent() {
		$user_id = $this->user();
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_NETWORK_META_KEY, (string) get_current_network_id() );
		$this->simulate_api_absent();

		$this->assertTrue( $this->provider->delete_user_totp_key( $user_id ) );
		$this->assertSame( '', $this->marker( $user_id ) );
	}

	/**
	 * Reading a plaintext key migrates it.
	 */
	public function test_lazy_migration_moves_plaintext_to_secrets_api() {
		$this->require_secrets_api();
		$user_id = $this->user();
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_META_KEY, 'ABCDEFGH' );

		$this->assertSame( 'ABCDEFGH', $this->provider->get_user_totp_key( $user_id ) );
		$this->assertSame( '', $this->plaintext( $user_id ) );
		$this->assertSame( (string) get_current_network_id(), $this->marker( $user_id ) );
		$this->assertSame( 1, $this->count_calls( 'migrated' ) );
		$this->assertSame( array( 'migrated', $user_id, 'totp' ), $this->calls[0] );
	}

	/**
	 * Migration runs once.
	 */
	public function test_lazy_migration_is_idempotent() {
		$this->require_secrets_api();
		$user_id = $this->user();
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_META_KEY, 'ABCDEFGH' );

		$this->provider->get_user_totp_key( $user_id );

		$this->assertSame( 'ABCDEFGH', $this->provider->get_user_totp_key( $user_id ) );
		$this->assertSame( 1, $this->count_calls( 'migrated' ) );
		$this->assertNull( $this->provider->migrate_user_totp_key( $user_id ) );
	}

	/**
	 * A failed write keeps plaintext.
	 */
	public function test_lazy_migration_write_failure_keeps_plaintext_and_fires_failed_action() {
		$this->require_secrets_api();
		$user_id = $this->user();
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_META_KEY, 'ABCDEFGH' );
		Two_Factor_Secrets::$test_overrides['set'] = function () {
			return new WP_Error( 'write_failed' );
		};

		$this->assertSame( 'ABCDEFGH', $this->provider->get_user_totp_key( $user_id ) );
		$this->assertSame( 'ABCDEFGH', $this->plaintext( $user_id ) );
		$this->assertSame( 1, $this->count_calls( 'failed' ) );
		$this->assertSame( 0, $this->count_calls( 'migrated' ) );
		$this->assertSame( '', $this->marker( $user_id ) );
	}

	/**
	 * A read-back mismatch keeps plaintext and cleans up.
	 */
	public function test_lazy_migration_readback_mismatch_keeps_plaintext_and_cleans_up() {
		$this->require_secrets_api();
		$user_id = $this->user();
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_META_KEY, 'ABCDEFGH' );
		Two_Factor_Secrets::$test_overrides['get'] = function () {
			return 'SOMETHINGELSE';
		};

		$result = $this->provider->migrate_user_totp_key( $user_id );

		$this->assertWPError( $result );
		$this->assertSame( 'two_factor_secrets_migration_mismatch', $result->get_error_code() );
		$this->assertSame( 'ABCDEFGH', $this->plaintext( $user_id ) );
		$this->assertSame( '', $this->marker( $user_id ) );
		$this->assertNull( wp_get_network_secret( "two-factor/totp-{$user_id}" ) );
		$this->assertSame( 1, $this->count_calls( 'failed' ) );
	}

	/**
	 * Opting out skips migration.
	 */
	public function test_lazy_migration_skipped_when_filter_opts_out() {
		$this->require_secrets_api();
		add_filter( 'two_factor_use_secrets_api', '__return_false' );
		$user_id = $this->user();
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_META_KEY, 'ABCDEFGH' );

		$this->assertSame( 'ABCDEFGH', $this->provider->get_user_totp_key( $user_id ) );
		$this->assertSame( 'ABCDEFGH', $this->plaintext( $user_id ) );
		$this->assertSame( array(), $this->calls );
	}

	/**
	 * Plaintext wins over a marker.
	 */
	public function test_plaintext_beats_marker() {
		$this->require_secrets_api();
		add_filter( 'two_factor_use_secrets_api', '__return_false' );
		$user_id = $this->user();
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_META_KEY, 'PLAINTEXT' );
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_NETWORK_META_KEY, (string) get_current_network_id() );

		$this->assertSame( 'PLAINTEXT', $this->provider->get_user_totp_key_state( $user_id ) );
	}

	/**
	 * No key means null.
	 */
	public function test_key_state_null_without_secret() {
		$this->assertNull( $this->provider->get_user_totp_key_state( $this->user() ) );
	}

	/**
	 * A marker without the API is an error.
	 */
	public function test_key_state_error_when_api_absent_with_marker() {
		$user_id = $this->user();
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_NETWORK_META_KEY, (string) get_current_network_id() );
		$this->simulate_api_absent();

		$state = $this->provider->get_user_totp_key_state( $user_id );

		$this->assertWPError( $state );
		$this->assertSame( 'two_factor_secrets_api_missing', $state->get_error_code() );
		$this->assertSame( '', $this->provider->get_user_totp_key( $user_id ) );
	}

	/**
	 * A marker for another network is an error.
	 */
	public function test_key_state_error_when_marker_names_other_network() {
		$this->require_secrets_api();
		$user_id = $this->user();
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_NETWORK_META_KEY, (string) ( get_current_network_id() + 1 ) );

		$state = $this->provider->get_user_totp_key_state( $user_id );

		$this->assertWPError( $state );
		$this->assertSame( 'two_factor_secret_wrong_network', $state->get_error_code() );
	}

	/**
	 * Opting out does not stop reads of migrated users.
	 */
	public function test_migrated_user_still_readable_when_filter_opts_out() {
		$this->require_secrets_api();
		$user_id = $this->user();
		$this->provider->set_user_totp_key( $user_id, 'ABCDEFGH' );
		add_filter( 'two_factor_use_secrets_api', '__return_false' );

		$this->assertSame( 'ABCDEFGH', $this->provider->get_user_totp_key( $user_id ) );
	}

	/**
	 * Available with marker and API.
	 */
	public function test_is_available_for_user_true_with_marker_and_api() {
		$this->require_secrets_api();
		$user_id = $this->user();
		$this->provider->set_user_totp_key( $user_id, 'ABCDEFGH' );

		$this->assertTrue( $this->provider->is_available_for_user( get_userdata( $user_id ) ) );
	}

	/**
	 * Unavailable with marker but no API.
	 */
	public function test_is_available_for_user_false_with_marker_and_api_absent() {
		$user_id = $this->user();
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_NETWORK_META_KEY, (string) get_current_network_id() );
		$this->simulate_api_absent();

		$this->assertFalse( $this->provider->is_available_for_user( get_userdata( $user_id ) ) );
	}

	/**
	 * Unavailable with a marker for another network.
	 */
	public function test_is_available_for_user_false_with_marker_for_other_network() {
		$this->require_secrets_api();
		$user_id = $this->user();
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_NETWORK_META_KEY, (string) ( get_current_network_id() + 1 ) );

		$this->assertFalse( $this->provider->is_available_for_user( get_userdata( $user_id ) ) );
	}

	/**
	 * Availability checks never migrate.
	 */
	public function test_is_available_for_user_does_not_migrate() {
		$this->require_secrets_api();
		$user_id = $this->user();
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_META_KEY, 'ABCDEFGH' );

		$this->assertTrue( $this->provider->is_available_for_user( get_userdata( $user_id ) ) );
		$this->assertSame( 'ABCDEFGH', $this->plaintext( $user_id ) );
		$this->assertSame( array(), $this->calls );
	}

	/**
	 * Enrolled-but-unavailable matrix.
	 */
	public function test_is_enrolled_but_unavailable_for_user_matrix() {
		$user_id = $this->user();
		$user    = get_userdata( $user_id );

		// Nothing stored.
		$this->assertFalse( $this->provider->is_enrolled_but_unavailable_for_user( $user ) );

		// Plaintext.
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_META_KEY, 'ABCDEFGH' );
		$this->assertFalse( $this->provider->is_enrolled_but_unavailable_for_user( $user ) );
		delete_user_meta( $user_id, Two_Factor_Totp::SECRET_META_KEY );

		// Marker for this network, API present.
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_NETWORK_META_KEY, (string) get_current_network_id() );
		if ( function_exists( 'wp_get_network_secret' ) ) {
			$this->assertFalse( $this->provider->is_enrolled_but_unavailable_for_user( $user ) );

			// Marker for another network.
			update_user_meta( $user_id, Two_Factor_Totp::SECRET_NETWORK_META_KEY, (string) ( get_current_network_id() + 1 ) );
			$this->assertTrue( $this->provider->is_enrolled_but_unavailable_for_user( $user ) );
			update_user_meta( $user_id, Two_Factor_Totp::SECRET_NETWORK_META_KEY, (string) get_current_network_id() );
		}

		// Marker with the API absent.
		$this->simulate_api_absent();
		$this->assertTrue( $this->provider->is_enrolled_but_unavailable_for_user( $user ) );
	}

	/**
	 * Storage descriptions.
	 */
	public function test_get_user_totp_key_storage_values() {
		$this->require_secrets_api();
		$user_id = $this->user();

		$this->assertSame( 'none', $this->provider->get_user_totp_key_storage( $user_id ) );

		update_user_meta( $user_id, Two_Factor_Totp::SECRET_META_KEY, 'ABCDEFGH' );
		$this->assertSame( 'plaintext', $this->provider->get_user_totp_key_storage( $user_id ) );
		$this->assertSame( 'ABCDEFGH', $this->plaintext( $user_id ), 'Reading storage does not migrate.' );

		$this->provider->delete_user_totp_key( $user_id );
		$this->provider->set_user_totp_key( $user_id, 'ABCDEFGH' );
		$this->assertSame( 'secrets-api', $this->provider->get_user_totp_key_storage( $user_id ) );

		$this->simulate_api_absent();
		$this->assertSame( 'unavailable', $this->provider->get_user_totp_key_storage( $user_id ) );
	}

	/**
	 * Uninstall removes the marker.
	 */
	public function test_uninstall_user_meta_keys_include_marker() {
		$this->assertContains( Two_Factor_Totp::SECRET_NETWORK_META_KEY, Two_Factor_Totp::uninstall_user_meta_keys() );
	}

	/**
	 * A secret set on one site validates on another.
	 */
	public function test_secret_set_on_blog_one_validates_on_blog_two() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		$this->require_secrets_api();

		$user_id = $this->user();
		$blog_id = self::factory()->blog->create();
		$key     = Two_Factor_Totp::generate_key();

		$this->provider->set_user_totp_key( $user_id, $key );

		switch_to_blog( $blog_id );
		try {
			$this->assertSame( $key, $this->provider->get_user_totp_key( $user_id ) );
			$this->assertTrue( Two_Factor_Totp::is_valid_authcode( $key, Two_Factor_Totp::calc_totp( $key ) ) );
		} finally {
			restore_current_blog();
		}
	}
}
