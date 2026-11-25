"""Offline browser regression for notification layout and real wheel scrolling.

Run: python tests/browser/notification-presentation.py (Playwright + Chromium).
Uses the real CSS and drawer/pager markup, with fixture notification rows; no ERP or network.
"""
import os
from io import BytesIO
from PIL import Image
import re
from pathlib import Path

from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[2]
layout = (ROOT / 'resources/views/layouts/app.blade.php').read_text()
inbox = (ROOT / 'resources/views/notifications/index.blade.php').read_text()
styles = '\n'.join((ROOT / 'public' / name).read_text() for name in (
    'lib/bootstrap.rtl.min.css', 'css/admin-theme.css', 'css/admin.css',
    'css/app-shell.css', 'css/app-theme.css'))
# Keep Blade pagination HTML and card classes in the fixture, substitute dynamic values.
pager = re.search(r'<(?:div|nav) class="notif-pagination".*?</(?:div|nav)>', layout, re.S).group()
pager = pager.replace(' hidden>', '>')
pager = re.sub(r'(<span data-notification-page-label[^>]*>).*?</span>', r'\g<1>صفحه ۲ از ۵</span>', pager)
page_pager = re.search(r'<nav class="notif-pagination".*?</nav>', inbox, re.S).group()
page_pager = re.sub(r'@if\(.*?@endif', '', page_pager)
page_pager = re.sub(r'{{.*?}}', '#', page_pager)
page_pager = re.sub(r'(<span data-notification-page-label[^>]*>).*?</span>', r'\g<1>صفحه ۲ از ۵</span>', page_pager)
drawer = re.search(r'<section id="notifPanel".*?</section>', layout, re.S).group()
drawer = re.sub(r'{{.*?}}', '#', drawer).replace('@csrf', '')
drawer = drawer.replace('در حال دریافت…', '۲۰ خوانده‌نشده')
drawer = re.sub(r'(<div id="notifList"[^>]*>).*?(?=\s*<nav class="notif-pagination")', r'\g<1>__ROWS__</div>', drawer, flags=re.S)
drawer = re.sub(r'<nav class="notif-pagination".*?</nav>', lambda _: pager, drawer, flags=re.S)
header = re.search(r'<header class="notif-center__header">.*?</header>', inbox, re.S).group()
header = re.sub(r'{{.*?}}', '10', header).replace('@csrf', '').replace('@disabled($unreadCount === 0)', '')
rows = ''.join(f'''<form><button type="button" class="notif-card {'notif-card--unread' if i % 2 else ''}">
<span class="notif-card__body"><span class="notif-card__top"><span class="notif-card__badge" data-category="{("مالی", "انبار", "فروش", "پرسنل")[i % 4]}">{("مالی", "انبار", "فروش", "پرسنل")[i % 4]}</span><time>۲ دقیقه پیش</time></span>
<span class="notif-card__title-line"><span class="notif-card__dot"></span><strong class="notif-card__title">فاکتور شماره {i} آماده بررسی است</strong></span>
<span class="notif-card__message">برای بررسی وضعیت فاکتور و اطلاعات تکمیلی، جزئیات را مشاهده کنید.</span><span class="notif-card__meta"><span class="notif-card__link">مشاهده جزئیات <span>←</span></span></span></span></button></form>''' for i in range(20))
drawer = drawer.replace('__ROWS__', rows)
html = f'''<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>{styles}</style></head>
<body class="app-body"><div class="app-layout"><main class="app-main-shell"><header class="app-topbar">ERP</header><div class="app-content"><div class="container py-3 notif-center">{header}<nav class="notif-center__tabs"><a class="notif-tool-btn is-active">خوانده‌نشده</a><a class="notif-tool-btn">تاریخچه</a><a class="notif-tool-btn">همه</a></nav><div class="notif-list notif-center__list">{rows}</div>{page_pager}</div></div></main></div><div class="notification-backdrop" hidden></div>{drawer}</body></html>'''

with sync_playwright() as p:
    browser = p.chromium.launch(executable_path=os.environ.get('CHROMIUM_PATH', '/usr/bin/chromium'), args=['--no-sandbox'])
    page = browser.new_page(viewport={'width': 1366, 'height': 768})
    page.set_content(html, wait_until='domcontentloaded')
    listing = page.locator('.notif-center__list')
    assert 'hidden' not in re.search(r'<nav class="notif-pagination"[^>]*>', inbox).group(), 'The server-rendered pager must remain visible even for one page'
    icons = listing.locator('.notif-card').evaluate_all("cards => cards.slice(0,4).map(e => getComputedStyle(e).getPropertyValue('--inbox-icon'))")
    assert len(set(icons)) == 4, 'Each category must have its own decorative icon'
    assert listing.locator('.notif-card__body').first.evaluate("e => getComputedStyle(e, '::after').maskImage.startsWith('url(')")

    assert listing.evaluate("e => getComputedStyle(e).overflowY") == 'visible', 'Inbox must not create a nested scroll container'
    assert listing.evaluate("e => getComputedStyle(e).overscrollBehaviorY") == 'auto', 'Inbox must allow scroll chaining'
    listing.locator('.notif-card').first.hover()
    before = page.evaluate('window.scrollY')
    page.mouse.wheel(0, 570)
    page.wait_for_function(f'window.scrollY > {before}')
    assert page.locator('.notif-center [data-notification-step]').first.bounding_box()['height'] <= 26
    page.mouse.wheel(0, -1000)
    page.wait_for_function('window.scrollY === 0')
    page.evaluate("document.querySelector('.notification-backdrop').hidden = false; document.querySelector('.notif-panel').classList.add('is-open'); document.body.classList.add('notification-dialog-open')")
    panel = page.locator('.notif-panel')
    assert panel.evaluate("e => getComputedStyle(e).backdropFilter.includes('blur(')"), 'Drawer surface itself must be frosted'
    assert panel.locator('.notif-list').evaluate("e => getComputedStyle(e).overflowY") == 'auto', 'Drawer must retain independent scrolling'
    assert panel.locator('[data-notification-step]').first.bounding_box()['height'] <= 26, 'Desktop pager must be compact'
    panel.locator('.notif-card').first.hover()
    page.mouse.wheel(0, 380)
    page.wait_for_function("document.querySelector('.notif-panel .notif-list').scrollTop > 0")
    assert page.evaluate('window.scrollY') == 0, 'The modal must not scroll the background page'
    # Measure rendered background alpha, not just the presence of a blur property.
    alpha = """e => { const c = document.createElement('canvas'); c.width = c.height = 1;
        const ctx = c.getContext('2d'); ctx.fillStyle = getComputedStyle(e).backgroundColor;
        ctx.fillRect(0, 0, 1, 1); return ctx.getImageData(0, 0, 1, 1).data[3] / 255; }"""
    for theme in ('light', 'dark'):
        page.evaluate('(theme) => document.documentElement.dataset.appTheme = theme', theme)
        for width, height in ((1366, 768), (390, 844), (320, 568), (844, 390)):
            page.set_viewport_size({'width': width, 'height': height})
            page.wait_for_timeout(220)
            box = panel.bounding_box()
            assert box['x'] >= -1 and box['x'] + box['width'] <= width + 1, (theme, width, box)
            assert box['y'] >= -1 and box['y'] + box['height'] <= height + 1, (theme, height, box)
            assert panel.locator('.notif-list').evaluate('e => e.scrollWidth <= e.clientWidth + 1')
            assert listing.evaluate('e => e.scrollWidth <= e.clientWidth + 1')
            assert listing.locator('.notif-card__body').first.evaluate("e => getComputedStyle(e).display") == 'grid'
            for card in listing.locator('.notif-card').all()[:4]:
                assert card.evaluate('e => e.scrollWidth <= e.clientWidth + 1'), (theme, width, 'inbox card overflow')
                assert card.locator('.notif-card__message').evaluate("e => getComputedStyle(e).overflowY") == 'visible'

            assert panel.evaluate(alpha) <= .35, (theme, 'Glass tint too opaque', panel.evaluate(alpha))
            assert panel.locator('.notif-card').first.evaluate(alpha) == 1, 'Text must sit on solid cards'
            limit = 28 if width <= 575 else 26
            for surface in (panel, page.locator('.notif-center')):
                for control in surface.locator('[data-notification-step]').all():
                    b = control.bounding_box()
                    assert 24 <= b['height'] <= limit and b['width'] >= 44, (theme, width, b)
    # Pixel-level proof: the empty-state glass must reveal changes in the content behind it.
    page.set_viewport_size({'width': 1366, 'height': 768})
    page.evaluate('''() => {
        document.documentElement.dataset.appTheme = 'light';
        const source = document.createElement('div'); source.id = 'glass-probe';
        source.style.cssText = 'position:fixed;inset:0;z-index:1060;background:#182c48;pointer-events:none';
        document.body.append(source);
        document.querySelector('.notif-panel .notif-list').innerHTML = '<div class="notif-empty"><span class="notif-empty__icon">✓</span><span>همه اعلان‌ها را خوانده‌اید.</span></div>';
        document.querySelector('.notif-panel .notif-pagination').hidden = true;
    }''')
    page.wait_for_timeout(250)
    box = panel.bounding_box()
    # Left side of empty-state area: clear of the text, borders and controls.
    sample = (round(box['x'] + 32), round(panel.locator('.notif-list').bounding_box()['y'] + 28))
    def pixel():
        return Image.open(BytesIO(page.screenshot())).convert('RGB').getpixel(sample)
    dark = pixel()
    page.evaluate("document.querySelector('#glass-probe').style.background = '#e6edf5'")
    light = pixel()
    assert sum(abs(a-b) for a,b in zip(light,dark))/3 >= 90, (dark, light, 'Actual glass must reveal the backdrop')
    # A sharp edge in the source should become a gradual transition through the panel.
    page.evaluate(f"document.querySelector('#glass-probe').style.background = 'linear-gradient(90deg,#182c48 {sample[0]}px,#e6edf5 {sample[0]}px)'")
    image = Image.open(BytesIO(page.screenshot())).convert('RGB')
    left = image.getpixel((sample[0]-4, sample[1])); right = image.getpixel((sample[0]+4, sample[1]))
    assert sum(abs(a-b) for a,b in zip(left,right))/3 < 75, (left, right, 'Backdrop edge must be blurred')
    browser.close()
    print('PASS: visible glass (pixel test), real blur, opaque cards, 26/28px pager, category icons, inbox card grid, wheel scrolling and light/dark at 4 viewport sizes.')
