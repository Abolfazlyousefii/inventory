"""Exercise the actual drawer markup, JS and CSS with a mocked HTTP API.

Run: python tests/browser/notification-center.py (Playwright + Chromium required).
No ERP server or database is contacted. The Laravel feature tests cover the API.
"""
import math
import os
import re
from pathlib import Path
from urllib.parse import parse_qs, urlparse

from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[2]
layout = (ROOT / 'resources/views/layouts/app.blade.php').read_text()
panel = re.search(r'<section id="notifPanel".*?</section>', layout, re.S).group()
panel = panel.replace("{{ route('notifications.read-all', [], false) }}", '/notifications/read-all')
panel = panel.replace("{{ route('notifications.index', ['filter' => 'read'], false) }}", '/notifications?filter=read')
panel = panel.replace("{{ route('notifications.index', [], false) }}", '/notifications').replace('@csrf', '')
bell = re.search(r'<button class="app-icon-btn app-notification-btn".*?</button>', layout, re.S).group()


def item(number, read=False):
    return dict(id=number, title=f'اعلان {number}', message='جزئیات اعلان آزمایشی <img src=x onerror=alert(1)>',
                category='مالی', created_at='2026-11-25T08:00:00+00:00', created_at_human='چند لحظه پیش',
                read_at='2026-11-25' if read else None, open_url=f'/notifications/{number}/open',
                read_url=f'/notifications/{number}/read')


def html(history=False):
    # Render a second surface using the same shared data-attribute contract as the Blade page.
    page = panel.replace('id="notifPanel"', '').replace('class="notif-panel"', 'class="notif-center"').replace('role="dialog" aria-modal="true" aria-hidden="true"', '').replace('data-filter="unread"', 'data-filter="read"').replace('data-per-page="6"', 'data-per-page="20"')
    page = re.sub(r'\s+id="[^"]+"', '', page) if history else ''
    return f'''<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="mock">
    <link rel="stylesheet" href="/lib/bootstrap.rtl.min.css"><link rel="stylesheet" href="/css/Vazirmatn.css"><link rel="stylesheet" href="/css/admin-theme.css"><link rel="stylesheet" href="/css/app-shell.css"><link rel="stylesheet" href="/css/app-theme.css"></head>
    <body data-notifications-count-url="/notifications/unread-count" data-notifications-latest-url="/notifications/latest"><div class="app-layout"><header style="height:62px;display:flex;justify-content:flex-end;align-items:center;padding:12px">{bell}</header>{page}</div><div id="notificationBackdrop" class="notification-backdrop" aria-hidden="true" hidden></div>{panel}<script src="/js/app-shell.js"></script></body></html>'''


with sync_playwright() as playwright:
    browser = playwright.chromium.launch(executable_path=os.environ.get('CHROMIUM_PATH') or '/usr/bin/chromium', args=['--no-sandbox'])
    page = browser.new_page(viewport={'width': 1366, 'height': 768})
    items = [item(i) for i in range(120, 0, -1)]
    fail = {'read_all': False, 'list': False}
    errors = []
    page.on('pageerror', lambda error: errors.append(str(error)))

    def route_request(route):
        url = urlparse(route.request.url)
        path = url.path
        query = parse_qs(url.query)
        count = lambda: sum(not i['read_at'] for i in items)
        if path == '/notifications/unread-count':
            route.fulfill(json={'count': count()})
        elif path == '/notifications/latest':
            if fail['list']:
                route.fulfill(status=500, json={'message': 'failed'})
                return
            filter_name = query.get('filter', ['unread'])[0]
            rows = [i for i in items if bool(i['read_at']) == (filter_name == 'read')] if filter_name != 'all' else items
            limit = int(query.get('per_page', ['6'])[0])
            last = max(1, math.ceil(len(rows) / limit))
            number = min(last, int(query.get('page', ['1'])[0]))
            route.fulfill(json={'items': rows[(number-1)*limit:number*limit], 'count': count(), 'total': len(rows), 'page': number, 'last_page': last})
        elif path == '/notifications/read-all':
            assert route.request.method == 'POST'
            assert route.request.headers['x-csrf-token'] == 'mock'
            if fail['read_all']:
                route.fulfill(status=500, json={'message': 'failed'})
                return
            for row in items:
                row['read_at'] = '2026-11-25'
            route.fulfill(json={'ok': True, 'count': count()})
        elif re.fullmatch(r'/notifications/\d+/read', path):
            assert route.request.method == 'POST'
            next(i for i in items if i['id'] == int(path.split('/')[2]))['read_at'] = '2026-11-25'
            route.fulfill(json={'ok': True, 'count': count(), 'url': '/notifications?filter=read'})
        elif path in ('/', '/notifications'):
            route.fulfill(content_type='text/html', body=html(query.get('filter') == ['read']))
        elif path.startswith(('/css/', '/js/', '/lib/', '/fonts/')) and (ROOT / 'public' / path.lstrip('/')).is_file():
            route.fulfill(path=str(ROOT / 'public' / path.lstrip('/')))
        else:
            route.fulfill(status=404, body='')

    page.route('**/*', route_request)
    page.goto('http://127.0.0.1:8765/')
    expect(page.locator('#notifBadge')).to_have_text('۱۲۰')
    page.locator('#notifBell').click()
    expect(page.locator('#notifList .notif-card')).to_have_count(6)
    expect(page.locator('#notificationBackdrop')).to_be_visible()
    assert page.locator('.app-layout').evaluate('(e) => e.inert')
    expect(page.locator('#notifUnreadText')).to_have_text('۱۲۰ خوانده‌نشده')
    assert page.locator('#notifList img').count() == 0, 'Notification content must be escaped'
    for width, height in [(1366, 768), (390, 844), (320, 568), (844, 390)]:
        page.set_viewport_size({'width': width, 'height': height})
        box = page.locator('#notifPanel').bounding_box()
        assert box['x'] >= 0 and box['x'] + box['width'] <= width + 1, (width, box)
        assert box['y'] + box['height'] <= height + 1, (height, box)
        assert page.locator('#notifList').evaluate('(e) => e.scrollWidth <= e.clientWidth + 1')
    page.set_viewport_size({'width': 390, 'height': 844})
    page.locator('#notifPanel [data-notification-step="1"]').click()
    expect(page.locator('#notifList form').first).to_have_attribute('data-notification-id', '114')
    page.locator('#notifList form').first.locator('button').click()
    page.wait_for_url('**/notifications?filter=read')
    expect(page.locator('#notifBadge')).to_have_text('۱۱۹')
    expect(page.locator('.notif-center .notif-card')).to_have_count(1)
    page.locator('#notifBell').click()
    expect(page.locator('#notifList form')).to_have_count(6)
    fail['read_all'] = True
    page.locator('#notifReadAllBtn').click()
    expect(page.locator('#notifPanel [data-notification-error]')).to_be_visible()
    expect(page.locator('#notifBadge')).to_have_text('۱۱۹')
    fail['read_all'] = False
    page.locator('#notifReadAllBtn').click()
    expect(page.locator('#notifBadge')).to_be_hidden()
    expect(page.locator('#notifList .notif-empty > span:last-child')).to_have_text('همه اعلان‌ها را خوانده‌اید.')
    expect(page.locator('.notif-center .notif-card')).to_have_count(20)
    expect(page.locator('#notifUnreadText')).to_have_text('۰ خوانده‌نشده')
    assert len(items) == 120 and all(i['read_at'] for i in items)
    items.insert(0, item(121))
    page.evaluate("document.dispatchEvent(new Event('visibilitychange'))")
    expect(page.locator('#notifBadge')).to_have_text('۱')
    expect(page.locator('#notifList form')).to_have_count(1)
    expect(page.locator('#notifList form')).to_have_attribute('data-notification-id', '121')
    fail['list'] = True
    page.evaluate("document.dispatchEvent(new Event('visibilitychange'))")
    expect(page.locator('#notifPanel [data-notification-error]')).to_be_visible()
    fail['list'] = False
    page.locator('#notifPanel [data-notification-retry]').click()
    expect(page.locator('#notifPanel [data-notification-error]')).to_be_hidden()
    page.keyboard.press('Escape')
    expect(page.locator('#notifPanel')).to_be_hidden()
    expect(page.locator('#notificationBackdrop')).to_be_hidden()
    assert not page.locator('.app-layout').evaluate('(e) => e.inert')
    assert not errors, errors
    browser.close()
    print('PASS: real Chromium drawer, responsive layout (4 viewports), exact counts, pagination, history, read-one/read-all, new arrivals, retry, escaping, keyboard dismissal (mock API).')
