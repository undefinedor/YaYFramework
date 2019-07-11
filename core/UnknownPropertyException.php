<?php
namespace yay\core;
/**
 * UnknownPropertyException 代表因访问不存在属性抛出的异常
 * @since 1.0
 */
class UnknownPropertyException extends \Exception
{
    /**
     * @return string
     */
    public function getName():string
    {
        return 'Unknown Property';
    }
}
