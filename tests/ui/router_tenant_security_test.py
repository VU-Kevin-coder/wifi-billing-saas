"""Offline Blade/Alpine regressions; simulates Blade HTML escaping, not Laravel rendering."""
import html
from html.parser import HTMLParser
import json
from pathlib import Path
import re
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]
TENANTS = ROOT / 'resources/views/platform/tenants/index.blade.php'

class Parser(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.attrs = None
    def handle_starttag(self, tag, attrs):
        if self.attrs is None: self.attrs = dict(attrs)

def node(source, data):
    r = subprocess.run(['node', '-e', source], input=json.dumps(data), capture_output=True, text=True, check=True)
    return json.loads(r.stdout)

class SecurityTests(unittest.TestCase):
    def test_tenant_old_values_are_data_not_code(self):
        s = TENANTS.read_text()
        tag = re.search(r'<div class="py-8 bg-gray-50 min-h-screen"[\s\S]*?x-data="\{[\s\S]*?\n    \}">', s).group()
        self.assertNotRegex(tag, r"(?:name|slug|status|owner_name|owner_email): '\{\{")
        fields = {'name':'oldName','slug':'oldSlug','status':'oldStatus','owner_name':'oldOwnerName','owner_email':'oldOwnerEmail'}
        for value in ["O'Reilly", '" onmouseover="bad()', "'); globalThis.pwned=true; //", '<script>x</script>', '&quot; \\ \n']:
            rendered = tag.replace("{{ $errors->any() ? 'true' : 'false' }}", 'false')
            for field in fields:
                default = 'trial' if field == 'status' else ''
                rendered = rendered.replace("{{ old('%s', '%s') }}" % (field, default), html.escape(value, quote=True))
            parser = Parser(); parser.feed(rendered); attrs = parser.attrs
            dataset = {camel: attrs['data-old-' + field.replace('_', '-')] for field, camel in fields.items()}
            out = node('''const p=JSON.parse(require('fs').readFileSync(0,'utf8')); globalThis.pwned=false; const state=eval('('+p.data+')'); const $el={dataset:p.dataset}; with(state){eval(p.init)}; console.log(JSON.stringify({state,pwned:globalThis.pwned}));''', {'data':attrs['x-data'], 'init':attrs['x-init'], 'dataset':dataset})
            self.assertFalse(out['pwned'])
            for field in fields: self.assertEqual(out['state'][field], value)

if __name__ == '__main__': unittest.main()
