<?php
/**
 * Plugin Name: WooCommerce Easy Checkout
 * Plugin URI:  https://example.com/
 * Description: Simplifies the WooCommerce checkout experience for returning customers while leaving the guest checkout unchanged.
 * Version:     1.0.0
 * Author:      Your Name
 * Author URI:  https://example.com/
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: woocommerce-easy-checkout
 * Requires at least: 6.8
 * Requires PHP: 8.4
 * WC requires at least: 8.0
 * WC tested up to: 10.3
 */

declare(strict_types=1);

namespace WooCommerceEasyCheckout;

use WP_User;

use function add_action;
use function add_filter;
use function current_user_can;
use function deactivate_plugins;
use function esc_html__;
use function get_user_meta;
use function is_plugin_active;
use function is_user_logged_in;
use function plugin_basename;
use function register_activation_hook;
use function wp_die;
use function wp_get_current_user;

if (! defined('ABSPATH')) {
    exit;
}

final class Plugin
{
    private static bool $initialised = false;

    public static function init(): void
    {
        if (self::$initialised) {
            return;
        }

        self::$initialised = true;

        if (! self::is_woocommerce_active()) {
            add_action('admin_notices', [self::class, 'render_missing_wc_notice']);
            return;
        }

        add_filter('woocommerce_checkout_fields', [self::class, 'filter_checkout_fields'], 20);
        add_filter('woocommerce_checkout_get_value', [self::class, 'prime_checkout_field_values'], 20, 2);
        add_action('woocommerce_checkout_process', [self::class, 'enforce_customer_contact_details']);
    }

    public static function on_activation(): void
    {
        if (! function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (! self::is_woocommerce_active()) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(
                esc_html__(
                    'WooCommerce Easy Checkout requires WooCommerce to be installed and active.',
                    'woocommerce-easy-checkout'
                )
            );
        }
    }

    public static function render_missing_wc_notice(): void
    {
        if (! current_user_can('activate_plugins')) {
            return;
        }

        echo '<div class="notice notice-error"><p>';
        echo esc_html__(
            'WooCommerce Easy Checkout requires WooCommerce to be installed and active.',
            'woocommerce-easy-checkout'
        );
        echo '</p></div>';
    }

    /**
     * Filters the WooCommerce checkout fields for logged-in customers.
     *
     * @param array<string, array<string, array<string, mixed>>> $fields Checkout fields grouped by section.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function filter_checkout_fields(array $fields): array
    {
        if (! is_user_logged_in()) {
            return $fields;
        }

        $allowed_billing_keys = [
            'billing_first_name',
            'billing_last_name',
            'billing_email',
            'billing_phone',
        ];

        if (isset($fields['billing'])) {
            foreach ($fields['billing'] as $key => $field) {
                if (! in_array($key, $allowed_billing_keys, true)) {
                    unset($fields['billing'][$key]);
                    continue;
                }

                if ('billing_email' === $key || 'billing_phone' === $key) {
                    if ('billing_email' === $key) {
                        $fields['billing'][$key]['required'] = true;
                    }

                    $fields['billing'][$key]['custom_attributes'] = self::merge_custom_attributes(
                        $field['custom_attributes'] ?? [],
                        ['readonly' => 'readonly']
                    );
                }
            }

            // Ensure ordering of remaining fields is preserved and sequential.
            $position = 0;
            foreach ($allowed_billing_keys as $key) {
                if (isset($fields['billing'][$key])) {
                    $fields['billing'][$key]['priority'] = $position += 10;
                }
            }
        }

        if (isset($fields['shipping'])) {
            $fields['shipping'] = [];
        }

        return $fields;
    }

    /**
     * Ensures stored customer contact details are used during checkout for logged-in users.
     */
    public static function enforce_customer_contact_details(): void
    {
        if (! is_user_logged_in()) {
            return;
        }

        $user = wp_get_current_user();

        if (! $user instanceof WP_User) {
            return;
        }

        $email = (string) $user->user_email;

        if ('' !== $email) {
            $_POST['billing_email'] = $email; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        }

        $first_name = (string) get_user_meta($user->ID, 'billing_first_name', true);

        if (
            '' !== $first_name
            && (! isset($_POST['billing_first_name']) || '' === (string) $_POST['billing_first_name'])
        ) {
            $_POST['billing_first_name'] = $first_name; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        }

        $last_name = (string) get_user_meta($user->ID, 'billing_last_name', true);

        if (
            '' !== $last_name
            && (! isset($_POST['billing_last_name']) || '' === (string) $_POST['billing_last_name'])
        ) {
            $_POST['billing_last_name'] = $last_name; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        }

        $phone = (string) get_user_meta($user->ID, 'billing_phone', true);

        if ('' !== $phone) {
            $_POST['billing_phone'] = $phone; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        }
    }

    /**
     * Primes the checkout field values with the customer's stored profile details.
     */
    public static function prime_checkout_field_values(?string $value, string $input): ?string
    {
        if (! is_user_logged_in()) {
            return $value;
        }

        $user = wp_get_current_user();

        if (! $user instanceof WP_User) {
            return $value;
        }

        switch ($input) {
            case 'billing_email':
                return (string) $user->user_email;
            case 'billing_phone':
                $stored_phone = (string) get_user_meta($user->ID, 'billing_phone', true);

                if ('' !== $stored_phone) {
                    return $stored_phone;
                }

                return $value;
            case 'billing_first_name':
                if ('' !== (string) $value) {
                    return $value;
                }

                $stored_first_name = (string) get_user_meta($user->ID, 'billing_first_name', true);

                if ('' !== $stored_first_name) {
                    return $stored_first_name;
                }

                $profile_first_name = (string) get_user_meta($user->ID, 'first_name', true);

                if ('' !== $profile_first_name) {
                    return $profile_first_name;
                }

                return $value;
            case 'billing_last_name':
                if ('' !== (string) $value) {
                    return $value;
                }

                $stored_last_name = (string) get_user_meta($user->ID, 'billing_last_name', true);

                if ('' !== $stored_last_name) {
                    return $stored_last_name;
                }

                $profile_last_name = (string) get_user_meta($user->ID, 'last_name', true);

                if ('' !== $profile_last_name) {
                    return $profile_last_name;
                }

                return $value;
            default:
                return $value;
        }
    }

    /**
     * @param array<string, string> $original
     * @param array<string, string> $additional
     *
     * @return array<string, string>
     */
    private static function merge_custom_attributes(array $original, array $additional): array
    {
        return array_merge($original, $additional);
    }

    private static function is_woocommerce_active(): bool
    {
        if (class_exists('WooCommerce')) {
            return true;
        }

        if (! function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active('woocommerce/woocommerce.php');
    }
}

add_action('plugins_loaded', [Plugin::class, 'init']);

register_activation_hook(__FILE__, [Plugin::class, 'on_activation']);
