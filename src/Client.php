<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisearch;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\TransferException;

/**
 * A thin Meilisearch HTTP client.
 *
 * Deliberately not the official SDK. Meilisearch's API is a dozen JSON
 * endpoints; vendoring a client for it would add a dependency GLPI's tree does
 * not carry, and the part that actually needs care — that a search never
 * throws into a page render — is ours either way.
 *
 * Two rules run through everything here:
 *
 *  - **Writes are asynchronous.** Every write returns a task id and does the
 *    work later. A caller that wants to know it worked has to ask
 *    {@see self::task()}; a caller that just wants the document in eventually
 *    can ignore it, which is what the indexer does for all but the last write
 *    of a batch.
 *  - **Reads fail quiet, writes fail loud.** A search that cannot reach
 *    Meilisearch returns nothing and lets the caller fall back to GLPI's own
 *    search; an indexing run that cannot reach it raises, because a cron task
 *    that silently indexes nothing is a cron task that looks healthy while the
 *    index rots.
 */
final class Client
{
    /**
     * Searches are on a keystroke path. A slow answer is a useless answer.
     *
     * The connect timeout is the one that matters here and it is deliberately
     * short. A Meilisearch that refuses a connection fails in a millisecond and
     * the caller falls back; a Meilisearch that has *hung* — the host is up,
     * the process is wedged — accepts nothing and answers nothing, and every
     * keystroke would sit on this timer before falling back. One second is
     * about as long as a search box may take to admit defeat.
     */
    private const SEARCH_TIMEOUT = 3;
    private const SEARCH_CONNECT = 1;

    /** Indexing is not on that path, and a large batch legitimately takes a while. */
    private const WRITE_TIMEOUT = 60;
    private const WRITE_CONNECT = 5;

    public function __construct(
        private readonly string $url,
        private readonly string $key,
    ) {
    }

    /** Built from the stored settings, or null when there is nothing to talk to. */
    public static function fromSettings(): ?self
    {
        $cfg = Settings::all();

        if ($cfg['url'] === '' || $cfg['api_key'] === '') {
            return null;
        }

        return new self($cfg['url'], $cfg['api_key']);
    }

    /**
     * Is the server there, and does the key work?
     *
     * `/version` rather than `/health`: health answers before authentication,
     * so a wrong key would report a healthy server the plugin cannot use.
     *
     * @return array{ok:bool,message:string,version:string}
     */
    public function ping(): array
    {
        try {
            $body = $this->request('GET', '/version', null, self::SEARCH_TIMEOUT, self::SEARCH_CONNECT);
        } catch (SearchException $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'version' => ''];
        }

        return [
            'ok'      => true,
            'message' => 'Connected.',
            'version' => (string) ($body['pkgVersion'] ?? 'unknown'),
        ];
    }

    /**
     * Create an index if it is not already there. Idempotent.
     *
     * @return bool true when it had to be created
     */
    public function ensureIndex(string $uid, string $primary_key = 'id'): bool
    {
        try {
            $this->request('GET', '/indexes/' . rawurlencode($uid), null, self::WRITE_TIMEOUT);

            return false;
        } catch (SearchException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }

        $this->request('POST', '/indexes', ['uid' => $uid, 'primaryKey' => $primary_key], self::WRITE_TIMEOUT);

        return true;
    }

    /** @param array<string,mixed> $settings */
    public function updateSettings(string $uid, array $settings): int
    {
        $body = $this->request(
            'PATCH',
            '/indexes/' . rawurlencode($uid) . '/settings',
            $settings,
            self::WRITE_TIMEOUT
        );

        return (int) ($body['taskUid'] ?? 0);
    }

    /**
     * Add or replace documents.
     *
     * POST rather than PUT, and the distinction matters: PUT merges a partial
     * document into whatever is already there, which would leave the fields of
     * a previous version of a record behind when a field is cleared.
     *
     * @param array<int,array<string,mixed>> $documents
     */
    public function addDocuments(string $uid, array $documents): int
    {
        if ($documents === []) {
            return 0;
        }

        $body = $this->request(
            'POST',
            '/indexes/' . rawurlencode($uid) . '/documents',
            array_values($documents),
            self::WRITE_TIMEOUT
        );

        return (int) ($body['taskUid'] ?? 0);
    }

    /** @param string[] $ids */
    public function deleteDocuments(string $uid, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $body = $this->request(
            'POST',
            '/indexes/' . rawurlencode($uid) . '/documents/delete-batch',
            array_values($ids),
            self::WRITE_TIMEOUT
        );

        return (int) ($body['taskUid'] ?? 0);
    }

    public function deleteIndex(string $uid): int
    {
        try {
            $body = $this->request('DELETE', '/indexes/' . rawurlencode($uid), null, self::WRITE_TIMEOUT);
        } catch (SearchException $e) {
            if ($e->getCode() === 404) {
                return 0;
            }

            throw $e;
        }

        return (int) ($body['taskUid'] ?? 0);
    }

    /**
     * One search across several indexes, in one round trip.
     *
     * Not federated on purpose. Federation merges everything into one ranked
     * list, and both consumers here — the header dropdown and the palette —
     * group by itemtype, so a merged list would have to be taken apart again.
     * The non-federated response keeps them separate and labels each with its
     * `indexUid`, which is the shape both callers want.
     *
     * @param array<int,array<string,mixed>> $queries
     * @return array<int,array<string,mixed>> one entry per query, in order
     */
    public function multiSearch(array $queries): array
    {
        if ($queries === []) {
            return [];
        }

        $body = $this->request(
            'POST',
            '/multi-search',
            ['queries' => array_values($queries)],
            self::SEARCH_TIMEOUT,
            self::SEARCH_CONNECT
        );

        // Note `results`, not `hits`. The flat shape belongs to a federated
        // search, which this is not.
        return is_array($body['results'] ?? null) ? $body['results'] : [];
    }

    /** @return array<string,mixed> the task, for a caller that wants to wait */
    public function task(int $uid): array
    {
        return $this->request('GET', '/tasks/' . $uid, null, self::SEARCH_TIMEOUT);
    }

    /**
     * Block until a task finishes.
     *
     * Only ever called from cron and from the settings page's own buttons —
     * never from a page a technician is waiting on.
     */
    public function awaitTask(int $uid, int $seconds = 30): array
    {
        $deadline = time() + $seconds;

        do {
            $task   = $this->task($uid);
            $status = (string) ($task['status'] ?? '');

            if ($status === 'succeeded' || $status === 'failed' || $status === 'canceled') {
                return $task;
            }

            usleep(200000);
        } while (time() < $deadline);

        return ['status' => 'processing', 'uid' => $uid];
    }

    /**
     * Every index on the server.
     *
     * @return string[] uids
     */
    public function indexes(): array
    {
        $out    = [];
        $offset = 0;

        do {
            $body = $this->request(
                'GET',
                '/indexes?limit=100&offset=' . $offset,
                null,
                self::WRITE_TIMEOUT
            );

            $page = is_array($body['results'] ?? null) ? $body['results'] : [];

            foreach ($page as $index) {
                if (is_array($index) && isset($index['uid'])) {
                    $out[] = (string) $index['uid'];
                }
            }

            $offset += 100;
        } while (count($page) === 100);

        return $out;
    }

    /**
     * The embedders configured on an index, by name.
     *
     * Read rather than written. Whether an index can be searched by meaning is
     * a property of the Meilisearch deployment — somebody configured a model,
     * gave it credentials and let it embed a corpus — and this plugin's job is
     * to notice, not to decide.
     *
     * @return array<string,array<string,mixed>>
     */
    public function embedders(string $uid): array
    {
        try {
            $body = $this->request(
                'GET',
                '/indexes/' . rawurlencode($uid) . '/settings/embedders',
                null,
                self::SEARCH_TIMEOUT,
                self::SEARCH_CONNECT
            );
        } catch (SearchException $e) {
            if ($e->getCode() === 404) {
                return [];
            }

            throw $e;
        }

        return $body;
    }

    /** @return array<string,mixed> */
    public function stats(string $uid): array
    {
        try {
            return $this->request('GET', '/indexes/' . rawurlencode($uid) . '/stats', null, self::SEARCH_TIMEOUT);
        } catch (SearchException $e) {
            if ($e->getCode() === 404) {
                return ['numberOfDocuments' => 0, 'isIndexing' => false];
            }

            throw $e;
        }
    }

    /**
     * @param array<string,mixed>|array<int,mixed>|null $body
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, array|null $body, int $timeout, ?int $connect = null): array
    {
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->key,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'timeout'         => $timeout,
            'connect_timeout' => $connect ?? min(self::WRITE_CONNECT, $timeout),
            'http_errors'     => false,
        ];

        if ($body !== null) {
            $options['body'] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        try {
            $response = (new Guzzle())->request($method, rtrim($this->url, '/') . $path, $options);
        } catch (TransferException $e) {
            // Network-level: refused, DNS, timeout. No status to report.
            throw new SearchException('Meilisearch is unreachable: ' . $e->getMessage(), 0, $e);
        }

        $status  = $response->getStatusCode();
        $decoded = json_decode((string) $response->getBody(), true);
        $decoded = is_array($decoded) ? $decoded : [];

        if ($status >= 400) {
            // Meilisearch's own error shape. Carrying its `code` through is
            // what lets a caller tell "index does not exist yet" from "your key
            // is wrong", which are the same 4xx to anything less careful.
            $message = (string) ($decoded['message'] ?? ('HTTP ' . $status));
            $code    = (string) ($decoded['code'] ?? '');

            throw new SearchException(
                $code !== '' ? sprintf('%s (%s)', $message, $code) : $message,
                $status
            );
        }

        return $decoded;
    }
}
