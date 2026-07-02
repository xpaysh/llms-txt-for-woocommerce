<?php
/**
 * AI Commerce tab — the dashboard "home" for the plugin.
 *
 * Design goals:
 *  - Local-first. This screen makes ZERO plugin-initiated network requests.
 *  - Every external action is a user-clicked outbound link (Playground /
 *    onboarding) carrying only permitted, disclosed parameters — no opt-in
 *    ping, no PII, no email. The Privacy tab remains the single place any
 *    opt-in telemetry is toggled.
 *  - The Agent-Readiness Score is a full audit that runs on xpay.sh, reached via
 *    a user-clicked link (SCORE_URL); nothing about it is computed or sent here.
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package AgenticCommerceLlmsTxt
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Lltxt_Ai_Commerce_Tab.
 */
class Lltxt_Ai_Commerce_Tab {

	/**
	 * Base URLs for the outbound (user-clicked) links.
	 */
	const PLAYGROUND_URL = 'https://play.xpay.sh/';
	const ONBOARDING_URL = 'https://app.xpay.sh/onboarding/';
	const FLAGSHIP_URL   = 'https://www.xpay.sh/agentic-commerce/';
	const REVIEWS_URL    = 'https://wordpress.org/support/plugin/agentic-commerce-llms-txt/reviews/#new-post';

	// Public (unauthenticated) xpay.sh landing pages: they showcase a sample +
	// the value, capture the shop domain from the query string, and route the
	// merchant onward (audit sign-up / flagship-plugin install). NOT app.xpay.sh
	// auth-gated paths — a cold merchant there just hits a login wall.
	const SCORE_URL       = 'https://www.xpay.sh/merchants/audit/';
	const BOT_TRAFFIC_URL = 'https://www.xpay.sh/merchants/ai-traffic/';

	/**
	 * Permitted, disclosed parameters passed on outbound links.
	 *
	 * Domain only — never email, never PII. `env` lets our side distinguish
	 * real production stores from local/staging test installs.
	 *
	 * @param array<string,string> $extra Extra query args (utm_*, persona…).
	 * @return array<string,string>
	 */
	public static function link_params( $extra = array() ) {
		$params = array(
			'store'  => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'env'    => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
			'v'      => LLTXT_VERSION,
			'locale' => get_locale(),
		);
		return array_merge( $params, $extra );
	}

	/**
	 * Cheap published-product count for the status line (local, no scoring).
	 *
	 * The Agent-Readiness Score itself is deliberately NOT computed here — it is
	 * a nuanced audit that runs on xpay.sh (see SCORE_URL), gated on the report.
	 *
	 * @return int
	 */
	public static function product_count() {
		if ( ! Lltxt_Catalog_Reader::wc_ready() ) {
			return 0;
		}
		$counts = wp_count_posts( 'product' );
		return ( $counts && isset( $counts->publish ) ) ? (int) $counts->publish : 0;
	}

	/**
	 * Render the tab.
	 *
	 * @return void
	 */
	public static function render() {
		$playground = add_query_arg(
			self::link_params(
				array(
					'utm_source'   => 'wc-plugin',
					'utm_medium'   => 'dashboard',
					'utm_campaign' => 'ai-commerce-tab',
				)
			),
			self::PLAYGROUND_URL
		);
		$onboarding = add_query_arg(
			self::link_params(
				array(
					'persona'      => 'merchant',
					'utm_source'   => 'wc-plugin',
					'utm_medium'   => 'dashboard',
					'utm_campaign' => 'flagship-bridge',
				)
			),
			self::ONBOARDING_URL
		);
		$score_url  = add_query_arg(
			self::link_params(
				array(
					'utm_source'   => 'wc-plugin',
					'utm_medium'   => 'dashboard',
					'utm_campaign' => 'readiness-score',
				)
			),
			self::SCORE_URL
		);
		$bot_url    = add_query_arg(
			self::link_params(
				array(
					'utm_source'   => 'wc-plugin',
					'utm_medium'   => 'dashboard',
					'utm_campaign' => 'bot-traffic',
				)
			),
			self::BOT_TRAFFIC_URL
		);

		$product_count = self::product_count();
		$last          = Lltxt_Refresh::last_refresh();

		// Resolve the two file URLs for the status card (local, cheap).
		$files = array();
		foreach ( Lltxt_Plugin::emitter_classes() as $class ) {
			$emitter = Lltxt_Plugin::make_emitter( $class );
			if ( null === $emitter ) {
				continue;
			}
			$path = $emitter->output_path();
			if ( null === $path || '' === $path ) {
				continue;
			}
			$files[] = array(
				'label' => '/' . $path,
				'url'   => Lltxt_Cache::get_url( $path ),
			);
		}
		?>
		<div class="lltxt-ac">
			<div class="ac-grid">

				<?php // ---- Status (local) ---- ?>
				<section class="ac-card ac-12">
					<div class="ac-row">
						<div>
							<h2 class="ac-h"><span class="ac-ok">&#10003; <?php esc_html_e( 'Your AI catalog files are live', 'agentic-commerce-llms-txt' ); ?></span> <span class="ac-pill ac-pill-local"><?php esc_html_e( 'local', 'agentic-commerce-llms-txt' ); ?></span></h2>
							<p class="ac-muted" style="margin:2px 0 0">
								<?php
								if ( $last ) {
									echo esc_html(
										sprintf(
											/* translators: %s: human-readable time difference. */
											__( 'Auto-refreshed daily · last generated %s ago', 'agentic-commerce-llms-txt' ),
											human_time_diff( (int) $last, time() )
										)
									);
								} else {
									esc_html_e( 'Auto-refreshed daily via WP-Cron.', 'agentic-commerce-llms-txt' );
								}
								if ( $product_count > 0 ) {
									echo ' · ';
									echo esc_html(
										sprintf(
											/* translators: %d: number of published products. */
											_n( '%d product in your catalog', '%d products in your catalog', $product_count, 'agentic-commerce-llms-txt' ),
											$product_count
										)
									);
								}
								?>
							</p>
						</div>
						<div class="ac-spacer"></div>
						<?php foreach ( $files as $f ) : ?>
							<a class="button" href="<?php echo esc_url( $f['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html__( 'View', 'agentic-commerce-llms-txt' ) . ' ' . esc_html( $f['label'] ); ?> <span class="ac-ext">&#8599;</span></a>
						<?php endforeach; ?>
						<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => Lltxt_Admin_Page::SLUG, 'tab' => 'files' ), admin_url( 'options-general.php' ) ) ); ?>"><?php esc_html_e( 'Manage files', 'agentic-commerce-llms-txt' ); ?></a>
					</div>
					<p class="ac-tiny ac-muted" style="margin:10px 0 0">
						<?php esc_html_e( 'Files working well for you?', 'agentic-commerce-llms-txt' ); ?>
						<a href="<?php echo esc_url( self::REVIEWS_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Leave a quick review ★', 'agentic-commerce-llms-txt' ); ?></a>
						&mdash; <?php esc_html_e( 'it means a lot and helps us keep improving it.', 'agentic-commerce-llms-txt' ); ?>
					</p>
				</section>

				<?php // ---- Playground (outbound link; no ping) ---- ?>
				<section class="ac-card ac-hero ac-7">
					<div class="ac-row" style="align-items:flex-start;gap:18px;">
						<div style="flex:1;min-width:230px;">
							<p class="ac-eyebrow"><?php esc_html_e( 'AI Shopping Agent Preview', 'agentic-commerce-llms-txt' ); ?></p>
							<h2><?php
								/* translators: emphasis markup wraps "AI shopping agent". */
								echo wp_kses( __( 'See your store the way an <em>AI shopping agent</em> does<br>— in 10 seconds', 'agentic-commerce-llms-txt' ), array( 'em' => array(), 'br' => array() ) );
							?></h2>
							<p class="ac-sub"><?php esc_html_e( 'Watch a live agent browse your store, read your catalog, and answer a shopper’s question.', 'agentic-commerce-llms-txt' ); ?></p>
							<div class="ac-row">
								<a class="button button-hero" href="<?php echo esc_url( $playground ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open the AI Playground', 'agentic-commerce-llms-txt' ); ?> <span class="ac-ext">&#8599;</span></a>
								<a class="ac-lite ac-tiny" href="<?php echo esc_url( add_query_arg( array( 'page' => Lltxt_Admin_Page::SLUG, 'tab' => 'privacy' ), admin_url( 'options-general.php' ) ) ); ?>"><?php esc_html_e( 'what’s sent?', 'agentic-commerce-llms-txt' ); ?></a>
							</div>
						</div>
						<div class="ac-thumb" aria-hidden="true">
							<div class="ac-play"></div>
							<span class="ac-cap"><?php esc_html_e( 'agent · live demo', 'agentic-commerce-llms-txt' ); ?></span>
						</div>
					</div>
				</section>

				<?php // ---- Agent-Readiness Score (nuanced audit runs on xpay.sh) ---- ?>
				<section class="ac-card ac-score ac-5">
					<div class="ac-row" style="gap:16px;">
						<div class="ac-ring" style="background:conic-gradient(#dc9b1e 0 75%, #e6e7e8 75% 100%)" aria-hidden="true">
							<span style="color:#8a6d1a">?</span>
						</div>
						<div style="flex:1;min-width:150px;">
							<h2 class="ac-h"><?php esc_html_e( 'Agent-Readiness Score', 'agentic-commerce-llms-txt' ); ?></h2>
							<p class="ac-muted" style="margin:2px 0 10px"><?php esc_html_e( 'How well can AI shopping agents read, understand, and recommend your catalog? Run the full audit for your score and a prioritized fix-list.', 'agentic-commerce-llms-txt' ); ?></p>
							<a class="button button-primary" href="<?php echo esc_url( $score_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Get my Agent-Readiness Score', 'agentic-commerce-llms-txt' ); ?> <span class="ac-ext">&#8599;</span></a>
						</div>
					</div>
				</section>

				<?php // ---- Bot Traffic bridge (explains value + prompts flagship install) ---- ?>
				<section class="ac-card ac-7">
					<h2 class="ac-h"><?php esc_html_e( 'See which AI agents visit your store', 'agentic-commerce-llms-txt' ); ?></h2>
					<p class="ac-muted" style="margin:2px 0 10px">
						<?php esc_html_e( 'A live Bot-Traffic view shows AI assistants — ChatGPT, Claude, Gemini and more — as they hit your store two ways: live shopper fetches (a person asking an AI about your products right now) and indexing crawls (building your future AI visibility), plus which products agents explored.', 'agentic-commerce-llms-txt' ); ?>
					</p>
					<p class="ac-muted" style="margin:0 0 10px">
						<?php esc_html_e( 'This lightweight plugin makes your store agent-readable; capturing that live traffic is done by our main Agentic Commerce for WooCommerce plugin. See a sample and connect your store:', 'agentic-commerce-llms-txt' ); ?>
					</p>
					<a class="button button-primary" href="<?php echo esc_url( $bot_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'See Bot Traffic & connect', 'agentic-commerce-llms-txt' ); ?> <span class="ac-ext">&#8599;</span></a>
				</section>

				<?php // ---- Flagship bridge (onboarding link) ---- ?>
				<section class="ac-card ac-bridge ac-5">
					<h2 class="ac-h"><?php esc_html_e( 'Ready for agent checkout?', 'agentic-commerce-llms-txt' ); ?></h2>
					<p class="ac-muted" style="margin:2px 0 10px"><?php esc_html_e( 'Your catalog is readable. The next step is letting agents buy — agent-ready cart & checkout.', 'agentic-commerce-llms-txt' ); ?></p>
					<a class="button button-primary" href="<?php echo esc_url( $onboarding ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Unlock agent checkouts', 'agentic-commerce-llms-txt' ); ?> <span class="ac-ext">&#8599;</span></a>
				</section>

				<?php // ---- Quick links to the other tabs ---- ?>
				<section class="ac-12" style="margin-top:2px;">
					<div class="ac-qlinks">
						<?php
						$links = array(
							array( 'files', 'media-text', __( 'Files', 'agentic-commerce-llms-txt' ), __( 'Preview & restore', 'agentic-commerce-llms-txt' ) ),
							array( 'catalog', 'products', __( 'Catalog', 'agentic-commerce-llms-txt' ), __( 'Categories & limits', 'agentic-commerce-llms-txt' ) ),
							array( 'diagnostics', 'chart-line', __( 'Diagnostics', 'agentic-commerce-llms-txt' ), __( 'Refresh log', 'agentic-commerce-llms-txt' ) ),
							array( 'version-control', 'backup', __( 'Version Control', 'agentic-commerce-llms-txt' ), __( 'History & pin', 'agentic-commerce-llms-txt' ) ),
							array( 'privacy', 'shield', __( 'Privacy', 'agentic-commerce-llms-txt' ), __( 'Data & consent', 'agentic-commerce-llms-txt' ) ),
						);
						foreach ( $links as $l ) :
							$href = add_query_arg( array( 'page' => Lltxt_Admin_Page::SLUG, 'tab' => $l[0] ), admin_url( 'options-general.php' ) );
							?>
							<a class="ac-qlink" href="<?php echo esc_url( $href ); ?>">
								<span class="dashicons dashicons-<?php echo esc_attr( $l[1] ); ?>"></span>
								<span><span class="ac-t"><?php echo esc_html( $l[2] ); ?></span><br><span class="ac-d"><?php echo esc_html( $l[3] ); ?></span></span>
							</a>
						<?php endforeach; ?>
					</div>
				</section>
			</div>

			<p class="ac-foot">
				<?php esc_html_e( 'by', 'agentic-commerce-llms-txt' ); ?>
				<a href="<?php echo esc_url( self::FLAGSHIP_URL ); ?>" target="_blank" rel="noopener">xpay&#10022; Commerce</a>
				&middot; <?php esc_html_e( 'Agentic Commerce for WooCommerce', 'agentic-commerce-llms-txt' ); ?>
			</p>
		</div>
		<?php
	}
}
