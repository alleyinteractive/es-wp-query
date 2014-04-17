## Things that don't quite work out of the box

* Sorting by RAND()
** You can make this work with a custom script
* Sorting by post__in, post_parent__in
** You can make this work with a custom script
* Sort by meta_value
** depending on your mapping, this may or may not be possible
* Query by week, w, dayofyear, dayofweek
** You can probably make this work with a custom script
* Meta queries without a key
** Depends on your map.
* Can't meta compare 'REGEXP', 'NOT REGEXP', 'RLIKE'


## Things that don't work at all out of the box

* Meta value casting
** There's no equivalent in ES

## Noteworthy

* Tests were failing because they were written to assume that two posts with the same date, when ordered by date, would show up in the order in which they were added to the database. However, in ES, they aren't guaranteed to show in that order. tl;dr: unspecified post orders aren't the same between MySQL and ES.

## Instructions for use

class ES_WP_Query extends ES_WP_Query_Wrapper {
	protected function query_es( $es_args ) {
		return wp_remote_post( 'http://localhost:9200/wordpress/post/_search', array( 'body' => json_encode( $es_args ) ) );
	}
}

## Tests

Unit tests are included using phpunit. In order to run the tests, you need to add an adapter for your Elasticsearch implementation.

1. You need to create a file called `es.php` and add it to the `tests/` directory.
2. `es.php` needs to contain a function named `es_wp_query_index_test_data()`. This function gets called whenever data is added, to give you an opportunity to index it. You should force Elasticsearch to refresh after indexing, to ensure that the data is immediately searchable.
3. `es.php` must also contain a class `ES_WP_Query` which extends `ES_WP_Query_Wrapper`. At a minimum, this class should contain a `protected function query_es( $es_args )` which queries your Elasticsearch server.
4. This file can also contain anything else you need to get everything working properly, e.g. adjustments to the field map.

Here is a demo file for using [SearchPress](https://github.com/alleyinteractive/searchpress) to test:

	<?php
	require_once dirname( __FILE__ ) . '/../../searchpress/searchpress.php';

	remove_action( 'save_post',       array( SP_Sync_Manager(), 'sync_post' ) );
	remove_action( 'delete_post',     array( SP_Sync_Manager(), 'delete_post' ) );
	remove_action( 'trashed_post',    array( SP_Sync_Manager(), 'delete_post' ) );

	class ES_WP_Query extends ES_WP_Query_Wrapper {
		protected function query_es( $es_args ) {
			return SP_API()->search( json_encode( $es_args ), array( 'output' => ARRAY_A ) );
		}
	}

	function es_wp_query_index_test_data() {
		SP_Config()->update_settings( array( 'active' => false, 'host' => 'http://localhost:9200' ) );
		SP_API()->index = 'es-wp-query-tests';

		SP_Config()->flush();
		SP_Config()->create_mapping();

		$posts = get_posts( 'posts_per_page=-1&post_type=any&post_status=any&orderby=ID&order=ASC' );

		$sp_posts = array();
		foreach ( $posts as $post ) {
			$sp_posts[] = new SP_Post( $post );
		}

		$response = SP_API()->index_posts( $sp_posts );
		if ( '200' != SP_API()->last_request['response_code'] ) {
			echo( "ES response not 200!\n" . print_r( $response, 1 ) );
		} elseif ( ! is_object( $response ) || ! is_array( $response->items ) ) {
			echo( "Error indexing data! Response:\n" . print_r( $response, 1 ) );
		}

		SP_Config()->update_settings( array( 'active' => true, 'must_init' => false ) );

		SP_API()->refresh_index();
	}

	function sp_es_field_map( $es_map ) {
		return wp_parse_args( array(
			'post_meta'          => 'post_meta.%s.raw',
			'post_meta.analyzed' => 'post_meta.%s',
			'term_name'          => 'terms.%s.name.raw',
			'post_name'          => 'post_name.raw',
			'post_type'          => 'post_type.raw',
		), $es_map );
	}
	add_filter( 'es_field_map', 'sp_es_field_map' );