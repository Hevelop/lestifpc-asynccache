<?php

class Hevelop_LestifpcAsynccache_Model_Observer
{
    public static function convertCacheTagToLesti($original_tag)
    {
        $tag = false;
        if (strpos($original_tag, 'cms') !== false) {
            $tag = sha1(str_replace('cms_page', 'cms', $original_tag));
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
                            ->setMode('matchingAnyTag')
                            ->setTags(implode(',', $tags_chunk))
                            ->setCacheType('fpc')
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
}
