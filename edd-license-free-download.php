<?php

/**
 * Plugin Name: Easy Digital Downloads - License Free Download
 * Plugin URI: https://easydigitaldownloads.com/downloads/license-free-download/
 * Description: Offer free product downloads to users with an active license of a previous product(s).
 * Author: Sandhills Development, LLC
 * Author URI: https://sandhillsdev.com
 * Version: 1.0.1
 * Text Domain: edd_lfd
 * Domain Path: languages
 */

class EDD_lfd {

	public static $edd_fdl_errors;

	public static function init() {

		load_plugin_textdomain( 'edd_lfd', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		add_filter( 'edd_cart_item_price', array( __CLASS__, 'set_price' ), 10, 3 );

		add_action( 'edd_empty_cart', array( __CLASS__, 'delete_saved_free_download_in_cart' ), 10, 3 );
		add_action( 'edd_post_remove_from_cart', array( __CLASS__, 'delete_free_downloads_on_cart_removal' ), 20, 2 );

		add_action( 'init', array( __CLASS__, 'process_download' ) );

		add_action( 'edd_cart_contents', array( __CLASS__, 'remove_duplicate_order' ) );

		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );

		add_action( 'save_post', array( __CLASS__, 'save_data' ) );

		add_shortcode( 'edd_lfd', array( __CLASS__, 'shortcode_form' ) );

		add_action( 'plugins_loaded', array( __CLASS__, 'licensing' ), 20 );

		add_filter( 'edd_settings_sections_extensions', array( __CLASS__, 'register_subsection' ) );
		add_filter( 'edd_settings_extensions', array( __CLASS__, 'settings_page' ) );
	}

	/**
	 * Use the EDD built in license handler.
	 *
	 * @since 1.0.1
	 */
	public static function licensing() {
		if ( class_exists( 'EDD_License' ) ) {
			$license = new EDD_License( __FILE__, 'License Free Download', '1.0.1', 'Sandhills Development, LLC', null, null, 599141 );

		}
	}

	/**
	 * Add meta-box to WP dashboard
	 */
	public static function add_meta_box() {

		add_meta_box(
			'lfd_id',
			__( 'License Holders Free Download', 'edd_lfd' ),
			array( __CLASS__, 'meta_box_callback' ),
			'download'
		);

	}


	/**
	 * Meta-box callback function.
	 *
	 * @param $post
	 */
	public static function meta_box_callback( $post ) {

		// Add an nonce field so we can check for it later.
		wp_nonce_field( 'edd_lfd_products_nonce', 'edd_lfd_products_nonce' );

		$activate = get_post_meta( $post->ID, '_edd_lfd_activate', true );
		if ( 'yes' === $activate ) {
			$activate = true;
		}
		$values = get_post_meta( $post->ID, '_edd_lfd_products', true );

		$product_list = get_posts(
			array(
				'post_type'      => 'download',
				'posts_per_page' => - 1,
				'nopaging'       => true,
			)
		);

		?>

		<p>
			<input id="lfd_activate" type="checkbox" name="edd_lfd_activate" value="1" <?php checked( 1, $activate ); ?>>
			<label for="lfd_activate"><?php esc_html_e( 'Activate Free Download', 'edd_lfd' ); ?></label>
		</p>
		<label for="products"><?php esc_html_e( 'Select the products whose license holders will be allowed to download this product for free.', 'edd_lfd' ); ?></label>
		<p>
		<?php
		echo EDD()->html->product_dropdown(
			array(
				'chosen'     => true,
				'multiple'   => true,
				'bundles'    => false,
				'name'       => 'edd_lfd_products[]',
				'selected'   => empty( $values ) ? false : $values,
				'variations' => true,
			)
		);
		?>
		</p>
		<?php
	}

	/**
	 * Save meta box data
	 *
	 * @param int $post_id
	 */
	public static function save_data( $post_id ) {
		// Check if our nonce is set.
		if ( ! isset( $_POST['edd_lfd_products_nonce'] ) ) {
			return;
		}

		// Verify that the nonce is valid.
		if ( ! wp_verify_nonce( $_POST['edd_lfd_products_nonce'], 'edd_lfd_products_nonce' ) ) {
			return;
		}

		// If this is an autosave, our form has not been submitted, so we don't want to do anything.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check the user's permissions.
		if ( isset( $_POST['post_type'] ) && 'download' == $_POST['post_type'] ) {

			if ( ! current_user_can( 'edit_page', $post_id ) ) {
				return;
			}

		} else {

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}
		}

		// Sanitize user input.
		$products = array();
		if ( ! empty( $_POST['edd_lfd_products'] ) && is_array( $_POST['edd_lfd_products'] ) ) {
			foreach ( $_POST['edd_lfd_products'] as $product ) {
				if ( ! empty( $product ) ) {
					$products[] = sanitize_text_field( $product );
				}
			}
		}
		if ( ! empty( $products ) ) {
			update_post_meta( $post_id, '_edd_lfd_products', $products );
		} else {
			delete_post_meta( $post_id, '_edd_lfd_products' );
		}

		if ( ! empty( $_POST['edd_lfd_activate'] ) ) {
			$activated = (bool) $_POST['edd_lfd_activate'];
			update_post_meta( $post_id, '_edd_lfd_activate', $activated );
		} else {
			delete_post_meta( $post_id, '_edd_lfd_activate' );
		}
	}


	/**
	 * License validation form.
	 */
	public static function shortcode_form( $atts ) {
		global $post;
		$atts = shortcode_atts(
			array(
				'id'          => absint( is_singular( 'download' ) ? $post->ID : '' ),
				'placeholder' => __('Enter license key', 'edd_lfd'),
				'button'      => __('Download Free', 'edd_lfd')
			),
			$atts
		);

		$error = '';
		if ( is_wp_error( self::$edd_fdl_errors ) ) {
			$error = '<div class="edd_lfd_error">' . self::$edd_fdl_errors->get_error_message() . '</div>';
		}
		ob_start();
		?>
	<form method="post">
		<p>
		<input name='edd_lfd_license_key' size="34" type="text" class="lfd_input" id="lfd_input_id" placeholder="<?php esc_attr_e( $atts['placeholder'] ); ?>"/>
		<input type="hidden" name="edd_lfd_download_id" value="<?php echo $atts['id']; ?>">
		<input type="submit" name="edd_lfd_validation" value="<?php esc_attr_e( $atts['button'] ); ?>" class="lfd_submit" id="lfd_submit_id">
		</p>
		</form>
		<?php

		$form = ob_get_clean();

		return $error . $form;
	}


	/**
	 * Process the free download.
	 */
	public static function process_download() {

		if ( ! isset( $_POST['edd_lfd_validation'] ) || empty( $_POST['edd_lfd_download_id'] ) ) {
			return;
		}

		global $edd_options;

		$download_id = absint( $_POST['edd_lfd_download_id'] );

		if ( ! isset( $_POST['edd_lfd_license_key'] ) || empty( $_POST['edd_lfd_license_key'] ) ) {
			$msg                  = ! empty( $edd_options['edd_lfd_license_missing'] ) ? $edd_options['edd_lfd_license_missing'] : apply_filters( 'lfd_license_missing', __( 'License key is missing', 'edd_lfd' ) );
			self::$edd_fdl_errors = new WP_Error( 'lfd_license_missing', $msg );

			return;
		} else {

			// Determine if the product is available for free download
			$product_status = get_post_meta( $download_id, '_edd_lfd_activate', true );
			if ( empty( $product_status ) ) {
				$msg = ! empty( $edd_options['edd_lfd_product_not_free'] ) ? $edd_options['edd_lfd_product_not_free'] : __( 'Product is not available for free.', 'edd_lfd' );

				self::$edd_fdl_errors = new WP_Error( 'lfd_products_not_free', $msg );

				return;
			}

			// License key
			$license_key = esc_attr( $_POST['edd_lfd_license_key'] );

			// verify license key
			if ( self::validate_license( $license_key ) ) {

				// check if the license key has access to download the cart product
				if ( self::comparison( $license_key, $download_id ) ) {
					self::add_to_cart_and_checkout( $download_id );
				} else {
					$msg = ! empty( $edd_options['edd_lfd_access_denied'] ) ? esc_attr( $edd_options['edd_lfd_access_denied'] ) : __( 'The license key isn\'t allowed to download this product for free. Sorry.', 'edd_lfd' );

					self::$edd_fdl_errors = new WP_Error( 'edd_lfd_access_denied', $msg );

					return;
				}
			} else {
				$msg = ! empty( $edd_options['edd_lfd_license_validation_failed'] ) ? esc_attr( $edd_options['edd_lfd_license_validation_failed'] ) : __( 'License key validation failed. Try again', 'edd_lfd' );

				self::$edd_fdl_errors = new WP_Error( 'edd_lfd_license_invalid', $msg );

				return;
			}
		}
	}

	/**
	 * Verify the license key hasn't expired
	 *
	 * Returns true if the license key is active or false otherwise
	 *
	 * @param string $license_key
	 *
	 * @return bool
	 */
	public static function validate_license( $license_key ) {
		$license = edd_software_licensing()->get_license( $license_key );
		if ( ! $license ) {
			return false;
		}
		if ( ! in_array( $license->status, array( 'expired', 'revoked', 'disabled' ), true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get the product id associated with a license key.
	 * For backwards compatibility, this is returned as an array even though
	 * the SL function returns an integer.
	 *
	 * @param string $license_key
	 *
	 * @return array
	 */
	public static function get_license_product_ids( $license_key ) {
		$licensed_products = (array) edd_software_licensing()->get_download_id_by_license( $license_key );

		return array_filter( $licensed_products );
	}

	/**
	 * Check if any or all of the license_key products is in the list of product available for free download
	 *
	 * @param string $license_key
	 * @param int    $download_id
	 * @return bool
	 */
	public static function comparison( $license_key, $download_id ) {

		// Product IDs whose license holders can download this product for free.
		$free_products = array_map( 'esc_attr', self::get_free_products_ids( $download_id ) );

		// Product ID for this license key (stored as an array).
		$licensed_products = array_map( 'esc_attr', self::get_license_product_ids( $license_key ) );

		// If a product is in both arrays, it qualifies for the free download.
		if ( array_intersect( $free_products, $licensed_products ) ) {
			return true;
		}

		// Check if any products allow free downloads for a specific price ID only.
		foreach ( $free_products as $free ) {
			$price_id_pos = strpos( $free, '_' );
			if ( false === $price_id_pos ) {
				continue;
			}
			list( $download_id, $price_id ) = explode( '_', $free );
			if ( ! in_array( $download_id, $licensed_products, true ) ) {
				continue;
			}
			$license = edd_software_licensing()->get_license( $license_key );
			if ( $price_id === $license->price_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return the IDs of products saved against the post/product with '$product_id' ID
	 *
	 * @param int $product_id ID of product that is mark as free to check
	 *
	 * @return array
	 */
	public static function get_free_products_ids( $product_id ) {
		$free_products = (array) get_post_meta( $product_id, '_edd_lfd_products', true );

		return array_filter( $free_products );
	}

	/**
	 * Add product to cart and subsequently checkout
	 *
	 * @param int $download_id
	 */
	public static function add_to_cart_and_checkout( $download_id ) {
		edd_add_to_cart( $download_id );

		$saved_download_ids = self::free_downloads_in_cart();

		if ( is_array( $saved_download_ids ) && ! empty( $saved_download_ids ) ) {
			$saved_download_ids[] = $download_id;
		} else {
			$saved_download_ids = array( $download_id );
		}

		EDD()->session->set( 'edd_fdl_download_ids', $saved_download_ids );

		wp_redirect( edd_get_checkout_uri() );
		exit;
	}


	/**
	 * Set price of free download to 0
	 *
	 * @param int $price
	 * @param int $download_id
	 * @param array $options
	 *
	 * @return int
	 */
	public static function set_price( $price, $download_id, $options ) {
		$free_product_ids = self::free_downloads_in_cart();

		if ( is_array( $free_product_ids ) && in_array( $download_id, $free_product_ids ) ) {
			$price = 0;
		}

		return $price;
	}


	/**
	 * Deleted free products saved to the free product session when cart is emptied.
	 */
	public static function delete_saved_free_download_in_cart() {
		EDD()->session->set( 'edd_fdl_download_ids', null );
	}


	/**
	 * Delete free product from session when removed from cart.
	 *
	 * @param int $cart_key
	 * @param int $item_id
	 */
	public static function delete_free_downloads_on_cart_removal( $cart_key, $item_id ) {
		// free products saved to session
		$free_products_in_cart = self::free_downloads_in_cart();

		if ( ! is_array( $free_products_in_cart ) ) {
			return;
		}

		// if the product being removed is among the saved/carted free product, delete.
		foreach ( $free_products_in_cart as $key => $value ) {
			if ( $item_id == $value ) {
				unset( $free_products_in_cart[ $key ] );
			}
		}

		// save the new free product array to seesion variable.
		if ( empty( $free_products_in_cart ) ) {
			EDD()->session->set( 'edd_fdl_download_ids', null );
		} else {
			EDD()->session->set( 'edd_fdl_download_ids', $free_products_in_cart );
		}
	}

	/**
	 * Ensure a distinct free download is available in cart.
	 *
	 * @param array $cart
	 *
	 * @return array
	 */
	public static function remove_duplicate_order( $cart ) {
		// products in cart
		$carts = EDD()->session->get( 'edd_cart' );
		$carts = ! empty( $carts ) ? array_values( $carts ) : false;

		// free downloads in saved to session and apparently also in cart
		$free_downloads_in_cart = self::free_downloads_in_cart();

		if ( ! $carts || ! is_array( $free_downloads_in_cart ) ) {
			return $cart;
		} else {

			// Am separating the free downloads from the cart item in order to make them distinct via self::array_unique

			/** @var array $free_downloads save the free download */
			$free_downloads = array();

			/** @var array $other_items_in_cart rest of other product in cart */
			$other_items_in_cart = array();

			foreach ( $carts as $key => $value ) {
				if ( in_array( $carts[ $key ]['id'], $free_downloads_in_cart ) ) {
					$free_downloads[] = $carts[ $key ];
				} else {
					$other_items_in_cart[] = $carts[ $key ];
				}
			}

			$cart = array_merge( $other_items_in_cart, self::array_unique( $free_downloads ) );

			return $cart;
		}
	}

	/**
	 * Remove duplicate from cart array
	 *
	 * @param $array
	 *
	 * @return array
	 */
	public static function array_unique( $array ) {
		$newArr = array();
		foreach ( $array as $val ) {
			$newArr[ $val['id'] ] = $val;
		}

		return array_values( $newArr );
	}


	/**
	 * Return free downloads in session/cart
	 *
	 * @return mixed
	 */
	public static function free_downloads_in_cart() {
		return EDD()->session->get( 'edd_fdl_download_ids' );
	}

	/**
	 * If dependency requirements are not satisfied, self-deactivate
	 */
	public static function maybe_self_deactivate() {
		if ( ! class_exists( 'Easy_Digital_Downloads' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
			deactivate_plugins( plugin_basename( __FILE__ ) );
			add_action( 'admin_notices', array( __CLASS__, 'self_deactivate_notice' ) );
		}
	}

	/**
	 * Registers the extension subsection.
	 *
	 * @since 1.0.1
	 * @param array $sections
	 * @return array
	 */
	public static function register_subsection( $sections ) {
		$sections['edd-license-free-download'] = __( 'License Free Downloads', 'edd_lfd' );

		return $sections;
	}

	/**
	 * Registers the extension's settings fields.
	 *
	 * @since 1.0.1
	 * @param array $settings
	 * @return array
	 */
	public static function settings_page( $settings ) {

		$license_settings = array(
			'edd-license-free-download' => array(
				array(
					'id'   => 'edd_lfd_license_missing',
					'name' => __( 'License Missing', 'edd_lfd' ),
					'desc' => __( 'Error displayed when no license key is detected.', 'edd_lfd' ),
					'type' => 'text',
				),
				array(
					'id'   => 'edd_lfd_license_validation_failed',
					'name' => __( 'Failed License Validation', 'edd_lfd' ),
					'desc' => __( 'Error displayed when a license key is deemed invalid.', 'edd_lfd' ),
					'type' => 'text',
				),
				array(
					'id'   => 'edd_lfd_product_not_free',
					'name' => __( 'Product not Free', 'edd_lfd' ),
					'desc' => __( 'Error displayed when trying to download a product that is not free.', 'edd_lfd' ),
					'type' => 'text',
				),
				array(
					'id'   => 'edd_lfd_access_denied',
					'name' => __( 'Access Denied', 'edd_lfd' ),
					'desc' => __( 'Error displayed when a license key is denied access to a free download.', 'edd_lfd' ),
					'type' => 'text',
				),
			),
		);

		return array_merge( $settings, $license_settings );
	}

	/**
	 * Display an error message when the plugin deactivates itself.
	 */
	public static function self_deactivate_notice() {
		echo '<div class="error"><p><strong>' . __( 'EDD License free download', 'edd_lfd' ) . '</strong> ' . __( 'requires Easy Digital Download plugin activated to work', 'edd_lfd' ) . '.</p></div>';
	}

	/**
	 * Default options on activation
	 */
	public static function register_activation() {

		// if plugin has been activated initially, return.
		if( false !== get_option('edd_lfd_plugin_activated') ) {
			return;
		}

		edd_update_option( 'edd_lfd_license_missing', 'License key is missing.' );
		edd_update_option( 'edd_lfd_license_validation_failed', 'License key validation failed. Try again.' );
		edd_update_option( 'edd_lfd_product_not_free', 'Product is not available for free.' );
		edd_update_option( 'edd_lfd_access_denied', 'The license key isn\'t allowed to download this product for free. Sorry.' );

		// option is added to prevent overriding user entered settings if the plugin is deactivated and reactivated.
		// option is deleted when plugin is uninstalled.
		add_option('edd_lfd_plugin_activated', 'true');
	}

}

register_activation_hook( __FILE__, array( 'EDD_lfd', 'register_activation' ) );

add_action( 'plugins_loaded', array( 'EDD_lfd', 'maybe_self_deactivate' ) );

add_action( 'plugins_loaded', 'edd_lfd_load_class' );

function edd_lfd_load_class() {
	EDD_lfd::init();
}
