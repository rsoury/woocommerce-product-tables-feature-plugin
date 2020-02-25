<?php
/**
 * When this functionality moves to core, this code will be moved into core directly and won't need to be hooked in!
 *
 * @package WooCommerce Product Tables Feature Plugin
 * @author Automattic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add extra fields for attributes.
 *
 * @param WC_Product_Attribute $attribute Attribute object.
 * @param int                  $i Index.
 */
function woocommerce_after_product_attribute_settings_custom_tables_support( $attribute, $i ) {
	?>
	<input type="hidden" name="attribute_product_attibute_ids[<?php echo esc_attr( $i ); ?>]" value="<?php echo esc_attr( $attribute->get_product_attribute_id() ); ?>" />
	<?php
}
add_action( 'woocommerce_after_product_attribute_settings', 'woocommerce_after_product_attribute_settings_custom_tables_support', 10, 2 );

/**
 * Add extra fields for attributes.
 *
 * @param WC_Product_Attribute $attribute Attribute object.
 * @param array                $data Post data.
 * @param int                  $i Index.
 */
function woocommerce_admin_meta_boxes_prepare_attribute_custom_tables_support( $attribute, $data, $i ) {
	$attribute_product_attibute_ids = $data['attribute_product_attibute_ids'];

	$attribute->set_product_attribute_id( absint( $attribute_product_attibute_ids[ $i ] ) );

	return $attribute;
}
add_filter( 'woocommerce_admin_meta_boxes_prepare_attribute', 'woocommerce_admin_meta_boxes_prepare_attribute_custom_tables_support', 10, 3 );

/**
 * Modify queries to use new table.
 *
 * @param array $query_vars WP Query vars.
 * @return array
 */
function woocommerce_modify_request_query_for_custom_tables( $query_vars ) {
	global $typenow, $wc_list_table;

	if ( 'product' !== $typenow ) {
		return $query_vars;
	}

	remove_filter( 'request', array( $wc_list_table, 'request_query' ) );

	if ( ! empty( $query_vars['product_type'] ) ) {
		if ( 'downloadable' === $query_vars['product_type'] ) {
			$query_vars['wc_products']['downloadable'] = 1;
		} elseif ( 'virtual' === $query_vars['product_type'] ) {
			$query_vars['wc_products']['virtual'] = 1;
		} else {
			$query_vars['wc_products']['type'] = $query_vars['product_type'];
		}
		unset( $query_vars['product_type'] );
	}

	if ( ! empty( $_REQUEST['stock_status'] ) ) { // WPCS: input var ok, CSRF ok.
		$query_vars['wc_products']['stock_status'] = wc_clean( wp_unslash( $_REQUEST['stock_status'] ) ); // WPCS: input var ok, CSRF ok.
		unset( $_GET['stock_status'] );
	}

	return $query_vars;
}

add_filter( 'request', 'woocommerce_modify_request_query_for_custom_tables', 5 );

/**
 * Handle filtering by type.
 *
 * @param array    $args Query args.
 * @param WP_Query $query Query object.
 * @return array
 */
function woocommerce_product_custom_tables_custom_query_vars( $args, $query ) {
	global $wpdb;

	if ( isset( $query->query_vars['wc_products'] ) ) {
		foreach ( $query->query_vars['wc_products'] as $key => $value ) {
			$key            = esc_sql( sanitize_key( $key ) );
			$args['where'] .= $wpdb->prepare( " AND wc_products.{$key} = %s ", $value ); // WPCS: db call ok, unprepared sql ok.
		}
	}

	return $args;
}

add_filter( 'posts_clauses', 'woocommerce_product_custom_tables_custom_query_vars', 10, 2 );

/**
 * Join product and post tables.
 *
 * @param array $args Query args.
 * @return array
 */
function woocommerce_product_custom_tables_join_product_to_post( $args ) {
	global $wpdb;
	$args['join'] .= " LEFT JOIN {$wpdb->prefix}wc_products wc_products ON $wpdb->posts.ID = wc_products.product_id ";
	return $args;
}

add_filter( 'posts_clauses', 'woocommerce_product_custom_tables_join_product_to_post' );




/**
 * Where meta_value is used to order query, order by meta value if set, otherwise by product table column
 *
 * @param array $args
 * @param WP_Query $context
 * @return void
 */
function woocommerce_product_custom_tables_order_by_case( $args, $context ) {
	global $wpdb;

	if (is_product_meta_query($context)) {
		$meta_key = $context->query['meta_key'];
		$args['orderby'] = '
			CASE
				WHEN ' . $wpdb->prefix . 'postmeta.meta_key = "' . $meta_key . '" THEN ' . $wpdb->prefix . 'postmeta.meta_value+0
				WHEN ' . $wpdb->prefix . 'postmeta.meta_key != "' . $meta_key . '" THEN wc_products.total_sales
			END
		' . strtoupper($context->query['order']);
	}

	return $args;
}

add_filter( 'posts_clauses', 'woocommerce_product_custom_tables_order_by_case', 10, 2 );

/**
 * Conditionally set meta_key query variable if product table column does not exist.
 * Used in conjunction with both posts_clauses filters
 *
 * @param array $sql
 * @param array $queries
 * @param string $type
 * @param string $primary_table
 * @param string $primary_id_column
 * @param object $context
 * @return array
 */
function woocommerce_product_custom_tables_conditional_meta_key( $sql, $queries, $type, $primary_table, $primary_id_column, $context ) {
	global $wpdb;

	if (is_product_meta_query($context)) {
		$meta_key = $context->query['meta_key'];
		// Trim meta key for _price meta -> price column compatibility.
		$column_key = ltrim($meta_key, '_');
		$sql['where'] = '
			AND (
				IF(
					NOT EXISTS (
						SELECT *
						FROM information_schema.COLUMNS
						WHERE
							TABLE_SCHEMA = "' . $wpdb->dbname . '"
							AND TABLE_NAME = "' . $wpdb->prefix . 'wc_products"
							AND COLUMN_NAME = "' . $column_key . '"
					),
					wp_postmeta.meta_key,
					"' . $meta_key . '"
				) = "' . $meta_key . '"
			)
		';
	}

	return $sql;
}

add_filter( 'get_meta_sql', 'woocommerce_product_custom_tables_conditional_meta_key', 10, 6 );

/**
 * Check if $context is WP_Query targets products and uses meta_value / meta_value_num for order
 *
 * @param object|WP_Query $context
 * @return boolean
 */
function is_product_meta_query($context){
	if ($context instanceof \WP_Query) {
		if (array_key_exists('post_type', $context->query)) {
			if ($context->query['post_type'] === 'product') {
				if (isset($context->query['meta_key']) && empty($context->query['meta_query'])) {
					return true;
				}
			}
		}
	}

	return false;
}
