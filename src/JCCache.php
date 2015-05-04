<?php

/**
 * Class JCCache
 */
define ('JCCACHE_ONLYRAW', 1);
define ('JCCACHE_ADDRAW', 2);
define ('JCCACHE_FROMRAW', 4);

class JCCache
{
    /**
     * массив с текущими тегами для кеша, сбрасывается после работы с кешем
     * @var array
     */
    private $tags = array();

    /**
     * текущее пространство имен для кеша
     * @var string
     */
    private $namespace = '';

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
    private $hosts = array();

    /**
     * если true - то выводит логи в поток
     * @var bool
     */
    private $loging = false;

    /**
     * @param array $hosts массив с хостами для подключания
     * @param string $namespace пространство имен
     */
    public function __construct($hosts = array(), $namespace = '')
    {
        $this->hosts = $this->checkHosts(
            $hosts,
            array('host' => '127.0.0.1', 'port' => 11211, 'persistent' => false)
        );
        $this->mc = new Memcache;
        $this->connected = true;
        foreach ($this->hosts as $h) {
            if (!$this->mc->addserver($h['host'], $h['port'], $h['persistent'])) {
                $this->connected = false;
            } else {
            }
        }
        if ($namespace != '') {
            $this->setNamespace($namespace);
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
            $hosts = array($def);
        }

        return $hosts;

    }

    /**
     * Записывает ключ в кеш
     * @param string $key имя ключа
     * @param mixed $value значение
     * @param int $ttl_seconds время жизни в секундах
     * @param int $flag дополнительные параметры. Используйте JCCACHE_FROMRAW чтобы удалить сырые данные из кеша, но с учетом $this->namespace
     * @return bool true если успешно, false если ошибка
     */
    public function set($key, $value, $ttl_seconds = 0, $flag = 0)
    {
        $result = false;

        if (($flag & JCCACHE_ADDRAW) > 0 || ($flag & JCCACHE_ONLYRAW) > 0) {
            $result = $this->mc->set($this->getNamespace() . $key, $value, 0, $ttl_seconds);

        }

        if (($flag & JCCACHE_ONLYRAW) == 0) {

            $key = $this->prepareNamespace($key);
            $value = array(
                'data' => $value,
                'tags' => $this->getTagsState($this->tags),
            );


            $result = $this->mc->set($key, $value, 0, $ttl_seconds);
        }

        $this->resetTags();

        return $result;

    }

    /**
     * Получает ключ из кеша
     * @param array|string $key имя ключа
     * @param int $flag дополнительные параметры. Используйте JCCACHE_FROMRAW чтобы получить сырые данные из кеша, но с учетом $this->namespace
     * @return array|string значение ключа, или ассоциативный массив, если был запрос по нескольким ключам. В этом случае в качестве индексов массива являются ключи из запроса.
     */
    public function get($key, $flag = 0)
    {

        if (($flag & JCCACHE_FROMRAW) > 0) {
            $result = $data = $this->mc->get($this->getNamespace() . $key);

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
        $result = array();

        if (!is_array($tags)) {
            $tags = array($tags);
        }
        //$this->log(array($tags, $this->prepareRawNameTags($tags)), 'Проверяем');

        if (count($tags) > 0) {
            //$arTags = array();
            // 1. получаем список тегов из кеша
            $current_in_cache = $this->mc->get($this->prepareRawNameTags($tags));

            //$this->log($current_in_cache, 'В кеше');

            // 2. проверяем на недостабщие
            $not_present = array_diff($this->prepareRawNameTags($tags), array_keys($current_in_cache));
            //$this->log($not_present, 'Не хватает тегов');
            // 2.1. записываем недостающие к кеш
            if (count($not_present) > 0) {
                foreach ($not_present as $v) {
                    $time = $this->setTags(array_search($v, $this->prepareRawNameTags($tags)));
                    $current_in_cache[$v] = $time;
                }
            }

            // 3. формируем результирующий массив
            foreach ($this->prepareRawNameTags($tags) as $k => $v) {
                $result[$k] = $current_in_cache[$v];
            }


        }

        //$this->log($result, 'Возвращаем');

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
            $tags = array($tags);
        }

        $time = microtime(true);
        if (count($tags) > 0) {
            foreach ($tags as $tag) {
                $this->log('- ' . $tag);
                $this->mc->set($this->prepareRawNameTags($tag), $time, 0, 0);

            }
        }

        return $time;

    }

    /**
     * Удаляет ключ из кеша
     * @param string $key имя ключа
     * @param int $ttl_seconds
     * @param int $flag дополнительные параметры. Используйте JCCACHE_FROMRAW чтобы удалить сырые данные из кеша, но с учетом $this->namespace
     * @return bool
     */

    public function rm($key, $ttl_seconds = 0, $flag = 0)
    {
        if (($flag & JCCACHE_FROMRAW) > 0) {
            $key = $this->prepareNamespace($this->getNamespace() . $key);
        } else {
            $key = $this->prepareNamespace($key);
        }

        $this->resetTags();

        return $this->mc->delete($key, $ttl_seconds);

    }

    /**
     * Устанавливает теги для хранения
     * @param array $arTags массив с тегами
     * @return $this
     */
    public function addTags($arTags)
    {
        if ($arTags) {
            $this->tags = array_merge($this->tags, (is_array($arTags) ? $arTags : array($arTags)));
        }

        return $this;
    }

    /**
     * Сбрасывает массив с тегами после работы с кешем
     * @return $this
     */
    private function resetTags()
    {
        $this->tags = array();

        return $this;

    }

    /**
     * Устанавливает пространство имен для кеша
     * @param string $namespace пространство имен
     * @return $this
     */
    public function setNamespace($namespace)
    {
        $this->namespace = $namespace;

        return $this;

    }

    /**
     * Получает пространство имен для кеша
     * @return string пространство имен
     */
    public function getNamespace()
    {
        return $this->namespace;
    }

    /**
     * Подготавливает сырое имя ключа относительно пространства имен
     * @param array|string $key
     * @return array|string
     */
    private function prepareNamespace($key)
    {
        if ($this->namespace != '') {
            if (is_array($key) && count($key) > 0) {
                foreach ($key as $k => $v) {
                    $key[$k] = '[' . $this->namespace . ']::' . $v;
                }

            } else {
                $key = '[' . $this->namespace . ']::' . $key;

            }
        }

        return $key;

    }

    /**
     * Получает сырое имя ключа отсносительно пространства имен
     * @param array|string $key имена ключей|ключа
     * @return array|string
     */
    public function getRawNameKey($key)
    {
        return $this->prepareNamespace($key);

    }

    /**
     * Подготавливает сырое имя тега относительно пространства имен
     * @param array|string $tags имена тегов|тега
     * @return array|string
     */
    private function prepareRawNameTags($tags)
    {
        if (is_array($tags)) {
            $new_tags = array();
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
    public function getRawNameTag($tag)
    {
        return $this->prepareRawNameTags($tag);

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
        if ($this->loging) {
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
    public function setLoging($state)
    {
        $this->loging = (bool)$state;

        return $this;

    }

}