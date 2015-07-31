<?php

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class FIDO_U2F_Keys_List_Table extends WP_List_Table {

	function get_columns() {
		return array(
			'issuer'     => __( 'Issuer', 'two-factor' ),
			'registered' => __( 'Registered', 'two-factor' ),
			'handle'     => __( 'Key Handle', 'two-factor' ),
			'last_used'  => __( 'Last Used', 'two-factor' ),
			'last_ip'    => __( 'Last IP', 'two-factor' ),
		);
	}

	function prepare_items() {
		$columns  = $this->get_columns();
		$hidden   = array();
		$sortable = array();
		$primary  = 'issuer';
		$this->_column_headers = array( $columns, $hidden, $sortable, $primary );
	}

	function column_default( $item, $column_name ) {
		switch( $column_name ) {
			case 'issuer':
				$actions = array(
					'delete' => FIDO_U2F_Keys::delete_link( $item ),
				);
				return esc_html( $item['issuer'] ) . self::row_actions( $actions );
			case 'registered':
				if ( empty( $item['registered'] ) ) {
					return __( 'Unknown', 'two-factor' );
				}
				return date( get_option( 'date_format', 'r' ), $item['registered'] );
			case 'handle':
				return esc_html( $item['handle'] );
			case 'last_used':
				if ( empty( $item['last_used'] ) ) {
					return __( 'Never', 'two-factor' );
				}
				return date( get_option( 'date_format', 'r' ), $item['last_used'] );
			case 'last_ip':
				if ( empty( $item['last_ip'] ) ) {
					return __( 'Never Used', 'two-factor' );
				}
				return $item['last_ip'];
			default:
				return 'WTF^^?';
		}
	}

	/**
	 * Pull into the child class to prevent conflicting nonces.
	 */
	protected function display_tablenav( $which ) {
		?>
		<div class="tablenav <?php echo esc_attr( $which ); ?>">

			<div class="alignleft actions bulkactions">
				<?php $this->bulk_actions( $which ); ?>
			</div>
			<?php
			$this->extra_tablenav( $which );
			$this->pagination( $which );
			?>

			<br class="clear" />
		</div>
		<?php
	}

	public function single_row( $item ) {
		echo '<tr data-slug="' . FIDO_U2F_Keys::unique_slug( $item ) . '">';
		$this->single_row_columns( $item );
		echo '</tr>';
	}
}