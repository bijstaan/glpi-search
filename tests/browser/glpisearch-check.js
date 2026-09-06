// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
// glpi-search in a browser: the header box, the results page, and the settings.
//
// Everything else about this plugin is testable from the CLI, and is — see
// glpi-search/tests/search.php. Three things are not, and they are exactly the
// three that decide whether anybody can use it:
//
//   - the header takeover attaches to core's own input and must not break the
//     form underneath it, so that Enter with nothing selected still lands on
//     GLPI's search page;
//   - the results panel has to appear *above* core's results on that page and
//     leave them alone;
//   - the facet chips have to actually narrow the list when clicked.
//
// It also takes the documentation screenshots.
//
//   cd glpi-search/tests/browser && SHOT_DIR=../../docs/screenshots \
//     node glpisearch-check.js
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const { fullPage } = require('./shot');

// Same helper the other checks use: a one-shot PHP script inside the container,
// for the fixtures a browser cannot make for itself.
const php = (code) =>
  execSync('docker exec -i glpi-glpi-1 php', {
    encoding: 'utf8',
    input:
      '<?php require "/var/www/glpi/vendor/autoload.php";'
      + '(new Glpi\\Kernel\\Kernel(Glpi\\Application\\Environment::PRODUCTION->value))->boot();'
      + '(new Auth())->login("glpi","glpi",true);(new Plugin())->init(true);'
      + code,
  }).trim();

const BASE = 'http://localhost:8081';
const SHOTS = process.env.SHOT_DIR || '.';

const fail = [];
function check(name, cond, detail) {
  console.log(`${cond ? 'PASS' : 'FAIL'}  ${name}${detail ? ' :: ' + String(detail).slice(0, 160) : ''}`);
  if (!cond) fail.push(name);
}

// What the header dropdown is showing, read out of the DOM rather than
// inferred from the query — the point is what a person would see.
const dropdown = (page) =>
  page.evaluate(() => {
    const panel = document.querySelector('.glpisearch-panel');
    if (!panel) return { open: false, groups: [], rows: [] };
    return {
      open: true,
      groups: Array.from(panel.querySelectorAll('.glpisearch-group')).map((g) => g.textContent.trim()),
      rows: Array.from(panel.querySelectorAll('.glpisearch-row')).map((r) => ({
        title: r.querySelector('.glpisearch-title')?.textContent.trim(),
        sub: r.querySelector('.glpisearch-subtitle')?.textContent.trim() || '',
        url: r.getAttribute('data-url'),
        active: r.classList.contains('glpisearch-row--active'),
      })),
    };
  });

const resultsPage = (page) =>
  page.evaluate(() => {
    const root = document.querySelector('.glpisearch-page');
    if (!root) return { present: false };
    return {
      present: true,
      // Above core's own results, which is the whole claim of the layout.
      aboveCore: !!(
        document.querySelector('.search_page_global') &&
        root.compareDocumentPosition(document.querySelector('.search_page_global')) &
          Node.DOCUMENT_POSITION_FOLLOWING
      ),
      coreStillThere: !!document.querySelector('.search_page_global'),
      facets: Array.from(root.querySelectorAll('.glpisearch-facet')).map((f) => ({
        label: f.querySelector('.glpisearch-facet-head')?.textContent.trim(),
        chips: Array.from(f.querySelectorAll('.glpisearch-chip')).map((c) => c.textContent.trim()),
      })),
      rows: Array.from(root.querySelectorAll('.glpisearch-row')).map((r) => ({
        title: r.querySelector('.glpisearch-title')?.textContent.trim(),
      })),
      on: Array.from(root.querySelectorAll('.glpisearch-chip--on')).map((c) => c.getAttribute('data-value')),
    };
  });

async function typeInHeader(page, text) {
  await page.click('#global-search');
  await page.fill('#global-search', '');
  await page.type('#global-search', text, { delay: 20 });
  await page.waitForTimeout(900);
}

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1500, height: 1000 } });
  const errs = [];
  page.on('pageerror', (e) => errs.push(e.message));

  await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  await page.fill('#login_name', 'glpi');
  await page.fill('input[type=password]', 'glpi');
  await page.click('button[type=submit]');
  await page.waitForLoadState('networkidle');
  await page.goto(`${BASE}/front/central.php`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1200);

  // --- 1. The header box ------------------------------------------------

  check('core\'s search box is still there', await page.locator('#global-search').count() === 1);

  await typeInHeader(page, 'printer');
  let d = await dropdown(page);
  check('typing opens a dropdown', d.open, JSON.stringify(d.groups));
  check('with results grouped by type', d.groups.length > 0, d.groups.join(' / '));
  check('and rows that link somewhere', d.rows.length > 0 && !!d.rows[0].url,
    JSON.stringify(d.rows[0] || {}));

  await fullPage(page, `${SHOTS}/search-01-header.png`, { highlight: '.glpisearch-panel' });

  // The claim the whole plugin rests on: a misspelling still finds it.
  await typeInHeader(page, 'priner');
  const typo = await dropdown(page);
  check('a typo finds the same records', typo.rows.length > 0,
    typo.rows.map((r) => r.title).join(' | '));
  check('and they are the printer ones',
    typo.rows.some((r) => /print/i.test(r.title || '')),
    typo.rows.map((r) => r.title).join(' | '));

  await fullPage(page, `${SHOTS}/search-02-typo.png`, { highlight: '.glpisearch-panel' });

  // Jargon: the word the user says, not the word the record uses.
  await typeInHeader(page, 'copier');
  const jargon = await dropdown(page);
  check('a synonym finds records that never use the word',
    jargon.rows.some((r) => /print/i.test(r.title || '')),
    jargon.rows.map((r) => r.title).join(' | '));

  // --- 2. Keyboard, and the form underneath -----------------------------

  await typeInHeader(page, 'printer');
  await page.keyboard.press('ArrowDown');
  await page.waitForTimeout(200);
  d = await dropdown(page);
  check('arrow keys select a row', d.rows.some((r) => r.active),
    d.rows.map((r) => (r.active ? '[' + r.title + ']' : r.title)).join(' '));

  await page.keyboard.press('Escape');
  await page.waitForTimeout(300);
  d = await dropdown(page);
  check('escape closes it', !d.open);

  // Nothing selected: Enter must fall through to the form core shipped, which
  // is what makes this safe to install. A broken plugin costs speed, not the
  // page people already know.
  await typeInHeader(page, 'printer');
  await page.keyboard.press('Enter');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(1200);
  check('Enter with nothing selected still submits core\'s form',
    /\/front\/search\.php/.test(page.url()) && /globalsearch=printer/.test(page.url()),
    page.url());

  // --- 3. The results page ----------------------------------------------

  let r = await resultsPage(page);
  check('the results panel appears on core\'s search page', r.present);
  check('above core\'s own results', r.aboveCore, JSON.stringify({ above: r.aboveCore }));
  check('and core\'s results are still on the page', r.coreStillThere);
  check('it lists results', r.rows.length > 0, r.rows.map((x) => x.title).join(' | '));
  check('with facets beside them', r.facets.length > 0,
    r.facets.map((f) => f.label).join(', '));

  await fullPage(page, `${SHOTS}/search-03-results-page.png`, { highlight: '.glpisearch-page' });

  // --- 4. Facets actually filter ----------------------------------------

  const before = r.rows.length;
  const facet = r.facets.find((f) => f.chips.length > 1) || r.facets[0];
  check('a facet offers more than one value to choose between', !!facet && facet.chips.length > 1,
    facet ? `${facet.label}: ${facet.chips.join(' / ')}` : 'none');

  if (facet) {
    await page.locator('.glpisearch-facet', { hasText: facet.label })
      .locator('.glpisearch-chip').first().click();
    await page.waitForTimeout(1200);

    r = await resultsPage(page);
    check('clicking a chip marks it chosen', r.on.length === 1, JSON.stringify(r.on));
    check('and narrows the results', r.rows.length > 0 && r.rows.length <= before,
      `${before} -> ${r.rows.length}`);

    await fullPage(page, `${SHOTS}/search-04-facet-filtered.png`, { highlight: '.glpisearch-facets' });

    // Clicking it again is the only way back, so it had better work.
    await page.locator('.glpisearch-chip--on').first().click();
    await page.waitForTimeout(1200);
    r = await resultsPage(page);
    check('clicking it again clears the filter', r.on.length === 0 && r.rows.length === before,
      `${r.rows.length} vs ${before}`);
  }

  // --- 5. The dossier ---------------------------------------------------
  //
  // The question a technician actually has when the phone rings. Needs a caller
  // who has things: the demo data has one user with every ticket on it and
  // several with nothing at all, and neither shape shows what this is for. The
  // fixtures are made here and removed at the end of this block.

  const seeded = JSON.parse(php(`
    $u = new User();
    $uid = (int) $u->add(["name" => "glpisearch-caller", "realname" => "Okonkwo",
      "firstname" => "Ada", "phone" => "555 0142", "is_active" => 1, "entities_id" => 0]);
    global $DB;
    $DB->insert("glpi_useremails", ["users_id" => $uid, "email" => "ada.okonkwo@example.test", "is_default" => 1]);

    $made = ["user" => $uid, "tickets" => [], "computers" => []];
    $t = new Ticket();
    foreach ([
      "Laptop will not wake from sleep",
      "Cannot print to the second floor MFP",
      "Shared drive is asking for a password",
    ] as $name) {
      $id = (int) $t->add(["name" => $name, "content" => "Reported by phone.",
        "entities_id" => 0, "_auto_import" => true,
        "_users_id_requester" => $uid]);
      $made["tickets"][] = $id;
    }

    $c = new Computer();
    foreach (["ADA-LAPTOP-01", "ADA-DOCK-01"] as $name) {
      $made["computers"][] = (int) $c->add(["name" => $name, "entities_id" => 0, "users_id" => $uid]);
    }

    GlpiPlugin\\Glpisearch\\Indexer::run(500);
    echo json_encode($made);
  `));

  await page.goto(`${BASE}/front/search.php?globalsearch=okonkwo`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3500);

  const dossier = await page.evaluate(() => {
    const d = document.querySelector('.glpisearch-dossier');
    if (!d) return { present: false };
    return {
      present: true,
      name: d.querySelector('.glpisearch-dossier-name')?.textContent.trim(),
      columns: Array.from(d.querySelectorAll('.glpisearch-facet-head')).map((h) => h.textContent.trim()),
      rows: d.querySelectorAll('.glpisearch-row').length,
    };
  });

  check('a search matching one person shows their dossier', dossier.present,
    JSON.stringify(dossier));
  check('with their open tickets and their assets',
    (dossier.columns || []).length === 2, (dossier.columns || []).join(' | '));
  check('and rows you can click through to', (dossier.rows || 0) > 0, String(dossier.rows));

  check('the caller\'s own tickets are the ones listed',
    /Laptop will not wake/.test(await page.evaluate(() =>
      document.querySelector('.glpisearch-dossier')?.textContent || '')),
    (dossier.columns || []).join(' | '));

  if (dossier.present) {
    await fullPage(page, `${SHOTS}/search-08-dossier.png`, { highlight: '.glpisearch-dossier' });
  }

  php(`
    $made = json_decode('${JSON.stringify(seeded).replace(/'/g, "")}', true);
    foreach ($made["tickets"] as $id) { (new Ticket())->delete(["id" => $id], true); }
    foreach ($made["computers"] as $id) { (new Computer())->delete(["id" => $id], true); }
    global $DB;
    $DB->delete("glpi_profiles_users", ["users_id" => $made["user"]]);
    (new User())->delete(["id" => $made["user"]], true);
    GlpiPlugin\\Glpisearch\\Indexer::run(500);
    echo "cleaned";
  `);

  // --- 6. Settings ------------------------------------------------------

  await page.goto(`${BASE}/front/plugin.php`, { waitUntil: 'networkidle' });
  await page.goto(`${BASE}/plugins/glpisearch/front/config.php`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(700);

  check('the settings page renders', await page.locator('input[name=url]').count() === 1);
  check('the API key is never sent back to the browser',
    !(await page.locator('input[name=api_key]').inputValue()).includes('glpi-dev-master-key'),
    await page.locator('input[name=api_key]').inputValue());
  check('it reports what is indexed',
    /\d/.test(await page.locator('.card', { hasText: 'Index status' }).innerText()),
    (await page.locator('.card', { hasText: 'Index status' }).innerText()).slice(0, 90));

  await fullPage(page, `${SHOTS}/search-05-settings.png`, { highlight: '.card:has(input[name=url])' });
  // Both of these live in the same card, so highlighting the card twice would
  // produce two identical images. Point at the controls themselves.
  await fullPage(page, `${SHOTS}/search-06-indexed-types.png`,
    { highlight: '.form-check:has(input[name=index_all])' });
  await fullPage(page, `${SHOTS}/search-07-jargon.png`,
    { highlight: 'textarea[name=synonyms]' });

  // Saving must not wipe the credential it never showed. The single most
  // expensive bug a settings page can have, and invisible from the CLI.
  await page.click('button[name=update]');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(900);
  await page.goto(`${BASE}/front/central.php`, { waitUntil: 'networkidle' });
  await typeInHeader(page, 'printer');
  const afterSave = await dropdown(page);
  check('saving the settings page does not break the connection',
    afterSave.rows.length > 0, JSON.stringify(afterSave.groups));

  check('no uncaught JavaScript errors', errs.length === 0, errs.join(' | '));

  await browser.close();

  console.log(fail.length ? `\n${fail.length} failed: ${fail.join(', ')}` : '\nall checks passed');
  process.exit(fail.length ? 1 : 0);
})();
