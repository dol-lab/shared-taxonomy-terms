<?php
/**
 * @package shared-taxonomy-terms
 */

namespace Shared_Taxonomies;

/**
 * Just some seperation if concerns...
 */
class Shared_Taxonomies_Ui {

	/**
	 * The "parent" - class.
	 *
	 * @var Shared_Taxonomy_Relation
	 */
	public $parent;

	/**
	 * Helps to add notices to taxonomy pages (where things are added via ajax).
	 *
	 * @var Taxonomy_Admin_Notices
	 */
	public $admin_notice;

	public function __construct( Shared_Taxonomy_Relation $shared_tax ) {
		$this->parent = $shared_tax;
		$this->admin_notice = new Taxonomy_Admin_Notices( $this->parent->get_relation_name() );
		$this->add_hooks();
	}

	/**
	 *
	 * @return void
	 */
	public function add_hooks() {
		add_action( 'admin_init', array( $this, 'warn_non_existing_taxonomies' ), 10 );
		add_action( 'admin_notices', array( $this, 'hook_add_admin_notices' ), 10, 0 );

	}

	/**
	 * Describe the relation of the given taxonomy in the current context.
	 *
	 * @param string $taxo_slug The slug ot the taxonomy we want to describe.
	 * @return string[] the descriptions. Empty if the taxonomy is not in this context.
	 */
	public function get_descriptions( $taxo_slug ) {
		$descriptions = array();
		if ( ! $this->parent->is_shared( $taxo_slug ) ) {
			return $descriptions;
		}
		$descriptions[] = '➡ ' . $this->get_source_description( $taxo_slug );
		$descriptions[] = '⬅ ' . $this->get_dest_description( $taxo_slug );
		$descriptions[] = '🔨 ' . \sprintf(
			/* Translators: %s contains a bullet-list of actions.*/
			esc_html__(
				'Following actions are applying (to the taxonomies mentioned above): %s',
				'shared-terms'
			),
			'<br>- ' . implode( '<br>- ', $this->parent->actions )
		);

		return array_filter( $descriptions );
	}

	private function get_source_description( $taxo_slug ) {
		if ( ! in_array( $taxo_slug, $this->parent->sources ) ) {
			return;
		}
		$dest_notice = array_map(
			function( $tx ) {
				return '- ' . $this->get_taxonomy_label( $tx ); },
			$this->parent->get_destinations_excluding( $taxo_slug )
		);
		return \sprintf(
			/* Translators: %1$s is the current taxonomy, %1$s is a bullet-point list of other taxonomies. */
			esc_html__( "The current taxonomy (%1\$s) synchronizes it's changes to the following taxonomies: %2\$s", 'shared-terms' ),
			$this->get_taxonomy_label( $taxo_slug ),
			'<br>' . implode( '<br>', $dest_notice ),
		);
	}

	/**
	 * Describes the given taxonomy as a "destination-taxonomy".
	 * Empy, if it's not a destination-taxonomy.
	 *
	 * @param string $taxo_slug
	 * @return string
	 */
	private function get_dest_description( $taxo_slug ) {
		if ( ! in_array( $taxo_slug, $this->parent->destinations ) ) {
			return;
		}
		$source = array_map(
			function( $tx ) {
				return '- ' . $this->get_taxonomy_label( $tx );
			},
			$this->parent->get_sources_excluding( $taxo_slug )
		);
		return \sprintf(
			/* Translators: %1$s is the current taxonomy, %1$s is a bullet-point list of other taxonomies. */
			esc_html__( 'Changes in the following taxonomies are applied to this taxonomy (%1$s): %2$s', 'shared-terms' ),
			$this->get_taxonomy_label( $taxo_slug ),
			'<br>' . implode( '<br>', $source ),
		);
	}

	public function warn_non_existing_taxonomies() {
		foreach ( $this->parent->sources as $tax_name ) {
			if ( ! $this->parent->taxononomy_slugs_exist( $tax_name ) ) {
				$this->admin_notice->add_error(
					"The taxonomy <code>$tax_name</code> is not defined, but specified as a 'shared taxonomy'.",
				);
			}
		}
	}

	public function maybe_add_taxonomy_issues_admin_notices() {
		$issues = $this->parent->collect_capability_issues();
		if ( ! empty( $issues ) ) {
			$issue_list = implode( '</li><li>', $issues );
			$this->admin_notice->add(
				array(
					'message' => esc_html__(
						'There are issues with your Shared Taxonomy.
					Your configured sychronization might fail as you dont have necessary capabilities.',
						'shared-terms'
					) .
					"<br><ul><li>$issue_list</li></ul>",
					'class' => 'notice-warning notice-large',
				)
			);
		}

	}
	/**
	 * Add information to the headline, so you know that the taxonomy is shared
	 * - when you edit all terms (edit-tags.php)
	 * - when you edit a single term (term.php)
	 *
	 * @param string $taxo_slug
	 * @return void
	 */
	public function add_taxonomy_info_admin_notices( $taxo_slug ) :void {

		$parent = '#wpbody-content';
		$headline = $parent . ' h1'; // this is unspecific, as it applies to headline in term.php & edit-tags.php
		$name = esc_html__( 'Shared Taxonomy', 'shared-terms' );
		echo "
			<style>
				$headline { cursor: pointer; }
				$headline:after { content: ' · $name ⏷';opacity: 0.5;}
				$parent.show-taxonomy-info h1:after { content: ' · $name ⏶'; }
				.shared-taxonomy-info~.shared-taxonomy-info{margin-top:-15px;}
				.show-taxonomy-info .shared-taxonomy-info{display:block}
			</style>
			<script>
				jQuery(document).ready(function(){
					jQuery('$headline').unbind('click').click(()=>{jQuery('$parent').toggleClass('show-taxonomy-info')});
				});
			</script>
		";

		// admin-notices which are not deleted (without the base class tax-ajax-admin-notice).
		$permanent_notice = new Taxonomy_Admin_Notices( $this->parent->get_relation_name(), 'notice' );

		foreach ( $this->get_descriptions( $taxo_slug ) as $description ) {
			$permanent_notice->add_now(
				array(
					'message' => $description,
					'class' => 'shared-taxonomy-info notice-info notice-large hidden',
				)
			);
		}
	}

	/**
	 * Depending on the time you check for a taxonomy it might not yet be initialized.
	 * When this is the case, we just fall back to the taxonomy slug.
	 *
	 * @param string $taxonomy_slug
	 * @return string Name or slug of the taxonomy.
	 */
	public function get_taxonomy_label( $taxonomy_slug ) {
		$tax = get_taxonomy( $taxonomy_slug );
		// error_log( 'tax!' . print_r( $tax, true ) );

		if ( ! $tax ) {
			return $taxonomy_slug;
		}
		$markup = $tax->label;
		/*
		 if ( ! current_user_can( 'manage_terms', $taxonomy_slug ) ) {
			error_log( "You are not allowed to mange_terms for $taxonomy_slug" );
			$markup = esc_html__( '(Not allowed for you)', 'shared_terms' ) . $markup;
		} */

		if ( $tax->show_ui ) {
			$location = add_query_arg(
				array(
					'taxonomy' => $taxonomy_slug,
				),
				admin_url( 'edit-tags.php' )
			);
			$markup = "<a href='$location'>$markup</a>";
		}

		return $markup;

	}

	public function hook_add_admin_notices() {
		// error_log( 'FN:' . __FUNCTION__ . 'screen  ' . ' ' . print_r( get_current_screen(), true ) );

		if ( $this->parent->is_shared( get_current_screen()->taxonomy ) ) {
			$this->add_taxonomy_info_admin_notices( get_current_screen()->taxonomy );
			$this->maybe_add_taxonomy_issues_admin_notices();
		}

		$menu_marker = '';
		foreach ( array_unique( array_merge( $this->parent->sources, $this->parent->destinations ) ) as $taxo_slug ) {
			$menu_marker .= "a[href$='taxonomy=$taxo_slug']:after{content:' · ST';opacity: 0.5;}";
		}
		echo "<style>$menu_marker</style>";

	}

}
