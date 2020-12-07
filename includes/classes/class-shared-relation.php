<?php
/**
 * @package shared-taxonomy-terms
 */

namespace Shared_Taxonomies;

use Exception;

/**
 * This class helps you to describe actions one group of (whatever) objects synchronizes to another group of objects.
 *
 * @todo This can probably be described better.
 * @note Throwing exceptions is slow (https://php.watch/articles/php-exception-performance).
 *       So we only use them in excepional situations.
 * @todo We manage cross-instance interference with WP-filters. If we manage more things with this class
 *       (not only taxonomies but e.c. terms) this might lead to problems.
 *       Use a managing class instead? This would also remove the WP-dependency and make this usable in other contexts.
 * @todo We should probably go for another approach. instead of talkin about caps we should use "actions" which are synced.
 *         - 'manage_terms'  => 'edit_posts',
 *         - 'edit_terms'    => 'edit_posts',
 *         - 'delete_terms'  => 'edit_posts',
 *         - 'assign_terms'  => 'edit_posts'
 * @todo don't do capability checking here. This should just help to manage actions
 */
class Objects_Relation {

	/**
	 * An array of source names.
	 *
	 * @var string[] $sources
	 */
	public $sources = array(); // origin.

	/**
	 * An array of destination names.
	 *
	 * @var string[]
	 */
	public $destinations = array(); // remote.

	/**
	 * Contains both sources & destinations
	 * @var array
	 */
	public $all_locations = array();

	/**
	 * An array of actions.
	 * Things $sources can do with $destinations.
	 *
	 * @var string[]
	 */
	public $actions = array();

	/**
	 * The actions, which are allowed by default. Specify others in the constructor, if you wish.
	 *
	 * @var array
	 */
	public $possible_caps = array();

	/**
	 * The name of the current relation. Is automatically generetad from supplied values.
	 * If different instances of this class have the same name, they actually do the same.
	 *
	 * @var string
	 */
	public $relation_name = '';

	public $cross_instance_cap_check_filter_name = 'relation_source_can_in_destination';

	/**
	 * Sync sources with destinations. Sources can contain destinationas and vice-versa (for convenience).
	 * You might want to sanitize source & destination after construction.
	 *
	 * @param string[] $sources
	 * @param string[] $destinations
	 * @param string[] $actions. There is three: create, delete, update.
	 */
	public function __construct( array $sources, array $destinations, array $actions ) {
		/*
			  if ( ! empty( $possible_caps ) ) {
			$this->possible_caps = $possible_caps;
		}

		if ( in_array( 'all', $actions ) ) {
			$actions = $this->possible_caps;
		}

		try {
			$this->actions = $this->sanitize_array( $actions, $this->possible_caps );
		} catch ( Exception $e ) {
			throw $e; // @todo die here?
		} */
		$this->actions = $actions;
		$this->sources = $sources;
		$this->destinations = $destinations;

		$this->all_locations = array_unique( array_merge( $sources, $destinations ) );

		$this->relation_name = $this->get_relation_name();

		if ( function_exists( 'add_filter' ) ) {
			add_filter( $this->cross_instance_cap_check_filter_name, array( $this, 'filter_actions' ), 10, 3 );
		}

	}

	/**
	 * Check if a key is in the the classes properties (only the ones which are arrays).
	 * Throw Errors if that is not the case.
	 *
	 * @param string $prop A name of the classe's prop (like sources, destinations, actions)
	 * @param string $key A value which is searched in the prop-array.
	 * @return void Doesn't return. Just throws Exceptions
	 * @throws Exception If a property does not exist, is not an array or is not containing the $key.
	 */
	public function in_property_exceptions( string $prop, string $key ) {
		if ( ! isset( $this->$prop ) || ! is_array( $this->$prop ) ){
			throw new Exception( "The given property $prop was not found." );
		}
		if ( ! in_array( $key, $this->$prop ) ){
			throw new Exception( "The given value $key is not in '$prop'." );
		}
	}

	public function is_shared( $taxonomy_slug ) {
		return in_array( $taxonomy_slug, array_merge( $this->sources, $this->destinations ) );
	}

	public function get_sources_excluding( string $exclude ) {
		return $this->get( 'sources', $exclude );
	}

	public function get_destinations_excluding( string $exclude ) : array {
		return $this->get( 'destinations', $exclude );
	}

	/**
	 * Returns all shared taxonomies, excluding the one given.
	 *
	 * @param string $taxo_slug
	 * @return array() The remaining shared taxonomies.
	 */
	private function get( $src_or_dest, string $exclude = '' ): array {
		if ( empty( $exclude ) ) {
			return $this->$src_or_dest;
		}
		return array_diff( $this->$src_or_dest, (array) $exclude );

	}

	/**
	 * Check if $sources have all $caps
	 *
	 * @param string[] $sources
	 * @param string[] $actions
	 * @param string[] $destinations
	 * @return bool
	 */
	public function sources_can_in_destinations( array $sources, array $actions, array $destinations ) :bool {
		foreach ( $sources as $source ) {
			foreach ( $destinations as $destination ) {
				foreach ( $actions as $action ) {
					try {
						$this->source_can_in_destination( $source, $destination, $action );  } catch ( \Exception $e ) {
						throw $e;
						return false;
					}
				}
			}
		}
		return false;
	}

	/**
	 * Check if $sources have all $caps
	 * @return array
	 */
	public function loop_destination_action( callable $callback )  {
		$collection = array();
		/* foreach ( $this->sources as $source ) { */
			foreach ( $this->destinations as $destination ) {
				foreach ( $this->actions as $action ) {
					$collection[] = $callback( $destination, $action );
				}
			}
		/* } */
		return $collection;
	}

	/**
	 * Triggered by the WP filter $this->cross_instance_cap_check_filter_name ('relation_source_can_in_destination')
	 *
	 * @param bool  $has_cap
	 * @param array $args
	 * @return bool
	 */
	public function filter_actions( bool $has_cap, array $args ) {
		if ( ! $has_cap ) {
			return $has_cap; // we can't allow, what is already forbidden.
		}
		if ( in_array( $this->get_relation_name(), $args['relation_names'] ) ) {
			// this is a check, which has been triggered by the current instance of the class, so pass it through...
			return $has_cap;
		}

		/**
		 * There is another instance of this class.
		 *
		 * Check, if this instance allows the action, the other instance wants to do...
		 */
		return $this->source_can_in_destination( $args['source'], $args['destination'], $args['action'] );
	}

	/**
	 * Checks if an action in source is (supposed to be) applied to a destination
	 *
	 * @param string $source
	 * @param string $destination
	 * @param string $action
	 * @return bool
	 */
	public function source_can_in_destination( string $source, string $destination, string $action ) {
		if ( ! in_array( $source, $this->sources ) ){
			throw new \Exception( "Your given source ($source) is not in the list of sources." );
		}
		if ( ! in_array( $destination, $this->destinations ) ){
			throw new \Exception( "Your given destination ($destination) is not in the list of destinations." );
		}

		$has_cap = in_array( $action, $this->actions );

		$has_cap = $this->apply_filters(
			$this->cross_instance_cap_check_filter_name,
			$has_cap,
			array(
				'source' => $source,
				'destination' => $destination,
				'action' => $action,
				'can_before_filter' => $has_cap,
			)
		);

		return $has_cap;

	}

	/**
	 * Filters a variable. This is a proxy for WordPress's apply filters. Overwrite in your Extension of this class.
	 *
	 * @param array $filter_name
	 * @param mixed $input
	 * @param array $args
	 * @return void
	 */
	public function apply_filters( string $filter_name, $input, array $args ) {
		// This is a WordPress function.
		if ( function_exists( 'apply_filters' ) ) {

			if ( ! isset( $args['relation_names'] ) ) {
				$args['relation_names'] = array();
			}
			// this might bouce throug multiple instances of this class...
			array_push( $args['relation_names'], $this->get_relation_name() );

			$input = apply_filters(
				$filter_name,
				$input,
				$args
			);
		}
		return $input;
	}

	/**
	 * Get a unique name for the current relation. If another relateion with
	 *
	 * @return string
	 */
	public function get_relation_name() {
		return 'abc';
		if ( ! empty( $this->relation_name ) ) {
			return $this->relation_name;
		}
		/**
		 * Order (in sources, caps & destinations) does not matter for a relation.
		 * So the same relation is initialized with a different order, it receives the same name.
		 */
		sort( $this->sources );
		sort( $this->actions );
		sort( $this->destinations );
		return implode( '|', $this->sources ) . '->' . implode( '|', $this->actions ) . '->' . implode( '|', $this->destinations );
	}


	/**
	 * Returns all keys in $given_opts, which are found in $possible_options.
	 *
	 * So $n[1,2,3], $h[2,3] => [2,3] (thrown Exception: 1 is not found...)
	 *
	 * @param string[] $given_opts The keys, which are searched in the $possible_options.
	 * @param string[] $possible_options The array with is searched.
	 * @throws \Exception If there were keys in $needle, which are not present in $possible_options.
	 * @return string[] Array of strings.
	 */
	public function sanitize_array( array $given_opts, array $possible_options ) : array {
		return array_filter(
			$given_opts,
			function ( $given_opt ) use ( $possible_options ) {
				$is_possible = in_array( $given_opt, $possible_options );
				if ( ! $is_possible ) {
					throw new \Exception(
						"At least one of your specified keys are found: $given_opt." .
						'Use one of the following instead: ' .
						implode( ', ', $possible_options ) . '.'
					);
				}
				return $is_possible;
			}
		);
	}

}
