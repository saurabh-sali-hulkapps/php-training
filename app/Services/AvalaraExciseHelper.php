<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Transaction;
use App\Models\User;
use App\Traits\Helpers;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Str;

class AvalaraExciseHelper
{
    protected $userName;

    protected $password;

    protected $companyId;

    public function setCredentials($username, $password, $companyId)
    {
        $this->userName = $username;
        $this->password = $password;
        $this->companyId = $companyId;
    }

    /**
     * @param $requestDataAdjust
     * @return \Illuminate\Http\Client\Response
     */
    public function calculateExcise($requestDataAdjust)
    {
        $headers = [
            'Accept' => 'application/json',
            'x-company-id' => $this->companyId
        ];
        $http = Http::timeout(60)->withHeaders($headers);
        $http->withBasicAuth($this->userName, $this->password);
        return $http->post(env('AVALARA_API_ENDPOINT') . '/AvaTaxExcise/transactions/create', $requestDataAdjust);
    }

    public function productChunk($shop, $productIds)
    {
        $products_chunk = array_chunk($productIds, 250);
        for ($i = 0; $i < count($products_chunk); $i++) {
            $ids = $products_chunk[$i];
            $ids = implode(",", $ids);
            $param = ['limit' => 250, 'ids' => $ids];
            $data250 = $shop->api()->rest('GET', '/admin/products.json', $param);
            if (isset($data250['body']['products'])) {
                foreach ($data250['body']['products'] as $key => $product) {
                    $tags = explode(",", $product['tags']);
                    $tags = array_map('trim', $tags);
                    Product::updateOrCreate([
                        'shop_id' => $shop->id,
                        'shopify_product_id' => $product['id'],
                    ], [
                        'shop_id' => $shop->id,
                        'shopify_product_id' => $product['id'],
                        'title' => $product['title'],
                        'handle' => $product['handle'],
                        'vendor' => $product['vendor'],
                        'tags' => $tags,
                        'image_url' => !empty($product['image']) ? $product['image']['src'] : null,
                    ]);

                    foreach ($product['variants'] as $variant) {
                        ProductVariant::updateOrCreate([
                            'shop_id' => $shop->id,
                            'variant_id' => $variant['id'],
                        ], [
                            'shop_id' => $shop->id,
                            'product_id' => $product['id'],
                            'variant_id' => $variant['id'],
                            'option_1_name' => isset($product['options'][0]) ? $product['options'][0]['name'] : null,
                            'option_1_value' => $variant['option1'],
                            'option_2_name' => isset($product['options'][1]) ? $product['options'][1]['name'] : null,
                            'option_2_value' => $variant['option2'],
                            'option_3_name' => isset($product['options'][2]) ? $product['options'][2]['name'] : null,
                            'option_3_value' => $variant['option3'],
                            'sku' => $variant['sku'],
                            'barcode' => $variant['barcode'],
                            'price' => $variant['price'],
                            'compare_at_price' => $variant['compare_at_price'],
                            'quantity' => $variant['inventory_quantity'],
                        ]);
                    }
                }
            }
        }
    }

    /**
     * @param $response
     * @param $shop
     * @param $requestDataAdjust
     * @param $transactionLines
     */
    public function insertAvalaraTransactionLog($response, $shop, $requestDataAdjust, $transactionLines)
    {
        DB::table('avalara_transaction_log')->insert([
            "ip" => "0.0.0.0",
            "shop_id" => $shop->id,
            "request_data" => json_encode($requestDataAdjust),
            "total_requested_products" => count($transactionLines),
            "response" => $response->status() != 200 ? json_encode($response->body()) : $response->body(),
            "filtered_request_data" => json_encode($requestDataAdjust),
            "status" =>$response->status(),
            "created_at" => Carbon::now()->format('Y-m-d H:i:s'),
            "updated_at" => Carbon::now()->format('Y-m-d H:i:s')
        ]);
    }

    /**
     * @param $shop
     * @param $itemCounter
     * @param $lineItem
     * @param $orderData
     * @param bool $isOrderCreate
     * @return array
     */
    public function setTransactionLines($shop, $itemCounter, $lineItem, $orderData, $isOrderCreate = false)
    {
        list(
            $titleTransferCode, $transactionType, $transportationModeCode, $seller, $buyer, $unitOfMeasure, $currency, $origin,
            $orderCustomString1, $orderCustomString2, $orderCustomString3, $orderCustomNumeric1, $orderCustomNumeric2, $orderCustomNumeric3,
            $itemCustomString1, $itemCustomString2, $itemCustomString3, $itemCustomNumeric1, $itemCustomNumeric2, $itemCustomNumeric3
            ) = Helpers::staticSettings($shop->id);

        return [
            "TransactionLineMeasures" => null,
            "OriginSpecialJurisdictions" => [],
            "DestinationSpecialJurisdictions" => [],
            "SaleSpecialJurisdictions" => [],
            "InvoiceLine" => ++$itemCounter,
            "ProductCode" => $lineItem->line_item->sku ? Str::substr($lineItem->line_item->sku, 0, 24) : '',
            "UnitPrice" => $lineItem->line_item->price,
            "NetUnits" => $lineItem->quantity,
            "GrossUnits" => $lineItem->quantity,
            "BilledUnits" => $isOrderCreate ? $lineItem->quantity : -$lineItem->quantity ,
            "BillOfLadingDate" => Carbon::parse($orderData['created_at'])->format('Y-m-d H:i:s'),
            "Origin" => $origin,
            "OriginAddress1" => isset($orderData['shipping_address']) ? $orderData['shipping_address']['address1'] : '',
            "OriginAddress2" => null,//isset($orderData['shipping_address']) ? $orderData['shipping_address']['address2'] : '',
            "DestinationCountryCode" => isset($orderData['shipping_address']) ? $orderData['shipping_address']['country_code'] : '',
            "DestinationJurisdiction" => isset($orderData['shipping_address']) ? $orderData['shipping_address']['province_code'] : '',
            "DestinationCounty" => "",
            "DestinationCity" => isset($orderData['shipping_address']) ? $orderData['shipping_address']['city'] : '',
            "DestinationPostalCode" => isset($orderData['shipping_address']) ? $orderData['shipping_address']['zip'] : '',
            "DestinationAddress1" => isset($orderData['shipping_address']) ? $orderData['shipping_address']['address1'] : '',
            "DestinationAddress2" => isset($orderData['shipping_address']) ? $orderData['shipping_address']['address2'] : '',
            "Currency" => $currency,
            "UnitOfMeasure" => $unitOfMeasure,
            "CustomString1" => $itemCustomString1 ? Helpers::getCustomString($itemCustomString1->value, $orderData) : null,
            "CustomString2" => $itemCustomString2 ? Helpers::getCustomString($itemCustomString2->value, $orderData) : null,
            "CustomString3" => $itemCustomString3 ? Helpers::getCustomString($itemCustomString3->value, $orderData) : null,
            "CustomNumeric1" => $itemCustomNumeric1 ? Helpers::getCustomNumeric($itemCustomNumeric1->value, $orderData) : null,
            "CustomNumeric2" => $itemCustomNumeric2 ? Helpers::getCustomNumeric($itemCustomNumeric2->value, $orderData) : null,
            "CustomNumeric3" => $itemCustomNumeric3 ? Helpers::getCustomNumeric($itemCustomNumeric3->value, $orderData) : null,
        ];
    }

    /**
     * @param $shop
     * @param $transactionLines
     * @param $orderData
     * @return array
     */
    public function setRequestDataAdjust($shop, $transactionLines, $orderData)
    {
        list(
            $titleTransferCode, $transactionType, $transportationModeCode, $seller, $buyer, $unitOfMeasure, $currency, $origin,
            $orderCustomString1, $orderCustomString2, $orderCustomString3, $orderCustomNumeric1, $orderCustomNumeric2, $orderCustomNumeric3,
            $itemCustomString1, $itemCustomString2, $itemCustomString3, $itemCustomNumeric1, $itemCustomNumeric2, $itemCustomNumeric3
            ) = Helpers::staticSettings($shop->id);

        $additionalStaticField = Helpers::additionalField($shop->id);
        $invoiceDate = Carbon::parse($orderData['created_at'])->format('Y-m-d H:i:s');

        return [
            'TransactionLines' => $transactionLines,
            'TransactionExchangeRates' => [],
            'EffectiveDate' => $invoiceDate,
            'InvoiceDate' => $invoiceDate,
            'InvoiceNumber' =>$orderData['order_number'],
            'TitleTransferCode' => $titleTransferCode,
            'TransactionType' => $transactionType,
            'TransportationModeCode' => $transportationModeCode,
            'Seller' => $seller,
            'Buyer' => $buyer,
            'PreviousSeller' => isset($additionalStaticField['previous_seller']) ? $additionalStaticField['previous_seller'] : '',
            'NextBuyer' => isset($additionalStaticField['next_buyer']) ? $additionalStaticField['next_buyer'] : '',
            'Middleman' => isset($additionalStaticField['middleman']) ? $additionalStaticField['middleman'] : '',
            'FuelUseCode' => isset($additionalStaticField['fuel_use_code']) ? $additionalStaticField['fuel_use_code'] : '',
            'CustomString1' => $orderCustomString1 ? Helpers::getCustomString($orderCustomString1->value, $orderData) : null,
            'CustomString2' => $orderCustomString2 ? Helpers::getCustomString($orderCustomString1->value, $orderData) : null,
            'CustomString3' => $orderCustomString3 ? Helpers::getCustomString($orderCustomString1->value, $orderData) : null,
            'CustomNumeric1' => $orderCustomNumeric1 ? Helpers::getCustomNumeric($orderCustomNumeric1->value, $orderData) : null,
            'CustomNumeric2' => $orderCustomNumeric2 ? Helpers::getCustomNumeric($orderCustomNumeric2->value, $orderData) : null,
            'CustomNumeric3' => $orderCustomNumeric3 ? Helpers::getCustomNumeric($orderCustomNumeric3->value, $orderData) : null,
        ];
    }

    public function commonCalculateExcise($requestDataAdjust, $shop, $productIds, $transactionLines)
    {
        list($companyId, $apiUsername, $apiUserPassword) = Helpers::avalaraCredentials($shop->id);
        $this->setCredentials($apiUsername, $apiUserPassword, $companyId);
        $response = $this->calculateExcise($requestDataAdjust);
        $response = $response->status() != 200 ? json_encode($response->body()) : $response->body();

        $this->productChunk($shop, $productIds);

        $this->insertAvalaraTransactionLog($response, $shop, $requestDataAdjust, $transactionLines);

        return $response;
    }

    public function setTransactionObj($shop, $data, $transactionLines, $exciseTax, $transactionError, $orderData = null)
    {
        $transactionObj = new Transaction();
        $transactionObj->shop_id = $shop->id;
        $transactionObj->order_id = $data->order_id;
        $transactionObj->order_number = $orderData['order_number'];
        $transactionObj->customer = isset($orderData['shipping_address']) ? $orderData['shipping_address']['name'] : '';
        $transactionObj->taxable_item = count($transactionLines);
        $transactionObj->order_total = $orderData['total_price'];
        $transactionObj->excise_tax = $exciseTax;//$resData->Status == 'Success' ? $resData->TotalTaxAmount : 0;
        $transactionObj->status = Helpers::getOrderFulfillmentStatus($orderData['fulfillment_status']);
        $transactionObj->order_date = $orderData['created_at'];
        $transactionObj->state = isset($orderData['shipping_address']) ? $orderData['shipping_address']['province'] : '';
        $transactionObj->failed_reason = $transactionError;
        $transactionObj->save();
    }
}
