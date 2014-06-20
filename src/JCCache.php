<?php

/**
 * Class JCCache
 */
class JCCache
{
    /**
     * массив с тегами для кеша
     * @var array
     */
    private $tags = array();

    /**
     * пространство имен для кеша
     * @var string
     */
    private $namespace = '';

    private $connected = false;

    /**
     * @var Memcache
     */
    private $mc;

    private $hosts = array();

    /**
     * @param array $hosts массив с хостами для подключания
     * @param string $namespace пространство имен
     */
    public function __construct($hosts = array(), $namespace = '')
    {
        $this->hosts = $this->defaultHosts(
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

    public function getHosts()
    {
        return $this->hosts;

    }

    /**
     * @param array $hosts
     * @param array $def
     * @return array
     */
    private function defaultHosts($hosts, $def)
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
     * @param array|string $key
     * @return array|string
     */
    public function get($key)
    {
        $key = $this->prepareNameKey($key);
        $this->setDefaultTags();

        return $this->mc->get($key);
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param int $ttl
     * @return bool
     */
    public function set($key, $value, $ttl = 0)
    {
        $key = $this->prepareNameKey($key);
        $this->setToTags($key)->setDefaultTags();

        return $this->mc->set($key, $value, 0, $ttl);

    }

    public function rm($key, $ttl = 0)
    {
        $key = $this->prepareNameKey($key);
        $this->setDefaultTags();

        return $this->mc->delete($key, $ttl);
    }

    /**
     * Удаляет из кеша по тегам
     * @param $arTags
     */
    public function rmByTags($arTags)
    {
        $arTags = $this->prepareNameTags($arTags);
        //var_dump($arTags);

        $tagsWithKeys = $this->mc->get($arTags);
        //var_dump($tagsWithKeys);

        if(count($tagsWithKeys)==1){
            foreach($tagsWithKeys as $tagName=>$keys){
                //var_dump($tagName,$keys);
                foreach($keys as $key){
                    $this->mc->delete($key);
                }
                $this->mc->set($tagName,array(),0,0);
            }
        }
        $tagsWithKeys = $this->mc->get($arTags);
        //var_dump($tagsWithKeys);


    }

    /**
     * Устанавливает теги для хранения
     * @param array $arTags массив с тегами
     * @return $this
     */
    public function setTags($arTags)
    {
        if ($arTags) {
            $arTags = $this->prepareNameTags($arTags);
            $this->tags = array_merge($this->tags, (is_array($arTags) ? $arTags : array($arTags)));
        }

        return $this;
    }

    public function getKeysByTags($arTags)
    {
        $arTags = $this->prepareNameTags($arTags);

        $keys = $this->mc->get($arTags);

        return $keys;

    }


    private function setDefaultTags()
    {
        $this->tags = array();

        return $this;

    }

    private function setToTags($key)
    {
        if (count($this->tags) > 0) {
            $ar_tmp = $this->mc->get($this->tags);

            foreach ($this->tags as $tag) {
                if (!isset($ar_tmp[$tag]) || !in_array($key, $ar_tmp[$tag]))
                {
                    $ar_tmp[$tag][] = $key;
                }
                $this->mc->set($tag, $ar_tmp[$tag], 0, 0);
            }

            $ar_tmp = $this->mc->get($this->tags);
        }

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
     * Подготавливает ключ относительно пространства имен
     * @param array|string $key
     * @return array|string
     */
    private function prepareNameKey($key)
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

    public function getRawNameKey($key)
    {
        return $this->prepareNameKey($key);

    }

    public function getRawNameTag($tag)
    {
        return $this->prepareNameTags($tag);

    }

    private function prepareNameTags($tags)
    {
        if (is_array($tags) && count($tags) > 0) {
            foreach ($tags as $k => $v) {
                $tags[$k] = '{tags_' . $v.'}';
            }

        } elseif (!is_array($tags)) {
            $tags = array('{tags_' . (string)$tags.'}');
        }

        $tags = $this->prepareNameKey($tags);

        return $tags;

    }

    /**
     * Получает статус коннекта к кеш серверу
     * @return bool
     */
    public function getConnected()
    {
        return $this->connected;
    }


}