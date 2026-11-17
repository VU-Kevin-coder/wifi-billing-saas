import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const read = p => readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');

test('all collapsible sidebar links retain names and fallback exposes real navigation', () => {
  const sidebar = read('resources/views/layouts/sidebar.blade.php');
  const links = [...sidebar.matchAll(/<a\s+[\s\S]*?<\/a>/g)].map(m => m[0]).filter(s => s.includes('x-show="!sidebarCollapsed"'));
  assert.equal(links.length, 24);
  for (const link of links) {
    const label = link.match(/<span x-show="!sidebarCollapsed">([^<]+)<\/span>/)[1];
    assert.ok(link.includes(`aria-label="${label.replaceAll('& ', '&amp; ')}"`), label);
  }
  assert.match(sidebar, /<button type="button" @click="userMenuOpen = !userMenuOpen"/);
  assert.match(sidebar, /aria-label="Account menu"/);
  assert.match(sidebar, /navigation-account-fallback/);
  assert.match(sidebar, /title="Control settings"[\s\S]*?x-show="sidebarCollapsed"/);
  const app = read('resources/views/layouts/app.blade.php');
  assert.match(app, /body:not\(\.navigation-enhanced\) \.navigation-fallback/);
  assert.match(app, /header :focus-visible, aside :focus-visible/);
  assert.match(app, /outline: 3px solid #2563eb !important/);
});

test('staff and filtered team lists report empty results; submission text is neutral', () => {
  const staff = read('resources/views/operator/staff/index.blade.php');
  const team = read('resources/views/operator/team/index.blade.php');
  const sites = read('resources/views/operator/sites/index.blade.php');
  assert.match(staff, /@forelse\(\$staff as \$member\)/);
  assert.match(staff, /@empty\s*<x-ui.empty-table-row :colspan="5" title="No staff members found"/);
  assert.match(team, /No users match your search/);
  assert.match(team, /querySelectorAll\('tr\[data-search\]'\)/);
  assert.match(team, /\$users->count\(\) }} total/);
  assert.match(staff, /data-loading-text="Submitting…"/);
  assert.match(sites, /data-loading-text="Submitting…"/);
});
