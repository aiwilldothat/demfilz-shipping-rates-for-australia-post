<?php
/**
 * Australia Post Postage Assessment Calculation (PAC) API client.
 *
 * Docs: https://developers.auspost.com.au/apis/pac/reference
 * Authentication: an AUTH-KEY header containing the API key obtained from the
 * Australia Post Developer Centre.
 *
 * @package DSRA
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DSRA_API
 */
class DSRA_API {

	/**
	 * Base URL for the Australia Post Digital API.
	 */
	const BASE_URL = 'https://digitalapi.auspost.com.au';

	/**
	 * The merchant API key.
	 *
	 * @var string
	 */
	protected $api_key;

	/**
	 * Cache lifetime in seconds (0 disables caching).
	 *
	 * @var int
	 */
	protected $cache_ttl;

	/**
	 * Whether to write debug entries to the WooCommerce logger.
	 *
	 * @var bool
	 */
	protected $debug;

	/**
	 * Constructor.
	 *
	 * @param string $api_key   API key (AUTH-KEY).
	 * @param int    $cache_ttl Cache lifetime in seconds.
	 * @param bool   $debug     Enable debug logging.
	 */
	public function __construct( $api_key, $cache_ttl = 0, $debug = false ) {
		$this->api_key   = trim( (string) $api_key );
		$this->cache_ttl = max( 0, (int) $cache_ttl );
		$this->debug     = (bool) $debug;
	}

	/**
	 * Retrieve available domestic parcel services with prices.
	 *
	 * @param string $from_postcode Origin postcode.
	 * @param string $to_postcode   Destination postcode.
	 * @param float  $weight        Weight in kilograms.
	 * @param float  $length        Length in centimetres.
	 * @param float  $width         Width in centimetres.
	 * @param float  $height        Height in centimetres.
	 * @return array|WP_Error List of services or WP_Error on failure.
	 */
	public function domestic_services( $from_postcode, $to_postcode, $weight, $length, $width, $height ) {
		$params = array(
			'from_postcode' => $from_postcode,
			'to_postcode'   => $to_postcode,
			'length'        => $length,
			'width'         => $width,
			'height'        => $height,
			'weight'        => $weight,
		);

		return $this->get_services( '/postage/parcel/domestic/service.json', $params );
	}

	/**
	 * Retrieve available international parcel services with prices.
	 *
	 * @param string $country_code Two-letter ISO destination country code.
	 * @param float  $weight       Weight in kilograms.
	 * @return array|WP_Error List of services or WP_Error on failure.
	 */
	public function international_services( $country_code, $weight ) {
		$params = array(
			'country_code' => strtoupper( $country_code ),
			'weight'       => $weight,
		);

		return $this->get_services( '/postage/parcel/international/service.json', $params );
	}

	/**
	 * Perform a request to a services endpoint and return normalised services.
	 *
	 * @param string $endpoint API endpoint path.
	 * @param array  $params   Query parameters.
	 * @return array|WP_Error
	 */
	protected function get_services( $endpoint, $params ) {
		if ( '' === $this->api_key ) {
			return new WP_Error( 'dsra_no_key', __( 'No Australia Post API key configured.', 'demfilz-shipping-rates-for-australia-post' ) );
		}

		$cache_key = 'dsra_' . md5( $endpoint . wp_json_encode( $params ) );

		if ( $this->cache_ttl > 0 ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				$this->log( 'Cache hit for ' . $endpoint );
				return $cached;
			}
		}

		$response = $this->request( $endpoint, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$services = $this->parse_services( $response );

		if ( $this->cache_ttl > 0 && ! empty( $services ) ) {
			set_transient( $cache_key, $services, $this->cache_ttl );
		}

		return $services;
	}

	/**
	 * Execute the HTTP request.
	 *
	 * @param string $endpoint API endpoint path.
	 * @param array  $params   Query parameters.
	 * @return array|WP_Error Decoded JSON body or WP_Error.
	 */
	protected function request( $endpoint, $params ) {
		$url = self::BASE_URL . $endpoint . '?' . http_build_query( $params );

		$this->log( 'Request: ' . $url );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'AUTH-KEY' => $this->api_key,
					'Accept'   => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log( 'HTTP error: ' . $response->get_error_message() );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		$this->log( 'Response (' . $code . '): ' . $body );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			return new WP_Error( 'dsra_bad_json', __( 'Australia Post returned an invalid response.', 'demfilz-shipping-rates-for-australia-post' ) );
		}

		if ( 200 !== $code || isset( $data['error'] ) ) {
			$message = isset( $data['error']['errorMessage'] )
				? $data['error']['errorMessage']
				: __( 'Australia Post API request failed.', 'demfilz-shipping-rates-for-australia-post' );
			return new WP_Error( 'dsra_api_error', $message, array( 'status' => $code ) );
		}

		return $data;
	}

	/**
	 * Normalise the services payload into a flat list of code/name/price arrays.
	 *
	 * @param array $data Decoded response body.
	 * @return array
	 */
	protected function parse_services( $data ) {
		if ( empty( $data['services']['service'] ) ) {
			return array();
		}

		$services = $data['services']['service'];

		// A single service is returned as an associative array rather than a list.
		if ( isset( $services['code'] ) ) {
			$services = array( $services );
		}

		$out = array();

		foreach ( $services as $service ) {
			if ( empty( $service['code'] ) || ! isset( $service['price'] ) ) {
				continue;
			}

			$out[] = array(
				'code'  => sanitize_text_field( $service['code'] ),
				'name'  => isset( $service['name'] ) ? sanitize_text_field( $service['name'] ) : $service['code'],
				'price' => (float) $service['price'],
			);
		}

		return $out;
	}

	/**
	 * Lightweight connectivity test used by the settings page.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		// Sydney (2000) to Melbourne (3000), 1kg, 10x10x10cm sample parcel.
		$result = $this->domestic_services( '2000', '3000', 1, 10, 10, 10 );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result ) ) {
			return new WP_Error( 'dsra_empty', __( 'Connected, but no services were returned for the test parcel.', 'demfilz-shipping-rates-for-australia-post' ) );
		}

		return true;
	}

	/**
	 * Write a debug message to the WooCommerce logger.
	 *
	 * @param string $message Message to log.
	 */
	protected function log( $message ) {
		if ( ! $this->debug || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		wc_get_logger()->debug( $message, array( 'source' => 'demfilz-shipping-rates-for-australia-post' ) );
	}
}
