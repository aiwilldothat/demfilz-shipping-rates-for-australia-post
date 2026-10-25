<?php
/**
 * Admin settings page for the Australia Post shipping plugin.
 *
 * Registers a dedicated tab under WooCommerce > Settings > Australia Post where
 * the credentials and all global options are configured. Settings are stored as
 * individual options prefixed with "dsra_" and read elsewhere through the
 * static {@see DSRA_Settings::get()} helper.
 *
 * Two rate sources are supported:
 *  - "pac"     The Postage Assessment Calculation API (a single API key).
 *  - "account" The Shipping & Tracking API (account number + API key + password),
 *              which returns the negotiated rates on your Australia Post account.
 *
 * @package DSRA
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DSRA_Settings
 */
class DSRA_Settings {

	/**
	 * Settings tab/slug.
	 */
	const TAB = 'auspost';

	/**
	 * Option name prefix.
	 */
	const PREFIX = 'dsra_';

	/**
	 * Singleton instance.
	 *
	 * @var DSRA_Settings|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return DSRA_Settings
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hook everything up.
	 */
	private function __construct() {
		add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_settings_tab' ), 50 );
		add_action( 'woocommerce_settings_tabs_' . self::TAB, array( $this, 'output' ) );
		add_action( 'woocommerce_update_options_' . self::TAB, array( $this, 'save' ) );

		// Custom field renderers.
		add_action( 'woocommerce_admin_field_dsra_test', array( $this, 'render_test_field' ) );
		add_action( 'woocommerce_admin_field_dsra_secret', array( $this, 'render_secret_field' ) );

		// Whitelist the enabled-services multiselect to known service codes on save.
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . self::PREFIX . 'enabled_services', array( $this, 'sanitize_services' ), 10, 3 );

		// AJAX handler for the connection test.
		add_action( 'wp_ajax_dsra_test', array( $this, 'ajax_test' ) );

		// Enqueue the settings-page script on our tab.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Read a setting value.
	 *
	 * @param string $key           Setting key (without prefix).
	 * @param mixed  $default_value Default value.
	 * @return mixed
	 */
	public static function get( $key, $default_value = '' ) {
		return get_option( self::PREFIX . $key, $default_value );
	}

	/**
	 * Option ids that hold secrets. These are rendered masked and saved manually
	 * so the stored value is never echoed back into the page.
	 *
	 * @return string[]
	 */
	protected function secret_ids() {
		return array(
			self::PREFIX . 'api_key',
			self::PREFIX . 'account_api_key',
			self::PREFIX . 'account_api_password',
		);
	}

	/**
	 * Curated list of Australia Post service codes mapped to friendly names.
	 *
	 * Used to populate the "Enabled services" multiselect. The list acts as an
	 * allow-list filter; a service still only appears at checkout if the API
	 * returns it for the given parcel, weight and destination.
	 *
	 * @return array<string,string>
	 */
	public static function get_service_options() {
		return array(
			// --- Domestic ---.
			'AUS_PARCEL_REGULAR'                => __( 'Parcel Post', 'demfilz-shipping-rates-for-australia-post' ),
			'AUS_PARCEL_REGULAR_SATCHEL_500G'   => __( 'Parcel Post – 500g Satchel', 'demfilz-shipping-rates-for-australia-post' ),
			'AUS_PARCEL_REGULAR_SATCHEL_1KG'    => __( 'Parcel Post – 1kg Satchel', 'demfilz-shipping-rates-for-australia-post' ),
			'AUS_PARCEL_REGULAR_SATCHEL_3KG'    => __( 'Parcel Post – 3kg Satchel', 'demfilz-shipping-rates-for-australia-post' ),
			'AUS_PARCEL_REGULAR_SATCHEL_5KG'    => __( 'Parcel Post – 5kg Satchel', 'demfilz-shipping-rates-for-australia-post' ),
			'AUS_PARCEL_EXPRESS'                => __( 'Express Post', 'demfilz-shipping-rates-for-australia-post' ),
			'AUS_PARCEL_EXPRESS_SATCHEL_500G'   => __( 'Express Post – 500g Satchel', 'demfilz-shipping-rates-for-australia-post' ),
			'AUS_PARCEL_EXPRESS_SATCHEL_3KG'    => __( 'Express Post – 3kg Satchel', 'demfilz-shipping-rates-for-australia-post' ),
			'AUS_PARCEL_EXPRESS_SATCHEL_5KG'    => __( 'Express Post – 5kg Satchel', 'demfilz-shipping-rates-for-australia-post' ),
			'AUS_PARCEL_COURIER'                => __( 'Courier Post', 'demfilz-shipping-rates-for-australia-post' ),
			'AUS_PARCEL_COURIER_SATCHEL_MEDIUM' => __( 'Courier Post – Medium Satchel', 'demfilz-shipping-rates-for-australia-post' ),
			// --- International ---.
			'INT_PARCEL_EXP_OWN_PACKAGING'      => __( 'Express Post International', 'demfilz-shipping-rates-for-australia-post' ),
			'INT_PARCEL_COR_OWN_PACKAGING'      => __( 'Express Courier International', 'demfilz-shipping-rates-for-australia-post' ),
			'INT_PARCEL_STD_OWN_PACKAGING'      => __( 'Standard / Registered Post International', 'demfilz-shipping-rates-for-australia-post' ),
			'INT_PARCEL_AIR_OWN_PACKAGING'      => __( 'Pack &amp; Track / Air Mail International', 'demfilz-shipping-rates-for-australia-post' ),
			'INT_PARCEL_SEA_OWN_PACKAGING'      => __( 'Economy Sea / Sea Mail International', 'demfilz-shipping-rates-for-australia-post' ),
		);
	}

	/**
	 * Register the settings tab.
	 *
	 * @param array $tabs Existing tabs.
	 * @return array
	 */
	public function add_settings_tab( $tabs ) {
		$tabs[ self::TAB ] = __( 'Australia Post', 'demfilz-shipping-rates-for-australia-post' );
		return $tabs;
	}

	/**
	 * Output the settings fields.
	 */
	public function output() {
		woocommerce_admin_fields( $this->get_settings() );
	}

	/**
	 * Persist the settings fields.
	 *
	 * Runs inside WooCommerce's nonce-verified, capability-checked save routine.
	 * Secrets are saved manually so a blank field preserves the stored value and
	 * the value is never rendered back into the form.
	 */
	public function save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// WooCommerce verifies this nonce before firing the save hook; re-check it
		// here so the secret handling below is also explicitly guarded.
		if ( empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), 'woocommerce-settings' ) ) {
			return;
		}

		foreach ( $this->secret_ids() as $id ) {
			$field = $id . '_new';
			if ( isset( $_POST[ $field ] ) ) {
				$submitted = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
				if ( '' !== $submitted ) {
					update_option( $id, $submitted );
				}
			}
		}

		woocommerce_update_options( $this->get_settings() );
	}

	/**
	 * Define the settings schema.
	 *
	 * @return array
	 */
	public function get_settings() {
		$p = self::PREFIX;

		$settings = array(

			// --- Rate source & credentials --------------------------------------.
			array(
				'title' => __( 'Rate source &amp; credentials', 'demfilz-shipping-rates-for-australia-post' ),
				'type'  => 'title',
				'desc'  => __( 'Choose where rates come from, then enter the matching credentials. The fields update to match your choice.', 'demfilz-shipping-rates-for-australia-post' ),
				'id'    => $p . 'section_api',
			),
			array(
				'title'    => __( 'Rate source', 'demfilz-shipping-rates-for-australia-post' ),
				'id'       => $p . 'api_mode',
				'type'     => 'select',
				'class'    => 'wc-enhanced-select',
				'default'  => 'pac',
				'options'  => array(
					'pac'     => __( 'PAC API — retail rates (single API key)', 'demfilz-shipping-rates-for-australia-post' ),
					'account' => __( 'Shipping & Tracking API — your account rates (account no. + key + password)', 'demfilz-shipping-rates-for-australia-post' ),
				),
				'desc'     => __( 'PAC returns standard retail postage. The Shipping & Tracking API returns the negotiated rates on your Australia Post business account (eParcel / MyPost Business / Enterprise).', 'demfilz-shipping-rates-for-australia-post' ),
				'desc_tip' => true,
			),
			array(
				'title' => __( 'PAC API key (AUTH-KEY)', 'demfilz-shipping-rates-for-australia-post' ),
				'desc'  => sprintf(
					/* translators: %s: Australia Post developer registration URL */
					__( 'For the PAC rate source. Get a free key from <a href="%s" target="_blank" rel="noopener noreferrer">developers.auspost.com.au</a>.', 'demfilz-shipping-rates-for-australia-post' ),
					'https://developers.auspost.com.au/apis/pacpcs-registration'
				),
				'id'    => $p . 'api_key',
				'type'  => 'dsra_secret',
			),
			array(
				'title'   => __( 'Account number', 'demfilz-shipping-rates-for-australia-post' ),
				'desc'    => __( 'For the Shipping & Tracking rate source — your Australia Post charge account number.', 'demfilz-shipping-rates-for-australia-post' ),
				'id'      => $p . 'account_number',
				'type'    => 'text',
				'css'     => 'min-width:250px;',
				'default' => '',
			),
			array(
				'title' => __( 'Account API key', 'demfilz-shipping-rates-for-australia-post' ),
				'desc'  => __( 'The API key issued for your Shipping & Tracking API account.', 'demfilz-shipping-rates-for-australia-post' ),
				'id'    => $p . 'account_api_key',
				'type'  => 'dsra_secret',
			),
			array(
				'title' => __( 'Account API password', 'demfilz-shipping-rates-for-australia-post' ),
				'desc'  => __( 'The API secret/password issued for your Shipping & Tracking API account.', 'demfilz-shipping-rates-for-australia-post' ),
				'id'    => $p . 'account_api_password',
				'type'  => 'dsra_secret',
			),
			array(
				'type' => 'dsra_test',
				'id'   => $p . 'test',
			),
			array(
				'type' => 'sectionend',
				'id'   => $p . 'section_api',
			),

			// --- Origin ----------------------------------------------------------.
			array(
				'title' => __( 'Shipping origin', 'demfilz-shipping-rates-for-australia-post' ),
				'type'  => 'title',
				'desc'  => __( 'Where parcels are dispatched from. Defaults to your WooCommerce store postcode if left blank.', 'demfilz-shipping-rates-for-australia-post' ),
				'id'    => $p . 'section_origin',
			),
			array(
				'title'             => __( 'Origin postcode', 'demfilz-shipping-rates-for-australia-post' ),
				'desc'              => __( 'Australian postcode parcels ship from, e.g. 2000.', 'demfilz-shipping-rates-for-australia-post' ),
				'id'                => $p . 'origin_postcode',
				'type'              => 'text',
				'css'               => 'width:120px;',
				'default'           => get_option( 'woocommerce_store_postcode', '' ),
				'custom_attributes' => array( 'maxlength' => '4' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => $p . 'section_origin',
			),

			// --- Packaging -------------------------------------------------------.
			array(
				'title' => __( 'Packaging', 'demfilz-shipping-rates-for-australia-post' ),
				'type'  => 'title',
				'desc'  => __( 'How the cart is measured for a rate request. Weights are summed across all items; dimensions are taken from products when available, otherwise the default box below is used. Dimensions are in centimetres.', 'demfilz-shipping-rates-for-australia-post' ),
				'id'    => $p . 'section_pack',
			),
			array(
				'title'   => __( 'Use product dimensions', 'demfilz-shipping-rates-for-australia-post' ),
				'desc'    => __( 'Calculate parcel size from product length/width/height when every item has dimensions set.', 'demfilz-shipping-rates-for-australia-post' ),
				'id'      => $p . 'use_product_dimensions',
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'             => __( 'Default weight (kg)', 'demfilz-shipping-rates-for-australia-post' ),
				'desc'              => __( 'Used when the cart total weight is zero (e.g. products with no weight).', 'demfilz-shipping-rates-for-australia-post' ),
				'id'                => $p . 'default_weight',
				'type'              => 'number',
				'css'               => 'width:90px;',
				'default'           => '0.5',
				'custom_attributes' => array(
					'step' => '0.01',
					'min'  => '0',
				),
			),
			array(
				'title'             => __( 'Default box length (cm)', 'demfilz-shipping-rates-for-australia-post' ),
				'desc'              => __( 'Fallback parcel dimensions in centimetres.', 'demfilz-shipping-rates-for-australia-post' ),
				'id'                => $p . 'default_length',
				'type'              => 'number',
				'css'               => 'width:90px;',
				'default'           => '20',
				'custom_attributes' => array(
					'step' => '0.1',
					'min'  => '0',
				),
			),
			array(
				'title'             => __( 'Default box width (cm)', 'demfilz-shipping-rates-for-australia-post' ),
				'id'                => $p . 'default_width',
				'type'              => 'number',
				'css'               => 'width:90px;',
				'default'           => '15',
				'custom_attributes' => array(
					'step' => '0.1',
					'min'  => '0',
				),
			),
			array(
				'title'             => __( 'Default box height (cm)', 'demfilz-shipping-rates-for-australia-post' ),
				'id'                => $p . 'default_height',
				'type'              => 'number',
				'css'               => 'width:90px;',
				'default'           => '10',
				'custom_attributes' => array(
					'step' => '0.1',
					'min'  => '0',
				),
			),
			array(
				'type' => 'sectionend',
				'id'   => $p . 'section_pack',
			),

			// --- Rates & services ------------------------------------------------.
			array(
				'title' => __( 'Rates &amp; services', 'demfilz-shipping-rates-for-australia-post' ),
				'type'  => 'title',
				'id'    => $p . 'section_rates',
			),
			array(
				'title'    => __( 'Enabled services', 'demfilz-shipping-rates-for-australia-post' ),
				'desc'     => __( 'Choose which Australia Post services to offer. Leave empty to show every service the API returns. A service only appears if Australia Post returns it for the given size, weight and destination.', 'demfilz-shipping-rates-for-australia-post' ),
				'id'       => $p . 'enabled_services',
				'type'     => 'multiselect',
				'class'    => 'wc-enhanced-select',
				'css'      => 'min-width:350px;',
				'default'  => array(),
				'options'  => self::get_service_options(),
				'desc_tip' => false,
			),
			array(
				'title'             => __( 'Handling fee', 'demfilz-shipping-rates-for-australia-post' ),
				'desc'              => __( 'Flat amount added to every quote (in your store currency).', 'demfilz-shipping-rates-for-australia-post' ),
				'id'                => $p . 'handling_fee',
				'type'              => 'number',
				'css'               => 'width:90px;',
				'default'           => '0',
				'custom_attributes' => array(
					'step' => '0.01',
					'min'  => '0',
				),
			),
			array(
				'title'             => __( 'Markup (%)', 'demfilz-shipping-rates-for-australia-post' ),
				'desc'              => __( 'Percentage added on top of the Australia Post price.', 'demfilz-shipping-rates-for-australia-post' ),
				'id'                => $p . 'markup_percent',
				'type'              => 'number',
				'css'               => 'width:90px;',
				'default'           => '0',
				'custom_attributes' => array(
					'step' => '0.1',
					'min'  => '0',
				),
			),
			array(
				'title'   => __( 'Label prefix', 'demfilz-shipping-rates-for-australia-post' ),
				'desc'    => __( 'Optional text shown before each service name at checkout, e.g. "Australia Post - ".', 'demfilz-shipping-rates-for-australia-post' ),
				'id'      => $p . 'label_prefix',
				'type'    => 'text',
				'css'     => 'min-width:250px;',
				'default' => '',
			),
			array(
				'title'   => __( 'Show delivery time estimate', 'demfilz-shipping-rates-for-australia-post' ),
				'desc'    => __( 'Append an estimated delivery time to each service at checkout (an estimate based on service type).', 'demfilz-shipping-rates-for-australia-post' ),
				'id'      => $p . 'delivery_time',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'title'   => __( 'Remove GST', 'demfilz-shipping-rates-for-australia-post' ),
				'desc'    => __( 'Strip the 10% GST from Australia Post prices — e.g. if you add tax separately via WooCommerce tax settings.', 'demfilz-shipping-rates-for-australia-post' ),
				'id'      => $p . 'remove_gst',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'type' => 'sectionend',
				'id'   => $p . 'section_rates',
			),

			// --- Free shipping ---------------------------------------------------.
			array(
				'title' => __( 'Free shipping', 'demfilz-shipping-rates-for-australia-post' ),
				'type'  => 'title',
				'id'    => $p . 'section_free',
			),
			array(
				'title'   => __( 'Enable free shipping', 'demfilz-shipping-rates-for-australia-post' ),
				'desc'    => __( 'Offer a free option once the cart subtotal reaches the threshold below.', 'demfilz-shipping-rates-for-australia-post' ),
				'id'      => $p . 'free_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'title'             => __( 'Free shipping minimum', 'demfilz-shipping-rates-for-australia-post' ),
				'desc'              => __( 'Cart subtotal (excluding tax/shipping) required to unlock free shipping.', 'demfilz-shipping-rates-for-australia-post' ),
				'id'                => $p . 'free_min',
				'type'              => 'number',
				'css'               => 'width:110px;',
				'default'           => '0',
				'custom_attributes' => array(
					'step' => '0.01',
					'min'  => '0',
				),
			),
			array(
				'title'   => __( 'Free shipping label', 'demfilz-shipping-rates-for-australia-post' ),
				'id'      => $p . 'free_label',
				'type'    => 'text',
				'css'     => 'min-width:250px;',
				'default' => __( 'Free Shipping', 'demfilz-shipping-rates-for-australia-post' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => $p . 'section_free',
			),

			// --- Fallback --------------------------------------------------------.
			array(
				'title' => __( 'Fallback rate', 'demfilz-shipping-rates-for-australia-post' ),
				'type'  => 'title',
				'desc'  => __( 'Offered when the API is unreachable or returns no services, so checkout is never blocked.', 'demfilz-shipping-rates-for-australia-post' ),
				'id'    => $p . 'section_fallback',
			),
			array(
				'title'   => __( 'Enable fallback rate', 'demfilz-shipping-rates-for-australia-post' ),
				'id'      => $p . 'fallback_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'title'             => __( 'Fallback cost', 'demfilz-shipping-rates-for-australia-post' ),
				'id'                => $p . 'fallback_cost',
				'type'              => 'number',
				'css'               => 'width:110px;',
				'default'           => '0',
				'custom_attributes' => array(
					'step' => '0.01',
					'min'  => '0',
				),
			),
			array(
				'title'   => __( 'Fallback label', 'demfilz-shipping-rates-for-australia-post' ),
				'id'      => $p . 'fallback_label',
				'type'    => 'text',
				'css'     => 'min-width:250px;',
				'default' => __( 'Shipping', 'demfilz-shipping-rates-for-australia-post' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => $p . 'section_fallback',
			),

			// --- Advanced --------------------------------------------------------.
			array(
				'title' => __( 'Advanced', 'demfilz-shipping-rates-for-australia-post' ),
				'type'  => 'title',
				'id'    => $p . 'section_advanced',
			),
			array(
				'title'             => __( 'Cache rates (minutes)', 'demfilz-shipping-rates-for-australia-post' ),
				'desc'              => __( 'Cache API responses to speed up checkout and reduce API calls. Set to 0 to disable.', 'demfilz-shipping-rates-for-australia-post' ),
				'id'                => $p . 'cache_minutes',
				'type'              => 'number',
				'css'               => 'width:90px;',
				'default'           => '720',
				'custom_attributes' => array(
					'step' => '1',
					'min'  => '0',
				),
			),
			array(
				'title'   => __( 'Test mode (admin only)', 'demfilz-shipping-rates-for-australia-post' ),
				'desc'    => __( 'Only show Australia Post rates to logged-in administrators. Use this to verify live rates before going live.', 'demfilz-shipping-rates-for-australia-post' ),
				'id'      => $p . 'test_mode',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'title'   => __( 'Debug logging', 'demfilz-shipping-rates-for-australia-post' ),
				'desc'    => __( 'Log API requests and responses to WooCommerce > Status > Logs (source: demfilz-shipping-rates-for-australia-post).', 'demfilz-shipping-rates-for-australia-post' ),
				'id'      => $p . 'debug',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'type' => 'sectionend',
				'id'   => $p . 'section_advanced',
			),
		);

		return apply_filters( 'dsra_settings', $settings );
	}

	/**
	 * Render a masked secret field. The stored value is never output; submitting a
	 * blank value keeps the existing secret (see {@see save()}).
	 *
	 * @param array $value Field definition.
	 */
	public function render_secret_field( $value ) {
		$id          = $value['id'];
		$has_key     = '' !== (string) get_option( $id, '' );
		$placeholder = $has_key
			? __( 'Saved — leave blank to keep current value', 'demfilz-shipping-rates-for-australia-post' )
			: __( 'Enter value', 'demfilz-shipping-rates-for-australia-post' );
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $value['title'] ); ?></label>
			</th>
			<td class="forminp">
				<input
					type="password"
					name="<?php echo esc_attr( $id . '_new' ); ?>"
					id="<?php echo esc_attr( $id ); ?>"
					value=""
					autocomplete="new-password"
					class="input-text regular-input"
					style="min-width:350px;"
					placeholder="<?php echo esc_attr( $placeholder ); ?>"
				/>
				<?php if ( ! empty( $value['desc'] ) ) : ?>
					<p class="description"><?php echo wp_kses_post( $value['desc'] ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render the custom "Test connection" row.
	 */
	public function render_test_field() {
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php esc_html_e( 'Test connection', 'demfilz-shipping-rates-for-australia-post' ); ?></th>
			<td class="forminp">
				<button type="button" class="button" id="dsra-test-btn"><?php esc_html_e( 'Test credentials', 'demfilz-shipping-rates-for-australia-post' ); ?></button>
				<span id="dsra-test-result" style="margin-left:10px;"></span>
				<p class="description"><?php esc_html_e( 'Sends a sample parcel quote (Sydney to Melbourne) using the selected rate source to verify your credentials. Save first if you have just entered them.', 'demfilz-shipping-rates-for-australia-post' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Whitelist the enabled-services value to known service codes.
	 *
	 * @param mixed $value     Sanitised value (unused).
	 * @param array $option    Option definition (unused).
	 * @param mixed $raw_value Raw submitted value.
	 * @return array
	 */
	public function sanitize_services( $value, $option, $raw_value ) {
		$allowed = array_keys( self::get_service_options() );
		$codes   = is_array( $raw_value ) ? array_map( 'sanitize_text_field', wp_unslash( $raw_value ) ) : array();
		return array_values( array_intersect( $codes, $allowed ) );
	}

	/**
	 * AJAX: test the API connection for the selected rate source.
	 */
	public function ajax_test() {
		check_ajax_referer( 'dsra_test', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'demfilz-shipping-rates-for-australia-post' ) ) );
		}

		$debug = 'yes' === self::get( 'debug', 'no' );
		$mode  = isset( $_POST['api_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['api_mode'] ) ) : self::get( 'api_mode', 'pac' );

		if ( 'account' === $mode ) {
			$account = isset( $_POST['account_number'] ) ? sanitize_text_field( wp_unslash( $_POST['account_number'] ) ) : '';
			$key     = isset( $_POST['account_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['account_api_key'] ) ) : '';
			$pass    = isset( $_POST['account_api_password'] ) ? sanitize_text_field( wp_unslash( $_POST['account_api_password'] ) ) : '';

			$account = '' !== $account ? $account : self::get( 'account_number' );
			$key     = '' !== $key ? $key : self::get( 'account_api_key' );
			$pass    = '' !== $pass ? $pass : self::get( 'account_api_password' );

			if ( '' === $account || '' === $key || '' === $pass ) {
				wp_send_json_error( array( 'message' => __( 'Enter your account number, API key and password first.', 'demfilz-shipping-rates-for-australia-post' ) ) );
			}

			$from = self::get( 'origin_postcode' );
			if ( '' === (string) $from ) {
				$from = get_option( 'woocommerce_store_postcode' );
			}

			$api = new DSRA_Account_API( $account, $key, $pass, $from, 0, $debug );
		} else {
			$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
			$api_key = '' !== $api_key ? $api_key : self::get( 'api_key' );

			if ( '' === $api_key ) {
				wp_send_json_error( array( 'message' => __( 'Please enter a PAC API key first.', 'demfilz-shipping-rates-for-australia-post' ) ) );
			}

			$api = new DSRA_API( $api_key, 0, $debug );
		}

		$result = $api->test_connection();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Success! Your credentials are working.', 'demfilz-shipping-rates-for-australia-post' ) ) );
	}

	/**
	 * Enqueue the settings-page script on our tab only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::TAB !== $tab ) {
			return;
		}

		wp_enqueue_script(
			'dsra-admin-settings',
			DSRA_URL . 'assets/js/admin-settings.js',
			array( 'jquery' ),
			DSRA_VERSION,
			true
		);

		wp_localize_script(
			'dsra-admin-settings',
			'dsraSettings',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => 'dsra_test',
				'nonce'   => wp_create_nonce( 'dsra_test' ),
				'i18n'    => array(
					'testing'       => __( 'Testing…', 'demfilz-shipping-rates-for-australia-post' ),
					'requestFailed' => __( 'Request failed.', 'demfilz-shipping-rates-for-australia-post' ),
				),
			)
		);
	}
}
