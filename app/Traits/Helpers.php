<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace App\Traits;

use App\Models\Setting\AvalaraCredential;
use App\Models\Setting\FailoverCheckout;
use App\Models\Setting\ProductForExcise;
use App\Models\Setting\ProductIdentifierForExcise;
use App\Models\Setting\StaticSetting;
use Carbon\Carbon;
use DateTimeZone;
use DateTime;
use GuzzleHttp\Client;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Helper\Helper;

trait Helpers
{
    /**
     * @param $request
     * @param $data
     * @param int $loop
     *
     * @return string|null
     */
    public static function fileUpload($request, $data, $loop = 0)
    {
        $dir = $data["dir"];
        $image = $data["file"];

        $originalName = $image->getClientOriginalName();

        if ($loop) {
            $file = $data["key"];
        } else {
            $file = $request->file($data["key"]);
        }
        $filename = $originalName . "_" . date('ymdhis') . '.' . $image->getClientOriginalExtension();
        if (!\Storage::disk('public')->exists($dir)) {
            \Storage::disk('public')->makeDirectory($dir);
        }
        if (@$data["old_file"]) {
            $usersImage = public_path($data["old_file"]);
            if (File::exists($usersImage)) {
                unlink($usersImage);
            }
        }
        $storage = storage_path('app/public/' . $dir);
        //dd($storage, $filename);
        $resp = $file->move($storage, $filename);
        if ($resp)
            return $dir . '/' . $filename;
        else
            return NULL;
    }

    /**
     * @return array
     */
    public static function FixedCSVHeader() {
        return ["master_company_id", "country_code", "jurisdiction", "product_code", "description", "alternate_product_code", "terminal_code", "tax_code", "alternate_effective_date", "alternate_obsolete_date", "product_effective_date", "product_obsolete_date"];
    }

    /**
     * @return array
     */
    public static function devContactEmails() {
        return ["satish@praella.com", "sameer@praella.com"];
    }

    /**
     * @return string
     */
    public static function startTime() {
        return " 00:00:00";
    }

    /**
     * @return string
     */
    public static function endTime() {
        return " 23:59:59";
    }

    /**
     * @param $shop
     * @param $orderId
     * @param $totalExcise
     */
    public static function orderEdit($shop, $orderId, $totalExcise) {
        $beginEditQuery = 'mutation beginEdit{
            orderEditBegin(id: "gid://shopify/Order/'.$orderId.'"){
                calculatedOrder{
                    id
                }
            }
        }';
        $beginEditRequest = $shop->api()->graph($beginEditQuery);
        $calculateOrder = $beginEditRequest['body']['data']['orderEditBegin']['calculatedOrder'];
        $calculateOrderId = $calculateOrder['id'];
        Helpers::addCustomItemToOrder($shop, $calculateOrderId, $orderId, $totalExcise);
    }

    /**
     * @param $shop
     * @param $calculateOrderId
     * @param $orderId
     * @param $totalExcise
     */
    public static function addCustomItemToOrder($shop, $calculateOrderId, $orderId, $totalExcise) {
        $addCustomItemToOrderQuery = 'mutation addCustomItemToOrder {
            orderEditAddCustomItem(id: "'.$calculateOrderId.'", title: "Excise Tax", quantity: 1, price: {amount: '.$totalExcise.', currencyCode: '.$shop->currency.'}) {
                calculatedOrder {
                    id
                    addedLineItems(first: 5) {
                        edges {
                            node {
                                id
                            }
                        }
                    }
                }
                userErrors {
                    field
                    message
                }
            }
        }';
        $addCustomItemToOrderRequest = $shop->api()->graph($addCustomItemToOrderQuery);
        $customLineItem = $addCustomItemToOrderRequest['body']['data']['orderEditAddCustomItem']['calculatedOrder'];
        $addedLineItems = $customLineItem['addedLineItems']['edges'];
        $isAddItem = false;
        foreach ($addedLineItems as $addedLineItem) {
            if($addedLineItem['node']['id']) {
                $isAddItem = true;
            }
        }
        if($isAddItem) {
            Helpers::orderEditCommit($shop, $calculateOrderId);
        }
    }

    /**
     * @param $shop
     * @param $calculateOrderId
     */
    public static function orderEditCommit($shop, $calculateOrderId) {
        $orderEditCommitQuery = 'mutation commitEdit {
          orderEditCommit(id: "'.$calculateOrderId.'", notifyCustomer: true, staffNote: "Due excise tax") {
            order {
              id
            }
            userErrors {
              field
              message
            }
          }
        }';

        $orderEditCommitRequest = $shop->api()->graph($orderEditCommitQuery);
        $orderEditCommit = $orderEditCommitRequest['body']['data']['orderEditCommit']['order'];
        $orderId = $orderEditCommit['id'];
    }

    /**
     * @param $shop
     * @param $orderId
     * @return mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public static function calculateExcise($shop, $orderId) {
        $orderRes = $shop->api()->rest('GET', '/admin/orders/'.$orderId.'.json');
        if (isset($orderRes['body']['order'])) {
            $orderData = $orderRes['body']['order'];

            //if (!empty($orderData['note_attributes'])) {
                //foreach ($orderData['note_attributes'] as $noteAttribute) {
                    //if ($noteAttribute['name'] === 'transaction_id') {

                        $staticSettings = StaticSetting::where('shop_id', $shop->id)->get();

                        // Static Settings
                        $getStaticSettings = (new self)->commonStaticSettings($staticSettings, $orderData);

                        $getCustomAndLineFields = (new self)->commonOrderAndLineItemsCustomField($staticSettings);

                        //Avalara Credential
                        $avalaraCredential = AvalaraCredential::where('shop_id', $shop->id)->first();
                        $companyId = $avalaraCredential->company_id;
                        $apiUsername = $avalaraCredential->username;
                        $apiUserPassword = $avalaraCredential->password;

                        //setting for excise calculation
                        $productForExcise = ProductForExcise::where('shop_id', $shop->id)->first();
                        $productIdentifierForExcise = ProductIdentifierForExcise::where('shop_id', $shop->id)->first();

                        $invoiceDate = Carbon::parse($orderData['created_at'])->format('Y-m-d H:i:s');
                        $transactionLines = $variantIds = $productIds = $past_fulfilled_items = [];
                        $itemCounter = 0;

                        if (!empty($orderData['line_items'])) {
                            foreach ($orderData['line_items'] as $line_item) {
                                if (!empty($line_item['sku'])) {

                                    $productTags = $shop->api()->rest('GET', '/admin/products/'.$line_item['product_id'].'.json');
                                    if(isset($productTags['body']['product']) && !empty($productTags['body']['product'])) {
                                        $productTags = $productTags['body']['product']['tags'];
                                    }
                                    $item['ProductCode'] = $item['itemSKU'] = Str::substr($line_item['sku'], 0, 24);
                                    $item['tags'] = $productTags;
                                    //return $item;
                                    if (!filterRequest($item, $productForExcise, $productIdentifierForExcise)) {
                                        continue;
                                    }
                                    $variantIds[] = $line_item['variant_id'];
                                    $productIds[] = $line_item['product_id'];

                                    $transactionLines[] = [
                                        "TransactionLineMeasures" => null,
                                        "OriginSpecialJurisdictions" => [],
                                        "DestinationSpecialJurisdictions" => [],
                                        "SaleSpecialJurisdictions" => [],
                                        "InvoiceLine" => ++$itemCounter,
                                        "ProductCode" => $line_item['sku'] ? Str::substr($line_item['sku'], 0, 24) : '',
                                        "UnitPrice" => $line_item['price'],
                                        "NetUnits" => $line_item['quantity'],
                                        "GrossUnits" => $line_item['quantity'],
                                        "BilledUnits" => $line_item['quantity'],
                                        "BillOfLadingDate" => $invoiceDate,
                                        "Origin" => $getStaticSettings['origin'],
                                        "OriginAddress1" => isset($orderData['shipping_address']) ? $orderData['shipping_address']['address1'] : '',
                                        "OriginAddress2" => null,
                                        "DestinationCountryCode" => isset($orderData['shipping_address']) ? $orderData['shipping_address']['country_code'] : '',
                                        "DestinationJurisdiction" => isset($orderData['shipping_address']) ? $orderData['shipping_address']['province_code'] : '',
                                        "DestinationCounty" => "",
                                        "DestinationCity" => isset($orderData['shipping_address']) ? $orderData['shipping_address']['city'] : '',
                                        "DestinationPostalCode" => isset($orderData['shipping_address']) ? $orderData['shipping_address']['zip'] : '',
                                        "DestinationAddress1" => isset($orderData['shipping_address']) ? $orderData['shipping_address']['address1'] : '',
                                        "DestinationAddress2" => isset($orderData['shipping_address']) ? $orderData['shipping_address']['address2'] : '',
                                        "Currency" => $getStaticSettings['currency'],
                                        "UnitOfMeasure" => $getStaticSettings['unitOfMeasure'],
                                        "CustomString1" => $getCustomAndLineFields['itemCustomString1'] ? Helpers::getCustomString($getCustomAndLineFields['itemCustomString1']->value, $orderData) : null,
                                        "CustomString2" => $getCustomAndLineFields['itemCustomString2'] ? Helpers::getCustomString($getCustomAndLineFields['itemCustomString2']->value, $orderData) : null,
                                        "CustomString3" => $getCustomAndLineFields['itemCustomString3'] ? Helpers::getCustomString($getCustomAndLineFields['itemCustomString3']->value, $orderData) : null,
                                        "CustomNumeric1" => $getCustomAndLineFields['itemCustomNumeric1'] ? Helpers::getCustomNumeric($getCustomAndLineFields['itemCustomNumeric1']->value, $orderData) : null,
                                        "CustomNumeric2" => $getCustomAndLineFields['itemCustomNumeric2'] ? Helpers::getCustomNumeric($getCustomAndLineFields['itemCustomNumeric2']->value, $orderData) : null,
                                        "CustomNumeric3" => $getCustomAndLineFields['itemCustomNumeric3'] ? Helpers::getCustomNumeric($getCustomAndLineFields['itemCustomNumeric3']->value, $orderData) : null,
                                        //"AlternateUnitPrice" => getVariant($shop->name, $line_item['variant_id']),
                                    ];
                                }
                            }
                        }

                        $requestDataAdjust = [
                            'TransactionLines' => $transactionLines,
                            'TransactionExchangeRates' => [],
                            'EffectiveDate' => $invoiceDate,
                            'InvoiceDate' => $invoiceDate,
                            'InvoiceNumber' => "",//$orderData['order_number'],
                            'TitleTransferCode' => $getStaticSettings['titleTransferCode'],
                            'TransactionType' => $getStaticSettings['transactionType'],
                            'TransportationModeCode' => $getStaticSettings['transportationModeCode'],
                            'Seller' => $getStaticSettings['seller'],
                            'Buyer' => $getStaticSettings['buyer'],
                            'CustomString1' => $getCustomAndLineFields['orderCustomString1'] ? Helpers::getCustomString($getCustomAndLineFields['orderCustomString1']->value, $orderData) : null,
                            'CustomString2' => $getCustomAndLineFields['orderCustomString2'] ? Helpers::getCustomString($getCustomAndLineFields['orderCustomString2']->value, $orderData) : null,
                            'CustomString3' => $getCustomAndLineFields['orderCustomString3'] ? Helpers::getCustomString($getCustomAndLineFields['orderCustomString3']->value, $orderData) : null,
                            'CustomNumeric1' => $getCustomAndLineFields['orderCustomNumeric1'] ? Helpers::getCustomNumeric($getCustomAndLineFields['orderCustomNumeric1']->value, $orderData) : null,
                            'CustomNumeric2' => $getCustomAndLineFields['orderCustomNumeric2'] ? Helpers::getCustomNumeric($getCustomAndLineFields['orderCustomNumeric2']->value, $orderData) : null,
                            'CustomNumeric3' => $getCustomAndLineFields['orderCustomNumeric3'] ? Helpers::getCustomNumeric($getCustomAndLineFields['orderCustomNumeric3']->value, $orderData) : null,
                        ];

                        if (!empty($transactionLines)) {
                            $client = new Client();
                            $headers = [
                                'Accept' => 'application/json',
                                'x-company-id' => $companyId
                            ];

                            $response = $client->post(env('AVALARA_API_ENDPOINT').'/AvaTaxExcise/transactions/create', [
                                'auth' => [
                                    $apiUsername, $apiUserPassword
                                ],
                                'headers' => $headers,
                                'json' => $requestDataAdjust
                            ]);

                            $resData = json_decode($response->getBody());
                            if ($resData->Status == 'Success') {
                                return $resData->TotalTaxAmount;
                            }
                        }
                    //}
                //}
            //}return "note attributes not found";
        }
        //return $orderRes;
    }

    /**
     * @param $stringType
     * @param $data
     * @return mixed|string
     */
    public static function getCustomString($stringType, $data) {
        switch ($stringType) {
            case 5:
                $customerName = '';
                if ($data['customer']) {
                    $customerName = $data['customer']['first_name'].' '.$data['customer']['last_name'];
                }

                return $customerName;
            case 2:
                return $data['order_number'];
            case 3:
                $phone = '';
                if ($data['customer']) {
                    $phone = $data['customer']['phone'];
                }

                return $phone;
            case 4:
                $customerEmail = '';
                if ($data['customer']) {
                    $customerEmail = $data['customer']['email'];
                }

                return $customerEmail;
            case 1:
                return '';
        }
    }

    /**
     * @param $numericType
     * @param $data
     * @return int|mixed|string
     */
    public static function getCustomNumeric($numericType, $data)
    {
        switch($numericType) {
            case 3:
                return $data['total_price'];
            case 2:
                $totalQuantity = 0;
                foreach ($data['line_items'] as $item) {
                    $totalQuantity += $item['quantity'];
                }

                return $totalQuantity;
            case 1:
                return '';
        }
    }

    /**
     * @param $shopId
     * @param int $unauthorizeLoaction
     * @return array
     */
    public static function failoverCheckout($shopId, $unauthorizeLoaction = 0) {
        $action = $unauthorizeLoaction ? 2 : 1;
        $failover = FailoverCheckout::where([['shop_id', $shopId], ['action', $action]])->first();

        if (!$unauthorizeLoaction) {
            $failoverMessage = $failover->message;
            $disableCheckout = false;
        } else {
            $disableCheckout = false;
            $failoverMessage = $failover->message;
        }
        return (["failoverMessage" => $failoverMessage, "disableCheckout" => $disableCheckout, "statusCode" => 400]);
    }

    /**
     * @param $status
     * @return int
     */
    public static function getOrderFulfillmentStatus($status) {
        switch ($status) {
            case 'fulfilled':
                return 1;
            case null:
                return 2;
            case 'partial':
                return 3;
            case 'restocked':
                return 4;
        }
    }

    /**
     * @param $shopId
     * @return array
     */
    public static function avalaraCredentials($shopId) {
        $avalaraCredential = AvalaraCredential::where('shop_id',$shopId)->first();
        return [$avalaraCredential->company_id, $avalaraCredential->username, $avalaraCredential->password];
    }

    /**
     * @param $shopId
     * @return mixed
     */
    public static function productForExcise($shopId) {
        return ProductForExcise::where('shop_id', $shopId)->first();
    }

    /**
     * @param $shopId
     * @return mixed
     */
    public static function productIdentifierForExcise($shopId) {
        return ProductIdentifierForExcise::where('shop_id', $shopId)->first();
    }

    /**
     * @param $shopId
     * @return array
     */
    public static function additionalField($shopId) {
        $staticSettings = StaticSetting::where('shop_id', $shopId)->get();

        $return = [];
        for ($i=1; $i <= 20; $i++) {
            $hasAdditionalField = $staticSettings->where('field', 'additional_custom_option'.$i)->first();
            if ($hasAdditionalField) {
                $hasAdditionalFieldValue = $staticSettings->where('field', 'additional_custom_value'.$i)->first();
                if ($hasAdditionalFieldValue)
                    $return[Helpers::additionalStaticField($hasAdditionalField->value)] = $hasAdditionalFieldValue->value;
            }
        }
        return $return;
    }

    /**
     * @param $option
     * @return string
     */
    public static function additionalStaticField($option) {
        switch ($option) {
            case 1:
                return "previous_seller";
                break;
            case 2:
                return "next_buyer";
                break;
            case 3:
                return "middleman";
                break;
            case 4:
                return "fuel_use_code";
                break;
        }
    }

    /**
     * @param $shopId
     * @return array
     */
    public static function staticSettings($shopId) {
        $staticSettings = StaticSetting::where('shop_id', $shopId)->get();

        $getStaticSettings = (new self)->commonStaticSettings($staticSettings);

        // Order Custom Fields
        $getCustomAndLineFields = (new self)->commonOrderAndLineItemsCustomField($staticSettings);

        return [
            $getStaticSettings['titleTransferCode'],
            $getStaticSettings['transactionType'],
            $getStaticSettings['transportationModeCode'],
            $getStaticSettings['seller'],
            $getStaticSettings['buyer'],
            $getStaticSettings['unitOfMeasure'],
            $getStaticSettings['currency'],
            $getStaticSettings['origin'],
            $getCustomAndLineFields['orderCustomString1'],
            $getCustomAndLineFields['orderCustomString2'],
            $getCustomAndLineFields['orderCustomString3'],
            $getCustomAndLineFields['orderCustomNumeric1'],
            $getCustomAndLineFields['orderCustomNumeric2'],
            $getCustomAndLineFields['orderCustomNumeric3'],
            $getCustomAndLineFields['itemCustomString1'],
            $getCustomAndLineFields['itemCustomString2'],
            $getCustomAndLineFields['itemCustomString3'],
            $getCustomAndLineFields['itemCustomNumeric1'],
            $getCustomAndLineFields['itemCustomNumeric2'],
            $getCustomAndLineFields['itemCustomNumeric3']
        ];
    }

    /**
     * @param $staticSettings
     * @param null $orderData
     * @return array
     */
    public function commonStaticSettings($staticSettings, $orderData = null)
    {
        $hasTitleTransferCode = $staticSettings->where('field', 'title_transfer_code')->first();
        $titleTransferCode = $hasTitleTransferCode ? $hasTitleTransferCode->value : 'DEST';
        $hasTransactionType = $staticSettings->where('field', 'transaction_type')->first();
        $transactionType = $hasTransactionType ? $hasTransactionType->value : 'WHOLESALE';
        $hasTransportationModeCode = $staticSettings->where('field', 'transportation_mode_code')->first();
        $transportationModeCode = $hasTransportationModeCode ? $hasTransportationModeCode->value : 'J';
        $hasSeller = $staticSettings->where('field', 'seller')->first();
        $seller = $hasSeller ? $hasSeller->value : '';
        $hasBuyer = $staticSettings->where('field' , 'buyer')->first();
        $buyer = $hasBuyer ? $hasBuyer->value : '';
        $hasUnitOfMeasure = $staticSettings->where('field', 'unit_of_measure')->first();
        $unitOfMeasure = $hasUnitOfMeasure ? $hasUnitOfMeasure->value : 'EA';
        $hasCurrency = $staticSettings->where('field', 'currency')->first();
        if ($orderData['currency']) {
            $currency = $hasCurrency ? $hasCurrency->value : $orderData['currency'];
        } else {
            $currency = $hasCurrency ? $hasCurrency->value : "USD";
        }
        $hasOrigin = $staticSettings->where('field', 'origin')->first();
        $origin = $hasOrigin ? $hasOrigin->value : '';

        return [
            'origin' => $origin,
            'currency' => $currency,
            'unitOfMeasure' => $unitOfMeasure,
            'titleTransferCode' => $titleTransferCode,
            'transactionType' => $transactionType,
            'transportationModeCode' => $transportationModeCode,
            'seller' => $seller,
            'buyer' => $buyer,
        ];
    }

    /**
     * @param $staticSettings
     * @return array
     */
    public function commonOrderAndLineItemsCustomField($staticSettings)
    {
        // Order Custom Fields
        $orderCustomString1 = $staticSettings->where('field', 'order_custom_string1')->first();
        $orderCustomString2 = $staticSettings->where('field', 'order_custom_string2')->first();
        $orderCustomString3 = $staticSettings->where('field', 'order_custom_string3')->first();
        $orderCustomNumeric1 = $staticSettings->where('field', 'order_custom_numeric1')->first();
        $orderCustomNumeric2 = $staticSettings->where('field', 'order_custom_numeric2')->first();
        $orderCustomNumeric3 = $staticSettings->where('field', 'order_custom_numeric3')->first();

        // Line Items Custom Fields
        $itemCustomString1 = $staticSettings->where('field', 'lineitem_custom_string1')->first();
        $itemCustomString2 = $staticSettings->where('field', 'lineitem_custom_string2')->first();
        $itemCustomString3 = $staticSettings->where('field', 'lineitem_custom_string3')->first();
        $itemCustomNumeric1 = $staticSettings->where('field', 'lineitem_custom_numeric1')->first();
        $itemCustomNumeric2 = $staticSettings->where('field', 'lineitem_custom_numeric2')->first();
        $itemCustomNumeric3 = $staticSettings->where('field', 'lineitem_custom_numeric3')->first();

        return [
            'orderCustomString1' => $orderCustomString1,
            'orderCustomString2' => $orderCustomString2,
            'orderCustomString3' => $orderCustomString3,
            'orderCustomNumeric1' => $orderCustomNumeric1,
            'orderCustomNumeric2' => $orderCustomNumeric2,
            'orderCustomNumeric3' => $orderCustomNumeric3,

            'itemCustomString1' => $itemCustomString1,
            'itemCustomString2' => $itemCustomString2,
            'itemCustomString3' => $itemCustomString3,
            'itemCustomNumeric1' => $itemCustomNumeric1,
            'itemCustomNumeric2' => $itemCustomNumeric2,
            'itemCustomNumeric3' => $itemCustomNumeric3,
        ];
    }
}
