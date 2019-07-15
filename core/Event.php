<?php
namespace yay\core;
/**
 * Event 是所有事件基类
 * 它封装了与事件关联的参数
 * [[sender]]属性描述了谁触发了该事件
 * [[handled]]属性表示该事件是否被处理
 * 如果一个事件处理器设置[[handled]]为`true`,其余未调用的处理程序将不会被调用
 * 此外，在附加事件处理程序时，当调用事件处理程序时，可以通过[[data]]属性传递额外的数据并使其可用。
 * @since 1.0
 */
class Event extends BaseObject
{
    /**
     * @var string 事件名,这个属性通过[[Component::trigger()]]与[[trigger]]设置
     *             每个处理器可能会通过该属性去检查哪个事件在执行
     * @see trigger
     */
    public string $name;
    /**
     * @var Object 事件发送者。如果没有设置，则该属性设置为调用[[trigger()]]的对象。
     *             当事件是一个静态调用的类级事件的时候，该属性可以为`null`
     */
    public Object $sender;
    /**
     * @var bool 事件是否被处理。默认为`false`.
     *           当一个事件处理器设置该属性为`true`,这个事件处理过程将会终止并忽略其它未调用的处理器
     */
    public bool $handled = false;
    /**
     * @var mixed 附加事件处理程序时传递给[[component:on()]的数据
     *            注意,该变量取决于当前正在执行的事件处理程序。
     */
    public $data;
    /**
     * @var array 包含所有全局注册的事件
     */
    private static array $_events = [];
    /**
     * @var array 包含所有全局注册的通配符模式事件
     */
    private static array $_eventWildcards = [];

    /**
     * 附加一个事件处理器到类级事件
     *
     * 当一个类级事件被触发，附加在该类与其父类的事件处理器都将会触发。
     *
     * 例如,下面的代码附加了一个事件处理器到`ActiveRecord`的`afterInsert`事件上:
     *
     * ```php
     * Event::on(ActiveRecord::className(), ActiveRecord::EVENT_AFTER_INSERT, function ($event) {
     *     Yii::trace(get_class($event->sender) . ' is inserted.');
     * });
     * ```
     *
     * 这个处理器将会在每次ActiveRecord成功插入后调用
     *
     * 你还可以指定类名或者事件名为通配符模式
     *
     * ```php
     * Event::on('app\models\db\*', '*Insert', function ($event) {
     *     Yii::trace(get_class($event->sender) . ' is inserted.');
     * });
     * ```
     *
     * @param string   $class   事件处理器附加的完整类名
     * @param string   $name    事件名
     * @param callable $handler 事件处理器
     * @param mixed    $data    当事件被触发，$data会传给事件处理器
     *                          如果事件处理器被调用，可以通过[[Event::data]]访问该数据
     * @param bool     $append  是否将事件处理器追加到事件处理器列表的尾部。如果为false,则插入事件处理器列表的头部。
     *
     * @see off()
     */
    public static function on(string $class, string $name, callable $handler, $data = null, bool $append = true)
    {
        $class = ltrim($class, '\\');

        if (strpos($class, '*') !== false || strpos($name, '*') !== false) {
            if ($append || empty(self::$_eventWildcards[$name][$class])) {
                self::$_eventWildcards[$name][$class][] = [$handler, $data];
            } else {
                array_unshift(self::$_eventWildcards[$name][$class], [$handler, $data]);
            }

            return;
        }

        if ($append || empty(self::$_events[$name][$class])) {
            self::$_events[$name][$class][] = [$handler, $data];
        } else {
            array_unshift(self::$_events[$name][$class], [$handler, $data]);
        }
    }

    /**
     * 从类级事件上剥离事件处理器
     *
     * 这个方法是[[on()]]的对立方法
     *
     * 注意：如果为事件名称传递了通配符模式，则只会删除使用此通配符注册的处理器，而将保留使用与此通配符匹配的普通名称注册的处理程序。
     *
     * @param string   $class   事件处理器附加的完整类名
     * @param string   $name    事件名
     * @param callable $handler 需要被移除的事件处理器,如果为null,附加在该事件上的所有事件处理器都将被移除
     *
     * @return bool 处理器是否被发现且被移除
     * @see on()
     */
    public static function off(string $class, string $name, callable $handler = null): bool
    {
        $class = ltrim($class, '\\');
        if (empty(self::$_events[$name][$class]) && empty(self::$_eventWildcards[$name][$class])) {
            return false;
        }
        if ($handler === null) {
            unset(self::$_events[$name][$class]);
            unset(self::$_eventWildcards[$name][$class]);

            return true;
        }

        // plain event names
        if (isset(self::$_events[$name][$class])) {
            $removed = false;
            foreach (self::$_events[$name][$class] as $i => $event) {
                if ($event[0] === $handler) {
                    unset(self::$_events[$name][$class][$i]);
                    $removed = true;
                }
            }
            if ($removed) {
                self::$_events[$name][$class] = array_values(self::$_events[$name][$class]);

                return $removed;
            }
        }

        // wildcard event names
        $removed = false;
        if (isset(self::$_eventWildcards[$name][$class])) {
            foreach (self::$_eventWildcards[$name][$class] as $i => $event) {
                if ($event[0] === $handler) {
                    unset(self::$_eventWildcards[$name][$class][$i]);
                    $removed = true;
                }
            }
            if ($removed) {
                self::$_eventWildcards[$name][$class] = array_values(self::$_eventWildcards[$name][$class]);
                // remove empty wildcards to save future redundant regex checks :
                if (empty(self::$_eventWildcards[$name][$class])) {
                    unset(self::$_eventWildcards[$name][$class]);
                    if (empty(self::$_eventWildcards[$name])) {
                        unset(self::$_eventWildcards[$name]);
                    }
                }
            }
        }

        return $removed;
    }

    /**
     * 移除所有注册的类级事件处理器
     * @see   on()
     * @see   off()
     * @since 2.0.10
     */
    public static function offAll(): void
    {
        self::$_events = [];
        self::$_eventWildcards = [];
    }

    /**
     * 是否有事件处理器绑定在指定类级事件上
     * 注意该事件会检测父类。
     *
     * @param string|object $class 对象或者指定类级事件的完整类名
     * @param string        $name  事件名
     *
     * @return bool whether there is any handler attached to the event.
     */
    public static function hasHandlers($class, string $name): bool
    {
        if (empty(self::$_eventWildcards) && empty(self::$_events[$name])) {
            return false;
        }

        if (is_object($class)) {
            $class = get_class($class);
        } else {
            $class = ltrim($class, '\\');
        }

        $classes = array_merge([$class], class_parents($class, true), class_implements($class, true));

        // regular events
        foreach ($classes as $className) {
            if (!empty(self::$_events[$name][$className])) {
                return true;
            }
        }

        // wildcard events
        foreach (self::$_eventWildcards as $nameWildcard => $classHandlers) {
            if (!static::matchWildcard($nameWildcard, $name, ['escape' => false])) {
                continue;
            }
            foreach ($classHandlers as $classWildcard => $handlers) {
                if (empty($handlers)) {
                    continue;
                }
                foreach ($classes as $className) {
                    if (static::matchWildcard($classWildcard, $className, ['escape' => false])) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * 触发类级事件
     * 此方法将导致调用附加到指定类及其所有父类的命名事件的事件处理程序。
     *
     * @param string|object $class 对象或者指定类级事件的完整类名
     * @param string        $name  事件名
     * @param Event         $event 事件的参数,如果不设置则创建一个默认的[[Event]]对象
     */
    public static function trigger($class, string $name, Event $event = null)
    {
        $wildcardEventHandlers = [];
        foreach (self::$_eventWildcards as $nameWildcard => $classHandlers) {
            if (!static::matchWildcard($nameWildcard, $name)) {
                continue;
            }
            $wildcardEventHandlers = array_merge($wildcardEventHandlers, $classHandlers);
        }

        if (empty(self::$_events[$name]) && empty($wildcardEventHandlers)) {
            return;
        }

        if ($event === null) {
            $event = new static();
        }
        $event->handled = false;
        $event->name = $name;

        if (is_object($class)) {
            if ($event->sender === null) {
                $event->sender = $class;
            }
            $class = get_class($class);
        } else {
            $class = ltrim($class, '\\');
        }

        $classes = array_merge([$class], class_parents($class, true), class_implements($class, true));

        foreach ($classes as $class) {
            $eventHandlers = [];
            foreach ($wildcardEventHandlers as $classWildcard => $handlers) {
                if (static::matchWildcard($classWildcard, $class)) {
                    $eventHandlers = array_merge($eventHandlers, $handlers);
                    unset($wildcardEventHandlers[$classWildcard]);
                }
            }

            if (!empty(self::$_events[$name][$class])) {
                $eventHandlers = array_merge($eventHandlers, self::$_events[$name][$class]);
            }

            foreach ($eventHandlers as $handler) {
                $event->data = $handler[1];
                call_user_func($handler[0], $event);
                if ($event->handled) {
                    return;
                }
            }
        }
    }

    /**
     * @param string $pattern
     * @param string $string
     * @param array  $options
     *
     * @return bool
     */
    public static function matchWildcard(string $pattern, string $string, array $options = [])
    {
        if ($pattern === '*' && empty($options['filePath'])) {
            return true;
        }

        $replacements = [
            '\\\\\\\\' => '\\\\',
            '\\\\\\*'  => '[*]',
            '\\\\\\?'  => '[?]',
            '\*'       => '.*',
            '\?'       => '.',
            '\[\!'     => '[^',
            '\['       => '[',
            '\]'       => ']',
            '\-'       => '-',
        ];

        if (isset($options['escape']) && !$options['escape']) {
            unset($replacements['\\\\\\\\']);
            unset($replacements['\\\\\\*']);
            unset($replacements['\\\\\\?']);
        }

        if (!empty($options['filePath'])) {
            $replacements['\*'] = '[^/\\\\]*';
            $replacements['\?'] = '[^/\\\\]';
        }

        $pattern = strtr(preg_quote($pattern, '#'), $replacements);
        $pattern = '#^' . $pattern . '$#us';

        if (isset($options['caseSensitive']) && !$options['caseSensitive']) {
            $pattern .= 'i';
        }

        return preg_match($pattern, $string) === 1;
    }
}
