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
                    $this->attachBehavior($name,
                        $value instanceof Behavior ? $value : Yii::createObject($value));//todo DI

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
     * 设置组件属性为空
     *
     * 这个方法将会按以下顺序检测并执行操作:
     * - 通过setter定义的属性：设置属性值为null
     * - 行为的属性：设置行为的属性值为null
     *
     * 不要直接调用PHP的魔术方法,它将会在`unset($object->property)`时被调用
     *
     * @param string $name 属性名
     *
     * @throws InvalidCallException 如果属性只读
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
     * 调用非成员方法时被调用
     *
     * 该方法将检测是否附加的行为具有该方法，如果有的话将会执行
     *
     * 不要直接调用PHP的魔术方法,当调用一个不存在的成员方法时被调用
     *
     * @param string $name   方法名
     * @param array  $params 方法参数
     *
     * @return mixed 方法返回值
     * @throws UnknownMethodException 方法不存在
     */
    public function __call(string $name, $params)
    {
        $this->ensureBehaviors();
        foreach ($this->_behaviors as $object) {
            if ($object->hasMethod($name)) {
                return $object->$name(...$params);
            }
        }
        throw new UnknownMethodException('Calling unknown method: ' . get_class($this) . "::$name()");
    }

    /**
     * 在通过克隆现有对象创建对象之后调用此方法。
     * 它将移除所有的行为，因为行为应该附加在旧对象上。
     */
    public function __clone()
    {
        $this->_events = [];
        $this->_eventWildcards = [];
        $this->_behaviors = null;
    }

    /**
     * 判断组件是否有该属性
     *
     * 属性有以下定义：
     * A property is defined if:
     * - 可以通过getter/setter访问到(这时候属性名是大小不敏感的)
     * - 存在该名称的成员变量(当`checkVars`为`true`)
     * - 附加的行为上有该属性(当`$checkBehaviors`为true)
     *
     * @param string $name           属性名
     * @param bool   $checkVars      是否将成员变量视为属性
     * @param bool   $checkBehaviors 是否将组件上行为的属性视为组件的属性
     *
     * @return bool 属性是否存在
     * @see canGetProperty()
     * @see canSetProperty()
     */
    public function hasProperty(string $name, bool $checkVars = true, bool $checkBehaviors = true): bool
    {
        return $this->canGetProperty($name, $checkVars, $checkBehaviors)
            || $this->canSetProperty($name, false, $checkBehaviors);
    }

    /**
     * 判断属性是否可读
     *
     * 属性可读的定义：
     * - 可以通过getter访问到(这时候属性名是大小不敏感的)
     * - 存在该名称的成员变量(当`checkVars`为`true`)
     * - 附加的行为具有该可读属性(当`$checkBehaviors`为true)
     *
     * @param string $name           属性名
     * @param bool   $checkVars      是否将成员变量视为属性
     * @param bool   $checkBehaviors 是否将组件上行为的属性视为组件的属性
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
     * 判断属性是否可写
     *
     * 属性可写的定义：
     * - 可以通过setter访问到(这时候属性名是大小不敏感的)
     * - 存在该名称的成员变量(当`checkVars`为`true`)
     * - 附加的行为具有该可写属性(当`$checkBehaviors`为true)
     *
     * @param string $name           属性名
     * @param bool   $checkVars      是否将成员变量视为属性
     * @param bool   $checkBehaviors 是否将组件上行为的属性视为组件的属性
     *
     * @return bool 属性是否可写
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
     *判断该方法是否存在
     *
     * 方法存在的定于：
     * - 存在该成员方法
     * - 附加的行为存在该方法（当`$checkBehaviors`为true）
     *
     * @param string $name           方法名
     * @param bool   $checkBehaviors 是否将组件上行为的方法视为组件的方法
     *
     * @return bool 方法是否存在
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
     * 返回此组件的行为列表。
     *
     * 子类可以重写该方法去指定对应行为
     *
     * 该方法返回值应该是包含行为对象或者行为配置的数组。一个行为配置除了是行为类名还可以是以下的数组形式：
     * ```php
     * 'behaviorName' => [
     *     'class' => 'BehaviorClass',
     *     'property1' => 'value1',
     *     'property2' => 'value2',
     * ]
     * ```
     * 注意，行为类必须继承自[[Behavior]].行为可以是名称或者匿名的。
     * 如果采用名称作为数组键名，那么之后该行为可以通过[[getBehavior()]]检索或者通过[[detachBehavior()]]剥离。匿名行为则不可以检索与剥离。
     *
     * 声明在该方法的行为将会自动的附加到组件上(按需)
     *
     * @return array 行为配置
     */
    public function behaviors(): array
    {
        return [];
    }

    /**
     * 判断该事件是否有事件处理器
     *
     * @param string $name 事件名
     *
     * @return bool 是否有事件处理器附加在该事件上
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
     * 附加事件处理器到事件上
     *
     * 事件处理器必须是回调函数。以下是例子：
     *
     * ```
     * function ($event) { ... }         // anonymous function
     * [$object, 'handleClick']          // $object->handleClick()
     * ['Page', 'handleClick']           // Page::handleClick()
     * 'handleClick'                     // global function handleClick()
     * ```
     *
     * 事件处理器必须如下声明
     *
     * ```
     * function ($event)
     * ```
     *
     * 此处的`$event`是一个包含事件相关参数的[[Event]]对象
     *
     * 通配符模式:
     *
     * ```php
     * $component->on('event.group.*', function ($event) {
     *     Yii::trace($event->name . ' is triggered.');
     * });
     * ```
     *
     * @param string   $name    事件名
     * @param callable $handler 事件处理器
     * @param mixed    $data    当事件被触发，$data会传给事件处理器
     *                          如果事件处理器被调用，可以通过[[Event::data]]访问该数据
     * @param bool     $append  是否将事件处理器追加到事件处理器列表的尾部。如果为false,则插入事件处理器列表的头部。
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
     * 从组件上剥离事件处理器
     *
     * 这个方法与[[on()]] 相反
     *
     * 注意：如果为事件名称传递了通配符模式，则只会删除使用此通配符注册的处理器，而将保留使用与此通配符匹配的普通名称注册的处理程序。
     *
     * @param string   $name    事件名
     * @param callable $handler 需要被移除的事件处理器,如果为null,附加在该事件上的所有事件处理器都将被移除
     *
     * @return bool 如果处理器被发现且被移除
     * @see on()
     */
    public function off(string $name, callable $handler = null): bool
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
     * 触发事件
     * 此方法表示事件的发生。 它调用事件的所有附加处理程序，包括类级处理程序。
     *
     * @param string $name  事件名
     * @param Event  $event 事件的参数,如果不设置则创建一个默认的[[Event]]对象
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
     * 找到对应名称的行为
     *
     * @param string $name 行为名
     *
     * @return null|Behavior 行为对象,如果为null则表示该行为不存在
     */
    public function getBehavior(string $name): ?Behavior
    {
        $this->ensureBehaviors();

        return isset($this->_behaviors[$name]) ? $this->_behaviors[$name] : null;
    }

    /**
     * 返回附加在该组件上的所有行为
     *
     * @return Behavior[] 附加到该组件上的所有行为
     */
    public function getBehaviors(): array
    {
        $this->ensureBehaviors();

        return $this->_behaviors;
    }

    /**
     * 附加行为到该组件
     * 该方法将根据给的参数创建一个行为对象，之后，该行为对象将通过调用[[Behavior::attach()]]附加到该组件上
     *
     * @param string                $name     行为名
     * @param string|array|Behavior $behavior 行为配置,可以是以下的几个形式：
     *                                        - 一个[[Behavior]]对象
     *                                        - Behavior类名
     *                                        - 可以通过[[Yii::createObject()]]创建的配置数组
     *
     * @return Behavior 行为对象
     * @see detachBehavior()
     */
    public function attachBehavior(string $name, $behavior): Behavior
    {
        $this->ensureBehaviors();

        return $this->attachBehaviorInternal($name, $behavior);
    }

    /**
     * 附加一系列行为到组件上
     *
     * @param array $behaviors 附加到组件上的行为
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
     * 从组件上剥离对象
     * 行为的[[Behavior::detach()]]方法将被调用
     *
     * @param string $name 行为名
     *
     * @return null|Behavior 被剥离的对象，如果为null则表示行为不存在
     */
    public function detachBehavior(string $name): ?Behavior
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
     * 从组件上剥离所有行为
     */
    public function detachBehaviors(): void
    {
        $this->ensureBehaviors();
        foreach ($this->_behaviors as $name => $behavior) {
            $this->detachBehavior($name);
        }
    }

    /**
     * 确保声明在[[behaviors()]]上的行为附加到组件上
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
     * 附加一个行为到组件上
     *
     * @param string|int            $name     行为名,如果是一个数字，则意味着该行为是一个匿名行为。
     *                                        除此之外，如果该行为已经被附加到该组件，则首先剥离
     * @param string|array|Behavior $behavior 附加的行为
     *
     * @return Behavior 附加的行为
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
