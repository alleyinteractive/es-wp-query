<?php

/**
 * Elasticsearch wrapper for WP_Meta_Query
 */
class ES_WP_Tax_Query extends WP_Tax_Query {

	public function __construct( $tax_query ) {
		$this->relation = $tax_query->relation;
		$this->queries = $tax_query->queries;
	}

	/**
	 * Turns an array of tax query parameters into ES Query DSL
	 *
	 * @access public
	 *
	 * @return array
	 */
	public function get_es_query() {
		global $wpdb;

		$join = '';
		$where = array();
		$i = 0;
		$count = count( $this->queries );

		foreach ( $this->queries as $index => $query ) {
			$this->clean_query( $query );

			if ( is_wp_error( $query ) )
				return self::$no_results;

			extract( $query );

			if ( 'IN' == $operator ) {

				if ( empty( $terms ) ) {
					if ( 'OR' == $this->relation ) {
						if ( ( $index + 1 === $count ) && empty( $where ) )
							return self::$no_results;
						continue;
					} else {
						return self::$no_results;
					}
				}

				$terms = implode( ',', $terms );

				$alias = $i ? 'tt' . $i : $wpdb->term_relationships;

				$join .= " INNER JOIN $wpdb->term_relationships";
				$join .= $i ? " AS $alias" : '';
				$join .= " ON ($primary_table.$primary_id_column = $alias.object_id)";

				$where[] = "$alias.term_taxonomy_id $operator ($terms)";
			} elseif ( 'NOT IN' == $operator ) {

				if ( empty( $terms ) )
					continue;

				$terms = implode( ',', $terms );

				$where[] = "$primary_table.$primary_id_column NOT IN (
					SELECT object_id
					FROM $wpdb->term_relationships
					WHERE term_taxonomy_id IN ($terms)
				)";
			} elseif ( 'AND' == $operator ) {

				if ( empty( $terms ) )
					continue;

				$num_terms = count( $terms );

				$terms = implode( ',', $terms );

				$where[] = "(
					SELECT COUNT(1)
					FROM $wpdb->term_relationships
					WHERE term_taxonomy_id IN ($terms)
					AND object_id = $primary_table.$primary_id_column
				) = $num_terms";
			}

			$i++;
		}

		if ( ! empty( $where ) )
			$where = ' AND ( ' . implode( " $this->relation ", $where ) . ' )';
		else
			$where = '';

		// return compact( 'join', 'where' );
	}

	/**
	 * Validates a single query.
	 *
	 * @since 3.2.0
	 * @access private
	 *
	 * @param array &$query The single query
	 */
	private function clean_query( &$query ) {
		if ( ! taxonomy_exists( $query['taxonomy'] ) ) {
			$query = new WP_Error( 'Invalid taxonomy' );
			return;
		}

		$query['terms'] = array_unique( (array) $query['terms'] );

		if ( is_taxonomy_hierarchical( $query['taxonomy'] ) && $query['include_children'] ) {
			$this->transform_query( $query, 'term_id' );

			if ( is_wp_error( $query ) )
				return;

			$children = array();
			foreach ( $query['terms'] as $term ) {
				$children = array_merge( $children, get_term_children( $term, $query['taxonomy'] ) );
				$children[] = $term;
			}
			$query['terms'] = $children;
		}

		$this->transform_query( $query, 'term_taxonomy_id' );
	}

	/**
	 * Transforms a single query, from one field to another.
	 *
	 * @since 3.2.0
	 *
	 * @param array &$query The single query
	 * @param string $resulting_field The resulting field
	 */
	public function transform_query( &$query, $resulting_field ) {
		global $wpdb;

		if ( empty( $query['terms'] ) )
			return;

		if ( $query['field'] == $resulting_field )
			return;

		$resulting_field = sanitize_key( $resulting_field );

		switch ( $query['field'] ) {
			case 'slug':
			case 'name':
				$terms = "'" . implode( "','", array_map( 'sanitize_title_for_query', $query['terms'] ) ) . "'";
				$terms = $wpdb->get_col( "
					SELECT $wpdb->term_taxonomy.$resulting_field
					FROM $wpdb->term_taxonomy
					INNER JOIN $wpdb->terms USING (term_id)
					WHERE taxonomy = '{$query['taxonomy']}'
					AND $wpdb->terms.{$query['field']} IN ($terms)
				" );
				break;
			case 'term_taxonomy_id':
				$terms = implode( ',', array_map( 'intval', $query['terms'] ) );
				$terms = $wpdb->get_col( "
					SELECT $resulting_field
					FROM $wpdb->term_taxonomy
					WHERE term_taxonomy_id IN ($terms)
				" );
				break;
			default:
				$terms = implode( ',', array_map( 'intval', $query['terms'] ) );
				$terms = $wpdb->get_col( "
					SELECT $resulting_field
					FROM $wpdb->term_taxonomy
					WHERE taxonomy = '{$query['taxonomy']}'
					AND term_id IN ($terms)
				" );
		}

		if ( 'AND' == $query['operator'] && count( $terms ) < count( $query['terms'] ) ) {
			$query = new WP_Error( 'Inexistent terms' );
			return;
		}

		$query['terms'] = $terms;
		$query['field'] = $resulting_field;
	}
}