<?php
/**
 * Uninstall routine. Removes all plugin options and cached rate transients.
 *
 * @package DSRA
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Bulk-remove the plugin's options and cached rate transients. Direct queries are
// appropriate for a one-off uninstall routine: the LIKE patterns are constant
// strings with no user input, and caching is irrelevant during uninstall.
// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'dsra\_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_dsra\_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_timeout\_dsra\_%'" );
// phpcs:enable WordPress.DB.DirectDatabaseQuery
