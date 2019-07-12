<?php

namespace yay\core;

/**
 * 行为是所有行为类的基类
 *
 * 一个行为可以将自己的方法与属性`注入`到组件上，不需要修改代码就可以增强已有组件的功能。它还可以响应组件的事件，拦截代码执行。*
 *
 * @since 1.0
 */
class Behavior extends BaseObject
{
    /**
     * @var Component|null 行为的宿主
     */
    public ?Component $owner;

    /**
     *
     * 声明事件
     *
     * 子类可以重写该方法来声明哪些事件应该注入到宿住组件中
     *
     * 声明的方式有以下几种
     *
     * - 行为中的方法 : `handleClick`等同于`[$this,'handleClick']`
     * - 成员方法: `[$object, 'handleClick']`
     * - 类方法: `['Page', 'handleClick']`
     * - 匿名函数 : `function ($event) { ... }`
     *
     *  以下是例子
     *
     * ```php
     * [
     *     Model::EVENT_BEFORE_VALIDATE => 'myBeforeValidate',
     *     Model::EVENT_AFTER_VALIDATE => 'myAfterValidate',
     * ]
     * ```
     *
     * @return array 事件名 (健名) 与对应的事件处理函数 (健值).
     */
    public function events(): array
    {
        return [];
    }

    /**
     * 将行为对象附加到组件上
     * 默认实现设置到[[owner]]属性上并将[[events]]附加到[[owner]]上
     * 如果重写该方法，务必调用父类实现
     *
     * @param Component $owner 宿主组件
     *
     * @return void
     */
    public function attach(Component $owner): void
    {
        $this->owner = $owner;
        foreach ($this->events() as $event => $handler) {
            $owner->on($event, is_string($handler) ? [$this, $handler] : $handler);
        }
    }

    /**
     * 从组件上剥离行为
     * 默认实现是unset[[owner]]属性,并剥离[[events]]声明的事件
     * 如果重写该方法，务必调用父类实现
     */
    public function detach(): void
    {
        if ($this->owner) {
            foreach ($this->events() as $event => $handler) {
                $this->owner->off($event, is_string($handler) ? [$this, $handler] : $handler);
            }
            $this->owner = null;
        }
    }
}
