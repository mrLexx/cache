<?php

namespace JustCommunication;

use Memcache;


class Cache
{
    const CACHE_ONLYRAW = 1;
    const CACHE_ADDRAW = 2;
    const CACHE_FROMRAW = 4;

    /**
     * массив с текущими тегами для кеша, сбрасывается после работы с кешем
     * @var array
     */
    private $tags = [];

    /**
     * текущее пространство имен для кеша
     * @var string
     */
    private $namespace = '';
    private $rawNamespace = '';

    /**
     * текущее пространство имен для кеша
     * @var string
     */
    private $protectedNamespace = 'just-communication/cache';

    /**
     * состояние коннекта к кешу
     * @var bool
     */
    private $connected = false;

    /**
     * @var Memcache
     */
    private $mc;

    /**
     * список хостов с кешем
     * @var array
     */
    private $hosts = [];

    /**
     * если true - то выводит логи в поток
     * @var bool
     */
    private $logging = false;

    /**
     * @param array $hosts = [
     *     ['host' => 'localhost', 'port' => 11211, 'persistent' => false],
     * ] массив с хостами для подключания
     * @param string $namespace пространство имен
     * @param string $rawNamespace
     */
    public function __construct($hosts = [], $namespace = '', $rawNamespace = '')
    {
        $this->hosts = $this->checkHosts(
            $hosts,
            ['host' => '127.0.0.1', 'port' => 11211, 'persistent' => false]
        );
        $this->mc = new Memcache();
        $this->connected = true;
        foreach ($this->hosts as $h) {
            if (!$this->mc->addserver($h['host'], $h['port'], $h['persistent'])) {
                $this->connected = false;
            }
        }
        if ($namespace != '') {
            $this->setNamespace($namespace);
        } else {
            $this->setNamespace('');
        }

        if ($rawNamespace != '') {
            $this->setRawNamespace($rawNamespace);
        } else {
            $this->setRawNamespace('');
        }

    }

    /**
     * Получает список хостов $this->hosts
     * @return array список текущих хостов кеша
     */
    public function getHosts()
    {
        return $this->hosts;

    }

    /**
     * Проверяет массив с хостами и дополняет недостающими параметрами из массива по умолчанию
     * @param array $hosts хосты для установки
     * @param array $def хосты по умолчанию
     * @return array дополненный список хостов
     */
    private function checkHosts($hosts, $def)
    {
        if (is_array($hosts) && count($hosts) > 0) {
            foreach ($hosts as $id => $host) {
                foreach ($def as $def_key => $def_val) {
                    if (!isset($hosts[$id][$def_key])) {
                        $hosts[$id][$def_key] = $def_val;
                    }
                }
            }

        } else {
            $hosts = [$def];
        }

        return $hosts;

    }

    /**
     * Записывает ключ в кеш
     * @param string $key имя ключа
     * @param mixed $value значение
     * @param int $ttl_seconds время жизни в секундах
     * @param int $flag дополнительные параметры.
     * @return bool true если успешно, false если ошибка
     */
    public function set($key, $value, $ttl_seconds = 0, $flag = 0)
    {
        $result = false;

        if (($flag & self::CACHE_ADDRAW) > 0 || ($flag & self::CACHE_ONLYRAW) > 0) {
            $result = $this->mc->set($this->prepareRawNamespace($key), $value, 0, $ttl_seconds);
        }

        if (($flag & self::CACHE_ONLYRAW) == 0) {

            $key = $this->prepareNamespace($key);
            $value = [
                'data' => $value,
                'tags' => $this->getTagsState($this->tags),
            ];

            $result = $this->mc->set($key, $value, 0, $ttl_seconds);
        }

        $this->resetTags();

        return $result;

    }

    /**
     * Получает ключ из кеша
     * @param array|string $key имя ключа
     * @param int $flag дополнительные параметры. Используйте CACHE_FROMRAW чтобы получить сырые данные из кеша, но с учетом $this->namespace
     * @return array|string значение ключа, или ассоциативный массив, если был запрос по нескольким ключам. В этом случае в качестве индексов массива являются ключи из запроса.
     */
    public function get($key, $flag = 0)
    {

        if (($flag & self::CACHE_FROMRAW) > 0) {
            $result = $data = $this->mc->get($this->prepareRawNamespace($key));

        } else {

            $key = $this->prepareNamespace($key);
            $this->resetTags();

            $data = $this->mc->get($key);

            if ($data !== false && is_array($data) && array_key_exists('data', $data)) {
                $result = $data['data'];
                if (isset($data['tags']) && count($data['tags']) > 0) {
                    // теги в кеше
                    $this->log($data['tags'], 'Tags in Cache');
                    //текущее состояние тегов из кеша
                    $this->log($this->getTagsState(array_keys($data['tags'])), 'State of Tags');
                    if (count(array_diff_assoc($data['tags'], $this->getTagsState(array_keys($data['tags'])))) > 0) {
                        $result = false;
                    }
                }

            } else {
                $result = false;
            }

            $this->resetTags();

        }

        return $result;
    }

    /**
     * Получает состояние тегов в кеше. Недостающие добавляет.
     * @param array|string $tags список тегов
     * @return array|string массив состояний тегов. В качестве индекса - имена тегов.
     */
    public function getTagsState($tags)
    {
        $result = [];

        if (!is_array($tags)) {
            $tags = [$tags];
        }

        if (count($tags) > 0) {
            //$arTags = array();
            // 1. получаем список тегов из кеша
            $current_in_cache = $this->mc->get($this->prepareNameTags($tags));

            // 2. проверяем на недостабщие
            $not_present = array_diff($this->prepareNameTags($tags), array_keys($current_in_cache));
            // 2.1. записываем недостающие к кеш
            if (count($not_present) > 0) {
                foreach ($not_present as $v) {
                    $time = $this->setTags(array_search($v, $this->prepareNameTags($tags)));
                    $current_in_cache[$v] = $time;
                }
            }

            // 3. формируем результирующий массив
            foreach ($this->prepareNameTags($tags) as $k => $v) {
                $result[$k] = $current_in_cache[$v];
            }


        }

        return $result;
    }

    /**
     * "Удаляет" теги из кеша
     * @param array|string $tags список тегов
     */
    public function rmTags($tags)
    {
        $this->setTags($tags);

    }

    /**
     * Устанавливает теги в кеш
     * @param array|string $tags список тегов
     * @return float установленное значение состояния для тегов
     */
    private function setTags($tags)
    {

        if (!is_array($tags)) {
            $tags = [$tags];
        }

        $time = microtime(true);
        if (count($tags) > 0) {
            foreach ($tags as $tag) {
                $this->log('- ' . $tag);
                $this->mc->set($this->prepareNameTags($tag), $time, 0, 0);

            }
        }

        return $time;

    }

    /**
     * Удаляет ключ из кеша
     * @param string $key имя ключа
     * @param int $flag дополнительные параметры. Используйте CACHE_ONLYRAW|CACHE_FROMRAW чтобы удалить сырые данные только/также из сырого кеша, но с учетом $this->rawNamespace
     * @return bool
     */

    public function rm($key, $flag = 0)
    {

        if (($flag & self::CACHE_ONLYRAW) > 0) {
            $return = $this->mc->delete($this->prepareRawNamespace($key));
        } else {

            $return = $this->mc->delete($this->prepareNamespace($key));
            if ($return && ($flag & self::CACHE_FROMRAW) > 0) {
                $return = $this->mc->delete($this->prepareRawNamespace($key));
            }
        }

        $this->resetTags();

        return $return;

    }

    /**
     * Устанавливает теги для хранения
     * @param array $arTags массив с тегами
     * @return $this
     */
    public function addTags($arTags)
    {
        if ($arTags) {
            $this->tags = array_merge($this->tags, (is_array($arTags) ? $arTags : [$arTags]));
        }

        return $this;
    }

    /**
     * Сбрасывает массив с тегами после работы с кешем
     * @return $this
     */
    private function resetTags()
    {
        $this->tags = [];

        return $this;

    }

    /**
     * Устанавливает пространство имен для кеша
     * @param string $namespace пространство имен
     * @return $this
     */
    public function setNamespace($namespace)
    {
        $this->namespace = ($namespace != '' ? ':' . $namespace : '');

        return $this;

    }

    /**
     * Устанавливает пространство имен для кеша
     * @param string $rawNamespace пространство имен
     * @return $this
     */
    public function setRawNamespace($rawNamespace)
    {
        $this->rawNamespace = ($rawNamespace != '' ? ':' . $rawNamespace : '');

        return $this;

    }

    /**
     * Получает пространство имен для кеша
     * @return string пространство имен
     */
    public function getRawNamespace()
    {
        return ($this->rawNamespace != '' ? ':' . $this->rawNamespace : '');
    }

    /**
     * Получает пространство имен для кеша
     * @return string пространство имен
     */
    public function getNamespace()
    {
        return $this->protectedNamespace . ($this->namespace != '' ? ':' . $this->namespace : '');
    }

    /**
     * Подготавливает сырое имя ключа относительно пространства имен
     * @param array|string $key
     * @return array|string
     */
    private function prepareNamespace($key)
    {
        if ($this->getNamespace() != '') {
            if (is_array($key) && count($key) > 0) {
                foreach ($key as $k => $v) {
                    $key[$k] = '[' . $this->getNamespace() . ']::' . $v;
                }

            } else {
                $key = '[' . $this->getNamespace() . ']::' . $key;

            }
        }

        return $key;

    }

    /**
     * Подготавливает сырое имя ключа относительно пространства имен
     * @param array|string $key
     * @return array|string
     */
    private function prepareRawNamespace($key)
    {
        if ($this->getRawNamespace() != '') {
            if (is_array($key) && count($key) > 0) {
                foreach ($key as $k => $v) {
                    $key[$k] = $this->getRawNamespace() . $v;
                }

            } else {
                $key = $this->getRawNamespace() . $key;

            }
        }

        return $key;

    }

    /**
     * Получает сырое имя ключа отсносительно пространства имен
     * @param array|string $key имена ключей|ключа
     * @return array|string
     */
    public function getNameKey($key)
    {
        return $this->prepareNamespace($key);

    }

    /**
     * Подготавливает сырое имя тега относительно пространства имен
     * @param array|string $tags имена тегов|тега
     * @return array|string
     */
    private function prepareNameTags($tags)
    {
        if (is_array($tags)) {
            $new_tags = [];
            if (count($tags) > 0) {
                foreach ($tags as $k => $v) {
                    $new_tags[$v] = '{tags_' . $v . '}';
                }
            }

        } else {
            $new_tags = '{tags_' . (string)$tags . '}';
        }

        $new_tags = $this->prepareNamespace($new_tags);

        return $new_tags;

    }

    /**
     * Получает сырое имя тега отсносительно пространства имен
     * @param array|string $tag имена тегов|тега
     * @return array|string
     */
    public function getNameTag($tag)
    {
        return $this->prepareNameTags($tag);

    }

    /**
     * Получает статус коннекта к кеш серверу
     * @return bool
     */
    public function getConnected()
    {
        return $this->connected;
    }

    /**
     * Выводит лог в текущий поток
     * @param mixed $var объект|значение для вывода
     * @param string $title "заголовок"
     */
    private function log($var, $title = '')
    {
        if ($this->logging) {
            if ($title != '') {
                print_r($title . ":\r\n");
            }

            if (is_array($var)) {
                print_r("(\r\n");
                foreach ($var as $k => $v) {
                    if (is_array($v)) {
                        print_r("\t(\r\n");
                        foreach ($v as $k2 => $v2) {
                            print_r("\t\t[" . $k2 . "] => " . $v2 . "\r\n");
                        }
                        print_r("\t)\r\n");

                    } else {
                        print_r("\t[" . $k . "] => " . $v . "\r\n");
                    }
                }
                print_r(")\r\n");

            } else {
                print_r($var);
                echo "\r\n";
            }
        }
    }

    /**
     * Включает/Отключает режим лога
     * @param boolean $state
     * @return $this
     */
    public function setLogging($state)
    {
        $this->logging = (bool)$state;

        return $this;

    }

}