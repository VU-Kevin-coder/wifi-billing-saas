import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
const source = readFileSync('public/js/ui-dialog.js','utf8');
const partial = readFileSync('resources/views/partials/swal-flash.blade.php','utf8');

test('server feedback is escaped, visible and does not create a duplicate modal', () => {
  assert.match(partial, /<details class="ui-flash" open>/);
  assert.match(partial, /data-flash-message="{{ \$message }}"/);
  assert.match(partial, /in_array\(\$type, \['error', 'validation'\], true\) \? 'alert' : 'status'/);
  assert.match(partial, /'status' => session\('status'\)/);
  assert.match(partial, /'success' => session\('success'\)/);
  assert.match(partial, /'validation' => \$errors->any\(\)/);
  assert.doesNotMatch(partial, /<script>|window\.toast|\bhidden\b|x-cloak/);
});

test('flash is not reconciled by arbitrary page text equality', () => { assert.doesNotMatch(source, /reconcileFlash/); assert.match(partial, /\$pageOwnedFeedback/); });
