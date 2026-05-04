/* ============================================================================
   meep-admin.js — Phase B
   --------------------------------------------------------------------------
   Companion JS for meep-admin.css.
   Augments the WordPress admin DOM at runtime — no PHP changes required.

   Path inside the plugin:  assets/js/meep-admin.js
   Companion CSS:           assets/css/meep-admin.css
   Enqueue:                 after existing admin scripts, plugin pages only

   What it does:
     1. Strips leading emoji + spacing from .aitrfoel-settings-section h3 strings.
     2. Injects an SVG icon to the left of each h3 based on section ID.
     3. Injects a mono "// section.name" eyebrow above each h3.
     4. Animates count-up on the compact stats bar values.
     5. Injects a hero intro strip on the dashboard page.
     6. Reveal-on-scroll animation for cards.
     7. Pauses animations if prefers-reduced-motion is set.
   ============================================================================ */

(function () {
  'use strict';

  document.documentElement.classList.add('js');

  // Run after DOM is ready.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  function init() {
    const wrapper = document.querySelector('.aitrfoel-admin-wrapper, .aitrfoel-dashboard-wrapper');
    if (!wrapper) return; // Not on a plugin admin page.

    upgradeSectionHeadings();
    upgradeSettingsNavPills();
    injectHero(wrapper);
    upgradeStatsBar();
    setupReveal();
    wrapper.classList.add('meep-admin-ready');
  }

  /* -----------------------------------------------------------------------
     1) Section headings — strip emoji, inject icon + mono eyebrow
     ----------------------------------------------------------------------- */

  // Map section IDs (from PHP) to:
  //   - human-readable mono eyebrow ("license", "multilingual", etc.)
  //   - SVG icon (Lucide-style stroked, 16×16, viewBox 24 24)
  const SECTION_MAP = {
    'section-license':            { eyebrow: 'license',      icon: iconKey() },
    'section-multilingual':       { eyebrow: 'multilingual', icon: iconGlobe() },
    'section-switcher':           { eyebrow: 'switcher',     icon: iconArrowSwap() },
    'section-menus-widgets':      { eyebrow: 'menus',        icon: iconList() },
    'section-elementor-templates':{ eyebrow: 'templates',    icon: iconGrid() },
    'section-cache':              { eyebrow: 'cache',        icon: iconBolt() },
    'section-page-creation':      { eyebrow: 'pages',        icon: iconDoc() },
    'section-seo':                { eyebrow: 'seo',          icon: iconSearch() },
    'section-woocommerce':        { eyebrow: 'commerce',     icon: iconCart() },
    'section-exclude-words':      { eyebrow: 'exclude',      icon: iconEyeOff() },
    'section-glossary':           { eyebrow: 'glossary',     icon: iconBook() },
    'section-theme':              { eyebrow: 'theme',        icon: iconPalette() },
    'section-advanced':           { eyebrow: 'advanced',     icon: iconCog() },
    'section-privacy':            { eyebrow: 'privacy',      icon: iconShield() },
  };

  // Matches a leading emoji (or a few common ones) followed by whitespace at the
  // start of an h3's text content. Covers the emojis used in class-settings.php.
  const LEADING_EMOJI = /^\s*([\u{1F300}-\u{1FAFF}]|[\u{2600}-\u{27BF}]|🔑|🌐|🌍|📋|🧩|💾|📄|🔍|🛒|🚫|📖|🎨|🔧|🛡)\s*/u;

  function upgradeSectionHeadings() {
    const sections = document.querySelectorAll(
      '.aitrfoel-admin-wrapper .aitrfoel-settings-section, ' +
      '.aitrfoel-dashboard-wrapper .aitrfoel-settings-section'
    );

    sections.forEach((section) => {
      const id = section.id || '';
      const meta = SECTION_MAP[id] || null;

      // 1a) Strip emoji prefixes from h3 text.
      const h3 = section.querySelector('h3');
      if (!h3 || h3.dataset.meepUpgraded === '1') return;
      h3.dataset.meepUpgraded = '1';

      // Walk text nodes and remove the leading emoji from the first one.
      let firstText = null;
      for (const node of h3.childNodes) {
        if (node.nodeType === Node.TEXT_NODE && node.textContent.trim().length) {
          firstText = node;
          break;
        }
      }
      if (firstText) {
        firstText.textContent = firstText.textContent.replace(LEADING_EMOJI, '');
      }

      // 1b) Inject SVG icon as the first child of h3.
      if (meta) {
        const iconWrap = document.createElement('span');
        iconWrap.className = 'meep-section-icon';
        iconWrap.setAttribute('aria-hidden', 'true');
        iconWrap.innerHTML = meta.icon;
        h3.insertBefore(iconWrap, h3.firstChild);

        // 1c) Inject mono eyebrow before the h3.
        const eyebrow = document.createElement('div');
        eyebrow.className = 'meep-section-eyebrow';
        eyebrow.textContent = '// ' + meta.eyebrow;
        section.insertBefore(eyebrow, h3);
      }
    });
  }

  function upgradeSettingsNavPills() {
    const nav = document.getElementById('aitrfoel-settings-nav');
    if (!nav) return;

    const links = nav.querySelectorAll('a[href^="#"]');
    links.forEach((link) => {
      if (link.dataset.meepNavUpgraded === '1') return;
      link.dataset.meepNavUpgraded = '1';

      const rawText = (link.textContent || '').replace(LEADING_EMOJI, '').trim();
      link.textContent = rawText;

      const targetId = (link.getAttribute('href') || '').replace('#', '');
      const meta = SECTION_MAP[targetId] || null;
      if (meta) {
        const iconWrap = document.createElement('span');
        iconWrap.className = 'meep-settings-nav-icon';
        iconWrap.setAttribute('aria-hidden', 'true');
        iconWrap.innerHTML = meta.icon;
        link.prepend(iconWrap);
      }
    });
  }

  /* -----------------------------------------------------------------------
     2) Hero strip on key admin pages (Dashboard, Settings, History)
     ----------------------------------------------------------------------- */
  function injectHero(wrapper) {
    const page = new URLSearchParams(window.location.search).get('page') || '';
    const shouldInject = (
      page === 'ai-translator-for-elementor-and-polylang' ||
      page === 'ai-translator-for-elementor-and-polylang-settings' ||
      page === 'ai-translator-for-elementor-and-polylang-history' ||
      page === 'ai-translator-for-elementor-and-polylang-translate-all'
    );

    if (!shouldInject) return;
    const header = wrapper.querySelector('.aitrfoel-plugin-header');
    const description = wrapper.querySelector(':scope > .description, :scope > p.description');
    if (wrapper.querySelector('.meep-hero')) {
      if (header) header.style.display = 'none';
      if (description) description.style.display = 'none';
      return;
    }

    // Find the description paragraph and replace it with a hero strip.
    const statsBar = wrapper.querySelector('.aitrfoel-compact-stats-bar');

    const hero = document.createElement('div');
    hero.className = 'meep-hero meep-reveal';

    const eyebrow = document.createElement('div');
    eyebrow.className = 'meep-hero-eyebrow';
    eyebrow.textContent = '// dashboard';

    const brand = document.createElement('div');
    brand.className = 'meep-hero-brand';

    const meta = document.createElement('div');
    meta.className = 'meep-hero-meta';

    let brandText = (typeof meepAdmin !== 'undefined' && meepAdmin.pluginName)
      ? meepAdmin.pluginName
      : 'Meep AI Translator';
    if (header) {
      const titleNode = header.querySelector('.aitrfoel-plugin-title');
      if (titleNode) {
        brandText = titleNode.textContent.replace(/\s+/g, ' ').trim();
      }

      const logoNode = header.querySelector('img');
      if (logoNode && logoNode.src) {
        const brandLogo = document.createElement('img');
        brandLogo.className = 'meep-hero-brand-logo';
        brandLogo.src = logoNode.src;
        brandLogo.alt = '';
        brandLogo.setAttribute('aria-hidden', 'true');
        brand.appendChild(brandLogo);
      }
    }

    const brandTextNode = document.createElement('span');
    brandTextNode.className = 'meep-hero-brand-text';
    brandTextNode.textContent = brandText;
    brand.appendChild(brandTextNode);

    const title = document.createElement('h2');
    title.className = 'meep-hero-title';
    title.innerHTML = 'Translate your site, <span class="accent">page by page</span>.';

    const sub = document.createElement('p');
    sub.className = 'meep-hero-sub';
    sub.textContent = description
      ? description.textContent.trim()
      : 'Manage translations for your pages and posts. Select source content to create bulk translations.';

    meta.appendChild(brand);
    meta.appendChild(eyebrow);
    hero.appendChild(meta);
    hero.appendChild(title);
    hero.appendChild(sub);

    // Insert: prefer right above the stats bar; otherwise at the top of the wrapper.
    if (statsBar) {
      statsBar.parentNode.insertBefore(hero, statsBar);
    } else {
      if (header && header.nextSibling) {
        header.parentNode.insertBefore(hero, header.nextSibling);
      } else {
        wrapper.insertBefore(hero, wrapper.firstChild);
      }
    }

    if (header) header.style.display = 'none';

    // If we replaced the description paragraph, hide the original.
    if (description) description.style.display = 'none';
  }

  /* -----------------------------------------------------------------------
     3) Count-up on stats bar values
     ----------------------------------------------------------------------- */
  function upgradeStatsBar() {
    const values = document.querySelectorAll(
      '.aitrfoel-dashboard-wrapper .aitrfoel-compact-stats-bar .aitrfoel-compact-value'
    );

    values.forEach((el) => {
      // Skip if it's not a pure number (e.g. "Unlimited", "Starter").
      const raw = el.textContent.trim().replace(/[,\s]/g, '');
      const target = parseFloat(raw);
      if (!isFinite(target) || raw.length === 0) return;
      // Don't animate strings that contain non-digits (e.g. "Starter ⚠️").
      if (!/^[\d.]+$/.test(raw)) return;

      const original = el.textContent;
      el.classList.add('meep-count-up');
      el.dataset.meepTarget = String(target);
      el.dataset.meepOriginal = original;
      el.textContent = '0';

      animateNumber(el, 0, target, 1300, original);
    });
  }

  function animateNumber(el, from, to, dur, finalText) {
    if (prefersReducedMotion()) {
      el.textContent = finalText;
      return;
    }
    const start = performance.now();
    const tick = (now) => {
      const t = Math.min(1, (now - start) / dur);
      const eased = 1 - Math.pow(1 - t, 3);
      const v = from + (to - from) * eased;
      // Format with thousands separators like the original.
      el.textContent = Math.round(v).toLocaleString('en-US');
      if (t < 1) requestAnimationFrame(tick);
      else el.textContent = finalText; // restore exact original (handles decimals, units)
    };
    requestAnimationFrame(tick);
  }

  /* -----------------------------------------------------------------------
     4) Reveal on scroll
     ----------------------------------------------------------------------- */
  function setupReveal() {
    if (prefersReducedMotion() || !('IntersectionObserver' in window)) {
      document.querySelectorAll('.meep-reveal').forEach((el) => el.classList.add('in'));
      return;
    }
    const obs = new IntersectionObserver((entries) => {
      entries.forEach((e) => {
        if (e.isIntersecting) {
          e.target.classList.add('in');
          obs.unobserve(e.target);
        }
      });
    }, { threshold: 0.12 });

    document.querySelectorAll('.meep-reveal').forEach((el) => obs.observe(el));
  }

  /* -----------------------------------------------------------------------
     Helpers
     ----------------------------------------------------------------------- */
  function prefersReducedMotion() {
    return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  /* -----------------------------------------------------------------------
     SVG icon factory (Lucide-inspired, 16×16, stroke-currentColor)
     Each function returns an SVG string used inside .meep-section-icon
     ----------------------------------------------------------------------- */
  function svg(paths) {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
      'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' + paths + '</svg>';
  }

  function iconKey()       { return svg('<circle cx="8" cy="15" r="4"/><path d="M10.85 12.15L21 2M15 5l3 3M11 8l3 3"/>'); }
  function iconGlobe()     { return svg('<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 010 20M12 2a15 15 0 000 20"/>'); }
  function iconArrowSwap() { return svg('<path d="M7 16h14M7 16l-4-4 4-4M17 8H3M17 8l4 4-4 4"/>'); }
  function iconList()      { return svg('<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>'); }
  function iconGrid()      { return svg('<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>'); }
  function iconBolt()      { return svg('<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>'); }
  function iconDoc()       { return svg('<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/>'); }
  function iconSearch()    { return svg('<circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>'); }
  function iconCart()      { return svg('<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/>'); }
  function iconEyeOff()    { return svg('<path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>'); }
  function iconBook()      { return svg('<path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/>'); }
  function iconPalette()   { return svg('<circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"/><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"/><path d="M12 22a10 10 0 110-20 7 7 0 015 12h-2a2 2 0 00-1 4 2 2 0 01-2 4z"/>'); }
  function iconCog()       { return svg('<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 11-4 0v-.09a1.65 1.65 0 00-1-1.51 1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 110-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 114 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 110 4h-.09a1.65 1.65 0 00-1.51 1z"/>'); }
  function iconShield()    { return svg('<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>'); }
})();
