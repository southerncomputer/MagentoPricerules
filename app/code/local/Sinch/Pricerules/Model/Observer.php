<?php
/**
 * Observer Methods
 *
 * @author Stock in the Channel
 */
define('CatIDPrefix', 'CategoryID_');

class Sinch_Pricerules_Model_Observer {
	public function getFinalPrice(Varien_Event_Observer $observer){
		$product = $observer->getProduct();
		$rulesTable = Mage::getSingleton('core/resource')->getTableName('sinch_pricerules/pricerules');
		$queryParams = array();
		$originalPrice = $product->getPrice();
		$queryParams["originalPrice"] = $originalPrice;
		$queryParams["productId"] = $product->getId();
		$queryParams["manufacturer"] = $product->getManufacturer();
		$custSession = Mage::getSingleton('customer/session');
		$queryParams["customerGroup"] = ($custSession->isLoggedIn() ? $custSession->getCustomer()->getSinchPricerulesGroup() : 0);
		$catParamNames = array();
		foreach($product->getCategoryIds() as $index => $id){
			$catParamNames[] = ":" . CatIDPrefix . $index;
			$queryParams[CatIDPrefix . $index] = $id;
		}
		$dbRead = Mage::getSingleton('core/resource')->getConnection('core_read');
		$query = "SELECT markup_percentage, markup_price, absolute_price FROM " . $rulesTable . " WHERE
			( price_from <= :originalPrice AND price_to >= :originalPrice ) AND
			( category_id IS NULL OR 
			  category_id IN ( " . implode(", ", $catParamNames) . " ) ) AND
			( product_id IS NULL OR
			  product_id = :productId ) AND
			( brand_id IS NULL OR
			  brand_id = :manufacturer ) AND
			customer_group_id = :customerGroup
			ORDER BY execution_order ASC
			LIMIT 1
		";
		$relevantRules = $dbRead->query($query, $queryParams);
		$rule = $relevantRules->fetch();
		if(!$rule) return $this;
		if($rule["markup_percentage"]){
			$newPrice = $originalPrice + ($originalPrice * ($rule["markup_percentage"] / 100));
		} elseif($rule["markup_price"]){
			$newPrice = $originalPrice + $rule["markup_price"];
		} elseif($rule["absolute_price"]){
			$newPrice = $rule["absolute_price"];
		} else {
				Mage::log("A Severe Pricerules Error Occurred");
				throw new Exception("Retrieved pricing rule not valid. Missing result action");
		}
		//Set all the Prices to prevent Magento revealing the original price in any of its "As low as *price*" blocks
		$product->setMinPrice($newPrice);
		$product->setMinimalPrice($newPrice);
		$product->setMaxPrice($newPrice);
		$product->setTierPrice($newPrice);
		$product->setFinalPrice($newPrice);
		return $this;
	}
	
	public function ListCollectionPrice(Varien_Event_Observer $observer){
		$collection = $observer->getCollection();
		foreach($collection as $product){
			Mage::dispatchEvent('catalog_product_get_final_price', array('product' => $product, 'qty' => 1));
		}
	}
	
	public function ImportPriceRules(Varien_Event_Observer $observer){
		$ruleFile = $observer->getRuleFile();
        $groupFile = $observer->getGroupFile();
		$terminate_char = $observer->getSeperator();
		$importTable = Mage::getSingleton('core/resource')->getTableName('sinch_pricerules/import');
		$prGroupTable = Mage::getSingleton('core/resource')->getTableName('sinch_pricerules/group');
		$rulesTable = Mage::getSingleton('core/resource')->getTableName('sinch_pricerules/pricerules');
		$dbWrite = Mage::getSingleton('core/resource')->getConnection('core_write');

        //Clear Auto-imported Rules
        $dbWrite->query("DELETE FROM ". $prGroupTable . "WHERE is_manually_added = 0 AND group_id != 0");
        //Import the Updated Rules
        $dbWrite->query("LOAD DATA LOCAL INFILE '" . $groupFile . "'
            REPLACE INTO TABLE" . $prGroupTable . " (group_id, group_name)
            FIELDS TERMINATED BY '" . $terminate_char . "'
            OPTIONALLY ENCLOSED BY '\"'
            LINES TERMINATED BY \"\r\n\"
            IGNORE 1 LINES
            (@group_id, @group_name)
            SET group_id = NULLIF(@group_id, ''),
                group_name = NULLIF(@group_name, '')
        ");

		$dbWrite->query("TRUNCATE TABLE " . $importTable);
		$dbWrite->query("LOAD DATA LOCAL INFILE '" . $ruleFile . "'
			INTO TABLE " . $importTable . "
			FIELDS TERMINATED BY '" . $terminate_char . "'
			OPTIONALLY ENCLOSED BY '\"'
			LINES TERMINATED BY \"\r\n\"
			IGNORE 1 LINES
			(pricerules_id, @price_from, @price_to, @category_id, @brand_id, @product_sku, @group_id, @markup_percentage, @markup_price, @absolute_price, @execution_order)
			SET	price_from = NULLIF(@price_from, ''),
				price_to = NULLIF(@price_to, ''), 
				category_id = NULLIF(@category_id, ''),
				brand_id = NULLIF(@brand_id, ''),
				product_sku = NULLIF(@product_sku, ''),
				group_id = NULLIF(@group_id, ''),
				markup_percentage = NULLIF(@markup_percentage, ''),
				markup_price = NULLIF(@markup_price, ''),
				absolute_price = NULLIF(@absolute_price, ''),
				execution_order = @execution_order
		");

		// update table with category IDs
		$dbWrite->query("UPDATE " . $importTable . " sipr
			INNER JOIN " . Mage::getSingleton('core/resource')->getTableName('catalog_category_entity') . " cce ON sipr.category_id = cce.store_category_id
			SET sipr.magento_category_id = cce.entity_id
		");
		// update table with brand IDs
		$dbWrite->query("UPDATE " . $importTable . " sipr
			INNER JOIN " . Mage::getSingleton('core/resource')->getTableName('stINch_manufacturers') . " sm ON sipr.brand_id = sm.sinch_manufacturer_id
			SET sipr.magento_brand_id = sm.shop_option_id
		");
		// update table with product IDs
		$dbWrite->query("UPDATE " . $importTable . " sipr
			INNER JOIN " . Mage::getSingleton('core/resource')->getTableName('stINch_products_mapping') . " spm ON sipr.product_sku = spm.product_sku
			SET sipr.magento_product_id = spm.entity_id
		");
		//delete useless rules
		$dbWrite->query("DELETE FROM " . $importTable . "
			WHERE (category_id IS NOT NULL AND magento_category_id IS NULL)
			OR (brand_id IS NOT NULL AND magento_brand_id IS NULL)
			OR (product_sku IS NOT NULL AND magento_product_id IS NULL)
			OR (markup_percentage IS NULL AND markup_price IS NULL AND absolute_price IS NULL)
		");
		// insert rules into sinch_pricerules from sinch_pricerulesimport
		$dbWrite->query("INSERT INTO " . $rulesTable . "
			(
				pricerules_id,
				price_from,
				price_to,
				category_id,
				brand_id,
				product_id,
				group_id,
				markup_percentage,
				markup_price,
				absolute_price,
				execution_order,
				is_manually_added
            )
			(
				SELECT
					pricerules_id,
					price_from,
					price_to,
					magento_category_id,
					magento_brand_id,
					magento_product_id,
					group_id,
					markup_percentage,
					markup_price,
					absolute_price,
					execution_order,
					0
				FROM " . $importTable . " a
            )
            ON DUPLICATE KEY UPDATE
				price_from = a.price_from,
				price_to = a.price_to,
				category_id = a.magento_category_id,
				brand_id = a.magento_brand_id,
				product_id = a.magento_product_id,
				group_id = a.group_id,
				markup_percentage = a.markup_percentage,
				markup_price = a.markup_price,
				absolute_price = a.absolute_price,
				execution_order = a.execution_order,
				is_manually_added = 0
		");
	}
}