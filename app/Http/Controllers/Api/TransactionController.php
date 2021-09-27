<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ReAttemptExciseJob;
use App\Models\Transaction;
use App\Traits\Helpers;
use Carbon\Carbon;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    /**
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response
     */
    public function orders(Request $request)
    {
        $orders = $this->commonOrders($request);

        return response([
            "data" => $orders['transaction'],
            "total_excise_errors" => $orders['total_excise_errors'],
            "total_ignored_orders" => $orders['total_ignored_orders'],
            'total_excise_collection' => $orders['total_excise_collection'],
        ]);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response
     */
    public function exciseErrors(Request $request)
    {
        $orders = $this->commonOrders($request, true, false, 0);

        return response([
            "data" => $orders['transaction'],
            "total_orders" => $orders['total_orders'],
            "total_ignored_orders" => $orders['total_ignored_orders'],
            'total_excise_collection' => $orders['total_excise_collection'],
        ]);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response
     */
    public function ignoredOrders(Request $request)
    {
        $orders = $this->commonOrders($request, true, false, 1);

        return response([
            "data" => $orders['transaction'],
            "total_orders" => $orders['total_orders'],
            "total_excise_errors" => $orders['total_excise_errors'],
            'total_excise_collection' => $orders['total_excise_collection'],
        ]);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response
     */
    public function ignoreExcise(Request $request)
    {
        $Responce = Transaction::where('id', $request->id)->update(['is_ignore' => 1]);
        if ($Responce) {
            return response(['data' => 'Order excise ignored successfully'], 200);
        } else {
            return response(['data' => 'Something went wrong please try later!'], 400);
        }
    }

    /**
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response
     */
    public function reattemptExcise(Request $request)
    {
        $shop = $request->user();
        ReAttemptExciseJob::dispatch($shop, $request->order_id);
        return response(['data' => "Re-attempt running in background!"], 200);
    }

    /**
     * @param $status
     * @return mixed
     */
    public function status($status)
    {
        switch ($status) {
            case 1:
                $response['status'] = 'Fulfilled';
                $response['badge'] = 'new';
                $response['progress'] = 'complete';
                break;
            case 2:
                $response['status'] = 'Unfulfilled';
                $response['badge'] = 'attention';
                $response['progress'] = 'incomplete';
                break;
            case 3:
                $response['status'] = 'Partially fulfilled';
                $response['badge'] = 'warning';
                $response['progress'] = 'partiallyComplete';
                break;
            default:
                $response['status'] = '-';
                $response['badge'] = '';
                $response['progress'] = '';
                break;
        }
        return $response;
    }

    /**
     * @param $date
     * @return string
     */
    public function fromToDate($date)
    {
        return $date ? Carbon::parse($date)->format('Y-m-d') : Carbon::now()->format('Y-m-d');
    }

    /**
     * @param $request
     * @param bool $exciseErrors
     * @param bool $ignoredOrders
     * @param int $isIgnoreValue
     * @return array
     */
    public function commonOrders($request, $exciseErrors = false, $ignoredOrders = true, $isIgnoreValue = 0)
    {
        $sortBy = request('sortBy') ?? 'order_number';
        $sortOrder = request('sortOrder') ?? 'ascending';
        $sortOrder = ($sortOrder == 'descending') ? 'desc' : 'asc';
        $search = isset($request->search) && $request->search ? $request->search : '';

        if (!$ignoredOrders) {
            $status = isset($request->status) && $request->status ? $request->status : '';
        }

        $from_date = $this->fromToDate($request->start_date);
        $to_date = $this->fromToDate($request->end_date);

        $shop = $request->user();
        $shopId = $shop->id;

        $transaction = Transaction::where('shop_id', $shopId);

        if ($exciseErrors) {
            $totalExcise = Transaction::where('shop_id', $shopId)->whereNull('failed_reason')->whereBetween('order_date', [$from_date . Helpers::startTime(), $to_date . Helpers::endTime()])->get();
        } else {
            $totalExcise = Transaction::where('shop_id', $shopId)->whereNotNull('failed_reason')->whereBetween('order_date', [$from_date . Helpers::startTime(), $to_date . Helpers::endTime()])->get();
        }

        if ($search) {
            $transaction->where(function ($q) use ($search) {
                $q->where('order_number', 'LIKE', '%' . $search . '%');
                $q->orWhere('customer', 'LIKE', '%' . $search . '%');
                $q->orWhere('state', 'LIKE', '%' . $search . '%');
            });
        }
        if ($from_date && $to_date) {
            $transaction->whereBetween('order_date', [$from_date . Helpers::startTime(), $to_date . Helpers::endTime()]);
        }
        if (isset($status)) {
            $transaction->where('status', $status);
        }

        if ($exciseErrors) {
            $transaction->whereNotNull('failed_reason');
            $transaction->where('is_ignore', $isIgnoreValue);
        } else {
            $transaction->whereNull('failed_reason');
        }

        $transaction->orderBy($sortBy, $sortOrder);
        $transaction = $transaction->paginate(15);
        $transaction->flatMap(function ($value) use ($exciseErrors) {
            $value->order_date = Carbon::parse($value->order_date)->format("d M, Y");
            $status = $this->status($value->status);
            $value->status = $status['status'];
            $value->badge = $status['badge'];
            $value->progress = $status['progress'];

            if ($exciseErrors) {
                $value->excise_tax = number_format($value->excise_tax, 2);
            }
        });

        return [
            'transaction' => $transaction,
            'total_orders' => $totalExcise->count(),
            'total_excise_errors' => $totalExcise->where('is_ignore', 0)->count(),
            'total_ignored_orders' => $totalExcise->where('is_ignore', 1)->count(),
            'total_excise_collection' => number_format($totalExcise->sum('excise_tax'), 2),
        ];
    }
}
