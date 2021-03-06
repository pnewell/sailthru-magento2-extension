<?php

namespace Sailthru\MageSail\Helper;

use Magento\Sales\Model\Order as OrderModel;
use Magento\Catalog\Helper\Product as MagentoProductHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\StoreManager;
use Magento\Directory\Api\CountryInformationAcquirerInterface;
use Sailthru\MageSail\Helper\ProductData as SailthruProductHelper;

class Order extends VariablesAbstractHelper
{
    /** @var \Magento\Sales\Model\Order */
    private $orderModel;

    /** @var MagentoProductHelper */
    private $magentoProductHelper;

    /** @var SailthruProductHelper */
    private $sailthruProductHelper;

    public function __construct(
        OrderModel $orderModel,
        MagentoProductHelper $magentoProductHelper,
        SailthruProductHelper $sailthruProductHelper,
        Context $context,
        StoreManager $storeManager,
        CountryInformationAcquirerInterface $countryInformation
    ) {
        parent::__construct($context, $storeManager, $countryInformation);

        $this->orderModel = $orderModel;
        $this->magentoProductHelper = $magentoProductHelper;
        $this->sailthruProductHelper = $sailthruProductHelper;
    }

    /**
     * To get processed order variable.
     * 
     * @param  Order  $object
     * @param  array  $data
     * 
     * @return array
     */
    public function getCustomVariables($object, $data = [])
    {        
        return [
            'order' => [
                'id' => $object->getId(),
                'items' => $this->getOrderItemsForSend($object),
                'adjustments' => $this->getOrderAdjustments($object),
                'tenders' => $this->getOrderTenders($object),
                'name' => $object->getCustomerName(),
                'status' => $object->getStatus(),
                'state' => $object->getState(),
                'created_date' => $object->getCreatedAt(),
                'total' => $object->getGrandTotal(),
                'subtotal' => $object->getSubtotal(),
                'couponCode' => $object->getCouponCode(),
                'discount' => $object->getDiscountAmount(),
                'shippingDescription' => $object->getShippingDescription(),
                'isGuest' => $object->getCustomerIsGuest() ? 1 : 0,
                'billingAddress' => $this->getAddress($object->getBillingAddress(), true),
                'shippingAddress' => $this->getAddress($object->getShippingAddress(), true),
            ],
        ];
    }

    /**
     * To get Order model.
     * 
     * @param  string $param
     * 
     * @return \Magento\Sales\Model\Order
     */
    public function getObject($param)
    {
        return $this->orderModel->loadByIncrementId($param);
    }

    /**
     * To get `isGuest` attribute for given Order object.
     * 
     * @param  OrderModel $order
     * 
     * @return array
     */
    public function getIsGuestVariable(OrderModel $order)
    {
        return ['isGuest' => $order->getCustomerIsGuest() ? 1 : 0];
    }

    /**
     * Prepare data on items in order.
     *
     * @param  OrderModel $order
     * 
     * @return array
     */
    private function getOrderItemsForSend(OrderModel $order)
    {
        /** @var \Magento\Sales\Model\Order\Item[] $items */
        $items = $order->getAllVisibleItems();
        $data = [];
        $configurableSkus = [];

        foreach ($items as $item) {
            $product = $item->getProduct();
            $_item = [];
            if ($item->getProduct()->getTypeId() == 'configurable') {
                $options = $item->getProductOptions();
                $_item['id'] = $options['simple_sku'];
                $_item['title'] = $item->getName();
                $_item['options'] = $this->getItemOptions($item, true);
                $configurableSkus[] = $options['simple_sku'];
            } elseif (!in_array($item->getSku(), $configurableSkus)) {
                $_item += [
                    'id' => $item->getSku(),
                    'title' => $item->getName(),
                ];
            } else {
                $_item['id'] = null;
            }

            if ($_item['id']) {
                $_item += [
                    'qty' => intval($item->getQtyOrdered()),
                    'url' => $item->getProduct()->getProductUrl(),
                    'image' => $this->magentoProductHelper->getSmallImageUrl($product),
                    'price' => $item->getPrice(),
                ];

                if ($tags = $this->sailthruProductHelper->getTags($product)) {
                    $_item['tags'] = $tags;
                }

                $data[] = $_item;
            }
        }

        return $data;
    }

    /**
     * Get order adjustments.
     * 
     * @param  OrderModel $order
     * 
     * @return array
     */
    private function getOrderAdjustments(OrderModel $order)
    {
        $adjustments = [];

        if ($shipCost = $order->getShippingAmount()) {
            $adjustments[] = [
                'title' => 'Shipping',
                'price' => $shipCost * 100,
            ];
        }

        if ($discount = $order->getDiscountAmount()) {
            $adjustments[] = [
                'title' => 'Discount',
                'price' => (($discount > 0) ? $discount*-1 : $discount)*100,
            ];
        }

        if ($tax = $order->getTaxAmount()) {
            $adjustments[] = [
                'title' => 'Tax',
                'price' => $tax * 100,
            ];
        }

        return $adjustments;
    }

    /**
     * Get payment information.
     * 
     * @param  OrderModel $order
     * 
     * @return string|array
     */
    private function getOrderTenders(OrderModel $order)
    {
        if (!$order->getPayment()) {
            return '';
        }

        $tenders = [
            [
              'title' => $order->getPayment()->getCcType(),
              'price' => $order->getPayment()->getBaseAmountOrdered(),
            ]
        ];

        if ($tenders[0]['title'] == null) {
            return '';
        }

        return $tenders;
    }

    /**
     * Get Sailthru item object vars
     *
     * @param OrderModel\Item $item
     * @param boolean         $keepLabelValue
     *
     * @return array
     */
    public function getItemOptions(OrderModel\Item $item, $keepLabelValue=false)
    {
        $productOptions = $item->getProductOptions();
        if (!$productOptions or !array_key_exists('attributes_info', $productOptions)) {
            return null;
        }
        $options = $productOptions['attributes_info'];

        if ($keepLabelValue) {
            return $options;
        }

        $vars = [];
        foreach($options as $attribute) {
            $vars[$attribute['label']] = $attribute['value'];
        }
        return $vars;
    }
}
