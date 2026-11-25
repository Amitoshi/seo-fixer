<?php
/**
 * Redirects screen: add/edit redirects, list them, import/export CSV, and the
 * "not found" (404) log with one-click "Create redirect".
 *
 * @package SeoFixer
 */

namespace Seofx\Admin;

use Seofx\Options;
use Seofx\Redirects\Log_404;
use Seofx\Redirects\Paths;
use Seofx\Redirects\Store;
use Seofx\Sanitize;
use Seofx\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Redirects screen and its form handlers.
 */
final class Redirects_Page {

	/**
	 * Page slug.
	 */
	const SLUG = 'seo-fixer-redirects';

	/**
	 * Rows per page.
	 */
	const PER_PAGE = 50;

	/**
	 * Hooks for the form handlers.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_seofx_redirect_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_seofx_redirect_rows', array( $this, 'handle_rows' ) );
		add_action( 'admin_post_seofx_redirect_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_seofx_redirect_import', array( $this, 'handle_import' ) );
		add_action( 'admin_post_seofx_404_rows', array( $this, 'handle_404_rows' ) );
	}

	/**
	 * URL of this screen.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Reads a GET parameter (read-only navigation, no state change).
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private static function query_arg( $key ) {
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view state.
	}

	/**
	 * Reads an address from a GET parameter (the "Create redirect" prefill), keeping %XX sequences.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private static function address_arg( $key ) {
		return isset( $_GET[ $key ] ) ? Sanitize::address( wp_unslash( $_GET[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only prefill; Sanitize::address() cleans it.
	}

	/**
	 * Renders the screen.
	 *
	 * @return void
	 */
	public function render() {
		Security::require_manage();

		$view = '404' === self::query_arg( 'view' ) ? '404' : 'redirects';
		$tabs = array(
			'redirects' => sprintf(
				/* translators: %s: number of redirects. */
				__( 'Redirects (%s)', 'seo-fixer' ),
				number_format_i18n( Store::query( '', 1, 1 )['total'] )
			),
			'404'       => sprintf(
				/* translators: %s: number of logged addresses. */
				__( 'Not found (%s)', 'seo-fixer' ),
				number_format_i18n( Log_404::count() )
			),
		);
		?>
		<div class="wrap seofx-wrap">
			<?php UI::page_header( __( 'Redirects', 'seo-fixer' ), __( 'Send visitors and search engines from old or broken addresses to the right page.', 'seo-fixer' ), self::SLUG ); ?>

			<nav class="seofx-tabs" aria-label="<?php esc_attr_e( 'Redirect sections', 'seo-fixer' ); ?>">
				<?php foreach ( $tabs as $id => $label ) : ?>
					<a class="seofx-tabs__tab<?php echo $id === $view ? ' is-active' : ''; ?>" href="<?php echo esc_url( self::url( 'redirects' === $id ? array() : array( 'view' => $id ) ) ); ?>"<?php echo $id === $view ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php
			if ( '404' === $view ) {
				$this->render_404();
			} else {
				$this->render_form();
				$this->render_list();
				$this->render_tools();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Add/edit form.
	 *
	 * @return void
	 */
	private function render_form() {
		$editing = absint( self::query_arg( 'edit' ) );
		$row     = $editing ? Store::get( $editing ) : null;
		$source  = $row ? $row['source'] : self::address_arg( 'source' );
		$target  = $row ? $row['target'] : self::address_arg( 'target' );
		$status  = $row ? (int) $row['status'] : 301;
		$regex   = $row && 'regex' === $row['match_type'];
		$advanced = Preferences::is_advanced() || $regex || 307 === $status;

		$types = array(
			301 => array( __( 'Permanent (301)', 'seo-fixer' ), __( 'The page moved for good. Search engines pass its ranking to the new address. Use this in almost every case.', 'seo-fixer' ) ),
			302 => array( __( 'Temporary (302)', 'seo-fixer' ), __( 'The page is away for a while, for example during a sale. Search engines keep the old address.', 'seo-fixer' ) ),
			410 => array( __( 'Gone (410)', 'seo-fixer' ), __( 'The page was removed on purpose and has no replacement. Search engines drop it faster than a normal “not found”.', 'seo-fixer' ) ),
		);
		if ( $advanced ) {
			$types[307] = array( __( 'Temporary, keep method (307)', 'seo-fixer' ), __( 'Like 302, but forms keep posting to the new address. Rarely needed.', 'seo-fixer' ) );
		}
		?>
		<section class="seofx-card" id="seofx-redirect-form" aria-labelledby="seofx-redirect-form-title">
			<header class="seofx-card__header">
				<h2 class="seofx-card__title" id="seofx-redirect-form-title"><?php echo $row ? esc_html__( 'Edit redirect', 'seo-fixer' ) : esc_html__( 'Add a redirect', 'seo-fixer' ); ?></h2>
				<p class="seofx-card__desc"><?php esc_html_e( 'When someone opens the old address, they are sent to the new one automatically.', 'seo-fixer' ); ?></p>
			</header>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-seofx-redirect-form>
				<input type="hidden" name="action" value="seofx_redirect_save" />
				<input type="hidden" name="id" value="<?php echo esc_attr( (string) $editing ); ?>" />
				<?php wp_nonce_field( 'seofx_redirect_save' ); ?>

				<div class="seofx-redirect-form__grid">
					<div class="seofx-mini-field">
						<label for="seofx-redirect-source"><?php esc_html_e( 'From (old address)', 'seo-fixer' ); ?></label>
						<span class="seofx-prefix-input">
							<span class="seofx-prefix-input__prefix" aria-hidden="true"><?php echo esc_html( untrailingslashit( home_url() ) ); ?></span>
							<input class="seofx-input" type="text" id="seofx-redirect-source" name="source" value="<?php echo esc_attr( $source ); ?>" placeholder="/old-page/" required />
						</span>
					</div>
					<div class="seofx-mini-field" data-seofx-redirect-target>
						<label for="seofx-redirect-target"><?php esc_html_e( 'To (new address)', 'seo-fixer' ); ?></label>
						<input class="seofx-input" type="text" id="seofx-redirect-target" name="target" value="<?php echo esc_attr( $target ); ?>" placeholder="<?php esc_attr_e( '/new-page/ or https://other-site.com/page', 'seo-fixer' ); ?>" />
					</div>
				</div>

				<fieldset class="seofx-redirect-types">
					<legend class="seofx-field__label"><?php esc_html_e( 'Type', 'seo-fixer' ); ?></legend>
					<div class="seofx-radio-cards">
						<?php foreach ( $types as $code => $type ) : ?>
							<label class="seofx-radio-card">
								<input type="radio" name="status" value="<?php echo esc_attr( (string) $code ); ?>" <?php checked( $status, $code ); ?> />
								<span class="seofx-radio-card__text">
									<span class="seofx-radio-card__title"><?php echo esc_html( $type[0] ); ?></span>
									<span class="seofx-radio-card__desc"><?php echo esc_html( $type[1] ); ?></span>
								</span>
							</label>
						<?php endforeach; ?>
					</div>
				</fieldset>

				<?php if ( $advanced ) : ?>
					<label class="seofx-check">
						<input type="checkbox" name="regex" value="1" <?php checked( $regex ); ?> />
						<?php esc_html_e( 'Treat “From” as a regular expression', 'seo-fixer' ); ?>
						<?php UI::badge( __( 'Advanced', 'seo-fixer' ), 'neutral' ); ?>
					</label>
					<p class="seofx-field__help"><?php esc_html_e( 'For example ^/blog/(.*)$ with “To” /news/$1 moves a whole section. Addresses are compared in lower case.', 'seo-fixer' ); ?></p>
				<?php endif; ?>

				<div class="seofx-form-actions">
					<button type="submit" class="seofx-btn seofx-btn--primary"><?php echo $row ? esc_html__( 'Save redirect', 'seo-fixer' ) : esc_html__( 'Add redirect', 'seo-fixer' ); ?></button>
					<?php if ( $row ) : ?>
						<a class="seofx-btn seofx-btn--ghost" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Cancel', 'seo-fixer' ); ?></a>
					<?php endif; ?>
				</div>
			</form>
		</section>
		<?php
	}

	/**
	 * Redirect list with search and paging.
	 *
	 * @return void
	 */
	private function render_list() {
		$search = self::query_arg( 's' );
		$page   = max( 1, absint( self::query_arg( 'paged' ) ) );
		$result = Store::query( $search, $page, self::PER_PAGE );
		$types  = array(
			301 => array( __( 'Permanent', 'seo-fixer' ), 'good' ),
			302 => array( __( 'Temporary', 'seo-fixer' ), 'info' ),
			307 => array( __( 'Temporary (307)', 'seo-fixer' ), 'info' ),
			410 => array( __( 'Gone', 'seo-fixer' ), 'warn' ),
		);
		$origins = array(
			'manual' => __( 'Added by you', 'seo-fixer' ),
			'slug'   => __( 'Address changed', 'seo-fixer' ),
			'import' => __( 'Imported', 'seo-fixer' ),
		);
		?>
		<section class="seofx-card" aria-labelledby="seofx-redirect-list-title">
			<header class="seofx-card__header seofx-card__header--row">
				<div>
					<h2 class="seofx-card__title" id="seofx-redirect-list-title"><?php esc_html_e( 'Your redirects', 'seo-fixer' ); ?></h2>
					<p class="seofx-card__desc"><?php esc_html_e( 'Redirects for pages whose address you changed are added here automatically.', 'seo-fixer' ); ?></p>
				</div>
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="seofx-search" role="search">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
					<label class="screen-reader-text" for="seofx-redirect-search"><?php esc_html_e( 'Search redirects', 'seo-fixer' ); ?></label>
					<input class="seofx-input" type="search" id="seofx-redirect-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search redirects', 'seo-fixer' ); ?>" />
					<button type="submit" class="seofx-btn seofx-btn--secondary seofx-btn--sm"><?php UI::icon( 'search', 16 ); ?><span class="screen-reader-text"><?php esc_html_e( 'Search', 'seo-fixer' ); ?></span></button>
				</form>
			</header>

			<?php if ( array() === $result['rows'] ) : ?>
				<p class="seofx-empty"><?php echo '' !== $search ? esc_html__( 'No redirects match your search.', 'seo-fixer' ) : esc_html__( 'No redirects yet. Add one above, or they will appear here when you change a page’s address.', 'seo-fixer' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="seofx_redirect_rows" />
					<?php wp_nonce_field( 'seofx_redirect_rows' ); ?>
					<div class="seofx-table-wrap">
						<table class="seofx-table seofx-table--list">
							<thead>
								<tr>
									<td class="seofx-table__check"><input type="checkbox" data-seofx-check-all aria-label="<?php esc_attr_e( 'Select all', 'seo-fixer' ); ?>" /></td>
									<th scope="col"><?php esc_html_e( 'From', 'seo-fixer' ); ?></th>
									<th scope="col"><?php esc_html_e( 'To', 'seo-fixer' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Type', 'seo-fixer' ); ?></th>
									<th scope="col" class="seofx-num"><?php esc_html_e( 'Used', 'seo-fixer' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Last used', 'seo-fixer' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Actions', 'seo-fixer' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php
								foreach ( $result['rows'] as $row ) :
									$id     = (int) $row['id'];
									$type   = isset( $types[ (int) $row['status'] ] ) ? $types[ (int) $row['status'] ] : $types[301];
									$active = 1 === (int) $row['enabled'];
									?>
									<tr class="<?php echo $active ? '' : 'is-disabled'; ?>">
										<td class="seofx-table__check"><input type="checkbox" name="ids[]" value="<?php echo esc_attr( (string) $id ); ?>" aria-label="<?php esc_attr_e( 'Select this redirect', 'seo-fixer' ); ?>" /></td>
										<td>
											<code class="seofx-path"><?php echo esc_html( $row['source'] ); ?></code>
											<?php
											if ( 'regex' === $row['match_type'] ) {
												UI::badge( __( 'Pattern', 'seo-fixer' ), 'neutral' );
											}
											if ( isset( $origins[ $row['origin'] ] ) && 'manual' !== $row['origin'] ) {
												UI::badge( $origins[ $row['origin'] ], 'neutral' );
											}
											if ( ! $active ) {
												UI::badge( __( 'Off', 'seo-fixer' ), 'warn' );
											}
											?>
										</td>
										<td class="seofx-table__target">
											<?php if ( 410 === (int) $row['status'] ) : ?>
												<span class="seofx-muted"><?php esc_html_e( '— removed for good', 'seo-fixer' ); ?></span>
											<?php else : ?>
												<a href="<?php echo esc_url( Paths::absolute( $row['target'] ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $row['target'] ); ?></a>
											<?php endif; ?>
										</td>
										<td><?php UI::badge( $type[0], $type[1] ); ?></td>
										<td class="seofx-num"><?php echo esc_html( number_format_i18n( (int) $row['hits'] ) ); ?></td>
										<td><?php echo esc_html( self::ago( $row['last_hit'] ) ); ?></td>
										<td class="seofx-row-actions">
											<a class="seofx-btn seofx-btn--ghost seofx-btn--sm" href="<?php echo esc_url( self::url( array( 'edit' => $id ) ) . '#seofx-redirect-form' ); ?>"><?php esc_html_e( 'Edit', 'seo-fixer' ); ?></a>
											<button type="submit" class="seofx-btn seofx-btn--ghost seofx-btn--sm" name="row_action" value="<?php echo esc_attr( ( $active ? 'disable-' : 'enable-' ) . $id ); ?>"><?php echo $active ? esc_html__( 'Turn off', 'seo-fixer' ) : esc_html__( 'Turn on', 'seo-fixer' ); ?></button>
											<button type="submit" class="seofx-btn seofx-btn--ghost seofx-btn--sm seofx-danger-text" name="row_action" value="<?php echo esc_attr( 'delete-' . $id ); ?>"><?php esc_html_e( 'Delete', 'seo-fixer' ); ?></button>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<div class="seofx-table-footer">
						<button type="submit" class="seofx-btn seofx-btn--secondary seofx-btn--sm" name="row_action" value="delete-selected"><?php esc_html_e( 'Delete selected', 'seo-fixer' ); ?></button>
						<?php self::pagination( $page, $result['total'], array( 's' => $search ) ); ?>
					</div>
				</form>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Import/export card.
	 *
	 * @return void
	 */
	private function render_tools() {
		$export = wp_nonce_url( admin_url( 'admin-post.php?action=seofx_redirect_export' ), 'seofx_redirect_export' );
		?>
		<section class="seofx-card" aria-labelledby="seofx-redirect-tools-title">
			<header class="seofx-card__header">
				<h2 class="seofx-card__title" id="seofx-redirect-tools-title"><?php esc_html_e( 'Import and export', 'seo-fixer' ); ?></h2>
				<p class="seofx-card__desc"><?php esc_html_e( 'Move redirects between sites with a spreadsheet file (CSV). Columns: from, to, type (301, 302, 307 or 410).', 'seo-fixer' ); ?></p>
			</header>
			<div class="seofx-redirect-tools">
				<a class="seofx-btn seofx-btn--secondary" href="<?php echo esc_url( $export ); ?>"><?php esc_html_e( 'Download all redirects (CSV)', 'seo-fixer' ); ?></a>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="seofx-import">
					<input type="hidden" name="action" value="seofx_redirect_import" />
					<?php wp_nonce_field( 'seofx_redirect_import' ); ?>
					<label class="screen-reader-text" for="seofx-import-file"><?php esc_html_e( 'CSV file to import', 'seo-fixer' ); ?></label>
					<input type="file" id="seofx-import-file" name="seofx_csv" accept=".csv,text/csv" required />
					<button type="submit" class="seofx-btn seofx-btn--secondary"><?php esc_html_e( 'Import', 'seo-fixer' ); ?></button>
				</form>
			</div>
		</section>
		<?php
	}

	/**
	 * "Not found" log.
	 *
	 * @return void
	 */
	private function render_404() {
		$page   = max( 1, absint( self::query_arg( 'paged' ) ) );
		$result = Log_404::query( $page, self::PER_PAGE );

		if ( ! Options::get( 'log_404' ) ) {
			UI::callout( __( 'The “not found” log is turned off, so new missing pages are not recorded.', 'seo-fixer' ), 'warn', '', admin_url( 'admin.php?page=' . Admin::SLUG . '-settings&tab=redirects' ), __( 'Turn it on', 'seo-fixer' ) );
		}
		?>
		<section class="seofx-card" aria-labelledby="seofx-404-title">
			<header class="seofx-card__header seofx-card__header--row">
				<div>
					<h2 class="seofx-card__title" id="seofx-404-title"><?php esc_html_e( 'Addresses that weren’t found', 'seo-fixer' ); ?></h2>
					<p class="seofx-card__desc"><?php esc_html_e( 'Visitors or search engines tried these addresses and got “Page not found”. Redirect the ones that should lead somewhere. Only the address and the linking page are stored, never visitors’ IP addresses.', 'seo-fixer' ); ?></p>
				</div>
				<?php if ( array() !== $result['rows'] ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="seofx_404_rows" />
						<?php wp_nonce_field( 'seofx_404_rows' ); ?>
						<button type="submit" class="seofx-btn seofx-btn--secondary seofx-btn--sm" name="row_action" value="clear" data-seofx-confirm="<?php esc_attr_e( 'Empty the whole log?', 'seo-fixer' ); ?>"><?php esc_html_e( 'Clear the log', 'seo-fixer' ); ?></button>
					</form>
				<?php endif; ?>
			</header>

			<?php if ( array() === $result['rows'] ) : ?>
				<p class="seofx-empty"><?php esc_html_e( 'Nothing here yet. Missing pages will be listed as soon as someone tries one.', 'seo-fixer' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="seofx_404_rows" />
					<?php wp_nonce_field( 'seofx_404_rows' ); ?>
					<div class="seofx-table-wrap">
						<table class="seofx-table seofx-table--list">
							<thead>
								<tr>
									<td class="seofx-table__check"><input type="checkbox" data-seofx-check-all aria-label="<?php esc_attr_e( 'Select all', 'seo-fixer' ); ?>" /></td>
									<th scope="col"><?php esc_html_e( 'Address', 'seo-fixer' ); ?></th>
									<th scope="col" class="seofx-num"><?php esc_html_e( 'Tries', 'seo-fixer' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Last tried', 'seo-fixer' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Linked from', 'seo-fixer' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Actions', 'seo-fixer' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $result['rows'] as $row ) : ?>
									<tr>
										<td class="seofx-table__check"><input type="checkbox" name="ids[]" value="<?php echo esc_attr( (string) $row['id'] ); ?>" aria-label="<?php esc_attr_e( 'Select this address', 'seo-fixer' ); ?>" /></td>
										<td><code class="seofx-path"><?php echo esc_html( $row['url'] ); ?></code></td>
										<td class="seofx-num"><?php echo esc_html( number_format_i18n( (int) $row['hits'] ) ); ?></td>
										<td><?php echo esc_html( self::ago( $row['last_seen'] ) ); ?></td>
										<td class="seofx-table__target">
											<?php if ( '' !== $row['referrer'] ) : ?>
												<a href="<?php echo esc_url( $row['referrer'] ); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php echo esc_html( self::short_url( $row['referrer'] ) ); ?></a>
											<?php else : ?>
												<span class="seofx-muted"><?php esc_html_e( 'Typed or bookmarked', 'seo-fixer' ); ?></span>
											<?php endif; ?>
										</td>
										<td class="seofx-row-actions">
											<a class="seofx-btn seofx-btn--secondary seofx-btn--sm" href="<?php echo esc_url( self::url( array( 'source' => rawurlencode( $row['url'] ) ) ) . '#seofx-redirect-form' ); ?>"><?php esc_html_e( 'Create redirect', 'seo-fixer' ); ?></a>
											<button type="submit" class="seofx-btn seofx-btn--ghost seofx-btn--sm" name="row_action" value="<?php echo esc_attr( 'delete-' . (int) $row['id'] ); ?>"><?php esc_html_e( 'Remove', 'seo-fixer' ); ?></button>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<div class="seofx-table-footer">
						<button type="submit" class="seofx-btn seofx-btn--secondary seofx-btn--sm" name="row_action" value="delete-selected"><?php esc_html_e( 'Remove selected', 'seo-fixer' ); ?></button>
						<?php self::pagination( $page, $result['total'], array( 'view' => '404' ) ); ?>
					</div>
				</form>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * "3 hours ago" from a GMT datetime ("Never" when empty).
	 *
	 * @param string|null $gmt GMT datetime.
	 * @return string
	 */
	private static function ago( $gmt ) {
		$time = $gmt ? strtotime( $gmt . ' UTC' ) : 0;
		if ( ! $time ) {
			return __( 'Never', 'seo-fixer' );
		}
		/* translators: %s: time span, e.g. "3 hours". */
		return sprintf( __( '%s ago', 'seo-fixer' ), human_time_diff( $time, time() ) );
	}

	/**
	 * Host + path of a URL, shortened.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function short_url( $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$text = preg_replace( '/^www\./', '', $host ) . $path;
		return mb_strlen( $text ) > 60 ? mb_substr( $text, 0, 57 ) . '…' : $text;
	}

	/**
	 * Previous/next paging.
	 *
	 * @param int   $page  Current page.
	 * @param int   $total Total rows.
	 * @param array $args  Query args to keep.
	 * @return void
	 */
	private static function pagination( $page, $total, array $args ) {
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages < 2 ) {
			return;
		}
		$args = array_filter( $args, 'strlen' );
		?>
		<nav class="seofx-pagination" aria-label="<?php esc_attr_e( 'Pages', 'seo-fixer' ); ?>">
			<?php if ( $page > 1 ) : ?>
				<a class="seofx-btn seofx-btn--ghost seofx-btn--sm" href="<?php echo esc_url( self::url( $args + array( 'paged' => $page - 1 ) ) ); ?>"><?php esc_html_e( 'Previous', 'seo-fixer' ); ?></a>
			<?php endif; ?>
			<span>
				<?php
				/* translators: 1: current page, 2: number of pages. */
				echo esc_html( sprintf( __( 'Page %1$s of %2$s', 'seo-fixer' ), number_format_i18n( $page ), number_format_i18n( $pages ) ) );
				?>
			</span>
			<?php if ( $page < $pages ) : ?>
				<a class="seofx-btn seofx-btn--ghost seofx-btn--sm" href="<?php echo esc_url( self::url( $args + array( 'paged' => $page + 1 ) ) ); ?>"><?php esc_html_e( 'Next', 'seo-fixer' ); ?></a>
			<?php endif; ?>
		</nav>
		<?php
	}

	/**
	 * Saves a redirect from the form.
	 *
	 * @return void
	 */
	public function handle_save() {
		Security::require_manage();
		check_admin_referer( 'seofx_redirect_save' );

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitize::address() keeps %XX sequences that sanitize_text_field() would delete.
		$source = isset( $_POST['source'] ) ? Sanitize::address( wp_unslash( $_POST['source'] ) ) : '';
		$target = isset( $_POST['target'] ) ? Sanitize::address( wp_unslash( $_POST['target'] ) ) : '';
		// phpcs:enable
		$status = isset( $_POST['status'] ) ? absint( $_POST['status'] ) : 301;
		$regex  = ! empty( $_POST['regex'] );
		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		$checked = Store::validate( $source, $target, $status, $regex ? 'regex' : 'exact' );
		if ( '' !== $checked['error'] ) {
			UI::flash( $checked['error'], 'bad' );
			wp_safe_redirect(
				self::url(
					array_filter(
						array(
							'edit'   => $id,
							'source' => rawurlencode( $source ),
							'target' => rawurlencode( $target ),
						)
					)
				) . '#seofx-redirect-form'
			);
			exit;
		}

		Store::save( $checked['data'], $id );
		if ( 'exact' === $checked['data']['match_type'] ) {
			Log_404::forget( $checked['data']['source'] );
		}

		UI::flash(
			410 === $checked['data']['status']
				/* translators: %s: old address. */
				? sprintf( __( 'Saved: %s now answers “gone”.', 'seo-fixer' ), $checked['data']['source'] )
				/* translators: 1: old address, 2: new address. */
				: sprintf( __( 'Saved: %1$s now goes to %2$s.', 'seo-fixer' ), $checked['data']['source'], $checked['data']['target'] )
		);
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Reads row_action and the selected ids.
	 *
	 * @return array{0: string, 1: int[]} Action and ids.
	 */
	private static function row_request() {
		$action = isset( $_POST['row_action'] ) ? sanitize_key( wp_unslash( $_POST['row_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers verify the nonce first.
		if ( preg_match( '/^(delete|disable|enable)-(\d+)$/', $action, $match ) ) {
			return array( $match[1], array( (int) $match[2] ) );
		}
		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers verify the nonce first.
		return array( $action, $ids );
	}

	/**
	 * Row and bulk actions on redirects.
	 *
	 * @return void
	 */
	public function handle_rows() {
		Security::require_manage();
		check_admin_referer( 'seofx_redirect_rows' );

		list( $action, $ids ) = self::row_request();
		if ( array() === $ids ) {
			UI::flash( __( 'Select at least one redirect first.', 'seo-fixer' ), 'warn' );
		} elseif ( 'delete' === $action || 'delete-selected' === $action ) {
			Store::delete( $ids );
			/* translators: %d: number of redirects. */
			UI::flash( sprintf( _n( '%d redirect deleted.', '%d redirects deleted.', count( $ids ), 'seo-fixer' ), count( $ids ) ) );
		} elseif ( 'disable' === $action || 'enable' === $action ) {
			Store::set_enabled( $ids, 'enable' === $action );
			UI::flash( 'enable' === $action ? __( 'Redirect turned on.', 'seo-fixer' ) : __( 'Redirect turned off.', 'seo-fixer' ) );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : self::url() );
		exit;
	}

	/**
	 * Row and bulk actions on the 404 log.
	 *
	 * @return void
	 */
	public function handle_404_rows() {
		Security::require_manage();
		check_admin_referer( 'seofx_404_rows' );

		list( $action, $ids ) = self::row_request();
		if ( 'clear' === $action ) {
			Log_404::clear();
			UI::flash( __( 'The log is empty.', 'seo-fixer' ) );
		} elseif ( array() !== $ids ) {
			Log_404::delete( $ids );
			/* translators: %d: number of entries. */
			UI::flash( sprintf( _n( '%d address removed from the log.', '%d addresses removed from the log.', count( $ids ), 'seo-fixer' ), count( $ids ) ) );
		}

		wp_safe_redirect( self::url( array( 'view' => '404' ) ) );
		exit;
	}

	/**
	 * Downloads all redirects as CSV.
	 *
	 * @return void
	 */
	public function handle_export() {
		Security::require_manage();
		check_admin_referer( 'seofx_redirect_export' );

		$csv = UI::csv_line( array( 'from', 'to', 'type', 'match', 'used' ) );
		foreach ( Store::all() as $row ) {
			$csv .= UI::csv_line( array( $row['source'], $row['target'], $row['status'], $row['match_type'], $row['hits'] ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=seo-fixer-redirects-' . gmdate( 'Y-m-d' ) . '.csv' );
		// UTF-8 byte order mark so spreadsheet apps read accents correctly.
		echo "\xEF\xBB\xBF" . $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download; every cell is escaped by UI::csv_line().
		exit;
	}

	/**
	 * Imports redirects from an uploaded CSV file.
	 *
	 * @return void
	 */
	public function handle_import() {
		Security::require_manage();
		check_admin_referer( 'seofx_redirect_import' );

		$file = isset( $_FILES['seofx_csv'] ) && is_array( $_FILES['seofx_csv'] ) ? $_FILES['seofx_csv'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each part is validated below; the file is only read, never stored.
		$tmp  = isset( $file['tmp_name'] ) && is_string( $file['tmp_name'] ) ? $file['tmp_name'] : '';
		$name = isset( $file['name'] ) && is_string( $file['name'] ) ? sanitize_file_name( $file['name'] ) : '';

		$error = '';
		if ( empty( $file ) || ! isset( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $tmp ) ) {
			$error = __( 'The file could not be uploaded. Please try again.', 'seo-fixer' );
		} elseif ( 'csv' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			$error = __( 'Please choose a .csv file.', 'seo-fixer' );
		} elseif ( (int) $file['size'] > 2 * MB_IN_BYTES ) {
			$error = __( 'The file is too large (2 MB at most).', 'seo-fixer' );
		}

		$content = '';
		if ( '' === $error ) {
			// The upload sits in PHP's own temp folder, so it is read directly. WP_Filesystem()
			// would ask for FTP details on hosts that don't allow direct writes.
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			$reader  = new \WP_Filesystem_Direct( null );
			$content = (string) $reader->get_contents( $tmp );
			if ( '' === $content ) {
				$error = __( 'The file is empty or could not be read.', 'seo-fixer' );
			}
		}

		if ( '' !== $error ) {
			UI::flash( $error, 'bad' );
			wp_safe_redirect( self::url() );
			exit;
		}

		$content = preg_replace( '/^\xEF\xBB\xBF/', '', $content );
		$lines   = preg_split( '/\r\n|\r|\n/', (string) $content );
		$added   = 0;
		$skipped = 0;

		foreach ( array_slice( (array) $lines, 0, 5001 ) as $index => $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$cells = array_map( 'trim', str_getcsv( $line ) );
			$cells = array_map(
				static function ( $cell ) {
					return ltrim( $cell, "'" );
				},
				$cells
			);
			if ( 0 === $index && in_array( strtolower( $cells[0] ), array( 'from', 'source', 'old', 'url' ), true ) ) {
				continue; // Header row.
			}

			$checked = Store::validate(
				isset( $cells[0] ) ? Sanitize::address( $cells[0] ) : '',
				isset( $cells[1] ) ? Sanitize::address( $cells[1] ) : '',
				isset( $cells[2] ) && '' !== $cells[2] ? (int) $cells[2] : 301,
				isset( $cells[3] ) && 'regex' === strtolower( $cells[3] ) ? 'regex' : 'exact'
			);
			if ( '' !== $checked['error'] ) {
				++$skipped;
				continue;
			}
			Store::save( $checked['data'] + array( 'origin' => 'import' ) );
			++$added;
		}

		/* translators: %d: number imported. */
		$message = sprintf( _n( 'Imported %d redirect.', 'Imported %d redirects.', $added, 'seo-fixer' ), $added );
		if ( $skipped ) {
			/* translators: %d: number of lines skipped. */
			$message .= ' ' . sprintf( _n( '%d line was skipped because it wasn’t valid.', '%d lines were skipped because they weren’t valid.', $skipped, 'seo-fixer' ), $skipped );
		}
		UI::flash( $message, $skipped ? 'warn' : 'good' );
		wp_safe_redirect( self::url() );
		exit;
	}
}
