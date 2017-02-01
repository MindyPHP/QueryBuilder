<?php

/*
 * (c) Studio107 <mail@studio107.ru> http://studio107.ru
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * Author: Maxim Falaleev <max@studio107.ru>
 */

namespace Mindy\QueryBuilder\Interfaces;

interface ISQLGenerator
{
    /**
     * @param $value
     *
     * @return bool
     */
    public function hasLimit($value);

    /**
     * @param $value
     *
     * @return bool
     */
    public function hasOffset($value);

    /**
     * @return string
     */
    public function getRandomOrder();

    /**
     * @param $value
     *
     * @return string
     */
    public function getBoolean($value = null);

    /**
     * @param null $value
     *
     * @return string
     */
    public function getDateTime($value = null);

    /**
     * @param null $value
     *
     * @return string
     */
    public function getDate($value = null);

    /**
     * @param null $value
     *
     * @return mixed
     */
    public function getTimestamp($value = null);
}
