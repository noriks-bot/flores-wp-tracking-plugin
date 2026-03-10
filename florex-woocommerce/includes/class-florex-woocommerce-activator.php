<?php

/**
 * Fired during plugin activation
 *
 * @link       https://#
 * @since      1.0.0
 *
 * @package    Florex_Woocommerce
 * @subpackage Florex_Woocommerce/includes
 */

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      1.0.0
 * @package    Florex_Woocommerce
 * @subpackage Florex_Woocommerce/includes
 * @author     2DIGIT d.o.o. <florjan@2digit.eu>
 */
class Florex_Woocommerce_Activator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function activate() {

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;

		/**
		 * 
		 * digit_tracking_user_meta table (holds user product prices)
		 */
		$tablename = $wpdb->prefix.'digit_tracking_user_meta'; 

		$main_sql_create = "CREATE TABLE `$tablename` 
		( 
			`id` INT NOT NULL AUTO_INCREMENT , 
			`id_user` VARCHAR(20) NOT NULL , 
			`id_order` INT NULL , 
			`landing` TEXT NULL , 
			`utm_source` VARCHAR(100) NULL , 
			`utm_medium` TEXT NULL ,
			`campaign_name` TEXT NULL ,
			`adset_name` TEXT NULL ,
			`ad_name` TEXT NULL ,
			`campaign_id` VARCHAR(50) NULL ,
			`adset_id` VARCHAR(50) NULL , 
			`ad_id` VARCHAR(50) NULL ,
			`placement` TEXT NULL ,
			`date` DATETIME NOT NULL ,
			PRIMARY KEY (`id`)
		)";

		$query = $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $tablename ) );

		if ( ! $wpdb->get_var( $query ) == $tablename ) {
			maybe_create_table( $wpdb->prefix . $tablename, $main_sql_create );
		}
		

		/**
		 * 
		 * digit_tracking_user_attribution table (holds triggered events sent to pixel)
		 */
		$tablename = $wpdb->prefix.'digit_tracking_user_attribution'; 

		$main_sql_create = "CREATE TABLE `$tablename` 
		( 
			`id` INT NOT NULL AUTO_INCREMENT , 
			`id_user` VARCHAR(20) NOT NULL , 
			`campaign_name` TEXT NULL ,
			`adset_name` TEXT NULL ,
			`ad_name` TEXT NULL ,
			`campaign_id` VARCHAR(50) NULL ,
			`adset_id` VARCHAR(50) NULL , 
			`ad_id` VARCHAR(50) NULL ,
			`date` DATETIME NOT NULL ,
			PRIMARY KEY (`id`)
		)";

		$query = $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $tablename ) );

		if ( ! $wpdb->get_var( $query ) == $tablename ) {
			maybe_create_table( $wpdb->prefix . $tablename, $main_sql_create );
		}

		/**
		 * 
		 * digit_tracking_user_pixel_events table (holds triggered events sent to pixel)
		 */
		$tablename = $wpdb->prefix.'digit_tracking_user_pixel_events'; 
		$reference = $wpdb->prefix.'digit_tracking_user_attribution'; 

		$main_sql_create = "CREATE TABLE `$tablename` 
		( 
			`id` INT NOT NULL AUTO_INCREMENT , 
			`id_user` VARCHAR(20) NOT NULL , 
			`event` VARCHAR(50) NOT NULL ,
			`id_attribution` INT NOT NULL , 
			`date` DATETIME NOT NULL ,
			PRIMARY KEY (`id`),
			FOREIGN KEY (`id_attribution`) REFERENCES $reference(`id`) ON DELETE CASCADE
		)";

		$query = $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $tablename ) );

		if ( ! $wpdb->get_var( $query ) == $tablename ) {
			maybe_create_table( $wpdb->prefix . $tablename, $main_sql_create );
		}

		/**
		 * 
		 * digit_tracking_sku_events table (holds visitors per SKU)
		 */
		$tablename = $wpdb->prefix.'digit_tracking_sku_events'; 

		$main_sql_create = "CREATE TABLE `$tablename` 
		( 
			`id` INT NOT NULL AUTO_INCREMENT , 
			`post_id` INT NULL , 
			`sku` VARCHAR(255) NOT NULL , 
			`event` VARCHAR(50) NOT NULL ,
			`date` DATETIME NOT NULL ,
			PRIMARY KEY (`id`)
		)";

		$query = $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $tablename ) );

		if ( ! $wpdb->get_var( $query ) == $tablename ) {
			maybe_create_table( $wpdb->prefix . $tablename, $main_sql_create );
		}
	}

}
