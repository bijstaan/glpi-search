// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
/**
 * The header search box, answering as you type.
 *
 * This attaches to core's `#global-search` rather than replacing it, and the
 * distinction is the whole safety story. The form is still a form: it still
 * points at GLPI's own search page, it still submits on Enter, and if anything
 * here throws, times out, or finds the backend gone, the box people have used
 * for years still works. Nothing is taken away — not by a bug, not by an
 * unreachable Meilisearch, not by this file failing to parse.
 *
 * So the rules are:
 *   - Enter with a result selected opens that result.
 *   - Enter with nothing selected submits the form, exactly as before.
 *   - Escape closes the dropdown and leaves the text alone.
 *   - A failed or unavailable search closes the dropdown rather than showing
 *     an error, because the fallback is one keypress away and saying so would
 *     be noise.
 */
(function () {
    'use strict';

    var ROOT = (window.CFG_GLPI && window.CFG_GLPI.root_doc) || '';
    var ENDPOINT = ROOT + '/plugins/glpisearch/ajax/search.php';

    var DEBOUNCE_MS = 140;

    function csrf() {
        var meta = document.querySelector('meta[property="glpi:csrf_token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    /**
     * One POST to the endpoint.
     *
     * `X-Requested-With` is not decoration and its absence is not a subtle bug:
     * without it GLPI's kernel treats the request as an ordinary form post and
     * *consumes* the CSRF token, so the first keystroke answers and every one
     * after it gets a 403 — which reaches the browser as a search box that
     * quietly stops working after one character.
     *
     * @param {Array<Array<string>>} pairs  name/value pairs, repeats allowed
     */
    function post(pairs, signal) {
        var body = new URLSearchParams();
        pairs.forEach(function (pair) {
            body.append(pair[0], pair[1]);
        });

        return fetch(ENDPOINT, {
            method: 'POST',
            credentials: 'same-origin',
            signal: signal,
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
                'X-Glpi-Csrf-Token': csrf()
            },
            body: body.toString()
        }).then(function (res) {
            return res.ok ? res.json() : null;
        });
    }

    // --- One attached search box ------------------------------------------

    function attach(input) {
        if (input.dataset.glpisearchBound === '1') {
            return;
        }
        input.dataset.glpisearchBound = '1';

        var form = input.closest('form');
        var panel = null;
        var rows = [];
        var active = -1;
        var seq = 0;
        var inflight = null;
        var timer = null;

        // GLPI's box is a plain form field; the browser's own history dropdown
        // would sit on top of ours.
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-autocomplete', 'list');

        function ensurePanel() {
            if (panel) {
                return panel;
            }

            panel = document.createElement('div');
            panel.className = 'glpisearch-panel';
            panel.setAttribute('role', 'listbox');

            // Positioned against the input's own wrapper so it tracks the box
            // through GLPI's two header layouts (vertical and horizontal menu)
            // without this file needing to know which one is in play.
            var anchor = input.closest('.input-group') || input.parentNode;
            anchor.style.position = anchor.style.position || 'relative';
            anchor.appendChild(panel);

            // Mousedown, not click: click fires after blur, by which time the
            // panel has already been torn down and the row is gone.
            panel.addEventListener('mousedown', function (ev) {
                var row = ev.target.closest('[data-url]');
                if (!row) {
                    return;
                }
                ev.preventDefault();
                window.location.href = row.getAttribute('data-url');
            });

            return panel;
        }

        function close() {
            if (panel) {
                panel.remove();
                panel = null;
            }
            rows = [];
            active = -1;
            input.setAttribute('aria-expanded', 'false');
        }

        function highlight() {
            rows.forEach(function (row, i) {
                row.el.classList.toggle('glpisearch-row--active', i === active);
            });

            if (active >= 0 && rows[active]) {
                rows[active].el.scrollIntoView({ block: 'nearest' });
            }
        }

        function render(groups) {
            var el = ensurePanel();
            el.textContent = '';
            rows = [];
            active = -1;

            groups.forEach(function (group) {
                var head = document.createElement('div');
                head.className = 'glpisearch-group';
                head.textContent = group.type_label;
                el.appendChild(head);

                (group.items || []).forEach(function (item) {
                    var row = document.createElement('a');
                    row.className = 'glpisearch-row';
                    row.setAttribute('role', 'option');
                    row.href = item.url;
                    row.setAttribute('data-url', item.url);

                    var icon = document.createElement('i');
                    icon.className = (group.icon || 'ti ti-file') + ' glpisearch-icon';
                    row.appendChild(icon);

                    var body = document.createElement('div');
                    body.className = 'glpisearch-body';

                    var title = document.createElement('div');
                    title.className = 'glpisearch-title';
                    // textContent throughout: these are record titles, and a
                    // ticket called <img onerror=…> is a ticket somebody will
                    // eventually file.
                    title.textContent = item.title;
                    body.appendChild(title);

                    if (item.subtitle) {
                        var sub = document.createElement('div');
                        sub.className = 'glpisearch-subtitle';
                        sub.textContent = item.subtitle;
                        body.appendChild(sub);
                    }

                    row.appendChild(body);

                    var badge = document.createElement('span');
                    badge.className = 'glpisearch-badge';
                    badge.textContent = '#' + item.id;
                    row.appendChild(badge);

                    el.appendChild(row);
                    rows.push({ el: row, url: item.url });
                });
            });

            input.setAttribute('aria-expanded', 'true');
            highlight();
        }

        function search(term) {
            var mine = ++seq;

            if (inflight) {
                inflight.abort();
            }
            inflight = new AbortController();

            post([['q', term]], inflight.signal)
                .then(function (data) {
                    // A response that arrived after a newer one was sent is
                    // stale, and rendering it would show results for a query
                    // the box no longer contains.
                    if (mine !== seq) {
                        return;
                    }

                    if (!data || !data.available || !data.groups || !data.groups.length) {
                        close();
                        return;
                    }

                    render(data.groups);
                })
                .catch(function () {
                    // Aborted, offline, or a backend that has gone away. The
                    // form underneath still works, so say nothing.
                    if (mine === seq) {
                        close();
                    }
                });
        }

        input.addEventListener('input', function () {
            var term = input.value.trim();

            clearTimeout(timer);

            if (term === '') {
                close();
                return;
            }

            timer = setTimeout(function () {
                search(term);
            }, DEBOUNCE_MS);
        });

        input.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape') {
                if (rows.length) {
                    ev.stopPropagation();
                }
                close();
                return;
            }

            if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
                if (!rows.length) {
                    return;
                }
                ev.preventDefault();
                var step = ev.key === 'ArrowDown' ? 1 : -1;
                active = (active + step + rows.length + 1) % (rows.length + 1);
                // The extra slot is "nothing selected", which is what makes
                // arrowing back past the top hand the box back to the form.
                if (active === rows.length) {
                    active = -1;
                }
                highlight();
                return;
            }

            if (ev.key === 'Enter') {
                if (active >= 0 && rows[active]) {
                    ev.preventDefault();
                    window.location.href = rows[active].url;
                }
                // Otherwise: do nothing, and let the form submit to GLPI's own
                // search page exactly as it always has.
            }
        });

        input.addEventListener('blur', function () {
            // Deferred so that a click landing on a row is processed first.
            setTimeout(close, 120);
        });

        if (form) {
            form.addEventListener('submit', close);
        }
    }

    // --- The results page ---------------------------------------------------
    //
    // GLPI's own global search page, with our results put above its own rather
    // than in place of them. Same reasoning as the header box: core's results
    // are exact-match and ours are not, they disagree in useful ways, and a
    // plugin that removed the page somebody already knows has taken something
    // away. Ours are on top because they are the ones that tolerate the typo.

    var page = {
        term: '',
        filters: {},
        root: null,
        results: null,
        facets: null
    };

    function facetLabel(name) {
        // Attribute names come from database columns — `itilcategories`,
        // `users_tech` — which are honest but not English. Only the ones worth
        // showing get a label; the rest are hidden rather than shown raw,
        // because a facet nobody can read is noise with a count next to it.
        var labels = {
            status: 'Status',
            priority: 'Priority',
            urgency: 'Urgency',
            type: 'Type',
            itilcategories: 'Category',
            locations: 'Location',
            requester: 'Requester',
            assignee: 'Assigned to',
            observer: 'Observer',
            states: 'State',
            manufacturers: 'Manufacturer',
            computermodels: 'Model',
            computertypes: 'Type',
            groups: 'Group',
            users: 'User',
            users_tech: 'Technician',
            requesttypes: 'Source'
        };

        return labels[name] || null;
    }

    function isChosen(attribute, value) {
        return (page.filters[attribute] || []).indexOf(value) !== -1;
    }

    function toggleFacet(attribute, value) {
        var chosen = page.filters[attribute] || [];
        var at = chosen.indexOf(value);

        if (at === -1) {
            chosen.push(value);
        } else {
            chosen.splice(at, 1);
        }

        if (chosen.length) {
            page.filters[attribute] = chosen;
        } else {
            delete page.filters[attribute];
        }

        runPageSearch();
    }

    function renderFacets(facets) {
        page.facets.textContent = '';

        var any = false;

        // Anything currently filtered on is shown whether or not it came back
        // in the distribution. Meilisearch counts facets *after* applying the
        // filter, so a chosen value collapses its own facet to one entry — and
        // hiding one-entry facets would make the chip you just clicked vanish,
        // leaving no way to undo it.
        var attributes = Object.keys(facets);
        Object.keys(page.filters).forEach(function (attribute) {
            if (attributes.indexOf(attribute) === -1) {
                attributes.push(attribute);
            }
        });

        attributes.forEach(function (attribute) {
            var label = facetLabel(attribute);
            var values = facets[attribute] || {};
            var chosen = page.filters[attribute] || [];

            // A chosen value always has a chip, even if this result set no
            // longer reports a count for it.
            chosen.forEach(function (value) {
                if (!(value in values)) {
                    values[value] = 0;
                }
            });

            var names = Object.keys(values);

            // A facet where everything shares one value narrows nothing — but
            // only say that when nothing is filtered on it.
            if (!label || (names.length < 2 && chosen.length === 0)) {
                return;
            }

            any = true;

            var group = document.createElement('div');
            group.className = 'glpisearch-facet';

            var head = document.createElement('div');
            head.className = 'glpisearch-facet-head';
            head.textContent = label;
            group.appendChild(head);

            names.forEach(function (value) {
                var chip = document.createElement('button');
                chip.type = 'button';
                chip.className = 'glpisearch-chip'
                    + (isChosen(attribute, value) ? ' glpisearch-chip--on' : '');
                chip.textContent = value;

                var count = document.createElement('span');
                count.className = 'glpisearch-chip-count';
                count.textContent = values[value];
                chip.appendChild(count);

                // The count is inside the chip, so textContent would read
                // "Medium1". Anything reading the chosen value back — a test,
                // or a person inspecting the DOM — needs the value itself.
                chip.setAttribute('data-value', value);
                chip.setAttribute('data-facet', attribute);

                chip.addEventListener('click', function () {
                    toggleFacet(attribute, value);
                });

                group.appendChild(chip);
            });

            page.facets.appendChild(group);
        });

        page.facets.style.display = any ? '' : 'none';
    }

    /**
     * Everything about one person, above the results.
     *
     * Shown when the search found exactly one user, which is the shape the
     * question takes when somebody is looking a caller up. Two or more and it
     * would be a guess about which one they meant, and a guess that fills the
     * top of the page is worse than no guess at all.
     */
    function renderDossier(dossier) {
        var card = document.createElement('div');
        card.className = 'glpisearch-dossier';

        var u = dossier.user;

        var head = document.createElement('div');
        head.className = 'glpisearch-dossier-head';

        var who = document.createElement('a');
        who.className = 'glpisearch-dossier-name';
        who.href = u.url;
        who.textContent = u.realname || u.name;
        head.appendChild(who);

        // Contact details, which are the reason to look somebody up at all.
        [u.realname ? u.name : '', u.emails[0] || '', u.phone, u.mobile, u.where]
            .filter(Boolean)
            .forEach(function (bit) {
                var span = document.createElement('span');
                span.className = 'glpisearch-dossier-bit';
                span.textContent = bit;
                head.appendChild(span);
            });

        if (!u.active) {
            var off = document.createElement('span');
            off.className = 'glpisearch-dossier-bit glpisearch-dossier-inactive';
            off.textContent = 'disabled';
            head.appendChild(off);
        }

        card.appendChild(head);

        var cols = document.createElement('div');
        cols.className = 'glpisearch-dossier-body';

        function column(title, rows, empty) {
            var col = document.createElement('div');
            col.className = 'glpisearch-dossier-col';

            var h = document.createElement('div');
            h.className = 'glpisearch-facet-head';
            h.textContent = title;
            col.appendChild(h);

            if (!rows.length) {
                var none = document.createElement('div');
                none.className = 'glpisearch-none';
                none.textContent = empty;
                col.appendChild(none);
                return col;
            }

            rows.forEach(function (row) {
                var a = document.createElement('a');
                a.className = 'glpisearch-row';
                a.href = row.url;

                var icon = document.createElement('i');
                icon.className = (row.icon || 'ti ti-alert-circle') + ' glpisearch-icon';
                a.appendChild(icon);

                var body = document.createElement('div');
                body.className = 'glpisearch-body';

                var t = document.createElement('div');
                t.className = 'glpisearch-title';
                t.textContent = row.title;
                body.appendChild(t);

                if (row.status || row.type) {
                    var sub = document.createElement('div');
                    sub.className = 'glpisearch-subtitle';
                    sub.textContent = row.status || row.type;
                    body.appendChild(sub);
                }

                a.appendChild(body);

                var badge = document.createElement('span');
                badge.className = 'glpisearch-badge';
                badge.textContent = '#' + row.id;
                a.appendChild(badge);

                col.appendChild(a);
            });

            return col;
        }

        var open = dossier.counts.open;
        var closed = dossier.counts.closed;
        cols.appendChild(column(
            'Open tickets' + (open ? ' (' + open + ')' : '')
                + (closed ? ' · ' + closed + ' closed' : ''),
            dossier.tickets,
            open ? '' : 'Nothing open.'
        ));

        cols.appendChild(column(
            'Assets' + (dossier.counts.assets ? ' (' + dossier.counts.assets + ')' : ''),
            dossier.assets,
            'Nothing assigned.'
        ));

        card.appendChild(cols);

        return card;
    }

    function loadDossier(groups) {
        var users = groups.filter(function (g) {
            return g.itemtype === 'User';
        });

        if (users.length !== 1 || users[0].items.length !== 1) {
            return;
        }

        post([['action', 'dossier'], ['users_id', String(users[0].items[0].id)]])
            .then(function (data) {
                if (!data || !data.dossier) {
                    return;
                }

                var card = renderDossier(data.dossier);
                page.results.insertBefore(card, page.results.firstChild);
            })
            .catch(function () {
                // The results are already on the page and are the main answer.
            });
    }

    function renderPageResults(groups) {
        page.results.textContent = '';

        if (!groups.length) {
            var none = document.createElement('p');
            none.className = 'glpisearch-none';
            none.textContent = Object.keys(page.filters).length
                ? 'Nothing matches that combination.'
                : 'No results.';
            page.results.appendChild(none);

            return;
        }

        groups.forEach(function (group) {
            var section = document.createElement('div');
            section.className = 'glpisearch-section';

            var head = document.createElement('h3');
            head.className = 'glpisearch-section-head';
            head.textContent = group.type_label;
            section.appendChild(head);

            group.items.forEach(function (item) {
                var row = document.createElement('a');
                row.className = 'glpisearch-row';
                row.href = item.url;

                var icon = document.createElement('i');
                icon.className = (group.icon || 'ti ti-file') + ' glpisearch-icon';
                row.appendChild(icon);

                var body = document.createElement('div');
                body.className = 'glpisearch-body';

                var title = document.createElement('div');
                title.className = 'glpisearch-title';
                title.textContent = item.title;
                body.appendChild(title);

                if (item.subtitle) {
                    var sub = document.createElement('div');
                    sub.className = 'glpisearch-subtitle';
                    sub.textContent = item.subtitle;
                    body.appendChild(sub);
                }

                row.appendChild(body);

                var badge = document.createElement('span');
                badge.className = 'glpisearch-badge';
                badge.textContent = '#' + item.id;
                row.appendChild(badge);

                section.appendChild(row);
            });

            page.results.appendChild(section);
        });
    }

    function runPageSearch() {
        var pairs = [['q', page.term]];

        Object.keys(page.filters).forEach(function (attribute) {
            page.filters[attribute].forEach(function (value) {
                pairs.push(['facets[' + attribute + '][]', value]);
            });
        });

        page.results.setAttribute('aria-busy', 'true');

        post(pairs)
            .then(function (data) {
                page.results.removeAttribute('aria-busy');

                if (!data || !data.available) {
                    // Nothing to add. Core's own results are already on the
                    // page below, so the honest thing is to take ours away
                    // rather than show an error above them.
                    page.root.remove();
                    return;
                }

                renderFacets(data.facets || {});
                renderPageResults(data.groups || []);
                loadDossier(data.groups || []);
            })
            .catch(function () {
                page.results.removeAttribute('aria-busy');
                page.root.remove();
            });
    }

    function enhanceSearchPage() {
        if (!/\/front\/search\.php$/.test(window.location.pathname)) {
            return;
        }

        var term = new URLSearchParams(window.location.search).get('globalsearch');
        if (!term || !term.trim()) {
            return;
        }

        var anchor = document.querySelector('.search_page_global') || document.querySelector('.search_page');
        if (!anchor) {
            return;
        }

        page.term = term.trim();

        page.root = document.createElement('div');
        page.root.className = 'glpisearch-page card mb-3';

        var head = document.createElement('div');
        head.className = 'glpisearch-page-head';
        head.textContent = 'Results for “' + page.term + '”';
        page.root.appendChild(head);

        var layout = document.createElement('div');
        layout.className = 'glpisearch-page-body';

        page.facets = document.createElement('aside');
        page.facets.className = 'glpisearch-facets';
        layout.appendChild(page.facets);

        page.results = document.createElement('div');
        page.results.className = 'glpisearch-results';
        layout.appendChild(page.results);

        page.root.appendChild(layout);

        var note = document.createElement('div');
        note.className = 'glpisearch-note';
        note.textContent = 'Counts are approximate: they are taken before per-record '
            + 'permissions are applied. GLPI\u2019s own exact-match results follow below.';
        page.root.appendChild(note);

        anchor.parentNode.insertBefore(page.root, anchor);

        runPageSearch();
    }

    function boot() {
        // querySelectorAll rather than getElementById: core includes the search
        // form from two places in the header template, and while only one of
        // them renders at a time, a layout change that rendered both would give
        // two elements one id — and getElementById would silently bind the
        // first and leave the visible one dead.
        document.querySelectorAll('#global-search, input[name="globalsearch"]').forEach(attach);
        enhanceSearchPage();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
