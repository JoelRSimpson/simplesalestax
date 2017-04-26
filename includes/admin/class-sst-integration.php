<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; 
}

/**
 * SST Integration.
 *
 * WooCommerce integration for Simple Sales Tax.
 *
 * @author 	Simple Sales Tax
 * @package SST
 * @since 	5.0
 */ 
class SST_Integration extends WC_Integration { 

	/**
	 * Constructor. Initialize the integration.
	 *
	 * @since 4.5
	 */
	public function __construct() {
		$this->id                 = 'wootax';
		$this->method_title       = __( 'Simple Sales Tax', 'woocommerce-wootax' );
		$this->method_description = __( '<p>Simple Sales Tax makes sales tax easy by connecting your store with <a href="https://taxcloud.net" target="_blank">TaxCloud</a>. If you have trouble with Simple Sales Tax, please consult the <a href="https://simplesalestax.com/#faq" target="_blank">FAQ</a> and the <a href="https://simplesalestax.com/installation-guide/" target="_blank">Installation Guide</a> before contacting support.</p><p>Need help? <a href="https://simplesalestax.com/contact-us/" target="_blank">Contact us</a>.</p>', 'woocommerce-wootax' );
 
		// Load the settings.
		$this->init_form_fields();

		// Register action hooks.
		add_action( 'woocommerce_update_options_integration_' .  $this->id, array( $this, 'process_admin_options' ) );
 		add_filter( 'woocommerce_settings_api_sanitized_fields_' . $this->id, array( $this, 'sanitize_settings' ) );
 		add_action( 'admin_enqueue_scripts', array( $this, 'register_scripts' ) );
	}

	/**
	 * Register scripts.
	 *
	 * @since 5.0
	 */
	public function register_scripts() {
		wp_register_script( 'sst-addresses', SST()->plugin_url() . '/assets/js/address-table.js', array( 'jquery', 'wp-util', 'underscore', 'backbone' ), SST()->version );
	}

 	/**
 	 * Initialize form fields for integration settings.
 	 *
 	 * @since 4.5
 	 */
	public function init_form_fields() {
		$this->form_fields = SST_Settings::get_form_fields();
	}

	/**
	 * Display admin options.
	 *
	 * @since 5.0
	 */
	public function admin_options() {
		$this->display_errors();
		parent::admin_options();
	}
 	
 	/**
 	 * Output HTML for field of type 'section.'
 	 *
 	 * @since 4.5
	 */
 	public function generate_section_html( $key, $data ) {
 		ob_start();
 		?>
 		<tr valign="top">
 			<td colspan="2" style="padding-left: 0;">
 				<h4 style="margin-top: 0;"><?php echo $data[ 'title' ]; ?></h4>
 				<p><?php echo $data[ 'description' ]; ?></p>
 			</td>
 		</tr>
 		<?php
 		return ob_get_clean();
 	}

 	/**
 	 * Output HTML for field of type 'button.'
 	 *
 	 * @since 4.5
	 */
 	public function generate_button_html( $key, $data ) {
 		$field = $this->plugin_id . $this->id . '_' . $key;

 		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field ); ?>"><?php echo wp_kses_post( $data[ 'title' ] ); ?></label>
				<?php echo $this->get_tooltip_html( $data ); ?>
			</th>
			<td class="forminp">
				<fieldset>
					<legend class="screen-reader-text"><span><?php echo wp_kses_post( $data[ 'title' ] ); ?></span></legend>
					<button class="wp-core-ui button button-secondary" type="button" id="<?php echo $data[ 'id' ]; ?>"><?php echo wp_kses_post( $data[ 'label' ] ); ?></button>
					<?php echo $this->get_description_html( $data ); ?>
				</fieldset>
			</td>
		</tr>
		<?php
		return ob_get_clean();
 	}

 	/**
 	 * Output HTML for field of type 'anchor.'
 	 *
 	 * @since 5.0
	 */
 	public function generate_anchor_html( $key, $data ) {
 		$field = $this->plugin_id . $this->id . '_' . $key;

 		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field ); ?>"><?php echo wp_kses_post( $data[ 'title' ] ); ?></label>
				<?php echo $this->get_tooltip_html( $data ); ?>
			</th>
			<td class="forminp">
				<fieldset>
					<legend class="screen-reader-text"><span><?php echo wp_kses_post( $data[ 'title' ] ); ?></span></legend>
					<a href="<?php echo esc_url( $data[ 'url' ] ); ?>" target="_blank" class="wp-core-ui button button-secondary" id="<?php echo $data[ 'id' ]; ?>"><?php echo wp_kses_post( $data[ 'label' ] ); ?></a>
					<?php echo $this->get_description_html( $data ); ?>
				</fieldset>
			</td>
		</tr>
		<?php
		return ob_get_clean();
 	}

 	/**
 	 * Get addresses formatted for output.
 	 *
 	 * @since 5.0
 	 */
 	private function get_addresses() {
 		$raw_addresses = SST_Settings::get( 'addresses' );

 		if ( ! is_array( $raw_addresses ) ) {
 			return array();
 		}

 		$addresses = array();

 		foreach ( $raw_addresses as $raw_address ) {
 			$addresses[] = json_decode( $raw_address, true );
 		}

 		return $addresses;
 	}
 	/**
 	 * Output HTML for 'address_table' field.
 	 *
 	 * @since 4.5
 	 */
 	public function generate_address_table_html( $key, $data ) {
 		wp_localize_script( 'sst-addresses', 'addressesLocalizeScript', array(
 			'addresses'       => $this->get_addresses(),
 			'strings'         => array(
 				'one_default_required' => __( 'At least one default address is required.', 'simplesalestax' ),
 			),
 			'default_address' => array(
 				'ID'       => '',
 				'Address1' => '',
 				'Address2' => '',
 				'City'     => '',
 				'State'    => '',
 				'Zip5'     => '',
 				'Zip4'     => '',
 				'Default'  => false,
 			),
 		) );
 		wp_enqueue_script( 'sst-addresses' );

 		ob_start();
 		include dirname( __FILE__ ) . '/views/html-address-table.php';
 		return ob_get_clean();
 	}

 	/**
 	 * Sanitize submitted settings.
 	 * 
 	 * Validate addresses before they are saved.
 	 *
 	 * @since 4.5
 	 *
 	 * @param  array $settings Array of submitted settings.
 	 * @return array
 	 */
 	public function sanitize_settings( $settings ) {
 		if ( ! isset( $_POST['addresses'] ) || ! is_array( $_POST['addresses'] ) ) {
 			return $settings;
 		}

 		$default_address = array(
 			'Address1' => '',
 			'Address2' => '',
 			'City'     => '',
 			'State'    => '',
 			'Zip5'     => '',
 			'Zip4'     => '',
 			'ID'       => '',
 			'Default'  => 'no',
 		);

 		$addresses = array();

 		foreach ( $_POST['addresses'] as $raw_address ) {
 			// Use defaults for missing fields
 			$raw_address = array_merge( $default_address, $raw_address );

 			try {
 				$address = new TaxCloud\Address(
					$raw_address['Address1'],
					$raw_address['Address2'],
					$raw_address['City'],
					$raw_address['State'],
					$raw_address['Zip5'],
					$raw_address['Zip4']
				);
 			} catch ( Exception $ex ) {
 				// Leave out address with error
 				$this->add_error( sprintf( __( 'Failed to save address <em>%s</em>: %s', 'simplesalestax' ), $raw_address['Address1'], $ex->getMessage() ) );
 				continue;
 			}
 			
			$verify = new TaxCloud\Request\VerifyAddress( $settings['tc_id'], $settings['tc_key'], $address );
			try {
				$address = TaxCloud()->VerifyAddress( $verify );
			} catch ( Exception $ex ) {
				// Use original address
			}
			
			// Convert verified address to SST_Origin_Address
			$address = new SST_Origin_Address(
				count( $addresses ),				// ID
				'yes' == $raw_address['Default'], 	// Default
				$address->getAddress1(),
				$address->getAddress2(),
				$address->getCity(),
				$address->getState(),
				$address->getZip5(),
				$address->getZip4()
			);

			$addresses[] = json_encode( $address );
 		}

 		$settings['addresses'] = $addresses;

		return $settings;
 	}
}