<?php
/**
 * Every health check: how serious it is and how to explain it in plain English.
 *
 * Severity → points lost:  page score  /  site score
 *   critical                 40           30   (also caps the overall score at 45: red)
 *   important                15           10
 *   minor                     5            3
 *   hint                      0            0   (shown, never scored)
 *
 * @package SeoFixer
 */

namespace Seofx\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Check definitions.
 */
final class Checks {

	/**
	 * Points lost per severity on a page.
	 */
	const PAGE_WEIGHTS = array(
		'critical'  => 40,
		'important' => 15,
		'minor'     => 5,
		'hint'      => 0,
	);

	/**
	 * Points lost per severity for the whole site.
	 */
	const SITE_WEIGHTS = array(
		'critical'  => 30,
		'important' => 10,
		'minor'     => 3,
		'hint'      => 0,
	);

	/**
	 * Highest overall score while any critical problem remains.
	 */
	const CRITICAL_CAP = 45;

	/**
	 * Page-level checks.
	 *
	 * Each: severity, label, message (with %s = formatted number of pages, singular/plural),
	 * page (text shown for one page), fix (M8 one-click fix id or '').
	 *
	 * @return array<string, array>
	 */
	public static function page_checks() {
		return array(
			'desc_missing'    => array(
				'severity' => 'important',
				'label'    => __( 'No description', 'seo-fixer' ),
				/* translators: %s: number of pages. */
				'message'  => _n_noop( '%s page has no description at all — Google may show random text from it.', '%s pages have no description at all — Google may show random text from them.', 'seo-fixer' ),
				'page'     => __( 'This page has no description, and its content is too short to write one from.', 'seo-fixer' ),
				'fix'      => 'description',
			),
			'title_duplicate' => array(
				'severity' => 'important',
				'label'    => __( 'Same title as another page', 'seo-fixer' ),
				/* translators: %s: number of pages. */
				'message'  => _n_noop( '%s page shares its title with another page — Google may not know which one to show.', '%s pages share their title with another page — Google may not know which one to show.', 'seo-fixer' ),
				'page'     => __( 'Another page has exactly the same title.', 'seo-fixer' ),
				'fix'      => '',
			),
			'desc_duplicate'  => array(
				'severity' => 'important',
				'label'    => __( 'Same description as another page', 'seo-fixer' ),
				/* translators: %s: number of pages. */
				'message'  => _n_noop( '%s page shares its description with another page.', '%s pages share their description with another page.', 'seo-fixer' ),
				'page'     => __( 'Another page has exactly the same description.', 'seo-fixer' ),
				'fix'      => '',
			),
			'img_no_alt'      => array(
				'severity' => 'important',
				'label'    => __( 'Images without alt text', 'seo-fixer' ),
				/* translators: %s: number of pages. */
				'message'  => _n_noop( '%s page has images without alt text — search engines and screen readers can’t tell what they show.', '%s pages have images without alt text — search engines and screen readers can’t tell what they show.', 'seo-fixer' ),
				'page'     => __( 'Some images have no alt text.', 'seo-fixer' ),
				'fix'      => 'alt',
			),
			'broken_links'    => array(
				'severity' => 'important',
				'label'    => __( 'Broken links', 'seo-fixer' ),
				/* translators: %s: number of pages. */
				'message'  => _n_noop( '%s page links to pages on your site that don’t exist.', '%s pages link to pages on your site that don’t exist.', 'seo-fixer' ),
				'page'     => __( 'Links to pages on your site that don’t exist.', 'seo-fixer' ),
				'fix'      => '',
			),
			'orphan'          => array(
				'severity' => 'important',
				'label'    => __( 'No links pointing to it', 'seo-fixer' ),
				/* translators: %s: number of pages. */
				'message'  => _n_noop( '%s page isn’t linked from any other page or menu — visitors and Google may never find it.', '%s pages aren’t linked from any other page or menu — visitors and Google may never find them.', 'seo-fixer' ),
				'page'     => __( 'No other page or menu links here.', 'seo-fixer' ),
				'fix'      => '',
			),
			'title_too_long'  => array(
				'severity' => 'minor',
				'label'    => __( 'Title too long', 'seo-fixer' ),
				/* translators: %s: number of pages. */
				'message'  => _n_noop( '%s page has a title that Google will cut off.', '%s pages have titles that Google will cut off.', 'seo-fixer' ),
				'page'     => __( 'The title is longer than about 65 characters, so Google will cut it off.', 'seo-fixer' ),
				'fix'      => '',
			),
			'desc_too_long'   => array(
				'severity' => 'minor',
				'label'    => __( 'Description too long', 'seo-fixer' ),
				/* translators: %s: number of pages. */
				'message'  => _n_noop( '%s page has a description that Google will cut off.', '%s pages have descriptions that Google will cut off.', 'seo-fixer' ),
				'page'     => __( 'The description is longer than about 160 characters.', 'seo-fixer' ),
				'fix'      => '',
			),
			'desc_too_short'  => array(
				'severity' => 'minor',
				'label'    => __( 'Description too short', 'seo-fixer' ),
				/* translators: %s: number of pages. */
				'message'  => _n_noop( '%s page has a very short description.', '%s pages have very short descriptions.', 'seo-fixer' ),
				'page'     => __( 'The description is shorter than 70 characters — there is room to say more.', 'seo-fixer' ),
				'fix'      => 'description',
			),
			'h1_in_content'   => array(
				'severity' => 'minor',
				'label'    => __( 'Extra main heading', 'seo-fixer' ),
				/* translators: %s: number of pages. */
				'message'  => _n_noop( '%s page uses a main heading (H1) inside its text, on top of the page title.', '%s pages use a main heading (H1) inside their text, on top of the page title.', 'seo-fixer' ),
				'page'     => __( 'The text contains a main heading (H1). The title is already the main heading; use Heading 2 inside the text.', 'seo-fixer' ),
				'fix'      => '',
			),
			'img_large'       => array(
				'severity' => 'minor',
				'label'    => __( 'Very large images', 'seo-fixer' ),
				/* translators: %s: number of pages. */
				'message'  => _n_noop( '%s page has images larger than 500 KB that slow it down.', '%s pages have images larger than 500 KB that slow them down.', 'seo-fixer' ),
				'page'     => __( 'Some images are larger than 500 KB and slow the page down.', 'seo-fixer' ),
				'fix'      => '',
			),
			'no_image'        => array(
				'severity' => 'minor',
				'label'    => __( 'No image for sharing', 'seo-fixer' ),
				/* translators: %s: number of pages. */
				'message'  => _n_noop( '%s page has no image, so it looks bare when shared and in Google Discover.', '%s pages have no image, so they look bare when shared and in Google Discover.', 'seo-fixer' ),
				'page'     => __( 'No featured or sharing image.', 'seo-fixer' ),
				'fix'      => '',
			),
			'slug_long'       => array(
				'severity' => 'minor',
				'label'    => __( 'Long address', 'seo-fixer' ),
				/* translators: %s: number of pages. */
				'message'  => _n_noop( '%s page has a very long address.', '%s pages have very long addresses.', 'seo-fixer' ),
				'page'     => __( 'The address (slug) is longer than 60 characters. Short addresses are easier to share and read.', 'seo-fixer' ),
				'fix'      => '',
			),
			'noindex'         => array(
				'severity' => 'minor',
				'label'    => __( 'Hidden from search', 'seo-fixer' ),
				/* translators: %s: number of pages. */
				'message'  => _n_noop( '%s page is hidden from search results on purpose — check that’s still what you want.', '%s pages are hidden from search results on purpose — check that’s still what you want.', 'seo-fixer' ),
				'page'     => __( 'This page is set to “hide from search results”.', 'seo-fixer' ),
				'fix'      => '',
			),
			'desc_auto'       => array(
				'severity' => 'hint',
				'label'    => __( 'Automatic description', 'seo-fixer' ),
				/* translators: %s: number of pages. */
				'message'  => _n_noop( '%s page uses a description written automatically from its first sentences. A hand-written one usually gets more clicks.', '%s pages use a description written automatically from their first sentences. A hand-written one usually gets more clicks.', 'seo-fixer' ),
				'page'     => __( 'The description is written automatically from the first sentences.', 'seo-fixer' ),
				'fix'      => 'description',
			),
			'thin_content'    => array(
				'severity' => 'hint',
				'label'    => __( 'Short content', 'seo-fixer' ),
				/* translators: %s: number of pages. */
				'message'  => _n_noop( '%s page has fewer than 300 words. That can be fine, but pages that answer a question fully tend to do better.', '%s pages have fewer than 300 words. That can be fine, but pages that answer a question fully tend to do better.', 'seo-fixer' ),
				'page'     => __( 'Fewer than 300 words.', 'seo-fixer' ),
				'fix'      => '',
			),
		);
	}

	/**
	 * What a passed page check means, for the editor checklist.
	 *
	 * @return array<string, string>
	 */
	public static function passed_labels() {
		return array(
			'desc_missing'    => __( 'Has a description', 'seo-fixer' ),
			'title_duplicate' => __( 'Title is unique', 'seo-fixer' ),
			'desc_duplicate'  => __( 'Description is unique', 'seo-fixer' ),
			'img_no_alt'      => __( 'Images have alt text', 'seo-fixer' ),
			'broken_links'    => __( 'No broken links', 'seo-fixer' ),
			'orphan'          => __( 'Other pages or menus link here', 'seo-fixer' ),
			'title_too_long'  => __( 'Title fits in Google', 'seo-fixer' ),
			'desc_too_long'   => __( 'Description fits in Google', 'seo-fixer' ),
			'desc_too_short'  => __( 'Description is long enough', 'seo-fixer' ),
			'h1_in_content'   => __( 'One main heading', 'seo-fixer' ),
			'img_large'       => __( 'Images are a sensible size', 'seo-fixer' ),
			'no_image'        => __( 'Has an image for sharing', 'seo-fixer' ),
			'slug_long'       => __( 'Address is short', 'seo-fixer' ),
			'noindex'         => __( 'Shown in search results', 'seo-fixer' ),
			'desc_auto'       => __( 'Description written by you', 'seo-fixer' ),
			'thin_content'    => __( 'Enough words', 'seo-fixer' ),
		);
	}

	/**
	 * "Why it matters" and "How to fix it" for each check, in plain English.
	 *
	 * @return array<string, array{why: string, how: string}>
	 */
	public static function guidance() {
		return array(
			// Page checks.
			'desc_missing'         => array(
				'why' => __( 'The description is the grey text under your link in Google. A clear one tells people what they’ll find and makes them more likely to click.', 'seo-fixer' ),
				'how' => __( 'Open the page and write one or two sentences under “Description” in the SEO Fixer box.', 'seo-fixer' ),
			),
			'title_duplicate'      => array(
				'why' => __( 'When two pages have the same title, Google can’t tell which one answers a search, and may show neither.', 'seo-fixer' ),
				'how' => __( 'Give each page a title that says what makes it different.', 'seo-fixer' ),
			),
			'desc_duplicate'       => array(
				'why' => __( 'Each page should have its own summary, so people can tell which result is the one they want.', 'seo-fixer' ),
				'how' => __( 'Write a different description for each of these pages.', 'seo-fixer' ),
			),
			'img_no_alt'           => array(
				'why' => __( 'Alt text describes an image for blind visitors and for Google Images. Without it, your images can’t be found in image search.', 'seo-fixer' ),
				'how' => __( 'In the editor, click the image and fill in “Alternative text” with a short description of what it shows.', 'seo-fixer' ),
			),
			'broken_links'         => array(
				'why' => __( 'Links to missing pages frustrate visitors and waste the time Google spends on your site.', 'seo-fixer' ),
				'how' => __( 'Edit the page and fix or remove the link — or, if the page moved, create a redirect from the old address.', 'seo-fixer' ),
			),
			'orphan'               => array(
				'why' => __( 'Google finds pages by following links. A page nothing links to may never be found, by Google or by visitors.', 'seo-fixer' ),
				'how' => __( 'Link to it from a related page, or add it to your menu.', 'seo-fixer' ),
			),
			'title_too_long'       => array(
				'why' => __( 'Google shows about 60 characters of a title. The rest is cut off with “…”, which can hide the most important words.', 'seo-fixer' ),
				'how' => __( 'Shorten the SEO title and put the most important words first.', 'seo-fixer' ),
			),
			'desc_too_long'        => array(
				'why' => __( 'Google shows about 155 characters of a description and cuts off the rest.', 'seo-fixer' ),
				'how' => __( 'Shorten the description to one or two sentences.', 'seo-fixer' ),
			),
			'desc_too_short'       => array(
				'why' => __( 'A very short description wastes space that could convince people to click.', 'seo-fixer' ),
				'how' => __( 'Add a sentence about what people will find or learn on the page.', 'seo-fixer' ),
			),
			'h1_in_content'        => array(
				'why' => __( 'The page title is already the main heading. A second main heading makes the page’s topic less clear.', 'seo-fixer' ),
				'how' => __( 'In the editor, change those headings to Heading 2 (or lower).', 'seo-fixer' ),
			),
			'img_large'            => array(
				'why' => __( 'Large images make pages slow to load, especially on phones. Slow pages lose visitors and rank lower.', 'seo-fixer' ),
				'how' => __( 'Upload a smaller version (under 500 KB), or use an image-compression plugin.', 'seo-fixer' ),
			),
			'no_image'             => array(
				'why' => __( 'Pages with an image stand out when shared on social media and can appear in Google Discover.', 'seo-fixer' ),
				'how' => __( 'Set a featured image, or choose a sharing image in the SEO Fixer box. You can also set a default sharing image in Settings.', 'seo-fixer' ),
			),
			'slug_long'            => array(
				'why' => __( 'Short addresses are easier to read, share and remember.', 'seo-fixer' ),
				'how' => __( 'Shorten the permalink in the editor. SEO Fixer adds a redirect from the old address for you.', 'seo-fixer' ),
			),
			'noindex'              => array(
				'why' => __( 'Hidden pages never appear in Google. That’s right for thank-you or login pages, but not for content you want people to find.', 'seo-fixer' ),
				'how' => __( 'If the page should be found, turn on “Show in search results” in its SEO Fixer box.', 'seo-fixer' ),
			),
			'desc_auto'            => array(
				'why' => __( 'SEO Fixer writes a description from the first sentences, so the page is never empty in Google. A description written for searchers usually gets more clicks.', 'seo-fixer' ),
				'how' => __( 'For your most important pages, write your own description in the SEO Fixer box.', 'seo-fixer' ),
			),
			'thin_content'         => array(
				'why' => __( 'Short pages are fine for contact or product pages. For articles, pages that answer a question fully tend to rank better.', 'seo-fixer' ),
				'how' => __( 'If this is an article, consider adding detail, examples or answers to common questions.', 'seo-fixer' ),
			),

			// Site checks.
			'search_visibility'    => array(
				'why' => __( 'This WordPress setting asks every search engine to stay away. Nothing else you do matters while it’s on.', 'seo-fixer' ),
				'how' => __( 'Go to Settings → Reading and untick “Discourage search engines from indexing this site”.', 'seo-fixer' ),
			),
			'homepage_noindex'     => array(
				'why' => __( 'Your homepage is usually the page people search for first, by your name.', 'seo-fixer' ),
				'how' => __( 'Edit the homepage and turn on “Show in search results” in the SEO Fixer box.', 'seo-fixer' ),
			),
			'robots_blocks'        => array(
				'why' => __( 'robots.txt tells search engines which parts of your site they may visit. A rule blocking everything keeps your site out of Google.', 'seo-fixer' ),
				'how' => __( 'Review the robots.txt rules in Settings → Sitemap & robots.txt and remove “Disallow: /”. If a robots.txt file exists on your server, ask your host to remove it.', 'seo-fixer' ),
			),
			'output_paused'        => array(
				'why' => __( 'While SEO Fixer is paused, your pages don’t get its titles, descriptions, sitemap tags or structured data.', 'seo-fixer' ),
				'how' => __( 'If you’ve stopped using your other SEO plugin, set SEO Fixer to “Always on” in Settings → General.', 'seo-fixer' ),
			),
			'https'                => array(
				'why' => __( 'Browsers mark http:// sites “Not secure”, which scares visitors away, and Google prefers secure pages.', 'seo-fixer' ),
				'how' => __( 'Ask your host for a free SSL certificate (most offer Let’s Encrypt), then change both addresses in Settings → General to https://.', 'seo-fixer' ),
			),
			'mixed_content'        => array(
				'why' => __( 'Files loaded over http:// on a secure page can be blocked by browsers, and the padlock disappears.', 'seo-fixer' ),
				'how' => __( 'Change those addresses to https://. A search-and-replace plugin can fix old links in your content.', 'seo-fixer' ),
			),
			'permalinks'           => array(
				'why' => __( 'Addresses with words (example.com/sourdough-bread) tell people and Google what a page is about before they open it.', 'seo-fixer' ),
				'how' => __( 'Go to Settings → Permalinks and choose “Post name”.', 'seo-fixer' ),
			),
			'homepage_description' => array(
				'why' => __( 'Your homepage description is often the first thing people read about you in Google.', 'seo-fixer' ),
				'how' => __( 'Write one or two sentences about what you offer and who it’s for.', 'seo-fixer' ),
			),
			'title_tags'           => array(
				'why' => __( 'Two title tags confuse search engines about what the page is called; none means Google makes one up.', 'seo-fixer' ),
				'how' => __( 'Check your theme or other plugins for their own title setting and turn it off, or ask your theme’s support.', 'seo-fixer' ),
			),
			'local_incomplete'     => array(
				'why' => __( 'Google uses your address and phone to show your business in local results and on Maps.', 'seo-fixer' ),
				'how' => __( 'Fill in the missing details under Settings → Local business.', 'seo-fixer' ),
			),
			'theme_h1'             => array(
				'why' => __( 'The main heading (H1) tells visitors and search engines what a page is about. One clear H1 per page is best.', 'seo-fixer' ),
				'how' => __( 'This comes from your theme. Look for a heading setting in the theme options, or ask the theme’s support.', 'seo-fixer' ),
			),
			'sitemap'              => array(
				'why' => __( 'A sitemap lists all your pages, so search engines find new and updated pages quickly.', 'seo-fixer' ),
				'how' => __( 'Turn the sitemap on in Settings → Sitemap & robots.txt.', 'seo-fixer' ),
			),
			'sitemap_submitted'    => array(
				'why' => __( 'Sending your sitemap to Google Search Console once means Google checks it regularly, and you can see which pages are in Google.', 'seo-fixer' ),
				'how' => __( 'Follow the step-by-step guide in Settings → Sitemap & robots.txt, then click “I’ve done this”.', 'seo-fixer' ),
			),
			'site_icon'            => array(
				'why' => __( 'Google shows your site icon next to every result, which makes your pages easy to recognise.', 'seo-fixer' ),
				'how' => __( 'Upload a square image of at least 512 × 512 pixels as your site icon.', 'seo-fixer' ),
			),
			'logo'                 => array(
				'why' => __( 'Google can show your logo next to your business name in search results.', 'seo-fixer' ),
				'how' => __( 'Choose your logo under Settings → Your brand.', 'seo-fixer' ),
			),
			'not_found'            => array(
				'why' => __( 'People (and Google) are trying addresses that don’t exist — often old pages that moved.', 'seo-fixer' ),
				'how' => __( 'Open the list of missing pages and redirect the ones that should lead somewhere.', 'seo-fixer' ),
			),
		);
	}

	/**
	 * Site-level checks (results come from Site_Checks).
	 *
	 * @return array<string, array> Each: severity, label.
	 */
	public static function site_checks() {
		return array(
			'search_visibility'    => array(
				'severity' => 'critical',
				'label'    => __( 'Search engines can see your site', 'seo-fixer' ),
			),
			'homepage_noindex'     => array(
				'severity' => 'critical',
				'label'    => __( 'Homepage shown in search results', 'seo-fixer' ),
			),
			'robots_blocks'        => array(
				'severity' => 'critical',
				'label'    => __( 'robots.txt lets Google in', 'seo-fixer' ),
			),
			'output_paused'        => array(
				'severity' => 'important',
				'label'    => __( 'SEO Fixer is active', 'seo-fixer' ),
			),
			'https'                => array(
				'severity' => 'important',
				'label'    => __( 'Secure connection (HTTPS)', 'seo-fixer' ),
			),
			'mixed_content'        => array(
				'severity' => 'important',
				'label'    => __( 'No insecure files on secure pages', 'seo-fixer' ),
			),
			'permalinks'           => array(
				'severity' => 'important',
				'label'    => __( 'Readable page addresses', 'seo-fixer' ),
			),
			'homepage_description' => array(
				'severity' => 'important',
				'label'    => __( 'Homepage description', 'seo-fixer' ),
			),
			'title_tags'           => array(
				'severity' => 'important',
				'label'    => __( 'One title per page', 'seo-fixer' ),
			),
			'local_incomplete'     => array(
				'severity' => 'important',
				'label'    => __( 'Local business details', 'seo-fixer' ),
			),
			'theme_h1'             => array(
				'severity' => 'minor',
				'label'    => __( 'One main heading per page', 'seo-fixer' ),
			),
			'sitemap'              => array(
				'severity' => 'minor',
				'label'    => __( 'XML sitemap', 'seo-fixer' ),
			),
			'sitemap_submitted'    => array(
				'severity' => 'minor',
				'label'    => __( 'Sitemap sent to Google', 'seo-fixer' ),
			),
			'site_icon'            => array(
				'severity' => 'minor',
				'label'    => __( 'Site icon', 'seo-fixer' ),
			),
			'logo'                 => array(
				'severity' => 'minor',
				'label'    => __( 'Logo for search results', 'seo-fixer' ),
			),
			'not_found'            => array(
				'severity' => 'minor',
				'label'    => __( 'Missing pages', 'seo-fixer' ),
			),
		);
	}
}
