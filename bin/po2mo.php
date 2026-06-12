<?php
/**
 * Minimal .po -> .mo compiler (no gettext dependency).
 *
 * Usage: php bin/po2mo.php languages/tur-takvimi-tr_TR.po
 *
 * Produces a sibling .mo file. Handles plain msgid/msgstr pairs with
 * multi-line continuations. Plural forms are not needed by this project.
 *
 * @package TurTakvimi
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$po = $argv[1] ?? '';
if ( ! $po || ! is_readable( $po ) ) {
	fwrite( STDERR, "Usage: php bin/po2mo.php <file.po>\n" );
	exit( 1 );
}

/**
 * Unescape a quoted .po string body.
 */
function po_unescape( string $s ): string {
	return strtr(
		$s,
		array(
			'\\n'  => "\n",
			'\\t'  => "\t",
			'\\r'  => "\r",
			'\\"'  => '"',
			'\\\\' => '\\',
		)
	);
}

$lines   = file( $po, FILE_IGNORE_NEW_LINES );
$entries = array();
$current = null;       // 'msgid' | 'msgstr'.
$id      = '';
$str     = '';

$flush = static function () use ( &$entries, &$id, &$str ) {
	if ( '' !== $id || '' === $id && '' !== $str ) {
		$entries[ $id ] = $str;
	}
};

foreach ( $lines as $line ) {
	$trim = trim( $line );

	if ( '' === $trim || str_starts_with( $trim, '#' ) ) {
		continue;
	}

	if ( str_starts_with( $trim, 'msgid ' ) ) {
		// Starting a new entry: store the previous one.
		if ( null !== $current ) {
			$entries[ $id ] = $str;
		}
		$id      = po_unescape( substr( $trim, 7, -1 ) );
		$str     = '';
		$current = 'msgid';
		continue;
	}

	if ( str_starts_with( $trim, 'msgstr ' ) ) {
		$str     = po_unescape( substr( $trim, 8, -1 ) );
		$current = 'msgstr';
		continue;
	}

	if ( str_starts_with( $trim, '"' ) ) {
		$chunk = po_unescape( substr( $trim, 1, -1 ) );
		if ( 'msgid' === $current ) {
			$id .= $chunk;
		} elseif ( 'msgstr' === $current ) {
			$str .= $chunk;
		}
	}
}
if ( null !== $current ) {
	$entries[ $id ] = $str;
}

// Build the .mo binary (GNU format). Header entry has empty msgid.
ksort( $entries );
$ids     = array_keys( $entries );
$count   = count( $ids );
$o_table = '';
$t_table = '';
$ids_blob = '';
$str_blob = '';

$o_offsets = array();
$t_offsets = array();
$base_ids  = 28 + ( $count * 8 ) + ( $count * 8 );

foreach ( $ids as $msgid ) {
	$o_offsets[] = array( strlen( $msgid ), strlen( $ids_blob ) );
	$ids_blob   .= $msgid . "\0";
}
foreach ( $ids as $msgid ) {
	$msgstr      = $entries[ $msgid ];
	$t_offsets[] = array( strlen( $msgstr ), strlen( $str_blob ) );
	$str_blob   .= $msgstr . "\0";
}

$ids_start = $base_ids;
$str_start = $base_ids + strlen( $ids_blob );

foreach ( $o_offsets as $o ) {
	$o_table .= pack( 'VV', $o[0], $ids_start + $o[1] );
}
foreach ( $t_offsets as $t ) {
	$t_table .= pack( 'VV', $t[0], $str_start + $t[1] );
}

$mo  = pack( 'V', 0x950412de );     // Magic.
$mo .= pack( 'V', 0 );              // Revision.
$mo .= pack( 'V', $count );         // Number of strings.
$mo .= pack( 'V', 28 );             // Offset of original table.
$mo .= pack( 'V', 28 + $count * 8 ); // Offset of translation table.
$mo .= pack( 'V', 0 );              // Hash table size.
$mo .= pack( 'V', 0 );              // Hash table offset.
$mo .= $o_table;
$mo .= $t_table;
$mo .= $ids_blob;
$mo .= $str_blob;

$out = preg_replace( '/\.po$/', '.mo', $po );
file_put_contents( $out, $mo );
fwrite( STDOUT, "Wrote {$out} ({$count} entries)\n" );
