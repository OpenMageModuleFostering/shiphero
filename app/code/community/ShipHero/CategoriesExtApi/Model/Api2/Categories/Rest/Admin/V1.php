<?php

class ShipHero_CategoriesExtApi_Model_Api2_Categories_Rest_Admin_V1 extends ShipHero_CategoriesExtApi_Model_Api2_Categories
{
    protected function _retrieveCollection()
    {
        $attributes = Mage::getResourceModel('catalog/product_attribute_collection')->addFieldToFilter('is_visible_on_front', 1);
        $attributeArray = array();

        foreach($attributes as $attribute){
            $attributeArray[] = array(
                'label' => $attribute->getData('frontend_label'),
                'value' => $attribute->getData('attribute_code')
            );
        }
        return $attributeArray;
    }
}