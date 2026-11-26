=== Meep AI Translator – AI Translation for Elementor, Polylang and WooCommerce ===
Contributors: meeptech, denislwh
Tags: polylang, ai translation, elementor, translation, multilingual
Requires at least: 5.8
Tested up to: 7.2
Requires PHP: 7.4
Stable tag: 1.8.51
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Translate Elementor, Gutenberg and WooCommerce sites with AI in one click. Works with Polylang, WPML or built-in languages. Layouts stay intact.

== Description ==

Meep AI Translator makes your WordPress site multilingual with AI. Pick pages, pick languages, click Translate: your Elementor and Gutenberg layouts stay exactly as you designed them, and the translated pages are ready to publish.

It reads Elementor's own data structure instead of treating your page as plain text, so widgets, columns and styling are never broken by translation.

[**Live Interactive Demo**](https://meeptech.com/products/ai-translator-for-elementor-polylang#demo) | [**Pro Plans & Pricing**](https://meeptech.com/products/ai-translator-for-elementor-polylang#pricing) | [**Full Documentation**](https://meeptech.com/products/ai-translator-for-elementor-polylang/documentation)

**What you get**

* One-click AI translation of pages, posts, custom post types and Elementor templates, one at a time or the whole site in bulk
* WooCommerce: products, variations, attributes, categories, cart and checkout in every language
* Built-in multilingual system: clean /de/ and /fr/ URLs, translated slugs, hreflang tags and a language switcher for menus, widgets and shortcodes. No other plugin needed, or connect Polylang or WPML if you already use them
* Review editor: check every translated segment side by side with the original and edit it inline
* SEO titles, descriptions and social tags translated for Yoast SEO, Rank Math, SEOPress and SureRank
* Menus, widgets and contact forms (SureForms, Contact Form 7, WPForms, Gravity Forms) translated too
* Translation memory and glossary: repeated text is translated once and reused for free, and brand names stay untranslated
* Auto-sync: edit the original and the translations update in the background
* Fast ChatGPT translation on every plan; Claude and DeepL on paid plans. No API key needed

**Free plan:** 7,200 words every month, no API key and no credit card.

**How it works**

1. Install and activate. A short setup wizard suggests languages for your site and adds a language switcher to your menu.
2. Open the dashboard. Your homepage and languages are already selected.
3. Click Translate. Pages are translated in the background, two at a time.
4. Review and publish. Visitors switch language from the menu.

**Also works with** custom fields from ACF, JetEngine, Meta Box and Pods, JetEngine listings and JetThemeCore templates, and SureCart products.

**Need more words?** Paid plans start at $9.99/month, with agency and lifetime options. [See plans](https://meeptech.com/products/ai-translator-for-elementor-polylang#pricing)

**Guides**

* [How to translate an Elementor site with AI](https://meeptech.com/blog/translate-elementor-site-with-ai)
* [Free AI translation for Polylang](https://meeptech.com/blog/ai-translation-for-polylang)
* [Documentation](https://meeptech.com/products/ai-translator-for-elementor-polylang/documentation)

== Installation ==
1. Upload the plugin folder to `/wp-content/plugins/` or install via the WordPress Plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Choose your language setup option:
   - **Built-in (Recommended):** Use the plugin's built-in language system — no extra plugins needed
   - **Polylang:** Go to Languages → Languages tab → Add your languages
   - **WPML:** Go to WPML → Languages → Add languages and configure
4. Follow the setup wizard, or open Meep Translator in your WordPress menu.
5. Start translating your pages, posts, and WooCommerce products!

== Frequently Asked Questions ==

= How do I translate Elementor pages without breaking the layout? =
Meep AI Translator reads Elementor's internal JSON structure directly instead of treating your page as plain text. This means every widget, column, heading, and button stays exactly where you placed it after translation. No broken sections, no collapsed widgets, no manual CSS fixes needed.

= Can I translate a WordPress site without Polylang or WPML? =
Yes. Meep AI Translator includes a fully built-in language management system. You can add languages, configure URL prefixes (/fr/, /de/), and get a language switcher widget — all without installing any additional plugins. Polylang and WPML are still supported if you prefer them.

= How do I use AI translation with Polylang? =
Meep AI Translator connects directly to Polylang without requiring separate API keys. Once Polylang is installed with your target languages, Meep AI Translator automatically detects your translation pairs and translates entire pages, posts, and forms into Polylang's language slots while keeping Elementor layouts pixel-perfect. Read our full [free AI translation for Polylang guide](https://meeptech.com/blog/ai-translation-for-polylang) for step-by-step instructions.

= How do I add multiple languages to WooCommerce? =
Install Meep AI Translator, choose Built-in language system during setup, add your target languages, then go to the Products tab in the dashboard. Select the products you want to translate, choose target languages, and click Translate. Product titles, descriptions, attributes, variations, and gallery alt text are all translated automatically.

= Can I translate my contact forms? =
Yes. Meep AI Translator translates SureForms, Contact Form 7, WPForms, Gravity Forms, and JetFormBuilder. Field labels, descriptions, placeholders, dropdown/radio/checkbox options, button text, and confirmation messages are translated automatically at render time. Build the form once in your default language, click "Auto-translate strings" on the Custom String Translations screen, and the labels appear in every language — the form logic and submitted data stay untouched. Use the "Scan forms" button to see which forms were detected.

= Does it work with SureCart? =
Yes. SureCart products appear on the Products tab in the dashboard, alongside WooCommerce products. Each product's name and description are translated and reviewed the same way, and the translated version is shown in the visitor's language everywhere the product renders — product pages, collection grids, and checkout line items. SureCart's own buttons and checkout labels are covered by the string-translation system.

= Does Elementor translation work with dynamic widgets? =
Meep AI Translator translates static Elementor content — text, headings, buttons, image captions, and any text stored inside Elementor's JSON. Dynamic widgets that pull content from external sources or custom fields at runtime are skipped to avoid conflicts. Standard Elementor widgets are fully supported.

= Does this work with Elementor Free or only Elementor Pro? =
Both. Meep AI Translator works with Elementor free and Elementor Pro. No Pro license is required for translation to work.

= Do I need my own OpenAI, Claude, or DeepL API key? =
No. All translation routing is handled through our secure, optimized cloud translation gateway — no API keys, no separate subscriptions, and no complicated setup required. Just install, pick your engine (or leave on Auto), and translate. Claude and DeepL are included in paid plans; the free plan uses ChatGPT.

= Which AI translation engine is best for my website? =
You can switch AI models anytime under Meep Translator → Settings → General & Performance:
* Auto (Recommended, all plans): our fast default, OpenAI ChatGPT, tuned for website content.
* OpenAI ChatGPT (all plans): maximum speed and global coverage across 100+ languages.
* Anthropic Claude (paid plans): best for marketing copy, brand voice, storytelling, and nuanced tone.
* DeepL Pro (paid plans): the gold standard for European languages, technical documentation, and precise phrasing. For languages DeepL doesn't offer, ChatGPT is used automatically.

= What is Smart Translation Memory and how does it save words? =
Smart Translation Memory (TM) analyzes your site's content segments and deduplicates repeated sentences, headings, buttons, and footers. When an identical phrase appears across multiple pages (e.g. "Read More", "Get in Touch", or common guarantee boxes), it is translated once and reused instantly everywhere at 0 additional word cost. On sites with a lot of repeated text this noticeably cuts word usage.

= How does Autonomous Background Auto-Sync work? =
When you edit any page, post, or WooCommerce product in your default language and click "Update", the plugin calculates a content hash. If changes are detected, it schedules an asynchronous background translation task. Translation Memory ensures only newly edited or added sentences consume words, automatically keeping all translated languages in sync with zero manual work.

= How many words can I translate for free? =
The free version includes 7,200 words per month, which resets automatically at the start of each month. There are no one-time limits — you can keep translating month after month on the free plan.

= Does this require Polylang Pro? =
No. It supports both Polylang free and Polylang Pro, and you don't need Polylang at all: the built-in language system replaces it completely.

= Will this modify my existing content? =
No. Translations are created as separate pages or posts in the target language. Your original content remains completely unchanged.

= How do I check how many words I have left? =
Go to Meep Translator → Dashboard to see your current usage, words remaining, translation memory stats, and monthly reset date.

= Does it translate my whole WooCommerce store, including cart and checkout? =
Yes. Products, shop and category loops, the mini-cart preview, and the cart and checkout pages all render in the visitor's language — including product links, prices, and the "View cart" / "Checkout" buttons. WooCommerce's own interface strings follow the installed language packs; for admin-entered text such as payment gateway titles/descriptions and the checkout privacy notice, use the built-in String Translations tool (with one-click auto-translate).

= Does it work with custom post types (JetEngine, ACF, etc.)? =
Yes. All UI-enabled custom post types — including dynamic tag sources like Testimonials, Employees, Team Members, Portfolios, Case Stories, and FAQs — can be translated individually from the dashboard or in bulk via Translate All. Custom fields and repeaters from ACF, JetEngine, Meta Box, and Pods are preserved and translated automatically.

= Can I translate image galleries and media attachments? =
Yes! When translating Case Studies, Portfolios, or WooCommerce products, custom gallery fields (ACF Gallery, JetEngine media fields, and WooCommerce product galleries) are automatically copied and synchronized to the translated post so your Elementor image gallery widgets work immediately.

= Does it duplicate image files on my server or waste disk space? =
No. By default, Meep AI Translator reuses your existing image files across all languages and translates the Alt text and SEO directly on the page without cluttering your Media Library. If you optionally enable "Media Library Duplication" (for workflows requiring language-specific attachment management in the WordPress Media Library), it creates linked database records that share the exact same physical files on disk — zero duplicate files and zero wasted disk space!

== Screenshots ==
1. Dashboard — pick pages and languages, see word estimates and progress, and translate in one click.
2. Live progress while translating — 2 pages at a time, language chips update as each translation finishes.
3. Setup wizard — smart language suggestions, a menu language switcher and publish options in one step.
4. Translate All — scan your whole site and see how many words each content type needs.
5. Translate All — page-by-page breakdown, word estimate against your plan, and one-click start.
6. Review editor — check every translated segment side by side with the source and edit it inline.
7. Built-in language system — add languages, translated URL slugs and clean /de/ /fr/ URLs, no extra plugins.
8. Language switcher settings — flags, names, dropdown styles, shortcode, widget and menu integration.
9. Your translated site — German homepage with the language switcher in the theme menu.
10. SEO translation — titles, descriptions and social tags for Yoast SEO, Rank Math, SEOPress and SureRank.
11. WooCommerce — products, variations, attributes, categories and image alt text.
12. Elementor templates, menus and widgets translation.
13. Glossary — keep brand names untranslated or force your own translations per language.
14. Translation memory (reuse at zero word cost) and Brand Voice tone presets.
15. Choose the AI engine — ChatGPT on every plan, Claude or DeepL on paid plans — plus background auto-sync.
16. Translation history — every translation with words, time and status.
17. Setup wizard — built-in multilingual system, or connect Polylang / WPML.
18. Setup complete — your languages are ready in about a minute.

== Changelog ==
= 1.8.51 =
* Compatibility: Tested with WordPress 7.2 and PHP 8.6.
* Fixed: The dashboard language chips no longer use jQuery's removed $.trim(), so they keep working with the jQuery Migrate update in WordPress 7.2 and the coming jQuery 4.
* Improvement: Cleared the WordPress.org Plugin Check errors: translator notes for all strings with placeholders, and output escaping on dashboard counters, numbers and security tokens. Nothing changes on screen.
* Improvement: Plugin name wording updated to follow WordPress.org naming rules ("…Polylang and WooCommerce").

= 1.8.50 =
* Improvement: The 80% monthly usage notice now resets dismissal each billing month, uses gentler wording, and hides free-tier bonus prompts on paid plans.
* Fixed: Preserved the first activation timestamp across monthly usage syncs and unified translation success counters so the review prompt triggers reliably after 3+ translations.
* Fixed: Removed a duplicate 100% word-limit admin notice so users at their limit see only one clear notice.

= 1.8.49 =
* Improvement: Upgrade buttons in the free version open a simpler upgrade page.

= 1.8.48 =
* Fixed: Improved responsive layout of the main dashboard on tablets and mobile screens (compact stats cards, bulk action bar, content type tabs, sort bar, and stacked page rows).

= 1.8.47 =
* Fixed: The Extra 50K Words top-up pack option is now shown only to active paid-plan users, while free-plan users see direct plan upgrade options.
* Improvement: Added a quick link in the dashboard header to unlock Anthropic Claude and DeepL Pro engines on paid plans.

= 1.8.46 =
* Fixed: In the free version, the "Upgrade Plan" buttons on Translate All, the page/post editor sidebar, and word-limit notifications now open the plans page instead of an unavailable internal admin screen.
* Improvement: Added direct "Dashboard", "Settings", and "Upgrade to Pro" action links on the WordPress Plugins screen and in the Meep Translator sidebar menu.

= 1.8.45 =
* Change: Claude and DeepL engines are now included in paid plans; the free plan translates with ChatGPT. On a free plan the two engines are shown as "Paid plans" in Settings, and a previously saved Claude/DeepL choice runs on ChatGPT.
* Improvement: The "Auto" engine is described as what it is: our fast ChatGPT default.
* Performance: The page title, excerpt and SEO title/description are now translated in the same request as the page content instead of separate follow-up requests, so each translation finishes a few seconds sooner (requires Translation Memory, on by default).
* Performance: The dashboard checks for finished translations after 2 seconds instead of always waiting 5.
* Fixed: The setup wizard no longer re-translates the homepage (and spends words) when that translation already exists.
* Fixed: Translate All no longer pre-selects empty untitled pages.
* Fixed: Progress text no longer says "2 pages at a time" when only one page is left.

= 1.8.44 =
* Fixed: Translating a variable WooCommerce product whose variations use a custom (product-level) attribute kept the variations working: the translated attribute name and options now carry over to every variation. Before, customers on the translated product got "no products matched your selection" or the wrong price.
* Fixed: Elementor global colours/fonts and dynamic tags (e.g. a Post Title heading in a Theme Builder template) are no longer sent for translation, so they can't break on the translated page and aren't counted as words.
* Fixed: Editing an Elementor v4 (atomic) heading or paragraph in the review editor no longer wipes the widget's content structure on save.
* Security: The review editor only saves the custom fields it lists for a post, instead of any post meta key.
* Security: Translating, re-translating, publishing and bulk-deleting translations from the dashboard now check the user's rights on each page, not just the role in general (Contributors could act on other users' pages).

= 1.8.43 =
* Fixed: Setup wizard toggles (language switcher, publish immediately, usage data) now show their on/off state; before, they always looked off.
* Improvement: The setup wizard's final button says "Translating your homepage…" while the demo translation runs, instead of silently greying out for up to 40 seconds.
* Fixed: Menu language switcher names were invisible on themes whose header links are white over a white dropdown (e.g. Astra transparent header); the text colour now adapts automatically.
* Fixed: The "Native" name format now really shows native names (Deutsch, Español, Français…) in the language switcher; before, it showed English names. Custom names still take priority.

= 1.8.42 =
* Feature: SureRank SEO titles, descriptions and social (Facebook/X) titles are now translated, like Yoast SEO, Rank Math and SEOPress. The SEO settings screen now also recognises SEOPress and SureRank, and SEO fields that contain only variables (e.g. "%title% - %site_name%") are no longer sent for translation.
* Improvement: Setup wizard shows a clear error with "Try again" if the server doesn't answer within 90 seconds, instead of waiting forever.
* Improvement: Dashboard progress counts only real content (pages, posts, products); forms, templates and empty untitled pages no longer inflate the total.
* Fixed: German, Danish, Catalan, Serbian and Bosnian translated URL slugs now use the language's own transliteration ("Über" becomes "ueber", not "uber").
* Fixed: Completed items in the setup checklist show a tick icon again.
* Feature: New option "Delete all plugin data when the plugin is deleted" (Settings > Tools & Advanced, off by default). Translated pages and posts are always kept.
* Feature: After setup, the dashboard opens with your homepage and chosen languages already selected, so the first translation is one click away.
* Performance: The dashboard translates 2 pages at a time (each page's languages stay in order), roughly halving the time for multi-page batches. Developers can change this with the aitrfoel_dashboard_concurrency filter.
* Improvement: Language chips and "x/y translated" in the dashboard table update as each translation finishes, instead of only at the end of the batch.
* Fixed: Translation groups are now allocated atomically, so two pages translated for the first time at the same moment can no longer end up linked to each other.

= 1.8.41 =
* Performance: Setup wizard no longer hangs for about a minute on "Applying language settings": WordPress and plugin translation packs for new languages now install in a background request.
* Improvement: Setup wizard suggests up to 6 target languages for your site (languages from TranslatePress or a previous WPML install, installed WordPress language packs, WooCommerce selling countries, common pairs for your site language); the rest of the list is folded under "More languages".
* Fixed: Setup wizard's default (source) language now follows the site language instead of always English.
* Feature: The setup wizard can add a language switcher to the main menu (on by default). It appears as a dropdown with the current language and the others underneath, using the theme's own menu styling and mobile toggle. The style can be changed in Settings.
* Feature: The setup wizard asks whether translations should be published immediately or saved as drafts for review (publish by default).
* Improvement: The setup wizard's "translate your homepage" demo now also runs for the built-in language system.
* Improvement: Anonymous usage data can now be turned on or off at any time under Settings > Tools & Advanced > Privacy & Data Collection.
* Improvement: Wizard language buttons show an empty circle until selected (the tick made them look already selected).
* Privacy: Language flags load from the plugin's bundled images; the flag CDN is only used for the few flags not bundled.
* Improvement: The share-for-bonus-words banner appears only after the first successful translation, and review prompts wait until 3 days after setup.
* Fixed: Elementor's "Default Kit" (global styles) is no longer listed as translatable content; untitled pages show "(no title) #ID".

= 1.8.40 =
* Fixed: Dashboard word estimate no longer doubles the expected word usage; it now matches what is actually deducted (1x source words per language), consistent with Translate All.
* Fixed: Settings "unsaved changes" indicator clears when a change is undone.
* Fixed: Translation progress timer now shows the real duration when a job finishes instead of resetting to 0:00.

= 1.8.39 =
* Fixed: Resolved Freemius background cron sync error when running with add-on packs in non-admin contexts.
* Fixed: Translation History now records background cron and auto-sync translations, normalizes language codes to prevent duplicate language counts, and captures accurate word counts on async jobs.
* Fixed: Review Translations and admin subpage hero headings now match correctly across all WordPress admin languages.
* Fixed: Universal internal link localization across Elementor URL controls and expanded Elementor Pro Form field extraction (options, acceptance text, step buttons, HTML fields).
* Improved: Removed free-tier bonus prompts and duplicate upgrade buttons for active paid plans on the Dashboard.
* Improved: Added a unified sticky Save Settings bar, distinct source-language badge styling, singular word badge formatting, published item count labels on Translate All, and escaped admin notice markup.

= 1.8.38 =
* Improved: Updated Freemius WordPress SDK to version 2.13.5 with defensive is_migration error handling.
* Fixed: Eliminated upgrade plan caching delay, ensuring immediate recognition of paid tiers in dashboard and stats bar.
* Improved: Hooked Freemius plan and license sync events to immediately invalidate stale cache and synchronize remote account state.

= 1.8.37 =
* Fixed: Resolved false "Limit Reached" admin notice and top admin bar badge for unlimited and Agency Lifetime plans.
* Improved: Suppressed upgrade prompts and bonus words share links in admin hub for active PRO and Agency Lifetime license holders.
* Fixed: Prevented blank white screen during manual license activation.
* Fixed: Language code normalization now supports regional language variants (e.g. es-mx, pt-br, zh-cn) across all multilingual integrations.
* Fixed: Front page language switcher link for default language now points to clean root URL when browsing translated front pages.
* Improved: Auto-healing for orphaned translations missing taxonomy terms or translation group records.
= 1.8.36 =
* Security: Delete, publish and bulk-delete translation actions now check per-post permissions (delete_post / publish_post), so Contributors and Authors can no longer act on content they don't own.
* Security: Translation settings, settings debug and job status endpoints now require the appropriate capability.
* Fixed: Removed a leftover debug log (wp-content/debug_sorting.log) that was written on every front-end query when post-order sync was active.
* Performance: Bulk-jobs table schema check now runs only when the schema version changes instead of on every page load.
* Performance: If the MeepTech API is unreachable, free-site registration now backs off for an hour instead of retrying (with a 30s timeout) on every admin page load.
* Fixed: Analytics opt-in from the setup wizard is now saved correctly; the wizard opt-in request is nonce-protected.
* Improvement: Review prompts no longer request a specific star rating, and the review invite is no longer shown alongside the bonus-words offer.
* Removed: Developer-only analytics test URL parameter.
* Fixed: Word usage shown in the plugin now stays in sync with the server after a cooldown ends, instead of being reset to 0 locally.
* Privacy: Admin fonts (Inter, JetBrains Mono) are now bundled with the plugin instead of loaded from Google Fonts, so no IP address is sent to Google.
* Readme: Documented all external endpoints (activation registration, deactivation feedback) and added SureForms to the contact-forms FAQ.
* Fixed: Language code normalization now supports regional language variants (e.g. es-mx, pt-br, zh-cn) across all multilingual integrations, preventing translated pages from being mislabeled as default language.
* Fixed: Front page language switcher link for the default language now correctly points to the root URL when on translated front pages.
* Improvement: Auto-heal orphaned translations missing taxonomy terms or translation group links via stored tracking metadata.

= 1.8.35 =
* Improvement: Standardized AI translation engine labels to Anthropic Claude and OpenAI ChatGPT across settings, prompts, and documentation to reflect the latest model capabilities.

= 1.8.34 =
* Feature: Added 1-click Quick-Add Target Language card directly on the Dashboard and Translate All screens when 0 target languages are configured (supports both Built-in Language System and Polylang).
* Improvement: Automatic target language pre-selection on Dashboard and Translate All when 1 target language is available, enabling 1-click translation as soon as pages are selected.
* Improvement: Added inline + Add another language quick-selector inside the Dashboard bulk translation bar dropdown and Translate All Step 2.
* Fixed: Preserved checked target languages in the Dashboard bulk bar when selecting additional pages and enabled Select All checkboxes across all content tabs.

= 1.8.33 =
* Fixed: Restored welcome onboarding redirect upon activation in the Free edition.
* Improvement: Hardened automatic remote quota and site initialization on plugin activation and monthly admin checks.

= 1.8.32 =
* Feature: Enhanced SureForms translation support — added extraction and translation of SureForms Gutenberg block attributes (`help`, `errorMsg`, `duplicateMsg`, `subHeading`), post meta (`_srfm_submit_button_text`, `_srfm_form_confirmation`), `[srfm_form]` shortcodes, `wp:srfm/form` blocks, and Elementor SureForms widgets.
* Feature: Added in-modal upgrade, Extra 50K Words top-up ($4.99), and +2,500 Free Bonus Words claim flow when reaching the monthly word limit on the Dashboard or Posts/Pages list translator.
* Feature: Added non-intrusive 5-star review milestone prompt after translating 3+ pages or 2,500+ words.
* Improvement: Added UTM campaign attribution parameters to in-plugin upgrade and top-up links.

= 1.8.31 =
* Fixed: Resolved social bonus word claiming API verification, automatic fallback routing, and self-healing recovery for previously failed claims.
* Improvement: Surfaced the +2,500 Free Bonus Words referral tool directly on the main Dashboard, monthly quota stats bar, and global 80%/100% admin notices.
* Improvement: Added automatic clipboard copy of share text on click and instant quota refresh upon bonus redemption.

= 1.8.30 =
* Fixed: Resolved translation misalignment in Review Editor by pairing source and translated segments by structural path instead of positional array indexing.
* Fixed: Enhanced Elementor Toggle, Accordion, and Tabs widget text extraction to guarantee all tab titles and body content are detected, translated, and written back accurately.
* Fixed: Hardened server and client batch translation prompts and 1-to-1 index matching for single-word labels and divider elements.
* Fixed: Corrected in-plugin upgrade and support links to point directly to active destination endpoints.
* Fixed: Enhanced icon contrast on floating quick translate toolbar and feedback support button.

= 1.8.29 =
* Improvement: Single-line layout normalization for Posts & Pages floating translation toolbar.
* Feature: Added option to hide floating toolbar for session or permanently with instant database persistence.
* Feature: Added toggle for list screens floating toolbar in Meep Settings.
* Improvement: Standardized branding to "Meep" across floating toolbar and bulk actions dropdown.

= 1.8.28 =
* Feature: Added Meep dynamic translation toolbar on WordPress Admin Posts and Pages list screens (`edit.php`) with real-time word calculation and live quota tracking.
* Feature: Integrated "Translate with Meep AI" into standard WordPress bulk actions dropdown on all translatable post types.
* Feature: Added Date Created sorting (Newest first / Oldest first) and Title sorting (A-Z / Z-A) to the main Meep Translator Dashboard with default newest-first ordering.

= 1.8.27 =
* Fixed: Resolved critical error on free installations by safely guarding license status and Freemius SDK lookups.
* Fixed: Restored smooth hover color styling on top Admin Bar translation actions (Edit and View Original/Translation links).
* Improvement: Restored Meep Translator label in the WordPress admin sidebar.

= 1.8.26 =
* Improvement: Standardized AI translation engine labels across settings and dashboard.
* Improvement: Refined top admin bar icon styling to match native WordPress theme and locked flag dimensions.
* Fixed: Resolved dynamic PRO header title display on free installations.

= 1.8.25 =
* Improvement: Unified single-page translation actions and global statistics hub into a single, cohesive Meep top WordPress admin bar menu.

Older releases: see [changelog.txt](https://plugins.svn.wordpress.org/ai-translator-for-elementor-and-polylang/trunk/changelog.txt).

== Upgrade Notice ==
= 1.8.51 =
Ready for WordPress 7.2 and PHP 8.6, plus WordPress.org Plugin Check clean-up. No visible changes.

= 1.8.45 =
Faster translations, three small fixes, and Claude/DeepL now included in paid plans (the free plan uses ChatGPT).

= 1.8.44 =
Security and data-loss fixes: per-page permission checks on dashboard actions, safer review-editor saves, Elementor global styles and dynamic tags left untouched, and working WooCommerce variations with custom attributes.

= 1.8.43 =
Native language names in the switcher (Deutsch, Español, Français…), automatic dropdown text contrast on transparent headers, and setup wizard visual fixes.

= 1.8.42 =
Faster multi-page translation (2 pages at a time), one-click first translation after setup, German-friendly URLs and an optional clean uninstall.

= 1.8.41 =
Faster setup wizard (background language pack installation, smart target-language suggestions, native dropdown menu switcher) and bundled flag icons.

= 1.8.36 =
Security release: tightens permission checks on delete/publish/settings actions and removes a debug log file written on every page load. Update recommended.

== External Services & Privacy ==

This plugin connects to external services to provide AI-powered translations.

= Services Used =

**MeepTech API** (meeptech.com)
* Handles usage tracking, translation memory routing, and word count enforcement
* Receives: Website URL, word counts, language pairs, and text chunks for routing
* Does NOT store your translated content

**Anthropic Claude** (anthropic.com)
* Processes translation requests using Anthropic Claude language models, only for sites on a paid plan that select Claude
* Receives: Text content to be translated
* Data processed securely via API; prompts are not retained or used to train models

**DeepL** (deepl.com)
* Processes translation requests using DeepL Pro neural machine translation, only for sites on a paid plan that select DeepL
* Receives: Text content to be translated
* Compliant with European Union GDPR data protection standards

**OpenAI** (openai.com)
* Processes translation requests using OpenAI ChatGPT language models
* Receives: Text content to be translated
* Content is sent for processing only and not retained for model training

**When the plugin contacts MeepTech**
* On activation and on the first admin page load of each month: registers the site (website URL, plan name) with api.meeptech.com to provision the free monthly word quota
* When you translate: sends the text to be translated, language pair and website URL to meeptech.com
* Only if you choose "Submit & Deactivate" in the optional deactivation survey: sends your website URL, selected reason and any comment you type to meeptech.com (choose "Skip & Deactivate" to send nothing)

**flagcdn.com** (Flagpedia)
* Only for the few language flags not bundled with the plugin, an image is loaded from flagcdn.com in the admin; your browser's IP address is sent to that server.

**PostHog** (posthog.com) - Optional
* Anonymous usage analytics (opt-in only)
* Disabled by default, requires explicit consent

= What We Collect =

* Website URL (for authentication)
* Word counts and language pairs (for plan limits)

= What We Do NOT Collect =

* Your translated content is not permanently stored
* Personal information or customer email addresses
* User or website visitor data

= Privacy & Security =

For data deletion, export, or GDPR/CCPA inquiries, contact sales@meeptech.com with your website URL.

* [MeepTech Privacy Policy](https://meeptech.com/privacy-policy)
* [MeepTech Terms of Service](https://meeptech.com/terms)
* [DeepL Privacy Policy](https://www.deepl.com/en/privacy)
* [Anthropic Privacy Policy](https://www.anthropic.com/privacy)
* [OpenAI Privacy Policy](https://openai.com/policies/privacy-policy)
