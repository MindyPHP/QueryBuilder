<?php

/*
 * (c) Studio107 <mail@studio107.ru> http://studio107.ru
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Mindy\Bundle\PageBundle\TemplateLoader;

interface PageTemplateLoaderInterface
{
    /**
     * @return array
     */
    public function getTemplates();
}
