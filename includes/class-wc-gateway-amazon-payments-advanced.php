<?php
/**
 * Gateway class.
 *
 * @package WC_Gateway_Amazon_Pay
 */

/**
 * Implement payment method for Amazon Pay.
 */
class WC_Gateway_Amazon_Payments_Advanced extends WC_Payment_Gateway {

	/**
	 * Amazon Order Reference ID (when not in "login app" mode checkout)
	 *
	 * @var string
	 */
	protected $reference_id;

	/**
	 * Amazon Pay Access Token ("login app" mode checkout)
	 *
	 * @var string
	 */
	protected $access_token;

	/**
	 * Amazon Private Key
	 *
	 * @var string
	 */
	protected $private_key;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->method_title         = __( 'Amazon Pay', 'woocommerce-gateway-amazon-payments-advanced' );
		$this->method_description   = __( 'Amazon Pay is embedded directly into your existing web site, and all the buyer interactions with Amazon Pay and Login with Amazon take place in embedded widgets so that the buyer never leaves your site. Buyers can log in using their Amazon account, select a shipping address and payment method, and then confirm their order. Requires an Amazon Pay seller account and supports USA, UK, Germany, France, Italy, Spain, Luxembourg, the Netherlands, Sweden, Portugal, Hungary, Denmark, and Japan.', 'woocommerce-gateway-amazon-payments-advanced' );
		$this->id                   = 'amazon_payments_advanced';
		$this->icon                 = apply_filters( 'woocommerce_amazon_pa_logo', plugins_url( 'assets/images/amazon-payments.png', plugin_dir_path( __FILE__ ) ) );
		$this->debug                = ( 'yes' === $this->get_option( 'debug' ) );
		$this->view_transaction_url = $this->get_transaction_url_format();
		$this->supports             = array(
			'products',
			'refunds',
		);
		$this->private_key          = get_option( WC_Amazon_Payments_Advanced_Merchant_Onboarding_Handler::KEYS_OPTION_PRIVATE_KEY );

		// Load multicurrency fields if compatibility. (Only on settings admin)
		if ( is_admin() ) {
			$compatible_region = isset( $_POST['woocommerce_amazon_payments_advanced_payment_region'] ) ? WC_Amazon_Payments_Advanced_Multi_Currency::compatible_region( $_POST['woocommerce_amazon_payments_advanced_payment_region'] ) : WC_Amazon_Payments_Advanced_Multi_Currency::compatible_region();
			if ( $compatible_region && WC_Amazon_Payments_Advanced_Multi_Currency::get_compatible_instance( $compatible_region ) ) {
				$this->add_currency_fields();
			}
		}

		// Load the settings.
		$this->init_settings();

		// Load saved settings.
		$this->load_settings();

		// Load the form fields.
		$this->init_form_fields();

		// Get Order Refererence ID and/or Access Token.
		$this->reference_id = WC_Amazon_Payments_Advanced_API::get_reference_id();
		$this->access_token = WC_Amazon_Payments_Advanced_API::get_access_token();

		// Handling for the review page of the German Market Plugin.
		if ( empty( $this->reference_id ) ) {
			if ( isset( $_SESSION['first_checkout_post_array']['amazon_reference_id'] ) ) {
				$this->reference_id = $_SESSION['first_checkout_post_array']['amazon_reference_id'];
			}
		}

		// Filter order received text for timedout transactions.
		add_filter( 'woocommerce_thankyou_order_received_text', array( $this, 'maybe_render_timeout_transaction_order_received_text' ), 10, 2 );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'validate_api_keys' ) );
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'store_shipping_info_in_session' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'check_customer_coupons' ) );
		add_action( 'wc_amazon_async_authorize', array( $this, 'process_payment_with_async_authorize' ), 10, 2 );
		add_action( 'woocommerce_after_settings_checkout', array( $this, 'import_export_fields_output' ) );
		add_action( 'admin_init', array( $this, 'process_settings_export' ) );
		add_action( 'admin_init', array( $this, 'process_settings_import' ) );

		add_action( 'wp_loaded', array( $this, 'init_handlers' ), 11 );

		add_filter( 'woocommerce_ajax_get_endpoint', array( $this, 'filter_ajax_endpoint' ), 10, 2 );

		add_action( 'wp_footer', array( $this, 'maybe_hide_standard_checkout_button' ) );
		add_action( 'wp_footer', array( $this, 'maybe_hide_amazon_buttons' ) );

		// AJAX calls to get updated order reference details
		add_action( 'wp_ajax_amazon_get_order_reference', array( $this, 'ajax_get_order_reference' ) );
		add_action( 'wp_ajax_nopriv_amazon_get_order_reference', array( $this, 'ajax_get_order_reference' ) );

		// Add SCA processing and redirect.
		add_action( 'template_redirect', array( $this, 'handle_sca_url_processing' ), 10, 2 );

		// SCA Strong Customer Authentication Upgrade.
		add_action( 'wp_ajax_amazon_sca_processing', array( $this, 'ajax_sca_processing' ) );
		add_action( 'wp_ajax_nopriv_amazon_sca_processing', array( $this, 'ajax_sca_processing' ) );

		// Log out from Amazon handlers
		add_action( 'woocommerce_thankyou_amazon_payments_advanced', array( $this, 'logout_from_amazon' ) );
		$this->maybe_attempt_to_logout();

		// Declined notice
		$this->maybe_display_declined_notice();
	}

	/**
	 * Get transaction URL format.
	 *
	 * @since 1.6.0
	 *
	 * @return string URL format
	 */
	public function get_transaction_url_format() {
		$url = 'https://sellercentral.amazon.com';

		$eu_countries = WC()->countries->get_european_union_countries();
		$base_country = WC()->countries->get_base_country();

		if ( in_array( $base_country, $eu_countries ) ) {
			$url = 'https://sellercentral-europe.amazon.com';
		} elseif ( 'JP' === $base_country ) {
			$url = 'https://sellercentral-japan.amazon.com';
		}

		$url .= '/hz/me/pmd/payment-details?orderReferenceId=%s';

		return apply_filters( 'woocommerce_amazon_pa_transaction_url_format', $url );
	}

	/**
	 * Amazon Pay is available if the following conditions are met (on top of
	 * WC_Payment_Gateway::is_available).
	 *
	 * 1) Login App mode is enabled and we have an access token from Amazon
	 * 2) Login App mode is *not* enabled and we have an order reference id
	 * 3) In checkout pay page.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) {
			return parent::is_available();
		}

		$login_app_enabled = ( 'yes' === $this->enable_login_app );
		$standard_mode_ok  = ( ! $login_app_enabled && ! empty( $this->reference_id ) );
		$login_app_mode_ok = ( $login_app_enabled && ! empty( $this->access_token ) );

		return ( parent::is_available() && ( $standard_mode_ok || $login_app_mode_ok ) );
	}

	/**
	 * Has fields.
	 *
	 * @return bool
	 */
	public function has_fields() {
		return is_checkout_pay_page();
	}

	/**
	 * Payment form on checkout page
	 */
	public function payment_fields() {
		if ( $this->has_fields() ) : ?>

			<?php if ( empty( $this->reference_id ) && empty( $this->access_token ) ) : ?>
				<div>
					<div id="pay_with_amazon"></div>
					<?php echo apply_filters( 'woocommerce_amazon_pa_checkout_message', __( 'Have an Amazon account?', 'woocommerce-gateway-amazon-payments-advanced' ) ); ?>
				</div>
			<?php else : ?>
				<div class="wc-amazon-payments-advanced-order-day-widgets">
					<div id="amazon_wallet_widget"></div>
					<div id="amazon_consent_widget"></div>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $this->reference_id ) ) : ?>
				<input type="hidden" name="amazon_reference_id" value="<?php echo esc_attr( $this->reference_id ); ?>" />
			<?php endif; ?>
			<?php if ( ! empty( $this->access_token ) ) : ?>
				<input type="hidden" name="amazon_access_token" value="<?php echo esc_attr( $this->access_token ); ?>" />
			<?php endif; ?>

		<?php endif;
	}

	/**
	 * Admin Panel Options
	 * - Options for bits like 'title' and availability on a country-by-country basis
	 */
	public function admin_options() {
		?>
		<h2>
			<?php
			echo esc_html( $this->get_method_title() );
			wc_back_link( __( 'Return to payments', 'woocommerce' ), admin_url( 'admin.php?page=wc-settings&tab=checkout' ) );
			?>
		</h2>
		<table class="form-table">
			<?php $this->generate_settings_html(); ?>
		</table><!--/.form-table-->
		<script>
		( function( $ ) {
			var dependentsToggler = {
				init: function() {
					$( '[data-dependent-selector]' )
						.on( 'change', this.toggleDependents )
						.trigger( 'change' );
				},

				toggleDependents: function( e ) {
					var $toggler    = $( e.target ),
						$dependents = $( $toggler.data( 'dependent-selector' ) ),
						showCondition = $toggler.data( 'dependent-show-condition' );

					$dependents.closest( 'tr' ).toggle( $toggler.is( showCondition ) );
				}
			};

			function run() {
				dependentsToggler.init();
			}

			$( run );
		} )( jQuery );
		</script>
		<?php
	}

	/**
	 * Init payment gateway form fields
	 */
	public function init_form_fields() {

		$login_app_setup_url = WC_Amazon_Payments_Advanced_API::get_client_id_instructions_url();
		/* translators: Login URL */
		$label_format           = __( 'This option makes the plugin to work with the latest API from Amazon, this will enable support for Subscriptions and make transactions more securely. <a href="%s" target="_blank">You must create a Login with Amazon App to be able to use this option.</a>', 'woocommerce-gateway-amazon-payments-advanced' );
		$label_format           = wp_kses(
			$label_format,
			array(
				'a' => array(
					'href'   => array(),
					'target' => array(),
				),
			)
		);
		$enable_login_app_label = sprintf( $label_format, $login_app_setup_url );
		$redirect_url           = add_query_arg( 'amazon_payments_advanced', 'true', get_permalink( wc_get_page_id( 'checkout' ) ) );
		$valid                  = isset( $this->settings['amazon_keys_setup_and_validated'] ) ? $this->settings['amazon_keys_setup_and_validated'] : false;

		$connect_desc    = __( 'Register for a new Amazon Pay merchant account, or sign in with your existing Amazon Pay Seller Central credentials to complete the plugin upgrade and configuration', 'woocommerce-gateway-amazon-payments-advanced' );
		$connect_btn     = '<button class="register_now button-primary">' . __( 'Connect to Amazon Pay', 'woocommerce-gateway-amazon-payments-advanced' ) . '</button>';
		$disconnect_desc = __( 'In order to connect to a different account you need to disconect first, this will delete current Account Settings, you will need to go throught all the configuration process again', 'woocommerce-gateway-amazon-payments-advanced' );
		$disconnect_btn  = '<button class="delete-settings button-primary">' . __( 'Disconnect Amazon Pay', 'woocommerce-gateway-amazon-payments-advanced' ) . '</button>';

		$this->form_fields = array(
			'important_note'                => array(
				'title'       => __( 'Important note, before you sign up:', 'woocommerce-gateway-amazon-payments-advanced' ),
				'type'        => 'title',
				'description' => __( 'Before you start the registration, make sure you sign out of all Amazon accounts you might have. Use an email address that you have never used for any Amazon account.   If you have an Amazon Seller account (Selling on Amazon), sign out and use a different address to register your Amazon Payments account.', 'woocommerce-gateway-amazon-payments-advanced' ),
			),
			'payment_region'                => array(
				'title'       => __( 'Payment Region', 'woocommerce-gateway-amazon-payments-advanced' ),
				'type'        => 'select',
				'description' => '',
				'default'     => WC_Amazon_Payments_Advanced_API::get_payment_region_from_country( WC()->countries->get_base_country() ),
				'options'     => WC_Amazon_Payments_Advanced_API::get_payment_regions(),
			),
			'register_now'                  => array(
				'title'       => __( 'Connect your Amazon Pay merchant account', 'woocommerce-gateway-amazon-payments-advanced' ),
				'type'        => 'title',
				'description' => $this->private_key ? $disconnect_desc . '<br/><br/>' . $disconnect_btn : $connect_desc . '<br/><br/>' . $connect_btn,
			),
			'enabled'                       => array(
				'title'       => __( 'Enable/Disable', 'woocommerce-gateway-amazon-payments-advanced' ),
				'label'       => __( 'Enable Amazon Pay &amp; Login with Amazon', 'woocommerce-gateway-amazon-payments-advanced' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'yes',
			),
			'account_details'               => array(
				'title'       => __( 'Amazon Pay Merchant account details', 'woocommerce-gateway-amazon-payments-advanced' ),
				'type'        => 'title',
				'description' => '',
			),
			'manual_notice'                 => array(
				'type'        => 'custom',
				'html'        => '<p>Problems with automatic setup? <a href="#" class="wcapa-toggle-section" data-toggle="#manual-settings-container, #automatic-settings-container">Click here</a> to manually enter your keys.</p>',
			),
			'manual_container_start'        => array(
				'type'        => 'custom',
				'html'        => '<div id="manual-settings-container" class="hidden">',
			),
			'keys_json'                     => array(
				'title'       => __( 'Manual Keys JSON', 'woocommerce-gateway-amazon-payments-advanced' ),
				'type'        => 'file',
				'description' => __( 'JSON format, retrieve the JSON clicking the "Download JSON file" button in Seller Central under "INTEGRATION- Central - Existing API keys', 'woocommerce-gateway-amazon-payments-advanced' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'private_key'                   => array(
				'title'       => __( 'Private Key File', 'woocommerce-gateway-amazon-payments-advanced' ),
				'label'       => __( 'Add .pem file with the private key generated in the Amazon seller Central', 'woocommerce-gateway-amazon-payments-advanced' ),
				'type'        => 'file',
				'description' => __( 'This key is created automatically when you Connect your Amazon Pay merchant account form the Configure button, but can be created by logging into Seller Central and create keys in INTEGRATION - Central', 'woocommerce-gateway-amazon-payments-advanced' ),
				'desc_tip'    => true,
			),
			'manual_container_end'          => array(
				'type'        => 'custom',
				'html'        => '</div>',
			),
			'default_container_start'        => array(
				'type'        => 'custom',
				'html'        => '<div id="automatic-settings-container">',
			),
			'merchant_id'                   => array(
				'title'       => __( 'Merchant ID', 'woocommerce-gateway-amazon-payments-advanced' ),
				'type'        => 'text',
				'description' => __( 'Obtained from your Amazon account. Usually found on Integration central after logging into your merchant account.', 'woocommerce-gateway-amazon-payments-advanced' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'store_id'                      => array(
				'title'       => __( 'Store ID', 'woocommerce-gateway-amazon-payments-advanced' ),
				'type'        => 'text',
				'description' => __( 'Obtained from your Amazon account. Usually found on Integration central after logging into your merchant account.', 'woocommerce-gateway-amazon-payments-advanced' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'public_key_id'                 => array(
				'title'       => __( 'Public Key Id', 'woocommerce-gateway-amazon-payments-advanced' ),
				'type'        => 'text',
				'description' => __( 'Obtained from your Amazon account. You can get these keys by logging into Seller Central and clicking the "See Details" button under INTEGRATION - Central - Existing API keys.', 'woocommerce-gateway-amazon-payments-advanced' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'default_container_end'          => array(
				'type'        => 'custom',
				'html'        => '</div>',
			),
			'enable_login_app'              => array(
				'title'             => __( 'Use Login with Amazon App', 'woocommerce-gateway-amazon-payments-advanced' ),
				'label'             => $enable_login_app_label,
				'type'              => 'checkbox',
				'description'       => '',
				'default'           => 'yes',
				'custom_attributes' => array(
					'data-dependent-selector'       => '.show-if-app-is-enabled',
					'data-dependent-show-condition' => ':checked',
				),
			),
			'sandbox'                       => array(
				'title'       => __( 'Use Sandbox', 'woocommerce-gateway-amazon-payments-advanced' ),
				'label'       => __( 'Enable sandbox mode during testing and development - live payments will not be taken if enabled.', 'woocommerce-gateway-amazon-payments-advanced' ),
				'type'        => 'select',
				'description' => '',
				'default'     => 'no',
				'options'     => array(
					'yes' => __( 'Yes', 'woocommerce-gateway-amazon-payments-advanced' ),
					'no'  => __( 'No', 'woocommerce-gateway-amazon-payments-advanced' ),
				),
			),
			'subscriptions_enabled'         => array(
				'title'       => __( 'Subscriptions support', 'woocommerce-gateway-amazon-payments-advanced' ),
				'label'       => __( 'Enable Subscriptions for carts that contain Subscription items (requires WooCommerce Subscriptions to be installed)', 'woocommerce-gateway-amazon-payments-advanced' ),
				'type'        => 'select',
				'description' => __( 'This will enable support for Subscriptions and make transactions more securely', 'woocommerce-gateway-amazon-payments-advanced' ),
				'default'     => 'yes',
				'options'     => array(
					'yes' => __( 'Yes', 'woocommerce-gateway-amazon-payments-advanced' ),
					'no'  => __( 'No', 'woocommerce-gateway-amazon-payments-advanced' ),
				),
			),
			'advanced_configuration'        => array(
				'title'       => __( 'Advanced configurations', 'woocommerce-gateway-amazon-payments-advanced' ),
				'type'        => 'title',
				'description' => sprintf(
					/* translators: Merchant URL to copy and paste */
					__( 'To process payment the Optimal way complete your Seller Central configuration on plugin setup. From your seller Central home page, go to “Settings – Integration Settings”. In this page, please paste the URL below to the “Merchant URL” input field. <br /><code>%1$s</code>', 'woocommerce-gateway-amazon-payments-advanced' ),
					wc_apa()->ipn_handler->get_notify_url()
				),
			),
			'payment_capture'               => array(
				'title'       => __( 'Payment Capture', 'woocommerce-gateway-amazon-payments-advanced' ),
				'type'        => 'select',
				'description' => '',
				'default'     => '',
				'options'     => array(
					''          => __( 'Authorize and Capture the payment when the order is placed.', 'woocommerce-gateway-amazon-payments-advanced' ),
					'authorize' => __( 'Authorize the payment when the order is placed.', 'woocommerce-gateway-amazon-payments-advanced' ),
					'manual'    => __( 'Don’t Authorize the payment when the order is placed (i.e. for pre-orders).', 'woocommerce-gateway-amazon-payments-advanced' ),
				),
			),
			'authorization_mode'            => array(
				'title'       => __( 'Authorization processing mode', 'woocommerce-gateway-amazon-payments-advanced' ),
				'type'        => 'select',
				'description' => '',
				'default'     => 'sync',
				'options'     => array(
					'sync'  => __( 'Synchronous', 'woocommerce-gateway-amazon-payments-advanced' ),
					'async' => __( 'Asynchronous', 'woocommerce-gateway-amazon-payments-advanced' ),
				),
			),
			'display_options'               => array(
				'title'       => __( 'Display options', 'woocommerce-gateway-amazon-payments-advanced' ),
				'description' => __( 'Customize the appearance of Amazon widgets.', 'woocommerce-gateway-amazon-payments-advanced' ),
				'type'        => 'title',
			),
			'button_language'               => array(
				'title'       => __( 'Button language', 'woocommerce-gateway-amazon-payments-advanced' ),
				'description' => __( 'Language to use in Login with Amazon or a Amazon Pay button. Only used when Login with Amazon App is enabled.', 'woocommerce-gateway-amazon-payments-advanced' ),
				'desc_tip'    => true,
				'type'        => 'select',
				'class'       => 'show-if-app-is-enabled',
				'default'     => '',
				'options'     => array(
					''      => __( 'Detect from buyer\'s browser', 'woocommerce-gateway-amazon-payments-advanced' ),
					'en-GB' => __( 'UK English', 'woocommerce-gateway-amazon-payments-advanced' ),
					'de-DE' => __( 'Germany\'s German', 'woocommerce-gateway-amazon-payments-advanced' ),
					'fr-FR' => __( 'France\'s French', 'woocommerce-gateway-amazon-payments-advanced' ),
					'it-IT' => __( 'Italy\'s Italian', 'woocommerce-gateway-amazon-payments-advanced' ),
					'es-ES' => __( 'Spain\'s Spanish', 'woocommerce-gateway-amazon-payments-advanced' ),
				),
			),
			'button_color'                  => array(
				'title'       => __( 'Button color', 'woocommerce-gateway-amazon-payments-advanced' ),
				'description' => __( 'Button color to display on cart and checkout pages. Only used when Login with Amazon App is enabled.', 'woocommerce-gateway-amazon-payments-advanced' ),
				'desc_tip'    => true,
				'type'        => 'select',
				'class'       => 'show-if-app-is-enabled',
				'default'     => 'Gold',
				'options'     => array(
					'Gold'      => __( 'Gold', 'woocommerce-gateway-amazon-payments-advanced' ),
					'LightGray' => __( 'Light gray', 'woocommerce-gateway-amazon-payments-advanced' ),
					'DarkGray'  => __( 'Dark gray', 'woocommerce-gateway-amazon-payments-advanced' ),
				),
			),
			'hide_standard_checkout_button' => array(
				'title'   => __( 'Standard checkout button', 'woocommerce-gateway-amazon-payments-advanced' ),
				'type'    => 'checkbox',
				'label'   => __( 'Hide standard checkout button on cart page', 'woocommerce-gateway-amazon-payments-advanced' ),
				'default' => 'no',
			),
			'misc_options'                  => array(
				'title' => __( 'Miscellaneous', 'woocommerce-gateway-amazon-payments-advanced' ),
				'type'  => 'title',
			),
			'debug'                         => array(
				'title'       => __( 'Debug', 'woocommerce-gateway-amazon-payments-advanced' ),
				'label'       => __( 'Enable debugging messages', 'woocommerce-gateway-amazon-payments-advanced' ),
				'type'        => 'checkbox',
				'description' => __( 'Sends debug messages to the WooCommerce System Status log.', 'woocommerce-gateway-amazon-payments-advanced' ),
				'desc_tip'    => true,
				'default'     => 'yes',
			),
			'hide_button_mode'              => array(
				'title'       => __( 'Hide Button Mode', 'woocommerce-gateway-amazon-payments-advanced' ),
				'label'       => __( 'Enable hide button mode', 'woocommerce-gateway-amazon-payments-advanced' ),
				'description' => __( 'This will hides Amazon buttons on cart and checkout pages so the gateway looks not available to the customers. The buttons are hidden via CSS. Only enable this when troubleshooting your integration.', 'woocommerce-gateway-amazon-payments-advanced' ),
				'desc_tip'    => true,
				'type'        => 'checkbox',
				'default'     => 'no',
			),
		);

		if ( ! empty( $this->settings['seller_id'] ) ) {
			$this->form_fields = array_merge(
				$this->form_fields,
				array(
					'account_details_v1'       => array(
						'title'       => __( 'Previous Version Configuration Details', 'woocommerce-gateway-amazon-payments-advanced' ),
						'type'        => 'title',
						'description' => 'These credentials and settings are read-only and cannot be modified. You must complete the plugin upgrade as instructed at the top of the page in order to make any changes. <a href="#" class="wcapa-toggle-section"  data-toggle="#v1-settings-container">Toggle visibility</a>.',
					),
					'container_start'       => array(
						'type'        => 'custom',
						'html'        => '<div id="v1-settings-container" class="hidden">'
					),
					'seller_id'                => array(
						'title'       => __( 'Seller ID', 'woocommerce-gateway-amazon-payments-advanced' ),
						'description' => $this->settings['seller_id'],
						'default'     => '',
						'type'        => 'hidden',
					),
					'mws_access_key'           => array(
						'title'       => __( 'MWS Access Key', 'woocommerce-gateway-amazon-payments-advanced' ),
						'description' => $this->settings['mws_access_key'],
						'default'     => '',
						'type'        => 'hidden',
					),
					'secret_key'               => array(
						'title'       => __( 'MWS Secret Key', 'woocommerce-gateway-amazon-payments-advanced' ),
						'type'        => 'hidden',
						'description' => __( 'Hidden secret key', 'woocommerce-gateway-amazon-payments-advanced' ),
						'default'     => '',
					),
					'app_client_id'            => array(
						'title'       => __( 'App Client ID', 'woocommerce-gateway-amazon-payments-advanced' ),
						'type'        => 'hidden',
						'description' => $this->settings['app_client_id'],
						'default'     => '',
					),
					'app_client_secret'        => array(
						'title'       => __( 'App Client Secret', 'woocommerce-gateway-amazon-payments-advanced' ),
						'type'        => 'hidden',
						'description' => __( 'Hidden secret key', 'woocommerce-gateway-amazon-payments-advanced' ),
						'default'     => '',
					),
					'redirect_authentication'  => array(
						'title'       => __( 'Login Authorization Mode', 'woocommerce-gateway-amazon-payments-advanced' ),
						'type'        => 'hidden',
						'description' => '<strong>' . $this->settings['redirect_authentication'] . '</strong><br>' . sprintf(
							/* translators: Redirect URL to copy and paste */
							__( 'Optimal mode requires setting the Allowed Return URLs and Allowed Javascript Origins in Amazon Seller Central. Click the Configure/Register Now button and sign in with your existing account to update the configuration and automatically set these values. If the URL is not added automatically to the Allowed Return URLs field in Amazon Seller Central, please copy and paste the one below manually. <br> <code>%1$s</code>' ),
							$redirect_url
						),
					),
					'cart_button_display_mode' => array(
						'title'       => __( 'Cart login button display', 'woocommerce-gateway-amazon-payments-advanced' ),
						'description' => '<strong>' . $this->settings['cart_button_display_mode'] . '</strong><br>' . __( 'How the login with Amazon button gets displayed on the cart page. This requires cart page served via HTTPS. If HTTPS is not available in cart page, please select disabled.', 'woocommerce-gateway-amazon-payments-advanced' ),
						'type'        => 'hidden',
						'default'     => 'button',
					),
					'button_type'              => array(
						'title'       => __( 'Button type', 'woocommerce-gateway-amazon-payments-advanced' ),
						'description' => '<strong>' . $this->settings['button_type'] . '</strong><br>' . __( 'Type of button image to display on cart and checkout pages. Only used when Amazon Login App is enabled.', 'woocommerce-gateway-amazon-payments-advanced' ),
						'type'        => 'hidden',
						'class'       => 'show-if-app-is-enabled',
					),
					'button_size'              => array(
						'title'       => __( 'Button size', 'woocommerce-gateway-amazon-payments-advanced' ),
						'description' => '<strong>' . $this->settings['button_size'] . '</strong><br>' . __( 'Button size to display on cart and checkout pages. Only used when Login with Amazon App is enabled.', 'woocommerce-gateway-amazon-payments-advanced' ),
						'type'        => 'hidden',
						'class'       => 'show-if-app-is-enabled',
					),
					'container_end'       => array(
						'type'        => 'custom',
						'html'        => '</div>'
					),
				)
			);
		}

		/**
		 * For new merchants "enforce" the use of LPA ( Hide "Use Login with Amazon App" and consider it ticked.)
		 * For old merchants, keep "Use Login with Amazon App" checkbox, as they can fallback to APA (no client id)
		 *
		 * @since 1.9.0
		 */
		if ( WC_Amazon_Payments_Advanced_API::is_new_installation() ) {
			unset( $this->form_fields['enable_login_app'] );
		}
	}

	/**
	 * Adds multicurrency settings to form fields.
	 */
	public function add_currency_fields() {
		$compatible_plugin = WC_Amazon_Payments_Advanced_Multi_Currency::compatible_plugin( true );

		$this->form_fields['multicurrency_options'] = array(
			'title'       => __( 'Multi-Currency', 'woocommerce-gateway-amazon-payments-advanced' ),
			'type'        => 'title',
			/* translators: Compatible plugin */
			'description' => sprintf( __( 'Multi-currency compatibility detected with <strong>%s</strong>', 'woocommerce-gateway-amazon-payments-advanced' ), $compatible_plugin ),
		);

		/**
		 * Only show currency list for plugins that will use the list. Frontend plugins will be exempt.
		 */
		if ( ! WC_Amazon_Payments_Advanced_Multi_Currency::$compatible_instance->is_front_end_compatible() ) {
			$this->form_fields['currencies_supported'] = array(
				'title'             => __( 'Select currencies to display Amazon in your shop', 'woocommerce-gateway-amazon-payments-advanced' ),
				'type'              => 'multiselect',
				'options'           => WC_Amazon_Payments_Advanced_API::get_supported_currencies( true ),
				'css'               => 'height: auto;',
				'custom_attributes' => array(
					'size' => 10,
					'name' => 'currencies_supported',
				),
			);
		}
	}

	/**
	 * Define user set variables.
	 */
	public function load_settings() {
		$settings = WC_Amazon_Payments_Advanced_API::get_settings();

		$this->title                   = $settings['title'];
		$this->payment_region          = $settings['payment_region'];
		$this->merchant_id             = $settings['merchant_id'];
		$this->store_id                = $settings['store_id'];
		$this->public_key_id           = $settings['public_key_id'];
		$this->seller_id               = $settings['seller_id'];
		$this->mws_access_key          = $settings['mws_access_key'];
		$this->secret_key              = $settings['secret_key'];
		$this->enable_login_app        = $settings['enable_login_app'];
		$this->app_client_id           = $settings['app_client_id'];
		$this->app_client_secret       = $settings['app_client_secret'];
		$this->sandbox                 = $settings['sandbox'];
		$this->payment_capture         = $settings['payment_capture'];
		$this->authorization_mode      = $settings['authorization_mode'];
		$this->redirect_authentication = $settings['redirect_authentication'];
	}

	/**
	 * Unset keys json box.
	 *
	 * @return bool|void
	 */
	public function process_admin_options() {
		if ( check_admin_referer( 'woocommerce-settings' ) ) {
			if ( isset( $_FILES['woocommerce_amazon_payments_advanced_keys_json'] ) && isset( $_FILES['woocommerce_amazon_payments_advanced_keys_json']['size'] ) && 0 < $_FILES['woocommerce_amazon_payments_advanced_keys_json']['size'] ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
				$json_file = $_FILES['woocommerce_amazon_payments_advanced_keys_json'];
				$this->process_settings_from_file( $json_file, true );
			}
			if ( isset( $_FILES['woocommerce_amazon_payments_advanced_private_key'] ) && isset( $_FILES['woocommerce_amazon_payments_advanced_private_key']['size'] ) && 0 < $_FILES['woocommerce_amazon_payments_advanced_private_key']['size'] ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
				$pem_file = $_FILES['woocommerce_amazon_payments_advanced_private_key'];

				$finfo = new finfo( FILEINFO_MIME_TYPE );
				$ext   = $finfo->file( $pem_file['tmp_name'] );
				if ( 'text/plain' === $ext && isset( $pem_file['tmp_name'] ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					$private_key = file_get_contents( $pem_file['tmp_name'] );
					$this->save_private_key( $private_key );
				}
			}
			parent::process_admin_options();
		}
	}

	/**
	 * Get the shipping address from Amazon and store in session.
	 *
	 * This makes tax/shipping rate calculation possible on AddressBook Widget selection.
	 *
	 * @since 1.0.0
	 * @version 1.8.0
	 */
	public function store_shipping_info_in_session() {
		if ( ! $this->reference_id ) {
			return;
		}

		$order_details = $this->get_amazon_order_details( $this->reference_id );

		// @codingStandardsIgnoreStart
		if ( ! $order_details || ! isset( $order_details->Destination->PhysicalDestination ) ) {
			return;
		}

		$address = WC_Amazon_Payments_Advanced_API::format_address( $order_details->Destination->PhysicalDestination );
		$address = $this->normalize_address( $address );
		// @codingStandardsIgnoreEnd

		foreach ( array( 'country', 'state', 'postcode', 'city' ) as $field ) {
			if ( ! isset( $address[ $field ] ) ) {
				continue;
			}

			$this->set_customer_info( $field, $address[ $field ] );
			$this->set_customer_info( 'shipping_' . $field, $address[ $field ] );
		}
	}

	/**
	 * Normalized address after formatted.
	 *
	 * @since 1.8.0
	 * @version 1.8.0
	 *
	 * @param array $address Address.
	 *
	 * @return array Address.
	 */
	private function normalize_address( $address ) {
		/**
		 * US postal codes comes back as a ZIP+4 when in "Login with Amazon App"
		 * mode.
		 *
		 * This is too specific for the local delivery shipping method,
		 * and causes the zip not to match, so we remove the +4.
		 */
		if ( 'US' === $address['country'] ) {
			$code_parts          = explode( '-', $address['postcode'] );
			$address['postcode'] = $code_parts[0];
		}

		$states = WC()->countries->get_states( $address['country'] );
		if ( empty( $states ) ) {
			return $address;
		}

		// State might be in city, so use that if state is not passed by
		// Amazon. But if state is available we still need the WC state key.
		$state = '';
		if ( ! empty( $address['state'] ) ) {
			$state = array_search( $address['state'], $states );
		}
		if ( ! $state && ! empty( $address['city'] ) ) {
			$state = array_search( $address['city'], $states );
		}
		if ( $state ) {
			$address['state'] = $state;
		}

		return $address;
	}

	/**
	 * Set customer info.
	 *
	 * WC 3.0.0 deprecates some methods in customer setter, especially for billing
	 * related address. This method provides compatibility to set customer billing
	 * info.
	 *
	 * @since 1.7.0
	 *
	 * @param string $setter_suffix Setter suffix.
	 * @param mixed  $value         Value to set.
	 */
	private function set_customer_info( $setter_suffix, $value ) {
		$setter             = array( WC()->customer, 'set_' . $setter_suffix );
		$is_shipping_setter = strpos( $setter_suffix, 'shipping_' ) !== false;

		if ( version_compare( WC_VERSION, '3.0', '>=' ) && ! $is_shipping_setter ) {
			$setter = array( WC()->customer, 'set_billing_' . $setter_suffix );
		}

		call_user_func( $setter, $value );
	}

	/**
	 * Check customer coupons after checkout validation.
	 *
	 * Since the checkout fields were hijacked and billing_email is not present,
	 * we need to call WC()->cart->check_customer_coupons again.
	 *
	 * @since 1.7.0
	 *
	 * @param array $posted Posted data.
	 */
	public function check_customer_coupons( $posted ) {
		if ( ! $this->reference_id ) {
			return;
		}

		$order_details = $this->get_amazon_order_details( $this->reference_id );
		// @codingStandardsIgnoreStart
		if ( ! $order_details || ! isset( $order_details->Buyer->Email ) ) {
			return;
		}

		if ( $this->id === $posted['payment_method'] ) {
			$posted['billing_email'] = empty( $posted['billing_email'] )
				? (string) $order_details->Buyer->Email
				: $posted['billing_email'];

			WC()->cart->check_customer_coupons( $posted );
		}
		// @codingStandardsIgnoreEnd
	}

	/**
	 * Process payment.
	 *
	 * @version 1.7.1
	 *
	 * @param int $order_id Order ID.
	 */
	public function process_payment( $order_id ) {
		$order               = wc_get_order( $order_id );
		$amazon_reference_id = isset( $_POST['amazon_reference_id'] ) ? wc_clean( $_POST['amazon_reference_id'] ) : '';

		try {
			if ( ! $order ) {
				throw new Exception( __( 'Invalid order.', 'woocommerce-gateway-amazon-payments-advanced' ) );
			}

			if ( ! $amazon_reference_id ) {
				throw new Exception( __( 'An Amazon Pay payment method was not chosen.', 'woocommerce-gateway-amazon-payments-advanced' ) );
			}

			/**
			 * If presentmentCurrency is updated (currency switched on checkout):
			 * If you need to update the presentmentCurrency of an order, you must change the presentmentCurrency in the Wallet widget by re-rendering the Wallet widget.
			 * You also must send a SetOrderReferenceDetails API call with the new OrderTotal, which matches the presentmentCurrency in the Wallet widget.
			 *
			 * @link https://pay.amazon.com/uk/developer/documentation/lpwa/4KGCA5DV6XQ4GUJ
			 */
			if ( WC_Amazon_Payments_Advanced_Multi_Currency::is_active() && WC_Amazon_Payments_Advanced_Multi_Currency::is_currency_switched_on_checkout() ) {
				$this->set_order_reference_details( $order, $amazon_reference_id );
			}

			$order_total = $order->get_total();
			$currency    = wc_apa_get_order_prop( $order, 'order_currency' );

			wc_apa()->log( __METHOD__, "Info: Beginning processing of payment for order {$order_id} for the amount of {$order_total} {$currency}. Amazon reference ID: {$amazon_reference_id}." );
			update_post_meta( $order_id, 'amazon_payment_advanced_version', WC_AMAZON_PAY_VERSION );
			update_post_meta( $order_id, 'woocommerce_version', WC()->version );

			// Get order details and save them to the order later.
			$order_details = $this->get_amazon_order_details( $amazon_reference_id );

			// @codingStandardsIgnoreStart
			$order_language = isset( $order_details->OrderLanguage )
				? (string) $order_details->OrderLanguage
				: 'unknown';
			// @codingStandardsIgnoreEnd

			/**
			 * Only set order reference details if state is 'Draft'.
			 *
			 * @link https://pay.amazon.com/de/developer/documentation/lpwa/201953810
			 * @see https://github.com/woocommerce/woocommerce-gateway-amazon-payments-advanced/issues/214
			 */
			// @codingStandardsIgnoreStart
			$state = isset( $order_details->OrderReferenceStatus->State )
				? (string) $order_details->OrderReferenceStatus->State
				: 'unknown';
			// @codingStandardsIgnoreEnd

			if ( 'Draft' === $state ) {
				// Update order reference with amounts.
				$this->set_order_reference_details( $order, $amazon_reference_id );
			}

			// Check if we are under SCA.
			$is_sca = WC_Amazon_Payments_Advanced_API::is_sca_region();
			// Confirm order reference.
			$this->confirm_order_reference( $amazon_reference_id, $is_sca );

			/**
			 * If retrieved order details missing `Name`, additional API
			 * request is needed after `ConfirmOrderReference` action to retrieve
			 * more information about `Destination` and `Buyer`.
			 *
			 * This happens when access token is not set in the request or merchants
			 * have **Use Amazon Login App** disabled.
			 *
			 * @see https://github.com/woocommerce/woocommerce-gateway-amazon-payments-advanced/issues/234
			 */
			// @codingStandardsIgnoreStart
			if ( ! isset( $order_details->Destination->PhysicalDestination->Name ) || ! isset( $order_details->Buyer->Name ) ) {
				$order_details = $this->get_amazon_order_details( $amazon_reference_id );
			}
			// @codingStandardsIgnoreEnd

			// Store buyer address in order.
			if ( $order_details ) {
				$this->store_order_address_details( $order, $order_details );
			}

			// Store reference ID in the order.
			update_post_meta( $order_id, 'amazon_reference_id', $amazon_reference_id );
			update_post_meta( $order_id, '_transaction_id', $amazon_reference_id );
			update_post_meta( $order_id, 'amazon_order_language', $order_language );

			wc_apa()->log( __METHOD__, sprintf( 'Info: Payment Capture method is %s', $this->payment_capture ? $this->payment_capture : 'authorize and capture' ) );

			// Stop execution if this is being processed by SCA.
			if ( $is_sca ) {
				wc_apa()->log( __METHOD__, sprintf( 'Info: SCA processing enabled. Transaction will be captured asynchronously' ) );
				return array(
					'result'   => 'success',
					'redirect' => '',
				);
			}

			// It will process payment and empty the cart.
			$this->process_payment_capture( $order, $amazon_reference_id );

			// Return thank you page redirect.
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);

		} catch ( Exception $e ) {
			// Async (optimal) mode on settings.
			if ( 'async' === $this->authorization_mode && isset( $e->transaction_timed_out ) ) {
				$this->process_async_auth( $order, $amazon_reference_id );

				return array(
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order ),
				);
			}
			wc_add_notice( __( 'Error:', 'woocommerce-gateway-amazon-payments-advanced' ) . ' ' . $e->getMessage(), 'error' );
		}
	}

	/**
	 * Checks payment_capture mode and process payment accordingly.
	 *
	 * @param WC_Order $order               Order to process payment for.
	 * @param string   $amazon_reference_id Associated Amazon reference ID.
	 *
	 * @throws Exception Declined transaction.
	 */
	protected function process_payment_capture( $order, $amazon_reference_id ) {

		switch ( $this->payment_capture ) {
			case 'manual':
				$this->process_payment_with_manual( $order, $amazon_reference_id );
				break;
			case 'authorize':
				$this->process_payment_with_authorize( $order, $amazon_reference_id );
				break;
			default:
				$this->process_payment_with_capture( $order, $amazon_reference_id );
		}

		// Remove cart.
		WC()->cart->empty_cart();
	}

	/**
	 * @param WC_Order $order
	 * @param string $amazon_reference_id
	 */
	protected function process_async_auth( $order, $amazon_reference_id ) {

		update_post_meta( $order->get_id(), 'amazon_timed_out_transaction', true );
		$order->update_status( 'on-hold', __( 'Transaction with Amazon Pay is currently being validated.', 'woocommerce-gateway-amazon-payments-advanced' ) );

		// https://pay.amazon.com/it/developer/documentation/lpwa/201953810
		// Make an ASYNC Authorize API call using a TransactionTimeout of 1440.
		$response = $this->process_payment_with_async_authorize( $order, $amazon_reference_id );

		$amazon_authorization_id = WC_Amazon_Payments_Advanced_API::get_auth_id_from_response( $response );
		$args = array(
			'order_id'                => $order->get_id(),
			'amazon_authorization_id' => $amazon_authorization_id,
		);
		// Schedule action to check pending order next hour.
		if ( false === as_next_scheduled_action( 'wcga_process_pending_syncro_payments', $args ) ) {
			as_schedule_single_action( strtotime( 'next hour' ) , 'wcga_process_pending_syncro_payments', $args );
		}
	}

	/**
	 * Process payment without authorizing and capturing the payment.
	 *
	 * This means store doesn't authorize and capture the payment when an order
	 * is placed. Store owner will manually authorize and capture the payment
	 * via edit order screen.
	 *
	 * @since 1.7.0
	 *
	 * @param WC_Order $order               WC Order object.
	 * @param string   $amazon_reference_id Amazon Order Reference ID.
	 */
	protected function process_payment_with_manual( $order, $amazon_reference_id ) {
		wc_apa()->log( __METHOD__, 'Info: No Authorize or Capture call.' );

		// Mark as on-hold.
		$order->update_status( 'on-hold', __( 'Amazon order opened. Use the "Amazon Pay" box to authorize and/or capture payment. Authorized payments must be captured within 7 days.', 'woocommerce-gateway-amazon-payments-advanced' ) );

		// Reduce stock levels.

		if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
			$order->reduce_order_stock();
		} else {
			wc_reduce_stock_levels( $order->get_id() );
		}
	}

	/**
	 * Process payment with authorizing the payment.
	 *
	 * Store owner will capture manually the payment via edit order screen.
	 *
	 * @since 1.7.0
	 *
	 * @param WC_Order $order               WC Order object.
	 * @param string   $amazon_reference_id Amazon Order Reference ID.
	 *
	 * @throws Exception Declined transaction.
	 */
	protected function process_payment_with_authorize( $order, $amazon_reference_id ) {
		wc_apa()->log( __METHOD__, 'Info: Trying to authorize payment in order reference ' . $amazon_reference_id );

		// Authorize only.
		$authorize_args = array(
			'amazon_reference_id' => $amazon_reference_id,
			'capture_now'         => false,
		);

		$order_id = wc_apa_get_order_prop( $order, 'id' );

		$result = WC_Amazon_Payments_Advanced_API::authorize( $order_id, $authorize_args );
		if ( is_wp_error( $result ) ) {
			$this->process_payment_check_declined_error( $order_id, $result );
		}

		$result = WC_Amazon_Payments_Advanced_API::handle_payment_authorization_response( $result, $order_id, false );
		if ( $result ) {
			// Mark as on-hold.
			$order->update_status( 'on-hold', __( 'Amazon order opened. Use the "Amazon Pay" box to authorize and/or capture payment. Authorized payments must be captured within 7 days.', 'woocommerce-gateway-amazon-payments-advanced' ) );

			// Reduce stock levels.
			if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
				$order->reduce_order_stock();
			} else {
				wc_reduce_stock_levels( $order->get_id() );
			}

			wc_apa()->log( __METHOD__, 'Info: Successfully authorized in order reference ' . $amazon_reference_id );
		} else {
			$order->update_status( 'failed', __( 'Could not authorize Amazon payment.', 'woocommerce-gateway-amazon-payments-advanced' ) );

			wc_apa()->log( __METHOD__, 'Error: Failed to authorize in order reference ' . $amazon_reference_id );
		}
	}

	/**
     * In asynchronous mode, the Authorize operation always returns the State as Pending. The authorisation remains in this state until it is processed by Amazon.
     * The processing time varies and can be a minute or more. After processing is complete, Amazon notifies you of the final processing status.
     * Transaction Timeout always set to 1440.
     *
	 * @param WC_Order $order               WC Order object.
	 * @param string   $amazon_reference_id Amazon Order Reference ID.
	 *
	 * @return SimpleXMLElement Response.
	 */
	public function process_payment_with_async_authorize( $order, $amazon_reference_id ) {
		wc_apa()->log( __METHOD__, 'Info: Trying to ASYNC authorize payment in order reference ' . $amazon_reference_id );

		$authorize_args = array(
			'amazon_reference_id' => $amazon_reference_id,
			'capture_now'         => ( 'authorize' === $this->payment_capture ) ? false : true,
			'transaction_timeout' => 1440,
		);
		$order_id = wc_apa_get_order_prop( $order, 'id' );
		return WC_Amazon_Payments_Advanced_API::authorize( $order_id, $authorize_args );
	}

	/**
	 * Process payment with authorizing and capturing.
	 *
	 * @since 1.7.0
	 *
	 * @param WC_Order $order               WC Order object.
	 * @param string   $amazon_reference_id Amazon Order Reference ID.
	 *
	 * @throws Exception Declined transaction.
	 */
	protected function process_payment_with_capture( $order, $amazon_reference_id ) {
		wc_apa()->log( __METHOD__, 'Info: Trying to capture payment in order reference ' . $amazon_reference_id );

		// Authorize and capture.
		$authorize_args = array(
			'amazon_reference_id' => $amazon_reference_id,
			'capture_now'         => true,
		);

		$order_id = wc_apa_get_order_prop( $order, 'id' );

		$result = WC_Amazon_Payments_Advanced_API::authorize( $order_id, $authorize_args );
		if ( is_wp_error( $result ) ) {
			$this->process_payment_check_declined_error( $order_id, $result );
		}

		$result = WC_Amazon_Payments_Advanced_API::handle_payment_authorization_response( $result, $order_id, true );
		if ( $result ) {
			// Payment complete.
			$order->payment_complete();

			// Close order reference.
			WC_Amazon_Payments_Advanced_API::close_order_reference( $order_id );

			wc_apa()->log( __METHOD__, 'Info: Successfully captured in order reference ' . $amazon_reference_id );

		} else {
			$order->update_status( 'failed', __( 'Could not authorize Amazon payment.', 'woocommerce-gateway-amazon-payments-advanced' ) );

			wc_apa()->log( __METHOD__, 'Error: Failed to capture in order reference ' . $amazon_reference_id );
		}
	}

	/**
	 * Check authorize result for a declined error.
	 *
	 * @since 1.7.0
	 *
	 * @throws Exception Declined transaction.
	 *
	 * @param int    $order_id Order ID.
	 * @param object $result   Return value from WC_Amazon_Payments_Advanced_API::request().
	 */
	protected function process_payment_check_declined_error( $order_id, $result ) {
		if ( ! is_wp_error( $result ) ) {
			return;
		}

		$code = $result->get_error_code();
		WC()->session->set( 'amazon_declined_code', $code );

		// If the transaction timed out and async authorization is enabled then throw an exception here and don't set
		// any of the session state related to declined orders. We'll let the calling code re-try by triggering an async
		// authorization.
		if ( in_array( $code, array( 'TransactionTimedOut' ) ) && 'async' === $this->authorization_mode ) {
			$e = new Exception( $result->get_error_message() );

			$e->transaction_timed_out = true;
			throw $e;
		}

		WC()->session->set( 'reload_checkout', true );
		if ( in_array( $code, array( 'AmazonRejected', 'ProcessingFailure', 'TransactionTimedOut' ) ) ) {
			WC()->session->set( 'amazon_declined_order_id', $order_id );
			WC()->session->set( 'amazon_declined_with_cancel_order', true );
		}
		throw new Exception( $result->get_error_message() );
	}

	/**
	 * Process refund.
	 *
	 * @since 1.6.0
	 *
	 * @param  int    $order_id      Order ID.
	 * @param  float  $refund_amount Amount to refund.
	 * @param  string $reason        Reason to refund.
	 *
	 * @return WP_Error|boolean True or false based on success, or a WP_Error object.
	 */
	public function process_refund( $order_id, $refund_amount = null, $reason = '' ) {
		wc_apa()->log( __METHOD__, 'Info: Trying to refund for order ' . $order_id );

		$amazon_capture_id = get_post_meta( $order_id, 'amazon_capture_id', true );
		if ( empty( $amazon_capture_id ) ) {
			return new WP_Error( 'error', sprintf( __( 'Unable to refund order %s. Order does not have Amazon capture reference. Make sure order has been captured.', 'woocommerce-gateway-amazon-payments-advanced' ), $order_id ) );
		}

		$ret = WC_Amazon_Payments_Advanced_API::refund_payment( $order_id, $amazon_capture_id, $refund_amount, $reason );

		return $ret;
	}

	/**
	 * Use 'SetOrderReferenceDetails' action to update details of the order reference.
	 *
	 * By default, use data from the WC_Order and WooCommerce / Site settings, but offer the ability to override.
	 *
	 * @throws Exception Error from API request.
	 *
	 * @param WC_Order $order               Order object.
	 * @param string   $amazon_reference_id Reference ID.
	 * @param array    $overrides           Optional. Override values sent to
	 *                                      the Amazon Pay API for the
	 *                                      SetOrderReferenceDetails request.
	 *
	 * @return WP_Error|array WP_Error or parsed response array
	 */
	public function set_order_reference_details( $order, $amazon_reference_id, $overrides = array() ) {

		$site_name = WC_Amazon_Payments_Advanced::get_site_name();

		/* translators: Order number and site's name */
		$seller_note  = sprintf( __( 'Order %1$s from %2$s.', 'woocommerce-gateway-amazon-payments-advanced' ), $order->get_order_number(), urlencode( $site_name ) );
		$version_note = sprintf( __( 'Created by WC_Gateway_Amazon_Pay/%1$s (Platform=WooCommerce/%2$s)', 'woocommerce-gateway-amazon-payments-advanced' ),  WC_AMAZON_PAY_VERSION, WC()->version );

		$request_args = array_merge( array(
			'Action'                                                           => 'SetOrderReferenceDetails',
			'AmazonOrderReferenceId'                                           => $amazon_reference_id,
			'OrderReferenceAttributes.OrderTotal.Amount'                       => $order->get_total(),
			'OrderReferenceAttributes.OrderTotal.CurrencyCode'                 => wc_apa_get_order_prop( $order, 'order_currency' ),
			'OrderReferenceAttributes.SellerNote'                              => $seller_note,
			'OrderReferenceAttributes.SellerOrderAttributes.SellerOrderId'     => $order->get_order_number(),
			'OrderReferenceAttributes.SellerOrderAttributes.StoreName'         => $site_name,
			'OrderReferenceAttributes.PlatformId'                              => 'A1BVJDFFHQ7US4',
			'OrderReferenceAttributes.SellerOrderAttributes.CustomInformation' => $version_note,
		), $overrides );

		// Update order reference with amounts.
		$response = WC_Amazon_Payments_Advanced_API::request( $request_args );

		if ( is_wp_error( $response ) ) {
			throw new Exception( $response->get_error_message() );
		}

		// @codingStandardsIgnoreStart
		if ( isset( $response->Error->Message ) ) {
			throw new Exception( (string) $response->Error->Message );
		}
		// @codingStandardsIgnoreEnd

		/**
		 * Check for constraints. Should bailed early on checkout and prompt
		 * the buyer again with the wallet for corrective action.
		 *
		 * @see https://payments.amazon.com/developer/documentation/apireference/201752890
		 */
		// @codingStandardsIgnoreStart
		if ( isset( $response->SetOrderReferenceDetailsResult->OrderReferenceDetails->Constraints->Constraint->ConstraintID ) ) {
			$constraint = (string) $response->SetOrderReferenceDetailsResult->OrderReferenceDetails->Constraints->Constraint->ConstraintID;
			// @codingStandardsIgnoreEnd

			switch ( $constraint ) {
				case 'BuyerEqualSeller':
					throw new Exception( __( 'You cannot shop on your own store.', 'woocommerce-gateway-amazon-payments-advanced' ) );
					break;
				case 'PaymentPlanNotSet':
					throw new Exception( __( 'You have not selected a payment method from your Amazon account. Please choose a payment method for this order.', 'woocommerce-gateway-amazon-payments-advanced' ) );
					break;
				case 'PaymentMethodNotAllowed':
					throw new Exception( __( 'There has been a problem with the selected payment method from your Amazon account. Please update the payment method or choose another one.', 'woocommerce-gateway-amazon-payments-advanced' ) );
				case 'ShippingAddressNotSet':
					throw new Exception( __( 'You have not selected a shipping address from your Amazon account. Please choose a shipping address for this order.', 'woocommerce-gateway-amazon-payments-advanced' ) );
					break;
			}
		}

		return $response;

	}

	/**
	 * Helper method to call 'ConfirmOrderReference' API action.
	 *
	 * @throws Exception Error from API request.
	 *
	 * @param string $amazon_reference_id Reference ID.
	 * @param bool   $is_sca If needs SCA, ConfirmOrderReference needs extra parameters.
	 *
	 * @return WP_Error|array WP_Error or parsed response array
	 */
	public function confirm_order_reference( $amazon_reference_id, $is_sca = false ) {

		$confirm_args = array(
			'Action'                 => 'ConfirmOrderReference',
			'AmazonOrderReferenceId' => $amazon_reference_id,
		);

		if ( $is_sca ) {
			// The buyer is redirected to this URL if the MFA is successful.
			$confirm_args['SuccessUrl'] = wc_get_checkout_url();
			// The buyer is redirected to this URL if the MFA is unsuccessful.
			$confirm_args['FailureUrl'] = wc_get_checkout_url();
		}

		$response = WC_Amazon_Payments_Advanced_API::request( $confirm_args );

		if ( is_wp_error( $response ) ) {
			throw new Exception( $response->get_error_message() );
		}

		// @codingStandardsIgnoreStart
		if ( isset( $response->Error->Message ) ) {
			throw new Exception( (string) $response->Error->Message );
		}
		// @codingStandardsIgnoreEnd

		return $response;

	}

	/**
	 * Retrieve full details from the order using 'GetOrderReferenceDetails'.
	 *
	 * @param string $amazon_reference_id Reference ID.
	 *
	 * @return bool|object Boolean false on failure, object of OrderReferenceDetails on success.
	 */
	public function get_amazon_order_details( $amazon_reference_id ) {

		$request_args = array(
			'Action'                 => 'GetOrderReferenceDetails',
			'AmazonOrderReferenceId' => $amazon_reference_id,
		);

		/**
		 * Full address information is available to the 'GetOrderReferenceDetails' call when we're in
		 * "login app" mode and we pass the AddressConsentToken to the API.
		 *
		 * @see the "Getting the Shipping Address" section here: https://payments.amazon.com/documentation/lpwa/201749990
		 */
		$settings = WC_Amazon_Payments_Advanced_API::get_settings();
		if ( 'yes' == $settings['enable_login_app'] ) {
			$request_args['AddressConsentToken'] = $this->access_token;
		}

		$response = WC_Amazon_Payments_Advanced_API::request( $request_args );

		// @codingStandardsIgnoreStart
		if ( ! is_wp_error( $response ) && isset( $response->GetOrderReferenceDetailsResult->OrderReferenceDetails ) ) {
			return $response->GetOrderReferenceDetailsResult->OrderReferenceDetails;
		}
		// @codingStandardsIgnoreEnd

		return false;

	}

	/**
	 * Format an Amazon Pay Address DataType for WooCommerce.
	 *
	 * @see https://payments.amazon.com/documentation/apireference/201752430
	 *
	 * @deprecated
	 *
	 * @param array $address Address object from Amazon Pay API.
	 *
	 * @return array Address formatted for WooCommerce.
	 */
	public function format_address( $address ) {
		_deprecated_function( 'WC_Gateway_Amazon_Payments_Advanced::format_address', '1.6.0', 'WC_Amazon_Payments_Advanced_API::format_address' );

		return WC_Amazon_Payments_Advanced_API::format_address( $address );
	}

	/**
	 * Parse the OrderReferenceDetails object and store billing/shipping addresses
	 * in order meta.
	 *
	 * @version 1.7.1
	 *
	 * @param int|WC_order $order                   Order ID or order instance.
	 * @param object       $order_reference_details Amazon API OrderReferenceDetails.
	 */
	public function store_order_address_details( $order, $order_reference_details ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( $order );
		}

		// @codingStandardsIgnoreStart
		$buyer         = $order_reference_details->Buyer;
		$destination   = $order_reference_details->Destination->PhysicalDestination;
		$shipping_info = WC_Amazon_Payments_Advanced_API::format_address( $destination );
		// @codingStandardsIgnoreEnd

		// Override Shipping State if Destination State doesn't exists in the country's states list.
		$order_current_state = $order->get_shipping_state();
		if ( ! empty( $order_current_state ) && $order_current_state !== $shipping_info['state'] ) {
			$states = WC()->countries->get_states( $shipping_info['country'] );
			if ( empty( $states ) || empty( $states[ $shipping_info['state'] ] ) ) {
				$shipping_info['state'] = $order_current_state;
			}
		}

		$order->set_address( $shipping_info, 'shipping' );

		// Some market API endpoint return billing address information, parse it if present.
		// @codingStandardsIgnoreStart
		if ( isset( $order_reference_details->BillingAddress->PhysicalAddress ) ) {
			$billing_address = WC_Amazon_Payments_Advanced_API::format_address( $order_reference_details->BillingAddress->PhysicalAddress );
		} elseif ( apply_filters( 'woocommerce_amazon_pa_billing_address_fallback_to_shipping_address', true ) ) {
			// Reuse the shipping address information if no bespoke billing info.
			$billing_address = $shipping_info;
		} else {
			$name            = ! empty( $buyer->Name ) ? (string) $buyer->Name : '';
			$billing_address = WC_Amazon_Payments_Advanced_API::format_name( $name );
		}

		$billing_address['email'] = (string) $buyer->Email;
		$billing_address['phone'] = isset( $billing_address['phone'] ) ? $billing_address['phone'] : (string) $buyer->Phone;
		// @codingStandardsIgnoreEnd

		$order->set_address( $billing_address, 'billing' );
	}

	/**
	 * If AuthenticationStatus is hit, we need to finish payment process that has been stopped previously.
	 */
	public function handle_sca_url_processing() {
		if ( ! isset( $_GET['AuthenticationStatus'] ) ) {
			return;
		}

		$order_id = isset( WC()->session->order_awaiting_payment ) ? WC()->session->order_awaiting_payment : null;
		if ( ! $order_id ) {
			return;
		}

		$order               = wc_get_order( $order_id );
		$amazon_reference_id = $order->get_meta( 'amazon_reference_id', true );
		WC()->session->set( 'amazon_reference_id', $amazon_reference_id );

		wc_apa()->log(
			__METHOD__,
			sprintf( 'Info: Continuing payment processing for order %s. Reference ID %s', $order_id, $amazon_reference_id )
		);

		$authorization_status = wc_clean( $_GET['AuthenticationStatus'] );
		switch ( $authorization_status ) {
			case 'Success':
				$this->handle_sca_success( $order, $amazon_reference_id );
				break;
			case 'Failure':
			case 'Abandoned':
				$this->handle_sca_failure( $order, $amazon_reference_id, $authorization_status );
				break;
			default:
				wp_redirect( wc_get_checkout_url() );
				exit;
		}
	}

	/**
	 * If redirected to success url, proceed with payment and redirect to thank you page.
	 *
	 * @param WC_Order $order
	 * @param string $amazon_reference_id
	 */
	protected function handle_sca_success( $order, $amazon_reference_id ) {
		$redirect = $this->get_return_url( $order );

		try {
			// It will process payment and empty the cart.
			$this->process_payment_capture( $order, $amazon_reference_id );
		} catch ( Exception $e ) {
			// Async (optimal) mode on settings.
			if ( 'async' === $this->authorization_mode && isset( $e->transaction_timed_out ) ) {
				$this->process_async_auth( $order, $amazon_reference_id );
			} else {
				wc_add_notice( __( 'Error:', 'woocommerce-gateway-amazon-payments-advanced' ) . ' ' . $e->getMessage(), 'error' );
				$redirect = wc_get_checkout_url();
			}
		}

		wp_redirect( $redirect );
		exit;
	}

	/**
	 * If redirected to failure url, add a notice with right information for the user.
	 *
	 * @param WC_Order $order
	 * @param string $amazon_reference_id
	 * @param string $authorization_status
	 */
	protected function handle_sca_failure( $order, $amazon_reference_id, $authorization_status ) {
		$redirect = wc_get_checkout_url();

		// Failure will mock AmazonRejected behaviour.
		if ( 'Failure' === $authorization_status ) {
			// Cancel order.
			$order->update_status( 'cancelled', __( 'Could not authorize Amazon payment. Failure on MFA (Multi-Factor Authentication) challenge.', 'woocommerce-gateway-amazon-payments-advanced' ) );
			// Cancel order on amazon.
			WC_Amazon_Payments_Advanced_API::cancel_order_reference( $order, 'MFA Failure' );

			// Redirect to cart and amazon logout.
			$redirect = add_query_arg(
				array(
					'amazon_payments_advanced' => 'true',
					'amazon_logout'            => 'true',
				),
				wc_get_cart_url()
			);

			// Adds notice and logging.
			wc_add_notice( __( 'There was a problem authorizing your transaction using Amazon Pay. Please try placing the order again.', 'woocommerce-gateway-amazon-payments-advanced' ), 'error' );
			wc_apa()->log( __METHOD__, 'MFA (Multi-Factor Authentication) Challenge Fail, Status "Failure", reference ' . $amazon_reference_id );
		}

		if ( 'Abandoned' === $authorization_status ) {
			wc_add_notice( __( 'Authentication for the transaction was not completed, please try again selecting another payment instrument from your Amazon wallet.', 'woocommerce-gateway-amazon-payments-advanced' ), 'error' );
			wc_apa()->log( __METHOD__, 'MFA (Multi-Factor Authentication) Challenge Fail, Status "Abandoned", reference ' . $amazon_reference_id );
		}

		wp_redirect( $redirect );
		exit;
	}

	/**
	 * Write a message to log if we're in "debug" mode.
	 *
	 * @deprecated
	 *
	 * @param string $context Context.
	 * @param string $message Message to log.
	 */
	public function log( $context, $message ) {
		_deprecated_function( 'WC_Gateway_Amazon_Payments_Advanced::log', '1.6.0', 'wc_apa()->log()' );

		wc_apa()->log( $context, $message );
	}

	/**
	 * @param $text
	 * @param WC_Order $order
	 *
	 * @return string
	 */
	public function maybe_render_timeout_transaction_order_received_text( $text, $order ) {
		if ( $order && $order->has_status( 'on-hold' ) && get_post_meta( $order->get_id(), 'amazon_timed_out_transaction', true ) ) {
			$text = __( 'Your transaction with Amazon Pay is currently being validated. Please be aware that we will inform you shortly as needed.', 'woocommerce-gateway-amazon-payments-advanced' );
		}
		return $text;
	}

	/**
	 * Print the forms to import and export the settings.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function import_export_fields_output() {
		if ( isset( $_GET['section'] ) && 'amazon_payments_advanced' === $_GET['section'] ) { //  phpcs:ignore WordPress.Security.NonceVerification.Recommended 
			?>
			<div class="wrap">
				<div class="metabox-holder">
					<div class="postbox">
						<h3><span><?php esc_html_e( 'Export Settings', 'woocommerce-gateway-amazon-payments-advanced' ); ?></span></h3>
						<div class="inside">
							<p><?php esc_html_e( 'Export the plugin settings for this site as a .json file. This allows you to easily import the configuration into another site.' ); ?></p>
							<form method="post">
								<p><input type="hidden" name="amazon_pay_action" value="export_settings" /></p>
								<p>
									<?php wp_nonce_field( 'amazon_pay_export_nonce', 'amazon_pay_export_nonce' ); ?>
									<?php submit_button( __( 'Export', 'woocommerce-gateway-amazon-payments-advanced' ), 'secondary', 'submit', false ); ?>
								</p>
							</form>
						</div><!-- .inside -->
						<h3><span><?php esc_html_e( 'Import Settings', 'woocommerce-gateway-amazon-payments-advanced' ); ?></span></h3>
						<div class="inside">
							<p><?php esc_html_e( 'Import the plugin settings from a .json file. This file can be obtained by exporting the settings on another site using the form above.', 'woocommerce-gateway-amazon-payments-advanced' ); ?></p>
							<form method="post" enctype="multipart/form-data">
								<p>
									<input type="file" name="import_file"/>
								</p>
								<p>
									<input type="hidden" name="amazon_pay_action" value="import_settings" />
									<?php wp_nonce_field( 'amazon_pay_import_nonce', 'amazon_pay_import_nonce' ); ?>
									<?php submit_button( esc_html__( 'Import', 'woocommerce-gateway-amazon-payments-advanced' ), 'secondary', 'import_submit', false ); ?>
								</p>
							</form>
						</div>
					</div>
				</div>
			</div>
			<?php
		}
	}

	/**
	 * Process a settings export that generates a .json file 
	 */
	public function process_settings_export() {

		if ( empty( $_POST['amazon_pay_action'] ) || 'export_settings' !== $_POST['amazon_pay_action'] ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( isset( $_POST['amazon_pay_export_nonce'] ) && ! wp_verify_nonce( $_POST['amazon_pay_export_nonce'], 'amazon_pay_export_nonce' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings    = get_option( $this->get_option_key() );
		$private_key = get_option( WC_Amazon_Payments_Advanced_Merchant_Onboarding_Handler::KEYS_OPTION_PRIVATE_KEY );

		$settings['private_key'] = $private_key;

		ignore_user_abort( true );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=wc-amazon-pay-settings-export-' . date( 'm-d-Y' ) . '.json' );
		header( 'Expires: 0' );

		echo wp_json_encode( $settings );
		exit;
	}

	/**
	 * Process settings in a file
	 *
	 * @param array $import_file PHP $_FILES (or similar) entry.
	 */
	private function process_settings_from_file( $import_file, $clean_post = false ) {
		$fn_parts  = explode( '.', $import_file['name'] );
		$extension = end( $fn_parts );

		if ( 'json' !== $extension ) {
			wp_die( esc_html__( 'Please upload a valid .json file', 'woocommerce-gateway-amazon-payments-advanced' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$json_settings = (array) json_decode( file_get_contents( $import_file['tmp_name'] ) );
		if ( isset( $json_settings['private_key'] ) ) {
			$private_key = $json_settings['private_key'];
			unset( $json_settings['private_key'] );
			$this->save_private_key( $private_key );
		}

		foreach ( $this->get_form_fields() as $key => $field ) {
			if ( 'title' !== $this->get_field_type( $field ) ) {
				try {
					if ( isset( $json_settings[ $key ] ) ) {
						$this->settings[ $key ] = $json_settings[ $key ];
						if ( $clean_post ) {
							$post_key = 'woocommerce_amazon_payments_advanced_' . $key;
							if ( isset( $this->data ) && is_array( $this->data ) && isset( $this->data[ $post_key ] ) ) {
								$this->data[ $post_key ] = $this->settings[ $key ];
							}
							if ( isset( $_POST[ $post_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
								$_POST[ $post_key ] = $this->settings[ $key ];
							}
						}
						unset( $json_settings[ $key ] );
					}
				} catch ( Exception $e ) {
					$this->add_error( $e->getMessage() );
				}
			}
		}

		update_option( $this->get_option_key( true ), apply_filters( 'woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings ), 'yes' );

		if ( empty( $this->settings['merchant_id'] ) ) {
			wc_apa()->delete_migration_status();
		} else {
			wc_apa()->update_migration_status();
		}
	}

	/**
	 * Process a settings import from a json file.
	 */
	public function process_settings_import() {

		if ( empty( $_POST['amazon_pay_action'] ) || 'import_settings' !== $_POST['amazon_pay_action'] ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( isset( $_POST['amazon_pay_import_nonce'] ) && ! wp_verify_nonce( $_POST['amazon_pay_import_nonce'], 'amazon_pay_import_nonce' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( empty( $_FILES['import_file']['tmp_name'] ) ) {
			wp_die( esc_html__( 'Please upload a file to import', 'woocommerce-gateway-amazon-payments-advanced' ) );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$import_file = $_FILES['import_file'];

		$this->process_settings_from_file( $import_file );
		wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . $this->id ) );
		exit;
	}

	public function generate_custom_html( $id, $conf ) {
		$html = isset( $conf['html'] ) ? wp_kses_post( $conf['html'] ) : "";

		if( $html ) {
			ob_start();
			?>
			</table>
			<?php echo $html; ?>
			<table class="form-table">
			<?php
			$html = ob_get_clean();
		}

		return $html;
	}

	/**
	 * Save private key
	 *
	 * @param string $private_key Private key PEM string.
	 */
	private function save_private_key( $private_key ) {
		$validate_private_key = openssl_pkey_get_private( $private_key );
		if ( $validate_private_key ) {
			update_option( WC_Amazon_Payments_Advanced_Merchant_Onboarding_Handler::KEYS_OPTION_PRIVATE_KEY, $private_key );
			return true;
		}

		return false;
	}

	public function validate_api_keys() {
		if ( wc_apa()->get_migration_status( true ) ) {
			WC_Amazon_Payments_Advanced_API::validate_api_keys();
		} else {
			WC_Amazon_Payments_Advanced_API_Legacy::validate_api_keys();
		}
	}

	/**
	 * Load handlers for cart and orders after WC Cart is loaded.
	 */
	public function init_handlers() {
		// Disable if no seller ID.
		if ( ! apply_filters( 'woocommerce_amazon_payments_init', true ) || empty( $this->settings['seller_id'] ) || 'no' == $this->settings['enabled'] ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'scripts' ) );

		// Login app actions.
		if ( 'yes' === $this->settings['enable_login_app'] ) {
			// Login app widget.
			add_action( 'wp_head', array( $this, 'init_amazon_login_app_widget' ) );
		}

		if ( 'button' === $this->settings['cart_button_display_mode'] ) {
			add_action( 'woocommerce_proceed_to_checkout', array( $this, 'checkout_button' ), 25 );
		} elseif ( 'banner' === $this->settings['cart_button_display_mode'] ) {
			add_action( 'woocommerce_before_cart', array( $this, 'checkout_message' ), 5 );
		}

		// Checkout
		add_action( 'woocommerce_checkout_init', array( $this, 'checkout_init' ) );
		add_filter( 'woocommerce_update_order_review_fragments', array( $this, 'update_amazon_widgets_fragment' ) );
		add_action( 'woocommerce_after_calculate_totals', array( $this, 'force_standard_mode_refresh_with_zero_order_total' ) );

		// When transaction is declined with reason code 'InvalidPaymentMethod',
		// the customer will be promopted with read-only address widget and need
		// to fix the chosen payment method. Coupon form should be disabled in
		// this state.
		//
		// @see https://github.com/woocommerce/woocommerce-gateway-amazon-payments-advanced/issues/244.
		// @see https://pay.amazon.com/de/developer/documentation/lpwa/201953810#sync-invalidpaymentmethod.
		if ( WC()->session && 'InvalidPaymentMethod' === WC()->session->amazon_declined_code ) {
			remove_action( 'woocommerce_before_checkout_form', 'woocommerce_checkout_coupon_form' );
		}
	}

	/**
	 * Maybe the request to logout from Amazon.
	 *
	 * @since 1.6.0
	 */
	public function maybe_attempt_to_logout() {
		if ( ! empty( $_GET['amazon_payments_advanced'] ) && ! empty( $_GET['amazon_logout'] ) ) {
			$this->logout_from_amazon();
		}
	}

	/**
	 * Logout from Amazon by removing Amazon related session and logout too
	 * from app widget.
	 *
	 * @since 1.6.0
	 */
	public function logout_from_amazon() {
		unset( WC()->session->amazon_reference_id );
		unset( WC()->session->amazon_access_token );

		$this->reference_id = '';
		$this->access_token = '';

		if ( is_order_received_page() && 'yes' === $this->settings['enable_login_app'] ) {
			?>
			<script>
			( function( $ ) {
				$( document ).on( 'wc_amazon_pa_login_ready', function() {
					amazon.Login.logout();
				} );
			} )(jQuery)
			</script>
			<?php
		}
	}

	/**
	 * Initialize Amazon Pay UI during checkout.
	 *
	 * @param WC_Checkout $checkout Checkout object.
	 */
	public function checkout_init( $checkout ) {

		/**
		 * Make sure this is checkout initiated from front-end where cart exsits.
		 *
		 * @see https://github.com/woocommerce/woocommerce-gateway-amazon-payments-advanced/issues/238
		 */
		if ( ! WC()->cart ) {
			return;
		}

		// Are we using the login app?
		$enable_login_app = ( 'yes' === $this->settings['enable_login_app'] );

		// Disable Amazon Pay for zero-total checkouts using the standard button.
		if ( ! WC()->cart->needs_payment() && ! $enable_login_app ) {

			// Render a placeholder widget container instead, in the event we
			// need to populate it later.
			add_action( 'woocommerce_checkout_before_customer_details', array( $this, 'placeholder_widget_container' ) );

			// Render a placeholder checkout message container, in the event we
			// need to populate it later.
			add_action( 'woocommerce_before_checkout_form', array( $this, 'placeholder_checkout_message_container' ), 5 );

			return;

		}

		add_action( 'woocommerce_before_checkout_form', array( $this, 'checkout_message' ), 5 );
		add_action( 'before_woocommerce_pay', array( $this, 'checkout_message' ), 5 );

		// Don't try to render the Amazon widgets if we don't have the prerequisites
		// for each mode.
		if ( ( ! $enable_login_app && empty( $this->reference_id ) ) || ( $enable_login_app && empty( $this->access_token ) ) ) {
			add_filter( 'woocommerce_available_payment_gateways', array( $this, 'remove_amazon_gateway' ) );
			return;
		}

		// If all prerequisites are meet to be an amazon checkout.
		do_action( 'woocommerce_amazon_checkout_init', $enable_login_app );

		add_action( 'woocommerce_checkout_before_customer_details', array( $this, 'payment_widget' ), 20 );
		add_action( 'woocommerce_checkout_before_customer_details', array( $this, 'address_widget' ), 10 );
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'remove_gateways' ) );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'capture_shipping_address_for_zero_order_total' ) );
		add_action( 'woocommerce_ship_to_different_address_checked', '__return_true' );
		// Some fields are not enforced on Amazon's side. Marking them as optional avoids issues with checkout.
		add_filter( 'woocommerce_billing_fields', array( $this, 'override_billing_fields' ) );
		add_filter( 'woocommerce_shipping_fields', array( $this, 'override_shipping_fields' ) );
		// The default checkout form uses the billing email for new account creation
		// Let's hijack that field for the Amazon-based checkout.
		if ( apply_filters( 'woocommerce_pa_hijack_checkout_fields', true ) ) {
			$this->hijack_checkout_fields( $checkout );
		}
	}

	/**
	 * Output an empty placeholder widgets container
	 */
	public function placeholder_widget_container() {
		?>
		<div id="amazon_customer_details"></div>
		<?php
	}

	/**
	 * Output an empty placeholder checkout message container
	 */
	public function placeholder_checkout_message_container() {
		?>
		<div class="wc-amazon-checkout-message"></div>
		<?php
	}

	/**
	 * Checkout Message
	 */
	public function checkout_message() {
		$subscriptions_installed = class_exists( 'WC_Subscriptions_Order' ) && function_exists( 'wcs_create_renewal_order' );
		$subscriptions_enabled   = empty( $this->settings['subscriptions_enabled'] ) || 'yes' == $this->settings['subscriptions_enabled'];
		$cart_contains_sub       = class_exists( 'WC_Subscriptions_Cart' ) ? WC_Subscriptions_Cart::cart_contains_subscription() : false;

		if ( $subscriptions_installed && ! $subscriptions_enabled && $cart_contains_sub ) {
			return;
		}

		echo '<div class="wc-amazon-checkout-message wc-amazon-payments-advanced-populated">';

		if ( empty( $this->reference_id ) && empty( $this->access_token ) ) {
			echo '<div class="woocommerce-info info wc-amazon-payments-advanced-info"><div id="pay_with_amazon"></div> ' . apply_filters( 'woocommerce_amazon_pa_checkout_message', __( 'Have an Amazon account?', 'woocommerce-gateway-amazon-payments-advanced' ) ) . '</div>';
		} else {
			$logout_url = $this->get_amazon_logout_url();
			$logout_msg_html = '<div class="woocommerce-info info">' . apply_filters( 'woocommerce_amazon_pa_checkout_logout_message', __( 'You\'re logged in with your Amazon Account.', 'woocommerce-gateway-amazon-payments-advanced' ) ) . ' <a href="' . esc_url( $logout_url ) . '" id="amazon-logout">' . __( 'Log out &raquo;', 'woocommerce-gateway-amazon-payments-advanced' ) . '</a></div>';
			echo apply_filters( 'woocommerce_amazon_payments_logout_checkout_message_html', $logout_msg_html );
		}

		echo '</div>';

	}

	/**
	 * Get Amazon logout URL.
	 *
	 * @since 1.6.0
	 *
	 * @return string Amazon logout URL
	 */
	public function get_amazon_logout_url() {
		return add_query_arg(
			array(
				'amazon_payments_advanced' => 'true',
				'amazon_logout'            => 'true',
			),
			get_permalink( wc_get_page_id( 'checkout' ) )
		);
	}

	/**
	 * Output the address widget HTML
	 */
	public function address_widget() {
		// Skip showing address widget for carts with virtual products only
		$show_address_widget = apply_filters( 'woocommerce_amazon_show_address_widget', WC()->cart->needs_shipping() );
		$hide_css_style      = ( ! $show_address_widget ) ? 'display: none;' : '';
		?>
		<div id="amazon_customer_details" class="wc-amazon-payments-advanced-populated">
			<div class="col2-set">
				<div class="col-1" style="<?php echo esc_attr( $hide_css_style ); ?>">
					<?php if ( 'skip' !== WC()->session->get( 'amazon_billing_agreement_details' ) ) : ?>
						<h3><?php esc_html_e( 'Shipping Address', 'woocommerce-gateway-amazon-payments-advanced' ); ?></h3>
						<div id="amazon_addressbook_widget"></div>
					<?php endif ?>
					<?php if ( ! empty( $this->reference_id ) ) : ?>
						<input type="hidden" name="amazon_reference_id" value="<?php echo esc_attr( $this->reference_id ); ?>" />
					<?php endif; ?>
					<?php if ( ! empty( $this->access_token ) ) : ?>
						<input type="hidden" name="amazon_access_token" value="<?php echo esc_attr( $this->access_token ); ?>" />
					<?php endif; ?>
				</div>
		<?php
	}

	/**
	 * Output the payment method widget HTML
	 */
	public function payment_widget() {
		$checkout = WC_Checkout::instance();
		?>
				<div class="col-2">
					<h3><?php _e( 'Payment Method', 'woocommerce-gateway-amazon-payments-advanced' ); ?></h3>
					<div id="amazon_wallet_widget"></div>
					<?php if ( ! empty( $this->reference_id ) ) : ?>
						<input type="hidden" name="amazon_reference_id" value="<?php echo esc_attr( $this->reference_id ); ?>" />
					<?php endif; ?>
					<?php if ( ! empty( $this->access_token ) ) : ?>
						<input type="hidden" name="amazon_access_token" value="<?php echo esc_attr( $this->access_token ); ?>" />
					<?php endif; ?>
					<?php if ( 'skip' === WC()->session->get( 'amazon_billing_agreement_details' ) ) : ?>
						<input type="hidden" name="amazon_billing_agreement_details" value="skip" />
					<?php endif; ?>
				</div>
				<?php if ( 'skip' !== WC()->session->get( 'amazon_billing_agreement_details' ) ) : ?>
					<div id="amazon_consent_widget" style="display: none;"></div>
				<?php endif; ?>

		<?php if ( ! is_user_logged_in() && $checkout->enable_signup ) : ?>

			<?php if ( $checkout->enable_guest_checkout ) : ?>

				<p class="form-row form-row-wide create-account">
					<input class="input-checkbox" id="createaccount" <?php checked( ( true === $checkout->get_value( 'createaccount' ) || ( true === apply_filters( 'woocommerce_create_account_default_checked', false ) ) ), true ) ?> type="checkbox" name="createaccount" value="1" /> <label for="createaccount" class="checkbox"><?php _e( 'Create an account?', 'woocommerce-gateway-amazon-payments-advanced' ); ?></label>
				</p>

			<?php endif; ?>

			<?php do_action( 'woocommerce_before_checkout_registration_form', $checkout ); ?>

			<?php if ( ! empty( $checkout->checkout_fields['account'] ) ) : ?>

				<div class="create-account">

					<h3><?php _e( 'Create Account', 'woocommerce-gateway-amazon-payments-advanced' ); ?></h3>
					<p><?php _e( 'Create an account by entering the information below. If you are a returning customer please login at the top of the page.', 'woocommerce-gateway-amazon-payments-advanced' ); ?></p>

					<?php foreach ( $checkout->checkout_fields['account'] as $key => $field ) : ?>

						<?php woocommerce_form_field( $key, $field, $checkout->get_value( $key ) ); ?>

					<?php endforeach; ?>

					<div class="clear"></div>

				</div>

			<?php endif; ?>

			<?php do_action( 'woocommerce_after_checkout_registration_form', $checkout ); ?>

		<?php endif; ?>
			</div>
		</div>

		<?php
	}

	/**
	 * Remove amazon gateway.
	 *
	 * @param $gateways
	 *
	 * @return array
	 */
	public function remove_amazon_gateway( $gateways ) {

		foreach ( $gateways as $gateway_key => $gateway ) {
			if ( 'amazon_payments_advanced' === $gateway_key ) {
				unset( $gateways[ $gateway_key ] );
			}
		}

		return $gateways;
	}

	/**
	 * Remove all gateways except amazon
	 *
	 * @param array $gateways List of payment methods.
	 *
	 * @return array List of payment methods.
	 */
	public function remove_gateways( $gateways ) {

		foreach ( $gateways as $gateway_key => $gateway ) {
			if ( 'amazon_payments_advanced' !== $gateway_key ) {
				unset( $gateways[ $gateway_key ] );
			}
		}

		return $gateways;
	}

	/**
	 * Capture full shipping address in the case of a $0 order, using the "login app"
	 * version of the API.
	 *
	 * @version 1.7.1
	 *
	 * @param int $order_id Order ID.
	 */
	public function capture_shipping_address_for_zero_order_total( $order_id ) {
		$order = wc_get_order( $order_id );

		/**
		 * Complete address data is only available without a confirmed order if
		 * we're using the login app.
		 *
		 * @see Getting the Shipping Address section at https://payments.amazon.com/documentation/lpwa/201749990
		 */
		if ( ( $order->get_total() > 0 ) || empty( $this->reference_id ) || ( 'yes' !== $this->settings['enable_login_app'] ) || empty( $this->access_token ) ) {
			return;
		}

		// Get FULL address details and save them to the order.
		$order_details = wc_apa()->get_gateway()->get_amazon_order_details( $this->reference_id );

		if ( $order_details ) {
			wc_apa()->get_gateway()->store_order_address_details( $order, $order_details );
		}
	}

	/**
	 * Override billing fields when checking out with Amazon.
	 *
	 * @param array $fields billing fields.
	 */
	public function override_billing_fields( $fields ) {
		// Last name and State are not required on Amazon billing addrress forms.
		$fields['billing_last_name']['required'] = false;
		$fields['billing_state']['required']     = false;
		// Phone field is missing on some sandbox accounts.
		$fields['billing_phone']['required'] = false;

		return $fields;
	}

	/**
	 * Override address fields when checking out with Amazon.
	 *
	 * @param array $fields default address fields.
	 */
	public function override_shipping_fields( $fields ) {
		// Last name and State are not required on Amazon shipping addrress forms.
		$fields['shipping_last_name']['required'] = false;
		$fields['shipping_state']['required']     = false;

		return $fields;
	}

	/**
	 * Hijack checkout fields during checkout.
	 *
	 * @since 1.6.0
	 *
	 * @param WC_Checkout $checkout WC_Checkout instance.
	 */
	public function hijack_checkout_fields( $checkout ) {
		$has_billing_fields = (
			isset( $checkout->checkout_fields['billing'] )
			&&
			is_array( $checkout->checkout_fields['billing'] )
		);

		if ( $has_billing_fields && 'yes' !== $this->settings['enable_login_app'] ) {
			$this->hijack_checkout_field_account( $checkout );
		}

		// During an Amazon checkout, the standard billing and shipping fields need to be
		// "removed" so that we don't trigger a false negative on form validation -
		// they can be empty since we're using the Amazon widgets.
		$this->hijack_checkout_field_billing( $checkout );
		$this->hijack_checkout_field_shipping( $checkout );
	}

	/**
	 * Alter account checkout field.
	 *
	 * @since 1.7.0
	 *
	 * @param WC_Checkout $checkout WC_Checkout instance.
	 */
	private function hijack_checkout_field_account( $checkout ) {
		$billing_fields_to_copy = array(
			'billing_first_name' => '',
			'billing_last_name'  => '',
			'billing_email'      => '',
		);

		$billing_fields_to_merge = array_intersect_key( $checkout->checkout_fields['billing'], $billing_fields_to_copy );

		/**
		 * WC 3.0 changes a bit a way to retrieve fields.
		 *
		 * @see https://github.com/woocommerce/woocommerce-gateway-amazon-payments-advanced/issues/217
		 */
		$checkout_fields = version_compare( WC_VERSION, '3.0', '>=' )
			? $checkout->get_checkout_fields()
			: $checkout->checkout_fields;

		/**
		 * Before 3.0.0, account fields may not set at all if guest checkout is
		 * disabled with account and password generated automatically.
		 *
		 * @see https://github.com/woocommerce/woocommerce/blob/2.6.14/includes/class-wc-checkout.php#L92-L132
		 * @see https://github.com/woocommerce/woocommerce/blob/master/includes/class-wc-checkout.php#L187-L197
		 * @see https://github.com/woocommerce/woocommerce-gateway-amazon-payments-advanced/issues/228
		 */
		$checkout_fields['account'] = ! empty( $checkout_fields['account'] )
			? $checkout_fields['account']
			: array();

		$checkout_fields['account'] = array_merge( $billing_fields_to_merge, $checkout_fields['account'] );

		if ( isset( $checkout_fields['account']['billing_email']['class'] ) ) {
			$checkout_fields['account']['billing_email']['class'] = array();
		}

		$checkout->checkout_fields = $checkout_fields;
	}

	/**
	 * Hijack billing checkout field.
	 *
	 * @since 1.7.0
	 *
	 * @param WC_Checkout $checkout WC_Checkout instance.
	 */
	private function hijack_checkout_field_billing( $checkout ) {
		// The following fields cannot be optional for WC compatibility reasons.
		$required_fields = array( 'billing_first_name', 'billing_last_name', 'billing_email' );
		// If the order does not require shipping, these fields can be optional.
		$optional_fields = array(
			'billing_company',
			'billing_country',
			'billing_address_1',
			'billing_address_2',
			'billing_city',
			'billing_state',
			'billing_postcode',
			'billing_phone',
		);
		$all_fields      = array_merge( $required_fields, $optional_fields );
		$checkout_fields = version_compare( WC_VERSION, '3.0', '>=' )
			? $checkout->get_checkout_fields()
			: $checkout->checkout_fields;

		if ( 'yes' === $this->settings['enable_login_app'] ) {
			// Some merchants might not have access to billing address information, so we need to make those fields optional
			// when the order doesn't require shipping.
			if ( ! apply_filters( 'woocommerce_amazon_show_address_widget', WC()->cart->needs_shipping() ) ) {
				foreach ( $optional_fields as $field ) {
					$checkout_fields['billing'][ $field ]['required'] = false;
				}
			}
			$this->add_hidden_class_to_fields( $checkout_fields['billing'], $all_fields );
		} else {
			// Cannot grab user details when not using login app. Need to unset all fields.
			$this->unset_fields_from_checkout( $checkout_fields['billing'], $all_fields );
		}

		$checkout->checkout_fields = $checkout_fields;
	}

	/**
	 * Hijack shipping checkout field.
	 *
	 * @since 1.7.0
	 *
	 * @param WC_Checkout $checkout WC_Checkout instance.
	 */
	private function hijack_checkout_field_shipping( $checkout ) {
		$field_list      = array(
			'shipping_first_name',
			'shipping_last_name',
			'shipping_company',
			'shipping_country',
			'shipping_address_1',
			'shipping_address_2',
			'shipping_city',
			'shipping_state',
			'shipping_postcode',
		);
		$checkout_fields = version_compare( WC_VERSION, '3.0', '>=' )
			? $checkout->get_checkout_fields()
			: $checkout->checkout_fields;

		if ( 'yes' === $this->settings['enable_login_app'] && apply_filters( 'woocommerce_amazon_show_address_widget', WC()->cart->needs_shipping() ) ) {
			$this->add_hidden_class_to_fields( $checkout_fields['shipping'], $field_list );
		} else {
			$this->unset_fields_from_checkout( $checkout_fields['shipping'], $field_list );
		}

		$checkout->checkout_fields = $checkout_fields;
	}

	/**
	 * Adds hidden class to checkout field
	 *
	 * @param array $field reference to the field to be hidden.
	 */
	private function add_hidden_class_to_field( &$field ) {
		if ( isset( $field['class'] ) ) {
			array_push( $field['class'], 'hidden' );
		} else {
			$field['class'] = array( 'hidden' );
		}
	}

	/**
	 * Adds hidden class to checkout fields based on a list
	 *
	 * @param array $checkout_fields reference to checkout fields.
	 * @param array $field_list fields to be hidden.
	 */
	private function add_hidden_class_to_fields( &$checkout_fields, $field_list ) {
		foreach ( $field_list as $field ) {
			$this->add_hidden_class_to_field( $checkout_fields[ $field ] );
		}
	}

	/**
	 * Removes fields from checkout based on a list
	 *
	 * @param array $checkout_fields reference to checkout fields.
	 * @param array $field_list fields to be removed.
	 */
	private function unset_fields_from_checkout( &$checkout_fields, $field_list ) {
		foreach ( $field_list as $field ) {
			unset( $checkout_fields[ $field ] );
		}
	}

	/**
	 * Render the Amazon Pay widgets when an order is updated to require
	 * payment, and the Amazon gateway is available.
	 *
	 * @param array $fragments Fragments.
	 *
	 * @return array
	 */
	public function update_amazon_widgets_fragment( $fragments ) {

		$available_gateways = WC()->payment_gateways()->get_available_payment_gateways();

		if ( WC()->cart->needs_payment() ) {

			ob_start();

			$this->checkout_message();

			$fragments['.wc-amazon-checkout-message:not(.wc-amazon-payments-advanced-populated)'] = ob_get_clean();

			if ( array_key_exists( 'amazon_payments_advanced', $available_gateways ) ) {

				ob_start();

				$this->address_widget();

				$this->payment_widget();

				$fragments['#amazon_customer_details:not(.wc-amazon-payments-advanced-populated)'] = ob_get_clean();
			}
		}

		return $fragments;

	}

	/**
	 * Force a page refresh when an order is updated to have a zero total and
	 * we're not using the "login app" mode.
	 *
	 * This ensures that the standard WC checkout form is rendered.
	 *
	 * @param WC_Cart $cart Cart object.
	 */
	public function force_standard_mode_refresh_with_zero_order_total( $cart ) {
		// Avoid constant reload loop in the event we've forced a checkout refresh.
		if ( ! is_ajax() ) {
			unset( WC()->session->reload_checkout );
		}

		// Login app mode can handle zero-total orders.
		if ( 'yes' === $this->settings['enable_login_app'] ) {
			return;
		}

		if ( ! wc_apa()->get_gateway()->is_available() ) {
			return;
		}

		// Get the previous cart total.
		$previous_total = WC()->session->wc_amazon_previous_total;

		// Store the current total.
		WC()->session->wc_amazon_previous_total = $cart->total;

		// If the total is non-zero, and we don't know what the previous total was, bail.
		if ( is_null( $previous_total ) || $cart->needs_payment() ) {
			return;
		}

		// This *wasn't* as zero-total order, but is now.
		if ( $previous_total > 0 ) {
			// Force reload, re-rendering standard WC checkout form.
			WC()->session->reload_checkout = true;
		}
	}

	/**
	 * Checkout Button
	 *
	 * Triggered from the 'woocommerce_proceed_to_checkout' action.
	 */
	public function checkout_button() {
		$subscriptions_installed = class_exists( 'WC_Subscriptions_Order' ) && function_exists( 'wcs_create_renewal_order' );
		$subscriptions_enabled   = empty( $this->settings['subscriptions_enabled'] ) || 'yes' == $this->settings['subscriptions_enabled'];
		$cart_contains_sub       = class_exists( 'WC_Subscriptions_Cart' ) ? WC_Subscriptions_Cart::cart_contains_subscription() : false;

		if ( $subscriptions_installed && ! $subscriptions_enabled && $cart_contains_sub ) {
			return;
		}

		echo '<div id="pay_with_amazon"></div>';
	}

	/**
	 * Maybe hide standard WC checkout button on the cart, if enabled
	 */
	public function maybe_hide_standard_checkout_button() {
		if ( 'yes' === $this->settings['enabled'] && 'yes' === $this->settings['hide_standard_checkout_button'] ) {
			?>
				<style type="text/css">
					.woocommerce a.checkout-button,
					.woocommerce input.checkout-button,
					.cart input.checkout-button,
					.cart a.checkout-button,
					.widget_shopping_cart a.checkout {
						display: none !important;
					}
				</style>
			<?php
		}
	}

	/**
	 * Maybe hides Amazon Pay buttons on cart or checkout pages if hide button mode
	 * is enabled.
	 *
	 * @since 1.6.0
	 */
	public function maybe_hide_amazon_buttons() {
		$hide_button_mode_enabled = ( 'yes' === $this->settings['enabled'] && 'yes' === $this->settings['hide_button_mode'] );
		$hide_button_mode_enabled = apply_filters( 'woocommerce_amazon_payments_hide_amazon_buttons', $hide_button_mode_enabled );

		if ( ! $hide_button_mode_enabled ) {
			return;
		}

		?>
		<style type="text/css">
			.wc-amazon-payments-advanced-info, #pay_with_amazon {
				display: none;
			}
		</style>
		<?php
	}

	/**
	 * When SCA, we hijack "Place Order Button" and perform our own custom Checkout.
	 */
	public function ajax_sca_processing() {
		check_ajax_referer( 'sca_nonce', 'nonce' );
		// Get $_POST and $_REQUEST compatible with process_checkout.
		parse_str( $_POST['data'], $_POST );
		$_REQUEST = $_POST;

		WC()->checkout()->process_checkout();
		wp_send_json_success();
	}

	/**
	 * Init Amazon login app widget.
	 */
	public function init_amazon_login_app_widget() {
		$redirect_page = is_cart() ? add_query_arg( 'amazon_payments_advanced', 'true', get_permalink( wc_get_page_id( 'checkout' ) ) ) : add_query_arg( array( 'amazon_payments_advanced' => 'true', 'amazon_logout' => false ) );
		?>
		<script type='text/javascript'>
		  	function getURLParameter(name, source) {
			return decodeURIComponent((new RegExp('[?|&|#]' + name + '=' +
				'([^&]+?)(&|#|;|$)').exec(source) || [,""])[1].replace(/\+/g,
				'%20')) || null;
			}

			var accessToken = getURLParameter("access_token", location.hash);

			if (typeof accessToken === 'string' && accessToken.match(/^Atza/)) {
				document.cookie = "amazon_Login_accessToken=" + encodeURIComponent(accessToken) +
				";secure";
				window.location = '<?php echo esc_js( esc_url_raw( $redirect_page ) ); ?>';
			}
		</script>
		<script>
			window.onAmazonLoginReady = function() {
				amazon.Login.setClientId( "<?php echo esc_js( $this->settings['app_client_id'] ); ?>" );
				jQuery( document ).trigger( 'wc_amazon_pa_login_ready' );
			};
		</script>
		<?php
	}

	/**
	 * Filter Ajax endpoint so it carries the query string after buyer is
	 * redirected from Amazon.
	 *
	 * Commit 75cc4f91b534ce3114d19da80586bacd083bb5a8 from WC 3.2 replaces the
	 * REQUEST_URI with `/` so that query string from current URL is not carried.
	 * This plugin hooked into checkout related actions/filters that might be
	 * called from Ajax request and expecting some parameters from query string.
	 *
	 * @since 1.8.0
	 *
	 * @param string $url     Ajax URL.
	 * @param string $request Request type. Expecting only 'checkout'.
	 *
	 * @return string URL.
	 */
	public function filter_ajax_endpoint( $url, $request ) {
		if ( 'checkout' !== $request ) {
			return $url;
		}

		if ( ! empty( $_GET['amazon_payments_advanced'] ) ) {
			$url = add_query_arg( 'amazon_payments_advanced', $_GET['amazon_payments_advanced'], $url );
		}
		if ( ! empty( $_GET['access_token'] ) ) {
			$url = add_query_arg( 'access_token', $_GET['access_token'], $url );
		}

		return $url;
	}

	/**
	 * Ajax handler for fetching order reference
	 */
	public function ajax_get_order_reference() {
		check_ajax_referer( 'order_reference_nonce', 'nonce' );

		if ( empty( $_POST['order_reference_id'] ) ) {
			wp_send_json(
				new WP_Error( 'amazon_missing_order_reference_id', __( 'Missing order reference ID.', 'woocommerce-gateway-amazon-payments-advanced' ) ),
				400
			);
		}

		if ( empty( $this->access_token ) ) {
			wp_send_json(
				new WP_Error( 'amazon_missing_access_token', __( 'Missing access token. Make sure you are logged in.', 'woocommerce-gateway-amazon-payments-advanced' ) ),
				400
			);
		}

		$order_reference_id = sanitize_text_field( wp_unslash( $_POST['order_reference_id'] ) );

		$response = wc_apa()->get_gateway()->get_amazon_order_details( $order_reference_id );

		if ( $response ) {
			wp_send_json( $response );
		} else {
			wp_send_json(
				new WP_Error( 'amazon_get_order_reference_failed', __( 'Failed to get order reference data. Make sure you are logged in and trying to access a valid order reference owned by you.', 'woocommerce-gateway-amazon-payments-advanced' ) ),
				400
			);
		}
	}

	/**
	 * Maybe display declined notice.
	 *
	 * @since 1.7.1
	 * @version 1.7.1
	 */
	public function maybe_display_declined_notice() {
		if ( ! empty( $_GET['amazon_declined'] ) ) {
			wc_add_notice( __( 'There was a problem with previously declined transaction. Please try placing the order again.', 'woocommerce-gateway-amazon-payments-advanced' ), 'error' );
		}
	}

	/**
	 * Add scripts
	 */
	public function scripts() {

		$enqueue_scripts = is_cart() || is_checkout() || is_checkout_pay_page();

		if ( ! apply_filters( 'woocommerce_amazon_pa_enqueue_scripts', $enqueue_scripts ) ) {
			return;
		}

		$js_suffix = '.min.js';
		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
			$js_suffix = '.js';
		}

		$type = ( 'yes' == $this->settings['enable_login_app'] ) ? 'app' : 'standard';

		wp_enqueue_style( 'amazon_payments_advanced', plugins_url( 'assets/css/style.css', __FILE__ ), array(), $this->version );
		wp_enqueue_script( 'amazon_payments_advanced_widgets', WC_Amazon_Payments_Advanced_API::get_widgets_url(), array(), $this->version, true );
		wp_enqueue_script( 'amazon_payments_advanced', plugins_url( 'assets/js/amazon-' . $type . '-widgets' . $js_suffix, __FILE__ ), array(), $this->version, true );

		$redirect_page = is_cart() ? add_query_arg( 'amazon_payments_advanced', 'true', get_permalink( wc_get_page_id( 'checkout' ) ) ) : add_query_arg( array( 'amazon_payments_advanced' => 'true', 'amazon_logout' => false ) );

		$params = array(
			'ajax_url'              => admin_url( 'admin-ajax.php' ),
			'seller_id'             => $this->settings['seller_id'],
			'reference_id'          => $this->reference_id,
			'redirect'              => esc_url_raw( $redirect_page ),
			'is_checkout_pay_page'  => is_checkout_pay_page(),
			'is_checkout'           => is_checkout(),
			'access_token'          => $this->access_token,
			'logout_url'            => esc_url_raw( $this->get_amazon_logout_url() ),
			'render_address_widget' => apply_filters( 'woocommerce_amazon_show_address_widget', WC()->cart->needs_shipping() ),
			'order_reference_nonce' => wp_create_nonce( 'order_reference_nonce' ),
		);

		if ( 'yes' == $this->settings['enable_login_app'] ) {

			$params['button_type']     = $this->settings['button_type'];
			$params['button_color']    = $this->settings['button_color'];
			$params['button_size']     = $this->settings['button_size'];
			$params['button_language'] = $this->settings['button_language'];
			$params['checkout_url']    = esc_url_raw( get_permalink( wc_get_page_id( 'checkout' ) ) );

		}

		if ( WC()->session->amazon_declined_code ) {
			$params['declined_code'] = WC()->session->amazon_declined_code;
			unset( WC()->session->amazon_declined_code );
		}

		if ( WC()->session->amazon_declined_with_cancel_order ) {
			$order                           = wc_get_order( WC()->session->amazon_declined_order_id );
			$params['declined_redirect_url'] = add_query_arg(
				array(
					'amazon_payments_advanced' => 'true',
					'amazon_logout'            => 'true',
					'amazon_declined'          => 'true',
				),
				$order->get_cancel_order_url()
			);

			unset( WC()->session->amazon_declined_order_id );
			unset( WC()->session->amazon_declined_with_cancel_order );
		}

		if ( class_exists( 'WC_Subscriptions_Cart' ) ) {

			$cart_contains_subscription      = WC_Subscriptions_Cart::cart_contains_subscription() || wcs_cart_contains_renewal();
			$change_payment_for_subscription = isset( $_GET['change_payment_method'] ) && wcs_is_subscription( absint( $_GET['change_payment_method'] ) );
			$params['is_recurring']          = $cart_contains_subscription || $change_payment_for_subscription;

			// No need to make billing agreement if automatic payments is turned off.
			if ( 'yes' === get_option( 'woocommerce_subscriptions_turn_off_automatic_payments' ) ) {
				unset( $params['is_recurring'] );
			}
		}

		// SCA support. If Merchant is European Region and Order does not contain or is a subscriptions.
		$params['is_sca'] = ( WC_Amazon_Payments_Advanced_API::is_sca_region() );
		if ( $params['is_sca'] ) {
			$params['sca_nonce'] = wp_create_nonce( 'sca_nonce' );
		}

		// Multi-currency support.
		$multi_currency                         = WC_Amazon_Payments_Advanced_Multi_Currency::is_active();
		$params['multi_currency_supported']     = $multi_currency;
		$params['multi_currency_nonce']         = wp_create_nonce( 'multi_currency_nonce' );
		$params['multi_currency_reload_wallet'] = ( $multi_currency ) ? WC_Amazon_Payments_Advanced_Multi_Currency::reload_wallet_widget() : false;
		$params['current_currency']             = ( $multi_currency ) ? WC_Amazon_Payments_Advanced_Multi_Currency::get_selected_currency() : '';
		$params['shipping_title']               =  __( 'Shipping details', 'woocommerce' );
		$params['redirect_authentication']      = $this->settings['redirect_authentication'];

		$params = array_map( 'esc_js', apply_filters( 'woocommerce_amazon_pa_widgets_params', $params ) );

		wp_localize_script( 'amazon_payments_advanced', 'amazon_payments_advanced_params', $params );

		do_action( 'wc_amazon_pa_scripts_enqueued', $type, $params );
	}
}
