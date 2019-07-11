<?php
namespace yay\core;
/**
 * InvalidCallException 代表因错误调用方法导致的异常
 * @since 1.0
 */
class InvalidCallException extends \BadMethodCallException
{
    /**
     * @return string
     */
    public function getName()
    {
        return 'Invalid Call';
    }
}
