<?php

namespace App\Jobs;

use App\Models\ExciseByProduct;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting\AvalaraCredential;
use App\Models\Setting\ProductForExcise;
use App\Models\Setting\ProductIdentifierForExcise;
use App\Models\Setting\StaticSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AvalaraExciseHelper;
use App\Traits\Helpers;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class RefundsCreateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Shop's myshopify domain
     *
     * @var ShopDomain
     */
    public $shopDomain;

    /**
     * The webhook data
     *
     * @var object
     */
    protected $data;

    /**
     * RefundsCreateJob constructor.
     *
     * @param $shopDomain
     * @param $data
     */
    public function __construct($shopDomain, $data)
    {
        $this->shopDomain = $shopDomain;
        $this->data = $data;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $data = $this->data;
        $newService = new AvalaraExciseHelper();

        $shop = User::where(['name' => $this->shopDomain->toNative()])->first();

        $productForExcise = Helpers::productForExcise($shop->id);
        $productIdentifierForExcise = Helpers::productIdentifierForExcise($shop->id);

        $orderRes = $shop->api()->rest('GET', '/admin/orders/' . $data->order_id . '.json');
        if (isset($orderRes['body']['order'])) {
            $orderData = $orderRes['body']['order'];

            if (!empty($orderData['note_attributes']) && empty($orderData['cancelled_at'])) {
                foreach ($orderData['note_attributes'] as $noteAttribute) {

                    if ($noteAttribute['name'] === 'transaction_id') {

                        $transactionLines = $variantIds = $productIds = $past_fulfilled_items = [];
                        $itemCounter = 0;

                        if (!empty($data->refund_line_items)) {
                            foreach ($data->refund_line_items as $line_item) {
                                if (!empty($line_item->line_item->sku)) {

                                    $productTags = $shop->api()->rest('GET', '/admin/products/' . $line_item->line_item->product_id . '.json');
                                    if (isset($productTags['body']['product']) && !empty($productTags['body']['product'])) {
                                        $productTags = $productTags['body']['product']['tags'];
                                    }
                                    $item['ProductCode'] = $item['itemSKU'] = Str::substr($line_item->line_item->sku, 0, 24);
                                    $item['tags'] = $productTags;
                                    if (!filterRequest($item, $productForExcise, $productIdentifierForExcise)) {
                                        continue;
                                    }

                                    $variantIds[] = $line_item->line_item->variant_id;
                                    $productIds[] = $line_item->line_item->product_id;

                                    $transactionLines[] = $newService->setTransactionLines($shop, $itemCounter, $line_item->line_item, $orderData);
                                }
                            }
                        }

                        $requestDataAdjust = $newService->setRequestDataAdjust($shop, $transactionLines, $orderData);

                        if (!empty($transactionLines)) {

                            $response = $newService->commonCalculateExcise($requestDataAdjust, $shop, $productIds, $transactionLines);

                            $exciseTax = 0;
                            $transactionError = null;
                            if ($response->status() == 200) {
                                $responseTemp = json_decode($response->body());
                                $exciseTax = $responseTemp->TotalTaxAmount;

                                foreach ($responseTemp->TransactionTaxes as $key => $transactionTax) {
                                    if (isset($productIds[$key])) {
                                        $exciseByProduct = ExciseByProduct::where('shop_id', $shop->id)
                                            ->where('product_id', $productIds[$key])
                                            ->where('date', Carbon::parse($orderData['created_at'])->format('Y-m-d'))->first();
                                    }
                                    if (isset($exciseByProduct)) {
                                        $exciseByProduct->excise_tax += $transactionTax->TaxAmount;
                                        $exciseByProduct->save();
                                    } else {
                                        ExciseByProduct::create([
                                            'shop_id' => $shop->id,
                                            'product_id' => $productIds[$key],
                                            'excise_tax' => $transactionTax->TaxAmount,
                                            'date' => Carbon::parse($orderData['created_at'])->format('Y-m-d')
                                        ]);
                                    }
                                }
                            } else {
                                $transactionError = json_encode($response->body());
                            }

                            $newService->setTransactionObj($shop, $data, $transactionLines, $exciseTax, $transactionError, $orderData);
                        }
                    }
                }
            }
        }
    }
}
