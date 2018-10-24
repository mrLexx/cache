<?php

use PHPUnit\Framework\TestCase;

final class CacheTest extends TestCase
{
    public function testCompleteHostsParam()
    {
        $jccache = new \JustCommunication\Cache(
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
            $jccache->getHosts()
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

        $jccache = new \JustCommunication\Cache(array(array('host' => 'localhost'),), $namespace);

        $jccache->set($key, $val);

        $stack[] = array($val, $jccache->get($key));

        $jccache->set($key_null, null);
        $jccache->set($key_false, false);
        $jccache->set($key_emstring, '');

        $stack[] = array(null, $jccache->get($key_null));
        $stack[] = array(false, $jccache->get($key_false));
        $stack[] = array('', $jccache->get($key_emstring));
        $stack[] = array(false, $jccache->get($key_empty));

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

        $jccache01 = new \JustCommunication\Cache(array(array('host' => 'localhost'),));
        $jccache02 = new \JustCommunication\Cache(array(array('host' => 'localhost'),));

        $jccache01->set($key, $val01);
        $stack[] = array($val01, $jccache01->get($key));
        $jccache02->set($key, $val02);
        $stack[] = array($val02, $jccache02->get($key));
        $stack[] = array($val02, $jccache01->get($key));

        $jccache01->setNamespace($namespace01);
        $jccache02->setNamespace($namespace02);

        $jccache01->set($key, $val01);
        $stack[] = array($val01, $jccache01->get($key));
        $jccache02->set($key, $val02);
        $stack[] = array($val02, $jccache02->get($key));
        $stack[] = array($val01, $jccache01->get($key));

        $jccache01->rm($key);
        $stack[] = array(false, $jccache01->get($key));
        $stack[] = array($val02, $jccache02->get($key));

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

        $jccache = new \JustCommunication\Cache(array(array('host' => 'localhost'),), $namespace);


        $jccache->addTags(array($arTags[0]))
            ->set($arKeys[0], $arVals[0]);
        $jccache->addTags(array($arTags[1]))
            ->set($arKeys[1], $arVals[1]);
        $jccache->addTags(array($arTags[0], $arTags[1], $arTags[2]))
            ->set($arKeys[2], $arVals[2]);


        $jccache->rmTags($arTags[1]);

        $jccache->get($arKeys[2]);
        $stack[] = array($arVals[0], $jccache->get($arKeys[0]));
        $stack[] = array(false, $jccache->get($arKeys[1]));
        $stack[] = array(false, $jccache->get($arKeys[2]));

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