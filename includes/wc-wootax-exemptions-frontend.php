<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Frontend support for tax exemptions
 *
 * @package Simple Sales Tax
 * @author Brett Porcelli
 */

require dirname( __FILE__) . '/../classes/class-wt-exemption-certificate.php';

/**
 * Define JS meta in footer
 * 
 * @return void
 * @since 1.0
 */
function wt_exempt_js_meta() { 
	$merchant_name = SST()->get_option( 'company_name' );
	$dir_url = SST()->plugin_url();
	$allow_blanket_certificates = is_user_logged_in();

	if ( is_checkout() ) {
		echo "
		<script type='text/javascript'>
			var wt_exempt_params = {
				merchantName: '$merchant_name',
				clickTarget: 'wootax_exemption_link',
				useBlanket: '$allow_blanket_certificates',
				pluginPath: '$dir_url',
			};
		</script>";
	}
}

add_action( 'wp_footer', 'wt_exempt_js_meta', 1 );

/**
 * Displays link to certificate management interface on the checkout page
 *
 * @return void
 * @since 1.0
 */
function maybe_display_exemption_link() {
	global $current_user;

	$restricted   = SST()->get_option( 'restrict_exempt' ) == 'yes' ? true : false;
	$exempt_roles = is_array( SST()->get_option( 'exempt_roles' ) ) ? SST()->get_option( 'exempt_roles' ) : array();

	if ( SST()->get_option( 'show_exempt' ) == 'true' ) {
		if ( $restricted === true && ( !is_user_logged_in() || count( array_intersect( $exempt_roles, $current_user->roles ) ) == 0 ) ) {
			return;
		}

		$raw_link_text = trim( SST()->get_option( 'exemption_text' ) );
		$link_text = empty( $raw_link_text ) ? 'Click here to add or apply an exemption certificate.' : $raw_link_text;

		$notice = 'Are you a tax exempt customer? <span id="wootax_exemption_link"><a href="#" style="text-decoration: none;">'. $link_text .'</a></span>';
		
		// Use class "woocommerce-message" for 2.1.x and 2.3.x; use "woocommerce-message" for 2.2.x
		// If this isn't done properly, the exemption certificate confirmation message isn't displayed properly
		$message_class = version_compare( WOOCOMMERCE_VERSION, '2.2', '>=' ) && version_compare( WOOCOMMERCE_VERSION, '2.3', '<' ) ? 'woocommerce-info' : 'woocommerce-message';

		echo "<div class='woocommerce-info'>$notice</div>";
		echo "<div class='$message_class' id='wooTaxApplied' style='". ( WC()->session->get( 'certificate_id' ) == null ? 'display: none !important;' : '' ) ."'>Exemption certificate applied (<a href='#' id='removeCert'>Remove</a>)</div>";
	}
}

add_action( 'woocommerce_before_checkout_form', 'maybe_display_exemption_link', 11 );

/**
 * Save or remove an exemption certificate via AJAX
 * Also handles setting the applied exemption certificate in the session 
 *
 * @return void
 * @since 1.0
 */
function ajax_update_exemption_certificate() {
	$action = esc_attr( $_POST['act'] );

	switch ( $action ) {
		case 'add':
			add_exemption_certificate();
			break;
		case 'remove':
			remove_exemption_certificate();
			break;
		case 'set': 
			set_exemption_certificate();
			break;
	}
}

add_action( 'wp_ajax_nopriv_wootax-update-certificate', 'ajax_update_exemption_certificate' );
add_action( 'wp_ajax_wootax-update-certificate', 'ajax_update_exemption_certificate' );

/**
 * Add exemption certificate
 *
 * @return void
 * @since 1.0
 */
function add_exemption_certificate() {
	$customer_id = WC()->session->get( 'wootax_customer_id' );

	// Construct certificate
	$state_abbr = sanitize_text_field( $_POST[ 'ExemptState' ] );
	
	$exempt_state = new \TaxCloud\ExemptState( $state_abbr, '', '' ); // StateAbbr, ReasonForExemption, IDNumber
	$exempt_states = array( $exempt_state );
	
	$single_purchase = sanitize_text_field( $_POST[ 'SinglePurchase' ] ) == 'false' ? false : true;
	$first_name = sanitize_text_field( $_POST['PurchaserFirstName'] );
	$last_name = sanitize_text_field( $_POST['PurchaserLastName'] );
	$title = sanitize_text_field( $_POST['PurchaserTitle'] );
	$address_1 = sanitize_text_field( $_POST['PurchaserAddress1'] );
	$city = sanitize_text_field( $_POST['PurchaserCity'] );
	$state = sanitize_text_field( $_POST['PurchaserState'] );
	$zip = sanitize_text_field( $_POST['PurchaserZip'] );
	$biz_type = sanitize_text_field( $_POST['PurchaserBusinessType'] );
	$biz_type_other_value = isset( $_POST['PurchaserBusinessTypeOtherValue'] ) ? sanitize_text_field( $_POST['PurchaserBusinessTypeOtherValue'] ) : NULL;
	$exemption_reason = sanitize_text_field( $_POST['PurchaserExemptionReason'] );
	$exemption_reason_value = sanitize_text_field( $_POST['PurchaserExemptionReasonValue'] );

	$tax_type = sanitize_text_field( $_POST['TaxType'] );
	$id_number = sanitize_text_field( $_POST['IDNumber'] );
	$state_of_issue = sanitize_text_field( $_POST['StateOfIssue'] );
	$tax_id = new \TaxCloud\TaxID( $tax_type, $id_number, $state_of_issue );

	$certificate = new \TaxCloud\ExemptionCertificate(
		$exempt_states,
		$single_purchase,
		NULL, // Single purchase order number not known at this time
		$first_name,
		$last_name,
		$title,
		$address_1,
		NULL, // Address 2 not collected
		$city,
		$state,
		$zip,
		$tax_id,
		$biz_type,
		$biz_type_other_value,
		$exemption_reason,
		$exemption_reason_value
	);

	// If this certificate will only be used for a single purchase, store it in the session; Else, send AddCertificate request to TaxCloud
	if ( true === $single_purchase ) {
		// Save cert to session for use during checkout
		WC()->session->set( 'certificate_data', $certificate );
		WC()->session->save_data();

		// Send back success response; for single certificates, this should trigger the lightbox to close and cause cart totals to be recalculated
		die( json_encode( array( 
			'status' => 'success', 
			'message' => ''
		) ) );
	} else {
		// Send request
		$tc_id = get_taxcloud_id();
		$tc_key = get_taxcloud_key();
		$client = new \TaxCloud\Client();
		$request = new \TaxCloud\Request\AddExemptCertificate( $tc_id, $tc_key, $customer_id, $certificate );

		try {
			$result = $client->AddExemptCertificate( $request );
			$certificate_id = $result->getAddExemptCertificateResult()->getCertificateID();

			// For blanket certificates, a success response should lead to a redirect to the "manage-certificates" lightbox
			die( json_encode( array( 
				'status'  => 'success', 
				'message' => 'Certificate ' . $certificate_id . ' saved successfully.' 
			) ) );
		} catch ( Exception $e ) {
			die( json_encode( array( 
				'status'  => 'error', 
				'message' => 'There was an error while saving this certificate: ' . $e->getMessage() 
			) ) );
		}
	}
}

/**
 * Remove exemption certificate
 *
 * @return void
 * @since 1.0
 */
function remove_exemption_certificate() {
	// Collect vars
	$certificate_id = esc_attr( $_POST['certificateID'] );
	// $single         = esc_attr( $_POST['single'] );

	// Fetch customer ID
	$customer_id = WC()->session->get( 'wootax_customer_id' );
	
	$tc_id = get_taxcloud_id();
	$tc_key = get_taxcloud_key();
	$client = new \TaxCloud\Client();
	$request = new \TaxCloud\Request\DeleteExemptCertificate( $tc_id, $tc_key, $certificate_id );

	try {
		$client->DeleteExemptCertificate( $request );

		die( json_encode( array( 
			'status'  => 'success', 
			'message' => 'Certificate ' . $certificate_id . ' removed successfully.' 
		) ) );
	} catch ( Exception $e ) {
		die( json_encode( array( 
			'status'  => 'error', 
			'message' => 'There was an error while removing this certificate: ' . $e->getMessage() 
		) ) );
	}

	// If this is a "single purchase" cert, we need to remove all certificates with the same OrderID
	// if ( $single == 'true' || intval( $single ) == 1 ) {		
	// 	$response = TaxCloud()->send_request( 'GetExemptCertificates', array( 'customerID' => $customer_id ) );

	// 	if ( $response !== false ) {
	// 		$certificates = $response->ExemptCertificates;
	// 		$duplicates = array();

	// 		// Dump certificates into object to be returned to client
	// 		if ( $certificates != NULL && is_object( $certificates ) ) {
	// 			foreach ( $certificates->ExemptionCertificate as $certificate ) {
	// 				// Add single purchase certificates to duplicate array
	// 				if ( $certificate->Detail->SinglePurchase == 1 ) {
	// 					$orderNum = $certificate->Detail->SinglePurchaseOrderNumber;
						
	// 					if ( !isset( $duplicates[$orderNum] ) || !is_array( $duplicates[$orderNum] ) )
	// 						$duplicates[ $orderNum ]   = array();
	// 						$duplicates[ $orderNum ][] = $certificate->CertificateID;
	// 					}
	// 				}
	// 			}

	// 			// Loop through dupes array; delete all exemption certificates that share the orderID of cert with ID certificateID
	// 			foreach ($duplicates as $dupes) {
	// 				if ( in_array( $certificate_id, $dupes ) ) {
	// 					foreach ( $dupes as $certID ) {
	// 						// Send request
	// 						$res = TaxCloud()->send_request( 'DeleteExemptCertificate', array( 'certificateID' => $certID ) );
							
	// 						// Check for errors
	// 						if ( $res == false ) {
	// 							die( json_encode( array( 
	// 								'status'  => 'error', 
	// 								'message' => 'There was an error while removing this certificate: ' . TaxCloud()->get_error_message() 
	// 							) ) );
	// 						}
	// 					}
	// 				}
	// 			}
				
	// 			die( json_encode( array( 
	// 				'status'  => 'success', 
	// 				'message' => 'Certificate ' . $certificate_id . ' removed successfully.' 
	// 			) ) );
	// 	} else {
	// 		die( json_encode( array( 
	// 			'status'  => 'error', 
	// 			'message' => 'There was an error while removing this certificate: ' . TaxCloud()->get_error_message() 
	// 		) ) );
	// 	}
	// } else {
	// 	// Send request
	// 	$res = TaxCloud()->send_request( 'DeleteExemptCertificate', array( 'certificateID' => $certificate_id ) );

	// 	// Check for errors
	// 	if ( $res !== false ) {
	// 		die( json_encode( array( 
	// 			'status'  => 'success', 
	// 			'message' => 'Certificate ' . $certificate_id . ' removed successfully.' 
	// 		) ) );
	// 	} else {
	// 		die( json_encode( array( 
	// 			'status'  => 'error', 
	// 			'message' => 'There was an error while removing this certificate: ' . TaxCloud()->get_error_message() 
	// 		) ) );
	// 	}
	// }
}

/**
 * Set exemption certificate in session
 *
 * @param $certID a certificate ID (optional)
 * @return void
 * @since 1.0
 */
function set_exemption_certificate( $certID = null ) {
	$cert = !empty( $certID ) ? $certID : ( isset( $_POST['cert'] ) ? esc_attr( $_POST['cert'] ) : null );
	
	// Set certID (empty if we are removing the currently applied certificate)
	WC()->session->set( 'certificate_id', $cert );

	// If we are removing the currently applied certificate, reset "certificate_data" and "certificate_applied" session variables
	// Also, set "cert_removed" to true (this way we dont auto-apply for exempt user if they happen to remove)
	if ( empty( $cert ) ) {
		WC()->session->set( 'certificate_data', null );
		WC()->session->set( 'certificate_applied', null );
		WC()->session->set( 'cert_removed', true );
	} 

	WC()->session->save_data();

	// Returning true will trigger the totals to update so WooTax applies the certificate
	if ( empty( $certID ) ) {
		die( true );
	} else {
		return true;
	}
}

/**
 * Get all exemption certificates for a user given their username
 *
 * @return an array of exemption certificates
 * @since 1.0
 */
function get_user_exemption_certs( $user_login ) {
	if ( empty( $user_login ) ) {
		return array();
	}

	$tc_id = get_taxcloud_id();
	$tc_key = get_taxcloud_key();
	$client = new \TaxCloud\Client();
	$request = new \TaxCloud\Request\GetExemptCertificates( $tc_id, $tc_key, $user_login );

	try {
		$result = $client->GetExemptCertificates( $request );
		$certificates = $result->getExemptCertificatesResult()->getExemptCertificates();

		if ( is_array( $certificates->ExemptionCertificate ) ) {
			return $certificates->ExemptionCertificate;
		} else {
			return array( $certificates->ExemptionCertificate );
		}
	} catch ( Exception $e ) {
		// Could not fetch certs; return empty array
		return array();
	}

	// Send GetExemptCertificates request
	// $response = TaxCloud()->send_request( 'GetExemptCertificates', array( 'customerID' => $user_login ) );

	// if ( $response !== false ) {
	// 	$certificate_result = is_object( $response->ExemptCertificates ) && isset( $response->ExemptCertificates->ExemptionCertificate ) ? $response->ExemptCertificates->ExemptionCertificate : NULL;

	// 	$final_certificates = array();
		
	// 	if ( $certificate_result != NULL ) {
	// 		// Convert response to array if only a single certificate is returned
	// 		if ( !is_array( $certificate_result ) ) {
	// 			$certificate_result = array( $certificate_result );
	// 		}

	// 		// Dump certificates into object to be returned to client
	// 		$certificates = $duplicates = array();

	// 		if ( is_array( $certificate_result ) ) {
	// 			foreach ( $certificate_result as $certificate ) {
	// 				// Add this certificate to the cert_list array
	// 				$certificates[] = $certificate;

	// 				// Add single purchase certificates to duplicate array
	// 				if ( $certificate->Detail->SinglePurchase == 1 ) {
	// 					$order_number = $certificate->Detail->SinglePurchaseOrderNumber;

	// 					if ( !isset( $duplicates[$order_number] ) || !is_array( $duplicates[$order_number] ) ) {
	// 						$duplicates[$order_number] = array();
	// 					}

	// 					$duplicates[$order_number][] = $certificate->CertificateID;
	// 				}
	// 			}
	// 		}

	// 		// Isolate single certificates that should be kept
	// 		if ( count( $duplicates ) > 0 ) {
	// 			foreach ( $duplicates as &$dupes ) {
	// 				if ( count( $dupes ) > 1 ) {
	// 					$x = count( $dupes );

	// 					while( count( $dupes ) > 1 ) {
	// 						unset( $dupes[$x] );
	// 						$x--;
	// 					}
	// 				}
	// 			}
	// 		}

	// 		// Loop through cert_list and construct filtered cert_list array (duplicate single certificates removed)
	// 		foreach ( $certificates as $cert ) {
	// 			if ( !is_object( $cert ) ) {
	// 				continue;
	// 			}

	// 			$keep = false;

	// 			if ( $cert->Detail->SinglePurchase == true && is_array( $duplicates[$cert->Detail->SinglePurchaseOrderNumber] ) && in_array( $cert->CertificateID, $duplicates[$cert->Detail->SinglePurchaseOrderNumber] ) ) {
	// 				$keep = true;
	// 			} elseif ( $cert->Detail->SinglePurchase == true && !is_array( $duplicates[$cert->Detail->SinglePurchaseOrderNumber] ) || $cert->Detail->SinglePurchase == false ) {
	// 				$keep = true;
	// 			} 

	// 			if ( $keep ) {
	// 				$final_certificates[] = $cert;
	// 			}
	// 		}
	// 	}

	// 	return $final_certificates;
	// } else {
	// 	return array();
	// }
}

/**
 * List exemption certificates for a given customer
 *
 * @return JSONP object with exemption certificates
 * @since 1.0
 */
function ajax_list_exemption_certificates() {
	global $current_user;

	$customer_id = is_user_logged_in() ? $current_user->user_login : '';

	if ( $customer_id ) {
		$certificates = get_user_exemption_certs( $customer_id );

		if ( count( $certificates ) > 0 ) {
			$final_certificates = new stdClass();
			$final_certificates->cert_list = $certificates;

			// Convert to JSON and return
			die( json_encode( $final_certificates ) );
		} else {
			die( '{cert_list:[]}' );
		}
	} else {
		die( '{cert_list:[]}' );
	}
}

add_action( 'wp_ajax_nopriv_wootax-list-certificates', 'ajax_list_exemption_certificates' );
add_action( 'wp_ajax_wootax-list-certificates', 'ajax_list_exemption_certificates' );

/**
 * Apply exemption certificate automatically for customers marked as exempt
 *
 * @return void
 * @since 1.0
 */
function maybe_apply_exemption_certificate() {
	global $current_user;

	$exempt_roles = SST()->get_option( 'exempt_roles' );

	if ( is_object( WC()->session ) && !WC()->session->get( 'certificate_id' ) && !WC()->session->get( 'cert_removed' ) && in_array( site_url( $_SERVER['REQUEST_URI'] ), array( get_permalink( wc_get_page_id( 'cart' ) ), get_permalink( wc_get_page_id( 'checkout' ) ) ) ) ) {
		if ( count( array_intersect( $exempt_roles, $current_user->roles ) ) > 0 ) {
			// Get all certs
			$certs = get_user_exemption_certs( $current_user->user_login );
	
			// Find ID of first blanket cert
			$first_id = -1;

			foreach ( $certs as $cert ) {
				if ( is_object( $cert ) && $cert->Detail->SinglePurchase !== true ) {
					$first_id = $cert->CertificateID;
					break;
				}
			}

			// Apply cert
			if ( $first_id != -1 ) {
				set_exemption_certificate( $first_id );
			}
		}
	}
}

add_action( 'init', 'maybe_apply_exemption_certificate' );

/**
 * Load an exemption management template via AJAX
 *
 * @return void
 * @since 1.0
 */
function ajax_load_exemption_template() {
	$template = urldecode( $_GET[ 'template' ] );

	// Parse out query string
	$querystr = "";
	$questpos = strpos( $template, '?' );

	if ( $questpos !== false ) {
		$querystr = substr( $template, $questpos );
		$template = substr( $template, 0, $questpos );
	}

	// Use cURL to load file contents (not sure how else to support query strings)
	$ch = curl_init( SST()->plugin_url() . '/templates/lightbox/' . $template . '.php' . $querystr );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_USERAGENT, 'Simple Sales Tax' );
	$content = curl_exec( $ch );
	curl_close( $ch );

	die( wt_do_template_substitutions( $content ) );
}

add_action( 'wp_ajax_nopriv_wootax-load-template', 'ajax_load_exemption_template' );
add_action( 'wp_ajax_wootax-load-template', 'ajax_load_exemption_template' );

/**
 * Perform template substitutions
 * 
 * @param (string) $cotent - template content before substitutions
 * @return (string) template content after substitutions
 * @since 1.0
 */
function wt_do_template_substitutions( $content ) {
	return str_replace( array( '{PLUGIN_PATH}', '{COMPANY_NAME}' ), array( SST()->plugin_url(), SST()->get_option( 'company_name' ) ), $content );	
}

/**
 * Associate certificate with an order when it is created
 *
 * @param (int) $order_id - ID of order being created
 * @return void
 * @since 1.0
 */
function wt_store_order_certificate( $order_id ) {
	$exempt_cert = false;

	if ( WC()->session->get( 'certificate_id' ) ) {
		if ( WC()->session->get( 'certificate_id' ) == 'true' ) {
			// Single use cert
			$exempt_cert = WC()->session->get( 'certificate_data' );

			if ( !isset( $exempt_cert['Detail']['SinglePurchaseOrderNumber'] ) ) {
				$exempt_cert['Detail']['SinglePurchaseOrderNumber'] = $order_id;
			}
		} else {
			// Blanket cert
			$exempt_cert = array(
				'CertificateID' => WC()->session->get( 'certificate_id' ),
			);
		}

		WT_Orders::update_meta( $order_id, 'exemption_applied', $exempt_cert );
	}
}

add_action( 'wt_persist_session_data', 'wt_store_order_certificate', 10, 1 );

/**
 * Delete session data related to exemption cert when checkout is complete
 *
 * @return void
 * @since 1.0
 */
function wt_delete_certificate_data() {
	WC()->session->set( 'certificate_id', '' );
	WC()->session->set( 'certificate_applied', '' );
	WC()->session->set( 'certificate_data', '' );
	WC()->session->set( 'exemption_applied', '' );
}

add_action( 'wt_delete_session_data', 'wt_delete_certificate_data' );

/**
 * Enqueue JS/CSS for exemption management interface
 */
function enqueue_checkout_scripts() {
	if ( !is_admin() && is_checkout() ) {
		// Enqueue Magnific Popup
		wp_enqueue_style( 'mpop-css', SST()->plugin_url() .'/css/magnificPopup.css' );
		wp_enqueue_script( 'mpop-js', SST()->plugin_url() .'/js/magnificPopup.js', array( 'jquery' ), '1.0', true );

		// Enqueue exemption JS
		$exempt_js = SST()->plugin_url() .'/js/certificate-manager.js?t='. time();
		wp_enqueue_script( 'exempt-js', $exempt_js, array( 'jquery', 'mpop-js' ), '1.0', true );
	}
}

add_action( 'wp_enqueue_scripts', 'enqueue_checkout_scripts', 20 );