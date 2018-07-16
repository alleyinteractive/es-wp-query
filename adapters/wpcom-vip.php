<?php

/**
 * An adapter for WordPress.com VIP
 */

class ES_WP_Query extends ES_WP_Query_Wrapper {
	protected function query_es( $es_args ) {
		if ( function_exists( 'es_api_search_index' ) ) {
			$es_args['name'] = es_api_get_index_name_by_blog_id( $es_args['blog_id'] );
			if ( is_wp_error( $es_args['name'] ) ) {
				return $es_args['name'];
			}
			return es_api_search_index( $es_args, 'es-wp-query' );
		}
	}

	protected function set_posts( $q, $es_response ) {
		$this->posts = array();
		if ( ! is_wp_error( $es_response ) && isset( $es_response['results']['hits'] ) ) {
			switch ( $q['fields'] ) {
				case 'ids' :
					foreach ( $es_response['results']['hits'] as $hit ) {
						$post_id = (array) $hit['fields'][ $this->es_map( 'post_id' ) ];
						$this->posts[] = reset( $post_id );
					}
					return;

				case 'id=>parent' :
					foreach ( $es_response['results']['hits'] as $hit ) {
						$post_id = (array) $hit['fields'][ $this->es_map( 'post_id' ) ];
						$post_parent = (array) $hit['fields'][ $this->es_map( 'post_parent' ) ];
						$this->posts[ reset( $post_id ) ] = reset( $post_parent );
					}
					return;

				default :
					if ( apply_filters( 'es_query_use_source', false ) ) {
						$this->posts = wp_list_pluck( $es_response['results']['hits'], '_source' );
						return;
					} else {
						$post_ids = array();
						foreach ( $es_response['results']['hits'] as $hit ) {
							$post_id = (array) $hit['fields'][ $this->es_map( 'post_id' ) ];
							$post_ids[] = absint( reset( $post_id ) );
						}
						$post_ids = array_filter( $post_ids );
						if ( ! empty( $post_ids ) ) {
							global $wpdb;
							$post__in = implode( ',', $post_ids );
							$this->posts = $wpdb->get_results( "SELECT $wpdb->posts.* FROM $wpdb->posts WHERE ID IN ($post__in) ORDER BY FIELD( {$wpdb->posts}.ID, $post__in )" );
						}
						return;
					}
			}
		} else {
			$this->posts = array();
		}
	}

	/**
	 * Set up the amount of found posts and the number of pages (if limit clause was used)
	 * for the current query.
	 *
	 * @access public
	 */
	public function set_found_posts( $q, $es_response ) {
		if ( ! is_wp_error( $es_response ) && isset( $es_response['results']['total'] ) ) {
			$this->found_posts = absint( $es_response['results']['total'] );
		} else {
			$this->found_posts = 0;
		}
		$this->found_posts = apply_filters_ref_array( 'es_found_posts', array( $this->found_posts, &$this ) );
		$this->max_num_pages = ceil( $this->found_posts / $q['posts_per_page'] );
	}
}

function vip_es_field_map( $es_map ) {
	return wp_parse_args( array(
		'post_author'                   => 'author_id',
		'post_author.user_nicename'     => 'author_login',
		'post_date'                     => 'date',
		'post_date.year'                => 'date_token.year',
		'post_date.month'               => 'date_token.month',
		'post_date.week'                => 'date_token.week',
		'post_date.day'                 => 'date_token.day',
		'post_date.day_of_year'         => 'date_token.day_of_year',
		'post_date.day_of_week'         => 'date_token.day_of_week',
		'post_date.hour'                => 'date_token.hour',
		'post_date.minute'              => 'date_token.minute',
		'post_date.second'              => 'date_token.second',
		'post_date_gmt'                 => 'date_gmt',
		'post_date_gmt.year'            => 'date_gmt_token.year',
		'post_date_gmt.month'           => 'date_gmt_token.month',
		'post_date_gmt.week'            => 'date_gmt_token.week',
		'post_date_gmt.day'             => 'date_gmt_token.day',
		'post_date_gmt.day_of_year'     => 'date_gmt_token.day_of_year',
		'post_date_gmt.day_of_week'     => 'date_gmt_token.day_of_week',
		'post_date_gmt.hour'            => 'date_gmt_token.hour',
		'post_date_gmt.minute'          => 'date_gmt_token.minute',
		'post_date_gmt.second'          => 'date_gmt_token.second',
		'post_content'                  => 'content',
		'post_content.analyzed'         => 'content',
		'post_title'                    => 'title',
		'post_title.analyzed'           => 'title',
		'post_excerpt'                  => 'excerpt',
		'post_password'                 => 'post_password',  // this isn't indexed on vip
		'post_name'                     => 'post_name',      // this isn't indexed on vip
		'post_modified'                 => 'modified',
		'post_modified.year'            => 'modified_token.year',
		'post_modified.month'           => 'modified_token.month',
		'post_modified.week'            => 'modified_token.week',
		'post_modified.day'             => 'modified_token.day',
		'post_modified.day_of_year'     => 'modified_token.day_of_year',
		'post_modified.day_of_week'     => 'modified_token.day_of_week',
		'post_modified.hour'            => 'modified_token.hour',
		'post_modified.minute'          => 'modified_token.minute',
		'post_modified.second'          => 'modified_token.second',
		'post_modified_gmt'             => 'modified_gmt',
		'post_modified_gmt.year'        => 'modified_gmt_token.year',
		'post_modified_gmt.month'       => 'modified_gmt_token.month',
		'post_modified_gmt.week'        => 'modified_gmt_token.week',
		'post_modified_gmt.day'         => 'modified_gmt_token.day',
		'post_modified_gmt.day_of_year' => 'modified_gmt_token.day_of_year',
		'post_modified_gmt.day_of_week' => 'modified_gmt_token.day_of_week',
		'post_modified_gmt.hour'        => 'modified_gmt_token.hour',
		'post_modified_gmt.minute'      => 'modified_gmt_token.minute',
		'post_modified_gmt.second'      => 'modified_gmt_token.second',
		'post_parent'                   => 'parent_post_id',
		'menu_order'                    => 'menu_order',     // this isn't indexed on vip
		'post_mime_type'                => 'post_mime_type', // this isn't indexed on vip
		'comment_count'                 => 'comment_count',  // this isn't indexed on vip
		'post_meta'                     => 'meta.%s.value.raw_lc',
		'post_meta.analyzed'            => 'meta.%s.value',
		'post_meta.long'                => 'meta.%s.long',
		'post_meta.double'              => 'meta.%s.double',
		'post_meta.binary'              => 'meta.%s.boolean',
		'term_id'                       => 'taxonomy.%s.term_id',
		'term_slug'                     => 'taxonomy.%s.slug',
		'term_name'                     => 'taxonomy.%s.name.raw_lc',
		'category_id'                   => 'category.term_id',
		'category_slug'                 => 'category.slug',
		'category_name'                 => 'category.name.raw',
		'tag_id'                        => 'tag.term_id',
		'tag_slug'                      => 'tag.slug',
		'tag_name'                      => 'tag.name.raw',
	), $es_map );
}
add_filter( 'es_field_map', 'vip_es_field_map' );

function vip_es_meta_value_tolower( $meta_value, $meta_key, $meta_compare, $meta_type ) {
	if ( ! empty( $meta_value ) && is_string( $meta_value ) ) {
		$meta_value = strtolower( $meta_value );
	} else if ( ! empty( $meta_value ) && is_array( $meta_value ) ) {
		$meta_value = array_map( 'strtolower', $meta_value );
	}
	return $meta_value;
}
add_filter( 'es_meta_query_meta_value', 'vip_es_meta_value_tolower', 10, 4 );

/*
 * Advanced Post Cache and es-wp-query do not work well together. In
 * particular, the WP_Query->found_posts attribute gets corrupted when using
 * both of these plugins, so here we disable Advanced Post Cache completely
 * when queries are being made using Elasticsearch.
 *
 * On the other hand, if a non-Elasticsearch query is run, and we disabled
 * Advanced Post Cache earlier, we enable it again, to make use of its caching
 * features.
 *
 * Note that this applies only to calles done via WP_Query(), and not
 * ES_WP_Query()
 */
function vip_es_disable_advanced_post_cache( &$query ) {
	global $advanced_post_cache_object;

	static $disabled_apc = false;


	/*
	 * These two might be passsed to us; we only
	 * handle WP_Query, so ignore these.
	 */

	if (
		( $query instanceof ES_WP_Query_Wrapper ) ||
		( $query instanceof ES_WP_Query )
	) {
		return;
	}

	if ( $query->get( 'es' ) ) {
		if ( true === $disabled_apc ) {
			// Already disabled, don't try again.
			return;
		}

		/*
		 * An Elasticsearch-enabled query is being run. Disable Advanced Post Cache
		 * entirely.
		 *
		 * Note that there is one action-hook that is not deactivated: The switch_blog
		 * action is not deactivated, because it might be called in-between
		 * Elasticsearch-enabled query, and a non-Elasticsearch query, and because it
		 * does not have an effect on WP_Query()-results directly.
		 */

		remove_filter( 'posts_request', array( $advanced_post_cache_object, 'posts_request' ) );
		remove_filter( 'posts_results', array( $advanced_post_cache_object, 'posts_results' ) );

		remove_filter( 'post_limits_request', array( $advanced_post_cache_object, 'post_limits_request' ), 999 );

		remove_filter( 'found_posts_query', array( $advanced_post_cache_object, 'found_posts_query' ) );
		remove_filter( 'found_posts', array( $advanced_post_cache_object, 'found_posts' ) );

		$disabled_apc = true;
	} else {
		// A non-ES query.
		if ( true === $disabled_apc ) {
			/*
			 * Earlier, we disabled Advanced Post Cache
			 * entirely, but now a non-Elasticsearch query is
			 * being run, and in such cases it might be useful
			 * to have the Cache enabled. Here we enable
			 * it again.
			 */
			$advanced_post_cache_object->__construct();

			$disabled_apc = false;
		}
	}
}
add_action( 'pre_get_posts', 'vip_es_disable_advanced_post_cache', -100 );
