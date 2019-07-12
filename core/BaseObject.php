<?php

namespace yay\core;

/**
 * `BaseObject`类是实现*属性*特性的基类
 *
 * 属性可以通过getter(例如:`getLabel`)，或者setter方法(例如:`setLabel`)进行访问。
 * 下面的例子就可以通过getter/setter定义`label`属性
 * ```php
 * private $_label;
 *
 * public function getLabel()
 * {
 *     return $this->_label;
 * }
 *
 * public function setLabel($value)
 * {
 *     $this->_label = $value;
 * }
 * ```
 * 属性名是大小写不敏感的
 *
 * 属性可以通过类似成员变量的方式进行访问。读写属性将会调用对应的__get/__set魔术方法。例如:
 * ```php
 * // equivalent to $label = $object->getLabel();
 * $label = $object->label;
 * // equivalent to $object->setLabel('abc');
 * $object->label = 'abc';
 * ```
 * 如果一个属性只有getter方法没有setter方法，则认为它是*只读*属性。因此如果试图修改这个*只读*属性，将会导致抛出异常。
 * 当然你也可以通过[[hasProperty()]],[[canGetProperty()]]或者[[canSetProperty()]]来检测属性。
 *
 * 除了属性特性外，`BaseObject`还提供了一个重要的对象初始化生命周期。创建`BaseObject`或其派生类的新实例将依次涉及以下生命周期：
 *
 * 1. 调用构造函数
 * 2. 通过给到的配置进行属性的初始化
 * 3. 调用`init`方法
 *
 * 上述的第2步与第3步都会发生在构造函数之后，因此，建议在`init()`方法执行对象初始化，因为这时候对象配置已经应用。
 *
 * 为了确保上述的生命周期，`BaseObject`的子类比较重写构造函数,示例如下:
 *
 * ```php
 * public function __construct($param1, $param2, ..., $config = [])
 * {
 *     ...
 *     parent::__construct($config);
 * }
 * ```
 * `$config`参数(默认`[]`)必须声明到构造器的最后一个参数，并且父类的实现必须在子类的构造器尾部调用。
 *
 * @since  1.0
 */
class BaseObject
{

    /**
     * @param Object $object
     * @param array  $properties
     *
     * @return Object
     */
    public static function configure(Object $object, array $properties): Object
    {
        foreach ($properties as $name => $value) {
            $object->$name = $value;
        }

        return $object;
    }


    /**
     * 构造器.
     *
     * 默认的实现做了两件事:
     *
     * - 通过` $config` 初始化对象属性
     * - 调用 [[init()]]方法
     *
     * 如果该方法被子类重写，有两点建议:
     *
     * - 构造函数的最后一个参数必须是$config
     * - 在构造器尾部调用父类实现
     *
     * @param array $config 健-值对,用来初始化对象属性
     *
     * @return void
     * @see init()
     */
    public function __construct(array $config = [])
    {
        if (!empty($config)) {
            static::configure($this, $config);
        }
        $this->init();
    }

    /**
     * 初始化对象
     *
     * 这个方法在构造器的最后被调用
     */
    public function init(): void
    {
    }

    /**
     * 返回属性值
     *
     * 不要直接调用PHP的魔术方法,它将会在`$value = $object->property;`时被调用
     *
     * @param string $name 属性名
     *
     * @return mixed 属性值
     * @throws UnknownPropertyException 如果属性未定义
     * @throws InvalidCallException 如果属性只写
     * @see __set()
     */
    public function __get(string $name)
    {
        $getter = 'get' . $name;
        if (method_exists($this, $getter)) {
            return $this->$getter();
        } else {
            if (method_exists($this, 'set' . $name)) {
                throw new InvalidCallException('Getting write-only property: ' . get_class($this) . '::' . $name);
            }
        }

        throw new UnknownPropertyException('Getting unknown property: ' . get_class($this) . '::' . $name);
    }

    /**
     * 设置属性值
     *
     * 不要直接调用PHP的魔术方法,它将会在`$object->property = $value;`时被调用
     *
     * @param string $name  属性名
     * @param mixed  $value 属性值
     *
     * @throws UnknownPropertyException 如果属性未定义
     * @throws InvalidCallException 如果属性只读
     * @see __get()
     */
    public function __set(string $name, $value)
    {
        $setter = 'set' . $name;
        if (method_exists($this, $setter)) {
            $this->$setter($value);
        } else {
            if (method_exists($this, 'get' . $name)) {
                throw new InvalidCallException('Setting read-only property: ' . get_class($this) . '::' . $name);
            } else {
                throw new UnknownPropertyException('Setting unknown property: ' . get_class($this) . '::' . $name);
            }
        }
    }

    /**
     * 判断属性是否被设置，例如被定义了且不为`null`
     *
     * 不要直接调用PHP的魔术方法,它将会在`isset($object->property)`时被调用
     *
     * 注意，如果属性未定义，它将返回false
     *
     * @param string $name 属性名或者事件名
     *
     * @return bool 属性是否被设置且不为null
     * @see https://secure.php.net/manual/en/function.isset.php
     */
    public function __isset(string $name)
    {
        $getter = 'get' . $name;
        if (method_exists($this, $getter)) {
            return $this->$getter() !== null;
        }

        return false;
    }

    /**
     * 设置属性为空
     *
     * 不要直接调用PHP的魔术方法,它将会在`unset($object->property)`时被调用
     *
     * 注意，如果属性未定义，这个方法什么也不会干。如果属性只读，将会抛出异常
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
        } else {
            if (method_exists($this, 'get' . $name)) {
                throw new InvalidCallException('Unsetting read-only property: ' . get_class($this) . '::' . $name);
            }
        }
    }

    /**
     * 调用非成员方法时被调用
     * 不要直接调用PHP的魔术方法,当调用一个不存在的成员方法时被调用
     *
     * @param string $name   方法名
     * @param array  $params 方法参数
     *
     * @return mixed 方法返回值
     * @throws UnknownMethodException 方法不存在
     */
    public function __call(string $name, array $params)
    {
        throw new UnknownMethodException('Calling unknown method: ' . get_class($this) . "::$name()");
    }

    /**
     * 判断属性是否被定义
     *
     * 判断一个属性定义的几个条件
     *
     * - 可以通过getter/setter访问到(这时候属性名是大小不敏感的)
     * - 存在该名称的成员变量(当`checkVars`为`true`)
     *
     * @param string $name      属性名
     * @param bool   $checkVars 是否将成员变量视为属性
     *
     * @return bool  属性是否存在
     * @see canGetProperty()
     * @see canSetProperty()
     */
    public function hasProperty(string $name, bool $checkVars = true): bool
    {
        return $this->canGetProperty($name, $checkVars) || $this->canSetProperty($name, false);
    }

    /**
     * 判断属性是否可读
     *
     * 判断一个属性可读的几个条件
     *
     * - 可以通过getter访问到(这时候属性名是大小不敏感的)
     * - 存在该名称的成员变量(当`checkVars`为`true`)
     *
     * @param string $name      属性名
     * @param bool   $checkVars 是否将成员变量视为属性
     *
     * @return bool 属性是否可读
     * @see canSetProperty()
     */
    public function canGetProperty(string $name, bool $checkVars = true): bool
    {
        return method_exists($this, 'get' . $name) || $checkVars && property_exists($this, $name);
    }

    /**
     * 判断属性是否可写
     *
     * 判断一个属性可写的几个条件
     *
     * - 可以通过setter访问到(这时候属性名是大小不敏感的)
     * - 存在该名称的成员变量(当`checkVars`为`true`)
     *
     * @param string $name      属性名
     * @param bool   $checkVars 是否将成员变量视为属性
     *
     * @return bool 属性是否可写
     * @see canGetProperty()
     */
    public function canSetProperty(string $name, bool $checkVars = true): bool
    {
        return method_exists($this, 'set' . $name) || $checkVars && property_exists($this, $name);
    }

    /**
     * 判断方法是否存在
     *
     * 默认的实现是通过调用php函数`method_exists()`
     * 你可以重写这个方法,当你想要实现`__call()`
     *
     * @param string $name 方法名
     *
     * @return bool 方法是否存在
     */
    public function hasMethod(string $name): bool
    {
        return method_exists($this, $name);
    }
}
