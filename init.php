<?
AddEventHandler("iblock", "OnAfterIBlockElementUpdate", Array("ElementBloka", "OnAfterIBlockElementUpdateHandler"));
AddEventHandler("iblock", "OnAfterIBlockElementAdd", Array("ElementBloka", "OnAfterIBlockElementAddHandler"));

class ElementBloka
{
   public static $disableHandler = false;
   public static $disableHandler2 = false;
   
    function OnAfterIBlockElementUpdateHandler(&$arFields)
    {
        if (self::$disableHandler)
            return;
		if ($arFields["IBLOCK_ID"]=="17"){
                        CModule::IncludeModule("iblock");
                        $el = new CIBlockElement;
                        
						



						self::$disableHandler = true;
						$MAIN_ID=0;
						$el_id_arr=$arFields['PROPERTY_VALUES'][169];
						foreach($el_id_arr as $el_id){
							$MAIN_ID=$el_id['VALUE'];	
						}
						if ($MAIN_ID>0){

							$db_props = CIBlockElement::GetProperty(16, $MAIN_ID, array("sort" => "asc"), Array("CODE"=>"CML2_ARTICLE"));
							if($ar_props = $db_props->Fetch())
								$CML2_ARTICLE = $ar_props["VALUE"];
							else
								$CML2_ARTICLE = false;

							if ($CML2_ARTICLE){


								CIBlockElement::SetPropertyValuesEx($arFields["ID"], false, array('CML2_ARTICLE' => $CML2_ARTICLE));
								}
							}


                } 
        }

	function OnAfterIBlockElementAddHandler(&$arFields)
    {
        if (self::$disableHandler2)
            return;
		if ($arFields["IBLOCK_ID"]=="17"){
                        CModule::IncludeModule("iblock");
                        $el = new CIBlockElement;





						self::$disableHandler2 = true;
						$MAIN_ID=0;
						$el_id_arr=$arFields['PROPERTY_VALUES'][169];
						foreach($el_id_arr as $el_id){
							$MAIN_ID=$el_id['VALUE'];	
						}
						if ($MAIN_ID>0){

							$db_props = CIBlockElement::GetProperty(16, $MAIN_ID, array("sort" => "asc"), Array("CODE"=>"CML2_ARTICLE"));
							if($ar_props = $db_props->Fetch())
								$CML2_ARTICLE = $ar_props["VALUE"];
							else
								$CML2_ARTICLE = false;

							if ($CML2_ARTICLE){


								CIBlockElement::SetPropertyValuesEx($arFields["ID"], false, array('CML2_ARTICLE' => $CML2_ARTICLE));
								}
							}


                } 
    }

}

CModule::IncludeModule("sale");
CModule::IncludeModule("catalog");

//original_order_id = ид оригинального заказа
//original_store_id = ид основного склада. если товара на нем нет - он будет перемещен в заказ-копию.
function SeparateOrderByStore($original_order_id, $original_store_id, $limit_statuses=array())
{
	//main data
	$originalOrder = Bitrix\Sale\Order::load($original_order_id);
	$originalBasket = $originalOrder->getBasket();
	$originalBasketItems = $originalBasket->getBasketItems();

	// проверим - нужно ли вообще разделять заказ....
		// проверим - нужно ли вообще разделять заказ....
	$NEED_TO_CREATE_CLONE=false;
	$GOODS_OUT_OF_STORE=0;
	$GOODS_IN_STORE=0;
	foreach($originalBasketItems as $originalBasketItem)
	{
		$res_product_stock = \Bitrix\Sale\StoreProductTable::getList(array("filter"=>array("PRODUCT_ID"=>$originalBasketItem->getProductId(), "<=AMOUNT"=>0, "STORE_ID"=>$original_store_id)));
		if($product_search=$res_product_stock->fetch())
		{
			$GOODS_OUT_OF_STORE++;
		}
		else
		{
			$GOODS_IN_STORE++;
		}
	}

	if($GOODS_OUT_OF_STORE>0 && $GOODS_IN_STORE>0)$NEED_TO_CREATE_CLONE=true;
	if(!in_array($originalOrder->getField('STATUS_ID'), $limit_statuses)) $NEED_TO_CREATE_CLONE=false;

	if($NEED_TO_CREATE_CLONE)
	{
		$order = \Bitrix\Sale\Order::create($originalOrder->getSiteId(), $originalOrder->getUserId(), $originalOrder->getCurrency());
		$order->setPersonTypeId($originalOrder->getPersonTypeId());
		$userProfiles = \Bitrix\Sale\Helpers\Admin\Blocks\OrderBuyer::getUserProfiles($originalOrder->getUserId());

		//profiles
		if(!empty($userProfiles[$originalOrder->getPersonTypeId()]))
		{
			$profileList = current($userProfiles[$originalOrder->getPersonTypeId()]);
			$profileId = key($userProfiles[$originalOrder->getPersonTypeId()]);
			$showProfiles = true;
		}


		//properties
		$originalPropCollection = $originalOrder->getPropertyCollection();
		$properties['PROPERTIES'] = array();
		$files = array();

		/** @var \Bitrix\Sale\PropertyValue $prop */
		foreach ($originalPropCollection as $prop)
		{
			if ($prop->getField('TYPE') == 'FILE')
			{
				$propValue = $prop->getValue();
				if ($propValue)
				{
					$files[] = CAllFile::MakeFileArray($propValue['ID']);
					$properties['PROPERTIES'][$prop->getPropertyId()] = $propValue['ID'];
				}
			}
			else
			{
				$properties['PROPERTIES'][$prop->getPropertyId()] = $prop->getValue();
			}
		}
		$propCollection = $order->getPropertyCollection();
		$propCollection->setValuesFromPost($properties, $files);

		$order->setField("USER_DESCRIPTION", $originalOrder->getField("USER_DESCRIPTION"));


		//basket
		$basket = \Bitrix\Sale\Basket::create($originalOrder->getSiteId());
		$basket->setFUserId($originalBasket->getFUserId());

		foreach($originalBasketItems as $originalBasketItem)
		{
			$res_product_stock = \Bitrix\Sale\StoreProductTable::getList(array("filter"=>array("PRODUCT_ID"=>$originalBasketItem->getProductId(), "AMOUNT"=>0, "STORE_ID"=>$original_store_id)));
			if($product_search=$res_product_stock->fetch())
			{
					//продукт не на складе, добавим в копию заказа...
					$item = $basket->createItem($originalBasketItem->getField("MODULE"), $originalBasketItem->getProductId());
					$item->setField('NAME', $originalBasketItem->getField('NAME'));

					$item->setFields(
						array_intersect_key(
							$originalBasketItem->getFields()->getValues(),
							array_flip(
								$originalBasketItem->getAvailableFields()
							)
						)
					);

					$item->getPropertyCollection()->setProperty(
					$originalBasketItem->getPropertyCollection()->getPropertyValues()
					);

				//удалим из основного заказа...
				$originalBasketItem->delete();
			}
			else
			{
				//продукт на складе
			}
		}

		$originalBasket->save();

		$shipmentCollection = $originalOrder->getShipmentCollection();
		foreach ($shipmentCollection as $shipment)
		{
			if (!$shipment->isSystem())
			{
				$shipmentItemCollection = $shipment->getShipmentItemCollection();
				$shipmentItemCollection->resetCollection($originalBasket);
				$shipment->save();
				$shipmentItemCollection->save();
			}
		}

		$res = $order->setBasket($basket);

		if(!$res->isSuccess())	$result->addErrors($res->getErrors());

		//payment
		$paymentCollection = $originalOrder->getPaymentCollection();
		$originalPayment = $paymentCollection->current();

		if ($originalPayment)
		{
			$payment = $order->getPaymentCollection()->createItem();
			$payment->setField('PAY_SYSTEM_ID', $originalPayment->getPaymentSystemId());
			$payment->setField('PAY_SYSTEM_NAME', $originalPayment->getField('PAY_SYSTEM_NAME'));
			$payment->setField('SUM', $originalPayment->getField('SUM'));
			$payment->setField('CURRENCY', $originalPayment->getField('CURRENCY'));
			$payment->setField('COMPANY_ID', $originalPayment->getField('COMPANY_ID'));
			$payment->setField('COMMENTS', $originalPayment->getField('COMMENTS'));
			$payment->setField('PRICE_COD', $originalPayment->getField('PRICE_COD'));
		}

		//shipment
		$originalDeliveryId = 0;
		$originalStoreId = 0;
		$shipmentCollection = $originalOrder->getShipmentCollection();
		$original_shipment_fields=array();
		foreach ($shipmentCollection as $shipment)
		{
			if (!$shipment->isSystem())
			{
				$originalDeliveryId = $shipment->getDeliveryId();
				$originalStoreId = $shipment->getStoreId();
				$original_shipment_fields=array_intersect_key($shipment->getFields()->getValues(),array_flip($shipment->getAvailableFields()));
				break;
			}
		}
		if ($originalDeliveryId > 0)
		{
			$shipment_collection = $order->getShipmentCollection();

			$shipment = $shipment_collection->createItem();

			$shipment->setField('DELIVERY_ID', $originalDeliveryId);
			$shipment->setField('CUSTOM_PRICE_DELIVERY', $original_shipment_fields['CUSTOM_PRICE_DELIVERY']);
			$shipment->setField('BASE_PRICE_DELIVERY', $original_shipment_fields['BASE_PRICE_DELIVERY']);
			$shipment->setField('ALLOW_DELIVERY', $original_shipment_fields['ALLOW_DELIVERY']);
			$shipment->setField('DATE_ALLOW_DELIVERY', $original_shipment_fields['DATE_ALLOW_DELIVERY']);
			$shipment->setField('DELIVERY_NAME', $original_shipment_fields['DELIVERY_NAME']);
			$shipment->setField('CURRENCY', $order->getCurrency());
			if(intval($originalStoreId) > 0) $shipment->setStoreId($originalStoreId);

			$shipmentItemCollection = $shipment->getShipmentItemCollection();
			foreach ($order->getBasket() as $item)
			{
				$shipmentItem = $shipmentItemCollection->createItem($item);
				$shipmentItem->setQuantity($item->getQuantity());
			}
			$shipment_collection->calculateDelivery();
		}


		$order->getDiscount()->calculate();
		$order->doFinalAction(true);
		$order->save();
	}
}


use Bitrix\Main; 
Main\EventManager::getInstance()->addEventHandler(
    'sale',
    'OnSaleOrderSaved',
    array('YBWSOrderSave','my_OnSaleOrderSaved')
);

//в обработчике изменим комментарий:

class YBWSOrderSave{
    public static $disableHandler = false;
	function my_OnSaleOrderSaved(Main\Event $event)
	{
		if (self::$disableHandler)
            return;

		self::$disableHandler = true;

		$order = $event->getParameter('ENTITY');
		$ORDER_ID = $order->getId();

		//������ �������� - ������ �������� ������, ��� ������� ������������ ����������...
		SeparateOrderByStore($ORDER_ID, 2, array("N"));

		self::$disableHandler = false;
	}  

}



?>