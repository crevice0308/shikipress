#!/usr/bin/env python3
"""Request every URL of the seeded scenario from the disposable local site and check the server response.

This reads server HTML/XML/JSON only. It is not a browser or JavaScript test and never leaves the loopback origin.
"""
import json
import pathlib
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
from html.parser import HTMLParser

ORIGIN = ('http', '127.0.0.1', 8080)
VOID = {'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'}


def assert_local(url):
    parsed = urllib.parse.urlsplit(url)
    if (parsed.scheme, parsed.hostname, parsed.port) != ORIGIN or parsed.username or parsed.password:
        raise ValueError('Refusing any URL outside the disposable local origin: ' + url)


class LocalRedirects(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        assert_local(newurl)
        return super().redirect_request(req, fp, code, msg, headers, newurl)


class Page(HTMLParser):
    """Collects start tags, comments and the text of the first h1."""

    def __init__(self, text):
        super().__init__(convert_charrefs=True)
        self.tags, self.comments, self.h1 = [], [], []
        self._in_h1 = False
        self.feed(text)

    def handle_starttag(self, tag, attrs):
        self.tags.append((tag, dict(attrs)))
        if tag == 'h1':
            self._in_h1 = True
            self.h1.append('')

    def handle_endtag(self, tag):
        if tag == 'h1':
            self._in_h1 = False

    def handle_data(self, data):
        if self._in_h1 and self.h1:
            self.h1[-1] += data

    def handle_comment(self, data):
        self.comments.append(data.strip())

    def count(self, tag, cls=None, **attrs):
        return sum(1 for name, a in self.tags if name == tag and (cls is None or cls in (a.get('class') or '').split())
                   and all(a.get(key) == value for key, value in attrs.items()))

    def values(self, tag, key, expected, value):
        return [a.get(value) for name, a in self.tags if name == tag and a.get(key) == expected]

    def classes(self):
        return {name for _, a in self.tags for name in (a.get('class') or '').split()}


def fetch(opener, url, cookies=None):
    assert_local(url)
    request = urllib.request.Request(url, headers={'User-Agent': 'ShikiPress-RenderSweep/1 (local CI)', 'Accept': '*/*'})
    if cookies:
        request.add_header('Cookie', '; '.join(f'{name}={urllib.parse.quote(value, safe="")}' for name, value in cookies.items()))
    try:
        response = opener.open(request, timeout=60)
    except urllib.error.HTTPError as error:
        response = error
    body = response.read()
    return {'status': response.status if hasattr(response, 'status') else response.code, 'url': response.geturl(),
            'type': (response.headers.get('Content-Type') or '').lower(), 'headers': response.headers,
            'text': body.decode('utf-8', errors='replace'), 'bytes': len(body)}


# Titles and slugs of the hidden fixtures a page may legitimately show: only its own.
OWN_SECRETS = {
    'single-password': ['SECRET_TEST_PASSWORD', 'secret-test-password'],
    'single-excluded': ['SECRET_TEST_EXCLUDED', 'SECRET_TEST_BODY_EXCLUDED', 'secret-test-excluded'],
    'page-password': ['SECRET_TEST_PAGE_PASSWORD', 'secret-test-page-password'],
}


def leaks(name, text):
    for own in OWN_SECRETS.get(name, []):
        text = text.replace(own, '')
    return sorted(set(re.findall(r'secret[_-]test[A-Za-z0-9_-]*', text, re.I)))[:8]


def theme_page(name, result, expect):
    """Checks shared by every page rendered through the theme's header and footer."""
    text = result['text']
    page = Page(text)
    ids = [a['id'] for _, a in page.tags if a.get('id')]
    duplicates = sorted({value for value in ids if ids.count(value) > 1})
    known = set(ids)
    fragments = [urllib.parse.unquote(a['href'][1:]) for tag, a in page.tags if tag == 'a' and (a.get('href') or '').startswith('#') and len(a['href']) > 1]
    references = [value for _, a in page.tags for key in ('aria-controls', 'aria-labelledby', 'aria-describedby') for value in (a.get(key) or '').split()]
    labels = [a['for'] for tag, a in page.tags if tag == 'label' and a.get('for')]
    expected_status = 404 if name == 'not-found' else 200
    checks = {
        'expected_status': result['status'] == expected_status,
        'html_content_type': result['type'].startswith('text/html'),
        'no_rendered_php_error': 'Fatal error' not in text and 'Parse error' not in text and 'Uncaught ' not in text,
        'one_title': page.count('title') == 1,
        'one_h1': len(page.h1) == 1 and page.h1[0].strip() != '',
        'theme_header': page.count('header', 'site-header') == 1,
        'theme_footer': page.count('footer', 'site-footer') == 1,
        'one_main': page.count('main', id='main-content') == 1,
        'unique_ids': not duplicates,
        'fragment_links_resolve': all(value in known for value in fragments),
        'aria_references_resolve': all(value in known for value in references),
        'label_targets_exist': all(value in known for value in labels),
        'at_most_one_canonical': len(page.values('link', 'rel', 'canonical', 'href')) <= 1,
        'at_most_one_description': len(page.values('meta', 'name', 'description', 'content')) <= 1,
        'theme_metadata_block_once': page.comments.count('ShikiPress metadata') == 1,
        'no_hidden_fixture_leak': not leaks(name, text),
    }
    detail = {'duplicate_ids': duplicates[:8], 'dangling_fragments': sorted({v for v in fragments if v not in known})[:8],
              'dangling_aria': sorted({v for v in references if v not in known})[:8], 'dangling_labels': sorted({v for v in labels if v not in known})[:8],
              'h1': [value.strip()[:60] for value in page.h1][:4], 'leaks': leaks(name, text)}
    classes = page.classes()
    ads = page.count('aside', 'shiki-ad')
    robots = ' '.join(page.values('meta', 'name', 'robots', 'content'))
    if name == 'home':
        checks['seasonal_event_shown'] = expect['event_label'] in text
        checks['popular_section_shown'] = 'よく読まれているしおり' in text
        checks['home_cards'] = page.count('article', 'post-card') >= 6
        checks['footer_ad_slot'] = ads >= 1
        checks['home_indexable'] = 'noindex' not in robots
        checks['menu_public_items_only'] = 'TEST メニュー固定ページ' in text and 'TEST メニュー外部' in text
    if name in ('single-featured', 'single-seo-fields', 'single-noindex', 'single-long-title'):
        checks['table_of_contents'] = 'article-toc' in classes
        checks['content_ads_between_blocks'] = ads >= 4
        checks['related_posts'] = 'related-posts' in classes
    if name == 'single-featured':
        checks['featured_image'] = 'article-featured' in classes and 'TEST キャプション' in text
        checks['comments_listed'] = 'TEST コメント本文' in text and 'TEST 返信本文' in text and page.count('form', id='commentform') == 1
        checks['article_schema'] = '"BlogPosting"' in text
    if name == 'single-seo-fields':
        checks['custom_title_and_description'] = 'TEST 個別SEOタイトル' in text and 'TEST 個別の説明' in text
    if name == 'single-noindex':
        checks['noindex_robots'] = 'noindex' in robots
    if name == 'single-classic':
        checks['table_of_contents'] = 'article-toc' in classes and all(heading in text for heading in expect['classic_headings'])
        checks['classic_content_ads'] = ads >= 4
        checks['page_links'] = 'page-links-nav' in classes
        checks['no_marker_left'] = 'data-shiki-ad-mark' not in text
    if name == 'single-classic-page2':
        checks['second_page_body'] = 'TEST 二ページ目の見出しA' in text and 'TEST クラシック見出しA' not in text
    if name in ('single-password', 'page-password'):
        checks['password_form_only'] = 'post-password-form' in classes and 'article-toc' not in classes and ads == 0
        checks['protected_noindex'] = 'noindex' in robots
    if name == 'single-excluded':
        checks['excluded_noindex'] = 'noindex' in robots
    if name == 'page-child':
        checks['ancestor_breadcrumb'] = 'shiki-breadcrumbs' in classes and expect['child_parent_title'] in text
    if name in ('page-sitemap', 'page-sitemap-2'):
        checks['reader_sitemap'] = page.count('div', 'shiki-html-sitemap') == 1 and 'shiki_map_posts=' in text
    if name in ('category', 'category-paged', 'tag', 'author', 'date-year', 'date-month', 'stories', 'stories-paged', 'search', 'search-paged'):
        checks['listing_cards'] = page.count('article', 'post-card') >= 1
    if name == 'author':
        checks['author_article_listed'] = expect['author_post_title'] in text
    if name in ('search-empty', 'search-missing-category'):
        checks['empty_state'] = ('empty-state' in classes) or page.count('article', 'post-card') >= 1
    if name == 'search-markup':
        checks['search_term_escaped'] = '<script>alert(1)</script>' not in text and 'alert(1)' in text
    if name in ('search', 'search-paged', 'search-empty', 'search-missing-category', 'search-markup', 'not-found', 'attachment'):
        checks['noindex_robots'] = 'noindex' in robots
    if name == 'not-found':
        checks['not_found_template'] = 'not-found' in classes
    if name == 'preview-season-anonymous':
        body = [(a.get('class') or '').split() for tag, a in page.tags if tag == 'body']
        checks['preview_requires_administrator'] = bool(body) and 'season-' + expect['preview_season'] not in body[0]
    return checks, detail


def run(private_file, reports):
    data = json.loads(pathlib.Path(private_file).read_text())
    expect = data['expect']
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), LocalRedirects())
    pages = reports / 'render-pages'
    pages.mkdir(parents=True, exist_ok=True)
    results = []
    for name, url in data['public'].items():
        result = fetch(opener, url)
        text = result['text']
        detail = {}
        if name.startswith('feed'):
            root = ET.fromstring(text)
            checks = {'status_200': result['status'] == 200, 'xml_feed': 'xml' in result['type'], 'no_hidden_fixture_leak': not leaks(name, text)}
            if name in ('feed', 'feed-category'):
                checks['has_items'] = len(root.findall('./channel/item')) >= 1
            if name in ('feed-comments', 'feed-post-comments'):
                checks['has_comment_items'] = len(root.findall('./channel/item')) >= 1
        elif name.startswith('sitemap'):
            ET.fromstring(text)
            checks = {'status_200': result['status'] == 200, 'xml_sitemap': 'xml' in result['type'], 'no_hidden_fixture_leak': not leaks(name, text),
                      'has_locations': '<loc>' in text}
            if name == 'sitemap-posts':
                checks['noindex_article_omitted'] = text.count('<loc>') >= 15 and 'test-autumn-3' not in text
        elif name == 'robots':
            checks = {'status_200': result['status'] == 200, 'plain_text': result['type'].startswith('text/plain'), 'sitemap_listed': 'wp-sitemap.xml' in text}
        elif name.startswith('rest-'):
            rows = json.loads(text)
            checks = {'status_200': result['status'] == 200, 'json_list': isinstance(rows, list) and len(rows) >= 1, 'no_hidden_fixture_leak': not leaks(name, text)}
        elif name == 'embed':
            checks = {'status_200': result['status'] == 200, 'no_rendered_php_error': 'Fatal error' not in text and 'Parse error' not in text,
                      'no_hidden_fixture_leak': not leaks(name, text)}
        else:
            checks, detail = theme_page(name, result, expect)
        if result['type'].startswith('text/html'):
            (pages / f'{name}.html').write_text(text)
        results.append({'case': name, 'url': url, 'status': result['status'], 'bytes': result['bytes'], 'checks': checks,
                        'pass': all(checks.values()), **({'detail': detail} if not all(checks.values()) else {})})
    for name, url in data['admin'].items():
        result = fetch(opener, url, data['cookies'])
        text = result['text']
        final = urllib.parse.urlsplit(result['url'])
        checks = {'status_200': result['status'] == 200, 'stays_signed_in': 'wp-login.php' not in final.path,
                  'no_rendered_php_error': 'Fatal error' not in text and 'Parse error' not in text and 'Uncaught ' not in text}
        if name == 'preview-season':
            page = Page(text)
            body = [a.get('class') or '' for tag, a in page.tags if tag == 'body']
            checks['previewed_season_applied'] = bool(body) and 'season-' + expect['preview_season'] in body[0].split()
            checks['preview_not_cacheable'] = 'no-cache' in (result['headers'].get('Cache-Control') or '')
        elif name.endswith('-signed-in'):
            # The public page with the administrator's toolbar: same structure and public scope as for readers.
            signed, signed_detail = theme_page(name.replace('-signed-in', '').replace('single', 'single-featured'), result, expect)
            signed['toolbar_shown'] = 'id="wpadminbar"' in text
            checks.update(signed)
            if not all(signed.values()):
                print(json.dumps({'case': name, 'failed_checks': [key for key, value in signed.items() if not value], 'detail': signed_detail}, ensure_ascii=False))
        else:
            checks['admin_screen'] = 'id="wpbody"' in text
        if name.startswith('admin-tab-') or name == 'admin-submenu-design':
            checks['theme_admin_wrapper'] = 'shiki-admin-heading' in text
            checks['no_unclosed_form_nesting'] = len(re.findall(r'<form\b', text)) == len(re.findall(r'</form>', text))
        if name in ('admin-edit-post', 'admin-edit-draft', 'admin-edit-page', 'admin-new-post'):
            checks['theme_meta_boxes'] = 'shiki_editor_nonce' in text and 'shiki_seo_nonce' in text
        if name == 'admin-category-edit':
            checks['term_seo_fields'] = 'shiki_term_seo_nonce' in text
        results.append({'case': name, 'status': result['status'], 'bytes': result['bytes'], 'checks': checks, 'pass': all(checks.values())})
    report = {'transport': 'native PHP development server / loopback HTTP / no JavaScript', 'cases': results,
              'passed': sum(1 for r in results if r['pass']), 'failed': [r for r in results if not r['pass']]}
    (reports / 'render-sweep.json').write_text(json.dumps(report, indent=2, ensure_ascii=False) + '\n')
    for row in results:
        print(('PASS ' if row['pass'] else 'FAIL ') + row['case'] + f" [{row['status']}, {row['bytes']} bytes, {len(row['checks'])} checks]")
    for row in report['failed']:
        print(json.dumps({'case': row['case'], 'failed_checks': [key for key, value in row['checks'].items() if not value], 'detail': row.get('detail')}, ensure_ascii=False))
    print(json.dumps({'cases': len(results), 'checks': sum(len(r['checks']) for r in results), 'passed': report['passed'], 'failed': len(report['failed'])}))
    return 0 if not report['failed'] else 1


if __name__ == '__main__':
    sys.exit(run(sys.argv[1], pathlib.Path(sys.argv[2])))
