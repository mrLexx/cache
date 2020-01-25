<?php

use JustCommunication\Cache;
use PHPUnit\Framework\TestCase;

final class CacheTest extends TestCase
{
    public function testCompleteHostsParam()
    {
        $cache = new Cache(
            array(
                array('host' => 'localhost', 'persistent' => true),
                array('port' => 11211, 'host' => 'localhost'),
            )
        );

        $this->assertEquals(
            array(
                array('host' => 'localhost', 'persistent' => true, 'port' => 11211),
                array('port' => 11211, 'host' => 'localhost', 'persistent' => false),
            ),
            $cache->getHosts()
        );
    }

    /**
     * @dataProvider providerCache
     * @param $a
     * @param $b
     */
    public function testCache($a, $b)
    {
        $this->assertEquals($a, $b);
    }

    public function providerCache()
    {
        $stack = array();

        $key = $this->randomString();
        $val = $this->randomString();
        $key_null = $this->randomString();
        $key_false = $this->randomString();
        $key_emstring = $this->randomString();
        $key_empty = $this->randomString();
        $namespace = 'testJCCache' . date("Y-m-d H:i:s u") . $this->randomString();

        $cache = new Cache(array(array('host' => 'localhost'),), $namespace);

        $cache->set($key, $val);

        $stack[] = array($val, $cache->get($key));

        $cache->set($key_null, null);
        $cache->set($key_false, false);
        $cache->set($key_emstring, '');

        $stack[] = array(null, $cache->get($key_null));
        $stack[] = array(false, $cache->get($key_false));
        $stack[] = array('', $cache->get($key_emstring));
        $stack[] = array(false, $cache->get($key_empty));

        return $stack;
    }

    /**
     * @dataProvider providerNamespaces
     * @param $a
     * @param $b
     */
    public function testNamespaces($a, $b)
    {
        $this->assertEquals($a, $b);
    }

    public function providerNamespaces()
    {
        $stack = array();
        $key = $this->randomString();

        $val01 = $this->randomString();
        $val02 = $this->randomString();
        $this->assertNotEquals($val01, $val02);

        $namespace01 = 'testJCCache' . date("Y-m-d H:i:s u") . $this->randomString();
        $namespace02 = 'testJCCache' . date("Y-m-d H:i:s u") . $this->randomString();

        $cache01 = new Cache(array(array('host' => 'localhost'),));
        $cache02 = new Cache(array(array('host' => 'localhost'),));

        $cache01->set($key, $val01);
        $stack[] = array($val01, $cache01->get($key));
        $cache02->set($key, $val02);
        $stack[] = array($val02, $cache02->get($key));
        $stack[] = array($val02, $cache01->get($key));

        $cache01->setNamespace($namespace01);
        $cache02->setNamespace($namespace02);

        $cache01->set($key, $val01);
        $stack[] = array($val01, $cache01->get($key));
        $cache02->set($key, $val02);
        $stack[] = array($val02, $cache02->get($key));
        $stack[] = array($val01, $cache01->get($key));

        $cache01->rm($key);
        $stack[] = array(false, $cache01->get($key));
        $stack[] = array($val02, $cache02->get($key));

        return $stack;

    }

    /**
     * @dataProvider providerTags
     * @param $a
     * @param $b
     */
    public function testTags($a, $b)
    {
        $this->assertEquals($a, $b);
    }

    public function providerTags()
    {
        $stack = array();

        $arKeys = array();
        $arVals = array();
        $arTags = array();
        for ($i = 0; $i < 5; $i++) {
            $arKeys[] = 'key0' . $i;
            $arVals[] = 'val0' . $i;
            $arTags[] = 'tag0' . $i;

        }
        $namespace = 'testJCCache' . time();

        $cache = new Cache(array(array('host' => 'localhost'),), $namespace);


        $cache->addTags(array($arTags[0]))
            ->set($arKeys[0], $arVals[0]);
        $cache->addTags(array($arTags[1]))
            ->set($arKeys[1], $arVals[1]);
        $cache->addTags(array($arTags[0], $arTags[1], $arTags[2]))
            ->set($arKeys[2], $arVals[2]);


        $cache->rmTags($arTags[1]);

        $cache->get($arKeys[2]);
        $stack[] = array($arVals[0], $cache->get($arKeys[0]));
        $stack[] = array(false, $cache->get($arKeys[1]));
        $stack[] = array(false, $cache->get($arKeys[2]));

        return $stack;
    }

    private function randomString($len = 10)
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $randstring = '';
        for ($i = 0; $i < $len; $i++) {
            mt_srand();
            $randstring .= $characters[mt_rand(0, strlen($characters) - 1)];
        }

        return $randstring;
    }
}