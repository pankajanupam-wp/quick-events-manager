<?php
/**
 * A QR code, with nothing to install.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\CheckIn;

defined( 'ABSPATH' ) || exit;

/**
 * Enough of QR to encode a ticket code, and deliberately no more.
 *
 * A general QR encoder is thousands of lines and four modes and forty versions.
 * What this needs to carry is a ticket code — `QEVT-` and twelve uppercase hex
 * characters — which sits inside **alphanumeric mode** exactly, and inside
 * **version 1** with room to spare: twenty characters at error correction level
 * M, against the seventeen a code uses.
 *
 * So this encodes version 1, level M, alphanumeric only, and **refuses anything
 * else** rather than guessing. A QR code that does not scan is worth less than
 * no QR code at all, because the person holding the phone believes it.
 *
 * No dependency, per [ADR-0007](../../docs/adr/0007-no-runtime-dependencies.md):
 * `vendor/` does not ship to wordpress.org, so a library here would have to be
 * copied into the plugin and maintained by us anyway.
 *
 * The output is an SVG string. Nothing here draws pixels — a QR code is a grid
 * of squares, an SVG of a grid of squares is a few hundred bytes, and it scales
 * to whatever the screen or the paper is without GD, Imagick or a temporary
 * file.
 *
 * @since 26.0
 */
final class Qr {

	/**
	 * Modules along one side, for version 1.
	 */
	const SIZE = 21;

	/**
	 * Data codewords in a version 1, level M symbol.
	 */
	const DATA_CODEWORDS = 16;

	/**
	 * Error correction codewords in a version 1, level M symbol.
	 */
	const EC_CODEWORDS = 10;

	/**
	 * The alphanumeric alphabet, in its QR order. Position is the value.
	 */
	const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';

	/**
	 * Whether this encoder can carry a given string.
	 *
	 * @since 26.0
	 *
	 * @param string $text The text.
	 */
	public static function can_encode( string $text ): bool {
		if ( '' === $text || strlen( $text ) > 20 ) {
			return false;
		}

		return 1 === preg_match( '/^[0-9A-Z $%*+\-.\/:]+$/', $text );
	}

	/**
	 * The module grid for a string, as rows of booleans.
	 *
	 * True is dark. Returns an empty array for anything this cannot carry,
	 * which the caller must treat as "no QR code" rather than "an empty one".
	 *
	 * @since 26.0
	 *
	 * @param string $text The text.
	 * @return array<int, array<int, bool>>
	 */
	public static function matrix( string $text ): array {
		if ( ! self::can_encode( $text ) ) {
			return array();
		}

		$codewords = self::codewords( $text );
		$best      = null;
		$lowest    = PHP_INT_MAX;

		/*
		 * All eight masks are built and scored, and the lowest penalty wins.
		 * The specification's four penalty rules exist because a scanner has to
		 * find the symbol in a photograph: long runs of one colour, solid
		 * blocks, and anything resembling a finder pattern all make that
		 * harder. Picking mask 0 always would produce codes that scan on a
		 * screen and fail on a printed page.
		 */
		for ( $mask = 0; $mask < 8; $mask++ ) {
			$candidate = self::draw( $codewords, $mask );
			$penalty   = self::penalty( $candidate );

			if ( $penalty < $lowest ) {
				$lowest = $penalty;
				$best   = $candidate;
			}
		}

		return null !== $best ? $best : array();
	}

	/**
	 * A QR code as an SVG string.
	 *
	 * @since 26.0
	 *
	 * @param string $text  The text.
	 * @param int    $scale Pixels per module.
	 * @param int    $quiet Quiet zone in modules; four is the specification's.
	 * @return string SVG, or '' when the text cannot be carried.
	 */
	public static function svg( string $text, int $scale = 6, int $quiet = 4 ): string {
		$matrix = self::matrix( $text );

		if ( array() === $matrix ) {
			return '';
		}

		$scale = max( 1, $scale );
		$quiet = max( 0, $quiet );
		$side  = ( self::SIZE + ( $quiet * 2 ) ) * $scale;
		$path  = '';

		foreach ( $matrix as $y => $row ) {
			foreach ( $row as $x => $dark ) {
				if ( ! $dark ) {
					continue;
				}

				$path .= sprintf(
					'M%d %dh%dv%dh-%dz',
					( $x + $quiet ) * $scale,
					( $y + $quiet ) * $scale,
					$scale,
					$scale,
					$scale
				);
			}
		}

		/*
		 * One path for every dark module rather than a rectangle each: the
		 * markup is a third of the size, and this ends up inline in an email
		 * where every byte is copied to every recipient.
		 *
		 * The white background is drawn rather than left transparent. A QR code
		 * on a dark background is a QR code nothing can read, and email clients
		 * and dark-mode readers are full of dark backgrounds.
		 */
		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 %1$d %1$d" role="img" aria-label="%2$s">'
				. '<rect width="%1$d" height="%1$d" fill="#fff"/><path d="%3$s" fill="#000"/></svg>',
			$side,
			esc_attr( sprintf( /* translators: %s: The ticket code the QR encodes. */ __( 'QR code for ticket %s', 'quick-events-manager' ), $text ) ),
			$path
		);
	}

	/**
	 * The final codeword sequence: data, padding and error correction.
	 *
	 * @since 26.0
	 *
	 * @param string $text The text.
	 * @return int[]
	 */
	private static function codewords( string $text ): array {
		$bits = '0010' . str_pad( decbin( strlen( $text ) ), 9, '0', STR_PAD_LEFT );

		/*
		 * Alphanumeric mode packs two characters into eleven bits, which is why
		 * a ticket code fits a version 1 symbol at all. A trailing odd
		 * character takes six.
		 */
		$length = strlen( $text );

		for ( $i = 0; $i < $length; $i += 2 ) {
			$first = strpos( self::ALPHABET, $text[ $i ] );

			if ( isset( $text[ $i + 1 ] ) ) {
				$second = strpos( self::ALPHABET, $text[ $i + 1 ] );
				$bits  .= str_pad( decbin( ( (int) $first * 45 ) + (int) $second ), 11, '0', STR_PAD_LEFT );

				continue;
			}

			$bits .= str_pad( decbin( (int) $first ), 6, '0', STR_PAD_LEFT );
		}

		$capacity = self::DATA_CODEWORDS * 8;
		$bits    .= str_repeat( '0', min( 4, $capacity - strlen( $bits ) ) );
		$bits    .= str_repeat( '0', ( 8 - ( strlen( $bits ) % 8 ) ) % 8 );

		$data = array();

		foreach ( str_split( $bits, 8 ) as $byte ) {
			$data[] = bindec( $byte );
		}

		// The specification's own padding bytes, alternating, to fill the block.
		$pad = array( 0xEC, 0x11 );

		$padded = 0;
		$so_far = count( $data );

		while ( $so_far < self::DATA_CODEWORDS ) {
			$data[] = $pad[ $padded % 2 ];

			++$padded;
			++$so_far;
		}

		return array_merge( $data, self::error_correction( $data ) );
	}

	/**
	 * Reed-Solomon error correction codewords.
	 *
	 * Polynomial division over GF(256), which is what lets a scanner read a
	 * code with a thumb over part of it. Level M recovers about fifteen per
	 * cent, which is the level chosen because these get printed, folded and
	 * photographed.
	 *
	 * @since 26.0
	 *
	 * @param int[] $data Data codewords.
	 * @return int[]
	 */
	private static function error_correction( array $data ): array {
		list( $exp, $log ) = self::tables();

		// The generator polynomial for ten EC codewords.
		$generator = array( 1 );

		for ( $i = 0; $i < self::EC_CODEWORDS; $i++ ) {
			$next = array_fill( 0, count( $generator ) + 1, 0 );

			foreach ( $generator as $index => $coefficient ) {
				$next[ $index ]     ^= $coefficient;
				$next[ $index + 1 ] ^= 0 === $coefficient ? 0 : $exp[ ( $log[ $coefficient ] + $i ) % 255 ];
			}

			$generator = $next;
		}

		$remainder = array_merge( $data, array_fill( 0, self::EC_CODEWORDS, 0 ) );
		$words     = count( $data );

		for ( $i = 0; $i < $words; $i++ ) {
			$lead = $remainder[ $i ];

			if ( 0 === $lead ) {
				continue;
			}

			foreach ( $generator as $index => $coefficient ) {
				if ( 0 === $coefficient ) {
					continue;
				}

				$remainder[ $i + $index ] ^= $exp[ ( $log[ $coefficient ] + $log[ $lead ] ) % 255 ];
			}
		}

		return array_slice( $remainder, $words, self::EC_CODEWORDS );
	}

	/**
	 * Exponent and logarithm tables for GF(256), built once.
	 *
	 * @since 26.0
	 *
	 * @return array{0: int[], 1: int[]}
	 */
	private static function tables(): array {
		static $tables = null;

		if ( null !== $tables ) {
			return $tables;
		}

		$exp = array_fill( 0, 256, 0 );
		$log = array_fill( 0, 256, 0 );
		$x   = 1;

		for ( $i = 0; $i < 255; $i++ ) {
			$exp[ $i ] = $x;
			$log[ $x ] = $i;

			$x <<= 1;

			// The QR specification's primitive polynomial.
			if ( $x & 0x100 ) {
				$x ^= 0x11D;
			}
		}

		$tables = array( $exp, $log );

		return $tables;
	}

	/**
	 * Place everything in the grid, with one mask applied.
	 *
	 * @since 26.0
	 *
	 * @param int[] $codewords Data and error correction codewords.
	 * @param int   $mask      Mask pattern, 0-7.
	 * @return array<int, array<int, bool>>
	 */
	private static function draw( array $codewords, int $mask ): array {
		$matrix   = array_fill( 0, self::SIZE, array_fill( 0, self::SIZE, false ) );
		$reserved = array_fill( 0, self::SIZE, array_fill( 0, self::SIZE, false ) );

		// Three finder patterns and their separators.
		foreach ( array( array( 0, 0 ), array( self::SIZE - 7, 0 ), array( 0, self::SIZE - 7 ) ) as $corner ) {
			list( $ox, $oy ) = $corner;

			for ( $y = -1; $y <= 7; $y++ ) {
				for ( $x = -1; $x <= 7; $x++ ) {
					$px = $ox + $x;
					$py = $oy + $y;

					if ( $px < 0 || $py < 0 || $px >= self::SIZE || $py >= self::SIZE ) {
						continue;
					}

					$edge = 0 === $x || 6 === $x || 0 === $y || 6 === $y;
					$core = $x >= 2 && $x <= 4 && $y >= 2 && $y <= 4;

					$matrix[ $py ][ $px ]   = ( $edge || $core ) && $x >= 0 && $x <= 6 && $y >= 0 && $y <= 6;
					$reserved[ $py ][ $px ] = true;
				}
			}
		}

		// Timing patterns.
		for ( $i = 8; $i < self::SIZE - 8; $i++ ) {
			$dark = 0 === $i % 2;

			$matrix[6][ $i ]   = $dark;
			$matrix[ $i ][6]   = $dark;
			$reserved[6][ $i ] = true;
			$reserved[ $i ][6] = true;
		}

		// The dark module, which is always dark and always here.
		$matrix[ self::SIZE - 8 ][8]   = true;
		$reserved[ self::SIZE - 8 ][8] = true;

		// Format information areas, filled in after masking.
		for ( $i = 0; $i < 9; $i++ ) {
			$reserved[8][ $i ] = true;
			$reserved[ $i ][8] = true;
		}

		for ( $i = 0; $i < 8; $i++ ) {
			$reserved[8][ self::SIZE - 1 - $i ] = true;
			$reserved[ self::SIZE - 1 - $i ][8] = true;
		}

		// The data, up the right-hand side and back down, two columns at a time.
		$bits = '';

		foreach ( $codewords as $codeword ) {
			$bits .= str_pad( decbin( $codeword ), 8, '0', STR_PAD_LEFT );
		}

		$position = 0;
		$upward   = true;
		$total    = strlen( $bits );

		for ( $right = self::SIZE - 1; $right > 0; $right -= 2 ) {
			if ( 6 === $right ) {
				// The vertical timing pattern is not a data column.
				--$right;
			}

			for ( $step = 0; $step < self::SIZE; $step++ ) {
				$y = $upward ? self::SIZE - 1 - $step : $step;

				foreach ( array( $right, $right - 1 ) as $x ) {
					if ( $reserved[ $y ][ $x ] ) {
						continue;
					}

					$bit = $position < $total && '1' === $bits[ $position ];

					++$position;

					$matrix[ $y ][ $x ] = self::mask_at( $mask, $x, $y ) !== $bit;
				}
			}

			$upward = ! $upward;
		}

		self::place_format( $matrix, $mask );

		return $matrix;
	}

	/**
	 * Whether a mask flips the module at a position.
	 *
	 * @since 26.0
	 *
	 * @param int $mask Mask pattern, 0-7.
	 * @param int $x    Column.
	 * @param int $y    Row.
	 */
	private static function mask_at( int $mask, int $x, int $y ): bool {
		switch ( $mask ) {
			case 0:
				return 0 === ( $y + $x ) % 2;
			case 1:
				return 0 === $y % 2;
			case 2:
				return 0 === $x % 3;
			case 3:
				return 0 === ( $y + $x ) % 3;
			case 4:
				return 0 === ( ( (int) floor( $y / 2 ) ) + ( (int) floor( $x / 3 ) ) ) % 2;
			case 5:
				return 0 === ( ( $y * $x ) % 2 ) + ( ( $y * $x ) % 3 );
			case 6:
				return 0 === ( ( ( $y * $x ) % 2 ) + ( ( $y * $x ) % 3 ) ) % 2;
			case 7:
			default:
				return 0 === ( ( ( $y + $x ) % 2 ) + ( ( $y * $x ) % 3 ) ) % 2;
		}
	}

	/**
	 * Write the format information, which says the level and the mask.
	 *
	 * @since 26.0
	 *
	 * @param array<int, array<int, bool>> $matrix The grid, by reference.
	 * @param int                          $mask   Mask pattern, 0-7.
	 * @return void
	 */
	private static function place_format( array &$matrix, int $mask ): void {
		// Level M is 00, followed by the three mask bits.
		$format = ( 0x00 << 3 ) | $mask;
		$bch    = $format << 10;

		for ( $i = 4; $i >= 0; $i-- ) {
			if ( $bch & ( 1 << ( $i + 10 ) ) ) {
				$bch ^= 0x537 << $i;
			}
		}

		$bits = ( ( $format << 10 ) | $bch ) ^ 0x5412;

		for ( $i = 0; $i < 15; $i++ ) {
			$dark = (bool) ( ( $bits >> $i ) & 1 );

			// Around the top-left finder.
			if ( $i < 6 ) {
				$matrix[ $i ][8] = $dark;
			} elseif ( 6 === $i ) {
				$matrix[7][8] = $dark;
			} elseif ( 7 === $i ) {
				$matrix[8][8] = $dark;
			} elseif ( 8 === $i ) {
				$matrix[8][7] = $dark;
			} else {
				$matrix[8][ 14 - $i ] = $dark;
			}

			// And the copy split between the other two.
			if ( $i < 8 ) {
				$matrix[8][ self::SIZE - 1 - $i ] = $dark;
			} else {
				$matrix[ self::SIZE - 15 + $i ][8] = $dark;
			}
		}
	}

	/**
	 * The specification's four penalty rules, for choosing a mask.
	 *
	 * @since 26.0
	 *
	 * @param array<int, array<int, bool>> $matrix The grid.
	 */
	private static function penalty( array $matrix ): int {
		$score = 0;
		$size  = self::SIZE;

		// Rule 1: runs of five or more of one colour, in both directions.
		for ( $i = 0; $i < $size; $i++ ) {
			foreach ( array( 'row', 'column' ) as $direction ) {
				$run  = 1;
				$last = null;

				for ( $j = 0; $j < $size; $j++ ) {
					$module = 'row' === $direction ? $matrix[ $i ][ $j ] : $matrix[ $j ][ $i ];

					if ( $module === $last ) {
						++$run;

						continue;
					}

					if ( $run >= 5 ) {
						$score += 3 + ( $run - 5 );
					}

					$run  = 1;
					$last = $module;
				}

				if ( $run >= 5 ) {
					$score += 3 + ( $run - 5 );
				}
			}
		}

		// Rule 2: any two-by-two block of one colour.
		for ( $y = 0; $y < $size - 1; $y++ ) {
			for ( $x = 0; $x < $size - 1; $x++ ) {
				$module = $matrix[ $y ][ $x ];

				if ( $module === $matrix[ $y ][ $x + 1 ]
					&& $module === $matrix[ $y + 1 ][ $x ]
					&& $module === $matrix[ $y + 1 ][ $x + 1 ] ) {
					$score += 3;
				}
			}
		}

		// Rule 3: anything that looks like a finder pattern.
		$patterns = array(
			array( true, false, true, true, true, false, true, false, false, false, false ),
			array( false, false, false, false, true, false, true, true, true, false, true ),
		);

		for ( $y = 0; $y < $size; $y++ ) {
			for ( $x = 0; $x < $size; $x++ ) {
				foreach ( $patterns as $pattern ) {
					if ( $x + 11 <= $size ) {
						$run = array();

						for ( $i = 0; $i < 11; $i++ ) {
							$run[] = $matrix[ $y ][ $x + $i ];
						}

						if ( $run === $pattern ) {
							$score += 40;
						}
					}

					if ( $y + 11 <= $size ) {
						$run = array();

						for ( $i = 0; $i < 11; $i++ ) {
							$run[] = $matrix[ $y + $i ][ $x ];
						}

						if ( $run === $pattern ) {
							$score += 40;
						}
					}
				}
			}
		}

		// Rule 4: how far the proportion of dark modules is from half.
		$dark = 0;

		foreach ( $matrix as $row ) {
			$dark += count( array_filter( $row ) );
		}

		$percent = ( $dark * 100 ) / ( $size * $size );
		$score  += ( (int) ( abs( $percent - 50 ) / 5 ) ) * 10;

		return $score;
	}
}
