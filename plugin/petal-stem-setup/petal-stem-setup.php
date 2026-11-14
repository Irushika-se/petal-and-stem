<?php
/**
 * Plugin Name: Petal & Stem Setup
 * Description: Builds a complete florist website on WooCommerce: pages, menu, styling, sample bouquet catalogue, delivery date / time slot / card message on every order, a Deliveries planner, a weddings & events enquiry form with inbox, a "Shop Details" settings page, top bar, footer, WhatsApp button and search-engine data. Requires WooCommerce.
 * Version: 1.2
 * Author: Petal & Stem
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PF_VERSION', '6' );

/* =====================================================================
 * 1. Shop details (Settings > Shop Details)
 * =================================================================== */
function pf_defaults() {
	return array(
		'phone'         => '',
		'whatsapp'      => '',
		'email'         => '',
		'address'       => '',
		'instagram'     => '',
		'facebook'      => '',
		'map'           => '',
		'hours_weekday' => '8:30 AM - 6:30 PM',
		'hours_sat'     => '8:30 AM - 6:00 PM',
		'hours_sun'     => '9:00 AM - 1:00 PM',
		'cutoff_hour'   => '14',
		'areas'         => 'Colombo and nearby suburbs',
	);
}

/* Real permalink for one of our pages (works with any permalink setting). */
function pf_page_url( $slug ) {
	$page = get_page_by_path( $slug );
	if ( $page && 'publish' === $page->post_status ) {
		return get_permalink( $page->ID );
	}
	return home_url( '/' );
}

function pf_detail( $key ) {
	$saved = get_option( 'pf_details', array() );
	$def   = pf_defaults();
	if ( is_array( $saved ) && isset( $saved[ $key ] ) && '' !== $saved[ $key ] ) {
		return $saved[ $key ];
	}
	return isset( $def[ $key ] ) ? $def[ $key ] : '';
}

add_action( 'admin_menu', function () {
	add_options_page( 'Shop Details', 'Shop Details', 'manage_options', 'pf-details', 'pf_details_page' );
} );

add_action( 'admin_init', function () {
	register_setting( 'pf_details_group', 'pf_details', array( 'sanitize_callback' => 'pf_sanitize_details' ) );
} );

function pf_sanitize_details( $in ) {
	$in  = is_array( $in ) ? $in : array();
	$out = array();
	foreach ( array( 'phone', 'address', 'hours_weekday', 'hours_sat', 'hours_sun', 'areas' ) as $k ) {
		$out[ $k ] = isset( $in[ $k ] ) ? sanitize_text_field( $in[ $k ] ) : '';
	}
	$out['whatsapp'] = isset( $in['whatsapp'] ) ? preg_replace( '/\D+/', '', $in['whatsapp'] ) : '';
	$out['email']    = isset( $in['email'] ) ? sanitize_email( $in['email'] ) : '';
	$hour            = isset( $in['cutoff_hour'] ) ? (int) $in['cutoff_hour'] : 14;
	$out['cutoff_hour'] = (string) max( 0, min( 23, $hour ) );
	foreach ( array( 'instagram', 'facebook' ) as $k ) {
		$u         = isset( $in[ $k ] ) ? esc_url_raw( trim( $in[ $k ] ) ) : '';
		$out[ $k ] = ( 0 === strpos( $u, 'https://' ) || 0 === strpos( $u, 'http://' ) ) ? $u : '';
	}
	$map        = isset( $in['map'] ) ? esc_url_raw( trim( $in['map'] ) ) : '';
	$out['map'] = preg_match( '#^https://www\.google\.com/maps/embed#', $map ) ? $map : '';
	return $out;
}

function pf_details_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$fields = array(
		'phone'         => array( 'Phone', 'Example: 011 234 5678' ),
		'whatsapp'      => array( 'WhatsApp number', 'With country code, digits only. Example: 94771234567' ),
		'email'         => array( 'Email', '' ),
		'address'       => array( 'Shop address', 'Street, city' ),
		'areas'         => array( 'Delivery areas (text)', 'Shown on the Delivery page. Example: Colombo, Dehiwala, Mount Lavinia' ),
		'cutoff_hour'   => array( 'Same-day cut-off hour (0-23)', '14 = orders before 2:00 PM can be delivered today. Later orders start from tomorrow.' ),
		'instagram'     => array( 'Instagram link', 'https://instagram.com/yourpage' ),
		'facebook'      => array( 'Facebook link', 'https://facebook.com/yourpage' ),
		'map'           => array( 'Google Map embed link', 'In Google Maps: Share > Embed a map > copy only the link inside src="..."' ),
		'hours_weekday' => array( 'Hours Monday - Friday', '' ),
		'hours_sat'     => array( 'Hours Saturday', '' ),
		'hours_sun'     => array( 'Hours Sunday', '' ),
	);
	echo '<div class="wrap"><h1>Shop Details</h1><p>Fill in your real details. They appear in the top bar, footer, Contact page and search-engine data. Empty fields are simply hidden.</p><form method="post" action="options.php">';
	settings_fields( 'pf_details_group' );
	$saved = get_option( 'pf_details', array() );
	$def   = pf_defaults();
	echo '<table class="form-table">';
	foreach ( $fields as $k => $f ) {
		$val = isset( $saved[ $k ] ) ? $saved[ $k ] : $def[ $k ];
		echo '<tr><th scope="row"><label for="pf_' . esc_attr( $k ) . '">' . esc_html( $f[0] ) . '</label></th><td><input type="text" class="regular-text" id="pf_' . esc_attr( $k ) . '" name="pf_details[' . esc_attr( $k ) . ']" value="' . esc_attr( $val ) . '"><p class="description">' . esc_html( $f[1] ) . '</p></td></tr>';
	}
	echo '</table>';
	submit_button();
	echo '</form></div>';
}

function pf_tel( $v ) {
	return preg_replace( '/[^0-9+]/', '', $v );
}

function pf_cutoff_label() {
	$h = (int) pf_detail( 'cutoff_hour' );
	return gmdate( 'g:i A', mktime( $h, 0, 0, 1, 1, 2000 ) );
}

/* =====================================================================
 * 2. Delivery date, time slot and card message (WooCommerce)
 * =================================================================== */
function pf_wc_active() {
	return class_exists( 'WooCommerce' );
}

function pf_slots() {
	return array( 'Morning (9 AM - 12 PM)', 'Afternoon (12 PM - 4 PM)', 'Evening (4 PM - 7 PM)' );
}

function pf_min_date() {
	$now = current_datetime();
	if ( (int) $now->format( 'G' ) < (int) pf_detail( 'cutoff_hour' ) ) {
		return $now->format( 'Y-m-d' );
	}
	return $now->modify( '+1 day' )->format( 'Y-m-d' );
}

function pf_max_date() {
	return current_datetime()->modify( '+90 days' )->format( 'Y-m-d' );
}

add_action( 'woocommerce_before_add_to_cart_button', function () {
	$min = pf_min_date();
	echo '<div class="pf-delivery"><h4>Delivery details</h4>';
	echo '<p class="pf-delivery-note">Order before ' . esc_html( pf_cutoff_label() ) . ' for same-day delivery. Delivery fee is shown at checkout.</p>';
	echo '<div class="pf-delivery-row"><label>Delivery date <input type="date" name="pf_date" min="' . esc_attr( $min ) . '" max="' . esc_attr( pf_max_date() ) . '" value="' . esc_attr( $min ) . '" required></label>';
	echo '<label>Time slot <select name="pf_slot" required>';
	foreach ( pf_slots() as $s ) {
		echo '<option value="' . esc_attr( $s ) . '">' . esc_html( $s ) . '</option>';
	}
	echo '</select></label></div>';
	echo '<label>Card message <span class="pf-opt">(optional, free)</span><textarea name="pf_card" rows="3" maxlength="200" placeholder="Happy birthday! Love, ..."></textarea></label></div>';
} );

add_filter( 'woocommerce_add_to_cart_validation', function ( $passed, $product_id ) {
	$date = isset( $_POST['pf_date'] ) ? sanitize_text_field( wp_unslash( $_POST['pf_date'] ) ) : '';
	$slot = isset( $_POST['pf_slot'] ) ? sanitize_text_field( wp_unslash( $_POST['pf_slot'] ) ) : '';
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || $date < pf_min_date() || $date > pf_max_date() ) {
		wc_add_notice( 'Please choose a valid delivery date. Same-day delivery is available for orders before ' . pf_cutoff_label() . '.', 'error' );
		return false;
	}
	if ( ! in_array( $slot, pf_slots(), true ) ) {
		wc_add_notice( 'Please choose a delivery time slot.', 'error' );
		return false;
	}
	return $passed;
}, 10, 2 );

add_filter( 'woocommerce_add_cart_item_data', function ( $data ) {
	if ( isset( $_POST['pf_date'] ) ) {
		$data['pf_date'] = sanitize_text_field( wp_unslash( $_POST['pf_date'] ) );
		$data['pf_slot'] = isset( $_POST['pf_slot'] ) ? sanitize_text_field( wp_unslash( $_POST['pf_slot'] ) ) : '';
		$card            = isset( $_POST['pf_card'] ) ? sanitize_textarea_field( wp_unslash( $_POST['pf_card'] ) ) : '';
		$data['pf_card'] = function_exists( 'mb_substr' ) ? mb_substr( $card, 0, 200 ) : substr( $card, 0, 200 );
		$data['pf_key']  = md5( $data['pf_date'] . '|' . $data['pf_slot'] . '|' . $data['pf_card'] );
	}
	return $data;
} );

add_filter( 'woocommerce_get_item_data', function ( $item_data, $cart_item ) {
	if ( ! empty( $cart_item['pf_date'] ) ) {
		$item_data[] = array( 'key' => 'Delivery date', 'value' => esc_html( $cart_item['pf_date'] ) );
		$item_data[] = array( 'key' => 'Time slot', 'value' => esc_html( $cart_item['pf_slot'] ) );
		if ( ! empty( $cart_item['pf_card'] ) ) {
			$item_data[] = array( 'key' => 'Card message', 'value' => esc_html( $cart_item['pf_card'] ) );
		}
	}
	return $item_data;
}, 10, 2 );

add_action( 'woocommerce_checkout_create_order_line_item', function ( $item, $key, $values ) {
	if ( ! empty( $values['pf_date'] ) ) {
		$item->add_meta_data( 'Delivery date', $values['pf_date'] );
		$item->add_meta_data( 'Time slot', $values['pf_slot'] );
		if ( ! empty( $values['pf_card'] ) ) {
			$item->add_meta_data( 'Card message', $values['pf_card'] );
		}
	}
}, 10, 3 );

// Clearer thank-you wording and page title after an order.
add_filter( 'woocommerce_thankyou_order_received_text', function () {
	return 'Thank you! Your order has been received. We will prepare your flowers for the delivery date and time slot you chose.';
} );
add_filter( 'the_title', function ( $title, $id = 0 ) {
	if ( function_exists( 'is_order_received_page' ) && is_order_received_page() && in_the_loop() && is_main_query() && $id && (int) $id === (int) wc_get_page_id( 'checkout' ) ) {
		return 'Order received';
	}
	return $title;
}, 10, 2 );

// Show the Sri Lankan rupee as "Rs." (the native symbol renders badly in many fonts).
add_filter( 'woocommerce_currency_symbol', function ( $symbol, $currency ) {
	return 'LKR' === $currency ? 'Rs.' : $symbol;
}, 10, 2 );

// Shop-loop buttons go to the product page so the delivery details are always chosen.
add_filter( 'woocommerce_loop_add_to_cart_link', function ( $html, $product ) {
	return '<a class="button pf-order" href="' . esc_url( $product->get_permalink() ) . '">Order this bouquet</a>';
}, 10, 2 );

add_filter( 'woocommerce_product_single_add_to_cart_text', function () {
	return 'Add to cart';
} );

// Admin: Deliveries planner (WooCommerce > Deliveries).
add_action( 'admin_menu', function () {
	if ( pf_wc_active() ) {
		add_submenu_page( 'woocommerce', 'Deliveries', 'Deliveries', 'manage_woocommerce', 'pf-deliveries', 'pf_deliveries_page' );
	}
}, 99 );

function pf_deliveries_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$orders = wc_get_orders( array( 'limit' => 300, 'status' => array( 'pending', 'on-hold', 'processing' ), 'orderby' => 'date', 'order' => 'DESC' ) );
	$rows   = array();
	foreach ( $orders as $order ) {
		foreach ( $order->get_items() as $item ) {
			$d = $item->get_meta( 'Delivery date' );
			if ( ! $d ) {
				continue;
			}
			$rows[ $d ][] = array(
				'id'    => $order->get_id(),
				'name'  => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ),
				'addr'  => $order->get_formatted_shipping_address() ? $order->get_formatted_shipping_address() : $order->get_formatted_billing_address(),
				'phone' => $order->get_billing_phone(),
				'item'  => $item->get_name() . ' x ' . $item->get_quantity(),
				'slot'  => $item->get_meta( 'Time slot' ),
				'card'  => $item->get_meta( 'Card message' ),
				'edit'  => $order->get_edit_order_url(),
			);
		}
	}
	ksort( $rows );
	echo '<div class="wrap"><h1>Deliveries</h1><p>Open orders (pending, on hold, processing) grouped by delivery date. Mark an order Completed to remove it from this list.</p>';
	if ( ! $rows ) {
		echo '<p>No open deliveries.</p></div>';
		return;
	}
	foreach ( $rows as $date => $list ) {
		echo '<h2>' . esc_html( $date ) . ( $date === current_datetime()->format( 'Y-m-d' ) ? ' (today)' : '' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>Order</th><th>Bouquet</th><th>Time slot</th><th>Deliver to</th><th>Phone</th><th>Card message</th></tr></thead><tbody>';
		foreach ( $list as $r ) {
			echo '<tr><td><a href="' . esc_url( $r['edit'] ) . '">#' . (int) $r['id'] . '</a></td><td>' . esc_html( $r['item'] ) . '</td><td>' . esc_html( $r['slot'] ) . '</td><td>' . wp_kses_post( $r['addr'] ) . '</td><td>' . esc_html( $r['phone'] ) . '</td><td>' . esc_html( $r['card'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
	echo '</div>';
}

// Shop page: banner + occasion chips instead of the default title.
add_filter( 'woocommerce_show_page_title', '__return_false' );
add_action( 'woocommerce_before_main_content', function () {
	if ( ! function_exists( 'is_shop' ) ) {
		return;
	}
	if ( is_shop() || is_product_category() ) {
		$title = is_product_category() ? single_term_title( '', false ) : 'Shop flowers';
		echo '<div class="pf-shophead"><h1>' . esc_html( $title ) . '</h1><p>Hand-tied bouquets for every occasion. Choose a delivery date at checkout.</p></div>';
		$cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'exclude' => array( (int) get_option( 'default_product_cat' ) ) ) );
		if ( $cats && ! is_wp_error( $cats ) ) {
			echo '<div class="pf-chips"><a class="' . ( is_shop() ? 'on' : '' ) . '" href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '">All</a>';
			foreach ( $cats as $c ) {
				echo '<a class="' . ( is_product_category( $c->slug ) ? 'on' : '' ) . '" href="' . esc_url( get_term_link( $c ) ) . '">' . esc_html( $c->name ) . '</a>';
			}
			echo '</div>';
		}
	}
}, 15 );

/* =====================================================================
 * 3. Weddings & events enquiries (form + inbox + status)
 * =================================================================== */
function pf_enq_statuses() {
	return array( 'New', 'Contacted', 'Booked', 'Closed' );
}

function pf_event_types() {
	return array( 'Wedding', 'Engagement', 'Corporate event', 'Memorial', 'Birthday party', 'Other' );
}

function pf_budgets() {
	return array( 'Under Rs. 25,000', 'Rs. 25,000 - 75,000', 'Rs. 75,000 - 150,000', 'Over Rs. 150,000', 'Not sure yet' );
}

add_action( 'init', function () {
	register_post_type( 'pf_enquiry', array(
		'labels'        => array( 'name' => 'Enquiries', 'singular_name' => 'Enquiry', 'menu_name' => 'Enquiries', 'not_found' => 'No enquiries yet.', 'edit_item' => 'Enquiry details' ),
		'public'        => false,
		'show_ui'       => true,
		'menu_icon'     => 'dashicons-email-alt',
		'menu_position' => 26,
		'supports'      => array( 'title', 'editor' ),
		'capabilities'  => array( 'create_posts' => 'do_not_allow' ),
		'map_meta_cap'  => true,
	) );
} );

add_filter( 'manage_pf_enquiry_posts_columns', function ( $c ) {
	return array(
		'cb'        => isset( $c['cb'] ) ? $c['cb'] : '',
		'title'     => 'Enquiry',
		'pf_event'  => 'Event',
		'pf_when'   => 'Event date',
		'pf_budget' => 'Budget',
		'pf_contact'=> 'Contact',
		'pf_status' => 'Status',
		'date'      => 'Received',
	);
} );

add_action( 'manage_pf_enquiry_posts_custom_column', function ( $col, $id ) {
	if ( 'pf_event' === $col ) {
		echo esc_html( get_post_meta( $id, 'pf_event', true ) );
	} elseif ( 'pf_when' === $col ) {
		echo esc_html( get_post_meta( $id, 'pf_date', true ) );
	} elseif ( 'pf_budget' === $col ) {
		echo esc_html( get_post_meta( $id, 'pf_budget', true ) );
	} elseif ( 'pf_contact' === $col ) {
		echo esc_html( get_post_meta( $id, 'pf_email', true ) ) . '<br>' . esc_html( get_post_meta( $id, 'pf_phone', true ) );
	} elseif ( 'pf_status' === $col ) {
		$s = get_post_meta( $id, 'pf_status', true );
		echo esc_html( $s ? $s : 'New' );
	}
}, 10, 2 );

add_action( 'add_meta_boxes', function () {
	add_meta_box( 'pf_status_box', 'Enquiry status', function ( $post ) {
		$cur = get_post_meta( $post->ID, 'pf_status', true );
		$cur = $cur ? $cur : 'New';
		wp_nonce_field( 'pf_status_save', 'pf_status_nonce' );
		echo '<select name="pf_status">';
		foreach ( pf_enq_statuses() as $s ) {
			echo '<option value="' . esc_attr( $s ) . '"' . selected( $cur, $s, false ) . '>' . esc_html( $s ) . '</option>';
		}
		echo '</select>';
	}, 'pf_enquiry', 'side' );
} );

add_action( 'save_post_pf_enquiry', function ( $id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! isset( $_POST['pf_status_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pf_status_nonce'] ) ), 'pf_status_save' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $id ) ) {
		return;
	}
	$s = isset( $_POST['pf_status'] ) ? sanitize_text_field( wp_unslash( $_POST['pf_status'] ) ) : 'New';
	if ( in_array( $s, pf_enq_statuses(), true ) ) {
		update_post_meta( $id, 'pf_status', $s );
	}
} );

add_shortcode( 'pf_enquiry', function () {
	$status = isset( $_GET['pf_sent'] ) ? sanitize_text_field( wp_unslash( $_GET['pf_sent'] ) ) : '';
	ob_start();
	if ( '1' === $status ) {
		echo '<div class="pf-notice pf-ok"><strong>Thank you!</strong> We have received your enquiry and will get back to you soon.</div>';
	} elseif ( '0' === $status ) {
		echo '<div class="pf-notice pf-err">Please fill in your name, a valid email, the event type and date, and tick the consent box.</div>';
	}
	$priv = get_page_by_path( 'privacy-policy' );
	$link = $priv ? get_permalink( $priv->ID ) : '#';
	?>
	<form class="pf-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="pf_enquiry_submit">
		<input type="hidden" name="pf_back" value="<?php echo esc_url( get_permalink() ); ?>">
		<?php wp_nonce_field( 'pf_enquiry', 'pf_nonce' ); ?>
		<label>Your name <input type="text" name="pf_name" required></label>
		<div class="pf-row">
			<label>Email address <input type="email" name="pf_email" required></label>
			<label>Phone (optional) <input type="tel" name="pf_phone"></label>
		</div>
		<div class="pf-row">
			<label>Event type
				<select name="pf_event" required>
					<option value="">Choose</option>
					<?php foreach ( pf_event_types() as $t ) : ?>
						<option value="<?php echo esc_attr( $t ); ?>"><?php echo esc_html( $t ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>Event date <input type="date" name="pf_date" min="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" required></label>
		</div>
		<label>Approximate budget
			<select name="pf_budget">
				<?php foreach ( pf_budgets() as $b ) : ?>
					<option value="<?php echo esc_attr( $b ); ?>"><?php echo esc_html( $b ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<label>Tell us about your event <textarea name="pf_message" rows="5" placeholder="Colours, venue, number of guests, anything you have in mind"></textarea></label>
		<p class="pf-hp" aria-hidden="true"><label>Leave empty <input type="text" name="pf_website" tabindex="-1" autocomplete="off"></label></p>
		<label class="pf-check"><input type="checkbox" name="pf_consent" value="1" required> <span>I agree that the shop may contact me about this enquiry. See the <a href="<?php echo esc_url( $link ); ?>">Privacy Policy</a>.</span></label>
		<button type="submit">Send enquiry</button>
	</form>
	<?php
	return ob_get_clean();
} );

function pf_enquiry_handle() {
	$back  = isset( $_POST['pf_back'] ) ? esc_url_raw( wp_unslash( $_POST['pf_back'] ) ) : home_url( '/' );
	$nonce = isset( $_POST['pf_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['pf_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'pf_enquiry' ) ) {
		wp_safe_redirect( add_query_arg( 'pf_sent', '0', $back ) );
		exit;
	}
	if ( ! empty( $_POST['pf_website'] ) ) {
		wp_safe_redirect( add_query_arg( 'pf_sent', '1', $back ) );
		exit;
	}
	$name    = isset( $_POST['pf_name'] ) ? sanitize_text_field( wp_unslash( $_POST['pf_name'] ) ) : '';
	$email   = isset( $_POST['pf_email'] ) ? sanitize_email( wp_unslash( $_POST['pf_email'] ) ) : '';
	$phone   = isset( $_POST['pf_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['pf_phone'] ) ) : '';
	$event   = isset( $_POST['pf_event'] ) ? sanitize_text_field( wp_unslash( $_POST['pf_event'] ) ) : '';
	$date    = isset( $_POST['pf_date'] ) ? sanitize_text_field( wp_unslash( $_POST['pf_date'] ) ) : '';
	$budget  = isset( $_POST['pf_budget'] ) ? sanitize_text_field( wp_unslash( $_POST['pf_budget'] ) ) : '';
	$message = isset( $_POST['pf_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['pf_message'] ) ) : '';
	$consent = ! empty( $_POST['pf_consent'] );
	$date_ok = (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && $date >= gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
	if ( '' === $name || ! is_email( $email ) || ! in_array( $event, pf_event_types(), true ) || ! $date_ok || ! $consent ) {
		wp_safe_redirect( add_query_arg( 'pf_sent', '0', $back ) );
		exit;
	}
	if ( ! in_array( $budget, pf_budgets(), true ) ) {
		$budget = '';
	}
	$body = "Name: $name\nEmail: $email\nPhone: $phone\nEvent: $event\nEvent date: $date\nBudget: $budget\n\n$message";
	$id   = wp_insert_post( array(
		'post_type'    => 'pf_enquiry',
		'post_status'  => 'publish',
		'post_title'   => $event . ' - ' . $date . ' - ' . $name,
		'post_content' => $body,
	) );
	if ( $id && ! is_wp_error( $id ) ) {
		update_post_meta( $id, 'pf_event', $event );
		update_post_meta( $id, 'pf_date', $date );
		update_post_meta( $id, 'pf_budget', $budget );
		update_post_meta( $id, 'pf_email', $email );
		update_post_meta( $id, 'pf_phone', $phone );
		update_post_meta( $id, 'pf_status', 'New' );
	}
	$site = get_bloginfo( 'name' );
	@wp_mail( get_option( 'admin_email' ), '[' . $site . '] New enquiry: ' . $event, $body );
	@wp_mail( $email, 'We received your enquiry - ' . $site, "Hello $name,\n\nThank you for your enquiry about your $event on $date. We will get back to you soon.\n\n$site" );
	wp_safe_redirect( add_query_arg( 'pf_sent', '1', $back ) );
	exit;
}
add_action( 'admin_post_nopriv_pf_enquiry_submit', 'pf_enquiry_handle' );
add_action( 'admin_post_pf_enquiry_submit', 'pf_enquiry_handle' );

/* =====================================================================
 * 4. Images
 * =================================================================== */
function pf_image_list() {
	return array(
		'shop'       => 'Flower buckets outside a florist',
		'market'     => 'Fresh roses and mixed flowers',
		'wedding'    => 'Bridal bouquet at a wedding',
		'ranunculus' => 'Hand-gathered white ranunculus',
		'vase'       => 'Statement vase arrangement',
		'roses'      => 'Deep red roses',
		'crimson'    => 'Crimson and blush garden bouquet',
		'pinkrose'   => 'Pink roses with gypsophila',
		'blush'      => 'Blush roses in cream wrap',
		'pinktulip'  => 'Pink tulips',
		'redtulip'   => 'Red tulips in kraft paper',
		'whitetulip' => 'White tulips',
		'whitegreen' => 'White and green bouquet',
		'sunflower'  => 'Sunflower bouquet',
		'petite'     => 'Petite hand-held bouquet',
		'vintage'    => 'Soft vintage blush bouquet',
	);
}

function pf_attachment_id( $slug ) {
	global $wpdb;
	$like = '%/' . $wpdb->esc_like( 'pf-' . $slug ) . '.%';
	$id   = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s ORDER BY post_id ASC LIMIT 1", $like ) );
	return $id ? (int) $id : 0;
}

function pf_img_url( $slug ) {
	$id = pf_attachment_id( $slug );
	return $id ? (string) wp_get_attachment_url( $id ) : '';
}

function pf_sideload_all() {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	foreach ( pf_image_list() as $slug => $title ) {
		if ( pf_attachment_id( $slug ) ) {
			continue;
		}
		$src = plugin_dir_path( __FILE__ ) . 'images/' . $slug . '.jpg';
		if ( ! file_exists( $src ) ) {
			continue;
		}
		$tmp = wp_tempnam( $slug . '.jpg' );
		copy( $src, $tmp );
		$id = media_handle_sideload( array( 'name' => 'pf-' . $slug . '.jpg', 'tmp_name' => $tmp ), 0, $title );
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp );
			continue;
		}
		update_post_meta( $id, '_wp_attachment_image_alt', $title );
	}
}

add_shortcode( 'pf_gallery', function () {
	$out = '<div class="pf-gallery">';
	foreach ( array( 'shop', 'wedding', 'market', 'vase', 'ranunculus', 'vintage' ) as $slug ) {
		$id = pf_attachment_id( $slug );
		if ( ! $id ) {
			continue;
		}
		$list = pf_image_list();
		$out .= '<figure>' . wp_get_attachment_image( $id, 'large', false, array( 'alt' => $list[ $slug ], 'loading' => 'lazy' ) ) . '</figure>';
	}
	return $out . '</div>';
} );

/* =====================================================================
 * 5. Dynamic pieces: hours, contact info, map
 * =================================================================== */
add_shortcode( 'pf_hours', function () {
	$row = function ( $d, $h ) {
		return '<div class="pf-price-row"><span class="pf-name">' . esc_html( $d ) . '</span><span class="pf-dots"></span><span class="pf-amt">' . esc_html( $h ) . '</span></div>';
	};
	return '<div class="pf-hours">' . $row( 'Monday - Friday', pf_detail( 'hours_weekday' ) ) . $row( 'Saturday', pf_detail( 'hours_sat' ) ) . $row( 'Sunday', pf_detail( 'hours_sun' ) ) . '</div>';
} );

add_shortcode( 'pf_contact_info', function () {
	$card = function ( $title, $html ) {
		return '<div class="pf-info"><h3>' . esc_html( $title ) . '</h3><p>' . $html . '</p></div>';
	};
	$out = '';
	if ( pf_detail( 'phone' ) ) {
		$out .= $card( 'Call us', '<a href="tel:' . esc_attr( pf_tel( pf_detail( 'phone' ) ) ) . '">' . esc_html( pf_detail( 'phone' ) ) . '</a>' );
	}
	if ( pf_detail( 'whatsapp' ) ) {
		$out .= $card( 'WhatsApp', '<a href="https://wa.me/' . esc_attr( pf_detail( 'whatsapp' ) ) . '" target="_blank" rel="noopener">Chat with us</a>' );
	}
	if ( pf_detail( 'email' ) ) {
		$out .= $card( 'Email', '<a href="mailto:' . esc_attr( pf_detail( 'email' ) ) . '">' . esc_html( pf_detail( 'email' ) ) . '</a>' );
	}
	if ( pf_detail( 'address' ) ) {
		$out .= $card( 'Visit us', esc_html( pf_detail( 'address' ) ) );
	}
	$out .= $card( 'Opening hours', 'Mon - Fri: ' . esc_html( pf_detail( 'hours_weekday' ) ) . '<br>Saturday: ' . esc_html( pf_detail( 'hours_sat' ) ) . '<br>Sunday: ' . esc_html( pf_detail( 'hours_sun' ) ) );
	return $out;
} );

add_shortcode( 'pf_map', function () {
	$map = pf_detail( 'map' );
	if ( ! $map ) {
		return '';
	}
	return '<div class="pf-map"><iframe src="' . esc_url( $map ) . '" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="Map" allowfullscreen></iframe></div>';
} );

add_shortcode( 'pf_areas', function () {
	return esc_html( pf_detail( 'areas' ) );
} );

add_shortcode( 'pf_cutoff', function () {
	return esc_html( pf_cutoff_label() );
} );

/* =====================================================================
 * 6. Top bar, footer, WhatsApp button, fonts, SEO
 * =================================================================== */
add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'pf-fonts', 'https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&display=swap', array(), null );
} );

add_action( 'astra_footer_before', function () {
	$soc = '';
	if ( pf_detail( 'instagram' ) ) {
		$soc .= '<a href="' . esc_url( pf_detail( 'instagram' ) ) . '" target="_blank" rel="noopener">Instagram</a>';
	}
	if ( pf_detail( 'facebook' ) ) {
		$soc .= '<a href="' . esc_url( pf_detail( 'facebook' ) ) . '" target="_blank" rel="noopener">Facebook</a>';
	}
	$contact = '';
	if ( pf_detail( 'phone' ) ) {
		$contact .= '<p><a href="tel:' . esc_attr( pf_tel( pf_detail( 'phone' ) ) ) . '">' . esc_html( pf_detail( 'phone' ) ) . '</a></p>';
	}
	if ( pf_detail( 'email' ) ) {
		$contact .= '<p><a href="mailto:' . esc_attr( pf_detail( 'email' ) ) . '">' . esc_html( pf_detail( 'email' ) ) . '</a></p>';
	}
	if ( pf_detail( 'address' ) ) {
		$contact .= '<p>' . esc_html( pf_detail( 'address' ) ) . '</p>';
	}
	$priv = get_page_by_path( 'privacy-policy' );
	$shop = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' );
	echo '<div class="pf-footer"><div class="pf-footer-in">'
		. '<div><h4>' . esc_html( get_bloginfo( 'name' ) ) . '</h4><p>Fresh, hand-tied flowers for birthdays, anniversaries, weddings and every day in between.</p>' . ( $soc ? '<p class="pf-soc">' . $soc . '</p>' : '' ) . '</div>'
		. '<div><h4>Quick links</h4><p><a href="' . esc_url( $shop ) . '">Shop flowers</a></p><p><a href="' . esc_url( pf_page_url( 'weddings-events' ) ) . '">Weddings &amp; events</a></p><p><a href="' . esc_url( pf_page_url( 'delivery' ) ) . '">Delivery &amp; FAQ</a></p><p><a href="' . esc_url( pf_page_url( 'about' ) ) . '">About</a></p>' . ( $priv ? '<p><a href="' . esc_url( get_permalink( $priv->ID ) ) . '">Privacy Policy</a></p>' : '' ) . '</div>'
		. '<div><h4>Hours &amp; contact</h4><p>Mon - Fri: ' . esc_html( pf_detail( 'hours_weekday' ) ) . '</p><p>Saturday: ' . esc_html( pf_detail( 'hours_sat' ) ) . '</p><p>Sunday: ' . esc_html( pf_detail( 'hours_sun' ) ) . '</p>' . $contact . '</div>'
		. '</div></div>';
} );

add_action( 'wp_footer', function () {
	$wa = pf_detail( 'whatsapp' );
	if ( $wa ) {
		echo '<a class="pf-wa" href="https://wa.me/' . esc_attr( $wa ) . '" target="_blank" rel="noopener" aria-label="Chat on WhatsApp"><svg viewBox="0 0 32 32" width="28" height="28" fill="#fff" aria-hidden="true"><path d="M16 3C9 3 3.4 8.6 3.4 15.5c0 2.4.7 4.7 1.9 6.6L3 29l7.100-1.900c1.900 1 4 1.600 6.100 1.600 7 0 12.600-5.600 12.600-12.500S23 3 16 3zm0 22.900c-1.900 0-3.800-.5-5.400-1.500l-.4-.2-4.200 1.100 1.100-4.100-.3-.4a10.400 10.400 0 1 1 9.200 5.100zm5.700-7.800c-.3-.2-1.900-.9-2.200-1s-.5-.2-.7.200-.8 1-1 1.200-.4.200-.7.100a8.500 8.500 0 0 1-4.200-3.700c-.3-.6.300-.5.900-1.700.1-.2 0-.4 0-.5l-1-2.300c-.2-.6-.5-.5-.7-.5h-.6c-.2 0-.5.100-.8.400s-1.100 1.100-1.100 2.600 1.100 3 1.300 3.200 2.200 3.400 5.400 4.700c2 .8 2.800.9 3.800.7.600-.1 1.900-.8 2.100-1.500s.3-1.400.2-1.500-.3-.2-.6-.4z"/></svg></a>';
	}
} );

add_action( 'wp_head', function () {
	if ( is_admin() ) {
		return;
	}
	if ( is_page() ) {
		$map  = array(
			'home'           => 'Fresh, hand-tied bouquets for birthdays, anniversaries and every occasion. Choose your delivery date online and add a free card message.',
			'weddings-events'=> 'Wedding and event flowers: bridal bouquets, ceremony and table arrangements. Send an enquiry for a personal quote.',
			'delivery'       => 'Delivery areas, time slots, same-day cut-off and answers to common questions about ordering flowers online.',
			'about'          => 'About Petal & Stem: a florist hand-tying fresh bouquets and arrangements for every occasion.',
			'contact'        => 'Contact the florist: phone, WhatsApp, email, address and opening hours.',
			'privacy-policy' => 'How Petal & Stem handles the information you send through orders and enquiry forms.',
		);
		$slug = get_post_field( 'post_name', get_queried_object_id() );
		if ( isset( $map[ $slug ] ) ) {
			echo '<meta name="description" content="' . esc_attr( $map[ $slug ] ) . '">' . "\n";
			echo '<meta property="og:title" content="' . esc_attr( wp_get_document_title() ) . '">' . "\n";
			echo '<meta property="og:description" content="' . esc_attr( $map[ $slug ] ) . '">' . "\n";
		}
	}
	if ( is_front_page() ) {
		$data = array(
			'@context' => 'https://schema.org',
			'@type'    => 'Florist',
			'name'     => get_bloginfo( 'name' ),
			'url'      => home_url( '/' ),
			'image'    => pf_img_url( 'shop' ),
			'openingHoursSpecification' => array(
				array( '@type' => 'OpeningHoursSpecification', 'dayOfWeek' => array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday' ), 'description' => pf_detail( 'hours_weekday' ) ),
				array( '@type' => 'OpeningHoursSpecification', 'dayOfWeek' => 'Saturday', 'description' => pf_detail( 'hours_sat' ) ),
				array( '@type' => 'OpeningHoursSpecification', 'dayOfWeek' => 'Sunday', 'description' => pf_detail( 'hours_sun' ) ),
			),
		);
		foreach ( array( 'telephone' => 'phone', 'email' => 'email', 'address' => 'address' ) as $k => $d ) {
			if ( pf_detail( $d ) ) {
				$data[ $k ] = pf_detail( $d );
			}
		}
		$same = array_values( array_filter( array( pf_detail( 'instagram' ), pf_detail( 'facebook' ) ) ) );
		if ( $same ) {
			$data['sameAs'] = $same;
		}
		echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
	}
} );

/* =====================================================================
 * 7. Page content
 * =================================================================== */
function pf_faq_html() {
	$items = array(
		'Can I get flowers delivered today?' => 'Yes. Orders placed before the cut-off time shown on the product page can be delivered the same day. Later orders start from the next day.',
		'Can I choose a delivery time?' => 'Yes. Pick a morning, afternoon or evening slot when you order.',
		'Can I add a card message?' => 'Yes, it is free. Write your message on the product page and we will handwrite it on a card.',
		'How do I pay?' => 'Pay in cash when the flowers are delivered, or choose another payment method if it is shown at checkout.',
		'Will my bouquet look exactly like the photo?' => 'We match the style, colours and value as closely as we can. Flowers are natural and seasonal, so if a stem is unavailable we use a similar one.',
		'Can I change or cancel my order?' => 'Please contact us as early as possible. Orders already being prepared cannot always be changed.',
	);
	$out = '';
	foreach ( $items as $q => $a ) {
		$out .= '<details class="pf-faq"><summary>' . esc_html( $q ) . '</summary><p>' . esc_html( $a ) . '</p></details>';
	}
	return '<div class="pf-faq-list">' . $out . '</div>';
}

function pf_shop_url() {
	return function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' );
}

function pf_cat_url( $slug ) {
	$t = get_term_by( 'slug', $slug, 'product_cat' );
	if ( $t && ! is_wp_error( $t ) ) {
		$l = get_term_link( $t );
		if ( ! is_wp_error( $l ) ) {
			return $l;
		}
	}
	return pf_shop_url();
}

function pf_page_html( $key ) {
	$shop = esc_url( pf_shop_url() );
	$wed  = esc_url( pf_page_url( 'weddings-events' ) );
	$del  = esc_url( pf_page_url( 'delivery' ) );
	$about = esc_url( pf_page_url( 'about' ) );

	if ( 'home' === $key ) {
		$tile = function ( $cls, $title, $text, $slug ) {
			return '<a class="pf-tile pf-tile--' . esc_attr( $cls ) . '" href="' . esc_url( pf_cat_url( $slug ) ) . '"><div><h3>' . esc_html( $title ) . '</h3><span>' . esc_html( $text ) . '</span></div></a>';
		};
		return '<div class="pf-hero"><h1>Fresh flowers, hand-tied and delivered</h1><p>Choose a bouquet, pick your delivery day and add a free card message. We do the rest.</p><div class="pf-btns"><a class="pf-btn" href="' . $shop . '">Shop bouquets</a><a class="pf-btn pf-btn-ghost" href="' . $wed . '">Weddings &amp; events</a></div></div>'
			. '<div class="pf-trust"><div class="pf-trust-item">Same-day delivery before [pf_cutoff]</div><div class="pf-trust-item">Choose your time slot</div><div class="pf-trust-item">Free card message</div><div class="pf-trust-item">Pay on delivery</div></div>'
			. '<div class="pf-section"><h2>Shop by occasion</h2><div class="pf-tiles">'
			. $tile( 'birthday', 'Birthday', 'Bright and cheerful', 'birthday' )
			. $tile( 'anniversary', 'Anniversary & Love', 'Roses and romance', 'anniversary' )
			. $tile( 'thanks', 'Thank You', 'A small, kind gesture', 'thank-you' )
			. $tile( 'sympathy', 'Sympathy', 'Soft whites and greens', 'sympathy' )
			. '</div></div>'
			. '<div class="pf-section"><h2>Featured bouquets</h2>[products featured="true" limit="8" columns="4" orderby="menu_order"]<p class="pf-center"><a class="pf-btn" href="' . $shop . '">See all flowers</a></p></div>'
			. '<div class="pf-section"><div class="pf-split"><div class="pf-split-img pf-split-img--about"></div><div><h2>Flowers with a personal touch</h2><p>Every bouquet is put together by hand, with fresh stems chosen on the day. Tell us who it is for and what you would like to say, and we will make it feel personal.</p><ul class="pf-list"><li>Fresh stems, conditioned and wrapped with care</li><li>Delivery date and time slot of your choice</li><li>Handwritten card with your message, free</li></ul><a class="pf-btn" href="' . $about . '">About us</a></div></div></div>'
			. '<div class="pf-section pf-band"><h2>How ordering works</h2><div class="pf-steps">'
			. '<div class="pf-step"><h3>Choose a bouquet</h3><p>Browse by occasion and pick the one you like.</p></div>'
			. '<div class="pf-step"><h3>Pick the day</h3><p>Select your delivery date, time slot and write a card message.</p></div>'
			. '<div class="pf-step"><h3>We hand-tie and deliver</h3><p>Your flowers arrive fresh, wrapped and ready to give.</p></div>'
			. '</div></div>'
			. '<div class="pf-section"><div class="pf-wedcta"><div><h2>Planning a wedding or event?</h2><p>Bridal bouquets, ceremony flowers and table arrangements, designed around your colours and venue.</p><a class="pf-btn" href="' . $wed . '">Send an enquiry</a></div></div></div>'
			. '<div class="pf-section"><h2>From our flower room</h2>[pf_gallery]</div>'
			. '<div class="pf-section"><h2>Questions we often hear</h2>' . pf_faq_html() . '</div>'
			. '<div class="pf-section"><div class="pf-cta"><h2>Send flowers today</h2><p>Pick a bouquet, choose your delivery date and add a card message. It takes a minute.</p><a class="pf-btn" href="' . $shop . '">Shop flowers</a></div></div>';
	}

	if ( 'weddings' === $key ) {
		return '<div class="pf-banner pf-banner--weddings"><h1>Weddings &amp; events</h1><p>Flowers designed around your day, your colours and your venue.</p></div>'
			. '<div class="pf-section"><div class="pf-features">'
			. '<div class="pf-feature"><h3>Bridal bouquets</h3><p>Hand-tied bouquets and bridesmaid posies in your palette.</p></div>'
			. '<div class="pf-feature"><h3>Ceremony &amp; tables</h3><p>Arrangements for aisles, arches and reception tables.</p></div>'
			. '<div class="pf-feature"><h3>Events &amp; memorials</h3><p>Corporate events, birthdays and memorial tributes.</p></div>'
			. '</div></div>'
			. '<div class="pf-section pf-band"><h2>How it works</h2><div class="pf-steps">'
			. '<div class="pf-step"><h3>Send an enquiry</h3><p>Tell us the date, venue and what you have in mind.</p></div>'
			. '<div class="pf-step"><h3>We prepare a quote</h3><p>We reply with ideas and a clear, itemised quote.</p></div>'
			. '<div class="pf-step"><h3>We create and deliver</h3><p>Your flowers are made fresh and delivered on the day.</p></div>'
			. '</div></div>'
			. '<div class="pf-section"><h2>Wedding &amp; event enquiry</h2><div class="pf-formbox pf-formbox--wide">[pf_enquiry]</div></div>';
	}

	if ( 'delivery' === $key ) {
		return '<div class="pf-banner pf-banner--delivery"><h1>Delivery &amp; FAQ</h1><p>Everything you need to know before you order.</p></div>'
			. '<div class="pf-section"><div class="pf-features">'
			. '<div class="pf-feature"><h3>Same-day delivery</h3><p>Order before [pf_cutoff] and choose today as your delivery date.</p></div>'
			. '<div class="pf-feature"><h3>Where we deliver</h3><p>[pf_areas]. The delivery fee is shown at checkout.</p></div>'
			. '<div class="pf-feature"><h3>Free card message</h3><p>Add your message on the product page and we will write it by hand.</p></div>'
			. '</div></div>'
			. '<div class="pf-section"><div class="pf-policy"><h3>Time slots</h3><p>Choose Morning (9 AM - 12 PM), Afternoon (12 PM - 4 PM) or Evening (4 PM - 7 PM). We will do our best to deliver within your slot. If nobody is available to receive the flowers, we will call before leaving them.</p></div></div>'
			. '<div class="pf-section"><h2>Frequently asked questions</h2>' . pf_faq_html() . '</div>'
			. '<div class="pf-section"><div class="pf-cta"><h2>Ready to send flowers?</h2><p>Pick a bouquet and choose your delivery date.</p><a class="pf-btn" href="' . $shop . '">Shop flowers</a></div></div>';
	}

	if ( 'about' === $key ) {
		return '<div class="pf-banner pf-banner--about"><h1>About us</h1><p>A florist for everyday moments and big occasions.</p></div>'
			. '<div class="pf-section"><div class="pf-split"><div><h2>Our story</h2><p>Petal &amp; Stem is a small florist built around one idea: flowers should feel personal. Every bouquet is made by hand, with fresh stems and a little care.</p><p>Whether it is a quick thank you or a full wedding, we listen first, then design something that fits the person and the moment.</p><a class="pf-btn" href="' . $shop . '">Shop flowers</a></div><div class="pf-split-img pf-split-img--ranunculus"></div></div></div>'
			. '<div class="pf-section pf-band"><h2>What we value</h2><div class="pf-features">'
			. '<div class="pf-feature"><h3>Freshness</h3><p>Fresh stems, conditioned and wrapped with care.</p></div>'
			. '<div class="pf-feature"><h3>Thoughtfulness</h3><p>Every card and colour choice is made with the receiver in mind.</p></div>'
			. '<div class="pf-feature"><h3>Reliability</h3><p>The right flowers, on the right day, in the slot you chose.</p></div>'
			. '</div></div>'
			. '<div class="pf-section"><div class="pf-cta"><h2>Let us make something for you</h2><p>Order online or send us a wedding and event enquiry.</p><a class="pf-btn" href="' . $shop . '">Shop flowers</a></div></div>';
	}

	if ( 'privacy' === $key ) {
		return '<div class="pf-banner pf-banner--contact"><h1>Privacy Policy</h1><p>How we handle the information you send us.</p></div>'
			. '<div class="pf-section pf-prose">'
			. '<h2>What we collect</h2><p>When you order we collect your name, billing and delivery address, phone number, email address, the delivery date and time slot, and any card message you write. The enquiry form collects your name, email, optional phone number, event details and message.</p>'
			. '<h2>How we use it</h2><p>We use this information only to prepare and deliver your order, to answer your enquiry and to contact you about it. We do not sell your information.</p>'
			. '<h2>Where it is stored</h2><p>Orders and enquiries are stored in the website database and are visible only to the shop team.</p>'
			. '<h2>Your choices</h2><p>You can ask us at any time to correct or delete your information by contacting us using the details on the Contact page.</p>'
			. '</div>';
	}

	// contact
	return '<div class="pf-banner pf-banner--contact"><h1>Contact us</h1><p>Questions about an order, or planning something special? Get in touch.</p></div>'
		. '<div class="pf-section"><div class="pf-contact-grid"><div>[pf_contact_info]</div><div><div class="pf-policy"><h3>Ordering flowers?</h3><p>Choose a bouquet in the shop and pick your delivery date and time slot there. See the Delivery page for areas, cut-off time and FAQ.</p><p><a class="pf-btn" href="' . $shop . '">Shop flowers</a></p></div><div class="pf-policy"><h3>Weddings &amp; events</h3><p>Send us the details of your event and we will reply with ideas and a quote.</p><p><a class="pf-btn" href="' . $wed . '">Send an enquiry</a></p></div></div></div></div>'
		. '[pf_map]';
}

function pf_make_page( $title, $slug, $html ) {
	$content = "<!-- wp:html -->\n" . $html . "\n<!-- /wp:html -->";
	$page    = get_page_by_path( $slug );
	if ( $page ) {
		wp_update_post( array( 'ID' => $page->ID, 'post_title' => $title, 'post_status' => 'publish', 'post_content' => $content ) );
		$id = $page->ID;
	} else {
		$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_name' => $slug, 'post_content' => $content ) );
	}
	if ( $id && ! is_wp_error( $id ) ) {
		update_post_meta( $id, 'site-sidebar-layout', 'no-sidebar' );
		update_post_meta( $id, 'site-content-layout', 'normal-width-container' );
		update_post_meta( $id, 'site-post-title', 'disabled' );
		return (int) $id;
	}
	return 0;
}

/* =====================================================================
 * 8. Catalogue (sample bouquets, created once, never overwritten)
 * =================================================================== */
function pf_categories() {
	return array(
		'birthday'    => 'Birthday',
		'anniversary' => 'Anniversary & Love',
		'thank-you'   => 'Thank You',
		'sympathy'    => 'Sympathy',
		'just-because'=> 'Just Because',
	);
}

function pf_products() {
	return array(
		array( 'PS-001', 'Classic Red Roses', 8500, 0, 'A generous bunch of deep red roses, wrapped and ready to give.', array( 'anniversary', 'just-because' ), 'roses', true ),
		array( 'PS-002', 'Crimson Garden Bouquet', 12500, 0, 'Dahlias, roses and berries in rich reds and blush pinks.', array( 'anniversary', 'birthday' ), 'crimson', true ),
		array( 'PS-003', 'Pink Roses & Gypsophila', 7500, 0, 'Soft pink roses with delicate gypsophila in pastel wrap.', array( 'anniversary', 'thank-you' ), 'pinkrose', true ),
		array( 'PS-004', 'Blush Rose Wrap', 6500, 0, 'Pink roses and little daisies in a cream wrap.', array( 'birthday', 'thank-you' ), 'blush', true ),
		array( 'PS-005', 'Pink Tulip Bunch', 4500, 0, 'Five fresh pink tulips in polka-dot kraft paper.', array( 'thank-you', 'just-because' ), 'pinktulip', true ),
		array( 'PS-006', 'Red Tulips in Kraft', 5500, 0, 'Bright red tulips wrapped in kraft paper with a satin ribbon.', array( 'anniversary', 'just-because' ), 'redtulip', true ),
		array( 'PS-007', 'White Tulips', 4800, 0, 'Pure white tulips with fresh green foliage.', array( 'sympathy', 'thank-you' ), 'whitetulip', false ),
		array( 'PS-008', 'White & Green Garden Bouquet', 7200, 0, 'Lilies, chrysanthemums and alstroemeria in fresh whites and greens.', array( 'sympathy', 'thank-you' ), 'whitegreen', true ),
		array( 'PS-009', 'Sunshine Sunflowers', 4500, 3900, 'A cheerful bunch of sunflowers in clear wrap.', array( 'birthday', 'just-because' ), 'sunflower', true ),
		array( 'PS-010', 'Statement Vase Arrangement', 14500, 0, 'Roses, hydrangea, sunflowers and delphinium arranged for a table or entrance.', array( 'birthday', 'anniversary' ), 'vase', false ),
		array( 'PS-011', 'Petite Hand Bouquet', 3500, 0, 'A small, sweet bouquet in soft pink wrap with a peach ribbon.', array( 'thank-you', 'just-because' ), 'petite', false ),
		array( 'PS-012', 'Vintage Blush Bouquet', 9500, 0, 'Roses, peony, carnation and eucalyptus in dusty pinks.', array( 'birthday', 'anniversary' ), 'vintage', false ),
	);
}

function pf_create_catalogue() {
	if ( ! pf_wc_active() || ! class_exists( 'WC_Product_Simple' ) ) {
		return;
	}
	$term_ids = array();
	foreach ( pf_categories() as $slug => $name ) {
		$t = get_term_by( 'slug', $slug, 'product_cat' );
		if ( ! $t ) {
			$r = wp_insert_term( $name, 'product_cat', array( 'slug' => $slug ) );
			if ( ! is_wp_error( $r ) ) {
				$term_ids[ $slug ] = (int) $r['term_id'];
			}
		} else {
			$term_ids[ $slug ] = (int) $t->term_id;
		}
	}
	$care = 'Hand-tied by our florist with fresh stems. Flowers are natural and seasonal; if a stem is unavailable we substitute a similar one in the same colours and value.';
	$i    = 0;
	foreach ( pf_products() as $p ) {
		list( $sku, $name, $price, $sale, $short, $cats, $img, $featured ) = $p;
		$i++;
		if ( wc_get_product_id_by_sku( $sku ) ) {
			continue;
		}
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_sku( $sku );
		$product->set_status( 'publish' );
		$product->set_regular_price( (string) $price );
		if ( $sale ) {
			$product->set_sale_price( (string) $sale );
		}
		$product->set_short_description( $short );
		$product->set_description( $short . ' ' . $care );
		$product->set_featured( (bool) $featured );
		$product->set_reviews_allowed( false );
		$product->set_sold_individually( false );
		$product->set_menu_order( $i );
		$ids = array();
		foreach ( $cats as $c ) {
			if ( isset( $term_ids[ $c ] ) ) {
				$ids[] = $term_ids[ $c ];
			}
		}
		$product->set_category_ids( $ids );
		$aid = pf_attachment_id( $img );
		if ( $aid ) {
			$product->set_image_id( $aid );
		}
		$product->save();
	}
}

function pf_configure_woocommerce() {
	if ( ! pf_wc_active() ) {
		return;
	}
	update_option( 'woocommerce_currency', 'LKR' );
	update_option( 'woocommerce_default_country', 'LK' );
	update_option( 'woocommerce_price_num_decimals', 0 );
	update_option( 'woocommerce_enable_reviews', 'no' );
	update_option( 'woocommerce_coming_soon', 'no' );
	update_option( 'woocommerce_store_pages_only', 'no' );
	update_option( 'woocommerce_allowed_countries', 'specific' );
	update_option( 'woocommerce_specific_allowed_countries', array( 'LK' ) );
	update_option( 'woocommerce_ship_to_countries', '' );
	$cod = get_option( 'woocommerce_cod_settings', array() );
	if ( ! is_array( $cod ) ) {
		$cod = array();
	}
	$cod['enabled']      = 'yes';
	$cod['title']        = 'Cash on delivery';
	$cod['description']  = 'Pay in cash when your flowers arrive.';
	$cod['enable_for_methods'] = array();
	update_option( 'woocommerce_cod_settings', $cod );

	// Flat-rate delivery zone (only if no zones exist yet).
	if ( class_exists( 'WC_Shipping_Zones' ) && class_exists( 'WC_Shipping_Zone' ) && ! WC_Shipping_Zones::get_zones() ) {
		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( 'Sri Lanka delivery' );
		$zone->add_location( 'LK', 'country' );
		$zone->save();
		$instance = $zone->add_shipping_method( 'flat_rate' );
		if ( $instance ) {
			update_option( 'woocommerce_flat_rate_' . $instance . '_settings', array(
				'title'      => 'Delivery',
				'tax_status' => 'none',
				'cost'       => '500',
			) );
		}
	}
}

/* =====================================================================
 * 9. Install / upgrade
 * =================================================================== */
add_action( 'admin_notices', function () {
	if ( ! pf_wc_active() && current_user_can( 'activate_plugins' ) ) {
		echo '<div class="notice notice-warning"><p><strong>Petal &amp; Stem Setup</strong> needs the free WooCommerce plugin. Install and activate WooCommerce, then open any admin page again.</p></div>';
	}
} );

register_activation_hook( __FILE__, 'pf_install' );
add_action( 'admin_init', function () {
	if ( pf_wc_active() && PF_VERSION !== get_option( 'pf_version' ) ) {
		pf_install();
	}
} );
add_action( 'after_switch_theme', 'pf_assign_menu' );

function pf_assign_menu() {
	$menu = wp_get_nav_menu_object( 'Petal Menu' );
	if ( $menu ) {
		$locs            = get_theme_mod( 'nav_menu_locations', array() );
		$locs['primary'] = $menu->term_id;
		set_theme_mod( 'nav_menu_locations', $locs );
	}
}

function pf_install() {
	if ( ! pf_wc_active() ) {
		return;
	}
	// Stop two installs running at the same time (this is what duplicated the menu items).
	if ( get_transient( 'pf_installing' ) ) {
		return;
	}
	set_transient( 'pf_installing', 1, 5 * MINUTE_IN_SECONDS );

	$first = ! get_option( 'pf_version' );

	// Plain permalinks make /about/ style links 404, so switch to pretty ones.
	if ( ! get_option( 'permalink_structure' ) ) {
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
	}

	if ( $first && ! get_option( 'timezone_string' ) ) {
		update_option( 'timezone_string', 'Asia/Colombo' );
	}

	// WooCommerce pages (shop, cart, checkout, my account).
	if ( class_exists( 'WC_Install' ) && wc_get_page_id( 'shop' ) < 1 ) {
		WC_Install::create_pages();
	}
	pf_configure_woocommerce();
	pf_sideload_all();
	pf_create_catalogue();

	// Create the pages first so buttons and links can point at their real addresses.
	foreach ( array( 'weddings-events' => 'Weddings & Events', 'delivery' => 'Delivery', 'about' => 'About' ) as $sl => $ti ) {
		if ( ! get_page_by_path( $sl ) ) {
			pf_make_page( $ti, $sl, '<p></p>' );
		}
	}

	$ids = array(
		'home'     => pf_make_page( 'Home', 'home', pf_page_html( 'home' ) ),
		'weddings' => pf_make_page( 'Weddings & Events', 'weddings-events', pf_page_html( 'weddings' ) ),
		'delivery' => pf_make_page( 'Delivery', 'delivery', pf_page_html( 'delivery' ) ),
		'about'    => pf_make_page( 'About', 'about', pf_page_html( 'about' ) ),
		'contact'  => pf_make_page( 'Contact', 'contact', pf_page_html( 'contact' ) ),
		'privacy'  => pf_make_page( 'Privacy Policy', 'privacy-policy', pf_page_html( 'privacy' ) ),
	);

	update_option( 'blogname', 'Petal & Stem' );
	update_option( 'blogdescription', 'Fresh flowers, hand-tied and delivered' );
	if ( $ids['home'] ) {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $ids['home'] );
	}
	if ( $ids['privacy'] ) {
		update_option( 'wp_page_for_privacy_policy', $ids['privacy'] );
	}

	if ( $first ) {
		$sample = get_page_by_path( 'sample-page' );
		if ( $sample && 'publish' === $sample->post_status ) {
			wp_trash_post( $sample->ID );
		}
		$hello = get_page_by_path( 'hello-world', OBJECT, 'post' );
		if ( $hello ) {
			wp_trash_post( $hello->ID );
		}
	}

	// Menu (rebuilt each time).
	if ( wp_get_nav_menu_object( 'Petal Menu' ) ) {
		wp_delete_nav_menu( 'Petal Menu' );
	}
	$menu_id = wp_create_nav_menu( 'Petal Menu' );
	if ( ! is_wp_error( $menu_id ) ) {
		$items = array(
			array( 'Home', $ids['home'], '' ),
			array( 'Shop', wc_get_page_id( 'shop' ), '' ),
			array( 'Weddings', $ids['weddings'], '' ),
			array( 'Delivery', $ids['delivery'], '' ),
			array( 'About', $ids['about'], '' ),
			array( 'Contact', $ids['contact'], '' ),
			array( 'Cart', wc_get_page_id( 'cart' ), 'pf-cart-btn' ),
		);
		$pos = 1;
		foreach ( $items as $it ) {
			if ( $it[1] && $it[1] > 0 ) {
				wp_update_nav_menu_item( $menu_id, 0, array(
					'menu-item-title'     => $it[0],
					'menu-item-object'    => 'page',
					'menu-item-object-id' => $it[1],
					'menu-item-type'      => 'post_type',
					'menu-item-status'    => 'publish',
					'menu-item-position'  => $pos++,
					'menu-item-classes'   => $it[2],
				) );
			}
		}
		pf_assign_menu();
	}

	// CSS: replace our earlier block (always last), then add the new one.
	$current = wp_get_custom_css();
	$cut     = strpos( $current, '/* PETAL STEM CSS' );
	if ( false !== $cut ) {
		$current = substr( $current, 0, $cut );
	}
	$css  = '';
	$file = plugin_dir_path( __FILE__ ) . 'css/petal-stem.css';
	if ( file_exists( $file ) ) {
		$css .= file_get_contents( $file );
	}
	$u = array();
	foreach ( array_keys( pf_image_list() ) as $sl ) {
		$u[ $sl ] = pf_img_url( $sl );
	}
	if ( ! in_array( '', $u, true ) ) {
		$bg   = function ( $sel, $sl ) use ( $u ) {
			return $sel . "{background-image:url('" . esc_url_raw( $u[ $sl ] ) . "');}\n";
		};
		$css .= "\n/* PETAL STEM IMAGES */\n"
			. $bg( '.pf-hero', 'shop' )
			. $bg( '.pf-tile--birthday', 'sunflower' )
			. $bg( '.pf-tile--anniversary', 'roses' )
			. $bg( '.pf-tile--thanks', 'petite' )
			. $bg( '.pf-tile--sympathy', 'whitegreen' )
			. $bg( '.pf-banner--weddings', 'wedding' )
			. $bg( '.pf-banner--delivery', 'market' )
			. $bg( '.pf-banner--about', 'shop' )
			. $bg( '.pf-banner--contact', 'vintage' )
			. $bg( '.pf-split-img--about', 'ranunculus' )
			. $bg( '.pf-split-img--ranunculus', 'vase' )
			. $bg( '.pf-wedcta', 'wedding' )
			. $bg( '.pf-cta', 'market' )
			. $bg( '.pf-shophead', 'market' );
	}
	wp_update_custom_css_post( rtrim( $current ) . "\n" . $css );

	// Astra options.
	$opts = get_option( 'astra-settings', array() );
	if ( is_array( $opts ) ) {
		$opts['footer-copyright-editor'] = 'Copyright [copyright] [current_year] [site_title]. All rights reserved.';
		$opts['scroll-to-top-enable']    = 1;
		update_option( 'astra-settings', $opts );
	}

	update_option( 'pf_version', PF_VERSION );
	delete_transient( 'pf_installing' );
	flush_rewrite_rules();
}
