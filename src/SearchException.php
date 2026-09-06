<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisearch;

use RuntimeException;

/**
 * Something went wrong talking to Meilisearch.
 *
 * The code carries the HTTP status when there was one, and 0 when the failure
 * was at the network level — which is the distinction callers actually act on:
 * a 404 from an index that does not exist yet is recoverable by creating it,
 * and a refused connection is not recoverable by anything this plugin can do.
 */
final class SearchException extends RuntimeException
{
}
