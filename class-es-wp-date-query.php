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

		$field = ( ! empty( $query['column'] ) ) ? esc_sql( $query['column'] ) : $this->column;
		$field = $this->validate_column( $field );

		$compare = $this->get_compare( $query );

		// Range queries, we like range queries
		if ( ! empty( $query['after'] ) || ! empty( $query['before'] ) ) {
			$inclusive = ! empty( $query['inclusive'] );

			if ( $inclusive ) {
				$lt = 'lte';
				$gt = 'gte';
			} else {
				$lt = 'lt';
				$gt = 'gt';
			}

			$range = array();

			if ( ! empty( $query['after'] ) ) {
				$range[ $gt ] = $this->build_datetime( $query['after'], ! $inclusive );
			}

			if ( ! empty( $query['before'] ) ) {
				$range[ $lt ] = $this->build_datetime( $query['before'], $inclusive );
			}

			if ( ! empty( $range ) ) {
				$filter_parts[] = $es_query->dsl_range( $es_query->es_map( $field ), $range );
			}
			unset( $range );
		}

		// Legacy support and field renaming
		if ( isset( $query['monthnum'] ) ) {
			$query['month'] = $query['monthnum'];
		}
		if ( isset( $query['w'] ) ) {
			$query['week'] = $query['w'];
		}
		if ( isset( $query['w'] ) ) {
			$query['week'] = $query['w'];
		}
		if ( isset( $query['dayofyear'] ) ) {
			$query['day_of_year'] = $query['dayofyear'];
		}
		if ( isset( $query['dayofweek'] ) ) {
			$query['day_of_week'] = $query['dayofweek'];
		}

		foreach ( array( 'year', 'month', 'week', 'day', 'day_of_year', 'day_of_week', 'hour', 'minute', 'second' ) as $date_token ) {
			if ( isset( $query[ $date_token ] ) && $part = $this->build_dsl_part( $es_query->es_map( "{$field}.{$date_token}" ), $query[ $date_token ], $compare ) ) {
				$filter_parts[] = $part;
			}
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


	public static function build_date_range( $date, $compare, $date2 = null, $compare2 = null ) {
		// If we pass two dates, create a range for both
		if ( isset( $date2 ) && isset( $compare2 ) ) {
			return array_merge( self::build_date_range( $date, $compare ), self::build_date_range( $date2, $compare2 ) );
		}

		// To improve readability
		$upper_edge = true;
		$lower_edge = false;

		switch ( $compare ) {
			case '!=' :
			case '=' :
				return array(
					'gte' => self::build_datetime( $date, $lower_edge ),
					'lte' => self::build_datetime( $date, $upper_edge )
				);

			case '>' :
				return array( 'gt' => self::build_datetime( $date, $upper_edge ) );
			case '>=' :
				return array( 'gte' => self::build_datetime( $date, $lower_edge ) );

			case '<' :
				return array( 'lt' => self::build_datetime( $date, $lower_edge ) );
			case '<=' :
				return array( 'lte' => self::build_datetime( $date, $upper_edge ) );
		}
	}

	/**
	 * Builds and validates a value string based on the comparison operator.
	 *
	 * @access public
	 *
	 * @param string $compare The compare operator to use
	 * @param string|array $value The value
	 * @return string|int|false The value to be used in DSL or false on error.
	 */
	public function build_dsl_part( $field, $value, $compare ) {
		if ( ! isset( $value ) )
			return false;

		$part = false;
		switch ( $compare ) {
			// '=', '!=', '>', '>=', '<', '<=', 'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN'
			case 'IN':
			case 'NOT IN':
				$part = ES_WP_Query_Wrapper::dsl_terms( $field, array_map( 'intval', (array) $value ) );
				break;

			case 'BETWEEN':
			case 'NOT BETWEEN':
				if ( ! is_array( $value ) ) {
					$value = array( $value, $value );
				} elseif ( count( $value ) >= 2 && ( ! isset( $value[0] ) || ! isset( $value[1] ) ) ) {
					$value = array( array_shift( $value ), array_shift( $value ) );
				} elseif ( count( $value ) ) {
					$value = reset( $value );
					$value = array( $value, $value );
				}

				if ( ! isset( $value[0] ) || ! isset( $value[1] ) ) {
					return false;
				}

				$value = array_map( 'intval', $value );
				sort( $value );

				$part = ES_WP_Query_Wrapper::dsl_range( $field, array( 'gte' => $value[0], 'lte' => $value[1] ) );
				break;

			case '>':
			case '>=':
			case '<':
			case '<=':
				switch ( $compare ) {
					case '>' :   $operator = 'gt';   break;
					case '>=' :  $operator = 'gte';  break;
					case '<' :   $operator = 'lt';   break;
					case '<=' :  $operator = 'lte';  break;
				}
				$part = ES_WP_Query_Wrapper::dsl_range( $field, array( $operator => intval( $value ) ) );
				break;

			default:
				$part = ES_WP_Query_Wrapper::dsl_terms( $field, intval( $value ) );
				break;
		}

		if ( ! empty( $part ) && in_array( $compare, array( '!=', 'NOT IN', 'NOT BETWEEN' ) ) ) {
			return array( 'not' => $part );
		} else {
			return $part;
		}
	}
}