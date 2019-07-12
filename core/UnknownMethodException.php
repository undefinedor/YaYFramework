<?php
namespace yay\core;
/**
 * UnknownMethodException 代表因调用不存在方法抛出的异常
 * @since 1.0
 */
class UnknownMethodException extends \BadMethodCallException
{
    /**
     * @return string
     */
    public function getName(): string
    {
        return 'Unknown Method';
    }
}
