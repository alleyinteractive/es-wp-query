# Elasticsearch Wrapper for WP_Query

A drop-in replacement for WP_Query to leverage Elasticsearch for complex queries.

## Warning!

This plugin is currently in pre-alpha development, and as such, no part of it is guaranteed. It works (the unit tests prove that), but we won't be concerned about backwards compatibility until the first release. If you choose to use this, please pay close attention to the commit log to make sure we don't break anything you've implemented.


## Instructions for use

This is actually more of a library than it is a plugin. With that, it is plugin-agnostic with regards to how you're connecting to Elasticsearch. It therefore generates Elasticsearch DSL, but does not actually connect to an Elasticsearch server to execute these queries. It also does no indexing of data, it doesn't add a mapping, etc. If you need an Elasticsearch WordPress plugin, we also offer a free and open-source option called [SearchPress](https://github.com/alleyinteractive/searchpress).

Once you have your Elasticsearch plugin setup and you have your data indexed, you need to tell this library how to use it. If the implementation you're using has an included adapter, you can load it like so:

	es_wp_query_load_adapter( 'adapter-name' );


If your Elasticsearch implementation doesn't have an included adapter, you need to create a class called `ES_WP_Query` which extends `ES_WP_Query_Wrapper`. That class should, at the least, have a method `query_es()` which executes the query on the Elasticsearch server. Here's an example:

	class ES_WP_Query extends ES_WP_Query_Wrapper {
		protected function query_es( $es_args ) {
			return wp_remote_post( 'http://localhost:9200/wordpress/post/_search', array( 'body' => json_encode( $es_args ) ) );
		}
	}

See the [included adapters](https://github.com/alleyinteractive/es-wp-query/tree/master/adapters) for examples and inspiration.


Once you have an adapter setup, you can use this library the same way you'd use `WP_Query`, except that you'll instantiate `ES_WP_Query` instead of `WP_Query`. For instance:

	$q = new ES_WP_Query( array( 'post_type' => 'event', 'posts_per_page' => 20 ) );
	while ( $q->have_posts() ) {
		$q->the_post();
		printf( '<li><a href="%s">%s</a></li>', get_permalink(), get_the_title() );
	}


## Contributing

Any help on this plugin is welcome and appreciated!

### Bugs

If you find a bug, [check the current issues](https://github.com/alleyinteractive/es-wp-query/issues) and if your bug isn't listed, [file a new one](https://github.com/alleyinteractive/es-wp-query/issues/new). If you'd like to also fix the bug you found, please indicate that in the issue before working on it (just in case we have other plans which might affect that bug, we don't want you to waste any time).

### Feature Requests

The scope of this plugin is very tight; it should cover as much of WP_Query as possible, and nothing more. If you think this is missing something within that scope, or you think some part of it can be improved, [we'd love to hear about it](https://github.com/alleyinteractive/es-wp-query/issues/new)!


## Unit Tests

Unit tests are included using phpunit. In order to run the tests, you need to add an adapter for your Elasticsearch implementation.

1. You need to create a file called `es.php` and add it to the `tests/` directory.
2. `es.php` can simply load one of the included adapters which is setup for testing. Otherwise, you'll need to do some additional setup.
3. If you're not using one of the provided adapters:
	* `es.php` needs to contain or include a function named `es_wp_query_index_test_data()`. This function gets called whenever data is added, to give you an opportunity to index it. You should force Elasticsearch to refresh after indexing, to ensure that the data is immediately searchable.
		* **NOTE: Even with refreshing, I've noticed that probably <0.1% of the time, a test may fail for no reason, and I think this is related. If a test sporadically and unexpectedly fails for you, you should re-run it to double-check.**
	* `es.php` must also contain or include a class `ES_WP_Query` which extends `ES_WP_Query_Wrapper`. At a minimum, this class should contain a `protected function query_es( $es_args )` which queries your Elasticsearch server.
	* This file can also contain anything else you need to get everything working properly, e.g. adjustments to the field map.
	* See the included adapters, especially `travis.php`, for examples.

