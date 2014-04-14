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
	public function get_es_query() {
		// The parts of the final query
		$where = array();

		foreach ( $this->queries as $key => $query ) {
			$where_parts = $this->get_es_subquery( $query );
			if ( $where_parts ) {
				// Combine the parts of this subquery into a single string
				$where[ $key ] = '( ' . implode( ' AND ', $where_parts ) . ' )';
			}
		}

		// Combine the subquery strings into a single string
		if ( $where )
			$where = ' AND ( ' . implode( " {$this->relation} ", $where ) . ' )';
		else
			$where = '';

		/**
		 * Filter the date query WHERE clause.
		 *
		 * @since 3.7.0
		 *
		 * @param string        $where WHERE clause of the date query.
		 * @param WP_Date_Query $this  The WP_Date_Query instance.
		 */
		// return apply_filters( 'get_date_dsl', $where, $this );
	}

	/**
	 * Turns a single date subquery into pieces for a WHERE clause.
	 *
	 * @since 3.7.0
	 * return array
	 */
	protected function get_es_subquery( $query ) {
		global $wpdb;

		// The sub-parts of a $where part
		$where_parts = array();

		$column = ( ! empty( $query['column'] ) ) ? esc_sql( $query['column'] ) : $this->column;

		$column = $this->validate_column( $column );

		$compare = $this->get_compare( $query );

		$lt = '<';
		$gt = '>';
		if ( ! empty( $query['inclusive'] ) ) {
			$lt .= '=';
			$gt .= '=';
		}

		// Range queries
		if ( ! empty( $query['after'] ) )
			$where_parts[] = $wpdb->prepare( "$column $gt %s", $this->build_mysql_datetime( $query['after'], true ) );

		if ( ! empty( $query['before'] ) )
			$where_parts[] = $wpdb->prepare( "$column $lt %s", $this->build_mysql_datetime( $query['before'], false ) );

		// Specific value queries

		if ( isset( $query['year'] ) && $value = $this->build_value( $compare, $query['year'] ) )
			$where_parts[] = "YEAR( $column ) $compare $value";

		if ( isset( $query['month'] ) && $value = $this->build_value( $compare, $query['month'] ) )
			$where_parts[] = "MONTH( $column ) $compare $value";

		// Legacy
		if ( isset( $query['monthnum'] ) && $value = $this->build_value( $compare, $query['monthnum'] ) )
			$where_parts[] = "MONTH( $column ) $compare $value";

		if ( isset( $query['week'] ) && false !== ( $value = $this->build_value( $compare, $query['week'] ) ) )
			$where_parts[] = _wp_mysql_week( $column ) . " $compare $value";

		// Legacy
		if ( isset( $query['w'] ) && false !== ( $value = $this->build_value( $compare, $query['w'] ) ) )
			$where_parts[] = _wp_mysql_week( $column ) . " $compare $value";

		if ( isset( $query['dayofyear'] ) && $value = $this->build_value( $compare, $query['dayofyear'] ) )
			$where_parts[] = "DAYOFYEAR( $column ) $compare $value";

		if ( isset( $query['day'] ) && $value = $this->build_value( $compare, $query['day'] ) )
			$where_parts[] = "DAYOFMONTH( $column ) $compare $value";

		if ( isset( $query['dayofweek'] ) && $value = $this->build_value( $compare, $query['dayofweek'] ) )
			$where_parts[] = "DAYOFWEEK( $column ) $compare $value";

		if ( isset( $query['hour'] ) || isset( $query['minute'] ) || isset( $query['second'] ) ) {
			// Avoid notices
			foreach ( array( 'hour', 'minute', 'second' ) as $unit ) {
				if ( ! isset( $query[$unit] ) ) {
					$query[$unit] = null;
				}
			}

			if ( $time_query = $this->build_time_query( $column, $compare, $query['hour'], $query['minute'], $query['second'] ) ) {
				$where_parts[] = $time_query;
			}
		}

		return $where_parts;
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
}