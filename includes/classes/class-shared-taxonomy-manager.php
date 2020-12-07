<?php

namespace Shared_Taxonomies;

/**
 * Manages all shared Taxonomy objects.
 * There don't create a new instance, just use the one publicly available (via the plugin).
 *
 * @todo This could also contain admin-setting interfaces?
 * @todo Check for the same thing init twice?
 *
 * @package Shared_Taxonomies
 */
class Shared_Taxonomy_Manager {

	/**
	 * Contains all intialized shared taxonomies.
	 *
	 * @var Shared_Taxonomy_Relation[]
	 */
	public $shared_taxonomies;

	/**
	 *
	 * @var Taxonomy_Admin_Notices
	 */
	public $notices;

	protected $action_name_sync_all = 'sync_all_shared_taxonomies';

	public $check_capabilities = true;


	public function __construct() {
		$this->notices = new Taxonomy_Admin_Notices( 'taxonomy_manager' );
	}

	public function add( Shared_Taxonomy_Relation $st ) {
		$this->shared_taxonomies[] = $st;
		$this->init();
	}

	public function init() {
		// Add the "Sync all taxonomies" button to every taxonomy, which is shared.
		foreach ( $this->get_all_taxonomy_slugs() as $taxo_slug ) {
			add_action( "after-{$taxo_slug}-table", array( $this, 'sync_shared_taxonomies_button' ), 99, 1 );
		}

		// This is only triggered on the edit-tags.php -page. Check for do_action( "load-{$pagenow}" );
		// add_action( 'load-edit-tags.php', array( $this, 'run_sync_taxonomies' ) );
		add_action( "admin_post_$this->action_name_sync_all", array( $this, 'admin_post' ) );
	}

	public function sync_all_shared_taxonomies( $admin_notice ) {
		foreach ( $this->shared_taxonomies as $st ) {
			$st->sync_all_shared_terms( $admin_notice );
		}
	}

	public function get_all_taxonomy_slugs() {
		$slugs = call_user_func_array( 'array_merge', (array) wp_list_pluck( $this->shared_taxonomies, 'all_locations' ) );
		return array_unique( $slugs );
	}

	public function get_all_capability_issues() {
		$issues = array();
		foreach ( $this->shared_taxonomies as $st ) {
			$issues = array_merge( $issues, $st->collect_capability_issues() );
		}
		return $issues;
	}

	public function admin_post() {
		if ( wp_verify_nonce( $_POST[ $this->action_name_sync_all . '_nonce' ], $this->action_name_sync_all ) ) {
			if ( isset( $_POST[ $this->action_name_sync_all ] ) ) {
				$this->sync_all_shared_taxonomies( true );
			}
		} else {
			$this->notices->add_error( esc_html__( 'Invalid nonce. Pleas try again.', 'shared_terms' ) );
		}

		if ( ! isset( $_POST['_wp_http_referer'] ) ) {
			die( 'Missing target.' );
		}
		wp_safe_redirect( urldecode( $_POST['_wp_http_referer'] ) );
		exit;
	}


	/**
	 * NOT YET IMPLEMENTED
	 *
	 * @param string $taxonomy
	 * @return void
	 */
	public function sync_shared_taxonomies_button( $taxonomy ) {
		$button_text = esc_html__( 'Sync Shared Taxonomies', '' );
		$nonce = wp_nonce_field( $this->action_name_sync_all, $this->action_name_sync_all . '_nonce', false, false );
		$url = admin_url( 'admin-post.php' );
		$redirect = urlencode( remove_query_arg( 'msg', $_SERVER['REQUEST_URI'] ) );

		$title_text = esc_attr__( "All existing terms in any existing 'shared taxonomies' will appear here (and in the other shared taxonomies)." );
		echo "
			<hr>
			<form action='$url' method='POST'>
				$nonce
				<input type='hidden' name='action' value='$this->action_name_sync_all'>
				<input type='hidden' name='_wp_http_referer' value='$redirect'>
				<input
					name='$this->action_name_sync_all'
					id='$this->action_name_sync_all'
					type='submit'
					value='$button_text'
					title='$title_text'
					class='button button-secondary'
				/>
			</form>
		";

	}


}
