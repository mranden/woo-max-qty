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
        
        add_action('woocommerce_checkout_process', array($this, 'validate_checkout_max_qty'));

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

        woocommerce_wp_checkbox(array(
            'id' => '_max_qty_email_restrict',
            'label' => __('Restrict rule to email / User', 'woocommerce'),
            'description' => __('Enable to restrict the maximum quantity of this product per customer email.', 'woocommerce')
        ));

    }

    public function save_max_qty_field($post_id) {
        $max_qty = isset($_POST['_max_qty']) ? $_POST['_max_qty'] : '';
        $max_qty_email_restrict = isset($_POST['_max_qty_email_restrict']) ? 'yes' : 'no';
    
        update_post_meta($post_id, '_max_qty_email_restrict', $max_qty_email_restrict);

        if (!empty($max_qty)) {
            update_post_meta($post_id, '_max_qty', esc_attr($max_qty));
        }
    }

    public function validate_max_qty($passed, $product_id, $quantity, $variation_id = 0, $variations = array()) {
        $max_qty = get_post_meta($product_id, '_max_qty', true);
        $max_qty_email_restrict = get_post_meta($product_id, '_max_qty_email_restrict', true);
    
        if ($max_qty_email_restrict === 'yes') {
            $total_purchased = $this->get_customer_total_purchased($product_id, WC()->customer->get_billing_email());
    
            if ($total_purchased + $quantity > $max_qty) {
                $remaining = $max_qty - $total_purchased;
                wc_add_notice(sprintf(__('Sorry, you can only purchase up to %s more of this product.', 'woocommerce'), $remaining), 'error');
                return false;
            }
        } elseif ($max_qty && $quantity > $max_qty) {
            wc_add_notice(sprintf(__('Sorry, you can only purchase up to %s of this product.', 'woocommerce'), $max_qty), 'error');
            return false;
        }
    
        return $passed;
    }
    

    public function custom_qty_input_args($args, $product) {
        
        $product_id = $product->get_id();

        $max_qty = get_post_meta($product_id, '_max_qty', true);

        $max_qty_email_restrict = get_post_meta($product_id, '_max_qty_email_restrict', true);
    
        if ($max_qty_email_restrict === 'yes') {
            $total_purchased = $this->get_customer_total_purchased($product_id, WC()->customer->get_billing_email());
            $remaining = max($max_qty - $total_purchased, 0);
            $args['max_value'] = min($remaining, $args['max_value']);
        } else {
            $args['max_value'] = min($max_qty, $args['max_value']);
        }
    
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


    /*
    * Checkout validation
    */

    public function validate_checkout_max_qty() {
        
        $cart_items = WC()->cart->get_cart();
    
        //var_dump($cart_items);
    
    
        foreach ($cart_items as $cart_item_key => $values) {
            $_product = $values['data'];
            
            $product_id = $_product->get_id();


            $quantity = $values['quantity'];
            $max_qty = get_post_meta($product_id, '_max_qty', true);
            $max_qty_email_restrict = get_post_meta($product_id, '_max_qty_email_restrict', true);

            if ($max_qty_email_restrict === 'yes') {
                $total_purchased = $this->get_customer_total_purchased($product_id, $_POST['billing_email']);    
                
                if ($total_purchased + $quantity > $max_qty) {
                    $remaining = $max_qty - $total_purchased;
                    $product_name = $_product->get_name();

                    wc_add_notice( sprintf(__('Sorry, you can only purchase up to %s more of "%s".', 'woocommerce'), $remaining, $product_name), 'error' );
                }


                //wp_die(var_dump($total_purchased));

            }
        }


    }
    
    private function get_customer_total_purchased($product_id, $customer_email) {
        $total_purchased = 0;


        
    
        // Get orders by email
        $email_orders = wc_get_orders(array(
            'email' => $customer_email,
            'status' => array('wc-completed', 'wc-processing'),
            'return' => 'ids',
        ));

    
        $total_purchased += $this->calculate_purchased_from_orders($email_orders, $product_id);

        
    
        // If the user is logged in, get orders by user ID as well
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $user_orders = wc_get_orders(array(
                'customer_id' => $user_id,
                'status' => array('wc-completed', 'wc-processing'),
                'return' => 'ids',
                'exclude' => $email_orders, // Exclude orders already counted by email
            ));
    
            $total_purchased += $this->calculate_purchased_from_orders($user_orders, $product_id);
        }
    
        return $total_purchased;
    }
    
    private function calculate_purchased_from_orders($orders, $product_id) {
        $quantity = 0;
        foreach ($orders as $order_id) {
            $order = wc_get_order($order_id);
            foreach ($order->get_items() as $item) {
                if ($item->get_product_id() == $product_id || $item->get_variation_id() == $product_id) {
                    $quantity += $item->get_quantity();
                }
            }
        }
        return $quantity;
    }
    
    

}

new Woo_Max_Qty();
