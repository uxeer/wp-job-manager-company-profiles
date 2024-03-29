<?php
/**
 * Plugin Name: WP Job Manager - Company Profiles
 * Plugin URI:  https://github.com/astoundify/wp-job-manager-company-profiles
 * Description: Output a list of all companies that have posted a job, with a link to a company profile.
 * Author:      Astoundify
 * Author URI:  http://astoundify.com
 * Version:     1.3
 * Text Domain: wp-job-manager-company-profiles
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

// Import Classes
require WP_JOB_MANAGER_COMPANY_PROFILES_PLUGIN_DIR . 'includes/class-wpjmcl-template-loader.php';


class WP_Job_Manager_Companies {

	/**
	 * @var $instance
	 */
	private static $instance;

	/**
	 * @var slug
	 */
	private $slug;

	/**
	 * Make sure only one instance is running.
	 */
	public static function instance() {
		if ( ! isset ( self::$instance ) ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	/**
	 * Start things up.
	 *
	 * @since WP Job Manager - Company Profiles 1.0
	 */
	public function __construct() {
		$this->setup_globals();
		$this->setup_actions();
	}

	/**
	 * Set some smart defaults to class variables. Allow some of them to be
	 * filtered to allow for early overriding.
	 *
	 * @since WP Job Manager - Company Profiles 1.0
	 *
	 * @return void
	 */
	private function setup_globals() {
		$this->file         = __FILE__;

		$this->basename     = plugin_basename( $this->file );
		$this->plugin_dir   = plugin_dir_path( $this->file );
		$this->plugin_url   = plugin_dir_url ( $this->file );

		$this->lang_dir     = trailingslashit( $this->plugin_dir . 'languages' );

		$this->domain       = 'wp-job-manager-company-profiles';

		$this->templates   = new WPJMCL_Template_Loader;

		/**
		 * The slug for creating permalinks
		 */
		$this->slug = apply_filters( 'wp_job_manager_companies_company_slug', __( 'company',
		'wp-job-manager-company-profiles' ) );
	}

	/**
	 * Setup the default hooks and actions
	 *
	 * @since WP Job Manager - Company Profiles 1.0
	 *
	 * @return void
	 */
	private function setup_actions() {
		add_shortcode( 'job_manager_companies', array( $this, 'shortcode' ) );

		add_filter( 'pre_get_document_title', array( $this, 'page_title' ), 20 );

		add_action( 'generate_rewrite_rules', array( $this, 'add_rewrite_rule' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_filter( 'pre_get_posts', array( $this, 'posts_filter' ) );
		add_action( 'template_redirect', array( $this, 'template_loader' ) );
		add_action( 'body_class', array( $this, 'single_company_class' ) );

		add_action( 'plugins_loaded', array( $this, 'load_plugin_textdomain' ) );

		register_activation_hook( __FILE__, array( $this, 'plugin_activation' ) );
	}

	public function plugin_activation() {
		flush_rewrite_rules();
		$this->generate_company_slug();
	}

	/**
	 * Define "company" as a valid query variable.
	 *
	 * @since WP Job Manager - Company Profiles 1.0
	 *
	 * @param array $vars The array of existing query variables.
	 * @return array $vars The modified array of query variables.
	 */
	public function query_vars( $vars ) {
		$vars[] = $this->slug;

		return $vars;
	}

	public function get_slug() {
		return $this->slug;
	}

	/**
	 * Create the custom rewrite tag, then add it as a custom structure.
	 *
	 * @since WP Job Manager - Company Profiles 1.0
	 *
	 * @return obj $wp_rewrite
	 */
	public function add_rewrite_rule() {
		global $wp_rewrite;

		$wp_rewrite->add_rewrite_tag( '%company%', '(.+?)', $this->slug . '=' );

		$rewrite_keywords_structure = $wp_rewrite->root . $this->slug ."/%company%/";

		$new_rule = $wp_rewrite->generate_rewrite_rules( $rewrite_keywords_structure );

		$wp_rewrite->rules = $new_rule + $wp_rewrite->rules;

		return $wp_rewrite->rules;
	}

	/**
	 * If we detect the "company" query variable, load our custom template
	 * file. This will check a child theme so it can be overwritten as well.
	 *
	 * @since WP Job Manager - Company Profiles 1.0
	 *
	 * @return void
	 */
	public function template_loader() {
		global $wp_query;

		if ( ! get_query_var( $this->slug ) )
			return;

		if ( 0 == $wp_query->found_posts )
			locate_template( apply_filters( 'wp_job_manager_companies_404', array( '404.php' ) ), true );
		else
			locate_template( apply_filters( 'wp_job_manager_companies_templates', array( 'single-company.php', 'taxonomy-job_listing_category.php' ) ), true );

		exit();
	}

	/**
	 * Add Classes to Single Company Page
	 */
	public function single_company_class($classes) {
		global $post;

		// remove 'blog' class
		if (get_query_var( $this->slug )) {
			unset( $classes[ array_search( 'blog', $classes ) ] );
		}

		// add 'single-company' class
		$classes[] = get_query_var( $this->slug ) ? 'single-company' : '';
		return $classes;
	}

	/**
	 * Potentialy filter the query. If we detect the "company" query variable
	 * then filter the results to show job listsing for that company.
	 *
	 * @since WP Job Manager - Company Profiles 1.0
	 *
	 * @param object $query
	 * @return void
	 */
	public function posts_filter( $query ) {
		if ( ! ( get_query_var( $this->slug ) && $query->is_main_query() && ! is_admin() ) )
			return;

		$meta_query = array(
			'relation' => 'OR',
			array(
				'key'   => '_company_name',
				'value' => urldecode( get_query_var( $this->slug ) )
			),
			array(
				'key'   => '_company_slug',
				'value' => urldecode( get_query_var( $this->slug ) )
			)
		);

		if ( get_option( 'job_manager_hide_filled_positions' ) == 1 ) {
			$meta_query[] = array(
				'key'     => '_filled',
				'value'   => '1',
				'compare' => '!='
			);
		}

		$query->set( 'post_type', 'job_listing' );
		$query->set( 'post_status', 'publish' );
		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Register the `[job_manager_companies]` shortcode.
	 *
	 * @since WP Job Manager - Company Profiles 1.0
	 *
	 * @param array $atts
	 * @return string The shortcode HTML output
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts( array(), $atts );

		return $this->build_company_archive( $atts );
	}

	/**
	 * Build the shortcode.
	 *
	 * Not very flexible at the moment. Only can deal with english letters.
	 *
	 * @since WP Job Manager - Company Profiles 1.0
	 *
	 * @param array $atts
	 * @return string The shortcode HTML output
	 */
	public function build_company_archive( $atts ) {
		ob_start();
		$this->templates->get_template_part( 'content', 'company_listing' );
		return ob_get_clean();
	}

	/**
	 * Return all industry names in an array
	 */
	public function get_industries() {
		global $wpdb;

		// base query for 'job_listing'
		$args = array(
			'post_type' => 'job_listing',
			'posts_per_page' => -1,
			'post_status' => 'publish',
		);

		// prepare query
		$query = new WP_Query( $args );
		
		$industries = array();

		while ( $query->have_posts() ) : $query->the_post();
			$industries[] = get_field( '_company_industry', get_the_ID() );
		endwhile;

		$industries = array_unique($industries);

		return $industries;

	}

	/**
	 * Return all company names in an array
	 */
	public function get_companies($keyword=null, $location=null, $industry=null) {
		global $wpdb;

		// base query for 'job_listing'
		$args = array(
			'post_type' => 'job_listing',
			'posts_per_page' => -1,
			'post_status' => 'publish',
		);

		// push 'meta_query' if $keyword is defined
		if ($keyword) {
			$keyword_search[] = array(
				'key'     => '_company_name',
				'value'   => $keyword,
				'compare' => 'like',
			);
			$args['meta_query'][] = $keyword_search;
		}

		// push another 'meta_query' if $location is defined
		if ($location) {
			$location_search[] = array(
				'key'     => '_company_location',
				'value'   => $location,
				'compare' => 'like',
			);
			$args['meta_query'][] = $location_search;
		}

		// push just another 'meta_query' if $industry is defined
		if ($industry) {
			$industry_search[] = array(
				'key'     => '_company_industry',
				'value'   => $industry,
				'compare' => 'like',
			);
			$args['meta_query'][] = $industry_search;
		}
		
		// prepare query
		$query = new WP_Query( $args );
		
		$companies = array();

		while ( $query->have_posts() ) : $query->the_post();
			$companies[] = get_field( '_company_name', get_the_ID() );
		endwhile;

		$companies = array_unique($companies);

		return $companies;

	}

	/**
	 * Return all posts for the given company
	 */
	public function all_company_data($company_name) {
		$query_args = array(
			'post_type' => 'job_listing',
			'posts_per_page'   => -1,
			'meta_key'         => '_company_name',
			'meta_value'       => $company_name
		);
		$company_query = new WP_Query( $query_args );

		$company_data = array(
			'count' 		  => count( $company_query->posts ),
			'company_posts'   => $company_query->posts,
		);

		wp_reset_postdata();

		return $company_data;
	}


	/**
	 * Return total positions for a given company
	 */
	public function get_positions_count($company_name) {
		$query_args = array(
			'post_type' 	   => 'job_listing',
			'posts_per_page'   => -1,
			'meta_key'         => '_company_name',
			'meta_value'       => $company_name
		);
		$company_query = new WP_Query( $query_args );

		return count( $company_query->posts );
	}

	/**
	 * Return latest info for the given company
	 */
	public function last_company_data($company_name) {
		$query_args = array(
			'post_type' => 'job_listing',
			'posts_per_page'   => 1,
			'meta_key'         => '_company_name',
			'meta_value'       => $company_name
		);
		$company_query = new WP_Query( $query_args );

		// get last company post
		foreach( $company_query->posts as $company_post ) {
			$last_id = $company_post->ID;
		}
		
		// intermediary variables
		$company_desc = get_field('_company_description', $last_id);
		$company_tagline = get_field('_company_tagline', $last_id);

		$company_data = array(
			'company_slug'    => get_field('_company_slug', $last_id),
			'company_logo' 	  => get_the_post_thumbnail_url($last_id),
			'company_info'    => $company_desc ? $company_desc : $company_tagline,
			'company_location'=> get_field('_company_location', $last_id),
			'company_size'    => get_field('_company_size', $last_id),
		);

		return $company_data;
	}

	/**
	 * Company profile URL. Depending on our permalink structure we might
	 * not output a pretty URL.
	 *
	 * @since WP Job Manager - Company Profiles 1.0
	 *
	 * @param string $company_name
	 * @return string $url The company profile URL.
	 */
	public function company_url( $company_slug, $company_name ) {
		global $wp_rewrite;

		$company_name = rawurlencode( $company_name );
		// $company_name = sanitize_title_with_dashes( $company_name ); // slugify company name
		$company_slug = $company_slug ? $company_slug : $company_name; // final slug

		if ( $wp_rewrite->permalink_structure == '' ) {
			$url = home_url( 'index.php?'. $this->slug . '=' . $company_slug );
		} else {
			$url = home_url( '/' . $this->slug . '/' . trailingslashit( $company_slug ) );
		}

		return esc_url( $url );
	}

	/**
	 * Generate company slugs if called upon.
	 *
	 * @since WP Job Manager - Company Profiles 1.0.0
	 *
	 */
	public function generate_company_slug() {
		$query_args = array(
			'post_type' 	   => 'job_listing',
			'posts_per_page'   => -1,
		);
		$company_query = new WP_Query( $query_args );

		// loop throught each company post
		foreach( $company_query->posts as $company_post ) {
			$company_id = $company_post->ID;
			$company_name = get_field('_company_name', $company_id);
			$company_slug = get_field('_company_slug', $company_id);
			
			// update company slug if not already defined
			if (!$company_slug) {
				update_post_meta($company_id, '_company_slug', sanitize_title_with_dashes( $company_name ) );
			}
		}

		return true;
	}

	/**
	 * Set a page title when viewing an individual company.
	 *
	 * @since WP Job Manager - Company Profiles 1.2
	 *
	 * @param string $title Default title text for current view.
	 * @param string $sep Optional separator.
	 * @return string Filtered title.
	 */
	function page_title($title) {
		global $paged, $page;
		$sep = apply_filters( 'document_title_separator', '-' );
		if ( ! get_query_var( $this->slug ) )
			return $title;

		$company = urldecode( get_query_var( $this->slug ) );

		$title = get_bloginfo( 'name' );

		$site_description = get_bloginfo( 'description', 'display' );

		if ( $site_description && ( is_home() || is_front_page() ) )
			$title = "$title $sep $site_description";

		$title = sprintf( __( 'Jobs at %s', 'wp-job-manager-company-profiles' ), $company ) . " $sep $title";

		return $title;
	}

	/**
	 * Localisation
	 *
	 * @access private
	 * @return void
	 */
	public function load_plugin_textdomain() {
		$locale = apply_filters( 'plugin_locale', get_locale(), 'wp-job-manager-company-profiles' );

		load_textdomain( 'wp-job-manager-company-profiles', WP_LANG_DIR . "/wp-job-manager-company-profiles/wp-job-manager-company-profiles-$locale.mo" );
		load_plugin_textdomain( 'wp-job-manager-company-profiles', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}
}
