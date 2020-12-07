<?php

namespace Shared_Taxonomies;

/**
 * Add admin Notices to category-page. ('action=add-tag').
 *
 * To allow notices for ajax-actions we listen to WordPress ajax-call.
 *
 */
class Taxonomy_Admin_Notices {

	/**
	 * The name of the current instance.
	 * Used for naming the tranient.
	 *
	 * @var string
	 */
	public $notice_name;

	/**
	 * Contains all notices
	 *
	 * @var array
	 */
	public $notice_store = array();

	/**
	 * Adds an additional class to every notice.
	 *
	 * @var string
	 */
	public $base_classes = '';

	public function __construct( string $notice_name, string $base_classes = 'notice tax-ajax-admin-notice' ) {
		$this->notice_name;
		$this->base_classes = $base_classes;
		$this->add_hooks();
	}

	public function add_hooks() {
		add_action( 'admin_notices', array( $this, 'show_admin_notices' ), 99, 0 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts_styles' ), 10 );
		add_action( 'wp_ajax_st_get_admin_notices', array( $this, 'wp_ajax_st_get_admin_notices' ) );
	}

	public function wp_ajax_st_get_admin_notices() {
		wp_send_json( $this->render_admin_notices() );
	}

	/**
	 * A little cracy. WP adds terms via ajax.
	 * We listen to the ajax-call finishing & send our own call to check if we have notices...
	 *
	 * @return void
	 */
	public function enqueue_scripts_styles() {
		if ( empty( get_current_screen()->taxonomy ) ) {
			return; // only add to category-page.
		}
		$script = "
			jQuery(document).ajaxComplete(function(event, xhr, settings) {
				console.log('welcome!', settings);
				if( ! settings.data.match(/action=(add-tag|delete-tag|inline-save-tax)/) ) {
					return; // bail early if is other ajax call.
				}
				var ajNtcContainer = jQuery( '#ajax-response').addClass( 'loading' ); // WP adds this ajNtcContainer.
				jQuery( '.tax-ajax-admin-notice').remove();
				ajNtcContainer.append('<span class=\'spinner\'></span>');
				jQuery.ajax({
					type: 'POST',
					url: ajaxurl,
					data: { action: 'st_get_admin_notices' },
					dataType: 'json',
					success: function (response) {
						console.log('cbt', ajNtcContainer);
						ajNtcContainer.removeClass('loading').append( response );
					},
				});
			});
		";
		if ( ! wp_add_inline_script( 'jquery', $script ) ) {
			error_log( 'script was not added...' );
		}
		echo '<style>#ajax-response.loading .spinner { visibility: visible; }</style>';
	}

	/**
	 * Add an error message.
	 *
	 * @param string $message The message you want to add.
	 * @return void
	 */
	public function add_error( string $message ) {
		$this->add(
			array(
				'message' => $message,
				'class' => 'notice-error',
			)
		);
	}

	/**
	 * Add a warning message.
	 *
	 * @param string $message The message you want to add.
	 * @return void
	 */
	public function add_warning( string $message ) {
		$this->add(
			array(
				'message' => $message,
				'class' => 'notice-warning',
			)
		);
	}

	/**
	 * Add a info message.
	 *
	 * @param string $message The message you want to add.
	 * @return void
	 */
	public function add_info( string $message ) {
		$this->add(
			array(
				'message' => $message,
				'class' => 'notice-info',
			)
		);
	}

	/**
	 * Add a success message.
	 *
	 * @param string $message The message you want to add.
	 * @return void
	 */
	public function add_success( string $message ) {
		$this->add(
			array(
				'message' => $message,
				'class' => 'notice-success is-dismissible',
			)
		);
	}

	/**
	 * Add a notice. Pass more options (like class...)
	 *
	 * @param array  $message
	 * @param string $class notice-error, notice-success, notice-info, notice-warning, is-dismissible
	 * @return void
	 */
	public function add( array $notice ) {
		$notice = wp_parse_args(
			$notice,
			array(
				'message' => '',
				'details' => '',
				'class' => 'error is-dismissible tax-ajax-admin-notice',
			)
		);

		$existing = array_filter( (array) get_transient( $this->notice_name ) );
		array_push( $existing, $notice );
		set_transient( $this->notice_name, $existing, 60 );
	}

	public function add_now( array $notice ) {
		$notice = wp_parse_args(
			$notice,
			array(
				'message' => '',
				'details' => '',
				'class' => 'error is-dismissible',
			)
		);
		array_push( $this->notice_store, $notice );
	}

	private function get_admin_notices() : array {
		$transient_notices = array_filter( (array) get_transient( $this->notice_name ) );
		$this->notice_store = array_merge( $this->notice_store, $transient_notices );
		if ( ! empty( $transient_notices ) ) {
			delete_transient( $this->notice_name );
			// error_log( 'delete transient' . $this->notice_name );
		}
		return $this->notice_store;
	}

	public function render_admin_notices() : string {
		$notices = $this->get_admin_notices();
		$markup = '';
		foreach ( $notices as $notice ) {
			$class = esc_attr( $notice['class'] . ' ' . $this->base_classes );
			if ( empty( $notice['details'] ) ) {
				$markup .= sprintf( '<div class="%1$s"><p>%2$s</p></div>', $class, $notice['message'] );
			} else {
				$markup .= "
					<div class='$class'>
						<details>
							<summary style='padding:8px 0;cursor:pointer;outline: none;'>{$notice['message']}</summary>
							<p>{$notice['details']}</p>
						</details>
					</div>
				";
			}
		}
		return $markup;
	}

	public function show_admin_notices() : void {
		echo $this->render_admin_notices();
	}

}
