<?php
class Hevelop_LestifpcAsynccache_Model_AsyncCache_Cleaner extends Aoe_AsyncCache_Model_Cleaner {
    /**
     * Retrieve the cache frontend for the specified cache type.
     *
     * @param string $cacheType Such as 'cache' or 'full_page_cache'.
     * @return Varien_Cache_Core
     */
    protected function getCacheByType($cacheType)
    {
        if ($cacheType === 'fpc') {
            $fpcModel = Mage::getSingleton('fpc/fpc');
            if ($fpcModel && $fpcModel->getFrontend()) {
                return $fpcModel->getFrontend();
            }
        }
        return parent::getCacheByType($cacheType);
    }
}