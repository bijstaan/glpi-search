<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisearch;

use CommonDBTM;
use CommonITILActor;
use Session;
use Ticket;
use User;

/**
 * Everything about one person, in one answer.
 *
 * The question a technician actually has when the phone rings is not "find me a
 * record". It is "who is this, and what have they got". Answering it out of a
 * search box means five searches — the person, their open tickets, the laptop
 * they are calling about, which site they are at — and four of those are
 * navigation rather than thought.
 *
 * So this is not a search. Nothing here goes near Meilisearch: the id is
 * already known by the time anybody asks, and what remains is a handful of
 * targeted lookups that the database answers better than an index would. What
 * it borrows from the rest of the plugin is the discipline about rights —
 * entity-scoped in SQL because that is cheap, then every single row put to
 * GLPI's own `canViewItem()` before it is shown.
 */
final class Dossier
{
    /** Enough to see the shape of someone's week without becoming a report. */
    public const MAX_TICKETS = 8;
    public const MAX_ASSETS  = 8;

    /**
     * @return array<string,mixed>|null null when there is no such user, or the
     *                                  caller may not see them
     */
    public static function forUser(int $users_id): ?array
    {
        if ($users_id <= 0) {
            return null;
        }

        $user = new User();

        if (!$user->getFromDB($users_id) || !$user->canViewItem()) {
            return null;
        }

        $tickets = self::tickets($users_id);
        $assets  = self::assets($users_id);

        return [
            'user'    => self::describe($user),
            'tickets' => $tickets['rows'],
            'assets'  => $assets['rows'],
            'counts'  => [
                'open'   => $tickets['open'],
                'closed' => $tickets['closed'],
                'assets' => $assets['total'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function describe(User $user): array
    {
        $id = (int) $user->getID();

        $real = trim(implode(' ', array_filter([
            (string) ($user->fields['realname'] ?? ''),
            (string) ($user->fields['firstname'] ?? ''),
        ])));

        $bits = [];
        foreach (['locations_id' => 'glpi_locations', 'usertitles_id' => 'glpi_usertitles'] as $field => $table) {
            $name = \Dropdown::getDropdownName($table, (int) ($user->fields[$field] ?? 0));
            if (is_string($name) && trim($name) !== '' && trim($name) !== '&nbsp;') {
                $bits[] = trim($name);
            }
        }

        return [
            'id'       => $id,
            'name'     => (string) ($user->fields['name'] ?? ''),
            'realname' => $real,
            'emails'   => self::emails($id),
            'phone'    => trim((string) ($user->fields['phone'] ?? '')),
            'mobile'   => trim((string) ($user->fields['mobile'] ?? '')),
            'where'    => implode(' · ', $bits),
            'active'   => (int) ($user->fields['is_active'] ?? 1) === 1,
            'url'      => $user->getLinkURL(),
        ];
    }

    /** @return string[] */
    private static function emails(int $users_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!$DB->tableExists('glpi_useremails')) {
            return [];
        }

        $out = [];

        foreach (
            $DB->request([
                'SELECT' => ['email'],
                'FROM'   => 'glpi_useremails',
                'WHERE'  => ['users_id' => $users_id],
                'ORDER'  => 'is_default DESC',
            ]) as $row
        ) {
            $email = trim((string) $row['email']);
            if ($email !== '') {
                $out[] = $email;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * The tickets this person raised, open ones first.
     *
     * Requester rather than "anyone on the ticket": the question is what this
     * person has asked for. A technician who is also a requester elsewhere
     * would otherwise arrive with their whole queue attached.
     *
     * @return array{rows:array<int,array<string,mixed>>,open:int,closed:int}
     */
    private static function tickets(int $users_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $closed = Ticket::getClosedStatusArray();
        $rows   = [];
        $counts = ['open' => 0, 'closed' => 0];
        $ticket = new Ticket();

        if (!$ticket->canView()) {
            return ['rows' => [], 'open' => 0, 'closed' => 0];
        }

        $criteria = [
            'SELECT'     => ['glpi_tickets.id', 'glpi_tickets.name', 'glpi_tickets.status',
                             'glpi_tickets.date_mod', 'glpi_tickets.entities_id'],
            'FROM'       => 'glpi_tickets',
            'INNER JOIN' => [
                'glpi_tickets_users' => [
                    'ON' => [
                        'glpi_tickets_users' => 'tickets_id',
                        'glpi_tickets'       => 'id',
                        ['AND' => [
                            'glpi_tickets_users.users_id' => $users_id,
                            'glpi_tickets_users.type'     => CommonITILActor::REQUESTER,
                        ]],
                    ],
                ],
            ],
            'WHERE'      => ['glpi_tickets.is_deleted' => 0]
                + getEntitiesRestrictCriteria('glpi_tickets'),
            // Open first, then most recently touched. The same ordering the
            // search results use, and for the same reason.
            'ORDER'      => ['glpi_tickets.date_mod DESC'],
        ];

        foreach ($DB->request($criteria) as $row) {
            $id = (int) $row['id'];

            // The index is not the authority here either.
            if (!$ticket->getFromDB($id) || !$ticket->canViewItem()) {
                continue;
            }

            $is_closed = in_array((int) $row['status'], $closed, true);
            $counts[$is_closed ? 'closed' : 'open']++;

            if (!$is_closed && count($rows) < self::MAX_TICKETS) {
                $rows[] = [
                    'id'     => $id,
                    'title'  => (string) $row['name'],
                    'status' => (string) Ticket::getStatus((int) $row['status']),
                    'url'    => $ticket->getLinkURL(),
                ];
            }
        }

        return ['rows' => $rows, 'open' => $counts['open'], 'closed' => $counts['closed']];
    }

    /**
     * What this person has been given.
     *
     * Every asset type GLPI gives an owner column, rather than a list of the
     * ones somebody thought of — an MSP that tracks phones and monitors cares
     * about those on a call as much as about laptops.
     *
     * @return array{rows:array<int,array<string,mixed>>,total:int}
     */
    private static function assets(int $users_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        /** @var array<string,mixed> $CFG_GLPI */
        global $CFG_GLPI;

        $rows  = [];
        $total = 0;

        foreach ((array) ($CFG_GLPI['asset_types'] ?? []) as $itemtype) {
            if (!is_string($itemtype) || !class_exists($itemtype)) {
                continue;
            }

            $item = getItemForItemtype($itemtype);
            if (!$item instanceof CommonDBTM || !$item->canView()) {
                continue;
            }

            $table = getTableForItemType($itemtype);
            if (!$DB->tableExists($table) || !$DB->fieldExists($table, 'users_id')) {
                continue;
            }

            $where = ['users_id' => $users_id];
            if ($DB->fieldExists($table, 'is_deleted')) {
                $where['is_deleted'] = 0;
            }
            if ($DB->fieldExists($table, 'is_template')) {
                $where['is_template'] = 0;
            }

            foreach (
                $DB->request([
                    'SELECT' => ['id', 'name'],
                    'FROM'   => $table,
                    'WHERE'  => $where + getEntitiesRestrictCriteria($table),
                    'ORDER'  => 'name ASC',
                ]) as $row
            ) {
                if (!$item->getFromDB((int) $row['id']) || !$item->canViewItem()) {
                    continue;
                }

                $total++;

                if (count($rows) < self::MAX_ASSETS) {
                    $rows[] = [
                        'id'    => (int) $row['id'],
                        'title' => trim((string) $row['name']) !== ''
                            ? (string) $row['name']
                            : (string) $item->getFriendlyName(),
                        'type'  => (string) $itemtype::getTypeName(1),
                        'icon'  => method_exists($itemtype, 'getIcon') ? (string) $itemtype::getIcon() : 'ti ti-box',
                        'url'   => $item->getLinkURL(),
                    ];
                }
            }
        }

        return ['rows' => $rows, 'total' => $total];
    }
}
