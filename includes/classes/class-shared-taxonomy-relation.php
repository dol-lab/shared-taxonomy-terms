<?php
/**
 * @package shared-taxonomy-terms
 */

namespace Shared_Taxonomies;

use Exception;
use UnexpectedValueException;
use WP_Error;
use WP_Term;

/**
 * This about Taxonomies which share the same terms
 *
 * - we miss-use the term-group field. Normally only terms from the same tax can share the same term-group as an "alias".
 *
 * ❗ What is the key we use to sync terms?
 *  - term_group (bigint) is not usable, as terms might be in multiple groups?
 *
 * ❓ how do we want to init capability checking?
 * ❓ how do we handle already created terms which don't have a term_group?
 * ❓ how do we handle terms with the same slug but different term_groups?
 * - slugs in the same taxonomy are different (wp)
 * - 👷‍♀️ make sure slugs are unique for different term-groups...
 *
 * @todo write get_parent_of_shared_term()
 * @todo sync term-meta!?
 *
 * @todo: sync between blogs?
 * @todo: sync term_relations (only if the object is the same...)
 * - extend the WP_Term object
 * -
 */
class Shared_Taxonomy_Relation extends Objects_Relation {

	public $debug = true;

	/**
	 * Stores all taxonomy-slugs which are defined in WordPress (via. register_taxonomy)
	 *
	 * @var array
	 */
	private $all_taxonomy_slugs = array();


	/**
	 * Contains functions reguarding the User interface. This is about separation of concerns...
	 *
	 * @var Shared_Taxonomies_Ui
	 */
	public $ui;

	/**
	 * When you manage across multiple taxonomies, you might also need different access rights.
	 * This defines if the class checks for capabilities.
	 *
	 * @todo This has to be fully implemented.
	 *
	 * @var boolean Whether we check for capabilities.
	 */
	public $capability_check = false;

	/**
	 * Sync sources with destinations. Sources can contain destinationas and vice-versa (for convenience).
	 * You might want to sanitize source & destination after construction.
	 *
	 * @param string[] $source_taxonomies
	 * @param string[] $destination_taxonomies
	 * @param string[] $actions. There is three: create, delete, update.
	 */
	public function __construct( array $source_taxonomies, array $destination_taxonomies, array $actions ) {

		/*
		foreach ( array_merge( $source_taxonomies, $destination_taxonomies ) as $tax ) {
			if ( ! taxonomy_exists( $tax ) ) {
				throw new Exception( "The taxonomy '$tax' does not exist..." );
				return;
			}
		} */

		/**
		 * We don't check if all slugs exist here.
		 * They are not be available yet, when this is initialized.
		 *
		 * @todo do it, when the relation is initially created (and maybe in backend).
		 */
		parent::__construct( $source_taxonomies, $destination_taxonomies, $actions );
		$this->add_hooks();
		$this->ui = new Shared_Taxonomies_Ui( $this );

	}

	/**
	 *
	 * @return void
	 */
	public function add_hooks() {

		add_action( 'saved_term', array( $this, 'hook_saved_updated_term' ), 10, 4 );
		add_action( 'delete_term', array( $this, 'hook_delete_term' ), 10, 5 ); // this should be handeled automatically.
		add_filter( 'map_meta_cap', array( $this, 'filter_map_meta_cap' ), 10, 4 );


		// @todo: add actions for term-relations!

		// add_action( 'admin_enqueue_scripts', array( FF$this, 'enqueue_scripts' ), 10 );

		/*
		wp_enqueue_script()
		wp_add_inline_script( $handle:string, $data:string, $position:string ) */

	}

	/**
	 * Synchronies all terms of a taxonomy.
	 *
	 * @todo We could add warnings about terms, which are in the destination taxonomies but are not synced.
	 *
	 * @param bool $admin_notice
	 * @return void
	 * @throws Exception
	 */
	public function sync_all_shared_terms( $admin_notice = true ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $this->sources,
				'hide_empty' => false,
			)
		);

		/**
		 * This supports cyclic relations like:
		 * tax_a, tax_b => tax_a, tax_b (changes in both taxonomies sync to the other one)
		 *
		 * To avoid handling term groups twice we assume, that:
		 * - if a term-group (some term-ids) has been updated (also as destination), it doesn't need handling again.
		 */
		$skip_term_ids = array();
		foreach ( $terms as $term ) {
			if ( in_array( $term->term_id, $skip_term_ids ) ) {
				continue;
			}
			$skip_term_ids[] = $term->term_id;
			$synced_term_ids = $this->hook_saved_updated_term( $term->term_id, $term->term_taxonomy_id, $term->taxonomy, true, $admin_notice );
			$skip_term_ids   = array_merge( $skip_term_ids, $synced_term_ids );
		}
	}

	public function get_all_wp_taxonomy_slugs() {
		if ( ! empty( $this->all_taxonomy_slugs ) ) {
			return $this->all_taxonomy_slugs;
		}

		$this->all_taxonomy_slugs = array_keys( get_taxonomies() );
		return $this->all_taxonomy_slugs;
	}

	/**
	 * Assign a term_group to a term. 0 adds a new unique.
	 *
	 * @todo This has to be rewritte, if we want to sync across blogs...
	 *
	 * @param WP_Term $term
	 * @param int     $term_group Add a term group. Pass 0 to create a new unique term-group.
	 * @return array|WP_Error
	 */
	private function assign_term_group( \WP_Term $term, int $term_group ) {
		global $wpdb;
		$new_term_group_id = $term_group ? $term_group : $wpdb->get_var( "SELECT MAX(term_group) FROM $wpdb->terms" ) + 1;
		$this->debug( "Update term group of term $term->term_id to $new_term_group_id." );
		return wp_update_term(
			$term->term_id,
			$term->taxonomy,
			array(
				'term_group' => $new_term_group_id,
			)
		);
	}

	/**
	 * Fires immediately after a new term is created, before the term cache is cleaned.
	 * It is also triggered, when
	 *
	 * @since 2.3.0
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public function hook_saved_updated_term( int $term_id, int $tt_id, string $taxonomy, bool $update, $admin_notice = true ) {

		if ( ! in_array( $taxonomy, $this->sources ) ) {
			$this->debug( $taxonomy . ' is not a source in shared taxonomy..' );
			return; // nothing to do here.
		}

		remove_action( 'saved_term', array( $this, 'hook_saved_updated_term' ) ); // remove this action, avoid loops.
		$source_term   = get_term( $term_id, $taxonomy );
		$action_string = $update ? 'updated' : 'created';
		$this->debug( "Term was $action_string (term_id[$term_id], taxonomy_id[$tt_id], taxonomy slug[$taxonomy])" );

		/**
		 * Determine the term_group.
		 * It can either be inheriterd from an exiting term in the group or newly created.
		 *
		 * We also do this for newly created terms. There might already be another term in another taxonomy...
		 */
		$shared_terms = $this->get_shared_terms( array( $source_term ), array_unique( array_merge( $this->sources, $this->destinations ) ) );
		
		$term_group_id = max( array_merge( array(0), wp_list_pluck( $shared_terms, 'term_group' ) ) ); // find the biggest term_group in shared terms.

		if ( 0 == $term_group_id // there is no term-group yet.
			|| $term_group_id != $source_term->term_group ) { // the source term does not have the proper term-group.
			$this->assign_term_group( $source_term, $term_group_id ); // make sure the term has a term_group.
			$source_term = get_term( $term_id, $taxonomy );
		}

		$remaining_taxos = $this->get_destinations_excluding( $taxonomy ); // we already added one, we just want to handle to other ones.

		$terms = array();
		foreach ( $remaining_taxos as $taxo_slug ) {
			$errors = false;
			try {
				$terms[] = $this->sync_taxonomy_action_create_update( $source_term, $taxo_slug, $admin_notice );
			} catch ( \Exception $e ) {
				$errors = true;
				$this->debug( $taxonomy . ' is not a source in shared taxonomy..' );
				if ( $admin_notice ) {
					$this->ui->admin_notice->add_warning( $e->getMessage() );
				}
			}
		}
		add_action( 'saved_term', array( $this, 'hook_saved_updated_term' ), 10, 4 );

		return $terms;

		// @todo how do we want to fail?
	}

	/**
	 * Synchronizes a shared term to another taxonomy.
	 * If a shared parent is not found in destination it is created (recursively).
	 *
	 * @todo: this breaks on errors... do we want that?
	 *
	 * @param WP_Term $source_term
	 * @param string  $dest_taxo_slug the slug of the shared taxonomy
	 * @return int The term_id if the inserted/updated term in the specified shared-taxonomy.
	 * @throws Exception
	 * @throws UnexpectedValueException
	 */
	public function sync_taxonomy_action_create_update( \WP_Term $source_term, string $dest_taxo_slug, $admin_notice = false ) : int {
		$this->in_property_exceptions( 'destinations', $dest_taxo_slug );

		/*
			  if ( ! $this->taxononomy_slugs_exist( $dest_taxo_slug ) ) {
			$msg = "Could not be added, the taxonomy with the name <code>$dest_taxo_slug</code> does not exist.";
			$this->debug( $msg );
			throw new UnexpectedValueException( $msg );
		} */

		/**
		 * Check the parent term (of a shared term) exists.
		 * If it does not: create recursively for all older generations.
		 */
		$dest_parent_id = 0;
		if ( 0 !== $source_term->parent ) {
			$source_parent_term = get_term( $source_term->parent, $source_term->taxonomy );
			if ( ! $source_parent_term instanceof \WP_Term ) {
				$msg = is_wp_error( $source_parent_term ) ? $source_parent_term->get_error_message() : '';
				throw new Exception( "Error finding parent of term[$source_term->term_id]. $msg" );
			}
			$dest_parent_term = $this->get_shared_term( $source_parent_term, $dest_taxo_slug );
			if ( $dest_parent_term ) { // parent exists.
				$dest_parent_id = $dest_parent_term->term_id;
			} else { // parent term does not exist in destination.
				$dest_parent_id = $this->sync_taxonomy_action_create_update( $source_parent_term, $dest_taxo_slug, $admin_notice );
			}
		}

		$dest_term = $this->get_shared_term( $source_term, $dest_taxo_slug );
		$action    = '';
		try {
			if ( ! $dest_term ) { // term does not yet exist, create!
				$term_id = $this->insert_get_shared_term( $source_term, $dest_taxo_slug, $dest_parent_id );
				$this->maybe_add_success_admin_notice( $term_id, $dest_taxo_slug, 'created', $admin_notice );
			} else {
				$term_id = $this->update_get_shared_term( $source_term, $dest_term, $dest_parent_id );
				$this->maybe_add_success_admin_notice( $term_id, $dest_taxo_slug, 'updated', $admin_notice );
			}
		} catch ( \Exception $e ) {
			throw new Exception(
				sprintf( 'An error occured adding the taxonomy "%s":', $dest_taxo_slug )
				. '"' . $e->getMessage() . '"'
			);
		}

		return $term_id;
	}

	private function maybe_add_success_admin_notice( int $term_id, string $dest_taxo_slug, string $action, bool $admin_notice ) {
		if ( ! $admin_notice ) {
			return;
		}
		$term        = get_term( $term_id, $dest_taxo_slug );
		$term_link   = get_edit_term_link( $term->term_id, $dest_taxo_slug );
		$linked_term = "<a href='$term_link'>$term->name</a>";
		$msgs        = array(
			'created' => esc_html__( 'The term %1$s has successfully been added to taxonomy %2$s.', 'shared-term' ),
			'updated' => esc_html__( 'The term %1$s has successfully been updated in taxonomy %2$s.', 'shared-term' ),
		);
		$notice      = sprintf(
			$msgs[ $action ],
			$linked_term,
			$this->ui->get_taxonomy_label( $dest_taxo_slug )
		);
		$this->ui->admin_notice->add_success( $notice );
	}

	public function sync_taxonomy_action_delete( \WP_Term $source_term, $dest_taxo_slug ) {

	}

	/**
	 * Insert a shared term (in anther taxonomy)
	 *
	 * @param WP_Term $source_term
	 * @param mixed   $dest_taxo_slug
	 * @return int The id of the newly created term.
	 * @throws Exception
	 */
	private function insert_get_shared_term( \WP_Term $source_term, string $dest_taxo_slug, int $dest_parent_id ) : int {

		$this->debug(
			"Sync term '$source_term->name' with term_group '$source_term->term_group'
			from tax '$source_term->taxonomy' to taxonomy '$dest_taxo_slug'"
		);

		$new_term = wp_insert_term(
			$source_term->name,
			$dest_taxo_slug,
			array(
				'term_group'  => $source_term->term_group, // doesn't work, see update_term below
				'description' => $source_term->description,
				'parent'      => $dest_parent_id,
				'slug'        => $source_term->slug,
			)
		);

		if ( is_wp_error( $new_term ) ) {
			$this->debug( $new_term->get_error_message() );
			throw new Exception( $new_term->get_error_message() );
		}

		/**
		 * We misuse the term_group property (in wp_terms tables). It is meant for "aliases" in the same taxonomy.
		 * Thats why wp_insert_term does not respect our term_group. Update does.
		 */
		$term = wp_update_term(
			$new_term['term_id'],
			$dest_taxo_slug,
			array(
				'term_group' => $source_term->term_group,
			)
		);

		if ( is_wp_error( $term ) ) {
			throw new Exception( "An error occured inserting a shared term: '" . $term->get_error_message() . "'" );
		}
		return (int) $term['term_id'];
	}

	private function update_get_shared_term( \WP_Term $source_term, \WP_Term $dest_term, int $dest_parent_id ) :int {
		$term = wp_update_term(
			$dest_term->term_id,
			$dest_term->taxonomy,
			array(
				'term_group'  => $source_term->term_group, // doesn't work, see update_term below
				'description' => $source_term->description,
				'parent'      => $dest_parent_id,
				'slug'        => $source_term->slug,
				'name'        => $source_term->name,
				// 'taxonomy' => $dest_term->taxonomy, // don't overwrite taxonomy!
			)
		);

		if ( is_wp_error( $term ) ) {
			throw new Exception( "Error updating a shared term[$dest_term->term_id]" . $term->get_error_message() );
		}
		return (int) $term['term_id'];
		/*
			if ( ! $this->source_can_in_destination( $source_taxo_slug, $dest_taxo_slug, 'manage_terms' ) || true ) {
			$msg = "You tried, but couldn't!";
			$this->debug( $msg );
			$this->admin_notice->add(
				array(
					'message' => 'nah',
					'class' => 'notice-error',
				),
				true
			);
		} */

		// $this->debug( $ms );
		/*
			$ret = $wpdb->insert(
			$wpdb->term_taxonomy,
			array(
				'term_id' => $term_id,
				'taxonomy' => $dest_taxo_slug,
				'description' => 'sth',
				'parent' => 0,
			) + array( 'count' => 0 )
		); */

	}

	public function get_shared_term( \WP_Term $term_in_group, string $taxonomy_slug ) {
		if ( $term_in_group->taxonomy == $taxonomy_slug ) {
			throw new Exception( 'You already found what you were searching for...' );
		}
		$terms = $this->get_shared_terms( array( $term_in_group ), (array) $taxonomy_slug );
		return ( isset( $terms[0] ) ) ? $terms[0] : false;
	}


	/**
	 *  Get a shared term from another taxonomy.
	 * - We search for terms with the same term_group first.
	 * - If not found: search for term with same slug.
	 *
	 * @param WP_Term $term_in_group
	 * @param array   $taxonomy_slugs
	 * @param bool    $single
	 * @return mixed
	 * @throws Exception
	 */

	/**
	 * Get all shared terms of the given term.
	 *
	 * @param WP_Term[] $terms_in_group
	 * @param string[]  $taxonomy_slugs
	 * @return WP_Term[]
	 * @throws Exception
	 */
	public function get_shared_terms( array $terms_in_group, array $taxonomy_slugs ) {
		$terms = array();

		if ( empty( $terms_in_group ) || empty( $taxonomy_slugs ) || ! is_a( $terms_in_group[0], '\WP_Term' ) ) {
			return new WP_Error( 'broke', 'Make sure the parameters are not empty and you pass WP_Term - Objects to get_shared_terms. ' );
		}

		$term_groups = array_unique( array_filter( wp_list_pluck( $terms_in_group, 'term_group' ) ) );

		/**
		 * We check if all $terms_in_group have non-zero term-groups.
		 */
		if ( count( $term_groups ) == count( $terms_in_group ) ) {
			$terms = $this->get_terms_by_group_ids( $term_groups, array( 'taxonomies' => $taxonomy_slugs ) );

		}

		$all_found = count( $terms ) === count( $terms_in_group );

		// search by groups didn't yield any results. search by slugs.
		if ( ! isset( $terms[0] ) || ! $terms[0] instanceof \WP_Term || ! $all_found ) {
			error_log( 'Shared-terms: Something is wrong with your term_groups. Fall back to matching by slugs.' );
			$slugs = wp_list_pluck( $terms_in_group, 'slug' );
			$terms = get_terms(
				array(
					'slug'       => $slugs,
					'taxonomy'   => $taxonomy_slugs,
					'hide_empty' => false,
				)
			);
		}

		if ( is_wp_error( $terms ) ) {
			throw new Exception( $terms->get_error_message() ); // @todo not sure if this is a good idea. just return wp_error?
		}
		return $terms;

	}

	/**
	 * This is sad. We can't use get_terms, get_term_by or WP_Term_Query. They don't respect term_group (WP 5.5.3).
	 *
	 * @todo This could be a PR for WP? term_group(s) also might get abandoned...
	 * @todo $args currently only supports 'taxonomies'.
	 *
	 * @param int[] $group_id
	 * @param array $args
	 * @return \WP_Term[]
	 * @throws Exception If there are multiple terms in a term_group for the same taxonomy.
	 */
	public function get_terms_by_group_ids( array $group_ids, $args = array() ) {
		/**
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		$args['taxonomies'] = isset( $args['taxonomies'] ) ? (array) $args['taxonomies'] : array();

		$limit_taxonomy = '';
		if ( ! empty( $args['taxonomies'] ) ) {
			$tax_ids        = implode( "', '", array_map( 'esc_sql', $args['taxonomies'] ) );
			$limit_taxonomy = "AND trm_tax.taxonomy IN ('$tax_ids')";
		}

		// create placeholders for every group_id.
		$placeholders = implode( ', ', array_fill( 0, count( $group_ids ), '%d' ) );



		$query  = $wpdb->prepare(
			"SELECT * FROM $wpdb->terms as trm
				INNER JOIN $wpdb->term_taxonomy as trm_tax
				ON trm.term_id = trm_tax.term_id
			WHERE trm.term_group in ($placeholders)",
			$group_ids
		);
		$query .= ' ' . $limit_taxonomy;

		$cache_key = md5( $query );
		if ( ! wp_cache_get( $cache_key, 'terms' ) ) {
			$results = $wpdb->get_results( $query );
			wp_cache_set( $cache_key, $results, 'terms' );
		} else {
			$results = wp_cache_get( $cache_key, 'terms' );
		}

		// $results = $wpdb->get_results( $query );
		
		$result_count = empty( $results ) ? 0 : count( $results );
		if ( $result_count > 1
			&& count( $args['taxonomies'] ) == 1
			&& count( $group_ids ) == 1
		) {
			throw new Exception(
				'Warning: There are multiple terms with the same term group in the same taxonomy. ' .
				"This is not expected by the 'shared-taxonomy-terms' plugin."
			);
		}


		$terms = array();
		foreach ( $results as $result ) {
			$term    = sanitize_term( $result, $result->taxonomy, 'raw' );
			$terms[] = new \WP_Term( $term ); // This doesn't require a switch to blog. */
		}
		return $terms;
	}


	/**
	 * Fires after a term is deleted from the database and the cache is cleaned.
	 *
	 * @since 2.5.0
	 * @since 4.5.0 Introduced the `$object_ids` argument.
	 *
	 * @param int    $source_term_id         Term ID.
	 * @param int    $tt_id        Term taxonomy ID.
	 * @param string $source_taxonomy     Taxonomy slug.
	 * @param mixed  $source_deleted_term Copy of the already-deleted term, in the form specified
	 *                             by the parent function. WP_Error otherwise.
	 * @param array  $object_ids   List of term object IDs.
	 */
	public function hook_delete_term( int $source_term_id, int $tt_id, string $source_taxonomy, $source_deleted_term, $object_ids ) {

		remove_action( 'delete_term', array( $this, 'hook_delete_term' ) ); // remove the action, so it does not trigger itself.

		if ( ! $source_deleted_term instanceof WP_Term ) {
			$msg = is_wp_error( $source_deleted_term ) ? $source_deleted_term->get_error_message() : '';
			throw new Exception( "An error occured deleting shared taxonomy term[$source_term_id]. " . $msg );
		}

		$remaining_taxos = $this->get_destinations_excluding( $source_taxonomy ); // we already added one, we just want to handle to other ones.
		foreach ( $remaining_taxos as $dest_taxo_slug ) {
			$dest_term    = $this->get_shared_term( $source_deleted_term, $dest_taxo_slug );
			$dest_tax_url = $this->ui->get_taxonomy_label( $dest_taxo_slug );
			$dest_deleted_term = $dest_term ? wp_delete_term( $dest_term->term_id, $dest_taxo_slug ) : 'false';
			if ( false == $dest_deleted_term || ! $dest_term ) {
				$this->ui->admin_notice->add_info(
					sprintf(
						/** Translator: */
						esc_html__( 'Deleting shared term: No shared term "%1$s" was found in taxonomy %2$s for term[%3$s].', 'shared-terms' ),
						$dest_term->name,
						$dest_tax_url,
						$source_term_id
					)
				);
			} elseif ( true == $dest_deleted_term ) {
				$this->ui->admin_notice->add_success(
					sprintf(
						/** Translator: */
						esc_html__( "Successfully deleted shared term '%1\$s' for taxonomy '%2\$s'", 'shared-terms' ),
						$dest_term->name,
						$dest_tax_url,
					)
				);
			} elseif ( is_wp_error( $dest_deleted_term ) ) {
				$this->ui->admin_notice->add_error(
					sprintf(
						/** Translator: */
						esc_html__( "Error deleting shared term: '%s'.", 'shared-terms' ),
						$dest_deleted_term->get_error_message(),
					)
				);
			} elseif ( 0 === $dest_deleted_term ) {
				$this->ui->admin_notice->add_warning(
					sprintf(
						/** Translator: */
						esc_html__( "Can't delete shared term for taxonomy %s as it is set as the default taxonomy", 'shared-terms' ),
						$dest_tax_url,
					)
				);
			}
		}

		add_action( 'delete_term', array( $this, 'hook_delete_term' ), 10, 5 ); // add the action again.

	}

	/**
	 * Filters a user's capabilities depending on specific context and/or privilege.
	 *
	 * When you register a taxonomy (register_taxonomy) you can add a (meta-)capbilities.
	 * This allows you to specify custom meta-capabilities...
	 *
	 * @todo This overwrites the caps set before. Check again. Caps have the form array( 'capname' => 1 ).
	 *
	 * @since 2.8.0
	 *
	 * @param string[] $caps    Array of the user's capabilities.
	 * @param string   $cap     Capability name.
	 * @param int      $user_id The user ID.
	 * @param array    $args    Adds the context to the cap. Typically the object ID.
	 */
	public function filter_map_meta_cap( $all_caps, $custom_cap, $user_id, $args ) {
		if ( ! in_array( $custom_cap, $this->actions ) ) {
			return $all_caps;
		}

		$taxonomy_slug = $args[0];
		if ( ! in_array( $taxonomy_slug, $this->destinations ) ) {
			return $all_caps;
		}

		$tax_obj = get_taxonomy( $taxonomy_slug );

		if ( ! $tax_obj ) {
			$this->debug( "Taxonomy $tax_obj not found" );
			return $all_caps;
		}

		if ( ! isset( $tax_obj->cap->$custom_cap ) ) {
			$this->debug( "The capability $custom_cap is not defined in the taxonomie's ($taxonomy_slug) capabilities." );
			return $all_caps;
		}

		/**
		 * Taxonomies map capabilities 'manage_terms' becomes 'edit_posts'. Check register_taxonomy.
		 */
		$all_caps = array( $tax_obj->cap->$custom_cap );
		return $all_caps;
	}

	/**
	 * Check if all given $taxo_slugs are registered (in WP via. register_taxonomy).
	 *
	 * Careful: As register_taxonomy should be hooked to 'init' you can only be sure that a tax exists after...
	 *
	 * @param string[]|string $taxo_slugs One or many taxonomy slugs.
	 * @return bool return true if all given taxonomy slugs exist in WP.
	 */
	public function taxononomy_slugs_exist( $taxo_slugs ) {
		return empty( array_diff( (array) $taxo_slugs, $this->get_all_wp_taxonomy_slugs() ) );
	}

	/**
	 * Check if you have all required capabilities.
	 *
	 * @todo: We could split the "markup"-part to another fuction in Shared_Taxonomies_Ui.
	 *
	 * @return string[]
	 */
	public function collect_capability_issues() {
		$taxonomy_issues = array();
		foreach ( $this->destinations as $destination ) {
			$issue_actions = array();
			foreach ( $this->actions as $action ) {
				if ( ! current_user_can( $action, $destination ) ) {
					$meta = implode( ', ', map_meta_cap( $action, get_current_user_id(), $destination ) );
					$issue_actions[] = "$action ($meta)";
				}
			}
			if ( ! empty( $issue_actions ) ) {
				$taxonomy_issues[] = sprintf(
					esc_html__( 'You are missing one or more capabilities in the taxonomy "%1$s": %2$s', 'shared-terms' ),
					$this->get_taxonomy_label( $destination ),
					'<ul><ol>' . implode( '</ol><ol>', $issue_actions ) . '</ol></ul>',
				);
			}
		}
		return $taxonomy_issues;
	}


	private function debug( $message, $data = null ) {
		if ( $this->debug ) {
			error_log( 'class SharedTaxonomies | ' . $message . print_r( $data, true ) );
		}
	}
}
