<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://#
 * @since      1.0.0
 *
 * @package    Florex_Woocommerce
 * @subpackage Florex_Woocommerce/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Florex_Woocommerce
 * @subpackage Florex_Woocommerce/admin
 * @author     2DIGIT d.o.o. <florjan@2digit.eu>
 */
use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;

class Florex_Woocommerce_Admin {

	protected $standalone_options;
	protected $duplicate_order_options;
	protected $webhook_update_url = 'https://app.florex.io/external/woocommerce/order/update';
	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Database manager
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      Florex_Woocommerce_Db_Manager    $db_manager
	 */
	private $db_manager;

	/**
	 * Status manager
	 *
	 * @since    3.1.0
	 * @access   private
	 * @var      Florex_Woocommerce_Status    $status
	 */
	private $status_manager;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string    $plugin_name       The name of this plugin.
	 * @param      string    $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version = $version;

		$this->load_dependencies();

		add_action( 'admin_init', array( $this, 'pixel_setup_sections' ) );
		add_action( 'admin_init', array( $this, 'pixel_setup_fields' ) );

		add_action( 'admin_menu', array( $this, 'options_add_plugin_page' ) );
		add_action( 'admin_init', array( $this, 'options_page_init' ) );

		add_action( 'add_meta_boxes', array( $this, 'digit_render_journey_metabox' ) );
		add_action( 'save_post', array($this, 'digit_custom_save_post') );

        add_action( 'admin_post_florex_cleanup_webhooks', [ $this, 'florex_cleanup_webhooks' ] );

		/**
		 * Cron tasks
		 */
		add_action( 'init', [$this, 'schedule_my_cron_events'] );
		add_action( 'cron_digit_once_a_day', [$this, 'digit_clean_expired_hashes'] );


        /**
		 * Rest API
		 */
		//add_filter('woocommerce_rest_prepare_product_object', array($this, 'digit_wc_rest_api_adjust_response_data'), 10, 3); Comment zaradi ONET sync product issue-a on product save
		add_action('rest_api_init', [$this, 'digit_register_custom_rest_api_endpoint']);

		/**
		 * Webhooks
		 */
		add_action('woocommerce_init', [$this, 'register_webhook']);

		// Since 15.1.2024
		add_filter( 'manage_edit-shop_order_columns', [$this, 'digit_add_custom_orders_column'], 10 );
		add_action( 'manage_shop_order_posts_custom_column' , [$this, 'digit_custom_orders_column_content'], 10, 2 );
	}

	public function load_dependencies() {
		require_once plugin_dir_path( __DIR__ ) . 'public/partials/class-florex-woocommerce-db-manager.php';
		require_once plugin_dir_path( __DIR__ ) . 'admin/partials/florex-woocommerce-status.php';

		$this->db_manager = new Florex_Woocommerce_Db_Manager();
		$this->status_manager = new Florex_Woocommerce_Status_Manager();
	}

	public function schedule_my_cron_events() {

		if ( ! wp_next_scheduled( 'cron_digit_once_a_day') ) {
			wp_schedule_event( strtotime('03:00:00'), 'daily', 'cron_digit_once_a_day' );
		}
	}

	public function digit_clean_expired_hashes() {
		try {
			global $wpdb;

			$standalone_options = get_option( 'standalone_option_config' );
			$cookie_lifetime = (isset($standalone_options['cookie_lifetime']) && is_numeric($standalone_options['cookie_lifetime'])) ? $standalone_options['cookie_lifetime'] : 7;
			$visitor_lifetime = (isset($standalone_options['visitor_lifetime']) && is_numeric($standalone_options['visitor_lifetime'])) ? $standalone_options['visitor_lifetime'] : 3;
			/**Delete expired hashes */
			$expired = gmdate('Y-m-d', strtotime("-$cookie_lifetime days"));

			$wpdb->query(
				"DELETE FROM " . $wpdb->prefix . "digit_tracking_user_meta 
				 WHERE DATE(date) < '$expired'");

			/**Delete expired attributions */
			$expired = gmdate('Y-m-d', strtotime("-$cookie_lifetime days"));

			$wpdb->query(
				"DELETE FROM " . $wpdb->prefix . "digit_tracking_user_attribution 
				 WHERE DATE(date) < '$expired'");

			/**Delete expired visitors */
			$expired = gmdate('Y-m-d', strtotime("-$visitor_lifetime days"));

			$wpdb->query(
				"DELETE FROM " . $wpdb->prefix . "digit_tracking_sku_events 
				 WHERE DATE(date) < '$expired'");
		} catch(\Exception $e) {
			error_log("failed removing expired digit_tracking_user_meta or digit_tracking_sku_events rows. ".$e->getMessage());
		}
		
	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {

		wp_enqueue_style( $this->plugin_name."-select2", plugin_dir_url( __FILE__ ) . 'css/select2.css', array(), $this->version, 'all' );
		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/florex-woocommerce-admin.css', array(), $this->version, 'all' );

	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts() {

		wp_enqueue_script( $this->plugin_name."-select2", plugin_dir_url( __FILE__ ) . 'js/select2.js', array( 'jquery' ), $this->version, false );
		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/florex-woocommerce-admin.js', array( 'jquery' ), $this->version, false );

	}

	public function options_add_plugin_page() {
		add_menu_page(
			'Florex', // page_title
			'Florex', // menu_title
			'manage_options', // capability
			'florex', // menu_slug
			array( $this, 'options_create_admin_page' ), // function
			'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNTQyIiBoZWlnaHQ9IjUzNCIgdmlld0JveD0iMCAwIDU0MiA1MzQiIGZpbGw9Im5vbmUiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+CjxwYXRoIGQ9Ik0wIDEyMy44NTFMNjYuOTEzNiAyMzguMjk4TDEzNi41MDQgMTE5LjU1Mkg0NzMuNDhMNTQyIDBINjYuOTEzNkwwIDEyMy44NTFaIiBmaWxsPSJibGFjayIvPgo8cGF0aCBkPSJNMjM5LjkwNCAyOTQuMDlIMzc1LjA3TDQ0MS44MTEgMTc3LjIyNEgxNzEuOTJMMzQuODgxMiA0MTYuMDZMMTAxLjc5NSA1MzRMMjM5LjkwNCAyOTQuMDlaIiBmaWxsPSJibGFjayIvPgo8L3N2Zz4K', // icon_url
			3 // position
		);
	}

	public function options_create_admin_page() {
		$this->standalone_options = get_option( 'standalone_option_config' ); 
		$this->duplicate_order_options = get_option( 'florex_duplicates_options' ); 
		
		$this->set_defaults($this->standalone_options);
		$this->set_duplicate_defaults($this->duplicate_order_options);

		$this->standalone_options = get_option( 'standalone_option_config' );
		$this->duplicate_order_options = get_option( 'florex_duplicates_options' ); 

		$preview = $this->get_preview($this->standalone_options);

		//Get the active tab from the $_GET param
		$default_tab = null;
		$tab = isset($_GET['tab']) ? $_GET['tab'] : $default_tab;
		?>

		<style>.update-nag, .updated, .error, .is-dismissible:not(#setting-error-settings_updated) { display: none !important; }</style>

		<div class="wrap florex-wrapper">
			<a class="florex-logo" href="https://florex.io?utm_source=florex_woo_plugin&utm_medium=undefined" target="_blank">
				<img src="<?php echo plugin_dir_url( __FILE__ ) . '/assets/logo.png' ?>" alt="Florex logo" style="max-width: 350px !important">
			</a>

			<nav class="nav-tab-wrapper">
				<a href="?page=florex" class="nav-tab <?php if($tab===null):?>nav-tab-active<?php endif; ?>"><?php echo __("Dashboard", "florex-woocommerce") ?></a>
				<a href="?page=florex&tab=duplicate-order" class="nav-tab <?php if($tab==='duplicate-order'):?>nav-tab-active<?php endif; ?>"><?php echo __("Duplicate order prevention", "florex-woocommerce") ?></a>
				<a href="?page=florex&tab=status" class="nav-tab <?php if($tab==='status'):?>nav-tab-active<?php endif; ?>"><?php echo __("Status", "florex-woocommerce") ?></a>
					<a href="?page=florex&tab=advanced" class="nav-tab <?php if($tab==='advanced'):?>nav-tab-active<?php endif; ?>"><?php echo __("Advanced", "florex-woocommerce") ?></a>
				<?php
				/*
				*
				*<a href="?page=florex&tab=fb-pixel" class="nav-tab <?php if($tab==='fb-pixel'):?>nav-tab-active<?php endif; ?>">Facebook Pixel</a>
				*	
				*/
					?>
			</nav>

			<div class="tab-content">
				<?php switch($tab) :
				case 'fb-pixel':
					?>
					<?php settings_errors(); ?>
					<form method="POST" action="options.php">
						<?php
							settings_fields( 'Standalone_tracking' );
							do_settings_sections( 'Standalone_tracking' );
							submit_button();
						?>
						<?php wp_nonce_field( 'pixel_update', 'florex_pixel_update_nonce' ); ?>
					</form>
					<?php
					break;
				case 'duplicate-order':
					?>
					<?php settings_errors(); ?>
					<form method="POST" action="options.php">
						<?php
							settings_fields( 'florex_duplicates_option_group' );
							do_settings_sections( 'florex-admin' );
							submit_button();
						?>
						<?php wp_nonce_field( 'duplicate_prevention', 'florex_duplicate_prevent_nonce' ); ?>
					</form>
					<?php
					break;
				case 'status':
					if ($this->webhook_exists($this->webhook_update_url)) {
						echo '<h3>Webhook Status</h3>';
						echo '<p class="flx-success">The order update webhook is registered and active on this store.</p>';
						?>
						<label for="webhook_secret"><b>Webhook secret key:</b>
							<input type="text" id="webhook_secret" style="min-width: 320px" readonly value="<?= $this->get_webhook_secret() ?>">
						</label>
						<p>Make sure the secret key is entered in Florex under <b>Settings > Shops</b> for this store.</p>
						<?php
					} else {
						echo '<h3>Webhook Status</h3>';
						echo '<p style="flx-error">The webhook is not registered or inactive.</p>';
					}

                    /* -----------------------------------------------------------
                     *  Cleanup button – appears only if there is at least 1
                     *  disabled/failed/paused Florex webhook.
                     * --------------------------------------------------------- */
                    global $wpdb;
                    $excess = $wpdb->get_var( "
                        SELECT COUNT(*) FROM {$wpdb->prefix}wc_webhooks
                        WHERE status IN ('disabled','failed','paused')
                        AND delivery_url LIKE '%florex.io%'" );

                    if ( $excess ) {
                        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:20px">';
                        wp_nonce_field( 'florex_cleanup_webhooks' );
                        echo '<input type="hidden" name="action" value="florex_cleanup_webhooks">';
                        submit_button(
                            sprintf( 'Delete %d disabled Florex webhook%s', $excess, $excess > 1 ? 's' : '' ),
                            'delete'
                        );
                        echo '</form>';
                    }

                    /* Show notice after redirect */
                    if ( isset( $_GET['flx_msg'] ) && $_GET['flx_msg'] === 'cleaned' ) {
                        echo '<p class="notice notice-success"><b>Done:</b> excess webhooks removed.</p>';
                    } elseif ( isset( $_GET['flx_msg'] ) && $_GET['flx_msg'] === 'nothing' ) {
                        echo '<p class="notice notice-info">No disabled Florex webhooks found.</p>';
                    }
					?>
					<?php $this->status_manager->test_databases(); ?>
					<?php
					break;
				case 'advanced':
					?>
					<div class="standalone_admin_grid">
						<div>
							<?php settings_errors(); ?>
					
							<form method="post" action="options.php">
								<?php
									settings_fields( 'standalone_option_group' );
									do_settings_sections( 'options-admin' );
									submit_button();
								?>
							</form>
						</div>
					
						<div>
							<h3><?php echo __('URL Builder preview', 'florex-woocommerce'); ?></h3>
							<div class='standalone-utm-preview' style="width: 100%" rows="10"><?php echo $preview ?></div>
						</div>
					</div>
					<?php
					break;
				default:
					?>
					<a href="https://florex.io?utm_source=florex_woo_plugin&utm_medium=undefined" target="_blank">
						<img src="<?php echo plugin_dir_url( __FILE__ ) . '/assets/florex-banner-v2.jpg' ?>" alt="Florex banner" style="width: 100%">
					</a>
					<?php
					break;
				endswitch; ?>
			</div>

			<h3><?php echo __("Florex / WooCommerce integration", "florex-woocommerce") ?> <?php echo sanitize_text_field($this->version) ?></h3>
			
		</div>
	<?php }

	public function get_preview($options) {
		return sprintf("%s={{site_source_name}}&%s=%s&%s={{campaign.name}}&%s={{adset.name}}&%s={{ad.name}}&%s={{campaign.id}}&%s={{adset.id}}&%s={{ad.id}}&%s={{placement}}",
			"<span data-param='site_source_name'>".$options['site_source_name']."</span>",
			"<span data-param='utm_medium'>".$options['utm_medium']."</span>",
			"<span data-param='utm_medium_value'>".$options['utm_medium_value']."</span>",
			"<span data-param='campaign_name'>".$options['campaign_name']."</span>",
			"<span data-param='adset_name'>".$options['adset_name']."</span>",
			"<span data-param='ad_name'>".$options['ad_name']."</span>",
			"<span data-param='campaign_id'>".$options['campaign_id']."</span>",
			"<span data-param='adset_id'>".$options['adset_id']."</span>",
			"<span data-param='ad_id'>".$options['ad_id']."</span>",
			"<span data-param='placement'>".$options['placement']."</span>"
		);
	}

	public function set_defaults($options) {
		$indexes = [
			'site_source_name' => 'utm_source',
			'utm_medium' => 'utm_medium',
			'utm_medium_value' => 'social',
			'campaign_name' => 'utm_campaign',
			'adset_name' => 'utm_content',
			'ad_name' => 'utm_term',
			'campaign_id' => 'utm_id',
			'adset_id' => 'fbc_id',
			'ad_id' => 'florex_id',
			'placement' => 'utm_placement',
			'cookie_lifetime' => 7,
			'visitor_lifetime' => 3
		];

		foreach($indexes as $index => $value) {
			if(! isset($options[$index]) || empty($options[$index]))
				$options[$index] = $value;
		}

		update_option('standalone_option_config', $options);
	}

	public function set_duplicate_defaults($options) {
		$indexes = [
			'restriction_time' => 24,
			'order_prevention_text' => "You have already placed an order on our site at [time_of_order] - that's why cash on delivery is not available to you. Feel free to use another payment method or contact our customer service at info@mysite.com. Your order number is [order_number].",
		];

		foreach($indexes as $index => $value) {
			if(! isset($options[$index]) || empty($options[$index]))
				$options[$index] = $value;
		}

		update_option('florex_duplicates_options', $options);
	}

	public function options_page_init() {
		register_setting(
			'standalone_option_group', // option_group
			'standalone_option_config', // option_name
			array( $this, 'options_sanitize' ) // sanitize_callback
		);

		add_settings_section(
			'options_setting_section', // id
			'Parameters', // title
			array( $this, 'options_section_info' ), // callback
			'options-admin' // page
		);

		add_settings_field(
			'site_source_name', // id
			'{{site_source_name}}', // title
			array( $this, 'site_source_name_callback' ), // callback
			'options-admin', // page
			'options_setting_section' // section
		);

		add_settings_field(
			'utm_medium', // id
			'UTM Medium', // title
			array( $this, 'utm_medium_callback' ), // callback
			'options-admin', // page
			'options_setting_section' // section
		);

		add_settings_field(
			'utm_medium_value', // id
			'UTM Medium default value', // title
			array( $this, 'utm_medium_value_callback' ), // callback
			'options-admin', // page
			'options_setting_section' // section
		);

		add_settings_field(
			'campaign_name', // id
			'{{campaign.name}}', // title
			array( $this, 'campaign_name_callback' ), // callback
			'options-admin', // page
			'options_setting_section' // section
		);

		add_settings_field(
			'adset_name', // id
			'{{adset.name}}', // title
			array( $this, 'adset_name_callback' ), // callback
			'options-admin', // page
			'options_setting_section' // section
		);

		add_settings_field(
			'ad_name', // id
			'{{ad.name}}', // title
			array( $this, 'ad_name_callback' ), // callback
			'options-admin', // page
			'options_setting_section' // section
		);

		add_settings_field(
			'campaign_id', // id
			'{{campaign.id}}', // title
			array( $this, 'campaign_id_callback' ), // callback
			'options-admin', // page
			'options_setting_section' // section
		);

		add_settings_field(
			'adset_id', // id
			'{{adset.id}}', // title
			array( $this, 'adset_id_callback' ), // callback
			'options-admin', // page
			'options_setting_section' // section
		);

		add_settings_field(
			'ad_id', // id
			'{{ad.id}}', // title
			array( $this, 'ad_id_callback' ), // callback
			'options-admin', // page
			'options_setting_section' // section
		);

		add_settings_field(
			'placement', // id
			'{{placement}}', // title
			array( $this, 'placement_callback' ), // callback
			'options-admin', // page
			'options_setting_section' // section
		);

		add_settings_field(
			'cookie_lifetime', // id
			'Cookie lifetime', // title
			array( $this, 'cookie_lifetime_callback' ), // callback
			'options-admin', // page
			'options_setting_section' // section
		);

		add_settings_field(
			'visitor_lifetime', // id
			'Unique visitor lifetime', // title
			array( $this, 'visitor_lifetime_callback' ), // callback
			'options-admin', // page
			'options_setting_section' // section
		);

		register_setting(
			'florex_duplicates_option_group', // option_group
			'florex_duplicates_options', // option_name
			array( $this, 'options_sanitize' ) // sanitize_callback
		);

		add_settings_section(
			'florex_duplicates_setting_section', // id
			'Duplicate order prevention', // title
			array( $this, 'options_section_info' ), // callback
			'florex-admin' // page
		);

		add_settings_field(
			'enable_duplicate_prevention', // id
			'Enable duplicate prevention module', // title
			array( $this, 'enable_duplicate_prevention_callback' ), // callback
			'florex-admin', // page
			'florex_duplicates_setting_section' // section
		);

		add_settings_field(
			'restriction_time', // id
			'Restriction time (hours):', // title
			array( $this, 'restriction_time_callback' ), // callback
			'florex-admin', // page
			'florex_duplicates_setting_section' // section
		);

		add_settings_field(
			'order_prevention_text', // id
			'Notification text on duplicate prevention', // title
			array( $this, 'order_prevention_text_callback' ), // callback
			'florex-admin', // page
			'florex_duplicates_setting_section' // section
		);
	}

	public function options_sanitize($input) {
		$sanitary_values = array();
		if ( isset( $input['site_source_name'] ) ) {
			$sanitary_values['site_source_name'] = sanitize_text_field( $input['site_source_name'] );
		}

		if ( isset( $input['utm_medium'] ) ) {
			$sanitary_values['utm_medium'] = sanitize_text_field( $input['utm_medium'] );
		}

		if ( isset( $input['utm_medium_value'] ) ) {
			$sanitary_values['utm_medium_value'] = sanitize_text_field( $input['utm_medium_value'] );
		}

		if ( isset( $input['campaign_name'] ) ) {
			$sanitary_values['campaign_name'] = sanitize_text_field( $input['campaign_name'] );
		}

		if ( isset( $input['adset_name'] ) ) {
			$sanitary_values['adset_name'] = sanitize_text_field( $input['adset_name'] );
		}

		if ( isset( $input['ad_name'] ) ) {
			$sanitary_values['ad_name'] = sanitize_text_field( $input['ad_name'] );
		}

		if ( isset( $input['campaign_id'] ) ) {
			$sanitary_values['campaign_id'] = sanitize_text_field( $input['campaign_id'] );
		}

		if ( isset( $input['adset_id'] ) ) {
			$sanitary_values['adset_id'] = sanitize_text_field( $input['adset_id'] );
		}

		if ( isset( $input['ad_id'] ) ) {
			$sanitary_values['ad_id'] = sanitize_text_field( $input['ad_id'] );
		}

		if ( isset( $input['placement'] ) ) {
			$sanitary_values['placement'] = sanitize_text_field( $input['placement'] );
		}

		if ( isset( $input['cookie_lifetime'] ) ) {
			$sanitary_values['cookie_lifetime'] = sanitize_text_field( $input['cookie_lifetime'] );
		}

		if ( isset( $input['visitor_lifetime'] ) ) {
			$sanitary_values['visitor_lifetime'] = sanitize_text_field( $input['visitor_lifetime'] );
		}

		if ( isset( $input['enable_duplicate_prevention'] ) ) {
			$sanitary_values['enable_duplicate_prevention'] = $input['enable_duplicate_prevention'];
		}

		if ( isset( $input['restriction_time'] ) ) {
			$sanitary_values['restriction_time'] = sanitize_text_field( $input['restriction_time'] );
		}

		if ( isset( $input['order_prevention_text'] ) ) {
			$sanitary_values['order_prevention_text'] = sanitize_text_field( $input['order_prevention_text'] );
		}

		return $sanitary_values;
	}

	public function options_section_info() {
		
	}

	public function site_source_name_callback() {
		printf(
			'<input class="regular-text" type="text" name="standalone_option_config[site_source_name]" id="site_source_name" value="%s">',
			isset( $this->standalone_options['site_source_name'] ) ? esc_attr( $this->standalone_options['site_source_name']) : ''
		);
	}

	public function utm_medium_callback() {
		printf(
			'<input class="regular-text" type="text" name="standalone_option_config[utm_medium]" id="utm_medium" value="%s">',
			isset( $this->standalone_options['utm_medium'] ) ? esc_attr( $this->standalone_options['utm_medium']) : ''
		);
	}

	public function utm_medium_value_callback() {
		printf(
			'<input class="regular-text" type="text" name="standalone_option_config[utm_medium_value]" id="utm_medium_value" value="%s">',
			isset( $this->standalone_options['utm_medium_value'] ) ? esc_attr( $this->standalone_options['utm_medium_value']) : ''
		);
	}

	public function campaign_name_callback() {
		printf(
			'<input class="regular-text" type="text" name="standalone_option_config[campaign_name]" id="campaign_name" value="%s">',
			isset( $this->standalone_options['campaign_name'] ) ? esc_attr( $this->standalone_options['campaign_name']) : ''
		);
	}

	public function adset_name_callback() {
		printf(
			'<input class="regular-text" type="text" name="standalone_option_config[adset_name]" id="adset_name" value="%s">',
			isset( $this->standalone_options['adset_name'] ) ? esc_attr( $this->standalone_options['adset_name']) : ''
		);
	}

	public function ad_name_callback() {
		printf(
			'<input class="regular-text" type="text" name="standalone_option_config[ad_name]" id="ad_name" value="%s">',
			isset( $this->standalone_options['ad_name'] ) ? esc_attr( $this->standalone_options['ad_name']) : ''
		);
	}

	public function campaign_id_callback() {
		printf(
			'<input class="regular-text" type="text" name="standalone_option_config[campaign_id]" id="campaign_id" value="%s">',
			isset( $this->standalone_options['campaign_id'] ) ? esc_attr( $this->standalone_options['campaign_id']) : ''
		);
	}

	public function adset_id_callback() {
		printf(
			'<input class="regular-text" type="text" name="standalone_option_config[adset_id]" id="adset_id" value="%s">',
			isset( $this->standalone_options['adset_id'] ) ? esc_attr( $this->standalone_options['adset_id']) : ''
		);
	}

	public function ad_id_callback() {
		printf(
			'<input class="regular-text" type="text" name="standalone_option_config[ad_id]" id="ad_id" value="%s">',
			isset( $this->standalone_options['ad_id'] ) ? esc_attr( $this->standalone_options['ad_id']) : ''
		);
	}

	public function placement_callback() {
		printf(
			'<input class="regular-text" type="text" name="standalone_option_config[placement]" id="placement" value="%s">',
			isset( $this->standalone_options['placement'] ) ? esc_attr( $this->standalone_options['placement']) : ''
		);
	}

	public function cookie_lifetime_callback() {
		printf(
			'<input class="regular-text" type="number" name="standalone_option_config[cookie_lifetime]" id="cookie_lifetime" value="%s">',
			isset( $this->standalone_options['cookie_lifetime'] ) ? esc_attr( $this->standalone_options['cookie_lifetime']) : ''
		);
	}

	public function visitor_lifetime_callback() {
		printf(
			'<input class="regular-text" type="number" name="standalone_option_config[visitor_lifetime]" id="visitor_lifetime" value="%s">',
			isset( $this->standalone_options['visitor_lifetime'] ) ? esc_attr( $this->standalone_options['visitor_lifetime']) : ''
		);
	}

	public function enable_duplicate_prevention_callback() {
		printf(
			'<input type="checkbox" name="florex_duplicates_options[enable_duplicate_prevention]" id="enable_duplicate_prevention" value="enable_duplicate_prevention" %s>',
			( isset( $this->duplicate_order_options['enable_duplicate_prevention'] ) && $this->duplicate_order_options['enable_duplicate_prevention'] === 'enable_duplicate_prevention' ) ? 'checked' : ''
		);
	}

	public function restriction_time_callback() {
		printf(
			'<input class="regular-text" type="number" name="florex_duplicates_options[restriction_time]" id="restriction_time" value="%s">',
			isset( $this->duplicate_order_options['restriction_time'] ) ? esc_attr( $this->duplicate_order_options['restriction_time']) : ''
		);
	}

	public function order_prevention_text_callback() {
		printf(
			'<textarea class="regular-text" rows="10" name="florex_duplicates_options[order_prevention_text]" id="order_prevention_text">%s</textarea>',
			isset( $this->duplicate_order_options['order_prevention_text'] ) ? esc_attr( $this->duplicate_order_options['order_prevention_text']) : ''
		);

		?>
		<div class="florex-available-inputs">
			<?php echo __("Available values: ", "florex-woocommerce") ?><b>[date_of_order]</b>, <b>[time_of_order]</b>, <b>[order_number]</b>
		</div>
		<?php
	}

	function digit_render_journey_metabox() {
		$screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) && wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

		add_meta_box( 'digit_standalone_tracking', __('Florex - Customer journey','florex-woocommerce'), [$this, 'digit_render_journey_display_cb'], $screen, 'normal', 'core' );

		add_meta_box( 'digit_standalone_tracking_sku', __('Connect with SKU','florex-woocommerce'), [$this, 'digit_connect_with_sku_cb'], null, 'side', 'high' );
		remove_meta_box('digit_standalone_tracking_sku', $screen, 'side');
	}

	public function pixel_setup_sections() {
		add_settings_section( 'Standalone_tracking_section', '', array(), 'Standalone_tracking' );
	}

	public function pixel_setup_fields() {
		$fields = array(
                    array(
                        'section' => 'Standalone_tracking_section',
                        'label' => 'Enable Conversion API (add the token below)',
                        'id' => 'standalone_enable_conversion_api',
                        'type' => 'checkbox',
                    ),
        
                    array(
                        'section' => 'Standalone_tracking_section',
                        'label' => 'Enable Advanced Matching',
                        'id' => 'standalone_advanced_matching',
                        'type' => 'checkbox',
                    ),
        
                    array(
                        'section' => 'Standalone_tracking_section',
                        'label' => 'Meta Pixel (formerly Facebook Pixel) ID:',
                        'id' => 'standalone_meta_pixel_id',
                        'type' => 'text',
                    ),
        
                    array(
                        'section' => 'Standalone_tracking_section',
                        'label' => 'Conversion API:',
                        'id' => 'standalone_meta_conversion_api',
                        'type' => 'textarea',
                    ),
        
                    array(
                        'section' => 'Standalone_tracking_section',
                        'label' => 'test_event_code :',
                        'id' => 'standalone_test_event_code',
                        'type' => 'text',
                    )
		);
		foreach( $fields as $field ){
			add_settings_field( $field['id'], $field['label'], array( $this, 'pixel_field_callback' ), 'Standalone_tracking', $field['section'], $field );
			register_setting( 'Standalone_tracking', $field['id'] );
		}
	}
	public function pixel_field_callback( $field ) {
		$value = get_option( $field['id'] );
		$placeholder = '';
		if ( isset($field['placeholder']) ) {
			$placeholder = $field['placeholder'];
		}
		switch ( $field['type'] ) {
            
            
                        case 'checkbox':
                            printf('<input %s id="%s" name="%s" type="checkbox" value="1">',
                                $value === '1' ? 'checked' : '',
                                $field['id'],
                                $field['id']
                        );
                            break;

                        case 'textarea':
                            printf( '<textarea name="%1$s" id="%1$s" placeholder="%2$s" rows="5" cols="50">%3$s</textarea>',
                                $field['id'],
                                $placeholder,
                                $value
                                );
                                break;
            
			default:
				printf( '<input name="%1$s" id="%1$s" type="%2$s" placeholder="%3$s" value="%4$s" />',
					$field['id'],
					$field['type'],
					$placeholder,
					$value
				);
		}
		if( isset($field['desc']) ) {
			if( $desc = $field['desc'] ) {
				printf( '<p class="description">%s </p>', $desc );
			}
		}
	}

	function digit_render_journey_display_cb() {
		global $post;

		if($this->db_manager) {

			$journey = $this->db_manager->getOrderJourney($post->ID);
			
			if($journey) {

				$this->digit_render_journey($post->ID, $journey);

			} else {

				_e('No UTM records found for this order.', 'florex-woocommerce');

			}

		}
	}

	function digit_render_journey($id_order, $journey) {
		$st = 0;

		if($st == count($journey)) {
			$last = 0;
		} else {
			$last = count($journey)-1;
		}

		$first_attribution = $this->db_manager->getOrderJourneyByAttribution($id_order, ['attribution' => 'first']);
		$last_attribution = $this->db_manager->getOrderJourneyByAttribution($id_order, ['attribution' => 'last']);

		if(isset($first_attribution) && isset($first_attribution[0])) $first_attribution = $first_attribution[0];
		if(isset($last_attribution) && isset($last_attribution[0])) $last_attribution = $last_attribution[0];

		

		echo "<table class='digit-journey'>";
		foreach($journey as $row) {
			
			if($st == 0 && $last != 0) echo "<tr><th colspan='2'><h3>".__('First click', 'florex-woocommerce')."</h3></th></tr>";

			elseif($st == 0 && $last == 0) echo "<tr><th colspan='2'><h3>".__('First & only click', 'florex-woocommerce')."</h3></th></tr>"; 

			elseif($st > 0 && $st == $last) echo "<tr><th colspan='2'><h3>".__('Last click', 'florex-woocommerce')."</h3></th></tr>";

			else echo "<tr><th colspan='2'><h3>".__('Click number: ', 'florex-woocommerce').($st+1)."</h3></th></tr>";

			$this->digit_render_journey_row_details($row, $first_attribution, $last_attribution);

			$st++;
		}
		echo '</table>';
	}

	function digit_connect_with_sku_cb() {
		global $pagenow;

		if ( 'post.php' === $pagenow && isset($_GET['post']) && 'product' === get_post_type( $_GET['post'] ) ) {
        	echo __("SKU is automatically connected on products.", "florex-woocommerce");
    	} else {
			$args = array(
				'post_type' => 'product', 
				'posts_per_page' => -1,
				'post_status'    => array( 'publish', 'private' )
			);
			
			$products = get_posts($args);
			
			if (count($products) && isset($_GET['post'])) {
				?>
				<select name="_associated_sku" id="_associated_sku">
					<option value="">Select SKU</option>
				<?php
				foreach ($products as $productPost) {
					$productSKU = get_post_meta($productPost->ID, '_sku', true);
					$selectedSKU = get_post_meta($_GET['post'], '_associated_sku', true);
			
					if(isset($productSKU) && ! empty($productSKU)) {
						$selected = (isset($selectedSKU) && $selectedSKU == $productSKU) ? "selected" : "";
						echo '<option value="'.$productSKU.'" '.$selected.'>' . $productSKU . '</option>';
					}
						
				}
				?>
				</select>
				<?php wp_nonce_field( 'florex_associate_sku', 'florex_associate_sku_nonce' ); ?>
				<?php
			}
		}
	}

	function digit_custom_save_post( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) 
			return;

		if ( ! isset( $_POST['florex_associate_sku_nonce'] ) || ! wp_verify_nonce( $_POST['florex_associate_sku_nonce'], 'florex_associate_sku' ) ) {
		   return;
		}

		if(isset($_POST['_associated_sku']) && ! empty($_POST['_associated_sku'])) {
			$associated_sku = sanitize_text_field($_POST['_associated_sku']);
			update_post_meta($post_id, '_associated_sku', $associated_sku);
		} else {
			delete_post_meta($post_id, '_associated_sku');
		}
		
	}

	function digit_render_journey_row_details($row, $first_attribution = null, $last_attribution = null) {
		$product_id = (isset($row->landing)) ? $this->get_product_id_by_slug($row->landing) : null;
		if($product_id && $product = wc_get_product($product_id)) {
			$permalink = $product->get_permalink();
			$url = sprintf("<a href=%s>%s</a>", $permalink, $permalink);
		} else {
			$url = $row->landing;
		}

		$badges = $this->attribution_badge_display($row, $first_attribution, $last_attribution);
		if(isset($badges) && is_array($badges) && count($badges) > 0): ?>
			<tr class='journey-row utm-badges'>
				<th><?php foreach($badges as $badge): echo '<div class="journey-badge">'.$badge.'</div>'; endforeach; ?></th>
			</tr>
			<?php endif; ?>

		<?php if(isset($row->date)): ?>
		<tr class='journey-row utm-date'>
			<th><?php echo __('Time of visit'); ?></th>
			<td><?php echo $row->date ?></td>
		</tr>
		<?php endif; ?>

		<?php if(isset($row->landing) && $row->landing): ?>
		<tr class='journey-row utm-source'>
			<th>Landing slug</th>
			<td><?php echo $url ?></td>
		</tr>
		<?php endif; ?>

		<?php if(isset($row->utm_source) && $row->utm_source): ?>
		<tr class='journey-row utm-source'>
			<th>UTM Source</th>
			<td><?php echo $row->utm_source ?></td>
		</tr>
		<?php endif; ?>

		<?php if(isset($row->utm_medium) && $row->utm_medium): ?>
		<tr class='journey-row utm-medium'>
			<th>UTM Medium</th>
			<td><?php echo $row->utm_medium ?></td>
		</tr>
		<?php endif; ?>

		<?php if(isset($row->placement) && $row->placement): ?>
		<tr class='journey-row utm-placement'>
			<th>UTM Placement</th>
			<td><?php echo $row->placement ?></td>
		</tr>
		<?php endif; ?>

		<?php if((isset($row->campaign_name) && $row->campaign_name != "") || (isset($row->campaign_id) && $row->campaign_id != "")): ?>
		<tr class='journey-row campaign'>
			<th>UTM Campaign</th>
			<td><?php echo isset($row->campaign_name) ? $row->campaign_name : "/" ?> <?php echo isset($row->campaign_id) ? "(ID: ".$row->campaign_id.")" : "/" ?></td>
		</tr>
		<?php endif; ?>

		<?php if((isset($row->adset_name) && $row->adset_name != "") || (isset($row->adset_id) && $row->adset_id != "")): ?>
		<tr class='journey-row adset'>
			<th>UTM Adset</th>
			<td><?php echo isset($row->adset_name) ? $row->adset_name : "/" ?> <?php echo isset($row->adset_id) ? "(ID: ".$row->adset_id.")" : "/" ?></td>
		</tr>
		<?php endif; ?>

		<?php if((isset($row->ad_name) && ! empty($row->ad_name)) || (isset($row->ad_id) && ! empty($row->ad_id))): ?>
		<tr class='journey-row ad'>
			<th>UTM Ad</th>
			<td><?php echo isset($row->ad_name) ? $row->ad_name : "/" ?> <?php echo isset($row->ad_id) ? "(ID: ".$row->ad_id.")" : "/" ?></td>
		</tr>
		<?php endif; ?>
		<?php
	}

	function attribution_badge_display($row, $first_attribution = null, $last_attribution = null) {
		$labels = [];
		if(isset($row->id)) {
			if(isset($first_attribution) && isset($first_attribution->id) && $first_attribution->id == $row->id) {
				$label = __("First attribution", "florex-woocommerce");
				array_push($labels, $label);
			}

			if(isset($last_attribution) && isset($last_attribution->id) && $last_attribution->id == $row->id) {
				$label = __("Last attribution", "florex-woocommerce");
				array_push($labels, $label);
			}
		}
		return $labels;
	}

	function get_product_id_by_slug( $slug, $post_type = "product" ) {
		$query = new WP_Query(
			array(
				'name'   => $slug,
				'post_type'   => $post_type,
				'numberposts' => 1,
				'fields'      => 'ids',
			) );
		$posts = $query->get_posts();
		return array_shift( $posts );
	}

	/**
	 * Improve Woo API, fetch only selected fields
	 * Commented in 3.0.3, ONET issue (sync conflict)
	 */
	function digit_wc_rest_api_adjust_response_data( $response, $object, $request ) {

		$params = $request->get_params();
		if ( ! isset($params['fields_in_response']) ) {
			return $response;
		}

		$data = $response->get_data();  
		$cropped_data = array();

		foreach ( $params['fields_in_response'] as $field ) {
			$cropped_data[ $field ] = $data[ $field ];
		}   

		$response->set_data( $cropped_data );   

		return $response;

	}

	function digit_add_custom_orders_column( $columns ) {
		$columns['florex_sync'] = __( 'Florex Sync', 'florex-woocommerce' );
		return $columns;
	}

	function digit_custom_orders_column_content( $column, $post_id ) {
		if ( 'florex_sync' === $column ) {
			// Replace 'your_meta_key' with the actual meta key you want to check
			$value = get_post_meta( $post_id, '_florex_sync', true );
	
			if ( $value ) {
				// Output a checkmark if true
				echo '<span class="dashicons dashicons-yes-alt florex-synced"></span>'; // Checkmark symbol
			} else {
				// Output a cross if false
				echo '<span class="dashicons dashicons-no"></span>'; // Cross symbol
			}
		}
	}

	/**
	 * Custom REST API endpoints
	 */

	function digit_register_custom_rest_api_endpoint() {
		register_rest_route( 'wc/v3', '/orders-utm-data/', array(
					'methods'  => 'GET',
					'callback' => [$this, 'digit_get_orders_utm_data_api'],
					'permission_callback' => function() {
						return current_user_can( 'edit_posts' );
					}
			));

		register_rest_route( 'wc/v3', '/florex-products/', array(
			'methods'  => 'GET',
			'callback' => [$this, 'digit_api_get_products'],
			'permission_callback' => function() {
				return current_user_can( 'edit_posts' );
			}
		));

		register_rest_route( 'wc/v3', '/florex-sku-metrics/', array(
			'methods'  => 'GET',
			'callback' => [$this, 'digit_api_get_sku_metrics'],
			'permission_callback' => function() {
				return current_user_can( 'edit_posts' );
			}
		));

		// Added 10.8.2023
		register_rest_route( 'wc/v3', '/orders-journeys/', array(
			'methods'  => 'GET',
			'callback' => [$this, 'digit_get_orders_journeys'],
			'permission_callback' => function() {
				return current_user_can( 'edit_posts' );
			}
		));

		// Added 15.1.2024
		register_rest_route( 'wc/v3', '/florex-sync/', array(
			'methods' => 'POST',
			'callback' => [$this, 'update_florex_sync'],
			'permission_callback' => function() {
				return current_user_can( 'edit_posts' );
			}
		));

		register_rest_route( 'wc/v3', '/florex-sync/', array(
			'methods' => 'GET',
			'callback' => [$this, 'digit_check_latest_order_sync'],
			'permission_callback' => function() {
				return current_user_can( 'edit_posts' );
			}
		));
	}

	function digit_get_orders_utm_data_api($request) {

		$args = array(
			'ids' => $request['include']
		);

		$attribution = (isset($request['attribution'])) ? $request['attribution'] : ['attribution' => "first"];
		//error_log("attr is ".json_encode($attribution));
	
		if(isset($args['ids'])) {

			foreach($args['ids'] as $id_order) {
				$journey = $this->db_manager->getOrderJourneyByAttribution($id_order, $attribution);

				$result[$id_order] = $journey;
			}
		}
	
		$response = new WP_REST_Response($result);
		$response->set_status(200);
	
		return $response;
	}

	function digit_get_orders_journeys($request) {

		$args = array(
			'ids' => $request['include']
		);

		//error_log("attr is ".json_encode($attribution));

		$result = [];
		$data = [];

		if(isset($args['ids']) && is_array($args['ids']) && count($args['ids']) > 0) {

			$result = $this->db_manager->getOrdersJourneys(implode(",", $args['ids']));

			if(is_array($result) && count($result) > 0) {
				foreach($result as $row) {
					$id_order = $row->id_order;
					$data[$id_order][] = $row;
				}
			}

		}
	
		$response = new WP_REST_Response($data);
		$response->set_status(200);
	
		return $response;
	}

    /* ========================================================================
     *   Helper: get the localized product-base  (cached per request)
     * ===================================================================== */
    private function get_product_base(): string {

        static $base = null;                           // use cache after first call

        if ( $base === null ) {
            $permalinks = (array) get_option( 'woocommerce_permalinks' );
            $base       = $permalinks['product_base'] ?? '';

            if ( $base === '' ) {                      // Woo default fallback
                $base = '/product/';
            }

            $base = trailingslashit( '/' . ltrim( $base, '/' ) );   // “/produs/”
        }

        return $base;
    }

    /* ========================================================================
     *  Helper: ensure a slug starts with that base (idempotent)
     * ===================================================================== */
    private function add_product_prefix( string $slug ): string {

        $slug        = ltrim( $slug, '/' );           // “tratament-instant…”
        $productBase = $this->get_product_base();     // “/produs/”
        $baseNoLead  = ltrim( $productBase, '/' );    // “produs/”

        return $baseNoLead . $slug;                  // “produs/tratament-instant…”
    }

    function digit_api_get_products($request) {
        $data = [];

        $args = array(
            'page' => (isset($request['page'])) ? $request['page'] : 1,
            'per_page' => (isset($request['per_page'])) ? $request['per_page'] : 250,
        );

        $products = $this->db_manager->getProductsOptimized($args['page'], $args['per_page']);

        if(is_array($products) && ! empty($products)) {
            foreach($products as $key => $product) {
                if(isset($product->post_id) && ! empty($product->post_id)) {
                    $_product = (! isset($product->associated_post_id)) ? wc_get_product($product->post_id) : wc_get_product(wc_get_product_id_by_sku($product->associated_post_id));

                    if($_product) {
                        if(isset($product->associated_post_id)) { // treated as landing page, dont prepend base
                            $url = get_permalink($product->post_id) ?? null;
                            $slug = get_post_field('post_name', $product->post_id) ?? null;
                        } else { // is product page
                            $url = $_product->get_permalink() ?? null;
                            $slug = $_product->get_slug() ?? null;
                            $slug = $this->add_product_prefix( $slug );
                        }

                        $sku = $_product->get_sku();

                        if ($_product->is_type('variation')) {
                            $parent_product_id = $_product->get_parent_id();
                            $parent_product = wc_get_product($parent_product_id);
                            if($parent_product) {
                                $parent_sku = $parent_product->get_sku();
                                $slug = $this->add_product_prefix( $parent_product->get_slug() );
                            } else {
                                continue;
                            }
                        } else {
                            $parent_sku = $sku;
                        }

                        if(empty($parent_sku)) $parent_sku = $sku;

                        $old_slugs = $product->old_slugs ?? null;

                        if ( ! empty( $old_slugs ) ) {
                            $old_slugs_arr = array_filter( array_map( 'trim', explode( ',', $old_slugs ) ) );
                            $old_slugs_arr = array_map( [ $this, 'add_product_prefix' ], $old_slugs_arr );
                            $old_slugs     = implode( ',', $old_slugs_arr );
                        }

                        $data[$key] = [
                            "id_product" => $product->post_id,
                            "parent_sku" => $parent_sku ?? null,
                            "sku" => $sku ?? null,
                            "type" => $_product->get_type(),
                            "status" => $product->post_status ?? null,
                            "catalog_visibility" => $_product->get_catalog_visibility() ?? null,
                            "url" => $url,
                            "slug" => $slug,
                            "old_slugs" => $old_slugs,
                            "date_updated" => date("Y-m-d H:i:s")
                        ];
                    }
                }
            }
        }

        $response = new WP_REST_Response($data);
        $response->set_status(200);

        return $response;

    }

	function digit_api_get_sku_metrics($request) {
		$data = [];

		if(! isset($request['date'])) {
			$response = new WP_REST_Response(['error' => "No date specified."]);
			$response->set_status(404);
		}

		$args = array(
			'key' => (isset($request['key'])) ? $request['key'] : "visit",
			'date' => $request['date']
		);

		$sku_metrics = $this->db_manager->getSkuMetrics($args['key'], $args['date']);

		if(isset($sku_metrics) && ! empty($sku_metrics)) {
			$response = new WP_REST_Response($sku_metrics);
			$response->set_status(200);
		} else {
			$response = new WP_REST_Response(['error' => "No data retrieved."]);
			$response->set_status(404);
		}

		return $response;
	}

	function update_florex_sync( $request ) {
		$ids = $request->get_param( 'ids' );
	
		if ( empty( $ids ) || !is_array( $ids ) ) {
			return new WP_Error( 'invalid_request', 'No IDs provided.', array( 'status' => 400 ) );
		}
	
		foreach ( $ids as $id ) {
			update_post_meta( $id, '_florex_sync', '1' );
		}
	
		return new WP_REST_Response( array( 'message' => 'Florex sync done successfully', 'status' => 200 ) );
	}

	function digit_check_latest_order_sync( $request ) {
		// Get the latest order with '_florex_sync' set to '1'
		$args = array(
			'limit' => 1,
			'orderby' => 'ID',
			'order' => 'DESC',
			'meta_key' => '_florex_sync',
			'meta_value' => '1'
		);
		$last_synced_order = wc_get_orders($args);
	
		if (empty($last_synced_order)) {
			return new WP_REST_Response( array( 'is_florex_synced' => false, 'message' => 'No synced orders found', 'status' => 404 ) );
		}
	
		// Get count of all orders before the last synced order
		global $wpdb;

		$count_query = "
			SELECT COUNT(*)
			FROM {$wpdb->posts}
			WHERE post_type = 'shop_order'
			AND ID > %d
		";
		
		$earlier_orders_count = $wpdb->get_var($wpdb->prepare($count_query, $last_synced_order[0]->get_id()));

		if($earlier_orders_count > 0)
			return new WP_REST_Response( array( 'is_florex_synced' => false, 'earlier_orders_count' => $earlier_orders_count, 'status' => 200 ) );
		else
			return new WP_REST_Response( array( 'is_florex_synced' => true, 'status' => 200 ) );
	}

	// Webhooks
	private function generate_and_store_secret() {
		$option_name = 'florex_webhook_secret';
	
		$existing_secret = get_option($option_name);
		if ($existing_secret) {
			return $this->decrypt_secret($existing_secret);
		}
	
		$secret = wp_generate_password(32, false);
	
		$encoded_secret = $this->encode_secret($secret);
	
		update_option($option_name, $encoded_secret);
	
		return $secret;
	}
	
	private function encode_secret($secret) {
		$key = wp_salt('auth');
		$combined = $key . $secret;
		return base64_encode($combined);
	}
	
	private function decode_secret($encoded) {
		$key = wp_salt('auth');
		$decoded = base64_decode($encoded);
	
		if (strpos($decoded, $key) === 0) {
			return substr($decoded, strlen($key));
		}
	
		return false;
	}
	
	public function get_webhook_secret() {
		$option_name = 'florex_webhook_secret';
		$encoded_secret = get_option($option_name);
	
		if ($encoded_secret) {
			return $this->decode_secret($encoded_secret);
		}
	
		return null;
	}

	public function register_webhook() {
		$webhook_secret = $this->get_webhook_secret();
		if (!$webhook_secret) {
			$webhook_secret = $this->generate_and_store_secret();
		}
	
		$delivery_url = $this->webhook_update_url;

		if ($this->webhook_exists($delivery_url)) {
			// Webhook already exists
			return;
		}

		// Create and save a new webhook
		$webhook = new WC_Webhook();
		$webhook->set_name('Florex Order Status Update');
		$webhook->set_topic('order.updated');
		$webhook->set_delivery_url($delivery_url);
		$webhook->set_secret($webhook_secret);
		$webhook->set_status('active');
		$webhook->save();
	}	

	private function webhook_exists($delivery_url) {
		global $wpdb;
	
		$table_name = $wpdb->prefix . 'wc_webhooks';
		$query = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table_name} WHERE delivery_url = %s",
			$delivery_url
		);
	
		$count = $wpdb->get_var($query);
		return $count > 0;
	}

    /**
     * Delete ALL disabled/failed/paused webhooks whose delivery_url contains
     * “florex.io”. Redirects back with ?flx_msg=cleaned (or nothing deleted).
     */
    public function florex_cleanup_webhooks(): void {

        check_admin_referer( 'florex_cleanup_webhooks' );
        global $wpdb;

        $table = $wpdb->prefix . 'wc_webhooks';

        // IDs to delete
        $ids = $wpdb->get_col( "
                SELECT webhook_id FROM {$table}
                WHERE status IN ('disabled','failed','paused')
                AND delivery_url LIKE '%florex.io%'" );

        if ( $ids ) {
            // delete rows (WC will cascade to logs)
            $in = implode( ',', array_map( 'absint', $ids ) );
            $wpdb->query( "DELETE FROM {$table} WHERE webhook_id IN ( {$in} )" );
            $flag = 'cleaned';
        } else {
            $flag = 'nothing';
        }

        wp_safe_redirect( add_query_arg( 'flx_msg', $flag, wp_get_referer() ) );
        exit;
    }
}
