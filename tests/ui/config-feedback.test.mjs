import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const root = 'resources/views/';
const read = name => readFileSync(root + name, 'utf8');
test('flash fallback is visible in server markup with JavaScript disabled', () => {
  const markup=read('partials/swal-flash.blade.php').split('<script>')[0];
  assert.match(markup, /data-flash-type="{{ \$type }}"/);
  assert.match(markup, /{{ \$message }}/);
  assert.doesNotMatch(markup, /\bhidden\b|x-cloak|display\s*:\s*none/);
});
test('flash is one server-rendered notification, not a modal duplicate', () => {
  const markup=read('partials/swal-flash.blade.php');
  assert.match(markup, /<details class="ui-flash" open>/);
  assert.doesNotMatch(markup, /<script>|window\.toast/);
});
test('tenant empty row spans all table columns',()=>{let s=read('platform/tenants/index.blade.php'); assert.match(s,/@forelse\(\$tenants as \$tenant\)/); assert.match(s,/@empty\s*<tr><td colspan="8"[^>]*>No operator tenants to display\.[^<]*<\/td><\/tr>\s*@endforelse/);});
