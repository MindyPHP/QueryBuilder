<?php

declare(strict_types=1);

/*
 * Studio 107 (c) 2018 Maxim Falaleev
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

return [
    'mysql' => [
        'url' => 'mysql://root@127.0.0.1/test?charset=utf8',
        'driver' => 'pdo_mysql',
        'fixture' => __DIR__.'/../fixtures/mysql.sql',
    ],
    'pgsql' => [
        'dsn' => 'pgsql://postgres@127.0.0.1:5432/test',
        'driver' => 'pdo_pgsql',
        'fixture' => __DIR__.'/../fixtures/pgsql.sql',
    ],
    'sqlite' => [
        'url' => 'sqlite:///:memory:',
        'driverClass' => 'Mindy\QueryBuilder\Database\Sqlite\Driver',
        'fixture' => __DIR__.'/../fixtures/sqlite.sql',
    ],
];
