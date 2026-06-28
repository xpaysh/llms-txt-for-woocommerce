<?php
/**
 * Emitter contract. Every emitted file (and the two hook-only emitters)
 * implements this so the refresh + router + admin code can treat them uniformly.
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package LLMsTxtForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Interface Lltxt_Emitter_Interface.
 */
interface Lltxt_Emitter_Interface {

	/**
	 * Render the file body.
	 *
	 * @return string
	 */
	public function render();

	/**
	 * Relative output path from the webroot (e.g. 'llms.txt'). Returns null for
	 * hook-only emitters (robots.txt, head discovery) that write no static file.
	 *
	 * @return string|null
	 */
	public function output_path();

	/**
	 * MIME type the deliverer should send.
	 *
	 * @return string
	 */
	public function mime_type();

	/**
	 * Regex route pattern for add_rewrite_rule (without anchors). Empty string
	 * for hook-only emitters.
	 *
	 * @return string
	 */
	public function route_pattern();
}
