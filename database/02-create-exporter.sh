#!/bin/bash
set -e

if [ -z "${MYSQL_EXPORTER_PASSWORD:-}" ]; then
  echo "ERROR: MYSQL_EXPORTER_PASSWORD is required"
  exit 1
fi

docker_process_sql --database=mysql <<SQL
CREATE USER IF NOT EXISTS 'exporter'@'%'
IDENTIFIED BY '${MYSQL_EXPORTER_PASSWORD}'
WITH MAX_USER_CONNECTIONS 3;

GRANT PROCESS, REPLICATION CLIENT, SELECT
ON *.* TO 'exporter'@'%';
SQL