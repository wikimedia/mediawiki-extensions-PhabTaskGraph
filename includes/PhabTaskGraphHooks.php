<?php

class PhabTaskGraphHooks {

	private static $graphNum = 0;

	/**
	 * Implement ParserFirstCallInit hook.
	 * See https://www.mediawiki.org/wiki/Manual:Hooks/ParserFirstCallInit
	 * Declare parser function.
	 * @since 2.0
	 * @param Parser &$parser the Parser object
	 */
	public static function onParserFirstCallInit( &$parser ) {
		$parser->setFunctionHook( 'datemultilinegraph',
			[ self::class, 'drawDateMultiLineGraph' ] );
	}

	/**
	 * Handle datemultiplinegraph parser function.
	 * @since 2.0
	 * @param Parser $parser the Parser object
	 * @return string HTML div for the graph
	 */
	public static function drawDateMultiLineGraph( Parser $parser ) {
		$params = func_get_args();
		array_shift( $params );
		$args = [];
		foreach ( $params as $param ) {
			$split = explode( '=', $param, 2 );
			if ( count( $split ) > 1 ) {
				$args[$split[0]] = $split[1];
			}
		}

		$output = $parser->getOutput();
		$output->addModules( 'ext.PTG_DateMultiLineGraph' );

		if ( isset( $args['width'] ) ) {
			$width = $args['width'];
		} else {
			$width = 800;
		}

		if ( isset( $args['height'] ) ) {
			$height = $args['height'];
		} else {
			$height = 400;
		}

		if ( isset( $args['xaxis'] ) ) {
			$xaxis = $args['xaxis'];
		} else {
			$xaxis = null;
		}

		if ( isset( $args['yaxis'] ) ) {
			$yaxis = $args['yaxis'];
		} else {
			$yaxis = null;
		}

		if ( isset( $args['delim'] ) ) {
			$delim = $args['delim'];
		} else {
			$delim = ',';
		}

		$rawdata = [];
		$lines = explode( PHP_EOL, $args['data'] );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( strlen( $line ) > 0 ) {
				$items = array_map( 'trim', explode( $delim, $line, 3 ) );
				if ( count( $items ) == 3 ) {
					$date = date_create( $items[1] );
					if ( $date ) {
						$date = date_format( $date, 'Y-m-d' );
						$name = $items[0];
						$value = intval( $items[2] );
						if ( !isset( $rawdata[$name] ) ) {
							$rawdata[$name] = [];
						}
						$rawdata[$name][] = [ $date, $value ];
					}
				}
			}
		}

		$data = [];
		foreach ( $rawdata as $key => $value ) {
			$data[] = [
				'name' => $key,
				'data' => $value
			];
		}

		$graphName = 'PTG_DateMultiLineGraph_' . self::$graphNum;
		$config = [
			'id' => $graphName,
			'width' => $width,
			'height' => $height,
			'xaxis' => $xaxis,
			'yaxis' => $yaxis,
			'data' => $data
		];
		$output->addJsConfigVars( 'PTG_DateMultiLineGraphConfig', $config );

		$html = Html::element( 'div',
				[
					'id' => $graphName
				] );

		return $html;
	}
}
