#!/usr/bin/env python3
"""Read server HTML only. This is not a browser/JavaScript/submission test."""
import json
import pathlib
import sys
import urllib.parse
import urllib.request
from html.parser import HTMLParser

ORIGIN = 'http://127.0.0.1:9450'


class LocalRedirects(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        assert_local(newurl)
        return super().redirect_request(req, fp, code, msg, headers, newurl)


def assert_local(url):
    parsed = urllib.parse.urlsplit(url)
    if parsed.scheme != 'http' or parsed.hostname != '127.0.0.1' or parsed.port != 9450 or parsed.username or parsed.password:
        raise ValueError('Refusing any URL outside the disposable local origin')


class Page(HTMLParser):
    def __init__(self, text):
        super().__init__(convert_charrefs=True)
        self.tags, self.comments, self.graphs = [], [], []
        self.script = None
        self.current_span = None
        self.current_pages = []
        self.feed(text)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        self.tags.append((tag, attrs))
        if tag == 'span' and attrs.get('aria-current') == 'page' and 'current' in attrs.get('class', '').split():
            self.current_span = []
        if tag == 'script' and attrs.get('type') == 'application/ld+json':
            self.script = []

    def handle_data(self, data):
        if self.current_span is not None:
            self.current_span.append(data)
        if self.script is not None:
            self.script.append(data)

    def handle_endtag(self, tag):
        if tag == 'span' and self.current_span is not None:
            self.current_pages.append(''.join(self.current_span).strip())
            self.current_span = None
        if tag == 'script' and self.script is not None:
            self.graphs.append(json.loads(''.join(self.script)))
            self.script = None

    def handle_comment(self, data):
        self.comments.append(data.strip())

    def values(self, tag, key, expected, value):
        return [a.get(value) for t, a in self.tags if t == tag and a.get(key) == expected]

    def count_class(self, tag, cls):
        return sum(t == tag and cls in a.get('class', '').split() for t, a in self.tags)


def main():
    reports, phase = pathlib.Path(sys.argv[1]), sys.argv[2]
    data = json.loads((reports / 'seed-all.json').read_text())
    urls = dict(data['urls'])
    for name in ('contact-form-7', 'tablepress'):
        path = reports / f'seed-plugin-{name}.json'
        if path.exists():
            urls.update(json.loads(path.read_text())['urls'])
    yoast = phase in ('wordpress-seo', 'combined')
    cases = [('article', urls['article'], urls['article'])]
    if phase in ('contact-form-7', 'combined'):
        cases.append(('contact-form-7', urls['contact-form-7'], urls['contact-form-7']))
    if phase in ('tablepress', 'combined'):
        cases.append(('tablepress', urls['tablepress'], urls['tablepress']))
    if yoast:
        site = urls['sitemap']
        cases.extend([
            ('sitemap-first', site, site),
            ('sitemap-page2', site + '?shiki_map_posts=2', site + '?shiki_map_posts=2'),
            ('sitemap-page2-tracking', site + '?shiki_map_posts=2&utm_source=local-ci', site + '?shiki_map_posts=2'),
            ('ordinary-page-ignore-pagination', urls['article'] + '?shiki_map_posts=2', urls['article']),
        ])
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), LocalRedirects())
    results = []
    for kind, url, expected in cases:
        assert_local(url)
        with opener.open(url, timeout=30) as response:
            text = response.read().decode('utf-8')
            status = response.status
            marker = response.headers.get('X-Shiki-CI-Smoke')
        page = Page(text)
        canonical = page.values('link', 'rel', 'canonical', 'href')
        og_url = page.values('meta', 'property', 'og:url', 'content')
        descriptions = page.values('meta', 'name', 'description', 'content')
        checks = {
            'http_200': status == 200,
            'owned_runtime_header': marker == 'disposable',
            'theme_header': page.count_class('header', 'site-header') == 1,
            'theme_footer': page.count_class('footer', 'site-footer') == 1,
            'wp_head_once': page.comments.count('shiki-ci-wp-head') == 1,
            'wp_body_open_once': page.comments.count('shiki-ci-body-open') == 1,
            'wp_footer_once': page.comments.count('shiki-ci-wp-footer') == 1,
            'one_title': sum(t == 'title' for t, _ in page.tags) == 1,
            'one_expected_canonical': canonical == [expected],
            'one_expected_og_url': og_url == [expected],
            'no_duplicate_description': len(descriptions) <= 1,
            'theme_metadata_ownership': ('ShikiPress metadata' in page.comments) is not yoast,
            'no_rendered_php_fatal': 'Fatal error' not in text and 'Parse error' not in text,
        }
        if kind.startswith('article') or kind == 'ordinary-page-ignore-pagination':
            checks['article_body'] = 'SHIKI_PLUGIN_SMOKE_BODY' in text
            checks['article_description'] = len(descriptions) == 1
        if kind == 'contact-form-7':
            checks['real_cf7_wrapper'] = page.count_class('div', 'wpcf7') == 1
            checks['real_cf7_form'] = page.count_class('form', 'wpcf7-form') == 1
            checks['real_cf7_email_field'] = len(page.values('input', 'name', 'your-email', 'type')) == 1
            checks['shortcode_expanded'] = '[contact-form-7' not in text
        if kind == 'tablepress':
            checks['real_tablepress_table'] = page.count_class('table', 'tablepress') == 1
            checks['both_table_rows'] = 'CI_TABLE_ROW_ALPHA' in text and 'CI_TABLE_ROW_BETA' in text
            checks['shortcode_expanded'] = '[table id=' not in text
        if yoast:
            nodes = [node for graph in page.graphs if isinstance(graph, dict) for node in graph.get('@graph', [])]
            webpages = [n for n in nodes if 'WebPage' in ([n.get('@type')] if isinstance(n.get('@type'), str) else n.get('@type', []))]
            checks['one_yoast_webpage'] = len(webpages) == 1
            checks['schema_url_matches_canonical'] = len(webpages) == 1 and webpages[0].get('url') == expected
            checks['schema_id_matches_canonical'] = len(webpages) == 1 and webpages[0].get('@id') == expected
        if kind.startswith('sitemap'):
            checks['real_reader_sitemap'] = page.count_class('div', 'shiki-html-sitemap') == 1
            checks['pagination_exists'] = 'shiki_map_posts=2' in text
            if 'page2' in kind:
                checks['second_slice_current'] = '2' in page.current_pages
        (reports / f'http-{phase}-{kind}.html').write_text(text)
        results.append({'case': kind, 'url': url, 'checks': checks, 'pass': all(checks.values())})
    report = {'phase': phase, 'transport': 'native PHP development server / local HTTP / no JavaScript',
              'cases': results, 'pass': all(r['pass'] for r in results)}
    (reports / f'http-{phase}.json').write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps(report, indent=2))
    return 0 if report['pass'] else 1


if __name__ == '__main__':
    sys.exit(main())
