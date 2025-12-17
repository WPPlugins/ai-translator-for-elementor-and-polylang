=== AI Translator for Elementor and Polylang ===
Contributors: meeptechdev
Tags: elementor, polylang, translation, ai, openai
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Requires Plugins: elementor, polylang
Stable tag: 1.1.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered translation for Elementor pages with Polylang. Translate your content instantly using OpenAI.

== Description ==
AI Translator for Elementor and Polylang is an AI translation solution for WordPress. Translate your Elementor-powered pages seamlessly with Polylang using OpenAI's advanced translation engine.

**Features:**
– One-click translation for Elementor pages
– Polylang integration (free and Pro versions supported)
– OpenAI-powered translations for natural, contextual results
– 1,800 words per week included free
– Clean admin interface without Elementor interference
– Dashboard with usage analytics

**How it works:**
1. Select your Elementor page
2. Choose target language(s)
3. Click translate - AI does the rest!

A PRO version with higher word limits is available at [meeptech.com](https://meeptech.com/products/ai-translator-for-elementor-polylang/).

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

== External Services ==

This plugin relies on external third-party services to function. Below is a detailed disclosure of what services are used, what data is transmitted, and when.

= MeepTech License & Usage API =

**Purpose:** Usage tracking and word count enforcement for translation plans.

**Service Provider:** MeepTech  
**API Endpoint:** https://meeptech.com/api/eat/get-usage  
**Data Transmitted:**
- Your website URL (for site authentication)
- Word count usage (number of words translated)
- Translation timestamps

**When Data is Sent:**
- When tracking word usage after each translation
- When checking remaining word limits before translation

**Privacy & Terms:**
- Privacy Policy: https://meeptech.com/privacy-policy
- Terms of Service: https://meeptech.com/terms
- Contact: sales@meeptech.com

= OpenAI Translation API =

**Purpose:** AI-powered translation of your content using OpenAI's language models.

**Service Provider:** OpenAI  
**API Endpoint:** https://api.openai.com/v1/chat/completions  
**Data Transmitted:**
- Text content to be translated (from your Elementor pages)
- Source and target language codes

**When Data is Sent:**
- When you initiate a page translation

**Privacy & Terms:**
- Privacy Policy: https://openai.com/policies/privacy-policy
- Terms of Service: https://openai.com/policies/terms-of-use
- API Terms: https://openai.com/policies/api-terms

**Important:** Your content is sent to OpenAI for processing. Please review OpenAI's data usage policies to ensure compliance with your content policies.

= PostHog Analytics (Optional) =

**Purpose:** Anonymous usage analytics to help improve the plugin (opt-in only).

**Service Provider:** PostHog  
**Data Transmitted (if opted in):**
- Plugin version, WordPress version, PHP version
- Features used (translation, bulk mode)
- Error reports for debugging

**When Data is Sent:**
- Only if you enable analytics in the welcome popup
- Never collects personal data, content, or API keys

**Privacy & Terms:**
- Privacy Policy: https://posthog.com/privacy

**Note:** Analytics is disabled by default and requires explicit user consent to enable.

== Screenshots ==
1. Dashboard with translation stats and usage info.

== Changelog ==
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

== Privacy & Data Collection ==

This plugin connects to our translation API at meeptech.com to process translations and track usage.

= What data is collected: =

✅ Your website URL (for service authentication and usage tracking)
✅ Word counts and language pairs (for plan limit enforcement)

= What data is NOT collected: =

❌ Your actual translated content is not stored (only sent to OpenAI for processing)
❌ Personal information or email addresses
❌ User or visitor data

= Third-party services: =

• MeepTech API (meeptech.com) - Handles usage tracking
• OpenAI - Processes translation requests (content is sent to OpenAI for translation only)
• PostHog - Optional anonymous analytics (opt-in only)

= Your privacy rights: =

You can request data deletion or export by contacting privacy@meeptech.com. 
For GDPR/CCPA inquiries, please contact us with your website URL.

For more information, see our policies:
– MeepTech Privacy Policy: https://meeptech.com/privacy-policy
– MeepTech Terms of Service: https://meeptech.com/terms
– OpenAI Privacy Policy: https://openai.com/policies/privacy-policy

== Security ==
If you discover a security vulnerability, please email security@meeptech.com with steps to reproduce. Do not disclose publicly until a fix is available.
