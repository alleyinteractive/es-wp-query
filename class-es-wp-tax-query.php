<?php

/**
 * Elasticsearch wrapper for WP_Meta_Query
 */
class ES_WP_Tax_Query extends WP_Tax_Query {

	public static function get_from_tax_query( $tax_query ) {
		$q = new ES_WP_Tax_Query( $tax_query->queries );
		$q->relation = $tax_query->relation;
		return $q;
	}

	/**
	 * Some object which extends ES_WP_Query_Wrapper.
	 *
	 * @var ES_WP_Query_Wrapper
	 */
	protected $es_query;

	/**
	 * Turns an array of tax query parameters into ES Query DSL
	 *
	 * @access public
	 *
	 * @param object $es_query Any object which extends ES_WP_Query_Wrapper.
	 * @param string $type Type of meta. Currently, only 'post' is supported.
	 * @return array ES filters
	 */
	public function get_dsl( $es_query ) {
		$this->es_query = $es_query;

		$filters = $this->get_dsl_clauses();

		return apply_filters_ref_array( 'es_wp_tax_query_dsl', array( $filters, $this->queries, $this->es_query ) );
	}

	/**
	 * Generate ES Filter clauses to be appended to a main query.
	 *
	 * Called by the public {@see ES_WP_Meta_Query::get_dsl()}, this method
	 * is abstracted out to maintain parity with the other Query classes.
	 *
	 * @access protected
	 *
	 * @return array
	 */
	protected function get_dsl_clauses() {
		/*
		 * $queries are passed by reference to
		 * `ES_WP_Meta_Query::get_dsl_for_query()` for recursion. To keep
		 * $this->queries unaltered, pass a copy.
		 */
		$queries = $this->queries;
		return $this->get_dsl_for_query( $queries );
	}

	/**
	 * Generate ES filters for a single query array.
	 *
	 * If nested subqueries are found, this method recurses the tree to produce
	 * the properly nested DSL.
	 *
	 * @access protected
	 *
	 * @param array $query Query to parse, passed by reference.
	 * @return array Array containing nested ES filter clauses.
	 */
	protected function get_dsl_for_query( &$query ) {
		$filters = array();

		foreach ( $query as $key => &$clause ) {
			if ( 'relation' === $key ) {
				$relation = $query['relation'];
			} elseif ( is_array( $clause ) ) {
				if ( $this->is_first_order_clause( $clause ) ) {
					// This is a first-order clause.
					$filters[] = $this->get_dsl_for_clause( $clause, $query );
				} else {
					// This is a subquery, so we recurse.
					$filters[] = $this->get_dsl_for_query( $clause );
				}
			}
		}

		// Filter to remove empties.
		$filters = array_filter( $filters );

		if ( empty( $relation ) ) {
			$relation = 'and';
		}

		if ( count( $filters ) > 1 ) {
			$filters = array( strtolower( $relation ) => $filters );
		} elseif ( ! empty( $filters ) ) {
			$filters = reset( $filters );
		}

		return $filters;
	}

	/**
	 * Generate ES filter clauses for a first-order query clause.
	 *
	 * "First-order" means that it's an array with a 'key' or 'value'.
	 *
	 * @access public
	 *
	 * @param array  $clause       Query clause, passed by reference.
	 * @param array  $query        Parent query array.
	 * @return array ES filter clause component.
	 */
	public function get_dsl_for_clause( &$clause, $query ) {
		$filter_options = array();
		$current_filter = null;

		$this->clean_query( $clause );

		if ( is_wp_error( $clause ) ) {
			return false;
		}

		if ( 'AND' == $clause['operator'] ) {
			$filter_options = array( 'execution' => 'and' );
		}

		if ( empty( $clause['terms'] ) && in_array( $clause['operator'], array( 'IN', 'NOT IN', 'AND' ) ) ) {
			return array();
		}

		switch ( $clause['field'] ) {
			case 'slug' :
			case 'name' :
				$terms = array_map( 'sanitize_title_for_query', array_values( $clause['terms'] ) );
				$current_filter = $this->es_query->dsl_terms( $this->es_query->tax_map( $clause['taxonomy'], 'term_' . $clause['field'] ), $terms, $filter_options );
				break;

			case 'term_taxonomy_id' :
				// This will likely not be hit, as these were probably turned into term_ids. However, by
				// returning false to the 'es_use_mysql_for_term_taxonomy_id' filter, you disable that.
				$current_filter = $this->es_query->dsl_terms( $this->es_query->tax_map( $clause['taxonomy'], 'term_tt_id' ), $clause['terms'], $filter_options );
				break;

			default :
				$terms = array_map( 'absint', array_values( $clause['terms'] ) );
				$current_filter = $this->es_query->dsl_terms( $this->es_query->tax_map( $clause['taxonomy'], 'term_id' ), $terms, $filter_options );
				break;
		}

		if ( 'NOT IN' == $clause['operator'] ) {
			return array( 'not' => $current_filter );
		} else {
			return $current_filter;
		}
	}

	/**
	 * Validates a single query.
	 *
	 * This is copied from core verbatim, because the core method is private.
	 *
	 * @access private
	 *
	 * @param array &$query The single query
	 */
	private function clean_query( &$query ) {
		if ( empty( $query['taxonomy'] ) || ! taxonomy_exists( $query['taxonomy'] ) ) {
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

		// If we have a term_taxonomy_id, use mysql, as that's almost certainly not stored in ES.
		// However, you can override this.
		if ( 'term_taxonomy_id' == $query['field'] ) {
			if ( apply_filters( 'es_use_mysql_for_term_taxonomy_id', true ) ) {
				$this->transform_query( $query, 'term_id' );
			}
		}
	}

	/**
	 * Transforms a single query, from one field to another.
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
