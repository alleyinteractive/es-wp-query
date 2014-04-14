<?php

/**
 * Elasticsearch wrapper for WP_Meta_Query
 */
class ES_WP_Meta_Query extends WP_Meta_Query {

	/**
	 * Turns an array of meta query parameters into ES Query DSL
	 *
	 * @access public
	 *
	 * @param string $type Type of meta
	 * @return array array()
	 */
	public function get_es_query( $type ) {
		global $wpdb;

		if ( ! $meta_table = _get_meta_table( $type ) )
			return false;

		$meta_id_column = sanitize_key( $type . '_id' );

		$join = array();
		$where = array();

		$key_only_queries = array();
		$queries = array();

		// Split out the queries with empty arrays as value
		foreach ( $this->queries as $k => $q ) {
			if ( isset( $q['value'] ) && is_array( $q['value'] ) && empty( $q['value'] ) ) {
				$key_only_queries[$k] = $q;
				unset( $this->queries[$k] );
			}
		}

		// Split out the meta_key only queries (we can only do this for OR)
		if ( 'OR' == $this->relation ) {
			foreach ( $this->queries as $k => $q ) {
				if ( ! array_key_exists( 'value', $q ) && ! empty( $q['key'] ) )
					$key_only_queries[$k] = $q;
				else
					$queries[$k] = $q;
			}
		} else {
			$queries = $this->queries;
		}

		// Specify all the meta_key only queries in one go
		if ( $key_only_queries ) {
			$join[]  = "INNER JOIN $meta_table ON $primary_table.$primary_id_column = $meta_table.$meta_id_column";

			foreach ( $key_only_queries as $key => $q )
				$where["key-only-$key"] = $wpdb->prepare( "$meta_table.meta_key = %s", trim( $q['key'] ) );
		}

		foreach ( $queries as $k => $q ) {
			$meta_key = isset( $q['key'] ) ? trim( $q['key'] ) : '';
			$meta_type = $this->get_cast_for_type( isset( $q['type'] ) ? $q['type'] : '' );

			if ( array_key_exists( 'value', $q ) && is_null( $q['value'] ) )
				$q['value'] = '';

			$meta_value = isset( $q['value'] ) ? $q['value'] : null;

			if ( isset( $q['compare'] ) )
				$meta_compare = strtoupper( $q['compare'] );
			else
				$meta_compare = is_array( $meta_value ) ? 'IN' : '=';

			if ( ! in_array( $meta_compare, array(
				'=', '!=', '>', '>=', '<', '<=',
				'LIKE', 'NOT LIKE',
				'IN', 'NOT IN',
				'BETWEEN', 'NOT BETWEEN',
				'NOT EXISTS',
				'REGEXP', 'NOT REGEXP', 'RLIKE'
			) ) )
				$meta_compare = '=';

			$i = count( $join );
			$alias = $i ? 'mt' . $i : $meta_table;

			if ( 'NOT EXISTS' == $meta_compare ) {
				$join[$i]  = "LEFT JOIN $meta_table";
				$join[$i] .= $i ? " AS $alias" : '';
				$join[$i] .= " ON ($primary_table.$primary_id_column = $alias.$meta_id_column AND $alias.meta_key = '$meta_key')";

				$where[$k] = ' ' . $alias . '.' . $meta_id_column . ' IS NULL';

				continue;
			}

			$join[$i]  = "INNER JOIN $meta_table";
			$join[$i] .= $i ? " AS $alias" : '';
			$join[$i] .= " ON ($primary_table.$primary_id_column = $alias.$meta_id_column)";

			$where[$k] = '';
			if ( !empty( $meta_key ) )
				$where[$k] = $wpdb->prepare( "$alias.meta_key = %s", $meta_key );

			if ( is_null( $meta_value ) ) {
				if ( empty( $where[$k] ) )
					unset( $join[$i] );
				continue;
			}

			if ( in_array( $meta_compare, array( 'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN' ) ) ) {
				if ( ! is_array( $meta_value ) )
					$meta_value = preg_split( '/[,\s]+/', $meta_value );

				if ( empty( $meta_value ) ) {
					unset( $join[$i] );
					continue;
				}
			} else {
				$meta_value = trim( $meta_value );
			}

			if ( 'IN' == substr( $meta_compare, -2) ) {
				$meta_compare_string = '(' . substr( str_repeat( ',%s', count( $meta_value ) ), 1 ) . ')';
			} elseif ( 'BETWEEN' == substr( $meta_compare, -7) ) {
				$meta_value = array_slice( $meta_value, 0, 2 );
				$meta_compare_string = '%s AND %s';
			} elseif ( 'LIKE' == substr( $meta_compare, -4 ) ) {
				$meta_value = '%' . like_escape( $meta_value ) . '%';
				$meta_compare_string = '%s';
			} else {
				$meta_compare_string = '%s';
			}

			if ( ! empty( $where[$k] ) )
				$where[$k] .= ' AND ';

			$where[$k] = ' (' . $where[$k] . $wpdb->prepare( "CAST($alias.meta_value AS {$meta_type}) {$meta_compare} {$meta_compare_string})", $meta_value );
		}

		$where = array_filter( $where );

		if ( empty( $where ) )
			$where = '';
		else
			$where = ' AND (' . implode( "\n{$this->relation} ", $where ) . ' )';

		$join = implode( "\n", $join );
		if ( ! empty( $join ) )
			$join = ' ' . $join;

		// return apply_filters_ref_array( 'get_meta_dsl', array( compact( 'join', 'where' ), $this->queries, $type, $primary_table, $primary_id_column, $context ) );
	}

}