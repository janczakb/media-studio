(() => {
  // Mintlify loads custom JS on every page. The native header is the fallback.
  const key = Symbol.for('flex.media-studio.docs');
  if (window[key]) return;
  window[key] = true;
  const groups = [
    ['Learn', [
      ['Installation', '/installation', 'Download01'], ['Configuration', '/configuration', 'Sorting01'],
      ['Workspaces', '/workspaces', 'Folder01'], ['Operations', '/operations', 'File01'],
      ['Licensing & pricing', '/licensing', 'File01'],
    ]],
    ['Use Studio', [
      ['Media library', '/library-organization', 'Layers01'], ['File previews', '/file-previews', 'Video01'],
      ['Search', '/search', 'Search01'], ['Sharing', '/sharing', 'Link01'],
      ['Browser Extension', '/browser-extension', 'Add01'],
    ]],
    ['Developers', [
      ['Filament', '/filament', 'Plug01'], ['FLEX Fields', '/flex-fields', 'Plug01'],
      ['API & SDK', '/api/introduction', 'Command'],
      ['MCP', '/mcp', 'SourceCode'], ['Laravel Boost', '/boost', 'SourceCode'], ['CLI', '/cli', 'SourceCode'],
    ]],
  ];
  const mountedRails = new WeakSet();
  const mountedAi = new Map();
  const updateActiveLink = link => {
    const path = link.getAttribute('href');
    const active = path === '/' ? location.pathname === '/' : location.pathname.startsWith(path);
    if (active) link.setAttribute('aria-current', 'page');
    else link.removeAttribute('aria-current');
  };
  const installMenu = () => {
    const navbar = document.getElementById('navbar');
    const searchGroup = navbar?.querySelector('#search-bar-entry')?.parentElement;
    const row = searchGroup?.parentElement;
    if (!row) return;
    const existing = row.querySelector('.fms-product-nav');
    if (existing) {
      existing.querySelectorAll('[data-fms-nav-link]').forEach(updateActiveLink);
      return;
    }

    row.classList.add('fms-topbar-row');
    const nav = document.createElement('nav');
    nav.className = 'fms-product-nav';
    nav.setAttribute('aria-label', 'Media Studio');
    const links = [['Home', '/'], ['Extension', '/browser-extension'], ['Docs', '/installation'], ['Pricing', '/licensing'], ['API', '/api/introduction'], ['FLEX family', '/flex-family']];
    for (const [label, path] of links) {
      const link = document.createElement('a');
      link.dataset.fmsNavLink = '';
      link.href = path;
      link.textContent = label;
      updateActiveLink(link);
      nav.append(link);
    }

    const resources = document.createElement('div');
    resources.className = 'fms-doc-resources';
    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.textContent = 'Resources';
    trigger.setAttribute('aria-controls', 'fms-mega-panel');
    trigger.setAttribute('aria-expanded', 'false');
    const menu = document.createElement('div');
    menu.id = 'fms-mega-panel';
    menu.className = 'fms-doc-mega';
    menu.inert = true;
    for (const [title, items] of groups) {
      const column = document.createElement('section');
      const heading = document.createElement('h2');
      heading.textContent = title;
      column.append(heading);
      for (const [label, path, iconName] of items) {
        const link = document.createElement('a');
        link.href = path;
        const icon = document.createElement('span');
        icon.className = `fms-mega-icon fms-icon fms-icon-${iconName}`;
        icon.setAttribute('aria-hidden', 'true');
        link.append(icon, document.createTextNode(label));
        column.append(link);
      }
      menu.append(column);
    }

    const setOpen = open => {
      trigger.setAttribute('aria-expanded', String(open));
      resources.toggleAttribute('data-open', open);
      menu.inert = !open;
    };
    trigger.addEventListener('click', () => setOpen(menu.inert));
    trigger.addEventListener('keydown', event => {
      if (event.key !== 'ArrowDown') return;
      event.preventDefault();
      setOpen(true);
      menu.querySelector('a')?.focus();
    });
    resources.addEventListener('focusout', event => {
      if (event.relatedTarget && !resources.contains(event.relatedTarget)) setOpen(false);
    });
    menu.addEventListener('click', event => {
      if (event.target.closest('a')) setOpen(false);
    });
    resources.append(trigger, menu);
    nav.insertBefore(resources, nav.lastElementChild);
    searchGroup.after(nav);
  };
  const installGallery = () => {
    const rail = document.querySelector('[data-fms-rail]');
    const controls = rail?.parentElement.querySelector('[data-fms-carousel-controls]');
    if (!controls || mountedRails.has(rail)) return;
    const slides = [...rail.querySelectorAll('[data-fms-slide]')];
    const buttons = [...controls.querySelectorAll('button[data-fms-slide-index]')];
    if (!slides.length || slides.length !== buttons.length) return;
    mountedRails.add(rail);

    const motion = matchMedia('(prefers-reduced-motion: reduce)');
    let selected = buttons.findIndex(button => button.getAttribute('aria-pressed') === 'true');
    let destination = null;
    let frame = 0;
    const select = index => {
      if (selected === index) return;
      buttons[selected]?.setAttribute('aria-pressed', 'false');
      buttons[index].setAttribute('aria-pressed', 'true');
      selected = index;
    };
    const syncScroll = () => {
      const bounds = rail.getBoundingClientRect();
      const center = bounds.left + rail.clientWidth / 2;
      let nearest = 0;
      let closest = Infinity;
      slides.forEach((slide, index) => {
        const rect = slide.getBoundingClientRect();
        const distance = Math.abs(rect.left + rect.width / 2 - center);
        if (distance >= closest) return;
        nearest = index;
        closest = distance;
      });
      select(nearest);
    };
    const goTo = index => {
      const slide = slides[index];
      const bounds = rail.getBoundingClientRect();
      const rect = slide.getBoundingClientRect();
      const left = rail.scrollLeft + rect.left + (rect.width - rail.clientWidth) / 2 - bounds.left;
      const image = slide.querySelector('img');
      if (image) image.loading = 'eager';
      destination = Math.max(0, Math.min(left, rail.scrollWidth - rail.clientWidth));
      select(index);
      rail.scrollTo({left: destination, behavior: motion.matches ? 'instant' : 'smooth'});
    };
    controls.hidden = false;
    controls.addEventListener('click', event => {
      const button = event.target.closest('button[data-fms-slide-index]');
      const index = buttons.indexOf(button);
      if (index !== -1) goTo(index);
    });
    const arrows = rail.parentElement.querySelector('.fms-gallery-arrows');
    if (arrows) {
      arrows.hidden = false;
      arrows.addEventListener('click', event => {
        const step = event.target.closest('button[data-fms-step]')?.dataset.fmsStep;
        if (step) goTo((selected + Number(step) + slides.length) % slides.length);
      });
    }
    const followScroll = () => { destination = null; };
    rail.addEventListener('pointerdown', followScroll, {passive: true});
    rail.addEventListener('wheel', followScroll, {passive: true});
    rail.addEventListener('keydown', followScroll);
    rail.addEventListener('scrollend', () => {
      destination = null;
      syncScroll();
    });
    rail.addEventListener('scroll', () => {
      if (frame) return;
      frame = requestAnimationFrame(() => {
        frame = 0;
        if (destination !== null && Math.abs(rail.scrollLeft - destination) > 2) return;
        destination = null;
        syncScroll();
      });
    }, {passive: true});
    syncScroll();
  };
  const installAiCarousels = () => {
    for (const [card, dispose] of mountedAi) {
      if (card.isConnected) continue;
      dispose();
      mountedAi.delete(card);
    }
    for (const card of document.querySelectorAll('[data-fms-ai]')) {
      if (mountedAi.has(card)) continue;
      const tabs = [...card.querySelectorAll('[role="tab"]')];
      const slides = [...card.querySelectorAll('[role="tabpanel"]')];
      const track = card.querySelector('.fms-ai-track');
      if (!track || tabs.length === 0 || tabs.length !== slides.length) continue;

      const motion = matchMedia('(prefers-reduced-motion: reduce)');
      let current = 0;
      let timer;
      let visible = false;
      let paused = false;
      const syncVideo = (reset = false) => {
        slides.forEach((slide, index) => {
          for (const video of slide.querySelectorAll('video')) {
            const playing = index === current && visible && !document.hidden && !motion.matches;
            if (!playing) video.pause();
            else {
              if (reset) video.currentTime = 0;
              void video.play().catch(() => {});
            }
          }
        });
      };
      const tabsRoot = tabs[0].parentElement;
      const select = index => {
        current = index;
        track.style.setProperty('--fms-slide', index);
        tabs.forEach((tab, tabIndex) => {
          const selected = tabIndex === index;
          tab.setAttribute('aria-selected', String(selected));
          tab.tabIndex = selected ? 0 : -1;
          slides[tabIndex].setAttribute('aria-hidden', String(!selected));
          slides[tabIndex].inert = !selected;
        });
        if (tabsRoot.scrollWidth > tabsRoot.clientWidth) {
          const tab = tabs[index].getBoundingClientRect();
          const rail = tabsRoot.getBoundingClientRect();
          if (tab.left < rail.left + 8) tabsRoot.scrollLeft += tab.left - rail.left - 8;
          else if (tab.right > rail.right - 8) tabsRoot.scrollLeft += tab.right - rail.right + 8;
        }
        syncVideo(true);
      };
      const schedule = () => {
        clearTimeout(timer);
        if (!card.isConnected || !visible || paused || motion.matches || document.hidden || card.matches(':hover,:focus-within')) return;
        timer = setTimeout(() => { select((current + 1) % tabs.length); schedule(); }, card.classList.contains('fms-capture-showcase') ? 8000 : 5500);
      };
      const observer = new IntersectionObserver(([entry]) => {
        visible = entry.isIntersecting;
        syncVideo();
        schedule();
      }, { threshold: .35 });
      observer.observe(card);
      const playbackChanged = () => {syncVideo(); schedule();};
      document.addEventListener('visibilitychange', playbackChanged);
      motion.addEventListener('change', playbackChanged);
      mountedAi.set(card, () => {
        clearTimeout(timer);
        observer.disconnect();
        document.removeEventListener('visibilitychange', playbackChanged);
        motion.removeEventListener('change', playbackChanged);
        for (const video of card.querySelectorAll('video')) video.pause();
      });
      tabsRoot.addEventListener('click', event => {
        const index = tabs.indexOf(event.target.closest('[role="tab"]'));
        if (index < 0) return;
        paused = true;
        select(index);
        schedule();
      });
      tabsRoot.addEventListener('pointerover', event => {
        if (event.pointerType !== 'mouse') return;
        const index = tabs.indexOf(event.target.closest('[role="tab"]'));
        if (index >= 0 && !tabsRoot.matches(':focus-within')) select(index);
      });
      tabsRoot.addEventListener('keydown', event => {
        const delta = { ArrowDown: 1, ArrowRight: 1, ArrowUp: -1, ArrowLeft: -1 }[event.key];
        if (!delta && event.key !== 'Home' && event.key !== 'End') return;
        event.preventDefault();
        paused = true;
        const index = delta ? (current + delta + tabs.length) % tabs.length : event.key === 'Home' ? 0 : tabs.length - 1;
        select(index);
        tabs[index].focus();
        schedule();
      });
      for (const name of ['pointerenter', 'pointerleave', 'focusin', 'focusout']) {
        card.addEventListener(name, () => requestAnimationFrame(schedule));
      }
      const requestedCapture = card.classList.contains('fms-capture-showcase') ? new URLSearchParams(location.search).get('capture') : null;
      const requestedIndex = requestedCapture ? slides.findIndex(slide => slide.id === 'capture-' + requestedCapture) : -1;
      select(Math.max(0, requestedIndex));
    }
  };
  document.addEventListener('pointerdown', event => {
    const resources = document.querySelector('.fms-doc-resources[data-open]');
    if (resources && !resources.contains(event.target)) resources.querySelector('button').click();
  });
  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    const resources = document.querySelector('.fms-doc-resources[data-open]');
    if (!resources) return;
    event.preventDefault();
    const button = resources.querySelector('button');
    if (resources.contains(document.activeElement)) button.focus();
    button.click();
  });
  let frame = 0;
  const mount = () => {
    if (frame) return;
    frame = requestAnimationFrame(() => { frame = 0; installMenu(); installGallery(); installAiCarousels(); });
  };
  new MutationObserver(mount).observe(document.body, { childList: true, subtree: true });
  mount();
})();
