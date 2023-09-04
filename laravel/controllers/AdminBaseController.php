<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

abstract class AdminBaseController extends Controller
{
    protected String $table;
    protected int $paginate = 10;
    protected $select = [];
    protected $isSort = true;

    protected function getQuery(Request $request)
    {
        $sort = 'asc';
        $order = 'id';

        if ($request->sort && $request->order) {
            $sort = $request->sort;
            $order = $request->order;
        }

        $query = DB::table($this->table);

        if ($this->isSort) {
            $query->orderBy($order, $sort);
        }

        if (!empty($this->select)) {
            $query->select(...$this->select);
        }

        if ($searchQuery = $request->q) {
            $query->where(function ($query) use ($searchQuery) {
                foreach ($this->searchFields as $field) {
                    $query->orWhere($field, 'like', '%' . $searchQuery . '%');
                }
            });
        }

        if ($filterQuery = $request->filter) {
            $query->where(function ($query) use ($filterQuery) {
                foreach ($filterQuery as $field => $filterParam) {
                    if ($field === 'created_at') {
                        $dates = array_map(function ($value) {
                            $date = Carbon::parse($value);
                            return $date->toDateString();
                        }, $filterParam);

                        if (count($dates) === 1) {
                            $dates[] = Carbon::now()->toDateString();
                        }

                        if (count($dates)) {
                            $query->whereBetween($field, $dates);
                        }
                    }
                    else if ($field === 'next_payment' || $field === 'last_payed') {
                        $dates = array_map(function ($value) {
                            $date = Carbon::parse($value);
                            return $date->timestamp;
                        }, $filterParam);

                        if (count($dates) === 1) {
                            $dates[] = time();
                        }

                        if (count($dates)) {
                            $query->whereBetween($field, $dates);
                        }
                    }
                    else {
                        if (is_array($filterParam)) {
                            $query->whereIn($field, $filterParam);
                        }
                    }
                }
            });
        }

        return $query;
    }

    protected function getData(Request $request)
    {
        $query = $this->getQuery($request);
        $data = $query->paginate($this->paginate);

        return [
            $this->table => $data,
            'total' => $this->getTotal()
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->getQuery($request);
        $data = $query->paginate($this->paginate);

        return response()->json([
            $this->table => $data,
            'total' => $this->getTotal()
        ]);
    }

    protected function getTotal(): array
    {
        return [];
    }
}
