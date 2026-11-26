export interface DocSection {
  title: string;
  type: 'text' | 'list' | 'steps';
  text?: string;
  items?: string[];
}

export interface DocTopic {
  id: string;
  title: string;
  badge?: string | null;
  summary: string;
  content: {
    heading: string;
    sections: DocSection[];
  };
}

export interface DocCategory {
  id: string;
  title: string;
  icon: string;
  topics: DocTopic[];
}

export interface DocumentationData {
  version: string;
  lastUpdated: string;
  pluginName: string;
  categories: DocCategory[];
}

export const documentationData: DocumentationData = {
    "version": "1.8.20",
    "lastUpdated": "2026-09-21",
    "pluginName": "Meep AI Translator",
    "categories": [
        {
            "id": "getting-started",
            "title": "Getting Started",
            "icon": "\ud83d\ude80",
            "topics": [
                {
                    "id": "installation-requirements",
                    "title": "Installation & Requirements",
                    "badge": null,
                    "summary": "System requirements, supported WordPress/PHP versions, and step-by-step installation.",
                    "content": {
                        "heading": "Installation & System Requirements",
                        "sections": [
                            {
                                "title": "System Requirements",
                                "type": "list",
                                "items": [
                                    "WordPress 5.8 or higher (fully tested on WordPress 7.1)",
                                    "PHP 7.4 or higher (recommended PHP 8.1 - 8.3+)",
                                    "Elementor 3.18.0+ through 4.1.3+ (including Elementor v4 atomic widgets e-heading, e-paragraph)",
                                    "Gutenberg Block Editor & Classic Editor supported out of the box",
                                    "Multilingual: Use the Built-in Language System (no plugins needed), or Polylang / WPML"
                                ]
                            },
                            {
                                "title": "Quick Installation",
                                "type": "steps",
                                "items": [
                                    "In WordPress Admin, navigate to Plugins \u2192 Add New \u2192 Upload Plugin.",
                                    "Select ai-translator-for-elementor-polylang.zip and click Install Now.",
                                    "Click Activate Plugin.",
                                    "The modern setup wizard will prompt you to pick your default site language and target languages."
                                ]
                            }
                        ]
                    }
                },
                {
                    "id": "admin-dashboard-overview",
                    "title": "Admin Dashboard Overview",
                    "badge": null,
                    "summary": "Tour of the translation dashboard, translation health meter, and quick-action toolbars.",
                    "content": {
                        "heading": "Admin Dashboard Overview",
                        "sections": [
                            {
                                "title": "Dashboard Features",
                                "type": "list",
                                "items": [
                                    "Translation Health Meter: Live completeness score, category status, and 1-click remaining translation action.",
                                    "Unified Content Tabs: Pages, Posts, Products, Forms, Elements & Templates, and Custom Post Types.",
                                    "Bulk Translation Bar: Select source/target languages and launch synchronous or background batch jobs.",
                                    "Row Actions: Direct Edit, Preview, Review translation side-by-side, Retranslate, and Delete."
                                ]
                            }
                        ]
                    }
                },
                {
                    "id": "onboarding-license",
                    "title": "Onboarding & License Activation",
                    "badge": null,
                    "summary": "4-step setup wizard, importing languages from existing plugins, and activating PRO licenses.",
                    "content": {
                        "heading": "Onboarding & License Activation",
                        "sections": [
                            {
                                "title": "Zero-Friction Onboarding",
                                "type": "text",
                                "text": "The onboarding wizard automatically detects if Polylang or WPML was previously installed and offers 1-click language import. Free tier users get 7,200 words/month with zero API key configuration."
                            }
                        ]
                    }
                }
            ]
        },
        {
            "id": "core-features",
            "title": "Core Features & AI",
            "icon": "\u2728",
            "topics": [
                {
                    "id": "multi-engine-ai",
                    "title": "Multi-Engine AI Routing",
                    "badge": "Multi-AI",
                    "summary": "Anthropic Claude, DeepL Pro, and OpenAI ChatGPT with smart automated routing.",
                    "content": {
                        "heading": "Multi-Engine AI Routing",
                        "sections": [
                            {
                                "title": "Supported AI Models",
                                "type": "list",
                                "items": [
                                    "Auto Smart Routing (Recommended): Automatically routes titles & UI to DeepL Pro and long-form prose to Anthropic Claude or ChatGPT.",
                                    "Anthropic Claude: Industry-leading tone, nuance, and structural HTML preservation.",
                                    "DeepL Pro: Gold standard for European grammatical precision.",
                                    "OpenAI ChatGPT: Ultra-fast multilingual translation across 100+ languages."
                                ]
                            }
                        ]
                    }
                },
                {
                    "id": "translation-health-meter",
                    "title": "Translation Health Meter",
                    "badge": "v1.8.20",
                    "summary": "Live translation completeness meter, category progress chips, and 1-click translate action.",
                    "content": {
                        "heading": "Translation Health & Completeness Meter",
                        "sections": [
                            {
                                "title": "Real-Time Completeness Tracking",
                                "type": "text",
                                "text": "The Translation Health Meter continuously monitors all published pages, posts, products, forms, and templates across active target languages. Color themes reflect site health: Emerald (\u226595%), Blue (70-94%), Amber (40-69%), and Orange (<40%)."
                            },
                            {
                                "title": "One-Click Translation Action",
                                "type": "text",
                                "text": "Clicking [ \u26a1 Translate Remaining ] instantly pre-populates and launches the Translate All queue for all missing language versions."
                            }
                        ]
                    }
                },
                {
                    "id": "smart-translation-memory",
                    "title": "Smart Translation Memory",
                    "badge": "0-Cost",
                    "summary": "Deduplicate repeated headings, buttons, and text across pages for 0 word cost.",
                    "content": {
                        "heading": "Smart Translation Memory (TM)",
                        "sections": [
                            {
                                "title": "Zero-Cost Repeated Strings",
                                "type": "text",
                                "text": "When identical strings appear across your website (like \"Add to Cart\", \"Contact Us\", or footer disclaimers), Meep AI translates them once and reuses them forever across all pages at 0 word quota cost."
                            }
                        ]
                    }
                },
                {
                    "id": "autonomous-auto-sync",
                    "title": "Autonomous Auto-Sync",
                    "badge": "Set & Forget",
                    "summary": "Edit in your default language; translations update automatically in the background.",
                    "content": {
                        "heading": "Autonomous Background Auto-Sync",
                        "sections": [
                            {
                                "title": "How Background Sync Works",
                                "type": "text",
                                "text": "Whenever you update and save a page, post, product, or template in your primary language, Meep detects the change and asynchronously syncs all translated versions, clearing page cache automatically."
                            }
                        ]
                    }
                }
            ]
        },
        {
            "id": "translating-content",
            "title": "Translating Content",
            "icon": "\u26a1",
            "topics": [
                {
                    "id": "elementor-templates",
                    "title": "Elementor Templates & Theme Builder",
                    "badge": "CSS-Safe",
                    "summary": "Saved templates vs. Theme Builder, CSS styling retention, and shortcode rewriting.",
                    "content": {
                        "heading": "Elementor Templates & Theme Builder Localization",
                        "sections": [
                            {
                                "title": "Saved Templates vs. Theme Builder (v1.8.17+)",
                                "type": "text",
                                "text": "Meep strictly preserves template taxonomy (elementor_library_type) so saved templates retain full CSS styling, layouts, and container structures without being displaced into Theme Builder."
                            },
                            {
                                "title": "Shortcode Rewriting",
                                "type": "text",
                                "text": "Shortcodes like [elementor-template id=\"123\"] are automatically remapped to their target-language equivalent both in page content and dynamically at runtime."
                            }
                        ]
                    }
                },
                {
                    "id": "contact-forms",
                    "title": "Contact Forms (CF7, SureForms, WPForms)",
                    "badge": "v1.8.20",
                    "summary": "Deep translation of Contact Form 7, SureForms, WPForms, validation messages, and shortcode swaps.",
                    "content": {
                        "heading": "Contact Forms & SureForms Translation",
                        "sections": [
                            {
                                "title": "Contact Form 7 Deep Translation",
                                "type": "list",
                                "items": [
                                    "Form template tags: labels, placeholders, option dropdown choices, and submit buttons.",
                                    "Validation messages (_messages): error notices, required alerts, and confirmation notices.",
                                    "Email notifications: localized subject lines.",
                                    "Automatic shortcode swapping: [contact-form-7 id=\"...\"] is rewritten on translated pages."
                                ]
                            },
                            {
                                "title": "SureForms Gutenberg Form Translation",
                                "type": "text",
                                "text": "Translates block attributes for SureForms and block form builders, including field labels, help text, validation errors, submit button copy, and option choices."
                            }
                        ]
                    }
                },
                {
                    "id": "woocommerce-surecart",
                    "title": "WooCommerce & SureCart Store Localization",
                    "badge": null,
                    "summary": "Product titles, descriptions, variations, attributes, gallery SEO, and checkout endpoints.",
                    "content": {
                        "heading": "E-Commerce Localization (WooCommerce & SureCart)",
                        "sections": [
                            {
                                "title": "Full Store Localization",
                                "type": "text",
                                "text": "Translates WooCommerce products, attributes, variations, and checkout notice strings. For SureCart, sc_product titles and HTML descriptions are localized with zero block corruption."
                            }
                        ]
                    }
                },
                {
                    "id": "custom-post-types",
                    "title": "Custom Post Types & JetEngine",
                    "badge": null,
                    "summary": "JetEngine, ACF, Meta Box, Pods, custom meta fields, and relational post remapping.",
                    "content": {
                        "heading": "Custom Post Types & JetEngine Dynamic Content",
                        "sections": [
                            {
                                "title": "Dynamic Content & Meta Fields",
                                "type": "text",
                                "text": "Automatically copies and translates custom post meta (ACF, JetEngine, Meta Box) and remaps relational fields (relationship, post_object, page_link) to point to target-language post IDs."
                            }
                        ]
                    }
                }
            ]
        },
        {
            "id": "tools-troubleshooting",
            "title": "Tools & Troubleshooting",
            "icon": "\ud83d\udee0\ufe0f",
            "topics": [
                {
                    "id": "diagnostics-repair",
                    "title": "Diagnostics & Repair Tools",
                    "badge": null,
                    "summary": "Repair Translation Links, Fix Elementor Template Shortcodes, and Cache Purging.",
                    "content": {
                        "heading": "Diagnostics & Repair Tools",
                        "sections": [
                            {
                                "title": "Built-In Maintenance Hub",
                                "type": "list",
                                "items": [
                                    "Repair Translation Links: Reconciles post parentage, child subpages, and internal link references.",
                                    "Fix Elementor Template Shortcodes: Scans and updates legacy template shortcode IDs across all translations.",
                                    "Purge Translation Memory & Cache: Synchronizes local caches when brand exclusions or glossaries change."
                                ]
                            }
                        ]
                    }
                },
                {
                    "id": "troubleshooting-faqs",
                    "title": "Troubleshooting Guide & FAQs",
                    "badge": null,
                    "summary": "Resolving 404s, LiteSpeed / CDN caching, and translation memory refresh.",
                    "content": {
                        "heading": "Troubleshooting Guide & FAQs",
                        "sections": [
                            {
                                "title": "Common Solutions",
                                "type": "list",
                                "items": [
                                    "Translated page showing old content: Purge your page caching plugin (LiteSpeed, WP Rocket) and CDN (Cloudflare/Hostinger).",
                                    "404 on language URLs: Go to Settings \u2192 Permalinks and click Save Changes to flush rewrite rules.",
                                    "Brand names getting translated: Add them to Settings \u2192 Brand & Glossary \u2192 Exclude Words."
                                ]
                            }
                        ]
                    }
                }
            ]
        }
    ]
};
