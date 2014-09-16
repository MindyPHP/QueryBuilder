<?php
/**
 * 
 *
 * All rights reserved.
 * 
 * @author Falaleev Maxim
 * @email max@studio107.ru
 * @version 1.0
 * @company Studio107
 * @site http://studio107.ru
 * @date 03/01/14.01.2014 21:52
 */

namespace Mindy\Orm;

use Mindy\Base\Mindy;
use Modules\Admin\AdminModule;
use Modules\User\Components\UserActionsTrait;
use \Yii;
use Mindy\Orm\Traits\AppYiiCompatible;

class Model extends Orm
{
    use AppYiiCompatible;

    public function __toString()
    {
        return (string) $this->classNameShort();
    }

    public function getVerboseName()
    {
        return $this->classNameShort();
    }

    public function reverse($route, $data = null)
    {
        return Mindy::app()->urlManager->reverse($route, $data);
    }

    public function recordActionInternal($owner, $text)
    {
        $url = method_exists($owner, 'getAbsoluteUrl') ? $owner->getAbsoluteUrl() : '#';
        $module = $this->getModule();
//        $this->recordAction(AdminModule::t('{model} [[{url}|{name}]] ' . $text, [
//            '{model}' => $module->t($owner->classNameShort()),
//            '{url}' => $url,
//            '{name}' => (string) $owner
//        ]));
    }

    public function afterUpdate($owner)
    {
//        $this->recordActionInternal($owner, 'was updated');
    }

    public function afterInsert($owner)
    {
//        $this->recordActionInternal($owner, 'was created');
    }

    public function afterDelete($owner)
    {
//        $this->recordActionInternal($owner, 'was deleted');
    }
}
