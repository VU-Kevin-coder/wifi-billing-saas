import re
import unittest
from html.parser import HTMLParser
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
VIEWS = ROOT / 'resources/views'
INCLUDE = "@include('partials.confirm-action-acknowledgment', ['confirmationMessage' => "

class FormParser(HTMLParser):
    def __init__(self, source):
        super().__init__(convert_charrefs=True)
        self.source = source
        self.lines = source.splitlines(keepends=True)
        self.stack = []
        self.forms = []

    def source_offset(self):
        line, col = self.getpos()
        return sum(map(len, self.lines[:line - 1])) + col

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'form':
            form = {'attrs': attrs, 'start': self.source_offset(), 'body': self.source_offset() + len(self.get_starttag_text()), 'bypass': []}
            if self.stack:
                self.stack[-1]['nested'] = True
            self.stack.append(form)
        elif self.stack and tag in ('button', 'input') and 'formnovalidate' in attrs:
            self.stack[-1]['bypass'].append((tag, self.getpos()))

    def handle_endtag(self, tag):
        if tag == 'form' and self.stack:
            form = self.stack.pop()
            form['end'] = self.source_offset()
            self.forms.append(form)

class PartialParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.label = 0
        self.inputs = []
        self.text = ''
        self.tags = []
    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        self.tags.append((tag, a))
        if tag == 'label': self.label += 1
        if tag == 'input': self.inputs.append((a, self.label))
    def handle_endtag(self, tag):
        if tag == 'label': self.label -= 1
    def handle_data(self, data):
        if self.label: self.text += data

class ConfirmFallbackTest(unittest.TestCase):
    def test_all_marked_forms_have_unconditional_fallback_and_no_bypass(self):
        count = 0
        for path in VIEWS.rglob('*.blade.php'):
            source = path.read_text()
            parser = FormParser(source)
            parser.feed(source)
            self.assertFalse(parser.stack, f'unclosed form in {path}')
            for form in parser.forms:
                if 'data-confirm-action' not in form['attrs']: continue
                count += 1
                where = f'{path}:{source.count(chr(10), 0, form["start"]) + 1}'
                self.assertNotIn('novalidate', form['attrs'], where)
                self.assertFalse(form['bypass'], where)
                self.assertFalse(form.get('nested'), where)
                body = source[form['body']:form['end']]
                self.assertRegex(body, r'^\s*@csrf\s*' + re.escape(INCLUDE), where)
                self.assertEqual(body.count(INCLUDE), 1, where)
        self.assertGreaterEqual(count, 24)

    def test_shared_partial_is_visible_required_labelled_checkbox(self):
        source = (VIEWS / 'partials/confirm-action-acknowledgment.blade.php').read_text()
        p = PartialParser(); p.feed(source)
        self.assertEqual(len(p.inputs), 1)
        attrs, labelled = p.inputs[0]
        self.assertEqual(attrs.get('type'), 'checkbox')
        self.assertIn('required', attrs)
        # Best-effort only: browsers may still preserve state on history restoration.
        self.assertEqual(attrs.get('autocomplete'), 'off')
        self.assertGreater(labelled, 0)
        self.assertIn('confirm', p.text.lower())
        for attr in ('checked', 'disabled', 'hidden', 'name', 'form'):
            self.assertNotIn(attr, attrs)
        for tag, attrs in p.tags:
            self.assertNotIn(tag, ('noscript', 'script', 'style'))
            self.assertNotIn('hidden', attrs)
            self.assertNotRegex(attrs.get('class', ''), r'\b(hidden|sr-only)\b')
            self.assertNotIn('style', attrs)
            self.assertFalse(any(k.startswith('on') for k in attrs))

if __name__ == '__main__': unittest.main()
