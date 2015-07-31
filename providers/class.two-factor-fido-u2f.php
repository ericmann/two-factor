<?php

/**
 * Universal Two-Factor (U2F) Provider for WordPress Authentication.
 */
class Two_Factor_FIDO_U2F extends Two_Factor_Provider {

	/**
	 * Singleton Factory
	 *
	 * @return Two_Factor_FIDO_U2F
	 */
	static function get_instance() {
		static $instance;
		$class = __CLASS__;
		if ( ! is_a( $instance, $class ) ) {
			$instance = new $class;
		}
		return $instance;
	}

	/**
	 * Generate the administrative label
	 *
	 * @return string
	 */
	public function get_label() {
		return _x( 'FIDO U2F', 'Provider Label', 'two-factor' );
	}

	/**
	 * Generate the Challenge-response page.
	 *
	 * @param WP_User $user
	 */
	public function authentication_page( $user ) {
		require_once( ABSPATH .  '/wp-admin/includes/template.php' );
		?>
		<p><?php esc_html_e( 'Press the flashing USB token.', 'two-factor' ); ?></p>
		<div class="card">
			<div class="cardTitle">
				<span><?php esc_html_e( 'U2F Authenticator', 'two-factor' ); ?></span>
			</div>
			<div class="cardContent">
				<div class="issuer">Blah</div>
				<div class="enrollmentTime"><?php echo esc_html( sprintf( __( 'Enroled on %s', 'two-factor' ), '' ) ); ?></div>
			</div>
		</div>
		<?php
		submit_button( __( 'Log In', 'two-factor' ), 'primary', 'submit', true, array( 'disabled' => 'disabled' ) );
	}

	public function validate_authentication( $user ) {}

	public function is_available_for_user( $user ) {
		return true;
	}

}
