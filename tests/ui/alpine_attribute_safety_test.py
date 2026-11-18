"""Offline regression checks for Blade data attributes and Alpine expressions.

Uses Python's HTML parser and Node, with Blade escaping simulated (not Laravel rendering).
Run: python3 -m unittest discover -s tests/ui -p 'alpine_attribute_safety_test.py'
"""
import html
from html.parser import HTMLParser
import json
from pathlib import Path
import re
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]
PACKAGES = ROOT / 'resources/views/operator/packages/index.blade.php'
ROUTER = ROOT / 'resources/views/operator/settings/router.blade.php'
NAMES = ["Kevin's plan", 'A "quoted" plan', "'); globalThis.pwned = true; //", '</script><script>globalThis.pwned=true</script>', '&quot; \\ \n']

class TagParser(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.tags = []
    def handle_starttag(self, tag, attrs):
        self.tags.append((tag, dict(attrs)))

def run_node(source, payload):
    result = subprocess.run(['node', '-e', source], input=json.dumps(payload), text=True, capture_output=True, check=True)
    return json.loads(result.stdout)

class AlpineAttributeSafetyTest(unittest.TestCase):
    def test_package_actions_round_trip_untrusted_names(self):
        template = PACKAGES.read_text()
        tags = re.findall(r'<button\b[^>]*data-package="\{\{ \$package->toJson\(\) \}\}"[^>]*>', template)
        self.assertEqual(3, len(tags))
        for name in NAMES:
            package = {'id': 42, 'name': name, 'features': ['<img src=x onerror=alert(1)>'], 'is_published': True}
            encoded = html.escape(json.dumps(package, ensure_ascii=True), quote=True)
            for tag in tags:
                parser = TagParser()
                parser.feed(tag.replace('{{ $package->toJson() }}', encoded))
                attrs = parser.tags[0][1]
                self.assertEqual(package, json.loads(attrs['data-package']))
                self.assertNotIn(name, attrs['@click'])
                result = run_node('''
const p = JSON.parse(require('fs').readFileSync(0, 'utf8'));
globalThis.pwned = false;
let form, open = true, edited = null, created = false;
const $el = {dataset: {package: p.data}};
function openEdit(x) { edited = x; }
function openCreate() { created = true; }
eval(p.expression);
console.log(JSON.stringify({form, open, edited, created, pwned: globalThis.pwned}));
''', {'data': attrs['data-package'], 'expression': attrs['@click']})
                self.assertFalse(result['pwned'])
                if 'Duplicate' in tag or 'const pkg' in attrs['@click']:
                    self.assertTrue(result['created'])
                    self.assertEqual(name + ' (Copy)', result['form']['name'])
                    self.assertFalse(result['form']['is_published'])
                else:
                    self.assertEqual(package, result['edited'])

    def test_router_confirmation_round_trips_untrusted_names(self):
        template = ROUTER.read_text()
        match = re.search(r'<button\s+type="button"\s+data-confirm-message="\{\{.*?\}\}"\s+@click="[^"]*"', template, re.S)
        self.assertIsNotNone(match)
        tag = match.group(0) + '>'
        placeholder = "{{ 'Disconnect MikroTik router and clear saved credentials for '.$tenant->name.'?' }}"
        for name in NAMES:
            message = 'Disconnect MikroTik router and clear saved credentials for ' + name + '?'
            parser = TagParser()
            parser.feed(tag.replace(placeholder, html.escape(message, quote=True)))
            attrs = parser.tags[0][1]
            self.assertEqual(message, attrs['data-confirm-message'])
            self.assertNotIn(name, attrs['@click'])
            result = run_node('''
const p = JSON.parse(require('fs').readFileSync(0, 'utf8'));
globalThis.pwned = false; let received, submitted = false;
const $el = {dataset: {confirmMessage: p.message}};
const window = {confirmAction: x => { received = x; return Promise.resolve(false); }};
const document = {getElementById: () => ({requestSubmit: () => submitted = true})};
eval(p.expression);
setImmediate(() => console.log(JSON.stringify({received, submitted, pwned: globalThis.pwned})));
''', {'message': attrs['data-confirm-message'], 'expression': attrs['@click']})
            self.assertEqual(message, result['received'])
            self.assertFalse(result['submitted'])
            self.assertFalse(result['pwned'])

    def test_no_blade_interpolation_remains_in_alpine_expressions(self):
        for path in (PACKAGES, ROUTER):
            text = path.read_text()
            self.assertNotRegex(text, r'@click="[^\"]*\{\{')
            self.assertNotRegex(text, r':action="[^\"]*\{\{')
            self.assertNotIn('@js($currency)', text)

if __name__ == '__main__':
    unittest.main()
