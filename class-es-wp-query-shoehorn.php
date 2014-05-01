<?php

/**
 * Add an 'es' query var to WP_Query which offers seamless integration
 */

class ES_WP_Query_Shoehorn {

	private $hash;

	private $do_found_posts = true;

	private $post_count;

	private $found_posts;

	public function __construct( $args, &$query, &$es_query ) {
		$this->hash = md5( serialize( $args ) );

		if ( $query->get( 'no_found_rows' ) || -1 == $query->get( 'posts_per_page' ) || true == $query->get( 'nopaging' ) ) {
			$this->do_found_posts = false;
		} else {
			$this->do_found_posts = true;
			$this->found_posts = $es_query->found_posts;
		}
		$this->post_count = $es_query->post_count;
		$this->add_query_hooks();
	}

	public function add_query_hooks() {
		# Nukes the FOUND_ROWS() database query
		add_filter( 'found_posts_query', array( $this, 'filter__found_posts_query' ), 5, 2 );

		# Since the FOUND_ROWS() query was nuked, we need to supply the total number of found posts
		add_filter( 'found_posts', array( $this, 'filter__found_posts' ), 5, 2 );

		# Kill the filters so they don't hang around for all future queries
		add_filter( 'the_posts', array( $this, 'filter__the_posts' ), 10, 2 );

		if ( ! $this->post_count ) {
			add_filter( 'posts_request', array( $this, 'filter__posts_request' ), 5, 2 );
		}
	}


	public function filter__found_posts_query( $sql, $query ) {
		if ( $this->hash == $query->query_vars_hash && $this->do_found_posts ) {
			return '';
		}
		return $sql;
	}

	public function filter__found_posts( $found_posts, $query ) {
		if ( $this->hash == $query->query_vars_hash && $this->do_found_posts ) {
			return $this->found_posts;
		}
		return $found_posts;
	}

	public function filter__posts_request( $sql, &$query ) {
		if ( $this->hash == $query->query_vars_hash && ! $this->post_count ) {
			global $wpdb;
			return "SELECT * FROM {$wpdb->posts} WHERE 1=0 /* ES_WP_Query Shoehorn */";
		}
		return $sql;
	}

	public function filter__the_posts( $posts, &$query ) {
		if ( $this->hash == $query->query_vars_hash ) {
			remove_filter( 'found_posts_query', array( $this, 'filter__found_posts_query' ), 5, 2 );
			remove_filter( 'found_posts', array( $this, 'filter__found_posts' ), 5, 2 );
			remove_filter( 'posts_request', array( $this, 'filter__posts_request' ), 5, 2 );
		}
		return $posts;
	}
}
