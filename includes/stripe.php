<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Stripe Payment Intent creation
 */
function vso_api_stripe_create_intent( $request ) {
    $body = $request->get_json_params();
    $amount = (float) ( $body['amount'] ?? 0 );

    if ( $amount < 0.50 ) {
        return new WP_Error( 'invalid_amount', 'Minimum order is $0.50', [ 'status' => 400 ] );
    }

    $secret_key = get_option( 'vso_stripe_secret_key', '' );
    if ( empty( $secret_key ) ) {
        return new WP_Error( 'stripe_not_configured', 'Stripe is not configured.', [ 'status' => 500 ] );
    }

    $response = wp_remote_post( 'https://api.stripe.com/v1/payment_intents', [
        'headers' => [
            'Authorization' => 'Bearer ' . $secret_key,
            'Content-Type'  => 'application/x-www-form-urlencoded',
        ],
        'body' => [
            'amount'                    => round( $amount * 100 ), // cents
            'currency'                  => 'usd',
            'automatic_payment_methods' => [ 'enabled' => 'true' ],
            'metadata'                  => [
                'source' => 'visit_sicily_nyc',
                'customer_name' => $body['name'] ?? '',
                'customer_email' => $body['email'] ?? '',
            ],
        ],
    ]);

    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'stripe_error', $response->get_error_message(), [ 'status' => 500 ] );
    }

    $result = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( isset( $result['error'] ) ) {
        return new WP_Error( 'stripe_error', $result['error']['message'], [ 'status' => 400 ] );
    }

    return rest_ensure_response([
        'clientSecret'    => $result['client_secret'],
        'paymentIntentId' => $result['id'],
    ]);
}

/**
 * Stripe Webhook handler
 */
function vso_api_stripe_webhook( $request ) {
    $payload = $request->get_body();
    $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    $webhook_secret = get_option( 'vso_stripe_webhook_secret', '' );

    // If webhook secret is set, verify signature
    if ( ! empty( $webhook_secret ) && ! empty( $sig_header ) ) {
        $elements = explode( ',', $sig_header );
        $timestamp = '';
        $signatures = [];
        foreach ( $elements as $el ) {
            [ $key, $value ] = explode( '=', $el, 2 );
            if ( $key === 't' ) $timestamp = $value;
            if ( $key === 'v1' ) $signatures[] = $value;
        }

        $signed_payload = $timestamp . '.' . $payload;
        $expected = hash_hmac( 'sha256', $signed_payload, $webhook_secret );

        if ( ! in_array( $expected, $signatures ) ) {
            return new WP_Error( 'invalid_signature', 'Invalid webhook signature.', [ 'status' => 400 ] );
        }
    }

    $event = json_decode( $payload, true );

    if ( $event['type'] === 'payment_intent.succeeded' ) {
        $pi_id = $event['data']['object']['id'];

        // Find the order with this payment intent
        $orders = get_posts([
            'post_type'  => 'vs_order',
            'meta_key'   => '_vso_stripe_payment_intent', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_value' => $pi_id,
            'posts_per_page' => 1,
        ]);

        if ( ! empty( $orders ) ) {
            update_post_meta( $orders[0]->ID, '_vso_payment_status', 'paid' );
        }
    }

    return rest_ensure_response([ 'received' => true ]);
}

/**
 * Check if Stripe is configured
 */
function vso_stripe_is_configured() {
    return ! empty( get_option( 'vso_stripe_publishable_key', '' ) )
        && ! empty( get_option( 'vso_stripe_secret_key', '' ) );
}

/**
 * Check if Stripe is in test mode
 */
function vso_stripe_is_test_mode() {
    $key = get_option( 'vso_stripe_publishable_key', '' );
    return strpos( $key, 'pk_test_' ) === 0;
}
