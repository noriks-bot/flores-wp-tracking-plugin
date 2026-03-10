<?php

/**
 * Database manipulating helper functions
 *
 * @link       https://florex.io/
 * @since      3.1.0
 *
 * @package    Florex_Woocommerce
 * @subpackage Florex_Woocommerce/public/partials
 */

/**
 *
 * @package    Florex_Woocommerce
 * @subpackage Florex_Woocommerce/public/partials
 * @author     2DIGIT d.o.o. <florjan@2digit.eu>
 */
class Florex_Woocommerce_Status_Manager {

    protected $tables = [
        'digit_tracking_user_meta',
        'digit_tracking_user_attribution',
        'digit_tracking_user_pixel_events',
        'digit_tracking_sku_events'
    ];

    public function test_databases() {
        global $wpdb;

        ?>
        <h4><?php _e("Plugin-dependant databases status", "florex-woocommerce") ?></h4>
        <?php

        foreach($this->tables as $table_name) {
            $full_table_name = $wpdb->prefix.$table_name;
            $query = $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $full_table_name ) );

            if ( ! $wpdb->get_var( $query ) == $full_table_name ) {
                ?>
                <p class="flx-error"><?php echo str_replace("digit_", "", $table_name).' '.__("is missing", "florex-woocommerce") ?>!</p>
                <?php
            } else {
                ?>
                <p class="flx-success"><?php echo str_replace("digit_", "", $table_name).' '.__("is OK", "florex-woocommerce") ?>!</p>
                <?php
            }
        }
    }

}
?>