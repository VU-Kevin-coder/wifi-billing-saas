"""Offline attribute/Alpine regressions. Simulates Blade escaping, not Laravel rendering."""
import html
from html.parser import HTMLParser
import json
from pathlib import Path
import re
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]
WALLET = ROOT / 'resources/views/operator/wallet/index.blade.php'
VOUCHERS = ROOT / 'resources/views/operator/vouchers/index.blade.php'
TENANTS = ROOT / 'resources/views/platform/tenants/index.blade.php'
PAYLOADS = ["O'Reilly", 'A "quote" & more', "'); globalThis.pwned = true; //", '</script><img src=x onerror=alert(1)>', '&quot; \\ \n']

class Parser(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.tags = []
    def handle_starttag(self, tag, attrs):
        self.tags.append((tag, dict(attrs)))

def attrs(tag, replacements):
    for placeholder, value in replacements.items():
        assert placeholder in tag, placeholder
        tag = tag.replace(placeholder, html.escape(str(value), quote=True))
    parser = Parser()
    parser.feed(tag)
    return parser.tags[0][1]

def run_js(source, payload):
    process = subprocess.run(['node', '-e', source], input=json.dumps(payload), text=True, capture_output=True, check=True)
    return json.loads(process.stdout)

def root_tag(template, css):
    return re.search(r'<div class="' + re.escape(css) + r'"[\s\S]*?x-data="\{[\s\S]*?\n    \}">', template).group()

class WalletVoucherTenantRegressionTest(unittest.TestCase):
    def test_wallet_initialization_and_next_step(self):
        template = WALLET.read_text()
        tag = root_tag(template, 'py-8 bg-gray-50 min-h-screen')
        click = re.search(r'@click="(if \(amount >= minWithdrawal[^\"]*)"', template).group(1)
        open_click = re.search(r'@click="(showWithdraw = true; amount = balance)"', template).group(1)
        for value in PAYLOADS:
            a = attrs(tag, {'{{ $defaultPhone }}': value, "{{ $payoutMethods[0] ?? '' }}": 'bank_transfer', '{{ $currency }}': value, '{{ (float) $minWithdrawal }}': '10000', '{{ (float) ($tenant->wallet_balance ?? 0) }}': '43210.5'})
            dataset = {'phone': a['data-phone'], 'method': a['data-method'], 'currency': a['data-currency'], 'minWithdrawal': a['data-min-withdrawal'], 'balance': a['data-balance']}
            out = run_js('''const p=JSON.parse(require('fs').readFileSync(0,'utf8')); globalThis.pwned=false; let message; const window={toast:{error:x=>message=x}}; const state=eval('('+p.data+')'); const $el={dataset:p.dataset}; with(state){eval(p.init); eval(p.open); amount=5; eval(p.click); amount=12000; bankName='bank'; accountName='name'; accountNumber='12'; eval(p.click);} console.log(JSON.stringify({phone:state.phone, method:state.method, currency:state.currency, min:state.minWithdrawal, balance:state.balance, shown:state.showWithdraw, step:state.step, message, pwned:globalThis.pwned}));''', {'data': a['x-data'], 'init': a['x-init'], 'dataset': dataset, 'click': click, 'open': open_click})
            self.assertEqual((value, 'bank_transfer', value, 10000, 43210.5, True, 2), (out['phone'], out['method'], out['currency'], out['min'], out['balance'], out['shown'], out['step']))
            self.assertEqual('Enter at least ' + value + ' 10,000 and complete the payout details.', out['message'])
            self.assertFalse(out['pwned'])

    def test_voucher_preview_copy_and_status(self):
        template = VOUCHERS.read_text()
        tag = root_tag(template, 'py-8')
        copy_tag = re.search(r'<button type="button"\s+data-voucher-code="\{\{ \$voucher->code \}\}"[\s\S]*?transition-colors">', template).group()
        row_tag = re.search(r'<tr class="hover:bg-gray-50 transition-colors group"[\s\S]*?x-show="[^"]*">', template).group()
        for value in PAYLOADS:
            a = attrs(tag, {"{{ $voucherSettings['voucher_prefix'] }}": value, "{{ $voucherSettings['voucher_length'] }}": '5', "{{ $voucherSettings['voucher_format'] ?? 'alphanumeric' }}": 'numeric', '{{ $voucherCharset }}': 'unambiguous'})
            dataset = {key: a['data-voucher-' + key.lower()] for key in ('prefix','length','format','charset')}
            dataset = {'voucherPrefix': dataset['prefix'], 'voucherLength': dataset['length'], 'voucherFormat': dataset['format'], 'voucherCharset': dataset['charset']}
            c = attrs(copy_tag, {'{{ $voucher->code }}': value})
            r = attrs(row_tag, {'{{ $voucher->status }}': value})
            out = run_js('''const p=JSON.parse(require('fs').readFileSync(0,'utf8')); globalThis.pwned=false; const state=eval('('+p.data+')'); let $el={dataset:p.dataset}; with(state){eval(p.init)}; let copied=false, open=true, code, callback, delay; const navigator={clipboard:{writeText:x=>code=x}}; function setTimeout(fn,ms){callback=fn; delay=ms}; $el={dataset:{voucherCode:p.code}}; eval(p.copy); const copiedImmediately=copied; callback(); $el={dataset:{voucherStatus:p.status}}; let activeTab=p.status; const matches=eval(p.show); activeTab='all'; const all=eval(p.show); activeTab='unused'; const other=eval(p.show); console.log(JSON.stringify({preview:state.voucherFormatPreview(),code,copiedImmediately,copied,open,delay,matches,all,other,pwned:globalThis.pwned}));''', {'data': a['x-data'], 'init': a['x-init'], 'dataset': dataset, 'code': c['data-voucher-code'], 'copy': c['@click'], 'status': r['data-voucher-status'], 'show': r['x-show']})
            self.assertEqual(value + '-00000', out['preview'])
            self.assertEqual(value, out['code'])
            self.assertTrue(out['copiedImmediately'] and out['matches'] and out['all'])
            self.assertFalse(out['copied'] or out['open'] or out['other'] or out['pwned'])
            self.assertEqual(800, out['delay'])

    def test_old_slug_is_preserved_and_fresh_slugs_autogenerate(self):
        template = TENANTS.read_text()
        tag = root_tag(template, 'py-8 bg-gray-50 min-h-screen')
        tag = tag.replace("{{ $errors->any() ? 'true' : 'false' }}", 'true')
        for slug, manual in [('', False), ('', True), ('auto-slug', False), ('my-custom-slug', True), ("'); globalThis.pwned = true; //", True)]:
            values = {'name': 'Old Name', 'slug': slug, 'status': 'active', 'owner_name': "O'Reilly", 'owner_email': 'owner@example.test'}
            replacements = {"{{ old('slug_manual', old('slug', '') !== '' ? '1' : '0') }}": '1' if manual else '0'}
            replacements.update({"{{ old('%s', '%s') }}" % (k, 'trial' if k == 'status' else ''): v for k, v in values.items()})
            a = attrs(tag, replacements)
            dataset = {'oldName': a['data-old-name'], 'oldSlug': a['data-old-slug'], 'oldSlugManual': a['data-old-slug-manual'], 'oldStatus': a['data-old-status'], 'oldOwnerName': a['data-old-owner-name'], 'oldOwnerEmail': a['data-old-owner-email']}
            out = run_js('''const p=JSON.parse(require('fs').readFileSync(0,'utf8')); globalThis.pwned=false; const state=eval('('+p.data+')'); const $el={dataset:p.dataset}; with(state){eval(p.init)}; const initial={...state}; state.name='New Company'; state.updateSlug(); const afterName=state.slug; state.slug='typed-by-user'; state.slugManual=true; state.name='Another Company'; state.updateSlug(); console.log(JSON.stringify({initial,afterName,afterManual:state.slug,pwned:globalThis.pwned}));''', {'data': a['x-data'], 'init': a['x-init'], 'dataset': dataset})
            self.assertEqual(slug, out['initial']['slug'])
            self.assertEqual(manual, out['initial']['slugManual'])
            self.assertEqual(slug if manual else 'new-company', out['afterName'])
            self.assertEqual('typed-by-user', out['afterManual'])
            self.assertTrue(out['initial']['showCreateOverlay'])
            self.assertEqual("O'Reilly", out['initial']['owner_name'])
            self.assertFalse(out['pwned'])
        self.assertIn('name="slug_manual" value="0" :value="slugManual ? \'1\' : \'0\'"', template)
        self.assertIn("@selected((string) old('platform_plan_id') === (string) $p->id)", template)
        self.assertRegex(template, r'@empty\s*<tr><td colspan="8"[^>]*>No operator tenants to display\. Create an operator tenant to get started\.')

    def test_no_dynamic_blade_values_in_javascript_attributes(self):
        for path in (WALLET, VOUCHERS):
            source = path.read_text()
            self.assertNotRegex(source, r'(?:x-data|x-init|x-show|@click)="[^"]*(?:\{\{|@js\(|@json\()')

if __name__ == '__main__':
    unittest.main()
