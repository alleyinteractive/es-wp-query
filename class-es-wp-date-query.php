<?php

/**
 * Elasticsearch wrapper for WP_Meta_Query
 */
class ES_WP_Date_Query extends WP_Date_Query {

	/**
	 * Turns an array of date query parameters into ES Query DSL.
	 *
	 * @access public
	 *
	 * @return array
	 */
	public function get_dsl( $es_query ) {
		// The parts of the final query
		$filter = array();

		foreach ( $this->queries as $query ) {
			$filter_parts = $this->get_es_subquery( $query, $es_query );
			if ( ! empty( $filter_parts ) ) {
				// Combine the parts of this subquery
				if ( 1 == count( $filter_parts ) ) {
					$filter[] = reset( $filter_parts );
				} else {
					$filter[] = array( 'and' => $filter_parts );
				}
			}
		}

		// Combine the subqueries
		if ( 1 == count( $filter ) ) {
			$filter = reset( $filter );
		} elseif ( ! empty( $filter ) ) {
			$filter = array( strtolower( $this->relation ) => $filter );
		} else {
			$filter = array();
		}

		/**
		 * Filter the date query WHERE clause.
		 *
		 * @param string        $where WHERE clause of the date query.
		 * @param WP_Date_Query $this  The WP_Date_Query instance.
		 */
		return apply_filters( 'get_date_dsl', $filter, $this );
	}

	/**
	 * Turns a single date subquery into elasticsearch filters
	 *
	 * @return array
	 */
	protected function get_es_subquery( $query, $es_query ) {
		global $wpdb;

		// The sub-parts of a $where part
		$filter_parts = array();

		$column = ( ! empty( $query['column'] ) ) ? esc_sql( $query['column'] ) : $this->column;

		$column = $this->validate_column( $column );

		$compare = $this->get_compare( $query );

		$inclusive = ! empty( $query['inclusive'] );

		if ( $inclusive ) {
			$lt = 'lte';
			$gt = 'gte';
		} else {
			$lt = 'lt';
			$gt = 'gt';
		}

		// Range queries, we like range queries
		$range = array();

		if ( ! empty( $query['after'] ) ) {
			$range[ $gt ] = $this->build_datetime( $query['after'], ! $inclusive );
		}

		if ( ! empty( $query['before'] ) ) {
			$range[ $lt ] = $this->build_datetime( $query['before'], $inclusive );
		}

		if ( ! empty( $range ) ) {
			$filter_parts[] = $es_query->dsl_range( $es_query->es_map( $column ), $range );
		}
		unset( $range );


		// Specific value queries
		$date = array();
		if ( isset( $query['year'] ) ) {
			$date['year'] = $query['year'];
		} else {
			return $filter_parts;
		}

		// Legacy
		if ( isset( $query['monthnum'] ) ) {
			$date['month'] = $query['monthnum'];
		}

		foreach ( array( 'month', 'day', 'hour', 'minute', 'second' ) as $unit ) {
			if ( isset( $query[ $unit ] ) ) {
				$date[ $unit ] = $query[ $unit ];
			} elseif ( ! isset( $date[ $unit ] ) ) {
				// This deviates from core. We can't query for e.g. all posts published at 5pm in 2014.
				// We can only do ranges, so we take note of the most precise argument we get  linearly
				// and we disregard anything after a gap.
				break;
			}
		}

		$range = $es_query->dsl_range( $column, $this->build_date_range( $date, $compare ) );

		switch ( $compare ) {
			case '!=' :
				$filter_parts[] = array( 'not' => $range );
				break;

			case '>' :
			case '>=' :
			case '<' :
			case '<=' :
				$filter_parts[] = $range;
				break;

			case '=' :
				if ( isset( $date['second'] ) ) {
					$filter_parts[] = $es_query->dsl_terms( $es_query->es_map( $column ), $this->build_datetime( $date ) );
				} else {
					$filter_parts[] = $range;
				}
				break;
		}

		return $filter_parts;
	}

	/**
	 * Builds a MySQL format date/time based on some query parameters.
	 *
	 * This is a clone of build_mysql_datetime, but specifically for static usage.
	 *
	 * You can pass an array of values (year, month, etc.) with missing parameter values being defaulted to
	 * either the maximum or minimum values (controlled by the $default_to parameter). Alternatively you can
	 * pass a string that that will be run through strtotime().
	 *
	 * @static
	 * @access public
	 *
	 * @param string|array $datetime An array of parameters or a strotime() string
	 * @param string $default_to Controls what values default to if they are missing from $datetime. Pass "min" or "max".
	 * @return string|false A MySQL format date/time or false on failure
	 */
	public static function build_datetime( $datetime, $default_to_max = false ) {
		$now = current_time( 'timestamp' );

		if ( ! is_array( $datetime ) ) {
			// @todo Timezone issues here possibly
			return gmdate( 'Y-m-d H:i:s', strtotime( $datetime, $now ) );
		}

		$datetime = array_map( 'absint', $datetime );

		if ( ! isset( $datetime['year'] ) )
			$datetime['year'] = gmdate( 'Y', $now );

		if ( ! isset( $datetime['month'] ) )
			$datetime['month'] = ( $default_to_max ) ? 12 : 1;

		if ( ! isset( $datetime['day'] ) )
			$datetime['day'] = ( $default_to_max ) ? (int) date( 't', mktime( 0, 0, 0, $datetime['month'], 1, $datetime['year'] ) ) : 1;

		if ( ! isset( $datetime['hour'] ) )
			$datetime['hour'] = ( $default_to_max ) ? 23 : 0;

		if ( ! isset( $datetime['minute'] ) )
			$datetime['minute'] = ( $default_to_max ) ? 59 : 0;

		if ( ! isset( $datetime['second'] ) )
			$datetime['second'] = ( $default_to_max ) ? 59 : 0;

		return sprintf( '%04d-%02d-%02d %02d:%02d:%02d', $datetime['year'], $datetime['month'], $datetime['day'], $datetime['hour'], $datetime['minute'], $datetime['second'] );
	}


	private function build_date_range( $date, $compare ) {
		// To improve readability
		$upper_edge = true;
		$lower_edge = false;

		switch ( $compare ) {
			case '!=' :
			case '=' :
				return array(
					'gte' => $this->build_datetime( $date, $lower_edge ),
					'lte' => $this->build_datetime( $date, $upper_edge )
				);

			case '>' :
				return array( 'gt' => $this->build_datetime( $date, $upper_edge ) );
			case '>=' :
				return array( 'gte' => $this->build_datetime( $date, $lower_edge ) );

			case '<' :
				return array( 'lt' => $this->build_datetime( $date, $lower_edge ) );
			case '<=' :
				return array( 'lte' => $this->build_datetime( $date, $upper_edge ) );
		}
	}

}