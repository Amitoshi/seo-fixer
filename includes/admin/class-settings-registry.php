<?php
/**
 * Declarative list of every setting: where it lives, how it looks, how it is cleaned.
 *
 * Each later milestone adds its tabs/fields here; rendering and saving are generic.
 *
 * @package SeoFixer
 */

namespace Seofx\Admin;

use Seofx\Meta\Context;
use Seofx\Meta\Templates;
use Seofx\Meta\Variables;
use Seofx\Options;
use Seofx\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Settings registry.
 *
 * Field keys:
 * - id       Option key (or preference id when storage = user).
 * - type     toggle | radio_cards | segmented | select | text | textarea | url | number.
 * - label    Short label.
 * - help     One plain-English line under the label.
 * - why      "Why it matters" text (optional).
 * - options  value => label (or value => [label, description] for radio_cards).
 * - mode     simple | advanced (advanced fields are hidden in Simple mode).
 * - storage  site | user.
 * - attrs    Extra input attributes (maxlength, min, max, placeholder).
 */
final class Settings_Registry {

	/**
	 * Cached tab list.
	 *
	 * @var array|null
	 */
	private $tabs = null;

	/**
	 * All tabs with their sections and fields.
	 *
	 * @return array
	 */
	public function tabs() {
		if ( null !== $this->tabs ) {
			return $this->tabs;
		}

		$tabs = array(
			'general' => array(
				'label'    => __( 'General', 'seo-fixer' ),
				'sections' => array(
					'site'   => array(
						'title'       => __( 'Your website', 'seo-fixer' ),
						'description' => __( 'Tell us what kind of site this is and we will pick sensible defaults for you.', 'seo-fixer' ),
						'fields'      => array(
							array(
								'id'      => 'site_type',
								'type'    => 'radio_cards',
								'label'   => __( 'Site type', 'seo-fixer' ),
								'help'    => __( 'Choose the one that fits best. You can change it any time.', 'seo-fixer' ),
								'why'     => __( 'A shop, a blog and a local business need different structured data and archive settings. Picking the right type means fewer things to set by hand.', 'seo-fixer' ),
								'options' => array(
									'business'  => array( __( 'Business', 'seo-fixer' ), __( 'Company, service or local business', 'seo-fixer' ) ),
									'blog'      => array( __( 'Blog', 'seo-fixer' ), __( 'Articles, news or a magazine', 'seo-fixer' ) ),
									'shop'      => array( __( 'Online shop', 'seo-fixer' ), __( 'Products you sell online', 'seo-fixer' ) ),
									'portfolio' => array( __( 'Portfolio', 'seo-fixer' ), __( 'Your work, projects or gallery', 'seo-fixer' ) ),
								),
							),
						),
					),
					'output' => array(
						'title'       => __( 'Search engine output', 'seo-fixer' ),
						'description' => __( 'Controls whether SEO Fixer adds its titles and tags to your pages.', 'seo-fixer' ),
						'fields'      => array(
							array(
								'id'      => 'output_mode',
								'type'    => 'segmented',
								'label'   => __( 'SEO Fixer output', 'seo-fixer' ),
								'help'    => __( 'Automatic pauses SEO Fixer while another SEO plugin is active.', 'seo-fixer' ),
								'why'     => __( 'Two SEO plugins running together print two titles and two descriptions on every page, which confuses search engines. Automatic prevents that.', 'seo-fixer' ),
								'options' => array(
									'auto' => __( 'Automatic', 'seo-fixer' ),
									'on'   => __( 'Always on', 'seo-fixer' ),
									'off'  => __( 'Paused', 'seo-fixer' ),
								),
							),
						),
					),
					'data'   => array(
						'title'       => __( 'Data & privacy', 'seo-fixer' ),
						'description' => __( 'SEO Fixer works entirely inside your site. Nothing is sent anywhere unless you turn on a feature that clearly says so.', 'seo-fixer' ),
						'fields'      => array(
							array(
								'id'    => 'keep_data',
								'type'  => 'toggle',
								'label' => __( 'Keep my settings if I delete the plugin', 'seo-fixer' ),
								'help'  => __( 'Leave this on if you might reinstall SEO Fixer later. Deactivating the plugin never removes anything.', 'seo-fixer' ),
								'why'   => __( 'With this off, deleting the plugin removes everything SEO Fixer stored: settings, the SEO titles and descriptions you wrote for pages, redirects, the “not found” log, health results and the change history. Your posts, pages and images stay as they are, including any alt text SEO Fixer added.', 'seo-fixer' ),
							),
						),
					),
				),
			),
			'titles'  => array(
				'label'    => __( 'Titles & descriptions', 'seo-fixer' ),
				'sections' => self::title_sections(),
			),
			'fixes'    => array(
				'label'    => __( 'Automatic fixes', 'seo-fixer' ),
				'sections' => array(
					'autofix' => array(
						'title'       => __( 'Fix new and updated pages as they are saved', 'seo-fixer' ),
						'description' => __( 'Each fix only fills in what is missing: text you wrote yourself is never replaced. Every change is listed under SEO Health → Changes, where you can undo it.', 'seo-fixer' ),
						'fields'      => array(
							array(
								'id'    => 'autofix_description',
								'type'  => 'toggle',
								'label' => __( 'Write missing descriptions', 'seo-fixer' ),
								'help'  => __( 'When a page has no description, or a very short one, SEO Fixer writes one from its first sentences.', 'seo-fixer' ),
								'why'   => __( 'A clear description under your link in Google makes people more likely to click. This makes sure no page is left without one.', 'seo-fixer' ),
							),
							array(
								'id'    => 'autofix_title',
								'type'  => 'toggle',
								'label' => __( 'Shorten titles that are too long', 'seo-fixer' ),
								'help'  => __( 'When the site name makes a title too long for Google, the page title is used on its own.', 'seo-fixer' ),
								'why'   => __( 'Google cuts titles off after about 60 characters, so the end of a long title — often your site name — is lost anyway. Dropping it keeps the important words visible.', 'seo-fixer' ),
							),
							array(
								'id'    => 'autofix_alt',
								'type'  => 'toggle',
								'label' => __( 'Add missing image alt text', 'seo-fixer' ),
								'help'  => __( 'Images without alt text get it from their Media Library details, file name, the heading above them or the page title.', 'seo-fixer' ),
								'why'   => __( 'Alt text helps blind visitors and lets your images appear in Google Images. A sensible description is much better than none, and you can always improve it.', 'seo-fixer' ),
							),
						),
					),
				),
			),
			'indexing' => array(
				'label'    => __( 'Search visibility', 'seo-fixer' ),
				'sections' => self::indexing_sections(),
			),
			'sitemap'  => array(
				'label'    => __( 'Sitemap & robots.txt', 'seo-fixer' ),
				'sections' => self::sitemap_sections(),
			),
			'services' => array(
				'label'    => __( 'Search engines & analytics', 'seo-fixer' ),
				'sections' => self::service_sections(),
			),
			'schema'   => array(
				'label'    => __( 'Schema & social', 'seo-fixer' ),
				'sections' => self::schema_sections(),
			),
			'redirects' => array(
				'label'    => __( 'Redirects', 'seo-fixer' ),
				'sections' => self::redirect_sections(),
			),
			'brand'   => array(
				'label'    => __( 'Your brand', 'seo-fixer' ),
				'sections' => array(
					'identity' => array(
						'title'       => __( 'Who is behind this website?', 'seo-fixer' ),
						'description' => __( 'Search engines use this to connect your website with your business or your name.', 'seo-fixer' ),
						'fields'      => self::identity_fields(),
					),
					'social'   => array(
						'title'       => __( 'Social profiles', 'seo-fixer' ),
						'description' => __( 'Add the profiles you use and leave the rest empty.', 'seo-fixer' ),
						'fields'      => self::social_fields(),
					),
				),
			),
			'local'   => array(
				'label'    => __( 'Local business', 'seo-fixer' ),
				'sections' => array(
					'local' => array(
						'title'       => __( 'Local business details', 'seo-fixer' ),
						'description' => __( 'For businesses that customers visit, or that serve a local area.', 'seo-fixer' ),
						'fields'      => self::local_fields(),
					),
				),
			),
			'view'    => array(
				'label'    => __( 'Your view', 'seo-fixer' ),
				'sections' => array(
					'interface' => array(
						'title'       => __( 'How SEO Fixer looks for you', 'seo-fixer' ),
						'description' => __( 'These choices only affect your own account.', 'seo-fixer' ),
						'fields'      => array(
							array(
								'id'      => 'ui_mode',
								'type'    => 'segmented',
								'storage' => 'user',
								'label'   => __( 'Interface mode', 'seo-fixer' ),
								'help'    => __( 'Simple shows only what matters. Advanced shows every option.', 'seo-fixer' ),
								'why'     => __( 'Most sites never need the advanced options. You can switch at any time from the top of every SEO Fixer page.', 'seo-fixer' ),
								'options' => array(
									'simple'   => __( 'Simple', 'seo-fixer' ),
									'advanced' => __( 'Advanced', 'seo-fixer' ),
								),
							),
							array(
								'id'      => 'admin_theme',
								'type'    => 'segmented',
								'storage' => 'user',
								'label'   => __( 'Appearance', 'seo-fixer' ),
								'help'    => __( 'Light matches the WordPress dashboard. Dark is easier on the eyes at night.', 'seo-fixer' ),
								'options' => array(
									'light' => __( 'Light', 'seo-fixer' ),
									'dark'  => __( 'Dark', 'seo-fixer' ),
									'auto'  => __( 'Match my device', 'seo-fixer' ),
								),
							),
						),
					),
				),
			),
		);

		/**
		 * Filters the settings tabs, sections and fields.
		 *
		 * @param array $tabs Settings definition.
		 */
		$this->tabs = (array) apply_filters( 'seofx_settings_tabs', $tabs );

		return $this->tabs;
	}

	/**
	 * Sections of the "Titles & descriptions" tab.
	 *
	 * Section keys used by Settings_Page besides fields:
	 * - preview   Google preview bound to two fields (title/description/context).
	 * - callout   Text + optional link shown instead of fields.
	 * - variables Show the list of %variables%.
	 *
	 * @return array
	 */
	public static function title_sections() {
		$front_id = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
		$home     = Context::for_home();
		$sections = array();

		$sections['home'] = array(
			'title'       => __( 'Homepage', 'seo-fixer' ),
			'description' => __( 'How your homepage looks in Google. For many people it’s the first thing they see of your site.', 'seo-fixer' ),
			'fields'      => array(),
		);

		if ( $front_id ) {
			$sections['home']['callout'] = array(
				/* translators: %s: title of the page used as the homepage. */
				'text' => sprintf( __( 'Your homepage is the page “%s”. Set its title and description in the SEO Fixer box when you edit that page.', 'seo-fixer' ), get_the_title( $front_id ) ),
				'url'  => (string) get_edit_post_link( $front_id, 'raw' ),
				'link' => __( 'Edit homepage', 'seo-fixer' ),
			);
		} else {
			$sections['home']['preview'] = array(
				'title'       => 'home_title',
				'description' => 'home_description',
			);
			$sections['home']['fields']  = array(
				array(
					'id'       => 'home_title',
					'type'     => 'text',
					'label'    => __( 'Homepage title', 'seo-fixer' ),
					'help'     => __( 'Leave empty to use your site title and tagline.', 'seo-fixer' ),
					'why'      => __( 'Google shows the title as the blue, clickable headline. A clear title that says what you offer gets more clicks.', 'seo-fixer' ),
					'sanitize' => array( 'Seofx\Sanitize', 'template' ),
					'attrs'    => array(
						'maxlength'   => 200,
						'placeholder' => Variables::replace( Templates::default_for( 'home_title' ), $home ),
					),
				),
				array(
					'id'       => 'home_description',
					'type'     => 'textarea',
					'label'    => __( 'Homepage description', 'seo-fixer' ),
					'help'     => __( 'One or two sentences about what you offer. Leave empty to use your tagline.', 'seo-fixer' ),
					'why'      => __( 'Google often shows this text under your title. Without one, Google picks some text from the page, which may not sell your site well.', 'seo-fixer' ),
					'sanitize' => array( 'Seofx\Sanitize', 'template' ),
					'attrs'    => array(
						'rows'        => 3,
						'maxlength'   => 400,
						'placeholder' => Variables::replace( Templates::default_for( 'home_description' ), $home ),
					),
				),
			);
		}

		$sections['separator'] = array(
			'title'       => __( 'Title separator', 'seo-fixer' ),
			'description' => __( 'Used between the page name and your site name in every title.', 'seo-fixer' ),
			'fields'      => array(
				array(
					'id'      => 'title_separator',
					'type'    => 'segmented',
					'label'   => __( 'Separator', 'seo-fixer' ),
					'help'    => __( 'The symbol between the page name and your site name, as in “About – My Bakery”.', 'seo-fixer' ),
					'why'     => __( 'It only changes how titles look. Pick one that is easy to read; search engines treat them all the same.', 'seo-fixer' ),
					'options' => array(
						'-' => '–',
						'—' => '—',
						'|' => '|',
						'·' => '·',
						'•' => '•',
						'/' => '/',
						'»' => '»',
					),
				),
			),
		);

		$content = array();
		foreach ( Templates::post_types() as $name => $type ) {
			$content = array_merge( $content, self::template_pair( 'tpl_' . $name, $type->labels->name ) );
		}
		$sections['content'] = array(
			'title'       => __( 'Posts, pages and other content', 'seo-fixer' ),
			'description' => __( 'Used for anything that has no SEO title or description of its own. Leave a box empty to use the default shown in it.', 'seo-fixer' ),
			'variables'   => true,
			'fields'      => $content,
		);

		$groups = array();
		foreach ( Templates::taxonomies() as $name => $taxonomy ) {
			$groups = array_merge( $groups, self::template_pair( 'tpl_tax_' . $name, $taxonomy->labels->name ) );
		}
		$sections['groups'] = array(
			'title'       => __( 'Categories, tags and other groups', 'seo-fixer' ),
			'description' => __( 'Used for the pages that list everything in a category, tag or similar group.', 'seo-fixer' ),
			'variables'   => true,
			'fields'      => $groups,
		);

		$sections['archives'] = array(
			'title'       => __( 'Other pages', 'seo-fixer' ),
			'description' => __( 'Author pages, date archives, search results and the “page not found” page.', 'seo-fixer' ),
			'variables'   => true,
			'fields'      => array_merge(
				self::template_pair( 'tpl_author', __( 'Author pages', 'seo-fixer' ) ),
				self::template_pair( 'tpl_archive', __( 'Content-type archives', 'seo-fixer' ) ),
				self::template_pair( 'tpl_date', __( 'Date archives', 'seo-fixer' ), false ),
				self::template_pair( 'tpl_search', __( 'Search results', 'seo-fixer' ), false ),
				self::template_pair( 'tpl_404', __( 'Page not found (404)', 'seo-fixer' ), false )
			),
		);

		return $sections;
	}

	/**
	 * Sections of the "Search visibility" tab.
	 *
	 * @return array
	 */
	public static function indexing_sections() {
		$types = array();
		foreach ( Templates::post_types() as $name => $type ) {
			$types[] = array(
				'id'      => 'index_pt_' . $name,
				'type'    => 'toggle',
				'mode'    => 'advanced',
				'default' => true,
				/* translators: %s: content type name, e.g. "Posts". */
				'label'   => sprintf( __( 'Show %s in search results', 'seo-fixer' ), $type->labels->name ),
				'help'    => __( 'You can still hide or show single items in the SEO Fixer box when editing them.', 'seo-fixer' ),
			);
		}
		foreach ( Templates::taxonomies() as $name => $taxonomy ) {
			$types[] = array(
				'id'      => 'index_tax_' . $name,
				'type'    => 'toggle',
				'mode'    => 'advanced',
				'default' => true,
				/* translators: %s: taxonomy name, e.g. "Categories". */
				'label'   => sprintf( __( 'Show %s pages in search results', 'seo-fixer' ), $taxonomy->labels->name ),
				'help'    => __( 'These pages list everything in one group. Hide them if they are thin or unused.', 'seo-fixer' ),
			);
		}

		return array(
			'hidden' => array(
				'title'       => __( 'Pages kept out of search results', 'seo-fixer' ),
				'description' => __( 'These pages repeat content from your real pages. Keeping them out of Google helps your important pages stand out.', 'seo-fixer' ),
				'fields'      => array(
					array(
						'id'    => 'noindex_search',
						'type'  => 'toggle',
						'label' => __( 'Hide your internal search result pages', 'seo-fixer' ),
						'help'  => __( 'Recommended. Google asks sites not to let their search results appear in Google.', 'seo-fixer' ),
						'why'   => __( 'Search result pages are endless and change all the time. Google treats them as low-quality pages, and they can be abused by spammers to create pages on your site.', 'seo-fixer' ),
					),
					array(
						'id'    => 'noindex_date_archives',
						'type'  => 'toggle',
						'label' => __( 'Hide date archives', 'seo-fixer' ),
						'help'  => __( 'Pages like “All posts from March 2026”. Recommended for most sites.', 'seo-fixer' ),
					),
					array(
						'id'    => 'noindex_author_archives',
						'type'  => 'toggle',
						'label' => __( 'Hide author pages', 'seo-fixer' ),
						'help'  => __( 'Recommended when one person writes everything; keep them on blogs with several writers.', 'seo-fixer' ),
					),
					array(
						'id'    => 'attachment_redirect',
						'type'  => 'toggle',
						'label' => __( 'Send visitors from image pages to the post', 'seo-fixer' ),
						'help'  => __( 'WordPress can create a separate, nearly empty page for every image you upload. This sends visitors and Google to the post instead.', 'seo-fixer' ),
						'why'   => __( 'Hundreds of empty image pages make a site look thin to Google. Redirecting them sends their value to the real post.', 'seo-fixer' ),
					),
				),
			),
			'types'  => array(
				'title'       => __( 'Content types and groups', 'seo-fixer' ),
				'description' => __( 'Turn a switch off to hide a whole type from search results and from your sitemap.', 'seo-fixer' ),
				'fields'      => $types,
			),
		);
	}

	/**
	 * Sections of the "Sitemap & robots.txt" tab.
	 *
	 * @return array
	 */
	public static function sitemap_sections() {
		return array(
			'sitemap' => array(
				'title'       => __( 'XML sitemap', 'seo-fixer' ),
				'description' => __( 'A list of all your pages that helps Google and Bing find them quickly. It updates itself whenever you publish.', 'seo-fixer' ),
				'render'      => array( Indexing_Panels::class, 'sitemap' ),
				'fields'      => array(
					array(
						'id'    => 'sitemap_enabled',
						'type'  => 'toggle',
						'label' => __( 'Create an XML sitemap', 'seo-fixer' ),
						'help'  => __( 'Replaces WordPress’s basic sitemap with a better one: hidden pages and user lists are left out, and images are included.', 'seo-fixer' ),
						'why'   => __( 'A sitemap doesn’t make pages rank, but it helps search engines discover new and updated pages sooner, especially on new or large sites.', 'seo-fixer' ),
					),
					array(
						'id'    => 'sitemap_images',
						'type'  => 'toggle',
						'mode'  => 'advanced',
						'label' => __( 'Include images', 'seo-fixer' ),
						'help'  => __( 'Lists the featured image and images in each post so Google Images can find them.', 'seo-fixer' ),
					),
				),
			),
			'robots'  => array(
				'title'       => __( 'robots.txt', 'seo-fixer' ),
				'description' => __( 'A small file that tells search engines which parts of your site they may visit. WordPress creates it for you; SEO Fixer adds your sitemap to it.', 'seo-fixer' ),
				'render'      => array( Indexing_Panels::class, 'robots' ),
				'fields'      => array(
					array(
						'id'       => 'robots_custom',
						'type'     => 'textarea',
						'mode'     => 'advanced',
						'label'    => __( 'Custom rules', 'seo-fixer' ),
						'help'     => __( 'Replaces WordPress’s default rules. Leave empty to keep the defaults. The sitemap line is added automatically.', 'seo-fixer' ),
						'why'      => __( 'One wrong line can hide your whole site from Google. SEO Fixer checks your rules and refuses changes that would block everything.', 'seo-fixer' ),
						'sanitize' => array( 'Seofx\Sanitize', 'robots' ),
						'validate' => array( Indexing_Panels::class, 'validate_robots' ),
						'attrs'    => array(
							'rows'        => 8,
							'placeholder' => \Seofx\Indexing\Robots::wordpress_default(),
						),
					),
				),
			),
		);
	}

	/**
	 * Sections of the "Search engines & analytics" tab.
	 *
	 * @return array
	 */
	public static function service_sections() {
		$engines = array(
			'verify_google'    => array( 'Google Search Console', __( 'Paste the “HTML tag” code from Google Search Console.', 'seo-fixer' ) ),
			'verify_bing'      => array( 'Bing Webmaster Tools', __( 'Paste the “HTML Meta Tag” code from Bing Webmaster Tools.', 'seo-fixer' ) ),
			'verify_yandex'    => array( 'Yandex Webmaster', __( 'Paste the “Meta tag” code from Yandex Webmaster.', 'seo-fixer' ) ),
			'verify_pinterest' => array( 'Pinterest', __( 'Paste the “HTML tag” code from Pinterest’s “Claimed accounts” settings.', 'seo-fixer' ) ),
		);

		$verify = array();
		foreach ( $engines as $id => $engine ) {
			$verify[] = array(
				'id'       => $id,
				'type'     => 'text',
				'label'    => $engine[0],
				'help'     => $engine[1],
				'why'      => 'verify_google' === $id ? __( 'Search Console shows which searches bring people to your site, which pages Google has found and any problems it sees. Google only shows this to the site’s owner, so you prove it once with this code.', 'seo-fixer' ) : '',
				'sanitize' => array( 'Seofx\Sanitize', 'verification' ),
				'validate' => array( Services_Panels::class, 'validate_verification' ),
				'attrs'    => array(
					'maxlength'   => 300,
					'placeholder' => '<meta name="…" content="…" />',
				),
			);
		}

		return array(
			'verify'    => array(
				'title'       => __( 'Prove this site is yours', 'seo-fixer' ),
				'description' => __( 'Google, Bing and others only show you how your site is doing after you prove you own it. Paste the code they give you and SEO Fixer adds it to your homepage.', 'seo-fixer' ),
				'render'      => array( Services_Panels::class, 'verification' ),
				'fields'      => $verify,
			),
			'analytics' => array(
				'title'       => __( 'Google Analytics', 'seo-fixer' ),
				'description' => __( 'See how many people visit, which pages they read and where they come from. Paste your ID and SEO Fixer adds Google’s code to every page; you don’t need another plugin.', 'seo-fixer' ),
				'render'      => array( Services_Panels::class, 'analytics' ),
				'fields'      => array(
					array(
						'id'       => 'analytics_id',
						'type'     => 'text',
						'label'    => __( 'Google Analytics or Tag Manager ID', 'seo-fixer' ),
						'help'     => __( 'Starts with “G-” for Google Analytics 4 or “GTM-” for Google Tag Manager. Leave empty to add no tracking code.', 'seo-fixer' ),
						'why'      => __( 'Visitor numbers show which pages work and which need attention, and whether your SEO changes bring more people. Nothing is tracked until you enter an ID.', 'seo-fixer' ),
						'sanitize' => array( 'Seofx\Sanitize', 'analytics_id' ),
						'validate' => array( Services_Panels::class, 'validate_analytics' ),
						'attrs'    => array(
							'maxlength'   => 2000,
							'placeholder' => 'G-ABC123XYZ9',
						),
					),
					array(
						'id'    => 'analytics_skip_editors',
						'type'  => 'toggle',
						'label' => __( 'Don’t count my own visits', 'seo-fixer' ),
						'help'  => __( 'Leaves out visits from logged-in administrators, editors and authors.', 'seo-fixer' ),
						'why'   => __( 'You visit your own site far more often than anyone else, especially while editing. Counting those visits makes your numbers look better than they are.', 'seo-fixer' ),
					),
					array(
						'id'    => 'analytics_consent',
						'type'  => 'toggle',
						'mode'  => 'advanced',
						'label' => __( 'Wait for consent (Google Consent Mode)', 'seo-fixer' ),
						'help'  => __( 'No cookies are set until your cookie banner reports that the visitor agreed. Only turn this on if your cookie consent plugin supports Google Consent Mode.', 'seo-fixer' ),
						'why'   => __( 'Privacy laws such as the GDPR require consent before analytics cookies are used. With Consent Mode, Google still receives anonymous, cookie-free signals from visitors who decline.', 'seo-fixer' ),
					),
				),
			),
			'indexnow'  => array(
				'title'       => __( 'IndexNow: instant updates for Bing and others', 'seo-fixer' ),
				'description' => __( 'When you publish, update or delete a page, SEO Fixer tells Bing, Yandex and other IndexNow search engines straight away, so they can update their results within hours instead of days.', 'seo-fixer' ),
				'render'      => array( Services_Panels::class, 'indexnow' ),
				'fields'      => array(
					array(
						'id'    => 'indexnow_enabled',
						'type'  => 'toggle',
						'label' => __( 'Tell search engines about changes right away', 'seo-fixer' ),
						'help'  => __( 'Sends the address of each page you publish, update or delete to IndexNow (api.indexnow.org). Google doesn’t use IndexNow; it finds changes through your sitemap.', 'seo-fixer' ),
						'why'   => __( 'Without IndexNow, search engines only notice changes when they next visit, which can take days or weeks. With it, new pages and corrections show up much sooner in Bing, whose results are also used by several other search services.', 'seo-fixer' ),
					),
				),
			),
		);
	}

	/**
	 * Sections of the "Schema & social" tab.
	 *
	 * @return array
	 */
	public static function schema_sections() {
		return array(
			'social'      => array(
				'title'       => __( 'Social sharing', 'seo-fixer' ),
				'description' => __( 'How your pages look when someone shares them on Facebook, LinkedIn, WhatsApp or X.', 'seo-fixer' ),
				'fields'      => array(
					array(
						'id'    => 'og_enabled',
						'type'  => 'toggle',
						'label' => __( 'Add social sharing tags', 'seo-fixer' ),
						'help'  => __( 'Adds Open Graph and X (Twitter) card tags with the title, description and image of each page.', 'seo-fixer' ),
						'why'   => __( 'Without these tags, apps guess which text and image to show, often picking a logo or a random picture. A clear image gets more clicks.', 'seo-fixer' ),
					),
					array(
						'id'    => 'social_image',
						'type'  => 'image',
						'label' => __( 'Default sharing image', 'seo-fixer' ),
						'help'  => __( 'Used when a page has no featured image. 1200 × 630 pixels works best.', 'seo-fixer' ),
						'why'   => __( 'Pages shared without an image get much less attention. Google also uses this image as a thumbnail when it fits.', 'seo-fixer' ),
						'attrs' => array(
							'min_size'      => 200,
							'small_message' => __( 'This image is smaller than 200 × 200 pixels, so Facebook and others may not show it. Use a larger image, ideally 1200 × 630.', 'seo-fixer' ),
						),
					),
				),
			),
			'schema'      => array(
				'title'       => __( 'Structured data (schema)', 'seo-fixer' ),
				'description' => __( 'Hidden information that helps search engines understand your pages, and can earn extra details in results such as your logo or breadcrumbs.', 'seo-fixer' ),
				'render'      => array( Schema_Panels::class, 'overview' ),
				'fields'      => array(
					array(
						'id'    => 'schema_enabled',
						'type'  => 'toggle',
						'label' => __( 'Add structured data', 'seo-fixer' ),
						'help'  => __( 'Describes your business, your website and every page in a format Google reads (JSON-LD).', 'seo-fixer' ),
						'why'   => __( 'Structured data is how Google learns your logo, your address and opening hours, who wrote an article, and where a page sits in your site.', 'seo-fixer' ),
					),
					array(
						'id'      => 'article_type',
						'type'    => 'select',
						'mode'    => 'advanced',
						'label'   => __( 'Posts are described as', 'seo-fixer' ),
						'help'    => __( 'Blog post fits most blogs; news article is for news sites.', 'seo-fixer' ),
						'options' => array(
							'Article'     => __( 'Article', 'seo-fixer' ),
							'BlogPosting' => __( 'Blog post', 'seo-fixer' ),
							'NewsArticle' => __( 'News article', 'seo-fixer' ),
						),
					),
				),
			),
			'breadcrumbs' => array(
				'title'       => __( 'Breadcrumbs', 'seo-fixer' ),
				'description' => __( 'A small “Home › Category › Page” trail that helps visitors and search engines see where a page sits.', 'seo-fixer' ),
				'render'      => array( Schema_Panels::class, 'breadcrumbs' ),
				'fields'      => array(
					array(
						'id'    => 'breadcrumbs_home_label',
						'type'  => 'text',
						'mode'  => 'advanced',
						'label' => __( 'Name of the first link', 'seo-fixer' ),
						'help'  => __( 'Leave empty to use “Home”.', 'seo-fixer' ),
						'attrs' => array(
							'maxlength'   => 60,
							'placeholder' => __( 'Home', 'seo-fixer' ),
						),
					),
					array(
						'id'    => 'breadcrumbs_separator',
						'type'  => 'text',
						'mode'  => 'advanced',
						'label' => __( 'Separator', 'seo-fixer' ),
						'help'  => __( 'The symbol between links, such as › or /.', 'seo-fixer' ),
						'attrs' => array( 'maxlength' => 5 ),
					),
				),
			),
		);
	}

	/**
	 * Sections of the "Redirects" tab.
	 *
	 * @return array
	 */
	public static function redirect_sections() {
		return array(
			'automatic' => array(
				'title'       => __( 'Automatic redirects', 'seo-fixer' ),
				'description' => __( 'Keep old links working without lifting a finger.', 'seo-fixer' ),
				'callout'     => array(
					'text' => __( 'Manage your redirects and see which pages visitors couldn’t find on the Redirects screen.', 'seo-fixer' ),
					'url'  => Redirects_Page::url(),
					'link' => __( 'Open Redirects', 'seo-fixer' ),
				),
				'fields'      => array(
					array(
						'id'    => 'redirect_slug_changes',
						'type'  => 'toggle',
						'label' => __( 'Redirect old addresses when a page’s address changes', 'seo-fixer' ),
						'help'  => __( 'When you change the address of a published post, page, category or tag, visitors using the old one are sent to the new one.', 'seo-fixer' ),
						'why'   => __( 'Links from other sites, bookmarks and Google still use the old address. Without a redirect they all end on “Page not found”, and the page loses its ranking.', 'seo-fixer' ),
					),
					array(
						'id'    => 'trash_notice',
						'type'  => 'toggle',
						'label' => __( 'Remind me to redirect pages I move to the trash', 'seo-fixer' ),
						'help'  => __( 'Shows a one-time message with a “Create a redirect” button after you trash a published page.', 'seo-fixer' ),
					),
				),
			),
			'log'       => array(
				'title'       => __( '“Not found” log', 'seo-fixer' ),
				'description' => __( 'A list of addresses that visitors or search engines tried but that don’t exist.', 'seo-fixer' ),
				'fields'      => array(
					array(
						'id'    => 'log_404',
						'type'  => 'toggle',
						'label' => __( 'Keep a list of pages that weren’t found', 'seo-fixer' ),
						'help'  => __( 'Only the address, how often it was tried and the linking page are kept. No IP addresses.', 'seo-fixer' ),
						'why'   => __( 'Broken links cost you visitors and ranking. The log shows exactly which ones to fix, most-tried first.', 'seo-fixer' ),
					),
					array(
						'id'    => 'log_404_days',
						'type'  => 'number',
						'mode'  => 'advanced',
						'label' => __( 'Keep entries for (days)', 'seo-fixer' ),
						'help'  => __( 'Older entries are removed automatically once a day.', 'seo-fixer' ),
						'attrs' => array(
							'min' => 1,
							'max' => 365,
						),
					),
					array(
						'id'    => 'log_404_max',
						'type'  => 'number',
						'mode'  => 'advanced',
						'label' => __( 'Maximum number of entries', 'seo-fixer' ),
						'help'  => __( 'Keeps the log small on busy sites.', 'seo-fixer' ),
						'attrs' => array(
							'min' => 100,
							'max' => 10000,
						),
					),
					array(
						'id'    => 'log_404_ignore_probes',
						'type'  => 'toggle',
						'mode'  => 'advanced',
						'label' => __( 'Ignore hacking attempts', 'seo-fixer' ),
						'help'  => __( 'Skips automated probes for files like .php, .env or wp-login that are never real pages.', 'seo-fixer' ),
					),
				),
			),
		);
	}

	/**
	 * Title (and optionally description) template fields for one kind of page.
	 *
	 * @param string $suffix          Key suffix, e.g. "tpl_post" → title_tpl_post / desc_tpl_post.
	 * @param string $label           What the templates are for, e.g. "Posts".
	 * @param bool   $with_description Whether to include a description template.
	 * @return array[]
	 */
	private static function template_pair( $suffix, $label, $with_description = true ) {
		$fields = array(
			array(
				'id'       => 'title_' . $suffix,
				'type'     => 'text',
				'mode'     => 'advanced',
				/* translators: %s: kind of page, e.g. "Posts". */
				'label'    => sprintf( __( '%s — title', 'seo-fixer' ), $label ),
				'help'     => __( 'Default title. Variables like %title% are replaced automatically.', 'seo-fixer' ),
				'sanitize' => array( 'Seofx\Sanitize', 'template' ),
				'attrs'    => array(
					'maxlength'   => 200,
					'placeholder' => Templates::default_for( 'title_' . $suffix ),
				),
			),
		);

		if ( $with_description ) {
			$fields[] = array(
				'id'       => 'desc_' . $suffix,
				'type'     => 'text',
				'mode'     => 'advanced',
				/* translators: %s: kind of page, e.g. "Posts". */
				'label'    => sprintf( __( '%s — description', 'seo-fixer' ), $label ),
				'help'     => __( 'Default meta description.', 'seo-fixer' ),
				'sanitize' => array( 'Seofx\Sanitize', 'template' ),
				'attrs'    => array(
					'maxlength'   => 400,
					'placeholder' => Templates::default_for( 'desc_' . $suffix ),
				),
			);
		}

		return $fields;
	}

	/**
	 * Brand identity fields.
	 *
	 * @return array[]
	 */
	public static function identity_fields() {
		return array(
			array(
				'id'      => 'entity_type',
				'type'    => 'segmented',
				'label'   => __( 'This website represents', 'seo-fixer' ),
				'help'    => __( 'Choose “A person” for a personal blog or portfolio.', 'seo-fixer' ),
				'why'     => __( 'Google can show your logo, name and social profiles in a panel next to search results. It needs to know whether they belong to a company or a person.', 'seo-fixer' ),
				'options' => array(
					'organization' => __( 'A business or organization', 'seo-fixer' ),
					'person'       => __( 'A person', 'seo-fixer' ),
				),
			),
			array(
				'id'    => 'entity_name',
				'type'  => 'text',
				'label' => __( 'Name', 'seo-fixer' ),
				'help'  => __( 'Your business name or your full name, written the way customers know it.', 'seo-fixer' ),
				'attrs' => array(
					'maxlength'   => 120,
					'placeholder' => get_bloginfo( 'name' ),
				),
			),
			array(
				'id'    => 'entity_logo',
				'type'  => 'image',
				'label' => __( 'Logo or photo', 'seo-fixer' ),
				'help'  => __( 'A square image of at least 112 × 112 pixels works best.', 'seo-fixer' ),
				'why'   => __( 'Google may show this image next to your business in search results. Images smaller than 112 × 112 pixels are not used.', 'seo-fixer' ),
			),
		);
	}

	/**
	 * Social profile fields.
	 *
	 * @return array[]
	 */
	public static function social_fields() {
		/* translators: 1: example profile address, 2: example @handle. */
		$either   = __( '%1$s or %2$s', 'seo-fixer' );
		$networks = array(
			'social_facebook'  => array( 'Facebook', 'https://www.facebook.com/yourpage' ),
			'social_instagram' => array( 'Instagram', sprintf( $either, 'https://www.instagram.com/yourname', '@yourname' ) ),
			'social_x'         => array( 'X (Twitter)', sprintf( $either, 'https://x.com/yourname', '@yourname' ) ),
			'social_linkedin'  => array( 'LinkedIn', 'https://www.linkedin.com/company/yourcompany' ),
			'social_youtube'   => array( 'YouTube', 'https://www.youtube.com/@yourchannel' ),
			'social_tiktok'    => array( 'TikTok', sprintf( $either, 'https://www.tiktok.com/@yourname', '@yourname' ) ),
			'social_pinterest' => array( 'Pinterest', 'https://www.pinterest.com/yourname' ),
		);

		$fields = array();
		foreach ( $networks as $id => $network ) {
			$fields[] = array(
				'id'       => $id,
				'type'     => 'url',
				'label'    => $network[0],
				/* translators: %s: social network name, e.g. Facebook. */
				'help'     => sprintf( __( 'Paste the link to your %s profile.', 'seo-fixer' ), $network[0] ),
				'why'      => 'social_facebook' === $id ? __( 'These links tell search engines which social accounts belong to you, so they can show them with your brand.', 'seo-fixer' ) : '',
				'attrs'    => array( 'placeholder' => $network[1] ),
				'sanitize' => array( 'Seofx\Sanitize', 'profile_url' ),
			);
		}

		$fields[] = array(
			'id'       => 'social_other',
			'type'     => 'textarea',
			'mode'     => 'advanced',
			'label'    => __( 'Other profiles', 'seo-fixer' ),
			'help'     => __( 'Any other official pages, such as Wikipedia or Mastodon. One link per line.', 'seo-fixer' ),
			'attrs'    => array( 'rows' => 3 ),
			'sanitize' => array( 'Seofx\Sanitize', 'url_list' ),
		);

		return $fields;
	}

	/**
	 * Local business fields.
	 *
	 * @return array[]
	 */
	public static function local_fields() {
		$show = array( 'local_business' => true );

		return array(
			array(
				'id'    => 'local_business',
				'type'  => 'toggle',
				'label' => __( 'Customers visit us, or we serve a local area', 'seo-fixer' ),
				'help'  => __( 'Turn this on for shops, restaurants, salons, trades, clinics and similar businesses.', 'seo-fixer' ),
				'why'   => __( 'Google uses your address and phone number for local results and Maps. Use exactly the same details as on your Google Business Profile.', 'seo-fixer' ),
			),
			array(
				'id'      => 'local_type',
				'type'    => 'select',
				'label'   => __( 'Type of business', 'seo-fixer' ),
				'help'    => __( 'Pick the closest match. “Other” is fine if nothing fits.', 'seo-fixer' ),
				'show_if' => $show,
				'options' => array(
					'Store'                       => __( 'Shop or store', 'seo-fixer' ),
					'Restaurant'                  => __( 'Restaurant', 'seo-fixer' ),
					'CafeOrCoffeeShop'            => __( 'Café or coffee shop', 'seo-fixer' ),
					'Bakery'                      => __( 'Bakery', 'seo-fixer' ),
					'HealthAndBeautyBusiness'     => __( 'Salon, spa or beauty', 'seo-fixer' ),
					'SportsActivityLocation'      => __( 'Gym or sports venue', 'seo-fixer' ),
					'MedicalBusiness'             => __( 'Medical practice or clinic', 'seo-fixer' ),
					'Dentist'                     => __( 'Dentist', 'seo-fixer' ),
					'HomeAndConstructionBusiness' => __( 'Home services (plumber, electrician, builder)', 'seo-fixer' ),
					'AutomotiveBusiness'          => __( 'Car repair or dealer', 'seo-fixer' ),
					'ProfessionalService'         => __( 'Professional service (agency, consultant)', 'seo-fixer' ),
					'LegalService'                => __( 'Lawyer or legal service', 'seo-fixer' ),
					'FinancialService'            => __( 'Accountant or financial service', 'seo-fixer' ),
					'RealEstateAgent'             => __( 'Real estate agent', 'seo-fixer' ),
					'LodgingBusiness'             => __( 'Hotel or accommodation', 'seo-fixer' ),
					'ChildCare'                   => __( 'Childcare', 'seo-fixer' ),
					'LocalBusiness'               => __( 'Other local business', 'seo-fixer' ),
				),
			),
			array(
				'id'      => 'local_street',
				'type'    => 'text',
				'label'   => __( 'Street address', 'seo-fixer' ),
				'help'    => __( 'Building number and street.', 'seo-fixer' ),
				'show_if' => $show,
				'attrs'   => array( 'maxlength' => 200 ),
			),
			array(
				'id'      => 'local_city',
				'type'    => 'text',
				'label'   => __( 'City or town', 'seo-fixer' ),
				'help'    => __( 'Where the business is located.', 'seo-fixer' ),
				'show_if' => $show,
				'attrs'   => array( 'maxlength' => 100 ),
			),
			array(
				'id'      => 'local_region',
				'type'    => 'text',
				'label'   => __( 'State or region', 'seo-fixer' ),
				'help'    => __( 'Leave empty if your country doesn’t use one.', 'seo-fixer' ),
				'show_if' => $show,
				'attrs'   => array( 'maxlength' => 100 ),
			),
			array(
				'id'      => 'local_postcode',
				'type'    => 'text',
				'label'   => __( 'Postal code', 'seo-fixer' ),
				'help'    => __( 'Your ZIP code or postcode.', 'seo-fixer' ),
				'show_if' => $show,
				'attrs'   => array( 'maxlength' => 20 ),
			),
			array(
				'id'       => 'local_country',
				'type'     => 'select',
				'label'    => __( 'Country', 'seo-fixer' ),
				'help'     => __( 'The country the business is in.', 'seo-fixer' ),
				'show_if'  => $show,
				'options'  => \Seofx\Countries::options(),
				'sanitize' => array( 'Seofx\Sanitize', 'country' ),
			),
			array(
				'id'      => 'local_phone',
				'type'    => 'tel',
				'label'   => __( 'Phone number', 'seo-fixer' ),
				'help'    => __( 'Include the country code, for example +44 20 7946 0958.', 'seo-fixer' ),
				'show_if' => $show,
				'attrs'   => array(
					'maxlength'   => 40,
					'placeholder' => '+1 555 123 4567',
				),
			),
			array(
				'id'       => 'local_hours',
				'type'     => 'hours',
				'label'    => __( 'Opening hours', 'seo-fixer' ),
				'help'     => __( 'Tick the days you are open and set the times. Leave everything unticked if you prefer not to show hours.', 'seo-fixer' ),
				'why'      => __( 'Google can show your opening hours in results and on Maps. Keep them the same as on your Google Business Profile.', 'seo-fixer' ),
				'show_if'  => $show,
				'sanitize' => array( 'Seofx\Sanitize', 'hours' ),
			),
		);
	}

	/**
	 * One field by id (normalized), or null.
	 *
	 * @param string $id Field id.
	 * @return array|null
	 */
	public function field( $id ) {
		$fields = $this->fields();
		return isset( $fields[ $id ] ) ? $fields[ $id ] : null;
	}

	/**
	 * Every field keyed by id, with defaults filled in.
	 *
	 * @return array<string, array>
	 */
	public function fields() {
		$fields = array();
		foreach ( $this->tabs() as $tab ) {
			foreach ( $tab['sections'] as $section ) {
				foreach ( $section['fields'] as $field ) {
					$fields[ $field['id'] ] = self::normalize( $field );
				}
			}
		}
		return $fields;
	}

	/**
	 * Fills optional keys.
	 *
	 * @param array $field Raw field.
	 * @return array
	 */
	public static function normalize( array $field ) {
		return wp_parse_args(
			$field,
			array(
				'type'     => 'text',
				'label'    => '',
				'help'     => '',
				'why'      => '',
				'options'  => array(),
				'mode'     => 'simple',
				'storage'  => 'site',
				'attrs'    => array(),
				'sanitize' => null,
				'validate' => null,
				'show_if'  => array(),
				'default'  => null,
			)
		);
	}

	/**
	 * Current value of a field (its own default when never saved).
	 *
	 * @param array $field Normalized field.
	 * @return mixed
	 */
	public static function value( array $field ) {
		if ( 'user' === $field['storage'] ) {
			return Preferences::get( $field['id'] );
		}
		$value = Options::get( $field['id'] );
		return null === $value ? $field['default'] : $value;
	}

	/**
	 * Default value of a field.
	 *
	 * @param array $field Normalized field.
	 * @return mixed
	 */
	public static function default_value( array $field ) {
		if ( 'user' === $field['storage'] ) {
			$allowed = Preferences::allowed();
			return isset( $allowed[ $field['id'] ] ) ? $allowed[ $field['id'] ][0] : '';
		}
		$defaults = Options::defaults();
		if ( array_key_exists( $field['id'], $defaults ) ) {
			return $defaults[ $field['id'] ];
		}
		return null !== $field['default'] ? $field['default'] : '';
	}

	/**
	 * Cleans submitted values for the fields that were on the form.
	 *
	 * Only ids listed in $field_ids are touched, so fields hidden in Simple mode
	 * or on other tabs keep their stored values.
	 *
	 * @param string[] $field_ids Ids rendered on the submitted form.
	 * @param array    $input     Raw (unslashed) submitted values.
	 * @return array{site: array, user: array, invalid: string[], errors: string[]}
	 *         "invalid" lists the labels of fields whose entry could not be used
	 *         (e.g. a malformed link); "errors" holds messages from 'validate'
	 *         callbacks, whose fields are left unchanged.
	 */
	public function sanitize( array $field_ids, array $input ) {
		$fields = $this->fields();
		$clean  = array(
			'site'    => array(),
			'user'    => array(),
			'invalid' => array(),
			'errors'  => array(),
		);

		foreach ( $field_ids as $id ) {
			if ( ! isset( $fields[ $id ] ) ) {
				continue;
			}
			$field = $fields[ $id ];
			$raw   = array_key_exists( $id, $input ) ? $input[ $id ] : null;
			$value = self::sanitize_value( $field, $raw );

			// A validator can refuse a value outright (e.g. robots.txt rules that block the whole site).
			if ( is_callable( $field['validate'] ) ) {
				$error = (string) call_user_func( $field['validate'], $value, $field, $raw );
				if ( '' !== $error ) {
					$clean['errors'][] = $error;
					continue;
				}
			}

			if ( '' === $value && is_scalar( $raw ) && '' !== trim( (string) $raw ) ) {
				$clean['invalid'][] = $field['label'];
			}

			$clean[ 'user' === $field['storage'] ? 'user' : 'site' ][ $id ] = $value;
		}

		return $clean;
	}

	/**
	 * Plain-English warning for fields that could not be used.
	 *
	 * @param string[] $labels Field labels.
	 * @return string
	 */
	public static function invalid_message( array $labels ) {
		return sprintf(
			/* translators: %s: list of field names, e.g. "LinkedIn and Phone number". */
			__( 'We couldn’t use what was entered for %s, so it was left empty. Please check it and try again.', 'seo-fixer' ),
			wp_sprintf( '%l', $labels )
		);
	}

	/**
	 * Cleans one value according to its field type.
	 *
	 * @param array $field Normalized field.
	 * @param mixed $raw   Raw value (null when absent, e.g. an unticked toggle).
	 * @return mixed
	 */
	public static function sanitize_value( array $field, $raw ) {
		if ( is_callable( $field['sanitize'] ) ) {
			return call_user_func( $field['sanitize'], $raw, $field );
		}

		$raw = is_scalar( $raw ) ? (string) $raw : '';

		switch ( $field['type'] ) {
			case 'toggle':
				return '1' === $raw;

			case 'radio_cards':
			case 'segmented':
			case 'select':
				return array_key_exists( $raw, $field['options'] ) ? $raw : self::default_value( $field );

			case 'number':
				$number = (int) $raw;
				if ( isset( $field['attrs']['min'] ) ) {
					$number = max( (int) $field['attrs']['min'], $number );
				}
				if ( isset( $field['attrs']['max'] ) ) {
					$number = min( (int) $field['attrs']['max'], $number );
				}
				return $number;

			case 'url':
				return Sanitize::web_url( $raw );

			case 'hours':
				return array();

			case 'image':
				return Sanitize::image_id( $raw );

			case 'tel':
				return Sanitize::phone( $raw );

			case 'email':
				return sanitize_email( $raw );

			case 'textarea':
				return sanitize_textarea_field( $raw );

			case 'text':
			default:
				$text = sanitize_text_field( $raw );
				if ( isset( $field['attrs']['maxlength'] ) ) {
					$text = mb_substr( $text, 0, (int) $field['attrs']['maxlength'] );
				}
				return $text;
		}
	}
}
