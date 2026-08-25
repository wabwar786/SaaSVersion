#!/usr/bin/env bash
set -e
cd "$(dirname "$0")"
php scripts/bootstrap_database.php
php scripts/seed_roles.php
php scripts/seed_suppliers.php
php scripts/seed_edge_node.php
php scripts/seed_restaurant_demo.php
php -S 127.0.0.1:8080 public/router.php
