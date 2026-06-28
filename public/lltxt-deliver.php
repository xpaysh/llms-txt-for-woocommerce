<?php
/**
 * Rewrite target for all 13 routes. Reads the lltxt_route query var, maps it to
 * a static file, and serves it with the correct Content-Type. Falls back to a
 * live dynamic render if the static file is missing (hardened/read-only hosts).
 *
 * Included (and exited) from Lltxt_Router::maybe_deliver().
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package LLMsTxtForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

$lltxt_route  = get_query_var( Lltxt_Router::QV );
$lltxt_routes = Lltxt_Router::routes();

if ( ! isset( $lltxt_routes[ $lltxt_route ] ) ) {
	status_header( 404 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	echo "Not found\n";
	return;
}

$lltxt_rel = $lltxt_routes[ $lltxt_route ];

// Find the emitter that owns this output path (for MIME type + dynamic fallback).
$lltxt_emitter = null;
foreach ( Lltxt_Plugin::emitter_classes() as $lltxt_class ) {
	$lltxt_obj = Lltxt_Plugin::make_emitter( $lltxt_class );
	if ( null !== $lltxt_obj && $lltxt_obj->output_path() === $lltxt_rel ) {
		$lltxt_emitter = $lltxt_obj;
		break;
	}
}

$lltxt_mime = $lltxt_emitter ? $lltxt_emitter->mime_type() : 'text/plain; charset=utf-8';
$lltxt_body = Lltxt_Cache::read( $lltxt_rel );

if ( false === $lltxt_body && null !== $lltxt_emitter ) {
	// Static file missing — render live as a graceful fallback.
	$lltxt_body = $lltxt_emitter->render();
}

if ( false === $lltxt_body || null === $lltxt_body ) {
	status_header( 404 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	echo "Not generated yet\n";
	return;
}

status_header( 200 );
header( 'Content-Type: ' . $lltxt_mime );
header( 'X-Robots-Tag: all' );
header( 'Cache-Control: public, max-age=300, stale-while-revalidate=900' );

// $lltxt_body is plugin-generated markdown/JSON/XML to be served verbatim.
echo $lltxt_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
