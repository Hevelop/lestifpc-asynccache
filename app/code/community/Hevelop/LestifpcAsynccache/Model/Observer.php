<?php

/**
 * Class Hevelop_LestifpcAsynccache_Model_Observer
 */
class Hevelop_LestifpcAsynccache_Model_Observer
{
    const CACHE_TYPE_CONFIG = 'config';
    const CACHE_TYPE_FPC = 'fpc';
    const CONFIG_CACHE_SERVICES_ROOT = 'redismanager/asynccache/';
    protected $_allBackends = [
        'fpc' => null,
        'config' => null
    ];

    public function getAllowedConfigType()
    {
        return [self::CACHE_TYPE_CONFIG, self::CACHE_TYPE_FPC];
    }

    /**
     * Returns the names of the services selected by backend
     * @param $cacheType
     * @return array
     */
    public function getRedisServicesSelected($cacheType)
    {
        return explode('::', Mage::getStoreConfig(self::CONFIG_CACHE_SERVICES_ROOT . $cacheType));
    }

    /**
     * Returns the names of the services selected by backend
     * @param $cacheType
     * @return array
     */
    public function getRedisServicesSelectedPrefix($cacheType)
    {
        return explode('::', Mage::getStoreConfig(self::CONFIG_CACHE_SERVICES_ROOT . $cacheType . '_prefix'));
    }

    public function getRedisServices($cacheType)
    {
        if (!in_array($cacheType, $this->getAllowedConfigType())) {
            $cacheType = self::CACHE_TYPE_CONFIG;
        }

        if (!is_null($this->_allBackends[$cacheType])) {
            return $this->_allBackends[$cacheType];
        }

        $this->_allBackends[$cacheType] = [];

        /** @var Steverobbins_Redismanager_Helper_Data $_helper */
        $_helper = Mage::helper('redismanager');
        if (!$_helper) {
            return $this->_allBackends;
        }
        $servicesData = $_helper->getServices();
        foreach ($servicesData as $serviceData) {
            $cacheConfigName = $cacheType == 'config' ? 'cache' : $cacheType;
            $serviceToFlush = array_search($serviceData['name'], $this->getRedisServicesSelected($cacheType));
            if ($serviceToFlush === false) {
                continue;
            }
            $prefixes = $this->getRedisServicesSelectedPrefix($cacheType);
            $prefix = (string) Mage::getConfig()->getNode('global/' . $cacheConfigName . '/prefix');
            if (!empty($prefixes[$serviceToFlush])) {
                $prefix = $prefixes[$serviceToFlush];
            }
            $service = new Mage_Core_Model_Cache([
                'id_prefix' => $prefix,
                'backend' => (string) Mage::getConfig()->getNode('global/' . $cacheConfigName . '/backend'),
                'backend_options' => [
                    'port' => $serviceData['port'],
                    'database' => $serviceData['db'],
                    'password' => $serviceData['password'],
                    'server' => $serviceData['host']
                ]
            ]);
            $this->_allBackends[$cacheType][] = $service;
        }
        return $this->_allBackends[$cacheType];
    }

    public static function convertCacheTagToLesti($original_tag)
    {
        $tag = false;
        if (strpos($original_tag, 'cms_page_') !== false) {
            $tag = sha1(str_replace('cms_page', 'cms', $original_tag));
        } else if (strpos($original_tag, 'cms_block_') !== false) {
            $tag = sha1(str_replace('cms_block', 'cmsblock', $original_tag));
        } else if (strpos($original_tag, 'catalog_product_') !== false) {
            $tag = sha1(str_replace('catalog_product', 'product', $original_tag));
        } else if (strpos($original_tag, 'catalog_category_') !== false) {
            $tag = sha1(str_replace('catalog_category', 'category', $original_tag));
        }
        return $tag;
    }

    public function addLestifpcTags($observer)
    {
        $useQueue = !Mage::registry('disableasynccache');
        $tags = $observer->getTags();
        if (!is_array($tags)) {
            $tags = explode(',', $tags);
        }
        if (!empty($tags)) {
            if ($useQueue) {
                $asyncCache = Mage::getModel('aoeasynccache/asynccache');
                if ($asyncCache !== false) {
                    $tags = array_filter(array_map(array('Hevelop_LestifpcAsynccache_Model_Observer', 'convertCacheTagToLesti'), $tags));
                    $tags_chunked = array_chunk($tags, 5);
                    foreach ($tags_chunked as $tags_chunk) {
                        $asyncCache->setTstamp(time())
                            ->setMode(Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG)
                            ->setTags(implode(',', $tags_chunk))
                            ->setCacheType(self::CACHE_TYPE_FPC)
                            ->setStatus(Aoe_AsyncCache_Model_Asynccache::STATUS_PENDING);
                        try {
                            $asyncCache->save();
                            return true;
                        } catch (Exception $e) {
                            // Table might not be created yet. Just go on without returning...
                        }
                    }
                }
            }
        }
    }

    /**
     * To cms page it also has to add the tag with the identifier
     * @param $observer
     */
    public function cmsPageSaveAfter($observer)
    {
        $useQueue = !Mage::registry('disableasynccache');
        if ($useQueue) {
            $asyncCache = Mage::getModel('aoeasynccache/asynccache');
            if ($asyncCache !== false) {
                $page = $observer->getEvent()->getObject();
                $asyncCache->setTstamp(time())
                    ->setMode(Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG)
                    ->setTags(sha1('cms_' . $page->getIdentifier()))
                    ->setCacheType(self::CACHE_TYPE_FPC)
                    ->setStatus(Aoe_AsyncCache_Model_Asynccache::STATUS_PENDING);
                try {
                    $asyncCache->save();
                    return true;
                } catch (Exception $e) {
                    // Table might not be created yet. Just go on without returning...
                }
            }
        }
    }

    /**
     * To cms page it also has to add the tag with the identifier
     * @param $observer
     */
    public function stockItemSaveAfter($observer)
    {
        $useQueue = !Mage::registry('disableasynccache');
        if ($useQueue) {
            $asyncCache = Mage::getModel('aoeasynccache/asynccache');
            if ($asyncCache !== false) {
                $item = $observer->getEvent()->getItem();
                if ($item->getStockStatusChangedAuto()) {
                    $asyncCache->setTstamp(time())
                        ->setMode(Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG)
                        ->setTags(sha1('product_' . $item->getProductId()))
                        ->setCacheType(self::CACHE_TYPE_FPC)
                        ->setStatus(Aoe_AsyncCache_Model_Asynccache::STATUS_PENDING);
                    try {
                        $asyncCache->save();
                        return true;
                    } catch (Exception $e) {
                        // Table might not be created yet. Just go on without returning...
                    }
                }
            }
        }
    }

    /**
     * On mass refresh also add lesti cache tag to invalidate all pages
     * @param $observer
     */
    public function cacheMassRefresh($observer)
    {
        $types = Mage::app()->getRequest()->getParam('types');
        $typesTags = [
            'fpc' => [sha1('cms'), 'FPC'],
            'block_html' => [sha1('cmsblock'), 'BLOCK_HTML']
        ];
        $useQueue = !Mage::registry('disableasynccache');
        if ($useQueue) {
            foreach ($types as $type) {
                if (!isset($typesTags[$type])) {
                    continue;
                }
                $asyncCache = Mage::getModel('aoeasynccache/asynccache');
                if ($asyncCache !== false) {
                    $asyncCache->setTstamp(time())
                        ->setMode(Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG)
                        ->setTags(implode(',', $typesTags[$type]))
                        ->setCacheType(self::CACHE_TYPE_FPC)
                        ->setStatus(Aoe_AsyncCache_Model_Asynccache::STATUS_PENDING);
                    try {
                        $asyncCache->save();
                        return true;
                    } catch (Exception $e) {
                        // Table might not be created yet. Just go on without returning...
                    }
                }
            }
        }
    }

    /**
     * If thereis redismanager, it also send the tags to all the cache instances.
     *
     * @param Mage_Core_Model_Observer|Varien_Event_Observer $observer
     */
    public function postProcessJobCollection(Varien_Event_Observer $observer)
    {
        /** @var $jobCollection Aoe_AsyncCache_Model_JobCollection */
        $jobCollection = $observer->getData('jobCollection');
        if (!$jobCollection) {
            return;
        }
        foreach ($jobCollection as $job) {
            /** @var $job Aoe_AsyncCache_Model_Job */
            if (in_array($job->getCacheType(), $this->getAllowedConfigType())) {
                try {
                    $tags = $job->getTags();
                    if (in_array('FPC', $tags) || in_array('BLOCK_HTML', $tags)) {
                        $tags = [];
                    }
                    $services = $this->getRedisServices($job->getCacheType());
                    if (!empty($services)) {
                        foreach ($services as $service) {
                            /** @var $service Mage_Core_Model_Cache */
                            $service->clean($tags);
                        }
                        Mage::log(sprintf('[LESTIFPC-ASYNCCACHE] MODE: %s, DURATION: %s sec, TAGS: %s',
                            $job->getMode(),
                            $job->getDuration(),
                            implode(',', $tags)
                        ));
                    }
                } catch (Exception $e) {
                    Mage::log(sprintf('[LESTIFPC-ASYNCCACHE] ERROR: %s, TAGS: %s', $e->getMessage(), implode(',', $tags)));
                }
            }
        }
    }
}
