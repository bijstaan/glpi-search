#!/bin/sh
# The glpi-search suite.
#
# Needs a reachable Meilisearch and a configured plugin — there is no mock, on
# purpose: what is under test is mostly what Meilisearch actually does, and a
# stand-in could only confirm that the plugin sent what it meant to send.
#
# The suite points the plugin at its own index prefix, so it cannot disturb a
# working index, and restores the configuration on the way out.
set -eu

cd "$(dirname "$0")/.."

php tests/search.php
