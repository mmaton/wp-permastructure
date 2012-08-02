<?php
/*
Plugin Name: Custom Post Type Permalinks
Plugin URI: https://github.com/interconnectit/custom-post-type-permalinks
Description: Adds the ability to define permalink structures for any custom post type using rewrite tags.
Version: 0.1
Author: Robert O'Rourke
Author URI: http://interconnectit.com
License: GPLv2 or later
*/

/**
 * Usage:
 * 
 * When registering a post type you can add a value to the rewrite
 * property with the key 'permastruct' to define your default
 * permalink structure.
 * 
 * eg:
 * 
 * register_post_type( 'my_type', array(
 * 		...
 * 		'rewrite' => array( 'permastruct' => '/%custom_taxonomy%/%author%/%postname%/' ),
 *  	...
 * ) );
 * 
 * Alternatively you can set the permalink structure from the permalinks settings page
 * in admin.
 */

if ( ! class_exists( 'custom_post_type_permalinks' ) ) {

add_action( 'init', array( 'custom_post_type_permalinks', 'instance' ), 0 );

class custom_post_type_permalinks {

	public $settings_section = 'custom_post_type_permalinks';

	/**
	 * Reusable object instance.
	 *
	 * @type object
	 */
	protected static $instance = null;

	/**
	 * Creates a new instance. Called on 'init'.
	 * May be used to access class methods from outside.
	 *
	 * @see    __construct()
	 * @return void
	 */
	public static function instance() {
		null === self :: $instance AND self :: $instance = new self;
		return self :: $instance;
	}


	public function __construct() {

		// Settings fields on permalinks page
		add_action( 'admin_init', array( $this, 'admin_init' ) );

		// add our new peramstructs to the rewrite rules
		add_filter( 'post_rewrite_rules', array( $this, 'add_permastructs' ) );

		// parse the generated links
		add_filter( 'post_type_link', array( $this, 'parse_permalinks' ), 10, 4 );

		// that's it!

	}


	public function admin_init() {

		add_settings_section(
			$this->settings_section,
			__( 'Other post type permalink settings' ),
			array( $this, 'settings_section' ),
			'permalink'
		);

		foreach( get_post_types( array( '_builtin' => false, 'public' => true ), 'objects' ) as $type ) {
			$id = $type->name . '_permalink_structure';

			register_setting( 'permalink', $id, 'sanitize_text_field' );
			add_settings_field(
				$id,
				__( $type->label . ' permalink structure' ),
				array( $this, 'permalink_field' ),
				'permalink',
				$this->settings_section,
				array( 'id' => $id )
			);
		}

	}


	public function settings_section() {

	}


	public function permalink_field( $args ) {
		echo '<input type="text" class="regular-text code" value="' . esc_attr( get_option( $args[ 'id' ] ) ) . '" id="' . $args[ 'id' ] . '" name="' . $args[ 'id' ] . '" />';
	}

	
	public function add_permastructs( $rules ) {
		global $wp_rewrite;

		$permastruct = $wp_rewrite->permalink_structure;
		$permastructs = array( $permastruct => array( 'post' ) );

		// get permastructs foreach custom post type and group any that use the same struct
		foreach( get_post_types( array( '_builtin' => false, 'public' => true ), 'objects' ) as $type ) {
			// add/override the custom permalink structure if set in options
			$post_type_permastruct = get_option( $type->name . '_permalink_structure' );
			if ( $post_type_permastruct && ! empty( $post_type_permastruct ) ) {
				if ( ! is_array( $type->rewrite ) )
					$type->rewrite = array();
				$type->rewrite[ 'permastruct' ] = $post_type_permastruct;
			}

			// check we have a custom permalink structure
			if ( ! is_array( $type->rewrite ) || ! isset( $type->rewrite[ 'permastruct' ] ) )
				continue;

			// remove default struct rules
			add_filter( $type->name . '_rewrite_rules', create_function( '$rules', 'return array();' ) );

			if ( ! isset( $permastructs[ $type->rewrite[ 'permastruct' ] ] ) )
				$permastructs[ $type->rewrite[ 'permastruct' ] ] = array();

			$permastructs[ $type->rewrite[ 'permastruct' ] ][] = $type->name;
		}

		// add our permastructs scoped to the post types - overwriting any keys that already exist
		foreach( $permastructs as $struct => $post_types ) {

			$post_type_rules_temp = $wp_rewrite->generate_rewrite_rules( $struct, EP_PERMALINK, false );
			foreach( $post_type_rules_temp as $regex => $query )
				$rules[ $regex ] = $query . ( count( $post_types ) < 2 ? '&post_type=' . $post_types[ 0 ] : '&post_type[]=' . join( '&post_type[]=', array_unique( $post_types ) ) );

		}

		return $rules;
	}


	public function parse_permalinks( $post_link, $post, $leavename, $sample ) {

		$id = $post->ID;

		$rewritecode = array(
			'%year%',
			'%monthnum%',
			'%day%',
			'%hour%',
			'%minute%',
			'%second%',
			$leavename? '' : '%postname%',
			'%post_id%',
			'%author%',
			$leavename? '' : '%pagename%',
		);

		$taxonomies = get_object_taxonomies( $post->post_type );

		foreach( $taxonomies as $taxonomy )
			$rewritecode[] = '%' . $taxonomy . '%';

		if ( is_object($id) && isset($id->filter) && 'sample' == $id->filter ) {
			$post = $id;
			$sample = true;
		} else {
			$post = &get_post($id);
			$sample = false;
		}

		$post_type = get_post_type_object( $post->post_type );
		$permastruct = get_option( $post_type->name . '_permalink_structure' );

		// prefer option over default
		if ( $permastruct && ! empty( $permastruct ) ) {
			$permalink = $permastruct;
		} elseif ( isset( $post_type->rewrite[ 'permastruct' ] ) && ! empty( $post_type->rewrite[ 'permastruct' ] ) ) {
			$permalink = $post_type->rewrite[ 'permastruct' ];
		} else {
			return $post_link;
		}

		$permalink = apply_filters('pre_post_link', $permalink, $post, $leavename);

		if ( '' != $permalink && !in_array($post->post_status, array('draft', 'pending', 'auto-draft')) ) {
			$unixtime = strtotime($post->post_date);

			// add ability to use any taxonomies in post type permastruct
			$replace_terms = array();
			foreach( $taxonomies as $taxonomy ) {
				$term = '';
				$taxonomy_object = get_taxonomy( $taxonomy );
				if ( strpos($permalink, '%'. $taxonomy .'%') !== false ) {
					$terms = get_the_terms( $post->ID, $taxonomy );
					if ( $terms ) {
						usort($terms, '_usort_terms_by_ID'); // order by ID
						$term = $terms[0]->slug;
						if ( $taxonomy_object->hierarchical && $parent = $terms[0]->parent )
							$term = get_term_parents($parent, $taxonomy, false, '/', true) . $term;
					}
					// show default category in permalinks, without
					// having to assign it explicitly
					if ( empty( $term ) && $taxonomy == 'category' ) {
						$default_category = get_category( get_option( 'default_category' ) );
						$term = is_wp_error( $default_category ) ? '' : $default_category->slug;
					}

				}
				$replace_terms[ $taxonomy ] = $term;
			}

			$author = '';
			if ( strpos($permalink, '%author%') !== false ) {
				$authordata = get_userdata($post->post_author);
				$author = $authordata->user_nicename;
			}

			$date = explode(" ",date('Y m d H i s', $unixtime));
			$rewritereplace =
			array(
				$date[0],
				$date[1],
				$date[2],
				$date[3],
				$date[4],
				$date[5],
				$post->post_name,
				$post->ID,
				$author,
				$post->post_name,
			);
			foreach( $taxonomies as $taxonomy )
				$rewritereplace[] = $replace_terms[ $taxonomy ];
			$permalink = home_url( str_replace($rewritecode, $rewritereplace, $permalink) );
			$permalink = user_trailingslashit($permalink, 'single');
		} else { // if they're not using the fancy permalink option
			$permalink = home_url('?p=' . $post->ID);
		}

		return $permalink;
	}

}

}

if ( ! function_exists( 'get_term_parents' ) ) {

	/**
	 * Retrieve term parents with separator.
	 *
	 * @param int $id Term ID.
	 * @param string $taxonomy The taxonomy the term belongs to.
	 * @param bool $link Optional, default is false. Whether to format with link.
	 * @param string $separator Optional, default is '/'. How to separate categories.
	 * @param bool $nicename Optional, default is false. Whether to use nice name for display.
	 * @param array $visited Optional. Already linked to categories to prevent duplicates.
	 * @return string
	 */
	function get_term_parents( $id, $taxonomy, $link = false, $separator = '/', $nicename = false, $visited = array() ) {
		$chain = '';
		$parent = &get_term( $id, $taxonomy );
		if ( is_wp_error( $parent ) )
			return $parent;

		if ( $nicename )
			$name = $parent->slug;
		else
			$name = $parent->cat_name;

		if ( $parent->parent && ( $parent->parent != $parent->term_id ) && !in_array( $parent->parent, $visited ) ) {
			$visited[] = $parent->parent;
			$chain .= get_term_parents( $parent->parent, $taxonomy, $link, $separator, $nicename, $visited );
		}

		if ( $link )
			$chain .= '<a href="' . get_term_link( $parent->term_id, $taxonomy ) . '" title="' . esc_attr( sprintf( __( "View all posts in %s" ), $parent->name ) ) . '">'.$name.'</a>' . $separator;
		else
			$chain .= $name.$separator;
		return $chain;
	}

}


// patch for WP not saving settings registered to the permalinks page
if ( ! function_exists( 'enable_permalinks_settings' ) ) {

	// process the $_POST variable after all settings have been
	// registered so they are whitelisted
	add_action( 'admin_init', 'enable_permalinks_settings', 300 );

	function enable_permalinks_settings() {
		global $new_whitelist_options;

		// save hook for permalinks page
		if ( isset( $_POST['permalink_structure'] ) || isset( $_POST['category_base'] ) ) {
			check_admin_referer('update-permalink');

			$option_page = 'permalink';

			$capability = 'manage_options';
			$capability = apply_filters( "option_page_capability_{$option_page}", $capability );

			if ( !current_user_can( $capability ) )
				wp_die(__('Cheatin&#8217; uh?'));

			// get extra permalink options
			$options = $new_whitelist_options[ $option_page ];

			if ( $options ) {
				foreach ( $options as $option ) {
					$option = trim($option);
					$value = null;
					if ( isset($_POST[$option]) )
						$value = $_POST[$option];
					if ( !is_array($value) )
						$value = trim($value);
					$value = stripslashes_deep($value);
					if ( ! get_option( $option ) )
						add_option( $option, $value );
					else
						update_option( $option, $value );
				}
			}

			/**
			 *  Handle settings errors
			 */
			set_transient('settings_errors', get_settings_errors(), 30);
		}
	}
}

?>
