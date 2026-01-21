=== AI Translator for Elementor and Polylang ===
Contributors: meeptechdev
Tags: elementor, polylang, translation, ai, openai
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Requires Plugins: elementor, polylang
Stable tag: 1.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered translation for Elementor pages with Polylang. Translate your content instantly using OpenAI.

== Description ==

AI Translator for Elementor and Polylang is an AI-powered translation solution for WordPress.

**No APIs and no complicated setup required.**
Translate your Elementor-powered pages seamlessly using OpenAI's advanced translation engine. The newest version also adds support for Gutenberg blocks and the WPML translation engine.

**Features:**

* One-click translation for Elementor pages (single page or bulk)
* Polylang integration (Free and Pro versions supported)
* WPML support added
* Gutenberg block-based pages supported
* OpenAI-powered translations for natural, contextual results
* No API setup needed – we do the rest!
* 1,800 words per week included for free

**How it works:**

1. Add the required site languages in Polylang or WPML
2. Go to the AI Translator for Elementor and Polylang plugin page
3. Select your Elementor page
4. Choose target language(s)
5. Click Translate – the AI does the rest!

**Want a higher word limit?**

We offer affordable plans to fit your needs:

* **Starter – $9.99/month** – Perfect for landing pages, portfolios, and small business sites. 1 site, ~18,000 words per month.
* **Agency – $34.99/month** – Best for agencies, freelancers, and managing client sites. Up to 10 sites.
* **Enterprise – $53.99/month** – Scalable solution for large businesses and white-label use. Unlimited sites.

👉 [Upgrade your license here](https://meeptech.com/products/ai-translator-for-elementor-polylang#pricing)

== Installation ==
1. Upload the plugin folder to `/wp-content/plugins/` or install via the WordPress Plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Add the languages you need in Polylang Settings (Languages → Languages tab).
4. Navigate to AI Translator plugin page.
5. Start translating your Elementor pages!

== Frequently Asked Questions ==
= How many words can I translate for free? =
The free version includes 1,800 words per week. After reaching the limit, there's a weekly cooldown period before you can translate again.

= Does this require Elementor Pro? =
No. It works with Elementor free and Elementor Pro.

= Does this require Polylang Pro? =
No. It supports both Polylang free and Polylang Pro.

= Which AI provider do you use? =
OpenAI via our secure translation API. We handle all API communication to ensure security and reliability.

= Will this modify my existing content? =
No. Translations are applied to translated copies in the target language. Your original content remains unchanged.

= What happens when I reach my word limit? =
You'll need to wait for the weekly reset, or upgrade to a PRO plan for higher limits and no waiting periods.

= How do I check how many words I have left? =
Visit AI Translator → Dashboard to see your current usage and words remaining.

== Screenshots ==
1. Dashboard with translation stats and usage info.

== Changelog ==
= 1.2.3 =
* Minor bug fixes

= 1.2.2 =
* Fixed license plan sync issue - Enterprise/Agency plans now correctly sync from Freemius
* Added auto-sync for plan mismatches (checks hourly)
* Added "Sync Plan" button in settings for manual plan synchronization
* Improved Luxembourgish translation support with language-specific instructions
* Fixed HTML structure corruption during translation (SVG elements, nested tags)
* Fixed issue where original text was appearing above translation
* Added language instructions for rare languages (Frisian, Breton, Occitan, Corsican, Romansh)
* Plugin now sends language-specific instructions to translation server for better accuracy
* Improved license activation to properly extract plan from Freemius license

= 1.2.1 =
* Added basic WPML support

= 1.2.0 =
* Added Gutenberg/Block Editor translation support
* Added "Manage Languages" button in settings for quick access to Polylang/WPML
* Plugin now visible in menu even when dependencies are missing (with setup guide)
* Fixed translation not being applied to Gutenberg content
* Fixed HTML tag corruption during translation
* Fixed API response mapping for translations
* Fixed HTML entity handling during text replacement
* Improved error messages with direct links to install required plugins
* Renamed plugin references to "AI Translator for Elementor" for consistency

= 1.1.5 =
* Added "Activate License" submenu for PRO version
* Users can now enter license key without reactivating plugin
* Improved license activation flow

= 1.1.4 =
* Fixed YouTube video error 153 in welcome popup
* Video now opens on YouTube instead of embed (more compatible)
* Fixed all remaining emoji encoding issues
* Fixed translation completion detection

= 1.1.3 =
* Fixed JavaScript syntax error causing dropdowns to not work
* Fixed language selector not responding to clicks

= 1.1.2 =
* Added server-side Freemius license validation
* License expired users are now properly downgraded to Free plan
* Added admin notice for expired licenses
* Dashboard now shows "Expired" status when license is invalid
* Updated pricing: Starter $9.99/month, Agency $34.99/month
* Added translated readme files for 9 languages
* Improved license status accuracy

= 1.1.1 =
* Added pricing plans to readme
* Improved PRO build script with automatic readme branding
* Minor improvements

= 1.1.0 =
* Fixed plugin name (removed PRO from free version)
* Updated readme for free version
* Added admin interface translations for 9 languages
* Added optional usage analytics with user consent
* Fixed WordPress plugin checker errors
* Performance improvements

= 1.0.8 =
* Bug fixes and improvements

= 1.0.7 =
* Initial public release
* Elementor + Polylang integration
* OpenAI translation engine
* Usage tracking and limits
* Clean admin interface

== Upgrade Notice ==
= 1.1.0 =
Fixed plugin name and updated readme. Recommended update.

== Pricing ==

= Free =
**$0**
Perfect for testing the plugin

– 1 Website
– 1,800 words/week
– Latest OpenAI Models
– Polylang Compatible
– Elementor Support
– 7-day cooldown after limit

= Starter — Most Popular =
**$9.99/month**
For small businesses and landing pages

– 1 Website License
– ~18K words/month (~46 pages)
– 100,000 tokens included
– Latest OpenAI Models
– Polylang Pro Compatible
– Elementor & Elementor Pro Support
– Email Support
– Continuous Updates

= Agency =
**$34.99/month**
Optimal for studios and agencies

– Up to 10 Website Licenses
– ~89K words/month (~232 pages)
– 500,000 tokens included
– Latest OpenAI Models
– Priority Email Support
– Continuous Updates

Upgrade your license inside WordPress: AI Translator → Pricing, or visit [meeptech.com](https://meeptech.com/products/ai-translator-for-elementor-polylang/).

== External Services & Privacy ==

This plugin connects to external services to provide AI-powered translations.

= Services Used =

**MeepTech API** (meeptech.com)
* Handles usage tracking and word count enforcement
* Receives: Website URL, word counts, language pairs
* Does NOT store your translated content

**OpenAI** (openai.com)
* Processes translation requests using AI language models
* Receives: Text content to be translated
* Content is sent for processing only, not stored by us

**PostHog** (posthog.com) - Optional
* Anonymous usage analytics (opt-in only)
* Disabled by default, requires explicit consent

= What We Collect =

✅ Website URL (for authentication)
✅ Word counts and language pairs (for plan limits)

= What We Do NOT Collect =

❌ Your translated content is not stored
❌ Personal information or email addresses
❌ User or visitor data

= Privacy & Security =

For data deletion, export, or GDPR/CCPA inquiries, contact sales@meeptech.com with your website URL.

* [MeepTech Privacy Policy](https://meeptech.com/privacy-policy)
* [MeepTech Terms of Service](https://meeptech.com/terms)
* [OpenAI Privacy Policy](https://openai.com/policies/privacy-policy)
