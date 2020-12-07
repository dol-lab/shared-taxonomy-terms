<?php
/**
 * Plugin Name: Shared Taxonomy Terms
 * Version: 1.0.0
 * Plugin URI: http://www.hughlashbrooke.com/
 * Description: This is your starter template for your next WordPress plugin.
 * Author: Hugh Lashbrooke
 * Author URI: http://www.hughlashbrooke.com/
 * Requires at least: 4.0
 * Tested up to: 4.0
 *
 * Text Domain: shared-terms
 * Domain Path: /lang/
 *
 * @package WordPress
 * @author Hugh Lashbrooke
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load plugin class files.
$plugin_class_dir = 'includes/plugin-classes/';
require_once $plugin_class_dir . 'class-shared-taxonomy-terms-plugin.php';
require_once $plugin_class_dir . 'class-shared-taxonomy-terms-settings.php';

// Load plugin libraries.
require_once $plugin_class_dir . 'lib/class-shared-taxonomy-terms-admin-api.php';
require_once $plugin_class_dir . 'lib/class-shared-taxonomy-terms-taxonomy.php';

// classes
require_once 'includes/classes/class-shared-relation.php';
require_once 'includes/classes/class-shared-taxonomy-relation.php';
require_once 'includes/classes/class-shared-taxonomies-ui.php';
require_once 'includes/classes/class-taxonomy-admin-notices.php';
require_once 'includes/classes/class-shared-taxonomy-manager.php';

shared_taxonomy_terms(); // initialize the plugin.

/**
 * Returns the main instance of Shared_Taxonomy_Terms to prevent the need to use globals.
 *
 * @since  1.0.0
 * @return object Shared_Taxonomy_Terms
 */
function shared_taxonomy_terms() {

	demo_shared_taxonomies();
	$instance = Shared_Taxonomy_Terms_Plugin::instance( __FILE__, '1.0.0' );
	return $instance;

}


function demo_shared_taxonomies() {
		/*
	$this->shared_taxonomy_slugs = $shared_taxonomy_slugs;
	$this->shared_taxonomy_slugs = get_option( $this->shared_term_option_name, $this->shared_taxonomy_slugs ); */

	/** This is currently hardcoded, move to options page. */
	$taxonomies = array( 'post-affiliation', 'user-affiliation', 'blog-affiliation' );

	/*  new Shared_Taxonomies\Shared_Taxonomies( $taxonomies ); */

	$relation = new Shared_Taxonomies\Shared_Taxonomy_Relation(
		// array( 'user-affiliation', 'blog-affiliation' ),
		$taxonomies,
		$taxonomies,
		array( 'manage_terms', 'edit_terms', 'delete_terms', 'assign_terms' )
	);

	$manager = new Shared_Taxonomies\Shared_Taxonomy_Manager();
	$manager->add( $relation );


}
