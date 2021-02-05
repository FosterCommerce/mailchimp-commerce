<?php

/**
 * Mailchimp for Craft Commerce
 *
 * @link https://ethercreative.co.uk
 * @copyright Copyright (c) 2019 Ether Creative
 */

namespace ether\mc\services;

use Craft;
use craft\base\Component;
use craft\base\Field;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\models\Address;
use craft\commerce\Plugin as Commerce;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use ether\mc\helpers\AddressHelper;
use ether\mc\MailchimpCommerce;
use yii\base\InvalidConfigException;
use yii\db\Exception;
use yii\db\Query;

/**
 * Class OrdersService
 *
 * @author Ether Creative
 * @package ether\mc\services
 */
class OrdersService extends Component
{

	// Public
	// =========================================================================

	/**
	 * @param $orderId
	 *
	 * @return bool
	 * @throws InvalidConfigException
	 * @throws \Throwable
	 * @throws \yii\base\Exception
	 */
	public function syncOrderById($orderId)
	{
		if (MailchimpCommerce::getInstance()->getSettings()->disableSyncing)
			return true;

		$hasBeenSynced = $this->_hasOrderBeenSynced($orderId);
		list($order, $data) = $this->_buildOrderData($orderId);

		if ($data === null)
			return true;

		$success = false;

		try {
			if ($hasBeenSynced) {
				$this->_updateOrder($order, $data);
			} else {
				$this->_createOrder($order, $data);
			}
			$success = true;
		} catch (\Exception $e) {
			// Nothing to do here
		}

		// Did that fail? Try the opposite operation
		if (!$success) {
			try {
				if ($hasBeenSynced) {
					$this->_createOrder($order, $data);
				} else {
					$this->_updateOrder($order, $data);
				}
				$success = true;
			} catch (\Exception $e) {
				// Nothing to do here
			}
		}

		// Did that fail too? Delete all of this customer's orders and carts from Mailchimp, then delete the customer, and finally try to sync the order from scratch
		if (!$success) {
			$storeId = MailchimpCommerce::$i->getSettings()->storeId;
			list($customerOrdersSuccess, $customerOrdersData, $customerOrdersError) = MailchimpCommerce::$i->chimp->get(
				'ecommerce/orders',
				[
					'customer_id' => (string) $order->customer->id,
					'count' => 1000,
				]
			);
			if ($customerOrdersSuccess) {
				foreach ($customerOrdersData['orders'] as $customerOrder) {
					$this->deleteOrderById($customerOrder['id'], false, false, $customerOrder['store_id']);
				}
			} else {
				Craft::error('[Customer ID: ' . $order->customer->id . '] Get orders: ' . $customerOrdersError, 'mailchimp-commerce');
			}
			// There is no endpoint for getting carts across stores, so we need to get the stores first and loop through them
			list($storesSuccess, $storesData, $storesError) = MailchimpCommerce::$i->chimp->get(
				'ecommerce/stores',
				[
					'count' => 1000,
				]
			);
			if ($storesSuccess) {
				foreach ($storesData['stores'] as $store) {
					list($cartsSuccess, $cartsData, $cartsError) = MailchimpCommerce::$i->chimp->get(
						'ecommerce/stores/' . $store['id'] . '/carts',
						[
							'count' => 1000,
						]
					);
					if ($cartsSuccess) {
						$customerCarts = array_filter($cartsData['carts'], function($cart) use ($order) {
							return $cart['customer']['id'] === (string) $order->customer->id;
						});
						foreach ($customerCarts as $customerCart) {
							$this->deleteOrderById($customerCart['id'], true, false, $store['id']);
						}
					} else {
						Craft::error('[Customer ID: ' . $order->customer->id . '] [Store ID: ' . $store['id'] . '] Get carts: ' . $cartsError, 'mailchimp-commerce');
					}
				}
			} else {
				Craft::error('Get stores: ' . $storesError, 'mailchimp-commerce');
			}
			try {
				$this->_deleteCustomer($order->customer);
			} catch (\Exception $e) {
				// Nothing to do here
			}
			try {
				$this->_createOrder($order, $data);
				$success = true;
			} catch (\Exception $e) {
				// Nothing to do here
			}
		}

		// If nothing worked, just delete the row from `mc_orders_synced` if there is one and return false
		if (!$success) {
			if ($hasBeenSynced) {
				Craft::$app->getDb()
					->createCommand()
					->delete('{{%mc_orders_synced}}', [
						'orderId' => $order->id,
					])
					->execute();
			}
			return false;
		}

		// Update or add row in `mc_orders_synced` and return true
		if ($hasBeenSynced) {
			Craft::$app->getDb()
				->createCommand()
				->update(
					'{{%mc_orders_synced}}',
					[
						'isCart'     => !$order->isCompleted,
						'lastSynced' => Db::prepareDateForDb(new \DateTime()),
					],
					['orderId' => $order->id],
					[],
					false
				)
				->execute();
		} else {
			Craft::$app->getDb()
				->createCommand()
				->insert(
					'{{%mc_orders_synced}}',
					[
						'orderId'    => $order->id,
						'isCart'     => !$order->isCompleted,
						'lastSynced' => Db::prepareDateForDb(new \DateTime()),
					],
					false
				)
				->execute();
		}
		
		return true;
	}

	/**
	 * Deletes the order from Mailchimp
	 *
	 * @param $orderId
	 * @param bool $asCart
	 *
	 * @return bool|void
	 * @throws Exception
	 */
	public function deleteOrderById($orderId, $asCart = false, $onlyIfSynced = true, $storeId = null)
	{
		if (MailchimpCommerce::getInstance()->getSettings()->disableSyncing)
			return;

		if ($onlyIfSynced && !$this->_hasOrderBeenSynced($orderId))
			return;

		$storeId = $storeId ?? MailchimpCommerce::$i->getSettings()->storeId;
		$order = Commerce::getInstance()->getOrders()->getOrderById($orderId);
		$type = $asCart || ($order && !$order->isCompleted) ? 'carts' : 'orders';

		list($success, $data, $error) = MailchimpCommerce::$i->chimp->delete(
			'ecommerce/stores/' . $storeId . '/' . $type . '/' . $orderId
		);

		if (!$success) {
			Craft::error('[Order ID: ' . $orderId . '] Delete: ' . $error, 'mailchimp-commerce');
			return false;
		}

		Craft::$app->getDb()->createCommand()
					->delete('{{%mc_orders_synced}}', [
						'orderId' => $orderId,
					])->execute();

		return true;
	}

	/**
	 * Returns the total number of orders synced
	 *
	 * @param bool $getCarts
	 *
	 * @return int|string
	 */
	public function getTotalOrdersSynced($getCarts = false)
	{
		return (new Query())
			->from('{{%mc_orders_synced}}')
			->where(['isCart' => $getCarts])
			->count();
	}

	// Private
	// =========================================================================

	/**
	 * Creates a new cart/order in Mailchimp
	 *
	 * @param Order $order
	 * @param $data
	 *
	 * @throws Exception
	 */
	private function _createOrder(Order $order, $data)
	{
		$storeId = MailchimpCommerce::$i->getSettings()->storeId;
		$type = $order->isCompleted ? 'orders' : 'carts';

		list($success, $data, $error) = MailchimpCommerce::$i->chimp->post(
			'ecommerce/stores/' . $storeId . '/' . $type,
			$data
		);

		if (!$success) {
			Craft::error('[Order ID: ' . $order->id . '] Create: ' . $error, 'mailchimp-commerce');
			throw new \Exception($error);
		}
	}

	/**
	 * Updates the given cart/order in Mailchimp
	 *
	 * @param Order $order
	 * @param $data
	 *
	 * @throws Exception
	 */
	private function _updateOrder($order, $data)
	{
		$storeId = MailchimpCommerce::$i->getSettings()->storeId;
		$type    = $order->isCompleted ? 'orders' : 'carts';

		list($success, $data, $error) = MailchimpCommerce::$i->chimp->patch(
			'ecommerce/stores/' . $storeId . '/' . $type . '/' . $order->id,
			$data
		);

		if (!$success) {
			Craft::error('[Order ID: ' . $order->id . '] Update: ' . $error, 'mailchimp-commerce');
			throw new \Exception($error);
		}
	}

	/**
	 * Deletes the given customer in Mailchimp
	 *
	 * @param Customer $customer
	 *
	 * @throws Exception
	 */
	private function _deleteCustomer($customer)
	{
		$storeId = MailchimpCommerce::$i->getSettings()->storeId;

		list($success, $data, $error) = MailchimpCommerce::$i->chimp->delete(
			'ecommerce/stores/' . $storeId . '/customers/' . $customer->id
		);

		if (!$success) {
			Craft::error('[Customer ID: ' . $customer->id . '] Delete: ' . $error, 'mailchimp-commerce');
			throw new \Exception($error);
		}
	}


	// Helpers
	// =========================================================================

	/**
	 * Checks if the given order ID has been synced
	 *
	 * @param $orderId
	 *
	 * @return bool
	 */
	private function _hasOrderBeenSynced($orderId)
	{
		return (new Query())
			->from('{{%mc_orders_synced}}')
			->where(['orderId' => $orderId])
			->exists();
	}

	/**
	 * Build the order data
	 *
	 * @param $orderId
	 *
	 * @return array
	 * @throws \Throwable
	 * @throws \yii\base\Exception
	 * @throws InvalidConfigException
	 */
	private function _buildOrderData($orderId)
	{
		$order = Commerce::getInstance()->getOrders()->getOrderById($orderId);

		if (!$order || !$order->email || empty($order->getLineItems()))
			return [$order, null];

		$data = [
			'id' => (string) $order->id,
			'currency_code' => $order->getPaymentCurrency(),
			'order_total' => (float) $order->getTotalPrice(),
			'tax_total' => (float) $order->getTotalTax(),
			'lines' => [],
			'promos' => [],
			'customer' => [
				'id' => (string) $order->customer->id,
				'email_address' => $order->customer->email ?: $order->email,
				'opt_in_status' => $this->_hasOptedIn($order),
				'first_name' => $order->billingAddress ? $order->billingAddress->firstName : '',
				'last_name' => $order->billingAddress ? $order->billingAddress->lastName : '',
				'orders_count' => (int) Order::find()->customer($order->customer)->isCompleted()->count(),
				'total_spent' => (float) Order::find()->customer($order->customer)->isCompleted()->sum('[[commerce_orders.totalPaid]]') ?: 0,
			],
		];

		if ($order->billingAddress)
			$data['customer']['address'] = self::_address($order->billingAddress);

		$cid = (new Query())
			->select('cid')
			->from('{{%mc_orders_synced}}')
			->where(['orderId' => $order->id])
			->scalar();

		if ($cid)
			$data['campaign_id'] = $cid;

		foreach ($order->lineItems as $item) {
			if (!$item->purchasable)
				continue;

			$li = [
				'id' => (string) $item->id,
				'product_id' => (string) $this->_getProduct($item->purchasable)->id,
				'product_variant_id' => (string) $item->purchasable->id,
				'quantity' => (int) $item->qty,
				'price' => (float) $item->price + $item->getAdjustmentsTotalByType('prorate'),
			];

			if ($order->isCompleted) {
				$li['discount'] = (float) $item->getDiscount();
			}

			$data['lines'][] = $li;
		}

		// Don't sync the order if there are no line items
		if (count($data['lines']) === 0)
			return [$order, null];

		if ($order->isCompleted) {
			$lastTransactionStatus = 'unset';
			if ($order->lastTransaction) {
				if (($order->lastTransaction->type === 'capture' || $order->lastTransaction->type === 'purchase') && $order->lastTransaction->status === 'success') {
					$lastTransactionStatus = 'paid';
				}
				if (($order->lastTransaction->type === 'capture' || $order->lastTransaction->type === 'purchase') && $order->lastTransaction->status === 'pending') {
					$lastTransactionStatus = 'pending';
				}
				if ($order->lastTransaction->type === 'authorize' && $order->lastTransaction->status === 'success') {
					// Send 'Order Confirmation' email via MC
					// Notifies a customer if their order is unpaid or partially paid.
					$lastTransactionStatus = 'pending';
				}
				if ($order->lastTransaction->type === 'refund' && $order->lastTransaction->status === 'success') {
					$lastTransactionStatus = 'refunded';
				}
			}

			$completeData = [
				'financial_status' => $lastTransactionStatus,
				'discount_total' => (float) $order->getTotalDiscount(),
				'tax_total' => (float) $order->getTotalTax(),
				'shipping_total' => (float) $order->getTotalShippingCost(),
				'processed_at_foreign' => $order->dateOrdered->format('c'),
				'updated_at_foreign' => $order->dateUpdated->format('c'),
			];

			if ($order->shippingAddress)
				$completeData['shipping_address'] = self::_address($order->shippingAddress);

			if ($order->billingAddress)
				$completeData['billing_address'] = self::_address($order->billingAddress);

			$data = array_merge($data, $completeData);

			if ($order->returnUrl)
				$data['order_url'] = UrlHelper::siteUrl($order->returnUrl);

			if ($this->_isOrderShipped($order))
				$data['fulfillment_status'] = 'shipped';

			$promo =
				$order->couponCode
				? Commerce::getInstance()->getDiscounts()->getDiscountByCode($order->couponCode)
				: null;

			foreach ($order->getAdjustments() as $adjustment) {
				$isPromoCode = $promo && $promo->name === $adjustment->name;

				$data['promos'][] = [
					'code'              => $isPromoCode ? $order->couponCode : $adjustment->name,
					'amount_discounted' => (float) $adjustment->amount,
					'type'              => 'fixed',
				];
			}
		} else {
			$data['checkout_url'] = UrlHelper::siteUrl(
				Craft::$app->getConfig()->getGeneral()->actionTrigger . '/mailchimp-commerce/order/restore',
				['number' => $order->number]
			);
		}

		Craft::info($data, 'mailchimp-commerce-order-data');

		return [$order, $data];
	}

	/**
	 * Checks if the given order has been shipped
	 *
	 * @param Order $order
	 *
	 * @return bool
	 */
	private function _isOrderShipped(Order $order)
	{
		return $order->orderStatus->handle === MailchimpCommerce::$i->getSettings()->shippedStatusHandle;
	}

	/**
	 * Check if the customer has opted in for marketing emails
	 *
	 * @param Order $order
	 *
	 * @return bool
	 */
	private function _hasOptedIn(Order $order)
	{
		$fieldUid = MailchimpCommerce::$i->getSettings()->optInField;

		if (!$fieldUid)
			return false;

		/** @var Field $field */
		$field = Craft::$app->getFields()->getFieldByUid($fieldUid);

		if (!$field)
			return false;

		try {
			if (
				$order->getCustomer() &&
				$order->getCustomer()->getUser() &&
				$order->getCustomer()->getUser()->{$field->handle}
			) return true;
		} catch (\Exception $e) {
		}

		if ($order->{$field->handle})
			return true;

		return false;
	}

	/**
	 * Gets the product for the given purchasable
	 *
	 * @param $purchasable
	 *
	 * @return Product|null
	 * @throws InvalidConfigException
	 */
	private function _getProduct($purchasable)
	{
		$mailchimpProducts = MailchimpCommerce::getInstance()->chimp->getProducts();

		foreach ($mailchimpProducts as $product) {
			if ($purchasable instanceof $product->variantClass) {
				$callable = [$purchasable, $product->variantToProductMethod];

				return $callable();
			}
		}

		/** @var Variant $purchasable */
		return $purchasable->getProduct();
	}

	/**
	 * Converts an address to an array
	 *
	 * @param Address $address
	 *
	 * @return array
	 */
	private static function _address(Address $address)
	{
		return array_filter(@AddressHelper::asArray($address));
	}
}
