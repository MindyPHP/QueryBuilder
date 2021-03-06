<?php

declare(strict_types=1);

/*
 * Studio 107 (c) 2018 Maxim Falaleev
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mindy\QueryBuilder\Tests\Database\Sqlite;

use Mindy\QueryBuilder\Database\Sqlite\ExpressionBuilder;
use Mindy\QueryBuilder\Tests\BaseTest;

class LookupCollectionTest extends BaseTest
{
    protected $driver = 'sqlite';

    public function providerLookups()
    {
        return [
            ['icontains', 'name', 'foo', "LOWER(name) LIKE '%foo%'"],
            ['icontains', 'name', 1, "LOWER(name) LIKE '%1%'"],
            ['icontains', 'name', true, "LOWER(name) LIKE '%1%'"],

            ['regex', 'name', 'foo', "name REGEXP '/foo/'"],
            ['regex', 'name', 1, "name REGEXP '/1/'"],
            ['regex', 'name', true, "name REGEXP '/1/'"],

            ['iregex', 'name', 'foo', "name REGEXP '/foo/i'"],
            ['iregex', 'name', 1, "name REGEXP '/1/i'"],
            ['iregex', 'name', true, "name REGEXP '/1/i'"],

            ['second', 'name', 1, "strftime('%S', name) = '1'"],
            ['minute', 'name', 1, "strftime('%M', name) = '1'"],
            ['hour', 'name', 1, "strftime('%H', name) = '1'"],
            ['year', 'name', 1, "strftime('%Y', name) = '1'"],
            ['month', 'name', 1, "strftime('%m', name) = '01'"],
            ['day', 'name', 1, "strftime('%d', name) = '1'"],
            ['week_day', 'name', 1, "strftime('%w', name) = '1'"],
            ['week_day', 'name', 7, "strftime('%w', name) = '0'"],

            ['gte', 'created_at', new \DateTime('2016-07-22 13:54:09'), 'created_at >= \'2016-07-22 13:54:09\''],
        ];
    }

    /**
     * @dataProvider providerLookups
     */
    public function testLookups($lookup, $field, $value, $result)
    {
        $c = new ExpressionBuilder($this->connection);
        $this->assertSame(
            $result,
            $c->process($this->getAdapter(), $lookup, $field, $value)
        );
    }

    public function testAvailableLookups()
    {
        static $lookups = [
            'regex', 'iregex', 'second', 'year', 'minute',
            'hour', 'day', 'month', 'week_day',
        ];

        $c = new ExpressionBuilder($this->connection);
        foreach ($lookups as $lookup) {
            $this->assertTrue($c->has($lookup));
        }

        $this->assertFalse($c->has('foobar'));
    }
}
