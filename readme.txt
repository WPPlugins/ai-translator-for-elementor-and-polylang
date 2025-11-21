=== AI Translator for Elementor and Polylang ===
Contributors: meeptechdev
Tags: elementor, polylang, translation, ai, openai, multilingual
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Requires Plugins: elementor, polylang
Stable tag: 1.0.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered translation for Elementor pages with Polylang integration.

== Description ==
AI Translator for Elementor and Polylang brings AI-powered translation to your WordPress site. Translate your Elementor-powered pages seamlessly with Polylang using OpenAI's advanced translation engine.

**Key Features:**
– One-click translation for Elementor pages
– Polylang integration (free and Pro versions supported)
– OpenAI-powered translations for natural, contextual results
– Word limit enforcement with pre-translation checks
– Automatic monthly word count reset
– Clean admin interface without Elementor interference
– Dashboard with usage tracking

**Free Plan Includes:**
– 1,800 words per month
– All core translation features
– Latest OpenAI models
– Compatible with Elementor Free & Pro
– Compatible with Polylang Free & Pro

Need more words or priority support? Upgrade to PRO for plans with 11,000 to 89,000 words/month, priority support, and no cooldown periods.

== Installation ==
1. Upload the plugin folder to `/wp-content/plugins/` or install via the WordPress Plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to AI Translator for Elementor and Polylang → Settings to configure.
4. Add your OpenAI API key (get one at https://platform.openai.com/api-keys).
5. Start translating your Elementor pages!

== Frequently Asked Questions ==
= Do I need an OpenAI API key? =
Yes. You can get one for free at https://platform.openai.com/api-keys. OpenAI charges separately for API usage.

= Does this require Elementor Pro? =
No. It works with both Elementor free and Elementor Pro.

= Does this require Polylang Pro? =
No. It supports both Polylang free and Polylang Pro.

= Which AI provider do you use? =
OpenAI via our secure translation API. We handle all API communication to ensure security and reliability.

= How many words can I translate per month? =
The free version includes 1,800 words per month. Upgrade to PRO for 11,000 to 89,000 words/month.

= What happens when I reach my word limit? =
Translation will be blocked until your limit resets at the beginning of next month, or you can upgrade to PRO for higher limits and no cooldowns.

= When does my word count reset? =
Your word count automatically resets to 0 on the first day of each month.

= How do I check how many words I have left? =
Visit AI Translator → Dashboard to see your current usage and words remaining.

= Will this modify my existing content? =
No. Translations are applied to translated copies in the target language. Your original content remains unchanged.

= What's the difference between FREE and PRO? =
PRO includes higher word limits (11,000-89,000/month), priority support, license management, and no cooldown periods. The free version has 1,800 words/month.

== External Services ==

This plugin relies on external third-party services to function. Below is a detailed disclosure of what services are used, what data is transmitted, and when.

= MeepTech Translation API =

**Purpose:** Usage tracking and word count enforcement for translation plans.

**Service Provider:** MeepTech  
**API Endpoint:** https://meeptech.com/api/translate  
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
- Your OpenAI API key (configured in settings)

**When Data is Sent:**
- When you initiate a page translation

**Privacy & Terms:**
- Privacy Policy: https://openai.com/policies/privacy-policy
- Terms of Service: https://openai.com/policies/terms-of-use
- API Terms: https://openai.com/policies/api-terms

**Important:** Your content is sent to OpenAI for processing. Please review OpenAI's data usage policies to ensure compliance with your content policies.

== Screenshots ==
1. Dashboard with translation stats and usage tracking.
2. Translation interface for Elementor pages.
3. Settings page with OpenAI API configuration.

== Changelog ==
= 1.0.7 =
* Initial public release
* Elementor + Polylang integration
* OpenAI translation engine
* Usage tracking and limits
* Clean admin interface

== Upgrade Notice ==
= 1.0.7 =
First stable release.

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
– All core features

**Upgrade to PRO for more:**
– Higher word limits (11,000-89,000/month)
– Priority support
– License management
– No cooldown periods

Upgrade inside WordPress: AI Translator → Pricing

== Privacy & Data Collection ==

This plugin connects to our translation API at meeptech.com to process translations and track usage.

= What data is collected: =

✅ Your website URL (for service authentication and usage tracking)
✅ Word counts and language pairs (for plan limit enforcement)

= What data is NOT collected: =

❌ Your actual translated content or page content
❌ Personal information or email addresses
❌ User or visitor data
❌ IP addresses (beyond standard web server logs)

= Third-party services: =

• MeepTech API (meeptech.com) - Handles usage tracking
• OpenAI - Processes translation requests (content is sent to OpenAI for translation only)

= Your privacy rights: =

You can request data deletion or export by contacting privacy@meeptech.com. 
For GDPR/CCPA inquiries, please contact us with your website URL.

For more information, see our policies:
– MeepTech Privacy Policy: https://meeptech.com/privacy-policy
– MeepTech Terms of Service: https://meeptech.com/terms
– OpenAI Privacy Policy: https://openai.com/policies/privacy-policy

== Security ==
If you discover a security vulnerability, please email security@meeptech.com with steps to reproduce. Do not disclose publicly until a fix is available.
