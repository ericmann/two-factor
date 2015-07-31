<?php

class FIDO_U2F_Keys {

	const USERMETA_KEY_FIDO_U2F_KEYS = '_fido_u2f_keys';

	public static function add_hooks() {
		add_filter( 'authenticate',             array( __CLASS__, 'authenticate' ), 10, 3 );
		add_action( 'show_user_profile',        array( __CLASS__, 'show_user_profile' ) );
		add_action( 'edit_user_profile',        array( __CLASS__, 'show_user_profile' ) );
	}

	public static function authenticate( $input_user, $username, $password ) {
		return $input_user;
	}

	public static function show_user_profile( $user ) {

		?>
		<div class="fido-u2f-keys" id="fido-u2f-keys-section">
			<h3><?php esc_html_e( 'FIDO U2F Keys', 'two-factor' ); ?></h3>
			<p><?php esc_html_e( 'FIDO U2F keys provide a secure, hardware-based second factor of authentication.', 'two-factor' ); ?></p>
			<div class="register-fido-u2f-key">
				<input type="text" size="30" name="new_application_password_name" placeholder="<?php esc_attr_e( 'New Application Password Name', 'two-factor' ); ?>" />
				<?php submit_button( __( 'Add New', 'two-factor' ), 'secondary', 'do_new_application_password', false ); ?>
			</div>

			<?php
			require( dirname( __FILE__ ) . '/class.fido-u2f-keys-list-table.php' );
			$application_passwords_list_table = new FIDO_U2F_Keys_List_Table();

			$application_passwords_list_table->prepare_items();
			$application_passwords_list_table->display();
			?>
		</div>
		<?php
	}

	/**
	 * Generate a link to delete a specified key
	 *
	 * @param array $item
	 *
	 * @return string
	 */
	public static function delete_link( $item ) {}

	/**
	 * Generate an repeatable slug from the key handle, issuer, and when it was created.
	 *
	 * This should be unique.
	 */
	public static function unique_slug( $item ) {
		$concat = $item['issuer'] . '|' . $item['handle'] . '|' . $item['registered'];
		$hash   = md5( $concat );
		return substr( $hash, 0, 12 );
	}
}