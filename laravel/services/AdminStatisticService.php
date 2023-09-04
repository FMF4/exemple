<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AdminStatisticService
{
    public function getYearStatistic(): array
    {
        $currentYear = Carbon::now()->year;
        $currentMonth = Carbon::now()->month;

        $startDate = Carbon::createFromDate($currentYear, 1, 1);

        $months = [];
        for ($i = 1; $i <= 12; $i++) {
            $months[] = $startDate->format('Y-m');
            $startDate->addMonth();
        }

        $data = DB::table('transactions')
            ->select(
                DB::raw('YEAR(created_at) as year'),
                DB::raw('MONTH(created_at) as month'),
                DB::raw('SUM(amount) as total')
            )
            ->where('status', '=', 'success')
            ->whereYear('created_at', $currentYear)
            ->groupBy('year', 'month')
            ->orderBy('year', 'asc')
            ->orderBy('month', 'asc')
            ->get()
            ->toArray();

        $statistics = [];

        foreach ($months as $month) {
            list($year, $monthNumber) = explode('-', $month);
            $monthNumber = intval($monthNumber);

            if ($currentMonth >= $monthNumber) {
                $statistics[$monthNumber] = 0;
            }
        }

        foreach ($data as $item) {
            $month = intval($item->month);
            $totalAmount = $item->total;
            $statistics[$month] = $totalAmount;
        }

        return $statistics;
    }

    public function getTotalStatistic(): array
    {
        $query = DB::table('transactions')->where('status', '=', 'success');

        $totalEarned = $query->sum('amount');

        $year = Carbon::now()->year;
        $earnedThisYear = $query
            ->whereYear('created_at', $year)
            ->sum('amount');

        $month = Carbon::now()->month;
        $earnedThisMonth = $query
            ->whereMonth('created_at', $month)
            ->sum('amount');

        $today = Carbon::today();
        $earnedToday = $query
            ->whereDate('created_at', $today)
            ->sum('amount');

        $result = [
            'total' => $totalEarned,
            'year'  => $earnedThisYear,
            'month' => $earnedThisMonth,
            'today'   => $earnedToday,
        ];
        $this->priceFormatter($result, ['total', 'year', 'month', 'today']);

        return $result;
    }

    public function getRefundsStatistic(): array
    {
        return  [
            'refunds' => DB::table('refunds')->where('status', '=', 'active')->count(),
            'unsubscribes' => DB::table('unsubscribes')->where('status', '=', 'active')->count(),
        ];
    }

    public function getTransactionStatistic(): array
    {
        $today = Carbon::today();


        $transactionToday = DB::table('transactions')
            ->where('status', '=', 'success')
            ->whereDate('created_at', $today)
            ->count();

        $transactionTotal = DB::table('transactions')->count();

        $transactionFail = DB::table('transactions')
            ->where('status', '=', 'fail')
            ->count();

        return [
            'today' => $transactionToday,
            'total' => $transactionTotal,
            'fail'  => $transactionFail,
        ];
    }

    private function priceFormatter(&$data, $key='total'): void
    {
        if (is_array($key)) {
            foreach ($key as $keyItem) {
                $val = $data[$keyItem];
                $data[$keyItem] = [
                    'price' => $val
                ];
                $data[$keyItem]['print'] = number_format($val, 2, '.', ' ');
            }
        }
        else {
            $val = $data[$key];
            $data[$key] = [
                'price' => $val
            ];
            $data[$key]['print'] = number_format($val, 2, '.', ' ');
        }
    }
}
