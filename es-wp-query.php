<?php

/*
	Plugin Name: Elasticsearch Wrapper for WP_Query
	Plugin URI: https://github.com/alleyinteractive/es-wp-query
	Description: A drop-in replacement for WP_Query to leverage Elasticsearch for complex queries.
	Version: 0.1.1
	Author: Matthew Boynes
	Author URI: http://www.alleyinteractive.com/
*/
/*  This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

define( 'ES_WP_QUERY_PATH', dirname( __FILE__ ) );

require_once( ES_WP_QUERY_PATH . '/class-es-wp-query-wrapper.php' );
require_once( ES_WP_QUERY_PATH . '/class-es-wp-tax-query.php' );
require_once( ES_WP_QUERY_PATH . '/class-es-wp-date-query.php' );
require_once( ES_WP_QUERY_PATH . '/class-es-wp-meta-query.php' );
require_once( ES_WP_QUERY_PATH . '/class-es-wp-query-shoehorn.php' );
require_once( ES_WP_QUERY_PATH . '/functions.php' );


function es_wp_query_arg( $vars ) {
	$vars[] = 'es';
	return $vars;
}
add_filter( 'query_vars', 'es_wp_query_arg' );


function es_wp_query_shoehorn( $query ) {
	if ( true == $query->get( 'es' ) ) {
		$args = $query->query_vars;
		$args['fields'] = 'ids';
		$es_query = new ES_WP_Query( $args );

		$new_args = $query->fill_query_vars( array(
			'es'               => true,
			'post_type'        => 'any',
			'post_status'      => 'any',
			'post__in'         => $es_query->posts,
			'posts_per_page'   => $es_query->post_count,
			'suppress_filters' => false,
			'fields'           => $query->get( 'fields' ),
			'orderby'          => 'post__in',
			'order'            => 'ASC',
		) );
		$shoehorn = new ES_WP_Query_Shoehorn( $new_args, $query, $es_query );
		$query->query_vars = $new_args;
	}
}
add_action( 'pre_get_posts', 'es_wp_query_shoehorn' );