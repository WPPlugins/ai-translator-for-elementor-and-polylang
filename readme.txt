=== AI Translator for Elementor and Polylang PRO ===
Contributors: meeptechdev
Tags: elementor, polylang, translation, ai, openai, premium
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Requires Plugins: elementor, polylang
Stable tag: 1.0.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Premium AI-powered translation for Elementor pages with Polylang. Professional version with advanced features and higher word limits.

== Description ==
AI Translator for Elementor and Polylang PRO is the premium version of our AI translation solution for WordPress. Translate your Elementor-powered pages seamlessly with Polylang using OpenAI's advanced translation engine.

**PRO Version Features:**
– **Higher Word Limits:** Professional plans with 18,000 to 355,000 words with no time limitations
– **Priority Support:** Get help from our dedicated support team
– **License Management:** Full Freemius integration for enterprise deployments
– **No Time Limitations:** Continuous translation access without waiting

**Core Features:**
– One-click translation for Elementor pages
– Polylang integration (free and Pro versions supported)
– OpenAI-powered translations for natural, contextual results
– Automatic weekly word count reset (free version)
– Clean admin interface without Elementor interference
– Advanced dashboard with analytics

This is the premium version. A free version with 1,800 words/week is available on WordPress.org.

== Installation ==
1. Upload the plugin folder to `/wp-content/plugins/` or install via the WordPress Plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Add the languages you need in Polylang Settings (Languages → Languages tab).
4. Navigate to AI Translator plugin page.
5. Start translating your Elementor pages!

== Frequently Asked Questions ==
= Is this different from the free version? =
Yes! The PRO version includes higher word limits (up to 355,000 words), bulk translation, priority support, and no time limitations. The free version is limited to 1,800 words per week with weekly cooldowns when limits are reached.

= Does this require Elementor Pro? =
No. It works with Elementor free and Elementor Pro.

= Does this require Polylang Pro? =
No. It supports both Polylang free and Polylang Pro.

= Which AI provider do you use? =
OpenAI via our secure translation API. We handle all API communication to ensure security and reliability.

= Where do I manage my license? =
Go to AI Translator for Elementor and Polylang PRO → Account to activate your license, view usage, and manage your subscription.

= Will this modify my existing content? =
No. Translations are applied to translated copies in the target language. Your original content remains unchanged.

= What happens when I reach my word limit? =
With PRO plans, you can upgrade to a higher tier at any time with no waiting period. Translation requests that exceed your remaining words will be blocked with a clear message. Free users experience a weekly cooldown period.

= Will I be charged if I go over my limit? =
No. The plugin checks your remaining words before starting each translation. If you don't have enough words, the translation will be blocked with a clear error message.

= When does my word count reset? =
PRO plans have no time limitations - your word allocation is available for use without monthly resets. Free version resets weekly.

= How do I check how many words I have left? =
Visit AI Translator → Dashboard to see your current usage, words remaining, and plan details.

= Do I get updates automatically? =
Yes! Licensed PRO users receive automatic updates through WordPress. You'll be notified when new versions are available.

== External Services ==

This plugin relies on external third-party services to function. Below is a detailed disclosure of what services are used, what data is transmitted, and when.

= MeepTech License & Usage API =

**Purpose:** License validation, usage tracking, and word count enforcement for translation plans.

**Service Provider:** MeepTech  
**API Endpoint:** https://meeptech.com/api/eat/get-usage  
**Data Transmitted:**
- Your website URL (for site authentication)
- License key (if using a paid plan)
- Word count usage (number of words translated)
- Translation timestamps

**When Data is Sent:**
- During license key activation/validation
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
- During bulk translation operations

**Privacy & Terms:**
- Privacy Policy: https://openai.com/policies/privacy-policy
- Terms of Service: https://openai.com/policies/terms-of-use
- API Terms: https://openai.com/policies/api-terms

**Important:** Your content is sent to OpenAI for processing. Please review OpenAI's data usage policies to ensure compliance with your content policies.

= Freemius SDK =

**Purpose:** License management, payment processing, and optional analytics (opt-in).

**Service Provider:** Freemius  
**Data Transmitted (if opted in):**
- Plugin version, WordPress version, PHP version
- Site URL and admin email
- Plugin activation/deactivation events

**When Data is Sent:**
- During plugin activation (if opted in)
- When managing licenses through the Account page
- During plugin updates

**Privacy & Terms:**
- Privacy Policy: https://freemius.com/privacy/
- Terms of Service: https://freemius.com/terms/

**Note:** Freemius data collection is optional and can be opted out during initial setup.

== Screenshots ==
1. Dashboard with translation stats and license info.

== Changelog ==
= 1.0.7 =
* Initial public release
* Elementor + Polylang integration
* OpenAI translation engine
* Freemius licensing system
* Usage tracking and limits
* Clean admin interface

== Upgrade Notice ==
= 1.0.7 =
First stable release of the PRO version.

== Pricing ==
= Free =
$0
Perfect for testing the plugin

– 1 Website License
– 1,800 words/month
– Latest OpenAI Models
– Polylang Compatible
– Elementor Support
– Community Support
– 7-day cooldown after limit
– All core features

Upgrade your license inside WordPress: AI Translator for Elementor and Polylang <span aria-hidden="true" class="wp-exclude-emoji"><span aria-hidden="true" class="wp-exclude-emoji"><span aria-hidden="true" class="wp-exclude-emoji">→</span></span></span> Pricing.

= Starter =
$49/year
For small businesses and landing pages

– 1 Website License
– ~18K words / ~46 pages
– 100,000 tokens included
– Latest OpenAI Models
– Polylang Pro Compatible
– Elementor & Elementor Pro Support
– Email Support
– Option to purchase additional packages
– 1 Year of Updates

= Agency =
$149/year — Most Popular
Optimal for studios and agencies

– Up to 10 Website Licenses
– ~89K words / ~232 pages
– 500,000 tokens included
– Latest OpenAI Models
– Polylang Pro Compatible
– Priority Email Support
– Option to purchase additional packages
– 1 Year of Updates

== Privacy & Data Collection ==

This plugin connects to our translation API at meeptech.com to process translations and track usage.

= What data is collected: =

✅ Your website URL (for service authentication and usage tracking)
✅ Word counts and language pairs (for plan limit enforcement)
✅ License key (if applicable, for premium features)

= What data is NOT collected: =

❌ Your actual translated content or page content
❌ Personal information or email addresses
❌ User or visitor data
❌ IP addresses (beyond standard web server logs)

= Third-party services: =

• MeepTech API (meeptech.com) - Handles usage tracking and licensing
• OpenAI - Processes translation requests (content is sent to OpenAI for translation only)
• Freemius - Manages licensing, payments, and optional telemetry (opt-in/opt-out available)

= Your privacy rights: =

You can request data deletion or export by contacting privacy@meeptech.com. 
For GDPR/CCPA inquiries, please contact us with your website URL.

For more information, see our policies:
– MeepTech Privacy Policy: https://meeptech.com/privacy-policy
– MeepTech Terms of Service: https://meeptech.com/terms
– Freemius Privacy Policy: https://freemius.com/privacy/
– OpenAI Privacy Policy: https://openai.com/policies/privacy-policy

== Security ==
If you discover a security vulnerability, please email security@meeptech.com with steps to reproduce. Do not disclose publicly until a fix is available. See docs/SECURITY.md in this repository for details.
