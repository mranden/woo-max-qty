<?php
/*
* Plugin Name: Woo Max Qty
* Description: Set a maximum quantity for products in WooCommerce.
* Version: 1.0
* Author: Andreas Pedersen
* Plugin URI:  https://bo-we.dk/
* Author:      Bo-we
* Author URI:  https://bo-we.dk/
* License:     GPL2
* License URI: https://www.gnu.org/licenses/gpl-2.0.html
* Version:     1.0
* Text Domain: woo-max-qty
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

define( 'WOO_MAX_QTY', dirname( plugin_basename( __FILE__ ) ) . '/languages' );

class Woo_Max_Qty {
    
    public function __construct() {
        add_action('woocommerce_product_options_inventory_product_data', array($this, 'add_max_qty_field'));
        add_action('woocommerce_process_product_meta', array($this, 'save_max_qty_field'));
        add_filter('woocommerce_add_to_cart_validation', array($this, 'validate_max_qty'), 10, 5);
        // Add filter to modify quantity input field
        add_filter('woocommerce_quantity_input_args', array($this, 'custom_qty_input_args'), 10, 2);
        add_filter('woo_max_qty_message_filter', array($this, 'modify_max_qty_message'), 10, 2);

        add_filter( 'rest_request_after_callbacks', [ $this, 'extend_cart_api_response' ], 10, 3 );
        
        //Translations
        add_action( 'plugins_loaded', [ $this, 'plugins_loaded' ] );
    }
    
    public function add_max_qty_field() {
        woocommerce_wp_text_input(array(
            'id' => '_max_qty',
            'label' => __('Maximum Quantity', 'woocommerce'),
            'description' => __('Set the maximum quantity a customer can buy.', 'woocommerce'),
            'type' => 'number',
            'custom_attributes' => array(
                'step' => '1',
                'min' => '1'
            )
        ));
    }

    public function save_max_qty_field($post_id) {
        $max_qty = isset($_POST['_max_qty']) ? $_POST['_max_qty'] : '';
        if (!empty($max_qty)) {
            update_post_meta($post_id, '_max_qty', esc_attr($max_qty));
        }
    }

    public function validate_max_qty($passed, $product_id, $quantity, $variation_id = 0, $variations= array()) {
        $max_qty = get_post_meta($product_id, '_max_qty', true);
        if ($max_qty && $quantity > $max_qty) {
            wc_add_notice(sprintf(__('Sorry, you can only purchase up to %s of this product.', 'woocommerce'), $max_qty), 'error');
            return false;
        }
        return $passed;
    }
     // Custom function to modify the quantity input arguments
     public function custom_qty_input_args($args, $product) {

        $product_id = $product->get_id();

        $max_qty = get_post_meta($product_id, '_max_qty', true);

        if( $max_qty > $args['max_value'] ) {
            return $args;
        }

        if (empty($max_qty)) {
            return $args; 
        }
        
        $args['max_value'] = $max_qty; // Set maximum value for quantity input

        return $args;
    }

    public function modify_max_qty_message($max_string, $max_value) {

        global $product;

        // Guardian pattern: Return early if not a single product page or $product is not set
        if ( !is_singular( 'product' ) || !is_a( $product, 'WC_Product' ) ) {
            return $max_string;
        }

        $product_id = $product->get_id();

        $max_qty = get_post_meta($product_id, '_max_qty', true);

        if( empty($max_qty) ) {
            return $max_string;
        }

        $product_stock = get_post_meta($product_id, '_stock', true); 

        if( $product_stock < $max_qty ) {
            return $max_string;
        }
        
        $max_string = sprintf(
            _n( 'Du kan maks købe %s produkt af dette', 'Vi har sat maks til %s produkter pr. kunde.', $max_value, 'bowe-woocommerce' ), 
            number_format_i18n( $max_value )
        ); 
        // Custom logic to modify the message
        // Return the modified message
        return $max_string;
    }

    public function plugins_loaded() {
        load_plugin_textdomain( 'woo-max-qty', false, WOO_MAX_QTY );
    }

    function extend_cart_api_response( $response, $server, $request ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( strpos( $request->get_route(), 'wc/store' ) === false ) {
			return $response;
		}

		$data = $response->get_data();

		if ( empty( $data[ 'items' ] ) ) {
			return $response;
		}

		$cart = WC()->cart->get_cart();
		// Perform your modifications to the cart item data based on your requirements

		foreach ( $data[ 'items' ] as &$item_data ) {

			$cart_item_key = $item_data[ 'key' ];
			$cart_item     = isset( $cart[ $cart_item_key ] ) ? $cart[ $cart_item_key ] : null;

			if ( is_null( $cart_item ) ) {
				continue;
			}

            $max_qty = get_post_meta( $item_data['id'], '_max_qty', true );

            if(empty($max_qty)) {
                continue;
            }

			$bundle = $cart_item[ 'data' ];
			 
			if ( ! $bundle->is_type( 'simple' ) && ! $bundle->is_type( 'bowe_bundle' )) {
				continue;
			}

			// Add the variation attributes to the API response
			$item_data['quantity_limits']->maximum = $max_qty;
		}

		$response->set_data( $data );


        return $response;
	}

}

new Woo_Max_Qty();
