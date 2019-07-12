<?php

namespace yay\core;

/**
 * 组件是实现*属性*,*事件*,*行为*特性的基类
 *
 * 组件提供了*事件*与 *行为* 特性,而*属性*特性是继承自[[\yay\core\BaseObject]]
 *
 * 事件是将自定义代码“注入”到特定位置的现有代码中的一种方法。
 * 例如，当用户添加评论时，评论对象可以触发“添加”事件。我们可以编写自定义代码并将其附加到此事件，以便在触发事件（即添加评论）时执行自定义代码。
 *
 *
 * 事件名在类中是唯一的,且*大小写敏感*
 *
 * 被称为*事件处理器*的一个或多个PHP回调可以附加在事件上。你可以通过调用[[trigger()]]触发事件.当事件被引发时，事件处理器将按照它们被附加的顺序被自动调用。
 *
 * 附加事件处理器到事件，可以调用[[on()]]:
 *
 * ```php
 * $post->on('update', function ($event) {
 *     // send email notification
 * });
 * ```
 * 上述代码中，一个匿名函数附加在post的"update"事件。你可以附加以下类型的事件处理器:
 *
 * - 匿名函数: `function ($event) { ... }`
 * - 成员方法:`[$object, 'handleAdd']`
 * - 静态类方法: `['Page', 'handleAdd']`
 * - 全局函数: `'handleAdd'`
 *
 * 时间处理器的示例如下:
 *
 * ```php
 * function foo($event)
 * ```
 * 其中 `$event` 是一个包含相关参数的[[Event]]对象
 *
 * 你也可以通过在组件上定义数组来附加时间处理器
 *
 * 语法如下:
 *
 * ```php
 * [
 *     'on add' => function ($event) { ... }
 * ]
 * ```
 * 这里的`on add`表示附加一个事件处理器到`add`事件上
 *
 * 同样的，在附加事件到组件的时候，你可能想要关联额外的数据到事件处理器。你可以这样做:
 *
 * ```php
 * $post->on('update', function ($event) {
 *     // the data can be accessed via $event->data
 * }, $data);
 * ```
 *
 * 一个行为是[[Behavior]]或者其子类的实例。一个组件被一个或多个行为附加。当一个行为附加到组件上时，它的公有属性与方法可以被组件直接访问，就仿佛是组件自己的一样。
 *
 * 附加一个行为到组件上，可以用[[behaviors()]]声明,或者直接调用[[attachBehavior]]。声明在[[behaviors]]的行为会自动的附加到对应的组件上
 *
 * 当通过数组配置组件的时候也可以附加行为。语法如下
 *
 * ```php
 * [
 *     'as tree' => [
 *         'class' => 'Tree',
 *     ],
 * ]
 * ```
 * 这里的`as tree`表示一个叫`tree`的行为附加到组件上。
 *
 * @property-read  Behavior[] $behaviors 附加到组件上的行为
 * @since  1.0
 */
class Component extends BaseObject
{
    /**
     * @var array 附加的事件(事件名 => 处理器)
     */
    private array $_events = [];
    /**
     * @var array 通配符模式的事件 (通配符事件名 => 处理器)
     */
    private array $_eventWildcards = [];
    /**
     * @var Behavior[]|null 附加的行为 (行为名 => 行为)
     */
    private ?array $_behaviors = null;


    /**
     * 返回组件属性值
     *
     * 这个方法将会按以下顺序检测并执行操作:
     * - 通过getter定义的属性
     * - 行为的属性
     *
     *  不要直接调用PHP的魔术方法,它将会在`$value = $object->property;`时被调用
     *
     *
     * @param string $name 属性名
     *
     * @return mixed 属性值/行为的属性值
     * @throws UnknownPropertyException 如果属性未定义
     * @throws InvalidCallException 如果属性只写
     * @see __set()
     */
    public function __get(string $name)
    {
        $getter = 'get' . $name;
        if (method_exists($this, $getter)) {
            // read property, e.g. getName()
            return $this->$getter();
        }

        // behavior property
        $this->ensureBehaviors();
        foreach ($this->_behaviors as $behavior) {
            if ($behavior->canGetProperty($name)) {
                return $behavior->$name;
            }
        }

        if (method_exists($this, 'set' . $name)) {
            throw new InvalidCallException('Getting write-only property: ' . get_class($this) . '::' . $name);
        }

        throw new UnknownPropertyException('Getting unknown property: ' . get_class($this) . '::' . $name);
    }

    /**
     * 设置组件属性
     *
     * 这个方法将会按以下顺序检测并执行操作:
     * - 通过setter定义:设置属性值
     * - "on xyz"形式的事件:附加"xyz"事件到组件上
     * - "as xyz"形式的行为:附加"xyz"行为到组件上
     * - 行为的属性：设置行为属性值
     *
     * 不要直接调用PHP的魔术方法,它将会在`$object->property = $value;`时被调用
     *
     * @param string $name  属性名/事件名/行为名
     * @param mixed  $value 值
     *
     * @throws UnknownPropertyException 如果属性未定义
     * @throws InvalidCallException 如果属性只读
     * @see __get()
     */
    public function __set(string $name, $value)
    {
        $setter = 'set' . $name;
        if (method_exists($this, $setter)) {
            // set property
            $this->$setter($value);

            return;
        } else {
            if (strncmp($name, 'on ', 3) === 0) {
                // on event: attach event handler
                $this->on(trim(substr($name, 3)), $value);

                return;
            } else {
                if (strncmp($name, 'as ', 3) === 0) {
                    // as behavior: attach behavior
                    $name = trim(substr($name, 3));
                    $this->attachBehavior($name, $value instanceof Behavior ? $value : Yii::createObject($value));//todo DI

                    return;
                }
            }
        }

        // behavior property
        $this->ensureBehaviors();
        foreach ($this->_behaviors as $behavior) {
            if ($behavior->canSetProperty($name)) {
                $behavior->$name = $value;

                return;
            }
        }

        if (method_exists($this, 'get' . $name)) {
            throw new InvalidCallException('Setting read-only property: ' . get_class($this) . '::' . $name);
        }

        throw new UnknownPropertyException('Setting unknown property: ' . get_class($this) . '::' . $name);
    }

    /**
     * 检测属性是否设置且不为null
     *
     * 这个方法将会按以下顺序检测并执行操作:
     * - 属性通过setter定义:返回属性是否设置
     * - 行为的属性: 返回属性是否设置
     *
     * 不要直接调用PHP的魔术方法,它将会在`isset($object->property)`时被调用
     *
     * @param string 属性名
     *
     * @return bool 属性是否存在
     * @see https://secure.php.net/manual/en/function.isset.php
     */
    public function __isset(string $name)
    {
        $getter = 'get' . $name;
        if (method_exists($this, $getter)) {
            return $this->$getter() !== null;
        }

        // behavior property
        $this->ensureBehaviors();
        foreach ($this->_behaviors as $behavior) {
            if ($behavior->canGetProperty($name)) {
                return $behavior->$name !== null;
            }
        }

        return false;
    }

    /**
     * Sets a component property to be null.
     *
     * This method will check in the following order and act accordingly:
     *
     *  - a property defined by a setter: set the property value to be null
     *  - a property of a behavior: set the property value to be null
     *
     * Do not call this method directly as it is a PHP magic method that
     * will be implicitly called when executing `unset($component->property)`.
     *
     * @param string $name the property name
     *
     * @throws InvalidCallException if the property is read only.
     * @see https://secure.php.net/manual/en/function.unset.php
     */
    public function __unset(string $name)
    {
        $setter = 'set' . $name;
        if (method_exists($this, $setter)) {
            $this->$setter(null);

            return;
        }

        // behavior property
        $this->ensureBehaviors();
        foreach ($this->_behaviors as $behavior) {
            if ($behavior->canSetProperty($name)) {
                $behavior->$name = null;

                return;
            }
        }

        throw new InvalidCallException('Unsetting an unknown or read-only property: ' . get_class($this) . '::' . $name);
    }

    /**
     * Calls the named method which is not a class method.
     *
     * This method will check if any attached behavior has
     * the named method and will execute it if available.
     *
     * Do not call this method directly as it is a PHP magic method that
     * will be implicitly called when an unknown method is being invoked.
     *
     * @param string $name   the method name
     * @param array  $params method parameters
     *
     * @return mixed the method return value
     * @throws UnknownMethodException when calling unknown method
     */
    public function __call(string $name, $params)
    {
        $this->ensureBehaviors();
        foreach ($this->_behaviors as $object) {
            if ($object->hasMethod($name)) {
                return call_user_func_array([$object, $name], $params);
            }
        }
        throw new UnknownMethodException('Calling unknown method: ' . get_class($this) . "::$name()");
    }

    /**
     * This method is called after the object is created by cloning an existing one.
     * It removes all behaviors because they are attached to the old object.
     */
    public function __clone()
    {
        $this->_events = [];
        $this->_eventWildcards = [];
        $this->_behaviors = null;
    }

    /**
     * Returns a value indicating whether a property is defined for this component.
     *
     * A property is defined if:
     *
     * - the class has a getter or setter method associated with the specified name
     *   (in this case, property name is case-insensitive);
     * - the class has a member variable with the specified name (when `$checkVars` is true);
     * - an attached behavior has a property of the given name (when `$checkBehaviors` is true).
     *
     * @param string $name           the property name
     * @param bool   $checkVars      whether to treat member variables as properties
     * @param bool   $checkBehaviors whether to treat behaviors' properties as properties of this component
     *
     * @return bool whether the property is defined
     * @see canGetProperty()
     * @see canSetProperty()
     */
    public function hasProperty(string $name, bool $checkVars = true, bool $checkBehaviors = true): bool
    {
        return $this->canGetProperty($name, $checkVars, $checkBehaviors) || $this->canSetProperty($name, false,
                $checkBehaviors);
    }

    /**
     * Returns a value indicating whether a property can be read.
     *
     * A property can be read if:
     *
     * - the class has a getter method associated with the specified name
     *   (in this case, property name is case-insensitive);
     * - the class has a member variable with the specified name (when `$checkVars` is true);
     * - an attached behavior has a readable property of the given name (when `$checkBehaviors` is true).
     *
     * @param string $name           the property name
     * @param bool   $checkVars      whether to treat member variables as properties
     * @param bool   $checkBehaviors whether to treat behaviors' properties as properties of this component
     *
     * @return bool whether the property can be read
     * @see canSetProperty()
     */
    public function canGetProperty(string $name, bool $checkVars = true, bool $checkBehaviors = true): bool
    {
        if (method_exists($this, 'get' . $name) || $checkVars && property_exists($this, $name)) {
            return true;
        } else {
            if ($checkBehaviors) {
                $this->ensureBehaviors();
                foreach ($this->_behaviors as $behavior) {
                    if ($behavior->canGetProperty($name, $checkVars)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Returns a value indicating whether a property can be set.
     *
     * A property can be written if:
     *
     * - the class has a setter method associated with the specified name
     *   (in this case, property name is case-insensitive);
     * - the class has a member variable with the specified name (when `$checkVars` is true);
     * - an attached behavior has a writable property of the given name (when `$checkBehaviors` is true).
     *
     * @param string $name           the property name
     * @param bool   $checkVars      whether to treat member variables as properties
     * @param bool   $checkBehaviors whether to treat behaviors' properties as properties of this component
     *
     * @return bool whether the property can be written
     * @see canGetProperty()
     */
    public function canSetProperty(string $name, bool $checkVars = true, bool $checkBehaviors = true): bool
    {
        if (method_exists($this, 'set' . $name) || $checkVars && property_exists($this, $name)) {
            return true;
        } else {
            if ($checkBehaviors) {
                $this->ensureBehaviors();
                foreach ($this->_behaviors as $behavior) {
                    if ($behavior->canSetProperty($name, $checkVars)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Returns a value indicating whether a method is defined.
     *
     * A method is defined if:
     *
     * - the class has a method with the specified name
     * - an attached behavior has a method with the given name (when `$checkBehaviors` is true).
     *
     * @param string $name           the property name
     * @param bool   $checkBehaviors whether to treat behaviors' methods as methods of this component
     *
     * @return bool whether the method is defined
     */
    public function hasMethod(string $name, bool $checkBehaviors = true): bool
    {
        if (method_exists($this, $name)) {
            return true;
        } else {
            if ($checkBehaviors) {
                $this->ensureBehaviors();
                foreach ($this->_behaviors as $behavior) {
                    if ($behavior->hasMethod($name)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Returns a list of behaviors that this component should behave as.
     *
     * Child classes may override this method to specify the behaviors they want to behave as.
     *
     * The return value of this method should be an array of behavior objects or configurations
     * indexed by behavior names. A behavior configuration can be either a string specifying
     * the behavior class or an array of the following structure:
     *
     * ```php
     * 'behaviorName' => [
     *     'class' => 'BehaviorClass',
     *     'property1' => 'value1',
     *     'property2' => 'value2',
     * ]
     * ```
     *
     * Note that a behavior class must extend from [[Behavior]]. Behaviors can be attached using a name or anonymously.
     * When a name is used as the array key, using this name, the behavior can later be retrieved using
     * [[getBehavior()]] or be detached using [[detachBehavior()]]. Anonymous behaviors can not be retrieved or
     * detached.
     *
     * Behaviors declared in this method will be attached to the component automatically (on demand).
     *
     * @return array the behavior configurations.
     */
    public function behaviors(): array
    {
        return [];
    }

    /**
     * Returns a value indicating whether there is any handler attached to the named event.
     *
     * @param string $name the event name
     *
     * @return bool whether there is any handler attached to the event.
     */
    public function hasEventHandlers(string $name): bool
    {
        $this->ensureBehaviors();

        foreach ($this->_eventWildcards as $wildcard => $handlers) {
            if (!empty($handlers) && StringHelper::matchWildcard($wildcard, $name)) {
                return true;
            }
        }

        return !empty($this->_events[$name]) || Event::hasHandlers($this, $name);
    }

    /**
     * Attaches an event handler to an event.
     *
     * The event handler must be a valid PHP callback. The following are
     * some examples:
     *
     * ```
     * function ($event) { ... }         // anonymous function
     * [$object, 'handleClick']          // $object->handleClick()
     * ['Page', 'handleClick']           // Page::handleClick()
     * 'handleClick'                     // global function handleClick()
     * ```
     *
     * The event handler must be defined with the following signature,
     *
     * ```
     * function ($event)
     * ```
     *
     * where `$event` is an [[Event]] object which includes parameters associated with the event.
     *
     * Since 2.0.14 you can specify event name as a wildcard pattern:
     *
     * ```php
     * $component->on('event.group.*', function ($event) {
     *     Yii::trace($event->name . ' is triggered.');
     * });
     * ```
     *
     * @param string   $name    the event name
     * @param callable $handler the event handler
     * @param mixed    $data    the data to be passed to the event handler when the event is triggered.
     *                          When the event handler is invoked, this data can be accessed via [[Event::data]].
     * @param bool     $append  whether to append new event handler to the end of the existing
     *                          handler list. If false, the new handler will be inserted at the beginning of the
     *                          existing handler list.
     *
     * @see off()
     */
    public function on(string $name, callable $handler, $data = null, bool $append = true)
    {
        $this->ensureBehaviors();

        if (strpos($name, '*') !== false) {
            if ($append || empty($this->_eventWildcards[$name])) {
                $this->_eventWildcards[$name][] = [$handler, $data];
            } else {
                array_unshift($this->_eventWildcards[$name], [$handler, $data]);
            }

            return;
        }

        if ($append || empty($this->_events[$name])) {
            $this->_events[$name][] = [$handler, $data];
        } else {
            array_unshift($this->_events[$name], [$handler, $data]);
        }
    }

    /**
     * Detaches an existing event handler from this component.
     *
     * This method is the opposite of [[on()]].
     *
     * Note: in case wildcard pattern is passed for event name, only the handlers registered with this
     * wildcard will be removed, while handlers registered with plain names matching this wildcard will remain.
     *
     * @param string   $name    event name
     * @param callable $handler the event handler to be removed.
     *                          If it is null, all handlers attached to the named event will be removed.
     *
     * @return bool if a handler is found and detached
     * @see on()
     */
    public function off(string $name, callable $handler = null)
    {
        $this->ensureBehaviors();
        if (empty($this->_events[$name]) && empty($this->_eventWildcards[$name])) {
            return false;
        }
        if ($handler === null) {
            unset($this->_events[$name], $this->_eventWildcards[$name]);

            return true;
        }

        $removed = false;
        // plain event names
        if (isset($this->_events[$name])) {
            foreach ($this->_events[$name] as $i => $event) {
                if ($event[0] === $handler) {
                    unset($this->_events[$name][$i]);
                    $removed = true;
                }
            }
            if ($removed) {
                $this->_events[$name] = array_values($this->_events[$name]);

                return $removed;
            }
        }

        // wildcard event names
        if (isset($this->_eventWildcards[$name])) {
            foreach ($this->_eventWildcards[$name] as $i => $event) {
                if ($event[0] === $handler) {
                    unset($this->_eventWildcards[$name][$i]);
                    $removed = true;
                }
            }
            if ($removed) {
                $this->_eventWildcards[$name] = array_values($this->_eventWildcards[$name]);
                // remove empty wildcards to save future redundant regex checks:
                if (empty($this->_eventWildcards[$name])) {
                    unset($this->_eventWildcards[$name]);
                }
            }
        }

        return $removed;
    }

    /**
     * Triggers an event.
     * This method represents the happening of an event. It invokes
     * all attached handlers for the event including class-level handlers.
     *
     * @param string $name  the event name
     * @param Event  $event the event parameter. If not set, a default [[Event]] object will be created.
     */
    public function trigger(string $name, Event $event = null)
    {
        $this->ensureBehaviors();

        $eventHandlers = [];
        foreach ($this->_eventWildcards as $wildcard => $handlers) {
            if (StringHelper::matchWildcard($wildcard, $name)) {
                $eventHandlers = array_merge($eventHandlers, $handlers);
            }
        }

        if (!empty($this->_events[$name])) {
            $eventHandlers = array_merge($eventHandlers, $this->_events[$name]);
        }

        if (!empty($eventHandlers)) {
            if ($event === null) {
                $event = new Event();
            }
            if ($event->sender === null) {
                $event->sender = $this;
            }
            $event->handled = false;
            $event->name = $name;
            foreach ($eventHandlers as $handler) {
                $event->data = $handler[1];
                call_user_func($handler[0], $event);
                // stop further handling if the event is handled
                if ($event->handled) {
                    return;
                }
            }
        }

        // invoke class-level attached handlers
        Event::trigger($this, $name, $event);
    }

    /**
     * Returns the named behavior object.
     *
     * @param string $name the behavior name
     *
     * @return null|Behavior the behavior object, or null if the behavior does not exist
     */
    public function getBehavior(string $name): ?Behavior
    {
        $this->ensureBehaviors();

        return isset($this->_behaviors[$name]) ? $this->_behaviors[$name] : null;
    }

    /**
     * Returns all behaviors attached to this component.
     * @return Behavior[] list of behaviors attached to this component
     */
    public function getBehaviors(): array
    {
        $this->ensureBehaviors();

        return $this->_behaviors;
    }

    /**
     * Attaches a behavior to this component.
     * This method will create the behavior object based on the given
     * configuration. After that, the behavior object will be attached to
     * this component by calling the [[Behavior::attach()]] method.
     *
     * @param string                $name     the name of the behavior.
     * @param string|array|Behavior $behavior the behavior configuration. This can be one of the following:
     *
     *  - a [[Behavior]] object
     *  - a string specifying the behavior class
     *  - an object configuration array that will be passed to [[Yii::createObject()]] to create the behavior object.
     *
     * @return Behavior the behavior object
     * @see detachBehavior()
     */
    public function attachBehavior(string $name, $behavior)
    {
        $this->ensureBehaviors();

        return $this->attachBehaviorInternal($name, $behavior);
    }

    /**
     * Attaches a list of behaviors to the component.
     * Each behavior is indexed by its name and should be a [[Behavior]] object,
     * a string specifying the behavior class, or an configuration array for creating the behavior.
     *
     * @param array $behaviors list of behaviors to be attached to the component
     *
     * @see attachBehavior()
     */
    public function attachBehaviors(array $behaviors)
    {
        $this->ensureBehaviors();
        foreach ($behaviors as $name => $behavior) {
            $this->attachBehaviorInternal($name, $behavior);
        }
    }

    /**
     * Detaches a behavior from the component.
     * The behavior's [[Behavior::detach()]] method will be invoked.
     *
     * @param string $name the behavior's name.
     *
     * @return null|Behavior the detached behavior. Null if the behavior does not exist.
     */
    public function detachBehavior(string $name)
    {
        $this->ensureBehaviors();
        if (isset($this->_behaviors[$name])) {
            $behavior = $this->_behaviors[$name];
            unset($this->_behaviors[$name]);
            $behavior->detach();

            return $behavior;
        }

        return null;
    }

    /**
     * Detaches all behaviors from the component.
     */
    public function detachBehaviors(): void
    {
        $this->ensureBehaviors();
        foreach ($this->_behaviors as $name => $behavior) {
            $this->detachBehavior($name);
        }
    }

    /**
     * Makes sure that the behaviors declared in [[behaviors()]] are attached to this component.
     */
    public function ensureBehaviors(): void
    {
        if ($this->_behaviors === null) {
            $this->_behaviors = [];
            foreach ($this->behaviors() as $name => $behavior) {
                $this->attachBehaviorInternal($name, $behavior);
            }
        }
    }

    /**
     * Attaches a behavior to this component.
     *
     * @param string|int            $name     the name of the behavior. If this is an integer, it means the behavior
     *                                        is an anonymous one. Otherwise, the behavior is a named one and any
     *                                        existing behavior with the same name will be detached first.
     * @param string|array|Behavior $behavior the behavior to be attached
     *
     * @return Behavior the attached behavior.
     */
    private function attachBehaviorInternal(string $name, $behavior): Behavior
    {
        if (!($behavior instanceof Behavior)) {
            $behavior = Yii::createObject($behavior);
        }
        if (is_int($name)) {
            $behavior->attach($this);
            $this->_behaviors[] = $behavior;
        } else {
            if (isset($this->_behaviors[$name])) {
                $this->_behaviors[$name]->detach();
            }
            $behavior->attach($this);
            $this->_behaviors[$name] = $behavior;
        }

        return $behavior;
    }
}
