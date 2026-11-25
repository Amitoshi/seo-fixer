=== SEO Fixer – Automatic SEO Audit & One-Click Fix ===
Contributors: amitoshi
Tags: seo, meta description, xml sitemap, schema, redirects
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Simple, guided SEO: a clear health score, plain-English issues and one-click fixes. Light, fast and private — no data leaves your site.

== Description ==

SEO Fixer is SEO for people who are not SEO experts. Instead of dozens of settings screens, you get a health score from 0 to 100, a plain-English list of what is holding your pages back, and a button that fixes it. Every fix can be undone.

= Find what's wrong =

* **SEO Health scan** of every page: titles that are too long or used twice, missing, short, long or duplicate descriptions, images without alt text or far too large, broken internal links, pages no other page links to, very thin content and pages hidden from search.
* **Site checks**: "Discourage search engines" left on, no HTTPS or mixed content, unreadable page addresses, robots.txt blocking your site, no site icon or logo, sitemap not submitted, and "not found" pages people keep trying.
* **A score** for your whole site and for every page, a page list you can filter, a printable report and a CSV export.
* Each problem says what it means, why it matters and how to fix it, in plain English.
* **Built for big sites**: the scan runs in short steps in the background, and pages are rechecked when you save them. Tested with 5,000 pages.

= Fix it in one click =

* **Meta descriptions** written from each page's own text, **titles** shortened, and **alt text** added to images. All of it is generated on your own site, with no AI service, no account and no cost.
* **Preview every suggestion**, edit it if you like, then apply one or use "Fix all".
* **Undo anything.** Every fix and import is listed under SEO Health → Changes. Values you edited afterwards are left alone.
* **Never overwrites your own words.** Titles and descriptions you wrote are never replaced.
* **Optional automatic fixes** for pages you publish from now on.

= Everything a site needs for search =

* **Titles and meta descriptions** with templates and variables, and a live Google preview for desktop and mobile.
* **Editor checklist**: an SEO Fixer sidebar in the block editor and a box in the classic editor, with the page's score, a checklist that updates as you type, and quick fixes.
* **XML sitemaps** (with images), a **robots.txt** editor that refuses rules that would hide your whole site, and "show in search results", "follow links" and canonical settings per page, content type and taxonomy.
* **Structured data (schema.org)**: Organization or Person, Local business with address, phone and opening hours, WebSite, breadcrumbs, Article, and per page Product, Software app or FAQ. Rich-result badges appear only where Google still shows rich results.
* **Breadcrumbs** block and `[seofx_breadcrumbs]` shortcode.
* **Social sharing**: Open Graph and X (Twitter) cards, with a default image.
* **Redirects**: 301, 302, 307 and 410, regular expressions, and CSV import and export. A redirect is added automatically when you change a page's address.
* **"Not found" (404) log** that shows which missing addresses people try, with one-click redirects. No IP addresses are stored.
* **Search engine verification** for Google, Bing, Yandex and Pinterest, **Google Analytics 4 / Google Tag Manager** by ID, and optional **IndexNow** so Bing and others hear about changes right away.
* **2-minute setup wizard** with best-practice defaults for a business, shop, blog or personal site.
* **Move from another SEO plugin**: import from Yoast SEO, Rank Math, All in One SEO, SEOPress and The SEO Framework, with a preview first and Undo after.

= Made for everyone =

* **Simple and Advanced views**: Simple shows what most sites need; Advanced shows every setting.
* **Light, dark or match your device.**
* **Accessible**: works with the keyboard and screen readers, and meets WCAG AA contrast.
* Right-to-left languages are supported, and every text can be translated.
* **Plays nicely**: while another SEO plugin is active, SEO Fixer pauses its own tags, so your pages never get duplicates.

= Private by design =

SEO Fixer does not track you and does not send your content anywhere. It adds no CSS or JavaScript to your pages; only Google's own code is added, and only if you enter an Analytics ID. Optional features that contact another service are off by default and are listed under "External services" below.

== Installation ==

1. Go to **Plugins → Add New** and search for "SEO Fixer".
2. Click **Install Now**, then **Activate**.
3. Open **SEO Fixer → SEO Health** and click **Start the 2-minute setup**, or **Scan my site** to see your score right away.

SEO Fixer needs WordPress 6.4 or later and PHP 7.4 or later with the "mbstring" extension, which almost every host has.

== Frequently Asked Questions ==

= Does SEO Fixer send my data anywhere? =

No. Everything runs inside your WordPress site. The only exceptions are features you switch on yourself (IndexNow, Google Analytics); see "External services".

= Does SEO Fixer use AI to write descriptions and alt text? =

No. Suggestions come from your page's own text, headings, titles and image file names, worked out on your server. Nothing is sent to an AI service, and there is nothing to pay for. You can review and edit every suggestion before it's applied.

= Can I undo a fix? =

Yes. Every change SEO Fixer makes, including "Fix all", automatic fixes and imports, is listed under SEO Health → Changes with an Undo button. If you edited a value after the fix, Undo leaves your edit alone.

= Will it slow down my site? =

No. SEO Fixer adds no stylesheets or scripts to your pages, and its sitemaps are cached. Heavy work, like the health scan, runs in short steps in the background or while you watch a progress bar. It doesn't run during your visitors' page views.

= Does it work on large sites? =

Yes. The scan, "Fix all", Undo and imports all work in small steps, so they don't hit server time limits. They were tested with 5,000 pages.

= Can I use SEO Fixer together with another SEO plugin? =

SEO Fixer detects Yoast SEO, Rank Math, All in One SEO, SEOPress, The SEO Framework, Slim SEO and Squirrly SEO. While one of them is active, SEO Fixer pauses its own output so your pages never get duplicate tags.

= Can I move my settings from Yoast SEO, Rank Math or another SEO plugin? =

Yes. Go to SEO Fixer → Tools → "Move from another SEO plugin". SEO Fixer can import from Yoast SEO, Rank Math, All in One SEO, SEOPress and The SEO Framework:

* SEO titles and meta descriptions, with their variables converted;
* hidden pages, "don't follow links" and canonical addresses;
* social sharing titles, descriptions and images;
* categories and tags;
* the homepage title and description, the title separator and verification codes;
* redirects from Rank Math, Yoast SEO Premium and All in One SEO Pro.

You see exactly what will be imported before anything changes. Values you already set in SEO Fixer are never replaced, the other plugin's data is only read (so you can switch back), and the whole import can be undone under SEO Health → Changes. The other plugin can stay active while you import; deactivate it afterwards and SEO Fixer takes over.

= What happens to my data if I delete the plugin? =

Deactivating never removes anything. When you delete the plugin, your settings are kept by default, in case you reinstall. Turn off "Keep my settings if I delete the plugin" under SEO Fixer → Settings to remove everything SEO Fixer stored: its settings, the SEO titles and descriptions you wrote for pages, redirects, the "not found" log, health results and the change history. Your posts, pages and images are never touched, including alt text SEO Fixer added to them.

= Is SEO Fixer available in my language? =

Every text in SEO Fixer can be translated, including the editor sidebar. Translations are made by volunteers at translate.wordpress.org and installed automatically by WordPress.

== Screenshots ==

1. SEO Health: your score, what to fix first and quick site checks.
2. One-click fixes: review suggested descriptions, edit them, then apply one or fix them all.
3. Every change is listed and can be undone.
4. The SEO Fixer box in the editor: Google preview, live checklist and the page's own title and description.
5. Titles & descriptions settings with a live preview of your homepage in Google.
6. Redirects and the "not found" log, with one-click redirects for missing pages.
7. Move from another SEO plugin: see exactly what will be imported before anything changes.
8. The 2-minute setup wizard.

== External services ==

SEO Fixer works without any outside service. The features below are off until you switch them on or enter an ID, and each one is clearly labelled in the settings.

= IndexNow =

When "Tell search engines about changes right away" is on (Settings → Search engines & analytics), SEO Fixer tells the IndexNow service when you publish, update or delete a page, so participating search engines such as Bing and Yandex can update their results sooner.

* What is sent: the addresses (URLs) of the pages that changed, your site's host name and the IndexNow key that SEO Fixer created for your site. No content, visitor data or personal data is sent.
* When: a couple of minutes after a page is published, updated, moved or deleted, and when you click "Send a test now".
* Where: https://api.indexnow.org/indexnow, which shares each submission with all participating search engines.
* IndexNow: https://www.indexnow.org/ — terms: https://www.indexnow.org/terms — Microsoft (Bing) privacy statement: https://privacy.microsoft.com/privacystatement

= Google Analytics and Google Tag Manager =

When you enter a Google Analytics measurement ID ("G-…") or a Google Tag Manager container ID ("GTM-…") under Settings → Search engines & analytics, SEO Fixer adds Google's code to your pages. Your visitors' browsers then load it from https://www.googletagmanager.com and send visit data (such as the page address, referrer, device and browser details and an anonymous identifier stored in a cookie) to Google, which shows it in your Google Analytics account. SEO Fixer itself never receives or stores this data. Visits from logged-in editors are left out unless you change that setting, and "Wait for consent" enables Google Consent Mode.

* Google Analytics terms: https://marketingplatform.google.com/about/analytics/terms/us/
* Google Tag Manager terms: https://marketingplatform.google.com/about/analytics/tag-manager/use-policy/
* Google privacy policy: https://policies.google.com/privacy

The site verification codes for Google, Bing, Yandex and Pinterest are only printed on your homepage as meta tags; SEO Fixer does not contact those services.

== Changelog ==

= 1.0.0 =
* First public release.
