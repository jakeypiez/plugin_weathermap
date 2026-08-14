<?php

$database_type     = 'mysql';
$database_default  = getenv('CACTI_DB_NAME') ?: 'cacti';
$database_hostname = getenv('CACTI_DB_HOST') ?: 'db';
$database_username = getenv('CACTI_DB_USER') ?: 'cacti';
$database_password = getenv('CACTI_DB_PASSWORD') ?: 'cacti-dev-db';
$database_port     = getenv('CACTI_DB_PORT') ?: '3306';
$database_retries  = 20;
$database_ssl      = false;
$database_persist  = false;

$poller_id = 1;
$url_path  = '/';

$cacti_session_name = 'CactiWeathermapDev';
$cacti_db_session    = false;

$path_csrf_secret = '/var/lib/cacti/csrf-secret.php';
$php_path          = '/usr/local/bin/php';

