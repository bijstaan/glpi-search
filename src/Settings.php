<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisearch;

use Config;
use GLPIKey;

/**
 * Plugin configuration.
 *
 * The Meilisearch key is the only secret here, and it is handled the way
 * glpi-ai handles a provider credential: declared to GLPI in SECURED_CONFIGS so
 * that `glpi:security:changekey` rotates it and the change history masks it,
 * encrypted on the way in and decrypted on the way out. GLPI does not encrypt
 * on write by itself — declaring the key only tells the rotation machinery
 * about it — so {@see self::save()} does that here.
 */
final class Settings
{
    /** Shown in place of a stored secret, and ignored when posted back. */
    public const SECRET_PLACEHOLDER = '••••••••';

    public const DEFAULTS = [
        // The master switch. Off means the header box behaves exactly as core
        // ships it and nothing is queued or indexed.
        'enabled'          => 0,

        // Where Meilisearch is. From inside the GLPI container on the dev
        // stack this is http://meilisearch:7700.
        'url'              => '',

        // An API key with write access to the indexes below. A search-only key
        // is not enough: this plugin also writes documents and settings.
        'api_key'          => '',

        // Prefixed so that two GLPI instances can share one Meilisearch without
        // silently merging their records into each other's results.
        'index_prefix'     => 'glpi',

        // Which itemtypes get indexed, as a CSV of class names. The default is
        // the palette's set: the types people actually reach for by name.
        'itemtypes'        => 'Ticket,Change,Problem,Computer,User,Software',

        // Index every itemtype GLPI has a table and a name for, rather than
        // the curated list. "Can I find it by typing part of its name" has no
        // good reason to stop at thirty types, and an index nobody queries
        // costs a few hundred kilobytes.
        'index_all'        => 0,

        // Ask Meilisearch to count facet values alongside results. Off costs
        // nothing to turn on; it is here because a site that never shows them
        // should not pay for computing them on every keystroke.
        'facets_enabled'   => 1,

        /**
         * Jargon, one rule per line.
         *
         * The gap this closes is not typos, it is vocabulary. A user says "the
         * VPN is down" and the ticket says AnyConnect; a user says "the copier"
         * and the asset is a Ricoh MFP. Neither side is wrong, and no amount of
         * typo tolerance bridges them, because the words are simply different.
         *
         * The defaults are bidirectional (`<=>`) because that is almost always
         * what jargon wants: the technician may type either the word the user
         * used or the word the record uses, and does not know in advance which
         * one the instance happens to have written down.
         */
        'synonyms'         => "vpn <=> anyconnect, globalprotect, forticlient\n"
            . "printer <=> mfp, copier, laserjet\n"
            . "laptop <=> notebook, macbook\n"
            . "email <=> outlook, exchange, mailbox\n"
            . "wifi <=> wireless, wlan, ssid\n"
            . "password <=> passwd, credentials, login",

        // Take over #global-search in the header.
        'takeover_header'  => 1,

        // Results per itemtype in the header dropdown.
        'per_type'         => 5,

        // Minimum query length before anything is sent.
        'min_chars'        => 2,

        // How many documents one cron run pushes. Meilisearch accepts far
        // larger batches happily; the limit here is GLPI's memory while it
        // builds them, not Meilisearch's appetite.
        'batch'            => 500,

        // How many candidates to ask Meilisearch for per itemtype before
        // GLPI's own rights check thins them. See Visibility::OVERFETCH.
        'overfetch'        => 4,

        // --------------------------------------------------------------- hybrid

        // There is no switch for hybrid search and no embedder configuration
        // here, on purpose. An embedder belongs to the Meilisearch index: it is
        // Meilisearch that calls the model, stores the vectors and keeps them
        // in step with the documents. Asking for the same thing twice — once on
        // the server and once here — would only create a second place to be
        // wrong, and the failure would be a plugin quietly not using an
        // embedder that was sitting there working.
        //
        // So the rule is: if an index has an embedder, searches against it are
        // hybrid. See Schema::embedderFor().
        //
        // This is the one part that genuinely is a query-time choice rather
        // than server configuration — Meilisearch has no index-level default
        // for it. 0.0 is pure keyword, 1.0 is pure meaning; Meilisearch's own
        // per-query default is 0.5, and a lower value is the safer start,
        // because keyword results are the ones people can predict.
        'semantic_ratio'   => '0.3',
    ];

    /** Config keys holding a secret, for SECURED_CONFIGS. */
    public static function secretKeys(): array
    {
        return ['api_key'];
    }

    /** @return array<string,string> every setting, secrets decrypted */
    public static function all(): array
    {
        $stored = Config::getConfigurationValues(
            PLUGIN_GLPISEARCH_CONFIG_CONTEXT,
            array_keys(self::DEFAULTS)
        );

        $out    = [];
        $secret = self::secretKeys();

        foreach (self::DEFAULTS as $key => $default) {
            $raw = $stored[$key] ?? null;

            if ($raw === null || $raw === '') {
                $out[$key] = (string) $default;
                continue;
            }

            if (in_array($key, $secret, true)) {
                // The other half of the asymmetry noted in save(): the write
                // path encrypts by itself and the read path does not decrypt,
                // so this is where it happens — once, in the one place that
                // keeps every caller from having to know which keys are secret.
                $plain     = (new GLPIKey())->decrypt((string) $raw);
                $out[$key] = is_string($plain) ? $plain : '';
                continue;
            }

            $out[$key] = (string) $raw;
        }

        return $out;
    }

    public static function get(string $key): string
    {
        return self::all()[$key] ?? (string) (self::DEFAULTS[$key] ?? '');
    }

    public static function flag(string $key): bool
    {
        return (int) self::get($key) === 1;
    }

    /** @param array<string,string> $input */
    public static function save(array $input): void
    {
        $values = [];
        $secret = self::secretKeys();

        foreach ($input as $key => $value) {
            if (!array_key_exists($key, self::DEFAULTS)) {
                continue;
            }

            $value = trim((string) $value);

            if (in_array($key, $secret, true)) {
                // An unchanged placeholder means "leave it alone". Without this
                // the settings page would erase a credential it never showed.
                if ($value === self::SECRET_PLACEHOLDER) {
                    continue;
                }

                if ($value !== '' && !(new GLPIKey())->isConfigSecured(PLUGIN_GLPISEARCH_CONFIG_CONTEXT, $key)) {
                    throw new \RuntimeException(
                        "glpisearch: refusing to store $key — it is not declared in SECURED_CONFIGS, "
                        . 'so it would be written unencrypted.'
                    );
                }

                // Handed over in the clear. The asymmetry is real and is GLPI's,
                // not ours: setConfigurationValues() encrypts a secured config
                // on the way in, while getConfigurationValues() does *not*
                // decrypt on the way out. Encrypting here as well would store
                // two layers and hand callers ciphertext that looks like a key.
                $values[$key] = $value;
                continue;
            }

            $values[$key] = $value;
        }

        if ($values !== []) {
            Config::setConfigurationValues(PLUGIN_GLPISEARCH_CONFIG_CONTEXT, $values);
        }
    }

    /** @return string[] the itemtypes configured for indexing, validated */
    public static function itemtypes(): array
    {
        $wanted = array_filter(array_map('trim', explode(',', self::get('itemtypes'))));

        return array_values(array_intersect($wanted, Schema::indexable()));
    }

    /**
     * The synonym rules, as Meilisearch wants them.
     *
     * Two forms, because both are genuinely wanted:
     *
     *  - `copier <=> printer` — either word finds the other, and every listed
     *    word finds every other. This is what jargon usually needs: the
     *    technician may type the word the user used or the word the record
     *    uses, and cannot know in advance which one this instance wrote down.
     *  - `vpn => anyconnect` — one-way. For when widening the reverse would be
     *    wrong: somebody who types a product name is being specific on purpose,
     *    and should not be handed every ticket of that general category.
     *
     * Meilisearch itself only understands the one-way form, so `<=>` is
     * expanded here into every pair it implies.
     *
     * @return array<string,string[]>
     */
    public static function synonyms(): array
    {
        $out = [];

        $add = static function (string $from, string $to) use (&$out): void {
            if ($from === '' || $to === '' || $from === $to) {
                return;
            }

            // Merged rather than overwritten, so two lines naming the same term
            // add up instead of the second silently winning.
            $out[$from] = array_values(array_unique(array_merge($out[$from] ?? [], [$to])));
        };

        foreach (preg_split('/\R/', self::get('synonyms')) ?: [] as $line) {
            $line = trim((string) $line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // `<=>` first: it contains `=>`, so testing for the one-way form
            // first would match every bidirectional line and silently halve it.
            $both = str_contains($line, '<=>');
            $sep  = $both ? '<=>' : '=>';

            if (!str_contains($line, $sep)) {
                continue;
            }

            [$term, $rest] = explode($sep, $line, 2);

            $term = mb_strtolower(trim($term));
            if ($term === '') {
                continue;
            }

            $values = [];
            foreach (explode(',', $rest) as $value) {
                $value = mb_strtolower(trim($value));
                if ($value !== '') {
                    $values[] = $value;
                }
            }

            foreach ($values as $value) {
                $add($term, $value);

                if ($both) {
                    $add($value, $term);

                    // Everything on the line means the same thing, so they must
                    // all reach each other — otherwise "copier" would find
                    // "printer" but never "mfp", which is not what the line says.
                    foreach ($values as $other) {
                        $add($value, $other);
                    }
                }
            }
        }

        return $out;
    }

    /** Is there enough here to talk to Meilisearch at all? */
    public static function isConfigured(): bool
    {
        $cfg = self::all();

        return $cfg['url'] !== '' && $cfg['api_key'] !== '';
    }

    /** The master switch and a usable host, which is what every caller means. */
    public static function isLive(): bool
    {
        return self::flag('enabled') && self::isConfigured();
    }
}
